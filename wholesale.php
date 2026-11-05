<?php
/**
 * Wholesale customer portal helpers: order requests, history and activity.
 */
require_once __DIR__ . '/config.php';

/** Human label and Bootstrap badge for a lead / order request status. */
function wholesale_status_meta($status) {
    switch (strtolower(trim((string)$status))) {
        case 'new':
            return ['label' => 'New request', 'class' => 'badge-info'];
        case 'pending':
            return ['label' => 'Pending review', 'class' => 'badge-warning'];
        case 'confirmed':
            return ['label' => 'Confirmed', 'class' => 'badge-primary'];
        case 'converted':
            return ['label' => 'Converted to order', 'class' => 'badge-success'];
        case 'cancelled':
            return ['label' => 'Cancelled', 'class' => 'badge-secondary'];
        case 'lost':
            return ['label' => 'Closed', 'class' => 'badge-secondary'];
        default:
            $status = trim((string)$status);
            return ['label' => $status !== '' ? ucfirst($status) : 'Unknown', 'class' => 'badge-secondary'];
    }
}

/** Fulfilment stages shown to wholesale customers. */
function wholesale_order_stages() {
    return [
        'shop'      => 'Preparing at shop',
        'transport' => 'In transit',
        'completed' => 'Completed',
        'returned'  => 'Returned',
    ];
}

/** Label and badge class for a wholesale order fulfilment stage. */
function wholesale_order_stage_meta($stage) {
    $stage = strtolower(trim((string)$stage));
    $labels = wholesale_order_stages();
    $classes = [
        'shop'      => 'is-neutral',
        'transport' => 'is-info',
        'completed' => 'is-success',
        'returned'  => 'is-danger',
    ];
    return [
        'label' => $labels[$stage] ?? ($stage !== '' ? ucfirst($stage) : 'Unknown'),
        'class' => $classes[$stage] ?? 'is-neutral',
    ];
}

/** Whether a wholesale order has been confirmed as received by the customer. */
function wholesale_order_is_received($order) {
    return $order && !empty($order['customer_confirmed_at']);
}

/**
 * Whether the wholesale customer may confirm this order as received.
 * Only confirmed/fulfilled orders that have not been returned or already confirmed.
 */
function wholesale_order_can_confirm_receipt($order) {
    if (!$order || wholesale_order_is_received($order)) return false;
    $status = strtolower((string)($order['status'] ?? ''));
    if (!in_array($status, ['confirmed', 'fulfilled'], true)) return false;
    $stage = strtolower((string)($order['stage'] ?? 'shop'));
    return $stage !== 'returned';
}

/** Whether a wholesale order has been fully paid. */
function wholesale_order_is_paid($order) {
    if (!$order) return false;
    $total = round((float)($order['total'] ?? 0), 2);
    $paid = round((float)($order['Amount_paid'] ?? 0), 2);
    return $total > 0 && $paid + 0.005 >= $total;
}

/** Payment label and badge class for a wholesale order. */
function wholesale_order_payment_meta($order) {
    $total = round((float)($order['total'] ?? 0), 2);
    $paid = round((float)($order['Amount_paid'] ?? 0), 2);
    if ($total > 0 && $paid + 0.005 >= $total) {
        return ['label' => 'Paid', 'class' => 'is-success'];
    }
    if ($paid > 0) {
        return ['label' => 'Partially paid', 'class' => 'is-info'];
    }
    return ['label' => 'Unpaid', 'class' => 'is-neutral'];
}

/**
 * Notify the wholesale customer when their order becomes fully paid.
 * Safe to call after any order amount update; duplicates are ignored.
 */
function wholesale_notify_order_paid($order_id, $tenant_id) {
    global $conn;

    if (!db_table_exists('wholesale_notifications')) return false;

    $order = fetch_one(
        "SELECT o.order_id, o.order_number, o.currency_code, o.total, o.Amount_paid,
                w.user_id AS wholesaler_user_id, w.contact_id
           FROM orders o
           JOIN wholesaler_accounts w
             ON w.contact_id = o.contact_id AND w.tenant_id = o.tenant_id
          WHERE o.order_id = ? AND o.tenant_id = ?
          LIMIT 1",
        (int)$order_id,
        (int)$tenant_id
    );
    if (!$order || !wholesale_order_is_paid($order)) return false;

    $user_id = (int)($order['wholesaler_user_id'] ?? 0);
    if ($user_id <= 0) return false;

    $currency = (string)($order['currency_code'] ?? '') ?: tenant_currency();
    $title = 'Payment received for ' . $order['order_number'];
    $message = 'We have received full payment of ' . money($order['total'], $currency)
        . ' for order ' . $order['order_number'] . '. Your receipt is ready to view or download.';

    $stmt = $conn->prepare(
        "INSERT IGNORE INTO wholesale_notifications
            (tenant_id, user_id, contact_id, order_id, type, title, message, created_at)
         VALUES (?, ?, ?, ?, 'order_paid', ?, ?, NOW())"
    );
    if (!$stmt) return false;
    $contact_id = (int)($order['contact_id'] ?? 0) ?: null;
    $stmt->bind_param(
        'iiiiss',
        $tenant_id,
        $user_id,
        $contact_id,
        $order_id,
        $title,
        $message
    );
    $ok = $stmt->execute();
    $created = $ok && $stmt->affected_rows > 0;
    $stmt->close();
    return $created;
}

/** Notifications for a wholesale portal user, newest first. */
function wholesale_notifications($tenant_id, $user_id, $limit = 50, $offset = 0) {
    if (!db_table_exists('wholesale_notifications')) return [];
    $limit = max(1, min(200, (int)$limit));
    $offset = max(0, (int)$offset);
    return fetch_all(
        "SELECT n.*,
                (SELECT r.request_id FROM storefront_lead_requests r
                  WHERE r.converted_order_id = n.order_id AND r.tenant_id = n.tenant_id
                  ORDER BY r.request_id LIMIT 1) AS request_id,
                o.order_number, o.total AS order_total, o.Amount_paid AS order_amount_paid,
                o.currency_code AS order_currency_code
           FROM wholesale_notifications n
           LEFT JOIN orders o ON o.order_id = n.order_id AND o.tenant_id = n.tenant_id
          WHERE n.tenant_id = ? AND n.user_id = ?
          ORDER BY n.created_at DESC, n.notification_id DESC
          LIMIT {$limit} OFFSET {$offset}",
        (int)$tenant_id,
        (int)$user_id
    );
}

/** Number of unread wholesale notifications. */
function wholesale_unread_notification_count($tenant_id, $user_id) {
    if (!db_table_exists('wholesale_notifications')) return 0;
    $row = fetch_one(
        "SELECT COUNT(*) AS total FROM wholesale_notifications
          WHERE tenant_id = ? AND user_id = ? AND read_at IS NULL",
        (int)$tenant_id,
        (int)$user_id
    );
    return (int)($row['total'] ?? 0);
}

/** Mark one notification, or all notifications, as read. */
function wholesale_mark_notifications_read($tenant_id, $user_id, $notification_id = null) {
    global $conn;
    if (!db_table_exists('wholesale_notifications')) return;
    if ($notification_id !== null) {
        $stmt = $conn->prepare(
            "UPDATE wholesale_notifications SET read_at = NOW()
              WHERE notification_id = ? AND tenant_id = ? AND user_id = ? AND read_at IS NULL"
        );
        if (!$stmt) return;
        $notification_id = (int)$notification_id;
        $stmt->bind_param('iii', $notification_id, $tenant_id, $user_id);
    } else {
        $stmt = $conn->prepare(
            "UPDATE wholesale_notifications SET read_at = NOW()
              WHERE tenant_id = ? AND user_id = ? AND read_at IS NULL"
        );
        if (!$stmt) return;
        $stmt->bind_param('ii', $tenant_id, $user_id);
    }
    $stmt->execute();
    $stmt->close();
}

/** One order belonging to a wholesale contact, or null. */
function wholesale_order($tenant_id, $order_id, $contact_id) {
    return fetch_one(
        "SELECT o.*, w.name store_name, s.name shop_name
           FROM orders o
           LEFT JOIN warehouses w ON w.warehouse_id = o.warehouse_id AND w.tenant_id = o.tenant_id
           LEFT JOIN shops s ON s.shop_id = o.shop_id AND s.tenant_id = o.tenant_id
          WHERE o.order_id = ? AND o.tenant_id = ? AND o.contact_id = ?
          LIMIT 1",
        (int)$order_id,
        (int)$tenant_id,
        (int)$contact_id
    );
}

/** Order-request list for one wholesale contact. */
function wholesale_requests($tenant_id, $contact_id, $limit = 50, $offset = 0) {
    $limit = max(1, min(200, (int)$limit));
    $offset = max(0, (int)$offset);
    return fetch_all(
        "SELECT r.request_id, r.currency_code, r.subtotal, r.created_at, r.converted_order_id,
                l.lead_id, l.lead_number, l.status, l.notes,
                o.order_id, o.order_number, o.status AS order_status, o.stage AS order_stage,
                o.customer_confirmed_at AS order_customer_confirmed_at,
                o.total AS order_total, o.Amount_paid AS order_amount_paid,
                o.currency_code AS order_currency_code,
                o.created_at AS order_created_at
           FROM storefront_lead_requests r
           JOIN leads l ON l.lead_id = r.lead_id AND l.tenant_id = r.tenant_id
           LEFT JOIN orders o ON o.order_id = r.converted_order_id AND o.tenant_id = r.tenant_id
          WHERE r.tenant_id = ? AND l.contact_id = ?
          ORDER BY r.created_at DESC, r.request_id DESC
          LIMIT ? OFFSET ?",
        (int)$tenant_id,
        (int)$contact_id,
        $limit,
        $offset
    );
}

/** Number of order requests for one wholesale contact. */
function wholesale_request_count($tenant_id, $contact_id) {
    $row = fetch_one(
        "SELECT COUNT(*) AS total
           FROM storefront_lead_requests r
           JOIN leads l ON l.lead_id = r.lead_id AND l.tenant_id = r.tenant_id
          WHERE r.tenant_id = ? AND l.contact_id = ?",
        (int)$tenant_id,
        (int)$contact_id
    );
    return (int)($row['total'] ?? 0);
}

/** One request belonging to the contact, with its converted order, or null. */
function wholesale_request($tenant_id, $request_id, $contact_id) {
    return fetch_one(
        "SELECT r.*, l.lead_number, l.status, l.notes, l.contact_name, l.company_name, l.phone, l.email, l.location,
                o.order_id, o.order_number, o.status AS order_status, o.stage AS order_stage,
                o.customer_confirmed_at AS order_customer_confirmed_at,
                o.customer_confirmation_note AS order_customer_confirmation_note,
                o.total AS order_total, o.Amount_paid AS order_amount_paid,
                o.currency_code AS order_currency_code,
                o.created_at AS order_created_at
           FROM storefront_lead_requests r
           JOIN leads l ON l.lead_id = r.lead_id AND l.tenant_id = r.tenant_id
           LEFT JOIN orders o ON o.order_id = r.converted_order_id AND o.tenant_id = r.tenant_id
          WHERE r.request_id = ? AND r.tenant_id = ? AND l.contact_id = ?
          LIMIT 1",
        (int)$request_id,
        (int)$tenant_id,
        (int)$contact_id
    );
}

/** Line items for an order request. */
function wholesale_request_items($tenant_id, $request_id) {
    return fetch_all(
        "SELECT * FROM storefront_lead_request_items
          WHERE tenant_id = ? AND request_id = ?
          ORDER BY line_no ASC",
        (int)$tenant_id,
        (int)$request_id
    );
}

/** Confirmed orders for the wholesale contact. */
function wholesale_orders($tenant_id, $contact_id, $limit = 50) {
    $limit = max(1, min(200, (int)$limit));
    return fetch_all(
        "SELECT o.order_id, o.order_number, o.status, o.stage, o.customer_confirmed_at,
                o.currency_code, o.subtotal, o.total, o.Amount_paid, o.created_at, o.notes,
                (SELECT r.request_id FROM storefront_lead_requests r
                  WHERE r.converted_order_id = o.order_id AND r.tenant_id = o.tenant_id
                  ORDER BY r.request_id LIMIT 1) AS request_id
           FROM orders o
          WHERE o.tenant_id = ? AND o.contact_id = ?
          ORDER BY created_at DESC, order_id DESC
          LIMIT {$limit}",
        (int)$tenant_id,
        (int)$contact_id
    );
}

/** Aggregated portal stats for one wholesale contact. */
function wholesale_stats($tenant_id, $contact_id) {
    $tenant_id = (int)$tenant_id;
    $contact_id = (int)$contact_id;
    $requests = fetch_one(
        "SELECT COUNT(*) AS total, COALESCE(SUM(r.subtotal),0) AS value
           FROM storefront_lead_requests r
           JOIN leads l ON l.lead_id = r.lead_id AND l.tenant_id = r.tenant_id
          WHERE r.tenant_id = ? AND l.contact_id = ?",
        $tenant_id,
        $contact_id
    ) ?: [];
    $orders = fetch_one(
        "SELECT COUNT(*) AS total, COALESCE(SUM(total),0) AS value,
                SUM(CASE WHEN status NOT IN ('cancelled','rejected') THEN 1 ELSE 0 END) AS active_total
           FROM orders
          WHERE tenant_id = ? AND contact_id = ?",
        $tenant_id,
        $contact_id
    ) ?: [];
    return [
        'request_count'     => (int)($requests['total'] ?? 0),
        'request_value'     => (float)($requests['value'] ?? 0),
        'order_count'       => (int)($orders['total'] ?? 0),
        'order_value'       => (float)($orders['value'] ?? 0),
        'active_order_count'=> (int)($orders['active_total'] ?? 0),
    ];
}

/** Recent activity for a wholesale user (their own audit trail). */
function wholesale_activity($tenant_id, $user_id, $limit = 30) {
    $limit = max(1, min(200, (int)$limit));
    return fetch_all(
        "SELECT action, entity_type, entity_id, details, created_at
           FROM audit_logs
          WHERE tenant_id = ? AND user_id = ?
          ORDER BY created_at DESC, log_id DESC
          LIMIT {$limit}",
        (int)$tenant_id,
        (int)$user_id
    );
}

/** Admin list of wholesale accounts with request counts. */
function wholesale_admin_accounts($tenant_id, $status = '', $query = '', $channel = '') {
    $sql = "SELECT w.*, u.username, u.is_active AS user_active, u.last_login,
                   c.customer_channel, c.customer_number,
                   (SELECT COUNT(*) FROM storefront_lead_requests r
                      JOIN leads l ON l.lead_id = r.lead_id AND l.tenant_id = r.tenant_id
                     WHERE r.tenant_id = w.tenant_id AND l.contact_id = w.contact_id) AS request_count,
                   (SELECT COUNT(*) FROM orders o
                     WHERE o.tenant_id = w.tenant_id AND o.contact_id = w.contact_id) AS order_count
              FROM wholesaler_accounts w
              JOIN users u ON u.user_id = w.user_id
              LEFT JOIN contacts c ON c.contact_id = w.contact_id AND c.tenant_id = w.tenant_id
             WHERE w.tenant_id = ?";
    $params = [(int)$tenant_id];

    if ($status === 'active' || $status === 'suspended') {
        $sql .= " AND w.status = ?";
        $params[] = $status;
    }
    if ($channel === 'online' || $channel === 'walk_in') {
        $sql .= " AND c.customer_channel = ?";
        $params[] = $channel;
    }
    $query = trim((string)$query);
    if ($query !== '') {
        $like = '%' . $query . '%';
        $sql .= " AND (w.business_name LIKE ? OR w.contact_name LIKE ? OR w.phone LIKE ? OR w.email LIKE ? OR u.username LIKE ?)";
        array_push($params, $like, $like, $like, $like, $like);
    }
    $sql .= " ORDER BY w.created_at DESC, w.wholesaler_id DESC";
    return fetch_all($sql, ...$params);
}

/** Digits-only phone value for comparisons. */
function wholesale_phone_digits($value) {
    return preg_replace('/\D+/', '', (string)$value);
}

/** Whether a phone number already belongs to a user in this tenant. */
function wholesale_phone_in_use($tenant_id, $phone) {
    $digits = wholesale_phone_digits($phone);
    if (strlen($digits) < 7) return false;
    $rows = fetch_all(
        "SELECT phone FROM users WHERE tenant_id = ? AND phone IS NOT NULL AND phone <> ''",
        (int)$tenant_id
    );
    foreach ($rows as $row) {
        $existing = wholesale_phone_digits($row['phone']);
        if (strlen($existing) >= 7 && substr($existing, -9) === substr($digits, -9)) {
            return true;
        }
    }
    return false;
}

/**
 * Create a wholesale account (contact + user + role + profile) in one transaction.
 * Validation is the caller's responsibility.
 *
 * @return array{user_id:int,contact_id:int,wholesaler_id:int,username:string,customer_number:string}
 */
function create_wholesale_account($tenant_id, array $input) {
    global $conn;

    $tenant_id = (int)$tenant_id;
    $name = trim((string)($input['name'] ?? ''));
    $phone = trim((string)($input['phone'] ?? ''));
    $location = trim((string)($input['location'] ?? ''));
    $password = (string)($input['password'] ?? '');
    $email = trim((string)($input['email'] ?? ''));

    $conn->begin_transaction();
    try {
        $role_id = ensure_wholesaler_role($tenant_id);
        $username = wholesaler_unique_username($tenant_id, $name, $email);
        $password_hash = password_hash($password, PASSWORD_DEFAULT);
        $name_parts = preg_split('/\s+/', $name, 2);
        $first_name = $name_parts[0] ?? $name;
        $last_name = $name_parts[1] ?? '';
        $customer_number = next_document_number($tenant_id, 'customer', 'CUS');

        $stmt = $conn->prepare(
            "INSERT INTO contacts
                (tenant_id, contact_type_id, customer_type, customer_channel, customer_number,
                 first_name, last_name, company_name, email, phone, address, is_customer, is_active)
             VALUES (?, 1, 'wholesale', 'online', ?, ?, ?, ?, ?, ?, ?, 1, 1)"
        );
        if (!$stmt) throw new RuntimeException($conn->error);
        $email_db = $email !== '' ? $email : null;
        $stmt->bind_param(
            'isssssss',
            $tenant_id,
            $customer_number,
            $first_name,
            $last_name,
            $name,
            $email_db,
            $phone,
            $location
        );
        if (!$stmt->execute()) throw new RuntimeException($stmt->error);
        $contact_id = (int)$stmt->insert_id;
        $stmt->close();

        $stmt = $conn->prepare(
            "INSERT INTO users (tenant_id, username, email, password_hash, first_name, last_name, phone, is_active)
             VALUES (?, ?, ?, ?, ?, ?, ?, 1)"
        );
        if (!$stmt) throw new RuntimeException($conn->error);
        $user_email = $email !== '' ? $email : $username . '@wholesale.local';
        $stmt->bind_param(
            'issssss',
            $tenant_id,
            $username,
            $user_email,
            $password_hash,
            $first_name,
            $last_name,
            $phone
        );
        if (!$stmt->execute()) throw new RuntimeException($stmt->error);
        $user_id = (int)$stmt->insert_id;
        $stmt->close();

        $stmt = $conn->prepare("INSERT INTO user_roles (user_id, role_id, tenant_id) VALUES (?, ?, ?)");
        if (!$stmt) throw new RuntimeException($conn->error);
        $stmt->bind_param('iii', $user_id, $role_id, $tenant_id);
        if (!$stmt->execute()) throw new RuntimeException($stmt->error);
        $stmt->close();

        $stmt = $conn->prepare(
            "INSERT INTO wholesaler_accounts
                (tenant_id, user_id, contact_id, business_name, business_type, contact_name, phone, email,
                 location, monthly_volume, product_interest, notes, status)
             VALUES (?, ?, ?, ?, NULL, ?, ?, ?, ?, NULL, NULL, NULL, 'active')"
        );
        if (!$stmt) throw new RuntimeException($conn->error);
        $stmt->bind_param(
            'iiisssss',
            $tenant_id,
            $user_id,
            $contact_id,
            $name,
            $name,
            $phone,
            $email_db,
            $location
        );
        if (!$stmt->execute()) throw new RuntimeException($stmt->error);
        $wholesaler_id = (int)$stmt->insert_id;
        $stmt->close();

        // Registration creates the customer account only. Leads are created
        // when the customer actually submits an online order request.
        $conn->commit();
        return [
            'user_id'         => $user_id,
            'contact_id'      => $contact_id,
            'wholesaler_id'   => $wholesaler_id,
            'username'        => $username,
            'customer_number' => $customer_number,
        ];
    } catch (Throwable $exception) {
        $conn->rollback();
        throw $exception;
    }
}

