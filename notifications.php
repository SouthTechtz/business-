<?php
require_once 'config.php';
require_once 'auth_check.php';
require_once 'notification-helpers.php';

$page_title = 'Notifications';
$tenant_id = (int)$current_user->tenant_id;
$user_id = (int)$current_user->user_id;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = (string)($_POST['action'] ?? '');
    if ($action === 'mark_all_read') {
        notification_mark_read($tenant_id, $user_id);
        flash_set('success', 'All notifications were marked as read.');
    } elseif ($action === 'mark_read') {
        notification_mark_read($tenant_id, $user_id, (int)($_POST['notification_id'] ?? 0));
        flash_set('success', 'Notification marked as read.');
    }
    redirect('notifications.php');
}

$notifications = notification_list($tenant_id, $user_id, 100, 0);

// Keep the "New" markers for this render, then clear the bell badge.
$unread_ids = [];
foreach ($notifications as $notification_row) {
    if (empty($notification_row['read_at'])) {
        $unread_ids[(int)$notification_row['notification_id']] = true;
    }
}
if ($unread_ids) {
    notification_mark_read($tenant_id, $user_id);
}

require_once 'navbar.php';
require_once 'sidebar.php';
?>

<div class="page-head">
    <div>
        <h1 class="page-title">Notifications</h1>
        <div class="page-sub">Stock requests, transfers, orders and payment updates that need your attention.</div>
    </div>
    <form method="POST" action="notifications.php" class="mb-0">
        <?php echo csrf_field(); ?>
        <input type="hidden" name="action" value="mark_all_read">
        <button type="submit" class="btn btn-light" <?php echo $notifications ? '' : 'disabled'; ?>>
            <i class="fa fa-check-square-o mr-1"></i> Mark all as read
        </button>
    </form>
</div>

<?php if ($f = flash_get()): ?>
    <div class="alert alert-<?php echo e($f['type']); ?>"><?php echo e($f['message']); ?></div>
<?php endif; ?>

<div class="card">
    <div class="card-header d-flex justify-content-between align-items-center">
        <h5 class="card-title mb-0">All notifications</h5>
        <span class="badge badge-light"><?php echo count($notifications); ?> total</span>
    </div>
    <?php if ($notifications): ?>
        <div class="notification-list">
            <?php foreach ($notifications as $notification_row): ?>
                <?php
                $notification_id = (int)$notification_row['notification_id'];
                $is_unread = isset($unread_ids[$notification_id]);
                $icon = trim((string)($notification_row['icon'] ?? '')) ?: 'fa-bell';
                ?>
                <a class="notification-row<?php echo $is_unread ? ' is-unread' : ''; ?>"
                   href="notification-open.php?id=<?php echo $notification_id; ?>">
                    <span class="notification-row-icon"><i class="fa <?php echo e($icon); ?>" aria-hidden="true"></i></span>
                    <span class="notification-row-body">
                        <span class="notification-row-title">
                            <?php echo e($notification_row['title']); ?>
                            <?php if ($is_unread): ?><span class="badge badge-primary">New</span><?php endif; ?>
                        </span>
                        <?php if (trim((string)$notification_row['message']) !== ''): ?>
                            <span class="notification-row-message"><?php echo e($notification_row['message']); ?></span>
                        <?php endif; ?>
                        <span class="notification-row-time">
                            <i class="fa fa-clock-o" aria-hidden="true"></i>
                            <?php echo e(notification_time_ago($notification_row['created_at'])); ?>
                            &middot; <?php echo e(date('d M Y, H:i', strtotime($notification_row['created_at']))); ?>
                        </span>
                    </span>
                    <span class="notification-row-arrow"><i class="fa fa-angle-right" aria-hidden="true"></i></span>
                </a>
            <?php endforeach; ?>
        </div>
    <?php else: ?>
        <div class="card-body">
            <div class="notification-empty">
                <i class="fa fa-bell-o" aria-hidden="true"></i>
                <strong>No notifications yet</strong>
                <p>When stock is requested, dispatched, received or an order is paid, it will appear here.</p>
            </div>
        </div>
    <?php endif; ?>
</div>

<?php require_once 'footer.php'; ?>
