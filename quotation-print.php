<?php
require_once 'config.php';
require_once 'auth_check.php';
require_permission('manage_invoices');

$tenant_id = (int)$current_user->tenant_id;
$id = (int)($_GET['id'] ?? 0);
$quotation = fetch_one(
    "SELECT q.*, c.first_name, c.last_name, c.company_name, c.email, c.phone, c.address, c.tax_id,
            w.name store_name, w.code store_code, s.name shop_name
       FROM quotations q
       LEFT JOIN contacts c ON c.contact_id = q.contact_id
       LEFT JOIN warehouses w ON w.warehouse_id = q.warehouse_id
       LEFT JOIN shops s ON s.shop_id = w.shop_id
      WHERE q.quotation_id = ? AND q.tenant_id = ?",
    $id,
    $tenant_id
);
if (!$quotation) {
    die('Quotation not found.');
}
if (!quotation_is_visible($quotation)) {
    http_response_code(403);
    die('You do not have access to the store for this quotation.');
}

$items = fetch_all(
    "SELECT qi.*, p.code FROM quotation_items qi
      LEFT JOIN products p ON p.product_id = qi.product_id AND p.tenant_id = qi.tenant_id
      WHERE qi.quotation_id = ? AND qi.tenant_id = ?
      ORDER BY qi.quotation_item_id",
    $id,
    $tenant_id
);

$settings = fetch_one("SELECT * FROM system_settings WHERE tenant_id = ?", $tenant_id);
$company = $settings ?: ['company_name' => APP_NAME, 'company_email' => '', 'company_phone' => '', 'company_address' => ''];
$logo_path = (!empty($settings['logo_path']) && file_exists($settings['logo_path'])) ? $settings['logo_path'] : '';
$cur = tenant_currency();

$display_status = (string)$quotation['status'];
if ($display_status !== 'converted' && !empty($quotation['valid_until']) && $quotation['valid_until'] < date('Y-m-d')) {
    $display_status = 'expired';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Quotation <?php echo e($quotation['quotation_number']); ?></title>
    <link rel="stylesheet" href="assets/css/bootstrap.min.css">
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body style="background:#e2e8f0;">
<div class="print-area mt-4 mb-4">
    <div class="no-print mb-3">
        <button class="btn btn-primary" onclick="window.print()"><i class="fa fa-print mr-1"></i> Print</button>
        <a href="quotation-download.php?id=<?php echo $id; ?>" class="btn btn-outline-success"><i class="fa fa-download mr-1"></i> Download PDF</a>
        <a href="quotation-details.php?id=<?php echo $id; ?>" class="btn btn-light">Back</a>
    </div>
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div class="d-flex align-items-center" style="gap:18px;">
            <?php if ($logo_path): ?>
                <img src="<?php echo e($logo_path); ?>" alt="Company logo" style="max-height:64px;max-width:150px;object-fit:contain;">
            <?php endif; ?>
            <div>
                <h3 class="mb-1" style="color:var(--primary)"><?php echo e($company['company_name'] ?? APP_NAME); ?></h3>
                <?php if (!empty($company['company_address'])): ?><div class="small"><?php echo e($company['company_address']); ?></div><?php endif; ?>
                <?php if (!empty($company['company_email'])): ?><div class="small"><?php echo e($company['company_email']); ?></div><?php endif; ?>
                <?php if (!empty($company['company_phone'])): ?><div class="small"><?php echo e($company['company_phone']); ?></div><?php endif; ?>
            </div>
        </div>
        <div class="text-right">
            <h4 class="mb-1">QUOTATION</h4>
            <div><strong><?php echo e($quotation['quotation_number']); ?></strong></div>
            <div class="text-muted small">Issue Date: <?php echo e(date('M d, Y', strtotime($quotation['issue_date']))); ?></div>
            <div class="text-muted small">Valid Until: <?php echo !empty($quotation['valid_until']) ? e(date('M d, Y', strtotime($quotation['valid_until']))) : '—'; ?></div>
            <span class="badge badge-<?php echo e(quotation_status_class($display_status)); ?> mt-2"><?php echo e(quotation_status_label($display_status)); ?></span>
        </div>
    </div>

    <div class="mb-4 p-3" style="background:#f8fafc;border-radius:10px;">
        <div class="small text-muted text-uppercase font-weight-bold mb-1">Prepared For</div>
        <div class="font-weight-semibold"><?php echo e(trim(($quotation['first_name'] ?? '') . ' ' . ($quotation['last_name'] ?? '')) ?: ($quotation['company_name'] ?? '—')); ?></div>
        <div class="small text-muted"><?php echo e($quotation['email'] ?? ''); ?></div>
        <div class="small text-muted"><?php echo e($quotation['phone'] ?? ''); ?></div>
        <div class="small text-muted"><?php echo e($quotation['address'] ?? ''); ?></div>
        <?php if (!empty($quotation['store_name'])): ?>
            <div class="small text-muted">Store: <?php echo e(trim(($quotation['shop_name'] ?? '') . ' / ' . $quotation['store_name'], ' /')); ?></div>
        <?php endif; ?>
    </div>

    <table class="table table-bordered">
        <thead>
            <tr>
                <th>#</th><th>Item</th><th class="text-center">Qty</th><th class="text-right">Unit Price</th><th class="text-right">Tax</th><th class="text-right">Total</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($items as $i => $item): ?>
            <tr>
                <td><?php echo $i + 1; ?></td>
                <td><?php echo e($item['description']); ?></td>
                <td class="text-center"><?php echo e(format_quantity($item['quantity'])); ?></td>
                <td class="text-right"><?php echo e(money($item['unit_price'], $cur)); ?></td>
                <td class="text-right"><?php echo e(money($item['tax_amount'], $cur)); ?></td>
                <td class="text-right"><?php echo e(money($item['total'], $cur)); ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>

    <div class="d-flex justify-content-end">
        <div style="width:320px;">
            <div class="d-flex justify-content-between py-1"><span>Subtotal</span><span><?php echo e(money($quotation['subtotal'], $cur)); ?></span></div>
            <?php if ((float)$quotation['tax_amount'] > 0): ?>
            <div class="d-flex justify-content-between py-1"><span>Tax</span><span><?php echo e(money($quotation['tax_amount'], $cur)); ?></span></div>
            <?php endif; ?>
            <div class="d-flex justify-content-between py-2 font-weight-bold" style="border-top:2px solid #e2e8f0;">
                <span>Total</span><span><?php echo e(money($quotation['total'], $cur)); ?></span>
            </div>
        </div>
    </div>

    <?php if ($quotation['notes']): ?>
        <div class="mt-4 small text-muted"><strong>Notes:</strong> <?php echo nl2br(e($quotation['notes'])); ?></div>
    <?php endif; ?>
    <?php if ($quotation['terms']): ?>
        <div class="mt-2 small text-muted"><strong>Terms:</strong> <?php echo nl2br(e($quotation['terms'])); ?></div>
    <?php endif; ?>
    <div class="text-center text-muted small mt-5">This quotation is an offer and does not reserve stock. Prices are valid until the date shown above.</div>
</div>
<script src="assets/js/jquery-3.2.1.min.js"></script>
<script src="assets/js/popper.min.js"></script>
<script src="assets/js/bootstrap.min.js"></script>
</body>
</html>
