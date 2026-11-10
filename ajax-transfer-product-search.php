<?php
require_once 'config.php';
require_once 'auth_check.php';
require_permission_any(['request_godown_transfers', 'manage_godown']);

header('Content-Type: application/json; charset=utf-8');

function transfer_search_error(int $status, string $message): void
{
    http_response_code($status);
    echo json_encode(['products' => [], 'error' => $message]);
    exit;
}

$request_shop_id = (int)($_GET['request_shop_id'] ?? 0);
$request_store_id = (int)($_GET['request_store_id'] ?? 0);
$source_warehouse_id = (int)($_GET['source_warehouse_id'] ?? 0);
$query = trim((string)($_GET['q'] ?? ''));
$tenant_id = (int)$current_user->tenant_id;

if (($request_shop_id > 0) === ($request_store_id > 0)) {
    transfer_search_error(400, 'Choose one request destination.');
}

if ($request_shop_id > 0) {
    if (!shop_is_accessible($request_shop_id, false)) {
        transfer_search_error(403, 'You do not have access to this active Shop.');
    }
    $destination_warehouse_id = shop_inventory_warehouse_id($request_shop_id, $tenant_id);
    $destination = $destination_warehouse_id
        ? fetch_one(
            "SELECT w.warehouse_id, w.shop_id, w.location_type
               FROM warehouses w
               JOIN shops s ON s.shop_id = w.shop_id AND s.tenant_id = w.tenant_id
              WHERE w.warehouse_id = ? AND w.tenant_id = ? AND w.shop_id = ?
                AND w.location_type = 'shop' AND w.is_active = 1 AND s.is_active = 1",
            $destination_warehouse_id,
            $tenant_id,
            $request_shop_id
        )
        : null;
    if (!$destination) {
        transfer_search_error(403, 'This Shop has no active inventory warehouse.');
    }
} else {
    $destination = find_accessible_store($request_store_id, false, false);
    if (!$destination || (string)$destination['location_type'] !== 'store') {
        transfer_search_error(403, 'You do not have access to this active Store.');
    }
    $destination_warehouse_id = (int)$destination['warehouse_id'];
}

$source = fetch_one(
    "SELECT w.warehouse_id, w.shop_id, w.location_type
       FROM warehouses w
       LEFT JOIN shops s ON s.shop_id = w.shop_id AND s.tenant_id = w.tenant_id
      WHERE w.warehouse_id = ? AND w.tenant_id = ? AND w.is_active = 1
        AND w.location_type IN ('godown', 'store', 'shop')
        AND (w.location_type = 'godown' OR s.is_active = 1)",
    $source_warehouse_id,
    $tenant_id
);
if (
    !$source
    || (int)$source['warehouse_id'] === $destination_warehouse_id
    || ($request_shop_id > 0 && (int)$source['shop_id'] === $request_shop_id)
    || !stock_transfer_destination_allowed((string)$source['location_type'], (string)$destination['location_type'])
) {
    transfer_search_error(403, 'The supplying location is not active or is not allowed for this request.');
}

if (mb_strlen($query) < 2) {
    echo json_encode(['products' => []]);
    exit;
}

$like = '%' . $query . '%';
$transfer_brand = brand_scope_condition('p.brand');
$products = fetch_all(
    "SELECT p.product_id, p.name, p.code, COALESCE(u.is_decimal, 0) is_decimal,
            COALESCE(stock.quantity, 0) available_quantity
       FROM products p
       LEFT JOIN units_of_measurement u ON u.unit_id = p.unit_id AND u.tenant_id = p.tenant_id
       LEFT JOIN inventory_stock stock
         ON stock.tenant_id = p.tenant_id AND stock.product_id = p.product_id
        AND stock.warehouse_id = ?
      WHERE p.tenant_id = ? AND p.is_active = 1 AND p.is_service = 0{$transfer_brand['sql']}
        AND (p.name LIKE ? OR p.code LIKE ? OR COALESCE(p.sku, '') LIKE ?
             OR COALESCE(p.barcode, '') LIKE ?)
      ORDER BY p.name
      LIMIT 25",
    $source_warehouse_id,
    $tenant_id,
    ...array_merge($transfer_brand['params'], [$like, $like, $like, $like])
);

foreach ($products as &$product) {
    $product['product_id'] = (int)$product['product_id'];
    $product['is_decimal'] = (int)$product['is_decimal'];
    $product['available_quantity'] = (float)$product['available_quantity'];
    $product['available_label'] = format_quantity($product['available_quantity']);
}
unset($product);

echo json_encode(['products' => $products]);
