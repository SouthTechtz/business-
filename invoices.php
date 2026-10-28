<?php
require_once 'header.php';
require_once 'auth_check.php';
require_permission('manage_invoices');
$page_title = 'Invoices';

// Delete invoice
if (isset($_GET['delete']) && is_numeric($_GET['delete'])) {
    $id = (int)$_GET['delete'];
    $invoice = fetch_one("SELECT * FROM invoices WHERE invoice_id = ? AND tenant_id = ?", $id, $current_user->tenant_id);
    if (!$invoice) {
        flash_set('danger', 'Invoice not found.');
    } elseif ((int)($invoice['warehouse_id'] ?? 0) > 0 && !store_is_accessible((int)$invoice['warehouse_id'], true)) {
        flash_set('danger', 'You do not have access to the store for this invoice.');
    } else {
        $conn->begin_transaction();
        try {
            $locked_invoice = fetch_one(
                "SELECT * FROM invoices WHERE invoice_id = ? AND tenant_id = ? FOR UPDATE",
                $id,
                $current_user->tenant_id
            );
            if (!$locked_invoice) {
                throw new Exception('Invoice not found.');
            }

            $pmt = fetch_one(
                "SELECT payment_id FROM payments WHERE invoice_id = ? AND tenant_id = ? LIMIT 1 FOR UPDATE",
                $id,
                $current_user->tenant_id
            );
            if ($pmt) {
                throw new Exception('This invoice has payments and cannot be deleted.');
            }

            $items = fetch_all("SELECT * FROM invoice_items WHERE invoice_id = ?", $id);
            foreach ($items as $item) {
                $product = fetch_one(
                    "SELECT is_service FROM products WHERE product_id = ? AND tenant_id = ?",
                    $item['product_id'],
                    $current_user->tenant_id
                );
                if ($product && (int)$product['is_service'] === 1) {
                    continue;
                }

                // Restore only to the item's original store. Unknown or
                // inaccessible stores block the deletion instead of guessing.
                $item_warehouse_id = (int)($item['warehouse_id'] ?? 0);
                if (!$item_warehouse_id || !find_accessible_store($item_warehouse_id, true)) {
                    throw new Exception('An item has an unknown or inaccessible store. The invoice was not deleted.');
                }

                $stmt = $conn->prepare("UPDATE inventory_stock SET quantity = quantity + ? WHERE product_id = ? AND warehouse_id = ? AND tenant_id = ?");
                $stmt->bind_param('diii', $item['quantity'], $item['product_id'], $item_warehouse_id, $current_user->tenant_id);
                $stmt->execute();
                $stmt->close();

                $stmt = $conn->prepare(
                    "INSERT INTO inventory_movements (tenant_id, product_id, warehouse_id, movement_type, quantity, reference_id, reference_type, created_by)
                     VALUES (?, ?, ?, 'invoice_delete', ?, ?, 'invoice', ?)"
                );
                $stmt->bind_param('iiidii', $current_user->tenant_id, $item['product_id'], $item_warehouse_id, $item['quantity'], $id, $current_user->user_id);
                $stmt->execute();
                $stmt->close();
            }

            $stmt = $conn->prepare("DELETE FROM invoice_items WHERE invoice_id = ?");
            $stmt->bind_param('i', $id);
            $stmt->execute();
            $stmt->close();

            $stmt = $conn->prepare("DELETE FROM invoices WHERE invoice_id = ? AND tenant_id = ?");
            $stmt->bind_param('ii', $id, $current_user->tenant_id);
            $stmt->execute();
            $stmt->close();

            $conn->commit();
            audit_log('delete', 'invoice', $id, 'Invoice ' . $invoice['invoice_number'] . ' deleted; stock restored');
            flash_set('success', 'Invoice deleted and stock restored.');
        } catch (Exception $ex) {
            $conn->rollback();
            flash_set('danger', 'Invoice deletion failed: ' . $ex->getMessage());
        }
    }
    redirect('invoices.php');
}

$status_filter = (int)($_GET['status'] ?? 0);
$search = trim($_GET['q'] ?? '');
$warehouse_filter = (int)($_GET['warehouse'] ?? 0);
$stores = accessible_stores(null, true);
$store_ids = array_map(fn($s) => (int)$s['warehouse_id'], $stores);
if ($warehouse_filter > 0 && !in_array($warehouse_filter, $store_ids, true)) {
    $warehouse_filter = 0;
}

$sql = "SELECT i.*, ds.name status_name, c.first_name, c.last_name, c.company_name,
               w.name store_name, s.name shop_name
        FROM invoices i
        LEFT JOIN document_statuses ds ON ds.status_id = i.status_id
        LEFT JOIN contacts c ON c.contact_id = i.contact_id
        LEFT JOIN warehouses w ON w.warehouse_id = i.warehouse_id
        LEFT JOIN shops s ON s.shop_id = w.shop_id
        WHERE i.tenant_id = ?";
$params = [$current_user->tenant_id];
$types = 'i';
$scope_ids = accessible_warehouse_ids();
if ($scope_ids !== null) {
    if (!$scope_ids) {
        $sql .= " AND 1 = 0";
    } else {
        $sql .= " AND i.warehouse_id IN (" . implode(',', array_map('intval', $scope_ids)) . ")";
    }
}
if ($warehouse_filter > 0) {
    $sql .= " AND i.warehouse_id = ?";
    $params[] = $warehouse_filter;
    $types .= 'i';
}
if ($status_filter > 0) {
    $sql .= " AND i.status_id = ?";
    $params[] = $status_filter;
    $types .= 'i';
}
if ($search !== '') {
    $sql .= " AND (i.invoice_number LIKE ? OR c.first_name LIKE ? OR c.last_name LIKE ? OR c.company_name LIKE ?)";
    $like = '%' . $search . '%';
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $types .= 'ssss';
}
$sql .= " ORDER BY i.issue_date DESC, i.invoice_id DESC";

$invoices = fetch_all($sql, ...$params);
$statuses = fetch_all(
    "SELECT * FROM document_statuses WHERE tenant_id = ? AND type = 'invoice' ORDER BY sort_order",
    $current_user->tenant_id
);

require_once 'navbar.php';
require_once 'sidebar.php';
$cur = tenant_currency();
?>

<div class="page-head">
    <div>
        <h1 class="page-title">Invoices</h1>
        <div class="page-sub"><?php echo count($invoices); ?> invoice(s).</div>
    </div>
    <a href="invoice-add.php" class="btn btn-primary"><i class="fa fa-plus mr-1"></i> New Invoice</a>
    <a href="export-invoices.php?<?php echo e(http_build_query(array_filter(['status' => $status_filter > 0 ? $status_filter : '', 'warehouse' => $warehouse_filter > 0 ? $warehouse_filter : '', 'q' => $search], fn($v) => $v !== ''))); ?>" class="btn btn-outline-success"><i class="fa fa-file-excel-o mr-1"></i> Excel</a>
</div>

<?php if ($f = flash_get()): ?>
    <div class="alert alert-<?php echo e($f['type']); ?>"><?php echo e($f['message']); ?></div>
<?php endif; ?>

<div class="card">
    <div class="card-header">
        <form method="GET" action="invoices.php" class="form-inline flex-wrap">
            <select name="status" class="form-control form-control-sm mr-2 mb-1">
                <option value="0">All Statuses</option>
                <?php foreach ($statuses as $s): ?>
                    <option value="<?php echo $s['status_id']; ?>" <?php echo $status_filter == $s['status_id'] ? 'selected' : ''; ?>><?php echo e($s['name']); ?></option>
                <?php endforeach; ?>
            </select>
            <input type="text" name="q" class="form-control form-control-sm mr-2 mb-1" placeholder="Search invoice # or customer" value="<?php echo e($search); ?>">
            <select name="warehouse" class="form-control form-control-sm mr-2 mb-1">
                <option value="0">All Stores</option>
                <?php foreach ($stores as $store): ?>
                    <option value="<?php echo (int)$store['warehouse_id']; ?>" <?php echo $warehouse_filter === (int)$store['warehouse_id'] ? 'selected' : ''; ?>><?php echo e(store_label($store)); ?><?php echo (int)$store['is_active'] ? '' : ' (inactive)'; ?></option>
                <?php endforeach; ?>
            </select>
            <button type="submit" class="btn btn-sm btn-primary mb-1"><i class="fa fa-search mr-1"></i> Search</button>
        </form>
        <div class="input-group dt-search ml-auto">
            <div class="input-group-prepend"><span class="input-group-text"><i class="fa fa-search"></i></span></div>
            <input type="text" class="form-control dt-search-input" data-scope="invoices" placeholder="Search invoices...">
        </div>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover data-table">
                <thead>
                    <tr>
                        <th>Invoice #</th><th>Customer</th><th>Store</th><th>Issue Date</th><th>Due Date</th><th>Status</th><th class="text-right">Total</th><th class="text-right">Balance</th><th class="text-right">Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($invoices as $inv): ?>
                    <tr>
                        <td class="font-weight-semibold"><a href="invoice-details.php?id=<?php echo $inv['invoice_id']; ?>"><?php echo e($inv['invoice_number']); ?></a></td>
                        <td><?php echo e(trim(($inv['first_name'] ?? '') . ' ' . ($inv['last_name'] ?? '')) ?: $inv['company_name'] ?: 'Walk-in Customer'); ?></td>
                        <td><?php echo e($inv['store_name'] ? trim(($inv['shop_name'] ?? '') . ' / ' . $inv['store_name'], ' /') : '—'); ?></td>
                        <td><?php echo e(date('M d, Y', strtotime($inv['issue_date']))); ?></td>
                        <td><?php echo !empty($inv['due_date']) && $inv['due_date'] !== '0000-00-00' ? e(date('M d, Y', strtotime($inv['due_date']))) : '—'; ?></td>
                        <td><span class="badge badge-info"><?php echo e($inv['status_name'] ?? 'Unknown'); ?></span></td>
                        <td class="text-right"><?php echo e(money($inv['total'], $cur)); ?></td>
                        <td class="text-right"><?php echo e(money($inv['amount_due'], $cur)); ?></td>
                        <td class="text-right">
                            <a href="invoice-details.php?id=<?php echo $inv['invoice_id']; ?>" class="btn btn-action btn-light" title="View"><i class="fa fa-eye"></i></a>
                            <a href="invoice-print.php?id=<?php echo $inv['invoice_id']; ?>" class="btn btn-action btn-light" title="Print"><i class="fa fa-print"></i></a>
                            <?php if ((float)$inv['amount_due'] > 0): ?>
                                <a href="payment-add.php?invoice=<?php echo $inv['invoice_id']; ?>" class="btn btn-action btn-light text-success" title="Record Payment"><i class="fa fa-money"></i></a>
                            <?php endif; ?>
                            <?php if ((float)$inv['amount_due'] == (float)$inv['total']): ?>
                                <a href="invoices.php?delete=<?php echo $inv['invoice_id']; ?>" class="btn btn-action btn-light text-danger" title="Delete" onclick="return confirm('Delete this invoice?');"><i class="fa fa-trash"></i></a>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$invoices): ?>
                    <tr><td colspan="9" class="text-center text-muted py-4">No invoices found. <a href="invoice-add.php">Create your first invoice</a>.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php require_once 'footer.php'; ?>
