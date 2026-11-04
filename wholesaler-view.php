<?php
require_once 'header.php';
require_once 'auth_check.php';
require_system_administrator();
require_once 'wholesale.php';
$page_title = 'Wholesale Account';

$id = (int)($_GET['id'] ?? 0);
$account = $id > 0 ? wholesaler_account_by_id($id, $current_user->tenant_id) : null;
if (!$account) {
    flash_set('danger', 'Wholesale account not found.');
    redirect('wholesalers.php');
}

$account_error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = (string)($_POST['action'] ?? '');

    if ($action === 'suspend' || $action === 'activate') {
        $new_status = $action === 'activate' ? 'active' : 'suspended';
        $conn->begin_transaction();
        try {
            $stmt = $conn->prepare("UPDATE wholesaler_accounts SET status = ? WHERE wholesaler_id = ? AND tenant_id = ?");
            $stmt->bind_param('sii', $new_status, $id, $current_user->tenant_id);
            $stmt->execute();
            $stmt->close();

            $active = $new_status === 'active' ? 1 : 0;
            $stmt = $conn->prepare("UPDATE users SET is_active = ? WHERE user_id = ? AND tenant_id = ?");
            $stmt->bind_param('iii', $active, $account['user_id'], $current_user->tenant_id);
            $stmt->execute();
            $stmt->close();

            $conn->commit();
            audit_log(
                $new_status === 'active' ? 'activate' : 'suspend',
                'wholesaler',
                $id,
                'Wholesale account ' . ($new_status === 'active' ? 'activated' : 'suspended') . ': ' . $account['business_name']
            );
            flash_set('success', 'Wholesale account ' . ($new_status === 'active' ? 'activated' : 'suspended') . '.');
            redirect('wholesaler-view.php?id=' . $id);
        } catch (Throwable $exception) {
            $conn->rollback();
            flash_set('danger', 'Could not update the wholesale account.');
            redirect('wholesaler-view.php?id=' . $id);
        }
    }

    if ($action === 'update_details') {
        $name = trim((string)($_POST['name'] ?? ''));
        $phone = trim((string)($_POST['phone'] ?? ''));
        $email = trim((string)($_POST['email'] ?? ''));
        $location = trim((string)($_POST['location'] ?? ''));

        if ($name === '') {
            $account_error = 'Enter the customer name.';
        } elseif ($location === '') {
            $account_error = 'Enter the delivery location.';
        } elseif (strlen(preg_replace('/\D+/', '', $phone)) < 7) {
            $account_error = 'Enter a valid phone number.';
        } elseif ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $account_error = 'Enter a valid email address, or leave it empty.';
        } elseif ($email !== '' && fetch_one(
            "SELECT user_id FROM users WHERE tenant_id = ? AND email = ? AND user_id <> ? LIMIT 1",
            $current_user->tenant_id,
            $email,
            $account['user_id']
        )) {
            $account_error = 'That email address is already used by another account.';
        } else {
            $name_parts = preg_split('/\s+/', $name, 2);
            $first_name = $name_parts[0] ?? $name;
            $last_name = $name_parts[1] ?? '';
            $contact_id = (int)($account['contact_id'] ?? 0);
            $email_db = $email !== '' ? $email : null;
            try {
                $conn->begin_transaction();
                if ($contact_id > 0) {
                    $stmt = $conn->prepare(
                        "UPDATE contacts SET first_name = ?, last_name = ?, company_name = ?, phone = ?, email = ?, address = ?
                          WHERE contact_id = ? AND tenant_id = ?"
                    );
                    if (!$stmt) throw new RuntimeException($conn->error);
                    $stmt->bind_param('ssssssii', $first_name, $last_name, $name, $phone, $email_db, $location, $contact_id, $current_user->tenant_id);
                    $stmt->execute();
                    $stmt->close();
                }
                $stmt = $conn->prepare("UPDATE users SET first_name = ?, last_name = ?, phone = ?, email = ? WHERE user_id = ? AND tenant_id = ?");
                if (!$stmt) throw new RuntimeException($conn->error);
                $user_email = $email !== '' ? $email : (string)$account['username'] . '@wholesale.local';
                $stmt->bind_param('ssssii', $first_name, $last_name, $phone, $user_email, $account['user_id'], $current_user->tenant_id);
                $stmt->execute();
                $stmt->close();
                $stmt = $conn->prepare("UPDATE wholesaler_accounts SET business_name = ?, contact_name = ?, phone = ?, email = ?, location = ? WHERE wholesaler_id = ? AND tenant_id = ?");
                if (!$stmt) throw new RuntimeException($conn->error);
                $stmt->bind_param('sssssii', $name, $name, $phone, $email_db, $location, $id, $current_user->tenant_id);
                $stmt->execute();
                $stmt->close();
                $conn->commit();
                audit_log('update', 'wholesaler', $id, 'Wholesale customer details updated: ' . $name);
                flash_set('success', 'Wholesale customer details updated.');
                redirect('wholesaler-view.php?id=' . $id);
            } catch (Throwable $exception) {
                $conn->rollback();
                $account_error = 'Could not update the wholesale customer.';
            }
        }
    }

    if ($action === 'reset_password') {
        $new_password = (string)($_POST['new_password'] ?? '');
        $confirm_password = (string)($_POST['confirm_password'] ?? '');
        if (strlen($new_password) < 8) {
            $account_error = 'The new password must be at least 8 characters.';
        } elseif ($new_password !== $confirm_password) {
            $account_error = 'The passwords do not match.';
        } else {
            $hash = password_hash($new_password, PASSWORD_DEFAULT);
            $stmt = $conn->prepare("UPDATE users SET password_hash = ? WHERE user_id = ? AND tenant_id = ?");
            $stmt->bind_param('sii', $hash, $account['user_id'], $current_user->tenant_id);
            $stmt->execute();
            $stmt->close();
            audit_log('update', 'wholesaler', $id, 'Password reset for wholesale account: ' . $account['business_name']);
            flash_set('success', 'The wholesale account password has been reset.');
            redirect('wholesaler-view.php?id=' . $id);
        }
    }
}

$contact_id = (int)($account['contact_id'] ?? 0);
$stats = wholesale_stats($current_user->tenant_id, $contact_id);
$requests = $contact_id ? wholesale_requests($current_user->tenant_id, $contact_id, 25, 0) : [];
$orders = $contact_id ? wholesale_orders($current_user->tenant_id, $contact_id, 25) : [];
$activity = wholesale_activity($current_user->tenant_id, (int)$account['user_id'], 25);
$currency = tenant_currency();
$is_active = strtolower((string)$account['status']) === 'active';

require_once 'navbar.php';
require_once 'sidebar.php';
?>
<div class="page-head">
    <div>
        <h1 class="page-title"><?php echo e($account['business_name']); ?></h1>
        <div class="page-sub">
            Wholesale account &middot;
            <span class="badge <?php echo $is_active ? 'badge-success' : 'badge-danger'; ?>"><?php echo $is_active ? 'Active' : 'Suspended'; ?></span>
        </div>
    </div>
    <div>
        <form method="post" action="wholesaler-view.php?id=<?php echo $id; ?>" class="d-inline" onsubmit="return confirm('<?php echo $is_active ? 'Suspend this account?' : 'Activate this account?'; ?>');">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="action" value="<?php echo $is_active ? 'suspend' : 'activate'; ?>">
            <button type="submit" class="btn <?php echo $is_active ? 'btn-outline-danger' : 'btn-success'; ?>">
                <i class="fa <?php echo $is_active ? 'fa-ban' : 'fa-check'; ?> mr-1"></i>
                <?php echo $is_active ? 'Suspend account' : 'Activate account'; ?>
            </button>
        </form>
        <a href="customers.php?tab=wholesalers" class="btn btn-light"><i class="fa fa-arrow-left mr-1"></i> Back</a>
    </div>
</div>

<?php if ($f = flash_get()): ?>
    <div class="alert alert-<?php echo e($f['type']); ?>"><?php echo e($f['message']); ?></div>
<?php endif; ?>
<?php if ($account_error !== ''): ?>
    <div class="alert alert-danger"><?php echo e($account_error); ?></div>
<?php endif; ?>

<div class="row mb-3">
    <div class="col-md-6 col-xl-3 mb-3"><div class="widget"><div class="widget-icon wi-blue"><i class="fa fa-paper-plane"></i></div><div class="widget-info"><div class="widget-value"><?php echo (int)$stats['request_count']; ?></div><div class="widget-label">Order requests</div></div></div></div>
    <div class="col-md-6 col-xl-3 mb-3"><div class="widget"><div class="widget-icon wi-green"><i class="fa fa-check-circle"></i></div><div class="widget-info"><div class="widget-value"><?php echo (int)$stats['order_count']; ?></div><div class="widget-label">Confirmed orders</div></div></div></div>
    <div class="col-md-6 col-xl-3 mb-3"><div class="widget"><div class="widget-icon wi-purple"><i class="fa fa-money"></i></div><div class="widget-info"><div class="widget-value" style="font-size:16px;"><?php echo e(money($stats['order_value'], $currency)); ?></div><div class="widget-label">Order value</div></div></div></div>
    <div class="col-md-6 col-xl-3 mb-3"><div class="widget"><div class="widget-icon wi-amber"><i class="fa fa-clock-o"></i></div><div class="widget-info"><div class="widget-value" style="font-size:15px;"><?php echo e($account['last_login'] ? date('d M Y', strtotime($account['last_login'])) : 'Never'); ?></div><div class="widget-label">Last sign-in</div></div></div></div>
</div>

<div class="row">
    <div class="col-lg-4 mb-3">
        <div class="card">
            <div class="card-header"><h5 class="card-title">Business details</h5></div>
            <div class="card-body">
                <table class="table">
                    <tbody>
                        <tr><th>Business</th><td><?php echo e($account['business_name']); ?></td></tr>
                        <tr><th>Type</th><td><?php echo e($account['business_type'] ?: '—'); ?></td></tr>
                        <tr><th>Contact</th><td><?php echo e($account['contact_name']); ?></td></tr>
                        <tr><th>Phone</th><td><?php echo e($account['phone'] ?: '—'); ?></td></tr>
                        <tr><th>Email</th><td><?php echo e($account['email'] ?: '—'); ?></td></tr>
                        <tr><th>Location</th><td><?php echo e($account['location'] ?: '—'); ?></td></tr>
                        <tr><th>Monthly volume</th><td><?php echo e($account['monthly_volume'] ?: '—'); ?></td></tr>
                        <tr><th>Products</th><td><?php echo e($account['product_interest'] ?: '—'); ?></td></tr>
                        <tr><th>Customer #</th><td><?php echo e($account['customer_number'] ?: '—'); ?></td></tr>
                        <tr><th>Channel</th><td><?php echo e(strtolower((string)$account['customer_channel']) === 'online' ? 'Online' : 'Walk-in'); ?></td></tr>
                        <tr><th>Registered</th><td><?php echo e(date('d M Y', strtotime($account['created_at']))); ?></td></tr>
                    </tbody>
                </table>
                <?php if ($contact_id > 0): ?>
                    <a href="customer-view.php?id=<?php echo $contact_id; ?>" class="btn btn-sm btn-light"><i class="fa fa-address-book mr-1"></i> Open customer record</a>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="col-lg-8 mb-3">
        <div class="card mb-3">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h5 class="card-title">Order requests</h5>
                <span class="text-muted small"><?php echo count($requests); ?> shown</span>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover mb-0">
                        <thead><tr><th>Request</th><th>Date</th><th class="text-right">Value</th><th>Status</th><th>Order</th></tr></thead>
                        <tbody>
                            <?php foreach ($requests as $request_row): ?>
                                <?php $status_meta = wholesale_status_meta($request_row['status']); ?>
                                <tr>
                                    <td><strong><?php echo e($request_row['lead_number']); ?></strong></td>
                                    <td><?php echo e(date('d M Y', strtotime($request_row['created_at']))); ?></td>
                                    <td class="text-right"><?php echo e(money($request_row['subtotal'], $request_row['currency_code'] ?: $currency)); ?></td>
                                    <td><span class="badge badge-secondary"><?php echo e($status_meta['label']); ?></span></td>
                                    <td><?php echo e($request_row['order_number'] ?: '—'); ?></td>
                                </tr>
                            <?php endforeach; ?>
                            <?php if (!$requests): ?><tr><td colspan="5" class="text-center text-muted py-3">No order requests yet.</td></tr><?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div class="card mb-3">
            <div class="card-header"><h5 class="card-title">Activity</h5></div>
            <div class="card-body">
                <?php if ($activity): ?>
                    <ul class="ws-timeline" style="margin:0;">
                        <?php foreach ($activity as $entry): ?>
                            <li>
                                <strong><?php echo e(ucfirst((string)$entry['action'])); ?></strong>
                                <?php if (!empty($entry['details'])): ?><span><?php echo e($entry['details']); ?></span><?php endif; ?>
                                <span><?php echo e(date('d M Y, H:i', strtotime($entry['created_at']))); ?></span>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php else: ?>
                    <p class="text-muted mb-0">No account activity recorded yet.</p>
                <?php endif; ?>
            </div>
        </div>

        <div class="card mb-3">
            <div class="card-header"><h5 class="card-title">Update customer details</h5></div>
            <div class="card-body">
                <form method="post" action="wholesaler-view.php?id=<?php echo $id; ?>">
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="action" value="update_details">
                    <div class="row">
                        <div class="col-md-6 form-group">
                            <label>Name or business name</label>
                            <input type="text" name="name" class="form-control" maxlength="191" required value="<?php echo e($account['business_name']); ?>">
                        </div>
                        <div class="col-md-6 form-group">
                            <label>Phone number</label>
                            <input type="tel" name="phone" class="form-control" maxlength="50" required value="<?php echo e($account['phone']); ?>">
                        </div>
                        <div class="col-md-6 form-group">
                            <label>Email <small class="text-muted">(optional)</small></label>
                            <input type="email" name="email" class="form-control" maxlength="191" value="<?php echo e($account['email']); ?>">
                        </div>
                        <div class="col-md-6 form-group">
                            <label>Location</label>
                            <input type="text" name="location" class="form-control" maxlength="255" required value="<?php echo e($account['location']); ?>">
                        </div>
                    </div>
                    <button type="submit" class="btn btn-sm btn-primary"><i class="fa fa-check mr-1"></i> Save changes</button>
                </form>
            </div>
        </div>

        <div class="card">
            <div class="card-header"><h5 class="card-title">Reset password</h5></div>
            <div class="card-body">
                <form method="post" action="wholesaler-view.php?id=<?php echo $id; ?>" class="form-inline" style="gap:10px;flex-wrap:wrap;">
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="action" value="reset_password">
                    <div class="form-group mb-0">
                        <label class="mr-2 small">New password</label>
                        <input type="password" name="new_password" class="form-control form-control-sm" minlength="8" required>
                    </div>
                    <div class="form-group mb-0">
                        <label class="mr-2 small">Confirm</label>
                        <input type="password" name="confirm_password" class="form-control form-control-sm" minlength="8" required>
                    </div>
                    <button type="submit" class="btn btn-sm btn-outline-primary"><i class="fa fa-key mr-1"></i> Reset password</button>
                </form>
            </div>
        </div>
    </div>
</div>

<?php require_once 'footer.php'; ?>
