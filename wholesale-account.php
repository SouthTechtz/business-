<?php
require_once __DIR__ . '/wholesale_auth.php';

$tenant_id = (int)$wholesale_user->tenant_id;
$error = '';
$password_error = '';

$business_types = [
    'Shop / retailer',
    'Wholesaler / distributor',
    'Hotel / restaurant',
    'Office / institution',
    'Online reseller',
    'Other business',
];
$volume_options = [
    'Not sure yet',
    'Under TZS 500,000 per month',
    'TZS 500,000 - 2,000,000 per month',
    'TZS 2,000,000 - 10,000,000 per month',
    'Over TZS 10,000,000 per month',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = (string)($_POST['action'] ?? 'profile');

    if ($action === 'password') {
        $current_password = (string)($_POST['current_password'] ?? '');
        $new_password = (string)($_POST['new_password'] ?? '');
        $confirm_password = (string)($_POST['confirm_password'] ?? '');
        $hash_row = fetch_one("SELECT password_hash FROM users WHERE user_id = ? LIMIT 1", $wholesale_user->user_id);

        if (!$hash_row || !password_verify($current_password, (string)$hash_row['password_hash'])) {
            $password_error = 'Your current password is not correct.';
        } elseif (strlen($new_password) < 8) {
            $password_error = 'Choose a new password of at least 8 characters.';
        } elseif ($new_password !== $confirm_password) {
            $password_error = 'The new passwords do not match.';
        } else {
            $hash = password_hash($new_password, PASSWORD_DEFAULT);
            $stmt = $conn->prepare("UPDATE users SET password_hash = ? WHERE user_id = ?");
            $stmt->bind_param('si', $hash, $wholesale_user->user_id);
            $stmt->execute();
            $stmt->close();
            audit_log('update', 'user', $wholesale_user->user_id, 'Wholesale customer changed their password');
            flash_set('success', 'Your password has been updated.');
            redirect('wholesale-account.php');
        }
    } else {
        $business_name = trim((string)($_POST['business_name'] ?? ''));
        $business_type = trim((string)($_POST['business_type'] ?? ''));
        $contact_name = trim((string)($_POST['contact_name'] ?? ''));
        $phone = trim((string)($_POST['phone'] ?? ''));
        $email = trim((string)($_POST['email'] ?? ''));
        $location = trim((string)($_POST['location'] ?? ''));
        $monthly_volume = trim((string)($_POST['monthly_volume'] ?? ''));
        $product_interest = trim((string)($_POST['product_interest'] ?? ''));
        $notes = trim((string)($_POST['notes'] ?? ''));

        if ($business_name === '') {
            $error = 'Business name is required.';
        } elseif (mb_strlen($business_name) > 191) {
            $error = 'The business name is too long.';
        } elseif ($contact_name === '') {
            $error = 'Contact name is required.';
        } elseif ($location === '') {
            $error = 'Delivery area is required.';
        } elseif ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = 'Enter a valid email address.';
        } elseif (mb_strlen($email) > 191) {
            $error = 'The email address is too long.';
        } elseif (fetch_one(
            "SELECT user_id FROM users WHERE tenant_id = ? AND email = ? AND user_id <> ? LIMIT 1",
            $tenant_id,
            $email,
            $wholesale_user->user_id
        )) {
            $error = 'Another account already uses that email address.';
        } elseif ($business_type !== '' && !in_array($business_type, $business_types, true)) {
            $error = 'Choose a valid business type.';
        } elseif ($monthly_volume !== '' && !in_array($monthly_volume, $volume_options, true)) {
            $error = 'Choose a valid monthly volume range.';
        } elseif (mb_strlen($product_interest) > 255) {
            $error = 'Product interest is too long.';
        } elseif (mb_strlen($notes) > 2000) {
            $error = 'Notes must be under 2,000 characters.';
        } else {
            $name_parts = preg_split('/\s+/', $contact_name, 2);
            $first_name = $name_parts[0] ?? $contact_name;
            $last_name = $name_parts[1] ?? '';

            try {
                $conn->begin_transaction();
                $contact_id = (int)($wholesaler['contact_id'] ?? 0);
                if ($contact_id > 0) {
                    $stmt = $conn->prepare(
                        "UPDATE contacts
                            SET first_name = ?, last_name = ?, company_name = ?, email = ?, phone = ?, address = ?
                          WHERE contact_id = ? AND tenant_id = ?"
                    );
                    if (!$stmt) throw new RuntimeException($conn->error);
                    $stmt->bind_param('ssssssii', $first_name, $last_name, $business_name, $email, $phone, $location, $contact_id, $tenant_id);
                    if (!$stmt->execute()) throw new RuntimeException($stmt->error);
                    $stmt->close();
                }

                $stmt = $conn->prepare(
                    "UPDATE users SET first_name = ?, last_name = ?, email = ?, phone = ? WHERE user_id = ?"
                );
                if (!$stmt) throw new RuntimeException($conn->error);
                $stmt->bind_param('ssssi', $first_name, $last_name, $email, $phone, $wholesale_user->user_id);
                if (!$stmt->execute()) throw new RuntimeException($stmt->error);
                $stmt->close();

                $stmt = $conn->prepare(
                    "UPDATE wholesaler_accounts
                        SET business_name = ?, business_type = ?, contact_name = ?, phone = ?, email = ?,
                            location = ?, monthly_volume = ?, product_interest = ?, notes = ?
                      WHERE wholesaler_id = ? AND tenant_id = ?"
                );
                if (!$stmt) throw new RuntimeException($conn->error);
                $stmt->bind_param(
                    'sssssssssii',
                    $business_name,
                    $business_type,
                    $contact_name,
                    $phone,
                    $email,
                    $location,
                    $monthly_volume,
                    $product_interest,
                    $notes,
                    $wholesaler['wholesaler_id'],
                    $tenant_id
                );
                if (!$stmt->execute()) throw new RuntimeException($stmt->error);
                $stmt->close();

                $conn->commit();
                audit_log('update', 'wholesaler', (int)$wholesaler['wholesaler_id'], 'Wholesale customer updated their account');
                flash_set('success', 'Your account details have been updated.');
                redirect('wholesale-account.php');
            } catch (Throwable $exception) {
                $conn->rollback();
                $error = 'We could not update your account right now. Please try again.';
            }
        }
    }
}

$page_title = 'My account';
require __DIR__ . '/wholesale-portal-header.php';
?>
<div class="ws-page-head">
    <div>
        <h1>My account</h1>
        <p>Keep your business and contact details up to date.</p>
    </div>
    <div class="ws-page-head-actions">
        <a class="ws-btn" href="wholesale-dashboard.php"><i class="fa fa-arrow-left" aria-hidden="true"></i> Dashboard</a>
        <span class="ws-badge <?php echo strtolower((string)$wholesaler['status']) === 'active' ? 'is-active' : 'is-suspended'; ?>">
            <?php echo strtolower((string)$wholesaler['status']) === 'active' ? 'Account active' : 'Account suspended'; ?>
        </span>
    </div>
</div>

<?php if ($error !== ''): ?><div class="ws-form-error" role="alert"><?php echo e($error); ?></div><?php endif; ?>

<div class="ws-grid-2">
    <section class="ws-panel" aria-labelledby="ws-profile-title">
        <div class="ws-panel-head">
            <div>
                <h2 id="ws-profile-title">Business details</h2>
                <p>Used on order requests and delivery communication.</p>
            </div>
        </div>
        <div class="ws-panel-body">
            <form method="post" action="wholesale-account.php">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="action" value="profile">
                <div class="ws-form-grid">
                    <div class="ws-field is-wide">
                        <label for="ws-business-name">Business name <span aria-hidden="true">*</span></label>
                        <input type="text" id="ws-business-name" name="business_name" maxlength="191" required value="<?php echo e($wholesaler['business_name']); ?>">
                    </div>
                    <div class="ws-field">
                        <label for="ws-business-type">Business type</label>
                        <select id="ws-business-type" name="business_type">
                            <option value="">Choose a business type</option>
                            <?php foreach ($business_types as $type): ?>
                                <option value="<?php echo e($type); ?>" <?php echo $wholesaler['business_type'] === $type ? 'selected' : ''; ?>><?php echo e($type); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="ws-field">
                        <label for="ws-account-contact">Contact person <span aria-hidden="true">*</span></label>
                        <input type="text" id="ws-account-contact" name="contact_name" maxlength="191" required value="<?php echo e($wholesaler['contact_name']); ?>">
                    </div>
                    <div class="ws-field">
                        <label for="ws-account-phone">Phone</label>
                        <input type="tel" id="ws-account-phone" name="phone" maxlength="50" value="<?php echo e($wholesaler['phone']); ?>">
                    </div>
                    <div class="ws-field">
                        <label for="ws-account-email">Email <span aria-hidden="true">*</span></label>
                        <input type="email" id="ws-account-email" name="email" maxlength="191" required value="<?php echo e($wholesaler['email']); ?>">
                    </div>
                    <div class="ws-field is-wide">
                        <label for="ws-account-location">Delivery area <span aria-hidden="true">*</span></label>
                        <input type="text" id="ws-account-location" name="location" maxlength="255" required value="<?php echo e($wholesaler['location']); ?>">
                    </div>
                    <div class="ws-field">
                        <label for="ws-account-volume">Estimated monthly volume</label>
                        <select id="ws-account-volume" name="monthly_volume">
                            <option value="">Choose a range</option>
                            <?php foreach ($volume_options as $volume): ?>
                                <option value="<?php echo e($volume); ?>" <?php echo $wholesaler['monthly_volume'] === $volume ? 'selected' : ''; ?>><?php echo e($volume); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="ws-field">
                        <label for="ws-account-interest">Products you buy</label>
                        <input type="text" id="ws-account-interest" name="product_interest" maxlength="255" value="<?php echo e($wholesaler['product_interest']); ?>">
                    </div>
                    <div class="ws-field is-wide">
                        <label for="ws-account-notes">Notes</label>
                        <textarea id="ws-account-notes" name="notes" maxlength="2000"><?php echo e($wholesaler['notes']); ?></textarea>
                    </div>
                </div>
                <div class="ws-form-actions">
                    <button type="submit" class="ws-btn ws-btn-primary"><i class="fa fa-check" aria-hidden="true"></i> Save details</button>
                </div>
            </form>
        </div>
    </section>

    <div>
        <section class="ws-panel" aria-labelledby="ws-security-title">
            <div class="ws-panel-head"><h2 id="ws-security-title">Sign-in details</h2></div>
            <div class="ws-panel-body">
                <dl class="ws-detail-list">
                    <div><dt>Username</dt><dd><?php echo e($wholesaler['username']); ?></dd></div>
                    <div><dt>Sign-in email</dt><dd><?php echo e($wholesaler['email'] ?: 'Not set'); ?></dd></div>
                    <div><dt>Account status</dt><dd><?php echo e(strtolower((string)$wholesaler['status']) === 'active' ? 'Active' : 'Suspended'); ?></dd></div>
                    <div><dt>Last sign-in</dt><dd><?php echo e($wholesaler['last_login'] ? date('d M Y, H:i', strtotime($wholesaler['last_login'])) : '—'); ?></dd></div>
                </dl>
            </div>
        </section>

        <section class="ws-panel" aria-labelledby="ws-password-title">
            <div class="ws-panel-head"><h2 id="ws-password-title">Change password</h2></div>
            <div class="ws-panel-body">
                <?php if ($password_error !== ''): ?><div class="ws-form-error" role="alert"><?php echo e($password_error); ?></div><?php endif; ?>
                <form method="post" action="wholesale-account.php">
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="action" value="password">
                    <div class="ws-form-grid">
                        <div class="ws-field is-wide">
                            <label for="ws-current-password">Current password</label>
                            <input type="password" id="ws-current-password" name="current_password" autocomplete="current-password" required>
                        </div>
                        <div class="ws-field">
                            <label for="ws-new-password">New password</label>
                            <input type="password" id="ws-new-password" name="new_password" minlength="8" autocomplete="new-password" required>
                        </div>
                        <div class="ws-field">
                            <label for="ws-confirm-password">Confirm password</label>
                            <input type="password" id="ws-confirm-password" name="confirm_password" minlength="8" autocomplete="new-password" required>
                        </div>
                    </div>
                    <div class="ws-form-actions">
                        <button type="submit" class="ws-btn ws-btn-primary"><i class="fa fa-lock" aria-hidden="true"></i> Update password</button>
                    </div>
                </form>
            </div>
        </section>
    </div>
</div>
<?php require __DIR__ . '/wholesale-portal-footer.php'; ?>
