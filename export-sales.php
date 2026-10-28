<?php
require_once 'config.php';
require_once 'auth_check.php';
require_permission('manage_pos');

$status = $_GET['status'] ?? '';
$customer_id = (int)($_GET['customer'] ?? 0);
$date_from = $_GET['date_from'] ?? date('Y-m-01');
$date_to = $_GET['date_to'] ?? date('Y-m-d');
$warehouse_filter = (int)($_GET['warehouse'] ?? 0);
$stores = accessible_stores(null, true);
$store_ids = array_map(fn($s) => (int)$s['warehouse_id'], $stores);
if ($warehouse_filter > 0 && !in_array($warehouse_filter, $store_ids, true)) {
    $warehouse_filter = 0;
}

$sql = "SELECT o.*, c.first_name, c.last_name, c.company_name,
               w.name store_name, s.name shop_name,
               COALESCE(SUM(oi.total), 0) items_total,
               COALESCE(SUM(oi.quantity), 0) items_count
        FROM pos_orders o
        LEFT JOIN contacts c ON c.contact_id = o.contact_id
        LEFT JOIN pos_order_items oi ON oi.order_id = o.order_id
        LEFT JOIN warehouses w ON w.warehouse_id = o.warehouse_id
        LEFT JOIN shops s ON s.shop_id = w.shop_id
        WHERE o.tenant_id = ? AND DATE(o.order_date) BETWEEN ? AND ?";
$params = [$current_user->tenant_id, $date_from, $date_to];
$types = 'iss';

$scope_ids = accessible_warehouse_ids();
if ($scope_ids !== null) {
    if (!$scope_ids) {
        $sql .= " AND 1 = 0";
    } else {
        $sql .= " AND o.warehouse_id IN (" . implode(',', array_map('intval', $scope_ids)) . ")";
    }
}
if ($warehouse_filter > 0) {
    $sql .= " AND o.warehouse_id = ?";
    $params[] = $warehouse_filter;
    $types .= 'i';
}
if ($status !== '') {
    $sql .= " AND o.status = ?";
    $params[] = $status;
    $types .= 's';
}
if ($customer_id > 0) {
    $sql .= " AND o.contact_id = ?";
    $params[] = $customer_id;
    $types .= 'i';
}
$sql .= " GROUP BY o.order_id ORDER BY o.order_date DESC";

$orders = fetch_all($sql, ...$params);

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
<?php echo row([cell('Order #'), cell('Date'), cell('Customer'), cell('Store'), cell('Items'), cell('Status'), cell('Subtotal', 'Number'), cell('VAT', 'Number'), cell('Discount', 'Number'), cell('Total', 'Number')]); ?>
<?php foreach ($orders as $o): ?>
<?php echo row([
    cell($o['order_number']),
    cell(date('Y-m-d H:i', strtotime($o['order_date']))),
    cell(trim(($o['first_name'] ?? '') . ' ' . ($o['last_name'] ?? '')) ?: $o['company_name'] ?: 'Walk-in Customer'),
    cell($o['store_name'] ? trim(($o['shop_name'] ?? '') . ' / ' . $o['store_name'], ' /') : ''),
    cell((float)$o['items_count'], 'Number'),
    cell(ucfirst($o['status'])),
    cell(number_format((float)$o['subtotal'], 2, '.', ''), 'Number'),
    cell(number_format((float)$o['tax_amount'], 2, '.', ''), 'Number'),
    cell(number_format((float)$o['discount'], 2, '.', ''), 'Number'),
    cell(number_format((float)$o['total'], 2, '.', ''), 'Number'),
]); ?>
<?php endforeach; ?>
</Table>
</Worksheet>
</Workbook>
