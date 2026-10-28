<?php
require_once 'config.php';
require_once 'auth_check.php';
require_permission('manage_pos');

$id = (int)($_GET['id'] ?? 0);
$order = fetch_one("SELECT * FROM pos_orders WHERE order_id = ? AND tenant_id = ?", $id, $current_user->tenant_id);
if (!$order) {
    flash_set('danger', 'Sale not found.');
    redirect('sales.php');
}
if ((int)($order['warehouse_id'] ?? 0) > 0 && !store_is_accessible((int)$order['warehouse_id'], true)) {
    flash_set('danger', 'You do not have access to the store for this sale.');
    redirect('sales.php');
}

$conn->begin_transaction();
try {
    $locked_order = fetch_one(
        "SELECT * FROM pos_orders WHERE order_id = ? AND tenant_id = ? FOR UPDATE",
        $id,
        $current_user->tenant_id
    );
    if (!$locked_order) {
        throw new Exception('Sale not found.');
    }

    // Deleting a live sale must not silently lose its stock. A cancelled sale
    // already restored stock during cancellation.
    if ($locked_order['status'] !== 'cancelled') {
        $items = fetch_all("SELECT * FROM pos_order_items WHERE order_id = ?", $id);
        foreach ($items as $item) {
            $product = fetch_one(
                "SELECT is_service FROM products WHERE product_id = ? AND tenant_id = ?",
                $item['product_id'],
                $current_user->tenant_id
            );
            if ($product && (int)$product['is_service'] === 1) {
                continue;
            }

            $item_warehouse_id = (int)($item['warehouse_id'] ?? 0);
            if (!$item_warehouse_id || !find_accessible_store($item_warehouse_id, true)) {
                throw new Exception('Cannot delete: an item has an unknown or inaccessible store. No stock was changed.');
            }

            $stmt = $conn->prepare("UPDATE inventory_stock SET quantity = quantity + ? WHERE product_id = ? AND warehouse_id = ? AND tenant_id = ?");
            $stmt->bind_param('diii', $item['quantity'], $item['product_id'], $item_warehouse_id, $current_user->tenant_id);
            $stmt->execute();
            $stmt->close();

            $stmt = $conn->prepare(
                "INSERT INTO inventory_movements (tenant_id, product_id, warehouse_id, movement_type, quantity, reference_id, reference_type, created_by)
                 VALUES (?, ?, ?, 'sale_delete', ?, ?, 'pos_order', ?)"
            );
            $stmt->bind_param('iiidii', $current_user->tenant_id, $item['product_id'], $item_warehouse_id, $item['quantity'], $id, $current_user->user_id);
            $stmt->execute();
            $stmt->close();
        }
    }

    $stmt = $conn->prepare("DELETE FROM pos_order_items WHERE order_id = ?");
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $stmt->close();

    $stmt = $conn->prepare("DELETE FROM pos_payments WHERE order_id = ?");
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $stmt->close();

    $stmt = $conn->prepare("DELETE FROM pos_orders WHERE order_id = ? AND tenant_id = ?");
    $stmt->bind_param('ii', $id, $current_user->tenant_id);
    $stmt->execute();
    $stmt->close();

    $conn->commit();
    audit_log('delete', 'pos_order', $id, 'Sale ' . $order['order_number'] . ' deleted');
    flash_set('success', 'Sale deleted permanently. Stock was restored before deletion.');
} catch (Exception $ex) {
    $conn->rollback();
    flash_set('danger', 'Deletion failed: ' . $ex->getMessage());
}
redirect('sales.php');
