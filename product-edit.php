<?php
require_once 'header.php';
require_once 'auth_check.php';
require_product_catalogue_access();
require_once 'barcode-lib.php';
$page_title = 'Edit Product';

$id = (int)($_GET['id'] ?? 0);
$product = fetch_one("SELECT * FROM products WHERE product_id = ? AND tenant_id = ?", $id, $current_user->tenant_id);
if (!$product) {
    flash_set('danger', 'Product not found.');
    redirect('products.php');
}
$product_id = (int)$product['product_id'];
if (!brand_scope_allows($product['brand'] ?? '')) {
    http_response_code(403);
    die('This product belongs to another brand.');
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $data = [
        'category_id'      => !empty($_POST['category_id']) ? (int)$_POST['category_id'] : null,
        'code'             => trim($_POST['code'] ?? ''),
        'name'             => trim($_POST['name'] ?? ''),
        'description'      => trim($_POST['description'] ?? ''),
        'barcode'          => trim($_POST['barcode'] ?? ''),
        'sku'              => trim($_POST['sku'] ?? ''),
        'brand'            => trim($_POST['brand'] ?? '') ?: null,
        'unit_id'          => !empty($_POST['unit_id']) ? (int)$_POST['unit_id'] : null,
        'purchase_price'   => (float)($_POST['purchase_price'] ?? 0),
        'sale_price'       => (float)($_POST['sale_price'] ?? 0),
        'wholesale_price'  => ($_POST['wholesale_price'] ?? '') !== '' ? max(0, (float)$_POST['wholesale_price']) : null,
        'wholesale_min_qty'=> ($_POST['wholesale_min_qty'] ?? '') !== '' ? max(0, (float)$_POST['wholesale_min_qty']) : null,
        'tax_rate'         => (float)($_POST['tax_rate'] ?? 0),
        'min_stock_level'  => (float)($_POST['min_stock_level'] ?? 0),
        'date_added'       => !empty($_POST['date_added']) ? $_POST['date_added'] : null,
        'is_active'        => isset($_POST['is_active']) ? 1 : 0,
        'is_service'       => isset($_POST['is_service']) ? 1 : 0,
        'variant_size'     => trim($_POST['variant_size'] ?? '') ?: null,
        'variant_color'    => trim($_POST['variant_color'] ?? '') ?: null,
    ];
    // The barcode is always generated from the manually entered product code.
    $data['barcode'] = $data['code'];
    // A brand-scoped Mini Admin cannot move a product to another brand.
    $brand_scope = current_brand_scope();
    if ($brand_scope !== '') {
        $data['brand'] = $brand_scope;
    }
    // Normalise optional wholesale pricing.
    if (($data['wholesale_price'] ?? 0) <= 0 && ($data['wholesale_min_qty'] ?? 0) <= 0) {
        $data['wholesale_price'] = null;
        $data['wholesale_min_qty'] = null;
    }
    $duplicate_code = $data['code'] !== ''
        ? fetch_one(
            "SELECT product_id FROM products WHERE tenant_id = ? AND code = ? AND product_id <> ? LIMIT 1",
            $current_user->tenant_id,
            $data['code'],
            $product_id
        )
        : null;

    if ($data['name'] === '' || $data['code'] === '') {
        $error = 'Product name and code are required.';
    } elseif (mb_strlen($data['code']) > 100) {
        $error = 'The product code must be 100 characters or fewer.';
    } elseif (!barcode_is_encodable($data['code'])) {
        $error = 'The product code can only contain printable letters, numbers and symbols so it can be encoded as a barcode.';
    } elseif ($duplicate_code) {
        $error = 'That product code is already used by another product.';
    } elseif (($data['wholesale_price'] === null) !== ($data['wholesale_min_qty'] === null)) {
        $error = 'Enter both a wholesale price and the minimum quantity that qualifies for it.';
    } elseif ($data['brand'] !== null && !in_array($data['brand'], product_brand_options(), true)) {
        $error = 'Select a valid product brand.';
    } else {
        $image_path = store_product_image_upload($_FILES['image'] ?? null, $error);
        if ($image_path !== false) {
            if ($image_path !== '') {
                $data['image'] = $image_path;
            } elseif (!empty($_POST['remove_image'])) {
                $data['image'] = null;
            }
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
                $stmt->close();
                if ($image_path !== '' || !empty($_POST['remove_image'])) {
                    remove_product_image_file($product['image'] ?? '');
                }
                audit_log('update', 'product', $id, 'Product updated');
                flash_set('success', 'Product updated successfully.');
                redirect('product-view.php?id=' . $id);
            }
            $error = 'Could not update the product: ' . $stmt->error;
            $stmt->close();
            if ($image_path !== '') remove_product_image_file($image_path);
        }
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
$stock_sql = "SELECT s.*, w.warehouse_id, w.name warehouse_name, w.is_godown, sh.name shop_name FROM inventory_stock s
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
    <div>
        <a href="product-barcode.php?id=<?php echo $product_id; ?>" class="btn btn-outline-primary"><i class="fa fa-barcode mr-1"></i> Print barcode</a>
        <a href="product-view.php?id=<?php echo $product_id; ?>" class="btn btn-light"><i class="fa fa-arrow-left mr-1"></i> Back</a>
    </div>
</div>

<div class="row">
    <div class="col-lg-9">
        <div class="card">
            <div class="card-body">
                <?php if ($error): ?><div class="alert alert-danger"><?php echo e($error); ?></div><?php endif; ?>
                <form method="POST" action="product-edit.php?id=<?php echo $product_id; ?>" enctype="multipart/form-data">
                    <?php echo csrf_field(); ?>
                    <div class="row">
                        <div class="col-md-4 form-group"><label>Code <span class="text-danger">*</span></label><input type="text" name="code" id="product-code" class="form-control" required maxlength="100" value="<?php echo e($product['code']); ?>"><small class="text-muted">The barcode is generated from this code.</small></div>
                        <div class="col-md-8 form-group"><label>Product Name <span class="text-danger">*</span></label><input type="text" name="name" class="form-control" required value="<?php echo e($product['name']); ?>"></div>
                        <div class="col-md-4 form-group">
                            <label>Product Brand</label>
                            <?php $form_brand_scope = current_brand_scope(); ?>
                            <select name="brand" class="form-control" <?php echo $form_brand_scope !== '' ? 'disabled' : ''; ?>>
                                <option value="">— Select Brand —</option>
                                <?php foreach (($form_brand_scope !== '' ? [$form_brand_scope] : product_brand_options()) as $brand): ?>
                                    <option value="<?php echo e($brand); ?>" <?php echo $product['brand'] === $brand ? 'selected' : ''; ?>><?php echo e($brand); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <?php if ($form_brand_scope !== ''): ?>
                                <input type="hidden" name="brand" value="<?php echo e($form_brand_scope); ?>">
                                <small class="text-muted">Locked to your brand: <?php echo e(brand_label($form_brand_scope)); ?>.</small>
                            <?php endif; ?>
                        </div>
                        <div class="col-md-4 form-group">
                            <label>Main Category</label>
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
                        <div class="col-md-4 form-group">
                            <label>Barcode</label>
                            <input type="text" name="barcode" id="product-barcode" class="form-control" readonly value="<?php echo e($product['barcode'] ?: $product['code']); ?>">
                            <small class="text-muted">Filled automatically from the product code.</small>
                            <div id="barcode-preview" class="mt-2"></div>
                        </div>
                        <div class="col-md-4 form-group"><label>SKU</label><input type="text" name="sku" class="form-control" value="<?php echo e($product['sku']); ?>"></div>
                        <div class="col-md-4 form-group"><label>Purchase Price</label><input type="number" name="purchase_price" class="form-control" step="1" min="0" value="<?php echo e($product['purchase_price']); ?>"></div>
                        <div class="col-md-4 form-group"><label>Sale Price</label><input type="number" name="sale_price" class="form-control" step="1" min="0" value="<?php echo e($product['sale_price']); ?>"></div>
                        <div class="col-md-4 form-group"><label>Wholesale Price</label><input type="number" name="wholesale_price" class="form-control" step="1" min="0" value="<?php echo e($product['wholesale_price']); ?>" placeholder="Optional"><small class="text-muted">Price per unit for wholesale orders.</small></div>
                        <div class="col-md-4 form-group"><label>Wholesale Minimum Qty</label><input type="number" name="wholesale_min_qty" class="form-control" step="1" min="0" value="<?php echo e($product['wholesale_min_qty']); ?>" placeholder="Optional"><small class="text-muted">Quantity that qualifies for the wholesale price.</small></div>
                        <div class="col-md-4 form-group"><label>Tax Rate (%)</label><input type="number" name="tax_rate" class="form-control" step="1" min="0" value="<?php echo e($product['tax_rate']); ?>"></div>
                        <div class="col-md-4 form-group"><label>Min Stock Level</label><input type="number" name="min_stock_level" class="form-control" step="1" min="0" value="<?php echo e($product['min_stock_level']); ?>"></div>
                        <div class="col-md-4 form-group"><label>Date Added</label><input type="date" name="date_added" class="form-control" value="<?php echo e($product['date_added']); ?>"></div>
                        <div class="col-md-4 form-group"><label>Variant Size</label><input type="text" name="variant_size" class="form-control" value="<?php echo e($product['variant_size']); ?>"></div>
                        <div class="col-md-4 form-group"><label>Color</label><input type="text" name="variant_color" class="form-control" value="<?php echo e($product['variant_color']); ?>" placeholder="e.g. White, Sky Blue"></div>
                        <div class="col-md-12 form-group"><label>Description</label><textarea name="description" class="form-control" rows="2"><?php echo e($product['description']); ?></textarea></div>
                        <div class="col-md-12 form-group">
                            <label for="product-image">Product Image</label>
                            <?php $product_image = trim((string)($product['image'] ?? '')); ?>
                            <?php if ($product_image !== ''): ?>
                                <div class="mb-2"><img src="<?php echo e($product_image); ?>" alt="<?php echo e($product['name']); ?>" style="max-width:180px;max-height:180px;object-fit:contain;"></div>
                                <div class="custom-control custom-checkbox mb-2">
                                    <input type="checkbox" class="custom-control-input" name="remove_image" id="remove_image">
                                    <label class="custom-control-label" for="remove_image">Remove current image</label>
                                </div>
                            <?php endif; ?>
                            <input type="file" id="product-image" name="image" class="form-control-file" accept="image/jpeg,image/png,image/webp,image/gif">
                            <small class="form-text text-muted">Optional. JPG, PNG, WEBP or GIF, up to 5 MB. Uploading a new image replaces the current one.</small>
                        </div>
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
                    <div class="d-flex justify-content-between align-items-center mb-2">
                        <span>
                            <?php echo e(trim(($s['shop_name'] ?? '') . ' / ' . $s['warehouse_name'], ' /')); ?>
                            <?php if ((int)($s['is_godown'] ?? 0) === 1): ?>
                                <span class="badge badge-warning">Central Godown</span>
                            <?php endif; ?>
                        </span>
                        <div>
                            <span class="font-weight-semibold mr-2"><?php echo e(format_quantity($s['quantity'])); ?></span>
                            <?php if (can('manage_inventory') && !$product['is_service']): ?>
                                <button type="button" 
                                        class="btn btn-sm btn-light open-adjust-modal" 
                                        data-product-id="<?php echo $product_id; ?>"
                                        data-warehouse-id="<?php echo (int)$s['warehouse_id']; ?>"
                                        data-warehouse-name="<?php echo e($s['warehouse_name']); ?>"
                                        title="Adjust Stock">
                                    <i class="fa fa-sliders"></i>
                                </button>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
                <?php if (!$stock): ?><div class="text-muted small mb-2">No stock recorded.</div><?php endif; ?>
                <?php if (can('manage_inventory') && !$product['is_service'] && empty($stock)): ?>
                    <button type="button" 
                            class="btn btn-sm btn-outline-primary mt-2 open-adjust-modal" 
                            data-product-id="<?php echo $product_id; ?>"
                            data-warehouse-id="0">
                        <i class="fa fa-plus mr-1"></i> Adjust Stock
                    </button>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- Adjust Stock Modal -->
<div class="modal fade" id="adjustStockModal" tabindex="-1" role="dialog" aria-labelledby="adjustStockModalLabel" aria-hidden="true">
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            <form id="ajaxAdjustForm" method="POST" action="inventory-adjust.php">
    <div class="modal-header">
        <h5 class="modal-title"><i class="fa fa-sliders mr-1"></i> Adjust Stock</h5>
        <button type="button" class="close" data-dismiss="modal" aria-label="Close">
            <span aria-hidden="true">&times;</span>
        </button>
    </div>
    <div class="modal-body">
        <div id="adjustModalAlert" class="alert d-none"></div>
        
        <!-- Make sure product_id and warehouse_id are explicitly present -->
        <input type="hidden" name="product_id" id="modal_product_id" value="">
        <input type="hidden" name="warehouse_id" id="modal_warehouse_id" value="<?php echo isset($godown) ? (int)$godown['warehouse_id'] : ''; ?>">
        
        <div class="form-group" id="modal_product_name_group">
            <label class="font-weight-semibold">Product</label>
            <input type="text" id="modal_product_name" class="form-control-plaintext font-weight-bold" readonly>
        </div>

        <div class="form-group">
            <label>Quantity to Add <span class="text-danger">*</span></label>
            <input type="number" name="quantity" id="modal_quantity" class="form-control" step="0.01" min="0.01" required>
            <small class="text-muted" id="modal_unit_hint">Enter quantity to add to stock.</small>
        </div>

        <div class="form-group">
            <label>Notes</label>
            <textarea name="notes" class="form-control" rows="2" placeholder="e.g. Stock adjustment / count correction"></textarea>
        </div>
    </div>
    <div class="modal-footer">
        <button type="button" class="btn btn-secondary btn-sm" data-dismiss="modal">Cancel</button>
        <button type="submit" class="btn btn-primary btn-sm" id="saveAdjustBtn"><i class="fa fa-check mr-1"></i> Save Stock</button>
    </div>
</form>

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

        // Trigger modal popup
        $(document).on('click', '.open-adjust-modal', function (e) {
            e.preventDefault();
            var warehouseId = $(this).data('warehouse-id');
            if (warehouseId) {
                $('#modal_warehouse_id').val(warehouseId);
            }
            $('#adjustModalAlert').addClass('d-none').removeClass('alert-success alert-danger');
            $('#adjustStockModal').modal('show');
        });

        // Handle AJAX submission
        $('#ajaxAdjustForm').on('submit', function (e) {
            e.preventDefault();
            var $form = $(this);
            var $btn = $('#saveAdjustBtn');
            $btn.prop('disabled', true).html('<i class="fa fa-spinner fa-spin mr-1"></i> Saving...');

            $.ajax({
                type: 'POST',
                url: 'inventory-adjust.php',
                data: $form.serialize(),
                dataType: 'json',
                success: function (res) {
                    if (res.success) {
                        $('#adjustModalAlert').removeClass('d-none').alert().addClass('alert-success').text(res.message);
                        setTimeout(function () {
                            $('#adjustStockModal').modal('hide');
                            location.reload();
                        }, 700);
                    } else {
                        $('#adjustModalAlert').removeClass('d-none').removeClass('alert-success').addClass('alert-danger').text(res.message);
                        $btn.prop('disabled', false).html('<i class="fa fa-check mr-1"></i> Save Stock');
                    }
                },
                error: function () {
                    $('#adjustModalAlert').removeClass('d-none').addClass('alert-danger').text('Server communication error.');
                    $btn.prop('disabled', false).html('<i class="fa fa-check mr-1"></i> Save Stock');
                }
            });
        });
    });

    // Manual product code drives the barcode value and live preview.
    (function () {
        var codeInput = document.getElementById('product-code');
        var barcodeInput = document.getElementById('product-barcode');
        var preview = document.getElementById('barcode-preview');
        if (!codeInput || !barcodeInput) return;

        function syncBarcode() {
            var value = codeInput.value.trim();
            barcodeInput.value = value;
            if (!preview) return;
            if (value === '') {
                preview.innerHTML = '<span class="text-muted small">Barcode preview appears here.</span>';
                return;
            }
            var img = document.createElement('img');
            img.alt = 'Barcode preview';
            img.src = 'barcode-image.php?code=' + encodeURIComponent(value);
            img.style.maxWidth = '100%';
            img.style.height = 'auto';
            preview.innerHTML = '';
            preview.appendChild(img);
        }

        codeInput.addEventListener('input', syncBarcode);
        syncBarcode();
    })();
</script>

<?php require_once 'footer.php'; ?>