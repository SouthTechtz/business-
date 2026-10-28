<?php
require_once 'header.php';
require_once 'auth_check.php';
require_permission('manage_inventory');
$page_title = 'Inventory';
$can_godown = can('manage_godown');

// Inline stock adjustment (adds to existing stock)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'adjust') {
    $stock_id = (int)($_POST['stock_id'] ?? 0);
    $add_qty = (float)($_POST['quantity'] ?? 0);
    $prod_date = ($_POST['production_date'] ?? '') ?: null;
    $exp_date = ($_POST['expires_at'] ?? '') ?: null;

    $stock = fetch_one(
        "SELECT s.*, p.is_service, u.is_decimal, w.is_godown FROM inventory_stock s
         JOIN products p ON p.product_id = s.product_id
         LEFT JOIN units_of_measurement u ON u.unit_id = p.unit_id
         JOIN warehouses w ON w.warehouse_id = s.warehouse_id AND w.tenant_id = s.tenant_id
         WHERE s.stock_id = ? AND s.tenant_id = ?",
        $stock_id, $current_user->tenant_id
    );
    if (!$stock) {
        flash_set('danger', 'Stock record not found.');
        redirect('inventory.php');
    }
    if (!store_is_accessible((int)$stock['warehouse_id'], true, true)) {
        flash_set('danger', 'You do not have access to the store for this stock record.');
        redirect('inventory.php');
    }
    if ((int)($stock['is_godown'] ?? 0) === 1) {
        flash_set('danger', 'Central Godown stock is received through the Central Godown page so the source reference and receipt are recorded.');
        redirect('godown.php');
    }
    if ($stock['is_service']) {
        flash_set('danger', 'Services do not have stock.');
        redirect('inventory.php');
    }
    if (!$stock['is_decimal'] && $add_qty != floor($add_qty)) {
        flash_set('danger', 'This product uses whole units only.');
        redirect('inventory.php');
    }
    if ($add_qty <= 0) {
        flash_set('danger', 'Quantity to add must be greater than zero.');
        redirect('inventory.php');
    }

    $old_qty = (float)$stock['quantity'];
    $new_qty = $old_qty + $add_qty;
    $conn->begin_transaction();
    try {
        $stmt = $conn->prepare(
            "UPDATE inventory_stock SET quantity = quantity + ?, production_date = ?, expires_at = ?, last_counted_at = CURRENT_TIMESTAMP
             WHERE stock_id = ? AND tenant_id = ?"
        );
        $stmt->bind_param('dssii', $add_qty, $prod_date, $exp_date, $stock_id, $current_user->tenant_id);
        $stmt->execute();
        $stmt = $conn->prepare(
            "INSERT INTO inventory_movements (tenant_id, product_id, warehouse_id, movement_type, quantity, created_by, notes, production_date, expires_at)
             VALUES (?, ?, ?, 'restock', ?, ?, ?, ?, ?)"
        );
        $notes = 'Restocked from ' . $old_qty . ' to ' . $new_qty;
        $stmt->bind_param('iiidisss', $current_user->tenant_id, $stock['product_id'], $stock['warehouse_id'], $add_qty, $current_user->user_id, $notes, $prod_date, $exp_date);
        $stmt->execute();
        $conn->commit();
        flash_set('success', 'Stock updated — added ' . rtrim(rtrim(number_format($add_qty, 2, '.', ''), '0'), '.') . ' (now ' . rtrim(rtrim(number_format($new_qty, 2, '.', ''), '0'), '.') . ').');
    } catch (Exception $ex) {
        $conn->rollback();
        flash_set('danger', 'Update failed: ' . $ex->getMessage());
    }
    redirect('inventory.php');
}

$shops = accessible_shops(null, true);
$accessible_shop_ids = array_map(fn($s) => (int)$s['shop_id'], $shops);
$shop_id = isset($_GET['shop']) && is_numeric($_GET['shop']) ? (int)$_GET['shop'] : 0;
if ($shop_id > 0 && !in_array($shop_id, $accessible_shop_ids, true)) {
    $shop_id = 0;
}

$warehouses = accessible_stores(null, true, null, $can_godown);
$accessible_store_ids = array_map(fn($w) => (int)$w['warehouse_id'], $warehouses);
$default_wh_id = 0;
foreach ($warehouses as $warehouse) {
    if ((int)($warehouse['is_godown'] ?? 0) === 0) {
        $default_wh_id = (int)$warehouse['warehouse_id'];
        break;
    }
}
$wh_id = isset($_GET['warehouse']) && is_numeric($_GET['warehouse']) ? (int)$_GET['warehouse'] : $default_wh_id;
if ($wh_id > 0 && !in_array($wh_id, $accessible_store_ids, true)) {
    $wh_id = 0;
}
if ($shop_id > 0 && $wh_id > 0) {
    $selected_wh = fetch_one("SELECT shop_id FROM warehouses WHERE warehouse_id = ? AND tenant_id = ?", $wh_id, $current_user->tenant_id);
    if (!$selected_wh || (int)$selected_wh['shop_id'] !== $shop_id) {
        $wh_id = 0;
    }
}

$stock_sql = "SELECT s.*, p.name product_name, p.code product_code, p.min_stock_level, p.is_service,
            u.is_decimal, u.short_name unit_short, w.name warehouse_name, w.is_godown,
            sh.name shop_name
     FROM inventory_stock s
     JOIN products p ON p.product_id = s.product_id
     LEFT JOIN units_of_measurement u ON u.unit_id = p.unit_id
     JOIN warehouses w ON w.warehouse_id = s.warehouse_id
     LEFT JOIN shops sh ON sh.shop_id = w.shop_id
     WHERE s.tenant_id = ? AND (? = 0 OR s.warehouse_id = ?) AND (? = 0 OR w.shop_id = ?)";
$stock_params = [$current_user->tenant_id, $wh_id, $wh_id, $shop_id, $shop_id];
if (!$can_godown) {
    $stock_sql .= " AND w.is_godown = 0";
}
$scope_ids = accessible_warehouse_ids(null, true, $can_godown);
if ($scope_ids !== null) {
    if (!$scope_ids) {
        $stock_sql .= " AND 1 = 0";
    } else {
        $stock_sql .= " AND s.warehouse_id IN (" . implode(',', array_map('intval', $scope_ids)) . ")";
    }
}
$stock_sql .= " ORDER BY w.is_godown DESC, p.name";
$stock_rows = fetch_all($stock_sql, ...$stock_params);

require_once 'navbar.php';
require_once 'sidebar.php';
?>

<div class="page-head">
    <div>
        <h1 class="page-title">Inventory</h1>
        <div class="page-sub">Stock levels by store. Central Godown stock is shown separately and is never treated as shop POS availability.</div>
    </div>
    <?php if ($can_godown): ?>
        <a href="godown.php" class="btn btn-outline-primary"><i class="fa fa-archive mr-1"></i> Central Godown</a>
    <?php endif; ?>
    <a href="inventory-adjust.php" class="btn btn-primary"><i class="fa fa-plus mr-1"></i> Adjust / Restock</a>
</div>

<?php if ($f = flash_get()): ?>
    <div class="alert alert-<?php echo e($f['type']); ?>"><?php echo e($f['message']); ?></div>
<?php endif; ?>

<div class="card">
    <div class="card-header d-flex justify-content-between align-items-center flex-wrap">
        <h5 class="card-title">Stock Levels</h5>
        <div class="d-flex align-items-center">
            <form method="GET" action="inventory.php" class="form-inline">
                <select name="shop" class="form-control form-control-sm mr-2" onchange="this.form.warehouse.value='0'; this.form.submit()">
                    <option value="0">All Shops</option>
                    <?php foreach ($shops as $s): ?>
                        <option value="<?php echo (int)$s['shop_id']; ?>" <?php echo $shop_id === (int)$s['shop_id'] ? 'selected' : ''; ?>><?php echo e($s['name']); ?><?php echo (int)$s['is_active'] ? '' : ' (inactive)'; ?></option>
                    <?php endforeach; ?>
                </select>
                <select name="warehouse" class="form-control form-control-sm" onchange="this.form.submit()">
                    <option value="0">All Stores</option>
                    <?php foreach ($warehouses as $w): ?>
                        <?php if ($shop_id > 0 && (int)$w['shop_id'] !== $shop_id) continue; ?>
                        <option value="<?php echo (int)$w['warehouse_id']; ?>" <?php echo $wh_id == $w['warehouse_id'] ? 'selected' : ''; ?>><?php echo e(store_label($w)); ?><?php echo (int)($w['is_godown'] ?? 0) ? ' — Central Godown' : ''; ?><?php echo (int)$w['is_active'] ? '' : ' (inactive)'; ?></option>
                    <?php endforeach; ?>
                </select>
            </form>
            <div class="input-group dt-search ml-2">
                <div class="input-group-prepend"><span class="input-group-text"><i class="fa fa-search"></i></span></div>
                <input type="text" class="form-control dt-search-input" data-scope="products" placeholder="Search stock...">
            </div>
        </div>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover data-table">
                <thead>
                    <tr>
                        <th>Product</th><th>Code</th><th>Store</th><th>Quantity</th><th>Unit</th><th>Min Level</th><th>Status</th><th class="text-right">Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($stock_rows as $row): ?>
                    <tr>
                        <td class="font-weight-semibold"><?php echo e($row['product_name']); ?></td>
                        <td><span class="badge badge-secondary"><?php echo e($row['product_code']); ?></span></td>
                        <td>
                            <?php echo e(trim(($row['shop_name'] ?? '') . ' / ' . $row['warehouse_name'], ' /')); ?>
                            <?php if ((int)($row['is_godown'] ?? 0) === 1): ?>
                                <span class="badge badge-warning ml-1">Central Godown</span>
                            <?php endif; ?>
                        </td>
                        <td class="font-weight-semibold"><?php echo e(format_quantity($row['quantity'])); ?></td>
                        <td><?php echo e($row['unit_short'] ?: ''); ?></td>
                        <td><?php echo e(format_quantity($row['min_stock_level'])); ?></td>
                        <td>
                            <?php if ((float)$row['quantity'] <= (float)$row['min_stock_level']): ?>
                                <span class="badge badge-danger">Low</span>
                            <?php else: ?>
                                <span class="badge badge-success">OK</span>
                            <?php endif; ?>
                        </td>
                        <td class="text-right">
                            <?php if ((int)($row['is_godown'] ?? 0) === 1): ?>
                                <a href="godown.php" class="btn btn-action btn-light" title="Receive or transfer central stock"><i class="fa fa-archive"></i></a>
                            <?php else: ?>
                                <button class="btn btn-action btn-light" data-toggle="modal" data-target="#adjustModal<?php echo $row['stock_id']; ?>"><i class="fa fa-edit"></i> Adjust</button>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$stock_rows): ?>
                    <tr><td colspan="8" class="text-center text-muted py-4">No stock records found.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php foreach ($stock_rows as $row): ?>
<?php if ((int)($row['is_godown'] ?? 0) === 1) continue; ?>
<div class="modal fade" id="adjustModal<?php echo $row['stock_id']; ?>" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Adjust Stock — <?php echo e($row['product_name']); ?></h5>
                <button type="button" class="close" data-dismiss="modal">&times;</button>
            </div>
            <form method="POST" action="inventory.php">
                <div class="modal-body">
                    <input type="hidden" name="action" value="adjust">
                    <input type="hidden" name="stock_id" value="<?php echo $row['stock_id']; ?>">
                    <div class="form-group">
                        <label>Quantity to Add (current: <?php echo e((float)$row['quantity']); ?>)</label>
                        <input type="number" name="quantity" class="form-control" step="1" min="1" required value="1">
                        <small class="text-muted">This amount is added to the existing stock.</small>
                    </div>
                    <div class="form-group"><label>Production Date</label><input type="date" name="production_date" class="form-control" value="<?php echo e($row['production_date']); ?>"></div>
                    <div class="form-group"><label>Expiry Date</label><input type="date" name="expires_at" class="form-control" value="<?php echo e($row['expires_at']); ?>"></div>
                    <small class="text-muted">The difference is logged as an inventory movement.</small>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-light" data-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Save</button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endforeach; ?>

<?php require_once 'footer.php'; ?>
