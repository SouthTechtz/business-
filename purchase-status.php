<?php
require_once 'header.php';
require_once 'auth_check.php';
require_permission('manage_purchases');

$tenant_id = (int)$current_user->tenant_id;
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('purchases.php');
}

verify_csrf();
$id = (int)($_POST['purchase_id'] ?? 0);
$status = (string)($_POST['status'] ?? '');
$statuses = purchase_goods_statuses();

if ($id <= 0 || !isset($statuses[$status])) {
    flash_set('danger', 'Select a valid purchase and goods status.');
    redirect('purchases.php');
}

$owner_filter = is_godown_manager() ? ' AND created_by = ?' : '';
$lookup_params = [$id, $tenant_id];
if (is_godown_manager()) {
    $lookup_params[] = (int)$current_user->user_id;
}
$purchase = fetch_one(
    "SELECT purchase_id FROM purchases WHERE purchase_id = ? AND tenant_id = ?{$owner_filter}",
    ...$lookup_params
);
if (!$purchase) {
    flash_set('danger', 'Purchase not found.');
    redirect('purchases.php');
}

$stmt = $conn->prepare("UPDATE purchases SET status = ? WHERE purchase_id = ? AND tenant_id = ?{$owner_filter}");
if (is_godown_manager()) {
    $stmt->bind_param('siii', $status, $id, $tenant_id, $current_user->user_id);
} else {
    $stmt->bind_param('sii', $status, $id, $tenant_id);
}
if ($stmt->execute()) {
    $stmt->close();
    audit_log('update', 'purchase', $id, 'Goods status set to ' . $statuses[$status]);
    flash_set('success', 'Goods status updated to ' . $statuses[$status] . '.');
} else {
    $stmt->close();
    flash_set('danger', 'Failed to update the goods status.');
}

redirect('purchases.php');
