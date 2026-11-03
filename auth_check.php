<?php
// ============================================================
// Authentication guard — every protected page requires this
// ============================================================

if (empty($_SESSION['user_id'])) {
    redirect('login.php');
}

$current_user = User::getUserById((int)$_SESSION['user_id']);
if (!$current_user || !$current_user->is_active) {
    end_session();
    redirect('login.php');
}

// Never let browsers cache protected pages (back button after logout must not work)
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

if (is_godown_manager()) {
    if (!godown_manager_page_allowed($_SERVER['SCRIPT_NAME'] ?? '')) {
        http_response_code(403);
        die('Your Godown Manager role does not allow access to this page.');
    }
}

/** Guard helper: die with 403 unless the current user has the permission. */
function require_permission($permission) {
    global $current_user;
    if (!can($permission)) {
        http_response_code(403);
        die('You do not have permission to access this page.');
    }
}

/** Guard helper: die with 403 unless the current user has ANY of the given permissions. */
function require_permission_any($permissions) {
    foreach ($permissions as $p) {
        if (can($p)) return;
    }
    http_response_code(403);
    die('You do not have permission to access this page.');
}
