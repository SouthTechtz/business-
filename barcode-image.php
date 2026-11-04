<?php
require_once 'config.php';
require_once 'auth_check.php';

if (!can_manage_product_catalogue()) {
    http_response_code(403);
    exit('Forbidden');
}

require_once 'barcode-lib.php';

$value = trim((string)($_GET['code'] ?? ''));
if ($value === '' || mb_strlen($value) > 100) {
    http_response_code(400);
    exit('A barcode value is required.');
}

$svg = barcode_svg($value, 2, 70, true);
if ($svg === '') {
    http_response_code(422);
    exit('This value cannot be encoded as a Code 128 barcode.');
}

header('Content-Type: image/svg+xml; charset=utf-8');
header('Cache-Control: private, max-age=86400');
echo $svg;
