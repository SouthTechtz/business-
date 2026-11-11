<?php
require_once 'header.php';
require_once 'auth_check.php';
require_permission('view_reports');
$page_title = 'Reports';
$cur = tenant_currency();

require_once 'reporting.php';
require_once 'capital-helpers.php';

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
$sales_by_brand = report_sales_by_brand($from, $to);
$sales_by_product = report_sales_by_product($from, $to);
$invoice_status_summary = report_invoice_status_summary($from, $to);

// Capital and balance-sheet position (brand-scoped for Mini Admins).
$balance_sheet = capital_balance_sheet($current_user->tenant_id, current_brand_scope());
$balance_sheet_brand_label = ($balance_sheet['brand'] ?? '') !== ''
    ? brand_label($balance_sheet['brand'])
    : 'All Brands';

// Shared safe location scope for invoice summaries.
$inv_scope = report_sales_warehouse_condition('i', 'warehouse_id');
$payment_scope = report_sales_warehouse_condition('i', 'warehouse_id');

// Monthly sales (invoices based, last 6 months)
$monthly_sales = fetch_all(
    "SELECT DATE_FORMAT(issue_date, '%Y-%m') month, SUM(total) total, COUNT(*) invoices
     FROM invoices i WHERE i.tenant_id = ?" . $inv_scope['sql'] . "
     GROUP BY DATE_FORMAT(issue_date, '%Y-%m') ORDER BY month DESC LIMIT 6",
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
    ['balance-sheet', 'Balance Sheet', 'fa-university', 'Capital, cash at bank, stock, receivables, payables and retained earnings.', 'wi-cyan'],
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

<div class="card mb-3">
    <div class="card-body d-flex flex-wrap justify-content-between">
        <span>Net POS Sales: <strong><?php echo e(money($sales_summary['pos']['net_sales'], $cur)); ?></strong></span>
        <span>Net Invoice Sales: <strong><?php echo e(money($sales_summary['invoice']['net_sales'], $cur)); ?></strong></span>
        <span>Returned Quantity: <strong><?php echo e((float)($sales_summary['pos']['returned_qty'] + $sales_summary['invoice']['returned_qty'])); ?></strong></span>
        <span>Accepted for Resale: <strong><?php echo e((float)($sales_summary['pos']['resale_qty'] + $sales_summary['invoice']['resale_qty'])); ?></strong></span>
    </div>
</div>

<div class="card mb-3">
    <div class="card-header"><h5 class="card-title">Sales by Shop</h5></div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-sm mb-0">
                <thead><tr><th>Shop</th><th class="text-right">Transactions</th><th class="text-right">POS Gross</th><th class="text-right">POS Returns</th><th class="text-right">POS Net</th><th class="text-right">Invoice Gross</th><th class="text-right">Invoice Returns</th><th class="text-right">Invoice Net</th><th class="text-right">Combined Net</th></tr></thead>
                <tbody>
                <?php foreach ($sales_by_shop as $shop): ?>
                    <tr>
                        <td><?php echo e($shop['shop_name']); ?></td>
                        <td class="text-right"><?php echo (int)$shop['sale_count']; ?></td>
                        <td class="text-right"><?php echo e(money($shop['pos_gross_sales'], $cur)); ?></td>
                        <td class="text-right"><?php echo e(money($shop['pos_accepted_returns'], $cur)); ?></td>
                        <td class="text-right"><?php echo e(money($shop['pos_net_sales'], $cur)); ?></td>
                        <td class="text-right"><?php echo e(money($shop['invoice_gross_sales'], $cur)); ?></td>
                        <td class="text-right"><?php echo e(money($shop['invoice_accepted_returns'], $cur)); ?></td>
                        <td class="text-right"><?php echo e(money($shop['invoice_net_sales'], $cur)); ?></td>
                        <td class="text-right font-weight-semibold"><?php echo e(money($shop['net_sales'], $cur)); ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$sales_by_shop): ?><tr><td colspan="9" class="text-center text-muted py-3">No eligible sales in this period.</td></tr><?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<div class="card mb-3">
    <div class="card-header"><h5 class="card-title">Sales by Brand</h5></div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-sm mb-0">
                <thead><tr><th>Brand</th><th class="text-right">Sales Transactions</th><th class="text-right">Gross Sales</th><th class="text-right">Returned Qty</th><th class="text-right">Accepted Returns</th><th class="text-right">Net Sales</th><th class="text-right">Total Sales</th></tr></thead>
                <tbody>
                <?php foreach ($sales_by_brand as $brand): ?>
                    <tr>
                        <td><span class="badge badge-navy"><?php echo e($brand['brand_label']); ?></span></td>
                        <td class="text-right"><?php echo (int)$brand['sale_count']; ?></td>
                        <td class="text-right"><?php echo e(money($brand['gross_sales'], $cur)); ?></td>
                        <td class="text-right"><?php echo e((float)$brand['returned_qty']); ?></td>
                        <td class="text-right"><?php echo e(money($brand['accepted_returns'], $cur)); ?></td>
                        <td class="text-right"><?php echo e(money($brand['net_sales'], $cur)); ?></td>
                        <td class="text-right font-weight-semibold"><?php echo e(money($brand['total_sales'] ?? $brand['net_sales'], $cur)); ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$sales_by_brand): ?><tr><td colspan="7" class="text-center text-muted py-3">No eligible sales in this period.</td></tr><?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<div class="card mb-3">
    <div class="card-header"><h5 class="card-title">Product Sales and Accepted Returns</h5></div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-sm mb-0">
                <thead><tr><th>Product</th><th class="text-right">Sold Qty</th><th class="text-right">Returned Qty</th><th class="text-right">Resale Qty</th><th class="text-right">Net Qty</th><th class="text-right">Gross Value</th><th class="text-right">Accepted Returns</th><th class="text-right">Net Value</th></tr></thead>
                <tbody>
                <?php foreach ($sales_by_product as $product): ?>
                    <tr>
                        <td><?php echo e($product['product_name']); ?></td>
                        <td class="text-right"><?php echo e((float)$product['sold_qty']); ?></td>
                        <td class="text-right"><?php echo e((float)$product['returned_qty']); ?></td>
                        <td class="text-right"><?php echo e((float)$product['resale_qty']); ?></td>
                        <td class="text-right"><?php echo e((float)$product['net_qty']); ?></td>
                        <td class="text-right"><?php echo e(money($product['gross_sales'], $cur)); ?></td>
                        <td class="text-right"><?php echo e(money($product['accepted_returns'], $cur)); ?></td>
                        <td class="text-right font-weight-semibold"><?php echo e(money($product['net_sales'], $cur)); ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$sales_by_product): ?><tr><td colspan="8" class="text-center text-muted py-3">No product sales in this period.</td></tr><?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<div class="card mb-3">
    <div class="card-header"><h5 class="card-title">Invoice Status, Balances and Returns</h5></div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-sm mb-0">
                <thead><tr><th>Status</th><th class="text-right">Invoices</th><th class="text-right">Invoice Total</th><th class="text-right">Outstanding</th><th class="text-right">Refund Due</th></tr></thead>
                <tbody>
                <?php foreach ($invoice_status_summary as $status): ?>
                    <tr>
                        <td><?php echo e($status['status_name']); ?></td>
                        <td class="text-right"><?php echo (int)$status['invoice_count']; ?></td>
                        <td class="text-right"><?php echo e(money($status['invoice_total'], $cur)); ?></td>
                        <td class="text-right"><?php echo e(money($status['outstanding_balance'], $cur)); ?></td>
                        <td class="text-right"><?php echo e(money($status['refund_due'], $cur)); ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$invoice_status_summary): ?><tr><td colspan="5" class="text-center text-muted py-3">No invoices in this period.</td></tr><?php endif; ?>
                </tbody>
            </table>
        </div>
        <div class="small text-muted p-2">Partial returns: <?php echo (int)$sales_summary['invoice']['partial_return_count']; ?> · Full returns: <?php echo (int)$sales_summary['invoice']['full_return_count']; ?> · Returned quantity is not the same as quantity accepted for resale.</div>
    </div>
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
                        <a href="report-print.php?type=<?php echo e($r[0]); ?><?php echo $qs; ?>" class="btn btn-sm btn-primary"><i class="fa fa-print mr-1"></i> Print</a>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <div class="text-muted small mt-2"><i class="fa fa-info-circle mr-1"></i>Reports open in a print-friendly view (Ctrl+P or the Print button to save as PDF). Perfect for TRA submissions.</div>
    </div>
</div>

<div class="card mb-3">
    <div class="card-header d-flex justify-content-between align-items-center flex-wrap" style="gap:8px;">
        <div>
            <h5 class="card-title mb-0">Balance Sheet</h5>
            <small class="text-muted">Position for <?php echo e($balance_sheet_brand_label); ?> as at <?php echo e(date('F d, Y')); ?>.</small>
        </div>
        <a href="report-print.php?type=balance-sheet" class="btn btn-sm btn-primary"><i class="fa fa-print mr-1"></i> Print Balance Sheet</a>
    </div>
    <div class="card-body">
        <div class="row">
            <div class="col-md-6 mb-3">
                <h6 class="font-weight-bold text-uppercase" style="font-size:12px;letter-spacing:.06em;color:#6b7280;">Assets</h6>
                <table class="table table-sm mb-0">
                    <tbody>
                        <tr><td>Capital in bank</td><td class="text-right"><?php echo e(money($balance_sheet['bank_balance'], $cur)); ?></td></tr>
                        <tr><td>Stock-held capital (inventory at cost)</td><td class="text-right"><?php echo e(money($balance_sheet['inventory'], $cur)); ?></td></tr>
                        <tr><td>Accounts receivable</td><td class="text-right"><?php echo e(money($balance_sheet['receivables'], $cur)); ?></td></tr>
                        <tr class="font-weight-bold" style="background:#f6f7fb;"><td>Total assets</td><td class="text-right"><?php echo e(money($balance_sheet['total_assets'], $cur)); ?></td></tr>
                    </tbody>
                </table>
            </div>
            <div class="col-md-6 mb-3">
                <h6 class="font-weight-bold text-uppercase" style="font-size:12px;letter-spacing:.06em;color:#6b7280;">Capital &amp; Liabilities</h6>
                <table class="table table-sm mb-0">
                    <tbody>
                        <tr><td>Capital introduced (net)</td><td class="text-right"><?php echo e(money($balance_sheet['capital_net'], $cur)); ?></td></tr>
                        <tr><td>Retained earnings</td><td class="text-right"><?php echo e(money($balance_sheet['net_profit'], $cur)); ?></td></tr>
                        <tr><td>Supplier payables</td><td class="text-right"><?php echo e(money($balance_sheet['payables'], $cur)); ?></td></tr>
                        <tr><td>Recorded capital &amp; liabilities</td><td class="text-right"><?php echo e(money($balance_sheet['total_funding'], $cur)); ?></td></tr>
                        <?php if (abs((float)$balance_sheet['difference']) > 0.005): ?>
                            <tr><td>Assets not yet matched by recorded capital</td><td class="text-right"><?php echo e(money($balance_sheet['difference'], $cur)); ?></td></tr>
                        <?php endif; ?>
                        <tr class="font-weight-bold" style="background:#f6f7fb;"><td>Total capital &amp; liabilities</td><td class="text-right"><?php echo e(money($balance_sheet['total_assets'], $cur)); ?></td></tr>
                    </tbody>
                </table>
            </div>
        </div>
        <div class="row mb-2">
            <div class="col-md-4 mb-2">
                <div class="p-2" style="background:#f6f7fb;border-radius:8px;">
                    <div class="small text-muted">Capital in Bank</div>
                    <div class="font-weight-semibold"><?php echo e(money($balance_sheet['bank_balance'], $cur)); ?></div>
                </div>
            </div>
            <div class="col-md-4 mb-2">
                <div class="p-2" style="background:#f6f7fb;border-radius:8px;">
                    <div class="small text-muted">Stock-Held Capital</div>
                    <div class="font-weight-semibold"><?php echo e(money($balance_sheet['stock_capital'], $cur)); ?></div>
                </div>
            </div>
            <div class="col-md-4 mb-2">
                <div class="p-2" style="background:#eef0f8;border-radius:8px;">
                    <div class="small text-muted">Total Brand Capital</div>
                    <div class="font-weight-semibold"><?php echo e(money($balance_sheet['total_capital'], $cur)); ?></div>
                </div>
            </div>
        </div>
        <div class="small text-muted">Total capital in bank = capital added − capital withdrawn + all shop sales − expenses − purchases − refunds. Stock-held capital is inventory at purchase price. Total brand capital is capital in bank plus stock. Capital is equity, not sales revenue. Record funding on the <a href="capital.php">Capital</a> page; there are no opening balances. Where assets exceed recorded capital, add the missing funding as a capital contribution.</div>
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

<?php require_once 'footer.php'; ?>
