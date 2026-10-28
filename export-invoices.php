<?php
require_once 'config.php';
require_once 'auth_check.php';
require_permission('manage_invoices');

$status_filter = (int)($_GET['status'] ?? 0);
$search = trim($_GET['q'] ?? '');
$warehouse_filter = (int)($_GET['warehouse'] ?? 0);
$stores = accessible_stores(null, true);
$store_ids = array_map(fn($s) => (int)$s['warehouse_id'], $stores);
if ($warehouse_filter > 0 && !in_array($warehouse_filter, $store_ids, true)) {
    $warehouse_filter = 0;
}

$sql = "SELECT i.*, ds.name status_name, c.first_name, c.last_name, c.company_name,
               w.name store_name, s.name shop_name
        FROM invoices i
        LEFT JOIN document_statuses ds ON ds.status_id = i.status_id
        LEFT JOIN contacts c ON c.contact_id = i.contact_id
        LEFT JOIN warehouses w ON w.warehouse_id = i.warehouse_id
        LEFT JOIN shops s ON s.shop_id = w.shop_id
        WHERE i.tenant_id = ?";
$params = [$current_user->tenant_id];
$types = 'i';
$scope_ids = accessible_warehouse_ids();
if ($scope_ids !== null) {
    if (!$scope_ids) {
        $sql .= " AND 1 = 0";
    } else {
        $sql .= " AND i.warehouse_id IN (" . implode(',', array_map('intval', $scope_ids)) . ")";
    }
}
if ($warehouse_filter > 0) {
    $sql .= " AND i.warehouse_id = ?";
    $params[] = $warehouse_filter;
    $types .= 'i';
}
if ($status_filter > 0) {
    $sql .= " AND i.status_id = ?";
    $params[] = $status_filter;
    $types .= 'i';
}
if ($search !== '') {
    $sql .= " AND (i.invoice_number LIKE ? OR c.first_name LIKE ? OR c.last_name LIKE ? OR c.company_name LIKE ?)";
    $like = '%' . $search . '%';
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $types .= 'ssss';
}
$sql .= " ORDER BY i.issue_date DESC, i.invoice_id DESC";

$invoices = fetch_all($sql, ...$params);

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
<?php echo row([cell('Invoice #'), cell('Customer'), cell('Store'), cell('Issue Date'), cell('Due Date'), cell('Status'), cell('Subtotal', 'Number'), cell('VAT', 'Number'), cell('Total', 'Number'), cell('Paid', 'Number'), cell('Balance', 'Number')]); ?>
<?php foreach ($invoices as $inv): ?>
<?php echo row([
    cell($inv['invoice_number']),
    cell(trim(($inv['first_name'] ?? '') . ' ' . ($inv['last_name'] ?? '')) ?: $inv['company_name'] ?: 'Walk-in Customer'),
    cell($inv['store_name'] ? trim(($inv['shop_name'] ?? '') . ' / ' . $inv['store_name'], ' /') : ''),
    cell(date('Y-m-d', strtotime($inv['issue_date']))),
    cell(!empty($inv['due_date']) && $inv['due_date'] !== '0000-00-00' ? date('Y-m-d', strtotime($inv['due_date'])) : ''),
    cell($inv['status_name'] ?? ''),
    cell(number_format((float)$inv['subtotal'], 2, '.', ''), 'Number'),
    cell(number_format((float)$inv['tax_amount'], 2, '.', ''), 'Number'),
    cell(number_format((float)$inv['total'], 2, '.', ''), 'Number'),
    cell(number_format((float)$inv['amount_paid'], 2, '.', ''), 'Number'),
    cell(number_format((float)$inv['amount_due'], 2, '.', ''), 'Number'),
]); ?>
<?php endforeach; ?>
</Table>
</Worksheet>
</Workbook>
