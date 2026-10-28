<?php
require_once 'header.php';
require_once 'auth_check.php';
require_permission('manage_pos');
$page_title = 'Sale Details';

$id = (int)($_GET['id'] ?? 0);
$order = fetch_one(
    "SELECT o.*, c.first_name, c.last_name, c.company_name, c.email, c.phone,
            u.first_name created_by_first, u.last_name created_by_last,
            w.name store_name, s.name shop_name
     FROM pos_orders o
     LEFT JOIN contacts c ON c.contact_id = o.contact_id
     LEFT JOIN users u ON u.user_id = o.created_by
     LEFT JOIN warehouses w ON w.warehouse_id = o.warehouse_id
     LEFT JOIN shops s ON s.shop_id = w.shop_id
     WHERE o.order_id = ? AND o.tenant_id = ?",
    $id, $current_user->tenant_id
);
if (!$order) {
    flash_set('danger', 'Sale not found.');
    redirect('sales.php');
}
$order_store_id = (int)($order['warehouse_id'] ?? 0);
if ($order_store_id > 0 && !store_is_accessible($order_store_id, true)) {
    flash_set('danger', 'You do not have access to the store for this sale.');
    redirect('sales.php');
}

$items = fetch_all(
    "SELECT oi.*, p.code, p.sku FROM pos_order_items oi
     LEFT JOIN products p ON p.product_id = oi.product_id
     WHERE oi.order_id = ? ORDER BY oi.order_item_id",
    $id
);

require_once 'navbar.php';
require_once 'sidebar.php';
$cur = tenant_currency();
?>

<div class="page-head">
    <div>
        <h1 class="page-title">Sale Details</h1>
        <div class="page-sub">Order <?php echo e($order['order_number']); ?><?php if (!empty($order['store_name'])): ?> · Store: <strong><?php echo e(trim(($order['shop_name'] ?? '') . ' / ' . $order['store_name'], ' /')); ?></strong><?php endif; ?></div>
    </div>
    <div>
        <?php if ($order['status'] !== 'cancelled'): ?>
            <a href="sale-cancel.php?id=<?php echo $id; ?>" class="btn btn-outline-warning" onclick="return confirm('Cancel this sale? Stock will be restored.');"><i class="fa fa-ban mr-1"></i> Cancel Sale</a>
        <?php endif; ?>
        <a href="sales.php" class="btn btn-light"><i class="fa fa-arrow-left mr-1"></i> Back</a>
    </div>
</div>

<?php if ($f = flash_get()): ?>
    <div class="alert alert-<?php echo e($f['type']); ?>"><?php echo e($f['message']); ?></div>
<?php endif; ?>

<div class="row">
    <div class="col-lg-8 mb-3">
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h5 class="card-title">Items</h5>
                <span class="badge badge-<?php echo $order['status'] === 'completed' ? 'success' : ($order['status'] === 'cancelled' ? 'danger' : 'warning'); ?>"><?php echo e(ucfirst($order['status'])); ?></span>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table mb-0">
                        <thead>
                            <tr><th>#</th><th>Item</th><th>Code</th><th class="text-center">Qty</th><th class="text-right">Unit Price</th><th class="text-right">Tax</th><th class="text-right">Total</th></tr>
                        </thead>
                        <tbody>
                        <?php foreach ($items as $i => $item): ?>
                            <tr>
                                <td><?php echo $i + 1; ?></td>
                                <td><?php echo e($item['description'] ?: $item['code']); ?></td>
                                <td><span class="badge badge-secondary"><?php echo e($item['code']); ?></span></td>
                                <td class="text-center"><?php echo e((float)$item['quantity']); ?></td>
                                <td class="text-right"><?php echo e(money($item['unit_price'], $cur)); ?></td>
                                <td class="text-right"><?php echo e(money($item['tax_amount'], $cur)); ?></td>
                                <td class="text-right font-weight-semibold"><?php echo e(money($item['total'], $cur)); ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
    <div class="col-lg-4 mb-3">
        <div class="card">
            <div class="card-header"><h5 class="card-title">Order Summary</h5></div>
            <div class="card-body">
                <div class="cart-total-row"><span>Order #</span><span class="font-weight-semibold"><?php echo e($order['order_number']); ?></span></div>
                <div class="cart-total-row"><span>Date</span><span><?php echo e(date('M d, Y H:i', strtotime($order['order_date']))); ?></span></div>
                <div class="cart-total-row"><span>Status</span><span><?php echo e(ucfirst($order['status'])); ?></span></div>
                <hr>
                <div class="cart-total-row"><span>Subtotal</span><span><?php echo e(money($order['subtotal'], $cur)); ?></span></div>
                <?php if ((float)$order['tax_amount'] > 0): ?>
                <div class="cart-total-row"><span>Tax</span><span><?php echo e(money($order['tax_amount'], $cur)); ?></span></div>
                <?php endif; ?>
                <?php if ((float)$order['discount'] > 0): ?>
                    <div class="cart-total-row text-danger"><span>Discount</span><span>- <?php echo e(money($order['discount'], $cur)); ?></span></div>
                <?php endif; ?>
                <div class="cart-total-row cart-grand"><span>Total</span><span><?php echo e(money($order['total'], $cur)); ?></span></div>
            </div>
        </div>
        <div class="card">
            <div class="card-header"><h5 class="card-title">Customer</h5></div>
            <div class="card-body">
                <?php if ($order['contact_id']): ?>
                    <div class="font-weight-semibold"><?php echo e(trim(($order['first_name'] ?? '') . ' ' . ($order['last_name'] ?? '')) ?: $order['company_name']); ?></div>
                    <div class="text-muted small"><?php echo e($order['email'] ?? ''); ?></div>
                    <div class="text-muted small"><?php echo e($order['phone'] ?? ''); ?></div>
                <?php else: ?>
                    <div class="text-muted">Walk-in Customer</div>
                <?php endif; ?>
                <hr>
                <div class="small text-muted">Created by <?php echo e(trim(($order['created_by_first'] ?? '') . ' ' . ($order['created_by_last'] ?? '')) ?: '—'); ?></div>
            </div>
        </div>
    </div>
</div>

<?php require_once 'footer.php'; ?>
