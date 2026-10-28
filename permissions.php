<?php
require_once 'header.php';
require_once 'auth_check.php';
require_permission('manage_users');
$page_title = 'Roles & Permissions';

$msg = '';

// Create a role
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'create_role') {
    $name = trim($_POST['name'] ?? '');
    $desc = trim($_POST['description'] ?? '');
    if ($name === '') {
        $msg = ['danger', 'Role name is required.'];
    } else {
        $id = db_insert('roles', [
            'tenant_id'   => $current_user->tenant_id,
            'name'        => $name,
            'description' => $desc,
        ]);
        $msg = $id ? ['success', 'Role created successfully.'] : ['danger', 'Could not create role.'];
        if ($id) {
            foreach (($_POST['perms'] ?? []) as $permId) {
                db_insert('role_permissions', ['role_id' => $id, 'permission_id' => (int)$permId]);
            }
        }
    }
}

// Delete a role (only if no users assigned)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete_role') {
    $rid = (int)($_POST['role_id'] ?? 0);
    $assigned = fetch_one("SELECT COUNT(*) c FROM user_roles WHERE role_id = ?", $rid);
    if (($assigned['c'] ?? 0) > 0) {
        $msg = ['danger', 'This role is assigned to users and cannot be deleted.'];
    } else {
        $stmt = $conn->prepare("DELETE FROM role_permissions WHERE role_id = ?");
        $stmt->bind_param('i', $rid);
        $stmt->execute();
        $stmt = $conn->prepare("DELETE FROM roles WHERE role_id = ? AND tenant_id = ?");
        $stmt->bind_param('ii', $rid, $current_user->tenant_id);
        $stmt->execute();
        $msg = ['success', 'Role deleted.'];
    }
}

// Toggle a permission on a role
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'toggle_perm') {
    $rid = (int)($_POST['role_id'] ?? 0);
    $pid = (int)($_POST['perm_id'] ?? 0);
    $exists = fetch_one("SELECT COUNT(*) c FROM role_permissions WHERE role_id = ? AND permission_id = ?", $rid, $pid);
    $blocked = false;
    if (($exists['c'] ?? 0) > 0) {
        // Removing a permission — never let the current user lock themselves out
        $perm = fetch_one("SELECT name FROM permissions WHERE permission_id = ?", $pid);
        $critical = in_array($perm['name'] ?? '', ['manage_users', 'view_reports', 'manage_settings'], true);
        if ($critical) {
            $mine = fetch_one(
                "SELECT COUNT(*) c FROM user_roles WHERE user_id = ? AND role_id = ? AND tenant_id = ?",
                $current_user->user_id, $rid, $current_user->tenant_id
            );
            if (($mine['c'] ?? 0) > 0 && $current_user->hasPermission($perm['name'])) {
                $other = fetch_one(
                    "SELECT COUNT(*) c FROM user_roles ur
                     JOIN role_permissions rp ON rp.role_id = ur.role_id
                     WHERE ur.user_id = ? AND ur.tenant_id = ? AND ur.role_id <> ? AND rp.permission_id = ?",
                    $current_user->user_id, $current_user->tenant_id, $rid, $pid
                );
                if (($other['c'] ?? 0) === 0) {
                    $blocked = true;
                    $msg = ['danger', 'You cannot remove this permission — it is your own account access and no other role provides it to you.'];
                }
            }
        }
        if (!$blocked) {
            $stmt = $conn->prepare("DELETE FROM role_permissions WHERE role_id = ? AND permission_id = ?");
            $stmt->bind_param('ii', $rid, $pid);
            $stmt->execute();
            $msg = ['success', 'Permission updated.'];
        }
    } else {
        db_insert('role_permissions', ['role_id' => $rid, 'permission_id' => $pid]);
        $msg = ['success', 'Permission updated.'];
    }
}

$users = fetch_all("SELECT user_id, first_name, last_name, username FROM users WHERE tenant_id = ? ORDER BY first_name", $current_user->tenant_id);
$roles = fetch_all("SELECT * FROM roles WHERE tenant_id = ? ORDER BY name", $current_user->tenant_id);
$permissions = fetch_all("SELECT * FROM permissions ORDER BY name");

$user_roles_map = [];
foreach (fetch_all(
    "SELECT ur.user_id, ur.role_id, r.name FROM user_roles ur
     JOIN roles r ON r.role_id = ur.role_id
     WHERE ur.tenant_id = ?",
    $current_user->tenant_id
) as $ur) {
    $user_roles_map[$ur['user_id']][] = $ur;
}

$role_perms_map = [];
foreach (fetch_all(
    "SELECT rp.role_id, rp.permission_id FROM role_permissions rp
     JOIN roles r ON r.role_id = rp.role_id WHERE r.tenant_id = ?",
    $current_user->tenant_id
) as $rp) {
    $role_perms_map[$rp['role_id']][] = (int)$rp['permission_id'];
}

require_once 'navbar.php';
require_once 'sidebar.php';
?>

<div class="page-head">
    <div>
        <h1 class="page-title">Roles &amp; Permissions</h1>
        <div class="page-sub">Control what each role can access.</div>
    </div>
</div>

<ul class="nav page-tabs">
    <li class="nav-item"><a class="nav-link" href="users.php"><i class="fa fa-users mr-1"></i> Users</a></li>
    <li class="nav-item"><a class="nav-link active" href="permissions.php"><i class="fa fa-shield mr-1"></i> Roles &amp; Permissions</a></li>
</ul>

<?php if ($msg): ?>
    <div class="alert alert-<?php echo e($msg[0]); ?>"><?php echo e($msg[1]); ?></div>
<?php endif; ?>

<div class="row">
    <div class="col-lg-7 mb-3">
        <div class="card">
            <div class="card-header"><h5 class="card-title">Roles</h5></div>
            <div class="card-body">
                <table class="table">
                    <thead><tr><th>Role</th><th>Description</th><th>Permissions</th><th>Assigned Users</th><th class="text-right">Actions</th></tr></thead>
                    <tbody>
                    <?php foreach ($roles as $role): ?>
                        <tr>
                            <td class="font-weight-semibold"><?php echo e($role['name']); ?></td>
                            <td><?php echo e($role['description'] ?: '—'); ?></td>
                            <td>
                                <?php
                                $rp = array_map('intval', $role_perms_map[$role['role_id']] ?? []);
                                foreach ($permissions as $perm):
                                    $on = in_array((int)$perm['permission_id'], $rp);
                                ?>
                                <form method="POST" action="permissions.php" class="d-inline">
                                    <input type="hidden" name="action" value="toggle_perm">
                                    <input type="hidden" name="role_id" value="<?php echo $role['role_id']; ?>">
                                    <input type="hidden" name="perm_id" value="<?php echo $perm['permission_id']; ?>">
                                    <button type="submit" class="btn btn-sm <?php echo $on ? 'btn-primary' : 'btn-light'; ?> mb-1" title="<?php echo e($perm['description']); ?>">
                                        <?php echo e(ucwords(str_replace('_', ' ', $perm['name']))); ?>
                                    </button>
                                </form>
                                <?php endforeach; ?>
                            </td>
                            <td><?php echo count(array_filter($user_roles_map, fn($list) => in_array((int)$role['role_id'], array_column($list, 'role_id')))); ?></td>
                            <td class="text-right">
                                <form method="POST" action="permissions.php" class="d-inline" onsubmit="return confirm('Delete this role?');">
                                    <input type="hidden" name="action" value="delete_role">
                                    <input type="hidden" name="role_id" value="<?php echo $role['role_id']; ?>">
                                    <button type="submit" class="btn btn-action btn-light text-danger"><i class="fa fa-trash"></i></button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <div class="col-lg-5 mb-3">
        <div class="card">
            <div class="card-header"><h5 class="card-title">Create New Role</h5></div>
            <div class="card-body">
                <form method="POST" action="permissions.php">
                    <input type="hidden" name="action" value="create_role">
                    <div class="form-group">
                        <label>Role Name <span class="text-danger">*</span></label>
                        <input type="text" name="name" class="form-control" required>
                    </div>
                    <div class="form-group">
                        <label>Description</label>
                        <textarea name="description" class="form-control" rows="2"></textarea>
                    </div>
                    <div class="form-group">
                        <label>Initial Permissions</label>
                        <?php foreach ($permissions as $perm): ?>
                            <div class="custom-control custom-checkbox">
                                <input type="checkbox" class="custom-control-input" name="perms[]" value="<?php echo $perm['permission_id']; ?>" id="nperm_<?php echo $perm['permission_id']; ?>">
                                <label class="custom-control-label" for="nperm_<?php echo $perm['permission_id']; ?>"><?php echo e(ucwords(str_replace('_', ' ', $perm['name']))); ?></label>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <button type="submit" class="btn btn-primary"><i class="fa fa-plus mr-1"></i> Create Role</button>
                </form>
            </div>
        </div>
        <div class="card">
            <div class="card-header"><h5 class="card-title">How it works</h5></div>
            <div class="card-body small text-muted">
                <p class="mb-1">Each role bundles permissions. Click a permission button on any role to grant or revoke it.</p>
                <p class="mb-1">Add users to roles in <a href="user-add.php">Users</a> — a user can hold several roles and inherits all their permissions.</p>
                <p class="mb-0">You cannot remove your own account access by accident — the system prevents it.</p>
            </div>
        </div>
    </div>
</div>

<?php require_once 'footer.php'; ?>
