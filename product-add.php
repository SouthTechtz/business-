<?php
require_once 'header.php';
require_once 'auth_check.php';
require_permission('manage_products');
$page_title = 'Add Product';

/** Generate the next product code in the form PRD-### */
function generateProductCode($conn, $tenantId) {
    $stmt = $conn->prepare("SELECT code FROM products WHERE tenant_id = ? AND code LIKE 'PRD-%' ORDER BY code DESC LIMIT 1");
    $stmt->bind_param('i', $tenantId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $next = 1;
    if ($row) {
        $suffix = (int)substr($row['code'], 4);
        $next = $suffix + 1;
    }
    return 'PRD-' . str_pad($next, 3, '0', STR_PAD_LEFT);
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $data = [
        'tenant_id'        => $current_user->tenant_id,
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
        'created_by'       => $current_user->user_id,
    ];
    if ($data['code'] === '') $data['code'] = generateProductCode($conn, $current_user->tenant_id);
    $initial_stock = (float)($_POST['initial_stock'] ?? 0);
    $opening_warehouse_id = (int)($_POST['opening_warehouse_id'] ?? 0);

    if ($data['name'] === '') {
        $error = 'Product name is required.';
    } elseif (!$data['is_service'] && $initial_stock > 0 && !find_accessible_store($opening_warehouse_id, false)) {
        $error = 'Please select an active store for the initial stock.';
    } else {
        $conn->begin_transaction();
        try {
            $id = db_insert('products', $data);
            if (!$id) throw new Exception('Could not create the product.');

            if (!$data['is_service'] && $initial_stock > 0) {
                $wh = find_accessible_store($opening_warehouse_id, false);
                if (!$wh) throw new Exception('The selected opening-stock store is no longer available.');
                $stmt = $conn->prepare("INSERT INTO inventory_stock (tenant_id, product_id, warehouse_id, quantity, reorder_level) VALUES (?, ?, ?, ?, ?)");
                $reorder = $data['min_stock_level'];
                $stmt->bind_param('iiidd', $current_user->tenant_id, $id, $wh['warehouse_id'], $initial_stock, $reorder);
                $stmt->execute();
                $stmt = $conn->prepare(
                    "INSERT INTO inventory_movements (tenant_id, product_id, warehouse_id, movement_type, quantity, created_by, notes)
                     VALUES (?, ?, ?, 'adjustment', ?, ?, 'Initial stock for new product')"
                );
                $stmt->bind_param('iiidi', $current_user->tenant_id, $id, $wh['warehouse_id'], $initial_stock, $current_user->user_id);
                $stmt->execute();
            }
            $conn->commit();
            audit_log('create', 'product', $id, 'Product ' . $data['name'] . ' created');
            flash_set('success', 'Product created successfully.');
            redirect('products.php');
        } catch (Exception $ex) {
            $conn->rollback();
            $error = $ex->getMessage();
        }
    }
}

$categories = fetch_all("SELECT * FROM product_categories WHERE tenant_id = ? ORDER BY name", $current_user->tenant_id);
$units = fetch_all("SELECT * FROM units_of_measurement WHERE tenant_id = ? ORDER BY name", $current_user->tenant_id);
$opening_stores = accessible_stores(null, false);
if (!isset($opening_warehouse_id) || !find_accessible_store($opening_warehouse_id, false)) {
    $opening_warehouse_id = $opening_stores ? (int)$opening_stores[0]['warehouse_id'] : 0;
}

// Build category tree for cascading selects (Main -> Sub -> Sub-Sub)
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

require_once 'navbar.php';
require_once 'sidebar.php';
?>

<div class="page-head">
    <div>
        <h1 class="page-title">Add Product</h1>
        <div class="page-sub">Create a new product or service.</div>
    </div>
    <a href="products.php" class="btn btn-light"><i class="fa fa-arrow-left mr-1"></i> Back to Products</a>
</div>

<div class="row">
    <div class="col-lg-9">
        <div class="card">
            <div class="card-body">
                <?php if ($error): ?><div class="alert alert-danger"><?php echo e($error); ?></div><?php endif; ?>
                <form method="POST" action="product-add.php">
                    <div class="row">
                        <div class="col-md-4 form-group">
                            <label>Code</label>
                            <input type="text" name="code" class="form-control" placeholder="Auto-generated (PRD-###)" value="<?php echo e($_POST['code'] ?? ''); ?>">
                        </div>
                        <div class="col-md-8 form-group">
                            <label>Product Name <span class="text-danger">*</span></label>
                            <input type="text" name="name" class="form-control" required value="<?php echo e($_POST['name'] ?? ''); ?>">
                        </div>
                        <div class="col-md-4 form-group">
                            <label>Main Category (Brand)</label>
                            <select name="category_main" id="catMain" class="form-control">
                                <option value="">— Select Brand —</option>
                                <?php foreach ($main_cats as $id => $c): ?>
                                    <option value="<?php echo $id; ?>"><?php echo e($c['name']); ?></option>
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
                            <input type="hidden" name="category_id" id="categoryLeaf" value="">
                        </div>
                        <div class="col-md-4 form-group">
                            <label>Unit of Measure</label>
                            <select name="unit_id" class="form-control">
                                <option value="">— None —</option>
                                <?php foreach ($units as $u): ?>
                                    <option value="<?php echo $u['unit_id']; ?>" <?php echo (($_POST['unit_id'] ?? '') == $u['unit_id']) ? 'selected' : ''; ?>><?php echo e($u['name'] . ' (' . $u['short_name'] . ')'); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4 form-group">
                            <label>Barcode</label>
                            <input type="text" name="barcode" class="form-control" value="<?php echo e($_POST['barcode'] ?? ''); ?>">
                        </div>
                        <div class="col-md-4 form-group">
                            <label>SKU</label>
                            <input type="text" name="sku" class="form-control" value="<?php echo e($_POST['sku'] ?? ''); ?>">
                        </div>
                        <div class="col-md-4 form-group">
                            <label>Purchase Price</label>
                            <input type="number" name="purchase_price" class="form-control" step="1" min="0" value="<?php echo e($_POST['purchase_price'] ?? '0'); ?>">
                        </div>
                        <div class="col-md-4 form-group">
                            <label>Sale Price</label>
                            <input type="number" name="sale_price" class="form-control" step="1" min="0" value="<?php echo e($_POST['sale_price'] ?? '0'); ?>">
                        </div>
                        <div class="col-md-4 form-group">
                            <label>Tax Rate (%)</label>
                            <input type="number" name="tax_rate" class="form-control" step="1" min="0" value="<?php echo e($_POST['tax_rate'] ?? '0'); ?>">
                        </div>
                        <div class="col-md-4 form-group">
                            <label>Min Stock Level</label>
                            <input type="number" name="min_stock_level" class="form-control" step="1" min="0" value="<?php echo e($_POST['min_stock_level'] ?? '0'); ?>">
                        </div>
                        <div class="col-md-4 form-group">
                            <label>Initial Stock</label>
                            <input type="number" name="initial_stock" class="form-control" step="1" min="0" value="<?php echo e($_POST['initial_stock'] ?? '0'); ?>">
                            <small class="text-muted">Only for non-service products.</small>
                        </div>
                        <div class="col-md-4 form-group">
                            <label>Opening Stock Store</label>
                            <select name="opening_warehouse_id" class="form-control">
                                <?php foreach ($opening_stores as $store): ?>
                                    <option value="<?php echo (int)$store['warehouse_id']; ?>" <?php echo (int)$store['warehouse_id'] === (int)$opening_warehouse_id ? 'selected' : ''; ?>><?php echo e(store_label($store)); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <small class="text-muted"><?php echo $opening_stores ? 'Initial stock is stored here.' : 'No active store is available.'; ?></small>
                        </div>
                        <div class="col-md-4 form-group">
                            <label>Manufactured Date</label>
                            <input type="date" name="manufactured_date" class="form-control" value="<?php echo e($_POST['manufactured_date'] ?? ''); ?>">
                        </div>
                        <div class="col-md-4 form-group">
                            <label>Expiry Date</label>
                            <input type="date" name="expires_date" class="form-control" value="<?php echo e($_POST['expires_date'] ?? ''); ?>">
                        </div>
                        <div class="col-md-4 form-group">
                            <label>Variant Size</label>
                            <input type="text" name="variant_size" class="form-control" value="<?php echo e($_POST['variant_size'] ?? ''); ?>">
                        </div>
                        <div class="col-md-4 form-group">
                            <label>Color</label>
                            <input type="text" name="variant_color" class="form-control" value="<?php echo e($_POST['variant_color'] ?? ''); ?>" placeholder="e.g. White, Sky Blue">
                        </div>
                        <div class="col-md-12 form-group">
                            <label>Description</label>
                            <textarea name="description" class="form-control" rows="2"><?php echo e($_POST['description'] ?? ''); ?></textarea>
                        </div>
                        <div class="col-md-6 form-group">
                            <div class="custom-control custom-checkbox">
                                <input type="checkbox" class="custom-control-input" name="is_service" id="is_service" <?php echo isset($_POST['is_service']) ? 'checked' : ''; ?>>
                                <label class="custom-control-label" for="is_service">This is a service (no stock tracking)</label>
                            </div>
                        </div>
                        <div class="col-md-6 form-group">
                            <div class="custom-control custom-checkbox">
                                <input type="checkbox" class="custom-control-input" name="is_active" id="is_active" checked>
                                <label class="custom-control-label" for="is_active">Active product</label>
                            </div>
                        </div>
                    </div>
                    <button type="submit" class="btn btn-primary"><i class="fa fa-check mr-1"></i> Create Product</button>
                </form>
            </div>
        </div>
    </div>
</div>

<script>
    var catTree = <?php echo json_encode($cat_map); ?>;
    function fillSelect($sel, parentId, placeholder) {
        $sel.empty().append('<option value="">' + placeholder + '</option>');
        (catTree[parentId] ? catTree[parentId].children : []).forEach(function (id) {
            $sel.append('<option value="' + id + '">' + catTree[id].name + '</option>');
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
    });
</script>

<?php require_once 'footer.php'; ?>
