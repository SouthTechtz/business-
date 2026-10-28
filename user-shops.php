<?php
require_once 'header.php';
require_once 'auth_check.php';
require_permission('manage_users');
require_permission('manage_shops');
$page_title = 'User Shop Access';

$user_id = (int)($_GET['user'] ?? $_POST['user_id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $user_id = (int)($_POST['user_id'] ?? 0);
    $user = fetch_one(
        "SELECT * FROM users WHERE user_id = ? AND tenant_id = ?",
        $user_id,
        $current_user->tenant_id
    );
    if (!$user) {
        flash_set('danger', 'User not found.');
        redirect('users.php');
    }

    $selected = array_map('intval', (array)($_POST['shops'] ?? []));
    $tenant_shops = fetch_all(
        "SELECT shop_id FROM shops WHERE tenant_id = ? ORDER BY shop_id",
        $current_user->tenant_id
    );
    $allowed = array_map(fn($row) => (int)$row['shop_id'], $tenant_shops);
    $selected = array_values(array_intersect($selected, $allowed));

    $conn->begin_transaction();
    try {
        $stmt = $conn->prepare("DELETE FROM user_shops WHERE tenant_id = ? AND user_id = ?");
        $stmt->bind_param('ii', $current_user->tenant_id, $user_id);
        $stmt->execute();
        $stmt->close();

        $stmt = $conn->prepare(
            "INSERT INTO user_shops (tenant_id, user_id, shop_id) VALUES (?, ?, ?)"
        );
        foreach ($selected as $shop_id) {
            $stmt->bind_param('iii', $current_user->tenant_id, $user_id, $shop_id);
            $stmt->execute();
        }
        $stmt->close();
        $conn->commit();

        audit_log(
            'update',
            'user_shops',
            $user_id,
            'Shop access updated for ' . $user['username'] . ': ' . (count($selected) ? implode(',', $selected) : 'none')
        );
        if ($selected) {
            flash_set('success', 'Shop access updated.');
        } else {
            flash_set('warning', 'No shops assigned. This user will not see shop-scoped sales, invoices, or inventory.');
        }
    } catch (Throwable $ex) {
        $conn->rollback();
        flash_set('danger', 'Could not update shop access: ' . $ex->getMessage());
    }
    redirect('user-shops.php?user=' . $user_id);
}

$users = fetch_all(
    "SELECT user_id, username, first_name, last_name, email, is_active
     FROM users WHERE tenant_id = ? ORDER BY first_name, last_name, username",
    $current_user->tenant_id
);
$user = null;
if ($user_id > 0) {
    $user = fetch_one(
        "SELECT * FROM users WHERE user_id = ? AND tenant_id = ?",
        $user_id,
        $current_user->tenant_id
    );
    if (!$user) {
        flash_set('danger', 'User not found.');
        redirect('users.php');
    }
}

$shops = fetch_all(
    "SELECT * FROM shops WHERE tenant_id = ? ORDER BY name",
    $current_user->tenant_id
);
$assigned = [];
if ($user) {
    $assigned = array_map(
        fn($row) => (int)$row['shop_id'],
        fetch_all(
            "SELECT shop_id FROM user_shops WHERE tenant_id = ? AND user_id = ?",
            $current_user->tenant_id,
            $user_id
        )
    );
}

require_once 'navbar.php';
require_once 'sidebar.php';
?>

<div class="page-head">
    <div>
        <h1 class="page-title">User Shop Access</h1>
        <div class="page-sub">Control which shops a user can see and operate in. Tenant administrators always see all shops.</div>
    </div>
    <a href="users.php" class="btn btn-light"><i class="fa fa-arrow-left mr-1"></i> Users</a>
</div>

<?php if ($f = flash_get()): ?>
    <div class="alert alert-<?php echo e($f['type']); ?>"><?php echo e($f['message']); ?></div>
<?php endif; ?>

<div class="row">
    <div class="col-lg-4">
        <div class="card">
            <div class="card-header"><h5 class="card-title">Select User</h5></div>
            <div class="card-body p-0">
                <div class="list-group list-group-flush">
                    <?php foreach ($users as $u): ?>
                        <?php $is_selected = (int)$u['user_id'] === (int)$user_id; ?>
                        <a class="list-group-item list-group-item-action d-flex justify-content-between align-items-center <?php echo $is_selected ? 'active' : ''; ?>" href="user-shops.php?user=<?php echo (int)$u['user_id']; ?>">
                            <span>
                                <?php echo e(trim(($u['first_name'] ?? '') . ' ' . ($u['last_name'] ?? ''))); ?>
                                <small class="d-block"><?php echo e($u['username']); ?> · <?php echo e($u['email']); ?></small>
                            </span>
                            <?php if (!(int)$u['is_active']): ?><span class="badge badge-secondary">Inactive</span><?php endif; ?>
                        </a>
                    <?php endforeach; ?>
                    <?php if (!$users): ?>
                        <div class="list-group-item text-muted">No users yet.</div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <div class="col-lg-8">
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h5 class="card-title mb-0">
                    <?php if ($user): ?>
                        Shop Access — <?php echo e(trim(($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? ''))); ?>
                    <?php else: ?>
                        Shop Access
                    <?php endif; ?>
                </h5>
                <?php if ($user && tenant_admin_scope()): ?>
                    <span class="badge badge-info">Administrator sees all shops</span>
                <?php endif; ?>
            </div>
            <div class="card-body">
                <?php if (!$user): ?>
                    <p class="text-muted mb-0">Choose a user on the left to manage their shop access.</p>
                <?php elseif (!$shops): ?>
                    <p class="text-muted mb-0">No shops exist yet. <a href="shops.php">Create a shop</a> first.</p>
                <?php else: ?>
                    <form method="POST" action="user-shops.php">
                        <?php echo csrf_field(); ?>
                        <input type="hidden" name="user_id" value="<?php echo (int)$user['user_id']; ?>">

                        <div class="d-flex justify-content-between align-items-center mb-2">
                            <div class="text-muted small">Select every shop this user may access.</div>
                            <div>
                                <button type="button" class="btn btn-sm btn-light" onclick="document.querySelectorAll('input[name=&quot;shops[]&quot;]').forEach(function (cb) { cb.checked = true; })">Select all</button>
                                <button type="button" class="btn btn-sm btn-light" onclick="document.querySelectorAll('input[name=&quot;shops[]&quot;]').forEach(function (cb) { cb.checked = false; })">Clear</button>
                            </div>
                        </div>

                        <div class="row">
                            <?php foreach ($shops as $shop): ?>
                                <div class="col-md-6 mb-3">
                                    <div class="border rounded p-3 h-100">
                                        <div class="custom-control custom-checkbox">
                                            <input type="checkbox" class="custom-control-input" name="shops[]" id="shop_<?php echo (int)$shop['shop_id']; ?>" value="<?php echo (int)$shop['shop_id']; ?>" <?php echo in_array((int)$shop['shop_id'], $assigned, true) ? 'checked' : ''; ?>>
                                            <label class="custom-control-label font-weight-semibold" for="shop_<?php echo (int)$shop['shop_id']; ?>"><?php echo e($shop['name']); ?></label>
                                        </div>
                                        <div class="small text-muted mt-1">
                                            <?php echo e($shop['code']); ?>
                                            <?php if (!(int)$shop['is_active']): ?>
                                                <span class="badge badge-secondary ml-1">Inactive</span>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>

                        <div class="alert alert-info small">
                            Shop access controls operational visibility. Tenant administrators with user/settings management always have full access.
                        </div>

                        <button type="submit" class="btn btn-primary"><i class="fa fa-save mr-1"></i> Save Access</button>
                    </form>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<?php require_once 'footer.php'; ?>
