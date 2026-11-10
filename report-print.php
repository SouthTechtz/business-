<?php
require_once 'config.php';
require_once 'auth_check.php';
require_permission('view_reports');
require_once 'reporting.php';
require_once 'capital-helpers.php';

$cur = tenant_currency();
$type = $_GET['type'] ?? 'income';
$period_result = report_resolve_period($_GET);
$from = $period_result['from'];
$to = $period_result['to'];
$period_error = $period_result['error'];

$settings = fetch_one("SELECT * FROM system_settings WHERE tenant_id = ?", $current_user->tenant_id);
$company = $settings['company_name'] ?? 'Business';
$company_email = $settings['company_email'] ?? '';
$company_phone = $settings['company_phone'] ?? '';
$company_address = $settings['company_address'] ?? '';
$logo_path = system_logo_path($current_user->tenant_id);
$range_label = ($from && $to) ? "$from to $to" : ($from ? "from $from" : ($to ? "up to $to" : 'All time'));
$today = date('F d, Y H:i');

$title = '';
$rows = [];
$totals = [];

$pos_scope = report_sales_warehouse_condition('o', 'warehouse_id');
$inv_scope = report_sales_warehouse_condition('i', 'warehouse_id');
$payment_scope = report_sales_warehouse_condition('i', 'warehouse_id');
$stock_scope = report_inventory_location_condition('s', 'warehouse_id', true, true);

switch ($type) {
    case 'income':
        $title = 'Income Statement (Profit & Loss)';
        $pos_date = report_date_condition('o.order_date', $from, $to);
        $income_sales = fetch_one(
            "SELECT COALESCE(SUM(CASE WHEN status = 'completed' THEN total END), 0) total,
                    COALESCE(SUM(CASE WHEN status = 'completed' THEN tax_amount END), 0) vat
             FROM pos_orders o WHERE o.tenant_id = ?" . $pos_scope['sql'] . $pos_date['sql'],
            $current_user->tenant_id, ...array_merge($pos_scope['params'], $pos_date['params'])
        );
        $payment_date = report_date_condition('p.payment_date', $from, $to);
        $income_inv = fetch_one(
            "SELECT COALESCE(SUM(p.amount), 0) total
             FROM payments p
             JOIN invoices i ON i.invoice_id = p.invoice_id
             WHERE p.tenant_id = ? AND i.tenant_id = ?" . $payment_scope['sql'] . $payment_date['sql'],
            $current_user->tenant_id, $current_user->tenant_id, ...array_merge($payment_scope['params'], $payment_date['params'])
        );
        $expense_date = report_date_condition('e.expense_date', $from, $to);
        $report_brand_scope = current_brand_scope();
        $expense_brand_sql = $report_brand_scope !== '' ? ' AND e.brand = ?' : '';
        $expense_brand_params = $report_brand_scope !== '' ? [$report_brand_scope] : [];
        $expenses = fetch_all(
            "SELECT COALESCE(c.name, 'Uncategorised') category_name, SUM(e.amount) total
             FROM expenses e LEFT JOIN expense_categories c ON c.expense_category_id = e.category_id
             WHERE e.tenant_id = ?" . $expense_date['sql'] . $expense_brand_sql . " GROUP BY e.category_id ORDER BY total DESC",
            $current_user->tenant_id, ...array_merge($expense_date['params'], $expense_brand_params)
        );
        $exp_total = 0;
        foreach ($expenses as $ex) $exp_total += (float)$ex['total'];
        $pos_total = (float)($income_sales['total'] ?? 0);
        $inv_total = (float)($income_inv['total'] ?? 0);
        $vat_total = (float)($income_sales['vat'] ?? 0);
        $income_total = $pos_total + $inv_total;
        $net = $income_total - $exp_total;

        $rows = [
            ['REVENUE', '', ''],
            ['POS / Walk-in Sales', money($pos_total, $cur), ''],
            ['Invoice Payments Received', money($inv_total, $cur), ''],
            ['Total Income', money($income_total, $cur), 'strong'],
            ['', '', ''],
            ['EXPENSES', '', ''],
        ];
        foreach ($expenses as $ex) {
            $rows[] = ['  ' . $ex['category_name'], money($ex['total'], $cur), ''];
        }
        $rows[] = ['Total Expenses', money($exp_total, $cur), 'strong'];
        $rows[] = ['', '', ''];
        $rows[] = ['NET INCOME', money($net, $cur), 'net'];
        $rows[] = ['', '', ''];
        $rows[] = ['VAT COLLECTED (on sales)', money($vat_total, $cur), ''];
        $capital_memo = capital_summary($current_user->tenant_id, $report_brand_scope);
        $capital_position = capital_balance_sheet($current_user->tenant_id, $report_brand_scope);
        $rows[] = ['', '', ''];
        $rows[] = ['MEMO: Capital introduced (equity, not income)', money($capital_memo['net'], $cur), ''];
        $rows[] = ['MEMO: Cash in hand', money($capital_position['cash_in_hand'] ?? 0, $cur), ''];
        $rows[] = ['MEMO: Bank balance', money($capital_position['bank_balance'] ?? 0, $cur), ''];
        $rows[] = ['MEMO: Liquid capital', money($capital_position['liquid_capital'] ?? 0, $cur), ''];
        $rows[] = ['MEMO: Stock capital', money($capital_position['stock_capital'] ?? 0, $cur), ''];
        $rows[] = ['MEMO: Total brand capital', money($capital_position['total_capital'] ?? 0, $cur), ''];
        $totals = ['net' => $net];
        break;

    case 'balance-sheet':
        $title = 'Balance Sheet';
        $bs = capital_balance_sheet($current_user->tenant_id, $_GET['brand'] ?? '');
        $bs_brand_label = ($bs['brand'] ?? '') !== '' ? brand_label($bs['brand']) : 'All Brands';
        $range_label = 'As at ' . date('F d, Y');
        $totals = ['assets' => $bs['total_assets'], 'funding' => $bs['total_funding']];
        break;

    case 'sales':
        $title = 'Sales Report (POS and Invoice)';
        $sales_summary = report_sales_summary($from, $to);
        $sales_by_shop = report_sales_by_shop($from, $to);
        $sales_by_product = report_sales_by_product($from, $to);
        $rows = report_pos_sale_rows($from, $to);
        $totals['total'] = $sales_summary['pos']['gross_sales'];
        $totals['count'] = $sales_summary['pos']['sale_count'];
        break;

    case 'invoice-sales':
        $title = 'Invoice Sales Report';
        $invoice_summary = report_invoice_sales_summary($from, $to);
        $invoice_status_summary = report_invoice_status_summary($from, $to);
        $rows = report_invoice_sale_rows($from, $to);
        $totals['total'] = $invoice_summary['gross_sales'];
        $totals['count'] = $invoice_summary['sale_count'];
        break;

    case 'payments':
        $title = 'Payments Received Report';
        $payment_date = report_date_condition('p.payment_date', $from, $to);
        $rows = fetch_all(
            "SELECT p.payment_date, p.amount, m.name method_name, i.invoice_number,
                    CONCAT(COALESCE(c.first_name,''), ' ', COALESCE(c.last_name,'')) customer_name, c.company_name
             FROM payments p
             LEFT JOIN payment_methods m ON m.payment_method_id = p.payment_method_id
             JOIN invoices i ON i.invoice_id = p.invoice_id
             LEFT JOIN contacts c ON c.contact_id = p.contact_id
             WHERE p.tenant_id = ? AND i.tenant_id = ?" . $payment_scope['sql'] . $payment_date['sql'] . " ORDER BY p.payment_date DESC",
            $current_user->tenant_id, $current_user->tenant_id, ...array_merge($payment_scope['params'], $payment_date['params'])
        );
        $totals['total'] = array_sum(array_map(fn($r) => (float)$r['amount'], $rows));
        $totals['count'] = count($rows);
        break;

    case 'expenses':
        $title = 'Expenses Report';
        $expense_date = report_date_condition('e.expense_date', $from, $to);
        $report_brand_scope = current_brand_scope();
        $expense_brand_sql = $report_brand_scope !== '' ? ' AND e.brand = ?' : '';
        $expense_brand_params = $report_brand_scope !== '' ? [$report_brand_scope] : [];
        $rows = fetch_all(
            "SELECT e.expense_date, e.description, e.amount, COALESCE(c.name,'Uncategorised') category_name, m.name method_name
             FROM expenses e
             LEFT JOIN expense_categories c ON c.expense_category_id = e.category_id
             LEFT JOIN payment_methods m ON m.payment_method_id = e.payment_method_id
             WHERE e.tenant_id = ?" . $expense_date['sql'] . $expense_brand_sql . " ORDER BY e.expense_date DESC",
            $current_user->tenant_id, ...array_merge($expense_date['params'], $expense_brand_params)
        );
        $totals['total'] = array_sum(array_map(fn($r) => (float)$r['amount'], $rows));
        $totals['count'] = count($rows);
        break;

    case 'receivables':
        $title = 'Receivables Report (Outstanding Invoices)';
        $receivable_date = report_date_condition('i.issue_date', $from, $to);
        $rows = fetch_all(
            "SELECT i.invoice_number, i.issue_date, i.due_date, i.total, i.amount_paid, i.amount_due,
                    CONCAT(COALESCE(c.first_name,''), ' ', COALESCE(c.last_name,'')) customer_name, c.company_name
             FROM invoices i LEFT JOIN contacts c ON c.contact_id = i.contact_id
             WHERE i.tenant_id = ? AND i.is_paid = 0" . $inv_scope['sql'] . $receivable_date['sql'] . " ORDER BY i.due_date ASC",
            $current_user->tenant_id, ...array_merge($inv_scope['params'], $receivable_date['params'])
        );
        $totals['total'] = array_sum(array_map(fn($r) => (float)$r['amount_due'], $rows));
        $totals['count'] = count($rows);
        break;

    case 'stock':
        $title = 'Stock / Inventory Report';
        $stock_brand = brand_scope_condition('p.brand');
        $rows = fetch_all(
            "SELECT p.code, p.name, p.sale_price, COALESCE(SUM(s.quantity),0) quantity, p.min_stock_level,
                    w.name warehouse, MAX(COALESCE(w.is_godown, 0)) is_godown
             FROM products p
             LEFT JOIN inventory_stock s ON s.product_id = p.product_id AND s.tenant_id = p.tenant_id
             LEFT JOIN warehouses w ON w.warehouse_id = s.warehouse_id
             WHERE p.tenant_id = ? AND p.is_service = 0" . $stock_scope['sql'] . $stock_brand['sql'] . "
             GROUP BY p.product_id, s.warehouse_id ORDER BY p.name",
            $current_user->tenant_id, ...array_merge($stock_scope['params'], $stock_brand['params'])
        );
        $totals['count'] = count($rows);
        break;

    case 'activity':
        $title = 'System Activity Summary';
        $activity_date = report_date_condition('l.created_at', $from, $to);
        $rows = fetch_all(
            "SELECT action, entity_type, COUNT(*) count
             FROM audit_logs l WHERE l.tenant_id = ?" . $activity_date['sql'] . "
             GROUP BY action, entity_type ORDER BY count DESC",
            $current_user->tenant_id, ...$activity_date['params']
        );
        $totals['count'] = array_sum(array_map(fn($r) => (int)$r['count'], $rows));
        break;

    default:
        redirect('reports.php');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title><?php echo e($title); ?> — <?php echo e($company); ?></title>
    <link rel="stylesheet" href="assets/css/fonts.css">
    <link rel="stylesheet" href="assets/css/bootstrap.min.css">
    <link rel="stylesheet" href="assets/css/font-awesome.min.css">
    <link rel="stylesheet" href="assets/css/style.css">
    <style>
        body { background: #f1f3f8; padding: 30px 0; }
        .report-doc {
            max-width: 900px; margin: 0 auto; background: #fff; padding: 40px 48px;
            border-radius: 12px; box-shadow: 0 10px 40px rgba(20,24,46,.08);
        }
        .report-head { display: flex; justify-content: space-between; align-items: flex-start; border-bottom: 3px solid #1c2333; padding-bottom: 18px; margin-bottom: 22px; }
        .report-head h2 { font-size: 20px; font-weight: 800; margin: 0; }
        .report-head p { margin: 2px 0; color: #555; font-size: 12.5px; }
        .report-meta { color: #777; font-size: 12px; margin-bottom: 14px; }
        .report-doc table { font-size: 12.5px; }
        .report-doc .strong td { font-weight: 700; background: #f6f7fb; }
        .report-doc .net td { font-weight: 800; font-size: 14px; color: #0a8a62; background: #e8faf3; }
        .totals-row { font-weight: 700; border-top: 2px solid #1c2333; }
        .report-foot { margin-top: 28px; border-top: 1px solid #ddd; padding-top: 12px; font-size: 11.5px; color: #888; }
        @media print {
            body { padding: 0; background: #fff; }
            .report-doc { box-shadow: none; padding: 12px; max-width: none; }
            .no-print { display: none !important; }
        }
    </style>
</head>
<body>
<div class="report-doc">
    <div class="text-right no-print mb-3">
        <button class="btn btn-primary" onclick="window.print()"><i class="fa fa-print mr-1"></i> Print / Save PDF</button>
        <a href="reports.php" class="btn btn-light ml-2"><i class="fa fa-arrow-left mr-1"></i> Back to Reports</a>
    </div>
    <div class="report-head">
        <div style="display:flex;align-items:flex-start;gap:14px;">
            <?php if ($logo_path): ?>
                <img src="<?php echo e($logo_path); ?>" alt="Company logo" style="max-height:56px;max-width:130px;object-fit:contain;">
            <?php endif; ?>
            <div>
                <h2><?php echo e($company); ?></h2>
                <?php if ($company_address): ?><p><?php echo e($company_address); ?></p><?php endif; ?>
                <?php if ($company_phone): ?><p><?php echo e($company_phone); ?><?php if ($company_email): ?> &middot; <?php echo e($company_email); ?><?php endif; ?></p><?php endif; ?>
            </div>
        </div>
        <div class="text-right">
            <h2><?php echo e($title); ?></h2>
            <p>Period: <?php echo e($range_label); ?></p>
        </div>
    </div>
    <div class="report-meta">Generated on <?php echo e($today); ?> &middot; Currency: <?php echo e($cur); ?></div>

    <?php if ($period_error !== ''): ?>
        <div class="alert alert-danger"><?php echo e($period_error); ?> No date filter was applied.</div>
    <?php endif; ?>

    <?php if ($type === 'income'): ?>
        <table class="table table-bordered">
            <tbody>
            <?php foreach ($rows as $r): ?>
                <tr class="<?php echo e($r[2]); ?>"><td><?php echo e($r[0]); ?></td><td class="text-right"><?php echo e($r[1]); ?></td></tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php elseif ($type === 'balance-sheet'): ?>
        <div class="report-meta"><strong>Brand:</strong> <?php echo e($bs_brand_label); ?></div>
        <div class="row">
            <div class="col-6">
                <h4>Assets</h4>
                <table class="table table-bordered">
                    <tbody>
                        <tr><td>Cash in hand</td><td class="text-right"><?php echo e(money($bs['cash_in_hand'], $cur)); ?></td></tr>
                        <tr><td>Bank balance (available / overdraft)</td><td class="text-right"><?php echo e(money($bs['bank_balance'], $cur)); ?></td></tr>
                        <tr><td>Stock capital (inventory at cost)</td><td class="text-right"><?php echo e(money($bs['inventory'], $cur)); ?></td></tr>
                        <tr><td>Accounts receivable</td><td class="text-right"><?php echo e(money($bs['receivables'], $cur)); ?></td></tr>
                        <tr class="strong"><td>Total assets</td><td class="text-right"><?php echo e(money($bs['total_assets'], $cur)); ?></td></tr>
                    </tbody>
                </table>
                <table class="table table-bordered" style="margin-top:8px;">
                    <tbody>
                        <tr><td>Liquid capital (cash + bank)</td><td class="text-right"><?php echo e(money($bs['liquid_capital'], $cur)); ?></td></tr>
                        <tr><td>Stock capital</td><td class="text-right"><?php echo e(money($bs['stock_capital'], $cur)); ?></td></tr>
                        <tr class="strong"><td>Total brand capital</td><td class="text-right"><?php echo e(money($bs['total_capital'], $cur)); ?></td></tr>
                    </tbody>
                </table>
            </div>
            <div class="col-6">
                <h4>Capital &amp; Liabilities</h4>
                <table class="table table-bordered">
                    <tbody>
                        <tr><td>Capital introduced (net)</td><td class="text-right"><?php echo e(money($bs['capital_net'], $cur)); ?></td></tr>
                        <tr><td>Retained earnings</td><td class="text-right"><?php echo e(money($bs['net_profit'], $cur)); ?></td></tr>
                        <tr><td>Supplier payables</td><td class="text-right"><?php echo e(money($bs['payables'], $cur)); ?></td></tr>
                        <tr><td>Recorded capital &amp; liabilities</td><td class="text-right"><?php echo e(money($bs['total_funding'], $cur)); ?></td></tr>
                        <?php if (abs((float)$bs['difference']) > 0.005): ?>
                            <tr><td>Assets not yet matched by recorded capital</td><td class="text-right"><?php echo e(money($bs['difference'], $cur)); ?></td></tr>
                        <?php endif; ?>
                        <tr class="strong"><td>Total capital &amp; liabilities</td><td class="text-right"><?php echo e(money($bs['total_assets'], $cur)); ?></td></tr>
                    </tbody>
                </table>
            </div>
        </div>

        <h4>Bank Accounts</h4>
        <table class="table table-bordered">
            <thead><tr><th>Bank</th><th class="text-right">Opening</th><th class="text-right">Capital In</th><th class="text-right">Withdrawn</th><th class="text-right">Cash Banked</th><th class="text-right">Money Out</th><th class="text-right">Transfers</th><th class="text-right">Available</th></tr></thead>
            <tbody>
            <?php foreach ($bs['banks'] as $bank): ?>
                <?php
                $bank_out = (float)$bank['expenses_out'] + (float)$bank['purchases_out'] + (float)$bank['refunds_out'];
                $bank_transfers = (float)$bank['transfers_in'] - (float)$bank['transfers_out'];
                ?>
                <tr>
                    <td><?php echo e($bank['bank_name']); ?></td>
                    <td class="text-right"><?php echo e(money($bank['opening_in'], $cur)); ?></td>
                    <td class="text-right"><?php echo e(money($bank['capital_in'], $cur)); ?></td>
                    <td class="text-right"><?php echo e(money($bank['capital_out'], $cur)); ?></td>
                    <td class="text-right"><?php echo e(money($bank['sales_banked'], $cur)); ?></td>
                    <td class="text-right"><?php echo e(money($bank_out, $cur)); ?></td>
                    <td class="text-right"><?php echo e(money($bank_transfers, $cur)); ?></td>
                    <td class="text-right font-weight-bold"><?php echo e(money($bank['available'], $cur)); ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$bs['banks']): ?><tr><td colspan="8" class="text-center">No bank accounts yet.</td></tr><?php endif; ?>
            </tbody>
        </table>

        <h4>Operating Result</h4>
        <table class="table table-bordered">
            <tbody>
                <tr><td>POS sales</td><td class="text-right"><?php echo e(money($bs['pos_sales'], $cur)); ?></td></tr>
                <tr><td>Invoice payments received</td><td class="text-right"><?php echo e(money($bs['invoice_payments'], $cur)); ?></td></tr>
                <tr><td>Storefront order sales (net of returns)</td><td class="text-right"><?php echo e(money($bs['order_sales'], $cur)); ?></td></tr>
                <tr><td>Less: accepted POS/invoice returns</td><td class="text-right"><?php echo e(money($bs['returns'], $cur)); ?></td></tr>
                <tr><td>Less: expenses</td><td class="text-right"><?php echo e(money($bs['expenses'], $cur)); ?></td></tr>
                <tr class="strong"><td>Retained earnings</td><td class="text-right"><?php echo e(money($bs['net_profit'], $cur)); ?></td></tr>
            </tbody>
        </table>
        <p class="text-muted" style="font-size:11.5px;">
            Liquid capital is cash in hand plus bank balance. Stock capital is inventory at purchase price.
            Total brand capital is liquid plus stock. Capital is equity, not sales revenue.
            Enter capital movements on the Capital page.
        </p>
    <?php elseif ($type === 'sales'): ?>
        <table class="table table-bordered">
            <thead><tr><th>Channel</th><th class="text-right">Gross Sales</th><th class="text-right">Accepted Returns</th><th class="text-right">Net Sales</th><th class="text-right">Transactions</th></tr></thead>
            <tbody>
                <tr><td>POS</td><td class="text-right"><?php echo e(money($sales_summary['pos']['gross_sales'], $cur)); ?></td><td class="text-right"><?php echo e(money($sales_summary['pos']['accepted_returns'], $cur)); ?></td><td class="text-right"><?php echo e(money($sales_summary['pos']['net_sales'], $cur)); ?></td><td class="text-right"><?php echo (int)$sales_summary['pos']['sale_count']; ?></td></tr>
                <tr><td>Invoice</td><td class="text-right"><?php echo e(money($sales_summary['invoice']['gross_sales'], $cur)); ?></td><td class="text-right"><?php echo e(money($sales_summary['invoice']['accepted_returns'], $cur)); ?></td><td class="text-right"><?php echo e(money($sales_summary['invoice']['net_sales'], $cur)); ?></td><td class="text-right"><?php echo (int)$sales_summary['invoice']['sale_count']; ?></td></tr>
                <tr class="strong"><td>Combined</td><td class="text-right"><?php echo e(money($sales_summary['gross_sales'], $cur)); ?></td><td class="text-right"><?php echo e(money($sales_summary['accepted_returns'], $cur)); ?></td><td class="text-right"><?php echo e(money($sales_summary['net_sales'], $cur)); ?></td><td class="text-right"><?php echo (int)$sales_summary['sale_count']; ?></td></tr>
            </tbody>
        </table>

        <h4>Sales by Shop</h4>
        <table class="table table-bordered">
            <thead><tr><th>Shop</th><th class="text-right">POS Gross</th><th class="text-right">POS Returns</th><th class="text-right">POS Net</th><th class="text-right">Invoice Gross</th><th class="text-right">Invoice Returns</th><th class="text-right">Invoice Net</th><th class="text-right">Combined Net</th></tr></thead>
            <tbody>
            <?php foreach ($sales_by_shop as $shop): ?>
                <tr>
                    <td><?php echo e($shop['shop_name']); ?></td>
                    <td class="text-right"><?php echo e(money($shop['pos_gross_sales'], $cur)); ?></td>
                    <td class="text-right"><?php echo e(money($shop['pos_accepted_returns'], $cur)); ?></td>
                    <td class="text-right"><?php echo e(money($shop['pos_net_sales'], $cur)); ?></td>
                    <td class="text-right"><?php echo e(money($shop['invoice_gross_sales'], $cur)); ?></td>
                    <td class="text-right"><?php echo e(money($shop['invoice_accepted_returns'], $cur)); ?></td>
                    <td class="text-right"><?php echo e(money($shop['invoice_net_sales'], $cur)); ?></td>
                    <td class="text-right font-weight-bold"><?php echo e(money($shop['net_sales'], $cur)); ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>

        <h4>Product Sales and Accepted Returns</h4>
        <table class="table table-bordered">
            <thead><tr><th>Product</th><th class="text-right">Sold Qty</th><th class="text-right">Returned Qty</th><th class="text-right">Resale Qty</th><th class="text-right">Net Qty</th><th class="text-right">Gross</th><th class="text-right">Returns</th><th class="text-right">Net</th></tr></thead>
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
                    <td class="text-right font-weight-bold"><?php echo e(money($product['net_sales'], $cur)); ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>

        <h4>POS Transactions</h4>
        <table class="table table-bordered">
            <thead><tr><th>Order #</th><th>Date</th><th>Shop</th><th>Customer</th><th>Status</th><th class="text-right">Gross</th><th class="text-right">Returns</th><th class="text-right">Net</th></tr></thead>
            <tbody>
            <?php foreach ($rows as $r): ?>
                <tr>
                    <td><?php echo e($r['order_number']); ?></td>
                    <td><?php echo e(date('M d, Y', strtotime($r['order_date']))); ?></td>
                    <td><?php echo e($r['shop_name'] ?: 'Unattributed (historical)'); ?></td>
                    <td><?php echo e(trim(($r['first_name'] ?? '') . ' ' . ($r['last_name'] ?? '')) ?: ($r['company_name'] ?: 'Walk-in Customer')); ?></td>
                    <td><?php echo e(pos_sale_payment_status($r['status'], $r['total'], $r['amount_paid'] ?? 0)['label']); ?></td>
                    <td class="text-right"><?php echo e(money($r['total'], $cur)); ?></td>
                    <td class="text-right"><?php echo e(money($r['accepted_returns'], $cur)); ?></td>
                    <td class="text-right"><?php echo e(money($r['net_sales'], $cur)); ?></td>
                </tr>
            <?php endforeach; ?>
            <tr class="totals-row"><td colspan="5">TOTAL — <?php echo (int)$totals['count']; ?> completed sales</td><td class="text-right"><?php echo e(money($totals['total'], $cur)); ?></td><td class="text-right"><?php echo e(money($sales_summary['pos']['accepted_returns'], $cur)); ?></td><td class="text-right"><?php echo e(money($sales_summary['pos']['net_sales'], $cur)); ?></td></tr>
            </tbody>
        </table>
    <?php elseif ($type === 'invoice-sales'): ?>
        <table class="table table-bordered">
            <thead><tr><th>Gross Invoice Sales</th><th>Accepted Returns</th><th>Net Invoice Sales</th><th>Outstanding Balance</th><th>Refund Due</th><th>Partial Returns</th><th>Full Returns</th></tr></thead>
            <tbody><tr>
                <td class="text-right"><?php echo e(money($invoice_summary['gross_sales'], $cur)); ?></td>
                <td class="text-right"><?php echo e(money($invoice_summary['accepted_returns'], $cur)); ?></td>
                <td class="text-right font-weight-bold"><?php echo e(money($invoice_summary['net_sales'], $cur)); ?></td>
                <td class="text-right"><?php echo e(money($invoice_summary['outstanding_balance'], $cur)); ?></td>
                <td class="text-right"><?php echo e(money($invoice_summary['refund_due'], $cur)); ?></td>
                <td class="text-right"><?php echo (int)$invoice_summary['partial_return_count']; ?></td>
                <td class="text-right"><?php echo (int)$invoice_summary['full_return_count']; ?></td>
            </tr></tbody>
        </table>
        <div class="small text-muted mb-2">Refund due is not actual cash refunded. Returned quantity and quantity accepted for resale are distinct.</div>

        <h4>Invoice Status Summary</h4>
        <table class="table table-bordered">
            <thead><tr><th>Status</th><th class="text-right">Invoices</th><th class="text-right">Total</th><th class="text-right">Outstanding</th><th class="text-right">Refund Due</th></tr></thead>
            <tbody>
            <?php foreach ($invoice_status_summary as $status): ?>
                <tr><td><?php echo e($status['status_name']); ?></td><td class="text-right"><?php echo (int)$status['invoice_count']; ?></td><td class="text-right"><?php echo e(money($status['invoice_total'], $cur)); ?></td><td class="text-right"><?php echo e(money($status['outstanding_balance'], $cur)); ?></td><td class="text-right"><?php echo e(money($status['refund_due'], $cur)); ?></td></tr>
            <?php endforeach; ?>
            </tbody>
        </table>

        <h4>Invoice Transactions</h4>
        <table class="table table-bordered">
            <thead><tr><th>Invoice #</th><th>Issue Date</th><th>Shop</th><th>Status</th><th>Return State</th><th class="text-right">Gross</th><th class="text-right">Returns</th><th class="text-right">Net</th><th class="text-right">Due</th><th class="text-right">Refund Due</th></tr></thead>
            <tbody>
            <?php foreach ($rows as $r): ?>
                <tr>
                    <td><?php echo e($r['invoice_number']); ?></td>
                    <td><?php echo e(date('M d, Y', strtotime($r['issue_date']))); ?></td>
                    <td><?php echo e($r['shop_name'] ?: 'Unattributed (historical)'); ?></td>
                    <td><?php echo e($r['status_name'] ?: 'Unknown'); ?></td>
                    <td><?php echo e($r['return_state']); ?></td>
                    <td class="text-right"><?php echo e(money($r['total'], $cur)); ?></td>
                    <td class="text-right"><?php echo e(money($r['accepted_returns'], $cur)); ?></td>
                    <td class="text-right"><?php echo e(money($r['net_sales'], $cur)); ?></td>
                    <td class="text-right"><?php echo e(money($r['amount_due'], $cur)); ?></td>
                    <td class="text-right"><?php echo e(money($r['refund_due'], $cur)); ?></td>
                </tr>
            <?php endforeach; ?>
            <tr class="totals-row"><td colspan="5">TOTAL — <?php echo (int)$totals['count']; ?> eligible invoices</td><td class="text-right"><?php echo e(money($totals['total'], $cur)); ?></td><td class="text-right"><?php echo e(money($invoice_summary['accepted_returns'], $cur)); ?></td><td class="text-right"><?php echo e(money($invoice_summary['net_sales'], $cur)); ?></td><td class="text-right"><?php echo e(money($invoice_summary['outstanding_balance'], $cur)); ?></td><td class="text-right"><?php echo e(money($invoice_summary['refund_due'], $cur)); ?></td></tr>
            </tbody>
        </table>
    <?php elseif ($type === 'payments'): ?>
        <table class="table table-bordered">
            <thead><tr><th>Date</th><th>Invoice</th><th>Customer</th><th>Method</th><th class="text-right">Amount</th></tr></thead>
            <tbody>
            <?php foreach ($rows as $r): ?>
                <tr>
                    <td><?php echo e(date('M d, Y', strtotime($r['payment_date']))); ?></td>
                    <td><?php echo e($r['invoice_number'] ?? 'POS Sale'); ?></td>
                    <td><?php echo e(trim($r['customer_name']) ?: ($r['company_name'] ?: 'Walk-in Customer')); ?></td>
                    <td><?php echo e($r['method_name'] ?? '—'); ?></td>
                    <td class="text-right"><?php echo e(money($r['amount'], $cur)); ?></td>
                </tr>
            <?php endforeach; ?>
            <tr class="totals-row"><td colspan="4">TOTAL — <?php echo (int)$totals['count']; ?> payments</td><td class="text-right"><?php echo e(money($totals['total'], $cur)); ?></td></tr>
            </tbody>
        </table>
    <?php elseif ($type === 'expenses'): ?>
        <table class="table table-bordered">
            <thead><tr><th>Date</th><th>Category</th><th>Description</th><th>Paid Via</th><th class="text-right">Amount</th></tr></thead>
            <tbody>
            <?php foreach ($rows as $r): ?>
                <tr>
                    <td><?php echo e(date('M d, Y', strtotime($r['expense_date']))); ?></td>
                    <td><?php echo e($r['category_name']); ?></td>
                    <td><?php echo e($r['description']); ?></td>
                    <td><?php echo e($r['method_name'] ?? '—'); ?></td>
                    <td class="text-right"><?php echo e(money($r['amount'], $cur)); ?></td>
                </tr>
            <?php endforeach; ?>
            <tr class="totals-row"><td colspan="4">TOTAL — <?php echo (int)$totals['count']; ?> expenses</td><td class="text-right"><?php echo e(money($totals['total'], $cur)); ?></td></tr>
            </tbody>
        </table>
    <?php elseif ($type === 'receivables'): ?>
        <table class="table table-bordered">
            <thead><tr><th>Invoice</th><th>Issue Date</th><th>Due Date</th><th>Customer</th><th class="text-right">Total</th><th class="text-right">Balance Due</th></tr></thead>
            <tbody>
            <?php foreach ($rows as $r): ?>
                <tr>
                    <td><?php echo e($r['invoice_number']); ?></td>
                    <td><?php echo e(date('M d, Y', strtotime($r['issue_date']))); ?></td>
                    <td><?php echo e(date('M d, Y', strtotime($r['due_date']))); ?></td>
                    <td><?php echo e(trim($r['customer_name']) ?: ($r['company_name'] ?: 'Walk-in Customer')); ?></td>
                    <td class="text-right"><?php echo e(money($r['total'], $cur)); ?></td>
                    <td class="text-right font-weight-bold"><?php echo e(money($r['amount_due'], $cur)); ?></td>
                </tr>
            <?php endforeach; ?>
            <tr class="totals-row"><td colspan="5">TOTAL OUTSTANDING — <?php echo (int)$totals['count']; ?> invoices</td><td class="text-right"><?php echo e(money($totals['total'], $cur)); ?></td></tr>
            </tbody>
        </table>
    <?php elseif ($type === 'stock'): ?>
        <table class="table table-bordered">
            <thead><tr><th>Code</th><th>Product</th><th>Warehouse</th><th class="text-right">In Stock</th><th class="text-right">Reorder Level</th><th class="text-right">Unit Price</th><th>Status</th></tr></thead>
            <tbody>
            <?php foreach ($rows as $r): ?>
                <tr>
                    <td><?php echo e($r['code']); ?></td>
                    <td><?php echo e($r['name']); ?></td>
                    <td>
                        <?php echo e($r['warehouse'] ?? '—'); ?>
                        <?php if ((int)($r['is_godown'] ?? 0) === 1): ?>
                            <span class="badge badge-warning">Central Godown</span>
                        <?php endif; ?>
                    </td>
                    <td class="text-right"><?php echo (float)$r['quantity']; ?></td>
                    <td class="text-right"><?php echo (float)$r['min_stock_level']; ?></td>
                    <td class="text-right"><?php echo e(money($r['sale_price'], $cur)); ?></td>
                    <td><?php echo (float)$r['quantity'] <= (float)$r['min_stock_level'] ? '<span class="badge badge-danger">Low Stock</span>' : '<span class="badge badge-success">OK</span>'; ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php elseif ($type === 'activity'): ?>
        <table class="table table-bordered">
            <thead><tr><th>Action</th><th>Module</th><th class="text-right">Count</th></tr></thead>
            <tbody>
            <?php foreach ($rows as $r): ?>
                <tr><td class="text-uppercase"><?php echo e($r['action']); ?></td><td><?php echo e(str_replace('_', ' ', ucwords($r['entity_type'] ?? '', '_'))); ?></td><td class="text-right"><?php echo (int)$r['count']; ?></td></tr>
            <?php endforeach; ?>
            <tr class="totals-row"><td colspan="2">TOTAL EVENTS</td><td class="text-right"><?php echo (int)$totals['count']; ?></td></tr>
            </tbody>
        </table>
    <?php endif; ?>

    <div class="report-foot">
        Generated by <?php echo e(system_title()); ?> &middot; This report reflects records entered in the system for the selected period.
    </div>
</div>
</body>
</html>
