<?php
require_once __DIR__ . '/wholesale_auth.php';
require_once __DIR__ . '/notification-helpers.php';

header('Content-Type: application/json; charset=utf-8');
@ini_set('display_errors', '0');

$tenant_id = (int)$wholesale_user->tenant_id;
$user_id = (int)$wholesale_user->user_id;
$pending = wholesale_notification_feed_pending($tenant_id, $user_id, 5);
$unread = wholesale_unread_notification_count($tenant_id, $user_id);

$items = [];
foreach ($pending as $row) {
    $items[] = [
        'id' => (int)$row['notification_id'],
        'type' => (string)$row['type'],
        'title' => (string)$row['title'],
        'message' => (string)($row['message'] ?? ''),
        'link' => 'wholesale-notifications.php',
        'open' => 'wholesale-notifications.php',
        'icon' => 'fa-check-circle',
        'time' => notification_time_ago($row['created_at']),
    ];
}

echo json_encode([
    'success' => true,
    'unread' => $unread,
    'notifications' => $items,
]);
