<?php
/** Stage 2 on-screen, printable, and export consistency checks. */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../reporting.php';
$current_user = User::getUserById(1);
$base = getenv('STAGE2_BASE_URL') ?: 'http://127.0.0.1:8087';
$from = '2026-01-01';
$to = '2026-12-31';
$summary = report_sales_summary($from, $to);
$passes = 0;
$failures = 0;

function stage2_http_assert($condition, $message) {
    global $passes, $failures;
    if (!$condition) { $failures++; echo "FAIL: $message\n"; return; }
    $passes++;
    echo "PASS: $message\n";
}
function stage2_http_get($url) {
    $context = stream_context_create(['http' => ['method' => 'GET', 'ignore_errors' => true, 'timeout' => 20]]);
    $body = file_get_contents($url, false, $context);
    $status = 0;
    $headers = $http_response_header ?? [];
    if ($headers && preg_match('/\s(\d{3})\s/', $headers[0], $match)) $status = (int)$match[1];
    return ['status' => $status, 'body' => $body === false ? '' : $body, 'headers' => $headers];
}
function stage2_clean($body) {
    return !preg_match('/Fatal error|Warning:|Notice:|mysqli_sql_exception|Undefined (function|variable|array key)/i', $body);
}

$onscreen = stage2_http_get("$base/reports.php?from=$from&to=$to&__stage1_user=1");
stage2_http_assert($onscreen['status'] === 200 && stage2_clean($onscreen['body']), 'on-screen Sales and Invoice report loads without runtime errors');
stage2_http_assert(strpos($onscreen['body'], 'Sales by Shop') !== false && strpos($onscreen['body'], 'Product Sales and Accepted Returns') !== false, 'on-screen report includes Shop and product tables');
stage2_http_assert(strpos($onscreen['body'], 'Invoice Status, Balances and Returns') !== false, 'on-screen report includes Invoice status and balances');
stage2_http_assert(strpos($onscreen['body'], money($summary['pos']['net_sales'], 'TZS')) !== false, 'on-screen net POS value matches shared calculation');
stage2_http_assert(strpos($onscreen['body'], money($summary['invoice']['net_sales'], 'TZS')) !== false, 'on-screen net Invoice value matches shared calculation');
stage2_http_assert(strpos($onscreen['body'], 'Refund Due (Not Cash Refunded)') !== false, 'refund due is explicitly not presented as cash refunded');

$print = stage2_http_get("$base/report-print.php?type=sales&from=$from&to=$to&__stage1_user=1");
stage2_http_assert($print['status'] === 200 && stage2_clean($print['body']), 'printable Sales report loads without runtime errors');
stage2_http_assert(strpos($print['body'], 'Sales Report (POS and Invoice)') !== false && strpos($print['body'], 'Sales by Shop') !== false, 'printable Sales report includes channel and Shop summaries');
stage2_http_assert(strpos($print['body'], money($summary['pos']['net_sales'], 'TZS')) !== false && strpos($print['body'], money($summary['net_sales'], 'TZS')) !== false, 'printable Sales net values match on-screen calculations');

$invoicePrint = stage2_http_get("$base/report-print.php?type=invoice-sales&from=$from&to=$to&__stage1_user=1");
stage2_http_assert($invoicePrint['status'] === 200 && stage2_clean($invoicePrint['body']), 'printable Invoice Sales report loads without runtime errors');
stage2_http_assert(strpos($invoicePrint['body'], 'Invoice Sales Report') !== false && strpos($invoicePrint['body'], 'Return State') !== false, 'printable Invoice report includes return states');
stage2_http_assert(strpos($invoicePrint['body'], money($summary['invoice']['net_sales'], 'TZS')) !== false && strpos($invoicePrint['body'], money($summary['invoice']['outstanding_balance'], 'TZS')) !== false, 'printable Invoice net and outstanding values match shared calculation');
stage2_http_assert(strpos($invoicePrint['body'], money($summary['invoice']['refund_due'], 'TZS')) !== false, 'printable Invoice refund due matches shared calculation');

$export = stage2_http_get("$base/export-sales.php?date_from=$from&date_to=$to&__stage1_user=1");
stage2_http_assert($export['status'] === 200 && stage2_clean($export['body']) && strpos($export['body'], '<Workbook') !== false, 'POS sales export remains a valid workbook');
stage2_http_assert(strpos($export['body'], 'Accepted Returns') !== false && strpos($export['body'], 'Net Sale') !== false && strpos($export['body'], number_format($summary['pos']['net_sales'], 2, '.', '')) !== false, 'POS export net total agrees with on-screen and printable calculations');

$invoiceExport = stage2_http_get("$base/export-invoices.php?from=$from&to=$to&__stage1_user=1");
stage2_http_assert($invoiceExport['status'] === 200 && stage2_clean($invoiceExport['body']) && strpos($invoiceExport['body'], '<Workbook') !== false, 'Invoice export remains a valid workbook');
stage2_http_assert(strpos($invoiceExport['body'], 'Return State') !== false && strpos($invoiceExport['body'], 'Refund Due') !== false, 'Invoice export includes return states and refund due');
stage2_http_assert(strpos($invoiceExport['body'], number_format($summary['invoice']['net_sales'], 2, '.', '')) !== false, 'Invoice export net total agrees with on-screen and printable calculations');

$shopView = stage2_http_get("$base/reports.php?from=$from&to=$to&__stage1_user=20");
stage2_http_assert($shopView['status'] === 200 && stage2_clean($shopView['body']) && strpos($shopView['body'], 'Unattributed (historical)') === false, 'Shop-scoped user does not receive unattributed historical sales');
$adminPrint = stage2_http_get("$base/report-print.php?type=sales&from=$from&to=$to&__stage1_user=1");
stage2_http_assert(strpos($adminPrint['body'], 'Unattributed (historical)') !== false, 'administrator sees historical NULL sales explicitly as unattributed');

echo "RESULT: $passes passed, $failures failed\n";
exit($failures > 0 ? 1 : 0);
