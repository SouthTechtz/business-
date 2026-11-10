<?php
require_once 'config.php';
require_once 'auth_check.php';
require_product_catalogue_access();
require_once 'barcode-lib.php';

$id = (int)($_GET['id'] ?? $_GET['product_id'] ?? 0);
$product = fetch_one(
    "SELECT p.*, c.name AS category_name
       FROM products p
       LEFT JOIN product_categories c ON c.category_id = p.category_id
      WHERE p.product_id = ? AND p.tenant_id = ?",
    $id,
    $current_user->tenant_id
);
if (!$product) {
    http_response_code(404);
    die('Product not found.');
}
if (!brand_scope_allows($product['brand'] ?? '')) {
    http_response_code(403);
    die('This product belongs to another brand.');
}

$labels = (int)($_GET['labels'] ?? 1);
$labels = max(1, min(60, $labels));

$barcode_value = trim((string)($product['barcode'] ?? ''));
if ($barcode_value === '') $barcode_value = (string)$product['code'];
$barcode_svg = barcode_svg($barcode_value, 2, 62, true);

$settings = fetch_one("SELECT * FROM system_settings WHERE tenant_id = ?", $current_user->tenant_id);
$company_name = trim((string)($settings['company_name'] ?? '')) ?: system_title($current_user->tenant_id);
$company_logo = system_logo_path();
$currency = tenant_currency();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Barcode &middot; <?php echo e($product['name']); ?></title>
    <link rel="stylesheet" href="assets/css/bootstrap.min.css">
    <link rel="stylesheet" href="assets/css/font-awesome.min.css">
    <link rel="stylesheet" href="assets/css/style.css">
    <style>
        .barcode-sheet {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 12px;
        }
        .barcode-company-header {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 12px;
        }
        .barcode-company-header img { max-height: 50px; max-width: 150px; object-fit: contain; }
        .barcode-company-header strong { font-size: 15px; color: #171141; }
        .barcode-label {
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 4px;
            padding: 10mm 6mm 6mm;
            border: 1px dashed #c3c9d1;
            border-radius: 6px;
            background: #fff;
            text-align: center;
            page-break-inside: avoid;
            break-inside: avoid;
        }
        .barcode-label .bl-company {
            font-size: 9px;
            font-weight: 700;
            letter-spacing: .08em;
            text-transform: uppercase;
            color: #6b7280;
        }
        .barcode-label .bl-name {
            width: 100%;
            font-size: 13px;
            font-weight: 700;
            color: #111827;
            line-height: 1.3;
            overflow: hidden;
            text-overflow: ellipsis;
            display: -webkit-box;
            -webkit-line-clamp: 2;
            -webkit-box-orient: vertical;
        }
        .barcode-label .bl-barcode {
            width: 100%;
            max-width: 62mm;
            margin: 2px auto;
        }
        .barcode-label .bl-barcode svg { display: block; width: 100%; height: auto; }
        .barcode-label .bl-price { font-size: 11px; color: #374151; }
        .barcode-label .bl-category { font-size: 9px; color: #6b7280; }
        @page { margin: 8mm; }
        @media print {
            body { background: #fff; }
            .barcode-sheet { gap: 6mm; }
            .barcode-label { border-color: #e5e7eb; padding: 4mm 4mm 3mm; }
        }
        @media (max-width: 600px) {
            .barcode-sheet { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body style="background:#e2e8f0;">
<div class="print-area mt-4 mb-4">
    <div class="barcode-company-header">
        <?php if ($company_logo !== ''): ?>
            <img src="<?php echo e($company_logo); ?>" alt="">
        <?php endif; ?>
        <strong><?php echo e($company_name); ?></strong>
    </div>
    <div class="no-print mb-3 d-flex flex-wrap align-items-center" style="gap:10px;">
        <button class="btn btn-primary" onclick="window.print()"><i class="fa fa-print mr-1"></i> Print barcode</button>
        <form method="get" action="product-barcode.php" class="form-inline" style="gap:8px;margin:0;">
            <input type="hidden" name="id" value="<?php echo (int)$product['product_id']; ?>">
            <label class="mr-1 small mb-0" for="barcode-labels">Labels</label>
            <input type="number" id="barcode-labels" name="labels" class="form-control form-control-sm"
                   min="1" max="60" value="<?php echo (int)$labels; ?>" style="width:80px;">
            <button type="submit" class="btn btn-light btn-sm">Update</button>
        </form>
        <a href="product-view.php?id=<?php echo (int)$product['product_id']; ?>" class="btn btn-light">Back to product</a>
        <a href="products.php" class="btn btn-light">All products</a>
    </div>

    <?php if ($barcode_svg === ''): ?>
        <div class="alert alert-warning">
            The value <strong><?php echo e($barcode_value); ?></strong> cannot be encoded as a Code 128 barcode.
            Use printable letters, numbers and dashes only.
        </div>
    <?php else: ?>
        <div class="barcode-sheet">
            <?php for ($i = 0; $i < $labels; $i++): ?>
                <div class="barcode-label">
                    <span class="bl-company"><?php echo e($company_name); ?></span>
                    <span class="bl-name"><?php echo e($product['name']); ?></span>
                    <div class="bl-barcode"><?php echo $barcode_svg; ?></div>
                    <span class="bl-price"><?php echo e(money($product['sale_price'], $currency)); ?></span>
                    <?php if (!empty($product['category_name'])): ?>
                        <span class="bl-category"><?php echo e($product['category_name']); ?></span>
                    <?php endif; ?>
                </div>
            <?php endfor; ?>
        </div>
    <?php endif; ?>
</div>
</body>
</html>
