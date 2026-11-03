<?php
require_once 'config.php';
require_once 'auth_check.php';

header('Content-Type: application/json; charset=utf-8');

if (!can('manage_godown')) {
    http_response_code(403);
    echo json_encode(['products' => [], 'error' => 'You do not have permission to receive Godown stock.']);
    exit;
}

$query = trim((string)($_GET['q'] ?? ''));
if (mb_strlen($query) < 2) {
    echo json_encode(['products' => []]);
    exit;
}
if (mb_strlen($query) > 100) {
    http_response_code(400);
    echo json_encode(['products' => [], 'error' => 'Search text must be 100 characters or fewer.']);
    exit;
}

$like = '%' . $query . '%';
$products = fetch_all(
    "SELECT p.product_id, p.name, p.code, COALESCE(p.sku, '') sku,
            COALESCE(u.is_decimal, 0) is_decimal
       FROM products p
       LEFT JOIN units_of_measurement u ON u.unit_id = p.unit_id AND u.tenant_id = p.tenant_id
      WHERE p.tenant_id = ? AND p.is_active = 1 AND p.is_service = 0
        AND (p.name LIKE ? OR p.code LIKE ? OR COALESCE(p.sku, '') LIKE ?
             OR COALESCE(p.barcode, '') LIKE ?)
      ORDER BY p.name, p.product_id
      LIMIT 25",
    (int)$current_user->tenant_id,
    $like,
    $like,
    $like,
    $like
);

foreach ($products as &$product) {
    $product['product_id'] = (int)$product['product_id'];
    $product['is_decimal'] = (int)$product['is_decimal'];
}
unset($product);

echo json_encode(['products' => $products]);
