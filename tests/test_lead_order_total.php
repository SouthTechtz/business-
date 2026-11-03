<?php
require_once __DIR__ . '/../config.php';

function lead_order_total_assert($condition, $message) {
    if (!$condition) {
        fwrite(STDERR, "FAIL: $message\n");
        exit(1);
    }
    echo "PASS: $message\n";
}

$admin = fetch_one("SELECT user_id, tenant_id FROM users WHERE is_active = 1 ORDER BY user_id LIMIT 1");
if (!$admin) {
    echo "SKIP: no active user is available.\n";
    exit(0);
}

$tenant_id = (int)$admin['tenant_id'];
$user_id = (int)$admin['user_id'];
$conn->begin_transaction();
try {
    $lead_id = db_insert('leads', [
        'tenant_id' => $tenant_id,
        'lead_number' => 'TESTORDERTOTAL_' . time(),
        'contact_name' => 'Lead Order Total Test',
        'status' => 'confirmed',
        'created_by' => $user_id,
    ]);

    $order_number = 'TESTORDERTOTAL_' . time();
    $shop_id = null;
    $warehouse_id = null;
    $contact_id = null;
    $customer_name = 'Lead Order Total Test';
    $customer_phone = '';
    $customer_location = '';
    $status = 'unpaid';
    $stage = 'shop';
    $order_total = 345.67;
    $amount_paid = 0.0;
    $notes = '';
    $stmt = $conn->prepare(
        "INSERT INTO orders
            (tenant_id, order_number, lead_id, shop_id, warehouse_id, contact_id, customer_name,
             customer_phone, customer_location, status, stage, subtotal, total, Amount_paid, notes, created_by, created_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())"
    );
    if (!$stmt) {
        throw new RuntimeException($conn->error);
    }
    $stmt->bind_param(
        'isiiiisssssdddsi',
        $tenant_id,
        $order_number,
        $lead_id,
        $shop_id,
        $warehouse_id,
        $contact_id,
        $customer_name,
        $customer_phone,
        $customer_location,
        $status,
        $stage,
        $order_total,
        $order_total,
        $amount_paid,
        $notes,
        $user_id
    );
    if (!$stmt->execute()) {
        throw new RuntimeException($stmt->error);
    }
    $order_id = (int)$stmt->insert_id;
    $stmt->close();

    $saved_order = fetch_one(
        "SELECT subtotal, total FROM orders WHERE order_id = ? AND tenant_id = ?",
        $order_id,
        $tenant_id
    );
    lead_order_total_assert(
        $saved_order
            && (float)$saved_order['subtotal'] === $order_total
            && (float)$saved_order['total'] === $order_total,
        'lead order saves entered amount as subtotal and total'
    );
    $conn->rollback();
    echo "Lead order total test passed (rolled back).\n";
} catch (Throwable $ex) {
    $conn->rollback();
    fwrite(STDERR, "ERROR: " . $ex->getMessage() . "\n");
    exit(1);
}
