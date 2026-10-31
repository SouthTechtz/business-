<?php
require_once 'header.php';
require_once 'auth_check.php';
require_permission('manage_pos');
$page_title = 'Sales Returns';
$tenant_id = (int)$current_user->tenant_id;

// Search original sale
$receipt = trim((string)($_GET['receipt'] ?? ''));
$sale = null;
$sale_items = [];
$returnable = [];
if ($receipt !== '') {
    $sale = fetch_one("SELECT o.*, c.first_name, c.last_name, c.company_name, w.name store_name, s.name shop_name FROM pos_orders o LEFT JOIN contacts c ON c.contact_id = o.contact_id LEFT JOIN warehouses w ON w.warehouse_id = o.warehouse_id LEFT JOIN shops s ON s.shop_id = w.shop_id WHERE o.tenant_id = ? AND o.order_number = ?", $tenant_id, $receipt);
    if ($sale && (int)$sale['warehouse_id'] > 0 && !store_is_accessible((int)$sale['warehouse_id'], true)) {
        flash_set('danger', 'You do not have access to this sale.');
        $sale = null;
    }
    if ($sale) {
        $sale_items = fetch_all("SELECT oi.*, p.code, p.sku FROM pos_order_items oi LEFT JOIN products p ON p.product_id = oi.product_id WHERE oi.order_id = ?", (int)$sale['order_id']);
        // previously returned
        $prev = fetch_all("SELECT sri.product_id, COALESCE(SUM(sri.quantity),0) AS returned_qty FROM sales_return_items sri JOIN sales_returns sr ON sr.return_id = sri.return_id AND sr.tenant_id = sri.tenant_id WHERE sr.tenant_id = ? AND sr.source_type = 'pos' AND sr.source_id = ? AND sr.status = 'completed' GROUP BY sri.product_id", $tenant_id, (int)$sale['order_id']);
        $prev_map = [];
        foreach ($prev as $p) { $prev_map[(int)$p['product_id']] = (float)$p['returned_qty']; }
        foreach ($sale_items as $it) {
            $pid = (int)$it['product_id'];
            $orig = (float)$it['quantity'];
            $already = $prev_map[$pid] ?? 0.0;
            $returnable[$pid] = max(0, $orig - $already);
        }
    }
}

// list returns
$returns = fetch_all("SELECT sr.*, c.first_name, c.last_name, c.company_name, s.name shop_name, u.first_name seller_first, u.last_name seller_last FROM sales_returns sr LEFT JOIN contacts c ON c.contact_id = sr.customer_id LEFT JOIN shops s ON s.shop_id = sr.shop_id LEFT JOIN users u ON u.user_id = sr.created_by WHERE sr.tenant_id = ? ORDER BY sr.created_at DESC LIMIT 50", $tenant_id);

require_once 'navbar.php';
require_once 'sidebar.php';
?>
<div class="page-head">
    <div><h1 class="page-title">Sales Returns</h1><div class="page-sub">Process and view accepted POS sales returns.</div></div>
</div>
<?php if ($f = flash_get()): ?>
    <div class="alert alert-<?php echo e($f['type']); ?>"><?php echo e($f['message']); ?></div>
<?php endif; ?>

<div class="card mb-3">
    <div class="card-header"><h5 class="card-title mb-0">Find Original Sale</h5></div>
    <div class="card-body">
        <form method="GET" action="sales-returns.php" class="form-inline">
            <input type="text" name="receipt" class="form-control mr-2" placeholder="Receipt number" value="<?php echo e($receipt); ?>">
            <button type="submit" class="btn btn-primary">Search</button>
        </form>
    </div>
</div>

<?php if ($sale): ?>
<div class="card mb-3">
    <div class="card-header"><h5 class="card-title mb-0">Original Sale: <?php echo e($sale['order_number']); ?></h5></div>
    <div class="card-body">
        <p>Customer: <?php echo e(trim(($sale['first_name'] ?? '') . ' ' . ($sale['last_name'] ?? '') . ' ' . ($sale['company_name'] ?? ''))); ?> | Shop: <?php echo e($sale['shop_name'] ?? ''); ?> | Date: <?php echo e($sale['order_date']); ?></p>
        <form method="POST" action="sales-return-add.php" onsubmit="return confirm('Process this return?');">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="source_type" value="pos">
            <input type="hidden" name="source_id" value="<?php echo (int)$sale['order_id']; ?>">
            <table class="table table-sm">
                <thead><tr><th>Product</th><th>Qty Purchased</th><th>Already Returned</th><th>Returnable</th><th>Unit Price</th><th>Return Qty</th></tr></thead>
                <tbody>
                <?php foreach ($sale_items as $it): ?>
                    <?php $pid = (int)$it['product_id']; $already = $prev_map[$pid] ?? 0.0; $rem = $returnable[$pid] ?? 0; ?>
                    <tr>
                        <td><?php echo e($it['description']); ?></td>
                        <td><?php echo e((float)$it['quantity']); ?></td>
                        <td><?php echo e($already); ?></td>
                        <td><?php echo e($rem); ?></td>
                        <td><?php echo e(money($it['unit_price'], tenant_currency())); ?></td>
                        <td><input type="number" name="quantity[]" class="form-control form-control-sm" min="0" max="<?php echo e($rem); ?>" step="0.01" value="0"><input type="hidden" name="product_id[]" value="<?php echo $pid; ?>"></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <button type="submit" class="btn btn-primary"><i class="fa fa-undo mr-1"></i> Process Return</button>
        </form>
    </div>
</div>
<?php elseif ($receipt !== ''): ?>
    <div class="alert alert-warning">Sale not found.</div>
<?php endif; ?>

<div class="card">
    <div class="card-header"><h5 class="card-title mb-0">Accepted Returns</h5></div>
    <div class="card-body p-0">
        <table class="table table-sm mb-0">
            <thead><tr><th>Return #</th><th>Receipt</th><th>Customer</th><th>Shop</th><th>Refund</th><th>Seller</th><th>Date</th></tr></thead>
            <tbody>
            <?php foreach ($returns as $r): ?>
                <tr>
                    <td><?php echo e($r['return_number']); ?></td>
                    <td><?php echo e($r['source_id']); ?></td>
                    <td><?php echo e(trim(($r['first_name'] ?? '') . ' ' . ($r['last_name'] ?? '') . ' ' . ($r['company_name'] ?? ''))); ?></td>
                    <td><?php echo e($r['shop_name'] ?? ''); ?></td>
                    <td><?php echo e(money($r['refund_amount'], tenant_currency())); ?></td>
                    <td><?php echo e(trim(($r['seller_first'] ?? '') . ' ' . ($r['seller_last'] ?? ''))); ?></td>
                    <td><?php echo e($r['return_date']); ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$returns): ?>
                <tr><td colspan="7" class="text-center text-muted py-3">No returns yet.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require_once 'footer.php'; ?>
