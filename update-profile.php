<?php
require_once 'config.php';
require_once 'auth_check.php';

$first_name = trim($_POST['first_name'] ?? '');
$last_name = trim($_POST['last_name'] ?? '');
$email = trim($_POST['email'] ?? '');
$phone = trim($_POST['phone'] ?? '');
$new_password = $_POST['new_password'] ?? '';
$confirm_password = $_POST['confirm_password'] ?? '';

if ($first_name === '' || $last_name === '' || $email === '') {
    flash_set('danger', 'Name and email are required.');
} elseif ($new_password !== '' && strlen($new_password) < 6) {
    flash_set('danger', 'New password must be at least 6 characters long.');
} elseif ($new_password !== '' && $new_password !== $confirm_password) {
    flash_set('danger', 'Passwords do not match.');
} else {
    $dup = fetch_one(
        "SELECT COUNT(*) c FROM users WHERE tenant_id = ? AND email = ? AND user_id != ?",
        $current_user->tenant_id, $email, $current_user->user_id
    );
    if (($dup['c'] ?? 0) > 0) {
        flash_set('danger', 'Email is already in use by another user.');
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
        flash_set('success', 'Profile updated successfully.');
    }
}
redirect('profile.php');
