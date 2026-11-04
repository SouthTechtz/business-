<?php
require_once __DIR__ . '/wholesale_auth.php';

$tenant_id = (int)$wholesale_user->tenant_id;
$contact_id = (int)($wholesaler['contact_id'] ?? 0);
$currency = tenant_currency();
$requests = $contact_id ? wholesale_requests($tenant_id, $contact_id, 100, 0) : [];
$orders = $contact_id ? wholesale_orders($tenant_id, $contact_id, 100) : [];
$stats = wholesale_stats($tenant_id, $contact_id);
$page_title = 'My orders';

require __DIR__ . '/wholesale-portal-header.php';
?>
<div class="ws-page-head">
    <div>
        <h1>My orders</h1>
        <p>Track your order requests and the orders our team has confirmed.</p>
    </div>
    <div class="ws-page-head-actions">
        <a class="ws-btn" href="wholesale-dashboard.php"><i class="fa fa-arrow-left" aria-hidden="true"></i> Dashboard</a>
        <a class="ws-btn ws-btn-primary" href="wholesale-order-new.php"><i class="fa fa-plus" aria-hidden="true"></i> New order request</a>
    </div>
</div>

<div class="ws-stat-grid" style="grid-template-columns:repeat(3,minmax(0,1fr));">
    <div class="ws-stat">
        <span class="ws-stat-icon"><i class="fa fa-paper-plane" aria-hidden="true"></i></span>
        <div class="ws-stat-body">
            <div class="ws-stat-value"><?php echo (int)$stats['request_count']; ?></div>
            <div class="ws-stat-label">Order requests</div>
        </div>
    </div>
    <div class="ws-stat">
        <span class="ws-stat-icon"><i class="fa fa-check-circle" aria-hidden="true"></i></span>
        <div class="ws-stat-body">
            <div class="ws-stat-value"><?php echo (int)$stats['order_count']; ?></div>
            <div class="ws-stat-label">Confirmed orders</div>
        </div>
    </div>
    <div class="ws-stat">
        <span class="ws-stat-icon"><i class="fa fa-money" aria-hidden="true"></i></span>
        <div class="ws-stat-body">
            <div class="ws-stat-value"><?php echo e(money($stats['request_value'], $currency)); ?></div>
            <div class="ws-stat-label">Requested value</div>
        </div>
    </div>
</div>

<section class="ws-panel" aria-labelledby="ws-request-history">
    <div class="ws-panel-head">
        <div>
            <h2 id="ws-request-history">Order requests</h2>
            <p>Every request you submitted from this portal.</p>
        </div>
    </div>
    <?php if ($requests): ?>
        <div class="ws-table-wrap">
            <table class="ws-table">
                <thead>
                    <tr><th>Request</th><th>Date</th><th class="text-right">Items value</th><th>Status</th><th>Order</th><th></th></tr>
                </thead>
                <tbody>
                    <?php foreach ($requests as $request_row): ?>
                        <?php $status_meta = wholesale_status_meta($request_row['status']); ?>
                        <tr>
                            <td><strong><?php echo e($request_row['lead_number']); ?></strong></td>
                            <td><?php echo e(date('d M Y, H:i', strtotime($request_row['created_at']))); ?></td>
                            <td class="text-right"><?php echo e(money($request_row['subtotal'], $request_row['currency_code'] ?: $currency)); ?></td>
                            <td><span class="ws-badge is-neutral"><?php echo e($status_meta['label']); ?></span></td>
                            <td>
                                <?php if (!empty($request_row['order_number'])): ?>
                                    <strong><?php echo e($request_row['order_number']); ?></strong>
                                    <br><small class="text-muted"><?php echo e(wholesale_order_stage_meta($request_row['order_stage'] ?? 'shop')['label']); ?></small>
                                <?php else: ?>
                                    <span class="text-muted">Not yet</span>
                                <?php endif; ?>
                            </td>
                            <td class="text-right"><a class="ws-btn ws-btn-sm" href="wholesale-order-view.php?id=<?php echo (int)$request_row['request_id']; ?>">View</a></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php else: ?>
        <div class="ws-empty">
            <i class="fa fa-shopping-basket" aria-hidden="true"></i>
            <strong>No order requests yet</strong>
            <p>Start with the catalogue and send your first wholesale order request.</p>
            <a class="ws-btn ws-btn-primary" href="wholesale-order-new.php">Create order request</a>
        </div>
    <?php endif; ?>
</section>

<section class="ws-panel" aria-labelledby="ws-confirmed-orders">
    <div class="ws-panel-head">
        <div>
            <h2 id="ws-confirmed-orders">Confirmed orders</h2>
            <p>Orders created by our team from your requests.</p>
        </div>
    </div>
    <?php if ($orders): ?>
        <div class="ws-table-wrap">
            <table class="ws-table">
                <thead>
                    <tr><th>Order</th><th>Date</th><th class="text-right">Total</th><th>Stage</th><th>Status</th><th class="text-right">Delivery</th></tr>
                </thead>
                <tbody>
                    <?php foreach ($orders as $order_row): ?>
                        <?php
                        $stage_meta = wholesale_order_stage_meta($order_row['stage'] ?? 'shop');
                        $received = wholesale_order_is_received($order_row);
                        $can_confirm = wholesale_order_can_confirm_receipt($order_row);
                        ?>
                        <tr>
                            <td>
                                <strong><?php echo e($order_row['order_number']); ?></strong>
                                <?php if (!empty($order_row['request_id'])): ?>
                                    <br><a class="ws-btn ws-btn-sm mt-1" href="wholesale-order-view.php?id=<?php echo (int)$order_row['request_id']; ?>">View request</a>
                                <?php endif; ?>
                            </td>
                            <td><?php echo e(date('d M Y, H:i', strtotime($order_row['created_at']))); ?></td>
                            <td class="text-right"><?php echo e(money($order_row['total'], $order_row['currency_code'] ?: $currency)); ?></td>
                            <td><span class="ws-badge <?php echo e($stage_meta['class']); ?>"><?php echo e($stage_meta['label']); ?></span></td>
                            <td><span class="ws-badge is-neutral"><?php echo e(ucfirst((string)$order_row['status'])); ?></span></td>
                            <td class="text-right">
                                <?php if ($received): ?>
                                    <span class="ws-badge is-success">Received</span>
                                    <br><small class="text-muted"><?php echo e(date('d M Y', strtotime($order_row['customer_confirmed_at']))); ?></small>
                                <?php elseif ($can_confirm): ?>
                                    <form method="POST" action="wholesale-order-confirm.php" onsubmit="return confirm('Confirm that you have received this order?');">
                                        <?php echo csrf_field(); ?>
                                        <input type="hidden" name="order_id" value="<?php echo (int)$order_row['order_id']; ?>">
                                        <input type="hidden" name="request_id" value="<?php echo (int)$order_row['request_id']; ?>">
                                        <button type="submit" class="ws-btn ws-btn-sm ws-btn-primary"><i class="fa fa-check" aria-hidden="true"></i> Confirm received</button>
                                    </form>
                                <?php else: ?>
                                    <span class="text-muted">—</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php else: ?>
        <div class="ws-panel-body">
            <p class="text-muted mb-0" style="font-size:13px;">No confirmed orders yet. Our team will contact you after reviewing your requests.</p>
        </div>
    <?php endif; ?>
</section>
<?php require __DIR__ . '/wholesale-portal-footer.php'; ?>
