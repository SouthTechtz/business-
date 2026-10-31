<?php
require_once 'header.php';
require_once 'auth_check.php';
require_permission('manage_pos');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { redirect('sales-returns.php'); }
verify_csrf();

$tenant_id = (int)$current_user->tenant_id;
$source_type = (string)($_POST['source_type'] ?? 'pos');
$source_id = (int)($_POST['source_id'] ?? 0);
$product_ids = $_POST['product_id'] ?? [];
$quantities = $_POST['quantity'] ?? [];

if ($source_type !== 'pos' || $source_id <= 0) {
    flash_set('danger', 'Invalid source transaction.');
    redirect('sales-returns.php');
}

$order = fetch_one("SELECT * FROM pos_orders WHERE order_id = ? AND tenant_id = ?", $source_id, $tenant_id);
if (!$order || $order['status'] !== 'completed') {
    flash_set('danger', 'Original sale not found or not completed.');
    redirect('sales-returns.php');
}
$warehouse_id = (int)($order['warehouse_id'] ?? 0);
$shop_id = null;
// derive shop from warehouse if possible
$wh = $warehouse_id > 0 ? fetch_one("SELECT shop_id FROM warehouses WHERE warehouse_id = ? AND tenant_id = ?", $warehouse_id, $tenant_id) : null;
if ($wh) $shop_id = (int)$wh['shop_id'];

// original items
$original_items = fetch_all("SELECT product_id, quantity, unit_price FROM pos_order_items WHERE order_id = ?", $source_id);
$orig_map = [];
foreach ($original_items as $oi) { $orig_map[(int)$oi['product_id']] = $oi; }

// previously returned quantities
$prev = fetch_all("SELECT sri.product_id, COALESCE(SUM(sri.quantity),0) AS returned_qty FROM sales_return_items sri JOIN sales_returns sr ON sr.return_id = sri.return_id AND sr.tenant_id = sri.tenant_id WHERE sr.tenant_id = ? AND sr.source_type = 'pos' AND sr.source_id = ? AND sr.status = 'completed' GROUP BY sri.product_id", $tenant_id, $source_id);
$prev_map = [];
foreach ($prev as $p) { $prev_map[(int)$p['product_id']] = (float)$p['returned_qty']; }

$items_to_return = [];
$total_refund = 0.0;
foreach ($product_ids as $idx => $pid_raw) {
    $pid = (int)$pid_raw;
    $qty = (float)($quantities[$idx] ?? 0);
    if ($pid <= 0 || $qty <= 0) continue;
    if (!isset($orig_map[$pid])) {
        flash_set('danger', 'Product not in original sale.');
        redirect('sales-returns.php');
    }
    $orig_qty = (float)$orig_map[$pid]['quantity'];
    $already = $prev_map[$pid] ?? 0.0;
    $remaining = $orig_qty - $already;
    if ($qty > $remaining + 0.0001) {
        flash_set('danger', 'Return quantity exceeds remaining returnable quantity.');
        redirect('sales-returns.php');
    }
    $unit_price = (float)$orig_map[$pid]['unit_price'];
    $refund = $qty * $unit_price;
    $items_to_return[] = ['product_id'=>$pid, 'quantity'=>$qty, 'unit_price'=>$unit_price, 'refund'=>$refund];
    $total_refund += $refund;
}

if (!$items_to_return) {
    flash_set('danger', 'No items to return.');
    redirect('sales-returns.php');
}

$conn->begin_transaction();
try {
    $return_number = 'RET-' . date('Ymd') . '-' . time();
    $return_id = db_insert('sales_returns', [
        'tenant_id' => $tenant_id,
        'return_number' => $return_number,
        'source_type' => 'pos',
        'source_id' => $source_id,
        'customer_id' => $order['contact_id'],
        'shop_id' => $shop_id,
        'warehouse_id' => $warehouse_id,
        'refund_amount' => $total_refund,
        'status' => 'completed',
        'created_by' => $current_user->user_id,
    ]);
    if (!$return_id) throw new RuntimeException('Could not create return.');
    foreach ($items_to_return as $it) {
        db_insert('sales_return_items', [
            'return_id' => $return_id,
            'tenant_id' => $tenant_id,
            'product_id' => $it['product_id'],
            'quantity' => $it['quantity'],
            'unit_price' => $it['unit_price'],
            'refund_amount' => $it['refund'],
        ]);
        // restore stock
        $exists = fetch_one("SELECT stock_id FROM inventory_stock WHERE tenant_id = ? AND warehouse_id = ? AND product_id = ?", $tenant_id, $warehouse_id, $it['product_id']);
        if ($exists) {
            $stmt = $conn->prepare("UPDATE inventory_stock SET quantity = quantity + ? WHERE tenant_id = ? AND warehouse_id = ? AND product_id = ?");
            $stmt->bind_param('diii', $it['quantity'], $tenant_id, $warehouse_id, $it['product_id']);
            $stmt->execute(); $stmt->close();
        } else {
            db_insert('inventory_stock', ['tenant_id'=>$tenant_id,'warehouse_id'=>$warehouse_id,'product_id'=>$it['product_id'],'quantity'=>$it['quantity']]);
        }
        // movement
        db_insert('inventory_movements', [
            'tenant_id' => $tenant_id,
            'product_id' => $it['product_id'],
            'warehouse_id' => $warehouse_id,
            'movement_type' => 'sales_return',
            'quantity' => $it['quantity'],
            'reference_id' => $return_id,
            'reference_type' => 'sales_return',
            'created_by' => $current_user->user_id,
            'notes' => 'POS sales return',
        ]);
    }
    $conn->commit();
    flash_set('success', 'Sales return processed. Refund: ' . number_format($total_refund, 2));
    redirect('sales-returns.php');
} catch (Throwable $e) {
    $conn->rollback();
    flash_set('danger', 'Could not process return: ' . $e->getMessage());
    redirect('sales-returns.php');
}
