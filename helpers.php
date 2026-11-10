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

/**
 * Render a list-row three-dot action menu.
 *
 * Each item supports:
 *  - link:  ['label' => 'View', 'icon' => 'fa-eye', 'href' => '...', 'attrs' => [...]]
 *  - button:['label' => 'Edit', 'icon' => 'fa-pencil', 'attrs' => ['data-toggle' => 'modal', ...]]
 *  - form:  ['label' => 'Delete', 'icon' => 'fa-trash', 'danger' => true,
 *            'form' => ['action' => 'page.php', 'fields' => [...], 'confirm' => 'Delete this?']]
 *  - divider: ['type' => 'divider']
 */
function row_action_menu(array $items, $label = 'Actions') {
    $items = array_values(array_filter($items));
    if (!$items) {
        return '<span class="text-muted">—</span>';
    }

    $html = '<div class="row-actions">'
        . '<button type="button" class="btn btn-action btn-light row-actions-toggle" data-row-actions '
        . 'aria-haspopup="menu" aria-expanded="false" title="' . e($label) . '" aria-label="' . e($label) . '">'
        . '<i class="fa fa-ellipsis-v" aria-hidden="true"></i><span class="sr-only">' . e($label) . '</span>'
        . '</button><div class="row-actions-menu" role="menu">';

    foreach ($items as $item) {
        if (($item['type'] ?? '') === 'divider') {
            $html .= '<div class="row-actions-divider" role="separator"></div>';
            continue;
        }

        $icon = !empty($item['icon']) ? '<i class="fa ' . e($item['icon']) . '" aria-hidden="true"></i>' : '';
        $class = 'row-actions-item' . (!empty($item['danger']) ? ' is-danger' : '');
        if (!empty($item['class'])) {
            $class .= ' ' . $item['class'];
        }
        $attrs = '';
        foreach (($item['attrs'] ?? []) as $name => $value) {
            if ($name === 'class') {
                continue;
            }
            $attrs .= ' ' . $name . '="' . e($value) . '"';
        }

        if (!empty($item['href'])) {
            $html .= '<a class="' . $class . '" role="menuitem" href="' . e($item['href']) . '"' . $attrs . '>'
                . $icon . e($item['label']) . '</a>';
            continue;
        }

        if (!empty($item['form'])) {
            $form = $item['form'];
            $method = strtoupper((string)($form['method'] ?? 'POST'));
            $html .= '<form method="' . e($method) . '" action="' . e($form['action']) . '" class="row-actions-form"'
                . (!empty($form['confirm']) ? ' onsubmit="return confirm(\'' . e($form['confirm']) . '\');"' : '') . '>';
            if ($method !== 'GET') {
                $html .= csrf_field();
            }
            foreach (($form['fields'] ?? []) as $name => $value) {
                $html .= '<input type="hidden" name="' . e($name) . '" value="' . e($value) . '">';
            }
            $html .= '<button type="submit" class="' . $class . '" role="menuitem">' . $icon . e($item['label']) . '</button>'
                . '</form>';
            continue;
        }

        $html .= '<button type="button" class="' . $class . '" role="menuitem"' . $attrs . '>'
            . $icon . e($item['label']) . '</button>';
    }

    return $html . '</div></div>';
}

/** Render a sidebar notification badge, or an empty string when there is nothing to show. */
function sidebar_count_badge($count, $label, $class = 'badge-danger') {
    $count = (int)$count;
    if ($count <= 0) {
        return '';
    }
    return '<span class="badge ' . e($class) . ' ml-auto" aria-label="' . e($count . ' ' . $label) . '">' . $count . '</span>';
}

/**
 * Map a script name to the sidebar badge section it belongs to.
 * Opening one of these pages acknowledges that section's badge.
 */
function sidebar_badge_section_for_script($script) {
    $script = basename((string)$script);
    // In the godown-manager layout, "My Godown" is not the transfers page.
    if ($script === 'godown.php' && is_godown_manager()) {
        return null;
    }
    $sections = [
        'orders'    => ['orders.php'],
        'invoices'  => ['invoices.php'],
        'inventory' => ['inventory.php'],
        'transfers' => ['godown-transfers.php', 'godown.php'],
        'leads'     => ['leads.php'],
    ];
    foreach ($sections as $section => $scripts) {
        if (in_array($script, $scripts, true)) {
            return $section;
        }
    }
    return null;
}

/** Whether the per-user sidebar badge table is available. */
function sidebar_badge_seen_ready() {
    static $ready = null;
    if ($ready === null) {
        $ready = db_table_exists('sidebar_badge_seen');
    }
    return $ready;
}

/** Last time the signed-in user opened each sidebar section's page. */
function sidebar_badge_seen_map($tenant_id, $user_id) {
    $map = [];
    if (!sidebar_badge_seen_ready()) {
        return $map;
    }

    $rows = fetch_all(
        "SELECT section, seen_at
           FROM sidebar_badge_seen
          WHERE tenant_id = ? AND user_id = ?",
        (int)$tenant_id,
        (int)$user_id
    );
    foreach ($rows as $row) {
        $map[(string)$row['section']] = (string)$row['seen_at'];
    }
    return $map;
}

/** Record that the signed-in user has opened a sidebar section's page. */
function sidebar_badge_mark_seen($section, $tenant_id = null, $user_id = null) {
    global $conn, $current_user;

    if (!$current_user) {
        return;
    }

    $tenant_id = $tenant_id === null ? (int)$current_user->tenant_id : (int)$tenant_id;
    $user_id = $user_id === null ? (int)$current_user->user_id : (int)$user_id;
    $section = trim((string)$section);
    if ($tenant_id <= 0 || $user_id <= 0 || $section === '') {
        return;
    }
    if (!sidebar_badge_seen_ready()) {
        return;
    }

    $stmt = $conn->prepare(
        "INSERT INTO sidebar_badge_seen (tenant_id, user_id, section, seen_at)
         VALUES (?, ?, ?, NOW())
         ON DUPLICATE KEY UPDATE seen_at = NOW()"
    );
    if (!$stmt) {
        return;
    }
    $stmt->bind_param('iis', $tenant_id, $user_id, $section);
    $stmt->execute();
    $stmt->close();
}

/**
 * Role/location scoped notification counts for sidebar navigation.
 * Counts only items the signed-in user can act on or see in their locations,
 * and only activity newer than the last time they opened that section.
 */
function sidebar_notification_counts($tenant_id = null) {
    global $current_user;
    static $cache = [];
    if (!$current_user) {
        return ['orders' => 0, 'invoices' => 0, 'transfers' => 0, 'low_stock' => 0];
    }

    $tenant_id = $tenant_id === null ? (int)$current_user->tenant_id : (int)$tenant_id;
    $cache_key = (int)$current_user->user_id . ':' . $tenant_id;
    if (isset($cache[$cache_key])) {
        return $cache[$cache_key];
    }

    $seen = sidebar_badge_seen_map($tenant_id, (int)$current_user->user_id);
    $counts = ['orders' => 0, 'invoices' => 0, 'transfers' => 0, 'low_stock' => 0];

    // Orders waiting for review/assignment.
    if (can('manage_orders')) {
        [$visibility_sql, $visibility_params] = order_visibility_clause('o');
        $sql = "SELECT COUNT(*) AS c FROM orders o
                 WHERE o.tenant_id = ? AND o.status IN ('new', 'under_review')" . $visibility_sql;
        $params = [$tenant_id, ...$visibility_params];
        if (!empty($seen['orders'])) {
            $sql .= ' AND (o.created_at > ? OR o.updated_at > ?)';
            $params[] = $seen['orders'];
            $params[] = $seen['orders'];
        }
        $row = fetch_one($sql, ...$params);
        $counts['orders'] = (int)($row['c'] ?? 0);
    }

    // Inventory locations that match the page scope of each role. Null means tenant-wide.
    if (tenant_admin_scope()) {
        $warehouse_ids = null;
    } elseif (is_godown_manager()) {
        $warehouse_ids = godown_manager_location_ids($tenant_id, false);
    } elseif (is_shop_manager()) {
        $warehouse_ids = [];
        foreach (accessible_shops(null, false) as $sidebar_shop) {
            $shop_warehouse = shop_inventory_warehouse_id((int)$sidebar_shop['shop_id'], $tenant_id);
            if ($shop_warehouse) $warehouse_ids[] = $shop_warehouse;
        }
        $warehouse_ids = array_values(array_unique($warehouse_ids));
    } else {
        $warehouse_ids = accessible_warehouse_ids($tenant_id, false, false);
        if ($warehouse_ids === null) $warehouse_ids = [];
    }
    $shop_ids = accessible_shop_ids($tenant_id, true);
    $has_warehouse_scope = $warehouse_ids === null || !empty($warehouse_ids);

    // Invoices with an outstanding balance, excluding cancelled documents.
    if (can('manage_invoices') && $has_warehouse_scope) {
        $sql = "SELECT COUNT(*) AS c FROM invoices i
                 LEFT JOIN document_statuses ds ON ds.status_id = i.status_id AND ds.tenant_id = i.tenant_id
                WHERE i.tenant_id = ? AND i.amount_due > 0.005
                  AND (ds.name IS NULL OR ds.name <> 'Cancelled')";
        $params = [$tenant_id];
        if ($warehouse_ids !== null) {
            $sql .= ' AND i.warehouse_id IN (' . implode(',', array_map('intval', $warehouse_ids)) . ')';
        }
        if (!empty($seen['invoices'])) {
            // Only new unpaid invoices should raise the badge again after a visit.
            $sql .= ' AND i.created_at > ?';
            $params[] = $seen['invoices'];
        }
        $row = fetch_one($sql, ...$params);
        $counts['invoices'] = (int)($row['c'] ?? 0);
    }

    // Active transfers at any of the user's source/destination locations.
    if (can('manage_inventory') || can('manage_godown') || can('request_godown_transfers') || can('receive_godown_transfers') || can('approve_godown_transfers') || can('dispatch_godown_transfers')) {
        $sql = "SELECT COUNT(*) AS c FROM stock_transfers t
                 WHERE t.tenant_id = ? AND t.status IN ('draft', 'approved', 'dispatched', 'discrepancy')";
        $params = [$tenant_id];
        if (!tenant_admin_scope()) {
            $scope_parts = [];
            if (!empty($warehouse_ids)) {
                $id_list = implode(',', array_map('intval', $warehouse_ids));
                $scope_parts[] = 't.source_warehouse_id IN (' . $id_list . ')';
                $scope_parts[] = 't.destination_warehouse_id IN (' . $id_list . ')';
            }
            if (!empty($shop_ids)) {
                $scope_parts[] = 't.destination_shop_id IN (' . implode(',', array_map('intval', $shop_ids)) . ')';
            }
            if (!$scope_parts) {
                $sql = '';
            } else {
                $sql .= ' AND (' . implode(' OR ', $scope_parts) . ')';
            }
        }
        if ($sql !== '') {
            if (!empty($seen['transfers'])) {
                $sql .= ' AND (t.created_at > ? OR t.updated_at > ?)';
                $params[] = $seen['transfers'];
                $params[] = $seen['transfers'];
            }
            $row = fetch_one($sql, ...$params);
            $counts['transfers'] = (int)($row['c'] ?? 0);
        }
    }

    // Products at or below their minimum level in the user's locations.
    if (can('manage_inventory') && $has_warehouse_scope) {
        $sql = "SELECT COUNT(*) AS c FROM (
                    SELECT s.product_id
                      FROM inventory_stock s
                      JOIN products p ON p.product_id = s.product_id AND p.tenant_id = s.tenant_id
                     WHERE s.tenant_id = ? AND p.is_active = 1 AND p.is_service = 0";
        $params = [$tenant_id];
        $sidebar_brand = current_brand_scope();
        if ($sidebar_brand !== '') {
            $sql .= ' AND p.brand = ?';
            $params[] = $sidebar_brand;
        }
        if ($warehouse_ids !== null) {
            $sql .= ' AND s.warehouse_id IN (' . implode(',', array_map('intval', $warehouse_ids)) . ')';
        }
        $sql .= "     GROUP BY s.product_id
                    HAVING SUM(s.quantity) <= MAX(COALESCE(p.min_stock_level, 0))";
        if (!empty($seen['inventory'])) {
            $sql .= ' AND MAX(s.updated_at) > ?';
            $params[] = $seen['inventory'];
        }
        $sql .= " ) low_stock";
        $row = fetch_one($sql, ...$params);
        $counts['low_stock'] = (int)($row['c'] ?? 0);
    }

    $cache[$cache_key] = $counts;
    return $counts;
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
        $result = $stmt->get_result();
        $row = $result->fetch_assoc();
        if ($row) $currency = $row['currency_code'];
        $stmt->close();
    }
    return $currency;
}

/**
 * Display name of the business as saved in System Settings.
 *
 * Uses the signed-in tenant when available, otherwise the first tenant with a
 * configured company name (so the public storefront and login screen match).
 * APP_NAME is only a fallback for installations where no company name is set.
 */
function system_title($tenant_id = null) {
    global $current_user;
    static $titles = [];

    if ($tenant_id === null) {
        if (isset($current_user->tenant_id)) {
            $tenant_id = (int)$current_user->tenant_id;
        } elseif (!empty($_SESSION['tenant_id'])) {
            $tenant_id = (int)$_SESSION['tenant_id'];
        }
    }

    $cache_key = $tenant_id !== null ? (int)$tenant_id : 0;
    if (array_key_exists($cache_key, $titles)) return $titles[$cache_key];

    if ($cache_key > 0) {
        $row = fetch_one("SELECT company_name FROM system_settings WHERE tenant_id = ? LIMIT 1", $cache_key);
    } else {
        $row = fetch_one(
            "SELECT ss.company_name
               FROM tenants t
               LEFT JOIN system_settings ss ON ss.tenant_id = t.tenant_id
              ORDER BY (ss.company_name IS NULL OR TRIM(ss.company_name) = '') ASC, t.tenant_id ASC
              LIMIT 1"
        );
    }

    $title = trim((string)($row['company_name'] ?? ''));
    if ($title === '') $title = APP_NAME;

    $titles[$cache_key] = $title;
    return $title;
}

/** Path to the uploaded system logo, or the bundled fallback. Empty if none. */
function system_logo_path($tenant_id = null) {
    global $current_user;
    static $logos = [];

    if ($tenant_id === null) {
        if (isset($current_user->tenant_id)) {
            $tenant_id = (int)$current_user->tenant_id;
        } elseif (!empty($_SESSION['tenant_id'])) {
            $tenant_id = (int)$_SESSION['tenant_id'];
        }
    }

    $cache_key = $tenant_id !== null ? (int)$tenant_id : 0;
    if (array_key_exists($cache_key, $logos)) return $logos[$cache_key];

    $candidates = [];
    if ($cache_key > 0) {
        $row = fetch_one("SELECT logo_path FROM system_settings WHERE tenant_id = ? LIMIT 1", $cache_key);
        if ($row && !empty($row['logo_path'])) $candidates[] = (string)$row['logo_path'];
    } else {
        $row = fetch_one(
            "SELECT ss.logo_path FROM tenants t
               LEFT JOIN system_settings ss ON ss.tenant_id = t.tenant_id
              ORDER BY (ss.logo_path IS NULL OR TRIM(ss.logo_path) = '') ASC, t.tenant_id ASC
              LIMIT 1"
        );
        if ($row && !empty($row['logo_path'])) $candidates[] = (string)$row['logo_path'];
    }
    $candidates[] = 'assets/images/logo.png';

    $logo = '';
    foreach ($candidates as $candidate) {
        $candidate = str_replace('\\', '/', trim($candidate));
        if ($candidate === '' || strpos($candidate, '..') !== false || preg_match('#^https?://#i', $candidate)) {
            continue;
        }
        if (file_exists(__DIR__ . '/' . ltrim($candidate, '/'))) {
            $logo = ltrim($candidate, '/');
            break;
        }
    }
    $logos[$cache_key] = $logo;
    return $logo;
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

/** Clear session data and invalidate the browser's session cookie. */
function end_session() {
    if (session_status() !== PHP_SESSION_ACTIVE) return;

    if (ini_get('session.use_cookies')) {
        $cookie = session_get_cookie_params();
        setcookie(
            session_name(),
            '',
            time() - 42000,
            $cookie['path'],
            $cookie['domain'],
            $cookie['secure'],
            $cookie['httponly']
        );
    }

    $_SESSION = [];
    session_unset();
    session_destroy();
    session_id('');
}

/** Store an uploaded product image and return its relative path, or false on error. */
function store_product_image_upload($file, &$error) {
    $error = '';
    if (!is_array($file) || !isset($file['error']) || $file['error'] === UPLOAD_ERR_NO_FILE) {
        return '';
    }
    if ($file['error'] !== UPLOAD_ERR_OK) {
        $error = 'The product image could not be uploaded.';
        return false;
    }
    if (!isset($file['size']) || (int)$file['size'] <= 0 || (int)$file['size'] > 5 * 1024 * 1024) {
        $error = 'Product images must be no larger than 5 MB.';
        return false;
    }
    if (empty($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) {
        $error = 'The uploaded product image is invalid.';
        return false;
    }

    $image_info = @getimagesize($file['tmp_name']);
    $extensions = [
        IMAGETYPE_JPEG => 'jpg',
        IMAGETYPE_PNG => 'png',
        IMAGETYPE_WEBP => 'webp',
        IMAGETYPE_GIF => 'gif',
    ];
    if (!$image_info || !isset($extensions[$image_info[2]])) {
        $error = 'Choose a valid JPG, PNG, WEBP or GIF image.';
        return false;
    }

    $directory = __DIR__ . '/uploads/products';
    if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) {
        $error = 'The product image upload directory is unavailable.';
        return false;
    }
    $filename = bin2hex(random_bytes(16)) . '.' . $extensions[$image_info[2]];
    $destination = $directory . '/' . $filename;
    if (!move_uploaded_file($file['tmp_name'], $destination)) {
        $error = 'The product image could not be saved.';
        return false;
    }
    return 'uploads/products/' . $filename;
}

/** Remove an image only when it is a locally stored product upload. */
function remove_product_image_file($path) {
    $path = str_replace('\\', '/', (string)$path);
    if (!preg_match('#^uploads/products/[a-f0-9]{32}\.(jpg|png|webp|gif)$#i', $path)) {
        return;
    }
    $file = __DIR__ . '/' . $path;
    if (is_file($file)) {
        unlink($file);
    }
}

/** Check a permission on behalf of the current user. */
function can($permission) {
    global $current_user;
    if (!$current_user || !$current_user->hasPermission($permission)) return false;
    if (
        is_godown_manager()
        && !in_array($permission, [
            'manage_godown',
            'view_godown',
            'manage_inventory',
            'manage_expenses',
            'manage_purchases',
            'manage_suppliers',
            'manage_invoices',
            'approve_godown_transfers',
            'dispatch_godown_transfers',
            'receive_godown_transfers',
            'request_godown_transfers',
        ], true)
    ) {
        return false;
    }
    return true;
}

function godown_manager_page_allowed($page) {
    return in_array(basename((string)$page), [
        'dashboard.php',
        'godown.php',
        'ajax-godown-product-search.php',
        'products.php',
        'product-add.php',
        'product-edit.php',
        'product-view.php',
        'product-barcode.php',
        'barcode-image.php',
        'inventory.php',
        'expenses.php',
        'export-expenses.php',
        'purchases.php',
        'purchase-add.php',
        'purchase-edit.php',
        'purchase-view.php',
        'purchase-delete.php',
        'purchase-status.php',
        'suppliers.php',
        'invoices.php',
        'invoice-add.php',
        'invoice-details.php',
        'invoice-edit.php',
        'invoice-cancel.php',
        'invoice-print.php',
        'export-invoices.php',
        'quotations.php',
        'quotation-add.php',
        'quotation-details.php',
        'quotation-print.php',
        'quotation-download.php',
        'godown-transfers.php',
        'godown-transfer-add.php',
        'godown-transfer-view.php',
        'notifications.php',
        'notifications-feed.php',
        'notification-open.php',
        'inventory-adjust.php',
        'profile.php',
        'update-profile.php',
        'logout.php',
    ], true);
}

/** True when the user has tenant-wide shop visibility (full administrators). */
function tenant_admin_scope() {
    return can('manage_users') || can('manage_settings');
}

/**
 * Brand a user account is restricted to ('' when the account is not scoped).
 * The stored users.brand_scope value wins; role names provide a fallback so a
 * Mini Admin is always restricted even if the column was cleared by mistake.
 */
function user_brand_scope($user = null) {
    if ($user === null) {
        global $current_user;
        $user = $current_user;
    }
    if (!$user) return '';

    $scope = trim((string)($user->brand_scope ?? ''));
    if ($scope === '' && is_array($user->roles ?? null)) {
        foreach ($user->roles as $role) {
            $key = strtolower(trim((string)$role));
            if ($key === 'mini admin - gsp') return 'GSP GROUP';
            if ($key === 'mini admin - techdokta') return 'TECHDOKTA INVESTMENT';
        }
    }
    if ($scope === '') return '';
    return in_array($scope, product_brand_options(), true) ? $scope : '';
}

/**
 * Brand scope of the signed-in user. Full administrators (manage_users or
 * manage_settings) are never restricted, even if a brand value is stored.
 */
function current_brand_scope() {
    global $current_user;
    if (!$current_user) return '';
    // Only the built-in Administrator role is exempt. A Mini Admin who is
    // accidentally given an administrative permission stays brand-scoped.
    if (is_array($current_user->roles)) {
        foreach ($current_user->roles as $role) {
            if (strtolower(trim((string)$role)) === 'administrator') return '';
        }
    }
    return user_brand_scope($current_user);
}

/** True when the signed-in account is a brand-scoped Mini Admin. */
function is_brand_admin() {
    global $current_user;
    if (!$current_user) return false;
    if (is_godown_manager() || is_wholesaler($current_user)) return false;
    return current_brand_scope() !== '';
}

/**
 * SQL condition restricting a query to the signed-in user's brand.
 * Returns ['sql' => '', 'params' => []] for unrestricted accounts.
 * Pass $include_unbranded for records where an empty brand means "shared".
 */
function brand_scope_condition($column, $include_unbranded = false) {
    $scope = current_brand_scope();
    if ($scope === '') return ['sql' => '', 'params' => []];
    $column = preg_replace('/[^a-zA-Z0-9_.]/', '', (string)$column);
    if ($column === '') return ['sql' => ' AND 1 = 0', 'params' => []];
    if ($include_unbranded) {
        return [
            'sql' => " AND ({$column} = ? OR {$column} IS NULL OR {$column} = '')",
            'params' => [$scope],
        ];
    }
    return ['sql' => " AND {$column} = ?", 'params' => [$scope]];
}

/** Whether a record brand is visible to the signed-in user. */
function brand_scope_allows($brand) {
    $scope = current_brand_scope();
    if ($scope === '') return true;
    return trim((string)$brand) === $scope;
}

/** Brand options for filter dropdowns: a Mini Admin only sees their brand. */
function brand_filter_options() {
    $scope = current_brand_scope();
    return $scope !== '' ? [$scope] : product_brand_options();
}

/** Whether the current user may manage capital funding. */
function can_manage_capital() {
    return can('manage_capital') || is_brand_admin();
}

/**
 * Resolve the capital brand filter. A Mini Admin is always locked to their
 * brand; a full administrator may choose one brand or all brands ('').
 */
function capital_resolve_brand($requested = '') {
    $scope = current_brand_scope();
    if ($scope !== '') return $scope;
    $requested = trim((string)$requested);
    return in_array($requested, product_brand_options(), true) ? $requested : '';
}

/** Brands visible on capital screens (both for a full administrator). */
function capital_visible_brands() {
    return brand_filter_options();
}


/**
 * Whether a lead is visible to the signed-in user. Leads with no product brand
 * information stay visible so older leads are never hidden.
 */
function lead_is_visible($lead) {
    global $current_user;
    if (!$current_user || !$lead) return false;
    if ((int)($lead['tenant_id'] ?? 0) !== (int)$current_user->tenant_id) return false;
    $scope = current_brand_scope();
    if ($scope === '') return true;
    $lead_id = (int)($lead['lead_id'] ?? 0);
    if ($lead_id <= 0) return true;
    if ((int)($lead['created_by'] ?? 0) === (int)$current_user->user_id) return true;
    $brands = lead_product_brands($lead_id);
    if (!$brands) return true;
    return in_array($scope, $brands, true);
}


/**
 * Count rows that refer to a tenant-owned shop or inventory location.
 * Column names are read from MySQL metadata and validated before use.
 */
function location_reference_count(string $table, string $column, int $record_id, int $tenant_id, string $exclude_table = ''): int
{
    global $conn;
    if ($table === 'warehouses' && $column === 'warehouse_id') {
        $reference_columns = ['warehouse_id', 'source_warehouse_id', 'destination_warehouse_id', 'godown_id', 'location_id'];
    } elseif ($table === 'shops' && $column === 'shop_id') {
        $reference_columns = ['shop_id', 'destination_shop_id'];
    } else {
        throw new InvalidArgumentException('Invalid foreign-key reference target.');
    }

    $column_placeholders = implode(', ', array_fill(0, count($reference_columns), '?'));
    $stmt = $conn->prepare(
        "SELECT c.TABLE_NAME, c.COLUMN_NAME,
                MAX(CASE WHEN tenant_col.COLUMN_NAME IS NOT NULL THEN 1 ELSE 0 END) has_tenant_id
           FROM information_schema.COLUMNS c
           LEFT JOIN information_schema.COLUMNS tenant_col
             ON tenant_col.TABLE_SCHEMA = c.TABLE_SCHEMA
            AND tenant_col.TABLE_NAME = c.TABLE_NAME
            AND tenant_col.COLUMN_NAME = 'tenant_id'
          WHERE c.TABLE_SCHEMA = ?
            AND c.TABLE_NAME NOT IN (?, ?)
            AND c.COLUMN_NAME IN ({$column_placeholders})
          GROUP BY c.TABLE_NAME, c.COLUMN_NAME"
    );
    if (!$stmt) {
        throw new RuntimeException('Could not inspect location references: ' . $conn->error);
    }
    $metadata_params = array_merge([DB_NAME, $table, $exclude_table], $reference_columns);
    if (!$stmt->bind_param(str_repeat('s', count($metadata_params)), ...$metadata_params) || !$stmt->execute()) {
        $error = $stmt->error;
        $stmt->close();
        throw new RuntimeException('Could not inspect location references: ' . $error);
    }
    $result = $stmt->get_result();
    if (!$result) {
        $stmt->close();
        throw new RuntimeException('Could not read location reference metadata.');
    }
    $references = $result->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    $count = 0;
    foreach ($references as $reference) {
        $child_table = (string)$reference['TABLE_NAME'];
        $child_column = (string)$reference['COLUMN_NAME'];
        if (!preg_match('/^[a-zA-Z0-9_]+$/', $child_table) || !preg_match('/^[a-zA-Z0-9_]+$/', $child_column)) {
            throw new RuntimeException('Unexpected location reference metadata returned by the database.');
        }

        $sql = 'SELECT COUNT(*) AS reference_count FROM `' . $child_table . '` WHERE `' . $child_column . '` = ?';
        $params = [$record_id];
        if ((int)$reference['has_tenant_id'] === 1) {
            $sql .= ' AND tenant_id = ?';
            $params[] = $tenant_id;
        }
        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            throw new RuntimeException('Could not count location references: ' . $conn->error);
        }
        $types = (int)$reference['has_tenant_id'] === 1 ? 'ii' : 'i';
        if (!$stmt->bind_param($types, ...$params) || !$stmt->execute()) {
            $error = $stmt->error;
            $stmt->close();
            throw new RuntimeException('Could not count location references: ' . $error);
        }
        $result = $stmt->get_result();
        if (!$result) {
            $stmt->close();
            throw new RuntimeException('Could not read location reference counts.');
        }
        $row = $result->fetch_assoc();
        $stmt->close();
        $count += (int)($row['reference_count'] ?? 0);
    }
    return $count;
}

/** Whether the current user has the built-in Shop Manager role. */
function is_shop_manager() {
    global $current_user;
    if (!$current_user || !is_array($current_user->roles)) return false;
    foreach ($current_user->roles as $role) {
        if (strtolower(trim((string)$role)) === 'shop manager') return true;
    }
    return false;
}

/** Whether the current user can verify storefront order requests. */
function can_verify_storefront_order_requests() {
    return can('manage_orders')
        && (tenant_admin_scope()
            || is_brand_admin()
            || (is_shop_manager() && count(accessible_shops(null, false)) > 0));
}

/** Whether the current account uses the restricted Godown Manager role. */
function is_godown_manager() {
    global $current_user;
    if (!$current_user || !is_array($current_user->roles)) return false;
    if ($current_user->hasPermission('manage_users') || $current_user->hasPermission('manage_settings')) {
        return false;
    }
    foreach ($current_user->roles as $role) {
        if (strtolower(trim((string)$role)) === 'godown manager') return true;
    }
    return false;
}

/** True when the current account is the system administrator role. */
function is_system_administrator() {
    global $current_user;
    if (!$current_user) return false;
    if (is_godown_manager()) return false;
    if (is_array($current_user->roles)) {
        foreach ($current_user->roles as $role) {
            if (strtolower(trim((string)$role)) === 'administrator') return true;
        }
    }
    // Fall back to a tenant-wide account when the administrator role is renamed.
    return tenant_admin_scope();
}

/**
 * Product catalogue (Products tab) is limited to the system administrator
 * and the Godown Manager. Shop and store managers must not see or use it.
 */
function can_manage_product_catalogue() {
    return is_system_administrator() || is_godown_manager() || is_brand_admin();
}

/** Guard helper: 403 unless the current user may manage the product catalogue. */
function require_product_catalogue_access() {
    if (!can_manage_product_catalogue()) {
        http_response_code(403);
        die('Only the system administrator, the Godown Manager and a brand Mini Admin can access products.');
    }
}

/** True when the account is a wholesale customer (portal role). */
function is_wholesaler($user = null) {
    global $current_user;
    if ($user === null) $user = $current_user;
    if (!$user || !is_array($user->roles)) return false;
    foreach ($user->roles as $role) {
        if (strtolower(trim((string)$role)) === 'wholesaler') return true;
    }
    return false;
}

/** Guard helper for system-administrator-only management screens. */
function require_system_administrator() {
    if (!is_system_administrator()) {
        http_response_code(403);
        die('Only the system administrator can access this page.');
    }
}

/** Return the tenant's Wholesaler role id, creating the role when missing. */
function ensure_wholesaler_role($tenant_id) {
    global $conn;
    $tenant_id = (int)$tenant_id;
    $existing = fetch_one(
        "SELECT role_id FROM roles WHERE tenant_id = ? AND LOWER(name) = 'wholesaler' LIMIT 1",
        $tenant_id
    );
    if ($existing) return (int)$existing['role_id'];

    $stmt = $conn->prepare("INSERT INTO roles (tenant_id, name, description) VALUES (?, 'Wholesaler', 'Wholesale customer portal access')");
    if (!$stmt) throw new RuntimeException($conn->error);
    $stmt->bind_param('i', $tenant_id);
    if (!$stmt->execute()) throw new RuntimeException($stmt->error);
    $role_id = (int)$stmt->insert_id;
    $stmt->close();
    return $role_id;
}

/** The wholesaler profile attached to a user account, or null. */
function wholesaler_account_for_user($user_id, $tenant_id = null) {
    global $current_user;
    $user_id = (int)$user_id;
    if ($tenant_id === null && $current_user) $tenant_id = (int)$current_user->tenant_id;
    $sql = "SELECT w.*, u.username, u.is_active AS user_active, u.last_login
              FROM wholesaler_accounts w
              JOIN users u ON u.user_id = w.user_id
             WHERE w.user_id = ?";
    $params = [$user_id];
    if ($tenant_id !== null) {
        $sql .= " AND w.tenant_id = ?";
        $params[] = (int)$tenant_id;
    }
    return fetch_one($sql . " LIMIT 1", ...$params);
}

/** One wholesaler profile by id, scoped to a tenant. */
function wholesaler_account_by_id($wholesaler_id, $tenant_id) {
    return fetch_one(
        "SELECT w.*, u.username, u.is_active AS user_active, u.last_login, u.email AS user_email, u.phone AS user_phone,
                c.customer_channel, c.customer_number
           FROM wholesaler_accounts w
           JOIN users u ON u.user_id = w.user_id
           LEFT JOIN contacts c ON c.contact_id = w.contact_id AND c.tenant_id = w.tenant_id
          WHERE w.wholesaler_id = ? AND w.tenant_id = ?
          LIMIT 1",
        (int)$wholesaler_id,
        (int)$tenant_id
    );
}

/** Build a unique username for a new wholesale account from their name. */
function wholesaler_unique_username($tenant_id, $name, $email = '') {
    $base = strtolower(preg_replace('/[^a-z0-9]+/i', '', (string)$name));
    if ($base === '') {
        $email = trim((string)$email);
        if ($email !== '' && strpos($email, '@') !== false) {
            $base = strtolower(preg_replace('/[^a-z0-9]+/i', '', (string)substr($email, 0, strpos($email, '@'))));
        }
    }
    if ($base === '') $base = 'wholesaler';

    $candidate = $base;
    $suffix = 1;
    while (fetch_one("SELECT user_id FROM users WHERE tenant_id = ? AND username = ? LIMIT 1", (int)$tenant_id, $candidate)) {
        $suffix++;
        $candidate = $base . $suffix;
    }
    return $candidate;
}

/** Customer segmentation options. */
function customer_type_options() {
    return ['retail' => 'Retail customer', 'wholesale' => 'Wholesale customer'];
}

/** Whether a table exists in the current database. */
function db_table_exists($table) {
    return (bool)fetch_one(
        "SELECT 1 FROM information_schema.TABLES
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? LIMIT 1",
        (string)$table
    );
}

/** Retail channel options. Channel applies to both retail and wholesale customers. */
function customer_channel_options() {
    return ['walk_in' => 'Walk-in customer', 'online' => 'Online customer'];
}

/** Human label for a stored customer type. */
function customer_type_label($type) {
    $options = customer_type_options();
    $type = strtolower(trim((string)$type));
    return $options[$type] ?? 'Retail customer';
}

/** Human label for a stored retail channel. */
function customer_channel_label($channel) {
    $options = customer_channel_options();
    $channel = strtolower(trim((string)$channel));
    return $options[$channel] ?? 'Walk-in customer';
}

/** Fetch the segmentation fields for one customer. */
function customer_segment($contact_id, $tenant_id) {
    return fetch_one(
        "SELECT contact_id, customer_type, customer_channel
           FROM contacts
          WHERE contact_id = ? AND tenant_id = ?
          LIMIT 1",
        (int)$contact_id,
        (int)$tenant_id
    );
}

/** Whether a contact is a wholesale customer. */
function contact_is_wholesale($contact_id, $tenant_id) {
    $row = customer_segment($contact_id, $tenant_id);
    return $row && strtolower((string)$row['customer_type']) === 'wholesale';
}

/** Whether a product has a usable wholesale price and threshold. */
function product_has_wholesale_price($product) {
    return !empty($product)
        && (float)($product['wholesale_price'] ?? 0) > 0
        && (float)($product['wholesale_min_qty'] ?? 0) > 0;
}

/**
 * Price for a product line. The wholesale price applies only when the
 * quantity meets the product's wholesale minimum; otherwise sale_price.
 */
function product_line_price($product, $quantity = null) {
    $sale = (float)($product['sale_price'] ?? 0);
    if (!product_has_wholesale_price($product)) return $sale;
    if ($quantity === null) return (float)$product['wholesale_price'];
    return (float)$quantity >= (float)$product['wholesale_min_qty']
        ? (float)$product['wholesale_price']
        : $sale;
}

/** Active or historical Godowns explicitly assigned to the current manager. */
function godown_manager_location_ids($tenant_id = null, $include_inactive = true) {
    global $current_user;
    if (!$current_user) return [];
    $tenant_id = $tenant_id === null ? (int)$current_user->tenant_id : (int)$tenant_id;
    if ($tenant_id !== (int)$current_user->tenant_id) return [];

    $active_sql = $include_inactive ? '' : ' AND w.is_active = 1';
    $rows = fetch_all(
        "SELECT DISTINCT w.warehouse_id
           FROM warehouses w
          WHERE w.tenant_id = ? AND w.location_type = 'godown'{$active_sql}
            AND (
                EXISTS (
                    SELECT 1 FROM user_inventory_locations uil
                     WHERE uil.tenant_id = w.tenant_id
                       AND uil.user_id = ? AND uil.location_id = w.warehouse_id
                )
                OR EXISTS (
                    SELECT 1 FROM user_godowns ug
                     WHERE ug.tenant_id = w.tenant_id
                       AND ug.user_id = ? AND ug.warehouse_id = w.warehouse_id
                )
            )
          ORDER BY w.warehouse_id",
        $tenant_id,
        (int)$current_user->user_id,
        (int)$current_user->user_id
    );
    return array_map(fn($row) => (int)$row['warehouse_id'], $rows);
}

/** Permission names granted by selected roles in the current tenant. */
function role_permissions_for_ids(array $role_ids, int $tenant_id): array
{
    $role_ids = array_values(array_unique(array_filter(array_map('intval', $role_ids), fn($id) => $id > 0)));
    if (!$role_ids || $tenant_id <= 0) return [];

    $placeholders = implode(',', array_fill(0, count($role_ids), '?'));
    $params = array_merge([$tenant_id], $role_ids);
    $rows = fetch_all(
        "SELECT DISTINCT r.role_id, p.name permission_name
           FROM roles r
           JOIN role_permissions rp ON rp.role_id = r.role_id
           JOIN permissions p ON p.permission_id = rp.permission_id
          WHERE r.tenant_id = ? AND r.role_id IN ($placeholders)",
        ...$params
    );
    $permissions = [];
    foreach ($rows as $row) {
        $permissions[(int)$row['role_id']][] = (string)$row['permission_name'];
    }
    return $permissions;
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
    if (is_godown_manager()) return [];
    $active_sql = $include_inactive ? '' : ' AND s.is_active = 1';
    // A brand-scoped Mini Admin sees every shop of their brand, regardless of
    // per-user shop mappings.
    $brand_scope = current_brand_scope();
    if ($brand_scope !== '') {
        return fetch_all(
            "SELECT s.* FROM shops s
              WHERE s.tenant_id = ? AND s.brand = ?{$active_sql}
              ORDER BY s.name",
            $tenant_id,
            $brand_scope
        );
    }
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
    if (is_godown_manager()) {
        if (!$include_godown || $shop_id !== null || !can_access_godown()) return [];
        $godown_ids = godown_manager_location_ids($tenant_id, $include_inactive);
        if (!$godown_ids) return [];
        $id_list = implode(',', array_map('intval', $godown_ids));
        $active_sql = $include_inactive ? '' : ' AND w.is_active = 1';
        return fetch_all(
            "SELECT w.*, NULL AS shop_name, NULL AS shop_code, 1 AS shop_is_active
               FROM warehouses w
              WHERE w.tenant_id = ? AND w.location_type = 'godown'
                AND w.warehouse_id IN ({$id_list}){$active_sql}
              ORDER BY w.name",
            $tenant_id
        );
    }

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

    if (!tenant_admin_scope()) {
        $rows_by_id = [];
        foreach ($rows as $row) {
            $rows_by_id[(int)$row['warehouse_id']] = $row;
        }
        $active_sql = $include_inactive ? '' : " AND w.is_active = 1 AND s.is_active = 1";
        $brand_scope = current_brand_scope();
        $brand_sql = $brand_scope !== '' ? ' AND s.brand = ?' : '';
        $assigned_params = [$tenant_id, (int)$current_user->user_id];
        if ($brand_scope !== '') {
            $assigned_params[] = $brand_scope;
        }
        $assigned_stores = fetch_all(
            "SELECT w.*, s.name shop_name, s.code shop_code, s.is_active shop_is_active
               FROM user_inventory_locations uil
               JOIN warehouses w ON w.warehouse_id = uil.location_id AND w.tenant_id = uil.tenant_id
               JOIN shops s ON s.shop_id = w.shop_id AND s.tenant_id = w.tenant_id
              WHERE uil.tenant_id = ? AND uil.user_id = ? AND w.location_type = 'store'{$active_sql}{$brand_sql}
              ORDER BY s.name, w.name",
            ...$assigned_params
        );
        foreach ($assigned_stores as $assigned_store) {
            if ($shop_id !== null && (int)$assigned_store['shop_id'] !== (int)$shop_id) continue;
            $rows_by_id[(int)$assigned_store['warehouse_id']] = $assigned_store;
        }
        $rows = array_values($rows_by_id);
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

    $unique = [];
    foreach ($rows as $row) {
        $unique[(int)$row['warehouse_id']] = $row;
    }
    return array_values($unique);
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
    if (is_godown_manager()) {
        return $include_godown && can_access_godown()
            ? godown_manager_location_ids($tenant_id, $include_inactive)
            : [];
    }
    if (tenant_admin_scope()) return null;

    // A brand Mini Admin can use every location belonging to their brand's
    // shops (both the shop inventory location and its stores), plus the shared
    // Central Godown when the caller asks for Godown access.
    $brand_scope = current_brand_scope();
    if ($brand_scope !== '') {
        global $current_user;
        $tenant_id = $tenant_id === null ? (int)$current_user->tenant_id : (int)$tenant_id;
        $ids = [];
        foreach (accessible_shops($tenant_id, $include_inactive) as $shop) {
            $location_id = shop_inventory_warehouse_id((int)$shop['shop_id'], $tenant_id);
            if ($location_id) {
                $ids[] = (int)$location_id;
            }
        }
        foreach (accessible_stores(null, $include_inactive, $tenant_id, false) as $store) {
            $ids[] = (int)$store['warehouse_id'];
        }
        if ($include_godown) {
            $active_sql = $include_inactive ? '' : ' AND is_active = 1';
            $godowns = fetch_all(
                "SELECT warehouse_id FROM warehouses
                  WHERE tenant_id = ? AND location_type = 'godown'{$active_sql}",
                $tenant_id
            );
            foreach ($godowns as $godown) {
                $ids[] = (int)$godown['warehouse_id'];
            }
        }
        return array_values(array_unique($ids));
    }

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
    if (is_godown_manager()) return false;
    $brand_scope = current_brand_scope();
    if ($brand_scope !== '') {
        return trim((string)($shop['brand'] ?? '')) === $brand_scope;
    }
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
        if (
            is_godown_manager()
            && !in_array($warehouse_id, godown_manager_location_ids(null, true), true)
        ) {
            return null;
        }
        return can_access_godown() ? $store : null;
    }
    if (is_godown_manager()) return null;

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
    $params = [$tenant_id];
    $assignment_sql = '';
    if (is_godown_manager()) {
        $godown_ids = godown_manager_location_ids($tenant_id, true);
        if (!$godown_ids) return null;
        $assignment_sql = ' AND w.warehouse_id IN (' . implode(',', array_fill(0, count($godown_ids), '?')) . ')';
        $params = array_merge($params, $godown_ids);
    }
    $active_sql = $include_inactive ? '' : ' AND w.is_active = 1';
    return fetch_one(
        "SELECT w.*, NULL AS shop_name, NULL AS shop_code, 1 AS shop_is_active
         FROM warehouses w
         WHERE w.tenant_id = ? AND w.location_type = 'godown'{$assignment_sql}{$active_sql}
         ORDER BY w.is_active DESC, w.warehouse_id
         LIMIT 1",
        ...$params
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
    if (is_godown_manager()) {
        $godown_ids = godown_manager_location_ids($tenant_id, $include_inactive);
        if (!$godown_ids) return [];
        return fetch_all(
            "SELECT w.*, NULL AS shop_name, NULL AS shop_code
               FROM warehouses w
              WHERE w.tenant_id = ? AND w.location_type = 'godown'
                AND w.warehouse_id IN (" . implode(',', array_fill(0, count($godown_ids), '?')) . ")
              ORDER BY w.name",
            ...array_merge([$tenant_id], $godown_ids)
        );
    }
    if (tenant_admin_scope()) {
        $active_sql = $include_inactive ? '' : " AND w.is_active = 1 AND (w.location_type = 'godown' OR s.is_active = 1)";
        return fetch_all("SELECT w.*, s.name shop_name, s.code shop_code FROM warehouses w LEFT JOIN shops s ON s.shop_id = w.shop_id AND s.tenant_id = w.tenant_id WHERE w.tenant_id = ?{$active_sql} ORDER BY w.name", $tenant_id);
    }
    $ids = accessible_warehouse_ids($tenant_id, $include_inactive, true);
    if ($ids === null) $ids = [];
    $shop_ids = accessible_shop_ids($tenant_id, $include_inactive);
    if ($shop_ids) {
        $shop_placeholders = implode(',', array_fill(0, count($shop_ids), '?'));
        $shop_locations = fetch_all(
            "SELECT warehouse_id FROM warehouses
              WHERE tenant_id = ? AND shop_id IN ({$shop_placeholders})
                AND location_type = 'shop'" . ($include_inactive ? '' : ' AND is_active = 1'),
            ...array_merge([$tenant_id], $shop_ids)
        );
        foreach ($shop_locations as $location) $ids[] = (int)$location['warehouse_id'];
    }
    $uil = fetch_all("SELECT location_id FROM user_inventory_locations WHERE tenant_id = ? AND user_id = ?", $tenant_id, $current_user->user_id);
    foreach ($uil as $r) $ids[] = (int)$r['location_id'];
    $ids = array_values(array_unique($ids));
    if (!$ids) return [];
    $ph = implode(',', array_fill(0, count($ids), '?'));
    $active_sql = $include_inactive ? '' : " AND w.is_active = 1 AND (w.location_type = 'godown' OR s.is_active = 1)";
    $params = array_merge([$tenant_id], $ids);
    return fetch_all("SELECT w.*, s.name shop_name, s.code shop_code FROM warehouses w LEFT JOIN shops s ON s.shop_id = w.shop_id AND s.tenant_id = w.tenant_id WHERE w.tenant_id = ? AND w.warehouse_id IN ($ph){$active_sql} ORDER BY w.name", ...$params);
}

/** Whether a stock transfer may move from one location type to another. */
function stock_transfer_destination_allowed($source_type, $destination_type) {
    $allowed = [
        'godown' => ['store', 'shop'],
        'store' => ['store', 'shop'],
        'shop' => ['shop'],
    ];
    return in_array((string)$destination_type, $allowed[(string)$source_type] ?? [], true);
}

/**
 * Active tenant Stores and Shops that may receive stock from the given source type.
 * Destinations are not limited to the user's own locations: a Store may send to
 * other Stores and Shops, and a Godown may supply any active Store or Shop.
 */
function stock_transfer_destination_locations($source_type, $tenant_id = null, $exclude_warehouse_id = 0) {
    global $current_user;
    if (!$current_user) return [];
    $tenant_id = $tenant_id === null ? (int)$current_user->tenant_id : (int)$tenant_id;
    $rows = fetch_all(
        "SELECT w.*, s.name shop_name, s.code shop_code, s.is_active shop_is_active
           FROM warehouses w
           JOIN shops s ON s.shop_id = w.shop_id AND s.tenant_id = w.tenant_id
          WHERE w.tenant_id = ? AND w.is_active = 1 AND s.is_active = 1
            AND w.location_type IN ('store', 'shop')
          ORDER BY CASE w.location_type WHEN 'store' THEN 0 ELSE 1 END, s.name, w.name",
        $tenant_id
    );
    return array_values(array_filter(
        $rows,
        fn($location) => (int)$location['warehouse_id'] !== (int)$exclude_warehouse_id
            && stock_transfer_destination_allowed((string)$source_type, (string)$location['location_type'])
    ));
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
        'dispatched'  => 'navy',
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

    $source_location = find_accessible_store(
        (int)($transfer['source_warehouse_id'] ?? 0),
        true,
        true
    );
    $godown_oversight = can('manage_godown')
        || can('view_godown')
        || can('approve_godown_transfers')
        || can('dispatch_godown_transfers')
        || can('receive_godown_transfers');
    $source_location_staff = can('manage_inventory')
        || can('manage_pos')
        || can('manage_shops');
    return $source_location !== null
        && ($godown_oversight || $source_location_staff);
}

/** Whether the current user manages a Shop that owns this location. */
function stock_transfer_shop_staff_for_location($location) {
    if (!$location || (int)($location['shop_id'] ?? 0) <= 0) return false;
    if (!can('manage_inventory') && !can('manage_shops')) return false;
    return shop_is_accessible((int)$location['shop_id'], true);
}

/** Whether the current user manages an accessible Store location. */
function stock_transfer_store_staff_for_location($location) {
    if (!$location || (string)($location['location_type'] ?? '') !== 'store') return false;
    if (!can('manage_inventory') && !can('manage_shops')) return false;
    return store_is_accessible((int)($location['warehouse_id'] ?? 0), true);
}

/** Whether the current user manages an accessible Shop or Store location. */
function stock_transfer_location_staff($location) {
    return stock_transfer_shop_staff_for_location($location)
        || stock_transfer_store_staff_for_location($location);
}

/** Whether the current user may approve a transfer from an accessible location. */
function stock_transfer_can_approve_from_location($location) {
    if (!$location) return false;
    return can('approve_godown_transfers')
        || stock_transfer_location_staff($location);
}

/** Whether the current user may dispatch a transfer from an accessible location. */
function stock_transfer_can_dispatch_from_location($location) {
    if (!$location) return false;
    return can('dispatch_godown_transfers')
        || stock_transfer_location_staff($location);
}

/** Whether the current user may receive a transfer into an accessible location. */
function stock_transfer_can_receive_at_location($location) {
    if (!$location) return false;
    return can('receive_godown_transfers')
        || stock_transfer_location_staff($location);
}

/** Whether the current user may cancel an undispatched transfer from an accessible location. */
function stock_transfer_can_cancel_from_location($location) {
    if (!$location) return false;
    return can('approve_godown_transfers')
        || can('manage_godown')
        || stock_transfer_location_staff($location);
}

/** Whether the current user may resolve a discrepancy involving an accessible Shop. */
function stock_transfer_can_resolve_discrepancy($source, $destination) {
    return can('approve_godown_transfers')
        || can('manage_godown')
        || stock_transfer_location_staff($source)
        || stock_transfer_location_staff($destination);
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
    $brand_scope = current_brand_scope();
    $brand_sql = '';
    $params = [$tenant_id, $permission];
    if ($brand_scope !== '') {
        // A brand Mini Admin only sees staff who belong to their brand.
        $brand_sql = " AND (
            u.user_id = ?
            OR u.brand_scope = ?
            OR EXISTS (
                SELECT 1 FROM user_shops bus
                  JOIN shops bs ON bs.shop_id = bus.shop_id AND bs.tenant_id = bus.tenant_id
                 WHERE bus.user_id = u.user_id AND bus.tenant_id = u.tenant_id AND bs.brand = ?
            )
        )";
        array_push($params, current_user_id(), $brand_scope, $brand_scope);
    }
    return fetch_all(
        "SELECT DISTINCT u.user_id, u.first_name, u.last_name, u.username
         FROM users u
         JOIN user_roles ur ON ur.user_id = u.user_id AND ur.tenant_id = u.tenant_id
         JOIN role_permissions rp ON rp.role_id = ur.role_id
         JOIN permissions p ON p.permission_id = rp.permission_id
         WHERE u.tenant_id = ? AND u.is_active = 1 AND p.name = ?{$brand_sql}
         ORDER BY u.first_name, u.last_name",
        ...$params
    );
}

/** Whether a tenant user holds a permission (used to validate assignments). */
function user_has_tenant_permission($user_id, $permission, $tenant_id) {
    $user_id = (int)$user_id;
    $tenant_id = (int)$tenant_id;
    if ($user_id <= 0 || $tenant_id <= 0) return false;

    // A brand Mini Admin may only assign work to staff of their own brand.
    $brand_scope = current_brand_scope();
    if ($brand_scope !== '') {
        $visible = fetch_one(
            "SELECT 1 FROM users u
              WHERE u.user_id = ? AND u.tenant_id = ? AND u.is_active = 1
                AND (
                    u.brand_scope = ?
                    OR EXISTS (
                        SELECT 1 FROM user_shops bus
                          JOIN shops bs ON bs.shop_id = bus.shop_id AND bs.tenant_id = bus.tenant_id
                         WHERE bus.user_id = u.user_id AND bus.tenant_id = u.tenant_id AND bs.brand = ?
                    )
                )
              LIMIT 1",
            $user_id,
            $tenant_id,
            $brand_scope,
            $brand_scope
        );
        if (!$visible) return false;
    }

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

/** Whether an order is currently in the returned stage. */
function order_is_returned($order) {
    return $order && strtolower(trim((string)($order['stage'] ?? ''))) === 'returned';
}

/**
 * SQL expression for the amount an order contributes to sales.
 * The paid amount is reduced by the recorded return amount, so a returned order
 * nets to zero instead of its payment being counted without a deduction.
 */
function order_sales_amount_sql($alias = 'o') {
    $alias = preg_replace('/[^A-Za-z0-9_]/', '', (string)$alias);
    if ($alias === '') {
        $alias = 'o';
    }
    return "COALESCE({$alias}.Amount_paid, 0) - COALESCE({$alias}.returned_amount, 0)";
}

/**
 * Record an order return reason and the amount reversed from sales.
 * The caller must hold an open transaction and pass the locked order values.
 */
function record_order_return($order_id, $tenant_id, $user_id, $reason, $amount_paid, $already_returned = false) {
    global $conn;

    $reason = trim((string)$reason);
    if ($reason === '') {
        throw new RuntimeException('Enter a reason for returning this order.');
    }
    if (mb_strlen($reason) > 255) {
        $reason = mb_substr($reason, 0, 255);
    }

    $order_id = (int)$order_id;
    $tenant_id = (int)$tenant_id;
    $user_id = (int)$user_id;
    $amount = round((float)$amount_paid, 2);

    if ($already_returned) {
        $stmt = $conn->prepare(
            "UPDATE orders
                SET return_reason = ?, returned_amount = ?
              WHERE order_id = ? AND tenant_id = ?"
        );
        if (!$stmt) throw new RuntimeException($conn->error);
        $stmt->bind_param('sdii', $reason, $amount, $order_id, $tenant_id);
    } else {
        $stmt = $conn->prepare(
            "UPDATE orders
                SET stage = 'returned', return_reason = ?, returned_at = NOW(),
                    returned_by = ?, returned_amount = ?
              WHERE order_id = ? AND tenant_id = ?"
        );
        if (!$stmt) throw new RuntimeException($conn->error);
        $stmt->bind_param('sidii', $reason, $user_id, $amount, $order_id, $tenant_id);
    }

    if (!$stmt->execute()) {
        $error = $stmt->error;
        $stmt->close();
        throw new RuntimeException($error);
    }
    $stmt->close();

    if (db_table_exists('sales_returns')) {
        sync_order_sales_return($order_id, $tenant_id, $user_id, $amount);
    }

    return $amount;
}

/**
 * Create or refresh the sales-return record that mirrors an order return.
 * The caller must hold an open transaction.
 */
function sync_order_sales_return($order_id, $tenant_id, $user_id, $refund_amount) {
    global $conn;

    if (!db_table_exists('sales_returns') || !db_table_exists('sales_return_items')) {
        return 0;
    }

    $order_id = (int)$order_id;
    $tenant_id = (int)$tenant_id;
    $user_id = (int)$user_id;
    $refund_amount = round((float)$refund_amount, 2);

    $order = fetch_one(
        "SELECT * FROM orders WHERE order_id = ? AND tenant_id = ?",
        $order_id,
        $tenant_id
    );
    if (!$order) {
        return 0;
    }

    $customer_id = (int)($order['contact_id'] ?? 0) ?: null;
    $shop_id = (int)($order['shop_id'] ?? 0) ?: null;
    $warehouse_id = (int)($order['warehouse_id'] ?? 0) ?: null;

    $existing = fetch_one(
        "SELECT * FROM sales_returns
          WHERE tenant_id = ? AND source_type = 'order' AND source_id = ?
          ORDER BY return_id DESC LIMIT 1 FOR UPDATE",
        $tenant_id,
        $order_id
    );

    if ($existing) {
        $return_id = (int)$existing['return_id'];
        $stmt = $conn->prepare(
            "UPDATE sales_returns
                SET refund_amount = ?, customer_id = ?, shop_id = ?, warehouse_id = ?,
                    status = 'completed', return_date = NOW(), created_by = ?
              WHERE return_id = ? AND tenant_id = ?"
        );
        if (!$stmt) throw new RuntimeException($conn->error);
        $stmt->bind_param(
            'diiiiii',
            $refund_amount,
            $customer_id,
            $shop_id,
            $warehouse_id,
            $user_id,
            $return_id,
            $tenant_id
        );
        if (!$stmt->execute()) {
            $error = $stmt->error;
            $stmt->close();
            throw new RuntimeException($error);
        }
        $stmt->close();

        $stmt = $conn->prepare("DELETE FROM sales_return_items WHERE return_id = ? AND tenant_id = ?");
        if (!$stmt) throw new RuntimeException($conn->error);
        $stmt->bind_param('ii', $return_id, $tenant_id);
        if (!$stmt->execute()) {
            $error = $stmt->error;
            $stmt->close();
            throw new RuntimeException($error);
        }
        $stmt->close();
    } else {
        $return_id = db_insert('sales_returns', [
            'tenant_id' => $tenant_id,
            'return_number' => next_document_number($tenant_id, 'sales_return', 'RET'),
            'source_type' => 'order',
            'source_id' => $order_id,
            'customer_id' => $customer_id,
            'shop_id' => $shop_id,
            'warehouse_id' => $warehouse_id,
            'return_date' => date('Y-m-d H:i:s'),
            'refund_amount' => $refund_amount,
            'status' => 'completed',
            'created_by' => $user_id ?: null,
            'submission_token' => 'order-return-' . $order_id,
        ]);
        if (!$return_id) {
            throw new RuntimeException('Could not create the sales return for this order.');
        }
    }

    $items = fetch_all(
        "SELECT product_id, quantity, unit_price, line_total
           FROM order_items
          WHERE order_id = ? AND tenant_id = ?
          ORDER BY line_no",
        $order_id,
        $tenant_id
    );

    $returnable = [];
    $line_total_sum = 0.0;
    foreach ($items as $item) {
        if ((int)$item['product_id'] <= 0) continue;
        $returnable[] = $item;
        $line_total_sum += (float)$item['line_total'];
    }
    if ($line_total_sum <= 0.005) {
        $line_total_sum = max(0, (float)($order['total'] ?? 0));
    }

    $remaining = $refund_amount;
    $last_index = count($returnable) - 1;
    foreach ($returnable as $index => $item) {
        if ($index === $last_index) {
            $item_refund = $remaining;
        } else {
            $line_total = (float)$item['line_total'];
            $item_refund = $line_total_sum > 0
                ? round($refund_amount * $line_total / $line_total_sum, 2)
                : 0.0;
            if ($item_refund > $remaining) {
                $item_refund = $remaining;
            }
        }
        $item_refund = round($item_refund, 2);
        $remaining = round($remaining - $item_refund, 2);

        $inserted = db_insert('sales_return_items', [
            'return_id' => $return_id,
            'tenant_id' => $tenant_id,
            'product_id' => (int)$item['product_id'],
            'quantity' => (float)$item['quantity'],
            'unit_price' => (float)$item['unit_price'],
            'refund_amount' => $item_refund,
            'resale_quantity' => 0.0,
        ]);
        if (!$inserted) {
            throw new RuntimeException('Could not create the sales return items for this order.');
        }
    }

    return $return_id;
}

/** Cancel the sales-return record when an order is no longer returned. */
function cancel_order_sales_return($order_id, $tenant_id) {
    global $conn;

    if (!db_table_exists('sales_returns')) {
        return;
    }

    $stmt = $conn->prepare(
        "UPDATE sales_returns
            SET status = 'cancelled'
          WHERE tenant_id = ? AND source_type = 'order' AND source_id = ? AND status = 'completed'"
    );
    if (!$stmt) throw new RuntimeException($conn->error);
    $stmt->bind_param('ii', $tenant_id, $order_id);
    if (!$stmt->execute()) {
        $error = $stmt->error;
        $stmt->close();
        throw new RuntimeException($error);
    }
    $stmt->close();
}

/** Clear the return details when an order is moved out of the returned stage. */
function clear_order_return($order_id, $tenant_id) {
    global $conn;

    $stmt = $conn->prepare(
        "UPDATE orders
            SET return_reason = NULL, returned_at = NULL, returned_by = NULL, returned_amount = 0.00
          WHERE order_id = ? AND tenant_id = ?"
    );
    if (!$stmt) throw new RuntimeException($conn->error);
    $stmt->bind_param('ii', $order_id, $tenant_id);
    if (!$stmt->execute()) {
        $error = $stmt->error;
        $stmt->close();
        throw new RuntimeException($error);
    }
    $stmt->close();

    cancel_order_sales_return($order_id, $tenant_id);
}

/** Human-readable lead statuses. */
function lead_statuses() {
    return [
        'pending'   => 'Pending',
        'follow_up' => 'Follow Up',
        'confirmed' => 'Confirmed',
        'cancelled' => 'Cancelled',
    ];
}

/** Whether a lead update must include a next follow-up date. */
function lead_follow_up_date_required($status, $date) {
    return (string)$status === 'follow_up' && trim((string)$date) === '';
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

    // Brand-scoped Mini Admin: only their brand's shops and brand orders.
    $brand_scope = current_brand_scope();
    if ($brand_scope !== '') {
        $shop_id = (int)($order['shop_id'] ?? 0);
        if ($shop_id > 0) return shop_is_accessible($shop_id, true);
        if ((int)($order['created_by'] ?? 0) === (int)$current_user->user_id) return true;
        if (($order['source'] ?? '') === 'storefront' && empty($order['created_by'])) {
            $match = fetch_one(
                "SELECT 1 FROM order_items oi
                   JOIN products p ON p.product_id = oi.product_id AND p.tenant_id = ?
                  WHERE oi.order_id = ? AND p.brand = ? LIMIT 1",
                $current_user->tenant_id,
                (int)$order['order_id'],
                $brand_scope
            );
            return (bool)$match;
        }
        return false;
    }

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

    // Brand-scoped Mini Admin: their brand's shops, plus storefront requests
    // that contain their brand's products and are not yet linked to a shop.
    $brand_scope = current_brand_scope();
    if ($brand_scope !== '') {
        $conn = $GLOBALS['conn'] ?? null;
        if (!$conn) return [' AND 1 = 0', []];
        $brand = $conn->real_escape_string($brand_scope);
        $parts = [
            "{$alias}.shop_id IN (
                SELECT bs.shop_id FROM shops bs
                 WHERE bs.tenant_id = {$alias}.tenant_id AND bs.brand = '{$brand}'
            )",
            "({$alias}.shop_id IS NULL AND {$alias}.source = 'storefront' AND {$alias}.created_by IS NULL
              AND EXISTS (
                  SELECT 1 FROM order_items boi
                    JOIN products bp ON bp.product_id = boi.product_id AND bp.tenant_id = {$alias}.tenant_id
                   WHERE boi.order_id = {$alias}.order_id AND bp.brand = '{$brand}'
              ))",
            "{$alias}.created_by = " . (int)current_user_id(),
        ];
        return [' AND (' . implode(' OR ', $parts) . ')', []];
    }

    $current_uid = current_user_id();
    $shop_ids = accessible_shop_ids(null, true);
    $parts = ["{$alias}.created_by = ?", "{$alias}.assigned_to = ?", "({$alias}.source = 'storefront' AND {$alias}.created_by IS NULL)"];
    $params = [$current_uid, $current_uid];
    if ($shop_ids) {
        $parts[] = "{$alias}.shop_id IN (" . implode(',', array_map('intval', $shop_ids)) . ")";
    }
    return [' AND (' . implode(' OR ', $parts) . ')', $params];
}

/**
 * Payment-aware display status for a POS sale. A completed sale that was not
 * fully paid is shown as partially paid or unpaid instead of completed.
 */
function pos_sale_payment_status($status, $total, $amount_paid) {
    $status = strtolower(trim((string)$status));
    if ($status === 'cancelled') {
        return ['label' => 'Cancelled', 'class' => 'danger'];
    }
    if ($status === 'pending') {
        return ['label' => 'Pending', 'class' => 'warning'];
    }

    $total = round((float)$total, 2);
    $paid = round((float)$amount_paid, 2);
    if ($total <= 0.005 || $paid + 0.005 >= $total) {
        return ['label' => 'Completed', 'class' => 'success'];
    }
    if ($paid > 0.005) {
        return ['label' => 'Partially paid', 'class' => 'warning'];
    }
    return ['label' => 'Unpaid', 'class' => 'danger'];
}

/** Bootstrap badge class for an order status. */
function order_status_class($status) {
    $map = [
        'new'          => 'warning',
        'under_review' => 'info',
        'confirmed'    => 'navy',
        'fulfilled'    => 'success',
        'rejected'     => 'danger',
        'cancelled'    => 'secondary',
    ];
    return $map[$status] ?? 'secondary';
}

/** Customer name to display for a lead, preserving the name entered at storefront checkout. */
function lead_customer_display_name($lead) {
    if (!$lead) return '';
    $contact_name = trim((string)($lead['contact_name'] ?? ''));
    if ((string)($lead['source'] ?? '') === 'storefront' && $contact_name !== '') {
        return $contact_name;
    }

    $company_name = trim((string)($lead['contact_company'] ?? ''));
    if ($company_name === '') $company_name = trim((string)($lead['company_name'] ?? ''));
    if ($company_name !== '') return $company_name;

    $contact_person = trim((string)($lead['contact_first'] ?? '') . ' ' . (string)($lead['contact_last'] ?? ''));
    return $contact_person !== '' ? $contact_person : $contact_name;
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
                throw new RuntimeException('This order reservation no longer matches the selected shop inventory location or quantities.');
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

/** Whether an order has a committed stock deduction for transport. */
function order_stock_was_dispatched($order_id, $tenant_id) {
    $row = fetch_one(
        "SELECT COUNT(*) movement_count
           FROM inventory_movements
          WHERE tenant_id = ? AND reference_id = ?
            AND reference_type = 'order_transport' AND movement_type = 'order_transport'",
        (int)$tenant_id,
        (int)$order_id
    );
    return (int)($row['movement_count'] ?? 0) > 0;
}

/**
 * Deduct an order's stock for transport. The caller must hold an open transaction.
 * The order, reservations, and inventory rows are locked before stock is changed.
 */
function dispatch_order_stock_for_transport($order_id, $tenant_id, $created_by = null) {
    global $conn;
    $order_id = (int)$order_id;
    $tenant_id = (int)$tenant_id;

    $order = fetch_one(
        "SELECT * FROM orders WHERE order_id = ? AND tenant_id = ? FOR UPDATE",
        $order_id,
        $tenant_id
    );
    if (!$order) throw new RuntimeException('Order not found.');
    if (($order['stage'] ?? 'shop') !== 'shop') {
        throw new RuntimeException('Only an order at the shop can be moved into transport.');
    }
    if (!empty($order['fulfilled_invoice_id'])) {
        throw new RuntimeException('This order already has a fulfilment invoice and cannot be dispatched again.');
    }
    if (in_array((string)$order['status'], ['cancelled', 'rejected', 'fulfilled'], true)) {
        throw new RuntimeException('This order is no longer eligible for transport.');
    }

    $warehouse_id = (int)($order['warehouse_id'] ?? 0);
    if ($warehouse_id <= 0) {
        throw new RuntimeException('Select a shop inventory location for this order before moving it into transport.');
    }
    $warehouse = fetch_one(
        "SELECT warehouse_id, name FROM warehouses
          WHERE warehouse_id = ? AND tenant_id = ? AND is_active = 1 FOR UPDATE",
        $warehouse_id,
        $tenant_id
    );
    if (!$warehouse) throw new RuntimeException('The order shop inventory location is no longer active.');
    if (order_stock_was_dispatched($order_id, $tenant_id)) {
        throw new RuntimeException('Stock for this order has already been deducted for transport.');
    }

    $items = fetch_all(
        "SELECT oi.order_item_id, oi.product_id, oi.quantity, p.name product_name,
                p.is_active, p.is_service
           FROM order_items oi
           LEFT JOIN products p ON p.product_id = oi.product_id AND p.tenant_id = oi.tenant_id
          WHERE oi.order_id = ? AND oi.tenant_id = ?
          ORDER BY oi.line_no
          FOR UPDATE",
        $order_id,
        $tenant_id
    );
    if (!$items) throw new RuntimeException('This order has no products to dispatch.');

    $needed = [];
    $expected_reservations = [];
    foreach ($items as $item) {
        if ($item['product_id'] === null) {
            throw new RuntimeException('An order item is not linked to a product and cannot be deducted from stock.');
        }
        if ((int)$item['is_active'] !== 1) {
            throw new RuntimeException('A product on this order is inactive and cannot be dispatched.');
        }
        if ((int)$item['is_service'] === 1) continue;

        $product_id = (int)$item['product_id'];
        $quantity = (float)$item['quantity'];
        if ($quantity <= 0) throw new RuntimeException('An order line has an invalid quantity.');
        if (!isset($needed[$product_id])) {
            $needed[$product_id] = [
                'quantity' => 0.0,
                'name' => (string)$item['product_name'],
            ];
        }
        $needed[$product_id]['quantity'] += $quantity;
        $expected_reservations[(int)$item['order_item_id']] = [
            'product_id' => $product_id,
            'quantity' => $quantity,
        ];
    }

    $reservation_history = order_reservation_rows($order_id, $tenant_id, null, true);
    $active_reservations = array_values(array_filter(
        $reservation_history,
        static function ($reservation) {
            return $reservation['status'] === 'active';
        }
    ));
    if ($reservation_history && count($active_reservations) !== count($expected_reservations)) {
        throw new RuntimeException('This order has incomplete or already-used stock reservations and cannot be dispatched.');
    }
    foreach ($active_reservations as $reservation) {
        $item_id = (int)$reservation['order_item_id'];
        if (!isset($expected_reservations[$item_id])
            || (int)$reservation['product_id'] !== $expected_reservations[$item_id]['product_id']
            || (int)$reservation['warehouse_id'] !== $warehouse_id
            || abs((float)$reservation['quantity'] - $expected_reservations[$item_id]['quantity']) > 0.0001) {
            throw new RuntimeException('The order stock reservation no longer matches its shop inventory location or quantities.');
        }
    }

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
        $reserved_by_others = reserved_stock_quantity(
            $tenant_id,
            (int)$product_id,
            $warehouse_id,
            $order_id
        );
        if (!$stock || $on_hand - $need['quantity'] + 0.0001 < $reserved_by_others) {
            throw new RuntimeException(
                'Insufficient available stock for ' . $need['name']
                . ' at ' . $warehouse['name'] . ' (requested: ' . $need['quantity']
                . ', available: ' . max(0, $on_hand - $reserved_by_others) . ').'
            );
        }
    }

    foreach ($needed as $product_id => $need) {
        $stmt = $conn->prepare(
            "UPDATE inventory_stock SET quantity = quantity - ?
              WHERE tenant_id = ? AND product_id = ? AND warehouse_id = ? AND quantity >= ?"
        );
        if (!$stmt) throw new RuntimeException($conn->error);
        $quantity = (float)$need['quantity'];
        $stmt->bind_param('diiid', $quantity, $tenant_id, $product_id, $warehouse_id, $quantity);
        if (!$stmt->execute()) {
            $error = $stmt->error;
            $stmt->close();
            throw new RuntimeException($error);
        }
        if ($stmt->affected_rows !== 1) {
            $stmt->close();
            throw new RuntimeException('Stock changed while the order was being dispatched. Reload and try again.');
        }
        $stmt->close();

        $stmt = $conn->prepare(
            "INSERT INTO inventory_movements
                (tenant_id, product_id, warehouse_id, movement_type, quantity, reference_id, reference_type, created_by, notes)
             VALUES (?, ?, ?, 'order_transport', ?, ?, 'order_transport', ?, ?)"
        );
        if (!$stmt) throw new RuntimeException($conn->error);
        $created_by_db = $created_by ? (int)$created_by : null;
        $notes = 'Stock deducted when order moved into transport';
        $stmt->bind_param('iiidiis', $tenant_id, $product_id, $warehouse_id, $quantity, $order_id, $created_by_db, $notes);
        if (!$stmt->execute()) {
            $error = $stmt->error;
            $stmt->close();
            throw new RuntimeException($error);
        }
        $stmt->close();
    }

    if ($active_reservations) {
        $stmt = $conn->prepare(
            "UPDATE order_stock_reservations
                SET status = 'consumed', consumed_at = NOW(), consumed_invoice_id = NULL
              WHERE order_id = ? AND tenant_id = ? AND status = 'active'"
        );
        if (!$stmt) throw new RuntimeException($conn->error);
        $stmt->bind_param('ii', $order_id, $tenant_id);
        if (!$stmt->execute()) {
            $error = $stmt->error;
            $stmt->close();
            throw new RuntimeException($error);
        }
        if ($stmt->affected_rows !== count($active_reservations)) {
            $stmt->close();
            throw new RuntimeException('The order reservation changed while stock was being dispatched.');
        }
        $stmt->close();
    }

    return count($needed);
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

/** Brands offered in the product and shop forms. Extend this list as new brands are added. */
function product_brand_options() {
    return ['GSP GROUP', 'TECHDOKTA INVESTMENT'];
}

/** Short display labels for the stored brand values. */
function product_brand_labels() {
    return [
        'GSP GROUP'          => 'GSP',
        'TECHDOKTA INVESTMENT' => 'TECHDOKTA',
    ];
}

/** Human label for a stored brand value. */
function brand_label($brand) {
    $brand = trim((string)$brand);
    if ($brand === '') return 'No brand';
    $labels = product_brand_labels();
    return $labels[$brand] ?? $brand;
}

/** The brand assigned to a shop, or '' when the shop sells every brand. */
function shop_brand($shop_id, $tenant_id = null) {
    global $current_user;
    $shop_id = (int)$shop_id;
    if ($shop_id <= 0) return '';
    $tenant_id = $tenant_id === null ? (int)$current_user->tenant_id : (int)$tenant_id;
    $row = fetch_one(
        "SELECT brand FROM shops WHERE shop_id = ? AND tenant_id = ? LIMIT 1",
        $shop_id,
        $tenant_id
    );
    return trim((string)($row['brand'] ?? ''));
}

/**
 * Brand scope for the signed-in user.
 *   null  = unrestricted (tenant administrator, or a shop with no brand)
 *   []    = no shop/brand access
 *   [...] = the brands sold by the user's shops
 */
function shop_brand_scope_for_user() {
    global $current_user;
    if (!$current_user) return [];
    if (tenant_admin_scope()) return null;
    $shops = accessible_shops(null, false);
    if (!$shops) return [];
    $brands = [];
    foreach ($shops as $shop) {
        $brand = trim((string)($shop['brand'] ?? ''));
        if ($brand === '') return null;
        $brands[$brand] = true;
    }
    return array_keys($brands);
}

/** Whether a lead/product brand list falls inside a brand scope. */
function brand_scope_matches($scope, array $brands) {
    $brands = array_values(array_filter(array_map(
        fn($b) => trim((string)$b),
        $brands
    ), fn($b) => $b !== ''));
    if ($scope === null) return true;
    if (!$brands) return true; // No brand information: keep it visible.
    if (!$scope) return false;
    return (bool)array_intersect($scope, $brands);
}

/** Whether a shop may sell/fulfil a set of product brands. */
function shop_can_fulfil_brands($shop_brand, array $brands) {
    $shop_brand = trim((string)$shop_brand);
    $brands = array_values(array_filter(array_map(
        fn($b) => trim((string)$b),
        $brands
    ), fn($b) => $b !== ''));
    if ($shop_brand === '') return true; // Unrestricted shop.
    if (!$brands) return true;           // No brand information.
    return in_array($shop_brand, $brands, true);
}

/** Distinct product brands requested on a lead (empty when unknown). */
function lead_product_brands($lead_id, $tenant_id = null) {
    global $current_user;
    $lead_id = (int)$lead_id;
    if ($lead_id <= 0) return [];
    $tenant_id = $tenant_id === null ? (int)$current_user->tenant_id : (int)$tenant_id;
    $rows = fetch_all(
        "SELECT DISTINCT p.brand
           FROM storefront_lead_request_items ri
           JOIN storefront_lead_requests r ON r.request_id = ri.request_id AND r.tenant_id = ri.tenant_id
           JOIN products p ON p.product_id = ri.product_id AND p.tenant_id = ri.tenant_id
          WHERE r.lead_id = ? AND r.tenant_id = ?
            AND p.brand IS NOT NULL AND p.brand <> ''",
        $lead_id,
        $tenant_id
    );
    return array_values(array_filter(array_map(
        fn($row) => trim((string)($row['brand'] ?? '')),
        $rows
    ), fn($brand) => $brand !== ''));
}

/**
 * Resolve how a sale should be categorised by brand.
 * A branded shop determines the brand; otherwise the brands of the sold
 * products are used, with "Mixed" when several brands appear.
 *
 * @return array{key:string,label:string}
 */
function sale_brand_info($shop_brand, $product_brands) {
    $shop_brand = trim((string)$shop_brand);
    if ($shop_brand !== '') {
        return ['key' => $shop_brand, 'label' => brand_label($shop_brand)];
    }
    if (is_string($product_brands)) {
        $product_brands = explode(',', $product_brands);
    }
    $brands = array_values(array_unique(array_filter(array_map(
        fn($b) => trim((string)$b),
        is_array($product_brands) ? $product_brands : []
    ), fn($b) => $b !== '')));
    if (count($brands) === 1) {
        return ['key' => $brands[0], 'label' => brand_label($brands[0])];
    }
    if (count($brands) > 1) {
        return ['key' => 'mixed', 'label' => 'Mixed'];
    }
    return ['key' => 'unbranded', 'label' => 'Unbranded'];
}

/**
 * Opening sales balance per shop, keyed by shop_id, with the shop's brand.
 */
function shop_opening_balances($tenant_id = null) {
    global $current_user;
    $tenant_id = $tenant_id === null
        ? (int)($current_user->tenant_id ?? 0)
        : (int)$tenant_id;
    if ($tenant_id <= 0) return [];
    $rows = fetch_all(
        "SELECT sob.shop_id, sob.opening_sales, sob.notes, sob.updated_by, sob.updated_at,
                s.name shop_name, s.code shop_code, s.brand shop_brand, s.is_active shop_is_active
           FROM shop_opening_balances sob
           JOIN shops s ON s.shop_id = sob.shop_id AND s.tenant_id = sob.tenant_id
          WHERE sob.tenant_id = ?
          ORDER BY s.name",
        $tenant_id
    );
    $out = [];
    foreach ($rows as $row) {
        $out[(int)$row['shop_id']] = [
            'shop_id' => (int)$row['shop_id'],
            'shop_name' => $row['shop_name'],
            'shop_code' => $row['shop_code'],
            'shop_brand' => $row['shop_brand'],
            'shop_is_active' => (int)$row['shop_is_active'],
            'opening_sales' => (float)($row['opening_sales'] ?? 0),
            'notes' => $row['notes'] ?? null,
            'updated_by' => $row['updated_by'] ?? null,
            'updated_at' => $row['updated_at'] ?? null,
        ];
    }
    return $out;
}

/** Opening sales for one shop. */
function shop_opening_sales($shop_id, $tenant_id = null) {
    $balances = shop_opening_balances($tenant_id);
    return (float)($balances[(int)$shop_id]['opening_sales'] ?? 0);
}

/** Total opening sales across all shops. */
function shop_opening_total($tenant_id = null) {
    $total = 0.0;
    foreach (shop_opening_balances($tenant_id) as $row) {
        $total += (float)($row['opening_sales'] ?? 0);
    }
    return $total;
}

/** Goods lifecycle statuses for a purchase record. */
function purchase_goods_statuses() {
    return [
        'ordered'        => 'Ordered',
        'in_production'  => 'In Production',
        'in_transport'   => 'In Transport',
        'arrived_godown' => 'Arrived at Godown',
        'cancelled'      => 'Cancelled',
    ];
}

/** Human label for a purchase goods status. */
function purchase_goods_status_label($status) {
    $statuses = purchase_goods_statuses();
    return $statuses[(string)$status] ?? ucwords(str_replace('_', ' ', (string)$status));
}

/** Bootstrap badge class for a purchase goods status. */
function purchase_goods_status_class($status) {
    $map = [
        'ordered'        => 'secondary',
        'in_production'  => 'warning',
        'in_transport'   => 'info',
        'arrived_godown' => 'success',
        'cancelled'      => 'navy',
    ];
    return $map[(string)$status] ?? 'secondary';
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
        'converted' => 'navy',
    ];
    return $map[$status] ?? 'secondary';
}

/** Whether the current user may see a quotation (tenant + store scope). */
function quotation_is_visible($quotation) {
    global $current_user;
    if (!$current_user || !$quotation) return false;
    if ((int)$quotation['tenant_id'] !== (int)$current_user->tenant_id) return false;
    if (tenant_admin_scope()) return true;
    $scope_ids = accessible_warehouse_ids(null, true, is_godown_manager());
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

    $store = find_accessible_store((int)$quotation['warehouse_id'], false, is_godown_manager());
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
            "SELECT invoice_prefix FROM system_settings WHERE tenant_id = ? FOR UPDATE",
            $tenant_id
        );
        $prefix = ($settings && !empty($settings['invoice_prefix'])) ? $settings['invoice_prefix'] : 'INV-';

        $last_inv = fetch_one(
            "SELECT invoice_number FROM invoices WHERE tenant_id = ? ORDER BY invoice_id DESC LIMIT 1 FOR UPDATE",
            $tenant_id
        );

        if (!$last_inv || empty($last_inv['invoice_number'])) {
            $next_no = 1001;
        } else {
            $numeric_part = (int)preg_replace('/[^0-9]/', '', $last_inv['invoice_number']);
            $next_no = $numeric_part + 1;
        }
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
