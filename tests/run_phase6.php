<?php
require_once __DIR__ . '/../config.test.php';

function test_assert($cond, $msg) {
    if (!$cond) { echo "FAIL: $msg\n"; exit(1); }
    echo "PASS: $msg\n";
}

$admin = fetch_one("SELECT user_id, tenant_id FROM users WHERE is_active=1 ORDER BY user_id LIMIT 1");
test_assert($admin, 'admin user exists');
$tenant_id = (int)$admin['tenant_id'];

require_once __DIR__ . '/../User.php';
$current_user = new User($admin);

$name = 'TESTSHOP_' . time();
$code = 'TST' . time();
$conn->begin_transaction();
try {
    $shop_id = db_insert('shops', [
        'tenant_id' => $tenant_id,
        'name' => $name,
        'code' => $code,
        'is_active' => 1,
    ]);
    test_assert($shop_id > 0, 'shop created');
    $loc_id = db_insert('warehouses', [
        'tenant_id' => $tenant_id,
        'shop_id' => $shop_id,
        'name' => $name,
        'code' => $code,
        'is_active' => 1,
        'location_type' => 'shop',
    ]);
    test_assert($loc_id > 0, 'shop location created');
    $cnt = fetch_one("SELECT COUNT(*) c FROM warehouses WHERE tenant_id=? AND shop_id=? AND location_type='shop'", $tenant_id, $shop_id);
    test_assert((int)$cnt['c'] === 1, 'exactly one shop location');
    $conn->rollback();
    echo "All tests passed (rolled back).\n";
} catch (Throwable $e) {
    $conn->rollback();
    echo "ERROR: " . $e->getMessage() . "\n";
    exit(1);
}
