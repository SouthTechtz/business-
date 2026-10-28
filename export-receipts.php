<?php
require_once 'config.php';
require_once 'auth_check.php';
require_permission('manage_receipts');

$payment_sql = "SELECT p.*, pm.name method_name, i.invoice_number, c.first_name, c.last_name, c.company_name
     FROM payments p
     JOIN invoices i ON i.invoice_id = p.invoice_id
     LEFT JOIN payment_methods pm ON pm.payment_method_id = p.payment_method_id
     LEFT JOIN contacts c ON c.contact_id = p.contact_id
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

function esc($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function cell($v, $type = 'String') { return "<Cell><Data ss:Type=\"$type\">" . esc($v) . "</Data></Cell>"; }
function row($cells) { return "<Row>" . implode('', $cells) . "</Row>"; }

header('Content-Type: application/vnd.ms-excel; charset=UTF-8');
header('Content-Disposition: attachment; filename="payments-' . date('Ymd-His') . '.xls"');

echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
echo '<?mso-application progid="Excel.Sheet"?>' . "\n";
?>
<Workbook xmlns="urn:schemas-microsoft-com:office:spreadsheet"
          xmlns:o="urn:schemas-microsoft-com:office:office"
          xmlns:x="urn:schemas-microsoft-com:office:excel"
          xmlns:ss="urn:schemas-microsoft-com:office:spreadsheet"
          xmlns:html="http://www.w3.org/TR/REC-html40">
<Worksheet ss:Name="Payments">
<Table>
<?php echo row([cell('Date'), cell('Invoice #'), cell('Customer'), cell('Method'), cell('Amount', 'Number')]); ?>
<?php foreach ($payments as $p): ?>
<?php echo row([
    cell(date('Y-m-d H:i', strtotime($p['payment_date']))),
    cell($p['invoice_number']),
    cell(trim(($p['first_name'] ?? '') . ' ' . ($p['last_name'] ?? '')) ?: $p['company_name'] ?: 'Walk-in Customer'),
    cell($p['method_name'] ?? ''),
    cell(number_format((float)$p['amount'], 2, '.', ''), 'Number'),
]); ?>
<?php endforeach; ?>
</Table>
</Worksheet>
</Workbook>
