<?php
// ============================================================
// Authentication guard — every protected page requires this
// ============================================================

if (empty($_SESSION['user_id'])) {
    redirect('login.php');
}

$current_user = User::getUserById($_SESSION['user_id']);
if (!$current_user || !$current_user->is_active) {
    session_destroy();
    redirect('login.php');
}

// Never let browsers cache protected pages (back button after logout must not work)
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

/** Guard helper: die with 403 unless the current user has the permission. */
function require_permission($permission) {
    global $current_user;
    if (!$current_user->hasPermission($permission)) {
        http_response_code(403);
        die('You do not have permission to access this page.');
    }
}

/** Guard helper: die with 403 unless the current user has ANY of the given permissions. */
function require_permission_any($permissions) {
    global $current_user;
    foreach ($permissions as $p) {
        if ($current_user->hasPermission($p)) return;
    }
    http_response_code(403);
    die('You do not have permission to access this page.');
}
