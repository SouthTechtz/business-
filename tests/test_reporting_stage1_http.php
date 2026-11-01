<?php
/** Runtime HTTP checks against a local PHP development server. */
require_once __DIR__ . '/../config.php';
$base = getenv('STAGE1_BASE_URL') ?: 'http://127.0.0.1:8087';
$storeSaleReference = fetch_one("SELECT order_number FROM pos_orders WHERE tenant_id = 1 AND warehouse_id = 1 ORDER BY order_id LIMIT 1");
$passes = 0;
$failures = 0;

function stage1_http_assert($condition, $message) {
    global $passes, $failures;
    if (!$condition) {
        $failures++;
        echo "FAIL: $message\n";
        return;
    }
    $passes++;
    echo "PASS: $message\n";
}

function stage1_http_get($url) {
    $context = stream_context_create(['http' => ['method' => 'GET', 'ignore_errors' => true, 'timeout' => 15]]);
    $body = file_get_contents($url, false, $context);
    $status = 0;
    $headers = $http_response_header ?? [];
    if ($headers && preg_match('/\s(\d{3})\s/', $headers[0], $match)) $status = (int)$match[1];
    return ['status' => $status, 'body' => $body === false ? '' : $body, 'headers' => $headers];
}

function stage1_page_assert($base, $path, $expectedText) {
    $separator = strpos($path, '?') === false ? '?' : '&';
    $response = stage1_http_get($base . '/' . $path . $separator . '__stage1_user=1');
    $clean = !preg_match('/Fatal error|Warning:|Notice:|mysqli_sql_exception|Undefined (function|variable|array key)/i', $response['body']);
    stage1_http_assert($response['status'] === 200 && $clean && stripos($response['body'], $expectedText) !== false, "$path loads successfully");
    return $response;
}

foreach (['today', 'week', 'month', 'year', 'all'] as $period) {
    stage1_page_assert($base, 'reports.php?period=' . $period, 'Total POS Orders');
}
stage1_page_assert($base, 'reports.php?from=2026-01-01&to=2026-12-31', 'Showing data from');
stage1_page_assert($base, 'reports.php?from=2026-02-30&to=2026-01-31', 'valid calendar date');
stage1_page_assert($base, 'reports.php?from=2026-02-01&to=2026-01-01', 'must be on or before');
$injection = rawurlencode("2026-01-01' OR 1=1 -- ");
stage1_page_assert($base, 'reports.php?from=' . $injection . '&to=2026-12-31', 'valid calendar date');

$printTypes = [
    'income' => 'Income Statement',
    'sales' => 'Sales Report',
    'payments' => 'Payments Received Report',
    'expenses' => 'Expenses Report',
    'receivables' => 'Receivables Report',
    'stock' => 'Stock / Inventory Report',
    'activity' => 'System Activity Summary',
];
foreach ($printTypes as $type => $title) {
    stage1_page_assert($base, 'report-print.php?type=' . $type . '&from=2026-01-01&to=2026-12-31', $title);
}
$adminSalesPrint = stage1_page_assert($base, 'report-print.php?type=sales', 'Sales Report');
if ($storeSaleReference) {
    stage1_http_assert(strpos($adminSalesPrint['body'], $storeSaleReference['order_number']) === false, 'actual Store-attributed POS sale is absent from administrator sales report');
}
stage1_page_assert($base, 'report-print.php?type=activity&from=' . $injection . '&to=2026-12-31', 'valid calendar date');
stage1_page_assert($base, 'report-print.php?type=sales&from=2026-02-01&to=2026-01-01', 'No date filter was applied');

$exports = [
    'export-sales.php?date_from=2026-01-01&date_to=2026-12-31',
    'export-invoices.php',
    'export-receipts.php',
    'export-expenses.php?month=2026-10',
];
foreach ($exports as $path) {
    $separator = strpos($path, '?') === false ? '?' : '&';
    $response = stage1_http_get($base . '/' . $path . $separator . '__stage1_user=1');
    $contentType = implode("\n", $response['headers']);
    $clean = !preg_match('/Fatal error|Warning:|Notice:|mysqli_sql_exception|Undefined (function|variable|array key)/i', $response['body']);
    stage1_http_assert($response['status'] === 200 && $clean && stripos($response['body'], '<Workbook') !== false && stripos($contentType, 'application/vnd.ms-excel') !== false, "$path exports an Excel workbook");
}
$exportInjection = stage1_http_get($base . '/export-sales.php?date_from=' . $injection . '&date_to=2026-12-31&__stage1_user=1');
stage1_http_assert($exportInjection['status'] === 200 && stripos($exportInjection['body'], '<Workbook') !== false && !preg_match('/Fatal error|Warning:|Notice:|mysqli_sql_exception/i', $exportInjection['body']), 'sales export handles injection-style date without SQL errors');
$expenseInjection = stage1_http_get($base . '/export-expenses.php?month=' . $injection . '&__stage1_user=1');
stage1_http_assert($expenseInjection['status'] === 200 && stripos($expenseInjection['body'], '<Workbook') !== false && !preg_match('/Fatal error|Warning:|Notice:|mysqli_sql_exception/i', $expenseInjection['body']), 'expense export rejects invalid month input without SQL errors');

$shopReport = stage1_http_get($base . '/reports.php?period=all&__stage1_user=20');
$shopClean = !preg_match('/Fatal error|Warning:|Notice:|mysqli_sql_exception|Undefined (function|variable|array key)/i', $shopReport['body']);
stage1_http_assert($shopReport['status'] === 200 && $shopClean && stripos($shopReport['body'], 'Total POS Orders') !== false, 'reports page loads for Shop-scoped user');
$zeroInvoice = preg_match('/<div class="widget-value"[^>]*>\s*TZS\s+0\.00\s*<\/div><div class="widget-label">Invoiced Amount<\/div>/', $shopReport['body']);
stage1_http_assert((bool)$zeroInvoice, 'Shop user does not receive unattributed historical invoice totals');

$shopStock = stage1_http_get($base . '/report-print.php?type=stock&__stage1_user=20');
$shopStockClean = !preg_match('/Fatal error|Warning:|Notice:|mysqli_sql_exception|Undefined (function|variable|array key)/i', $shopStock['body']);
stage1_http_assert($shopStock['status'] === 200 && $shopStockClean && stripos($shopStock['body'], 'Stock / Inventory Report') !== false, 'printable stock report loads for Shop-scoped user');
stage1_http_assert(strpos($shopStock['body'], '>Main Shop<') === false, 'Shop stock report excludes the unauthorized Main Shop Store');

echo "RESULT: $passes passed, $failures failed\n";
exit($failures > 0 ? 1 : 0);
