<?php
require_once 'header.php';
require_once 'auth_check.php';
require_permission('view_reports');
$page_title = 'Reports';
$cur = tenant_currency();

require_once 'reporting.php';

// ---- Period filter (Today / This Week / This Month / This Year / Custom) ----
$period_result = report_resolve_period($_GET);
$period = $period_result['period'];
$from = $period_result['from'];
$to = $period_result['to'];
$period_error = $period_result['error'];

$has_range = $from !== '' && $to !== '';
$qs = ($from ? "&from=" . urlencode($from) : '') . ($to ? "&to=" . urlencode($to) : '');

$sales_summary = report_sales_summary($from, $to);
$sales_by_shop = report_sales_by_shop($from, $to);
$sales_by_product = report_sales_by_product($from, $to);
$invoice_status_summary = report_invoice_status_summary($from, $to);

// Shared safe location scope for sales reports.
$pos_scope = report_sales_warehouse_condition('o', 'warehouse_id');
$inv_scope = report_sales_warehouse_condition('i', 'warehouse_id');
$payment_scope = report_sales_warehouse_condition('i', 'warehouse_id');

// Monthly sales (invoices based, last 6 months)
$monthly_sales = fetch_all(
    "SELECT DATE_FORMAT(issue_date, '%Y-%m') month, SUM(total) total, COUNT(*) invoices
     FROM invoices i WHERE i.tenant_id = ?" . $inv_scope['sql'] . "
     GROUP BY DATE_FORMAT(issue_date, '%Y-%m') ORDER BY month DESC LIMIT 6",
    $current_user->tenant_id, ...$inv_scope['params']
);

// Top products by invoice items
$top_products = fetch_all(
    "SELECT p.name, p.code, SUM(ii.quantity) qty, SUM(ii.total) total
     FROM invoice_items ii
     JOIN invoices i ON i.invoice_id = ii.invoice_id
     JOIN products p ON p.product_id = ii.product_id
     WHERE i.tenant_id = ?" . $inv_scope['sql'] . "
     GROUP BY p.product_id ORDER BY total DESC LIMIT 5",
    $current_user->tenant_id, ...$inv_scope['params']
);

// Payments by month
$payments_by_month = fetch_all(
    "SELECT DATE_FORMAT(payment_date, '%Y-%m') month, SUM(p.amount) total, COUNT(*) count
     FROM payments p
     JOIN invoices i ON i.invoice_id = p.invoice_id
     WHERE p.tenant_id = ? AND i.tenant_id = ?" . $payment_scope['sql'] . "
     GROUP BY DATE_FORMAT(payment_date, '%Y-%m') ORDER BY month DESC LIMIT 6",
    $current_user->tenant_id, $current_user->tenant_id, ...$payment_scope['params']
);

require_once 'navbar.php';
require_once 'sidebar.php';

$reports = [
    ['income', 'Income Statement (TRA)', 'fa-balance-scale', 'Income, expenses, VAT and net income for tax submission.', 'wi-indigo'],
    ['sales', 'Sales Report', 'fa-shopping-cart', 'POS and Invoice sales, accepted returns, and net sales by Shop and product.', 'wi-blue'],
    ['invoice-sales', 'Invoice Sales Report', 'fa-file-text', 'Invoice totals, return states, outstanding balances, and refund due.', 'wi-purple'],
    ['payments', 'Payments Received', 'fa-money', 'All money received from invoices and POS sales.', 'wi-green'],
    ['expenses', 'Expenses Report', 'fa-money', 'Every recorded expense grouped by category.', 'wi-red'],
    ['receivables', 'Outstanding Receivables', 'fa-exclamation-circle', 'Unpaid invoices and balances owed to you.', 'wi-amber'],
    ['stock', 'Stock / Inventory', 'fa-cubes', 'Current stock levels, low-stock alerts and values.', 'wi-cyan'],
    ['activity', 'System Activity', 'fa-history', 'Summary of all actions performed in the system.', 'wi-purple'],
];
?>
<div class="page-head">
    <div>
        <h1 class="page-title">Reports</h1>
        <div class="page-sub">Business performance and printable reports.</div>
    </div>
</div>

<div class="card mb-3">
    <div class="card-header"><h5 class="card-title">Reporting Period</h5></div>
    <div class="card-body">
        <div class="d-flex flex-wrap align-items-center gap-2">
            <a href="reports.php?period=today" class="btn btn-sm <?php echo $period === 'today' ? 'btn-primary' : 'btn-light'; ?>">Today</a>
            <a href="reports.php?period=week" class="btn btn-sm <?php echo $period === 'week' ? 'btn-primary' : 'btn-light'; ?>">This Week</a>
            <a href="reports.php?period=month" class="btn btn-sm <?php echo $period === 'month' ? 'btn-primary' : 'btn-light'; ?>">This Month</a>
            <a href="reports.php?period=year" class="btn btn-sm <?php echo $period === 'year' ? 'btn-primary' : 'btn-light'; ?>">This Year</a>
            <a href="reports.php?period=all" class="btn btn-sm <?php echo $period === 'all' || ($period === '' && !$has_range) ? 'btn-primary' : 'btn-light'; ?>">All Time</a>
            <form method="GET" action="reports.php" class="form-inline ml-auto">
                <label class="mr-1 small">From</label>
                <input type="date" name="from" class="form-control form-control-sm mr-2" value="<?php echo e($period === '' ? $from : ''); ?>">
                <label class="mr-1 small">To</label>
                <input type="date" name="to" class="form-control form-control-sm mr-2" value="<?php echo e($period === '' ? $to : ''); ?>">
                <button class="btn btn-sm btn-light"><i class="fa fa-filter mr-1"></i>Custom Range</button>
            </form>
        </div>
        <?php if ($has_range): ?>
            <div class="small text-muted mt-2">Showing data from <strong><?php echo e($from); ?></strong> to <strong><?php echo e($to); ?></strong>.</div>
        <?php else: ?>
            <div class="small text-muted mt-2">Showing all-time data.</div>
        <?php endif; ?>
    </div>
</div>

<?php if (!empty($period_error)): ?>
<div class="alert alert-danger"><?php echo e($period_error); ?></div>
<?php endif; ?>
<div class="row mb-3">
    <div class="col-md-6 col-xl-3 mb-3"><div class="widget"><div class="widget-icon wi-blue"><i class="fa fa-shopping-cart"></i></div><div class="widget-info"><div class="widget-value"><?php echo (int)$sales_summary['sale_count']; ?></div><div class="widget-label">Sales Transactions</div></div></div></div>
    <div class="col-md-6 col-xl-3 mb-3"><div class="widget"><div class="widget-icon wi-green"><i class="fa fa-money"></i></div><div class="widget-info"><div class="widget-value" style="font-size:17px;"><?php echo e(money($sales_summary['pos']['gross_sales'], $cur)); ?></div><div class="widget-label">Gross POS Sales</div></div></div></div>
    <div class="col-md-6 col-xl-3 mb-3"><div class="widget"><div class="widget-icon wi-purple"><i class="fa fa-file-text"></i></div><div class="widget-info"><div class="widget-value" style="font-size:17px;"><?php echo e(money($sales_summary['invoice']['gross_sales'], $cur)); ?></div><div class="widget-label">Gross Invoice Sales</div></div></div></div>
    <div class="col-md-6 col-xl-3 mb-3"><div class="widget"><div class="widget-icon wi-indigo"><i class="fa fa-line-chart"></i></div><div class="widget-info"><div class="widget-value" style="font-size:17px;"><?php echo e(money($sales_summary['net_sales'], $cur)); ?></div><div class="widget-label">Combined Net Sales</div></div></div></div>
</div>

<div class="row mb-3">
    <div class="col-md-6 col-xl-3 mb-3"><div class="widget"><div class="widget-icon wi-amber"><i class="fa fa-undo"></i></div><div class="widget-info"><div class="widget-value" style="font-size:17px;"><?php echo e(money($sales_summary['pos']['accepted_returns'], $cur)); ?></div><div class="widget-label">POS Accepted Returns</div></div></div></div>
    <div class="col-md-6 col-xl-3 mb-3"><div class="widget"><div class="widget-icon wi-amber"><i class="fa fa-undo"></i></div><div class="widget-info"><div class="widget-value" style="font-size:17px;"><?php echo e(money($sales_summary['invoice']['accepted_returns'], $cur)); ?></div><div class="widget-label">Invoice Accepted Returns</div></div></div></div>
    <div class="col-md-6 col-xl-3 mb-3"><div class="widget"><div class="widget-icon wi-red"><i class="fa fa-exclamation-circle"></i></div><div class="widget-info"><div class="widget-value" style="font-size:17px;"><?php echo e(money($sales_summary['invoice']['outstanding_balance'], $cur)); ?></div><div class="widget-label">Outstanding Balance</div></div></div></div>
    <div class="col-md-6 col-xl-3 mb-3"><div class="widget"><div class="widget-icon wi-red"><i class="fa fa-money"></i></div><div class="widget-info"><div class="widget-value" style="font-size:17px;"><?php echo e(money($sales_summary['invoice']['refund_due'], $cur)); ?></div><div class="widget-label">Refund Due (Not Cash Refunded)</div></div></div></div>
</div>

<!-- Printable reports -->
<div class="card">
    <div class="card-header">
        <h5 class="card-title"><i class="fa fa-print mr-1" style="color:var(--primary)"></i> Printable Reports</h5>
    </div>
    <div class="card-body">
        <div class="row">
            <?php foreach ($reports as $r): ?>
            <div class="col-md-6 col-xl-4 mb-3">
                <div class="widget" style="padding:16px 18px;">
                    <div class="widget-icon <?php echo e($r[4]); ?>"><i class="fa <?php echo e($r[2]); ?>"></i></div>
                    <div class="widget-info" style="flex:1">
                        <div class="font-weight-semibold" style="font-size:14px;"><?php echo e($r[1]); ?></div>
                        <div class="widget-label mb-2"><?php echo e($r[3]); ?></div>
                        <a href="report-print.php?type=<?php echo $r[0]; ?><?php echo $qs; ?>" class="btn btn-sm btn-primary"><i class="fa fa-print mr-1"></i> Print</a>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <div class="text-muted small mt-2"><i class="fa fa-info-circle mr-1"></i>Reports open in a print-friendly view (Ctrl+P or the Print button to save as PDF). Perfect for TRA submissions.</div>
    </div>
</div>

<div class="row">
    <div class="col-lg-6 mb-3">
        <div class="card">
            <div class="card-header"><h5 class="card-title">Sales by Month (Invoices)</h5></div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table mb-0">
                        <thead><tr><th>Month</th><th class="text-right">Invoices</th><th class="text-right">Total</th></tr></thead>
                        <tbody>
                        <?php foreach ($monthly_sales as $row): ?>
                            <tr>
                                <td><?php echo e(date('F Y', strtotime($row['month'] . '-01'))); ?></td>
                                <td class="text-right"><?php echo (int)$row['invoices']; ?></td>
                                <td class="text-right font-weight-semibold"><?php echo e(money($row['total'], $cur)); ?></td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (!$monthly_sales): ?><tr><td colspan="3" class="text-center text-muted py-4">No data yet.</td></tr><?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
    <div class="col-lg-6 mb-3">
        <div class="card">
            <div class="card-header"><h5 class="card-title">Payments Received by Month</h5></div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table mb-0">
                        <thead><tr><th>Month</th><th class="text-right">Payments</th><th class="text-right">Total</th></tr></thead>
                        <tbody>
                        <?php foreach ($payments_by_month as $row): ?>
                            <tr>
                                <td><?php echo e(date('F Y', strtotime($row['month'] . '-01'))); ?></td>
                                <td class="text-right"><?php echo (int)$row['count']; ?></td>
                                <td class="text-right font-weight-semibold"><?php echo e(money($row['total'], $cur)); ?></td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (!$payments_by_month): ?><tr><td colspan="3" class="text-center text-muted py-4">No payments yet.</td></tr><?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="row">
    <div class="col-lg-7 mb-3">
        <div class="card">
            <div class="card-header"><h5 class="card-title">Top Products by Revenue</h5></div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table mb-0">
                        <thead><tr><th>Product</th><th>Code</th><th class="text-right">Units Sold</th><th class="text-right">Revenue</th></tr></thead>
                        <tbody>
                        <?php foreach ($top_products as $row): ?>
                            <tr>
                                <td class="font-weight-semibold"><?php echo e($row['name']); ?></td>
                                <td><span class="badge badge-secondary"><?php echo e($row['code']); ?></span></td>
                                <td class="text-right"><?php echo e((float)$row['qty']); ?></td>
                                <td class="text-right"><?php echo e(money($row['total'], $cur)); ?></td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (!$top_products): ?><tr><td colspan="4" class="text-center text-muted py-4">No invoice items yet.</td></tr><?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<?php require_once 'footer.php'; ?>
