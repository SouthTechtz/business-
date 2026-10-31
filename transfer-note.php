<?php
require_once "header.php";
require_once "auth_check.php";
require_permission_any(['manage_godown','approve_godown_transfers','dispatch_godown_transfers','receive_godown_transfers','request_godown_transfers']);
$tenant_id = (int)$current_user->tenant_id;
$transfer_id = (int)($_GET['id'] ?? 0);
$transfer = fetch_one("SELECT t.*, sw.name source_name, sw.location_type source_type, dw.name dest_name, dw.location_type dest_type FROM stock_transfers t LEFT JOIN warehouses sw ON sw.warehouse_id=t.source_warehouse_id AND sw.tenant_id=t.tenant_id LEFT JOIN warehouses dw ON dw.warehouse_id=t.destination_warehouse_id AND dw.tenant_id=t.tenant_id WHERE t.transfer_id=? AND t.tenant_id=?", $transfer_id, $tenant_id);
if (!$transfer) { flash_set('danger','Transfer not found.'); redirect('godown-transfers.php'); }
$items = fetch_all("SELECT ti.*, p.name product_name, p.code product_code FROM stock_transfer_items ti LEFT JOIN products p ON p.product_id=ti.product_id AND p.tenant_id=ti.tenant_id WHERE ti.transfer_id=? AND ti.tenant_id=? ORDER BY ti.line_no", $transfer_id, $tenant_id);
$settings = fetch_one("SELECT company_name, company_address, company_phone FROM system_settings WHERE tenant_id=?", $tenant_id);
$company = $settings['company_name'] ?? APP_NAME;
?>
<!DOCTYPE html>
<html><head><meta charset="utf-8"><title>Goods Transfer Note #<?php echo e($transfer['transfer_number']); ?></title>
<style>body{font-family:Arial,sans-serif;margin:20px;} table{width:100%;border-collapse:collapse;} th,td{border:1px solid #333;padding:6px;} .no-print{margin-bottom:10px;} @media print {.no-print{display:none;}}</style>
</head><body>
<div class="no-print"><button onclick="window.print()">Print</button> <a href="godown-transfer-view.php?id=<?php echo (int)$transfer_id; ?>">Back</a></div>
<h2><?php echo e($company); ?></h2>
<p>Goods Transfer Note</p>
<p><strong>Transfer #:</strong> <?php echo e($transfer['transfer_number']); ?><br>
<strong>Source:</strong> <?php echo e($transfer['source_name']); ?> (<?php echo e($transfer['source_type']); ?>)<br>
<strong>Destination:</strong> <?php echo e($transfer['dest_name']); ?> (<?php echo e($transfer['dest_type']); ?>)<br>
<strong>Status:</strong> <?php echo e($transfer['status']); ?><br>
<strong>Date:</strong> <?php echo e($transfer['requested_at']); ?></p>
<table>
<tr><th>#</th><th>Product</th><th>Code</th><th>Requested Qty</th></tr>
<?php foreach ($items as $i => $it): ?>
<tr><td><?php echo $i+1; ?></td><td><?php echo e($it['product_name']); ?></td><td><?php echo e($it['product_code']); ?></td><td><?php echo e($it['requested_quantity']); ?></td></tr>
<?php endforeach; ?>
</table>
<p>Prepared by: ____________  Released by: ____________  Collected by: ____________  Received by: ____________</p>
</body></html>
