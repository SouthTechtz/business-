<?php
require_once 'header.php';
require_once 'auth_check.php';
require_permission('manage_purchases');

$tenant_id = (int)$current_user->tenant_id;
$id = (int)($_GET['id'] ?? 0);

$stmt = $conn->prepare("DELETE FROM purchases WHERE purchase_id = ? AND tenant_id = ?");
$stmt->bind_param('ii', $id, $tenant_id);
if ($stmt->execute() && $stmt->affected_rows > 0) {
    flash_set('success', 'Purchase deleted successfully.');
} else {
    flash_set('danger', 'Purchase not found or could not be deleted.');
}
$stmt->close();

redirect('purchases.php');