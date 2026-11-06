<?php
require_once 'header.php';
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

$notify_category = function ($type) {
    $type = strtolower(trim((string)$type));
    if (strpos($type, 'order') === 0 || strpos($type, 'lead') === 0 || strpos($type, 'wholesale') === 0) {
        return 'orders';
    }
    if (strpos($type, 'stock') === 0 || strpos($type, 'transfer') === 0 || strpos($type, 'inventory') === 0) {
        return 'stock';
    }
    return 'system';
};

$notify_tone = function ($type) {
    $type = strtolower(trim((string)$type));
    if (in_array($type, ['transfer_received', 'order_paid', 'payment_received'], true)) {
        return 'success';
    }
    if ($type === 'transfer_dispatched') {
        return 'info';
    }
    if (in_array($type, ['transfer_approved', 'order_created'], true)) {
        return 'navy';
    }
    if (in_array($type, ['stock_request', 'order_request'], true)) {
        return 'warning';
    }
    return 'primary';
};

$today = date('Y-m-d');
$yesterday = date('Y-m-d', strtotime('-1 day'));
$week_floor = date('Y-m-d', strtotime('-6 days'));

$notify_bucket = function ($created_at) use ($today, $yesterday, $week_floor) {
    $date = date('Y-m-d', strtotime((string)$created_at));
    if ($date === $today) return 'today';
    if ($date === $yesterday) return 'yesterday';
    if ($date >= $week_floor) return 'week';
    return 'older';
};

$category_labels = [
    'orders' => 'Orders',
    'stock'  => 'Stock & transfers',
    'system' => 'System',
];
$group_labels = [
    'today'     => 'Today',
    'yesterday' => 'Yesterday',
    'week'      => 'Earlier this week',
    'older'     => 'Older',
];

$counts = ['all' => count($notifications), 'unread' => 0, 'orders' => 0, 'stock' => 0, 'system' => 0];
$today_count = 0;
$week_count = 0;
$groups = [];

foreach ($notifications as $notification_row) {
    $category = $notify_category($notification_row['type'] ?? '');
    $counts[$category]++;
    if (isset($unread_ids[(int)$notification_row['notification_id']])) {
        $counts['unread']++;
    }

    $created_date = date('Y-m-d', strtotime((string)$notification_row['created_at']));
    if ($created_date === $today) {
        $today_count++;
    }
    if ($created_date >= $week_floor) {
        $week_count++;
    }

    $groups[$notify_bucket($notification_row['created_at'])][] = $notification_row;
}

require_once 'navbar.php';
require_once 'sidebar.php';
?>

<div class="notify-hero">
    <div class="notify-hero-main">
        <span class="notify-hero-icon"><i class="fa fa-bell" aria-hidden="true"></i></span>
        <div>
            <h1 class="notify-hero-title">Notifications</h1>
            <p class="notify-hero-sub">Stock movements, order requests and payment updates — all in one place.</p>
        </div>
    </div>
    <form method="POST" action="notifications.php" class="notify-hero-actions">
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

<div class="notify-stats">
    <div class="notify-stat">
        <span class="notify-stat-icon notify-tone-primary"><i class="fa fa-bell" aria-hidden="true"></i></span>
        <div>
            <div class="notify-stat-value"><?php echo (int)$counts['unread']; ?></div>
            <div class="notify-stat-label">Unread</div>
        </div>
    </div>
    <div class="notify-stat">
        <span class="notify-stat-icon notify-tone-warning"><i class="fa fa-calendar-check-o" aria-hidden="true"></i></span>
        <div>
            <div class="notify-stat-value"><?php echo (int)$today_count; ?></div>
            <div class="notify-stat-label">Today</div>
        </div>
    </div>
    <div class="notify-stat">
        <span class="notify-stat-icon notify-tone-info"><i class="fa fa-line-chart" aria-hidden="true"></i></span>
        <div>
            <div class="notify-stat-value"><?php echo (int)$week_count; ?></div>
            <div class="notify-stat-label">Last 7 days</div>
        </div>
    </div>
    <div class="notify-stat">
        <span class="notify-stat-icon notify-tone-navy"><i class="fa fa-inbox" aria-hidden="true"></i></span>
        <div>
            <div class="notify-stat-value"><?php echo (int)$counts['all']; ?></div>
            <div class="notify-stat-label">Total</div>
        </div>
    </div>
</div>

<?php if ($notifications): ?>
    <div class="notify-toolbar">
        <div class="notify-chips" role="group" aria-label="Filter notifications">
            <button type="button" class="notify-chip is-active" data-filter="all" aria-pressed="true">
                All <span><?php echo (int)$counts['all']; ?></span>
            </button>
            <button type="button" class="notify-chip" data-filter="unread" aria-pressed="false">
                Unread <span><?php echo (int)$counts['unread']; ?></span>
            </button>
            <button type="button" class="notify-chip" data-filter="orders" aria-pressed="false">
                Orders <span><?php echo (int)$counts['orders']; ?></span>
            </button>
            <button type="button" class="notify-chip" data-filter="stock" aria-pressed="false">
                Stock <span><?php echo (int)$counts['stock']; ?></span>
            </button>
            <button type="button" class="notify-chip" data-filter="system" aria-pressed="false">
                System <span><?php echo (int)$counts['system']; ?></span>
            </button>
        </div>
        <div class="notify-search">
            <i class="fa fa-search" aria-hidden="true"></i>
            <input type="search" id="notifySearch" placeholder="Search notifications..." aria-label="Search notifications">
        </div>
    </div>

    <div class="notify-panel">
        <div class="notify-panel-head">
            <h2>Activity feed</h2>
            <span class="notify-panel-count" id="notifyVisibleCount">Showing <?php echo (int)$counts['all']; ?> of <?php echo (int)$counts['all']; ?></span>
        </div>

        <div class="notify-feed" id="notifyFeed">
            <?php foreach ($groups as $bucket => $rows): ?>
                <div class="notify-group" data-group>
                    <div class="notify-group-head">
                        <span><?php echo e($group_labels[$bucket] ?? 'Older'); ?></span>
                        <span><?php echo count($rows); ?></span>
                    </div>
                    <?php foreach ($rows as $notification_row): ?>
                        <?php
                        $notification_id = (int)$notification_row['notification_id'];
                        $is_unread = isset($unread_ids[$notification_id]);
                        $category = $notify_category($notification_row['type'] ?? '');
                        $tone = $notify_tone($notification_row['type'] ?? '');
                        $category_label = $category_labels[$category] ?? 'System';
                        $icon = trim((string)($notification_row['icon'] ?? ''));
                        if ($icon === '') {
                            $icon = $category === 'stock' ? 'fa-exchange' : ($category === 'orders' ? 'fa-file-text-o' : 'fa-bell');
                        }
                        $search_text = mb_strtolower(implode(' ', array_filter([
                            (string)$notification_row['title'],
                            (string)$notification_row['message'],
                            (string)$notification_row['type'],
                            $category_label,
                        ])), 'UTF-8');
                        ?>
                        <a class="notify-item<?php echo $is_unread ? ' is-unread' : ''; ?>"
                           href="notification-open.php?id=<?php echo $notification_id; ?>"
                           data-category="<?php echo e($category); ?>"
                           data-unread="<?php echo $is_unread ? '1' : '0'; ?>"
                           data-search="<?php echo e($search_text); ?>">
                            <span class="notify-item-icon notify-tone-<?php echo e($tone); ?>">
                                <i class="fa <?php echo e($icon); ?>" aria-hidden="true"></i>
                            </span>
                            <span class="notify-item-body">
                                <span class="notify-item-top">
                                    <span class="notify-item-title"><?php echo e($notification_row['title']); ?></span>
                                    <?php if ($is_unread): ?><span class="notify-dot" aria-label="Unread"></span><?php endif; ?>
                                </span>
                                <?php if (trim((string)$notification_row['message']) !== ''): ?>
                                    <span class="notify-item-message"><?php echo e($notification_row['message']); ?></span>
                                <?php endif; ?>
                                <span class="notify-item-meta">
                                    <span class="notify-item-tag notify-tone-<?php echo e($tone); ?>"><?php echo e($category_label); ?></span>
                                    <span><i class="fa fa-clock-o" aria-hidden="true"></i> <?php echo e(notification_time_ago($notification_row['created_at'])); ?></span>
                                    <span class="notify-item-date"><?php echo e(date('d M Y, H:i', strtotime($notification_row['created_at']))); ?></span>
                                </span>
                            </span>
                            <span class="notify-item-arrow"><i class="fa fa-angle-right" aria-hidden="true"></i></span>
                        </a>
                    <?php endforeach; ?>
                </div>
            <?php endforeach; ?>
        </div>

        <div class="notify-no-results" id="notifyNoResults" hidden>
            <i class="fa fa-search" aria-hidden="true"></i>
            <strong>No notifications match your filters</strong>
            <p>Try a different search term or filter.</p>
        </div>
    </div>
<?php else: ?>
    <div class="notify-panel">
        <div class="notify-empty">
            <i class="fa fa-bell-o" aria-hidden="true"></i>
            <strong>No notifications yet</strong>
            <p>When stock is requested, dispatched, received or an order is paid, it will appear here.</p>
        </div>
    </div>
<?php endif; ?>

<script>
    (function () {
        var feed = document.getElementById('notifyFeed');
        if (!feed) return;

        var items = Array.prototype.slice.call(feed.querySelectorAll('.notify-item'));
        var groups = Array.prototype.slice.call(feed.querySelectorAll('.notify-group'));
        var chips = Array.prototype.slice.call(document.querySelectorAll('.notify-chip'));
        var search = document.getElementById('notifySearch');
        var empty = document.getElementById('notifyNoResults');
        var visibleCount = document.getElementById('notifyVisibleCount');
        var activeFilter = 'all';

        function applyFilters() {
            var query = (search && search.value ? search.value : '').trim().toLowerCase();
            var shown = 0;

            items.forEach(function (item) {
                var category = item.getAttribute('data-category') || '';
                var isUnread = item.getAttribute('data-unread') === '1';
                var haystack = item.getAttribute('data-search') || '';
                var matchesFilter = activeFilter === 'all'
                    || (activeFilter === 'unread' && isUnread)
                    || (activeFilter !== 'unread' && category === activeFilter);
                var matchesSearch = query === '' || haystack.indexOf(query) !== -1;
                var visible = matchesFilter && matchesSearch;
                item.hidden = !visible;
                if (visible) shown++;
            });

            groups.forEach(function (group) {
                var hasVisible = group.querySelectorAll('.notify-item:not([hidden])').length > 0;
                group.hidden = !hasVisible;
            });

            if (empty) empty.hidden = shown !== 0;
            if (visibleCount) visibleCount.textContent = 'Showing ' + shown + ' of ' + items.length;
        }

        chips.forEach(function (chip) {
            chip.addEventListener('click', function () {
                chips.forEach(function (other) {
                    other.classList.remove('is-active');
                    other.setAttribute('aria-pressed', 'false');
                });
                chip.classList.add('is-active');
                chip.setAttribute('aria-pressed', 'true');
                activeFilter = chip.getAttribute('data-filter') || 'all';
                applyFilters();
            });
        });

        if (search) {
            search.addEventListener('input', applyFilters);
            document.addEventListener('keydown', function (event) {
                if (event.key === '/' && document.activeElement !== search) {
                    event.preventDefault();
                    search.focus();
                }
            });
        }

        applyFilters();
    })();
</script>

<?php require_once 'footer.php'; ?>
