<?php
require_once 'header.php';
require_once 'auth_check.php';
require_permission('manage_pos');
$page_title = 'Sales';

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

$customers = fetch_all(
    "SELECT contact_id, first_name, last_name, company_name FROM contacts
     WHERE tenant_id = ? AND is_customer = 1 ORDER BY first_name",
    $current_user->tenant_id
);

require_once 'navbar.php';
require_once 'sidebar.php';
$cur = tenant_currency();
?>

<div class="page-head">
    <div>
        <h1 class="page-title">Sales</h1>
        <div class="page-sub"><?php echo count($orders); ?> sale(s) in the selected period.</div>
    </div>
    <a href="sale-add.php" class="btn btn-primary"><i class="fa fa-plus mr-1"></i> New Sale</a>
    <a href="export-sales.php?<?php echo e(http_build_query(array_filter(['status' => $status, 'customer' => $customer_id > 0 ? $customer_id : '', 'warehouse' => $warehouse_filter > 0 ? $warehouse_filter : '', 'date_from' => $date_from, 'date_to' => $date_to], fn($v) => $v !== ''))); ?>" class="btn btn-outline-success"><i class="fa fa-file-excel-o mr-1"></i> Excel</a>
</div>

<?php if ($f = flash_get()): ?>
    <div class="alert alert-<?php echo e($f['type']); ?>"><?php echo e($f['message']); ?></div>
<?php endif; ?>

<div class="card">
    <div class="card-header">
        <form method="GET" action="sales.php" class="form-inline flex-wrap">
            <select name="status" class="form-control form-control-sm mr-2 mb-1">
                <option value="">All Statuses</option>
                <option value="pending" <?php echo $status === 'pending' ? 'selected' : ''; ?>>Pending</option>
                <option value="completed" <?php echo $status === 'completed' ? 'selected' : ''; ?>>Completed</option>
                <option value="cancelled" <?php echo $status === 'cancelled' ? 'selected' : ''; ?>>Cancelled</option>
            </select>
            <select name="customer" class="form-control form-control-sm mr-2 mb-1">
                <option value="0">All Customers</option>
                <?php foreach ($customers as $c): ?>
                    <option value="<?php echo $c['contact_id']; ?>" <?php echo $customer_id == $c['contact_id'] ? 'selected' : ''; ?>>
                        <?php echo e(trim(($c['first_name'] ?? '') . ' ' . ($c['last_name'] ?? '')) ?: $c['company_name']); ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <select name="warehouse" class="form-control form-control-sm mr-2 mb-1">
                <option value="0">All Stores</option>
                <?php foreach ($stores as $store): ?>
                    <option value="<?php echo (int)$store['warehouse_id']; ?>" <?php echo $warehouse_filter === (int)$store['warehouse_id'] ? 'selected' : ''; ?>><?php echo e(store_label($store)); ?><?php echo (int)$store['is_active'] ? '' : ' (inactive)'; ?></option>
                <?php endforeach; ?>
            </select>
            <input type="date" name="date_from" class="form-control form-control-sm mr-2 mb-1" value="<?php echo e($date_from); ?>">
            <span class="mr-2 mb-1">to</span>
            <input type="date" name="date_to" class="form-control form-control-sm mr-2 mb-1" value="<?php echo e($date_to); ?>">
            <button type="submit" class="btn btn-sm btn-primary mb-1"><i class="fa fa-filter mr-1"></i> Filter</button>
        </form>
        <div class="input-group dt-search ml-auto">
            <div class="input-group-prepend"><span class="input-group-text"><i class="fa fa-search"></i></span></div>
            <input type="text" class="form-control dt-search-input" data-scope="sales" placeholder="Search sales...">
        </div>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover data-table">
                <thead>
                    <tr>
                        <th>Order #</th><th>Customer</th><th>Store</th><th>Date</th><th>Items</th><th>Status</th><th class="text-right">Total</th><th class="text-right">Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($orders as $o): ?>
                    <tr>
                        <td class="font-weight-semibold"><?php echo e($o['order_number']); ?></td>
                        <td><?php echo e(trim(($o['first_name'] ?? '') . ' ' . ($o['last_name'] ?? '')) ?: $o['company_name'] ?: 'Walk-in Customer'); ?></td>
                        <td><?php echo e($o['store_name'] ? trim(($o['shop_name'] ?? '') . ' / ' . $o['store_name'], ' /') : '—'); ?></td>
                        <td><?php echo e(date('M d, Y H:i', strtotime($o['order_date']))); ?></td>
                        <td><?php echo e((float)$o['items_count']); ?></td>
                        <td>
                            <span class="badge badge-<?php echo $o['status'] === 'completed' ? 'success' : ($o['status'] === 'cancelled' ? 'danger' : 'warning'); ?>">
                                <?php echo e(ucfirst($o['status'])); ?>
                            </span>
                        </td>
                        <td class="text-right font-weight-semibold"><?php echo e(money($o['total'], $cur)); ?></td>
                        <td class="text-right">
                            <a href="sale-details.php?id=<?php echo $o['order_id']; ?>" class="btn btn-action btn-light"><i class="fa fa-eye"></i></a>
                            <?php if ($o['status'] !== 'cancelled'): ?>
                                <a href="sale-cancel.php?id=<?php echo $o['order_id']; ?>" class="btn btn-action btn-light text-warning" title="Cancel" onclick="return confirm('Cancel this sale? Stock will be restored.');"><i class="fa fa-ban"></i></a>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$orders): ?>
                    <tr><td colspan="8" class="text-center text-muted py-4">No sales found for the selected filters.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php require_once 'footer.php'; ?>
