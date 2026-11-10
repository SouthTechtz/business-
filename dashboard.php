<?php
require_once 'header.php';
require_once 'auth_check.php';
require_once 'capital-helpers.php';

$page_title = 'Dashboard';
$cur = tenant_currency();

// Brand-scoped Mini Admin: every dashboard figure is limited to this brand.
$brand_scope = current_brand_scope();
$brand_shop_ids = $brand_scope !== '' ? accessible_shop_ids(null, true) : [];
$brand_shop_in = $brand_shop_ids ? implode(',', array_map('intval', $brand_shop_ids)) : '0';

if (is_godown_manager()) {
    redirect('godown.php');
}

if (is_shop_manager() && !tenant_admin_scope()) {
    $assigned_shops = accessible_shops(null, false);
    if ($assigned_shops) {
        redirect('shop-view.php?id=' . (int)$assigned_shops[0]['shop_id']);
    }
    if (can('request_godown_transfers')) redirect('godown-transfers.php');
    redirect('profile.php');
}

if (!tenant_admin_scope() && !is_brand_admin()) {
    $assigned_shops = accessible_shops(null, false);
    if ($assigned_shops) {
        redirect('shop-view.php?id=' . (int)$assigned_shops[0]['shop_id']);
    }
    $assigned_stores = accessible_stores(null, false);
    if ($assigned_stores) {
        redirect('store-view.php?id=' . (int)$assigned_stores[0]['warehouse_id']);
    }
}

// The tenant-wide dashboard is reserved for tenant administrators and the
// brand-scoped Mini Administrators.
if ((!tenant_admin_scope() && !is_brand_admin()) || !can('view_dashboard')) {
    foreach ([
        'manage_pos'      => 'sales.php',
        'manage_invoices' => 'invoices.php',
        'manage_receipts' => 'receipts.php',
        'manage_contacts' => 'customers.php',
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
    'users'             => 0,
    'retail_customers'  => 0,
    'wholesale_customers'=> 0,
    'products'          => 0,
    'invoices'          => 0,
    'orders'            => 0,
    'leads'             => 0,
    'pending_requests'  => 0,
];

// Brand-scoped lead condition (leads themselves carry no brand column; the
// requested storefront products do).
$lead_brand_exists = $brand_scope !== ''
    ? " AND EXISTS (
           SELECT 1 FROM storefront_lead_request_items bi
             JOIN storefront_lead_requests br ON br.request_id = bi.request_id AND br.tenant_id = bi.tenant_id
             JOIN products bp ON bp.product_id = bi.product_id AND bp.tenant_id = bi.tenant_id
            WHERE br.lead_id = l.lead_id AND br.tenant_id = l.tenant_id AND bp.brand = ?
       )"
    : '';
$order_brand_scope = $brand_scope !== '' ? " AND o.shop_id IN ({$brand_shop_in})" : '';
$product_brand_scope = $brand_scope !== '' ? ' AND p.brand = ?' : '';
$lead_brand_params = $brand_scope !== '' ? [$brand_scope] : [];

$queries = [
    'users' => [
        'sql' => "SELECT COUNT(DISTINCT u.user_id) c FROM users u
                    WHERE u.tenant_id = ? AND u.is_active = 1" .
                    ($brand_scope !== ''
                        ? " AND (u.brand_scope = ? OR EXISTS (
                               SELECT 1 FROM user_shops bus
                                 JOIN shops bs ON bs.shop_id = bus.shop_id AND bs.tenant_id = bus.tenant_id
                                WHERE bus.user_id = u.user_id AND bus.tenant_id = u.tenant_id AND bs.brand = ?
                           ))"
                        : '') .
                    " AND NOT EXISTS (
                        SELECT 1 FROM user_roles wur
                        JOIN roles wr ON wr.role_id = wur.role_id
                        WHERE wur.user_id = u.user_id AND wur.tenant_id = u.tenant_id
                          AND LOWER(wr.name) = 'wholesaler'
                    )",
        'params' => $brand_scope !== ''
            ? [$current_user->tenant_id, $brand_scope, $brand_scope]
            : [$current_user->tenant_id],
    ],
    'retail_customers' => [
        'sql' => "SELECT COUNT(*) c FROM contacts WHERE tenant_id = ? AND is_customer = 1 AND customer_type = 'retail'",
        'params' => [$current_user->tenant_id],
    ],
    'wholesale_customers' => [
        'sql' => "SELECT COUNT(*) c FROM wholesaler_accounts WHERE tenant_id = ? AND status = 'active'",
        'params' => [$current_user->tenant_id],
    ],
    'products' => [
        'sql' => "SELECT COUNT(*) c FROM products p WHERE p.tenant_id = ? AND p.is_active = 1{$product_brand_scope}",
        'params' => array_merge([$current_user->tenant_id], $lead_brand_params),
    ],
    'invoices' => [
        'sql' => "SELECT COUNT(*) c FROM invoices WHERE tenant_id = ?{$wh_scope}",
        'params' => [$current_user->tenant_id],
    ],
    'orders' => [
        'sql' => "SELECT COUNT(*) c FROM orders o WHERE o.tenant_id = ?{$order_brand_scope}",
        'params' => [$current_user->tenant_id],
    ],
    'leads' => [
        'sql' => "SELECT COUNT(*) c FROM leads l WHERE l.tenant_id = ? AND l.status NOT IN ('lost'){$lead_brand_exists}",
        'params' => array_merge([$current_user->tenant_id], $lead_brand_params),
    ],
    'pending_requests' => [
        'sql' => "SELECT COUNT(*) c FROM storefront_lead_requests r
                    JOIN leads l ON l.lead_id = r.lead_id AND l.tenant_id = r.tenant_id
                   WHERE r.tenant_id = ? AND r.converted_order_id IS NULL
                     AND l.status NOT IN ('converted', 'lost'){$lead_brand_exists}",
        'params' => array_merge([$current_user->tenant_id], $lead_brand_params),
    ],
];
foreach ($queries as $key => $query) {
    $row = fetch_one($query['sql'], ...$query['params']);
    $counts[$key] = $row ? (int)$row['c'] : 0;
}

// Inventory valuation across all tenant locations.
$valuation = fetch_one(
    "SELECT COALESCE(SUM(s.quantity), 0) units,
            COALESCE(SUM(s.quantity * p.purchase_price), 0) capital,
            COALESCE(SUM(s.quantity * p.sale_price), 0) expected_sales
       FROM inventory_stock s
       JOIN products p ON p.product_id = s.product_id AND p.tenant_id = s.tenant_id
      WHERE s.tenant_id = ?{$product_brand_scope}",
    ...array_merge([$current_user->tenant_id], $lead_brand_params)
) ?: [];
$stock_units = (float)($valuation['units'] ?? 0);
$capital_valuation = (float)($valuation['capital'] ?? 0);
$expected_sales_value = (float)($valuation['expected_sales'] ?? 0);

// Low-stock count across shop stock (all pages use the same definition).
$low_stock_total_row = fetch_one(
    "SELECT COUNT(*) c FROM (
        SELECT p.product_id,
               COALESCE((SELECT SUM(s.quantity)
                           FROM inventory_stock s
                           JOIN warehouses sw ON sw.warehouse_id = s.warehouse_id AND sw.tenant_id = s.tenant_id
                          WHERE s.product_id = p.product_id AND s.tenant_id = p.tenant_id
                            AND sw.is_godown = 0), 0) total_stock,
               p.min_stock_level
          FROM products p
         WHERE p.tenant_id = ? AND p.is_service = 0{$product_brand_scope}
     ) x WHERE x.total_stock <= x.min_stock_level",
    ...array_merge([$current_user->tenant_id], $lead_brand_params)
);
$low_stock_total = (int)($low_stock_total_row['c'] ?? 0);

// Total sales this month: POS sales + money paid on online/wholesale orders.
$row = fetch_one(
    "SELECT (
        COALESCE((SELECT SUM(total) FROM pos_orders
                   WHERE tenant_id = ? AND status = 'completed'
                     AND MONTH(order_date) = MONTH(NOW()) AND YEAR(order_date) = YEAR(NOW()){$wh_scope}), 0)
      + COALESCE((SELECT SUM(COALESCE(Amount_paid, 0) - COALESCE(returned_amount, 0))
                    FROM orders o
                   WHERE o.tenant_id = ? AND o.status NOT IN ('cancelled', 'rejected'){$order_brand_scope}
                     AND MONTH(o.created_at) = MONTH(NOW()) AND YEAR(o.created_at) = YEAR(NOW())), 0)
     ) AS t",
    $current_user->tenant_id,
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
     WHERE p.tenant_id = ? AND p.is_service = 0{$product_brand_scope}
     HAVING total_stock <= p.min_stock_level
     ORDER BY total_stock ASC LIMIT 5",
    ...array_merge([$current_user->tenant_id], $lead_brand_params)
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
    "SELECT contact_id, first_name, last_name, email, customer_type, customer_channel
     FROM contacts WHERE tenant_id = ? AND is_customer = 1
     ORDER BY created_at DESC LIMIT 5",
    $current_user->tenant_id
);

// Recent storefront/order records
$recent_orders = fetch_all(
    "SELECT order_id, order_number, customer_name, status, total, currency_code, created_at
       FROM orders o WHERE o.tenant_id = ?{$order_brand_scope}
      ORDER BY created_at DESC, order_id DESC LIMIT 5",
    $current_user->tenant_id
);

// Recent wholesale accounts
$recent_wholesalers = db_table_exists('wholesaler_accounts')
    ? fetch_all(
        "SELECT w.business_name, w.contact_name, w.status, w.created_at, c.customer_channel
           FROM wholesaler_accounts w
           LEFT JOIN contacts c ON c.contact_id = w.contact_id AND c.tenant_id = w.tenant_id
          WHERE w.tenant_id = ?
          ORDER BY w.created_at DESC, w.wholesaler_id DESC LIMIT 5",
        $current_user->tenant_id
    )
    : [];

// Total earned by each shop: completed POS sales + paid online/wholesale
// orders fulfilled by the shop, less completed refunds.
$shop_earnings = fetch_all(
    "SELECT s.shop_id, s.name, s.code, s.is_active,
            COALESCE((
                SELECT SUM(po.total)
                  FROM pos_orders po
                  JOIN warehouses w ON w.warehouse_id = po.warehouse_id AND w.tenant_id = po.tenant_id
                 WHERE po.tenant_id = s.tenant_id
                   AND w.shop_id = s.shop_id
                   AND po.status = 'completed'
            ), 0) AS pos_sales,
            COALESCE((
                SELECT SUM(COALESCE(o.Amount_paid, 0))
                  FROM orders o
                 WHERE o.tenant_id = s.tenant_id
                   AND o.status NOT IN ('cancelled', 'rejected')
                   AND (
                       o.shop_id = s.shop_id
                       OR (o.shop_id IS NULL AND o.warehouse_id IN (
                           SELECT w2.warehouse_id FROM warehouses w2
                            WHERE w2.tenant_id = s.tenant_id AND w2.shop_id = s.shop_id
                       ))
                   )
            ), 0) AS order_payments,
            COALESCE((
                SELECT SUM(sr.refund_amount)
                  FROM sales_returns sr
                 WHERE sr.tenant_id = s.tenant_id
                   AND sr.status = 'completed'
                   AND (
                       sr.shop_id = s.shop_id
                       OR (sr.shop_id IS NULL AND sr.warehouse_id IN (
                           SELECT w3.warehouse_id FROM warehouses w3
                            WHERE w3.tenant_id = s.tenant_id AND w3.shop_id = s.shop_id
                       ))
                   )
            ), 0) AS refunds
       FROM shops s
      WHERE s.tenant_id = ?" . ($brand_scope !== '' ? ' AND s.brand = ?' : '') . "
      ORDER BY s.is_active DESC, s.name ASC",
    ...($brand_scope !== ''
        ? [$current_user->tenant_id, $brand_scope]
        : [$current_user->tenant_id])
);

// Orders not linked to a shop (for example an online order awaiting shop
// assignment) are shown separately so the card still reconciles.
$unassigned_earnings = fetch_one(
    "SELECT
        COALESCE((SELECT SUM(COALESCE(o.Amount_paid, 0) - COALESCE(o.returned_amount, 0))
                    FROM orders o
                   WHERE o.tenant_id = ? AND o.status NOT IN ('cancelled', 'rejected')
                     AND o.shop_id IS NULL
                     AND (o.warehouse_id IS NULL OR o.warehouse_id NOT IN (
                         SELECT w.warehouse_id FROM warehouses w
                          WHERE w.tenant_id = ? AND w.shop_id IS NOT NULL
                     ))), 0) AS order_payments,
        COALESCE((SELECT SUM(sr.refund_amount) FROM sales_returns sr
                   WHERE sr.tenant_id = ? AND sr.status = 'completed'
                     AND sr.shop_id IS NULL
                     AND (sr.warehouse_id IS NULL OR sr.warehouse_id NOT IN (
                         SELECT w.warehouse_id FROM warehouses w
                          WHERE w.tenant_id = ? AND w.shop_id IS NOT NULL
                     ))), 0) AS refunds",
    $current_user->tenant_id,
    $current_user->tenant_id,
    $current_user->tenant_id,
    $current_user->tenant_id
) ?: [];
$unassigned_orders = (float)($unassigned_earnings['order_payments'] ?? 0);
$unassigned_refunds = (float)($unassigned_earnings['refunds'] ?? 0);
if ($brand_scope === '' && (abs($unassigned_orders) > 0.005 || abs($unassigned_refunds) > 0.005)) {
    $shop_earnings[] = [
        'shop_id' => 0,
        'name' => 'Unassigned',
        'code' => 'Orders not linked to a shop',
        'is_active' => 0,
        'pos_sales' => 0,
        'order_payments' => $unassigned_orders,
        'refunds' => $unassigned_refunds,
    ];
}

$shop_pos_total = 0.0;
$shop_order_total = 0.0;
$shop_refunds_total = 0.0;
$shop_earnings_total = 0.0;

// Shop opening sales are entered per shop by an administrator.
$shop_opening_balances = shop_opening_balances($current_user->tenant_id);

foreach ($shop_earnings as &$shop_row) {
    $opening_shop_id = (int)($shop_row['shop_id'] ?? 0);
    $shop_row['opening_sales'] = $opening_shop_id > 0
        ? (float)($shop_opening_balances[$opening_shop_id]['opening_sales'] ?? 0)
        : 0.0;
}
unset($shop_row);

$shop_opening_total = 0.0;
foreach ($shop_earnings as $shop_row) {
    $shop_pos = (float)($shop_row['pos_sales'] ?? 0);
    $shop_orders = (float)($shop_row['order_payments'] ?? 0);
    $shop_refunds = (float)($shop_row['refunds'] ?? 0);
    $shop_opening = (float)($shop_row['opening_sales'] ?? 0);
    $shop_pos_total += $shop_pos;
    $shop_order_total += $shop_orders;
    $shop_refunds_total += $shop_refunds;
    $shop_opening_total += $shop_opening;
    $shop_earnings_total += $shop_pos + $shop_orders - $shop_refunds + $shop_opening;
}
$all_time_sales = $shop_earnings_total;

// Dual-tier capital position for this brand (or both brands for an administrator).
$dashboard_breakdown = can_manage_capital()
    ? brand_capital_breakdown($current_user->tenant_id, $brand_scope)
    : ['cash' => 0, 'bank' => 0, 'liquid' => 0, 'stock' => 0, 'total' => 0];
$dashboard_balance = can_manage_capital()
    ? capital_balance_sheet($current_user->tenant_id, $brand_scope)
    : ['cash' => 0, 'net_profit' => 0];
$dashboard_brand_rows = ($brand_scope === '' && can_manage_capital())
    ? brand_capital_breakdown_by_brand($current_user->tenant_id)
    : [];

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
                <div class="widget-value"><?php echo (int)$counts['retail_customers']; ?></div>
                <div class="widget-label">Retail Customers</div>
            </div>
        <?php if ($card_link): ?></a><?php else: ?></div><?php endif; ?>
    </div>
    <?php $card_link = 'customers.php?tab=wholesalers'; ?>
    <div class="col-md-6 col-xl-3 mb-3">
        <a href="<?php echo e($card_link); ?>" class="widget widget-link">
            <div class="widget-icon wi-purple"><i class="fa fa-briefcase"></i></div>
            <div class="widget-info">
                <div class="widget-value"><?php echo (int)$counts['wholesale_customers']; ?></div>
                <div class="widget-label">Wholesale Customers</div>
            </div>
        </a>
    </div>
    <?php $card_link = can_manage_product_catalogue() ? 'products.php' : ''; ?>
    <div class="col-md-6 col-xl-3 mb-3">
        <?php if ($card_link): ?><a href="<?php echo e($card_link); ?>" class="widget widget-link"><?php else: ?><div class="widget"><?php endif; ?>
            <div class="widget-icon wi-amber"><i class="fa fa-cube"></i></div>
            <div class="widget-info">
                <div class="widget-value"><?php echo (int)$counts['products']; ?></div>
                <div class="widget-label">Active Products</div>
            </div>
        <?php if ($card_link): ?></a><?php else: ?></div><?php endif; ?>
    </div>
</div>

<div class="row">
    <?php $card_link = can('manage_invoices') ? 'invoices.php' : ''; ?>
    <div class="col-md-6 col-xl-3 mb-3">
        <?php if ($card_link): ?><a href="<?php echo e($card_link); ?>" class="widget widget-link"><?php else: ?><div class="widget"><?php endif; ?>
            <div class="widget-icon wi-red"><i class="fa fa-file-text"></i></div>
            <div class="widget-info">
                <div class="widget-value"><?php echo (int)$counts['invoices']; ?></div>
                <div class="widget-label">Invoices</div>
            </div>
        <?php if ($card_link): ?></a><?php else: ?></div><?php endif; ?>
    </div>
    <?php $card_link = can('manage_orders') ? 'orders.php' : ''; ?>
    <div class="col-md-6 col-xl-3 mb-3">
        <?php if ($card_link): ?><a href="<?php echo e($card_link); ?>" class="widget widget-link"><?php else: ?><div class="widget"><?php endif; ?>
            <div class="widget-icon wi-blue"><i class="fa fa-list-alt"></i></div>
            <div class="widget-info">
                <div class="widget-value"><?php echo (int)$counts['orders']; ?></div>
                <div class="widget-label">Total Orders</div>
            </div>
        <?php if ($card_link): ?></a><?php else: ?></div><?php endif; ?>
    </div>
    <?php $card_link = can('manage_leads') ? 'leads.php' : ''; ?>
    <div class="col-md-6 col-xl-3 mb-3">
        <?php if ($card_link): ?><a href="<?php echo e($card_link); ?>" class="widget widget-link"><?php else: ?><div class="widget"><?php endif; ?>
            <div class="widget-icon wi-cyan"><i class="fa fa-bullseye"></i></div>
            <div class="widget-info">
                <div class="widget-value"><?php echo (int)$counts['leads']; ?></div>
                <div class="widget-label">Leads</div>
            </div>
        <?php if ($card_link): ?></a><?php else: ?></div><?php endif; ?>
    </div>
    <?php $card_link = can('manage_leads') ? 'leads.php' : ''; ?>
    <div class="col-md-6 col-xl-3 mb-3">
        <?php if ($card_link): ?><a href="<?php echo e($card_link); ?>" class="widget widget-link"><?php else: ?><div class="widget"><?php endif; ?>
            <div class="widget-icon wi-amber"><i class="fa fa-inbox"></i></div>
            <div class="widget-info">
                <div class="widget-value"><?php echo (int)$counts['pending_requests']; ?></div>
                <div class="widget-label">Pending Order Requests</div>
            </div>
        <?php if ($card_link): ?></a><?php else: ?></div><?php endif; ?>
    </div>
</div>

<div class="row">
    <?php $card_link = can('manage_pos') ? 'sales.php' : ''; ?>
    <div class="col-md-6 col-xl-3 mb-3">
        <?php if ($card_link): ?><a href="<?php echo e($card_link); ?>" class="widget widget-link"><?php else: ?><div class="widget"><?php endif; ?>
            <div class="widget-icon wi-green"><i class="fa fa-money"></i></div>
            <div class="widget-info">
                <div class="widget-value" style="font-size:18px;"><?php echo e(money($month_sales, $cur)); ?></div>
                <div class="widget-label">Sales this month</div>
                <div class="small text-muted" style="margin-top:4px;">All-time incl. shop opening: <?php echo e(money($all_time_sales, $cur)); ?></div>
            </div>
        <?php if ($card_link): ?></a><?php else: ?></div><?php endif; ?>
    </div>
    <div class="col-md-6 col-xl-3 mb-3">
        <div class="widget">
            <div class="widget-icon wi-purple"><i class="fa fa-bank"></i></div>
            <div class="widget-info">
                <div class="widget-value" style="font-size:18px;"><?php echo e(money($capital_valuation, $cur)); ?></div>
                <div class="widget-label">Stock Capital</div>
                <div class="small text-muted" style="margin-top:4px;">Inventory at purchase price</div>
            </div>
        </div>
    </div>
    <div class="col-md-6 col-xl-3 mb-3">
        <div class="widget">
            <div class="widget-icon wi-cyan"><i class="fa fa-line-chart"></i></div>
            <div class="widget-info">
                <div class="widget-value" style="font-size:18px;"><?php echo e(money($expected_sales_value, $cur)); ?></div>
                <div class="widget-label">Expected Sales Value</div>
            </div>
        </div>
    </div>
    <div class="col-md-6 col-xl-3 mb-3">
        <?php $card_link = can('manage_inventory') ? 'inventory.php' : ''; ?>
        <?php if ($card_link): ?><a href="<?php echo e($card_link); ?>" class="widget widget-link"><?php else: ?><div class="widget"><?php endif; ?>
            <div class="widget-icon wi-red"><i class="fa fa-exclamation-triangle"></i></div>
            <div class="widget-info">
                <div class="widget-value"><?php echo (int)$low_stock_total; ?></div>
                <div class="widget-label">Low Stock Items</div>
            </div>
        <?php if ($card_link): ?></a><?php else: ?></div><?php endif; ?>
    </div>
</div>

<?php if (can_manage_capital()): ?>
<div class="row">
    <div class="col-md-6 col-xl mb-3">
        <a href="capital.php" class="widget widget-link">
            <div class="widget-icon wi-green"><i class="fa fa-money"></i></div>
            <div class="widget-info">
                <div class="widget-value" style="font-size:18px;"><?php echo e(money($dashboard_breakdown['cash'], $cur)); ?></div>
                <div class="widget-label">Cash in Hand</div>
                <div class="small text-muted" style="margin-top:4px;"><?php echo e($brand_scope !== '' ? brand_label($brand_scope) : 'Both brands combined'); ?></div>
            </div>
        </a>
    </div>
    <div class="col-md-6 col-xl mb-3">
        <a href="capital.php" class="widget widget-link">
            <div class="widget-icon wi-blue"><i class="fa fa-bank"></i></div>
            <div class="widget-info">
                <div class="widget-value" style="font-size:18px;"><?php echo e(money($dashboard_breakdown['bank'], $cur)); ?></div>
                <div class="widget-label">Bank Balance</div>
                <div class="small text-muted" style="margin-top:4px;">All brand bank accounts</div>
            </div>
        </a>
    </div>
    <div class="col-md-6 col-xl mb-3">
        <a href="capital.php" class="widget widget-link">
            <div class="widget-icon wi-indigo"><i class="fa fa-tint"></i></div>
            <div class="widget-info">
                <div class="widget-value" style="font-size:18px;"><?php echo e(money($dashboard_breakdown['liquid'], $cur)); ?></div>
                <div class="widget-label">Liquid Capital</div>
                <div class="small text-muted" style="margin-top:4px;">Cash + bank</div>
            </div>
        </a>
    </div>
    <div class="col-md-6 col-xl mb-3">
        <a href="capital.php" class="widget widget-link">
            <div class="widget-icon wi-purple"><i class="fa fa-cubes"></i></div>
            <div class="widget-info">
                <div class="widget-value" style="font-size:18px;"><?php echo e(money($dashboard_breakdown['stock'], $cur)); ?></div>
                <div class="widget-label">Stock Capital</div>
                <div class="small text-muted" style="margin-top:4px;">Inventory at purchase price</div>
            </div>
        </a>
    </div>
    <div class="col-md-6 col-xl mb-3">
        <a href="capital.php" class="widget widget-link">
            <div class="widget-icon wi-amber"><i class="fa fa-pie-chart"></i></div>
            <div class="widget-info">
                <div class="widget-value" style="font-size:18px;"><?php echo e(money($dashboard_breakdown['total'], $cur)); ?></div>
                <div class="widget-label">Total Brand Capital</div>
                <div class="small text-muted" style="margin-top:4px;">Operating profit: <?php echo e(money($dashboard_balance['net_profit'] ?? 0, $cur)); ?></div>
            </div>
        </a>
    </div>
</div>
<?php endif; ?>

<?php if ($dashboard_brand_rows): ?>
<div class="card mb-3">
    <div class="card-header d-flex justify-content-between align-items-center flex-wrap" style="gap:8px;">
        <div>
            <h5 class="card-title mb-0">Capital by Brand</h5>
            <small class="text-muted">Cash in hand, bank balance, liquid capital and stock capital for each brand.</small>
        </div>
        <a href="capital.php" class="btn btn-sm btn-light"><i class="fa fa-bank mr-1"></i> Open Capital</a>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead>
                    <tr>
                        <th>Brand</th>
                        <th class="text-right">Cash in Hand</th>
                        <th class="text-right">Bank Balance</th>
                        <th class="text-right">Liquid Capital</th>
                        <th class="text-right">Stock Capital</th>
                        <th class="text-right">Total Brand Capital</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($dashboard_brand_rows as $brand_row): ?>
                    <tr>
                        <td><a href="capital.php?brand=<?php echo urlencode((string)$brand_row['brand']); ?>" class="badge badge-navy"><?php echo e(brand_label($brand_row['brand'])); ?></a></td>
                        <td class="text-right"><?php echo e(money($brand_row['cash'], $cur)); ?></td>
                        <td class="text-right"><?php echo e(money($brand_row['bank'], $cur)); ?></td>
                        <td class="text-right font-weight-semibold"><?php echo e(money($brand_row['liquid'], $cur)); ?></td>
                        <td class="text-right"><?php echo e(money($brand_row['stock'], $cur)); ?></td>
                        <td class="text-right font-weight-semibold"><?php echo e(money($brand_row['total'], $cur)); ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php endif; ?>

<div class="row">
    <div class="col-12 mb-3">
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center flex-wrap" style="gap:8px;">
                <div>
                    <h5 class="card-title mb-0">Total Earned by Shop</h5>
                    <small class="text-muted">Completed POS sales, order payments and shop opening sales, less sales returns and refunds.</small>
                </div>
                <span class="badge badge-primary" style="font-size:12px;padding:7px 12px;">
                    <?php echo e(money($shop_earnings_total, $cur)); ?> total
                </span>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover mb-0">
                        <thead>
                            <tr>
                                <th>Shop</th>
                                <th class="text-right">POS Sales</th>
                                <th class="text-right">Order Payments</th>
                                <th class="text-right">Refunds</th>
                                <th class="text-right">Opening</th>
                                <th class="text-right">Total Earned</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php if (!$shop_earnings): ?>
                            <tr><td colspan="6" class="text-center text-muted py-4">No shops yet.</td></tr>
                        <?php else: foreach ($shop_earnings as $shop_row): ?>
                            <?php
                            $shop_pos = (float)($shop_row['pos_sales'] ?? 0);
                            $shop_orders = (float)($shop_row['order_payments'] ?? 0);
                            $shop_refunds = (float)($shop_row['refunds'] ?? 0);
                            $shop_opening = (float)($shop_row['opening_sales'] ?? 0);
                            $shop_net = $shop_pos + $shop_orders - $shop_refunds + $shop_opening;
                            ?>
                            <tr>
                                <td>
                                    <?php if ((int)$shop_row['shop_id'] > 0): ?>
                                        <a href="shop-view.php?id=<?php echo (int)$shop_row['shop_id']; ?>" class="font-weight-semibold"><?php echo e($shop_row['name']); ?></a>
                                    <?php else: ?>
                                        <span class="font-weight-semibold"><?php echo e($shop_row['name']); ?></span>
                                    <?php endif; ?>
                                    <div class="text-muted small">
                                        <?php echo e($shop_row['code']); ?><?php echo ((int)$shop_row['is_active'] === 1 || (int)$shop_row['shop_id'] === 0) ? '' : ' &middot; Inactive'; ?>
                                    </div>
                                </td>
                                <td class="text-right"><?php echo e(money($shop_pos, $cur)); ?></td>
                                <td class="text-right"><?php echo e(money($shop_orders, $cur)); ?></td>
                                <td class="text-right <?php echo $shop_refunds > 0 ? 'text-danger' : 'text-muted'; ?>">
                                    <?php echo $shop_refunds > 0 ? '- ' . e(money($shop_refunds, $cur)) : e(money(0, $cur)); ?>
                                </td>
                                <td class="text-right"><?php echo e(money($shop_opening, $cur)); ?></td>
                                <td class="text-right font-weight-semibold"><?php echo e(money($shop_net, $cur)); ?></td>
                            </tr>
                        <?php endforeach; endif; ?>
                        </tbody>
                        <?php if ($shop_earnings): ?>
                        <tfoot>
                            <tr>
                                <th>Total</th>
                                <th class="text-right"><?php echo e(money($shop_pos_total, $cur)); ?></th>
                                <th class="text-right"><?php echo e(money($shop_order_total, $cur)); ?></th>
                                <th class="text-right"><?php echo $shop_refunds_total > 0 ? '- ' . e(money($shop_refunds_total, $cur)) : e(money(0, $cur)); ?></th>
                                <th class="text-right"><?php echo e(money($shop_opening_total, $cur)); ?></th>
                                <th class="text-right"><?php echo e(money($shop_earnings_total, $cur)); ?></th>
                            </tr>
                        </tfoot>
                        <?php endif; ?>
                    </table>
                </div>
            </div>
        </div>
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

<div class="row">
    <div class="col-lg-8 mb-3">
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h5 class="card-title">Recent Orders</h5>
                <?php if (can('manage_orders')): ?><a href="orders.php" class="btn btn-sm btn-outline-primary">View all</a><?php endif; ?>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover mb-0">
                        <thead>
                            <tr><th>Order #</th><th>Customer</th><th>Status</th><th>Date</th><th class="text-right">Total</th></tr>
                        </thead>
                        <tbody>
                        <?php if (!$recent_orders): ?>
                            <tr><td colspan="5" class="text-center text-muted py-4">No orders yet.</td></tr>
                        <?php else: foreach ($recent_orders as $row): ?>
                            <tr>
                                <td><?php echo e($row['order_number']); ?></td>
                                <td><?php echo e($row['customer_name']); ?></td>
                                <td><span class="badge badge-secondary"><?php echo e(ucfirst((string)$row['status'])); ?></span></td>
                                <td><?php echo e(date('M d, Y', strtotime($row['created_at']))); ?></td>
                                <td class="text-right font-weight-semibold"><?php echo e(money($row['total'], $row['currency_code'] ?: $cur)); ?></td>
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
            <div class="card-header d-flex justify-content-between align-items-center">
                <h5 class="card-title">Recent Wholesale Accounts</h5>
                <a href="customers.php?tab=wholesalers" class="btn btn-sm btn-outline-primary">View all</a>
            </div>
            <div class="card-body p-3">
                <?php if (!$recent_wholesalers): ?>
                    <div class="text-center text-muted py-4">No wholesale accounts yet.</div>
                <?php else: foreach ($recent_wholesalers as $row): ?>
                    <div class="d-flex align-items-start mb-3">
                        <span class="avatar mr-3" style="background:var(--purple)"><?php echo e(strtoupper(substr($row['business_name'] ?? '?', 0, 1))); ?></span>
                        <div class="min-w-0">
                            <div class="font-weight-semibold"><?php echo e($row['business_name']); ?></div>
                            <small class="text-muted">
                                <?php echo e(date('M d, Y', strtotime($row['created_at']))); ?> &middot;
                                <?php echo e(strtolower((string)$row['status']) === 'active' ? 'Active' : 'Suspended'); ?>
                                &middot; <?php echo e(strtolower((string)($row['customer_channel'] ?? '')) === 'online' ? 'Online' : 'Walk-in'); ?>
                            </small>
                        </div>
                    </div>
                <?php endforeach; endif; ?>
            </div>
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
            borderColor: '#BE2430',
            backgroundColor: 'rgba(190, 36, 48, .12)',
            fill: true,
            tension: .4,
            pointRadius: 4,
            pointBackgroundColor: '#BE2430'
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
            backgroundColor: ['#BE2430', '#0ea5e9', '#f59e0b', '#10b981', '#ef4444', '#94a3b8'],
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
