<?php
require_once __DIR__ . '/storefront.php';

$sf_tenant = storefront_tenant();
if (!$sf_tenant) {
    http_response_code(503);
    exit('The storefront is not configured.');
}
$sf_settings = storefront_settings($sf_tenant);
$sf_currency = storefront_currency($sf_tenant, $sf_settings);
$sf_tenant_id = (int)$sf_tenant['tenant_id'];
$sf_company = trim((string)($sf_settings['company_name'] ?? '')) ?: APP_NAME;
$sf_format = strtolower((string)($_GET['format'] ?? 'json'));
if (!in_array($sf_format, ['json', 'csv'], true)) $sf_format = 'json';

$sf_rows = fetch_all(
    storefront_product_select() . " WHERE p.tenant_id = ? AND p.is_active = 1 ORDER BY p.product_id",
    $sf_tenant_id
);

$sf_products = [];
foreach ($sf_rows as $sf_product) {
    $sf_name = trim((string)$sf_product['name']);
    $sf_description = trim(preg_replace('/\s+/', ' ', strip_tags((string)($sf_product['description'] ?? ''))));
    if ($sf_description === '') $sf_description = $sf_name;

    $sf_barcode = preg_replace('/\D+/', '', (string)($sf_product['barcode'] ?? ''));
    $sf_gtin = in_array(strlen($sf_barcode), [8, 12, 13, 14], true) ? $sf_barcode : '';
    $sf_mpn = trim((string)($sf_product['sku'] ?? '')) ?: trim((string)$sf_product['code']);

    $sf_url = storefront_absolute_url(storefront_product_url($sf_product));
    $sf_image = storefront_image_url($sf_product['image'] ?? '');

    $sf_products[] = [
        'id' => (string)$sf_product['product_id'],
        'title' => $sf_name,
        'description' => mb_substr($sf_description, 0, 5000),
        'link' => $sf_url,
        'image_link' => $sf_image,
        'price' => number_format((float)$sf_product['sale_price'], 2, '.', '') . ' ' . $sf_currency,
        'availability' => storefront_available($sf_product) ? 'in_stock' : 'out_of_stock',
        'brand' => '',
        'gtin' => $sf_gtin,
        'mpn' => $sf_mpn,
        'condition' => 'new',
        'category' => (string)($sf_product['category_name'] ?? ''),
        'product_type' => (string)($sf_product['category_name'] ?? ''),
        'store' => $sf_company,
    ];
}

if ($sf_format === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="product-feed.csv"');
    header('Cache-Control: no-store');
    $sf_out = fopen('php://output', 'w');
    fputcsv($sf_out, ['id', 'title', 'description', 'link', 'image_link', 'price', 'availability', 'brand', 'gtin', 'mpn', 'condition', 'category']);
    foreach ($sf_products as $sf_row) {
        fputcsv($sf_out, [
            $sf_row['id'],
            $sf_row['title'],
            $sf_row['description'],
            $sf_row['link'],
            $sf_row['image_link'],
            $sf_row['price'],
            $sf_row['availability'],
            $sf_row['brand'],
            $sf_row['gtin'],
            $sf_row['mpn'],
            $sf_row['condition'],
            $sf_row['category'],
        ]);
    }
    fclose($sf_out);
    exit;
}

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
echo json_encode([
    'generated_at' => gmdate('c'),
    'currency' => $sf_currency,
    'count' => count($sf_products),
    'products' => $sf_products,
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
