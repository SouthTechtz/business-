<?php
require_once 'config.php';
require_once 'auth_check.php';
require_permission('manage_invoices');
require_once 'reporting.php';

$period = report_resolve_period($_GET);
$warehouse_filter = (int)($_GET['warehouse'] ?? 0);
$allowedShopIds = report_accessible_sales_warehouse_ids();
if ($warehouse_filter > 0 && $allowedShopIds !== null && !in_array($warehouse_filter, $allowedShopIds, true)) {
    $warehouse_filter = 0;
}

$invoices = report_invoice_sale_rows($period['from'], $period['to'], [
    'warehouse_id' => $warehouse_filter,
    'status_id' => (int)($_GET['status'] ?? 0),
    'search' => trim((string)($_GET['q'] ?? '')),
]);
$totalGross = $totalReturns = $totalNet = $totalDue = $totalRefund = 0.0;
foreach ($invoices as $invoice) {
    $fullyReturned = (float)$invoice['total'] > 0 && (float)$invoice['returned_amount'] >= (float)$invoice['total'] - 0.01;
    $eligible = $invoice['status_name'] !== 'Cancelled' || $fullyReturned;
    if (!$eligible) continue;
    $totalGross += (float)$invoice['total'];
    $totalReturns += (float)$invoice['accepted_returns'];
    $totalNet += (float)$invoice['net_sales'];
    $totalDue += (float)$invoice['amount_due'];
    $totalRefund += (float)$invoice['refund_due'];
}

function esc($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function cell($v, $type = 'String') { return "<Cell><Data ss:Type=\"$type\">" . esc($v) . "</Data></Cell>"; }
function row($cells) { return "<Row>" . implode('', $cells) . "</Row>"; }

header('Content-Type: application/vnd.ms-excel; charset=UTF-8');
header('Content-Disposition: attachment; filename="invoices-' . date('Ymd-His') . '.xls"');

echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
echo '<?mso-application progid="Excel.Sheet"?>' . "\n";
?>
<Workbook xmlns="urn:schemas-microsoft-com:office:spreadsheet"
          xmlns:o="urn:schemas-microsoft-com:office:office"
          xmlns:x="urn:schemas-microsoft-com:office:excel"
          xmlns:ss="urn:schemas-microsoft-com:office:spreadsheet"
          xmlns:html="http://www.w3.org/TR/REC-html40">
<Worksheet ss:Name="Invoices">
<Table>
<?php echo row([cell('Invoice #'), cell('Customer'), cell('Shop'), cell('Issue Date'), cell('Due Date'), cell('Status'), cell('Return State'), cell('Subtotal', 'Number'), cell('VAT', 'Number'), cell('Gross Sale', 'Number'), cell('Returned Qty', 'Number'), cell('Resale Qty', 'Number'), cell('Accepted Returns', 'Number'), cell('Net Sale', 'Number'), cell('Paid', 'Number'), cell('Balance', 'Number'), cell('Refund Due', 'Number')]); ?>
<?php foreach ($invoices as $inv): ?>
<?php echo row([
    cell($inv['invoice_number']),
    cell(trim(($inv['first_name'] ?? '') . ' ' . ($inv['last_name'] ?? '')) ?: $inv['company_name'] ?: 'Walk-in Customer'),
    cell($inv['shop_name'] ?: 'Unattributed (historical)'),
    cell(date('Y-m-d', strtotime($inv['issue_date']))),
    cell(!empty($inv['due_date']) && $inv['due_date'] !== '0000-00-00' ? date('Y-m-d', strtotime($inv['due_date'])) : ''),
    cell($inv['status_name'] ?? ''),
    cell($inv['return_state']),
    cell(number_format((float)$inv['subtotal'], 2, '.', ''), 'Number'),
    cell(number_format((float)$inv['tax_amount'], 2, '.', ''), 'Number'),
    cell(number_format((float)$inv['total'], 2, '.', ''), 'Number'),
    cell(number_format((float)$inv['returned_qty'], 2, '.', ''), 'Number'),
    cell(number_format((float)$inv['resale_qty'], 2, '.', ''), 'Number'),
    cell(number_format((float)$inv['accepted_returns'], 2, '.', ''), 'Number'),
    cell(number_format((float)$inv['net_sales'], 2, '.', ''), 'Number'),
    cell(number_format((float)$inv['amount_paid'], 2, '.', ''), 'Number'),
    cell(number_format((float)$inv['amount_due'], 2, '.', ''), 'Number'),
    cell(number_format((float)$inv['refund_due'], 2, '.', ''), 'Number'),
]); ?>
<?php endforeach; ?>
<?php echo row([cell('TOTALS'), cell(''), cell(''), cell(''), cell(''), cell(''), cell(''), cell(''), cell(''), cell(number_format($totalGross, 2, '.', ''), 'Number'), cell(''), cell(''), cell(number_format($totalReturns, 2, '.', ''), 'Number'), cell(number_format($totalNet, 2, '.', ''), 'Number'), cell(''), cell(number_format($totalDue, 2, '.', ''), 'Number'), cell(number_format($totalRefund, 2, '.', ''), 'Number')]); ?>
</Table>
</Worksheet>
</Workbook>
