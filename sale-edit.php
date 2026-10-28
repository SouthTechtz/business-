<?php
require_once 'header.php';
require_once 'auth_check.php';
require_permission('manage_pos');
$page_title = 'Edit Sale';

$id = (int)($_GET['id'] ?? 0);
$order = fetch_one("SELECT * FROM pos_orders WHERE order_id = ? AND tenant_id = ? AND status != 'cancelled'", $id, $current_user->tenant_id);
if (!$order) {
    flash_set('danger', 'Sale not found or cannot be edited.');
    redirect('sales.php');
}

$order_warehouse_id = (int)($order['warehouse_id'] ?? 0);
if (!$order_warehouse_id || !store_is_accessible($order_warehouse_id, true)) {
    http_response_code(403);
    die('You do not have access to the store for this sale.');
}
$order_store = find_accessible_store($order_warehouse_id, true);

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $contact_id = (int)($_POST['contact_id'] ?? 0);
    $quantities = $_POST['qty'] ?? [];       // order_item_id => qty
    $remove_items = $_POST['remove'] ?? [];  // order_item_id list
    $new_product_ids = $_POST['new_product'] ?? [];
    $new_quantities = $_POST['new_qty'] ?? [];

    $items = fetch_all("SELECT * FROM pos_order_items WHERE order_id = ?", $id);

    $conn->begin_transaction();
    try {
        // Serialize edits and prevent editing a sale that was cancelled meanwhile.
        $locked_order = fetch_one(
            "SELECT * FROM pos_orders WHERE order_id = ? AND tenant_id = ? AND status != 'cancelled' FOR UPDATE",
            $id,
            $current_user->tenant_id
        );
        if (!$locked_order) {
            throw new Exception('Sale not found or cannot be edited.');
        }
        $order = $locked_order;
        $items = fetch_all("SELECT * FROM pos_order_items WHERE order_id = ?", $id);

        $subtotal = 0;
        $tax_total = 0;

        // Existing items
        foreach ($items as $item) {
            $oid_item = (int)$item['order_item_id'];
            $item_warehouse_id = (int)($item['warehouse_id'] ?? 0);
            if (!$item_warehouse_id || !find_accessible_store($item_warehouse_id, true)) {
                throw new Exception('An item has an unknown or inaccessible store. No stock was changed.');
            }

            if (in_array($oid_item, array_map('intval', $remove_items))) {
                // restore stock
                $p = fetch_one("SELECT is_service FROM products WHERE product_id = ? AND tenant_id = ?", $item['product_id'], $current_user->tenant_id);
                if (!$p || !$p['is_service']) {
                    $stmt = $conn->prepare("UPDATE inventory_stock SET quantity = quantity + ? WHERE product_id = ? AND warehouse_id = ? AND tenant_id = ?");
                    $stmt->bind_param('diii', $item['quantity'], $item['product_id'], $item_warehouse_id, $current_user->tenant_id);
                    $stmt->execute();
                }
                $stmt = $conn->prepare("DELETE FROM pos_order_items WHERE order_item_id = ?");
                $stmt->bind_param('i', $oid_item);
                $stmt->execute();
                continue;
            }

            $qty = (float)($quantities[$oid_item] ?? 0);
            if ($qty <= 0) {
                $p = fetch_one("SELECT is_service FROM products WHERE product_id = ? AND tenant_id = ?", $item['product_id'], $current_user->tenant_id);
                if (!$p || !$p['is_service']) {
                    $stmt = $conn->prepare("UPDATE inventory_stock SET quantity = quantity + ? WHERE product_id = ? AND warehouse_id = ? AND tenant_id = ?");
                    $stmt->bind_param('diii', $item['quantity'], $item['product_id'], $item_warehouse_id, $current_user->tenant_id);
                    $stmt->execute();
                }
                $stmt = $conn->prepare("DELETE FROM pos_order_items WHERE order_item_id = ?");
                $stmt->bind_param('i', $oid_item);
                $stmt->execute();
                continue;
            }

            $delta = $qty - (float)$item['quantity'];
            $p = fetch_one("SELECT is_service FROM products WHERE product_id = ? AND tenant_id = ?", $item['product_id'], $current_user->tenant_id);
            if (!$p || !$p['is_service']) {
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
                            'Not enough available stock to increase quantity (needed: ' . $delta
                            . ', available: ' . max(0, $on_hand - $reserved) . ').'
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

            $line_total = (float)$item['unit_price'] * $qty;
            $line_tax = $line_total * ($item['tax_amount'] > 0 && $item['unit_price'] > 0 ? (($item['tax_amount'] / $item['subtotal']) * 100) / 100 : 0);
            // Recompute tax at the original rate
            $original_rate = ($item['subtotal'] > 0 && $item['tax_amount'] > 0) ? ($item['tax_amount'] / $item['subtotal']) * 100 : 0;
            $line_tax = $line_total * ($original_rate / 100);
            $line_total_with_tax = $line_total + $line_tax;
            $subtotal += $line_total;
            $tax_total += $line_tax;

            $stmt = $conn->prepare(
                "UPDATE pos_order_items SET warehouse_id = ?, quantity = ?, tax_amount = ?, subtotal = ?, total = ? WHERE order_item_id = ?"
            );
            $stmt->bind_param('iddddi', $item_warehouse_id, $qty, $line_tax, $line_total, $line_total_with_tax, $oid_item);
            $stmt->execute();
        }

        // New items
        foreach ($new_product_ids as $idx => $pid) {
            $pid = (int)$pid;
            $qty = (float)($new_quantities[$idx] ?? 0);
            if (!$pid || $qty <= 0) continue;
            $p = fetch_one("SELECT * FROM products WHERE product_id = ? AND tenant_id = ? AND is_active = 1", $pid, $current_user->tenant_id);
            if (!$p) continue;
            if (!$p['is_service']) {
                $avail = fetch_one(
                    "SELECT quantity FROM inventory_stock
                     WHERE product_id = ? AND warehouse_id = ? AND tenant_id = ? FOR UPDATE",
                    $pid, $order_warehouse_id, $current_user->tenant_id
                );
                $on_hand = $avail ? (float)$avail['quantity'] : 0.0;
                $reserved = reserved_stock_quantity(
                    (int)$current_user->tenant_id,
                    (int)$pid,
                    (int)$order_warehouse_id
                );
                if ($on_hand - $reserved + 0.0001 < $qty) {
                    throw new Exception(
                        'Not enough available stock for ' . $p['name']
                        . ' (requested: ' . $qty . ', available: ' . max(0, $on_hand - $reserved) . ').'
                    );
                }
                $stmt = $conn->prepare("UPDATE inventory_stock SET quantity = quantity - ? WHERE product_id = ? AND warehouse_id = ? AND tenant_id = ?");
                $stmt->bind_param('diii', $qty, $pid, $order_warehouse_id, $current_user->tenant_id);
                $stmt->execute();
            }
            $line_total = (float)$p['sale_price'] * $qty;
            $line_tax = $line_total * ((float)$p['tax_rate'] / 100);
            $subtotal += $line_total;
            $tax_total += $line_tax;

            $stmt = $conn->prepare(
                "INSERT INTO pos_order_items (order_id, warehouse_id, product_id, description, quantity, unit_price, tax_amount, subtotal, total)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)"
            );
            $desc = $p['name'];
            $item_total = $line_total + $line_tax;
            $stmt->bind_param('iiisddddd', $id, $order_warehouse_id, $pid, $desc, $qty, $p['sale_price'], $line_tax, $line_total, $item_total);
            $stmt->execute();
        }

        $discount = max(0, (float)($order['discount'] ?? 0));
        $grand = max(0, $subtotal + $tax_total - $discount);
        $stmt = $conn->prepare("UPDATE pos_orders SET contact_id = ?, subtotal = ?, tax_amount = ?, discount = ?, total = ? WHERE order_id = ? AND tenant_id = ?");
        $contact_db = $contact_id ?: null;
        $stmt->bind_param('iddddii', $contact_db, $subtotal, $tax_total, $discount, $grand, $id, $current_user->tenant_id);
        $stmt->execute();

        $conn->commit();
        audit_log('update', 'pos_order', $id, 'Sale ' . $order['order_number'] . ' updated');
        flash_set('success', 'Sale updated successfully.');
        redirect('sale-details.php?id=' . $id);
    } catch (Exception $ex) {
        $conn->rollback();
        $error = $ex->getMessage();
    }
}

$items = fetch_all(
    "SELECT oi.*, p.code, p.name, p.is_service FROM pos_order_items oi
     LEFT JOIN products p ON p.product_id = oi.product_id
     WHERE oi.order_id = ? ORDER BY oi.order_item_id",
    $id
);
$products = fetch_all(
    "SELECT p.*, GREATEST(0, COALESCE((SELECT s.quantity FROM inventory_stock s
                           WHERE s.product_id = p.product_id AND s.warehouse_id = ? AND s.tenant_id = p.tenant_id), 0)
                           - COALESCE((SELECT SUM(r.quantity) FROM order_stock_reservations r
                           WHERE r.tenant_id = p.tenant_id AND r.product_id = p.product_id AND r.warehouse_id = ? AND r.status = 'active'), 0)) total_stock
     FROM products p WHERE p.tenant_id = ? AND p.is_active = 1 ORDER BY p.name",
    $order_warehouse_id, $order_warehouse_id, $current_user->tenant_id
);
$customers = fetch_all(
    "SELECT contact_id, first_name, last_name, company_name FROM contacts
     WHERE tenant_id = ? AND is_customer = 1 ORDER BY first_name",
    $current_user->tenant_id
);

require_once 'navbar.php';
require_once 'sidebar.php';
$cur = tenant_currency();
?>

<div class="page-head">
    <div>
        <h1 class="page-title">Edit Sale</h1>
        <div class="page-sub">Order <?php echo e($order['order_number']); ?> · Store: <strong><?php echo e(store_label($order_store)); ?></strong></div>
    </div>
    <a href="sale-details.php?id=<?php echo $id; ?>" class="btn btn-light"><i class="fa fa-arrow-left mr-1"></i> Back</a>
</div>

<?php if ($error): ?>
    <div class="alert alert-danger"><?php echo e($error); ?></div>
<?php endif; ?>

<div class="row">
    <div class="col-lg-9">
        <div class="card">
            <div class="card-header"><h5 class="card-title">Items</h5></div>
            <div class="card-body">
                <form method="POST" action="sale-edit.php?id=<?php echo $id; ?>" id="editForm">
                    <div class="form-group">
                        <label>Customer</label>
                        <select name="contact_id" class="form-control" style="max-width:380px;">
                            <option value="0">Walk-in Customer</option>
                            <?php foreach ($customers as $c): ?>
                                <option value="<?php echo $c['contact_id']; ?>" <?php echo $order['contact_id'] == $c['contact_id'] ? 'selected' : ''; ?>>
                                    <?php echo e(trim(($c['first_name'] ?? '') . ' ' . ($c['last_name'] ?? '')) ?: $c['company_name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="table-responsive">
                        <table class="table" id="itemsTable">
                            <thead>
                                <tr><th>Item</th><th style="width:120px;">Qty</th><th class="text-right">Unit Price</th><th class="text-right">Total</th><th style="width:60px;"></th></tr>
                            </thead>
                            <tbody>
                            <?php foreach ($items as $item): ?>
                                <tr>
                                    <td>
                                        <input type="hidden" name="item_id[]" value="<?php echo $item['order_item_id']; ?>">
                                        <?php echo e($item['description'] ?: $item['code']); ?>
                                        <small class="text-muted d-block"><?php echo e($item['code']); ?></small>
                                    </td>
                                    <td>
                                        <input type="number" name="qty[<?php echo $item['order_item_id']; ?>]" class="form-control"
                                               value="<?php echo e((float)$item['quantity']); ?>" min="0" step="1">
                                    </td>
                                    <td class="text-right"><?php echo e(money($item['unit_price'], $cur)); ?></td>
                                    <td class="text-right font-weight-semibold"><?php echo e(money($item['total'], $cur)); ?></td>
                                    <td class="text-right">
                                        <input type="checkbox" name="remove[]" value="<?php echo $item['order_item_id']; ?>" title="Remove item">
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <div class="mt-3">
                        <h6>Add New Items</h6>
                        <div class="form-row" id="newItemsContainer">
                            <div class="col-7">
                                <select name="new_product[]" class="form-control">
                                    <option value="">— Select Product —</option>
                                    <?php foreach ($products as $p): ?>
                                        <option value="<?php echo $p['product_id']; ?>"><?php echo e($p['name'] . ' (' . $p['code'] . ')'); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-3">
                                <input type="number" name="new_qty[]" class="form-control" placeholder="Qty" min="0" step="1">
                            </div>
                            <div class="col-2">
                                <button type="button" class="btn btn-light btn-block" onclick="addNewItemRow()"><i class="fa fa-plus"></i></button>
                            </div>
                        </div>
                    </div>
                    <button type="submit" class="btn btn-primary mt-4"><i class="fa fa-check mr-1"></i> Save Changes</button>
                </form>
            </div>
        </div>
    </div>
</div>

<script>
function addNewItemRow() {
    var proto = $('#newItemsContainer .col-7').first().parent();
    var clone = proto.clone();
    clone.find('select').val('');
    clone.find('input[type=number]').val('');
    clone.css('margin-top', '8px');
    $('#newItemsContainer').append(clone);
}
</script>

<?php require_once 'footer.php'; ?>
