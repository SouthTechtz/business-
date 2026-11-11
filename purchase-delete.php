<?php
require_once 'header.php';
require_once 'auth_check.php';
require_permission('manage_purchases');

// Deleting a purchase is reserved for the system (master) administrator.
// Godown Managers and Mini Admins must never remove purchase records.
if (!is_system_administrator()) {
    http_response_code(403);
    die('Only the system administrator can delete purchases.');
}

$tenant_id = (int)$current_user->tenant_id;
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('purchases.php');
}
verify_csrf();

$id = (int)($_POST['purchase_id'] ?? 0);
$purchase = fetch_one(
    "SELECT purchase_id FROM purchases WHERE purchase_id = ? AND tenant_id = ?",
    $id,
    $tenant_id
);
if (!$purchase) {
    flash_set('danger', 'Purchase not found.');
    redirect('purchases.php');
}

// A confirmed (received) purchase is locked: editing, status changes and
// deletion are all blocked so receipts, returns and stock stay consistent.
if (purchase_is_received($id, $tenant_id)) {
    flash_set('danger', 'This purchase has been confirmed received, so it cannot be deleted.');
    redirect('purchase-view.php?id=' . $id);
}

$conn->begin_transaction();
try {
    $stmt = $conn->prepare("DELETE FROM purchases WHERE purchase_id = ? AND tenant_id = ?");
    $stmt->bind_param('ii', $id, $tenant_id);
    $stmt->execute();
    $deleted = $stmt->affected_rows;
    $stmt->close();
    if ($deleted !== 1) {
        throw new RuntimeException('Purchase not found or could not be deleted.');
    }
    $conn->commit();
} catch (Throwable $ex) {
    $conn->rollback();
    flash_set('danger', $ex->getMessage());
    redirect('purchases.php');
}

audit_log('delete', 'purchase', $id, 'Purchase #' . $id . ' deleted');
flash_set('success', 'Purchase deleted successfully.');
redirect('purchases.php');
