<?php
require_once 'header.php';
require_once 'auth_check.php';
require_permission('manage_users');
$page_title = 'Edit User';

$id = (int)($_GET['id'] ?? 0);
$user = fetch_one("SELECT * FROM users WHERE user_id = ? AND tenant_id = ?", $id, $current_user->tenant_id);
if (!$user) {
    flash_set('danger', 'User not found.');
    redirect('users.php');
}

$current_roles = array_map(fn($r) => (int)$r['role_id'], fetch_all(
    "SELECT role_id FROM user_roles WHERE user_id = ? AND tenant_id = ?", $id, $current_user->tenant_id
));
$current_shop_ids = array_map(fn($r) => (int)$r['shop_id'], fetch_all(
    "SELECT shop_id FROM user_shops WHERE user_id = ? AND tenant_id = ?", $id, $current_user->tenant_id
));
$current_shop_location_ids = array_map(fn($r) => (int)$r['shop_id'], fetch_all(
    "SELECT DISTINCT w.shop_id FROM user_inventory_locations uil
     JOIN warehouses w ON w.warehouse_id = uil.location_id AND w.tenant_id = uil.tenant_id
     WHERE uil.user_id = ? AND uil.tenant_id = ? AND w.location_type = 'shop'",
    $id,
    $current_user->tenant_id
));
$current_shop_ids = array_values(array_unique(array_merge($current_shop_ids, $current_shop_location_ids)));
$current_store_ids = array_map(fn($r) => (int)$r['location_id'], fetch_all(
    "SELECT uil.location_id FROM user_inventory_locations uil
     JOIN warehouses w ON w.warehouse_id = uil.location_id AND w.tenant_id = uil.tenant_id
     WHERE uil.user_id = ? AND uil.tenant_id = ? AND w.location_type = 'store'",
    $id,
    $current_user->tenant_id
));
$current_godown_ids = array_map(fn($r) => (int)$r['location_id'], fetch_all(
    "SELECT uil.location_id FROM user_inventory_locations uil
     JOIN warehouses w ON w.warehouse_id = uil.location_id AND w.tenant_id = uil.tenant_id
     WHERE uil.user_id = ? AND uil.tenant_id = ? AND w.location_type = 'godown'",
    $id,
    $current_user->tenant_id
));
$legacy_godown_ids = array_map(fn($r) => (int)$r['warehouse_id'], fetch_all(
    "SELECT warehouse_id FROM user_godowns WHERE user_id = ? AND tenant_id = ?",
    $id,
    $current_user->tenant_id
));
$current_godown_ids = array_values(array_unique(array_merge($current_godown_ids, $legacy_godown_ids)));

$error = '';
$username = (string)($_POST['username'] ?? $user['username']);
$can_assign_locations = can('manage_users');
$roles = fetch_all("SELECT * FROM roles WHERE tenant_id = ? ORDER BY name", $current_user->tenant_id);
$selected_role_ids = array_map('intval', (array)($_POST['roles'] ?? $current_roles));
$role_permissions = role_permissions_for_ids($selected_role_ids, (int)$current_user->tenant_id);
$selected_role_ids = array_values(array_intersect($selected_role_ids, array_map('intval', array_column($roles, 'role_id'))));
$selected_permissions = array_values(array_unique(array_merge(...array_values($role_permissions ?: [[]]))));
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
$selected_shop_ids = array_map('intval', (array)($_POST['shops'] ?? $current_shop_ids));
$selected_store_ids = array_map('intval', (array)($_POST['stores'] ?? $current_store_ids));
$selected_godown_ids = array_map('intval', (array)($_POST['godowns'] ?? $current_godown_ids));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim((string)($_POST['username'] ?? ''));
    $email = trim($_POST['email'] ?? '');
    $first_name = trim($_POST['first_name'] ?? '');
    $last_name = trim($_POST['last_name'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $is_active = isset($_POST['is_active']) ? 1 : 0;
    $valid_role_ids = array_map('intval', array_column($roles, 'role_id'));
    $role_ids = array_values(array_intersect($selected_role_ids, $valid_role_ids));
    $selected_role_ids = $role_ids;
    $role_permissions = role_permissions_for_ids($role_ids, (int)$current_user->tenant_id);
    $selected_permissions = array_values(array_unique(array_merge(...array_values($role_permissions ?: [[]]))));
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
    $selected_shop_ids = $shop_ids;
    $selected_store_ids = $store_ids;
    $selected_godown_ids = $godown_ids;
    $new_password = $_POST['new_password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';

    if ($username === '' || $first_name === '' || $last_name === '' || $email === '') {
        $error = 'Required fields must be filled.';
    } elseif (strlen($username) > 100) {
        $error = 'Username cannot be longer than 100 characters.';
    } elseif (
        !$role_ids
        || (
            !$current_user->hasPermission('manage_settings')
            && ($can_assign_shop_locations || $can_assign_store_locations || $can_assign_godown_locations)
            && !$shop_ids && !$store_ids && !$godown_ids
        )
    ) {
        $error = !$role_ids ? 'Select at least one valid role.' : 'Assign at least one active location allowed by the selected role.';
    } elseif ($new_password !== '' && strlen($new_password) < 6) {
        $error = 'New password must be at least 6 characters long.';
    } elseif ($new_password !== '' && $new_password !== $confirm_password) {
        $error = 'Passwords do not match.';
    } else {
        $dup = fetch_one(
            "SELECT COUNT(*) c FROM users
              WHERE tenant_id = ? AND user_id != ? AND (username = ? OR email = ?)",
            $current_user->tenant_id, $id, $username, $email
        );
        if (($dup['c'] ?? 0) > 0) {
            $error = 'Username or email already in use by another user.';
        } else {
            $conn->begin_transaction();
            try {
            if ($new_password !== '') {
                $hash = password_hash($new_password, PASSWORD_DEFAULT);
                $stmt = $conn->prepare(
                    "UPDATE users SET username = ?, email = ?, first_name = ?, last_name = ?, phone = ?, password_hash = ?, is_active = ? WHERE user_id = ? AND tenant_id = ?"
                );
                $stmt->bind_param('ssssssiii', $username, $email, $first_name, $last_name, $phone, $hash, $is_active, $id, $current_user->tenant_id);
            } else {
                $stmt = $conn->prepare(
                    "UPDATE users SET username = ?, email = ?, first_name = ?, last_name = ?, phone = ?, is_active = ? WHERE user_id = ? AND tenant_id = ?"
                );
                $stmt->bind_param('sssssiii', $username, $email, $first_name, $last_name, $phone, $is_active, $id, $current_user->tenant_id);
            }
            $stmt->execute();
            $stmt->close();

            $stmt = $conn->prepare("DELETE FROM user_roles WHERE user_id = ? AND tenant_id = ?");
            $stmt->bind_param('ii', $id, $current_user->tenant_id);
            $stmt->execute();
            $stmt->close();
            foreach ($role_ids as $rid) {
                db_insert('user_roles', [
                    'user_id'   => $id,
                    'role_id'   => $rid,
                    'tenant_id' => $current_user->tenant_id,
                ]);
            }

            if ($can_assign_locations) {
                foreach (['user_shops', 'user_godowns', 'user_inventory_locations'] as $table) {
                    $stmt = $conn->prepare("DELETE FROM `$table` WHERE tenant_id = ? AND user_id = ?");
                    $stmt->bind_param('ii', $current_user->tenant_id, $id);
                    $stmt->execute();
                    $stmt->close();
                }
                foreach ($shop_ids as $shop_id) {
                    db_insert('user_shops', [
                        'tenant_id' => $current_user->tenant_id,
                        'user_id' => $id,
                        'shop_id' => $shop_id,
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
            }
            $conn->commit();
            } catch (Throwable $ex) {
                $conn->rollback();
                $error = 'Could not update the user: ' . $ex->getMessage();
            }
            if (!$error) {
                audit_log('update', 'user', $id, 'User details and role-based location assignments updated');
                flash_set('success', 'User updated successfully.');
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
        <h1 class="page-title">Edit User</h1>
        <div class="page-sub"><?php echo e($user['first_name'] . ' ' . $user['last_name']); ?></div>
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
                <form method="POST" action="user-edit.php?id=<?php echo $id; ?>">
                    <div class="row">
                        <div class="col-md-6 form-group">
                            <label>First Name <span class="text-danger">*</span></label>
                            <input type="text" name="first_name" class="form-control" required value="<?php echo e($user['first_name']); ?>">
                        </div>
                        <div class="col-md-6 form-group">
                            <label>Last Name <span class="text-danger">*</span></label>
                            <input type="text" name="last_name" class="form-control" required value="<?php echo e($user['last_name']); ?>">
                        </div>
                        <div class="col-md-6 form-group">
                            <label>Username <span class="text-danger">*</span></label>
                            <input type="text" name="username" class="form-control" maxlength="100" required value="<?php echo e($username); ?>">
                        </div>
                        <div class="col-md-6 form-group">
                            <label>Email <span class="text-danger">*</span></label>
                            <input type="email" name="email" class="form-control" required value="<?php echo e($user['email']); ?>">
                        </div>
                        <div class="col-md-6 form-group">
                            <label>Phone</label>
                            <input type="text" name="phone" class="form-control" value="<?php echo e($user['phone']); ?>">
                        </div>
                        <div class="col-md-6 form-group">
                            <label>Status</label><br>
                            <div class="custom-control custom-switch">
                                <input type="checkbox" class="custom-control-input" name="is_active" id="is_active" <?php echo $user['is_active'] ? 'checked' : ''; ?>>
                                <label class="custom-control-label" for="is_active">Active account</label>
                            </div>
                        </div>
                        <div class="col-md-6 form-group">
                            <label>Roles</label>
                            <?php foreach ($roles as $role): ?>
                                <div class="custom-control custom-checkbox">
                                    <input type="checkbox" class="custom-control-input user-role" name="roles[]" value="<?php echo (int)$role['role_id']; ?>" id="erole_<?php echo (int)$role['role_id']; ?>"
                                           data-permissions="<?php echo e(json_encode($role_permissions[(int)$role['role_id']] ?? [])); ?>"
                                           <?php echo in_array((int)$role['role_id'], $selected_role_ids, true) ? 'checked' : ''; ?>>
                                    <label class="custom-control-label" for="erole_<?php echo $role['role_id']; ?>"><?php echo e($role['name']); ?></label>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        <?php if ($can_assign_locations): ?>
                        <div class="col-12">
                            <label>Location Access</label>
                            <small class="text-muted d-block mb-2">Available location assignments depend on the permissions granted by the selected roles.</small>
                            <div class="row">
                                <div class="col-md-4 form-group location-assignment-group" data-required-permission="manage_shops,manage_inventory,manage_pos" <?php echo $can_assign_shop_locations ? '' : 'style="display:none;"'; ?>>
                                    <label>Shops</label>
                                <?php foreach ($shops as $shop): ?>
                                    <div class="custom-control custom-checkbox">
                                        <input type="checkbox" class="custom-control-input location-assignment" name="shops[]" value="<?php echo (int)$shop['shop_id']; ?>" id="eshop_<?php echo (int)$shop['shop_id']; ?>" <?php echo in_array((int)$shop['shop_id'], $selected_shop_ids, true) ? 'checked' : ''; ?> <?php echo $can_assign_shop_locations ? '' : 'disabled'; ?>>
                                        <label class="custom-control-label" for="eshop_<?php echo (int)$shop['shop_id']; ?>"><?php echo e($shop['name']); ?><?php echo (int)$shop['is_active'] ? '' : ' (inactive)'; ?></label>
                                    </div>
                                <?php endforeach; ?>
                                    <?php if (!$shops): ?><small class="text-muted">No active shops exist.</small><?php endif; ?>
                                </div>
                                <div class="col-md-4 form-group location-assignment-group" data-required-permission="manage_shops,manage_inventory" <?php echo $can_assign_store_locations ? '' : 'style="display:none;"'; ?>>
                                    <label>Stores</label>
                                    <?php foreach ($stores as $store): ?>
                                        <div class="custom-control custom-checkbox">
                                            <input type="checkbox" class="custom-control-input location-assignment" name="stores[]" value="<?php echo (int)$store['warehouse_id']; ?>" id="estore_<?php echo (int)$store['warehouse_id']; ?>" <?php echo in_array((int)$store['warehouse_id'], $selected_store_ids, true) ? 'checked' : ''; ?> <?php echo $can_assign_store_locations ? '' : 'disabled'; ?>>
                                            <label class="custom-control-label" for="estore_<?php echo (int)$store['warehouse_id']; ?>"><?php echo e(trim(($store['shop_name'] ?? '') . ' / ' . $store['name'], ' /')); ?></label>
                                        </div>
                                    <?php endforeach; ?>
                                    <?php if (!$stores): ?><small class="text-muted">No active stores exist.</small><?php endif; ?>
                                </div>
                                <div class="col-md-4 form-group location-assignment-group" data-required-permission="manage_godown,view_godown,approve_godown_transfers,dispatch_godown_transfers,receive_godown_transfers" <?php echo $can_assign_godown_locations ? '' : 'style="display:none;"'; ?>>
                                    <label>Godowns</label>
                                    <?php foreach ($godowns as $godown): ?>
                                        <div class="custom-control custom-checkbox">
                                            <input type="checkbox" class="custom-control-input location-assignment" name="godowns[]" value="<?php echo (int)$godown['warehouse_id']; ?>" id="egodown_<?php echo (int)$godown['warehouse_id']; ?>" <?php echo in_array((int)$godown['warehouse_id'], $selected_godown_ids, true) ? 'checked' : ''; ?> <?php echo $can_assign_godown_locations ? '' : 'disabled'; ?>>
                                            <label class="custom-control-label" for="egodown_<?php echo (int)$godown['warehouse_id']; ?>"><?php echo e($godown['name']); ?></label>
                                        </div>
                                    <?php endforeach; ?>
                                    <?php if (!$godowns): ?><small class="text-muted">No active Godowns exist.</small><?php endif; ?>
                                </div>
                            </div>
                        </div>
                        <?php endif; ?>
                        <div class="col-md-6 form-group">
                            <label>New Password</label>
                            <input type="password" name="new_password" class="form-control" placeholder="Leave blank to keep current">
                        </div>
                        <div class="col-md-6 form-group">
                            <label>Confirm Password</label>
                            <input type="password" name="confirm_password" class="form-control">
                        </div>
                    </div>
                    <button type="submit" class="btn btn-primary"><i class="fa fa-check mr-1"></i> Save Changes</button>
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
                $(this).toggle(allowed).find('.location-assignment').prop('disabled', !allowed);
                if (!allowed) $(this).find('.location-assignment').prop('checked', false);
            });
        }
        $('.user-role').on('change', updateLocationAssignments);
        updateLocationAssignments();
    });
</script>

<?php require_once 'footer.php'; ?>
