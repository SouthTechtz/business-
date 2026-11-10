<?php
require_once 'header.php';
require_once 'auth_check.php';
require_permission('manage_purchases');

$tenant_id = (int)$current_user->tenant_id;
$id = (int)($_GET['id'] ?? 0);

// A brand Mini Admin cannot delete another brand's purchase.
$delete_brand_scope = current_brand_scope();
if ($delete_brand_scope !== '') {
    $delete_product = fetch_one(
        "SELECT p.brand FROM purchases pu
           JOIN products p ON p.product_id = pu.product_id AND p.tenant_id = pu.tenant_id
          WHERE pu.purchase_id = ? AND pu.tenant_id = ?",
        $id,
        $tenant_id
    );
    if (!$delete_product || !brand_scope_allows($delete_product['brand'] ?? '')) {
        flash_set('danger', 'Purchase not found or could not be deleted.');
        redirect('purchases.php');
    }
}

$owner_filter = is_godown_manager() ? ' AND created_by = ?' : '';
$stmt = $conn->prepare("DELETE FROM purchases WHERE purchase_id = ? AND tenant_id = ?{$owner_filter}");
if (is_godown_manager()) {
    $stmt->bind_param('iii', $id, $tenant_id, $current_user->user_id);
} else {
    $stmt->bind_param('ii', $id, $tenant_id);
}
if ($stmt->execute() && $stmt->affected_rows > 0) {
    flash_set('success', 'Purchase deleted successfully.');
} else {
    flash_set('danger', 'Purchase not found or could not be deleted.');
}
$stmt->close();

redirect('purchases.php');