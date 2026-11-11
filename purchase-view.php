<?php
require_once 'header.php';
require_once 'auth_check.php';
require_permission('manage_purchases');
$page_title = 'View Purchase';

$tenant_id = (int)$current_user->tenant_id;
$id = (int)($_GET['id'] ?? 0);
// The Godown Manager sees their own purchases plus the receiving queue (goods
// that have arrived and are waiting to be recorded into the Godown).
$purchase_scope = is_godown_manager()
    ? " AND (pu.created_by = ? OR (pu.status = 'arrived_godown' AND pu.received_at IS NULL))"
    : '';
$purchase_params = [$id, $tenant_id];
if (is_godown_manager()) {
    $purchase_params[] = (int)$current_user->user_id;
}
$purchase_brand_scope = current_brand_scope();
if ($purchase_brand_scope !== '') {
    $purchase_scope .= ' AND pr.brand = ?';
    $purchase_params[] = $purchase_brand_scope;
}
$row = fetch_one("SELECT pu.*, pr.name product_name, pr.code product_code, s.company_name supplier_name,
                         w.name received_godown_name,
                         TRIM(CONCAT(COALESCE(u.first_name, ''), ' ', COALESCE(u.last_name, ''))) received_by_name,
                         u.username received_by_username
                    FROM purchases pu
                    JOIN products pr ON pr.product_id = pu.product_id AND pr.tenant_id = pu.tenant_id
                    LEFT JOIN suppliers s ON s.supplier_id = pu.supplier_id AND s.tenant_id = pu.tenant_id
                    LEFT JOIN warehouses w ON w.warehouse_id = pu.received_godown_id AND w.tenant_id = pu.tenant_id
                    LEFT JOIN users u ON u.user_id = pu.received_by
                  WHERE pu.purchase_id = ? AND pu.tenant_id = ?{$purchase_scope}", ...$purchase_params);
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
$is_received = purchase_is_received((int)$row['purchase_id'], $tenant_id);
$received_good = max(0, (float)$row['received_quantity']
    - (float)$row['damaged_quantity']
    - (float)($row['returned_quantity'] ?? 0)
    - (float)($row['other_quantity'] ?? 0));
$can_receive = !$is_received
    && (string)($row['status'] ?? '') !== 'cancelled'
    && can_receive_purchases();
?>
<div class="page-head">
    <div><h1 class="page-title">Purchase #<?php echo (int)$row['purchase_id']; ?></h1></div>
    <div class="d-flex align-items-center flex-wrap" style="gap:8px;">
        <?php if ($can_receive): ?>
            <a href="purchases.php?receive=<?php echo (int)$row['purchase_id']; ?>" class="btn btn-success">
                <i class="fa fa-download mr-1"></i> Confirm Goods Received
            </a>
        <?php endif; ?>
        <a href="purchases.php" class="btn btn-light"><i class="fa fa-arrow-left mr-1"></i> Back</a>
    </div>
</div>
<?php if ($f = flash_get()): ?>
    <div class="alert alert-<?php echo e($f['type']); ?>"><?php echo e($f['message']); ?></div>
<?php endif; ?>
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
            <dt class="col-sm-3">Goods Status</dt>
            <dd class="col-sm-9">
                <span class="badge badge-<?php echo e(purchase_goods_status_class($row['status'] ?? 'in_production')); ?>"><?php echo e(purchase_goods_status_label($row['status'] ?? 'in_production')); ?></span>
                <?php if ($is_received): ?>
                    <span class="badge badge-success">Received</span>
                <?php elseif ((string)($row['status'] ?? '') === 'arrived_godown'): ?>
                    <span class="badge badge-warning">Receiving due</span>
                <?php endif; ?>
            </dd>
            <?php if ($is_received): ?>
                <dt class="col-sm-3">Received Into Stock</dt>
                <dd class="col-sm-9">
                    <?php echo e(format_quantity($received_good)); ?> good
                    <?php if ((float)$row['damaged_quantity'] > 0): ?>
                        <span class="text-danger">(<?php echo e(format_quantity($row['damaged_quantity'])); ?> damaged)</span>
                    <?php endif; ?>
                    <?php if ((float)($row['returned_quantity'] ?? 0) > 0): ?>
                        <span class="text-danger">(<?php echo e(format_quantity($row['returned_quantity'])); ?> to return)</span>
                    <?php endif; ?>
                    <?php if ((float)($row['other_quantity'] ?? 0) > 0): ?>
                        <span class="text-danger">(<?php echo e(format_quantity($row['other_quantity'])); ?> other)</span>
                    <?php endif; ?>
                    of <?php echo e(format_quantity($row['received_quantity'])); ?> received
                    at <?php echo e($row['received_godown_name'] ?: '—'); ?>
                </dd>
                <dt class="col-sm-3">Received By</dt>
                <dd class="col-sm-9">
                    <?php echo e(date('M d, Y H:i', strtotime($row['received_at']))); ?>
                    by <?php echo e(trim((string)$row['received_by_name']) ?: ($row['received_by_username'] ?? '—')); ?>
                </dd>
            <?php endif; ?>
            <dt class="col-sm-3">Notes</dt>
            <dd class="col-sm-9"><?php echo e($row['notes'] ?: '—'); ?></dd>
        </dl>
    </div>
</div>
<?php require_once 'footer.php'; ?>