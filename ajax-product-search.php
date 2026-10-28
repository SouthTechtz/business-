<?php
// ============================================================
// AJAX product search — POS / product lists
// GET q=<term> (LIKE search across name, code, SKU, barcode, description)
// GET q=<term>&exact=1 (exact code/SKU/barcode match, case-insensitive)
// Returns JSON: { products: [...] }
// ============================================================
require_once 'config.php';
require_once 'auth_check.php';
require_permission_any(['manage_pos', 'manage_products']);

header('Content-Type: application/json; charset=utf-8');

$q = trim($_GET['q'] ?? '');
$exact = (int)($_GET['exact'] ?? 0) === 1;
$is_pos = (int)($_GET['pos'] ?? 0) === 1;

if ($is_pos) {
    // POS stock must come from the active session's fixed store.
    if (!can('manage_pos')) {
        http_response_code(403);
        echo json_encode(['products' => [], 'error' => 'POS access denied.']);
        exit;
    }
    $session = find_open_pos_session();
    $store = $session && (int)$session['warehouse_id'] > 0
        ? find_accessible_store((int)$session['warehouse_id'], false)
        : null;
    if (!$store) {
        http_response_code(403);
        echo json_encode(['products' => [], 'error' => 'Open a POS session first.']);
        exit;
    }
    $warehouse_id = (int)$store['warehouse_id'];
} else {
    $warehouse_id = (int)($_GET['warehouse_id'] ?? 0);
    $store = $warehouse_id > 0 ? find_accessible_store($warehouse_id, false) : null;
    if ($warehouse_id > 0 && !$store) {
        http_response_code(403);
        echo json_encode(['products' => [], 'error' => 'Store access denied.']);
        exit;
    }
}

$params = [];
if ($store) {
    $stock_expr = "GREATEST(0, COALESCE((SELECT s.quantity FROM inventory_stock s
                              WHERE s.product_id = p.product_id AND s.warehouse_id = ? AND s.tenant_id = p.tenant_id), 0)
                              - COALESCE((SELECT SUM(r.quantity) FROM order_stock_reservations r
                              WHERE r.tenant_id = p.tenant_id AND r.product_id = p.product_id
                                AND r.warehouse_id = ? AND r.status = 'active'), 0))";
    $params[] = (int)$store['warehouse_id'];
    $params[] = (int)$store['warehouse_id'];
} else {
    $stock_expr = "COALESCE((SELECT SUM(s.quantity) FROM inventory_stock s
                              JOIN warehouses sw ON sw.warehouse_id = s.warehouse_id AND sw.tenant_id = s.tenant_id
                              WHERE s.product_id = p.product_id AND s.tenant_id = p.tenant_id
                                AND sw.is_godown = 0";
    $scope_ids = accessible_warehouse_ids();
    if ($scope_ids !== null) {
        if (!$scope_ids) {
            $stock_expr .= " AND 1 = 0";
        } else {
            $stock_expr .= " AND s.warehouse_id IN (" . implode(',', array_map('intval', $scope_ids)) . ")";
        }
    }
    $stock_expr .= "), 0) - COALESCE((SELECT SUM(r.quantity) FROM order_stock_reservations r
                              WHERE r.tenant_id = p.tenant_id AND r.product_id = p.product_id
                                AND r.status = 'active'";
    if ($scope_ids !== null) {
        if (!$scope_ids) {
            $stock_expr .= " AND 1 = 0";
        } else {
            $stock_expr .= " AND r.warehouse_id IN (" . implode(',', array_map('intval', $scope_ids)) . ")";
        }
    }
    $stock_expr .= "), 0)";
    $stock_expr = "GREATEST(0, {$stock_expr})";
}

$sql = "SELECT p.product_id, p.name, p.code, p.sku, p.barcode, p.description, p.sale_price,
               p.min_stock_level, p.is_service,
               {$stock_expr} total_stock
        FROM products p
        WHERE p.tenant_id = ? AND p.is_active = 1";

$params[] = $current_user->tenant_id;

if ($exact && $q !== '') {
    $sql .= " AND (LOWER(p.code) = LOWER(?) OR LOWER(COALESCE(p.sku, '')) = LOWER(?) OR LOWER(COALESCE(p.barcode, '')) = LOWER(?))";
    $params[] = $q;
    $params[] = $q;
    $params[] = $q;
    $sql .= " ORDER BY p.name LIMIT 5";
} elseif ($q !== '') {
    $sql .= " AND (p.name LIKE ? OR p.code LIKE ? OR COALESCE(p.sku, '') LIKE ? OR COALESCE(p.barcode, '') LIKE ? OR COALESCE(p.description, '') LIKE ?)";
    $like = '%' . $q . '%';
    for ($i = 0; $i < 5; $i++) $params[] = $like;
    $sql .= " ORDER BY p.name LIMIT 100";
} else {
    $sql .= " ORDER BY p.name LIMIT 100";
}

$rows = fetch_all($sql, ...$params);

foreach ($rows as &$r) {
    $r['sale_price'] = (float)$r['sale_price'];
    $r['min_stock_level'] = (float)$r['min_stock_level'];
    $r['total_stock'] = (float)$r['total_stock'];
    $r['is_service'] = (int)$r['is_service'];
    $r['product_id'] = (int)$r['product_id'];
}
unset($r);

echo json_encode(['products' => $rows]);
