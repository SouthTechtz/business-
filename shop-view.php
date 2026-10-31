<?php
require_once 'header.php';
require_once 'auth_check.php';
require_permission_any(['manage_shops', 'manage_inventory', 'manage_pos', 'manage_orders', 'manage_products']);
$page_title = 'Shop Dashboard';

$tenant_id = (int)$current_user->tenant_id;
$accessible_shops = accessible_shops(null, true);
$shop_ids = array_map(fn($s) => (int)$s["shop_id"], $accessible_shops);
$requested_shop_id = (int)($_GET['id'] ?? 0);
if ($requested_shop_id > 0 && !in_array($requested_shop_id, $shop_ids, true)) {
    $requested_shop_id = 0;
}
if ($requested_shop_id <= 0) {
    $requested_shop_id = $shop_ids[0] ?? 0;
}
$shop_id = $requested_shop_id;
$shop = assert_shop_access($shop_id, true);

$warehouses = fetch_all(
    "SELECT warehouse_id, name, code, is_active FROM warehouses
      WHERE tenant_id = ? AND shop_id = ? AND location_type = 'store'
      ORDER BY name",
    $tenant_id,
    $shop_id
);
$warehouse_ids = array_map(fn($w) => (int)$w['warehouse_id'], $warehouses);

$stock_rows = [];
$total_products = 0;
$total_qty = 0.0;
$capital = 0.0;
$low_stock = 0;
$out_of_stock = 0;
if ($warehouse_ids) {
    $ph = implode(",", array_fill(0, count($warehouse_ids), "?"));
    $params = array_merge([$tenant_id], $warehouse_ids);
    $stock_rows = fetch_all(
        "SELECT s.warehouse_id, w.name warehouse_name, p.product_id, p.name product_name, p.code product_code,
                p.sku, p.purchase_price, p.sale_price, p.min_stock_level, s.quantity, s.reorder_level, s.last_counted_at
           FROM inventory_stock s
           JOIN products p ON p.product_id = s.product_id AND p.tenant_id = s.tenant_id
           JOIN warehouses w ON w.warehouse_id = s.warehouse_id AND w.tenant_id = s.tenant_id
          WHERE s.tenant_id = ? AND s.warehouse_id IN ($ph)
          ORDER BY w.name, p.name",
        ...$params
    );

    $agg = [];
    foreach ($stock_rows as $r) {
        $pid = (int)$r["product_id"];
        if (!isset($agg[$pid])) {
            $agg[$pid] = ["qty" => 0.0, "min" => (float)$r["min_stock_level"]];
        }
        $agg[$pid]["qty"] += (float)$r["quantity"];
        $capital += (float)$r["quantity"] * (float)$r["purchase_price"];
    }
    $total_products = count($agg);
    foreach ($agg as $a) {
        $total_qty += $a["qty"];
        if ($a["qty"] <= 0) {
            $out_of_stock++;
        } elseif ($a["qty"] <= $a["min"]) {
            $low_stock++;
        }
    }
}

$active_transfers = 0;
if ($warehouse_ids) {
    $ph = implode(',', array_fill(0, count($warehouse_ids), '?'));
    $params = array_merge([$tenant_id], $warehouse_ids, $warehouse_ids);
    $row = fetch_one(
        "SELECT COUNT(*) AS c FROM stock_transfers
          WHERE tenant_id = ? AND status IN ('draft','approved','dispatched','discrepancy')
            AND (source_warehouse_id IN ($ph) OR destination_warehouse_id IN ($ph))",
        ...$params
    );
    $active_transfers = (int)($row['c'] ?? 0);
}

$stock_rows = [];
if ($warehouse_ids) {
    $ph = implode(',', array_fill(0, count($warehouse_ids), '?'));
    $params = array_merge([$tenant_id], $warehouse_ids);
    $stock_rows = fetch_all(
        "SELECT s.warehouse_id, w.name warehouse_name, p.product_id, p.name product_name, p.code product_code,
                p.sku, p.purchase_price, p.sale_price, p.min_stock_level, s.quantity, s.reorder_level, s.last_counted_at
           FROM inventory_stock s
           JOIN products p ON p.product_id = s.product_id AND p.tenant_id = s.tenant_id
           JOIN warehouses w ON w.warehouse_id = s.warehouse_id AND w.tenant_id = s.tenant_id
          WHERE s.tenant_id = ? AND s.warehouse_id IN ($ph)
          ORDER BY w.name, p.name",
        ...$params
    );
}

$movement_rows = [];
if ($warehouse_ids) {
    $ph = implode(',', array_fill(0, count($warehouse_ids), '?'));
    $params = array_merge([$tenant_id], $warehouse_ids);
    $movement_rows = fetch_all(
        "SELECT m.movement_id, m.created_at, m.movement_type, m.quantity, m.notes,
                p.name product_name, p.code product_code, w.name warehouse_name
           FROM inventory_movements m
           JOIN products p ON p.product_id = m.product_id AND p.tenant_id = m.tenant_id
           JOIN warehouses w ON w.warehouse_id = m.warehouse_id AND w.tenant_id = m.tenant_id
          WHERE m.tenant_id = ? AND m.warehouse_id IN ($ph)
          ORDER BY m.created_at DESC, m.movement_id DESC
          LIMIT 100",
        ...$params
    );
}

require_once 'navbar.php';
require_once 'sidebar.php';
$cur = tenant_currency();
?>
<div class="page-head">
    <div>
        <h1 class="page-title"><?php echo e($shop['name']); ?> — Shop Dashboard</h1>
        <div class="page-sub">Shop code: <?php echo e($shop['code']); ?> &middot; <?php echo count($warehouses); ?> store(s) &middot; <?php echo count($stock_rows); ?> stock row(s)</div>
    </div>
    <a href="godown-transfer-add.php" class="btn btn-primary"><i class="fa fa-plus mr-1"></i> Request Stock</a>
</div>

<?php if (tenant_admin_scope() && count($accessible_shops) > 1): ?>
<form method="GET" action="shop-view.php" class="form-inline mb-3">
    <label class="mr-2">Shop</label>
    <select name="id" class="form-control form-control-sm mr-2" onchange="this.form.submit()">
        <?php foreach ($accessible_shops as $sh): ?>
            <option value="<?php echo (int)$sh["shop_id"]; ?>" <?php echo (int)$sh["shop_id"] === (int)$shop_id ? "selected" : ""; ?>><?php echo e($sh["name"]); ?></option>
        <?php endforeach; ?>
    </select>
</form>
<?php endif; ?>

<div class="row mb-3">
    <div class="col-md-3 col-sm-6 mb-2">
        <div class="card"><div class="card-body">
            <div class="text-muted small">Total Products / SKUs</div>
            <div class="h4 mb-0"><?php echo $total_products; ?></div>
        </div></div>
    </div>
    <div class="col-md-3 col-sm-6 mb-2">
        <div class="card"><div class="card-body">
            <div class="text-muted small">Total Stock Quantity</div>
            <div class="h4 mb-0"><?php echo e(format_quantity($total_qty)); ?></div>
        </div></div>
    </div>
    <div class="col-md-3 col-sm-6 mb-2">
        <div class="card"><div class="card-body">
            <div class="text-muted small">Low Stock Items</div>
            <div class="h4 mb-0"><?php echo $low_stock; ?></div>
        </div></div>
    </div>
    <div class="col-md-3 col-sm-6 mb-2">
        <div class="card"><div class="card-body">
            <div class="text-muted small">Out of Stock Products</div>
            <div class="h4 mb-0"><?php echo $out_of_stock; ?></div>
        </div></div>
    </div>
    <div class="col-md-3 col-sm-6 mb-2">
        <div class="card"><div class="card-body">
            <div class="text-muted small">Capital Valuation</div>
            <div class="h4 mb-0"><?php echo e(money($capital, $cur)); ?></div>
        </div></div>
    </div>
    <div class="col-md-3 col-sm-6 mb-2">
        <div class="card"><div class="card-body">
            <div class="text-muted small">Active Transfers</div>
            <div class="h4 mb-0"><?php echo $active_transfers; ?></div>
        </div></div>
    </div>
</div>

<div class="card mb-3">
    <div class="card-header"><h5 class="card-title mb-0">Available Stock in This Shop's Stores</h5></div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead>
                    <tr>
                        <th>Store</th><th>Product</th><th>Code / SKU</th><th class="text-right">Qty</th><th class="text-right">Reorder</th><th class="text-right">Purchase Price</th><th class="text-right">Sale Price</th><th>Last Counted</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($stock_rows as $r): ?>
                        <tr>
                            <td><?php echo e($r['warehouse_name']); ?></td>
                            <td class="font-weight-semibold"><?php echo e($r['product_name']); ?></td>
                            <td><small class="text-muted"><?php echo e($r['product_code']); ?><?php echo $r['sku'] ? ' / ' . e($r['sku']) : ''; ?></small></td>
                            <td class="text-right"><?php echo e(format_quantity($r['quantity'])); ?></td>
                            <td class="text-right"><?php echo e(format_quantity($r['reorder_level'])); ?></td>
                            <td class="text-right"><?php echo e(money($r['purchase_price'], $cur)); ?></td>
                            <td class="text-right"><?php echo e(money($r['sale_price'], $cur)); ?></td>
                            <td><?php echo $r['last_counted_at'] ? e(date('M d, Y H:i', strtotime($r['last_counted_at']))) : '—'; ?></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$stock_rows): ?>
                        <tr><td colspan="8" class="text-center text-muted py-4">No stock recorded for this shop's stores.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<div class="card mb-3">
    <div class="card-header"><h5 class="card-title mb-0">Entity Movement &amp; Activity History</h5></div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead>
                    <tr><th>Date</th><th>Store</th><th>Product</th><th>Movement Type</th><th class="text-right">Qty</th><th>Notes</th></tr>
                </thead>
                <tbody>
                    <?php foreach ($movement_rows as $m): ?>
                        <tr>
                            <td><?php echo e(date('M d, Y H:i', strtotime($m['created_at']))); ?></td>
                            <td><?php echo e($m['warehouse_name']); ?></td>
                            <td><?php echo e($m['product_name']); ?> <small class="text-muted">(<?php echo e($m['product_code']); ?>)</small></td>
                            <td><?php echo e(ucwords(str_replace('_', ' ', $m['movement_type']))); ?></td>
                            <td class="text-right"><?php echo e(format_quantity($m['quantity'])); ?></td>
                            <td><?php echo e($m['notes'] ?: '—'); ?></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$movement_rows): ?>
                        <tr><td colspan="6" class="text-center text-muted py-4">No movements recorded for this shop.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<div class="card">
    <div class="card-body">
        <h5 class="card-title">Action Hub</h5>
        <p class="text-muted mb-3">Request stock directly from the Central Godown or peer locations for this shop's stores.</p>
        <a href="godown-transfer-add.php" class="btn btn-primary"><i class="fa fa-plus mr-1"></i> Request Stock Transfer</a>
        <a href="stores.php" class="btn btn-light ml-2"><i class="fa fa-building mr-1"></i> Manage Stores</a>
    </div>
</div>

<?php require_once 'footer.php'; ?>