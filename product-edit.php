<?php
require_once 'header.php';
require_once 'auth_check.php';
require_permission('manage_products');
$page_title = 'Edit Product';

$id = (int)($_GET['id'] ?? 0);
$product = fetch_one("SELECT * FROM products WHERE product_id = ? AND tenant_id = ?", $id, $current_user->tenant_id);
if (!$product) {
    flash_set('danger', 'Product not found.');
    redirect('products.php');
}
$product_id = (int)$product['product_id'];

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $data = [
        'category_id'      => !empty($_POST['category_id']) ? (int)$_POST['category_id'] : null,
        'code'             => trim($_POST['code'] ?? ''),
        'name'             => trim($_POST['name'] ?? ''),
        'description'      => trim($_POST['description'] ?? ''),
        'barcode'          => trim($_POST['barcode'] ?? ''),
        'sku'              => trim($_POST['sku'] ?? ''),
        'unit_id'          => !empty($_POST['unit_id']) ? (int)$_POST['unit_id'] : null,
        'purchase_price'   => (float)($_POST['purchase_price'] ?? 0),
        'sale_price'       => (float)($_POST['sale_price'] ?? 0),
        'tax_rate'         => (float)($_POST['tax_rate'] ?? 0),
        'min_stock_level'  => (float)($_POST['min_stock_level'] ?? 0),
        'manufactured_date'=> $_POST['manufactured_date'] ?: null,
        'expires_date'     => $_POST['expires_date'] ?: null,
        'is_active'        => isset($_POST['is_active']) ? 1 : 0,
        'is_service'       => isset($_POST['is_service']) ? 1 : 0,
        'variant_size'     => trim($_POST['variant_size'] ?? '') ?: null,
        'variant_color'    => trim($_POST['variant_color'] ?? '') ?: null,
    ];
    if ($data['name'] === '' || $data['code'] === '') {
        $error = 'Product name and code are required.';
    } else {
        $set = [];
        $vals = [];
        foreach ($data as $k => $v) {
            $set[] = "`$k` = ?";
            $vals[] = $v;
        }
        $vals[] = $id;
        $vals[] = $current_user->tenant_id;
        $stmt = $conn->prepare("UPDATE products SET " . implode(', ', $set) . " WHERE product_id = ? AND tenant_id = ?");
        $types = '';
        foreach ($data as $v) $types .= is_int($v) ? 'i' : (is_float($v) ? 'd' : 's');
        $types .= 'ii';
        $stmt->bind_param($types, ...$vals);
        if ($stmt->execute()) {
            audit_log('update', 'product', $id, 'Product updated');
            flash_set('success', 'Product updated successfully.');
            redirect('product-view.php?id=' . $id);
        }
        $error = 'Could not update the product.';
    }
}

$categories = fetch_all("SELECT * FROM product_categories WHERE tenant_id = ? ORDER BY name", $current_user->tenant_id);
$units = fetch_all("SELECT * FROM units_of_measurement WHERE tenant_id = ? ORDER BY name", $current_user->tenant_id);

$cat_map = [];
foreach ($categories as $c) {
    $cat_map[(int)$c['category_id']] = [
        'name' => $c['name'],
        'parent' => $c['parent_category_id'] ? (int)$c['parent_category_id'] : 0,
        'children' => [],
    ];
}
foreach ($cat_map as $id => &$c) {
    if ($c['parent']) $cat_map[$c['parent']]['children'][] = $id;
}
unset($c);
$main_cats = array_filter($cat_map, fn($c) => !$c['parent']);
$prod_chain = category_chain($product['category_id']);
$selected_cat = (int)$product['category_id'];
$cur_main = 0; $cur_sub = 0; $cur_subsub = 0;
foreach ($categories as $c) {
    $id = (int)$c['category_id'];
    if ($id === $selected_cat) {
        if ($c['parent_category_id']) {
            $sub = (int)$c['parent_category_id'];
            $cur_subsub = $id;
            $g = fetch_one("SELECT parent_category_id FROM product_categories WHERE category_id = ?", $sub);
            if ($g && $g['parent_category_id']) {
                $cur_sub = $sub;
                $cur_main = (int)$g['parent_category_id'];
            } else {
                $cur_sub = $id;
                $cur_subsub = 0;
                $cur_main = $sub;
            }
        } else {
            $cur_main = $id;
        }
    }
}

$can_godown = can('manage_godown');
$scope_ids = accessible_warehouse_ids(null, true, $can_godown);
$stock_sql = "SELECT s.*, w.name warehouse_name, w.is_godown, sh.name shop_name FROM inventory_stock s
     JOIN warehouses w ON w.warehouse_id = s.warehouse_id
     LEFT JOIN shops sh ON sh.shop_id = w.shop_id AND sh.tenant_id = w.tenant_id
     WHERE s.product_id = ? AND s.tenant_id = ?";
$stock_params = [$product_id, $current_user->tenant_id];
if ($scope_ids !== null) {
    if (!$scope_ids) {
        $stock_sql .= " AND 1 = 0";
    } else {
        $stock_sql .= " AND s.warehouse_id IN (" . implode(',', array_map('intval', $scope_ids)) . ")";
    }
}
$stock = fetch_all($stock_sql, ...$stock_params);

require_once 'navbar.php';
require_once 'sidebar.php';
?>

<div class="page-head">
    <div>
        <h1 class="page-title">Edit Product</h1>
        <div class="page-sub"><?php echo e($product['name']); ?></div>
    </div>
    <a href="product-view.php?id=<?php echo $product_id; ?>" class="btn btn-light"><i class="fa fa-arrow-left mr-1"></i> Back</a>
</div>

<div class="row">
    <div class="col-lg-9">
        <div class="card">
            <div class="card-body">
                <?php if ($error): ?><div class="alert alert-danger"><?php echo e($error); ?></div><?php endif; ?>
                <form method="POST" action="product-edit.php?id=<?php echo $product_id; ?>">
                    <div class="row">
                        <div class="col-md-4 form-group"><label>Code <span class="text-danger">*</span></label><input type="text" name="code" class="form-control" required value="<?php echo e($product['code']); ?>"></div>
                        <div class="col-md-8 form-group"><label>Product Name <span class="text-danger">*</span></label><input type="text" name="name" class="form-control" required value="<?php echo e($product['name']); ?>"></div>
                        <div class="col-md-4 form-group">
                            <label>Main Category (Brand)</label>
                            <select name="category_main" id="catMain" class="form-control">
                                <option value="">— Select Brand —</option>
                                <?php foreach ($main_cats as $id => $c): ?>
                                    <option value="<?php echo $id; ?>" <?php echo $cur_main === $id ? 'selected' : ''; ?>><?php echo e($c['name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4 form-group">
                            <label>Subcategory (Line)</label>
                            <select name="category_sub" id="catSub" class="form-control">
                                <option value="">— Select Line —</option>
                            </select>
                        </div>
                        <div class="col-md-4 form-group">
                            <label>Sub-Subcategory (Pack)</label>
                            <select name="category_subsub" id="catSubSub" class="form-control">
                                <option value="">— Select Pack —</option>
                            </select>
                            <input type="hidden" name="category_id" id="categoryLeaf" value="<?php echo $selected_cat; ?>">
                        </div>
                        <div class="col-md-4 form-group">
                            <label>Unit of Measure</label>
                            <select name="unit_id" class="form-control">
                                <option value="">— None —</option>
                                <?php foreach ($units as $u): ?>
                                    <option value="<?php echo $u['unit_id']; ?>" <?php echo $product['unit_id'] == $u['unit_id'] ? 'selected' : ''; ?>><?php echo e($u['name'] . ' (' . $u['short_name'] . ')'); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4 form-group"><label>Barcode</label><input type="text" name="barcode" class="form-control" value="<?php echo e($product['barcode']); ?>"></div>
                        <div class="col-md-4 form-group"><label>SKU</label><input type="text" name="sku" class="form-control" value="<?php echo e($product['sku']); ?>"></div>
                        <div class="col-md-4 form-group"><label>Purchase Price</label><input type="number" name="purchase_price" class="form-control" step="1" min="0" value="<?php echo e($product['purchase_price']); ?>"></div>
                        <div class="col-md-4 form-group"><label>Sale Price</label><input type="number" name="sale_price" class="form-control" step="1" min="0" value="<?php echo e($product['sale_price']); ?>"></div>
                        <div class="col-md-4 form-group"><label>Tax Rate (%)</label><input type="number" name="tax_rate" class="form-control" step="1" min="0" value="<?php echo e($product['tax_rate']); ?>"></div>
                        <div class="col-md-4 form-group"><label>Min Stock Level</label><input type="number" name="min_stock_level" class="form-control" step="1" min="0" value="<?php echo e($product['min_stock_level']); ?>"></div>
                        <div class="col-md-4 form-group"><label>Manufactured Date</label><input type="date" name="manufactured_date" class="form-control" value="<?php echo e($product['manufactured_date']); ?>"></div>
                        <div class="col-md-4 form-group"><label>Expiry Date</label><input type="date" name="expires_date" class="form-control" value="<?php echo e($product['expires_date']); ?>"></div>
                        <div class="col-md-4 form-group"><label>Variant Size</label><input type="text" name="variant_size" class="form-control" value="<?php echo e($product['variant_size']); ?>"></div>
                        <div class="col-md-4 form-group"><label>Color</label><input type="text" name="variant_color" class="form-control" value="<?php echo e($product['variant_color']); ?>" placeholder="e.g. White, Sky Blue"></div>
                        <div class="col-md-12 form-group"><label>Description</label><textarea name="description" class="form-control" rows="2"><?php echo e($product['description']); ?></textarea></div>
                        <div class="col-md-6 form-group">
                            <div class="custom-control custom-checkbox">
                                <input type="checkbox" class="custom-control-input" name="is_service" id="is_service" <?php echo $product['is_service'] ? 'checked' : ''; ?>>
                                <label class="custom-control-label" for="is_service">This is a service (no stock tracking)</label>
                            </div>
                        </div>
                        <div class="col-md-6 form-group">
                            <div class="custom-control custom-checkbox">
                                <input type="checkbox" class="custom-control-input" name="is_active" id="is_active" <?php echo $product['is_active'] ? 'checked' : ''; ?>>
                                <label class="custom-control-label" for="is_active">Active product</label>
                            </div>
                        </div>
                    </div>
                    <button type="submit" class="btn btn-primary"><i class="fa fa-check mr-1"></i> Save Changes</button>
                </form>
            </div>
        </div>
    </div>
    <div class="col-lg-3 mb-3">
        <div class="card">
            <div class="card-header"><h5 class="card-title">Stock by Warehouse</h5></div>
            <div class="card-body">
                <?php foreach ($stock as $s): ?>
                    <div class="d-flex justify-content-between mb-2">
                        <span>
                            <?php echo e(trim(($s['shop_name'] ?? '') . ' / ' . $s['warehouse_name'], ' /')); ?>
                            <?php if ((int)($s['is_godown'] ?? 0) === 1): ?>
                                <span class="badge badge-warning">Central Godown</span>
                            <?php endif; ?>
                        </span>
                        <span class="font-weight-semibold"><?php echo e(format_quantity($s['quantity'])); ?></span>
                    </div>
                <?php endforeach; ?>
                <?php if (!$stock): ?><div class="text-muted small">No stock recorded.</div><?php endif; ?>
                <?php if (can('manage_inventory') && !$product['is_service']): ?>
                    <a href="inventory-adjust.php?product=<?php echo $product_id; ?>" class="btn btn-sm btn-outline-primary mt-2"><i class="fa fa-plus mr-1"></i> Adjust Stock</a>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<script>
    var catTree = <?php echo json_encode($cat_map); ?>;
    var curMain = <?php echo (int)$cur_main; ?>;
    var curSub = <?php echo (int)$cur_sub; ?>;
    var curSubSub = <?php echo (int)$cur_subsub; ?>;
    function fillSelect($sel, parentId, placeholder, selected) {
        $sel.empty().append('<option value="">' + placeholder + '</option>');
        (catTree[parentId] ? catTree[parentId].children : []).forEach(function (id) {
            $sel.append('<option value="' + id + '"' + (String(id) === String(selected) ? ' selected' : '') + '>' + catTree[id].name + '</option>');
        });
    }
    function syncLeaf() {
        var leaf = $('#catSubSub').val() || $('#catSub').val() || $('#catMain').val() || '';
        $('#categoryLeaf').val(leaf);
    }
    $(function () {
        $('#catMain').on('change', function () {
            fillSelect($('#catSub'), $(this).val(), '— Select Line —');
            $('#catSubSub').empty().append('<option value="">— Select Pack —</option>');
            syncLeaf();
        });
        $('#catSub').on('change', function () {
            fillSelect($('#catSubSub'), $(this).val(), '— Select Pack —');
            syncLeaf();
        });
        $('#catSubSub').on('change', syncLeaf);
        if (curMain) {
            fillSelect($('#catSub'), curMain, '— Select Line —', curSub);
            if (curSub) fillSelect($('#catSubSub'), curSub, '— Select Pack —', curSubSub);
        }
    });
</script>

<?php require_once 'footer.php'; ?>
