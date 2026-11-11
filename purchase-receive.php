<?php
require_once 'header.php';
require_once 'auth_check.php';
require_permission('manage_purchases');

// Confirming an arriving purchase is allowed for the master administrator, a
// brand Mini Admin (their own brand only) and the Godown Manager.
if (!can_receive_purchases()) {
    http_response_code(403);
    die('Only an administrator, a Mini Admin or the Godown Manager can confirm purchased goods.');
}

$page_title = 'Receive Purchase';
$tenant_id = (int)$current_user->tenant_id;
$id = (int)($_GET['id'] ?? $_POST['purchase_id'] ?? 0);

// Receiving is a Godown duty, not an ownership right: the Godown Manager must
// be able to receive goods that an administrator ordered. Brand scope still
// applies, and only arrived, not-yet-received purchases can be received.
$purchase_scope = '';
$purchase_params = [$id, $tenant_id];
$purchase_brand_scope = current_brand_scope();
if ($purchase_brand_scope !== '') {
    $purchase_scope .= ' AND pr.brand = ?';
    $purchase_params[] = $purchase_brand_scope;
}

function purchase_receive_load(int $id, int $tenant_id, string $scope, array $params)
{
    return fetch_one(
        "SELECT pu.*,
                pr.name product_name, pr.code product_code, pr.brand product_brand,
                pr.is_service, pr.purchase_price,
                COALESCE(u.is_decimal, 0) is_decimal, u.short_name unit_name,
                s.company_name supplier_name,
                TRIM(CONCAT(COALESCE(rb.first_name, ''), ' ', COALESCE(rb.last_name, ''))) received_by_name,
                rb.username received_by_username,
                w.name received_godown_name
           FROM purchases pu
           JOIN products pr ON pr.product_id = pu.product_id AND pr.tenant_id = pu.tenant_id
           LEFT JOIN units_of_measurement u ON u.unit_id = pr.unit_id AND u.tenant_id = pr.tenant_id
           LEFT JOIN suppliers s ON s.supplier_id = pu.supplier_id AND s.tenant_id = pu.tenant_id
           LEFT JOIN users rb ON rb.user_id = pu.received_by
           LEFT JOIN warehouses w ON w.warehouse_id = pu.received_godown_id AND w.tenant_id = pu.tenant_id
          WHERE pu.purchase_id = ? AND pu.tenant_id = ?{$scope}",
        ...$params
    );
}

$purchase = purchase_receive_load($id, $tenant_id, $purchase_scope, $purchase_params);
if (!$purchase) {
    flash_set('danger', 'Purchase not found.');
    redirect('purchases.php');
}

$godown = find_active_godown($tenant_id);
$already_received = purchase_is_received($id, $tenant_id);
$ordered_quantity = (float)$purchase['quantity'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    $return_to = (string)($_POST['return_to'] ?? '');
    $back = $return_to === 'purchases' ? 'purchases.php' : 'purchase-receive.php?id=' . (int)$id;
    if (!form_token_valid()) {
        flash_set('danger', 'This receiving form has expired. Open the purchase again and retry.');
        redirect($back);
    }
    if (!$godown) {
        flash_set('danger', 'Create an active Godown before receiving purchased goods.');
        redirect($back);
    }
    if ($already_received) {
        flash_set('danger', 'This purchase has already been received into stock.');
        redirect('purchase-view.php?id=' . (int)$id);
    }
    if ((string)($purchase['status'] ?? '') === 'cancelled') {
        flash_set('danger', 'Cancelled purchases cannot be confirmed as received.');
        redirect('purchase-view.php?id=' . (int)$id);
    }

    $received_quantity = round((float)($_POST['received_quantity'] ?? 0), 2);
    $damaged_quantity = round((float)($_POST['damaged_quantity'] ?? 0), 2);
    $returned_quantity = round((float)($_POST['returned_quantity'] ?? 0), 2);
    $other_quantity = round((float)($_POST['other_quantity'] ?? 0), 2);
    $notes = trim((string)($_POST['notes'] ?? ''));
    $rejected_quantity = round($damaged_quantity + $returned_quantity + $other_quantity, 2);
    $good_quantity = round($received_quantity - $rejected_quantity, 2);

    $errors = [];
    if ((int)($purchase['is_service'] ?? 0) === 1) {
        $errors[] = 'Services cannot be received into stock.';
    }
    if ($received_quantity <= 0 || $received_quantity > 99999999) {
        $errors[] = 'Enter the quantity actually received (greater than zero).';
    }
    if ($received_quantity > $ordered_quantity + 0.0001) {
        $errors[] = 'The received quantity cannot exceed the ordered quantity of '
            . format_quantity($ordered_quantity) . '.';
    }
    foreach ([
        'damaged' => $damaged_quantity,
        'to return' => $returned_quantity,
        'other' => $other_quantity,
    ] as $label => $quantity) {
        if ($quantity < 0 || $quantity > 99999999) {
            $errors[] = 'Enter a valid ' . $label . ' quantity.';
        }
    }
    if ($rejected_quantity > $received_quantity + 0.0001) {
        $errors[] = 'Damaged, returned and other quantities together cannot be more than the quantity received.';
    }
    if ($good_quantity < -0.0001) {
        $errors[] = 'The good quantity cannot be negative. Reduce the damaged, returned or other quantity.';
    }
    if (!(int)($purchase['is_decimal'] ?? 0)) {
        foreach ([$received_quantity, $damaged_quantity, $returned_quantity, $other_quantity] as $quantity) {
            if (abs($quantity - round($quantity)) > 0.0001) {
                $errors[] = 'This product uses whole units only.';
                break;
            }
        }
    }
    if (mb_strlen($notes) > 2000) {
        $errors[] = 'Notes are too long (maximum 2,000 characters).';
    }

    if ($errors) {
        flash_set('danger', implode(' ', $errors));
        redirect($back);
    }

    $conn->begin_transaction();
    $in_transaction = true;
    try {
        $locked_godown = fetch_one(
            "SELECT * FROM warehouses
              WHERE warehouse_id = ? AND tenant_id = ? AND location_type = 'godown' AND is_active = 1
              FOR UPDATE",
            (int)$godown['warehouse_id'],
            $tenant_id
        );
        if (!$locked_godown) {
            throw new RuntimeException('The central Godown is no longer active.');
        }

        $locked_purchase_scope = '';
        $locked_purchase_params = [$id, $tenant_id];
        if ($purchase_brand_scope !== '') {
            $locked_purchase_scope = ' AND product_id IN (SELECT product_id FROM products WHERE tenant_id = ? AND brand = ?)';
            $locked_purchase_params[] = $tenant_id;
            $locked_purchase_params[] = $purchase_brand_scope;
        }
        $locked_purchase = fetch_one(
            "SELECT purchase_id, quantity, status, received_at
               FROM purchases
              WHERE purchase_id = ? AND tenant_id = ?{$locked_purchase_scope}
              FOR UPDATE",
            ...$locked_purchase_params
        );
        if (!$locked_purchase) {
            throw new RuntimeException('Purchase not found.');
        }
        if (!empty($locked_purchase['received_at'])) {
            throw new RuntimeException('This purchase has already been received into stock.');
        }
        if ((string)$locked_purchase['status'] === 'cancelled') {
            throw new RuntimeException('Cancelled purchases cannot be confirmed as received.');
        }

        $locked_product = fetch_one(
            "SELECT product_id, name, is_active, is_service, min_stock_level
               FROM products
              WHERE product_id = ? AND tenant_id = ?
              FOR UPDATE",
            (int)$purchase['product_id'],
            $tenant_id
        );
        if (!$locked_product || (int)$locked_product['is_active'] !== 1) {
            throw new RuntimeException('The purchased product is no longer active.');
        }
        if ((int)$locked_product['is_service'] === 1) {
            throw new RuntimeException('Services cannot be received into stock.');
        }

        $godown_warehouse_id = (int)$locked_godown['warehouse_id'];
        $purchase_product_id = (int)$purchase['product_id'];
        $purchase_user_id = (int)$current_user->user_id;
        $stmt = $conn->prepare(
            "INSERT INTO inventory_stock (tenant_id, product_id, warehouse_id, quantity, reorder_level)
             VALUES (?, ?, ?, 0.00, ?)
             ON DUPLICATE KEY UPDATE stock_id = stock_id"
        );
        $min_level = (float)($locked_product['min_stock_level'] ?? 0);
        $stmt->bind_param('iiid', $tenant_id, $purchase_product_id, $godown_warehouse_id, $min_level);
        $stmt->execute();
        $stmt->close();

        $stock = fetch_one(
            "SELECT stock_id, quantity FROM inventory_stock
              WHERE tenant_id = ? AND product_id = ? AND warehouse_id = ?
              FOR UPDATE",
            $tenant_id,
            $purchase_product_id,
            $godown_warehouse_id
        );
        if (!$stock) {
            throw new RuntimeException('The Godown stock row could not be locked.');
        }
        $stock_id = (int)$stock['stock_id'];

        $receipt_number = next_document_number($tenant_id, 'godown_receipt', 'GRN');
        $source_reference = 'Purchase #' . (int)$id;
        $notes_db = $notes !== '' ? $notes : null;
        $supplier_db = !empty($purchase['supplier_id']) ? (int)$purchase['supplier_id'] : null;

        $stmt = $conn->prepare(
            "INSERT INTO godown_receipts
                (tenant_id, receipt_number, godown_id, product_id, purchase_id, supplier_id,
                 quantity, damaged_quantity, returned_quantity, other_quantity,
                 source_reference, notes, received_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
        );
        $stmt->bind_param(
            'isiiiiddddssi',
            $tenant_id,
            $receipt_number,
            $godown_warehouse_id,
            $purchase_product_id,
            $id,
            $supplier_db,
            $good_quantity,
            $damaged_quantity,
            $returned_quantity,
            $other_quantity,
            $source_reference,
            $notes_db,
            $purchase_user_id
        );
        $stmt->execute();
        $receipt_id = (int)$stmt->insert_id;
        $stmt->close();

        if ($good_quantity > 0) {
            $stmt = $conn->prepare(
                "UPDATE inventory_stock
                    SET quantity = quantity + ?, last_counted_at = CURRENT_TIMESTAMP
                  WHERE stock_id = ? AND tenant_id = ?"
            );
            $stmt->bind_param('dii', $good_quantity, $stock_id, $tenant_id);
            $stmt->execute();
            $stmt->close();
        }

        $movement_notes = 'Receipt ' . $receipt_number . ' from Purchase #' . (int)$id;
        if ($damaged_quantity > 0) {
            $movement_notes .= '; damaged ' . format_quantity($damaged_quantity);
        }
        if ($returned_quantity > 0) {
            $movement_notes .= '; to return ' . format_quantity($returned_quantity);
        }
        if ($other_quantity > 0) {
            $movement_notes .= '; other ' . format_quantity($other_quantity);
        }
        if ($notes_db !== null) {
            $movement_notes .= ' - ' . $notes_db;
        }

        if ($good_quantity > 0) {
            $stmt = $conn->prepare(
                "INSERT INTO inventory_movements
                    (tenant_id, product_id, warehouse_id, movement_type, quantity, reference_id, reference_type, created_by, notes)
                 VALUES (?, ?, ?, 'godown_receipt', ?, ?, 'godown_receipt', ?, ?)"
            );
            $stmt->bind_param(
                'iiidiis',
                $tenant_id,
                $purchase_product_id,
                $godown_warehouse_id,
                $good_quantity,
                $receipt_id,
                $purchase_user_id,
                $movement_notes
            );
            $stmt->execute();
            $stmt->close();
        }

        $stmt = $conn->prepare(
            "UPDATE purchases
                SET received_quantity = ?, damaged_quantity = ?, returned_quantity = ?, other_quantity = ?,
                    status = 'arrived_godown',
                    received_at = CURRENT_TIMESTAMP, received_by = ?, received_godown_id = ?
              WHERE purchase_id = ? AND tenant_id = ?"
        );
        $stmt->bind_param(
            'ddddiiii',
            $received_quantity,
            $damaged_quantity,
            $returned_quantity,
            $other_quantity,
            $purchase_user_id,
            $godown_warehouse_id,
            $id,
            $tenant_id
        );
        $stmt->execute();
        $stmt->close();

        // Rejected goods (damaged, to return, other) are recorded in Sales
        // Returns under the supplier category, so they never reduce sales.
        $supplier_return_number = '';
        $supplier_return_id = 0;
        if ($rejected_quantity > 0) {
            $unit_cost = $ordered_quantity > 0
                ? round((float)$purchase['total'] / $ordered_quantity, 4)
                : (float)$purchase['purchase_price'];
            $rejected_value = round($rejected_quantity * $unit_cost, 2);
            $supplier_return_number = next_document_number($tenant_id, 'sales_return', 'RET');

            $return_notes_parts = [];
            if ($damaged_quantity > 0) $return_notes_parts[] = 'Damaged: ' . format_quantity($damaged_quantity);
            if ($returned_quantity > 0) $return_notes_parts[] = 'To return to supplier: ' . format_quantity($returned_quantity);
            if ($other_quantity > 0) $return_notes_parts[] = 'Other: ' . format_quantity($other_quantity);
            $return_notes = 'Supplier delivery from Purchase #' . (int)$id
                . ' (' . $receipt_number . '). ' . implode('; ', $return_notes_parts) . '.';
            if ($notes_db !== null) {
                $return_notes .= ' ' . $notes_db;
            }

            $supplier_customer_id = null;
            $supplier_shop_id = null;
            $return_category = 'supplier_damage';
            $return_source_type = 'purchase';
            $return_status = 'completed';
            $return_token = 'purchase-receipt-' . $receipt_id;

            $stmt = $conn->prepare(
                "INSERT INTO sales_returns
                    (tenant_id, return_number, source_type, return_category, source_id,
                     customer_id, shop_id, warehouse_id, return_date, refund_amount,
                     refund_method_id, refund_bank_name, damaged_quantity, returned_quantity,
                     other_quantity, notes, status, created_by, submission_token)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW(), ?, NULL, NULL, ?, ?, ?, ?, ?, ?, ?)"
            );
            $stmt->bind_param(
                'isssiiiiddddssis',
                $tenant_id,
                $supplier_return_number,
                $return_source_type,
                $return_category,
                $id,
                $supplier_customer_id,
                $supplier_shop_id,
                $godown_warehouse_id,
                $rejected_value,
                $damaged_quantity,
                $returned_quantity,
                $other_quantity,
                $return_notes,
                $return_status,
                $purchase_user_id,
                $return_token
            );
            $stmt->execute();
            $supplier_return_id = (int)$stmt->insert_id;
            $stmt->close();

            $stmt = $conn->prepare(
                "INSERT INTO sales_return_items
                    (return_id, tenant_id, product_id, quantity, unit_price, refund_amount, resale_quantity)
                 VALUES (?, ?, ?, ?, ?, ?, 0.00)"
            );
            $stmt->bind_param('iiiddd', $supplier_return_id, $tenant_id, $purchase_product_id, $rejected_quantity, $unit_cost, $rejected_value);
            $stmt->execute();
            $stmt->close();
        }

        $conn->commit();
        $in_transaction = false;

        consume_form_token();
        audit_log(
            'receive',
            'purchase',
            (int)$id,
            'Purchase #' . (int)$id . ' confirmed into ' . $locked_godown['name']
                . ' — good ' . format_quantity($good_quantity)
                . ($damaged_quantity > 0 ? ', damaged ' . format_quantity($damaged_quantity) : '')
                . ($returned_quantity > 0 ? ', to return ' . format_quantity($returned_quantity) : '')
                . ($other_quantity > 0 ? ', other ' . format_quantity($other_quantity) : '')
                . ' (' . $receipt_number . ').'
        );
        if ($rejected_quantity > 0) {
            audit_log(
                'create',
                'sales_return',
                $supplier_return_id,
                'Supplier damaged goods from Purchase #' . (int)$id
                    . ' recorded on sales return ' . $supplier_return_number
                    . ' (' . format_quantity($rejected_quantity) . ').'
            );
        }

        $flash = 'Confirmed ' . format_quantity($received_quantity) . ' ' . ($purchase['unit_name'] ?: 'unit(s)')
            . ' of ' . $purchase['product_name'] . ' at ' . $locked_godown['name'] . '. '
            . format_quantity($good_quantity) . ' added to stock';
        if ($damaged_quantity > 0) $flash .= ', ' . format_quantity($damaged_quantity) . ' damaged';
        if ($returned_quantity > 0) $flash .= ', ' . format_quantity($returned_quantity) . ' to return';
        if ($other_quantity > 0) $flash .= ', ' . format_quantity($other_quantity) . ' other';
        $flash .= '. Receipt ' . $receipt_number . '.';
        if ($rejected_quantity > 0) {
            $flash .= ' Supplier return ' . $supplier_return_number . ' created in Sales Returns.';
        }
        flash_set('success', $flash);
        redirect($return_to === 'purchases' ? 'purchases.php' : 'purchase-view.php?id=' . (int)$id);
    } catch (Throwable $ex) {
        if ($in_transaction) {
            $conn->rollback();
        }
        flash_set('danger', $ex->getMessage());
        redirect($back);
    }
}

require_once 'navbar.php';
require_once 'sidebar.php';
$cur = tenant_currency();
$already_received = purchase_is_received($id, $tenant_id);
$received_good = max(0, (float)$purchase['received_quantity']
    - (float)$purchase['damaged_quantity']
    - (float)($purchase['returned_quantity'] ?? 0)
    - (float)($purchase['other_quantity'] ?? 0));
$receive_return_to = (string)($_GET['return_to'] ?? '');
?>

<div class="page-head">
    <div>
        <h1 class="page-title">Confirm Purchase #<?php echo (int)$purchase['purchase_id']; ?></h1>
        <div class="page-sub">Confirm what actually arrived. The goods status becomes Arrived at Godown only after you confirm; good units enter stock, while damaged, returned and other units are recorded as a supplier return.</div>
    </div>
    <a href="purchase-view.php?id=<?php echo (int)$purchase['purchase_id']; ?>" class="btn btn-light">
        <i class="fa fa-arrow-left mr-1"></i> Back to Purchase
    </a>
</div>

<?php if ($f = flash_get()): ?>
    <div class="alert alert-<?php echo e($f['type']); ?>"><?php echo e($f['message']); ?></div>
<?php endif; ?>

<div class="row">
    <div class="col-lg-7 mb-3">
        <div class="card">
            <div class="card-header"><h5 class="card-title mb-0"><i class="fa fa-cube mr-1" style="color:var(--primary)"></i> Purchase Details</h5></div>
            <div class="card-body">
                <dl class="row mb-0">
                    <dt class="col-sm-4">Product</dt>
                    <dd class="col-sm-8"><?php echo e($purchase['product_name']); ?> (<?php echo e($purchase['product_code']); ?>)</dd>
                    <dt class="col-sm-4">Supplier</dt>
                    <dd class="col-sm-8"><?php echo e($purchase['supplier_name'] ?: '—'); ?></dd>
                    <dt class="col-sm-4">Ordered Quantity</dt>
                    <dd class="col-sm-8"><?php echo e(format_quantity($ordered_quantity)); ?> <?php echo e($purchase['unit_name'] ?: 'unit(s)'); ?></dd>
                    <dt class="col-sm-4">Purchase Total</dt>
                    <dd class="col-sm-8"><?php echo e(money((float)$purchase['total'], $cur)); ?></dd>
                    <dt class="col-sm-4">Goods Status</dt>
                    <dd class="col-sm-8">
                        <span class="badge badge-<?php echo e(purchase_goods_status_class($purchase['status'] ?? 'in_production')); ?>">
                            <?php echo e(purchase_goods_status_label($purchase['status'] ?? 'in_production')); ?>
                        </span>
                    </dd>
                    <dt class="col-sm-4">Receiving Godown</dt>
                    <dd class="col-sm-8"><?php echo e($godown['name'] ?? '—'); ?></dd>
                </dl>
            </div>
        </div>
    </div>

    <div class="col-lg-5 mb-3">
        <div class="card h-100">
            <div class="card-header"><h5 class="card-title mb-0"><i class="fa fa-check-circle mr-1" style="color:var(--primary)"></i> Receive Goods</h5></div>
            <div class="card-body">
                <?php if ($already_received): ?>
                    <div class="alert alert-success">
                        This purchase was received on
                        <strong><?php echo e(date('M d, Y H:i', strtotime($purchase['received_at']))); ?></strong>
                        by <?php echo e(trim((string)$purchase['received_by_name']) ?: ($purchase['received_by_username'] ?? '—')); ?>.
                    </div>
                    <dl class="row mb-0">
                        <dt class="col-7">Received (total)</dt>
                        <dd class="col-5 text-right"><?php echo e(format_quantity($purchase['received_quantity'])); ?></dd>
                        <dt class="col-7">Damaged</dt>
                        <dd class="col-5 text-right text-danger"><?php echo e(format_quantity($purchase['damaged_quantity'])); ?></dd>
                        <dt class="col-7">To return to supplier</dt>
                        <dd class="col-5 text-right text-danger"><?php echo e(format_quantity($purchase['returned_quantity'] ?? 0)); ?></dd>
                        <dt class="col-7">Other</dt>
                        <dd class="col-5 text-right text-danger"><?php echo e(format_quantity($purchase['other_quantity'] ?? 0)); ?></dd>
                        <dt class="col-7 font-weight-bold">Good quantity in stock</dt>
                        <dd class="col-5 text-right font-weight-bold text-success"><?php echo e(format_quantity($received_good)); ?></dd>
                        <dt class="col-7">Godown</dt>
                        <dd class="col-5 text-right"><?php echo e($purchase['received_godown_name'] ?: '—'); ?></dd>
                    </dl>
                <?php elseif (!$godown): ?>
                    <div class="alert alert-danger mb-0">Create an active Godown before receiving purchased goods.</div>
                <?php elseif ((string)($purchase['status'] ?? '') === 'cancelled'): ?>
                    <div class="alert alert-danger mb-0">This purchase is cancelled and cannot be confirmed.</div>
                <?php else: ?>
                    <form method="POST" action="purchase-receive.php?id=<?php echo (int)$purchase['purchase_id']; ?>">
                        <?php echo csrf_field(); ?>
                        <?php echo form_token_field(); ?>
                        <input type="hidden" name="purchase_id" value="<?php echo (int)$purchase['purchase_id']; ?>">
                        <input type="hidden" name="return_to" value="<?php echo e($receive_return_to); ?>">
                        <div class="form-group">
                            <label>Quantity Received <span class="text-danger">*</span></label>
                            <input type="number" name="received_quantity" id="receivedQuantity" class="form-control"
                                   min="<?php echo (int)$purchase['is_decimal'] ? '0.01' : '1'; ?>" max="<?php echo e(number_format($ordered_quantity, 2, '.', '')); ?>"
                                   step="<?php echo (int)$purchase['is_decimal'] ? '0.01' : '1'; ?>"
                                   value="<?php echo e(number_format($ordered_quantity, 2, '.', '')); ?>" required>
                            <small class="text-muted">Total goods that arrived, including damaged and returned units. Maximum <?php echo e(format_quantity($ordered_quantity)); ?>.</small>
                        </div>
                        <div class="form-row">
                            <div class="form-group col-6">
                                <label>Damaged</label>
                                <input type="number" name="damaged_quantity" id="damagedQuantity" class="form-control receive-deduction"
                                       min="0" max="<?php echo e(number_format($ordered_quantity, 2, '.', '')); ?>"
                                       step="<?php echo (int)$purchase['is_decimal'] ? '0.01' : '1'; ?>" value="0">
                                <small class="text-muted">Broken / not sellable.</small>
                            </div>
                            <div class="form-group col-6">
                                <label>To return to supplier</label>
                                <input type="number" name="returned_quantity" id="returnedQuantity" class="form-control receive-deduction"
                                       min="0" max="<?php echo e(number_format($ordered_quantity, 2, '.', '')); ?>"
                                       step="<?php echo (int)$purchase['is_decimal'] ? '0.01' : '1'; ?>" value="0">
                                <small class="text-muted">Goods going back to the supplier.</small>
                            </div>
                        </div>
                        <div class="form-group">
                            <label>Other (missing / rejected)</label>
                            <input type="number" name="other_quantity" id="otherQuantity" class="form-control receive-deduction"
                                   min="0" max="<?php echo e(number_format($ordered_quantity, 2, '.', '')); ?>"
                                   step="<?php echo (int)$purchase['is_decimal'] ? '0.01' : '1'; ?>" value="0">
                            <small class="text-muted">Any other units that will not enter stock.</small>
                        </div>
                        <div class="alert alert-info py-2">
                            Good quantity to add to stock:
                            <strong id="goodQuantityPreview"><?php echo e(format_quantity($ordered_quantity)); ?></strong>
                            <?php echo e($purchase['unit_name'] ?: 'unit(s)'); ?>
                            <div class="small mt-1">Damaged, returned and other units are recorded as a supplier return.</div>
                        </div>
                        <div class="form-group">
                            <label>Confirmation Notes</label>
                            <textarea name="notes" class="form-control" rows="2" maxlength="2000" placeholder="Optional note about this delivery"></textarea>
                        </div>
                        <button type="submit" class="btn btn-success btn-block">
                            <i class="fa fa-check mr-1"></i> Confirm Goods Received
                        </button>
                    </form>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<?php if (!$already_received && $godown && (string)($purchase['status'] ?? '') !== 'cancelled'): ?>
<script>
$(function () {
    var received = document.getElementById('receivedQuantity');
    var deductions = document.querySelectorAll('.receive-deduction');
    var preview = document.getElementById('goodQuantityPreview');
    if (!received || !preview) return;
    function refresh() {
        var good = parseFloat(received.value) || 0;
        deductions.forEach(function (input) {
            good -= parseFloat(input.value) || 0;
        });
        preview.textContent = (good > 0 ? good : 0).toLocaleString(undefined, {
            minimumFractionDigits: 0,
            maximumFractionDigits: 2
        });
    }
    received.addEventListener('input', refresh);
    deductions.forEach(function (input) { input.addEventListener('input', refresh); });
    refresh();
});
</script>
<?php endif; ?>

<?php require_once 'footer.php'; ?>
