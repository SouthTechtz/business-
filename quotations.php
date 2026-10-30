<?php
require_once 'header.php';
require_once 'auth_check.php';
require_permission('manage_invoices');
$page_title = 'Quotations';

$tenant_id = (int)$current_user->tenant_id;

// Delete quotation (only when it has not been converted).
if (isset($_GET['delete']) && is_numeric($_GET['delete'])) {
    $id = (int)$_GET['delete'];
    $quotation = fetch_one(
        "SELECT * FROM quotations WHERE quotation_id = ? AND tenant_id = ?",
        $id,
        $tenant_id
    );
    if (!$quotation) {
        flash_set('danger', 'Quotation not found.');
    } elseif (!quotation_is_visible($quotation)) {
        flash_set('danger', 'You do not have access to the store for this quotation.');
    } elseif (!empty($quotation['converted_invoice_id'])) {
        flash_set('danger', 'This quotation was already converted and cannot be deleted.');
    } else {
        $stmt = $conn->prepare("DELETE FROM quotations WHERE quotation_id = ? AND tenant_id = ? AND converted_invoice_id IS NULL");
        $stmt->bind_param('ii', $id, $tenant_id);
        $stmt->execute();
        $stmt->close();
        audit_log('delete', 'quotation', $id, 'Quotation ' . $quotation['quotation_number'] . ' deleted');
        flash_set('success', 'Quotation deleted.');
    }
    redirect('quotations.php');
}

$status_filter = (string)($_GET['status'] ?? '');
if ($status_filter !== '' && !isset(quotation_statuses()[$status_filter])) {
    $status_filter = '';
}
$search = trim($_GET['q'] ?? '');
$warehouse_filter = (int)($_GET['warehouse'] ?? 0);
$stores = accessible_stores(null, true);
$store_ids = array_map(fn($s) => (int)$s['warehouse_id'], $stores);
if ($warehouse_filter > 0 && !in_array($warehouse_filter, $store_ids, true)) {
    $warehouse_filter = 0;
}

$sql = "SELECT q.*, c.first_name, c.last_name, c.company_name,
               w.name store_name, s.name shop_name,
               i.invoice_number converted_invoice_number
        FROM quotations q
        LEFT JOIN contacts c ON c.contact_id = q.contact_id
        LEFT JOIN warehouses w ON w.warehouse_id = q.warehouse_id
        LEFT JOIN shops s ON s.shop_id = w.shop_id
        LEFT JOIN invoices i ON i.invoice_id = q.converted_invoice_id
        WHERE q.tenant_id = ?";
$params = [$tenant_id];
$types = 'i';
$scope_ids = accessible_warehouse_ids();
if ($scope_ids !== null) {
    if (!$scope_ids) {
        $sql .= " AND 1 = 0";
    } else {
        $sql .= " AND q.warehouse_id IN (" . implode(',', array_map('intval', $scope_ids)) . ")";
    }
}
if ($warehouse_filter > 0) {
    $sql .= " AND q.warehouse_id = ?";
    $params[] = $warehouse_filter;
    $types .= 'i';
}
if ($status_filter !== '') {
    $sql .= " AND q.status = ?";
    $params[] = $status_filter;
    $types .= 's';
}
if ($search !== '') {
    $sql .= " AND (q.quotation_number LIKE ? OR c.first_name LIKE ? OR c.last_name LIKE ? OR c.company_name LIKE ?)";
    $like = '%' . $search . '%';
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $types .= 'ssss';
}
$sql .= " ORDER BY q.issue_date DESC, q.quotation_id DESC";

$quotations = fetch_all($sql, ...$params);
$statuses = quotation_statuses();

require_once 'navbar.php';
require_once 'sidebar.php';
$cur = tenant_currency();
?>

<div class="page-head">
    <div>
        <h1 class="page-title">Invoices &amp; Quotations</h1>
        <div class="page-sub"><?php echo count($quotations); ?> quotation(s).</div>
    </div>
    <a href="quotation-add.php" class="btn btn-primary"><i class="fa fa-plus mr-1"></i> New Quotation</a>
    <a href="invoice-add.php" class="btn btn-outline-primary"><i class="fa fa-file-text mr-1"></i> New Invoice</a>
</div>

<?php if ($f = flash_get()): ?>
    <div class="alert alert-<?php echo e($f['type']); ?>"><?php echo e($f['message']); ?></div>
<?php endif; ?>

<ul class="nav nav-tabs mb-3">
    <li class="nav-item"><a class="nav-link" href="invoices.php"><i class="fa fa-file-text mr-1"></i> Invoices</a></li>
    <li class="nav-item"><a class="nav-link active" href="quotations.php"><i class="fa fa-file-signature mr-1"></i> Quotations</a></li>
</ul>

<div class="card">
    <div class="card-header">
        <form method="GET" action="quotations.php" class="form-inline flex-wrap">
            <select name="status" class="form-control form-control-sm mr-2 mb-1">
                <option value="">All Statuses</option>
                <?php foreach ($statuses as $key => $label): ?>
                    <option value="<?php echo e($key); ?>" <?php echo $status_filter === $key ? 'selected' : ''; ?>><?php echo e($label); ?></option>
                <?php endforeach; ?>
            </select>
            <input type="text" name="q" class="form-control form-control-sm mr-2 mb-1" placeholder="Search quotation # or customer" value="<?php echo e($search); ?>">
            <select name="warehouse" class="form-control form-control-sm mr-2 mb-1">
                <option value="0">All Stores</option>
                <?php foreach ($stores as $store): ?>
                    <option value="<?php echo (int)$store['warehouse_id']; ?>" <?php echo $warehouse_filter === (int)$store['warehouse_id'] ? 'selected' : ''; ?>><?php echo e(store_label($store)); ?><?php echo (int)$store['is_active'] ? '' : ' (inactive)'; ?></option>
                <?php endforeach; ?>
            </select>
            <button type="submit" class="btn btn-sm btn-primary mb-1"><i class="fa fa-search mr-1"></i> Search</button>
            <?php if ($status_filter !== '' || $search !== '' || $warehouse_filter > 0): ?>
                <a href="quotations.php" class="btn btn-sm btn-light mb-1 ml-1">Reset</a>
            <?php endif; ?>
        </form>
        <div class="input-group dt-search ml-auto">
            <div class="input-group-prepend"><span class="input-group-text"><i class="fa fa-search"></i></span></div>
            <input type="text" class="form-control dt-search-input" data-scope="quotations" placeholder="Search quotations...">
        </div>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover data-table">
                <thead>
                    <tr>
                        <th>Quotation #</th><th>Customer</th><th>Store</th><th>Issue Date</th><th>Valid Until</th><th>Status</th><th class="text-right">Total</th><th class="text-right">Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($quotations as $q): ?>
                    <?php
                    $status = (string)$q['status'];
                    if ($status !== 'converted' && !empty($q['valid_until']) && $q['valid_until'] < date('Y-m-d')) {
                        $status = 'expired';
                    }
                    $customer_name = trim(($q['first_name'] ?? '') . ' ' . ($q['last_name'] ?? '')) ?: ($q['company_name'] ?? '') ?: 'Customer';
                    ?>
                    <tr>
                        <td class="font-weight-semibold"><a href="quotation-details.php?id=<?php echo (int)$q['quotation_id']; ?>"><?php echo e($q['quotation_number']); ?></a></td>
                        <td><?php echo e($customer_name); ?></td>
                        <td><?php echo e($q['store_name'] ? trim(($q['shop_name'] ?? '') . ' / ' . $q['store_name'], ' /') : '—'); ?></td>
                        <td><?php echo e(date('M d, Y', strtotime($q['issue_date']))); ?></td>
                        <td><?php echo !empty($q['valid_until']) ? e(date('M d, Y', strtotime($q['valid_until']))) : '—'; ?></td>
                        <td>
                            <span class="badge badge-<?php echo e(quotation_status_class($status)); ?>"><?php echo e(quotation_status_label($status)); ?></span>
                            <?php if (!empty($q['converted_invoice_number'])): ?>
                                <small class="d-block text-muted"><?php echo e($q['converted_invoice_number']); ?></small>
                            <?php endif; ?>
                        </td>
                        <td class="text-right"><?php echo e(money($q['total'], $cur)); ?></td>
                        <td class="text-right">
                            <a href="quotation-details.php?id=<?php echo (int)$q['quotation_id']; ?>" class="btn btn-action btn-light" title="View"><i class="fa fa-eye"></i></a>
                            <a href="quotation-print.php?id=<?php echo (int)$q['quotation_id']; ?>" class="btn btn-action btn-light" title="Print"><i class="fa fa-print"></i></a>
                            <a href="quotation-download.php?id=<?php echo (int)$q['quotation_id']; ?>" class="btn btn-action btn-light" title="Download PDF"><i class="fa fa-download"></i></a>
                            <?php if (empty($q['converted_invoice_id'])): ?>
                                <a href="quotations.php?delete=<?php echo (int)$q['quotation_id']; ?>" class="btn btn-action btn-light text-danger" title="Delete" onclick="return confirm('Delete this quotation?');"><i class="fa fa-trash"></i></a>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$quotations): ?>
                    <tr><td colspan="8" class="text-center text-muted py-4">No quotations found. <a href="quotation-add.php">Create your first quotation</a>.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php require_once 'footer.php'; ?>
