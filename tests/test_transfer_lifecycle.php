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
    // Transfer lifecycle
    $locs = fetch_all("SELECT warehouse_id FROM warehouses WHERE tenant_id=? AND is_active=1 ORDER BY warehouse_id LIMIT 2", $tenant_id);
    T(count($locs)>=2, 'two locations');
    $src=(int)$locs[0]['warehouse_id']; $dst=(int)$locs[1]['warehouse_id'];
    $prod = fetch_one("SELECT product_id FROM products WHERE tenant_id=? AND is_active=1 AND is_service=0 LIMIT 1", $tenant_id);
    T($prod, 'product');
    $pid=(int)$prod['product_id'];
    // ensure source stock
    $st = fetch_one("SELECT quantity FROM inventory_stock WHERE tenant_id=? AND warehouse_id=? AND product_id=?", $tenant_id, $src, $pid);
    if (!$st) db_insert('inventory_stock',['tenant_id'=>$tenant_id,'warehouse_id'=>$src,'product_id'=>$pid,'quantity'=>100]);
    else { $u=$conn->prepare("UPDATE inventory_stock SET quantity=100 WHERE tenant_id=? AND warehouse_id=? AND product_id=?"); $u->bind_param('iii',$tenant_id,$src,$pid); $u->execute(); $u->close(); }
    $tn='TR-TEST-'.time();
    $tid=db_insert('stock_transfers',['tenant_id'=>$tenant_id,'transfer_number'=>$tn,'source_warehouse_id'=>$src,'destination_warehouse_id'=>$dst,'status'=>'draft','requested_by'=>$admin['user_id']]);
    T($tid>0,'transfer created');
    db_insert('stock_transfer_items',['tenant_id'=>$tenant_id,'transfer_id'=>$tid,'line_no'=>1,'product_id'=>$pid,'requested_quantity'=>10]);
    // approve
    $u=$conn->prepare("UPDATE stock_transfers SET status='approved', approved_by=?, approved_at=NOW() WHERE transfer_id=? AND tenant_id=?"); $u->bind_param('iii',$admin['user_id'],$tid,$tenant_id); $u->execute(); $u->close();
    // dispatch
    $u=$conn->prepare("UPDATE stock_transfer_items SET dispatched_quantity=10 WHERE transfer_id=? AND tenant_id=?"); $u->bind_param('ii',$tid,$tenant_id); $u->execute(); $u->close();
    $u=$conn->prepare("UPDATE inventory_stock SET quantity=quantity-10 WHERE tenant_id=? AND warehouse_id=? AND product_id=?"); $u->bind_param('iii',$tenant_id,$src,$pid); $u->execute(); $u->close();
    $u=$conn->prepare("UPDATE stock_transfers SET status='dispatched', dispatched_by=?, dispatched_at=NOW() WHERE transfer_id=? AND tenant_id=?"); $u->bind_param('iii',$admin['user_id'],$tid,$tenant_id); $u->execute(); $u->close();
    $dstst = fetch_one("SELECT 1 FROM inventory_stock WHERE tenant_id=? AND warehouse_id=? AND product_id=?", $tenant_id, $dst, $pid);
    if (!$dstst) db_insert('inventory_stock', ['tenant_id'=>$tenant_id, 'warehouse_id'=>$dst, 'product_id'=>$pid, 'quantity'=>0]);
    // receive
    $u=$conn->prepare("UPDATE stock_transfer_items SET received_quantity=10 WHERE transfer_id=? AND tenant_id=?"); $u->bind_param('ii',$tid,$tenant_id); $u->execute(); $u->close();
    $u=$conn->prepare("UPDATE inventory_stock SET quantity=quantity+10 WHERE tenant_id=? AND warehouse_id=? AND product_id=?"); $u->bind_param('iii',$tenant_id,$dst,$pid); $u->execute(); $u->close();
    $u=$conn->prepare("UPDATE stock_transfers SET status='received', received_by=?, received_at=NOW() WHERE transfer_id=? AND tenant_id=?"); $u->bind_param('iii',$admin['user_id'],$tid,$tenant_id); $u->execute(); $u->close();
    $srcr = fetch_one("SELECT quantity FROM inventory_stock WHERE tenant_id=? AND warehouse_id=? AND product_id=?", $tenant_id, $src, $pid);
    $dstr = fetch_one("SELECT quantity FROM inventory_stock WHERE tenant_id=? AND warehouse_id=? AND product_id=?", $tenant_id, $dst, $pid);
    T((float)$srcr['quantity'] === 90.0, 'source decremented after transfer');
    T($dstr && (float)$dstr['quantity'] >= 10.0, 'destination incremented after transfer');
    $conn->rollback();
    echo "Transfer lifecycle smoke test passed (rolled back).\n";
} catch (Throwable $e) {
    $conn->rollback();
    echo "ERROR: " . $e->getMessage() . "\n";
    exit(1);
}

echo "POS and Lead tests: NOT IMPLEMENTED in this script (require full workflow/session fixtures).\n";
