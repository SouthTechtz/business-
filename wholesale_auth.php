<?php
/**
 * Guard for the wholesale customer portal.
 * Keeps wholesale accounts out of staff screens and suspended accounts out entirely.
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/wholesale.php';

if (empty($_SESSION['user_id'])) {
    flash_set('warning', 'Please sign in to open your wholesale account.');
    redirect('login.php');
}

$wholesale_user = User::getUserById((int)$_SESSION['user_id']);
if (!$wholesale_user || !$wholesale_user->is_active) {
    end_session();
    redirect('login.php');
}

if (!is_wholesaler($wholesale_user)) {
    redirect('dashboard.php');
}

$wholesaler = wholesaler_account_for_user($wholesale_user->user_id, $wholesale_user->tenant_id);
if (!$wholesaler) {
    http_response_code(403);
    die('This login is not linked to a wholesale account. Please contact us.');
}

if (strtolower(trim((string)$wholesaler['status'])) !== 'active') {
    end_session();
    flash_set('danger', 'Your wholesale account is suspended. Please contact us for help.');
    redirect('login.php');
}

// Keep the shared helpers (audit_log, flash, etc.) pointed at this user.
$current_user = $wholesale_user;

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
