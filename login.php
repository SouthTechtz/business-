<?php
require_once 'config.php';
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

if (!empty($_SESSION['user_id'])) {
    redirect('dashboard.php');
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $identifier = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    $user = User::authenticate($identifier, $password);
    if ($user) {
        $_SESSION['user_id'] = $user->user_id;
        $_SESSION['tenant_id'] = $user->tenant_id;
        $current_user = $user;
        audit_log('login', 'user', $user->user_id, 'User signed in');
        redirect('dashboard.php');
    }
    $error = 'Invalid username/email or password.';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Sign In — <?php echo e(APP_NAME); ?></title>
    <link rel="icon" type="image/png" href="assets/img/favicon.png">
    <link rel="stylesheet" href="assets/css/fonts.css">
    <link rel="stylesheet" href="assets/css/bootstrap.min.css">
    <link rel="stylesheet" href="assets/css/font-awesome.min.css">
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body class="login-page">
    <div class="login-wrap">
        <div class="login-card">
            <div class="login-brand">
                <?php if (file_exists('assets/images/logo.png')): ?>
                    <img src="assets/images/logo.png" alt="<?php echo e(APP_NAME); ?>" class="login-logo-img">
                <?php else: ?>
                    <h1><?php echo e(APP_NAME); ?></h1>
                <?php endif; ?>
            </div>
            <?php if ($error): ?>
                <div class="alert alert-danger"><?php echo e($error); ?></div>
            <?php endif; ?>
            <form method="POST" action="login.php">
                <div class="form-group">
                    <label>Username or Email</label>
                    <div class="input-group">
                        <div class="input-group-prepend"><span class="input-group-text"><i class="fa fa-user"></i></span></div>
                        <input type="text" name="username" class="form-control" required autofocus>
                    </div>
                </div>
                <div class="form-group">
                    <label>Password</label>
                    <div class="input-group">
                        <div class="input-group-prepend"><span class="input-group-text"><i class="fa fa-lock"></i></span></div>
                        <input type="password" name="password" class="form-control" required>
                    </div>
                </div>
                <button type="submit" class="btn btn-primary btn-block btn-lg">Sign In</button>
            </form>
        </div>
    </div>
</body>
</html>
