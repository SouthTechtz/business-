<?php
require_once 'header.php';
require_once 'auth_check.php';
require_permission('manage_pos');
require_once 'reporting.php';
$page_title = 'Sales';
$is_shop_manager_view = is_shop_manager() && !tenant_admin_scope();


$status = $_GET['status'] ?? '';
$customer_id = (int)($_GET['customer'] ?? 0);
$date_from = (string)($_GET['date_from'] ?? '');
$date_to = (string)($_GET['date_to'] ?? '');
$warehouse_filter = (int)($_GET['warehouse'] ?? 0);
$brand_filter = trim((string)($_GET['brand'] ?? ''));
if ($brand_filter !== '' && !in_array($brand_filter, product_brand_options(), true)) {
    $brand_filter = '';
}
$accessible_sales_warehouse_ids = report_accessible_sales_warehouse_ids();
$location_sql = "SELECT w.warehouse_id, w.name, s.name shop_name
                   FROM warehouses w
                   JOIN shops s ON s.shop_id = w.shop_id AND s.tenant_id = w.tenant_id
                  WHERE w.tenant_id = ? AND w.location_type = 'shop'
                    AND w.is_active = 1 AND s.is_active = 1";
$location_params = [(int)$current_user->tenant_id];
if (is_array($accessible_sales_warehouse_ids)) {
    if (!$accessible_sales_warehouse_ids) {
        $location_sql .= " AND 1 = 0";
    } else {
        $location_sql .= " AND w.warehouse_id IN (" . implode(',', array_fill(0, count($accessible_sales_warehouse_ids), '?')) . ")";
        $location_params = array_merge($location_params, $accessible_sales_warehouse_ids);
    }
}
$location_sql .= " ORDER BY s.name, w.name";
$sales_locations = fetch_all($location_sql, ...$location_params);
$sales_location_ids = array_map(fn($location) => (int)$location['warehouse_id'], $sales_locations);
if ($warehouse_filter > 0 && !in_array($warehouse_filter, $sales_location_ids, true)) {
    $warehouse_filter = 0;
}

$sql = "SELECT o.*, c.first_name, c.last_name, c.company_name,
               w.name store_name, s.name shop_name, s.brand shop_brand,
               COALESCE(oi.items_total, 0) items_total,
               COALESCE(oi.items_count, 0) items_count,
               COALESCE(pay.amount_paid, 0) amount_paid
        FROM pos_orders o
        LEFT JOIN pos_sessions ps ON ps.session_id = o.session_id AND ps.tenant_id = o.tenant_id
        LEFT JOIN (
            SELECT order_id, SUM(total) items_total, SUM(quantity) items_count,
                   MAX(warehouse_id) item_warehouse_id
              FROM pos_order_items
             GROUP BY order_id
        ) oi ON oi.order_id = o.order_id
        LEFT JOIN (
            SELECT order_id, SUM(amount) amount_paid
              FROM pos_payments
             GROUP BY order_id
        ) pay ON pay.order_id = o.order_id
        LEFT JOIN contacts c ON c.contact_id = o.contact_id
        LEFT JOIN warehouses w ON w.warehouse_id = COALESCE(o.warehouse_id, ps.warehouse_id, oi.item_warehouse_id)
            AND w.tenant_id = o.tenant_id
        LEFT JOIN shops s ON s.shop_id = w.shop_id
        WHERE o.tenant_id = ?";
$params = [(int)$current_user->tenant_id];

if (is_array($accessible_sales_warehouse_ids)) {
    if (!$accessible_sales_warehouse_ids) {
        $sql .= " AND 1 = 0";
    } else {
        $sql .= " AND COALESCE(o.warehouse_id, ps.warehouse_id, oi.item_warehouse_id) IN ("
            . implode(',', array_fill(0, count($accessible_sales_warehouse_ids), '?')) . ")";
        $params = array_merge($params, $accessible_sales_warehouse_ids);
    }
}

if ($date_from !== '') {
    $sql .= " AND DATE(o.order_date) >= ?";
    $params[] = $date_from;
}
if ($date_to !== '') {
    $sql .= " AND DATE(o.order_date) <= ?";
    $params[] = $date_to;
}

if ($warehouse_filter > 0) {
    $sql .= " AND COALESCE(o.warehouse_id, ps.warehouse_id, oi.item_warehouse_id) = ?";
    $params[] = $warehouse_filter;
}
if ($status !== '') {
    $sql .= " AND o.status = ?";
    $params[] = $status;
}
if ($customer_id > 0) {
    $sql .= " AND o.contact_id = ?";
    $params[] = $customer_id;
}
if ($brand_filter !== '') {
    $sql .= " AND (
        s.brand = ?
        OR (s.brand IS NULL AND EXISTS (
            SELECT 1 FROM pos_order_items bfi
            JOIN products bfp ON bfp.product_id = bfi.product_id AND bfp.tenant_id = o.tenant_id
            WHERE bfi.order_id = o.order_id AND bfp.brand = ?
        ))
    )";
    $params[] = $brand_filter;
    $params[] = $brand_filter;
}
$sql .= " ORDER BY o.order_date DESC, o.order_id DESC";

$orders = fetch_all($sql, ...$params);
$total_sales_count = count($orders);
$total_sales_amount = array_sum(array_map(
    fn($order) => (float)$order['total'],
    $orders
));

$order_items_by_order = [];
$order_brands_by_order = [];
if ($orders) {
    $order_ids = array_map(fn($order) => (int)$order['order_id'], $orders);
    $order_placeholders = implode(',', array_fill(0, count($order_ids), '?'));
    $item_rows = fetch_all(
        "SELECT oi.order_id, COALESCE(NULLIF(TRIM(oi.description), ''), p.name, 'Item') item_name,
                oi.quantity, p.brand item_brand
           FROM pos_order_items oi
           JOIN pos_orders o ON o.order_id = oi.order_id
           LEFT JOIN products p ON p.product_id = oi.product_id AND p.tenant_id = o.tenant_id
          WHERE o.tenant_id = ? AND oi.order_id IN ({$order_placeholders})
          ORDER BY oi.order_id, oi.order_item_id",
        $current_user->tenant_id,
        ...$order_ids
    );
    foreach ($item_rows as $item_row) {
        $order_items_by_order[(int)$item_row['order_id']][] = $item_row;
        $item_brand = trim((string)($item_row['item_brand'] ?? ''));
        if ($item_brand !== '') {
            $order_brands_by_order[(int)$item_row['order_id']][$item_brand] = true;
        }
    }
}

$customers = fetch_all(
    "SELECT contact_id, first_name, last_name, company_name FROM contacts
     WHERE tenant_id = ? AND is_customer = 1 ORDER BY first_name",
    $current_user->tenant_id
);

// Categorise the visible sales by brand for the summary cards.
$brand_totals = [];
foreach ($orders as $order) {
    $brand_info = sale_brand_info(
        $order['shop_brand'] ?? '',
        array_keys($order_brands_by_order[(int)$order['order_id']] ?? [])
    );
    $brand_key = $brand_info['key'] !== '' ? $brand_info['key'] : 'unbranded';
    if (!isset($brand_totals[$brand_key])) {
        $brand_totals[$brand_key] = ['label' => $brand_info['label'], 'count' => 0, 'total' => 0.0];
    }
    $brand_totals[$brand_key]['count']++;
    $brand_totals[$brand_key]['total'] += (float)$order['total'];
}

$total_sales_display = $total_sales_amount;
uasort($brand_totals, fn($a, $b) => $b['total'] <=> $a['total']);

require_once 'navbar.php';
require_once 'sidebar.php';
$cur = tenant_currency();
?>

<div class="page-head">
    <div>
        <h1 class="page-title">Sales</h1>
        <div class="page-sub"><?php echo count($orders); ?> sale(s) found.</div>
    </div>
    <a href="sale-add.php" class="btn btn-primary"><i class="fa fa-plus mr-1"></i> New Sale</a>
    <a href="export-sales.php?<?php echo e(http_build_query(array_filter(['status' => $status, 'customer' => $customer_id > 0 ? $customer_id : '', 'warehouse' => $warehouse_filter > 0 ? $warehouse_filter : '', 'brand' => $brand_filter, 'date_from' => $date_from, 'date_to' => $date_to], fn($v) => $v !== ''))); ?>" class="btn btn-outline-success"><i class="fa fa-file-excel-o mr-1"></i> Excel</a>
</div>

<?php if ($f = flash_get()): ?>
    <div class="alert alert-<?php echo e($f['type']); ?>"><?php echo e($f['message']); ?></div>
<?php endif; ?>

<div class="row mb-3">
    <?php if (!$is_shop_manager_view): ?>
    <div class="col-md-4 mb-3 mb-md-0">
        <div class="card border-left-primary shadow-sm h-100 py-2">
            <div class="card-body">
                <div class="text-xs font-weight-bold text-primary text-uppercase mb-1">Total Sales</div>
                <div class="h5 mb-0 font-weight-bold text-gray-800"><?php echo number_format($total_sales_count); ?></div>
                <div class="small text-muted">Sale records shown</div>
            </div>
        </div>
    </div>
    <div class="col-md-4 mb-3 mb-md-0">
        <div class="card border-left-success shadow-sm h-100 py-2">
            <div class="card-body">
                <div class="text-xs font-weight-bold text-success text-uppercase mb-1">Total Amount Sold</div>
                <div class="h5 mb-0 font-weight-bold text-gray-800"><?php echo e(money($total_sales_display, tenant_currency())); ?></div>
                <div class="small text-muted">Completed sales in the current view</div>
            </div>
        </div>
    </div>
    <?php endif; ?>
</div>

<?php if (!$is_shop_manager_view && $brand_totals): ?>
<div class="row mb-3">
    <?php foreach ($brand_totals as $brand_total): ?>
    <div class="col-md-4 mb-3 mb-md-0">
        <div class="card border-left-primary shadow-sm h-100 py-2">
            <div class="card-body">
                <div class="text-xs font-weight-bold text-primary text-uppercase mb-1"><?php echo e($brand_total['label']); ?> Sales</div>
                <div class="h5 mb-0 font-weight-bold text-gray-800"><?php echo e(money($brand_total['total'], tenant_currency())); ?></div>
                <div class="small text-muted"><?php echo number_format($brand_total['count']); ?> sale(s)</div>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
</div>
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
                <option value="0">All Shops</option>
                <?php foreach ($sales_locations as $location): ?>
                    <option value="<?php echo (int)$location['warehouse_id']; ?>" <?php echo $warehouse_filter === (int)$location['warehouse_id'] ? 'selected' : ''; ?>><?php echo e(store_label($location)); ?></option>
                <?php endforeach; ?>
            </select>
            <select name="brand" class="form-control form-control-sm mr-2 mb-1">
                <option value="">All Brands</option>
                <?php foreach (brand_filter_options() as $brand_option): ?>
                    <option value="<?php echo e($brand_option); ?>" <?php echo $brand_filter === $brand_option ? 'selected' : ''; ?>><?php echo e(brand_label($brand_option)); ?></option>
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
            <table class="table table-hover data-table" data-page-length="20">
                <thead>
                    <tr>
                        <th>Order #</th><th>Customer</th><th>Store</th><th>Brand</th><th>Date</th><th>Items</th><th>Status</th><th class="text-right">Total</th><th class="text-right">Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($orders as $o): ?>
                    <tr>
                        <td class="font-weight-semibold"><?php echo e($o['order_number']); ?></td>
                        <td><?php echo e(trim(($o['first_name'] ?? '') . ' ' . ($o['last_name'] ?? '')) ?: $o['company_name'] ?: 'Walk-in Customer'); ?></td>
                        <td><?php echo e($o['store_name'] ? trim(($o['shop_name'] ?? '') . ' / ' . $o['store_name'], ' /') : '—'); ?></td>
                        <?php $sale_brand = sale_brand_info($o['shop_brand'] ?? '', array_keys($order_brands_by_order[(int)$o['order_id']] ?? [])); ?>
                        <td><span class="badge badge-navy"><?php echo e($sale_brand['label']); ?></span></td>
                        <td><?php echo e(date('M d, Y H:i', strtotime($o['order_date']))); ?></td>
                        <td>
                            <?php $sold_items = $order_items_by_order[(int)$o['order_id']] ?? []; ?>
                            <?php if ($sold_items): ?>
                                <ul class="list-unstyled mb-0 small">
                                    <?php foreach ($sold_items as $sold_item): ?>
                                        <li><?php echo e($sold_item['item_name']); ?> <span class="text-muted">× <?php echo e((float)$sold_item['quantity']); ?></span></li>
                                    <?php endforeach; ?>
                                </ul>
                                <span class="text-muted small"><?php echo e((float)$o['items_count']); ?> total</span>
                            <?php else: ?>
                                <span class="text-muted">No items recorded</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php $sale_status_meta = pos_sale_payment_status($o['status'], $o['total'], $o['amount_paid'] ?? 0); ?>
                            <span class="badge badge-<?php echo e($sale_status_meta['class']); ?>">
                                <?php echo e($sale_status_meta['label']); ?>
                            </span>
                        </td>
                        <td class="text-right font-weight-semibold"><?php echo e(money($o['total'], $cur)); ?></td>
                        <td class="text-right">
                            <?php
                            $sale_actions = [
                                ['label' => 'View sale', 'icon' => 'fa-eye', 'href' => 'sale-details.php?id=' . (int)$o['order_id']],
                            ];
                            if ($o['status'] !== 'cancelled') {
                                $sale_actions[] = ['type' => 'divider'];
                                $sale_actions[] = [
                                    'label' => 'Cancel sale',
                                    'icon' => 'fa-ban',
                                    'danger' => true,
                                    'href' => 'sale-cancel.php?id=' . (int)$o['order_id'],
                                    'attrs' => ['onclick' => "return confirm('Cancel this sale? Stock will be restored.');"],
                                ];
                            }
                            echo row_action_menu($sale_actions, 'Sale actions');
                            ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$orders): ?>
                    <tr><td colspan="9" class="text-center text-muted py-4">No sales found for the selected filters.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php require_once 'footer.php'; ?>
