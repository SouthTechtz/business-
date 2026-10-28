<?php
require_once 'header.php';
require_once 'auth_check.php';
require_permission_any(['view_activity_log', 'view_reports']);
$page_title = 'Activity Log';

$logs = fetch_all(
    "SELECT l.*, u.first_name, u.last_name, u.username
     FROM audit_logs l
     LEFT JOIN users u ON u.user_id = l.user_id
     WHERE l.tenant_id = ?
     ORDER BY l.log_id DESC
     LIMIT 500",
    $current_user->tenant_id
);

$badges = [
    'login'     => 'badge-success',
    'logout'    => 'badge-secondary',
    'create'    => 'badge-primary',
    'update'    => 'badge-info',
    'delete'    => 'badge-danger',
    'cancel'    => 'badge-warning',
    'approve'   => 'badge-info',
    'dispatch'  => 'badge-primary',
    'receive'   => 'badge-success',
    'resolve'   => 'badge-success',
    'configure' => 'badge-info',
];
$entity_icons = [
    'pos_order'  => 'fa-shopping-cart',
    'invoice'    => 'fa-file-text',
    'payment'    => 'fa-money',
    'product'    => 'fa-cube',
    'customer'   => 'fa-address-book',
    'supplier'   => 'fa-truck',
    'user'       => 'fa-users',
    'role'       => 'fa-key',
    'settings'   => 'fa-cog',
    'stock'      => 'fa-cubes',
    'warehouse'  => 'fa-building',
    'stock_transfer' => 'fa-exchange',
    'godown_receipt' => 'fa-archive',
];
?>
<?php require_once 'navbar.php'; ?>
<?php require_once 'sidebar.php'; ?>
<div class="page-head">
    <div>
        <h1 class="page-title">Activity Log</h1>
        <div class="page-sub">Complete security trail of every action performed in the system.</div>
    </div>
    <div class="text-muted small">Last 500 events</div>
</div>

<div class="card">
    <div class="card-header d-flex justify-content-between align-items-center">
        <h5 class="card-title"><i class="fa fa-history mr-1" style="color:var(--primary)"></i> Recent Activity</h5>
        <div class="input-group dt-search">
            <div class="input-group-prepend"><span class="input-group-text"><i class="fa fa-search"></i></span></div>
            <input type="text" class="form-control dt-search-input" data-scope="activity" placeholder="Search activity...">
        </div>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover data-table mb-0">
                <thead>
                    <tr>
                        <th>Date &amp; Time</th>
                        <th>User</th>
                        <th>Action</th>
                        <th>Module</th>
                        <th>Details</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($logs as $log): ?>
                    <tr>
                        <td class="text-nowrap"><i class="fa fa-clock-o mr-1 text-muted"></i><?php echo e(date('M d, Y H:i', strtotime($log['created_at']))); ?></td>
                        <td>
                            <?php if ($log['first_name']): ?>
                                <strong><?php echo e($log['first_name'] . ' ' . $log['last_name']); ?></strong><br>
                                <small class="text-muted"><?php echo e($log['username']); ?></small>
                            <?php else: ?>
                                <span class="text-muted">—</span>
                            <?php endif; ?>
                        </td>
                        <td><span class="badge <?php echo $badges[$log['action']] ?? 'badge-secondary'; ?>"><?php echo e(strtoupper($log['action'])); ?></span></td>
                        <td>
                            <span class="text-muted"><i class="fa <?php echo $entity_icons[$log['entity_type']] ?? 'fa-circle-o'; ?> mr-1"></i>
                            <?php echo e(str_replace('_', ' ', ucwords($log['entity_type'], '_'))); ?> #<?php echo (int)$log['entity_id']; ?></span>
                        </td>
                        <td class="text-muted"><?php echo e($log['details'] ?? ''); ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php require_once 'footer.php'; ?>
