<?php
if (empty($wholesaler) || empty($wholesale_user)) {
    exit('Wholesale session not initialised.');
}
$ws_settings = fetch_one("SELECT * FROM system_settings WHERE tenant_id = ? LIMIT 1", (int)$wholesale_user->tenant_id);
$ws_company = trim((string)($ws_settings['company_name'] ?? '')) ?: system_title((int)$wholesale_user->tenant_id);
$ws_logo = trim((string)($ws_settings['logo_path'] ?? ''));
if ($ws_logo === '' || !file_exists(__DIR__ . '/' . ltrim($ws_logo, '/'))) {
    $ws_logo = file_exists(__DIR__ . '/assets/images/logo.png') ? 'assets/images/logo.png' : '';
}
$ws_current = basename((string)($_SERVER['SCRIPT_NAME'] ?? ''));
$ws_title = isset($page_title) && $page_title !== '' ? $page_title : 'Wholesale portal';
$ws_nav = [
    'wholesale-dashboard.php'      => ['Dashboard', 'fa-tachometer'],
    'wholesale-order-new.php'      => ['New order', 'fa-plus-circle'],
    'wholesale-orders.php'         => ['My orders', 'fa-list-alt'],
    'wholesale-notifications.php'  => ['Notifications', 'fa-bell'],
    'wholesale-activity.php'       => ['Activity', 'fa-history'],
    'wholesale-account.php'        => ['My account', 'fa-user-circle'],
];
$ws_unread_notifications = wholesale_unread_notification_count((int)$wholesale_user->tenant_id, (int)$wholesale_user->user_id);
$ws_name = trim((string)$wholesaler['business_name']);
if ($ws_name === '') $ws_name = $wholesale_user->fullName();
$ws_contact_line = trim((string)($wholesaler['email'] ?? ''));
if ($ws_contact_line === '') $ws_contact_line = trim((string)($wholesaler['phone'] ?? ''));
if ($ws_contact_line === '') $ws_contact_line = (string)$wholesale_user->username;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?php echo e($ws_title); ?> &middot; <?php echo e($ws_company); ?></title>
    <link rel="icon" type="image/png" href="assets/img/favicon.png">
    <link rel="stylesheet" href="assets/css/fonts.css?v=<?php echo filemtime('assets/css/fonts.css'); ?>">
    <link rel="stylesheet" href="assets/css/bootstrap.min.css?v=<?php echo filemtime('assets/css/bootstrap.min.css'); ?>">
    <link rel="stylesheet" href="assets/css/font-awesome.min.css?v=<?php echo filemtime('assets/css/font-awesome.min.css'); ?>">
    <link rel="stylesheet" href="assets/css/wholesale.css?v=<?php echo file_exists('assets/css/wholesale.css') ? filemtime('assets/css/wholesale.css') : time(); ?>">
</head>
<body class="ws-body">
<header class="ws-header">
    <div class="ws-container ws-header-inner">
        <a class="ws-brand" href="wholesale-dashboard.php">
            <?php if ($ws_logo !== ''): ?>
                <img src="<?php echo e($ws_logo); ?>" alt="" class="ws-brand-logo">
            <?php endif; ?>
            <span class="ws-brand-text">
                <strong><?php echo e($ws_company); ?></strong>
                <small>Wholesale portal</small>
            </span>
        </a>

        <nav class="ws-nav" aria-label="Wholesale portal">
            <?php foreach ($ws_nav as $ws_href => $ws_item): ?>
                <a href="<?php echo e($ws_href); ?>" class="ws-nav-link<?php echo $ws_current === $ws_href ? ' is-active' : ''; ?>"
                   <?php echo $ws_href === 'wholesale-notifications.php' ? 'id="wsNotifyNav"' : ''; ?>
                   <?php echo $ws_current === $ws_href ? 'aria-current="page"' : ''; ?>>
                    <i class="fa <?php echo e($ws_item[1]); ?>" aria-hidden="true"></i>
                    <span><?php echo e($ws_item[0]); ?></span>
                    <?php if ($ws_href === 'wholesale-notifications.php' && $ws_unread_notifications > 0): ?>
                        <span class="ws-nav-badge" aria-label="<?php echo (int)$ws_unread_notifications; ?> unread notifications"><?php echo (int)$ws_unread_notifications; ?></span>
                    <?php endif; ?>
                </a>
            <?php endforeach; ?>
        </nav>

        <div class="ws-user">
            <span class="ws-user-avatar"><?php echo e(strtoupper(mb_substr($ws_name, 0, 1))); ?></span>
            <span class="ws-user-meta">
                <strong><?php echo e($ws_name); ?></strong>
                <small><?php echo e($ws_contact_line); ?></small>
            </span>
            <a class="ws-logout" href="logout.php" title="Sign out"><i class="fa fa-sign-out" aria-hidden="true"></i><span>Sign out</span></a>
        </div>
    </div>
</header>

<?php $ws_flash = flash_get(); ?>
<?php if ($ws_flash): ?>
    <div class="ws-container ws-flash-wrap">
        <div class="alert alert-<?php echo e($ws_flash['type']); ?> ws-flash" role="alert"><?php echo e($ws_flash['message']); ?></div>
    </div>
<?php endif; ?>

<main class="ws-main">
    <div class="ws-container">
