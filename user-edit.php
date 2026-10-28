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

$error = '';
$can_assign_shops = can('manage_shops');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    $first_name = trim($_POST['first_name'] ?? '');
    $last_name = trim($_POST['last_name'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $is_active = isset($_POST['is_active']) ? 1 : 0;
    $role_ids = array_map('intval', $_POST['roles'] ?? []);
    $shops = $can_assign_shops
        ? fetch_all("SELECT * FROM shops WHERE tenant_id = ? ORDER BY name", $current_user->tenant_id)
        : [];
    $allowed_shop_ids = array_map(fn($s) => (int)$s['shop_id'], $shops);
    $shop_ids = $can_assign_shops
        ? array_values(array_intersect(array_map('intval', (array)($_POST['shops'] ?? [])), $allowed_shop_ids))
        : $current_shop_ids;
    $selected_shop_ids = $shop_ids;
    $new_password = $_POST['new_password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';

    if ($first_name === '' || $last_name === '' || $email === '') {
        $error = 'Required fields must be filled.';
    } elseif ($new_password !== '' && strlen($new_password) < 6) {
        $error = 'New password must be at least 6 characters long.';
    } elseif ($new_password !== '' && $new_password !== $confirm_password) {
        $error = 'Passwords do not match.';
    } else {
        $dup = fetch_one(
            "SELECT COUNT(*) c FROM users WHERE tenant_id = ? AND email = ? AND user_id != ?",
            $current_user->tenant_id, $email, $id
        );
        if (($dup['c'] ?? 0) > 0) {
            $error = 'Email already in use by another user.';
        } else {
            if ($new_password !== '') {
                $hash = password_hash($new_password, PASSWORD_DEFAULT);
                $stmt = $conn->prepare(
                    "UPDATE users SET email = ?, first_name = ?, last_name = ?, phone = ?, password_hash = ?, is_active = ? WHERE user_id = ? AND tenant_id = ?"
                );
                $stmt->bind_param('sssssiii', $email, $first_name, $last_name, $phone, $hash, $is_active, $id, $current_user->tenant_id);
            } else {
                $stmt = $conn->prepare(
                    "UPDATE users SET email = ?, first_name = ?, last_name = ?, phone = ?, is_active = ? WHERE user_id = ? AND tenant_id = ?"
                );
                $stmt->bind_param('ssssiii', $email, $first_name, $last_name, $phone, $is_active, $id, $current_user->tenant_id);
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

            if ($can_assign_shops) {
                $stmt = $conn->prepare("DELETE FROM user_shops WHERE user_id = ? AND tenant_id = ?");
                $stmt->bind_param('ii', $id, $current_user->tenant_id);
                $stmt->execute();
                $stmt->close();
                foreach ($shop_ids as $shop_id) {
                    db_insert('user_shops', [
                        'tenant_id' => $current_user->tenant_id,
                        'user_id'   => $id,
                        'shop_id'   => $shop_id,
                    ]);
                }
            }
            audit_log('update', 'user', $id, 'User details updated');
            flash_set('success', 'User updated successfully.');
            redirect('users.php');
        }
    }
}

$roles = fetch_all("SELECT * FROM roles WHERE tenant_id = ? ORDER BY name", $current_user->tenant_id);
if (!isset($shops)) {
    $shops = $can_assign_shops
        ? fetch_all("SELECT * FROM shops WHERE tenant_id = ? ORDER BY name", $current_user->tenant_id)
        : [];
}
if (!isset($selected_shop_ids)) {
    $selected_shop_ids = $current_shop_ids;
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
                            <label>Username</label>
                            <input type="text" class="form-control" value="<?php echo e($user['username']); ?>" disabled>
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
                                    <input type="checkbox" class="custom-control-input" name="roles[]" value="<?php echo $role['role_id']; ?>" id="erole_<?php echo $role['role_id']; ?>" <?php echo in_array((int)$role['role_id'], $current_roles) ? 'checked' : ''; ?>>
                                    <label class="custom-control-label" for="erole_<?php echo $role['role_id']; ?>"><?php echo e($role['name']); ?></label>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        <div class="col-md-6 form-group">
                            <label>Shop Access</label>
                            <?php if ($can_assign_shops): ?>
                                <?php foreach ($shops as $shop): ?>
                                    <div class="custom-control custom-checkbox">
                                        <input type="checkbox" class="custom-control-input" name="shops[]" value="<?php echo (int)$shop['shop_id']; ?>" id="eshop_<?php echo (int)$shop['shop_id']; ?>" <?php echo in_array((int)$shop['shop_id'], array_map('intval', $selected_shop_ids), true) ? 'checked' : ''; ?>>
                                        <label class="custom-control-label" for="eshop_<?php echo (int)$shop['shop_id']; ?>"><?php echo e($shop['name']); ?><?php echo (int)$shop['is_active'] ? '' : ' (inactive)'; ?></label>
                                    </div>
                                <?php endforeach; ?>
                                <?php if (!$shops): ?><small class="text-muted">No shops exist. <a href="shops.php">Create a shop</a> first.</small><?php endif; ?>
                                <small class="text-muted d-block">Leave all unchecked to remove shop access.</small>
                            <?php else: ?>
                                <small class="text-muted d-block">Your role cannot change shop access. Existing assignments were left unchanged.</small>
                            <?php endif; ?>
                        </div>
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

<?php require_once 'footer.php'; ?>
