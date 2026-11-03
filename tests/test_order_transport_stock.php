<?php
require_once __DIR__ . '/../config.php';

function order_transport_assert($condition, $message) {
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
$warehouse = fetch_one(
    "SELECT warehouse_id, shop_id FROM warehouses
      WHERE tenant_id = ? AND is_active = 1 AND location_type = 'store' AND shop_id IS NOT NULL
      ORDER BY warehouse_id LIMIT 1",
    $tenant_id
);
$product = fetch_one(
    "SELECT product_id, code, name FROM products
      WHERE tenant_id = ? AND is_active = 1 AND is_service = 0
      ORDER BY product_id LIMIT 1",
    $tenant_id
);
if (!$warehouse || !$product) {
    echo "SKIP: an active store and stockable product are required.\n";
    exit(0);
}

$warehouse_id = (int)$warehouse['warehouse_id'];
$shop_id = (int)$warehouse['shop_id'];
$product_id = (int)$product['product_id'];
$conn->begin_transaction();
try {
    $stock = fetch_one(
        "SELECT stock_id FROM inventory_stock
          WHERE tenant_id = ? AND warehouse_id = ? AND product_id = ?",
        $tenant_id,
        $warehouse_id,
        $product_id
    );
    if ($stock) {
        $stmt = $conn->prepare(
            "UPDATE inventory_stock SET quantity = 20
              WHERE tenant_id = ? AND warehouse_id = ? AND product_id = ?"
        );
        $stmt->bind_param('iii', $tenant_id, $warehouse_id, $product_id);
        if (!$stmt->execute()) throw new RuntimeException($stmt->error);
        $stmt->close();
    } else {
        $stock_id = db_insert('inventory_stock', [
            'tenant_id' => $tenant_id,
            'warehouse_id' => $warehouse_id,
            'product_id' => $product_id,
            'quantity' => 20.0,
        ]);
        if (!$stock_id) throw new RuntimeException('Could not create test inventory stock.');
    }

    $order_id = db_insert('orders', [
        'tenant_id' => $tenant_id,
        'order_number' => 'TEST-TRANSPORT-' . uniqid(),
        'shop_id' => $shop_id,
        'warehouse_id' => $warehouse_id,
        'customer_name' => 'Transport Stock Test',
        'customer_phone' => '',
        'customer_location' => '',
        'status' => 'confirmed',
        'stage' => 'shop',
        'subtotal' => 3.0,
        'total' => 3.0,
        'created_by' => $user_id,
    ]);
    if (!$order_id) throw new RuntimeException('Could not create test order.');

    $order_item_id = db_insert('order_items', [
        'tenant_id' => $tenant_id,
        'order_id' => $order_id,
        'line_no' => 1,
        'product_id' => $product_id,
        'product_code_snapshot' => (string)$product['code'],
        'product_name_snapshot' => (string)$product['name'],
        'product_description_snapshot' => '',
        'unit_label_snapshot' => '',
        'quantity' => 3.0,
        'unit_price' => 1.0,
        'tax_rate_snapshot' => 0.0,
        'line_total' => 3.0,
    ]);
    if (!$order_item_id) throw new RuntimeException('Could not create test order item.');

    $reservation_id = db_insert('order_stock_reservations', [
        'tenant_id' => $tenant_id,
        'order_id' => $order_id,
        'order_item_id' => $order_item_id,
        'product_id' => $product_id,
        'warehouse_id' => $warehouse_id,
        'quantity' => 3.0,
        'status' => 'active',
        'created_by' => $user_id,
    ]);
    if (!$reservation_id) throw new RuntimeException('Could not create test order reservation.');

    dispatch_order_stock_for_transport($order_id, $tenant_id, $user_id);
    $stmt = $conn->prepare(
        "UPDATE orders SET stage = 'transport' WHERE order_id = ? AND tenant_id = ?"
    );
    $stmt->bind_param('ii', $order_id, $tenant_id);
    if (!$stmt->execute()) throw new RuntimeException($stmt->error);
    $stmt->close();

    $stock_after_dispatch = fetch_one(
        "SELECT quantity FROM inventory_stock
          WHERE tenant_id = ? AND warehouse_id = ? AND product_id = ?",
        $tenant_id,
        $warehouse_id,
        $product_id
    );
    $reservation = fetch_one(
        "SELECT status, consumed_invoice_id FROM order_stock_reservations
          WHERE order_id = ? AND tenant_id = ?",
        $order_id,
        $tenant_id
    );
    $movement_count = fetch_one(
        "SELECT COUNT(*) movement_count FROM inventory_movements
          WHERE tenant_id = ? AND reference_id = ?
            AND reference_type = 'order_transport' AND movement_type = 'order_transport'",
        $tenant_id,
        $order_id
    );
    order_transport_assert((float)$stock_after_dispatch['quantity'] === 17.0, 'transport deducts the reserved order quantity exactly once');
    order_transport_assert(
        $reservation && $reservation['status'] === 'consumed' && $reservation['consumed_invoice_id'] === null,
        'transport consumes the reservation without tying it to a future invoice'
    );
    order_transport_assert((int)$movement_count['movement_count'] === 1, 'transport records an auditable stock movement');
    order_transport_assert(order_stock_was_dispatched($order_id, $tenant_id), 'invoice workflow can recognize stock already dispatched');

    $dispatch_error = '';
    try {
        dispatch_order_stock_for_transport($order_id, $tenant_id, $user_id);
    } catch (RuntimeException $ex) {
        $dispatch_error = $ex->getMessage();
    }
    $stock_after_repeat = fetch_one(
        "SELECT quantity FROM inventory_stock
          WHERE tenant_id = ? AND warehouse_id = ? AND product_id = ?",
        $tenant_id,
        $warehouse_id,
        $product_id
    );
    order_transport_assert($dispatch_error !== '' && (float)$stock_after_repeat['quantity'] === 17.0, 'a repeated transport request cannot deduct stock again');

    $stmt = $conn->prepare(
        "UPDATE inventory_stock SET quantity = 1
          WHERE tenant_id = ? AND warehouse_id = ? AND product_id = ?"
    );
    $stmt->bind_param('iii', $tenant_id, $warehouse_id, $product_id);
    if (!$stmt->execute()) throw new RuntimeException($stmt->error);
    $stmt->close();

    $insufficient_order_id = db_insert('orders', [
        'tenant_id' => $tenant_id,
        'order_number' => 'TEST-TRANSPORT-LOW-' . uniqid(),
        'shop_id' => $shop_id,
        'warehouse_id' => $warehouse_id,
        'customer_name' => 'Transport Stock Insufficient Test',
        'customer_phone' => '',
        'customer_location' => '',
        'status' => 'confirmed',
        'stage' => 'shop',
        'subtotal' => 2.0,
        'total' => 2.0,
        'created_by' => $user_id,
    ]);
    if (!$insufficient_order_id) throw new RuntimeException('Could not create insufficient-stock order.');
    $insufficient_item_id = db_insert('order_items', [
        'tenant_id' => $tenant_id,
        'order_id' => $insufficient_order_id,
        'line_no' => 1,
        'product_id' => $product_id,
        'product_code_snapshot' => (string)$product['code'],
        'product_name_snapshot' => (string)$product['name'],
        'product_description_snapshot' => '',
        'unit_label_snapshot' => '',
        'quantity' => 2.0,
        'unit_price' => 1.0,
        'tax_rate_snapshot' => 0.0,
        'line_total' => 2.0,
    ]);
    if (!$insufficient_item_id) throw new RuntimeException('Could not create insufficient-stock order item.');

    $insufficient_error = '';
    try {
        dispatch_order_stock_for_transport($insufficient_order_id, $tenant_id, $user_id);
    } catch (RuntimeException $ex) {
        $insufficient_error = $ex->getMessage();
    }
    $stock_after_failure = fetch_one(
        "SELECT quantity FROM inventory_stock
          WHERE tenant_id = ? AND warehouse_id = ? AND product_id = ?",
        $tenant_id,
        $warehouse_id,
        $product_id
    );
    order_transport_assert(
        $insufficient_error !== '' && (float)$stock_after_failure['quantity'] === 1.0,
        'insufficient stock rejects dispatch without changing inventory'
    );

    $conn->rollback();
    echo "Order transport stock test passed (rolled back).\n";
} catch (Throwable $ex) {
    $conn->rollback();
    fwrite(STDERR, "ERROR: " . $ex->getMessage() . "\n");
    exit(1);
}
