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

// Default destination warehouse for this shop
$default_dest_warehouse_id = $warehouse_ids[0] ?? 0;

// Fetch all available products across tenant for the modal selection
$all_products = fetch_all(
    "SELECT product_id, name, code, sku FROM products WHERE tenant_id = ? ORDER BY name",
    $tenant_id
);

// Fetch stock availability across Godowns, Stores, and Shops for modal filtering
$available_sources_stock = fetch_all(
    "SELECT s.product_id, s.warehouse_id, s.quantity, w.name AS warehouse_name, w.location_type, sh.name AS shop_name
       FROM inventory_stock s
       JOIN warehouses w ON w.warehouse_id = s.warehouse_id AND w.tenant_id = s.tenant_id
  LEFT JOIN shops sh ON sh.shop_id = w.shop_id AND sh.tenant_id = w.tenant_id
      WHERE s.tenant_id = ? AND s.quantity > 0",
    $tenant_id
);

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
    <button type="button" class="btn btn-primary" data-toggle="modal" data-target="#requestStockModal">
        <i class="fa fa-plus mr-1"></i> Request Stock
    </button>
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

<!-- Request Stock Popup Modal -->
<div class="modal fade" id="requestStockModal" tabindex="-1" role="dialog" aria-labelledby="requestStockModalLabel" aria-hidden="true">
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            <form action="stock-transfer-process.php" method="POST">
                <div class="modal-header">
                    <h5 class="modal-title" id="requestStockModalLabel">Request Stock Transfer (Shop)</h5>
                    <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                        <span aria-hidden="true">&times;</span>
                    </button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="destination_warehouse_id" value="<?php echo $default_dest_warehouse_id; ?>">
                    
                    <div class="form-group">
                        <label>Select Product <span class="text-danger">*</span></label>
                        <select name="product_id" id="shop_product_select" class="form-control" required onchange="filterShopSources()">
                            <option value="">-- Choose Product --</option>
                            <?php foreach ($all_products as $p): ?>
                                <option value="<?php echo (int)$p['product_id']; ?>">
                                    <?php echo e($p['name']); ?> (<?php echo e($p['code']); ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label>Source Location (Godown, Store, or Shop with available stock) <span class="text-danger">*</span></label>
                        <select name="source_warehouse_id" id="shop_source_select" class="form-control" required>
                            <option value="">-- Select Product First --</option>
                        </select>
                    </div>

                    <div class="form-group">
                        <label>Quantity <span class="text-danger">*</span></label>
                        <input type="number" step="0.01" min="0.01" name="quantity" class="form-control" placeholder="Enter quantity" required>
                    </div>

                    <div class="form-group">
                        <label>Notes / Reason</label>
                        <textarea name="notes" class="form-control" rows="2" placeholder="Optional notes..."></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Submit Request</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
const shopStockData = <?php echo json_encode($available_sources_stock); ?>;

function filterShopSources() {
    const selectedProductId = parseInt(document.getElementById('shop_product_select').value);
    const sourceSelect = document.getElementById('shop_source_select');
    sourceSelect.innerHTML = '<option value="">-- Choose Source Location --</option>';

    if (!selectedProductId) return;

    // Logic: Shop can request from Godowns, Stores, or other Shops as long as qty > 0
    const matchingSources = shopStockData.filter(item => parseInt(item.product_id) === selectedProductId && parseFloat(item.quantity) > 0);

    if (matchingSources.length === 0) {
        sourceSelect.innerHTML = '<option value="">No locations currently have stock for this product</option>';
        return;
    }

    matchingSources.forEach(source => {
        let labelType = source.location_type.toUpperCase();
        if (source.shop_name) {
            labelType += ` (${source.shop_name})`;
        }
        const opt = document.createElement('option');
        opt.value = source.warehouse_id;
        opt.textContent = `[${labelType}] ${source.warehouse_name} — Available: ${source.quantity}`;
        sourceSelect.appendChild(opt);
    });
}
</script>

<?php require_once 'footer.php'; ?>