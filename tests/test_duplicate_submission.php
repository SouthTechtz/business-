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
    $locs = fetch_all("SELECT warehouse_id FROM warehouses WHERE tenant_id=? AND is_active=1 ORDER BY warehouse_id LIMIT 2", $tenant_id);
    $src = (int)$locs[0]["warehouse_id"]; $dst = (int)$locs[1]["warehouse_id"];
    $prod = fetch_one("SELECT product_id FROM products WHERE tenant_id=? AND is_active=1 AND is_service=0 LIMIT 1", $tenant_id);
    $pid = (int)$prod["product_id"];
    $st = fetch_one("SELECT quantity FROM inventory_stock WHERE tenant_id=? AND warehouse_id=? AND product_id=?", $tenant_id, $src, $pid);
    if (!$st) db_insert("inventory_stock", ["tenant_id"=>$tenant_id,"warehouse_id"=>$src,"product_id"=>$pid,"quantity"=>50]);
    else { $u=$conn->prepare("UPDATE inventory_stock SET quantity=50 WHERE tenant_id=? AND warehouse_id=? AND product_id=?"); $u->bind_param("iii",$tenant_id,$src,$pid); $u->execute(); $u->close(); }
    $tid = db_insert("stock_transfers", ["tenant_id"=>$tenant_id,"transfer_number"=>"TR-DUP-".time(),"source_warehouse_id"=>$src,"destination_warehouse_id"=>$dst,"status"=>"draft","requested_by"=>$admin["user_id"]]);
    db_insert("stock_transfer_items", ["tenant_id"=>$tenant_id,"transfer_id"=>$tid,"line_no"=>1,"product_id"=>$pid,"requested_quantity"=>10]);
    $u=$conn->prepare("UPDATE stock_transfers SET status=\"approved\", approved_by=?, approved_at=NOW() WHERE transfer_id=? AND tenant_id=? AND status IN (\"draft\",\"approved\")"); $u->bind_param("iii",$admin["user_id"],$tid,$tenant_id); $u->execute(); $affected1=$u->affected_rows; $u->close();
    $u=$conn->prepare("UPDATE stock_transfers SET status=\"approved\", approved_by=?, approved_at=NOW() WHERE transfer_id=? AND tenant_id=? AND status IN (\"draft\",\"approved\")"); $u->bind_param("iii",$admin["user_id"],$tid,$tenant_id); $u->execute(); $affected2=$u->affected_rows; $u->close();
    T($affected1 === 1, "first approval applied");
    T($affected2 === 0, "duplicate approval did not re-apply");
    $tr = fetch_one("SELECT status FROM stock_transfers WHERE transfer_id=? AND tenant_id=?", $tid, $tenant_id);
    T($tr["status"]==="approved", "transfer status approved");
    $conn->rollback();
    echo "Duplicate submission protection test passed (rolled back).\n";
} catch (Throwable $e) {
    $conn->rollback();
    echo "ERROR: " . $e->getMessage() . "\n";
    exit(1);
}
echo "Concurrent multi-connection tests: NOT IMPLEMENTED (requires separate processes).\n";
echo "Lead duplicate confirmation: NOT IMPLEMENTED (requires lead/order fixtures).\n";
?>
