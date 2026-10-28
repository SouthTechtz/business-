<?php
require_once 'config.php';
require_once 'auth_check.php';
require_permission('manage_expenses');

$month = trim($_GET['month'] ?? '');
$cat_filter = (int)($_GET['category'] ?? 0);
$where = "WHERE e.tenant_id = ?";
$params = [$current_user->tenant_id];
$types = 'i';
if ($month !== '' && preg_match('/^\d{4}-\d{2}$/', $month)) {
    $where .= " AND DATE_FORMAT(e.expense_date, '%Y-%m') = ?";
    $params[] = $month;
    $types .= 's';
}
if ($cat_filter > 0) {
    $where .= " AND e.category_id = ?";
    $params[] = $cat_filter;
    $types .= 'i';
}

$expenses = fetch_all(
    "SELECT e.*, c.name category_name, m.name method_name
     FROM expenses e
     LEFT JOIN expense_categories c ON c.expense_category_id = e.category_id
     LEFT JOIN payment_methods m ON m.payment_method_id = e.payment_method_id
     $where ORDER BY e.expense_date DESC, e.expense_id DESC",
    ...$params
);

$exp_ids = array_map(fn($e) => (int)$e['expense_id'], $expenses);
$items_by_expense = [];
if ($exp_ids) {
    $in = implode(',', $exp_ids);
    $item_rows = fetch_all(
        "SELECT expense_id, name, quantity, unit_price, total FROM expense_items
         WHERE tenant_id = ? AND expense_id IN ($in) ORDER BY item_id",
        $current_user->tenant_id
    );
    foreach ($item_rows as $ir) {
        $items_by_expense[(int)$ir['expense_id']][] = $ir;
    }
}

function esc($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function cell($v, $type = 'String') { return "<Cell><Data ss:Type=\"$type\">" . esc($v) . "</Data></Cell>"; }
function row($cells) { return "<Row>" . implode('', $cells) . "</Row>"; }

header('Content-Type: application/vnd.ms-excel; charset=UTF-8');
header('Content-Disposition: attachment; filename="expenses-' . date('Ymd-His') . '.xls"');

echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
echo '<?mso-application progid="Excel.Sheet"?>' . "\n";
?>
<Workbook xmlns="urn:schemas-microsoft-com:office:spreadsheet"
          xmlns:o="urn:schemas-microsoft-com:office:office"
          xmlns:x="urn:schemas-microsoft-com:office:excel"
          xmlns:ss="urn:schemas-microsoft-com:office:spreadsheet"
          xmlns:html="http://www.w3.org/TR/REC-html40">
<Worksheet ss:Name="Expenses">
<Table>
<?php echo row([cell('Date'), cell('Category'), cell('Items (name x qty @ unit price)'), cell('Method'), cell('Notes'), cell('Total', 'Number')]); ?>
<?php foreach ($expenses as $e): ?>
<?php
    $lines = $items_by_expense[(int)$e['expense_id']] ?? [];
    $detail = $lines
        ? implode(' | ', array_map(fn($li) => $li['name'] . ' x' . rtrim(rtrim(number_format((float)$li['quantity'], 2, '.', ''), '0'), '.') . ' @' . number_format((float)$li['unit_price'], 0), $lines))
        : $e['description'];
    echo row([
        cell($e['expense_date']),
        cell($e['category_name'] ?? ''),
        cell($detail),
        cell($e['method_name'] ?? ''),
        cell($e['notes'] ?? ''),
        cell(number_format((float)$e['amount'], 2, '.', ''), 'Number'),
    ]);
?>
<?php endforeach; ?>
</Table>
</Worksheet>
</Workbook>
