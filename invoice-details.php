<?php
require_once 'header.php';
require_once 'auth_check.php';
require_permission('manage_invoices');
$page_title = 'Invoice Details';

$id = (int)($_GET['id'] ?? 0);
$invoice = fetch_one(
    "SELECT i.*, ds.name status_name, c.first_name, c.last_name, c.company_name, c.email, c.phone, c.address, c.tax_id,
            w.name store_name, s.name shop_name
     FROM invoices i
     LEFT JOIN document_statuses ds ON ds.status_id = i.status_id
     LEFT JOIN contacts c ON c.contact_id = i.contact_id
     LEFT JOIN warehouses w ON w.warehouse_id = i.warehouse_id
     LEFT JOIN shops s ON s.shop_id = w.shop_id
     WHERE i.invoice_id = ? AND i.tenant_id = ?",
    $id, $current_user->tenant_id
);
if (!$invoice) {
    flash_set('danger', 'Invoice not found.');
    redirect('invoices.php');
}
if ((int)($invoice['warehouse_id'] ?? 0) > 0 && !store_is_accessible((int)$invoice['warehouse_id'], true)) {
    flash_set('danger', 'You do not have access to the store for this invoice.');
    redirect('invoices.php');
}

$items = fetch_all(
    "SELECT ii.*, p.code FROM invoice_items ii
     LEFT JOIN products p ON p.product_id = ii.product_id
     WHERE ii.invoice_id = ? ORDER BY ii.invoice_item_id",
    $id
);

$payments = fetch_all(
    "SELECT p.*, pm.name method_name FROM payments p
     LEFT JOIN payment_methods pm ON pm.payment_method_id = p.payment_method_id
     WHERE p.invoice_id = ? AND p.tenant_id = ? ORDER BY p.payment_date",
    $id, $current_user->tenant_id
);

$settings = fetch_one("SELECT * FROM system_settings WHERE tenant_id = ?", $current_user->tenant_id);
$company = $settings ?: ['company_name' => APP_NAME, 'company_email' => '', 'company_phone' => '', 'company_address' => ''];

require_once 'navbar.php';
require_once 'sidebar.php';
$cur = tenant_currency();
?>

<div class="page-head">
    <div>
        <h1 class="page-title">Invoice Details</h1>
        <div class="page-sub"><?php echo e($invoice['invoice_number']); ?><?php if (!empty($invoice['store_name'])): ?> · Store: <strong><?php echo e(trim(($invoice['shop_name'] ?? '') . ' / ' . $invoice['store_name'], ' /')); ?></strong><?php endif; ?></div>
    </div>
    <div>
        <?php if ((float)$invoice['amount_due'] > 0 && can('manage_receipts')): ?>
            <a href="payment-add.php?invoice=<?php echo $id; ?>" class="btn btn-success"><i class="fa fa-money mr-1"></i> Record Payment</a>
        <?php endif; ?>
        <a href="invoice-print.php?id=<?php echo $id; ?>" class="btn btn-outline-primary"><i class="fa fa-print mr-1"></i> Print</a>
        <a href="invoices.php" class="btn btn-light"><i class="fa fa-arrow-left mr-1"></i> Back</a>
    </div>
</div>

<?php if ($f = flash_get()): ?>
    <div class="alert alert-<?php echo e($f['type']); ?>"><?php echo e($f['message']); ?></div>
<?php endif; ?>

<div class="row">
    <div class="col-lg-8 mb-3">
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h5 class="card-title">Invoice Items</h5>
                <span class="badge badge-info"><?php echo e($invoice['status_name'] ?? 'Unknown'); ?></span>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table mb-0">
                        <thead>
                            <tr><th>#</th><th>Item</th><th class="text-center">Qty</th><th class="text-right">Unit Price</th><th class="text-right">Tax</th><th class="text-right">Total</th></tr>
                        </thead>
                        <tbody>
                        <?php foreach ($items as $i => $item): ?>
                            <tr>
                                <td><?php echo $i + 1; ?></td>
                                <td>
                                    <?php echo e($item['description']); ?>
                                    <?php if ($item['code']): ?><small class="text-muted d-block"><?php echo e($item['code']); ?></small><?php endif; ?>
                                </td>
                                <td class="text-center"><?php echo e((float)$item['quantity']); ?></td>
                                <td class="text-right"><?php echo e(money($item['unit_price'], $cur)); ?></td>
                                <td class="text-right"><?php echo e(money($item['tax_amount'], $cur)); ?></td>
                                <td class="text-right font-weight-semibold"><?php echo e(money($item['total'], $cur)); ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <div class="card-footer">
                <div class="d-flex justify-content-end">
                    <div style="width:320px;">
                        <div class="cart-total-row"><span>Subtotal</span><span><?php echo e(money($invoice['subtotal'], $cur)); ?></span></div>
                        <?php if ((float)$invoice['tax_amount'] > 0): ?>
                        <div class="cart-total-row"><span>Tax</span><span><?php echo e(money($invoice['tax_amount'], $cur)); ?></span></div>
                        <?php endif; ?>
                        <div class="cart-total-row"><span>Total</span><span class="font-weight-semibold"><?php echo e(money($invoice['total'], $cur)); ?></span></div>
                        <div class="cart-total-row"><span>Paid</span><span class="text-success"><?php echo e(money($invoice['amount_paid'], $cur)); ?></span></div>
                        <div class="cart-total-row cart-grand"><span>Balance Due</span><span><?php echo e(money($invoice['amount_due'], $cur)); ?></span></div>
                    </div>
                </div>
            </div>
        </div>
        <div class="card">
            <div class="card-header"><h5 class="card-title">Payment History</h5></div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table mb-0">
                        <thead><tr><th>Date</th><th>Method</th><th class="text-right">Amount</th></tr></thead>
                        <tbody>
                        <?php if (!$payments): ?>
                            <tr><td colspan="3" class="text-center text-muted py-4">No payments recorded yet.</td></tr>
                        <?php else: foreach ($payments as $pmt): ?>
                            <tr>
                                <td><?php echo e(date('M d, Y H:i', strtotime($pmt['payment_date']))); ?></td>
                                <td><?php echo e($pmt['method_name'] ?? '—'); ?></td>
                                <td class="text-right font-weight-semibold text-success"><?php echo e(money($pmt['amount'], $cur)); ?></td>
                            </tr>
                        <?php endforeach; endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
    <div class="col-lg-4 mb-3">
        <div class="card">
            <div class="card-header"><h5 class="card-title">Invoice Information</h5></div>
            <div class="card-body">
                <table class="table">
                    <tbody>
                        <tr><th>Invoice #</th><td><?php echo e($invoice['invoice_number']); ?></td></tr>
                        <tr><th>Issue Date</th><td><?php echo e(date('M d, Y', strtotime($invoice['issue_date']))); ?></td></tr>
                        <tr><th>Due Date</th><td><?php echo !empty($invoice['due_date']) && $invoice['due_date'] !== '0000-00-00' ? e(date('M d, Y', strtotime($invoice['due_date']))) : '—'; ?></td></tr>
                        <tr><th>Status</th><td><span class="badge badge-info"><?php echo e($invoice['status_name'] ?? 'Unknown'); ?></span></td></tr>
                        <tr><th>Reference</th><td><?php echo e($invoice['reference'] ?: '—'); ?></td></tr>
                        <tr><th>Terms</th><td><?php echo e($invoice['terms'] ?: '—'); ?></td></tr>
                        <tr><th>Notes</th><td><?php echo e($invoice['notes'] ?: '—'); ?></td></tr>
                    </tbody>
                </table>
            </div>
        </div>
        <div class="card">
            <div class="card-header"><h5 class="card-title">Customer</h5></div>
            <div class="card-body">
                <div class="font-weight-semibold"><?php echo e(trim(($invoice['first_name'] ?? '') . ' ' . ($invoice['last_name'] ?? '')) ?: $invoice['company_name'] ?: '—'); ?></div>
                <div class="text-muted small"><?php echo e($invoice['email'] ?? ''); ?></div>
                <div class="text-muted small"><?php echo e($invoice['phone'] ?? ''); ?></div>
                <div class="text-muted small"><?php echo e($invoice['address'] ?? ''); ?></div>
            </div>
        </div>
    </div>
</div>

<?php require_once 'footer.php'; ?>
