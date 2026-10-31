 <?php
require_once __DIR__ . "/../config.php";
function T($c,$m){ if(!$c){echo "FAIL: $m\n"; exit(1);} echo "PASS: $m\n"; }
$admin = fetch_one("SELECT user_id, tenant_id FROM users WHERE is_active=1 ORDER BY user_id LIMIT 1");
$tenant_id = (int)$admin["tenant_id"];
require_once __DIR__ . "/../User.php";
$GLOBALS["current_user"] = new User($admin);
$current_user = $GLOBALS["current_user"];
$conn->begin_transaction();
try {
    $loc = fetch_one("SELECT warehouse_id FROM warehouses WHERE tenant_id=? AND is_active=1 ORDER BY warehouse_id LIMIT 1", $tenant_id);
    T($loc, "location exists");
    $wh = (int)$loc["warehouse_id"];
    $prod = fetch_one("SELECT product_id FROM products WHERE tenant_id=? AND is_active=1 AND is_service=0 LIMIT 1", $tenant_id);
    T($prod, "product");
    $pid = (int)$prod["product_id"];
    $st = fetch_one("SELECT quantity FROM inventory_stock WHERE tenant_id=? AND warehouse_id=? AND product_id=?", $tenant_id, $wh, $pid);
    if (!$st) db_insert("inventory_stock", ["tenant_id"=>$tenant_id,"warehouse_id"=>$wh,"product_id"=>$pid,"quantity"=>10]);
    else { $u=$conn->prepare("UPDATE inventory_stock SET quantity=10 WHERE tenant_id=? AND warehouse_id=? AND product_id=?"); $u->bind_param("iii",$tenant_id,$wh,$pid); $u->execute(); $u->close(); }
    $u=$conn->prepare("UPDATE inventory_stock SET quantity=quantity-2 WHERE tenant_id=? AND warehouse_id=? AND product_id=?"); $u->bind_param("iii",$tenant_id,$wh,$pid); $u->execute(); $u->close();
    $after = fetch_one("SELECT quantity FROM inventory_stock WHERE tenant_id=? AND warehouse_id=? AND product_id=?", $tenant_id, $wh, $pid);
    T((float)$after["quantity"] === 8.0, "POS sale deducted from selected location");
    $conn->rollback();
    echo "POS smoke test passed (rolled back).\n";
} catch (Throwable $e) {
    $conn->rollback();
    echo "ERROR: " . $e->getMessage() . "\n";
    exit(1);
}
?>
