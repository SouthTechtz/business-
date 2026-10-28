<?php
require_once 'header.php';
require_once 'auth_check.php';
$page_title = 'My Profile';

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $first_name = trim($_POST['first_name'] ?? '');
    $last_name = trim($_POST['last_name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $new_password = $_POST['new_password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';

    if ($first_name === '' || $last_name === '' || $email === '') {
        $error = 'Name and email are required.';
    } elseif ($new_password !== '' && strlen($new_password) < 6) {
        $error = 'New password must be at least 6 characters long.';
    } elseif ($new_password !== '' && $new_password !== $confirm_password) {
        $error = 'Passwords do not match.';
    } else {
        $dup = fetch_one(
            "SELECT COUNT(*) c FROM users WHERE tenant_id = ? AND email = ? AND user_id != ?",
            $current_user->tenant_id, $email, $current_user->user_id
        );
        if (($dup['c'] ?? 0) > 0) {
            $error = 'Email is already in use by another user.';
        } else {
            if ($new_password !== '') {
                $hash = password_hash($new_password, PASSWORD_DEFAULT);
                $stmt = $conn->prepare(
                    "UPDATE users SET first_name = ?, last_name = ?, email = ?, phone = ?, password_hash = ? WHERE user_id = ? AND tenant_id = ?"
                );
                $stmt->bind_param('sssssii', $first_name, $last_name, $email, $phone, $hash, $current_user->user_id, $current_user->tenant_id);
            } else {
                $stmt = $conn->prepare(
                    "UPDATE users SET first_name = ?, last_name = ?, email = ?, phone = ? WHERE user_id = ? AND tenant_id = ?"
                );
                $stmt->bind_param('ssssii', $first_name, $last_name, $email, $phone, $current_user->user_id, $current_user->tenant_id);
            }
            $stmt->execute();
            $stmt->close();
            $current_user = User::getUserById($current_user->user_id);
            $success = 'Profile updated successfully.';
        }
    }
}

require_once 'navbar.php';
require_once 'sidebar.php';
?>

<div class="page-head">
    <div>
        <h1 class="page-title">My Profile</h1>
        <div class="page-sub">Update your account information.</div>
    </div>
</div>

<?php if ($error): ?>
    <div class="alert alert-danger"><?php echo e($error); ?></div>
<?php endif; ?>
<?php if ($success): ?>
    <div class="alert alert-success"><?php echo e($success); ?></div>
<?php endif; ?>

<div class="row">
    <div class="col-lg-6">
        <div class="card">
            <div class="card-header"><h5 class="card-title">Account Information</h5></div>
            <div class="card-body">
                <div class="text-center mb-4">
                    <span class="avatar mx-auto mb-2" style="width:72px;height:72px;font-size:28px;"><?php echo e(strtoupper(substr($current_user->first_name, 0, 1))); ?></span>
                    <div class="font-weight-semibold"><?php echo e($current_user->fullName()); ?></div>
                    <div class="text-muted small">@<?php echo e($current_user->username); ?></div>
                </div>
                <form method="POST" action="profile.php">
                    <div class="row">
                        <div class="col-md-6 form-group">
                            <label>First Name <span class="text-danger">*</span></label>
                            <input type="text" name="first_name" class="form-control" required value="<?php echo e($current_user->first_name); ?>">
                        </div>
                        <div class="col-md-6 form-group">
                            <label>Last Name <span class="text-danger">*</span></label>
                            <input type="text" name="last_name" class="form-control" required value="<?php echo e($current_user->last_name); ?>">
                        </div>
                        <div class="col-md-6 form-group">
                            <label>Email <span class="text-danger">*</span></label>
                            <input type="email" name="email" class="form-control" required value="<?php echo e($current_user->email); ?>">
                        </div>
                        <div class="col-md-6 form-group">
                            <label>Phone</label>
                            <input type="text" name="phone" class="form-control" value="<?php echo e($current_user->phone); ?>">
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
                    <button type="submit" class="btn btn-primary"><i class="fa fa-check mr-1"></i> Update Profile</button>
                </form>
            </div>
        </div>
    </div>
</div>

<?php require_once 'footer.php'; ?>
