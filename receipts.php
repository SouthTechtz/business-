<?php
require_once 'header.php';
require_once 'auth_check.php';
require_permission('manage_receipts');
$page_title = 'Payments';

$payment_sql = "SELECT p.*, pm.name method_name, i.invoice_number, i.total invoice_total, c.first_name, c.last_name, c.company_name,
                       w.name store_name, s.name shop_name
     FROM payments p
     JOIN invoices i ON i.invoice_id = p.invoice_id
     LEFT JOIN payment_methods pm ON pm.payment_method_id = p.payment_method_id
     LEFT JOIN contacts c ON c.contact_id = p.contact_id
     LEFT JOIN warehouses w ON w.warehouse_id = i.warehouse_id
     LEFT JOIN shops s ON s.shop_id = w.shop_id
     WHERE p.tenant_id = ? AND i.tenant_id = ?";
$payment_params = [$current_user->tenant_id, $current_user->tenant_id];
$scope_ids = accessible_warehouse_ids();
if ($scope_ids !== null) {
    if (!$scope_ids) {
        $payment_sql .= " AND 1 = 0";
    } else {
        $payment_sql .= " AND i.warehouse_id IN (" . implode(',', array_map('intval', $scope_ids)) . ")";
    }
}
$payment_sql .= " ORDER BY p.payment_date DESC";
$payments = fetch_all($payment_sql, ...$payment_params);

$total_received = array_sum(array_map(fn($p) => (float)$p['amount'], $payments));

require_once 'navbar.php';
require_once 'sidebar.php';
$cur = tenant_currency();
?>

<div class="page-head">
    <div>
        <h1 class="page-title">Payments</h1>
        <div class="page-sub"><?php echo count($payments); ?> payment(s) — total received: <strong><?php echo e(money($total_received, $cur)); ?></strong></div>
    </div>
    <a href="payment-add.php" class="btn btn-success"><i class="fa fa-money mr-1"></i> Record Payment</a>
    <a href="export-receipts.php" class="btn btn-outline-success"><i class="fa fa-file-excel-o mr-1"></i> Excel</a>
</div>

<?php if ($f = flash_get()): ?>
    <div class="alert alert-<?php echo e($f['type']); ?>"><?php echo e($f['message']); ?></div>
<?php endif; ?>

<div class="card">
    <div class="card-header d-flex justify-content-between align-items-center">
        <h5 class="card-title">Payments Received</h5>
        <div class="input-group dt-search">
            <div class="input-group-prepend"><span class="input-group-text"><i class="fa fa-search"></i></span></div>
            <input type="text" class="form-control dt-search-input" data-scope="receipts" placeholder="Search payments...">
        </div>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover data-table">
                <thead>
                    <tr>
                        <th>Date</th><th>Invoice</th><th>Customer</th><th>Store</th><th>Method</th><th class="text-right">Amount</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($payments as $p): ?>
                    <tr>
                        <td><?php echo e(date('M d, Y H:i', strtotime($p['payment_date']))); ?></td>
                        <td><a href="invoice-details.php?id=<?php echo $p['invoice_id']; ?>"><?php echo e($p['invoice_number']); ?></a></td>
                        <td><?php echo e(trim(($p['first_name'] ?? '') . ' ' . ($p['last_name'] ?? '')) ?: $p['company_name'] ?: 'Walk-in Customer'); ?></td>
                        <td><?php echo e($p['store_name'] ? trim(($p['shop_name'] ?? '') . ' / ' . $p['store_name'], ' /') : '—'); ?></td>
                        <td><span class="badge badge-primary"><?php echo e($p['method_name'] ?? '—'); ?></span></td>
                        <td class="text-right font-weight-semibold text-success"><?php echo e(money($p['amount'], $cur)); ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$payments): ?>
                    <tr><td colspan="6" class="text-center text-muted py-4">No payments recorded yet.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php require_once 'footer.php'; ?>
