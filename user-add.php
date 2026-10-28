<?php
require_once 'header.php';
require_once 'auth_check.php';
require_permission('manage_users');
$page_title = 'Add User';

$error = '';
$can_assign_shops = can('manage_shops');

$shops = $can_assign_shops
    ? fetch_all("SELECT * FROM shops WHERE tenant_id = ? ORDER BY name", $current_user->tenant_id)
    : [];
$selected_shop_ids = $can_assign_shops
    ? (array)($_POST['shops'] ?? array_map(fn($s) => (int)$s['shop_id'], $shops))
    : [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $first_name = trim($_POST['first_name'] ?? '');
    $last_name = trim($_POST['last_name'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $role_ids = array_map('intval', $_POST['roles'] ?? []);
    $allowed_shop_ids = array_map(fn($s) => (int)$s['shop_id'], $shops);
    $shop_ids = $can_assign_shops
        ? array_values(array_intersect(array_map('intval', (array)($_POST['shops'] ?? [])), $allowed_shop_ids))
        : [];

    if ($username === '' || $email === '' || $password === '' || $first_name === '' || $last_name === '') {
        $error = 'All required fields must be filled.';
    } elseif (strlen($password) < 6) {
        $error = 'Password must be at least 6 characters long.';
    } else {
        $dup = fetch_one(
            "SELECT COUNT(*) c FROM users WHERE tenant_id = ? AND (username = ? OR email = ?)",
            $current_user->tenant_id, $username, $email
        );
        if (($dup['c'] ?? 0) > 0) {
            $error = 'Username or email already exists for this account.';
        } else {
            $hash = password_hash($password, PASSWORD_DEFAULT);
            $id = db_insert('users', [
                'tenant_id'     => $current_user->tenant_id,
                'username'      => $username,
                'email'         => $email,
                'password_hash' => $hash,
                'first_name'    => $first_name,
                'last_name'     => $last_name,
                'phone'         => $phone,
                'is_active'     => 1,
            ]);
            if (!$id) {
                $error = 'Could not create the user. Please try again.';
            } else {
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
                }
                audit_log('create', 'user', $id, 'User ' . $username . ' created');
                flash_set('success', 'User created successfully.');
                redirect('users.php');
            }
        }
    }
}

$roles = fetch_all("SELECT * FROM roles WHERE tenant_id = ? ORDER BY name", $current_user->tenant_id);

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
                            <label>Roles</label>
                            <?php foreach ($roles as $role): ?>
                                <div class="custom-control custom-checkbox">
                                    <input type="checkbox" class="custom-control-input" name="roles[]" value="<?php echo $role['role_id']; ?>" id="role_<?php echo $role['role_id']; ?>">
                                    <label class="custom-control-label" for="role_<?php echo $role['role_id']; ?>"><?php echo e($role['name']); ?></label>
                                </div>
                            <?php endforeach; ?>
                            <?php if (!$roles): ?><small class="text-muted">No roles defined yet.</small><?php endif; ?>
                        </div>
                        <div class="col-md-6 form-group">
                            <label>Shop Access</label>
                            <?php if ($can_assign_shops): ?>
                                <?php foreach ($shops as $shop): ?>
                                    <div class="custom-control custom-checkbox">
                                        <input type="checkbox" class="custom-control-input" name="shops[]" value="<?php echo (int)$shop['shop_id']; ?>" id="shop_<?php echo (int)$shop['shop_id']; ?>" <?php echo in_array((int)$shop['shop_id'], array_map('intval', $selected_shop_ids), true) ? 'checked' : ''; ?>>
                                        <label class="custom-control-label" for="shop_<?php echo (int)$shop['shop_id']; ?>"><?php echo e($shop['name']); ?><?php echo (int)$shop['is_active'] ? '' : ' (inactive)'; ?></label>
                                    </div>
                                <?php endforeach; ?>
                                <?php if (!$shops): ?><small class="text-muted">No shops exist. <a href="shops.php">Create a shop</a> first.</small><?php endif; ?>
                                <small class="text-muted d-block">Users only see data for selected shops. Administrators see all shops.</small>
                            <?php else: ?>
                                <small class="text-muted d-block">Your role cannot assign shop access. A user with <code>manage_shops</code> can assign shops after the account is created.</small>
                            <?php endif; ?>
                        </div>
                    </div>
                    <button type="submit" class="btn btn-primary"><i class="fa fa-check mr-1"></i> Create User</button>
                </form>
            </div>
        </div>
    </div>
</div>

<?php require_once 'footer.php'; ?>
