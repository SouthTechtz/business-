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
    $lead_id = db_insert("leads", ["tenant_id"=>$tenant_id, "lead_number"=>"TESTLEAD_".time(), "contact_name"=>"Test Lead", "status"=>"pending", "created_by"=>$admin["user_id"]]);
    T($lead_id>0, "lead created");
    $u=$conn->prepare("UPDATE leads SET status=\"confirmed\" WHERE lead_id=? AND tenant_id=?"); $u->bind_param("ii",$lead_id,$tenant_id); $u->execute(); $u->close();
    $row = fetch_one("SELECT status FROM leads WHERE lead_id=? AND tenant_id=?", $lead_id, $tenant_id);
    T($row && $row["status"]==="confirmed", "lead status updated to confirmed");
    $conn->rollback();
    echo "Lead smoke test passed (rolled back).\n";
} catch (Throwable $e) {
    $conn->rollback();
    echo "ERROR: " . $e->getMessage() . "\n";
    exit(1);
}
?>
