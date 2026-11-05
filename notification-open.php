<?php
require_once 'config.php';
require_once 'auth_check.php';
require_once 'notification-helpers.php';

$tenant_id = (int)$current_user->tenant_id;
$user_id = (int)$current_user->user_id;
$notification_id = (int)($_GET['id'] ?? 0);

$notification = $notification_id > 0
    ? fetch_one(
        "SELECT * FROM notifications
          WHERE notification_id = ? AND tenant_id = ? AND user_id = ?",
        $notification_id,
        $tenant_id,
        $user_id
    )
    : null;

if (!$notification) {
    flash_set('warning', 'That notification was not found.');
    redirect('notifications.php');
}

notification_mark_read($tenant_id, $user_id, $notification_id);

$link = trim((string)($notification['link'] ?? ''));
if (
    $link !== ''
    && !preg_match('#^(?:[a-z][a-z0-9+.-]*:)?//#i', $link)
    && strpos($link, '..') === false
) {
    redirect($link);
}

redirect('notifications.php');
