<?php
require_once 'header.php';
require_once 'auth_check.php';
require_permission('manage_inventory');
$page_title = 'Adjust / Restock';

$msg = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $product_id = (int)($_POST['product_id'] ?? 0);
    $warehouse_id = (int)($_POST['warehouse_id'] ?? 0);
    $quantity = (float)($_POST['quantity'] ?? 0);
    $prod_date = ($_POST['production_date'] ?? '') ?: null;
    $exp_date = ($_POST['expires_at'] ?? '') ?: null;
    $notes = trim($_POST['notes'] ?? '');

    $product = fetch_one(
        "SELECT p.*, u.is_decimal FROM products p
         LEFT JOIN units_of_measurement u ON u.unit_id = p.unit_id
         WHERE p.product_id = ? AND p.tenant_id = ?",
        $product_id, $current_user->tenant_id
    );
    if (!$product) {
        $msg = ['danger', 'Product not found.'];
    } elseif (!store_is_accessible($warehouse_id, false)) {
        $msg = ['danger', 'Please select an active store you can access.'];
    } elseif ($product['is_service']) {
        $msg = ['danger', 'Services cannot have stock.'];
    } elseif (!$product['is_decimal'] && $quantity != floor($quantity)) {
        $msg = ['danger', 'This product uses whole units only.'];
    } elseif ($quantity <= 0) {
        $msg = ['danger', 'Quantity must be greater than zero.'];
    } else {
        // Upsert stock row
        $stock = fetch_one(
            "SELECT * FROM inventory_stock WHERE product_id = ? AND warehouse_id = ? AND tenant_id = ?",
            $product_id, $warehouse_id, $current_user->tenant_id
        );
        $conn->begin_transaction();
        try {
            $type = 'restock';
            if ($stock) {
                $old = (float)$stock['quantity'];
                $new = $old + $quantity;
                $stmt = $conn->prepare(
                    "UPDATE inventory_stock SET quantity = ?, production_date = ?, expires_at = ?, last_counted_at = CURRENT_TIMESTAMP
                     WHERE stock_id = ? AND tenant_id = ?"
                );
                $stmt->bind_param('dssii', $new, $prod_date, $exp_date, $stock['stock_id'], $current_user->tenant_id);
                $stmt->execute();
            } else {
                $stmt = $conn->prepare(
                    "INSERT INTO inventory_stock (tenant_id, product_id, warehouse_id, quantity, reorder_level, production_date, expires_at)
                     VALUES (?, ?, ?, ?, ?, ?, ?)"
                );
                $stmt->bind_param('iiiddss', $current_user->tenant_id, $product_id, $warehouse_id, $quantity, $product['min_stock_level'], $prod_date, $exp_date);
                $stmt->execute();
            }
            $stmt = $conn->prepare(
                "INSERT INTO inventory_movements (tenant_id, product_id, warehouse_id, movement_type, quantity, created_by, notes, production_date, expires_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)"
            );
            $stmt->bind_param('iiisdisss', $current_user->tenant_id, $product_id, $warehouse_id, $type, $quantity, $current_user->user_id, $notes, $prod_date, $exp_date);
            $stmt->execute();
            $conn->commit();
            $msg = ['success', 'Stock updated successfully.'];
        } catch (Exception $ex) {
            $conn->rollback();
            $msg = ['danger', 'Update failed: ' . $ex->getMessage()];
        }
    }
}

$products = fetch_all(
    "SELECT p.product_id, p.code, p.name, p.is_service
     FROM products p
     WHERE p.tenant_id = ? AND p.is_active = 1
     ORDER BY p.name",
    $current_user->tenant_id
);
$warehouses = accessible_stores(null, false);

require_once 'navbar.php';
require_once 'sidebar.php';
?>

<div class="page-head">
    <div>
        <h1 class="page-title">Adjust / Restock</h1>
        <div class="page-sub">Add stock to a product in a warehouse.</div>
    </div>
    <a href="inventory.php" class="btn btn-light"><i class="fa fa-arrow-left mr-1"></i> Back to Inventory</a>
</div>

<?php if ($msg): ?>
    <div class="alert alert-<?php echo e($msg[0]); ?>"><?php echo e($msg[1]); ?></div>
<?php endif; ?>
<?php if (!$warehouses): ?>
    <div class="alert alert-warning">No active store is assigned to your account. Ask an administrator to grant shop/store access.</div>
<?php endif; ?>

<div class="row">
    <div class="col-lg-6">
        <div class="card">
            <div class="card-body">
                <form method="POST" action="inventory-adjust.php">
                    <div class="form-group">
                        <label>Product <span class="text-danger">*</span></label>
                        <select name="product_id" class="form-control" required id="adj_product">
                            <option value="">— Select Product —</option>
                            <?php foreach ($products as $p): ?>
                                <option value="<?php echo $p['product_id']; ?>" data-service="<?php echo $p['is_service']; ?>">
                                    <?php echo e($p['name'] . ' (' . $p['code'] . ')'); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Store <span class="text-danger">*</span></label>
                        <select name="warehouse_id" class="form-control" required>
                            <?php foreach ($warehouses as $w): ?>
                                <option value="<?php echo $w['warehouse_id']; ?>"><?php echo e(store_label($w)); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Quantity to Add <span class="text-danger">*</span></label>
                        <input type="number" name="quantity" class="form-control" step="1" min="1" required>
                    </div>
                    <div class="row">
                        <div class="col-md-6 form-group"><label>Production Date</label><input type="date" name="production_date" class="form-control"></div>
                        <div class="col-md-6 form-group"><label>Expiry Date</label><input type="date" name="expires_at" class="form-control"></div>
                    </div>
                    <div class="form-group">
                        <label>Notes</label>
                        <textarea name="notes" class="form-control" rows="2" placeholder="e.g. Supplier delivery"></textarea>
                    </div>
                    <button type="submit" class="btn btn-primary"><i class="fa fa-check mr-1"></i> Save Stock</button>
                </form>
            </div>
        </div>
    </div>
</div>

<?php require_once 'footer.php'; ?>
