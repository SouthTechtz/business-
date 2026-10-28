<?php
require_once 'config.php';
require_once 'auth_check.php';
require_permission('manage_invoices');

$id = (int)($_GET['id'] ?? 0);
$invoice = fetch_one(
    "SELECT i.*, ds.name status_name, c.first_name, c.last_name, c.company_name, c.email, c.phone, c.address, c.tax_id
     FROM invoices i
     LEFT JOIN document_statuses ds ON ds.status_id = i.status_id
     LEFT JOIN contacts c ON c.contact_id = i.contact_id
     WHERE i.invoice_id = ? AND i.tenant_id = ?",
    $id, $current_user->tenant_id
);
if (!$invoice) {
    die('Invoice not found.');
}
if ((int)($invoice['warehouse_id'] ?? 0) > 0 && !store_is_accessible((int)$invoice['warehouse_id'], true)) {
    http_response_code(403);
    die('You do not have access to the store for this invoice.');
}

$items = fetch_all(
    "SELECT ii.*, p.code FROM invoice_items ii
     LEFT JOIN products p ON p.product_id = ii.product_id
     WHERE ii.invoice_id = ? ORDER BY ii.invoice_item_id",
    $id
);

$settings = fetch_one("SELECT * FROM system_settings WHERE tenant_id = ?", $current_user->tenant_id);
$company = $settings ?: ['company_name' => APP_NAME, 'company_email' => '', 'company_phone' => '', 'company_address' => ''];
$logo_path = (!empty($settings['logo_path']) && file_exists($settings['logo_path'])) ? $settings['logo_path'] : '';
$cur = tenant_currency();

$status_color = match ($invoice['status_name'] ?? '') {
    'Paid' => 'success',
    'Overdue' => 'danger',
    'Cancelled' => 'secondary',
    default => 'info',
};
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Invoice <?php echo e($invoice['invoice_number']); ?></title>
    <link rel="stylesheet" href="assets/css/bootstrap.min.css">
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body style="background:#e2e8f0;">
<div class="print-area mt-4 mb-4">
    <div class="no-print mb-3">
        <button class="btn btn-primary" onclick="window.print()"><i class="fa fa-print mr-1"></i> Print</button>
        <a href="invoice-details.php?id=<?php echo $id; ?>" class="btn btn-light">Back</a>
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
            <h4 class="mb-1">INVOICE</h4>
            <div><strong><?php echo e($invoice['invoice_number']); ?></strong></div>
            <div class="text-muted small">Issue Date: <?php echo e(date('M d, Y', strtotime($invoice['issue_date']))); ?></div>
            <div class="text-muted small">Due Date: <?php echo !empty($invoice['due_date']) && $invoice['due_date'] !== '0000-00-00' ? e(date('M d, Y', strtotime($invoice['due_date']))) : '—'; ?></div>
            <span class="badge badge-<?php echo $status_color; ?> mt-2"><?php echo e($invoice['status_name'] ?? 'Unknown'); ?></span>
        </div>
    </div>

    <div class="mb-4 p-3" style="background:#f8fafc;border-radius:10px;">
        <div class="small text-muted text-uppercase font-weight-bold mb-1">Bill To</div>
        <div class="font-weight-semibold"><?php echo e(trim(($invoice['first_name'] ?? '') . ' ' . ($invoice['last_name'] ?? '')) ?: $invoice['company_name'] ?: '—'); ?></div>
        <div class="small text-muted"><?php echo e($invoice['email'] ?? ''); ?></div>
        <div class="small text-muted"><?php echo e($invoice['phone'] ?? ''); ?></div>
        <div class="small text-muted"><?php echo e($invoice['address'] ?? ''); ?></div>
        <div class="small text-muted">Tax ID: <?php echo e($invoice['tax_id'] ?? '—'); ?></div>
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
                <td class="text-center"><?php echo e((float)$item['quantity']); ?></td>
                <td class="text-right"><?php echo e(money($item['unit_price'], $cur)); ?></td>
                <td class="text-right"><?php echo e(money($item['tax_amount'], $cur)); ?></td>
                <td class="text-right"><?php echo e(money($item['total'], $cur)); ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>

    <div class="d-flex justify-content-end">
        <div style="width:320px;">
            <div class="d-flex justify-content-between py-1"><span>Subtotal</span><span><?php echo e(money($invoice['subtotal'], $cur)); ?></span></div>
            <?php if ((float)$invoice['tax_amount'] > 0): ?>
            <div class="d-flex justify-content-between py-1"><span>Tax</span><span><?php echo e(money($invoice['tax_amount'], $cur)); ?></span></div>
            <?php endif; ?>
            <div class="d-flex justify-content-between py-1"><span>Total</span><strong><?php echo e(money($invoice['total'], $cur)); ?></strong></div>
            <div class="d-flex justify-content-between py-1"><span>Paid</span><span><?php echo e(money($invoice['amount_paid'], $cur)); ?></span></div>
            <div class="d-flex justify-content-between py-2 font-weight-bold" style="border-top:2px solid #e2e8f0;">
                <span>Balance Due</span><span><?php echo e(money($invoice['amount_due'], $cur)); ?></span>
            </div>
        </div>
    </div>

    <?php if ($invoice['notes']): ?>
        <div class="mt-4 small text-muted"><strong>Notes:</strong> <?php echo nl2br(e($invoice['notes'])); ?></div>
    <?php endif; ?>
    <?php if ($invoice['terms']): ?>
        <div class="mt-2 small text-muted"><strong>Terms:</strong> <?php echo nl2br(e($invoice['terms'])); ?></div>
    <?php endif; ?>
    <div class="text-center text-muted small mt-5">Thank you for your business!</div>
</div>
<script src="assets/js/jquery-3.2.1.min.js"></script>
<script src="assets/js/popper.min.js"></script>
<script src="assets/js/bootstrap.min.js"></script>
</body>
</html>
