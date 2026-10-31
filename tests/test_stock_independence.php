<?php
require_once __DIR__ . '/../config.test.php';
function test_assert($cond, $msg) { if (!$cond) { echo "FAIL: $msg\n"; exit(1);} echo "PASS: $msg\n"; }
$admin = fetch_one("SELECT user_id, tenant_id FROM users WHERE is_active=1 ORDER BY user_id LIMIT 1");
$tenant_id = (int)$admin['tenant_id'];
require_once __DIR__ . '/../User.php';
$current_user = new User($admin);
// Find a product and two warehouses (store/godown/shop)
$whs = fetch_all("SELECT warehouse_id FROM warehouses WHERE tenant_id=? AND is_active=1 LIMIT 2", $tenant_id);
test_assert(count($whs) >= 2, 'at least two locations');
$pid = fetch_one("SELECT product_id FROM products WHERE tenant_id=? AND is_active=1 LIMIT 1", $tenant_id);
test_assert($pid, 'product exists');
$pid = (int)$pid['product_id'];
$w1 = (int)$whs[0]['warehouse_id'];
$w2 = (int)$whs[1]['warehouse_id'];
$conn->begin_transaction();
try {
    // set stock independently
    $s1 = fetch_one("SELECT 1 FROM inventory_stock WHERE tenant_id=? AND product_id=? AND warehouse_id=?", $tenant_id, $pid, $w1);
    if (!$s1) db_insert('inventory_stock', ['tenant_id'=>$tenant_id,'product_id'=>$pid,'warehouse_id'=>$w1,'quantity'=>10]);
    else { $st=$conn->prepare("UPDATE inventory_stock SET quantity=10 WHERE tenant_id=? AND product_id=? AND warehouse_id=?"); $st->bind_param('iii',$tenant_id,$pid,$w1); $st->execute(); $st->close(); }
    $s2 = fetch_one("SELECT 1 FROM inventory_stock WHERE tenant_id=? AND product_id=? AND warehouse_id=?", $tenant_id, $pid, $w2);
    if (!$s2) db_insert('inventory_stock', ['tenant_id'=>$tenant_id,'product_id'=>$pid,'warehouse_id'=>$w2,'quantity'=>5]);
    else { $st=$conn->prepare("UPDATE inventory_stock SET quantity=5 WHERE tenant_id=? AND product_id=? AND warehouse_id=?"); $st->bind_param('iii',$tenant_id,$pid,$w2); $st->execute(); $st->close(); }
    $r1 = fetch_one("SELECT quantity FROM inventory_stock WHERE tenant_id=? AND product_id=? AND warehouse_id=?", $tenant_id, $pid, $w1);
    $r2 = fetch_one("SELECT quantity FROM inventory_stock WHERE tenant_id=? AND product_id=? AND warehouse_id=?", $tenant_id, $pid, $w2);
    test_assert((float)$r1['quantity'] === 10.0, 'location 1 stock independent');
    test_assert((float)$r2['quantity'] === 5.0, 'location 2 stock independent');
    $conn->rollback();
    echo "All stock independence tests passed (rolled back).\n";
} catch (Throwable $e) {
    $conn->rollback();
    echo "ERROR: " . $e->getMessage() . "\n";
    exit(1);
}
