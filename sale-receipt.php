<?php
require_once 'config.php';
require_once 'auth_check.php';
require_permission('manage_pos');

$id = (int)($_GET['id'] ?? 0);
$sale = fetch_one(
    "SELECT o.*, c.first_name, c.last_name, c.company_name, c.phone, c.email,
            u.first_name cashier_first, u.last_name cashier_last,
            w.name store_name, s.name shop_name
       FROM pos_orders o
       LEFT JOIN contacts c ON c.contact_id = o.contact_id
       LEFT JOIN users u ON u.user_id = o.created_by
       LEFT JOIN warehouses w ON w.warehouse_id = o.warehouse_id
       LEFT JOIN shops s ON s.shop_id = w.shop_id
      WHERE o.order_id = ? AND o.tenant_id = ?",
    $id,
    $current_user->tenant_id
);
if (!$sale) {
    http_response_code(404);
    die('Sale not found.');
}
if ((int)($sale['warehouse_id'] ?? 0) > 0 && !store_is_accessible((int)$sale['warehouse_id'], true)) {
    http_response_code(403);
    die('You do not have access to the store for this sale.');
}

$items = fetch_all(
    "SELECT oi.*, p.code FROM pos_order_items oi
       LEFT JOIN products p ON p.product_id = oi.product_id
      WHERE oi.order_id = ? ORDER BY oi.order_item_id",
    $id
);
$payments = fetch_all(
    "SELECT pp.*, pm.name method_name FROM pos_payments pp
       LEFT JOIN payment_methods pm ON pm.payment_method_id = pp.payment_method_id
      WHERE pp.order_id = ?
      ORDER BY pp.payment_id",
    $id
);
$paid = 0.0;
foreach ($payments as $payment) $paid += (float)$payment['amount'];
$balance = max(0, round((float)$sale['total'] - $paid, 2));

$settings = fetch_one("SELECT * FROM system_settings WHERE tenant_id = ?", $current_user->tenant_id);
$company = $settings ?: [];
$company_name = trim((string)($company['company_name'] ?? '')) ?: system_title($current_user->tenant_id);
$company_logo = system_logo_path();
$currency = tenant_currency();
$customer = $sale['contact_id']
    ? (trim((string)($sale['first_name'] ?? '') . ' ' . (string)($sale['last_name'] ?? '')) ?: (string)$sale['company_name'])
    : 'Walk-in Customer';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Receipt <?php echo e($sale['order_number']); ?></title>
    <link rel="stylesheet" href="assets/css/bootstrap.min.css">
    <link rel="stylesheet" href="assets/css/font-awesome.min.css">
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body style="background:#e2e8f0;">
<div class="print-area mt-4 mb-4" style="max-width:430px;margin-left:auto;margin-right:auto;">
    <div class="no-print mb-3 text-center">
        <button class="btn btn-primary" onclick="window.print()"><i class="fa fa-print mr-1"></i> Print receipt</button>
        <a href="sale-details.php?id=<?php echo $id; ?>" class="btn btn-light">Back to sale</a>
    </div>

    <div class="card">
        <div class="card-body">
            <div class="text-center mb-3">
                <?php if ($company_logo !== ''): ?>
                    <img src="<?php echo e($company_logo); ?>" alt="" style="max-height:60px;max-width:170px;object-fit:contain;margin-bottom:6px;">
                <?php endif; ?>
                <h4 class="mb-1"><?php echo e($company_name); ?></h4>
                <?php if (!empty($company['company_address'])): ?><div class="small text-muted"><?php echo e($company['company_address']); ?></div><?php endif; ?>
                <?php if (!empty($company['company_phone'])): ?><div class="small text-muted"><?php echo e($company['company_phone']); ?></div><?php endif; ?>
                <div class="mt-2 font-weight-bold">SALES RECEIPT</div>
            </div>

            <div class="d-flex justify-content-between small mb-1"><span>Receipt #</span><strong><?php echo e($sale['order_number']); ?></strong></div>
            <div class="d-flex justify-content-between small mb-1"><span>Date</span><span><?php echo e(date('M d, Y H:i', strtotime($sale['order_date']))); ?></span></div>
            <div class="d-flex justify-content-between small mb-1"><span>Customer</span><span><?php echo e($customer); ?></span></div>
            <?php if (!empty($sale['store_name'])): ?>
                <div class="d-flex justify-content-between small mb-1"><span>Location</span><span><?php echo e(trim(($sale['shop_name'] ?? '') . ' / ' . $sale['store_name'], ' /')); ?></span></div>
            <?php endif; ?>
            <div class="d-flex justify-content-between small mb-3"><span>Cashier</span><span><?php echo e(trim((string)($sale['cashier_first'] ?? '') . ' ' . (string)($sale['cashier_last'] ?? ''))); ?></span></div>

            <table class="table table-sm">
                <thead>
                    <tr><th>Item</th><th class="text-center">Qty</th><th class="text-right">Amount</th></tr>
                </thead>
                <tbody>
                    <?php foreach ($items as $item): ?>
                        <tr>
                            <td>
                                <?php echo e($item['description'] ?: $item['code']); ?>
                                <div class="small text-muted"><?php echo e(money($item['unit_price'], $currency)); ?> each</div>
                            </td>
                            <td class="text-center"><?php echo e(format_quantity($item['quantity'])); ?></td>
                            <td class="text-right"><?php echo e(money($item['total'], $currency)); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

            <div class="d-flex justify-content-between small"><span>Subtotal</span><span><?php echo e(money($sale['subtotal'], $currency)); ?></span></div>
            <?php if ((float)$sale['tax_amount'] > 0): ?>
                <div class="d-flex justify-content-between small"><span>Tax</span><span><?php echo e(money($sale['tax_amount'], $currency)); ?></span></div>
            <?php endif; ?>
            <?php if ((float)$sale['discount'] > 0): ?>
                <div class="d-flex justify-content-between small text-danger"><span>Discount</span><span>- <?php echo e(money($sale['discount'], $currency)); ?></span></div>
            <?php endif; ?>
            <div class="d-flex justify-content-between font-weight-bold border-top mt-2 pt-2"><span>Total</span><span><?php echo e(money($sale['total'], $currency)); ?></span></div>
            <div class="d-flex justify-content-between small text-success"><span>Paid</span><span><?php echo e(money($paid, $currency)); ?></span></div>
            <?php if ($balance > 0.004): ?>
                <div class="d-flex justify-content-between small text-danger"><span>Balance due</span><span><?php echo e(money($balance, $currency)); ?></span></div>
            <?php endif; ?>

            <?php if ($payments): ?>
                <div class="small text-muted mt-2">Payment method: <?php echo e(implode(', ', array_filter(array_map(fn($p) => $p['method_name'], $payments)))); ?></div>
            <?php endif; ?>

            <div class="text-center small text-muted mt-4">Thank you for your business.</div>
        </div>
    </div>
</div>
</body>
</html>
