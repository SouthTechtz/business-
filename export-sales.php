<?php
require_once 'config.php';
require_once 'auth_check.php';
require_permission('manage_pos');
require_once 'reporting.php';

$period = report_resolve_period([
    'from' => $_GET['date_from'] ?? date('Y-m-01'),
    'to' => $_GET['date_to'] ?? date('Y-m-d'),
]);
$warehouse_filter = (int)($_GET['warehouse'] ?? 0);
$brand_filter = trim((string)($_GET['brand'] ?? ''));
if ($brand_filter !== '' && !in_array($brand_filter, product_brand_options(), true)) {
    $brand_filter = '';
}
$allowedShopIds = report_accessible_sales_warehouse_ids();
if ($warehouse_filter > 0 && $allowedShopIds !== null && !in_array($warehouse_filter, $allowedShopIds, true)) {
    $warehouse_filter = 0;
}

$orders = report_pos_sale_rows($period['from'], $period['to'], [
    'warehouse_id' => $warehouse_filter,
    'status' => (string)($_GET['status'] ?? ''),
    'customer_id' => (int)($_GET['customer'] ?? 0),
    'brand' => $brand_filter,
]);
$totalGross = $totalReturns = $totalNet = 0.0;
foreach ($orders as $order) {
    if ($order['status'] !== 'completed') continue;
    $totalGross += (float)$order['total'];
    $totalReturns += (float)$order['accepted_returns'];
    $totalNet += (float)$order['net_sales'];
}

function esc($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function cell($v, $type = 'String') { return "<Cell><Data ss:Type=\"$type\">" . esc($v) . "</Data></Cell>"; }
function row($cells) { return "<Row>" . implode('', $cells) . "</Row>"; }

header('Content-Type: application/vnd.ms-excel; charset=UTF-8');
header('Content-Disposition: attachment; filename="sales-' . date('Ymd-His') . '.xls"');

echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
echo '<?mso-application progid="Excel.Sheet"?>' . "\n";
?>
<Workbook xmlns="urn:schemas-microsoft-com:office:spreadsheet"
          xmlns:o="urn:schemas-microsoft-com:office:office"
          xmlns:x="urn:schemas-microsoft-com:office:excel"
          xmlns:ss="urn:schemas-microsoft-com:office:spreadsheet"
          xmlns:html="http://www.w3.org/TR/REC-html40">
<Worksheet ss:Name="Sales">
<Table>
<?php echo row([cell('Order #'), cell('Date'), cell('Customer'), cell('Shop'), cell('Brand'), cell('Items'), cell('Status'), cell('Subtotal', 'Number'), cell('VAT', 'Number'), cell('Discount', 'Number'), cell('Gross Sale', 'Number'), cell('Returned Qty', 'Number'), cell('Resale Qty', 'Number'), cell('Accepted Returns', 'Number'), cell('Net Sale', 'Number')]); ?>
<?php foreach ($orders as $o): ?>
<?php echo row([
    cell($o['order_number']),
    cell(date('Y-m-d H:i', strtotime($o['order_date']))),
    cell(trim(($o['first_name'] ?? '') . ' ' . ($o['last_name'] ?? '')) ?: $o['company_name'] ?: 'Walk-in Customer'),
    cell($o['shop_name'] ?: 'Unattributed (historical)'),
    cell(sale_brand_info($o['shop_brand'] ?? '', $o['product_brands'] ?? '')['label']),
    cell((float)$o['items_count'], 'Number'),
    cell(pos_sale_payment_status($o['status'], $o['total'], $o['amount_paid'] ?? 0)['label']),
    cell(number_format((float)$o['subtotal'], 2, '.', ''), 'Number'),
    cell(number_format((float)$o['tax_amount'], 2, '.', ''), 'Number'),
    cell(number_format((float)$o['discount'], 2, '.', ''), 'Number'),
    cell(number_format((float)$o['total'], 2, '.', ''), 'Number'),
    cell(number_format((float)$o['returned_qty'], 2, '.', ''), 'Number'),
    cell(number_format((float)$o['resale_qty'], 2, '.', ''), 'Number'),
    cell(number_format((float)$o['accepted_returns'], 2, '.', ''), 'Number'),
    cell(number_format((float)$o['net_sales'], 2, '.', ''), 'Number'),
]); ?>
<?php endforeach; ?>
<?php echo row([cell('TOTALS'), cell(''), cell(''), cell(''), cell(''), cell(''), cell(''), cell(''), cell(''), cell(number_format($totalGross, 2, '.', ''), 'Number'), cell(''), cell(''), cell(number_format($totalReturns, 2, '.', ''), 'Number'), cell(number_format($totalNet, 2, '.', ''), 'Number')]); ?>
</Table>
</Worksheet>
</Workbook>
