<?php
require_once 'header.php';
require_once 'auth_check.php';
require_permission('manage_purchases');
$page_title = 'View Purchase';

$tenant_id = (int)$current_user->tenant_id;
$id = (int)($_GET['id'] ?? 0);
$row = fetch_one("SELECT pu.*, pr.name product_name, pr.code product_code, s.company_name supplier_name
                   FROM purchases pu
                   JOIN products pr ON pr.product_id = pu.product_id AND pr.tenant_id = pu.tenant_id
                   LEFT JOIN suppliers s ON s.supplier_id = pu.supplier_id AND s.tenant_id = pu.tenant_id
                  WHERE pu.purchase_id = ? AND pu.tenant_id = ?", $id, $tenant_id);
if (!$row) {
    flash_set('danger', 'Purchase not found.');
    redirect('purchases.php');
}

require_once 'navbar.php';
require_once 'sidebar.php';
$cur = tenant_currency();
$paid = (float)$row['amount_paid'];
$total = (float)$row['total'];
$remained = $total - $paid;
?>
<div class="page-head">
    <div><h1 class="page-title">Purchase #<?php echo (int)$row['purchase_id']; ?></h1></div>
    <a href="purchases.php" class="btn btn-light"><i class="fa fa-arrow-left mr-1"></i> Back</a>
</div>
<div class="card">
    <div class="card-body">
        <dl class="row mb-0">
            <dt class="col-sm-3">Product</dt>
            <dd class="col-sm-9"><?php echo e($row['product_name']); ?> (<?php echo e($row['product_code']); ?>)</dd>
            <dt class="col-sm-3">Quantity</dt>
            <dd class="col-sm-9"><?php echo e(format_quantity($row['quantity'])); ?></dd>
            <dt class="col-sm-3">Supplier</dt>
            <dd class="col-sm-9"><?php echo e($row['supplier_name'] ?: '—'); ?></dd>
            <dt class="col-sm-3">Amount Paid</dt>
            <dd class="col-sm-9"><?php echo e(money($paid, $cur)); ?></dd>
            <dt class="col-sm-3">Amount Remained</dt>
            <dd class="col-sm-9"><?php echo e(money($remained, $cur)); ?></dd>
            <dt class="col-sm-3">Total</dt>
            <dd class="col-sm-9"><?php echo e(money($total, $cur)); ?></dd>
            <dt class="col-sm-3">Purchase Date</dt>
            <dd class="col-sm-9"><?php echo e($row['purchase_date'] ?: '—'); ?></dd>
            <dt class="col-sm-3">Notes</dt>
            <dd class="col-sm-9"><?php echo e($row['notes'] ?: '—'); ?></dd>
        </dl>
    </div>
</div>
<?php require_once 'footer.php'; ?>