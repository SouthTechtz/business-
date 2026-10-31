<?php
// ============================================================
// Shared helper functions
// ============================================================

/** Escape output for safe HTML rendering. */
function e($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

/** Format a number as money using the tenant currency. */
function money($amount, $currency = 'TZS') {
    return $currency . ' ' . number_format((float)$amount, 2);
}

/** Get current tenant's currency code (cached per request). */
function tenant_currency() {
    global $conn, $current_user;
    static $currency = null;
    if ($currency !== null) return $currency;
    $currency = 'TZS';
    if (isset($current_user->tenant_id)) {
        $stmt = $conn->prepare("SELECT currency_code FROM tenants WHERE tenant_id = ?");
        $stmt->bind_param('i', $current_user->tenant_id);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        if ($row) $currency = $row['currency_code'];
        $stmt->close();
    }
    return $currency;
}

/** Whether the business charges tax (VAT) on sales and invoices (cached per request). */
function tax_enabled() {
    global $conn, $current_user;
    static $enabled = null;
    if ($enabled !== null) return $enabled;
    $enabled = true;
    if (isset($current_user->tenant_id)) {
        $row = fetch_one("SELECT apply_tax FROM system_settings WHERE tenant_id = ?", $current_user->tenant_id);
        if ($row !== null) $enabled = (int)$row['apply_tax'] === 1;
    }
    return $enabled;
}

/** Set a one-time flash message. */
function flash_set($type, $message) {
    $_SESSION['flash'] = ['type' => $type, 'message' => $message];
}

/** Get and clear the flash message. */
function flash_get() {
    if (!empty($_SESSION['flash'])) {
        $f = $_SESSION['flash'];
        unset($_SESSION['flash']);
        return $f;
    }
    return null;
}

/** Redirect and stop execution. */
function redirect($url) {
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    header('Location: ' . $url);
    exit;
}

/** Check a permission on behalf of the current user. */
function can($permission) {
    global $current_user;
    return $current_user && $current_user->hasPermission($permission);
}

/** True when the user has tenant-wide shop visibility (full administrators). */
function tenant_admin_scope() {
    return can('manage_users') || can('manage_settings');
}

/**
 * Shops the current user may access.
 * Full administrators see every shop; other users see only mapped shops.
 */
function accessible_shops($tenant_id = null, $include_inactive = false) {
    global $current_user;
    if (!$current_user) return [];
    $tenant_id = $tenant_id === null ? (int)$current_user->tenant_id : (int)$tenant_id;
    if ($tenant_id !== (int)$current_user->tenant_id) return [];
    $active_sql = $include_inactive ? '' : ' AND s.is_active = 1';
    if (tenant_admin_scope()) {
        return fetch_all(
            "SELECT s.* FROM shops s WHERE s.tenant_id = ?{$active_sql} ORDER BY s.name",
            $tenant_id
        );
    }
    return fetch_all(
        "SELECT s.* FROM shops s
         WHERE s.tenant_id = ?{$active_sql}
           AND (
             EXISTS (SELECT 1 FROM user_shops us WHERE us.tenant_id = s.tenant_id AND us.user_id = ? AND us.shop_id = s.shop_id)
             OR EXISTS (SELECT 1 FROM user_inventory_locations uil JOIN warehouses w ON w.warehouse_id = uil.location_id AND w.tenant_id = uil.tenant_id WHERE uil.tenant_id = s.tenant_id AND uil.user_id = ? AND w.shop_id = s.shop_id AND w.location_type = 'shop')
           )
         ORDER BY s.name",
        $tenant_id,
        $current_user->user_id,
        $current_user->user_id
    );
}

/** IDs of shops the current user may access. */
function accessible_shop_ids($tenant_id = null, $include_inactive = false) {
    return array_map(
        fn($row) => (int)$row['shop_id'],
        accessible_shops($tenant_id, $include_inactive)
    );
}

/**
 * Stores (warehouses) the current user may access.
 * Pass $shop_id to restrict to one shop.
 */
function accessible_stores($shop_id = null, $include_inactive = false, $tenant_id = null, $include_godown = false) {
    global $current_user;
    if (!$current_user) return [];
    $tenant_id = $tenant_id === null ? (int)$current_user->tenant_id : (int)$tenant_id;
    if ($tenant_id !== (int)$current_user->tenant_id) return [];

    $shops = accessible_shops($tenant_id, $include_inactive);
    $shop_ids = array_map(fn($row) => (int)$row['shop_id'], $shops);

    $rows = [];
    if ($shop_ids) {
        $placeholders = implode(',', array_fill(0, count($shop_ids), '?'));

        $sql = "SELECT w.*, s.name shop_name, s.code shop_code, s.is_active shop_is_active
                FROM warehouses w
                JOIN shops s ON s.shop_id = w.shop_id AND s.tenant_id = w.tenant_id
                WHERE w.tenant_id = ? AND w.shop_id IN ({$placeholders}) AND w.location_type = 'store'";
        $params = array_merge([$tenant_id], $shop_ids);
        if (!$include_inactive) {
            $sql .= " AND w.is_active = 1 AND s.is_active = 1";
        }
        if ($shop_id !== null) {
            $sql .= " AND w.shop_id = ?";
            $params[] = (int)$shop_id;
        }
        $sql .= " ORDER BY s.name, w.name";
        $rows = fetch_all($sql, ...$params);
    }

    // A Godown is an independent warehouse that does not belong to a shop, so
    // it cannot come from the shop join above. Central pages pass
    // $include_godown = true after checking a Godown permission.
    if ($include_godown && $shop_id === null) {
        $active_sql = $include_inactive ? '' : ' AND w.is_active = 1';
        $godowns = fetch_all(
            "SELECT w.*, NULL AS shop_name, NULL AS shop_code, 1 AS shop_is_active
               FROM warehouses w
              WHERE w.tenant_id = ? AND w.location_type = 'godown'{$active_sql}
              ORDER BY w.name",
            $tenant_id
        );
        $rows = array_merge($rows, $godowns);
    }

    return $rows;
}

/** Whether the current user may work with Godown records. */
function can_access_godown() {
    return can('manage_godown')
        || can('view_godown')
        || can('approve_godown_transfers')
        || can('dispatch_godown_transfers')
        || can('receive_godown_transfers')
        || can('request_godown_transfers');
}

/**
 * Warehouse IDs for scoping list/report queries.
 * Returns null for a full administrator (no restriction), or an array
 * of allowed IDs for a shop-scoped user. An empty array means no access.
 */
function accessible_warehouse_ids($tenant_id = null, $include_inactive = true, $include_godown = false) {
    if (tenant_admin_scope()) return null;
    return array_map(
        fn($row) => (int)$row['warehouse_id'],
        accessible_stores(null, $include_inactive, $tenant_id, $include_godown)
    );
}

/** Whether the current user may access a shop. */
function shop_is_accessible($shop_id, $include_inactive = true) {
    global $current_user;
    $shop_id = (int)$shop_id;
    if (!$current_user || $shop_id <= 0) return false;
    $shop = fetch_one(
        "SELECT * FROM shops WHERE shop_id = ? AND tenant_id = ?",
        $shop_id,
        $current_user->tenant_id
    );
    if (!$shop) return false;
    if (!$include_inactive && !(int)$shop['is_active']) return false;
    if (tenant_admin_scope()) return true;
    $mapped = fetch_one(
        "SELECT 1 FROM user_shops WHERE tenant_id = ? AND user_id = ? AND shop_id = ?",
        $current_user->tenant_id,
        $current_user->user_id,
        $shop_id
    );
    if ($mapped) return true;
    $loc = fetch_one(
        "SELECT 1 FROM user_inventory_locations uil JOIN warehouses w ON w.warehouse_id = uil.location_id AND w.tenant_id = uil.tenant_id WHERE uil.tenant_id = ? AND uil.user_id = ? AND w.shop_id = ? AND w.location_type = 'shop'",
        $current_user->tenant_id,
        $current_user->user_id,
        $shop_id
    );
    return (bool)$loc;
}

/** Return an accessible store row, or null. */
function find_accessible_store($warehouse_id, $include_inactive = false, $include_godown = false) {
    global $current_user;
    $warehouse_id = (int)$warehouse_id;
    if (!$current_user || $warehouse_id <= 0) return null;
    $store = fetch_one(
        "SELECT w.*, s.name shop_name, s.code shop_code, s.is_active shop_is_active
         FROM warehouses w
         LEFT JOIN shops s ON s.shop_id = w.shop_id AND s.tenant_id = w.tenant_id
         WHERE w.warehouse_id = ? AND w.tenant_id = ?",
        $warehouse_id,
        $current_user->tenant_id
    );
    if (!$store) return null;

    if ((string)($store['location_type'] ?? '') === 'godown') {
        if (!$include_godown) return null;
        if (!$include_inactive && !(int)$store['is_active']) return null;
        return can_access_godown() ? $store : null;
    }

    if (!$include_inactive && (!(int)$store['is_active'] || !(int)$store['shop_is_active'])) return null;
    if (!$include_godown && (int)($store['is_godown'] ?? 0) === 1) return null;
    if (shop_is_accessible((int)$store['shop_id'], $include_inactive)) return $store;
    global $current_user;
    if ($current_user && tenant_admin_scope()) return $store;
    if ($current_user) {
        $loc = fetch_one("SELECT 1 FROM user_inventory_locations WHERE tenant_id = ? AND user_id = ? AND location_id = ?", $current_user->tenant_id, $current_user->user_id, (int)$store['warehouse_id']);
        if ($loc) return $store;
    }
    return null;
}

/** Whether the current user may access a store. */
function store_is_accessible($warehouse_id, $include_inactive = false, $include_godown = false) {
    return find_accessible_store($warehouse_id, $include_inactive, $include_godown) !== null;
}

/** The tenant's active central Godown, or null. A Godown is independent of shops. */
function find_active_godown($tenant_id = null, $include_inactive = false) {
    global $current_user;
    if (!$current_user) return null;
    $tenant_id = $tenant_id === null ? (int)$current_user->tenant_id : (int)$tenant_id;
    if ($tenant_id !== (int)$current_user->tenant_id) return null;
    $active_sql = $include_inactive ? '' : ' AND w.is_active = 1';
    return fetch_one(
        "SELECT w.*, NULL AS shop_name, NULL AS shop_code, 1 AS shop_is_active
         FROM warehouses w
         WHERE w.tenant_id = ? AND w.location_type = 'godown'{$active_sql}
         ORDER BY w.is_active DESC, w.warehouse_id
         LIMIT 1",
        $tenant_id
    );
}

/**
 * The tenant's active default Godown regardless of shop access.
 * Use only after checking a Godown permission. Shop requesters may need to
 * request stock from a Godown owned by another shop.
 */
function find_tenant_godown($tenant_id = null) {
    global $current_user;
    if (!$current_user) return null;
    $tenant_id = $tenant_id === null ? (int)$current_user->tenant_id : (int)$tenant_id;
    if ($tenant_id !== (int)$current_user->tenant_id) return null;
    return fetch_one(
        "SELECT w.*, NULL AS shop_name, NULL AS shop_code, 1 AS shop_is_active
         FROM warehouses w
         WHERE w.tenant_id = ? AND w.location_type = 'godown'
           AND w.is_active = 1
         ORDER BY w.warehouse_id
         LIMIT 1",
        $tenant_id
    );
}

/**
 * Ensure the tenant has its default central Godown and return it.
 * The Godown is created empty (no stock) when the tenant has no active one.
 */
function ensure_central_godown($tenant_id = null) {
    global $current_user, $conn;
    if (!$current_user) return null;
    $tenant_id = $tenant_id === null ? (int)$current_user->tenant_id : (int)$tenant_id;
    if ($tenant_id !== (int)$current_user->tenant_id) return null;

    $existing = fetch_one(
        "SELECT * FROM warehouses
          WHERE tenant_id = ? AND location_type = 'godown' AND is_active = 1
          ORDER BY warehouse_id
          LIMIT 1",
        $tenant_id
    );
    if ($existing) return $existing;

    $n = 1;
    do {
        $code = 'GODOWN-' . str_pad((string)$n, 3, '0', STR_PAD_LEFT);
        $taken = fetch_one(
            "SELECT 1 FROM warehouses WHERE tenant_id = ? AND code = ?",
            $tenant_id,
            $code
        );
        $n++;
    } while ($taken);

    $stmt = $conn->prepare(
        "INSERT INTO warehouses (tenant_id, shop_id, name, code, location_type, is_active)
         VALUES (?, NULL, 'Central Godown', ?, 'godown', 1)"
    );
    $stmt->bind_param('is', $tenant_id, $code);
    $stmt->execute();
    $warehouse_id = (int)$stmt->insert_id;
    $stmt->close();

    return fetch_one(
        "SELECT * FROM warehouses WHERE warehouse_id = ? AND tenant_id = ?",
        $warehouse_id,
        $tenant_id
    );
}

/** Guard: stop with 403 unless the shop is accessible. */
function assert_shop_access($shop_id, $include_inactive = true) {
    global $current_user;
    if (!$current_user || !shop_is_accessible($shop_id, $include_inactive)) {
        http_response_code(403);
        die('You do not have access to this shop.');
    }
    $shop = fetch_one(
        "SELECT * FROM shops WHERE shop_id = ? AND tenant_id = ?",
        (int)$shop_id,
        (int)$current_user->tenant_id
    );
    if (!$shop) {
        http_response_code(403);
        die('You do not have access to this shop.');
    }
    return $shop;
}

/** Guard: stop with 403 unless the store is accessible. */
function assert_store_access($warehouse_id, $include_inactive = false, $include_godown = false) {
    $store = find_accessible_store($warehouse_id, $include_inactive, $include_godown);
    if (!$store) {
        http_response_code(403);
        die('You do not have access to this store.');
    }
    return $store;
}

/** Next available shop code for a tenant. */
function next_shop_code($tenant_id) {
    $n = 1;
    do {
        $code = 'SHOP-' . str_pad((string)$n, 3, '0', STR_PAD_LEFT);
        $exists = fetch_one("SELECT 1 FROM shops WHERE tenant_id = ? AND code = ?", $tenant_id, $code);
        $n++;
    } while ($exists);
    return $code;
}

/** Next available store code for a tenant. */
function next_store_code($tenant_id) {
    $n = 1;
    do {
        $code = 'STORE-' . str_pad((string)$n, 3, '0', STR_PAD_LEFT);
        $exists = fetch_one("SELECT 1 FROM warehouses WHERE tenant_id = ? AND code = ?", $tenant_id, $code);
        $n++;
    } while ($exists);
    return $code;
}

/**
 * The signed-in user's current open POS session, or null.
 * The joined warehouse/shop may be inactive or missing; callers must validate
 * the returned warehouse with find_accessible_store() before selling.
 */
function find_open_pos_session($user_id = null, $tenant_id = null) {
    global $current_user;
    if (!$current_user) return null;
    $tenant_id = $tenant_id === null ? (int)$current_user->tenant_id : (int)$tenant_id;
    $user_id = $user_id === null ? (int)$current_user->user_id : (int)$user_id;
    if ($tenant_id !== (int)$current_user->tenant_id || $user_id <= 0) return null;

    return fetch_one(
        "SELECT ps.*, w.name store_name, w.code store_code, w.shop_id,
                w.is_active store_is_active, s.name shop_name, s.code shop_code,
                s.is_active shop_is_active
         FROM pos_sessions ps
         LEFT JOIN warehouses w ON w.warehouse_id = ps.warehouse_id AND w.tenant_id = ps.tenant_id
         LEFT JOIN shops s ON s.shop_id = w.shop_id AND s.tenant_id = w.tenant_id
         WHERE ps.tenant_id = ? AND ps.user_id = ? AND ps.is_closed = 0
         ORDER BY ps.session_id DESC LIMIT 1",
        $tenant_id,
        $user_id
    );
}

/** Generate a collision-free POS session number for a tenant. */
function next_pos_session_number($tenant_id) {
    do {
        $number = 'POS-SESSION-' . date('Ymd') . '-' . str_pad((string)random_int(1, 9999), 4, '0', STR_PAD_LEFT);
        $exists = fetch_one(
            "SELECT 1 FROM pos_sessions WHERE tenant_id = ? AND session_number = ?",
            (int)$tenant_id,
            $number
        );
    } while ($exists);
    return $number;
}

/** All inventory locations (godown/store/shop) accessible to the current user. */
function accessible_locations($include_inactive = false, $tenant_id = null) {
    global $current_user;
    if (!$current_user) return [];
    $tenant_id = $tenant_id === null ? (int)$current_user->tenant_id : (int)$tenant_id;
    if ($tenant_id !== (int)$current_user->tenant_id) return [];
    if (tenant_admin_scope()) {
        $active_sql = $include_inactive ? '' : ' AND w.is_active = 1';
        return fetch_all("SELECT w.*, s.name shop_name, s.code shop_code FROM warehouses w LEFT JOIN shops s ON s.shop_id = w.shop_id AND s.tenant_id = w.tenant_id WHERE w.tenant_id = ?{$active_sql} ORDER BY w.name", $tenant_id);
    }
    $ids = accessible_warehouse_ids($tenant_id, $include_inactive, true);
    if ($ids === null) $ids = [];
    $uil = fetch_all("SELECT location_id FROM user_inventory_locations WHERE tenant_id = ? AND user_id = ?", $tenant_id, $current_user->user_id);
    foreach ($uil as $r) $ids[] = (int)$r['location_id'];
    $ids = array_values(array_unique($ids));
    if (!$ids) return [];
    $ph = implode(',', array_fill(0, count($ids), '?'));
    $active_sql = $include_inactive ? '' : ' AND w.is_active = 1';
    $params = array_merge([$tenant_id], $ids);
    return fetch_all("SELECT w.*, s.name shop_name, s.code shop_code FROM warehouses w LEFT JOIN shops s ON s.shop_id = w.shop_id AND s.tenant_id = w.tenant_id WHERE w.tenant_id = ? AND w.warehouse_id IN ($ph){$active_sql} ORDER BY w.name", ...$params);
}

/** The independent inventory location (warehouse) for a shop, or null. */
function shop_inventory_warehouse_id($shop_id, $tenant_id = null) {
    global $current_user;
    $shop_id = (int)$shop_id;
    if ($shop_id <= 0) return null;
    $tenant_id = $tenant_id === null ? (int)$current_user->tenant_id : (int)$tenant_id;
    $row = fetch_one("SELECT warehouse_id FROM warehouses WHERE tenant_id = ? AND shop_id = ? AND location_type = 'shop' AND is_active = 1 LIMIT 1", $tenant_id, $shop_id);
    return $row ? (int)$row['warehouse_id'] : null;
}

/** Whether a shop has an initialized, non-empty independent inventory location. */
function shop_inventory_ready($shop_id, $tenant_id = null) {
    global $current_user;
    $wh = shop_inventory_warehouse_id($shop_id, $tenant_id);
    if (!$wh) return false;
    $tid = $tenant_id === null ? (int)$current_user->tenant_id : (int)$tenant_id;
    $row = fetch_one("SELECT COALESCE(SUM(quantity),0) AS q FROM inventory_stock WHERE tenant_id = ? AND warehouse_id = ?", $tid, $wh);
    return $row && (float)$row['q'] > 0;
}

/** Display label for a store row that includes shop_name/name. */
function store_label($store) {
    $shop = trim((string)($store['shop_name'] ?? ''));
    $name = trim((string)($store['name'] ?? ''));
    if ($shop !== '' && $name !== '') return $shop . ' / ' . $name;
    return $name !== '' ? $name : $shop;
}

/** Format a stock quantity without noisy trailing zeroes. */
function format_quantity($value) {
    $formatted = number_format((float)$value, 2, '.', '');
    $formatted = rtrim(rtrim($formatted, '0'), '.');
    return $formatted === '' || $formatted === '-0' ? '0' : $formatted;
}

/** Human-readable Godown transfer statuses. */
function godown_transfer_statuses() {
    return [
        'draft'       => 'Requested',
        'approved'    => 'Approved',
        'dispatched'  => 'Dispatched',
        'received'    => 'Received',
        'discrepancy' => 'Discrepancy',
        'cancelled'   => 'Cancelled',
    ];
}

/** Human label for a Godown transfer status. */
function godown_transfer_status_label($status) {
    $labels = godown_transfer_statuses();
    return $labels[$status] ?? ucwords(str_replace('_', ' ', (string)$status));
}

/** Bootstrap badge class for a Godown transfer status. */
function godown_transfer_status_class($status) {
    $map = [
        'draft'       => 'warning',
        'approved'    => 'info',
        'dispatched'  => 'primary',
        'received'    => 'success',
        'discrepancy' => 'danger',
        'cancelled'   => 'secondary',
    ];
    return $map[$status] ?? 'secondary';
}

/** Whether the current user may see a Godown transfer. */
function stock_transfer_is_visible($transfer) {
    global $current_user;
    if (!$current_user || !$transfer) return false;
    if ((int)$transfer['tenant_id'] !== (int)$current_user->tenant_id) return false;
    if (tenant_admin_scope()) return true;
    $destination_shop_id = (int)($transfer['destination_shop_id'] ?? 0);
    if ($destination_shop_id > 0 && shop_is_accessible($destination_shop_id, true)) return true;

    // Source-side visibility belongs to Godown oversight staff. A requester
    // must not see another shop's request just because it came from the same
    // Godown; requesters rely on their destination-shop access above.
    $godown_oversight = can('manage_godown')
        || can('view_godown')
        || can('approve_godown_transfers')
        || can('dispatch_godown_transfers')
        || can('receive_godown_transfers');
    return $godown_oversight
        && find_accessible_store((int)($transfer['source_warehouse_id'] ?? 0), true, true) !== null;
}

/** Create/retrieve a per-session CSRF token. */
function csrf_token() {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/** Hidden CSRF field for new forms. */
function csrf_field() {
    return '<input type="hidden" name="csrf_token" value="' . e(csrf_token()) . '">';
}

/** Verify a POSTed CSRF token; stop on failure. */
function verify_csrf() {
    $token = $_POST['csrf_token'] ?? '';
    $known = $_SESSION['csrf_token'] ?? '';
    if (!is_string($token) || $known === '' || !hash_equals($known, $token)) {
        http_response_code(403);
        die('Invalid security token. Please go back and try again.');
    }
}

/** Fetch a single row safely, or null. */
function fetch_one($sql, ...$params) {
    global $conn;
    $stmt = $conn->prepare($sql);
    if (!$stmt) return null;
    if ($params) {
        $types = '';
        foreach ($params as $p) {
            $types .= is_int($p) ? 'i' : (is_float($p) ? 'd' : 's');
        }
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row;
}

/** Fetch all rows safely, or empty array. */
function fetch_all($sql, ...$params) {
    global $conn;
    $stmt = $conn->prepare($sql);
    if (!$stmt) return [];
    if ($params) {
        $types = '';
        foreach ($params as $p) {
            $types .= is_int($p) ? 'i' : (is_float($p) ? 'd' : 's');
        }
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $rows;
}

/** Insert a row, returns insert_id or 0 on failure. */
function db_insert($table, array $data) {
    global $conn;
    $cols = implode(', ', array_map(fn($c) => "`$c`", array_keys($data)));
    $vals = implode(', ', array_fill(0, count($data), '?'));
    $stmt = $conn->prepare("INSERT INTO `$table` ($cols) VALUES ($vals)");
    if (!$stmt) return 0;
    $types = '';
    foreach ($data as $v) {
        $types .= is_int($v) ? 'i' : (is_float($v) ? 'd' : 's');
    }
    $stmt->bind_param($types, ...array_values($data));
    if (!$stmt->execute()) return 0;
    $id = $stmt->insert_id;
    $stmt->close();
    return $id;
}

/** Log an audit event. */
function audit_log($action, $entityType, $entityId, $details = null) {
    global $conn, $current_user;
    if (!isset($current_user)) return;
    $stmt = $conn->prepare(
        "INSERT INTO audit_logs (tenant_id, user_id, action, entity_type, entity_id, details)
         VALUES (?, ?, ?, ?, ?, ?)"
    );
    $tid = $current_user->tenant_id;
    $uid = $current_user->user_id;
    $stmt->bind_param('iissis', $tid, $uid, $action, $entityType, $entityId, $details);
    $stmt->execute();
    $stmt->close();
}

/** Resolve a category to its [main, sub, sub-sub] chain (empty strings for missing levels). */
function category_chain($category_id) {
    global $conn;
    $out = ['', '', ''];
    if (!$category_id) return $out;
    $ids = [];
    $current = (int)$category_id;
    while ($current && count($ids) < 3) {
        $ids[] = $current;
        $stmt = $conn->prepare("SELECT parent_category_id FROM product_categories WHERE category_id = ?");
        $stmt->bind_param('i', $current);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$row) break;
        $current = $row['parent_category_id'] ? (int)$row['parent_category_id'] : null;
    }
    $ids = array_reverse($ids);
    foreach ($ids as $i => $id) {
        $stmt = $conn->prepare("SELECT name FROM product_categories WHERE category_id = ?");
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if ($row) $out[$i] = $row['name'];
    }
    return $out;
}

/** Current user's ID, or 0 when there is no signed-in user. */
function current_user_id() {
    global $current_user;
    return $current_user ? (int)$current_user->user_id : 0;
}

/**
 * Active users in a tenant who hold a given permission.
 * Used to populate assignee lists for orders and leads.
 */
function users_with_permission($permission, $tenant_id = null) {
    global $current_user;
    $tenant_id = $tenant_id === null ? (int)$current_user->tenant_id : (int)$tenant_id;
    return fetch_all(
        "SELECT DISTINCT u.user_id, u.first_name, u.last_name, u.username
         FROM users u
         JOIN user_roles ur ON ur.user_id = u.user_id AND ur.tenant_id = u.tenant_id
         JOIN role_permissions rp ON rp.role_id = ur.role_id
         JOIN permissions p ON p.permission_id = rp.permission_id
         WHERE u.tenant_id = ? AND u.is_active = 1 AND p.name = ?
         ORDER BY u.first_name, u.last_name",
        $tenant_id,
        $permission
    );
}

/** Whether a tenant user holds a permission (used to validate assignments). */
function user_has_tenant_permission($user_id, $permission, $tenant_id) {
    $user_id = (int)$user_id;
    $tenant_id = (int)$tenant_id;
    if ($user_id <= 0 || $tenant_id <= 0) return false;
    return (bool)fetch_one(
        "SELECT 1 FROM users u
         JOIN user_roles ur ON ur.user_id = u.user_id AND ur.tenant_id = u.tenant_id
         JOIN role_permissions rp ON rp.role_id = ur.role_id
         JOIN permissions p ON p.permission_id = rp.permission_id
         WHERE u.user_id = ? AND u.tenant_id = ? AND u.is_active = 1 AND p.name = ?
         LIMIT 1",
        $user_id,
        $tenant_id,
        $permission
    );
}

/**
 * Allocate the next document number for a tenant.
 * Call inside an open transaction. The counter row is locked by UPDATE and the
 * value is read back with LAST_INSERT_ID(expr), so concurrent allocations
 * cannot return the same number.
 */
function next_document_number($tenant_id, $sequence_key, $prefix, $pad = 6) {
    global $conn;
    $tenant_id = (int)$tenant_id;
    $sequence_key = (string)$sequence_key;

    $stmt = $conn->prepare(
        "INSERT IGNORE INTO document_sequences (tenant_id, sequence_key, next_value) VALUES (?, ?, 1)"
    );
    if (!$stmt) throw new RuntimeException($conn->error);
    $stmt->bind_param('is', $tenant_id, $sequence_key);
    $stmt->execute();
    $stmt->close();

    $stmt = $conn->prepare(
        "UPDATE document_sequences
            SET next_value = LAST_INSERT_ID(next_value + 1)
          WHERE tenant_id = ? AND sequence_key = ?"
    );
    if (!$stmt) throw new RuntimeException($conn->error);
    $stmt->bind_param('is', $tenant_id, $sequence_key);
    if (!$stmt->execute()) {
        $error = $stmt->error;
        $stmt->close();
        throw new RuntimeException($error);
    }
    $stmt->close();

    $row = fetch_one("SELECT LAST_INSERT_ID() AS allocated");
    $allocated = (int)($row['allocated'] ?? 0);
    if ($allocated < 1) throw new RuntimeException('Could not allocate a document number.');
    return $prefix . '-' . str_pad((string)($allocated - 1), $pad, '0', STR_PAD_LEFT);
}

/** One-time form token used to block duplicate submissions and replays. */
function form_token_field() {
    if (empty($_SESSION['form_tokens']) || !is_array($_SESSION['form_tokens'])) {
        $_SESSION['form_tokens'] = [];
    }
    if (count($_SESSION['form_tokens']) > 20) {
        $_SESSION['form_tokens'] = array_slice($_SESSION['form_tokens'], -10, null, true);
    }
    $token = bin2hex(random_bytes(16));
    $_SESSION['form_tokens'][$token] = time();
    return '<input type="hidden" name="form_token" value="' . e($token) . '">';
}

/** Check that the POSTed form token was issued to this session (does not consume it). */
function form_token_valid() {
    $token = $_POST['form_token'] ?? '';
    return is_string($token) && $token !== '' && !empty($_SESSION['form_tokens'][$token]);
}

/** Consume the POSTed form token after a successful write. */
function consume_form_token() {
    $token = $_POST['form_token'] ?? '';
    if (!is_string($token) || $token === '') return false;
    if (empty($_SESSION['form_tokens'][$token])) return false;
    unset($_SESSION['form_tokens'][$token]);
    return true;
}

/** Human-readable order statuses. */
function order_statuses() {
    return [
        'unpaid'         => 'Unpaid',
        'partially_paid' => 'Partially Paid',
        'paid'           => 'Paid'
    ];
}

/** Statuses an order may move to from its current status. */
function order_allowed_transitions($status) {
    $map = [
        'new'          => ['under_review', 'rejected', 'cancelled'],
        'under_review' => ['confirmed', 'rejected', 'cancelled'],
        'confirmed'    => ['fulfilled', 'cancelled'],
        'rejected'     => [],
        'cancelled'    => [],
        'fulfilled'    => [],
    ];
    return $map[$status] ?? [];
}

/** Human-readable lead statuses. */
function lead_statuses() {
    return [
        'pending'   => 'Pending',
        'confirmed' => 'Confirmed',
        'follow_up' => 'Follow Up',
        'cancelled' => 'Cancelled',
    ];
}

/** Statuses a lead may move to from its current status. */
function lead_allowed_transitions($status) {
    $map = [
        'pending'   => ['follow_up', 'confirmed', 'cancelled'],
        'follow_up' => ['pending', 'confirmed', 'cancelled'],
        'confirmed' => [],
        'cancelled' => ['pending'],
    ];
    return $map[$status] ?? [];
}

/** Whether the current user may see an order row (tenant + shop scope). */
function order_is_visible($order) {
    global $current_user;
    if (!$current_user || !$order) return false;
    if ((int)$order['tenant_id'] !== (int)$current_user->tenant_id) return false;
    if (tenant_admin_scope()) return true;
    if ((int)($order['created_by'] ?? 0) === (int)$current_user->user_id) return true;
    if ((int)($order['assigned_to'] ?? 0) === (int)$current_user->user_id) return true;
    // Unassigned storefront requests are a tenant-level intake queue until a
    // staff member takes ownership or assigns the order to a shop.
    if (($order['source'] ?? '') === 'storefront' && empty($order['created_by'])) return true;
    $shop_id = (int)($order['shop_id'] ?? 0);
    return $shop_id > 0 && shop_is_accessible($shop_id, true);
}

/** SQL fragment + params restricting an order query to what the user may see. */
function order_visibility_clause($alias = 'o') {
    if (tenant_admin_scope()) return ['', []];
    $current_uid = current_user_id();
    $shop_ids = accessible_shop_ids(null, true);
    $parts = ["{$alias}.created_by = ?", "{$alias}.assigned_to = ?", "({$alias}.source = 'storefront' AND {$alias}.created_by IS NULL)"];
    $params = [$current_uid, $current_uid];
    if ($shop_ids) {
        $parts[] = "{$alias}.shop_id IN (" . implode(',', array_map('intval', $shop_ids)) . ")";
    }
    return [' AND (' . implode(' OR ', $parts) . ')', $params];
}

/** Bootstrap badge class for an order status. */
function order_status_class($status) {
    $map = [
        'new'          => 'warning',
        'under_review' => 'info',
        'confirmed'    => 'primary',
        'fulfilled'    => 'success',
        'rejected'     => 'danger',
        'cancelled'    => 'secondary',
    ];
    return $map[$status] ?? 'secondary';
}

/** Bootstrap badge class for a lead status. */
function lead_status_class($status) {
    $map = [
        'pending'   => 'warning',
        'follow_up' => 'info',
        'confirmed' => 'success',
        'cancelled' => 'secondary',
    ];
    return $map[$status] ?? 'secondary';
}

/** Human label for an order/lead source. */
function source_label($source) {
    $labels = [
        'staff'    => 'Staff entry',
        'phone'    => 'Phone',
        'whatsapp' => 'WhatsApp',
        'email'    => 'Email',
        'walk_in'  => 'Walk-in',
        'referral' => 'Referral',
        'online'   => 'Online',
        'storefront' => 'Storefront',
    ];
    return $labels[$source] ?? ucwords(str_replace('_', ' ', (string)$source));
}

/** Human label for a lead activity type. */
function activity_type_label($type) {
    $labels = [
        'note'          => 'Note',
        'call'          => 'Call',
        'email'         => 'Email',
        'sms'           => 'SMS',
        'whatsapp'      => 'WhatsApp',
        'meeting'       => 'Meeting',
        'created'       => 'Lead created',
        'assignment'    => 'Assignment',
        'status_change' => 'Status change',
        'order_link'    => 'Order link',
    ];
    return $labels[$type] ?? ucwords(str_replace('_', ' ', (string)$type));
}

/** Append an activity/history row to a lead. */
function record_lead_activity($lead_id, $tenant_id, $type, $subject = null, $notes = null, $outcome = null, $order_id = null, $next_follow_up = null) {
    global $conn;
    $lead_id = (int)$lead_id;
    $tenant_id = (int)$tenant_id;
    $order_id = $order_id ? (int)$order_id : null;
    $next_follow_up = $next_follow_up ?: null;
    $created_by = current_user_id() ?: null;
    $status = 'completed';
    $stmt = $conn->prepare(
        "INSERT INTO activities
            (tenant_id, lead_id, order_id, subject, activity_type, notes, outcome, due_date, status, created_by)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
    );
    if (!$stmt) throw new RuntimeException($conn->error);
    $stmt->bind_param(
        'iiissssssi',
        $tenant_id,
        $lead_id,
        $order_id,
        $subject,
        $type,
        $notes,
        $outcome,
        $next_follow_up,
        $status,
        $created_by
    );
    if (!$stmt->execute()) {
        $error = $stmt->error;
        $stmt->close();
        throw new RuntimeException($error);
    }
    $id = $stmt->insert_id;
    $stmt->close();
    return $id;
}

/** Active reservation quantity for a product at one store, optionally excluding an order. */
function reserved_stock_quantity($tenant_id, $product_id, $warehouse_id, $exclude_order_id = 0) {
    $sql = "SELECT COALESCE(SUM(quantity), 0) AS reserved
              FROM order_stock_reservations
             WHERE tenant_id = ? AND product_id = ? AND warehouse_id = ? AND status = 'active'";
    $params = [(int)$tenant_id, (int)$product_id, (int)$warehouse_id];
    if ((int)$exclude_order_id > 0) {
        $sql .= " AND order_id <> ?";
        $params[] = (int)$exclude_order_id;
    }
    $row = fetch_one($sql, ...$params);
    return (float)($row['reserved'] ?? 0);
}

/** Reservation rows for an order, optionally limited by status. */
function order_reservation_rows($order_id, $tenant_id, $statuses = null, $lock = false) {
    $sql = "SELECT * FROM order_stock_reservations WHERE order_id = ? AND tenant_id = ?";
    $params = [(int)$order_id, (int)$tenant_id];
    if (is_array($statuses) && $statuses) {
        $placeholders = implode(',', array_fill(0, count($statuses), '?'));
        $sql .= " AND status IN ({$placeholders})";
        foreach ($statuses as $status) $params[] = (string)$status;
    }
    $sql .= " ORDER BY reservation_id";
    if ($lock) $sql .= " FOR UPDATE";
    return fetch_all($sql, ...$params);
}

/**
 * Reserve every non-service order line for one store.
 *
 * The caller must hold an open transaction. Order and stock rows are locked so
 * two verifications or confirmations cannot reserve the same on-hand quantity.
 * No physical stock is changed here. Returns the number of active reservation rows.
 */
function reserve_order_stock($order_id, $tenant_id, $shop_id, $warehouse_id, $created_by = null, $low_stock_message = null) {
    global $conn;
    $order_id = (int)$order_id;
    $tenant_id = (int)$tenant_id;
    $shop_id = (int)$shop_id;
    $warehouse_id = (int)$warehouse_id;

    $order = fetch_one(
        "SELECT * FROM orders WHERE order_id = ? AND tenant_id = ? FOR UPDATE",
        $order_id,
        $tenant_id
    );
    if (!$order) throw new RuntimeException('Order not found while reserving stock.');
    if (!in_array($order['status'], ['under_review', 'confirmed'], true)) {
        throw new RuntimeException('Only an under-review or confirmed order can reserve stock.');
    }

    $location = fetch_one(
        "SELECT s.shop_id, w.warehouse_id, w.name store_name
           FROM shops s
           JOIN warehouses w ON w.shop_id = s.shop_id AND w.tenant_id = s.tenant_id
          WHERE s.tenant_id = ? AND s.shop_id = ? AND w.warehouse_id = ?
            AND s.is_active = 1 AND w.is_active = 1
          FOR UPDATE",
        $tenant_id,
        $shop_id,
        $warehouse_id
    );
    if (!$location) {
        throw new RuntimeException('The selected shop/store is inactive or does not belong to this tenant.');
    }

    $items = fetch_all(
        "SELECT oi.*, p.name current_name, p.code current_code, p.is_active product_active, p.is_service
           FROM order_items oi
           LEFT JOIN products p ON p.product_id = oi.product_id AND p.tenant_id = oi.tenant_id
          WHERE oi.order_id = ? AND oi.tenant_id = ?
          ORDER BY oi.line_no",
        $order_id,
        $tenant_id
    );
    if (!$items) throw new RuntimeException('This order has no products to reserve.');

    $needed = [];
    $expected_by_item = [];
    foreach ($items as $item) {
        if ((int)$item['is_service'] === 1) continue;
        $product_id = (int)$item['product_id'];
        if ($product_id <= 0 || !$item['current_name'] || (int)$item['product_active'] !== 1) {
            throw new RuntimeException('A product on this order was deleted or deactivated and cannot be reserved.');
        }
        $qty = (float)$item['quantity'];
        if ($qty <= 0) throw new RuntimeException('An order line has an invalid quantity.');
        if (!isset($needed[$product_id])) {
            $needed[$product_id] = ['qty' => 0.0, 'name' => (string)$item['current_name']];
        }
        $needed[$product_id]['qty'] += $qty;
        $expected_by_item[(int)$item['order_item_id']] = [
            'product_id' => $product_id,
            'quantity' => $qty,
        ];
    }

    $existing = fetch_all(
        "SELECT * FROM order_stock_reservations WHERE order_id = ? AND tenant_id = ? FOR UPDATE",
        $order_id,
        $tenant_id
    );
    if ($existing) {
        if (count($existing) !== count($expected_by_item)) {
            throw new RuntimeException('This order has reservation history that no longer matches its lines. Cancel it and create a new order.');
        }
        foreach ($existing as $row) {
            $item_id = (int)$row['order_item_id'];
            if ($row['status'] !== 'active' || !isset($expected_by_item[$item_id])) {
                throw new RuntimeException('This order already has a completed reservation history and cannot reserve again.');
            }
            $expected = $expected_by_item[$item_id];
            if ((int)$row['product_id'] !== $expected['product_id']
                || (int)$row['warehouse_id'] !== $warehouse_id
                || abs((float)$row['quantity'] - $expected['quantity']) > 0.0001) {
                throw new RuntimeException('This order reservation no longer matches the selected store or quantities.');
            }
        }
        return count($existing);
    }

    // Lock stock rows in a deterministic order so concurrent confirmations do
    // not deadlock each other while checking several products.
    ksort($needed);
    foreach ($needed as $product_id => $need) {
        $stock = fetch_one(
            "SELECT quantity FROM inventory_stock
              WHERE tenant_id = ? AND product_id = ? AND warehouse_id = ?
              FOR UPDATE",
            $tenant_id,
            (int)$product_id,
            $warehouse_id
        );
        $on_hand = $stock ? (float)$stock['quantity'] : 0.0;
        $reserved = reserved_stock_quantity($tenant_id, (int)$product_id, $warehouse_id, $order_id);
        $available = $on_hand - $reserved;
        if ($available + 0.0001 < (float)$need['qty']) {
            if ($low_stock_message !== null) {
                throw new RuntimeException((string)$low_stock_message);
            }
            throw new RuntimeException(
                'Insufficient available stock for ' . $need['name']
                . ' at ' . $location['store_name']
                . ': requested ' . (float)$need['qty']
                . ', available ' . max(0, $available)
                . ' (on hand ' . $on_hand . ', already reserved ' . $reserved . ').'
            );
        }
    }

    $created_db = $created_by ? (int)$created_by : null;
    foreach ($items as $item) {
        if ((int)$item['is_service'] === 1) continue;
        $stmt = $conn->prepare(
            "INSERT INTO order_stock_reservations
                (tenant_id, order_id, order_item_id, product_id, warehouse_id, quantity, status, created_by)
             VALUES (?, ?, ?, ?, ?, ?, 'active', ?)"
        );
        if (!$stmt) throw new RuntimeException($conn->error);
        $product_id = (int)$item['product_id'];
        $item_id = (int)$item['order_item_id'];
        $qty = (float)$item['quantity'];
        $stmt->bind_param('iiiiidi', $tenant_id, $order_id, $item_id, $product_id, $warehouse_id, $qty, $created_db);
        if (!$stmt->execute()) {
            $error = $stmt->error;
            $stmt->close();
            throw new RuntimeException($error);
        }
        $stmt->close();
    }

    return count($expected_by_item);
}

/** Release all active reservations for an order exactly once. */
function release_order_reservations($order_id, $tenant_id, $reason = null) {
    global $conn;
    $reason_db = $reason ?: null;
    $stmt = $conn->prepare(
        "UPDATE order_stock_reservations
            SET status = 'released', released_at = NOW(), release_reason = ?
          WHERE order_id = ? AND tenant_id = ? AND status = 'active'"
    );
    if (!$stmt) throw new RuntimeException($conn->error);
    $stmt->bind_param('sii', $reason_db, $order_id, $tenant_id);
    if (!$stmt->execute()) {
        $error = $stmt->error;
        $stmt->close();
        throw new RuntimeException($error);
    }
    $count = $stmt->affected_rows;
    $stmt->close();
    return $count;
}

/** Consume all active reservations for an order exactly once. */
function consume_order_reservations($order_id, $tenant_id, $invoice_id) {
    global $conn;
    $stmt = $conn->prepare(
        "UPDATE order_stock_reservations
            SET status = 'consumed', consumed_at = NOW(), consumed_invoice_id = ?
          WHERE order_id = ? AND tenant_id = ? AND status = 'active'"
    );
    if (!$stmt) throw new RuntimeException($conn->error);
    $stmt->bind_param('iii', $invoice_id, $order_id, $tenant_id);
    if (!$stmt->execute()) {
        $error = $stmt->error;
        $stmt->close();
        throw new RuntimeException($error);
    }
    $count = $stmt->affected_rows;
    $stmt->close();
    return $count;
}

/** True when an invoice contains exactly the same product quantities as an order. */
function order_invoice_matches($order_id, $tenant_id, $invoice_id) {
    $order_lines = fetch_all(
        "SELECT product_id, SUM(quantity) qty
           FROM order_items
          WHERE order_id = ? AND tenant_id = ? AND product_id IS NOT NULL
          GROUP BY product_id",
        (int)$order_id,
        (int)$tenant_id
    );
    $invoice_lines = fetch_all(
        "SELECT product_id, SUM(quantity) qty
           FROM invoice_items
          WHERE invoice_id = ? AND tenant_id = ? AND product_id IS NOT NULL
          GROUP BY product_id",
        (int)$invoice_id,
        (int)$tenant_id
    );
    $order_counts = fetch_one(
        "SELECT COUNT(*) total_items,
                SUM(CASE WHEN product_id IS NOT NULL THEN 1 ELSE 0 END) product_items
           FROM order_items WHERE order_id = ? AND tenant_id = ?",
        (int)$order_id,
        (int)$tenant_id
    );
    $invoice_counts = fetch_one(
        "SELECT COUNT(*) total_items,
                SUM(CASE WHEN product_id IS NOT NULL THEN 1 ELSE 0 END) product_items
           FROM invoice_items WHERE invoice_id = ? AND tenant_id = ?",
        (int)$invoice_id,
        (int)$tenant_id
    );
    if (!$order_lines || !$invoice_lines) return false;
    if ((int)($order_counts['total_items'] ?? 0) !== (int)($order_counts['product_items'] ?? 0)) return false;
    if ((int)($invoice_counts['total_items'] ?? 0) !== (int)($invoice_counts['product_items'] ?? 0)) return false;

    $order_map = [];
    foreach ($order_lines as $row) $order_map[(int)$row['product_id']] = (float)$row['qty'];
    $invoice_map = [];
    foreach ($invoice_lines as $row) $invoice_map[(int)$row['product_id']] = (float)$row['qty'];
    if (count($order_map) !== count($invoice_map)) return false;
    foreach ($order_map as $product_id => $qty) {
        if (!array_key_exists($product_id, $invoice_map)) return false;
        if (abs($qty - $invoice_map[$product_id]) > 0.0001) return false;
    }
    return true;
}

/** Brands offered in the product forms. Extend this list as new brands are added. */
function product_brand_options() {
    return ['GSP GROUP', 'TECHDOKTA INVESTMENT'];
}

/** Human-readable quotation statuses. */
function quotation_statuses() {
    return [
        'draft'     => 'Draft',
        'sent'      => 'Sent',
        'accepted'  => 'Accepted',
        'rejected'  => 'Rejected',
        'expired'   => 'Expired',
        'converted' => 'Converted',
    ];
}

/** Human label for a quotation status. */
function quotation_status_label($status) {
    $labels = quotation_statuses();
    return $labels[$status] ?? ucwords(str_replace('_', ' ', (string)$status));
}

/** Bootstrap badge class for a quotation status. */
function quotation_status_class($status) {
    $map = [
        'draft'     => 'secondary',
        'sent'      => 'info',
        'accepted'  => 'success',
        'rejected'  => 'danger',
        'expired'   => 'warning',
        'converted' => 'primary',
    ];
    return $map[$status] ?? 'secondary';
}

/** Whether the current user may see a quotation (tenant + store scope). */
function quotation_is_visible($quotation) {
    global $current_user;
    if (!$current_user || !$quotation) return false;
    if ((int)$quotation['tenant_id'] !== (int)$current_user->tenant_id) return false;
    if (tenant_admin_scope()) return true;
    $scope_ids = accessible_warehouse_ids();
    if ($scope_ids === null) return true;
    $warehouse_id = (int)($quotation['warehouse_id'] ?? 0);
    return $warehouse_id > 0 && in_array($warehouse_id, $scope_ids, true);
}

/**
 * Convert an accepted/draft quotation into a real invoice.
 *
 * The caller must have verified the CSRF token. Stock is checked and deducted
 * exactly once, inside a single transaction. A quotation can only convert once.
 * Returns the new invoice ID.
 */
function convert_quotation_to_invoice($quotation_id, $tenant_id) {
    global $conn, $current_user;
    $quotation_id = (int)$quotation_id;
    $tenant_id = (int)$tenant_id;

    $quotation = fetch_one(
        "SELECT * FROM quotations WHERE quotation_id = ? AND tenant_id = ? FOR UPDATE",
        $quotation_id,
        $tenant_id
    );
    if (!$quotation) {
        throw new RuntimeException('Quotation not found.');
    }
    if ($quotation['status'] === 'converted' || !empty($quotation['converted_invoice_id'])) {
        throw new RuntimeException('This quotation was already converted into an invoice.');
    }
    if ($quotation['status'] === 'rejected') {
        throw new RuntimeException('A rejected quotation cannot be converted.');
    }

    $store = find_accessible_store((int)$quotation['warehouse_id'], false);
    if (!$store) {
        throw new RuntimeException('The quotation store is no longer active or accessible.');
    }

    $items = fetch_all(
        "SELECT qi.*, p.is_service, p.name current_name, p.is_active product_active
           FROM quotation_items qi
           LEFT JOIN products p ON p.product_id = qi.product_id AND p.tenant_id = qi.tenant_id
          WHERE qi.quotation_id = ? AND qi.tenant_id = ?
          ORDER BY qi.quotation_item_id",
        $quotation_id,
        $tenant_id
    );
    if (!$items) {
        throw new RuntimeException('This quotation has no items.');
    }

    // Aggregate stock needs before touching any row.
    $needed = [];
    foreach ($items as $item) {
        if ((int)($item['is_service'] ?? 0) === 1) continue;
        $product_id = (int)($item['product_id'] ?? 0);
        $qty = (float)$item['quantity'];
        if ($qty <= 0) {
            throw new RuntimeException('A quotation line has an invalid quantity.');
        }
        if ($product_id <= 0) {
            throw new RuntimeException('A quotation line has no product and cannot be converted.');
        }
        if (!$item['current_name'] || (int)$item['product_active'] !== 1) {
            throw new RuntimeException('A product on this quotation was deleted or deactivated. Update the quotation before converting.');
        }
        $needed[$product_id] = ($needed[$product_id] ?? 0.0) + $qty;
    }

    ksort($needed);
    foreach ($needed as $product_id => $qty) {
        $stock = fetch_one(
            "SELECT quantity FROM inventory_stock
              WHERE tenant_id = ? AND product_id = ? AND warehouse_id = ?
              FOR UPDATE",
            $tenant_id,
            $product_id,
            (int)$quotation['warehouse_id']
        );
        $on_hand = $stock ? (float)$stock['quantity'] : 0.0;
        $reserved = reserved_stock_quantity($tenant_id, $product_id, (int)$quotation['warehouse_id']);
        $available = $on_hand - $reserved;
        if ($available + 0.0001 < $qty) {
            $name = '';
            foreach ($items as $item) {
                if ((int)$item['product_id'] === $product_id) {
                    $name = (string)($item['description'] ?: $item['current_name']);
                    break;
                }
            }
            throw new RuntimeException(
                'Insufficient available stock for ' . $name
                . ' at ' . store_label($store)
                . ' (requested: ' . $qty . ', available: ' . max(0, $available) . ').'
            );
        }
    }

    $settings = fetch_one(
        "SELECT * FROM system_settings WHERE tenant_id = ? FOR UPDATE",
        $tenant_id
    );
    $prefix = ($settings && isset($settings['invoice_prefix'])) ? $settings['invoice_prefix'] : 'INV-';
    $next_no = ($settings && isset($settings['invoice_next_number'])) ? (int)$settings['invoice_next_number'] : 1001;
    $invoice_number = $prefix . $next_no;

    $status = fetch_one(
        "SELECT status_id FROM document_statuses
          WHERE tenant_id = ? AND type = 'invoice'
          ORDER BY CASE WHEN name = 'Draft' THEN 0 ELSE 1 END, sort_order
          LIMIT 1",
        $tenant_id
    );
    $status_id = $status ? (int)$status['status_id'] : null;

    $issue_date = date('Y-m-d');
    $due_date = !empty($quotation['valid_until']) ? $quotation['valid_until'] : null;
    $reference = trim((string)$quotation['quotation_number']);
    $notes = trim((string)$quotation['notes']);
    $terms = trim((string)$quotation['terms']);
    $subtotal = (float)$quotation['subtotal'];
    $tax_total = (float)$quotation['tax_amount'];
    $total = (float)$quotation['total'];

    $stmt = $conn->prepare(
        "INSERT INTO invoices
            (tenant_id, invoice_number, warehouse_id, contact_id, issue_date, due_date, status_id,
             reference, notes, terms, subtotal, tax_amount, total, amount_due, amount_paid, is_paid, created_by)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, 0, ?)"
    );
    $invoice_warehouse_id = (int)$quotation['warehouse_id'];
    $invoice_contact_id = (int)$quotation['contact_id'];
    $created_by = (int)$current_user->user_id;
    $stmt->bind_param(
        'isiississsddddi',
        $tenant_id,
        $invoice_number,
        $invoice_warehouse_id,
        $invoice_contact_id,
        $issue_date,
        $due_date,
        $status_id,
        $reference,
        $notes,
        $terms,
        $subtotal,
        $tax_total,
        $total,
        $total,
        $created_by
    );
    $stmt->execute();
    $invoice_id = (int)$stmt->insert_id;
    $stmt->close();

    $stmt = $conn->prepare(
        "INSERT INTO invoice_items
            (tenant_id, invoice_id, warehouse_id, product_id, description, quantity, unit_price,
             tax_rate_id, tax_amount, subtotal, total, discount)
         VALUES (?, ?, ?, ?, ?, ?, ?, NULL, ?, ?, ?, 0)"
    );
    foreach ($items as $item) {
        $product_id = (int)($item['product_id'] ?? 0) ?: null;
        $description = (string)($item['description'] ?: $item['current_name'] ?: 'Item');
        $quantity = (float)$item['quantity'];
        $unit_price = (float)$item['unit_price'];
        $line_tax = (float)$item['tax_amount'];
        $line_subtotal = (float)$item['subtotal'];
        $line_total = (float)$item['total'];
        $warehouse_id = (int)$quotation['warehouse_id'];
        $stmt->bind_param(
            'iiiisddddd',
            $tenant_id,
            $invoice_id,
            $warehouse_id,
            $product_id,
            $description,
            $quantity,
            $unit_price,
            $line_tax,
            $line_subtotal,
            $line_total
        );
        $stmt->execute();

        if ($product_id !== null && (int)($item['is_service'] ?? 0) !== 1) {
            $stmt_stock = $conn->prepare(
                "UPDATE inventory_stock SET quantity = quantity - ?
                  WHERE tenant_id = ? AND product_id = ? AND warehouse_id = ? AND quantity >= ?"
            );
            $stmt_stock->bind_param(
                'diiid',
                $quantity,
                $tenant_id,
                $product_id,
                $warehouse_id,
                $quantity
            );
            $stmt_stock->execute();
            if ($stmt_stock->affected_rows !== 1) {
                $stmt_stock->close();
                throw new RuntimeException('Stock changed while converting the quotation. No invoice was created.');
            }
            $stmt_stock->close();

            $movement_notes = 'Converted from quotation ' . $quotation['quotation_number'];
            $stmt_movement = $conn->prepare(
                "INSERT INTO inventory_movements
                    (tenant_id, product_id, warehouse_id, movement_type, quantity, reference_id, reference_type, created_by, notes)
                 VALUES (?, ?, ?, 'invoice', ?, ?, 'invoice', ?, ?)"
            );
            $stmt_movement->bind_param(
                'iiidiis',
                $tenant_id,
                $product_id,
                $warehouse_id,
                $quantity,
                $invoice_id,
                $current_user->user_id,
                $movement_notes
            );
            $stmt_movement->execute();
            $stmt_movement->close();
        }
    }
    $stmt->close();

    if ($settings && isset($settings['invoice_next_number'])) {
        $stmt = $conn->prepare(
            "UPDATE system_settings SET invoice_next_number = invoice_next_number + 1 WHERE tenant_id = ?"
        );
        $stmt->bind_param('i', $tenant_id);
        $stmt->execute();
        $stmt->close();
    }

    $stmt = $conn->prepare(
        "UPDATE quotations
            SET status = 'converted', converted_invoice_id = ?, converted_at = NOW()
          WHERE quotation_id = ? AND tenant_id = ?
            AND converted_invoice_id IS NULL AND status <> 'converted'"
    );
    $stmt->bind_param('iii', $invoice_id, $quotation_id, $tenant_id);
    $stmt->execute();
    if ($stmt->affected_rows !== 1) {
        $stmt->close();
        throw new RuntimeException('The quotation changed while it was being converted.');
    }
    $stmt->close();

    return $invoice_id;
}

/** Build a flat list of categories with depth (0 = root, 1 = sub, 2 = sub-sub). */
function category_tree($tenant_id) {
    $all = fetch_all("SELECT * FROM product_categories WHERE tenant_id = ? ORDER BY name", $tenant_id);
    $byParent = [];
    foreach ($all as $c) {
        $p = $c['parent_category_id'] ? (int)$c['parent_category_id'] : 0;
        $byParent[$p][] = $c;
    }
    $flat = [];
    $walk = function ($parent, $depth) use (&$walk, &$byParent, &$flat) {
        foreach (($byParent[$parent] ?? []) as $c) {
            $flat[] = ['category_id' => (int)$c['category_id'], 'name' => $c['name'], 'depth' => $depth];
            $walk((int)$c['category_id'], $depth + 1);
        }
    };
    $walk(0, 0);
    return $flat;
}
/** Stock summary metrics for a single shop (aggregated across its stores). */
function shop_stock_summary($shop_id, $tenant_id) {
    global $conn;
    $shop_id = (int)$shop_id;
    $tenant_id = (int)$tenant_id;
    $out = ["products" => 0, "qty" => 0.0, "low" => 0, "out" => 0];
    if ($shop_id <= 0 || $tenant_id <= 0) return $out;
    $stores = fetch_all("SELECT warehouse_id FROM warehouses WHERE tenant_id = ? AND shop_id = ? AND location_type = 'store'", $tenant_id, $shop_id);
    $ids = array_map(fn($r) => (int)$r["warehouse_id"], $stores);
    if (!$ids) return $out;
    $ph = implode(",", array_fill(0, count($ids), "?"));
    $params = array_merge([$tenant_id], $ids);
    $rows = fetch_all("SELECT s.product_id, COALESCE(SUM(s.quantity),0) AS qty, p.min_stock_level FROM inventory_stock s JOIN products p ON p.product_id = s.product_id AND p.tenant_id = s.tenant_id WHERE s.tenant_id = ? AND s.warehouse_id IN ($ph) GROUP BY s.product_id, p.min_stock_level", ...$params);
    foreach ($rows as $r) {
        $qty = (float)$r["qty"];
        $out["products"]++;
        $out["qty"] += $qty;
        if ($qty <= 0) {
            $out["out"]++;
        } elseif ($qty <= (float)$r["min_stock_level"]) {
            $out["low"]++;
        }
    }
    return $out;
}

/** Stock summary metrics for a single store (warehouse). */
function store_stock_summary($warehouse_id, $tenant_id) {
    $warehouse_id = (int)$warehouse_id;
    $tenant_id = (int)$tenant_id;
    $out = ["products" => 0, "qty" => 0.0, "low" => 0, "out" => 0];
    if ($warehouse_id <= 0 || $tenant_id <= 0) return $out;
    $rows = fetch_all("SELECT s.quantity, p.min_stock_level, s.reorder_level FROM inventory_stock s JOIN products p ON p.product_id = s.product_id AND p.tenant_id = s.tenant_id WHERE s.tenant_id = ? AND s.warehouse_id = ?", $tenant_id, $warehouse_id);
    foreach ($rows as $r) {
        $qty = (float)$r["quantity"];
        $threshold = $r["reorder_level"] !== null ? (float)$r["reorder_level"] : (float)$r["min_stock_level"];
        $out["products"]++;
        $out["qty"] += $qty;
        if ($qty <= 0) {
            $out["out"]++;
        } elseif ($qty <= $threshold) {
            $out["low"]++;
        }
    }
    return $out;
}

