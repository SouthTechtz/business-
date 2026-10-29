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

$filter_brand = trim((string)($_GET['brand'] ?? ''));
if ($filter_brand !== '' && $filter_brand !== '__none' && !in_array($filter_brand, product_brand_options(), true)) {
    $filter_brand = '';
}
$filter_category = (int)($_GET['category'] ?? 0);
$filter_stock = (string)($_GET['stock'] ?? '');
if (!in_array($filter_stock, ['in', 'out', 'low'], true)) $filter_stock = '';
$filter_status = (string)($_GET['status'] ?? '');
if (!in_array($filter_status, ['active', 'inactive'], true)) $filter_status = '';

$categories = fetch_all("SELECT * FROM product_categories WHERE tenant_id = ? ORDER BY name", $current_user->tenant_id);
$cat_tree = category_tree($current_user->tenant_id);
$category_ids = array_map('intval', array_column($categories, 'category_id'));
if ($filter_category > 0 && !in_array($filter_category, $category_ids, true)) {
    $filter_category = 0;
}

// Include every descendant so choosing a main category also matches its lines/packs.
$category_filter_ids = [];
if ($filter_category > 0) {
    $by_parent = [];
    foreach ($categories as $c) {
        $by_parent[(int)($c['parent_category_id'] ?? 0)][] = (int)$c['category_id'];
    }
    $stack = [$filter_category];
    while ($stack) {
        $cid = array_pop($stack);
        if (isset($category_filter_ids[$cid])) continue;
        $category_filter_ids[$cid] = $cid;
        foreach ($by_parent[$cid] ?? [] as $child) $stack[] = $child;
    }
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

$where = ['p.tenant_id = ?'];
$query_params = [$current_user->tenant_id];
if ($filter_brand === '__none') {
    $where[] = "(p.brand IS NULL OR p.brand = '')";
} elseif ($filter_brand !== '') {
    $where[] = 'p.brand = ?';
    $query_params[] = $filter_brand;
}
if ($category_filter_ids) {
    $where[] = 'p.category_id IN (' . implode(',', array_map('intval', $category_filter_ids)) . ')';
}
if ($filter_status !== '') {
    $where[] = 'p.is_active = ?';
    $query_params[] = $filter_status === 'active' ? 1 : 0;
}

$products = fetch_all(
    "SELECT p.*, c.name category_name,
        COALESCE((SELECT SUM(s.quantity) FROM inventory_stock s
                  JOIN warehouses sw ON sw.warehouse_id = s.warehouse_id AND sw.tenant_id = s.tenant_id
                  WHERE s.product_id = p.product_id AND s.tenant_id = p.tenant_id
                    AND sw.is_godown = 0{$stock_scope}), 0) total_stock
     FROM products p
     LEFT JOIN product_categories c ON c.category_id = p.category_id
     WHERE " . implode(' AND ', $where) . "
     ORDER BY p.name",
    ...$query_params
);

if ($filter_stock !== '') {
    $products = array_values(array_filter($products, function ($p) use ($filter_stock) {
        if ((int)$p['is_service'] === 1) return false;
        $qty = (float)$p['total_stock'];
        if ($filter_stock === 'in') return $qty > 0;
        if ($filter_stock === 'out') return $qty <= 0;
        return $qty > 0 && $qty <= (float)$p['min_stock_level'];
    }));
}

// Sum of the unit purchase and sale prices of the products in the list
$total_purchase = 0;
$total_sales = 0;
foreach ($products as $p) {
    $total_purchase += (float)$p['purchase_price'];
    $total_sales += (float)$p['sale_price'];
}

require_once 'navbar.php';
require_once 'sidebar.php';
?>

<div class="page-head">
    <div>
        <h1 class="page-title">Products</h1>
        <div class="page-sub"><?php echo count($products); ?> product(s).</div>
    </div>
    <!-- Changed to trigger modal popup -->
    <button type="button" class="btn btn-primary" data-toggle="modal" data-target="#addProductModal">
        <i class="fa fa-plus mr-1"></i> Add Product
    </button>
</div>

<?php if ($msg): ?>
    <div class="alert alert-<?php echo e($msg[0]); ?>"><?php echo e($msg[1]); ?></div>
<?php endif; ?>
<?php if ($f = flash_get()): ?>
    <div class="alert alert-<?php echo e($f['type']); ?>"><?php echo e($f['message']); ?></div>
<?php endif; ?>

<style>
    .product-floating-totals {
        position: sticky;
        bottom: 0;
        z-index: 5;
        display: flex;
        flex-wrap: wrap;
        justify-content: space-between;
        align-items: center;
        gap: 6px 18px;
        padding: 11px 22px;
        background: var(--card-bg, #fff);
        border-top: 2px solid var(--primary, #6f42c1);
        border-radius: 0 0 var(--radius, 8px) var(--radius, 8px);
        box-shadow: 0 -6px 18px rgba(33, 26, 74, .10);
    }
    .product-floating-totals strong { color: var(--primary, #6f42c1); font-weight: 700; }
    .product-floating-totals .fa { font-size: 13px; }
</style>

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
            <div class="card-body border-bottom pb-2">
                <form method="GET" action="products.php" class="form-row align-items-end">
                    <div class="col-6 col-md-3 form-group mb-2">
                        <label class="small mb-1">Brand</label>
                        <select name="brand" class="form-control form-control-sm">
                            <option value="">All brands</option>
                            <?php foreach (product_brand_options() as $brand): ?>
                                <option value="<?php echo e($brand); ?>" <?php echo $filter_brand === $brand ? 'selected' : ''; ?>><?php echo e($brand); ?></option>
                            <?php endforeach; ?>
                            <option value="__none" <?php echo $filter_brand === '__none' ? 'selected' : ''; ?>>No brand</option>
                        </select>
                    </div>
                    <div class="col-6 col-md-3 form-group mb-2">
                        <label class="small mb-1">Category</label>
                        <select name="category" class="form-control form-control-sm">
                            <option value="">All categories</option>
                            <?php foreach ($cat_tree as $c): ?>
                                <option value="<?php echo $c['category_id']; ?>" <?php echo $filter_category === (int)$c['category_id'] ? 'selected' : ''; ?>><?php echo e(str_repeat('— ', $c['depth']) . $c['name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-6 col-md-2 form-group mb-2">
                        <label class="small mb-1">Stock</label>
                        <select name="stock" class="form-control form-control-sm">
                            <option value="">All stock</option>
                            <option value="in" <?php echo $filter_stock === 'in' ? 'selected' : ''; ?>>In stock</option>
                            <option value="low" <?php echo $filter_stock === 'low' ? 'selected' : ''; ?>>Low stock</option>
                            <option value="out" <?php echo $filter_stock === 'out' ? 'selected' : ''; ?>>Out of stock</option>
                        </select>
                    </div>
                    <div class="col-6 col-md-2 form-group mb-2">
                        <label class="small mb-1">Status</label>
                        <select name="status" class="form-control form-control-sm">
                            <option value="">All statuses</option>
                            <option value="active" <?php echo $filter_status === 'active' ? 'selected' : ''; ?>>Active</option>
                            <option value="inactive" <?php echo $filter_status === 'inactive' ? 'selected' : ''; ?>>Inactive</option>
                        </select>
                    </div>
                    <div class="col-12 col-md-2 form-group mb-2">
                        <button type="submit" class="btn btn-sm btn-primary"><i class="fa fa-filter mr-1"></i> Filter</button>
                        <a href="products.php" class="btn btn-sm btn-light">Reset</a>
                    </div>
                </form>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover data-table" id="productsTable">
                        <thead>
                            <tr>
                                <th>Code</th><th>Name</th><th>Brand</th><th>Main Category</th><th>Subcategory</th><th>Sub-Subcategory</th><th>Purchase</th><th>Sale</th><th>Shop Stock</th><th>Status</th><th class="text-right">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($products as $p): ?>
                            <?php $chain = category_chain($p['category_id']); ?>
                            <tr data-purchase-value="<?php echo e($p['purchase_price']); ?>"
                                data-sale-value="<?php echo e($p['sale_price']); ?>">
                                <td><span class="badge badge-secondary"><?php echo e($p['code']); ?></span><span class="sr-only"><?php echo e(implode(' ', array_filter([
                                    $p['sku'] ?? '', $p['barcode'] ?? '', $p['description'] ?? '', $p['variant_color'] ?? '',
                                ]))); ?></span></td>
                                <td class="font-weight-semibold"><?php echo e($p['name']); ?>
                                    <?php if (!empty($p['variant_color'])): ?><span class="badge badge-purple ml-1"><?php echo e($p['variant_color']); ?></span><?php endif; ?>
                                    <?php if ($p['is_service']): ?><span class="badge badge-purple ml-1">Service</span><?php endif; ?>
                                </td>
                                <td><?php echo e($p['brand'] ?: '—'); ?></td>
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
                            <tr><td colspan="11" class="text-center text-muted py-4">No products yet. <a href="#" data-toggle="modal" data-target="#addProductModal">Add your first product</a>.</td></tr>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <div class="product-floating-totals" id="productFloatingTotals">
                <div class="small"><i class="fa fa-calculator mr-1 text-muted"></i><strong id="productListCount"><?php echo count($products); ?></strong> product(s) in list</div>
                <div class="small text-right">
                    <span class="mr-3" title="Sum of unit purchase prices for the products shown">
                        Total purchases: <strong id="productTotalPurchase"><?php echo e(money($total_purchase, tenant_currency())); ?></strong>
                    </span>
                    <span title="Sum of unit sale prices for the products shown">
                        Total expected sales: <strong id="productTotalSales"><?php echo e(money($total_sales, tenant_currency())); ?></strong>
                    </span>
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

<!-- Add Product Modal Window -->
<div class="modal fade" id="addProductModal" tabindex="-1" role="dialog" aria-labelledby="addProductModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="addProductModalLabel"><i class="fa fa-plus-circle mr-1"></i> Add Product</h5>
                <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
            <div class="modal-body" id="addProductModalBody">
                <div class="text-center py-4">
                    <i class="fa fa-spinner fa-spin fa-2x text-muted"></i>
                    <p class="text-muted mt-2">Loading form...</p>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
    $(function () {
        var $table = $('#productsTable');
        var currency = <?php echo json_encode(tenant_currency()); ?>;

        function formatMoney(value) {
            return currency + ' ' + Number(value).toLocaleString(undefined, {
                minimumFractionDigits: 2,
                maximumFractionDigits: 2
            });
        }

        function recalcProductTotals() {
            var purchase = 0, sales = 0, count = 0;
            var rows;
            if ($.fn.DataTable && $.fn.DataTable.isDataTable($table[0])) {
                rows = $table.DataTable().rows({ search: 'applied' }).nodes();
            } else {
                rows = $table.find('tbody tr');
            }
            $(rows).each(function () {
                purchase += parseFloat($(this).attr('data-purchase-value')) || 0;
                sales += parseFloat($(this).attr('data-sale-value')) || 0;
                count++;
            });
            $('#productListCount').text(count);
            $('#productTotalPurchase').text(formatMoney(purchase));
            $('#productTotalSales').text(formatMoney(sales));
        }

        if ($table.length) {
            $table.on('init.dt draw.dt', recalcProductTotals);
            recalcProductTotals();
        }

        // Dynamically load product-add.php form into modal when opened
        $('#addProductModal').on('show.bs.modal', function () {
            var $body = $('#addProductModalBody');
            if ($body.find('form').length === 0) {
                $.get('product-add.php', function (html) {
                    // Extract just the inner content/card body of product-add.php
                    var $parsed = $(html);
                    var $formContent = $parsed.find('form').parent();
                    if ($formContent.length) {
                        // Clean up back links or headers from the standalone add page if desired
                        $formContent.find('.page-head, .btn-light').remove();
                        $body.html($formContent);
                    } else {
                        $body.html('<div class="alert alert-danger">Could not load add product form.</div>');
                    }
                }).fail(function () {
                    $body.html('<div class="alert alert-danger">Failed to connect. Please try again.</div>');
                });
            }
        });
    });
</script>

<?php require_once 'footer.php'; ?>