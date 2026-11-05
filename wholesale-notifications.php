<?php
require_once __DIR__ . '/wholesale_auth.php';

$tenant_id = (int)$wholesale_user->tenant_id;
$user_id = (int)$wholesale_user->user_id;
$contact_id = (int)($wholesaler['contact_id'] ?? 0);
$currency = tenant_currency();
$notifications = wholesale_notifications($tenant_id, $user_id, 100, 0);
$page_title = 'Notifications';

// Remember which rows were unread for this render, then mark everything read.
$unread_ids = [];
foreach ($notifications as $notification_row) {
    if (empty($notification_row['read_at'])) {
        $unread_ids[(int)$notification_row['notification_id']] = true;
    }
}
if ($unread_ids) {
    wholesale_mark_notifications_read($tenant_id, $user_id);
}

require __DIR__ . '/wholesale-portal-header.php';
?>
<div class="ws-page-head">
    <div>
        <h1>Notifications</h1>
        <p>Payment confirmations and updates about your wholesale orders.</p>
    </div>
    <div class="ws-page-head-actions">
        <a class="ws-btn" href="wholesale-dashboard.php"><i class="fa fa-arrow-left" aria-hidden="true"></i> Dashboard</a>
        <a class="ws-btn ws-btn-primary" href="wholesale-orders.php"><i class="fa fa-list-alt" aria-hidden="true"></i> My orders</a>
    </div>
</div>

<section class="ws-panel" aria-labelledby="ws-notification-list">
    <div class="ws-panel-head">
        <div>
            <h2 id="ws-notification-list">Your notifications</h2>
            <p><?php echo count($notifications); ?> notification<?php echo count($notifications) === 1 ? '' : 's'; ?> in your account.</p>
        </div>
    </div>
    <?php if ($notifications): ?>
        <div class="ws-notification-list">
            <?php foreach ($notifications as $notification_row): ?>
                <?php
                $notification_id = (int)$notification_row['notification_id'];
                $is_unread = isset($unread_ids[$notification_id]);
                $order_id = (int)($notification_row['order_id'] ?? 0);
                $request_id = (int)($notification_row['request_id'] ?? 0);
                $is_paid = wholesale_order_is_paid([
                    'total' => $notification_row['order_total'] ?? 0,
                    'Amount_paid' => $notification_row['order_amount_paid'] ?? 0,
                ]);
                ?>
                <article class="ws-notification<?php echo $is_unread ? ' is-unread' : ''; ?>">
                    <span class="ws-notification-icon"><i class="fa fa-check-circle" aria-hidden="true"></i></span>
                    <div class="ws-notification-body">
                        <div class="ws-notification-title">
                            <?php echo e($notification_row['title']); ?>
                            <?php if ($is_unread): ?><span class="ws-badge is-info">New</span><?php endif; ?>
                        </div>
                        <p><?php echo e($notification_row['message']); ?></p>
                        <div class="ws-notification-meta">
                            <span><i class="fa fa-clock-o" aria-hidden="true"></i> <?php echo e(date('d M Y, H:i', strtotime($notification_row['created_at']))); ?></span>
                            <?php if ($order_id > 0): ?>
                                <span>Order <strong><?php echo e($notification_row['order_number']); ?></strong></span>
                            <?php endif; ?>
                        </div>
                        <?php if ($order_id > 0): ?>
                            <div class="ws-notification-actions">
                                <?php if ($request_id > 0): ?>
                                    <a class="ws-btn ws-btn-sm" href="wholesale-order-view.php?id=<?php echo $request_id; ?>"><i class="fa fa-file-text-o" aria-hidden="true"></i> View order</a>
                                <?php endif; ?>
                                <?php if ($is_paid): ?>
                                    <a class="ws-btn ws-btn-sm ws-btn-primary" href="wholesale-order-receipt.php?id=<?php echo $order_id; ?>"><i class="fa fa-eye" aria-hidden="true"></i> View receipt</a>
                                    <a class="ws-btn ws-btn-sm" href="wholesale-order-receipt.php?id=<?php echo $order_id; ?>&amp;download=1"><i class="fa fa-download" aria-hidden="true"></i> Download</a>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </article>
            <?php endforeach; ?>
        </div>
    <?php else: ?>
        <div class="ws-empty">
            <i class="fa fa-bell-o" aria-hidden="true"></i>
            <strong>No notifications yet</strong>
            <p>We will let you know here when a payment is received for your order.</p>
            <a class="ws-btn ws-btn-primary" href="wholesale-orders.php">View my orders</a>
        </div>
    <?php endif; ?>
</section>
<?php require __DIR__ . '/wholesale-portal-footer.php'; ?>
