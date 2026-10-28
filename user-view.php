<?php
require_once 'header.php';
require_once 'auth_check.php';
require_permission('manage_users');
$page_title = 'User Details';

$id = (int)($_GET['id'] ?? 0);
$user = fetch_one(
    "SELECT u.*, GROUP_CONCAT(r.name SEPARATOR ', ') role_names
     FROM users u
     LEFT JOIN user_roles ur ON ur.user_id = u.user_id
     LEFT JOIN roles r ON r.role_id = ur.role_id
     WHERE u.user_id = ? AND u.tenant_id = ?
     GROUP BY u.user_id",
    $id, $current_user->tenant_id
);
if (!$user) {
    flash_set('danger', 'User not found.');
    redirect('users.php');
}

$logs = fetch_all(
    "SELECT * FROM audit_logs WHERE tenant_id = ? AND user_id = ? ORDER BY created_at DESC LIMIT 10",
    $current_user->tenant_id, $id
);

require_once 'navbar.php';
require_once 'sidebar.php';
?>

<div class="page-head">
    <div>
        <h1 class="page-title">User Details</h1>
        <div class="page-sub"><?php echo e($user['first_name'] . ' ' . $user['last_name']); ?></div>
    </div>
    <div>
        <a href="user-edit.php?id=<?php echo $id; ?>" class="btn btn-outline-primary"><i class="fa fa-pencil mr-1"></i> Edit</a>
        <a href="users.php" class="btn btn-light"><i class="fa fa-arrow-left mr-1"></i> Back</a>
    </div>
</div>

<ul class="nav page-tabs">
    <li class="nav-item"><a class="nav-link active" href="users.php"><i class="fa fa-users mr-1"></i> Users</a></li>
    <li class="nav-item"><a class="nav-link" href="permissions.php"><i class="fa fa-shield mr-1"></i> Roles &amp; Permissions</a></li>
</ul>

<div class="row">
    <div class="col-md-4 mb-3">
        <div class="card text-center">
            <div class="card-body">
                <span class="avatar mx-auto mb-3" style="width:72px;height:72px;font-size:28px;background:var(--primary)"><?php echo e(strtoupper(substr($user['first_name'], 0, 1))); ?></span>
                <h5 class="mb-1"><?php echo e($user['first_name'] . ' ' . $user['last_name']); ?></h5>
                <p class="text-muted mb-2">@<?php echo e($user['username']); ?></p>
                <div>
                    <?php if ($user['is_active']): ?>
                        <span class="badge badge-success">Active</span>
                    <?php else: ?>
                        <span class="badge badge-secondary">Inactive</span>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
    <div class="col-md-8 mb-3">
        <div class="card">
            <div class="card-header"><h5 class="card-title">Account Information</h5></div>
            <div class="card-body">
                <table class="table">
                    <tbody>
                        <tr><th style="width:180px;">Full Name</th><td><?php echo e($user['first_name'] . ' ' . $user['last_name']); ?></td></tr>
                        <tr><th>Email</th><td><?php echo e($user['email']); ?></td></tr>
                        <tr><th>Phone</th><td><?php echo e($user['phone'] ?: '—'); ?></td></tr>
                        <tr><th>Roles</th><td><?php echo e($user['role_names'] ?: 'No role'); ?></td></tr>
                        <tr><th>Last Login</th><td><?php echo $user['last_login'] ? e(date('M d, Y H:i', strtotime($user['last_login']))) : 'Never'; ?></td></tr>
                        <tr><th>Member Since</th><td><?php echo e(date('M d, Y', strtotime($user['created_at']))); ?></td></tr>
                    </tbody>
                </table>
            </div>
        </div>
        <div class="card">
            <div class="card-header"><h5 class="card-title">Recent Activity</h5></div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table mb-0">
                        <thead><tr><th>Action</th><th>Entity</th><th>Details</th><th>Date</th></tr></thead>
                        <tbody>
                        <?php if (!$logs): ?>
                            <tr><td colspan="4" class="text-center text-muted py-4">No activity yet.</td></tr>
                        <?php else: foreach ($logs as $log): ?>
                            <tr>
                                <td><span class="badge badge-primary"><?php echo e($log['action']); ?></span></td>
                                <td><?php echo e($log['entity_type']); ?> #<?php echo e($log['entity_id']); ?></td>
                                <td><?php echo e($log['details'] ?? '—'); ?></td>
                                <td><?php echo e(date('M d, Y H:i', strtotime($log['created_at']))); ?></td>
                            </tr>
                        <?php endforeach; endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<?php require_once 'footer.php'; ?>
