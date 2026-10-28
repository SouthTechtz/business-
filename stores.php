<?php
require_once 'header.php';
require_once 'auth_check.php';
require_permission('manage_shops');
$page_title = 'Stores';
$can_godown = can('manage_godown');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'add_store') {
        $shop_id = (int)($_POST['shop_id'] ?? 0);
        $shop = assert_shop_access($shop_id, false);
        $name = trim($_POST['name'] ?? '');
        $code = strtoupper(trim($_POST['code'] ?? ''));

        if ($name === '') {
            flash_set('danger', 'Store name is required.');
            redirect('stores.php');
        }
        if ($code === '') {
            $code = next_store_code($current_user->tenant_id);
        }
        $code = preg_replace('/[^A-Z0-9_-]/', '', $code);
        if ($code === '') {
            flash_set('danger', 'Store code is invalid.');
            redirect('stores.php');
        }
        $dup = fetch_one(
            "SELECT COUNT(*) c FROM warehouses WHERE tenant_id = ? AND code = ?",
            $current_user->tenant_id,
            $code
        );
        if ((int)($dup['c'] ?? 0) > 0) {
            flash_set('danger', 'That store code is already in use.');
            redirect('stores.php');
        }

        $id = db_insert('warehouses', [
            'tenant_id' => $current_user->tenant_id,
            'shop_id' => (int)$shop['shop_id'],
            'name' => $name,
            'code' => $code,
            'is_active' => 1,
        ]);
        if (!$id) {
            flash_set('danger', 'Could not create the store.');
            redirect('stores.php');
        }
        audit_log('create', 'warehouse', $id, 'Store ' . $name . ' (' . $code . ') created');
        flash_set('success', 'Store created successfully.');
        redirect('stores.php?shop=' . (int)$shop['shop_id']);
    }

    if ($action === 'edit_store') {
        $warehouse_id = (int)($_POST['warehouse_id'] ?? 0);
        $store = find_accessible_store($warehouse_id, true, $can_godown);
        if (!$store) {
            http_response_code(403);
            die('You do not have access to this store.');
        }
        $shop_id = (int)($_POST['shop_id'] ?? 0);
        $name = trim($_POST['name'] ?? '');
        $code = strtoupper(trim($_POST['code'] ?? ''));
        $is_active = isset($_POST['is_active']) ? 1 : 0;

        if ((int)($store['is_godown'] ?? 0) === 1) {
            if ($shop_id > 0 && $shop_id !== (int)$store['shop_id']) {
                flash_set('danger', 'The shop for the central Godown cannot be changed here. Remove the Godown designation first if the shop must change.');
                redirect('stores.php');
            }
            // Shop and active state are managed from the Central Godown page.
            $shop_id = (int)$store['shop_id'];
            $is_active = 1;
        }
        $shop = assert_shop_access($shop_id, true);

        if ($name === '') {
            flash_set('danger', 'Store name is required.');
            redirect('stores.php');
        }
        $code = preg_replace('/[^A-Z0-9_-]/', '', $code);
        if ($code === '') {
            $code = $store['code'];
        }
        $dup = fetch_one(
            "SELECT COUNT(*) c FROM warehouses WHERE tenant_id = ? AND code = ? AND warehouse_id != ?",
            $current_user->tenant_id,
            $code,
            $warehouse_id
        );
        if ((int)($dup['c'] ?? 0) > 0) {
            flash_set('danger', 'That store code is already in use.');
            redirect('stores.php');
        }

        $stmt = $conn->prepare(
            "UPDATE warehouses SET shop_id = ?, name = ?, code = ?, is_active = ?
             WHERE warehouse_id = ? AND tenant_id = ?"
        );
        $stmt->bind_param('issiii', $shop_id, $name, $code, $is_active, $warehouse_id, $current_user->tenant_id);
        $stmt->execute();
        $stmt->close();

        audit_log('update', 'warehouse', $warehouse_id, 'Store ' . $name . ' updated');
        flash_set('success', 'Store updated successfully.');
        redirect('stores.php?shop=' . $shop_id);
    }
}

$shops = accessible_shops(null, true);
$active_shops = array_values(array_filter($shops, fn($s) => (int)$s['is_active'] === 1));

$filter_shop = (int)($_GET['shop'] ?? 0);
if ($filter_shop > 0 && !shop_is_accessible($filter_shop, true)) {
    $filter_shop = 0;
}
$stores = accessible_stores($filter_shop > 0 ? $filter_shop : null, true, null, $can_godown);

$store_stats = [];
foreach ($stores as $store) {
    $stat = fetch_one(
        "SELECT
            (SELECT COUNT(*) FROM inventory_stock WHERE tenant_id = ? AND warehouse_id = ?) rows_count,
            (SELECT COALESCE(SUM(quantity), 0) FROM inventory_stock WHERE tenant_id = ? AND warehouse_id = ?) qty,
            (SELECT COUNT(*) FROM pos_orders WHERE tenant_id = ? AND warehouse_id = ?) order_count,
            (SELECT COUNT(*) FROM invoices WHERE tenant_id = ? AND warehouse_id = ?) invoice_count,
            (SELECT COUNT(*) FROM pos_sessions WHERE tenant_id = ? AND warehouse_id = ? AND is_closed = 0) open_session_count",
        $current_user->tenant_id, $store['warehouse_id'],
        $current_user->tenant_id, $store['warehouse_id'],
        $current_user->tenant_id, $store['warehouse_id'],
        $current_user->tenant_id, $store['warehouse_id'],
        $current_user->tenant_id, $store['warehouse_id']
    );
    $store_stats[(int)$store['warehouse_id']] = $stat ?: [
        'rows_count' => 0, 'qty' => 0, 'order_count' => 0, 'invoice_count' => 0, 'open_session_count' => 0
    ];
}

require_once 'navbar.php';
require_once 'sidebar.php';
?>

<div class="page-head">
    <div>
        <h1 class="page-title">Stores</h1>
        <div class="page-sub">Stores hold stock and belong to a shop. A store can be designated as the tenant's single central Godown.</div>
    </div>
    <div>
        <?php if ($can_godown): ?>
            <a href="godown.php" class="btn btn-outline-primary"><i class="fa fa-archive mr-1"></i> Central Godown</a>
        <?php endif; ?>
        <button type="button" class="btn btn-primary" data-toggle="modal" data-target="#addStoreModal" <?php echo $active_shops ? '' : 'disabled'; ?>><i class="fa fa-plus mr-1"></i> Add Store</button>
        <a href="shops.php" class="btn btn-light"><i class="fa fa-building mr-1"></i> Shops</a>
    </div>
</div>

<ul class="nav page-tabs">
    <li class="nav-item"><a class="nav-link" href="shops.php"><i class="fa fa-building mr-1"></i> Shops</a></li>
    <li class="nav-item"><a class="nav-link active" href="stores.php"><i class="fa fa-cubes mr-1"></i> Stores</a></li>
    <?php if (can('manage_users') && can('manage_shops')): ?>
    <li class="nav-item"><a class="nav-link" href="user-shops.php"><i class="fa fa-user-lock mr-1"></i> User Access</a></li>
    <?php endif; ?>
</ul>

<?php if ($f = flash_get()): ?>
    <div class="alert alert-<?php echo e($f['type']); ?>"><?php echo e($f['message']); ?></div>
<?php endif; ?>

<div class="card">
    <div class="card-header d-flex justify-content-between align-items-center">
        <h5 class="card-title">Stores</h5>
        <form method="GET" action="stores.php" class="form-inline">
            <select name="shop" class="form-control form-control-sm mr-2" onchange="this.form.submit()">
                <option value="0">All accessible shops</option>
                <?php foreach ($shops as $shop): ?>
                    <option value="<?php echo (int)$shop['shop_id']; ?>" <?php echo $filter_shop === (int)$shop['shop_id'] ? 'selected' : ''; ?>><?php echo e($shop['name']); ?><?php echo (int)$shop['is_active'] ? '' : ' (inactive)'; ?></option>
                <?php endforeach; ?>
            </select>
            <button type="submit" class="btn btn-sm btn-light">Filter</button>
        </form>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover data-table">
                <thead>
                    <tr><th>Store</th><th>Shop</th><th>Code</th><th>Stock Lines</th><th>Total Qty</th><th>Sales/Invoices</th><th>Status</th><th class="text-right">Actions</th></tr>
                </thead>
                <tbody>
                <?php foreach ($stores as $store): ?>
                    <?php $stat = $store_stats[(int)$store['warehouse_id']] ?? ['rows_count' => 0, 'qty' => 0]; ?>
                    <tr>
                        <td class="font-weight-semibold">
                            <?php echo e($store['name']); ?>
                            <?php if ((int)($store['is_godown'] ?? 0) === 1): ?>
                                <span class="badge badge-warning ml-1">Central Godown</span>
                            <?php endif; ?>
                        </td>
                        <td><?php echo e($store['shop_name']); ?><?php echo (int)$store['shop_is_active'] ? '' : ' <span class="badge badge-secondary">inactive</span>'; ?></td>
                        <td><span class="badge badge-secondary"><?php echo e($store['code']); ?></span></td>
                        <td><?php echo (int)$stat['rows_count']; ?></td>
                        <td><?php echo e(number_format((float)$stat['qty'], 2)); ?></td>
                        <td><?php echo (int)$stat['order_count']; ?> / <?php echo (int)$stat['invoice_count']; ?></td>
                        <td>
                            <?php if ((int)$store['is_active']): ?>
                                <span class="badge badge-success">Active</span>
                            <?php else: ?>
                                <span class="badge badge-secondary">Inactive</span>
                            <?php endif; ?>
                        </td>
                        <td class="text-right">
                            <?php if ((int)($store['is_godown'] ?? 0) === 1): ?>
                                <a href="godown.php" class="btn btn-action btn-light" title="Manage central Godown"><i class="fa fa-archive"></i></a>
                            <?php endif; ?>
                            <button type="button" class="btn btn-action btn-light" title="Edit" data-toggle="modal" data-target="#editStoreModal<?php echo (int)$store['warehouse_id']; ?>"><i class="fa fa-pencil"></i></button>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$stores): ?>
                    <tr><td colspan="8" class="text-center text-muted py-4">No stores yet.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<div class="modal fade" id="addStoreModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Add Store</h5>
                <button type="button" class="close" data-dismiss="modal">&times;</button>
            </div>
            <form method="POST" action="stores.php">
                <div class="modal-body">
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="action" value="add_store">
                    <div class="form-group">
                        <label>Shop <span class="text-danger">*</span></label>
                        <select name="shop_id" class="form-control" required>
                            <option value="">— Select Shop —</option>
                            <?php foreach ($active_shops as $shop): ?>
                                <option value="<?php echo (int)$shop['shop_id']; ?>" <?php echo $filter_shop === (int)$shop['shop_id'] ? 'selected' : ''; ?>><?php echo e($shop['name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group"><label>Store Name <span class="text-danger">*</span></label><input type="text" name="name" class="form-control" required></div>
                    <div class="form-group"><label>Store Code</label><input type="text" name="code" class="form-control" placeholder="Auto-generated (STORE-###)"><small class="text-muted">Letters, numbers, dashes and underscores only.</small></div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-light" data-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Create Store</button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php foreach ($stores as $store): ?>
<?php $stat = $store_stats[(int)$store['warehouse_id']] ?? ['rows_count' => 0, 'qty' => 0, 'order_count' => 0, 'invoice_count' => 0, 'open_session_count' => 0]; ?>
<div class="modal fade" id="editStoreModal<?php echo (int)$store['warehouse_id']; ?>" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Edit Store</h5>
                <button type="button" class="close" data-dismiss="modal">&times;</button>
            </div>
            <form method="POST" action="stores.php" onsubmit="return confirmStoreDeactivation(this, <?php echo (int)$stat['rows_count']; ?>, <?php echo (float)$stat['qty']; ?>, <?php echo (int)$stat['order_count']; ?>, <?php echo (int)$stat['invoice_count']; ?>, <?php echo (int)$stat['open_session_count']; ?>);">
                <div class="modal-body">
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="action" value="edit_store">
                    <input type="hidden" name="warehouse_id" value="<?php echo (int)$store['warehouse_id']; ?>">
                    <?php $is_godown = (int)($store['is_godown'] ?? 0) === 1; ?>
                    <?php if ($is_godown): ?>
                        <div class="alert alert-warning py-2">
                            <i class="fa fa-archive mr-1"></i> This is the central Godown. Its shop and active state are managed from the
                            <a href="godown.php" class="alert-link">Central Godown page</a>.
                        </div>
                    <?php endif; ?>
                    <div class="form-group">
                        <label>Shop <span class="text-danger">*</span></label>
                        <select name="shop_id" class="form-control" required <?php echo $is_godown ? 'disabled' : ''; ?>>
                            <?php foreach ($shops as $shop): ?>
                                <option value="<?php echo (int)$shop['shop_id']; ?>" <?php echo (int)$store['shop_id'] === (int)$shop['shop_id'] ? 'selected' : ''; ?>><?php echo e($shop['name']); ?><?php echo (int)$shop['is_active'] ? '' : ' (inactive)'; ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group"><label>Store Name <span class="text-danger">*</span></label><input type="text" name="name" class="form-control" required value="<?php echo e($store['name']); ?>"></div>
                    <div class="form-group"><label>Store Code</label><input type="text" name="code" class="form-control" value="<?php echo e($store['code']); ?>"></div>
                    <div class="custom-control custom-switch">
                        <input type="checkbox" class="custom-control-input" name="is_active" id="store_active_<?php echo (int)$store['warehouse_id']; ?>" <?php echo (int)$store['is_active'] ? 'checked' : ''; ?> <?php echo $is_godown ? 'disabled' : ''; ?>>
                        <label class="custom-control-label" for="store_active_<?php echo (int)$store['warehouse_id']; ?>">Active</label>
                    </div>
                    <small class="text-muted d-block mt-2">Deactivating a store hides it from new sales and invoices. Historical transactions stay attributed to it.</small>
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
    function confirmStoreDeactivation(form, stockRows, stockQty, orderCount, invoiceCount, openSessions) {
        if (!form.is_active.checked && (stockRows > 0 || stockQty > 0 || orderCount > 0 || invoiceCount > 0 || openSessions > 0)) {
            return confirm('This store has stock/history dependencies: ' + stockRows + ' stock line(s), ' +
                stockQty + ' total quantity, ' + orderCount + ' sale(s), ' + invoiceCount +
                ' invoice(s), and ' + openSessions + ' open POS session(s). Deactivating it hides the store from new sales and invoices but keeps all history. Continue?');
        }
        return true;
    }
</script>

<?php require_once 'footer.php'; ?>
