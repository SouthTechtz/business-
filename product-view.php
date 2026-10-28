<?php
require_once 'header.php';
require_once 'auth_check.php';
$page_title = 'Product Details';

$id = (int)($_GET['id'] ?? 0);
$product = fetch_one(
    "SELECT p.*, c.name category_name, u.name unit_name
     FROM products p
     LEFT JOIN product_categories c ON c.category_id = p.category_id
     LEFT JOIN units_of_measurement u ON u.unit_id = p.unit_id
     WHERE p.product_id = ? AND p.tenant_id = ?",
    $id, $current_user->tenant_id
);
if (!$product) {
    flash_set('danger', 'Product not found.');
    redirect('products.php');
}

$can_godown = can('manage_godown');
$scope_ids = accessible_warehouse_ids(null, true, $can_godown);
$stock_sql = "SELECT s.*, w.name warehouse_name, w.is_godown, sh.name shop_name FROM inventory_stock s
     JOIN warehouses w ON w.warehouse_id = s.warehouse_id
     LEFT JOIN shops sh ON sh.shop_id = w.shop_id AND sh.tenant_id = w.tenant_id
     WHERE s.product_id = ? AND s.tenant_id = ?";
$stock_params = [$id, $current_user->tenant_id];
if ($scope_ids !== null) {
    if (!$scope_ids) {
        $stock_sql .= " AND 1 = 0";
    } else {
        $stock_sql .= " AND s.warehouse_id IN (" . implode(',', array_map('intval', $scope_ids)) . ")";
    }
}
$stock = fetch_all($stock_sql, ...$stock_params);
$total_stock = array_sum(array_map(fn($s) => (float)$s['quantity'], $stock));

$movement_sql = "SELECT * FROM inventory_movements WHERE product_id = ? AND tenant_id = ?";
$movement_params = [$id, $current_user->tenant_id];
if ($scope_ids !== null) {
    if (!$scope_ids) {
        $movement_sql .= " AND 1 = 0";
    } else {
        $movement_sql .= " AND warehouse_id IN (" . implode(',', array_map('intval', $scope_ids)) . ")";
    }
}
$movement_sql .= " ORDER BY created_at DESC LIMIT 15";
$movements = fetch_all($movement_sql, ...$movement_params);

require_once 'navbar.php';
require_once 'sidebar.php';
$cur = tenant_currency();
$chain = category_chain($product['category_id']);
?>

<div class="page-head">
    <div>
        <h1 class="page-title">Product Details</h1>
        <div class="page-sub"><?php echo e($product['name']); ?></div>
    </div>
    <div>
        <?php if (can('manage_products')): ?>
            <a href="product-edit.php?id=<?php echo $id; ?>" class="btn btn-outline-primary"><i class="fa fa-pencil mr-1"></i> Edit</a>
        <?php endif; ?>
        <a href="products.php" class="btn btn-light"><i class="fa fa-arrow-left mr-1"></i> Back</a>
    </div>
</div>

<?php if ($f = flash_get()): ?>
    <div class="alert alert-<?php echo e($f['type']); ?>"><?php echo e($f['message']); ?></div>
<?php endif; ?>

<div class="row mb-3">
    <div class="col-md-6 col-xl-3 mb-3"><div class="widget"><div class="widget-icon wi-blue"><i class="fa fa-barcode"></i></div><div class="widget-info"><div class="widget-value" style="font-size:16px;"><?php echo e($product['code']); ?></div><div class="widget-label">Product Code</div></div></div></div>
    <div class="col-md-6 col-xl-3 mb-3"><div class="widget"><div class="widget-icon wi-green"><i class="fa fa-cubes"></i></div><div class="widget-info"><div class="widget-value"><?php echo e(format_quantity($total_stock)); ?></div><div class="widget-label">Total Stock (all accessible stores)</div></div></div></div>
    <div class="col-md-6 col-xl-3 mb-3"><div class="widget"><div class="widget-icon wi-purple"><i class="fa fa-tag"></i></div><div class="widget-info"><div class="widget-value" style="font-size:16px;"><?php echo e(money($product['sale_price'], $cur)); ?></div><div class="widget-label">Sale Price</div></div></div></div>
    <div class="col-md-6 col-xl-3 mb-3"><div class="widget"><div class="widget-icon wi-amber"><i class="fa fa-money"></i></div><div class="widget-info"><div class="widget-value" style="font-size:16px;"><?php echo e(money($product['purchase_price'], $cur)); ?></div><div class="widget-label">Purchase Price</div></div></div></div>
</div>

<div class="row">
    <div class="col-lg-5 mb-3">
        <div class="card">
            <div class="card-header"><h5 class="card-title">Product Information</h5></div>
            <div class="card-body">
                <table class="table">
                    <tbody>
                        <tr><th>Name</th><td><?php echo e($product['name']); ?></td></tr>
                        <tr><th>Main Category</th><td><?php echo e($chain[0] ?: '—'); ?></td></tr>
                        <tr><th>Subcategory</th><td><?php echo e($chain[1] ?: '—'); ?></td></tr>
                        <tr><th>Sub-Subcategory</th><td><?php echo e($chain[2] ?: '—'); ?></td></tr>
                        <tr><th>Color</th><td><?php echo e($product['variant_color'] ?: '—'); ?></td></tr>
                        <tr><th>Unit</th><td><?php echo e($product['unit_name'] ?: '—'); ?></td></tr>
                        <tr><th>Barcode</th><td><?php echo e($product['barcode'] ?: '—'); ?></td></tr>
                        <tr><th>SKU</th><td><?php echo e($product['sku'] ?: '—'); ?></td></tr>
                        <tr><th>Tax Rate</th><td><?php echo e((float)$product['tax_rate']); ?>%</td></tr>
                        <tr><th>Min Stock Level</th><td><?php echo e((float)$product['min_stock_level']); ?></td></tr>
                        <tr><th>Manufactured</th><td><?php echo $product['manufactured_date'] ? e(date('M d, Y', strtotime($product['manufactured_date']))) : '—'; ?></td></tr>
                        <tr><th>Expires</th><td><?php echo $product['expires_date'] ? e(date('M d, Y', strtotime($product['expires_date']))) : '—'; ?></td></tr>
                        <tr><th>Pack Size</th><td><?php echo e($product['variant_size'] ?: '—'); ?></td></tr>
                        <tr><th>Type</th><td><?php echo $product['is_service'] ? '<span class="badge badge-purple">Service</span>' : '<span class="badge badge-primary">Product</span>'; ?></td></tr>
                        <tr><th>Status</th><td><?php echo $product['is_active'] ? '<span class="badge badge-success">Active</span>' : '<span class="badge badge-secondary">Inactive</span>'; ?></td></tr>
                        <tr><th>Description</th><td><?php echo e($product['description'] ?: '—'); ?></td></tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <div class="col-lg-7 mb-3">
        <div class="card">
            <div class="card-header"><h5 class="card-title">Stock by Warehouse</h5></div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table mb-0">
                        <thead><tr><th>Warehouse</th><th>Quantity</th><th>Reorder Level</th><th>Last Counted</th></tr></thead>
                        <tbody>
                        <?php if (!$stock): ?>
                            <tr><td colspan="4" class="text-center text-muted py-4">No stock recorded.</td></tr>
                        <?php else: foreach ($stock as $s): ?>
                            <tr>
                                <td>
                                    <?php echo e(trim(($s['shop_name'] ?? '') . ' / ' . $s['warehouse_name'], ' /')); ?>
                                    <?php if ((int)($s['is_godown'] ?? 0) === 1): ?>
                                        <span class="badge badge-warning ml-1">Central Godown</span>
                                    <?php endif; ?>
                                </td>
                                <td><span class="badge badge-<?php echo (float)$s['quantity'] <= (float)$product['min_stock_level'] ? 'danger' : 'success'; ?>"><?php echo e(format_quantity($s['quantity'])); ?></span></td>
                                <td><?php echo e(format_quantity($s['reorder_level'])); ?></td>
                                <td><?php echo $s['last_counted_at'] ? e(date('M d, Y', strtotime($s['last_counted_at']))) : 'Never'; ?></td>
                            </tr>
                        <?php endforeach; endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        <div class="card">
            <div class="card-header"><h5 class="card-title">Recent Stock Movements</h5></div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table mb-0">
                        <thead><tr><th>Date</th><th>Type</th><th>Quantity</th><th>Reference</th><th>Notes</th></tr></thead>
                        <tbody>
                        <?php if (!$movements): ?>
                            <tr><td colspan="5" class="text-center text-muted py-4">No movements yet.</td></tr>
                        <?php else: foreach ($movements as $m): ?>
                            <tr>
                                <td><?php echo e(date('M d, Y H:i', strtotime($m['created_at']))); ?></td>
                                <td><span class="badge badge-info"><?php echo e($m['movement_type']); ?></span></td>
                                <td class="font-weight-semibold"><?php echo e((float)$m['quantity']); ?></td>
                                <td><?php echo e($m['reference_type'] ? $m['reference_type'] . ' #' . $m['reference_id'] : '—'); ?></td>
                                <td><?php echo e($m['notes'] ?: '—'); ?></td>
                            </tr>
                        <?php endforeach; endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<?php require_once 'footer.php'; ?>
