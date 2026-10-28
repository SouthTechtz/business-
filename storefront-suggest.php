<?php
require_once __DIR__ . '/storefront.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: public, max-age=30');

$sf_tenant = storefront_tenant();
if (!$sf_tenant) {
    http_response_code(503);
    echo json_encode(['items' => []]);
    exit;
}

$sf_query = trim((string)($_GET['q'] ?? ''));
if (mb_strlen($sf_query) > 100) $sf_query = mb_substr($sf_query, 0, 100);
$sf_currency = storefront_currency($sf_tenant, storefront_settings($sf_tenant));

$sf_items = [];
if (mb_strlen($sf_query) >= 2) {
    $sf_tenant_id = (int)$sf_tenant['tenant_id'];
    foreach (storefront_catalogue($sf_tenant_id, ['q' => $sf_query], 6, 0) as $sf_product) {
        $sf_category = trim((string)($sf_product['category_name'] ?? ''));
        $sf_stock = storefront_available($sf_product) ? 'In stock' : 'Out of stock';
        $sf_meta = trim($sf_category !== '' ? $sf_category . ' · ' . $sf_stock : $sf_stock);
        $sf_items[] = [
            'name' => (string)$sf_product['name'],
            'url' => storefront_product_url($sf_product),
            'image' => storefront_image_path($sf_product['image'] ?? ''),
            'meta' => $sf_meta,
            'price' => money($sf_product['sale_price'], $sf_currency),
        ];
    }
}

echo json_encode(['items' => $sf_items], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
