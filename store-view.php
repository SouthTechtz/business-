<?php
require_once 'header.php';
require_once 'auth_check.php';
require_permission_any(['manage_shops', 'manage_inventory', 'manage_pos', 'manage_orders', 'manage_products']);
$page_title = 'Store Dashboard';

$tenant_id = (int)$current_user->tenant_id;
$accessible_stores = accessible_stores(null, true);
$store_ids = array_map(fn($s) => (int)$s["warehouse_id"], $accessible_stores);
$requested_store_id = (int)($_GET["id"] ?? 0);
if ($requested_store_id > 0 && !in_array($requested_store_id, $store_ids, true)) {
    $requested_store_id = 0;
}
if ($requested_store_id <= 0) {
    $requested_store_id = $store_ids[0] ?? 0;
}
$warehouse_id = $requested_store_id;
$store = assert_store_access($warehouse_id, true);
if (($store['location_type'] ?? '') !== 'store') {
    flash_set('danger', 'This page is for stores only.');
    redirect('stores.php');
}

// Fetch all available products across tenant for the modal selection
$all_products = fetch_all(
    "SELECT product_id, name, code, sku FROM products WHERE tenant_id = ? ORDER BY name",
    $tenant_id
);

// Fetch stock availability restricting store source to ONLY Godown or Other Stores with qty > 0
$available_store_sources = fetch_all(
    "SELECT s.product_id, s.warehouse_id, s.quantity, w.name AS warehouse_name, w.location_type
       FROM inventory_stock s
       JOIN warehouses w ON w.warehouse_id = s.warehouse_id AND w.tenant_id = s.tenant_id
      WHERE s.tenant_id = ? AND s.quantity > 0 AND w.location_type IN ('godown', 'store') AND w.warehouse_id != ?",
    $tenant_id,
    $warehouse_id
);

// Summary metrics
$row = fetch_one(
    "SELECT COUNT(DISTINCT s.product_id) AS products,
            COALESCE(SUM(s.quantity), 0) AS qty,
            COALESCE(SUM(s.quantity * p.purchase_price), 0) AS capital
       FROM inventory_stock s
       JOIN products p ON p.product_id = s.product_id AND p.tenant_id = s.tenant_id
      WHERE s.tenant_id = ? AND s.warehouse_id = ?",
    $tenant_id,
    $warehouse_id
);
$total_products = (int)($row['products'] ?? 0);
$total_qty = (float)($row['qty'] ?? 0);
$capital = (float)($row['capital'] ?? 0);
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
    <button type="button" class="btn btn-primary" data-toggle="modal" data-target="#requestStoreStockModal">
        <i class="fa fa-plus mr-1"></i> Request Stock Transfer
    </button>
</div>

<?php if (tenant_admin_scope() && count($accessible_stores) > 1): ?>
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
    <div class="col-md-4 col-sm-6 mb-2">
        <div class="card"><div class="card-body">
            <div class="text-muted small">Capital Valuation</div>
            <div class="h4 mb-0"><?php echo e(money($capital, $cur)); ?></div>
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

<!-- Request Stock Popup Modal -->
<div class="modal fade" id="requestStoreStockModal" tabindex="-1" role="dialog" aria-labelledby="requestStoreStockModalLabel" aria-hidden="true">
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            <form action="stock-transfer-process.php" method="POST">
                <div class="modal-header">
                    <h5 class="modal-title" id="requestStoreStockModalLabel">Request Stock Transfer (Store)</h5>
                    <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                        <span aria-hidden="true">&times;</span>
                    </button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="destination_warehouse_id" value="<?php echo (int)$warehouse_id; ?>">
                    
                    <div class="form-group">
                        <label>Select Product <span class="text-danger">*</span></label>
                        <select name="product_id" id="store_product_select" class="form-control" required onchange="filterStoreSources()">
                            <option value="">-- Choose Product --</option>
                            <?php foreach ($all_products as $p): ?>
                                <option value="<?php echo (int)$p['product_id']; ?>">
                                    <?php echo e($p['name']); ?> (<?php echo e($p['code']); ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label>Source Location (Godown or Other Store with available stock) <span class="text-danger">*</span></label>
                        <select name="source_warehouse_id" id="store_source_select" class="form-control" required>
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
const storeStockData = <?php echo json_encode($available_store_sources); ?>;

function filterStoreSources() {
    const selectedProductId = parseInt(document.getElementById('store_product_select').value);
    const sourceSelect = document.getElementById('store_source_select');
    sourceSelect.innerHTML = '<option value="">-- Choose Source Location --</option>';

    if (!selectedProductId) return;

    // Logic: Store can request ONLY from Godowns or other Stores with qty > 0
    const matchingSources = storeStockData.filter(item => parseInt(item.product_id) === selectedProductId && parseFloat(item.quantity) > 0);

    if (matchingSources.length === 0) {
        sourceSelect.innerHTML = '<option value="">No godown or store currently has stock for this product</option>';
        return;
    }

    matchingSources.forEach(source => {
        const opt = document.createElement('option');
        opt.value = source.warehouse_id;
        opt.textContent = `[${source.location_type.toUpperCase()}] ${source.warehouse_name} — Available: ${source.quantity}`;
        sourceSelect.appendChild(opt);
    });
}
</script>

<?php require_once 'footer.php'; ?>