<?php
require_once 'header.php';
require_once 'auth_check.php';
require_permission('manage_shops');
$page_title = 'Shops & Stores';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'add_shop') {
        $name = trim($_POST['name'] ?? '');
        $code = strtoupper(trim($_POST['code'] ?? ''));
        $address = trim($_POST['address'] ?? '');
        $phone = trim($_POST['phone'] ?? '');

        if ($name === '') {
            flash_set('danger', 'Shop name is required.');
            redirect('shops.php');
        }
        if ($code === '') {
            $code = next_shop_code($current_user->tenant_id);
        }
        $code = preg_replace('/[^A-Z0-9_-]/', '', $code);
        if ($code === '') {
            flash_set('danger', 'Shop code is invalid.');
            redirect('shops.php');
        }
        $dup = fetch_one(
            "SELECT COUNT(*) c FROM shops WHERE tenant_id = ? AND code = ?",
            $current_user->tenant_id,
            $code
        );
        if ((int)($dup['c'] ?? 0) > 0) {
            flash_set('danger', 'That shop code is already in use.');
            redirect('shops.php');
        }

        $conn->begin_transaction();
        try {
            $id = db_insert('shops', [
                'tenant_id' => $current_user->tenant_id,
                'name' => $name,
                'code' => $code,
                'address' => $address !== '' ? $address : null,
                'phone' => $phone !== '' ? $phone : null,
                'is_active' => 1,
            ]);
            if (!$id) { throw new RuntimeException('Could not create the shop.'); }
            $loc_code = $code;
            $dup_loc = fetch_one("SELECT 1 FROM warehouses WHERE tenant_id = ? AND code = ?", $current_user->tenant_id, $loc_code);
            if ($dup_loc) { $loc_code = $loc_code . '-' . $id; }
            db_insert('warehouses', [
                'tenant_id' => $current_user->tenant_id,
                'shop_id' => $id,
                'name' => $name,
                'code' => $loc_code,
                'is_active' => 1,
                'location_type' => 'shop',
            ]);
            if (!tenant_admin_scope()) {
                db_insert('user_shops', [
                    'tenant_id' => $current_user->tenant_id,
                    'user_id' => $current_user->user_id,
                    'shop_id' => $id,
                ]);
            }
            $conn->commit();
        } catch (Throwable $e) {
            $conn->rollback();
            flash_set('danger', 'Could not create the shop: ' . $e->getMessage());
            redirect('shops.php');
        }

        // A shop manager who creates a shop can immediately work in it.
        if (!tenant_admin_scope()) {
            db_insert('user_shops', [
                'tenant_id' => $current_user->tenant_id,
                'user_id' => $current_user->user_id,
                'shop_id' => $id,
            ]);
        }
        audit_log('create', 'shop', $id, 'Shop ' . $name . ' (' . $code . ') created');
        flash_set('success', 'Shop created successfully.');
        redirect('shops.php');
    }

    if ($action === 'edit_shop') {
        $shop_id = (int)($_POST['shop_id'] ?? 0);
        $shop = assert_shop_access($shop_id);
        $name = trim($_POST['name'] ?? '');
        $code = strtoupper(trim($_POST['code'] ?? ''));
        $address = trim($_POST['address'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        $is_active = isset($_POST['is_active']) ? 1 : 0;

        if ($name === '') {
            flash_set('danger', 'Shop name is required.');
            redirect('shops.php');
        }
        $code = preg_replace('/[^A-Z0-9_-]/', '', $code);
        if ($code === '') {
            $code = $shop['code'];
        }
        $dup = fetch_one(
            "SELECT COUNT(*) c FROM shops WHERE tenant_id = ? AND code = ? AND shop_id != ?",
            $current_user->tenant_id,
            $code,
            $shop_id
        );
        if ((int)($dup['c'] ?? 0) > 0) {
            flash_set('danger', 'That shop code is already in use.');
            redirect('shops.php');
        }

        $stmt = $conn->prepare(
            "UPDATE shops SET name = ?, code = ?, address = ?, phone = ?, is_active = ?
             WHERE shop_id = ? AND tenant_id = ?"
        );
        $address_db = $address !== '' ? $address : null;
        $phone_db = $phone !== '' ? $phone : null;
        $stmt->bind_param('ssssiii', $name, $code, $address_db, $phone_db, $is_active, $shop_id, $current_user->tenant_id);
        $stmt->execute();
        $stmt->close();

        audit_log('update', 'shop', $shop_id, 'Shop ' . $name . ' updated');
        flash_set('success', 'Shop updated successfully.');
        redirect('shops.php');
    }
}

$shops = accessible_shops(null, true);
$shop_ids = array_map(fn($s) => (int)$s['shop_id'], $shops);
$shop_stats = [];
if ($shop_ids) {
    $placeholders = implode(',', array_fill(0, count($shop_ids), '?'));
    $rows = fetch_all(
        "SELECT s.shop_id,
                (SELECT COUNT(*) FROM warehouses w WHERE w.shop_id = s.shop_id AND w.tenant_id = s.tenant_id) store_count,
                (SELECT COUNT(*) FROM user_shops us WHERE us.shop_id = s.shop_id AND us.tenant_id = s.tenant_id) user_count
         FROM shops s WHERE s.tenant_id = ? AND s.shop_id IN ({$placeholders})",
        $current_user->tenant_id,
        ...$shop_ids
    );
    foreach ($rows as $row) {
        $shop_stats[(int)$row['shop_id']] = $row;
    }
}

$selected_shop_id = (int)($_GET["shop"] ?? 0);
$accessible_shop_ids = array_map(fn($s) => (int)$s["shop_id"], $shops);
if ($selected_shop_id > 0 && !in_array($selected_shop_id, $accessible_shop_ids, true)) {
    $selected_shop_id = 0;
}
if ($selected_shop_id <= 0) {
    $selected_shop_id = $accessible_shop_ids[0] ?? 0;
}
$selected_shop_summary = $selected_shop_id ? shop_stock_summary($selected_shop_id, (int)$current_user->tenant_id) : ["products" => 0, "qty" => 0.0, "low" => 0, "out" => 0];

require_once 'navbar.php';
require_once 'sidebar.php';
?>

<div class="page-head">
    <div>
        <h1 class="page-title">Shops &amp; Stores</h1>
        <div class="page-sub">A shop is a business location. Each shop can contain one or more stores.</div>
    </div>
    <div>
        <a href="stores.php" class="btn btn-light"><i class="fa fa-building mr-1"></i> Stores</a>
    </div>
</div>

<ul class="nav page-tabs">
    <li class="nav-item"><a class="nav-link active" href="shops.php"><i class="fa fa-building mr-1"></i> Shops</a></li>
    <li class="nav-item"><a class="nav-link" href="stores.php"><i class="fa fa-cubes mr-1"></i> Stores</a></li>
    <?php if (can('manage_users') && can('manage_shops')): ?>
    <li class="nav-item"><a class="nav-link" href="user-shops.php"><i class="fa fa-user-lock mr-1"></i> User Access</a></li>
    <?php endif; ?>
</ul>

<?php if ($f = flash_get()): ?>
    <div class="alert alert-<?php echo e($f['type']); ?>"><?php echo e($f['message']); ?></div>
<?php endif; ?>

<?php if (tenant_admin_scope() && count($shops) > 1): ?>
<form method="GET" action="shops.php" class="form-inline mb-3">
    <label class="mr-2">Shop</label>
    <select name="shop" class="form-control form-control-sm mr-2" onchange="this.form.submit()">
        <?php foreach ($shops as $sh): ?>
            <option value="<?php echo (int)$sh["shop_id"]; ?>" <?php echo (int)$sh["shop_id"] === (int)$selected_shop_id ? "selected" : ""; ?>><?php echo e($sh["name"]); ?></option>
        <?php endforeach; ?>
    </select>
</form>
<?php endif; ?>

<div class="row mb-3">
    <div class="col-md-3 col-sm-6 mb-2">
        <div class="card"><div class="card-body">
            <div class="text-muted small">Total Products Available</div>
            <div class="h4 mb-0"><?php echo (int)$selected_shop_summary["products"]; ?></div>
        </div></div>
    </div>
    <div class="col-md-3 col-sm-6 mb-2">
        <div class="card"><div class="card-body">
            <div class="text-muted small">Total Stock Quantity</div>
            <div class="h4 mb-0"><?php echo e(format_quantity($selected_shop_summary["qty"])); ?></div>
        </div></div>
    </div>
    <div class="col-md-3 col-sm-6 mb-2">
        <div class="card"><div class="card-body">
            <div class="text-muted small">Low Stock Items</div>
            <div class="h4 mb-0"><?php echo (int)$selected_shop_summary["low"]; ?></div>
        </div></div>
    </div>
    <div class="col-md-3 col-sm-6 mb-2">
        <div class="card"><div class="card-body">
            <div class="text-muted small">Out of Stock Products</div>
            <div class="h4 mb-0"><?php echo (int)$selected_shop_summary["out"]; ?></div>
        </div></div>
    </div>
</div>

<div class="card">
    <div class="card-header d-flex justify-content-between align-items-center">
        <h5 class="card-title">Shops</h5>
        <div class="input-group dt-search">
            <div class="input-group-prepend"><span class="input-group-text"><i class="fa fa-search"></i></span></div>
            <input type="text" class="form-control dt-search-input" data-scope="shops" placeholder="Search shops...">
        </div>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover data-table">
                <thead>
                    <tr>
                        <th>Shop</th><th>Code</th><th>Address</th><th>Phone</th><th>Stores</th><th>Users</th><th>Status</th><th class="text-right">Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($shops as $shop): ?>
                    <?php $stats = $shop_stats[(int)$shop['shop_id']] ?? ['store_count' => 0, 'user_count' => 0]; ?>
                    <tr>
                        <td class="font-weight-semibold"><?php echo e($shop['name']); ?></td>
                        <td><span class="badge badge-secondary"><?php echo e($shop['code']); ?></span></td>
                        <td><?php echo e($shop['address'] ?: '—'); ?></td>
                        <td><?php echo e($shop['phone'] ?: '—'); ?></td>
                        <td><?php echo (int)$stats['store_count']; ?></td>
                        <td><?php echo (int)$stats['user_count']; ?></td>
                        <td>
                            <?php if ((int)$shop['is_active']): ?>
                                <span class="badge badge-success">Active</span>
                            <?php else: ?>
                                <span class="badge badge-secondary">Inactive</span>
                            <?php endif; ?>
                        </td>
                        <td class="text-right">
                            <a href="stores.php?shop=<?php echo (int)$shop['shop_id']; ?>" class="btn btn-action btn-light" title="Stores"><i class="fa fa-cubes"></i></a>
                            <button type="button" class="btn btn-action btn-light" title="Edit" data-toggle="modal" data-target="#editShopModal<?php echo (int)$shop['shop_id']; ?>"><i class="fa fa-pencil"></i></button>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$shops): ?>
                    <tr><td colspan="8" class="text-center text-muted py-4">No shops yet.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<div class="modal fade" id="addShopModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Add Shop</h5>
                <button type="button" class="close" data-dismiss="modal">&times;</button>
            </div>
            <form method="POST" action="shops.php">
                <div class="modal-body">
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="action" value="add_shop">
                    <div class="form-group"><label>Shop Name <span class="text-danger">*</span></label><input type="text" name="name" class="form-control" required></div>
                    <div class="form-group"><label>Shop Code</label><input type="text" name="code" class="form-control" placeholder="Auto-generated (SHOP-###)"><small class="text-muted">Letters, numbers, dashes and underscores only.</small></div>
                    <div class="form-group"><label>Address</label><textarea name="address" class="form-control" rows="2"></textarea></div>
                    <div class="form-group"><label>Phone</label><input type="text" name="phone" class="form-control"></div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-light" data-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Create Shop</button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php foreach ($shops as $shop): ?>
<?php $stats = $shop_stats[(int)$shop['shop_id']] ?? ['store_count' => 0, 'user_count' => 0]; ?>
<div class="modal fade" id="editShopModal<?php echo (int)$shop['shop_id']; ?>" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Edit Shop</h5>
                <button type="button" class="close" data-dismiss="modal">&times;</button>
            </div>
            <form method="POST" action="shops.php" onsubmit="return confirmShopDeactivation(this, <?php echo (int)$stats['store_count']; ?>, <?php echo (int)$stats['user_count']; ?>);">
                <div class="modal-body">
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="action" value="edit_shop">
                    <input type="hidden" name="shop_id" value="<?php echo (int)$shop['shop_id']; ?>">
                    <div class="form-group"><label>Shop Name <span class="text-danger">*</span></label><input type="text" name="name" class="form-control" required value="<?php echo e($shop['name']); ?>"></div>
                    <div class="form-group"><label>Shop Code</label><input type="text" name="code" class="form-control" value="<?php echo e($shop['code']); ?>"></div>
                    <div class="form-group"><label>Address</label><textarea name="address" class="form-control" rows="2"><?php echo e($shop['address']); ?></textarea></div>
                    <div class="form-group"><label>Phone</label><input type="text" name="phone" class="form-control" value="<?php echo e($shop['phone']); ?>"></div>
                    <div class="custom-control custom-switch">
                        <input type="checkbox" class="custom-control-input" name="is_active" id="shop_active_<?php echo (int)$shop['shop_id']; ?>" <?php echo (int)$shop['is_active'] ? 'checked' : ''; ?>>
                        <label class="custom-control-label" for="shop_active_<?php echo (int)$shop['shop_id']; ?>">Active</label>
                    </div>
                    <small class="text-muted d-block mt-2">Deactivating a shop hides its stores from new sales and invoices, but keeps all history.</small>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-light" data-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Save Changes</button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endforeach; ?>

<script>
    function confirmShopDeactivation(form, storeCount, userCount) {
        if (!form.is_active.checked && (storeCount > 0 || userCount > 0)) {
            return confirm('This shop still has ' + storeCount + ' store(s) and ' + userCount +
                ' user assignment(s). Deactivating it hides the shop from new sales and invoices but keeps history. Continue?');
        }
        return true;
    }
</script>

<?php require_once 'footer.php'; ?>
