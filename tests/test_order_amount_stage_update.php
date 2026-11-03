<?php
require_once __DIR__ . '/../config.php';

function order_amount_stage_assert($condition, $message) {
    if (!$condition) {
        fwrite(STDERR, "FAIL: $message\n");
        exit(1);
    }
    echo "PASS: $message\n";
}

$user = fetch_one("SELECT user_id, tenant_id FROM users WHERE is_active = 1 ORDER BY user_id LIMIT 1");
if (!$user) {
    echo "SKIP: no active user is available.\n";
    exit(0);
}

$tenant_id = (int)$user['tenant_id'];
$user_id = (int)$user['user_id'];
$conn->begin_transaction();
try {
    $order_number = 'TESTORDEREDIT_' . time();
    $customer_name = 'Order Edit Test';
    $customer_phone = '';
    $customer_location = '';
    $status = 'new';
    $old_total = 10.00;
    $old_paid = 0.00;
    $stmt = $conn->prepare(
        "INSERT INTO orders
            (tenant_id, order_number, customer_name, customer_phone, customer_location, status, stage,
             subtotal, total, Amount_paid, created_by)
         VALUES (?, ?, ?, ?, ?, ?, 'shop', ?, ?, ?, ?)"
    );
    if (!$stmt) {
        throw new RuntimeException($conn->error);
    }
    $stmt->bind_param(
        'isssssdddi',
        $tenant_id,
        $order_number,
        $customer_name,
        $customer_phone,
        $customer_location,
        $status,
        $old_total,
        $old_total,
        $old_paid,
        $user_id
    );
    if (!$stmt->execute()) {
        throw new RuntimeException($stmt->error);
    }
    $order_id = (int)$stmt->insert_id;
    $stmt->close();

    $shop_id = null;
    $warehouse_id = null;
    $contact_id = null;
    $new_total = 250.75;
    $new_paid = 50.00;
    $stage = 'completed';
    $notes = '';
    $stmt = $conn->prepare(
        "UPDATE orders
            SET shop_id = ?, warehouse_id = ?, contact_id = ?, customer_name = ?,
                customer_phone = ?, customer_location = ?, subtotal = ?, total = ?, stage = ?,
                Amount_paid = ?, notes = ?
          WHERE order_id = ? AND tenant_id = ?"
    );
    if (!$stmt) {
        throw new RuntimeException($conn->error);
    }
    $stmt->bind_param(
        'iiisssddsdsii',
        $shop_id,
        $warehouse_id,
        $contact_id,
        $customer_name,
        $customer_phone,
        $customer_location,
        $new_total,
        $new_total,
        $stage,
        $new_paid,
        $notes,
        $order_id,
        $tenant_id
    );
    if (!$stmt->execute()) {
        throw new RuntimeException($stmt->error);
    }
    $stmt->close();

    $saved = fetch_one(
        "SELECT status, stage, subtotal, total, Amount_paid
           FROM orders WHERE order_id = ? AND tenant_id = ?",
        $order_id,
        $tenant_id
    );
    order_amount_stage_assert(
        $saved
            && $saved['status'] === 'new'
            && $saved['stage'] === 'completed'
            && (float)$saved['subtotal'] === $new_total
            && (float)$saved['total'] === $new_total
            && (float)$saved['Amount_paid'] === $new_paid,
        'order total, amount paid, and stage update without changing workflow status'
    );

    $conn->rollback();
    echo "Order amount and stage test passed (rolled back).\n";
} catch (Throwable $ex) {
    $conn->rollback();
    fwrite(STDERR, "ERROR: " . $ex->getMessage() . "\n");
    exit(1);
}
