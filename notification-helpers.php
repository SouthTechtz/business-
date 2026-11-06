<?php
/**
 * General in-app notifications.
 *
 * Notifications are stored per user and rendered as small Windows-style
 * toasts at the bottom of the screen. Each notification can also be opened
 * from the bell menu and the notifications page.
 */
require_once __DIR__ . '/config.php';

/** Whether the notifications table has been created on this deployment. */
function notifications_ready() {
    static $ready = null;
    if ($ready === null) {
        $ready = db_table_exists('notifications');
    }
    return $ready;
}

/** Remove duplicates, invalid ids and the acting user from a recipient list. */
function notification_filter_user_ids(array $user_ids, $exclude_user_id = 0) {
    $exclude_user_id = (int)$exclude_user_id;
    $clean = [];
    foreach ($user_ids as $user_id) {
        $user_id = (int)$user_id;
        if ($user_id > 0 && $user_id !== $exclude_user_id) {
            $clean[$user_id] = true;
        }
    }
    return array_keys($clean);
}

/** Active users in a tenant who hold a permission. */
function notification_user_ids_with_permission($tenant_id, $permission, $exclude_user_id = 0) {
    $tenant_id = (int)$tenant_id;
    if ($tenant_id <= 0 || (string)$permission === '') return [];
    $rows = fetch_all(
        "SELECT DISTINCT u.user_id
           FROM users u
           JOIN user_roles ur ON ur.user_id = u.user_id AND ur.tenant_id = u.tenant_id
           JOIN role_permissions rp ON rp.role_id = ur.role_id
           JOIN permissions p ON p.permission_id = rp.permission_id
          WHERE u.tenant_id = ? AND u.is_active = 1 AND p.name = ?",
        $tenant_id,
        (string)$permission
    );
    return notification_filter_user_ids(array_column($rows, 'user_id'), $exclude_user_id);
}

/** Active users assigned to an inventory location (or the shop behind it). */
function notification_user_ids_at_location($tenant_id, $warehouse_id, $exclude_user_id = 0, $include_admins = false) {
    $tenant_id = (int)$tenant_id;
    $warehouse_id = (int)$warehouse_id;
    if ($tenant_id <= 0 || $warehouse_id <= 0) return [];

    $rows = fetch_all(
        "SELECT DISTINCT u.user_id
           FROM users u
          WHERE u.tenant_id = ? AND u.is_active = 1
            AND (
                EXISTS (SELECT 1 FROM user_inventory_locations uil
                         WHERE uil.tenant_id = u.tenant_id AND uil.user_id = u.user_id
                           AND uil.location_id = ?)
                OR EXISTS (SELECT 1 FROM user_shops us
                             JOIN warehouses w ON w.shop_id = us.shop_id AND w.tenant_id = us.tenant_id
                            WHERE us.tenant_id = u.tenant_id AND us.user_id = u.user_id
                              AND w.warehouse_id = ?)
            )",
        $tenant_id,
        $warehouse_id,
        $warehouse_id
    );
    $ids = array_column($rows, 'user_id');
    if ($include_admins) {
        $ids = array_merge(
            $ids,
            notification_user_ids_with_permission($tenant_id, 'manage_users'),
            notification_user_ids_with_permission($tenant_id, 'manage_settings')
        );
    }
    return notification_filter_user_ids($ids, $exclude_user_id);
}

/**
 * Users who hold a permission and work at a location. Administrators are
 * included because they can act on any location.
 */
function notification_user_ids_for_location_permission($tenant_id, $warehouse_id, $permission, $exclude_user_id = 0, $include_admins = true) {
    $tenant_id = (int)$tenant_id;
    $warehouse_id = (int)$warehouse_id;
    if ($tenant_id <= 0 || $warehouse_id <= 0 || (string)$permission === '') return [];

    $admin_sql = $include_admins
        ? " OR EXISTS (SELECT 1 FROM user_roles ur2
                         JOIN role_permissions rp2 ON rp2.role_id = ur2.role_id
                         JOIN permissions p2 ON p2.permission_id = rp2.permission_id
                        WHERE ur2.user_id = u.user_id AND ur2.tenant_id = u.tenant_id
                          AND p2.name IN ('manage_users', 'manage_settings'))"
        : '';

    $rows = fetch_all(
        "SELECT DISTINCT u.user_id
           FROM users u
          WHERE u.tenant_id = ? AND u.is_active = 1
            AND EXISTS (SELECT 1 FROM user_roles ur
                          JOIN role_permissions rp ON rp.role_id = ur.role_id
                          JOIN permissions p ON p.permission_id = rp.permission_id
                         WHERE ur.user_id = u.user_id AND ur.tenant_id = u.tenant_id
                           AND p.name = ?)
            AND (
                EXISTS (SELECT 1 FROM user_inventory_locations uil
                         WHERE uil.tenant_id = u.tenant_id AND uil.user_id = u.user_id
                           AND uil.location_id = ?)
                OR EXISTS (SELECT 1 FROM user_shops us
                             JOIN warehouses w ON w.shop_id = us.shop_id AND w.tenant_id = us.tenant_id
                            WHERE us.tenant_id = u.tenant_id AND us.user_id = u.user_id
                              AND w.warehouse_id = ?)
                {$admin_sql}
            )",
        $tenant_id,
        (string)$permission,
        $warehouse_id,
        $warehouse_id
    );
    return notification_filter_user_ids(array_column($rows, 'user_id'), $exclude_user_id);
}

/**
 * Store one notification. Returns true when a new row was created.
 * Notification failures never break the business action that triggered them.
 */
function notification_create($tenant_id, $user_id, $type, $title, $message = '', $link = '', $icon = 'fa-bell', $dedupe_key = null, $actor_user_id = 0) {
    global $conn;

    if (!notifications_ready()) return false;

    $tenant_id = (int)$tenant_id;
    $user_id = (int)$user_id;
    if ($tenant_id <= 0 || $user_id <= 0 || trim((string)$title) === '') return false;

    try {
        $stmt = $conn->prepare(
            "INSERT IGNORE INTO notifications
                (tenant_id, user_id, actor_user_id, type, title, message, link, icon, dedupe_key, created_at)
             VALUES (?, ?, NULLIF(?, 0), ?, ?, ?, ?, ?, ?, NOW())"
        );
        if (!$stmt) return false;

        $type = (string)$type !== '' ? (string)$type : 'system';
        $title = mb_substr(trim((string)$title), 0, 191);
        $message = (string)$message;
        $link = trim((string)$link);
        $link = $link !== '' ? mb_substr($link, 0, 255) : null;
        $icon = trim((string)$icon) !== '' ? mb_substr(trim((string)$icon), 0, 50) : 'fa-bell';
        $dedupe_key = $dedupe_key !== null && (string)$dedupe_key !== ''
            ? mb_substr((string)$dedupe_key, 0, 191)
            : null;
        $actor_user_id = (int)$actor_user_id;

        $stmt->bind_param(
            'iiissssss',
            $tenant_id,
            $user_id,
            $actor_user_id,
            $type,
            $title,
            $message,
            $link,
            $icon,
            $dedupe_key
        );
        $ok = $stmt->execute();
        $created = $ok && $stmt->affected_rows > 0;
        $stmt->close();
        return $created;
    } catch (Throwable $ex) {
        error_log('Notification insert failed: ' . $ex->getMessage());
        return false;
    }
}

/** Store the same notification for several recipients. */
function notification_create_for_users(array $user_ids, $tenant_id, $type, $title, $message = '', $link = '', $icon = 'fa-bell', $dedupe_key = null, $actor_user_id = 0) {
    $created = 0;
    foreach (notification_filter_user_ids($user_ids, $actor_user_id) as $user_id) {
        if (notification_create($tenant_id, $user_id, $type, $title, $message, $link, $icon, $dedupe_key, $actor_user_id)) {
            $created++;
        }
    }
    return $created;
}

/** Unread notifications for a user. */
function notification_unread_count($tenant_id, $user_id) {
    if (!notifications_ready()) return 0;
    $row = fetch_one(
        "SELECT COUNT(*) AS total FROM notifications
          WHERE tenant_id = ? AND user_id = ? AND read_at IS NULL",
        (int)$tenant_id,
        (int)$user_id
    );
    return (int)($row['total'] ?? 0);
}

/** Recent notifications for the top-bar bell menu. */
function notification_recent($tenant_id, $user_id, $limit = 6) {
    if (!notifications_ready()) return [];
    $limit = max(1, min(20, (int)$limit));
    return fetch_all(
        "SELECT * FROM notifications
          WHERE tenant_id = ? AND user_id = ?
          ORDER BY created_at DESC, notification_id DESC
          LIMIT {$limit}",
        (int)$tenant_id,
        (int)$user_id
    );
}

/** Full notification list for the notifications page. */
function notification_list($tenant_id, $user_id, $limit = 100, $offset = 0) {
    if (!notifications_ready()) return [];
    $limit = max(1, min(200, (int)$limit));
    $offset = max(0, (int)$offset);
    return fetch_all(
        "SELECT * FROM notifications
          WHERE tenant_id = ? AND user_id = ?
          ORDER BY created_at DESC, notification_id DESC
          LIMIT {$limit} OFFSET {$offset}",
        (int)$tenant_id,
        (int)$user_id
    );
}

/** Mark one notification or every notification for a user as read. */
function notification_mark_read($tenant_id, $user_id, $notification_id = null) {
    global $conn;
    if (!notifications_ready()) return;

    if ($notification_id !== null) {
        $stmt = $conn->prepare(
            "UPDATE notifications SET read_at = NOW()
              WHERE notification_id = ? AND tenant_id = ? AND user_id = ? AND read_at IS NULL"
        );
        if (!$stmt) return;
        $notification_id = (int)$notification_id;
        $stmt->bind_param('iii', $notification_id, $tenant_id, $user_id);
    } else {
        $stmt = $conn->prepare(
            "UPDATE notifications SET read_at = NOW()
              WHERE tenant_id = ? AND user_id = ? AND read_at IS NULL"
        );
        if (!$stmt) return;
        $stmt->bind_param('ii', $tenant_id, $user_id);
    }
    $stmt->execute();
    $stmt->close();
}

/**
 * Mark every notification that points at a specific page as read. Used when
 * the user opens the record directly instead of clicking the bell item.
 */
function notification_mark_link_read($tenant_id, $user_id, $link) {
    global $conn;
    if (!notifications_ready()) return;

    $link = trim((string)$link);
    if ($link === '') return;

    $stmt = $conn->prepare(
        "UPDATE notifications SET read_at = NOW()
          WHERE tenant_id = ? AND user_id = ? AND link = ? AND read_at IS NULL"
    );
    if (!$stmt) return;
    $stmt->bind_param('iis', $tenant_id, $user_id, $link);
    $stmt->execute();
    $stmt->close();
}

/**
 * Notifications that should pop up as toasts. Rows are marked as seen so a
 * toast is only shown once, even across page reloads.
 */
function notification_feed_pending($tenant_id, $user_id, $limit = 5) {
    global $conn;
    if (!notifications_ready()) return [];

    $tenant_id = (int)$tenant_id;
    $user_id = (int)$user_id;
    $limit = max(1, min(10, (int)$limit));

    $rows = fetch_all(
        "SELECT notification_id, type, title, message, link, icon, created_at
           FROM notifications
          WHERE tenant_id = ? AND user_id = ? AND read_at IS NULL AND toast_seen_at IS NULL
          ORDER BY created_at ASC, notification_id ASC
          LIMIT {$limit}",
        $tenant_id,
        $user_id
    );
    if (!$rows) return [];

    $ids = array_map(fn($row) => (int)$row['notification_id'], $rows);
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $sql = "UPDATE notifications SET toast_seen_at = NOW()
             WHERE tenant_id = ? AND user_id = ? AND notification_id IN ({$placeholders})";
    $stmt = $conn->prepare($sql);
    if ($stmt) {
        $types = 'ii' . str_repeat('i', count($ids));
        $params = array_merge([$tenant_id, $user_id], $ids);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $stmt->close();
    }

    return $rows;
}

/** Human-friendly relative time for notification cards. */
function notification_time_ago($datetime) {
    $timestamp = strtotime((string)$datetime);
    if (!$timestamp) return '';
    $diff = time() - $timestamp;
    if ($diff < 60) return 'Just now';
    if ($diff < 3600) return floor($diff / 60) . ' min ago';
    if ($diff < 86400) return floor($diff / 3600) . ' hr ago';
    if ($diff < 604800) return floor($diff / 86400) . ' day' . (floor($diff / 86400) === 1 ? '' : 's') . ' ago';
    return date('d M Y', $timestamp);
}

/** Load a transfer with the labels and people needed for notifications. */
function notification_transfer_context($transfer_id, $tenant_id) {
    return fetch_one(
        "SELECT t.*,
                sw.name source_name, sw.location_type source_type, sw.shop_id source_shop_id,
                ssw.name source_shop_name,
                w.name destination_name, w.location_type destination_type,
                s.name destination_shop_name,
                TRIM(CONCAT(COALESCE(ur.first_name, ''), ' ', COALESCE(ur.last_name, ''))) requested_by_name,
                ur.username requested_by_username
           FROM stock_transfers t
           JOIN warehouses sw ON sw.warehouse_id = t.source_warehouse_id AND sw.tenant_id = t.tenant_id
           LEFT JOIN shops ssw ON ssw.shop_id = sw.shop_id AND ssw.tenant_id = sw.tenant_id
           JOIN warehouses w ON w.warehouse_id = t.destination_warehouse_id AND w.tenant_id = t.tenant_id
           LEFT JOIN shops s ON s.shop_id = t.destination_shop_id AND s.tenant_id = t.tenant_id
           LEFT JOIN users ur ON ur.user_id = t.requested_by
          WHERE t.transfer_id = ? AND t.tenant_id = ?",
        (int)$transfer_id,
        (int)$tenant_id
    );
}

/** Readable "Shop / Store" label for a transfer location. */
function notification_location_label($shop_name, $location_name) {
    $shop_name = trim((string)$shop_name);
    $location_name = trim((string)$location_name);
    if ($shop_name !== '' && $location_name !== '' && strcasecmp($shop_name, $location_name) !== 0) {
        return $shop_name . ' / ' . $location_name;
    }
    return $location_name !== '' ? $location_name : $shop_name;
}

/** Users who should review stock requests arriving at a source location. */
function notification_transfer_source_user_ids($context, $permission, $exclude_user_id = 0) {
    if (!$context) return [];
    $tenant_id = (int)$context['tenant_id'];
    $source_id = (int)$context['source_warehouse_id'];

    $ids = array_merge(
        notification_user_ids_for_location_permission($tenant_id, $source_id, $permission, $exclude_user_id),
        notification_user_ids_for_location_permission($tenant_id, $source_id, 'manage_godown', $exclude_user_id)
    );
    if (!$ids) {
        $ids = notification_user_ids_with_permission($tenant_id, $permission, $exclude_user_id);
    }
    return notification_filter_user_ids($ids, $exclude_user_id);
}

/** Tell source staff that a location has requested stock. */
function notification_notify_stock_request($transfer_id, $tenant_id, $actor_user_id = 0) {
    $context = notification_transfer_context($transfer_id, $tenant_id);
    if (!$context) return 0;

    $tenant_id = (int)$tenant_id;
    $source = notification_location_label($context['source_shop_name'] ?? '', $context['source_name'] ?? '');
    $destination = notification_location_label($context['destination_shop_name'] ?? '', $context['destination_name'] ?? '');
    $requested_by = trim((string)($context['requested_by_name'] ?? '')) ?: (string)($context['requested_by_username'] ?? 'A location');
    $number = (string)$context['transfer_number'];

    $ids = array_merge(
        notification_transfer_source_user_ids($context, 'approve_godown_transfers', $actor_user_id),
        notification_transfer_source_user_ids($context, 'dispatch_godown_transfers', $actor_user_id)
    );

    return notification_create_for_users(
        $ids,
        $tenant_id,
        'stock_request',
        'Stock requested: ' . $number,
        $requested_by . ' requested stock from ' . $source . ' for ' . $destination . '. Review the request and approve or dispatch it.',
        'godown-transfer-view.php?id=' . (int)$transfer_id,
        'fa-inbox',
        'transfer:' . (int)$transfer_id . ':requested',
        $actor_user_id
    );
}

/** Tell source dispatchers an approved transfer is ready to move. */
function notification_notify_transfer_approved($transfer_id, $tenant_id, $actor_user_id = 0) {
    $context = notification_transfer_context($transfer_id, $tenant_id);
    if (!$context) return 0;

    $source = notification_location_label($context['source_shop_name'] ?? '', $context['source_name'] ?? '');
    $destination = notification_location_label($context['destination_shop_name'] ?? '', $context['destination_name'] ?? '');
    $number = (string)$context['transfer_number'];

    $ids = notification_transfer_source_user_ids($context, 'dispatch_godown_transfers', $actor_user_id);
    return notification_create_for_users(
        $ids,
        (int)$tenant_id,
        'transfer_approved',
        'Transfer approved: ' . $number,
        'Transfer ' . $number . ' was approved. Dispatch it from ' . $source . ' to ' . $destination . '.',
        'godown-transfer-view.php?id=' . (int)$transfer_id,
        'fa-check',
        'transfer:' . (int)$transfer_id . ':approved',
        $actor_user_id
    );
}

/** Tell destination staff that stock is on the way. */
function notification_notify_transfer_dispatched($transfer_id, $tenant_id, $actor_user_id = 0) {
    $context = notification_transfer_context($transfer_id, $tenant_id);
    if (!$context) return 0;

    $source = notification_location_label($context['source_shop_name'] ?? '', $context['source_name'] ?? '');
    $destination = notification_location_label($context['destination_shop_name'] ?? '', $context['destination_name'] ?? '');
    $number = (string)$context['transfer_number'];
    $destination_id = (int)$context['destination_warehouse_id'];

    $ids = array_merge(
        notification_user_ids_at_location((int)$tenant_id, $destination_id, $actor_user_id, false),
        notification_user_ids_for_location_permission((int)$tenant_id, $destination_id, 'receive_godown_transfers', $actor_user_id)
    );

    return notification_create_for_users(
        $ids,
        (int)$tenant_id,
        'transfer_dispatched',
        'Stock dispatched: ' . $number,
        'Stock from ' . $source . ' is on the way to ' . $destination . '. Confirm receipt when it arrives.',
        'godown-transfer-view.php?id=' . (int)$transfer_id,
        'fa-truck',
        'transfer:' . (int)$transfer_id . ':dispatched',
        $actor_user_id
    );
}

/** Tell the requester and source team that stock was received. */
function notification_notify_transfer_received($transfer_id, $tenant_id, $accepted_total = null, $has_discrepancy = false, $actor_user_id = 0) {
    $context = notification_transfer_context($transfer_id, $tenant_id);
    if (!$context) return 0;

    $source = notification_location_label($context['source_shop_name'] ?? '', $context['source_name'] ?? '');
    $destination = notification_location_label($context['destination_shop_name'] ?? '', $context['destination_name'] ?? '');
    $number = (string)$context['transfer_number'];

    $ids = [];
    if ((int)($context['requested_by'] ?? 0) > 0) {
        $ids[] = (int)$context['requested_by'];
    }
    $ids = array_merge($ids, notification_transfer_source_user_ids($context, 'approve_godown_transfers', $actor_user_id));

    $message = $destination . ' confirmed receipt of ' . format_quantity($accepted_total ?? 0)
        . ' unit(s) from ' . $source . '.';
    if ($has_discrepancy) {
        $message .= ' A quantity discrepancy was recorded for review.';
    }

    return notification_create_for_users(
        $ids,
        (int)$tenant_id,
        $has_discrepancy ? 'transfer_discrepancy' : 'transfer_received',
        ($has_discrepancy ? 'Transfer discrepancy: ' : 'Stock received: ') . $number,
        $message,
        'godown-transfer-view.php?id=' . (int)$transfer_id,
        $has_discrepancy ? 'fa-exclamation-triangle' : 'fa-check-circle',
        'transfer:' . (int)$transfer_id . ':received',
        $actor_user_id
    );
}

/** Tell the requester a transfer was cancelled. */
function notification_notify_transfer_cancelled($transfer_id, $tenant_id, $actor_user_id = 0) {
    $context = notification_transfer_context($transfer_id, $tenant_id);
    if (!$context) return 0;

    $ids = [(int)($context['requested_by'] ?? 0)];
    $source = notification_location_label($context['source_shop_name'] ?? '', $context['source_name'] ?? '');
    $destination = notification_location_label($context['destination_shop_name'] ?? '', $context['destination_name'] ?? '');
    $number = (string)$context['transfer_number'];

    return notification_create_for_users(
        $ids,
        (int)$tenant_id,
        'transfer_cancelled',
        'Transfer cancelled: ' . $number,
        'The transfer from ' . $source . ' to ' . $destination . ' was cancelled. No stock was moved.',
        'godown-transfer-view.php?id=' . (int)$transfer_id,
        'fa-ban',
        'transfer:' . (int)$transfer_id . ':cancelled',
        $actor_user_id
    );
}

/** Tell the requester a discrepancy review is complete. */
function notification_notify_transfer_resolved($transfer_id, $tenant_id, $actor_user_id = 0) {
    $context = notification_transfer_context($transfer_id, $tenant_id);
    if (!$context) return 0;

    $number = (string)$context['transfer_number'];
    $ids = [(int)($context['requested_by'] ?? 0)];

    return notification_create_for_users(
        $ids,
        (int)$tenant_id,
        'transfer_resolved',
        'Discrepancy resolved: ' . $number,
        'The quantity discrepancy on transfer ' . $number . ' has been reviewed and closed.',
        'godown-transfer-view.php?id=' . (int)$transfer_id,
        'fa-check-circle',
        'transfer:' . (int)$transfer_id . ':resolved',
        $actor_user_id
    );
}

/** Tell order managers that a new order was created. */
function notification_notify_order_created($order_id, $tenant_id, $actor_user_id = 0) {
    $order = fetch_one(
        "SELECT order_id, order_number, customer_name, total, currency_code
           FROM orders WHERE order_id = ? AND tenant_id = ?",
        (int)$order_id,
        (int)$tenant_id
    );
    if (!$order) return 0;

    $ids = notification_user_ids_with_permission($tenant_id, 'manage_orders', $actor_user_id);
    $currency = (string)($order['currency_code'] ?? '') ?: tenant_currency();

    return notification_create_for_users(
        $ids,
        (int)$tenant_id,
        'order_created',
        'New order: ' . $order['order_number'],
        $order['customer_name'] . ' — ' . money($order['total'], $currency) . '.',
        'order-view.php?id=' . (int)$order_id,
        'fa-list-alt',
        'order:' . (int)$order_id . ':created',
        $actor_user_id
    );
}

/** Tell finance/order staff that an order is fully paid. */
function notification_notify_order_paid($order_id, $tenant_id, $actor_user_id = 0) {
    $order = fetch_one(
        "SELECT order_id, order_number, customer_name, total, Amount_paid, currency_code, stage
           FROM orders WHERE order_id = ? AND tenant_id = ?",
        (int)$order_id,
        (int)$tenant_id
    );
    if (!$order || order_is_returned($order)) return 0;

    $total = round((float)$order['total'], 2);
    $paid = round((float)$order['Amount_paid'], 2);
    if ($total <= 0 || $paid + 0.005 < $total) return 0;

    $ids = array_merge(
        notification_user_ids_with_permission($tenant_id, 'manage_invoices', $actor_user_id),
        notification_user_ids_with_permission($tenant_id, 'manage_orders', $actor_user_id)
    );
    $currency = (string)($order['currency_code'] ?? '') ?: tenant_currency();

    return notification_create_for_users(
        $ids,
        (int)$tenant_id,
        'order_paid',
        'Order fully paid: ' . $order['order_number'],
        $order['customer_name'] . ' paid ' . money($total, $currency) . ' in full.',
        'order-view.php?id=' . (int)$order_id,
        'fa-check-circle',
        'order:' . (int)$order_id . ':paid',
        $actor_user_id
    );
}

/** Tell order managers that an online or wholesale order request is waiting. */
function notification_notify_order_request($tenant_id, $lead_id, $lead_number, $customer_name, $amount, $currency, $source = 'online', $actor_user_id = 0) {
    $ids = notification_user_ids_with_permission($tenant_id, 'manage_orders', $actor_user_id);
    $source_label = strtolower((string)$source) === 'wholesale' ? 'wholesale' : 'online';
    $currency = (string)$currency !== '' ? (string)$currency : tenant_currency();

    return notification_create_for_users(
        $ids,
        (int)$tenant_id,
        'order_request',
        'New ' . $source_label . ' order request: ' . $lead_number,
        trim((string)$customer_name) . ' requested products worth ' . money($amount, $currency) . '. Review and confirm the request.',
        'lead-view.php?id=' . (int)$lead_id,
        'fa-file-text-o',
        'lead:' . (int)$lead_id . ':request',
        $actor_user_id
    );
}
