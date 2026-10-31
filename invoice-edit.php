<?php
require_once 'header.php';
require_once 'auth_check.php';
require_permission('manage_invoices');
$page_title = 'Edit Invoice';

$id = (int)($_GET['id'] ?? 0);
$invoice = fetch_one("SELECT * FROM invoices WHERE invoice_id = ? AND tenant_id = ?", $id, $current_user->tenant_id);
if (!$invoice) {
    flash_set('danger', 'Invoice not found.');
    redirect('invoices.php');
}

$invoice_warehouse_id = (int)($invoice['warehouse_id'] ?? 0);
if (!$invoice_warehouse_id || !store_is_accessible($invoice_warehouse_id, true)) {
    http_response_code(403);
    die('You do not have access to the store for this invoice.');
}
$invoice_store = find_accessible_store($invoice_warehouse_id, true);

// Cancelled invoices cannot be edited.
$invoice_status = fetch_one("SELECT name FROM document_statuses WHERE status_id = ? AND tenant_id = ?", (int)$invoice['status_id'], $current_user->tenant_id);
if ($invoice_status && strcasecmp((string)$invoice_status['name'], 'Cancelled') === 0) {
    flash_set('danger', 'Cancelled invoices cannot be edited.');
    redirect('invoice-details.php?id=' . $id);
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $linked_order_check = fetch_one("SELECT order_id FROM orders WHERE fulfilled_invoice_id = ? AND tenant_id = ?", $id, $current_user->tenant_id);
    if ($linked_order_check) {
        flash_set('danger', 'This invoice is linked to an order and cannot be edited.');
        redirect('invoice-details.php?id=' . $id);
    }
    $issue_date = $_POST['issue_date'] ?? $invoice['issue_date'];
    $due_date = ($_POST['due_date'] ?? '') ?: null;
    $status_id = (int)($_POST['status_id'] ?? $invoice['status_id']);
    $selected_status = fetch_one("SELECT name FROM document_statuses WHERE status_id = ? AND tenant_id = ?", $status_id, $current_user->tenant_id);
    if ($selected_status && strcasecmp((string)$selected_status['name'], 'Cancelled') === 0) {
        $error = 'Use the Cancel Invoice button to cancel an invoice.';
    }
    $reference = trim($_POST['reference'] ?? '');
    $notes = trim($_POST['notes'] ?? '');
    $terms = trim($_POST['terms'] ?? '');
    $quantities = $_POST['qty'] ?? [];
    $prices = $_POST['price'] ?? [];
    $remove_items = $_POST['remove'] ?? [];
    $new_product_ids = $_POST['new_product'] ?? [];
    $new_quantities = $_POST['new_qty'] ?? [];
    $new_prices = $_POST['new_price'] ?? [];

    $items = fetch_all("SELECT * FROM invoice_items WHERE invoice_id = ?", $id);

    $conn->begin_transaction();
    try {
        // Serialize edits and use the current item rows.
        $locked_invoice = fetch_one(
            "SELECT * FROM invoices WHERE invoice_id = ? AND tenant_id = ? FOR UPDATE",
            $id,
            $current_user->tenant_id
        );
        if (!$locked_invoice) {
            throw new Exception('Invoice not found.');
        }
        $posted_version = (string)($_POST['invoice_version'] ?? '');
        if ($posted_version === '' || $posted_version !== (string)$locked_invoice['updated_at']) {
            throw new Exception('This invoice was changed since you opened it. Reload and try again.');
        }
        $invoice = $locked_invoice;
        $items = fetch_all("SELECT * FROM invoice_items WHERE invoice_id = ?", $id);

        $subtotal = 0;
        $tax_total = 0;

        foreach ($items as $item) {
            $iid = (int)$item['invoice_item_id'];
            $product = fetch_one(
                "SELECT * FROM products WHERE product_id = ? AND tenant_id = ?",
                $item['product_id'], $current_user->tenant_id
            );
            $item_warehouse_id = (int)($item['warehouse_id'] ?? 0);
            if (!$item_warehouse_id || !find_accessible_store($item_warehouse_id, true)) {
                throw new Exception('An item has an unknown or inaccessible store. No stock was changed.');
            }

            if (in_array($iid, array_map('intval', $remove_items))) {
                if ($product && !$product['is_service']) {
                    $stmt = $conn->prepare("UPDATE inventory_stock SET quantity = quantity + ? WHERE product_id = ? AND warehouse_id = ? AND tenant_id = ?");
                    $stmt->bind_param('diii', $item['quantity'], $item['product_id'], $item_warehouse_id, $current_user->tenant_id);
                    $stmt->execute();
                }
                $stmt = $conn->prepare("DELETE FROM invoice_items WHERE invoice_item_id = ?");
                $stmt->bind_param('i', $iid);
                $stmt->execute();
                continue;
            }

            $qty = (float)($quantities[$iid] ?? 0);
            $price = (float)($prices[$iid] ?? $item['unit_price']);
            if ($qty <= 0) {
                if ($product && !$product['is_service']) {
                    $stmt = $conn->prepare("UPDATE inventory_stock SET quantity = quantity + ? WHERE product_id = ? AND warehouse_id = ? AND tenant_id = ?");
                    $stmt->bind_param('diii', $item['quantity'], $item['product_id'], $item_warehouse_id, $current_user->tenant_id);
                    $stmt->execute();
                }
                $stmt = $conn->prepare("DELETE FROM invoice_items WHERE invoice_item_id = ?");
                $stmt->bind_param('i', $iid);
                $stmt->execute();
                continue;
            }

            $delta = $qty - (float)$item['quantity'];
            if ($product && !$product['is_service']) {
                if ($delta > 0) {
                    $avail = fetch_one(
                        "SELECT quantity FROM inventory_stock
                         WHERE product_id = ? AND warehouse_id = ? AND tenant_id = ? FOR UPDATE",
                        $item['product_id'], $item_warehouse_id, $current_user->tenant_id
                    );
                    $on_hand = $avail ? (float)$avail['quantity'] : 0.0;
                    $reserved = reserved_stock_quantity(
                        (int)$current_user->tenant_id,
                        (int)$item['product_id'],
                        $item_warehouse_id
                    );
                    if ($on_hand - $reserved + 0.0001 < $delta) {
                        throw new Exception(
                            'Not enough available stock to increase quantity for ' . $product['name']
                            . ' (needed: ' . $delta . ', available: ' . max(0, $on_hand - $reserved) . ').'
                        );
                    }
                    $stmt = $conn->prepare("UPDATE inventory_stock SET quantity = quantity - ? WHERE product_id = ? AND warehouse_id = ? AND tenant_id = ?");
                    $stmt->bind_param('diii', $delta, $item['product_id'], $item_warehouse_id, $current_user->tenant_id);
                    $stmt->execute();
                } elseif ($delta < 0) {
                    $stmt = $conn->prepare("UPDATE inventory_stock SET quantity = quantity + ? WHERE product_id = ? AND warehouse_id = ? AND tenant_id = ?");
                    $delta_abs = abs($delta);
                    $stmt->bind_param('diii', $delta_abs, $item['product_id'], $item_warehouse_id, $current_user->tenant_id);
                    $stmt->execute();
                }
            }

            $tax_rate = $product ? (float)$product['tax_rate'] : 0;
            $line_total = $price * $qty;
            $line_tax = $line_total * ($tax_rate / 100);
            $subtotal += $line_total;
            $tax_total += $line_tax;

            $stmt = $conn->prepare(
                "UPDATE invoice_items SET warehouse_id = ?, quantity = ?, unit_price = ?, tax_amount = ?, subtotal = ?, total = ? WHERE invoice_item_id = ?"
            );
            $item_total = $line_total + $line_tax;
            $stmt->bind_param('idddddi', $item_warehouse_id, $qty, $price, $line_tax, $line_total, $item_total, $iid);
            $stmt->execute();
        }

        // New items
        foreach ($new_product_ids as $idx => $pid) {
            $pid = (int)$pid;
            $qty = (float)($new_quantities[$idx] ?? 0);
            $price = (float)($new_prices[$idx] ?? 0);
            if (!$pid || $qty <= 0) continue;
            $product = fetch_one("SELECT * FROM products WHERE product_id = ? AND tenant_id = ? AND is_active = 1", $pid, $current_user->tenant_id);
            if (!$product) continue;
            if (!$product['is_service']) {
                $avail = fetch_one(
                    "SELECT quantity FROM inventory_stock
                     WHERE product_id = ? AND warehouse_id = ? AND tenant_id = ? FOR UPDATE",
                    $pid, $invoice_warehouse_id, $current_user->tenant_id
                );
                $on_hand = $avail ? (float)$avail['quantity'] : 0.0;
                $reserved = reserved_stock_quantity(
                    (int)$current_user->tenant_id,
                    (int)$pid,
                    (int)$invoice_warehouse_id
                );
                if ($on_hand - $reserved + 0.0001 < $qty) {
                    throw new Exception(
                        'Not enough available stock for ' . $product['name'] . ' at ' . store_label($invoice_store)
                        . ' (requested: ' . $qty . ', available: ' . max(0, $on_hand - $reserved) . ').'
                    );
                }
                $stmt = $conn->prepare("UPDATE inventory_stock SET quantity = quantity - ? WHERE product_id = ? AND warehouse_id = ? AND tenant_id = ?");
                $stmt->bind_param('diii', $qty, $pid, $invoice_warehouse_id, $current_user->tenant_id);
                $stmt->execute();
            }
            $tax_rate = (float)$product['tax_rate'];
            $line_total = $price * $qty;
            $line_tax = $line_total * ($tax_rate / 100);
            $subtotal += $line_total;
            $tax_total += $line_tax;

            $stmt = $conn->prepare(
                "INSERT INTO invoice_items (tenant_id, invoice_id, warehouse_id, product_id, description, quantity, unit_price, tax_rate_id, tax_amount, subtotal, total, discount)
                 VALUES (?, ?, ?, ?, ?, ?, ?, NULL, ?, ?, ?, 0)"
            );
            $desc = $product['name'];
            $item_total = $line_total + $line_tax;
            $stmt->bind_param('iiiisddddd', $current_user->tenant_id, $id, $invoice_warehouse_id, $pid, $desc, $qty, $price, $line_tax, $line_total, $item_total);
            $stmt->execute();
        }

        $grand = $subtotal + $tax_total;
        $amount_paid = (float)$invoice['amount_paid'];
        $amount_due = max(0, $grand - $amount_paid);
        $is_paid = $amount_due <= 0 ? 1 : 0;

        $stmt = $conn->prepare(
            "UPDATE invoices SET issue_date = ?, due_date = ?, status_id = ?, reference = ?, notes = ?, terms = ?, subtotal = ?, tax_amount = ?, total = ?, amount_due = ?, is_paid = ? WHERE invoice_id = ? AND tenant_id = ?"
        );
        $stmt->bind_param('ssisssddddiii', $issue_date, $due_date, $status_id, $reference, $notes, $terms, $subtotal, $tax_total, $grand, $amount_due, $is_paid, $id, $current_user->tenant_id);
        $stmt->execute();

        $conn->commit();
        audit_log('update', 'invoice', $id, 'Invoice ' . $invoice['invoice_number'] . ' updated');
        flash_set('success', 'Invoice updated successfully.');
        redirect('invoice-details.php?id=' . $id);
    } catch (Exception $ex) {
        $conn->rollback();
        $error = $ex->getMessage();
    }
}

$items = fetch_all(
    "SELECT ii.*, p.code, p.name FROM invoice_items ii
     LEFT JOIN products p ON p.product_id = ii.product_id
     WHERE ii.invoice_id = ? ORDER BY ii.invoice_item_id",
    $id
);
$products = fetch_all(
    "SELECT p.*, GREATEST(0, COALESCE((SELECT s.quantity FROM inventory_stock s
                           WHERE s.product_id = p.product_id AND s.warehouse_id = ? AND s.tenant_id = p.tenant_id), 0)
                           - COALESCE((SELECT SUM(r.quantity) FROM order_stock_reservations r
                           WHERE r.tenant_id = p.tenant_id AND r.product_id = p.product_id AND r.warehouse_id = ? AND r.status = 'active'), 0)) total_stock
     FROM products p WHERE p.tenant_id = ? AND p.is_active = 1 ORDER BY p.name",
    $invoice_warehouse_id, $invoice_warehouse_id, $current_user->tenant_id
);
$statuses = fetch_all(
    "SELECT * FROM document_statuses WHERE tenant_id = ? AND type = 'invoice' ORDER BY sort_order",
    $current_user->tenant_id
);

require_once 'navbar.php';
require_once 'sidebar.php';
$cur = tenant_currency();
?>

<div class="page-head">
    <div>
        <h1 class="page-title">Edit Invoice</h1>
        <div class="page-sub"><?php echo e($invoice['invoice_number']); ?> · Store: <strong><?php echo e(store_label($invoice_store)); ?></strong></div>
    </div>
    <a href="invoice-details.php?id=<?php echo $id; ?>" class="btn btn-light"><i class="fa fa-arrow-left mr-1"></i> Back</a>
</div>

<?php if ($error): ?>
    <div class="alert alert-danger"><?php echo e($error); ?></div>
<?php endif; ?>

<div class="row">
    <div class="col-lg-9">
        <div class="card">
            <div class="card-body">
                <form method="POST" action="invoice-edit.php?id=<?php echo $id; ?>">
                    <div class="row">
                        <div class="col-md-3 form-group"><label>Issue Date</label><input type="date" name="issue_date" class="form-control" value="<?php echo e($invoice['issue_date']); ?>"></div>
                        <div class="col-md-3 form-group"><label>Due Date</label><input type="date" name="due_date" class="form-control" value="<?php echo e(!empty($invoice['due_date']) && $invoice['due_date'] !== '0000-00-00' ? $invoice['due_date'] : ''); ?>"></div>
                        <div class="col-md-3 form-group">
                            <label>Status</label>
                            <select name="status_id" class="form-control">
                                <?php foreach ($statuses as $s): ?>
                                    <option value="<?php echo $s['status_id']; ?>" <?php echo $invoice['status_id'] == $s['status_id'] ? 'selected' : ''; ?>><?php echo e($s['name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-3 form-group"><label>Reference</label><input type="text" name="reference" class="form-control" value="<?php echo e($invoice['reference']); ?>"></div>
                        <div class="col-md-6 form-group"><label>Terms</label><input type="text" name="terms" class="form-control" value="<?php echo e($invoice['terms']); ?>"></div>
                    </div>

                    <div class="table-responsive">
                        <table class="table" id="invItemsTable">
                            <thead>
                                <tr><th>Item</th><th style="width:110px;">Qty</th><th style="width:140px;">Unit Price</th><th class="text-right">Total</th><th style="width:60px;" class="text-center">Remove</th></tr>
                            </thead>
                            <tbody>
                            <?php foreach ($items as $item): ?>
                                <tr>
                                    <td>
                                        <?php echo e($item['description']); ?>
                                        <small class="text-muted d-block"><?php echo e($item['code']); ?></small>
                                    </td>
                                    <td><input type="number" name="qty[<?php echo $item['invoice_item_id']; ?>]" class="form-control" min="0" step="1" value="<?php echo e((float)$item['quantity']); ?>"></td>
                                    <td><input type="number" name="price[<?php echo $item['invoice_item_id']; ?>]" class="form-control" min="0" step="1" value="<?php echo e((float)$item['unit_price']); ?>"></td>
                                    <td class="text-right font-weight-semibold"><?php echo e(money($item['total'], $cur)); ?></td>
                                    <td class="text-center"><input type="checkbox" name="remove[]" value="<?php echo $item['invoice_item_id']; ?>"></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                    <h6 class="mt-3">Add New Items</h6>
                    <div id="newItemsContainer">
                        <div class="form-row">
                            <div class="col-6">
                                <select name="new_product[]" class="form-control">
                                    <option value="">— Select Product —</option>
                                    <?php foreach ($products as $p): ?>
                                        <option value="<?php echo $p['product_id']; ?>" data-price="<?php echo e($p['sale_price']); ?>"><?php echo e($p['name'] . ' (' . $p['code'] . ')'); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-2">
                                <input type="number" name="new_qty[]" class="form-control" placeholder="Qty" min="0" step="1" value="1">
                            </div>
                            <div class="col-2">
                                <input type="number" name="new_price[]" class="form-control new-price" placeholder="Price" min="0" step="1">
                            </div>
                            <div class="col-2">
                                <button type="button" class="btn btn-light btn-block" onclick="addNewItemRow()"><i class="fa fa-plus"></i></button>
                            </div>
                        </div>
                    </div>

                    <div class="form-group mt-3"><label>Notes</label><textarea name="notes" class="form-control" rows="2"><?php echo e($invoice['notes']); ?></textarea></div>
                    <button type="submit" class="btn btn-primary"><i class="fa fa-check mr-1"></i> Save Changes</button>
                </form>
            </div>
        </div>
    </div>
</div>

<script>
    $(function () {
        $('#newItemsContainer').on('change', 'select', function () {
            var price = $(this).find('option:selected').data('price');
            $(this).closest('.form-row').find('.new-price').val(price);
        });
    });
    function addNewItemRow() {
        var proto = $('#newItemsContainer .form-row').first();
        var clone = proto.clone();
        clone.find('select').val('');
        clone.find('input').val('');
        clone.css('margin-top', '8px');
        $('#newItemsContainer').append(clone);
    }
</script>

<?php require_once 'footer.php'; ?>
