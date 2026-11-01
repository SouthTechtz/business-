<?php
require_once 'config.php';
require_once 'auth_check.php';
require_permission('view_reports');
require_once 'reporting.php';

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
$logo_path = (!empty($settings['logo_path']) && file_exists($settings['logo_path'])) ? $settings['logo_path'] : '';
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
        $expenses = fetch_all(
            "SELECT COALESCE(c.name, 'Uncategorised') category_name, SUM(e.amount) total
             FROM expenses e LEFT JOIN expense_categories c ON c.expense_category_id = e.category_id
             WHERE e.tenant_id = ?" . $expense_date['sql'] . " GROUP BY e.category_id ORDER BY total DESC",
            $current_user->tenant_id, ...$expense_date['params']
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
        $totals = ['net' => $net];
        break;

    case 'sales':
        $title = 'Sales Report (POS)';
        $sales_date = report_date_condition('o.order_date', $from, $to);
        $rows = fetch_all(
            "SELECT o.order_number, o.order_date, o.status, o.total, o.tax_amount,
                    CONCAT(COALESCE(c.first_name,''), ' ', COALESCE(c.last_name,'')) customer_name, c.company_name
             FROM pos_orders o LEFT JOIN contacts c ON c.contact_id = o.contact_id
             WHERE o.tenant_id = ?" . $pos_scope['sql'] . $sales_date['sql'] . " ORDER BY o.order_date DESC",
            $current_user->tenant_id, ...array_merge($pos_scope['params'], $sales_date['params'])
        );
        $totals['total'] = array_sum(array_map(fn($r) => (float)$r['total'], $rows));
        $totals['count'] = count($rows);
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
        $rows = fetch_all(
            "SELECT e.expense_date, e.description, e.amount, COALESCE(c.name,'Uncategorised') category_name, m.name method_name
             FROM expenses e
             LEFT JOIN expense_categories c ON c.expense_category_id = e.category_id
             LEFT JOIN payment_methods m ON m.payment_method_id = e.payment_method_id
             WHERE e.tenant_id = ?" . $expense_date['sql'] . " ORDER BY e.expense_date DESC",
            $current_user->tenant_id, ...$expense_date['params']
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
        $rows = fetch_all(
            "SELECT p.code, p.name, p.sale_price, COALESCE(SUM(s.quantity),0) quantity, p.min_stock_level,
                    w.name warehouse, MAX(COALESCE(w.is_godown, 0)) is_godown
             FROM products p
             LEFT JOIN inventory_stock s ON s.product_id = p.product_id AND s.tenant_id = p.tenant_id
             LEFT JOIN warehouses w ON w.warehouse_id = s.warehouse_id
             WHERE p.tenant_id = ? AND p.is_service = 0" . $stock_scope['sql'] . "
             GROUP BY p.product_id, s.warehouse_id ORDER BY p.name",
            $current_user->tenant_id, ...$stock_scope['params']
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
    <?php elseif ($type === 'sales'): ?>
        <table class="table table-bordered">
            <thead><tr><th>Order #</th><th>Date</th><th>Customer</th><th>Status</th><th class="text-right">VAT</th><th class="text-right">Total</th></tr></thead>
            <tbody>
            <?php foreach ($rows as $r): ?>
                <tr>
                    <td><?php echo e($r['order_number']); ?></td>
                    <td><?php echo e(date('M d, Y', strtotime($r['order_date']))); ?></td>
                    <td><?php echo e(trim($r['customer_name']) ?: ($r['company_name'] ?: 'Walk-in Customer')); ?></td>
                    <td><?php echo e(ucfirst($r['status'])); ?></td>
                    <td class="text-right"><?php echo e(money($r['tax_amount'], $cur)); ?></td>
                    <td class="text-right"><?php echo e(money($r['total'], $cur)); ?></td>
                </tr>
            <?php endforeach; ?>
            <tr class="totals-row"><td colspan="4">TOTAL — <?php echo (int)$totals['count']; ?> sales</td><td class="text-right"></td><td class="text-right"><?php echo e(money($totals['total'], $cur)); ?></td></tr>
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
        Generated by <?php echo e(APP_NAME); ?> &middot; This report reflects records entered in the system for the selected period.
    </div>
</div>
</body>
</html>
