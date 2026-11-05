<?php
require_once 'config.php';
require_once 'auth_check.php';
require_once 'notification-helpers.php';

header('Content-Type: application/json; charset=utf-8');
@ini_set('display_errors', '0');

$tenant_id = (int)$current_user->tenant_id;
$user_id = (int)$current_user->user_id;
$pending = notification_feed_pending($tenant_id, $user_id, 5);
$unread = notification_unread_count($tenant_id, $user_id);

$items = [];
foreach ($pending as $row) {
    $items[] = [
        'id' => (int)$row['notification_id'],
        'type' => (string)$row['type'],
        'title' => (string)$row['title'],
        'message' => (string)($row['message'] ?? ''),
        'link' => (string)($row['link'] ?? ''),
        'icon' => (string)($row['icon'] ?? 'fa-bell'),
        'time' => notification_time_ago($row['created_at']),
    ];
}

echo json_encode([
    'success' => true,
    'unread' => $unread,
    'notifications' => $items,
]);
