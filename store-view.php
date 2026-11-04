<?php
require_once 'header.php';
require_once 'auth_check.php';
if (is_shop_manager() && !tenant_admin_scope()) {
    http_response_code(403);
    die('Shop Managers do not have access to Store dashboards.');
}
require_permission_any(['manage_shops', 'manage_inventory', 'manage_pos', 'manage_orders', 'manage_products']);
$page_title = 'Store Dashboard';

$tenant_id = (int)$current_user->tenant_id;
$accessible_stores = accessible_stores(null, !is_shop_manager());
$store_ids = array_map(fn($s) => (int)$s["warehouse_id"], $accessible_stores);
$requested_store_id = (int)($_GET["id"] ?? 0);
if ($requested_store_id > 0 && !in_array($requested_store_id, $store_ids, true)) {
    $requested_store_id = 0;
}
if ($requested_store_id <= 0) {
    $requested_store_id = $store_ids[0] ?? 0;
}
$warehouse_id = $requested_store_id;
$store = assert_store_access($warehouse_id, !is_shop_manager());
if (($store['location_type'] ?? '') !== 'store') {
    flash_set('danger', 'This page is for stores only.');
    redirect('stores.php');
}
$_SESSION['active_request_destination'] = ['type' => 'store', 'id' => $warehouse_id];
$request_stock_enabled = (int)$store['is_active'] === 1
    && (int)($store['shop_is_active'] ?? 1) === 1
    && can('request_godown_transfers');
$request_source_locations = [];
if ($request_stock_enabled) {
    $request_source_locations = fetch_all(
        "SELECT w.warehouse_id, w.name warehouse_name, w.code warehouse_code,
                w.location_type, s.name shop_name, s.code shop_code
           FROM warehouses w
           LEFT JOIN shops s ON s.shop_id = w.shop_id AND s.tenant_id = w.tenant_id
          WHERE w.tenant_id = ? AND w.warehouse_id <> ? AND w.is_active = 1
            AND w.location_type IN ('godown', 'store')
            AND (w.location_type = 'godown' OR s.is_active = 1)
          ORDER BY CASE w.location_type WHEN 'godown' THEN 0 ELSE 1 END, s.name, w.name",
        $tenant_id,
        $warehouse_id
    );
    $request_transfer_action = 'godown-transfer-add.php?' . http_build_query(['request_store_id' => $warehouse_id]);
    $request_transfer_context = ['request_store_id' => $warehouse_id];
    $request_destination_label = store_label($store);
}

// Summary metrics
$row = fetch_one(
    "SELECT COUNT(DISTINCT s.product_id) AS products,
            COALESCE(SUM(s.quantity), 0) AS qty,
            COALESCE(SUM(s.quantity * p.purchase_price), 0) AS capital,
            COALESCE(SUM(CASE WHEN s.quantity > 0 THEN s.quantity * p.sale_price ELSE 0 END), 0) AS expected_sales_value
       FROM inventory_stock s
       JOIN products p ON p.product_id = s.product_id AND p.tenant_id = s.tenant_id
      WHERE s.tenant_id = ? AND s.warehouse_id = ?",
    $tenant_id,
    $warehouse_id
);
$total_products = (int)($row['products'] ?? 0);
$total_qty = (float)($row['qty'] ?? 0);
$capital = (float)($row['capital'] ?? 0);
$expected_sales_value = (float)($row['expected_sales_value'] ?? 0);
$low_stock = 0;
$out_of_stock = 0;

// Available stock table
$stock_rows = fetch_all(
    "SELECT p.product_id, p.name product_name, p.code product_code, p.sku, p.purchase_price, p.sale_price,
            p.min_stock_level, s.quantity, s.reorder_level, s.last_counted_at
       FROM inventory_stock s
       JOIN products p ON p.product_id = s.product_id AND p.tenant_id = s.tenant_id
      WHERE s.tenant_id = ? AND s.warehouse_id = ?
      ORDER BY p.name",
    $tenant_id,
    $warehouse_id
);

foreach ($stock_rows as $r) {
    $qty = (float)$r["quantity"];
    $threshold = isset($r["reorder_level"]) && $r["reorder_level"] !== null ? (float)$r["reorder_level"] : (float)$r["min_stock_level"];
    if ($qty <= 0) {
        $out_of_stock++;
    } elseif ($qty <= $threshold) {
        $low_stock++;
    }
}

// Movement history
$movement_rows = fetch_all(
    "SELECT m.movement_id, m.created_at, m.movement_type, m.quantity, m.notes,
            p.name product_name, p.code product_code
       FROM inventory_movements m
       JOIN products p ON p.product_id = m.product_id AND p.tenant_id = m.tenant_id
      WHERE m.tenant_id = ? AND m.warehouse_id = ?
      ORDER BY m.created_at DESC, m.movement_id DESC
      LIMIT 100",
    $tenant_id,
    $warehouse_id
);

require_once 'navbar.php';
require_once 'sidebar.php';
$cur = tenant_currency();
$shop = fetch_one("SELECT name, code FROM shops WHERE shop_id = ? AND tenant_id = ?", (int)$store['shop_id'], $tenant_id);
?>
<div class="page-head">
    <div>
        <h1 class="page-title"><?php echo e($store['name']); ?> — Store Dashboard</h1>
        <div class="page-sub">Store code: <?php echo e($store['code']); ?> &middot; Shop: <?php echo e($shop['name'] ?? '—'); ?> &middot; <?php echo count($stock_rows); ?> stock row(s)</div>
    </div>
    <?php if ($request_stock_enabled): ?>
        <button type="button" class="btn btn-primary" data-toggle="modal" data-target="#requestStockModal">
            <i class="fa fa-plus mr-1"></i> Request Stock
        </button>
    <?php endif; ?>
    <?php if (can('manage_inventory') || can('manage_shops') || can('request_godown_transfers') || can('approve_godown_transfers') || can('dispatch_godown_transfers') || can('receive_godown_transfers')): ?>
        <a href="godown-transfers.php" class="btn btn-outline-primary"><i class="fa fa-exchange mr-1"></i> Stock Transfers</a>
    <?php endif; ?>
</div>

<?php if (count($accessible_stores) > 1): ?>
<form method="GET" action="store-view.php" class="form-inline mb-3">
    <label class="mr-2">Store</label>
    <select name="id" class="form-control form-control-sm mr-2" onchange="this.form.submit()">
        <?php foreach ($accessible_stores as $st): ?>
            <option value="<?php echo (int)$st["warehouse_id"]; ?>" <?php echo (int)$st["warehouse_id"] === (int)$warehouse_id ? "selected" : ""; ?>><?php echo e(store_label($st)); ?></option>
        <?php endforeach; ?>
    </select>
</form>
<?php endif; ?>

<div class="row mb-3">
    <div class="col-md-4 col-sm-6 mb-2">
        <div class="card"><div class="card-body">
            <div class="text-muted small">Total Products Available</div>
            <div class="h4 mb-0"><?php echo $total_products; ?></div>
        </div></div>
    </div>
    <div class="col-md-4 col-sm-6 mb-2">
        <div class="card"><div class="card-body">
            <div class="text-muted small">Total Physical Stock Quantity</div>
            <div class="h4 mb-0"><?php echo e(format_quantity($total_qty)); ?></div>
        </div></div>
    </div>
    <div class="col-md-4 col-sm-6 mb-2">
        <div class="card"><div class="card-body">
            <div class="text-muted small">Low Stock Items</div>
            <div class="h4 mb-0"><?php echo $low_stock; ?></div>
        </div></div>
    </div>
    <div class="col-md-4 col-sm-6 mb-2">
        <div class="card"><div class="card-body">
            <div class="text-muted small">Out of Stock Products</div>
            <div class="h4 mb-0"><?php echo $out_of_stock; ?></div>
        </div></div>
    </div>
</div>

<div class="card mb-3">
    <div class="card-header"><h5 class="card-title mb-0">Available Stock in This Store</h5></div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead>
                    <tr>
                        <th>Product</th><th>Code / SKU</th><th class="text-right">Qty</th><th class="text-right">Reorder</th><th class="text-right">Purchase Price</th><th class="text-right">Sale Price</th><th>Min Level</th><th>Last Counted</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($stock_rows as $r): ?>
                        <tr>
                            <td class="font-weight-semibold"><?php echo e($r['product_name']); ?></td>
                            <td><small class="text-muted"><?php echo e($r['product_code']); ?><?php echo $r['sku'] ? ' / ' . e($r['sku']) : ''; ?></small></td>
                            <td class="text-right"><?php echo e(format_quantity($r['quantity'])); ?></td>
                            <td class="text-right"><?php echo e(format_quantity($r['reorder_level'])); ?></td>
                            <td class="text-right"><?php echo e(money($r['purchase_price'], $cur)); ?></td>
                            <td class="text-right"><?php echo e(money($r['sale_price'], $cur)); ?></td>
                            <td><?php echo e(format_quantity($r['min_stock_level'])); ?></td>
                            <td><?php echo $r['last_counted_at'] ? e(date('M d, Y H:i', strtotime($r['last_counted_at']))) : '—'; ?></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$stock_rows): ?>
                        <tr><td colspan="8" class="text-center text-muted py-4">No stock recorded for this store.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<div class="card mb-3">
    <div class="card-header"><h5 class="card-title mb-0">Product Movement History</h5></div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead>
                    <tr><th>Date</th><th>Product</th><th>Movement Type</th><th class="text-right">Qty</th><th>Notes</th></tr>
                </thead>
                <tbody>
                    <?php foreach ($movement_rows as $m): ?>
                        <tr>
                            <td><?php echo e(date('M d, Y H:i', strtotime($m['created_at']))); ?></td>
                            <td><?php echo e($m['product_name']); ?> <small class="text-muted">(<?php echo e($m['product_code']); ?>)</small></td>
                            <td><?php echo e(ucwords(str_replace('_', ' ', $m['movement_type']))); ?></td>
                            <td class="text-right"><?php echo e(format_quantity($m['quantity'])); ?></td>
                            <td><?php echo e($m['notes'] ?: '—'); ?></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$movement_rows): ?>
                        <tr><td colspan="5" class="text-center text-muted py-4">No movements recorded for this store.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php if ($request_stock_enabled): ?>
    <?php require __DIR__ . '/partials/request-stock-modal.php'; ?>
<?php endif; ?>

<?php require_once 'footer.php'; ?>