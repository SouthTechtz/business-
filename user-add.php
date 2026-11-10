<?php
require_once 'header.php';
require_once 'auth_check.php';
require_permission('manage_users');
$page_title = 'Add User';

$error = '';
$can_assign_locations = can('manage_users');
$brand_scope_options = product_brand_options();
$selected_brand_scope = trim((string)($_POST['brand_scope'] ?? ''));
if ($selected_brand_scope !== '' && !in_array($selected_brand_scope, $brand_scope_options, true)) {
    $selected_brand_scope = '';
}
$roles = fetch_all("SELECT * FROM roles WHERE tenant_id = ? ORDER BY name", $current_user->tenant_id);
$all_role_ids = array_map('intval', array_column($roles, 'role_id'));
$role_permissions = role_permissions_for_ids($all_role_ids, (int)$current_user->tenant_id);
$selected_role_ids = array_map('intval', (array)($_POST['roles'] ?? []));
$selected_role_ids = array_values(array_intersect($selected_role_ids, array_map('intval', array_column($roles, 'role_id'))));
$selected_permissions = [];
foreach ($selected_role_ids as $selected_role_id) {
    $selected_permissions = array_merge($selected_permissions, $role_permissions[$selected_role_id] ?? []);
}
$selected_permissions = array_values(array_unique($selected_permissions));
$can_assign_shop_locations = (bool)array_intersect($selected_permissions, ['manage_shops', 'manage_inventory', 'manage_pos']);
$can_assign_store_locations = (bool)array_intersect($selected_permissions, ['manage_shops', 'manage_inventory']);
$can_assign_godown_locations = (bool)array_intersect(
    $selected_permissions,
    ['manage_godown', 'view_godown', 'approve_godown_transfers', 'dispatch_godown_transfers', 'receive_godown_transfers']
);
$shops = $can_assign_locations
    ? fetch_all("SELECT * FROM shops WHERE tenant_id = ? AND is_active = 1 ORDER BY name", $current_user->tenant_id)
    : [];
$stores = $can_assign_locations
    ? fetch_all(
        "SELECT w.*, s.name shop_name FROM warehouses w
         JOIN shops s ON s.shop_id = w.shop_id AND s.tenant_id = w.tenant_id
         WHERE w.tenant_id = ? AND w.location_type = 'store' AND w.is_active = 1 AND s.is_active = 1
         ORDER BY s.name, w.name",
        $current_user->tenant_id
    )
    : [];
$godowns = $can_assign_locations
    ? fetch_all("SELECT * FROM warehouses WHERE tenant_id = ? AND location_type = 'godown' AND is_active = 1 ORDER BY name", $current_user->tenant_id)
    : [];
$selected_shop_ids = array_map('intval', (array)($_POST['shops'] ?? []));
$selected_store_ids = array_map('intval', (array)($_POST['stores'] ?? []));
$selected_godown_ids = array_map('intval', (array)($_POST['godowns'] ?? []));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $first_name = trim($_POST['first_name'] ?? '');
    $last_name = trim($_POST['last_name'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $brand_scope = trim($_POST['brand_scope'] ?? '');
    if ($brand_scope !== '' && !in_array($brand_scope, $brand_scope_options, true)) {
        $brand_scope = '';
    }
    $valid_role_ids = array_map('intval', array_column($roles, 'role_id'));
    $role_ids = array_values(array_intersect($selected_role_ids, $valid_role_ids));
    $selected_role_permissions = role_permissions_for_ids($role_ids, (int)$current_user->tenant_id);
    $selected_permissions = array_values(array_unique(array_merge(...array_values($selected_role_permissions ?: [[]]))));
    $can_assign_shop_locations = (bool)array_intersect($selected_permissions, ['manage_shops', 'manage_inventory', 'manage_pos']);
    $can_assign_store_locations = (bool)array_intersect($selected_permissions, ['manage_shops', 'manage_inventory']);
    $can_assign_godown_locations = (bool)array_intersect(
        $selected_permissions,
        ['manage_godown', 'view_godown', 'approve_godown_transfers', 'dispatch_godown_transfers', 'receive_godown_transfers']
    );
    $shop_ids = $can_assign_locations && $can_assign_shop_locations
        ? array_values(array_intersect($selected_shop_ids, array_map(fn($s) => (int)$s['shop_id'], $shops)))
        : [];
    $store_ids = $can_assign_locations && $can_assign_store_locations
        ? array_values(array_intersect($selected_store_ids, array_map(fn($s) => (int)$s['warehouse_id'], $stores)))
        : [];
    $godown_ids = $can_assign_locations && $can_assign_godown_locations
        ? array_values(array_intersect($selected_godown_ids, array_map(fn($g) => (int)$g['warehouse_id'], $godowns)))
        : [];

    if ($username === '' || $email === '' || $password === '' || $first_name === '' || $last_name === '') {
        $error = 'All required fields must be filled.';
    } elseif (strlen($password) < 6) {
        $error = 'Password must be at least 6 characters long.';
    } elseif (
        !$role_ids
        || (
            !$current_user->hasPermission('manage_settings')
            && ($can_assign_shop_locations || $can_assign_store_locations || $can_assign_godown_locations)
            && !$shop_ids && !$store_ids && !$godown_ids
        )
    ) {
        $error = !$role_ids ? 'Select at least one valid role.' : 'Assign at least one active location allowed by the selected role.';
    } else {
        $dup = fetch_one(
            "SELECT COUNT(*) c FROM users WHERE tenant_id = ? AND (username = ? OR email = ?)",
            $current_user->tenant_id, $username, $email
        );
        if (($dup['c'] ?? 0) > 0) {
            $error = 'Username or email already exists for this account.';
        } else {
            $conn->begin_transaction();
            try {
                $hash = password_hash($password, PASSWORD_DEFAULT);
                $id = db_insert('users', [
                    'tenant_id'     => $current_user->tenant_id,
                    'username'      => $username,
                    'email'         => $email,
                    'password_hash' => $hash,
                    'first_name'    => $first_name,
                    'last_name'     => $last_name,
                    'phone'         => $phone,
                    'brand_scope'   => $brand_scope !== '' ? $brand_scope : null,
                    'is_active'     => 1,
                ]);
                if (!$id) throw new RuntimeException('Could not create the user.');
                foreach ($role_ids as $rid) {
                    db_insert('user_roles', [
                        'user_id'   => $id,
                        'role_id'   => $rid,
                        'tenant_id' => $current_user->tenant_id,
                    ]);
                }
                foreach ($shop_ids as $shop_id) {
                    db_insert('user_shops', [
                        'tenant_id' => $current_user->tenant_id,
                        'user_id'   => $id,
                        'shop_id'   => $shop_id,
                    ]);
                    $shop_warehouse_id = shop_inventory_warehouse_id($shop_id, (int)$current_user->tenant_id);
                    if ($shop_warehouse_id) {
                        db_insert('user_inventory_locations', [
                            'tenant_id' => $current_user->tenant_id,
                            'user_id' => $id,
                            'location_id' => $shop_warehouse_id,
                        ]);
                    }
                }
                foreach (array_merge($store_ids, $godown_ids) as $location_id) {
                    db_insert('user_inventory_locations', [
                        'tenant_id' => $current_user->tenant_id,
                        'user_id' => $id,
                        'location_id' => $location_id,
                    ]);
                }
                foreach ($godown_ids as $godown_id) {
                    db_insert('user_godowns', [
                        'tenant_id' => $current_user->tenant_id,
                        'user_id' => $id,
                        'warehouse_id' => $godown_id,
                    ]);
                }
                $conn->commit();
            } catch (Throwable $ex) {
                $conn->rollback();
                $error = 'Could not create the user: ' . $ex->getMessage();
            }
            if (!$error) {
                audit_log('create', 'user', $id, 'User ' . $username . ' created');
                flash_set('success', 'User created successfully.');
                redirect('users.php');
            }
        }
    }
}

require_once 'navbar.php';
require_once 'sidebar.php';
?>

<div class="page-head">
    <div>
        <h1 class="page-title">Add User</h1>
        <div class="page-sub">Create a new staff account.</div>
    </div>
    <a href="users.php" class="btn btn-light"><i class="fa fa-arrow-left mr-1"></i> Back to Users</a>
</div>

<ul class="nav page-tabs">
    <li class="nav-item"><a class="nav-link active" href="users.php"><i class="fa fa-users mr-1"></i> Users</a></li>
    <li class="nav-item"><a class="nav-link" href="permissions.php"><i class="fa fa-shield mr-1"></i> Roles &amp; Permissions</a></li>
</ul>

<div class="row">
    <div class="col-md-8">
        <div class="card">
            <div class="card-body">
                <?php if ($error): ?><div class="alert alert-danger"><?php echo e($error); ?></div><?php endif; ?>
                <form method="POST" action="user-add.php">
                    <div class="row">
                        <div class="col-md-6 form-group">
                            <label>First Name <span class="text-danger">*</span></label>
                            <input type="text" name="first_name" class="form-control" required value="<?php echo e($_POST['first_name'] ?? ''); ?>">
                        </div>
                        <div class="col-md-6 form-group">
                            <label>Last Name <span class="text-danger">*</span></label>
                            <input type="text" name="last_name" class="form-control" required value="<?php echo e($_POST['last_name'] ?? ''); ?>">
                        </div>
                        <div class="col-md-6 form-group">
                            <label>Username <span class="text-danger">*</span></label>
                            <input type="text" name="username" class="form-control" required value="<?php echo e($_POST['username'] ?? ''); ?>">
                        </div>
                        <div class="col-md-6 form-group">
                            <label>Email <span class="text-danger">*</span></label>
                            <input type="email" name="email" class="form-control" required value="<?php echo e($_POST['email'] ?? ''); ?>">
                        </div>
                        <div class="col-md-6 form-group">
                            <label>Password <span class="text-danger">*</span></label>
                            <input type="password" name="password" class="form-control" required minlength="6">
                            <small class="text-muted">At least 6 characters.</small>
                        </div>
                        <div class="col-md-6 form-group">
                            <label>Phone</label>
                            <input type="text" name="phone" class="form-control" value="<?php echo e($_POST['phone'] ?? ''); ?>">
                        </div>
                        <div class="col-md-6 form-group">
                            <label>Brand Scope</label>
                            <select name="brand_scope" class="form-control">
                                <option value="">— No brand restriction —</option>
                                <?php foreach ($brand_scope_options as $brand_option): ?>
                                    <option value="<?php echo e($brand_option); ?>" <?php echo $selected_brand_scope === $brand_option ? 'selected' : ''; ?>><?php echo e($brand_option); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <small class="text-muted">Set a brand only for a Mini Admin. That account will see just this brand's shops, products, stock, sales and orders.</small>
                        </div>
                        <div class="col-md-6 form-group">
                            <label>Roles</label>
                            <?php foreach ($roles as $role): ?>
                                <div class="custom-control custom-checkbox">
                                    <input type="checkbox" class="custom-control-input user-role" name="roles[]" value="<?php echo (int)$role['role_id']; ?>" id="role_<?php echo (int)$role['role_id']; ?>"
                                           data-permissions="<?php echo e(json_encode($role_permissions[(int)$role['role_id']] ?? [])); ?>"
                                           <?php echo in_array((int)$role['role_id'], $selected_role_ids, true) ? 'checked' : ''; ?>>
                                    <label class="custom-control-label" for="role_<?php echo $role['role_id']; ?>"><?php echo e($role['name']); ?></label>
                                </div>
                            <?php endforeach; ?>
                            <?php if (!$roles): ?><small class="text-muted">No roles defined yet.</small><?php endif; ?>
                        </div>
                        <?php if ($can_assign_locations): ?>
                        <div class="col-12">
                            <label>Location Access</label>
                            <small class="text-muted d-block mb-2">Choose the Shop, Store, or Godown this account can access. Select a role first; location options are enabled only when the selected roles grant the corresponding permissions.</small>
                            <div class="row">
                                <div class="col-md-4 form-group location-assignment-group" data-required-permission="manage_shops,manage_inventory,manage_pos">
                                    <label>Shops</label>
                                <?php foreach ($shops as $shop): ?>
                                    <div class="custom-control custom-checkbox">
                                        <input type="checkbox" class="custom-control-input location-assignment" name="shops[]" value="<?php echo (int)$shop['shop_id']; ?>" id="shop_<?php echo (int)$shop['shop_id']; ?>" <?php echo in_array((int)$shop['shop_id'], $selected_shop_ids, true) ? 'checked' : ''; ?> <?php echo $can_assign_shop_locations ? '' : 'disabled'; ?>>
                                        <label class="custom-control-label" for="shop_<?php echo (int)$shop['shop_id']; ?>"><?php echo e($shop['name']); ?><?php echo (int)$shop['is_active'] ? '' : ' (inactive)'; ?></label>
                                    </div>
                                <?php endforeach; ?>
                                    <?php if (!$shops): ?><small class="text-muted">No active shops exist.</small><?php endif; ?>
                                </div>
                                <div class="col-md-4 form-group location-assignment-group" data-required-permission="manage_shops,manage_inventory">
                                    <label>Stores</label>
                                    <?php foreach ($stores as $store): ?>
                                        <div class="custom-control custom-checkbox">
                                            <input type="checkbox" class="custom-control-input location-assignment" name="stores[]" value="<?php echo (int)$store['warehouse_id']; ?>" id="store_<?php echo (int)$store['warehouse_id']; ?>" <?php echo in_array((int)$store['warehouse_id'], $selected_store_ids, true) ? 'checked' : ''; ?> <?php echo $can_assign_store_locations ? '' : 'disabled'; ?>>
                                            <label class="custom-control-label" for="store_<?php echo (int)$store['warehouse_id']; ?>"><?php echo e(trim(($store['shop_name'] ?? '') . ' / ' . $store['name'], ' /')); ?></label>
                                        </div>
                                    <?php endforeach; ?>
                                    <?php if (!$stores): ?><small class="text-muted">No active stores exist.</small><?php endif; ?>
                                </div>
                                <div class="col-md-4 form-group location-assignment-group" data-required-permission="manage_godown,view_godown,approve_godown_transfers,dispatch_godown_transfers,receive_godown_transfers">
                                    <label>Godowns</label>
                                    <?php foreach ($godowns as $godown): ?>
                                        <div class="custom-control custom-checkbox">
                                            <input type="checkbox" class="custom-control-input location-assignment" name="godowns[]" value="<?php echo (int)$godown['warehouse_id']; ?>" id="godown_<?php echo (int)$godown['warehouse_id']; ?>" <?php echo in_array((int)$godown['warehouse_id'], $selected_godown_ids, true) ? 'checked' : ''; ?> <?php echo $can_assign_godown_locations ? '' : 'disabled'; ?>>
                                            <label class="custom-control-label" for="godown_<?php echo (int)$godown['warehouse_id']; ?>"><?php echo e($godown['name']); ?></label>
                                        </div>
                                    <?php endforeach; ?>
                                    <?php if (!$godowns): ?><small class="text-muted">No active Godowns exist.</small><?php endif; ?>
                                </div>
                            </div>
                        </div>
                        <?php endif; ?>
                    </div>
                    <button type="submit" class="btn btn-primary"><i class="fa fa-check mr-1"></i> Create User</button>
                </form>
            </div>
        </div>
    </div>
</div>

<script>
    $(function () {
        function updateLocationAssignments() {
            var permissions = {};
            $('.user-role:checked').each(function () {
                try {
                    $.each(JSON.parse($(this).attr('data-permissions') || '[]'), function (_, permission) {
                        permissions[permission] = true;
                    });
                } catch (error) {
                    console.error('Could not read role permissions for location assignment.', error);
                }
            });
            $('.location-assignment-group').each(function () {
                var required = String($(this).data('required-permission')).split(',');
                var allowed = required.some(function (permission) { return permissions[permission]; });
                $(this).toggleClass('text-muted', !allowed).find('.location-assignment').prop('disabled', !allowed);
                if (!allowed) $(this).find('.location-assignment').prop('checked', false);
            });
        }
        $('.user-role').on('change', updateLocationAssignments);
        updateLocationAssignments();
    });
</script>

<?php require_once 'footer.php'; ?>
