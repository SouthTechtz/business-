<?php
/** Read-only Stage 1 reporting foundation tests. */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../reporting.php';

$failures = 0;
$passes = 0;
function stage1_assert($condition, $message) {
    global $failures, $passes;
    if (!$condition) {
        $failures++;
        echo "FAIL: $message\n";
        return;
    }
    $passes++;
    echo "PASS: $message\n";
}

class Stage1MemoryUser extends User {
    private $testPermissions = [];
    public function __construct($userId, $tenantId, array $permissions) {
        $this->user_id = $userId;
        $this->tenant_id = $tenantId;
        $this->testPermissions = $permissions;
    }
    public function hasPermission($permission) {
        return in_array($permission, $this->testPermissions, true);
    }
}

stage1_assert(report_valid_date('2026-02-28'), 'valid calendar date accepted');
stage1_assert(report_valid_date('2024-02-29'), 'leap-day date accepted');
stage1_assert(!report_valid_date('2026-02-29'), 'invalid leap-day rejected');
stage1_assert(!report_valid_date('2026-13-01'), 'invalid month rejected');
stage1_assert(!report_valid_date("2026-01-01' OR 1=1 -- "), 'SQL-injection-style date rejected');

$range = report_resolve_period(['from' => '2026-01-01', 'to' => '2026-01-31']);
stage1_assert($range['from'] === '2026-01-01' && $range['to'] === '2026-01-31' && $range['error'] === '', 'valid custom range preserved');
$range = report_resolve_period(['from' => '2026-02-30', 'to' => '2026-01-31']);
stage1_assert($range['from'] === '' && $range['error'] !== '', 'invalid custom date returns validation error');
$range = report_resolve_period(['from' => '2026-02-01', 'to' => '2026-01-01']);
stage1_assert($range['from'] === '' && $range['to'] === '' && $range['error'] !== '', 'reversed custom range rejected with error');
$range = report_resolve_period(['from' => "2026-01-01' OR 1=1 -- ", 'to' => '2026-01-31']);
stage1_assert($range['from'] === '' && $range['error'] !== '', 'SQL-injection-style custom range rejected with error');

$expectedPeriods = [
    'today' => [date('Y-m-d'), date('Y-m-d')],
    'week' => [date('Y-m-d', strtotime('monday this week')), date('Y-m-d', strtotime('sunday this week'))],
    'month' => [date('Y-m-01'), date('Y-m-t')],
    'year' => [date('Y-01-01'), date('Y-12-31')],
    'all' => ['', ''],
];
foreach ($expectedPeriods as $period => $expected) {
    $range = report_resolve_period(['period' => $period, 'from' => 'bad', 'to' => 'bad']);
    stage1_assert([$range['from'], $range['to']] === $expected && $range['error'] === '', "$period period resolved consistently");
}

$condition = report_date_condition('o.order_date', '2026-01-01', '2026-01-31');
stage1_assert($condition['params'] === ['2026-01-01', '2026-01-31'], 'date parameters remain ordered');
stage1_assert(substr_count($condition['sql'], '?') === 2 && strpos($condition['sql'], '2026-01-01') === false, 'date SQL uses placeholders and no raw values');
$condition = report_date_condition('o.order_date', '2026-01-01', '');
stage1_assert(substr_count($condition['sql'], '?') === 1 && $condition['params'] === ['2026-01-01'], 'from-only date condition parameterized');
$condition = report_date_condition('o.order_date', '', '2026-01-31');
stage1_assert(substr_count($condition['sql'], '?') === 1 && $condition['params'] === ['2026-01-31'], 'to-only date condition parameterized');
$condition = report_date_condition('o.order_date', '', '');
stage1_assert($condition['sql'] === '' && $condition['params'] === [], 'all-time date condition adds no filter');

$admin = User::getUserById(1);
stage1_assert($admin && (int)$admin->tenant_id === 1, 'existing administrator loaded');
$current_user = $admin;
$scope = report_sales_warehouse_condition('i', 'warehouse_id');
stage1_assert(strpos($scope['sql'], 'IS NULL') !== false, 'admin sales scope preserves unattributed historical warehouse references');
stage1_assert(strpos($scope['sql'], "rw.location_type = 'shop'") !== false && strpos($scope['sql'], 'rw.is_active = 1') !== false && strpos($scope['sql'], 'rs.is_active = 1') !== false, 'admin sales scope permits only active Shop inventory locations');
stage1_assert(strpos($scope['sql'], "'store', 'shop'") === false && strpos($scope['sql'], "'godown'") === false, 'admin sales scope excludes Store and Godown locations');

$scopeIds = report_accessible_sales_warehouse_ids();
stage1_assert($scopeIds === null, 'administrator sales scope remains tenant-wide');

$shopUser = User::getUserById(20);
$current_user = $shopUser;
stage1_assert($shopUser && !tenant_admin_scope(), 'Shop-scoped test user is not tenant administrator');
$scopeIds = report_accessible_sales_warehouse_ids();
sort($scopeIds);
stage1_assert($scopeIds === [15], 'Shop user sales scope contains only the authorized active Shop inventory location');
stage1_assert(!in_array(11, $scopeIds, true) && !in_array(13, $scopeIds, true), 'assigned Store and authorized Godown are excluded from Shop user sales scope');
$scope = report_sales_warehouse_condition('o', 'warehouse_id');
stage1_assert(count($scope['params']) === 1 && substr_count($scope['sql'], '?') === 1, 'Shop scope placeholder count matches the single Shop location parameter');
stage1_assert(strpos($scope['sql'], 'IS NULL') === false, 'unattributed historical rows are not assigned to Shop user');
$storeGodownSales = fetch_one("SELECT COUNT(*) c FROM pos_orders o WHERE o.tenant_id = ? AND o.warehouse_id IN (11, 13)" . $scope['sql'], 1, ...$scope['params']);
stage1_assert($storeGodownSales && (int)$storeGodownSales['c'] === 0, 'Shop user cannot see sales attributed to assigned Store or authorized Godown');
$inventoryScope = report_inventory_location_condition('s', 'warehouse_id', true, true);
$inventoryIds = $inventoryScope['params'];
sort($inventoryIds);
stage1_assert($inventoryIds === [11, 13, 15] && strpos($inventoryScope['sql'], 'IS NULL') !== false, 'inventory scope includes assigned Shop/Store and authorized Godown while preserving zero-stock product rows');

$current_user = new Stage1MemoryUser(999999, 1, ['view_reports', 'manage_godown']);
$scope = report_sales_warehouse_condition('o', 'warehouse_id');
stage1_assert($scope['sql'] === ' AND 1 = 0' && $scope['params'] === [], 'Godown-only user receives no Shop/Store sales rows');
$scope = report_inventory_location_condition('s', 'warehouse_id', true, false);
stage1_assert($scope['params'] === [13] && strpos($scope['sql'], 's.warehouse_id IN (?)') !== false, 'Godown-only inventory scope is limited to the authorized Godown');

$current_user = new Stage1MemoryUser(999999, 1, ['view_reports']);
$scope = report_inventory_location_condition('s', 'warehouse_id', true, false);
stage1_assert($scope['sql'] === ' AND 1 = 0' && $scope['params'] === [], 'user without inventory-location permission receives no located stock rows');

$current_user = $admin;
$condition = report_sales_warehouse_condition('o', 'warehouse_id');
$scoped = fetch_one("SELECT COUNT(*) c FROM pos_orders o WHERE o.tenant_id = ?" . $condition['sql'], 1, ...$condition['params']);
stage1_assert($scoped !== null, 'tenant-scoped POS reporting query executes');
$storeSale = fetch_one("SELECT COUNT(*) c FROM pos_orders o WHERE o.tenant_id = ? AND o.warehouse_id = 1" . $condition['sql'], 1, ...$condition['params']);
stage1_assert($storeSale && (int)$storeSale['c'] === 0, 'administrator sales scope excludes the actual Store-attributed POS sale');
$crossTenant = fetch_one("SELECT COUNT(*) c FROM pos_orders o WHERE o.tenant_id = ?", 999999);
stage1_assert($crossTenant && (int)$crossTenant['c'] === 0, 'nonexistent/other tenant receives no POS rows');

$nullHistorical = fetch_one("SELECT COUNT(*) c FROM invoices WHERE tenant_id = ? AND warehouse_id IS NULL", 1);
stage1_assert($nullHistorical !== null, 'historical NULL warehouse query executes without backfilling attribution');
$scope = report_sales_warehouse_condition('i', 'warehouse_id');
$scopedTotal = fetch_one("SELECT COALESCE(SUM(i.total), 0) t FROM invoices i WHERE i.tenant_id = ?" . $scope['sql'], 1, ...$scope['params']);
$tenantTotal = fetch_one("SELECT COALESCE(SUM(total), 0) t FROM invoices WHERE tenant_id = ?", 1);
stage1_assert($scopedTotal && $tenantTotal && (float)$scopedTotal['t'] === (float)$tenantTotal['t'], 'admin invoice scope includes historical unattributed totals exactly once');
$dateCondition = report_date_condition('p.payment_date', '2026-01-01', '2026-12-31');
$scope = report_sales_warehouse_condition('i', 'warehouse_id');
$paymentCheck = fetch_one("SELECT COUNT(*) c FROM payments p JOIN invoices i ON i.invoice_id = p.invoice_id WHERE p.tenant_id = ? AND i.tenant_id = ?" . $scope['sql'] . $dateCondition['sql'], 1, 1, ...array_merge($scope['params'], $dateCondition['params']));
stage1_assert($paymentCheck !== null, 'payment query tenant, location, and date placeholders execute in correct order');

$relationships = report_revenue_source_relationships();
stage1_assert(strpos($relationships['payments'], 'not sales revenue') !== false, 'payments remain documented as cash receipts, not revenue');
stage1_assert(strpos($relationships['orders'], 'fulfilled_invoice_id') !== false, 'Order-to-Invoice relationship documented');
stage1_assert(strpos($relationships['stock_transfers'], 'not sales revenue') !== false, 'stock transfers excluded from revenue definitions');
stage1_assert(report_lead_confirmed_statuses() === ['confirmed'], 'Lead Confirmed status definition uses confirmed');

$reportFiles = ['reports.php', 'report-print.php', 'dashboard.php', 'export-sales.php', 'export-invoices.php', 'export-receipts.php', 'export-expenses.php'];
$foundVerified = false;
foreach ($reportFiles as $file) {
    $contents = file_get_contents(__DIR__ . '/../' . $file);
    if (stripos($contents, 'verified') !== false) $foundVerified = true;
}
stage1_assert(!$foundVerified, 'reporting and export terminology contains no Verified label');

$printSource = file_get_contents(__DIR__ . '/../report-print.php');
stage1_assert(strpos($printSource, 'function date_filter') === false, 'obsolete unsafe date_filter removed');
stage1_assert(strpos($printSource, "BETWEEN '\$from'") === false, 'raw date interpolation removed from printable reports');

echo "RESULT: $passes passed, $failures failed\n";
exit($failures > 0 ? 1 : 0);

