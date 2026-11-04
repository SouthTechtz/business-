<?php
require_once 'header.php';
require_once 'auth_check.php';
require_permission('manage_users');
$page_title = 'Users';

// Delete user
if (isset($_GET['delete']) && is_numeric($_GET['delete'])) {
    $id = (int)$_GET['delete'];
    if ($id === (int)$current_user->user_id) {
        flash_set('danger', 'You cannot delete your own account.');
    } else {
        $conn->begin_transaction();
        try {
            $j = fetch_one("SELECT COUNT(*) c FROM journal_entries WHERE created_by = ? AND tenant_id = ?", $id, $current_user->tenant_id);
            $a = fetch_one("SELECT COUNT(*) c FROM activities WHERE (created_by = ? OR assigned_to = ?) AND tenant_id = ?", $id, $id, $current_user->tenant_id);
            if (($j['c'] ?? 0) > 0 || ($a['c'] ?? 0) > 0) {
                throw new Exception('This user cannot be deleted because they have associated journal entries or activities.');
            }
            $stmt = $conn->prepare("DELETE FROM user_roles WHERE user_id = ?");
            $stmt->bind_param('i', $id);
            $stmt->execute();
            $stmt = $conn->prepare("DELETE FROM users WHERE user_id = ? AND tenant_id = ?");
            $stmt->bind_param('ii', $id, $current_user->tenant_id);
            $stmt->execute();
            $conn->commit();
            flash_set('success', 'User deleted successfully.');
        } catch (Exception $ex) {
            $conn->rollback();
            flash_set('danger', $ex->getMessage());
        }
    }
    redirect('users.php');
}

$users = fetch_all(
    "SELECT u.*, GROUP_CONCAT(r.name SEPARATOR ', ') role_names
     FROM users u
     LEFT JOIN user_roles ur ON ur.user_id = u.user_id
     LEFT JOIN roles r ON r.role_id = ur.role_id
     WHERE u.tenant_id = ?
       AND NOT EXISTS (
           SELECT 1 FROM user_roles wur
           JOIN roles wr ON wr.role_id = wur.role_id
           WHERE wur.user_id = u.user_id AND wur.tenant_id = u.tenant_id
             AND LOWER(wr.name) = 'wholesaler'
       )
     GROUP BY u.user_id ORDER BY u.created_at DESC",
    $current_user->tenant_id
);

require_once 'navbar.php';
require_once 'sidebar.php';
?>

<div class="page-head">
    <div>
        <h1 class="page-title">Users</h1>
        <div class="page-sub">Manage staff accounts and access.</div>
    </div>
    <div>
        <?php if (is_system_administrator()): ?>
            <a href="customers.php?tab=wholesalers" class="btn btn-outline-primary mr-1"><i class="fa fa-briefcase mr-1"></i> Wholesale Customers</a>
        <?php endif; ?>
        <a href="user-add.php" class="btn btn-primary"><i class="fa fa-plus mr-1"></i> Add User</a>
    </div>
</div>

<ul class="nav page-tabs">
    <li class="nav-item"><a class="nav-link active" href="users.php"><i class="fa fa-users mr-1"></i> Staff Users</a></li>
    <li class="nav-item"><a class="nav-link" href="permissions.php"><i class="fa fa-shield mr-1"></i> Roles &amp; Permissions</a></li>
    <?php if (is_system_administrator()): ?>
        <li class="nav-item"><a class="nav-link" href="customers.php?tab=wholesalers"><i class="fa fa-briefcase mr-1"></i> Wholesale Customers</a></li>
    <?php endif; ?>
</ul>

<?php if ($f = flash_get()): ?>
    <div class="alert alert-<?php echo e($f['type']); ?>"><?php echo e($f['message']); ?></div>
<?php endif; ?>

<div class="card">
    <div class="card-header d-flex justify-content-between align-items-center">
        <h5 class="card-title">System Users</h5>
        <div class="input-group dt-search">
            <div class="input-group-prepend"><span class="input-group-text"><i class="fa fa-search"></i></span></div>
            <input type="text" class="form-control dt-search-input" data-scope="users" placeholder="Search users...">
        </div>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover data-table">
                <thead>
                    <tr>
                        <th>Name</th><th>Username</th><th>Email</th><th>Phone</th><th>Roles</th><th>Status</th><th>Last Login</th><th class="text-right">Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($users as $row): ?>
                    <tr>
                        <td>
                            <div class="d-flex align-items-center">
                                <span class="avatar mr-2" style="background:<?php echo $row['user_id'] === $current_user->user_id ? 'var(--success)' : 'var(--primary)'; ?>"><?php echo e(strtoupper(substr($row['first_name'], 0, 1))); ?></span>
                                <?php echo e($row['first_name'] . ' ' . $row['last_name']); ?>
                            </div>
                        </td>
                        <td><?php echo e($row['username']); ?></td>
                        <td><?php echo e($row['email']); ?></td>
                        <td><?php echo e($row['phone'] ?: '—'); ?></td>
                        <td><?php echo e($row['role_names'] ?: 'No role'); ?></td>
                        <td>
                            <?php if ($row['is_active']): ?>
                                <span class="badge badge-success">Active</span>
                            <?php else: ?>
                                <span class="badge badge-secondary">Inactive</span>
                            <?php endif; ?>
                        </td>
                        <td><?php echo $row['last_login'] ? e(date('M d, Y H:i', strtotime($row['last_login']))) : 'Never'; ?></td>
                        <td class="text-right">
                            <?php
                            $user_actions = [
                                ['label' => 'View user', 'icon' => 'fa-eye', 'href' => 'user-view.php?id=' . (int)$row['user_id']],
                                ['label' => 'Edit user', 'icon' => 'fa-pencil', 'href' => 'user-edit.php?id=' . (int)$row['user_id']],
                            ];
                            if (can('manage_shops')) {
                                $user_actions[] = ['label' => 'Shop access', 'icon' => 'fa-building', 'href' => 'user-shops.php?user=' . (int)$row['user_id']];
                            }
                            if ($row['user_id'] !== $current_user->user_id) {
                                $user_actions[] = ['type' => 'divider'];
                                $user_actions[] = [
                                    'label' => 'Delete user',
                                    'icon' => 'fa-trash',
                                    'danger' => true,
                                    'href' => 'users.php?delete=' . (int)$row['user_id'],
                                    'attrs' => ['onclick' => "return confirm('Delete this user?');"],
                                ];
                            }
                            echo row_action_menu($user_actions, 'User actions');
                            ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$users): ?>
                    <tr><td colspan="8" class="text-center text-muted py-4">No users found.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php require_once 'footer.php'; ?>
