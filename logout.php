<?php
require_once 'config.php';
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
if (!empty($_SESSION['user_id'])) {
    $current_user = User::getUserById((int)$_SESSION['user_id']);
    if ($current_user) {
        audit_log('logout', 'user', $current_user->user_id, 'User signed out');
    }
}
$_SESSION = [];
session_destroy();
redirect('login.php');
