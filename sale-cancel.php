<?php
require_once 'config.php';
require_once 'auth_check.php';
require_permission('manage_pos');

$id = (int)($_GET['id'] ?? 0);
$order = fetch_one("SELECT * FROM pos_orders WHERE order_id = ? AND tenant_id = ? AND status != 'cancelled'", $id, $current_user->tenant_id);
if (!$order) {
    flash_set('danger', 'Sale not found or already cancelled.');
    redirect('sales.php');
}

$order_warehouse_id = (int)($order['warehouse_id'] ?? 0);
if (!$order_warehouse_id || !store_is_accessible($order_warehouse_id, true)) {
    http_response_code(403);
    die('You do not have access to the store for this sale.');
}

$conn->begin_transaction();
try {
    // Re-read and lock the sale so two cancel requests cannot restore twice.
    $locked_order = fetch_one(
        "SELECT * FROM pos_orders WHERE order_id = ? AND tenant_id = ? FOR UPDATE",
        $id,
        $current_user->tenant_id
    );
    if (!$locked_order || $locked_order['status'] === 'cancelled') {
        throw new Exception('Sale not found or already cancelled.');
    }

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

        // Restore to the item's original warehouse. If that warehouse is not
        // known or not accessible, stop rather than silently using another one.
        $item_warehouse_id = (int)($item['warehouse_id'] ?? 0);
        if (!$item_warehouse_id || !find_accessible_store($item_warehouse_id, true)) {
            throw new Exception('Cannot cancel: an item has an unknown or inaccessible store. No stock was changed.');
        }

        $stmt = $conn->prepare("UPDATE inventory_stock SET quantity = quantity + ? WHERE product_id = ? AND warehouse_id = ? AND tenant_id = ?");
        $stmt->bind_param('diii', $item['quantity'], $item['product_id'], $item_warehouse_id, $current_user->tenant_id);
        $stmt->execute();
        $stmt->close();

        $stmt = $conn->prepare(
            "INSERT INTO inventory_movements (tenant_id, product_id, warehouse_id, movement_type, quantity, reference_id, reference_type, created_by)
             VALUES (?, ?, ?, 'sale_cancel', ?, ?, 'pos_order', ?)"
        );
        $stmt->bind_param('iiidii', $current_user->tenant_id, $item['product_id'], $item_warehouse_id, $item['quantity'], $id, $current_user->user_id);
        $stmt->execute();
        $stmt->close();
    }

    $stmt = $conn->prepare("UPDATE pos_orders SET status = 'cancelled' WHERE order_id = ? AND tenant_id = ? AND status != 'cancelled'");
    $stmt->bind_param('ii', $id, $current_user->tenant_id);
    $stmt->execute();
    if ($stmt->affected_rows !== 1) {
        $stmt->close();
        throw new Exception('Sale was already cancelled by another request.');
    }
    $stmt->close();

    $conn->commit();
    audit_log('cancel', 'pos_order', $id, 'Sale ' . $order['order_number'] . ' cancelled');
    flash_set('success', 'Sale cancelled. Stock has been restored to the original store.');
} catch (Exception $ex) {
    $conn->rollback();
    flash_set('danger', 'Cancellation failed: ' . $ex->getMessage());
}
redirect('sale-details.php?id=' . $id);
