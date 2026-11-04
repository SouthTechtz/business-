<?php
require_once 'header.php';
require_once 'auth_check.php';
require_permission('manage_inventory');
$page_title = 'Inventory';

$tenant_id = (int)$current_user->tenant_id;
$shop_manager_inventory = is_shop_manager() && !tenant_admin_scope();
$godown_manager_inventory = is_godown_manager();
$can_godown = !$shop_manager_inventory && (
    can('manage_godown')
    || can('view_godown')
    || can('approve_godown_transfers')
    || can('dispatch_godown_transfers')
    || can('receive_godown_transfers')
);
$can_godown_page = can('manage_godown') || can('view_godown');
$can_request_stock = can('manage_godown') || can('request_godown_transfers');

if (can('manage_godown') && !$godown_manager_inventory) {
    ensure_central_godown($tenant_id);
}

$tabs = [];
if ($godown_manager_inventory) {
    $tabs = ['godown' => 'My Godown Inventory'];
} elseif ($can_godown) {
    $tabs['godown'] = 'Godown';
}
$tabs['stores'] = 'Stores';
$tabs['shops'] = 'Shops';
$shops = accessible_shops(null, !$shop_manager_inventory);
$stores = $shop_manager_inventory ? [] : accessible_stores(null, true);
$godowns = [];
if ($can_godown) {
    if (tenant_admin_scope()) {
        $godowns = fetch_all(
            "SELECT * FROM warehouses
              WHERE tenant_id = ? AND location_type = 'godown'
              ORDER BY is_active DESC, name",
            $tenant_id
        );
    } else {
        $godowns = array_values(array_filter(
            accessible_locations(true, $tenant_id),
            fn($location) => (string)$location['location_type'] === 'godown'
        ));
    }
}
if ($godown_manager_inventory) {
    $tabs = ['godown' => 'My Godown Inventory'];
} elseif ($shop_manager_inventory) {
    $tabs = ['shops' => 'Shop Inventory'];
}
$tab = (string)($_GET['tab'] ?? '');
$tab_requested = isset($_GET['tab']);
if (!isset($tabs[$tab])) {
    $tab = array_key_first($tabs);
}

/* ---------------- Locations available to the current user ---------------- */
// Without an existing Godown, open on a tab that has something to show.
if (!$godown_manager_inventory && !$tab_requested && $tab === 'godown' && !$godowns) {
    $tab = (isset($tabs['stores']) && $stores) ? 'stores' : (isset($tabs['shops']) ? 'shops' : $tab);
}

/* ---------------- Selected location ---------------- */
$godown_ids = array_map('intval', array_column($godowns, 'warehouse_id'));
$godown_id = (int)($_GET['godown'] ?? 0);
if (!in_array($godown_id, $godown_ids, true)) {
    $godown_id = $godown_ids[0] ?? 0;
}

$store_ids = array_map('intval', array_column($stores, 'warehouse_id'));
$store_id = (int)($_GET['store'] ?? 0);
if (!in_array($store_id, $store_ids, true)) {
    $store_id = 0;
    foreach ($stores as $store) {
        if ((int)$store['is_active'] === 1) {
            $store_id = (int)$store['warehouse_id'];
            break;
        }
    }
    if ($store_id === 0) {
        $store_id = $store_ids[0] ?? 0;
    }
}

$shop_ids = array_map('intval', array_column($shops, 'shop_id'));
$shop_id = (int)($_GET['shop'] ?? 0);
if (!in_array($shop_id, $shop_ids, true)) {
    $shop_id = 0;
    foreach ($shops as $shop) {
        if ((int)$shop['is_active'] === 1) {
            $shop_id = (int)$shop['shop_id'];
            break;
        }
    }
    if ($shop_id === 0) {
        $shop_id = $shop_ids[0] ?? 0;
    }
}

$godown_by_id = [];
foreach ($godowns as $godown) {
    $godown_by_id[(int)$godown['warehouse_id']] = $godown;
}
$store_by_id = [];
foreach ($stores as $store) {
    $store_by_id[(int)$store['warehouse_id']] = $store;
}
$shop_by_id = [];
foreach ($shops as $shop) {
    $shop_by_id[(int)$shop['shop_id']] = $shop;
}

/* Warehouse IDs whose stock the current tab lists. */
$scope_ids = [];
$location_name = '';
if ($tab === 'godown' && $godown_id > 0 && isset($godown_by_id[$godown_id])) {
    $scope_ids = [$godown_id];
    $location_name = $godown_by_id[$godown_id]['name'];
} elseif ($tab === 'stores' && $store_id > 0 && isset($store_by_id[$store_id])) {
    $scope_ids = [$store_id];
    $location_name = store_label($store_by_id[$store_id]);
} elseif ($tab === 'shops' && $shop_id > 0 && isset($shop_by_id[$shop_id])) {
    $location_name = $shop_by_id[$shop_id]['name'];
    if ($shop_manager_inventory) {
        $inventory_warehouse_id = shop_inventory_warehouse_id($shop_id, $tenant_id);
        if ($inventory_warehouse_id) {
            $scope_ids[] = $inventory_warehouse_id;
        }
    } else {
        foreach ($stores as $store) {
            if ((int)$store['shop_id'] === $shop_id) {
                $scope_ids[] = (int)$store['warehouse_id'];
            }
        }
    }
}

/* ---------------- Listing filters ---------------- */
$q = trim((string)($_GET['q'] ?? ''));
$category_id = (int)($_GET['category'] ?? 0);
$stock_filter = (string)($_GET['stock'] ?? '');
if (!in_array($stock_filter, ['in', 'low', 'out'], true)) {
    $stock_filter = '';
}
$categories = $scope_ids
    ? fetch_all(
        "SELECT DISTINCT c.category_id, c.name
           FROM product_categories c
           JOIN products p ON p.category_id = c.category_id AND p.tenant_id = c.tenant_id
           JOIN inventory_stock stock ON stock.product_id = p.product_id
                AND stock.tenant_id = p.tenant_id
          WHERE c.tenant_id = ? AND stock.warehouse_id IN (" . implode(',', array_map('intval', $scope_ids)) . ")
          ORDER BY c.name",
        $tenant_id
    )
    : [];
if ($category_id > 0 && !in_array($category_id, array_map('intval', array_column($categories, 'category_id')), true)) {
    $category_id = 0;
}

/* ---------------- Products for the selected location ---------------- */
$rows = [];
if ($scope_ids) {
    $in_list = implode(',', array_map('intval', $scope_ids));
    $sql = "SELECT p.product_id, p.code, p.name, p.min_stock_level, p.sale_price, p.is_active,
                   c.name category_name, COALESCE(u.short_name, '') unit_short,
                   COALESCE(st.qty, 0) qty, st.stock_id, st.last_counted_at
              FROM products p
              LEFT JOIN product_categories c ON c.category_id = p.category_id AND c.tenant_id = p.tenant_id
              LEFT JOIN units_of_measurement u ON u.unit_id = p.unit_id AND u.tenant_id = p.tenant_id
              LEFT JOIN (
                    SELECT product_id, MAX(stock_id) stock_id, SUM(quantity) qty, MAX(last_counted_at) last_counted_at
                      FROM inventory_stock
                     WHERE tenant_id = ? AND warehouse_id IN ({$in_list})
                     GROUP BY product_id
              ) st ON st.product_id = p.product_id
             WHERE p.tenant_id = ? AND p.is_service = 0";
    $params = [$tenant_id, $tenant_id];
    if ($shop_manager_inventory || $godown_manager_inventory) {
        $sql .= " AND EXISTS (
                    SELECT 1 FROM inventory_stock own_stock
                     WHERE own_stock.tenant_id = p.tenant_id
                       AND own_stock.product_id = p.product_id
                       AND own_stock.warehouse_id IN ({$in_list})
                  )";
    }

    if ($q !== '') {
        $like = '%' . $q . '%';
        $sql .= " AND (p.name LIKE ? OR p.code LIKE ? OR p.sku LIKE ? OR p.barcode LIKE ?)";
        array_push($params, $like, $like, $like, $like);
    }
    if ($category_id > 0) {
        $sql .= " AND p.category_id = ?";
        $params[] = $category_id;
    }
    if ($stock_filter === 'in') {
        $sql .= " AND COALESCE(st.qty, 0) > 0";
    } elseif ($stock_filter === 'out') {
        $sql .= " AND COALESCE(st.qty, 0) <= 0";
    } elseif ($stock_filter === 'low') {
        $sql .= " AND COALESCE(st.qty, 0) > 0 AND COALESCE(st.qty, 0) <= p.min_stock_level";
    }
    $sql .= " ORDER BY p.name, p.product_id";
    $rows = fetch_all($sql, ...$params);
}

/* Summary cards for the current listing (follows the filters). */
$summary = ['products' => count($rows), 'qty' => 0.0, 'expected_sales_value' => 0.0, 'low' => 0, 'out' => 0];
foreach ($rows as $row) {
    $qty = (float)$row['qty'];
    $summary['qty'] += $qty;
    $summary['expected_sales_value'] += max(0, $qty) * (float)$row['sale_price'];
    if ($qty <= 0) {
        $summary['out']++;
    } elseif ($qty <= (float)$row['min_stock_level']) {
        $summary['low']++;
    }
}

require_once 'navbar.php';
require_once 'sidebar.php';
?>

<div class="page-head">
    <div>
        <h1 class="page-title">Inventory</h1>
        <div class="page-sub"><?php echo $godown_manager_inventory ? 'Products and stock for your assigned Godown only.' : ($shop_manager_inventory ? 'Products and stock for your assigned Shop only.' : 'Stock by Godown, store and shop. Godown stock is shown separately and is never treated as shop POS availability.'); ?></div>
    </div>
    <div class="d-flex flex-wrap" style="gap:8px;">
        <?php if (!$shop_manager_inventory && !$godown_manager_inventory && tenant_admin_scope() && can('manage_shops')): ?>
            <a href="shops.php" class="btn btn-outline-secondary"><i class="fa fa-plus mr-1"></i> Add Shop</a>
            <a href="stores.php" class="btn btn-outline-secondary"><i class="fa fa-plus mr-1"></i> Add Store</a>
        <?php endif; ?>
        <?php if (!$shop_manager_inventory && $can_godown_page): ?>
            <a href="godown.php" class="btn btn-outline-primary"><i class="fa fa-archive mr-1"></i> <?php echo $godown_manager_inventory ? 'My Godown' : 'Central Godown'; ?></a>
        <?php endif; ?>
        <?php if (!$shop_manager_inventory && $can_request_stock): ?>
            <a href="godown-transfer-add.php" class="btn btn-primary"><i class="fa fa-exchange mr-1"></i> Request Stock</a>
        <?php endif; ?>
    </div>
</div>

<?php if ($f = flash_get()): ?>
    <div class="alert alert-<?php echo e($f['type']); ?>"><?php echo e($f['message']); ?></div>
<?php endif; ?>

<?php
$qty_card_label = [
    'godown' => 'Godown Stock Qty',
    'stores' => 'Store Stock Qty',
    'shops'  => 'Shop Stock Qty',
][$tab];
$qty_card_icon = $tab === 'godown' ? 'fa-archive' : ($tab === 'stores' ? 'fa-home' : 'fa-building');
?>

<div class="row mb-4">
    <div class="col-md-6 col-xl-3 mb-3">
        <div class="widget">
            <div class="widget-icon wi-blue"><i class="fa <?php echo e($qty_card_icon); ?>"></i></div>
            <div class="widget-info">
                <div class="widget-value"><?php echo e(format_quantity($summary['qty'])); ?></div>
                <div class="widget-label"><?php echo e($qty_card_label); ?></div>
            </div>
        </div>
    </div>
    <div class="col-md-6 col-xl-3 mb-3">
        <div class="widget">
            <div class="widget-icon wi-purple"><i class="fa fa-cubes"></i></div>
            <div class="widget-info">
                <div class="widget-value"><?php echo $summary['products']; ?></div>
                <div class="widget-label">Available Products</div>
            </div>
        </div>
    </div>
    <div class="col-md-6 col-xl-3 mb-3">
        <div class="widget">
            <div class="widget-icon wi-amber"><i class="fa fa-exclamation-triangle"></i></div>
            <div class="widget-info">
                <div class="widget-value"><?php echo $summary['low']; ?></div>
                <div class="widget-label">Low Stock</div>
            </div>
        </div>
    </div>
    <div class="col-md-6 col-xl-3 mb-3">
        <div class="widget">
            <div class="widget-icon wi-red"><i class="fa fa-times-circle"></i></div>
            <div class="widget-info">
                <div class="widget-value"><?php echo $summary['out']; ?></div>
                <div class="widget-label">Out of Stock</div>
            </div>
        </div>
    </div>
</div>

<ul class="nav page-tabs">
    <?php foreach ($tabs as $tab_key => $tab_label): ?>
        <li class="nav-item">
            <a class="nav-link <?php echo $tab === $tab_key ? 'active' : ''; ?>" href="inventory.php?tab=<?php echo e($tab_key); ?>">
                <i class="fa <?php echo $tab_key === 'godown' ? 'fa-archive' : ($tab_key === 'stores' ? 'fa-home' : 'fa-building'); ?> mr-1"></i>
                <?php echo e($tab_label); ?>
            </a>
        </li>
    <?php endforeach; ?>
</ul>

<?php
$tab_title = [
    'godown' => $godown_manager_inventory ? 'My Godown Products' : 'Godown Products',
    'stores' => 'Store Products',
    'shops'  => 'Shop Products',
][$tab];
$tab_has_location = ($tab === 'godown' && $godown_id > 0)
    || ($tab === 'stores' && $store_id > 0)
    || ($tab === 'shops' && $shop_id > 0);
$location_options = $tab === 'godown' ? $godowns : ($tab === 'stores' ? $stores : $shops);
$location_empty_message = $tab === 'godown'
    ? ($godown_manager_inventory ? 'No active Godown is assigned to your account. Contact an administrator.' : 'No Godown has been created yet. Create one from the Central Godown page.')
    : ($tab === 'stores' ? 'No store is assigned to your account.' : 'No shop is assigned to your account.');
?>

<div class="card">
    <div class="card-header d-flex justify-content-between align-items-center flex-wrap">
        <h5 class="card-title mb-1"><?php echo e($tab_title); ?><?php echo $location_name !== '' ? ' — ' . e($location_name) : ''; ?></h5>
    </div>

    <?php if (!$location_options): ?>
        <div class="card-body">
            <div class="alert alert-warning mb-0">
                <?php echo e($location_empty_message); ?>
                <?php if ($tab === 'godown' && $can_godown_page): ?>
                    <a href="godown.php?tab=overview" class="alert-link">Open Central Godown</a>.
                <?php endif; ?>
            </div>
        </div>
    <?php else: ?>
        <div class="card-body border-bottom pb-2">
            <form method="GET" action="inventory.php" class="form-row align-items-end">
                <input type="hidden" name="tab" value="<?php echo e($tab); ?>">
                <div class="col-12 col-md-3 form-group mb-2">
                    <label class="small mb-1"><?php echo $tab === 'godown' ? 'Godown' : ($tab === 'stores' ? 'Store' : 'Shop'); ?></label>
                    <?php if ($tab === 'godown'): ?>
                        <select name="godown" class="form-control form-control-sm" onchange="this.form.submit()">
                            <?php foreach ($godowns as $godown): ?>
                                <option value="<?php echo (int)$godown['warehouse_id']; ?>" <?php echo $godown_id === (int)$godown['warehouse_id'] ? 'selected' : ''; ?>>
                                    <?php echo e($godown['name'] . ((int)$godown['is_active'] ? '' : ' (inactive)')); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    <?php elseif ($tab === 'stores'): ?>
                        <select name="store" class="form-control form-control-sm" onchange="this.form.submit()">
                            <?php foreach ($stores as $store): ?>
                                <option value="<?php echo (int)$store['warehouse_id']; ?>" <?php echo $store_id === (int)$store['warehouse_id'] ? 'selected' : ''; ?>>
                                    <?php echo e(store_label($store) . ((int)$store['is_active'] ? '' : ' (inactive)')); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    <?php else: ?>
                        <select name="shop" class="form-control form-control-sm" onchange="this.form.submit()">
                            <?php foreach ($shops as $shop): ?>
                                <option value="<?php echo (int)$shop['shop_id']; ?>" <?php echo $shop_id === (int)$shop['shop_id'] ? 'selected' : ''; ?>>
                                    <?php echo e($shop['name'] . ' (' . $shop['code'] . ')' . ((int)$shop['is_active'] ? '' : ' (inactive)')); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    <?php endif; ?>
                </div>
                <div class="col-6 col-md-3 form-group mb-2">
                    <label class="small mb-1">Search</label>
                    <input type="search" name="q" class="form-control form-control-sm" value="<?php echo e($q); ?>" placeholder="Product, code, SKU, barcode...">
                </div>
                <div class="col-6 col-md-2 form-group mb-2">
                    <label class="small mb-1">Category</label>
                    <select name="category" class="form-control form-control-sm">
                        <option value="0">All categories</option>
                        <?php foreach ($categories as $category): ?>
                            <option value="<?php echo (int)$category['category_id']; ?>" <?php echo $category_id === (int)$category['category_id'] ? 'selected' : ''; ?>>
                                <?php echo e($category['name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-6 col-md-2 form-group mb-2">
                    <label class="small mb-1">Stock</label>
                    <select name="stock" class="form-control form-control-sm">
                        <option value="">All stock</option>
                        <option value="in" <?php echo $stock_filter === 'in' ? 'selected' : ''; ?>>In stock</option>
                        <option value="low" <?php echo $stock_filter === 'low' ? 'selected' : ''; ?>>Low stock</option>
                        <option value="out" <?php echo $stock_filter === 'out' ? 'selected' : ''; ?>>Out of stock</option>
                    </select>
                </div>
                <div class="col-6 col-md-2 form-group mb-2">
                    <button type="submit" class="btn btn-sm btn-primary"><i class="fa fa-filter mr-1"></i> Filter</button>
                    <a href="inventory.php?tab=<?php echo e($tab); ?>" class="btn btn-sm btn-light">Reset</a>
                </div>
            </form>
        </div>

        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover data-table">
                    <thead>
                        <tr>
                            <th>Product</th><th>Code</th><th>Category</th><th>Quantity</th><th>Unit</th><th>Min Level</th><th>Status</th><?php if ($can_request_stock || ($tab === 'godown' && $can_godown_page)): ?><th class="text-right">Actions</th><?php endif; ?>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($rows as $row): ?>
                        <?php
                        $qty = (float)$row['qty'];
                        $min = (float)$row['min_stock_level'];
                        if ($qty <= 0) {
                            $status = '<span class="badge badge-danger">Out</span>';
                        } elseif ($qty <= $min) {
                            $status = '<span class="badge badge-warning">Low</span>';
                        } else {
                            $status = '<span class="badge badge-success">OK</span>';
                        }
                        ?>
                        <tr>
                            <td class="font-weight-semibold">
                                <?php echo e($row['name']); ?>
                                <?php if (!(int)$row['is_active']): ?><span class="badge badge-secondary ml-1">Inactive</span><?php endif; ?>
                            </td>
                            <td><span class="badge badge-secondary"><?php echo e($row['code']); ?></span></td>
                            <td><?php echo e($row['category_name'] ?: '—'); ?></td>
                            <td class="font-weight-semibold" data-order="<?php echo (float)$qty; ?>"><?php echo e(format_quantity($qty)); ?></td>
                            <td><?php echo e($row['unit_short'] ?: ''); ?></td>
                            <td data-order="<?php echo (float)$min; ?>"><?php echo e(format_quantity($min)); ?></td>
                            <td><?php echo $status; ?></td>
                            <?php if ($can_request_stock || ($tab === 'godown' && $can_godown_page)): ?>
                            <td class="text-right">
                                <?php
                                $inventory_actions = [];
                                if ($tab === 'godown') {
                                    if ($can_godown_page) {
                                        $inventory_actions[] = ['label' => 'Receive or transfer Godown stock', 'icon' => 'fa-archive', 'href' => 'godown.php?tab=products'];
                                    }
                                } elseif ($can_request_stock) {
                                    $request_params = $tab === 'stores'
                                        ? ['request_store_id' => (int)$store_id]
                                        : ['request_shop_id' => (int)$shop_id];
                                    $request_params = array_filter($request_params, fn($value) => (int)$value > 0);
                                    $inventory_actions[] = [
                                        'label' => 'Request stock',
                                        'icon' => 'fa-exchange',
                                        'href' => 'godown-transfer-add.php?' . http_build_query($request_params),
                                    ];
                                }
                                echo row_action_menu($inventory_actions, 'Stock actions');
                                ?>
                            </td>
                            <?php endif; ?>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$rows): ?>
                        <tr>
                            <td colspan="<?php echo ($can_request_stock || ($tab === 'godown' && $can_godown_page)) ? 8 : 7; ?>" class="text-center text-muted py-4">
                                <?php if (!$scope_ids): ?>
                                    <?php echo $shop_manager_inventory ? 'No inventory warehouse is available for this Shop.' : 'This location has no inventory warehouse, so no stock can be listed.'; ?>
                                <?php else: ?>
                                    No products match the current filters.
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    <?php endif; ?>
</div>

<?php require_once 'footer.php'; ?>
