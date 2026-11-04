<?php
require_once __DIR__ . '/wholesale_auth.php';

$tenant_id = (int)$wholesale_user->tenant_id;
$contact_id = (int)($wholesaler['contact_id'] ?? 0);
$currency = tenant_currency();
$stats = wholesale_stats($tenant_id, $contact_id);
$recent_requests = $contact_id ? wholesale_requests($tenant_id, $contact_id, 5, 0) : [];
$recent_orders = $contact_id ? wholesale_orders($tenant_id, $contact_id, 4) : [];
$page_title = 'Dashboard';

require __DIR__ . '/wholesale-portal-header.php';
?>
<div class="ws-page-head">
    <div>
        <h1>Welcome back, <?php echo e(trim((string)$wholesaler['contact_name']) ?: $ws_name); ?></h1>
        <p>Your wholesale account overview, recent requests and quick actions.</p>
    </div>
    <div class="ws-page-head-actions">
        <a class="ws-btn ws-btn-primary" href="wholesale-order-new.php"><i class="fa fa-plus" aria-hidden="true"></i> Create order request</a>
        <a class="ws-btn" href="wholesale-orders.php"><i class="fa fa-list-alt" aria-hidden="true"></i> All orders</a>
    </div>
</div>

<div class="ws-stat-grid">
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
        <span class="ws-stat-icon"><i class="fa fa-shopping-cart" aria-hidden="true"></i></span>
        <div class="ws-stat-body">
            <div class="ws-stat-value"><?php echo e(money($stats['order_value'], $currency)); ?></div>
            <div class="ws-stat-label">Value of confirmed orders</div>
        </div>
    </div>
    <div class="ws-stat">
        <span class="ws-stat-icon"><i class="fa fa-shield" aria-hidden="true"></i></span>
        <div class="ws-stat-body">
            <div class="ws-stat-value" style="font-size:16px;">
                <span class="ws-badge <?php echo strtolower((string)$wholesaler['status']) === 'active' ? 'is-active' : 'is-suspended'; ?>">
                    <?php echo strtolower((string)$wholesaler['status']) === 'active' ? 'Active' : 'Suspended'; ?>
                </span>
            </div>
            <div class="ws-stat-label">Account status</div>
        </div>
    </div>
</div>

<div class="ws-grid-2">
    <section class="ws-panel" aria-labelledby="ws-recent-requests">
        <div class="ws-panel-head">
            <div>
                <h2 id="ws-recent-requests">Recent order requests</h2>
                <p>Requests you sent from this portal.</p>
            </div>
            <a class="ws-btn ws-btn-sm" href="wholesale-orders.php">View all</a>
        </div>
        <?php if ($recent_requests): ?>
            <div class="ws-table-wrap">
                <table class="ws-table">
                    <thead>
                        <tr><th>Request</th><th>Date</th><th class="text-right">Value</th><th>Status</th><th></th></tr>
                    </thead>
                    <tbody>
                        <?php foreach ($recent_requests as $request_row): ?>
                            <?php $status_meta = wholesale_status_meta($request_row['status']); ?>
                            <tr>
                                <td><strong><?php echo e($request_row['lead_number']); ?></strong></td>
                                <td><?php echo e(date('d M Y', strtotime($request_row['created_at']))); ?></td>
                                <td class="text-right"><?php echo e(money($request_row['subtotal'], $request_row['currency_code'] ?: $currency)); ?></td>
                                <td><span class="ws-badge is-neutral"><?php echo e($status_meta['label']); ?></span></td>
                                <td class="text-right"><a class="ws-btn ws-btn-sm" href="wholesale-order-view.php?id=<?php echo (int)$request_row['request_id']; ?>">View</a></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php else: ?>
            <div class="ws-empty">
                <i class="fa fa-paper-plane-o" aria-hidden="true"></i>
                <strong>No order requests yet</strong>
                <p>Build your first wholesale order request from the catalogue.</p>
                <a class="ws-btn ws-btn-primary" href="wholesale-order-new.php">Create order request</a>
            </div>
        <?php endif; ?>
    </section>

    <div>
        <section class="ws-panel" aria-labelledby="ws-account-card">
            <div class="ws-panel-head">
                <h2 id="ws-account-card">Account</h2>
                <a class="ws-btn ws-btn-sm" href="wholesale-account.php">Manage</a>
            </div>
            <div class="ws-panel-body">
                <dl class="ws-detail-list">
                    <div><dt>Business</dt><dd><?php echo e($wholesaler['business_name']); ?></dd></div>
                    <div><dt>Contact</dt><dd><?php echo e($wholesaler['contact_name']); ?></dd></div>
                    <div><dt>Phone</dt><dd><?php echo e($wholesaler['phone'] ?: '—'); ?></dd></div>
                    <div><dt>Delivery area</dt><dd><?php echo e($wholesaler['location'] ?: '—'); ?></dd></div>
                    <div><dt>Username</dt><dd><?php echo e($wholesaler['username']); ?></dd></div>
                </dl>
            </div>
        </section>

        <section class="ws-panel" aria-labelledby="ws-orders-card">
            <div class="ws-panel-head">
                <h2 id="ws-orders-card">Recent confirmed orders</h2>
            </div>
            <?php if ($recent_orders): ?>
                <div class="ws-panel-body p-0">
                    <table class="ws-table">
                        <tbody>
                            <?php foreach ($recent_orders as $order_row): ?>
                                <tr>
                                    <td><strong><?php echo e($order_row['order_number']); ?></strong><br><small class="text-muted"><?php echo e(date('d M Y', strtotime($order_row['created_at']))); ?></small></td>
                                    <td class="text-right">
                                        <?php echo e(money($order_row['total'], $order_row['currency_code'] ?: $currency)); ?>
                                        <br><span class="ws-badge <?php echo e(wholesale_order_stage_meta($order_row['stage'] ?? 'shop')['class']); ?>"><?php echo e(wholesale_order_stage_meta($order_row['stage'] ?? 'shop')['label']); ?></span>
                                        <?php if (!empty($order_row['customer_confirmed_at'])): ?><br><small class="text-muted">Received <?php echo e(date('d M Y', strtotime($order_row['customer_confirmed_at']))); ?></small><?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <div class="ws-panel-body">
                    <p class="text-muted mb-0" style="font-size:13px;">Confirmed orders will appear here once our team processes your requests.</p>
                </div>
            <?php endif; ?>
        </section>
    </div>
</div>
<?php require __DIR__ . '/wholesale-portal-footer.php'; ?>
