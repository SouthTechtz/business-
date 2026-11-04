<?php
require_once __DIR__ . '/wholesale_auth.php';

$tenant_id = (int)$wholesale_user->tenant_id;
$contact_id = (int)($wholesaler['contact_id'] ?? 0);
$order_id = (int)($_POST['order_id'] ?? 0);
$request_id = (int)($_POST['request_id'] ?? 0);
$back = $request_id > 0
    ? 'wholesale-order-view.php?id=' . $request_id
    : 'wholesale-orders.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || $order_id <= 0 || $contact_id <= 0) {
    flash_set('danger', 'That order could not be confirmed.');
    redirect('wholesale-orders.php');
}

verify_csrf();

$order = wholesale_order($tenant_id, $order_id, $contact_id);
if (!$order) {
    flash_set('danger', 'That order does not belong to your account.');
    redirect('wholesale-orders.php');
}
if (!wholesale_order_can_confirm_receipt($order)) {
    flash_set('danger', 'This order cannot be confirmed as received right now.');
    redirect($back);
}

$conn->begin_transaction();
try {
    $locked = fetch_one(
        "SELECT * FROM orders WHERE order_id = ? AND tenant_id = ? AND contact_id = ? FOR UPDATE",
        $order_id,
        $tenant_id,
        $contact_id
    );
    if (!$locked || !wholesale_order_can_confirm_receipt($locked)) {
        throw new RuntimeException('This order can no longer be confirmed as received.');
    }

    $stmt = $conn->prepare(
        "UPDATE orders
            SET stage = 'completed',
                customer_confirmed_at = NOW(),
                customer_confirmed_by = ?
          WHERE order_id = ? AND tenant_id = ? AND customer_confirmed_at IS NULL"
    );
    if (!$stmt) throw new RuntimeException($conn->error);
    $stmt->bind_param('iii', $wholesale_user->user_id, $order_id, $tenant_id);
    if (!$stmt->execute()) throw new RuntimeException($stmt->error);
    if ($stmt->affected_rows !== 1) {
        throw new RuntimeException('This order was already confirmed as received.');
    }
    $stmt->close();

    audit_log(
        'update',
        'order',
        $order_id,
        'Wholesale customer confirmed order ' . $locked['order_number'] . ' as received'
    );
    $conn->commit();
    flash_set('success', 'Thank you. Order ' . $locked['order_number'] . ' is now marked as received and completed.');
} catch (Throwable $ex) {
    $conn->rollback();
    flash_set('danger', $ex->getMessage());
}

redirect($back);
