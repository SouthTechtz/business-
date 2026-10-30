<?php
require_once 'header.php';
require_once 'auth_check.php';
require_permission('manage_invoices');
$page_title = 'Quotation Details';

$tenant_id = (int)$current_user->tenant_id;
$id = (int)($_GET['id'] ?? $_POST['quotation_id'] ?? 0);
if ($id <= 0) {
    flash_set('danger', 'Quotation not found.');
    redirect('quotations.php');
}

$quotation = fetch_one(
    "SELECT q.*, c.first_name, c.last_name, c.company_name, c.email, c.phone, c.address, c.tax_id,
            w.name store_name, w.code store_code, s.name shop_name,
            i.invoice_number converted_invoice_number
       FROM quotations q
       LEFT JOIN contacts c ON c.contact_id = q.contact_id
       LEFT JOIN warehouses w ON w.warehouse_id = q.warehouse_id
       LEFT JOIN shops s ON s.shop_id = w.shop_id
       LEFT JOIN invoices i ON i.invoice_id = q.converted_invoice_id
      WHERE q.quotation_id = ? AND q.tenant_id = ?",
    $id,
    $tenant_id
);
if (!$quotation) {
    flash_set('danger', 'Quotation not found.');
    redirect('quotations.php');
}
if (!quotation_is_visible($quotation)) {
    flash_set('danger', 'You do not have access to the store for this quotation.');
    redirect('quotations.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'convert') {
    verify_csrf();
    $conn->begin_transaction();
    try {
        $invoice_id = convert_quotation_to_invoice($id, $tenant_id);
        $conn->commit();
        audit_log('convert', 'quotation', $id, 'Quotation ' . $quotation['quotation_number'] . ' converted to invoice #' . $invoice_id);
        flash_set('success', 'Quotation converted into invoice #' . $invoice_id . '.');
        redirect('invoice-details.php?id=' . $invoice_id);
    } catch (Throwable $ex) {
        $conn->rollback();
        flash_set('danger', 'Could not convert the quotation: ' . $ex->getMessage());
        redirect('quotation-details.php?id=' . $id);
    }
}

$items = fetch_all(
    "SELECT qi.*, p.code FROM quotation_items qi
      LEFT JOIN products p ON p.product_id = qi.product_id AND p.tenant_id = qi.tenant_id
      WHERE qi.quotation_id = ? AND qi.tenant_id = ?
      ORDER BY qi.quotation_item_id",
    $id,
    $tenant_id
);

$display_status = (string)$quotation['status'];
if ($display_status !== 'converted' && !empty($quotation['valid_until']) && $quotation['valid_until'] < date('Y-m-d')) {
    $display_status = 'expired';
}
$can_convert = empty($quotation['converted_invoice_id']) && $display_status !== 'rejected';

require_once 'navbar.php';
require_once 'sidebar.php';
$cur = tenant_currency();
?>

<div class="page-head">
    <div>
        <h1 class="page-title">Quotation <?php echo e($quotation['quotation_number']); ?></h1>
        <div class="page-sub">
            <?php echo e(trim(($quotation['first_name'] ?? '') . ' ' . ($quotation['last_name'] ?? '')) ?: ($quotation['company_name'] ?? 'Customer')); ?>
            &middot; <?php echo e($quotation['store_name'] ? trim(($quotation['shop_name'] ?? '') . ' / ' . $quotation['store_name'], ' /') : 'No store'); ?>
        </div>
    </div>
    <div>
        <a href="quotations.php" class="btn btn-light"><i class="fa fa-arrow-left mr-1"></i> Back</a>
        <a href="quotation-print.php?id=<?php echo $id; ?>" class="btn btn-outline-primary"><i class="fa fa-print mr-1"></i> Print</a>
        <a href="quotation-download.php?id=<?php echo $id; ?>" class="btn btn-outline-success"><i class="fa fa-download mr-1"></i> Download PDF</a>
        <?php if ($can_convert): ?>
            <form method="POST" action="quotation-details.php?id=<?php echo $id; ?>" class="d-inline" onsubmit="return confirm('Convert this quotation into an invoice? Stock will be deducted from the selected store.');">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="quotation_id" value="<?php echo $id; ?>">
                <input type="hidden" name="action" value="convert">
                <button type="submit" class="btn btn-primary"><i class="fa fa-file-text mr-1"></i> Convert to Invoice</button>
            </form>
        <?php elseif (!empty($quotation['converted_invoice_number'])): ?>
            <a href="invoice-details.php?id=<?php echo (int)$quotation['converted_invoice_id']; ?>" class="btn btn-success"><i class="fa fa-check mr-1"></i> View <?php echo e($quotation['converted_invoice_number']); ?></a>
        <?php endif; ?>
    </div>
</div>

<?php if ($f = flash_get()): ?>
    <div class="alert alert-<?php echo e($f['type']); ?>"><?php echo e($f['message']); ?></div>
<?php endif; ?>

<div class="row">
    <div class="col-lg-8 mb-3">
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h5 class="card-title">Quotation Items</h5>
                <span class="badge badge-<?php echo e(quotation_status_class($display_status)); ?>"><?php echo e(quotation_status_label($display_status)); ?></span>
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
                                <td class="text-center"><?php echo e(format_quantity($item['quantity'])); ?></td>
                                <td class="text-right"><?php echo e(money($item['unit_price'], $cur)); ?></td>
                                <td class="text-right"><?php echo e(money($item['tax_amount'], $cur)); ?></td>
                                <td class="text-right font-weight-semibold"><?php echo e(money($item['total'], $cur)); ?></td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (!$items): ?>
                            <tr><td colspan="6" class="text-center text-muted py-4">This quotation has no items.</td></tr>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <div class="card-footer">
                <div class="d-flex justify-content-end">
                    <div style="width:320px;">
                        <div class="cart-total-row"><span>Subtotal</span><span><?php echo e(money($quotation['subtotal'], $cur)); ?></span></div>
                        <?php if ((float)$quotation['tax_amount'] > 0): ?>
                        <div class="cart-total-row"><span>Tax</span><span><?php echo e(money($quotation['tax_amount'], $cur)); ?></span></div>
                        <?php endif; ?>
                        <div class="cart-total-row cart-grand"><span>Total</span><span><?php echo e(money($quotation['total'], $cur)); ?></span></div>
                    </div>
                </div>
            </div>
        </div>
        <div class="alert alert-info small">
            A quotation does not reserve or deduct stock. Converting it creates an invoice and deducts stock from the selected store once.
        </div>
    </div>
    <div class="col-lg-4 mb-3">
        <div class="card">
            <div class="card-header"><h5 class="card-title">Quotation Information</h5></div>
            <div class="card-body">
                <table class="table">
                    <tbody>
                        <tr><th>Quotation #</th><td><?php echo e($quotation['quotation_number']); ?></td></tr>
                        <tr><th>Issue Date</th><td><?php echo e(date('M d, Y', strtotime($quotation['issue_date']))); ?></td></tr>
                        <tr><th>Valid Until</th><td><?php echo !empty($quotation['valid_until']) ? e(date('M d, Y', strtotime($quotation['valid_until']))) : '—'; ?></td></tr>
                        <tr><th>Status</th><td><span class="badge badge-<?php echo e(quotation_status_class($display_status)); ?>"><?php echo e(quotation_status_label($display_status)); ?></span></td></tr>
                        <tr><th>Store</th><td><?php echo e($quotation['store_name'] ? trim(($quotation['shop_name'] ?? '') . ' / ' . $quotation['store_name'], ' /') : '—'); ?></td></tr>
                        <tr><th>Reference</th><td><?php echo e($quotation['reference'] ?: '—'); ?></td></tr>
                        <tr><th>Terms</th><td><?php echo e($quotation['terms'] ?: '—'); ?></td></tr>
                        <tr><th>Notes</th><td><?php echo e($quotation['notes'] ?: '—'); ?></td></tr>
                        <?php if (!empty($quotation['converted_invoice_number'])): ?>
                        <tr><th>Invoice</th><td><a href="invoice-details.php?id=<?php echo (int)$quotation['converted_invoice_id']; ?>"><?php echo e($quotation['converted_invoice_number']); ?></a></td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <div class="card">
            <div class="card-header"><h5 class="card-title">Customer</h5></div>
            <div class="card-body">
                <div class="font-weight-semibold"><?php echo e(trim(($quotation['first_name'] ?? '') . ' ' . ($quotation['last_name'] ?? '')) ?: ($quotation['company_name'] ?? '—')); ?></div>
                <div class="text-muted small"><?php echo e($quotation['email'] ?? ''); ?></div>
                <div class="text-muted small"><?php echo e($quotation['phone'] ?? ''); ?></div>
                <div class="text-muted small"><?php echo e($quotation['address'] ?? ''); ?></div>
            </div>
        </div>
    </div>
</div>

<?php require_once 'footer.php'; ?>
