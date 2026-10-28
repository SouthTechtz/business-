<?php
require_once 'header.php';
require_once 'auth_check.php';

$page_title = 'Dashboard';
$cur = tenant_currency();

// Dashboard is optional — send users without access to their first available module
if (!can('view_dashboard')) {
    foreach ([
        'manage_pos'      => 'sales.php',
        'manage_invoices' => 'invoices.php',
        'manage_receipts' => 'receipts.php',
        'manage_contacts' => 'customers.php',
        'manage_products' => 'products.php',
    ] as $perm => $page) {
        if (can($perm)) {
            redirect($page);
        }
    }
    redirect('profile.php');
}

// KPI counts
$scope_ids = accessible_warehouse_ids();
$wh_scope = $scope_ids === null ? '' : ($scope_ids ? " AND warehouse_id IN (" . implode(',', array_map('intval', $scope_ids)) . ")" : " AND 1 = 0");
$invoice_wh_scope = $scope_ids === null ? '' : ($scope_ids ? " AND i.warehouse_id IN (" . implode(',', array_map('intval', $scope_ids)) . ")" : " AND 1 = 0");
$order_wh_scope = $scope_ids === null ? '' : ($scope_ids ? " AND o.warehouse_id IN (" . implode(',', array_map('intval', $scope_ids)) . ")" : " AND 1 = 0");
$stock_wh_scope = $scope_ids === null ? '' : ($scope_ids ? " AND s.warehouse_id IN (" . implode(',', array_map('intval', $scope_ids)) . ")" : " AND 1 = 0");

$counts = [
    'users'     => 0,
    'customers' => 0,
    'products'  => 0,
    'invoices'  => 0,
];
$queries = [
    'users'     => "SELECT COUNT(*) c FROM users WHERE tenant_id = ?",
    'customers' => "SELECT COUNT(*) c FROM contacts WHERE tenant_id = ? AND is_customer = 1",
    'products'  => "SELECT COUNT(*) c FROM products WHERE tenant_id = ?",
    'invoices'  => "SELECT COUNT(*) c FROM invoices WHERE tenant_id = ?{$wh_scope}",
];
foreach ($queries as $key => $sql) {
    $row = fetch_one($sql, $current_user->tenant_id);
    $counts[$key] = $row ? (int)$row['c'] : 0;
}

// Total sales this month
$row = fetch_one(
    "SELECT COALESCE(SUM(total), 0) t FROM pos_orders
     WHERE tenant_id = ? AND status = 'completed' AND MONTH(order_date) = MONTH(NOW()) AND YEAR(order_date) = YEAR(NOW()){$wh_scope}",
    $current_user->tenant_id
);
$month_sales = $row ? (float)$row['t'] : 0;

// Monthly sales chart data (last 12 months)
$sales_labels = [];
$sales_data = [];
$res = fetch_all(
    "SELECT DATE_FORMAT(issue_date, '%Y-%m') month, SUM(total) total
     FROM invoices WHERE tenant_id = ?{$wh_scope}
     GROUP BY DATE_FORMAT(issue_date, '%Y-%m') ORDER BY month DESC LIMIT 12",
    $current_user->tenant_id
);
foreach (array_reverse($res) as $r) {
    $sales_labels[] = $r['month'];
    $sales_data[] = (float)$r['total'];
}

// Invoice status chart data
$status_labels = [];
$status_data = [];
$res = fetch_all(
    "SELECT ds.name, COUNT(i.invoice_id) c
     FROM document_statuses ds
     LEFT JOIN invoices i ON ds.status_id = i.status_id AND i.tenant_id = ?{$invoice_wh_scope}
     WHERE ds.tenant_id = ? AND ds.type = 'invoice'
     GROUP BY ds.status_id",
    $current_user->tenant_id, $current_user->tenant_id
);
foreach ($res as $r) {
    $status_labels[] = $r['name'];
    $status_data[] = (int)$r['c'];
}

// Low stock: only ordinary shop stock counts. A product whose stock sits
// exclusively in the central Godown has zero shop availability and must still
// appear here, so use a scoped subquery rather than filtering joined rows.
$low_stock = fetch_all(
    "SELECT p.name, p.code,
            COALESCE((SELECT SUM(s.quantity)
                        FROM inventory_stock s
                        JOIN warehouses sw ON sw.warehouse_id = s.warehouse_id AND sw.tenant_id = s.tenant_id
                       WHERE s.product_id = p.product_id AND s.tenant_id = p.tenant_id
                         AND sw.is_godown = 0{$stock_wh_scope}), 0) total_stock,
            p.min_stock_level
     FROM products p
     WHERE p.tenant_id = ? AND p.is_service = 0
     HAVING total_stock <= p.min_stock_level
     ORDER BY total_stock ASC LIMIT 5",
    $current_user->tenant_id
);

// Recent sales
$recent_sales = fetch_all(
    "SELECT o.order_id, o.order_number, o.order_date, o.total, c.first_name, c.last_name
     FROM pos_orders o
     LEFT JOIN contacts c ON o.contact_id = c.contact_id
     WHERE o.tenant_id = ?{$order_wh_scope} ORDER BY o.order_date DESC LIMIT 5",
    $current_user->tenant_id
);

// Recent customers
$recent_customers = fetch_all(
    "SELECT contact_id, first_name, last_name, email
     FROM contacts WHERE tenant_id = ? AND is_customer = 1
     ORDER BY created_at DESC LIMIT 5",
    $current_user->tenant_id
);

require_once 'navbar.php';
require_once 'sidebar.php';
?>

<?php if ($f = flash_get()): ?>
    <div class="alert alert-<?php echo e($f['type']); ?>"><?php echo e($f['message']); ?></div>
<?php endif; ?>

<div class="page-head">
    <div>
        <h1 class="page-title">Dashboard</h1>
        <div class="page-sub">Welcome back, <?php echo e($current_user->first_name); ?> — here is what is happening today.</div>
    </div>
    <div class="d-flex align-items-center">
        <?php if (can('manage_pos')): ?>
            <a href="sale-add.php" class="btn btn-primary"><i class="fa fa-plus mr-1"></i> New Sale</a>
        <?php endif; ?>
        <a href="logout.php" class="btn btn-outline-danger ml-2"><i class="fa fa-sign-out mr-1"></i> Logout</a>
    </div>
</div>

<div class="row">
    <?php $card_link = can('manage_users') ? 'users.php' : ''; ?>
    <div class="col-md-6 col-xl-3 mb-3">
        <?php if ($card_link): ?><a href="<?php echo e($card_link); ?>" class="widget widget-link"><?php else: ?><div class="widget"><?php endif; ?>
            <div class="widget-icon wi-blue"><i class="fa fa-users"></i></div>
            <div class="widget-info">
                <div class="widget-value"><?php echo $counts['users']; ?></div>
                <div class="widget-label">Active Users</div>
            </div>
        <?php if ($card_link): ?></a><?php else: ?></div><?php endif; ?>
    </div>
    <?php $card_link = can('manage_contacts') ? 'customers.php' : ''; ?>
    <div class="col-md-6 col-xl-3 mb-3">
        <?php if ($card_link): ?><a href="<?php echo e($card_link); ?>" class="widget widget-link"><?php else: ?><div class="widget"><?php endif; ?>
            <div class="widget-icon wi-green"><i class="fa fa-address-book"></i></div>
            <div class="widget-info">
                <div class="widget-value"><?php echo $counts['customers']; ?></div>
                <div class="widget-label">Customers</div>
            </div>
        <?php if ($card_link): ?></a><?php else: ?></div><?php endif; ?>
    </div>
    <?php $card_link = can('manage_products') ? 'products.php' : ''; ?>
    <div class="col-md-6 col-xl-3 mb-3">
        <?php if ($card_link): ?><a href="<?php echo e($card_link); ?>" class="widget widget-link"><?php else: ?><div class="widget"><?php endif; ?>
            <div class="widget-icon wi-amber"><i class="fa fa-cube"></i></div>
            <div class="widget-info">
                <div class="widget-value"><?php echo $counts['products']; ?></div>
                <div class="widget-label">Products</div>
            </div>
        <?php if ($card_link): ?></a><?php else: ?></div><?php endif; ?>
    </div>
    <?php $card_link = can('manage_invoices') ? 'invoices.php' : ''; ?>
    <div class="col-md-6 col-xl-3 mb-3">
        <?php if ($card_link): ?><a href="<?php echo e($card_link); ?>" class="widget widget-link"><?php else: ?><div class="widget"><?php endif; ?>
            <div class="widget-icon wi-red"><i class="fa fa-file-text"></i></div>
            <div class="widget-info">
                <div class="widget-value"><?php echo $counts['invoices']; ?></div>
                <div class="widget-label">Invoices</div>
            </div>
        <?php if ($card_link): ?></a><?php else: ?></div><?php endif; ?>
    </div>
</div>

<div class="row">
    <?php $card_link = can('manage_pos') ? 'sales.php' : ''; ?>
    <div class="col-md-6 col-xl-3 mb-3">
        <?php if ($card_link): ?><a href="<?php echo e($card_link); ?>" class="widget widget-link"><?php else: ?><div class="widget"><?php endif; ?>
            <div class="widget-icon wi-purple"><i class="fa fa-money"></i></div>
            <div class="widget-info">
                <div class="widget-value" style="font-size:18px;"><?php echo e(money($month_sales, $cur)); ?></div>
                <div class="widget-label">Sales this month</div>
            </div>
        <?php if ($card_link): ?></a><?php else: ?></div><?php endif; ?>
    </div>
    <?php $card_link = can('manage_inventory') ? 'inventory.php' : ''; ?>
    <div class="col-md-6 col-xl-3 mb-3">
        <?php if ($card_link): ?><a href="<?php echo e($card_link); ?>" class="widget widget-link"><?php else: ?><div class="widget"><?php endif; ?>
            <div class="widget-icon wi-cyan"><i class="fa fa-exclamation-triangle"></i></div>
            <div class="widget-info">
                <div class="widget-value"><?php echo count($low_stock); ?></div>
                <div class="widget-label">Low stock items</div>
            </div>
        <?php if ($card_link): ?></a><?php else: ?></div><?php endif; ?>
    </div>
</div>

<div class="row">
    <div class="col-lg-8 mb-3">
        <div class="card">
            <div class="card-header"><h5 class="card-title">Total Sales Over Time</h5></div>
            <div class="card-body"><canvas id="salesChart" height="90"></canvas></div>
        </div>
    </div>
    <div class="col-lg-4 mb-3">
        <div class="card">
            <div class="card-header"><h5 class="card-title">Invoice Status</h5></div>
            <div class="card-body"><canvas id="statusChart" height="220"></canvas></div>
        </div>
    </div>
</div>

<div class="row">
    <div class="col-lg-8 mb-3">
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h5 class="card-title">Recent Sales</h5>
                <?php if (can('manage_pos')): ?><a href="sales.php" class="btn btn-sm btn-outline-primary">View all</a><?php endif; ?>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover mb-0">
                        <thead>
                            <tr><th>Order #</th><th>Customer</th><th>Date</th><th class="text-right">Total</th></tr>
                        </thead>
                        <tbody>
                        <?php if (!$recent_sales): ?>
                            <tr><td colspan="4" class="text-center text-muted py-4">No sales yet.</td></tr>
                        <?php else: foreach ($recent_sales as $row): ?>
                            <tr>
                                <td><?php echo e($row['order_number']); ?></td>
                                <td><?php echo e(trim(($row['first_name'] ?? '') . ' ' . ($row['last_name'] ?? '')) ?: 'Walk-in'); ?></td>
                                <td><?php echo e(date('M d, Y H:i', strtotime($row['order_date']))); ?></td>
                                <td class="text-right font-weight-semibold"><?php echo e(money($row['total'], $cur)); ?></td>
                            </tr>
                        <?php endforeach; endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
    <div class="col-lg-4 mb-3">
        <div class="card">
            <div class="card-header"><h5 class="card-title">Recent Customers</h5></div>
            <div class="card-body p-3">
                <?php if (!$recent_customers): ?>
                    <div class="text-center text-muted py-4">No customers yet.</div>
                <?php else: foreach ($recent_customers as $row): ?>
                    <div class="d-flex align-items-center mb-3">
                        <span class="avatar mr-3" style="background:var(--purple)"><?php echo e(strtoupper(substr($row['first_name'] ?? '?', 0, 1))); ?></span>
                        <div class="min-w-0">
                            <div class="font-weight-semibold"><?php echo e(trim(($row['first_name'] ?? '') . ' ' . ($row['last_name'] ?? ''))); ?></div>
                            <small class="text-muted"><?php echo e($row['email'] ?? 'No email'); ?></small>
                        </div>
                    </div>
                <?php endforeach; endif; ?>
            </div>
            <?php if (can('manage_contacts')): ?>
            <div class="card-footer text-center"><a href="customers.php" class="text-muted small">View all customers</a></div>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php if ($low_stock && can('manage_inventory')): ?>
<div class="row">
    <div class="col-lg-6">
        <div class="card">
            <div class="card-header"><h5 class="card-title">Low Stock Alerts</h5></div>
            <div class="card-body p-3">
                <?php foreach ($low_stock as $row): ?>
                    <div class="alert alert-warning mb-2">
                        <strong><?php echo e($row['name']); ?></strong> (<?php echo e($row['code']); ?>)
                        — Stock: <?php echo e((float)$row['total_stock']); ?> / Min: <?php echo e((float)$row['min_stock_level']); ?>
                    </div>
                <?php endforeach; ?>
                <a href="inventory.php" class="btn btn-sm btn-outline-primary mt-2">Go to Inventory</a>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<script src="assets/js/chart.min.js"></script>
<script>
const salesChart = new Chart(document.getElementById('salesChart'), {
    type: 'line',
    data: {
        labels: <?php echo json_encode($sales_labels); ?>,
        datasets: [{
            label: 'Sales (<?php echo e($cur); ?>)',
            data: <?php echo json_encode($sales_data); ?>,
            borderColor: '#4f46e5',
            backgroundColor: 'rgba(79, 70, 229, .12)',
            fill: true,
            tension: .4,
            pointRadius: 4,
            pointBackgroundColor: '#4f46e5'
        }]
    },
    options: {
        responsive: true,
        plugins: {
            legend: { display: false },
            tooltip: { callbacks: { label: ctx => '<?php echo e($cur); ?> ' + Number(ctx.parsed.y).toLocaleString() } }
        },
        scales: {
            y: { beginAtZero: true, ticks: { callback: v => '<?php echo e($cur); ?> ' + v.toLocaleString() } }
        }
    }
});

new Chart(document.getElementById('statusChart'), {
    type: 'doughnut',
    data: {
        labels: <?php echo json_encode($status_labels); ?>,
        datasets: [{
            data: <?php echo json_encode($status_data); ?>,
            backgroundColor: ['#4f46e5', '#0ea5e9', '#f59e0b', '#10b981', '#ef4444', '#94a3b8'],
            hoverOffset: 10
        }]
    },
    options: {
        responsive: true,
        cutout: '62%',
        plugins: {
            legend: { position: 'bottom', labels: { boxWidth: 10, font: { size: 11 } } },
            tooltip: { callbacks: { label: ctx => ctx.label + ': ' + ctx.parsed + ' invoices' } }
        }
    }
});
</script>

<?php require_once 'footer.php'; ?>
