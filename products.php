<?php
require_once 'header.php';
require_once 'auth_check.php';
require_permission('manage_products');
$page_title = 'Products';

$msg = '';

// Category CRUD
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($action === 'add_category') {
        $name = trim($_POST['name'] ?? '');
        if ($name !== '') {
            db_insert('product_categories', [
                'tenant_id'         => $current_user->tenant_id,
                'parent_category_id'=> !empty($_POST['parent']) ? (int)$_POST['parent'] : null,
                'name'              => $name,
                'description'       => trim($_POST['description'] ?? ''),
            ]);
            $msg = ['success', 'Category added.'];
        }
    } elseif ($action === 'delete_category') {
        $cid = (int)($_POST['category_id'] ?? 0);
        $used = fetch_one("SELECT COUNT(*) c FROM products WHERE category_id = ? AND tenant_id = ?", $cid, $current_user->tenant_id);
        if (($used['c'] ?? 0) > 0) {
            $msg = ['danger', 'This category has products and cannot be deleted.'];
        } else {
            $stmt = $conn->prepare("UPDATE product_categories SET parent_category_id = NULL WHERE parent_category_id = ? AND tenant_id = ?");
            $stmt->bind_param('ii', $cid, $current_user->tenant_id);
            $stmt->execute();
            $stmt = $conn->prepare("DELETE FROM product_categories WHERE category_id = ? AND tenant_id = ?");
            $stmt->bind_param('ii', $cid, $current_user->tenant_id);
            $stmt->execute();
            $msg = ['success', 'Category deleted.'];
        }
    }
}

// Delete product
if (isset($_GET['delete']) && is_numeric($_GET['delete'])) {
    $pid = (int)$_GET['delete'];
    $stmt = $conn->prepare("DELETE FROM product_variants WHERE product_id = ? AND tenant_id = ?");
    $stmt->bind_param('ii', $pid, $current_user->tenant_id);
    $stmt->execute();
    $stmt = $conn->prepare("DELETE FROM products WHERE product_id = ? AND tenant_id = ?");
    $stmt->bind_param('ii', $pid, $current_user->tenant_id);
    $stmt->execute();
    flash_set('success', 'Product deleted.');
    redirect('products.php');
}

$stock_scope = '';
$scope_ids = accessible_warehouse_ids(null, true, false);
if ($scope_ids !== null) {
    if (!$scope_ids) {
        $stock_scope = " AND 1 = 0";
    } else {
        $stock_scope = " AND s.warehouse_id IN (" . implode(',', array_map('intval', $scope_ids)) . ")";
    }
}
$products = fetch_all(
    "SELECT p.*, c.name category_name,
        COALESCE((SELECT SUM(s.quantity) FROM inventory_stock s
                  JOIN warehouses sw ON sw.warehouse_id = s.warehouse_id AND sw.tenant_id = s.tenant_id
                  WHERE s.product_id = p.product_id AND s.tenant_id = p.tenant_id
                    AND sw.is_godown = 0{$stock_scope}), 0) total_stock
     FROM products p
     LEFT JOIN product_categories c ON c.category_id = p.category_id
     WHERE p.tenant_id = ?
     ORDER BY p.name",
    $current_user->tenant_id
);

$categories = fetch_all("SELECT * FROM product_categories WHERE tenant_id = ? ORDER BY name", $current_user->tenant_id);
$cat_tree = category_tree($current_user->tenant_id);

require_once 'navbar.php';
require_once 'sidebar.php';
?>

<div class="page-head">
    <div>
        <h1 class="page-title">Products</h1>
        <div class="page-sub"><?php echo count($products); ?> product(s).</div>
    </div>
    <a href="product-add.php" class="btn btn-primary"><i class="fa fa-plus mr-1"></i> Add Product</a>
</div>

<?php if ($msg): ?>
    <div class="alert alert-<?php echo e($msg[0]); ?>"><?php echo e($msg[1]); ?></div>
<?php endif; ?>
<?php if ($f = flash_get()): ?>
    <div class="alert alert-<?php echo e($f['type']); ?>"><?php echo e($f['message']); ?></div>
<?php endif; ?>

<div class="row">
    <div class="col-lg-9 mb-3">
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h5 class="card-title">All Products</h5>
                <div class="input-group dt-search">
                    <div class="input-group-prepend"><span class="input-group-text"><i class="fa fa-search"></i></span></div>
                    <input type="text" class="form-control dt-search-input" data-scope="products" placeholder="Search products...">
                </div>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover data-table">
                        <thead>
                            <tr>
                                <th>Code</th><th>Name</th><th>Main Category</th><th>Subcategory</th><th>Sub-Subcategory</th><th>Purchase</th><th>Sale</th><th>Shop Stock</th><th>Status</th><th class="text-right">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($products as $p): ?>
                            <?php $chain = category_chain($p['category_id']); ?>
                            <tr>
                                <td><span class="badge badge-secondary"><?php echo e($p['code']); ?></span><span class="sr-only"><?php echo e(implode(' ', array_filter([
                                    $p['sku'] ?? '', $p['barcode'] ?? '', $p['description'] ?? '', $p['variant_color'] ?? '',
                                ]))); ?></span></td>
                                <td class="font-weight-semibold"><?php echo e($p['name']); ?>
                                    <?php if (!empty($p['variant_color'])): ?><span class="badge badge-purple ml-1"><?php echo e($p['variant_color']); ?></span><?php endif; ?>
                                    <?php if ($p['is_service']): ?><span class="badge badge-purple ml-1">Service</span><?php endif; ?>
                                </td>
                                <td><?php echo e($chain[0] ?: '—'); ?></td>
                                <td><?php echo e($chain[1] ?: '—'); ?></td>
                                <td><?php echo e($chain[2] ?: '—'); ?></td>
                                <td><?php echo e(money($p['purchase_price'], tenant_currency())); ?></td>
                                <td><?php echo e(money($p['sale_price'], tenant_currency())); ?></td>
                                <td>
                                    <?php if (!$p['is_service']): ?>
                                        <span class="badge <?php echo (float)$p['total_stock'] <= (float)$p['min_stock_level'] ? 'badge-danger' : 'badge-success'; ?>">
                                            <?php echo e(format_quantity($p['total_stock'])); ?>
                                        </span>
                                    <?php else: ?>—<?php endif; ?>
                                </td>
                                <td><?php echo $p['is_active'] ? '<span class="badge badge-success">Active</span>' : '<span class="badge badge-secondary">Inactive</span>'; ?></td>
                                <td class="text-right">
                                    <a href="product-view.php?id=<?php echo $p['product_id']; ?>" class="btn btn-action btn-light" title="View"><i class="fa fa-eye"></i></a>
                                    <a href="product-edit.php?id=<?php echo $p['product_id']; ?>" class="btn btn-action btn-light" title="Edit"><i class="fa fa-pencil"></i></a>
                                    <a href="products.php?delete=<?php echo $p['product_id']; ?>" class="btn btn-action btn-light text-danger" title="Delete" onclick="return confirm('Delete this product?');"><i class="fa fa-trash"></i></a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (!$products): ?>
                            <tr><td colspan="10" class="text-center text-muted py-4">No products yet. <a href="product-add.php">Add your first product</a>.</td></tr>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
    <div class="col-lg-3 mb-3">
        <div class="card">
            <div class="card-header"><h5 class="card-title">Categories</h5></div>
            <div class="card-body">
                <form method="POST" action="products.php" class="mb-3">
                    <input type="hidden" name="action" value="add_category">
                    <div class="form-group"><label>Name <span class="text-danger">*</span></label><input type="text" name="name" class="form-control" required></div>
                    <div class="form-group">
                        <label>Parent Category <small class="text-muted">(Brand &rarr; Line &rarr; Pack)</small></label>
                        <select name="parent" class="form-control">
                            <option value="">— None (Main) —</option>
                            <?php foreach ($cat_tree as $c): ?>
                                <option value="<?php echo $c['category_id']; ?>"><?php echo e(str_repeat('&nbsp;&nbsp;&nbsp;', $c['depth']) . $c['name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group"><label>Description</label><textarea name="description" class="form-control" rows="2"></textarea></div>
                    <button type="submit" class="btn btn-sm btn-primary"><i class="fa fa-plus mr-1"></i> Add Category</button>
                </form>
                <hr>
                <div class="category-list">
                    <?php foreach ($cat_tree as $c): ?>
                        <div class="d-flex justify-content-between align-items-center mb-2">
                            <span style="padding-left:<?php echo $c['depth'] * 14; ?>px;">
                                <?php if ($c['depth'] > 0): ?><i class="fa fa-angle-right text-muted mr-1" style="font-size:10px;"></i><?php endif; ?>
                                <?php echo e($c['name']); ?>
                            </span>
                            <form method="POST" action="products.php" class="d-inline" onsubmit="return confirm('Delete category?');">
                                <input type="hidden" name="action" value="delete_category">
                                <input type="hidden" name="category_id" value="<?php echo $c['category_id']; ?>">
                                <button type="submit" class="btn btn-action btn-light text-danger"><i class="fa fa-trash"></i></button>
                            </form>
                        </div>
                    <?php endforeach; ?>
                    <?php if (!$categories): ?><div class="text-muted small">No categories yet.</div><?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<?php require_once 'footer.php'; ?>
