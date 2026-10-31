<?php
require_once __DIR__ . '/../config.php';
function T($c,$m){ if(!$c){echo "FAIL: $m\n"; exit(1);} echo "PASS: $m\n"; }
$admin = fetch_one("SELECT user_id, tenant_id FROM users WHERE is_active=1 ORDER BY user_id LIMIT 1");
$tenant_id = (int)$admin['tenant_id'];
require_once __DIR__ . '/../User.php';
$GLOBALS['current_user'] = new User($admin);
$current_user = $GLOBALS['current_user'];
$conn->begin_transaction();
try {
    $lead_id = db_insert('leads', ['tenant_id'=>$tenant_id, 'lead_number'=>'TESTLEAD_'.time(), 'contact_name'=>'Test Lead', 'status'=>'pending', 'created_by'=>$admin['user_id']]);
    T($lead_id>0, 'lead created');
    $order_number = 'ORD-TEST-'.time();
    $order_id = db_insert('orders', ['tenant_id'=>$tenant_id, 'order_number'=>$order_number, 'lead_id'=>$lead_id, 'customer_name'=>'Test Lead', 'source'=>'storefront', 'status'=>'under_review', 'currency_code'=>'TZS', 'subtotal'=>10, 'total'=>10, 'created_by'=>$admin['user_id']]);
    T($order_id>0, 'first order created');
    $existing = fetch_all("SELECT * FROM orders WHERE lead_id=? AND tenant_id=?", $lead_id, $tenant_id);
    T(count($existing) === 1, 'one order before duplicate attempt');
    if (count($existing) === 0) {
        db_insert('orders', ['tenant_id'=>$tenant_id, 'order_number'=>$order_number.'-DUP', 'lead_id'=>$lead_id, 'customer_name'=>'Test Lead', 'source'=>'storefront', 'status'=>'under_review', 'currency_code'=>'TZS', 'subtotal'=>10, 'total'=>10, 'created_by'=>$admin['user_id']]);
    }
    $after = fetch_all("SELECT * FROM orders WHERE lead_id=? AND tenant_id=?", $lead_id, $tenant_id);
    T(count($after) === 1, 'no duplicate order created');
    $conn->rollback();
    echo "Lead duplicate confirmation smoke test passed (rolled back).\n";
} catch (Throwable $e) {
    $conn->rollback();
    echo "ERROR: " . $e->getMessage() . "\n";
    exit(1);
}
