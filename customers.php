<?php
require_once 'header.php';
require_once 'auth_check.php';
require_permission('manage_contacts');
$page_title = 'Customers';

// Delete customer
if (isset($_GET['delete']) && is_numeric($_GET['delete'])) {
    if (!tenant_admin_scope()) {
        flash_set('danger', 'Only tenant administrators can delete shared customer records.');
        redirect('customers.php');
    }
    $id = (int)$_GET['delete'];
    $conn->begin_transaction();
    try {
        $pmt = fetch_one(
            "SELECT COUNT(*) c FROM payments p
             JOIN invoices i ON i.invoice_id = p.invoice_id
             WHERE p.contact_id = ? AND i.tenant_id = ?",
            $id, $current_user->tenant_id
        );
        $deals = fetch_one("SELECT COUNT(*) c FROM deals WHERE contact_id = ? AND tenant_id = ?", $id, $current_user->tenant_id);
        $order_count = fetch_one("SELECT COUNT(*) c FROM orders WHERE contact_id = ? AND tenant_id = ?", $id, $current_user->tenant_id);
        $lead_count = fetch_one("SELECT COUNT(*) c FROM leads WHERE contact_id = ? AND tenant_id = ?", $id, $current_user->tenant_id);
        if (($pmt['c'] ?? 0) > 0 || ($deals['c'] ?? 0) > 0) {
            throw new Exception('This customer cannot be deleted because they have payments or deals.');
        }
        if ((int)($order_count['c'] ?? 0) > 0 || (int)($lead_count['c'] ?? 0) > 0) {
            throw new Exception('This customer has order or lead history and cannot be deleted.');
        }
        $inv_count = fetch_one(
            "SELECT COUNT(*) c FROM invoices WHERE contact_id = ? AND tenant_id = ?",
            $id,
            $current_user->tenant_id
        );
        if ((int)($inv_count['c'] ?? 0) > 0) {
            throw new Exception('This customer has invoice history and cannot be deleted.');
        }
        $stmt = $conn->prepare("DELETE FROM contacts WHERE contact_id = ? AND tenant_id = ?");
        $stmt->bind_param('ii', $id, $current_user->tenant_id);
        $stmt->execute();
        $conn->commit();
        flash_set('success', 'Customer deleted successfully.');
    } catch (Exception $ex) {
        $conn->rollback();
        flash_set('danger', $ex->getMessage());
    }
    redirect('customers.php');
}

$invoice_scope = '';
$scope_ids = accessible_warehouse_ids();
if ($scope_ids !== null) {
    $invoice_scope = $scope_ids
        ? " AND i.warehouse_id IN (" . implode(',', array_map('intval', $scope_ids)) . ")"
        : " AND 1 = 0";
}
$customers = fetch_all(
    "SELECT c.*,
        (SELECT COUNT(*) FROM invoices i WHERE i.contact_id = c.contact_id AND i.tenant_id = ?{$invoice_scope}) invoice_count,
        (SELECT COALESCE(SUM(i.total), 0) FROM invoices i WHERE i.contact_id = c.contact_id AND i.tenant_id = ?{$invoice_scope}) total_spent
     FROM contacts c
     WHERE c.tenant_id = ? AND c.is_customer = 1
     ORDER BY c.created_at DESC",
    $current_user->tenant_id, $current_user->tenant_id, $current_user->tenant_id
);

require_once 'navbar.php';
require_once 'sidebar.php';
?>

<div class="page-head">
    <div>
        <h1 class="page-title">Customers</h1>
        <div class="page-sub"><?php echo count($customers); ?> customer(s) registered.</div>
    </div>
    <a href="customer-add.php" class="btn btn-primary"><i class="fa fa-plus mr-1"></i> Add Customer</a>
</div>

<?php if ($f = flash_get()): ?>
    <div class="alert alert-<?php echo e($f['type']); ?>"><?php echo e($f['message']); ?></div>
<?php endif; ?>

<div class="card">
    <div class="card-header d-flex justify-content-between align-items-center">
        <h5 class="card-title">All Customers</h5>
        <div class="input-group dt-search">
            <div class="input-group-prepend"><span class="input-group-text"><i class="fa fa-search"></i></span></div>
            <input type="text" class="form-control dt-search-input" data-scope="customers" placeholder="Search customers...">
        </div>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover data-table">
                <thead>
                    <tr>
                        <th>Customer #</th><th>Name</th><th>Company</th><th>Email</th><th>Phone</th><th>City</th><th>Invoices</th><th>Total Spent</th><th class="text-right">Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($customers as $row): ?>
                    <tr>
                        <td><?php echo e($row['customer_number'] ?: '—'); ?></td>
                        <td>
                            <div class="d-flex align-items-center">
                                <span class="avatar mr-2" style="background:var(--purple)"><?php echo e(strtoupper(substr($row['first_name'] ?? $row['company_name'] ?? '?', 0, 1))); ?></span>
                                <?php echo e(trim(($row['first_name'] ?? '') . ' ' . ($row['last_name'] ?? '')) ?: $row['company_name'] ?: 'Unnamed'); ?>
                            </div>
                        </td>
                        <td><?php echo e($row['company_name'] ?: '—'); ?></td>
                        <td><?php echo e($row['email'] ?: '—'); ?></td>
                        <td><?php echo e($row['phone'] ?: '—'); ?></td>
                        <td><?php echo e($row['city'] ?: '—'); ?></td>
                        <td><span class="badge badge-primary"><?php echo (int)$row['invoice_count']; ?></span></td>
                        <td><?php echo e(money($row['total_spent'], $cur = tenant_currency())); ?></td>
                        <td class="text-right">
                            <a href="customer-view.php?id=<?php echo $row['contact_id']; ?>" class="btn btn-action btn-light" title="View"><i class="fa fa-eye"></i></a>
                            <a href="customer-edit.php?id=<?php echo $row['contact_id']; ?>" class="btn btn-action btn-light" title="Edit"><i class="fa fa-pencil"></i></a>
                            <?php if (tenant_admin_scope()): ?>
                                <a href="customers.php?delete=<?php echo $row['contact_id']; ?>" class="btn btn-action btn-light text-danger" title="Delete" onclick="return confirm('Delete this customer and their invoices?');"><i class="fa fa-trash"></i></a>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$customers): ?>
                    <tr><td colspan="9" class="text-center text-muted py-4">No customers yet. <a href="customer-add.php">Add your first customer</a>.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php require_once 'footer.php'; ?>
