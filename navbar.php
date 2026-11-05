    <?php
    require_once __DIR__ . '/notification-helpers.php';
    $nav_tenant_id = (int)$current_user->tenant_id;
    $nav_user_id = (int)$current_user->user_id;
    $nav_unread = notification_unread_count($nav_tenant_id, $nav_user_id);
    $nav_recent = notification_recent($nav_tenant_id, $nav_user_id, 6);
    ?>
    <div class="app-main">
        <header class="topbar">
            <div class="topbar-title"><?php echo isset($page_title) ? e($page_title) : e(system_title()); ?><small><?php echo e(system_title()); ?></small></div>
            <div class="topbar-search hide-xs">
                <div class="search-wrap">
                    <div class="input-group input-group-sm">
                        <div class="input-group-prepend"><span class="input-group-text"><i class="fa fa-search"></i></span></div>
                        <input type="text" id="globalSearch" class="form-control" placeholder="Search products, customers, invoices, sales..." autocomplete="off">
                    </div>
                    <div class="search-suggest" id="globalSearchSuggest"></div>
                </div>
            </div>
            <div class="topbar-spacer"></div>
            <div class="dropdown topbar-notify">
                <a href="#" class="topbar-icon-btn" data-toggle="dropdown" aria-haspopup="true" aria-expanded="false" title="Notifications" aria-label="Notifications">
                    <i class="fa fa-bell-o" aria-hidden="true"></i>
                    <span class="topbar-notify-badge" id="topbarNotifyBadge"<?php echo $nav_unread > 0 ? '' : ' hidden'; ?>><?php echo $nav_unread > 99 ? '99+' : (int)$nav_unread; ?></span>
                </a>
                <div class="dropdown-menu dropdown-menu-right topbar-notify-menu">
                    <div class="topbar-notify-head">
                        <strong>Notifications</strong>
                        <?php if ($nav_unread > 0): ?>
                            <span class="badge badge-primary"><?php echo (int)$nav_unread; ?> new</span>
                        <?php endif; ?>
                    </div>
                    <?php if ($nav_recent): ?>
                        <div class="topbar-notify-list">
                            <?php foreach ($nav_recent as $nav_notification): ?>
                                <?php $nav_is_unread = empty($nav_notification['read_at']); ?>
                                <a class="topbar-notify-item<?php echo $nav_is_unread ? ' is-unread' : ''; ?>"
                                   href="notification-open.php?id=<?php echo (int)$nav_notification['notification_id']; ?>">
                                    <span class="topbar-notify-icon"><i class="fa <?php echo e(trim((string)($nav_notification['icon'] ?? '')) ?: 'fa-bell'); ?>" aria-hidden="true"></i></span>
                                    <span class="topbar-notify-body">
                                        <span class="topbar-notify-title"><?php echo e($nav_notification['title']); ?></span>
                                        <?php if (trim((string)$nav_notification['message']) !== ''): ?>
                                            <span class="topbar-notify-message"><?php echo e($nav_notification['message']); ?></span>
                                        <?php endif; ?>
                                        <span class="topbar-notify-time"><?php echo e(notification_time_ago($nav_notification['created_at'])); ?></span>
                                    </span>
                                </a>
                            <?php endforeach; ?>
                        </div>
                        <a class="topbar-notify-all" href="notifications.php">View all notifications <i class="fa fa-angle-right" aria-hidden="true"></i></a>
                    <?php else: ?>
                        <div class="topbar-notify-empty">
                            <i class="fa fa-bell-o" aria-hidden="true"></i>
                            <span>No notifications yet</span>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
            <div class="dropdown">
                <a href="#" class="user-menu" data-toggle="dropdown">
                    <span class="avatar"><?php echo e(strtoupper(substr($current_user->first_name, 0, 1))); ?></span>
                    <span class="hide-xs"><strong><?php echo e($current_user->fullName()); ?></strong><br><small class="text-muted"><?php echo e($current_user->email); ?></small></span>
                    <i class="fa fa-chevron-down hide-xs" style="font-size:10px;color:var(--text-muted)"></i>
                </a>
                <div class="dropdown-menu dropdown-menu-right">
                    <a class="dropdown-item" href="profile.php"><i class="fa fa-user mr-2"></i>My Profile</a>
                    <div class="dropdown-divider"></div>
                    <a class="dropdown-item text-danger" href="logout.php"><i class="fa fa-sign-out mr-2"></i>Logout</a>
                </div>
            </div>
        </header>
        <main class="app-content">
