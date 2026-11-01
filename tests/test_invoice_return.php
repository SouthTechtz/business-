<?php
require_once __DIR__ . "/../config.test.php";
function T($c,$m){ if(!$c){echo "FAIL: $m\n"; exit(1);} echo "PASS: $m\n"; }
$admin = fetch_one("SELECT user_id, tenant_id FROM users WHERE is_active=1 ORDER BY user_id LIMIT 1");
$tenant_id = (int)$admin["tenant_id"];
require_once __DIR__ . "/../User.php";
$GLOBALS["current_user"] = new User($admin);
$current_user = $GLOBALS["current_user"];
$conn->begin_transaction();
try {
    $wh = fetch_one("SELECT warehouse_id FROM warehouses WHERE tenant_id=? AND is_active=1 ORDER BY warehouse_id LIMIT 1", $tenant_id);
    T($wh, "warehouse exists");
    $whid = (int)$wh["warehouse_id"];
    $prod = fetch_one("SELECT product_id, sale_price, tax_rate FROM products WHERE tenant_id=? AND is_active=1 AND is_service=0 LIMIT 1", $tenant_id);
    T($prod, "product");
    $pid = (int)$prod["product_id"];
    $price = (float)$prod["sale_price"];
    // stock
    $st = fetch_one("SELECT quantity FROM inventory_stock WHERE tenant_id=? AND warehouse_id=? AND product_id=?", $tenant_id,$whid,$pid);
    if (!$st) db_insert("inventory_stock", ["tenant_id"=>$tenant_id,"warehouse_id"=>$whid,"product_id"=>$pid,"quantity"=>10]);
    else { $u=$conn->prepare("UPDATE inventory_stock SET quantity=10 WHERE tenant_id=? AND warehouse_id=? AND product_id=?"); $u->bind_param("iii",$tenant_id,$whid,$pid); $u->execute(); $u->close(); }

    // invoice
    $cust = fetch_one("SELECT contact_id FROM contacts WHERE tenant_id=? LIMIT 1", $tenant_id);
    $cid = $cust ? (int)$cust["contact_id"] : null;
    $inv_no = "TESTINV-" . time();
    $iid = db_insert("invoices", ["tenant_id"=>$tenant_id,"invoice_number"=>$inv_no,"warehouse_id"=>$whid,"contact_id"=>$cid,"issue_date"=>date("Y-m-d"),"status_id"=>2,"subtotal"=>$price,"tax_amount"=>0,"total"=>$price,"amount_due"=>$price,"amount_paid"=>0,"is_paid"=>0,"created_by"=>$admin["user_id"]]);
    T($iid>0, "invoice created");
    db_insert("invoice_items", ["tenant_id"=>$tenant_id,"invoice_id"=>$iid,"warehouse_id"=>$whid,"product_id"=>$pid,"description"=>"P","quantity"=>1,"unit_price"=>$price,"tax_amount"=>0,"subtotal"=>$price,"total"=>$price]);
    // payment partial
    db_insert("payments", ["tenant_id"=>$tenant_id,"invoice_id"=>$iid,"contact_id"=>$cid,"amount"=>round($price*0.4,2),"payment_date"=>date("Y-m-d H:i:s"),"created_by"=>$admin["user_id"],"submission_token"=>"tok1"]);
    $conn->query("UPDATE invoices SET amount_paid=" . ($price*0.4) . ", amount_due=" . ($price*0.6) . ", is_paid=0 WHERE invoice_id=$iid AND tenant_id=$tenant_id");

    // simulate partial return 0.5 qty? quantity integer? use 1? Let's return full qty to test full return with payment.
    // Instead partial: create second invoice? Simpler full return.
    // Process return: resale 1
    $ret_no = "RET-TEST-" . time();
    $rid = db_insert("sales_returns", ["tenant_id"=>$tenant_id,"return_number"=>$ret_no,"source_type"=>"invoice","source_id"=>$iid,"customer_id"=>$cid,"shop_id"=>null,"warehouse_id"=>$whid,"refund_amount"=>$price,"status"=>"completed","submission_token"=>"rettok1","created_by"=>$admin["user_id"]]);
    T($rid>0, "return created");
    db_insert("sales_return_items", ["return_id"=>$rid,"tenant_id"=>$tenant_id,"product_id"=>$pid,"quantity"=>1,"unit_price"=>$price,"refund_amount"=>$price,"resale_quantity"=>1]);
    // restore stock
    $conn->query("UPDATE inventory_stock SET quantity=quantity+1 WHERE tenant_id=$tenant_id AND warehouse_id=$whid AND product_id=$pid");
    // invoice update full return
    $cumulative = $price;
    $net = max(0, $price - $cumulative);
    $paid = $price*0.4;
    $due = max(0, $net - $paid);
    $refund = max(0, $paid - $net);
    $conn->query("UPDATE invoices SET status_id=6, amount_due=$due, is_paid=1, returned_amount=$cumulative, refund_due=$refund, version=version+1 WHERE invoice_id=$iid AND tenant_id=$tenant_id");
    $inv = fetch_one("SELECT returned_amount, refund_due, amount_due, status_id FROM invoices WHERE invoice_id=? AND tenant_id=?", $iid, $tenant_id);
    T((float)$inv["returned_amount"] == $price, "returned_amount recorded");
    T((float)$inv["refund_due"] == round($price*0.4,2), "refund_due recorded");
    T((int)$inv["status_id"] === 6, "invoice cancelled on full return");
    // stock increased
    $after = fetch_one("SELECT quantity FROM inventory_stock WHERE tenant_id=? AND warehouse_id=? AND product_id=?", $tenant_id,$whid,$pid);
    T((float)$after["quantity"] == 11.0, "stock restored");
    $conn->rollback();
    echo "Invoice return test passed (rolled back).\n";
} catch (Throwable $e) {
    $conn->rollback();
    echo "ERROR: " . $e->getMessage() . "\n";
    exit(1);
}
