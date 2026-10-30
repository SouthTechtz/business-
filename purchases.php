<?php
require_once 'header.php';
require_once 'auth_check.php';
require_permission_any(['manage_godown', 'view_godown']);
$page_title = 'Purchases';

$tenant_id = (int)$current_user->tenant_id;
$can_manage_godown = can('manage_godown');

$search = trim((string)($_GET['q'] ?? ''));
$supplier_filter = (int)($_GET['supplier'] ?? 0);
$godown_filter = (int)($_GET['godown'] ?? 0);
$date_from = trim((string)($_GET['from'] ?? ''));
$date_to = trim((string)($_GET['to'] ?? ''));
$valid_date = function (string $value): bool {
    if ($value === '') return true;
    $date = DateTime::createFromFormat('Y-m-d', $value);
    return $date && $date->format('Y-m-d') === $value;
};
if (!$valid_date($date_from)) $date_from = '';
if (!$valid_date($date_to)) $date_to = '';

$godowns = fetch_all(
    "SELECT warehouse_id, name, code FROM warehouses
      WHERE tenant_id = ? AND location_type = 'godown'
      ORDER BY is_active DESC, name",
    $tenant_id
);
$godown_ids = array_map(fn($row) => (int)$row['warehouse_id'], $godowns);
if ($godown_filter > 0 && !in_array($godown_filter, $godown_ids, true)) {
    $godown_filter = 0;
}

$suppliers = fetch_all(
    "SELECT supplier_id, company_name FROM suppliers WHERE tenant_id = ? ORDER BY company_name",
    $tenant_id
);
$supplier_ids = array_map(fn($row) => (int)$row['supplier_id'], $suppliers);
if ($supplier_filter > 0 && !in_array($supplier_filter, $supplier_ids, true)) {
    $supplier_filter = 0;
}

$sql = "SELECT gr.*, p.name product_name, p.code product_code,
               s.company_name supplier_name,
               w.name godown_name, w.code godown_code,
               TRIM(CONCAT(COALESCE(u.first_name, ''), ' ', COALESCE(u.last_name, ''))) received_by_name,
               u.username received_by_username
          FROM godown_receipts gr
          JOIN products p ON p.product_id = gr.product_id AND p.tenant_id = gr.tenant_id
          JOIN warehouses w ON w.warehouse_id = gr.godown_id AND w.tenant_id = gr.tenant_id
          LEFT JOIN suppliers s ON s.supplier_id = gr.supplier_id AND s.tenant_id = gr.tenant_id
          LEFT JOIN users u ON u.user_id = gr.received_by
         WHERE gr.tenant_id = ?";
$params = [$tenant_id];
$types = 'i';

if ($godown_filter > 0) {
    $sql .= " AND gr.godown_id = ?";
    $params[] = $godown_filter;
    $types .= 'i';
}
if ($supplier_filter > 0) {
    $sql .= " AND gr.supplier_id = ?";
    $params[] = $supplier_filter;
    $types .= 'i';
}
if ($date_from !== '') {
    $sql .= " AND gr.received_at >= ?";
    $params[] = $date_from . ' 00:00:00';
    $types .= 's';
}
if ($date_to !== '') {
    $sql .= " AND gr.received_at <= ?";
    $params[] = $date_to . ' 23:59:59';
    $types .= 's';
}
if ($search !== '') {
    $sql .= " AND (gr.receipt_number LIKE ? OR p.name LIKE ? OR p.code LIKE ? OR s.company_name LIKE ? OR gr.source_reference LIKE ?)";
    $like = '%' . $search . '%';
    array_push($params, $like, $like, $like, $like, $like);
    $types .= 'sssss';
}
$sql .= " ORDER BY gr.received_at DESC, gr.receipt_id DESC";
$purchases = fetch_all($sql, ...$params);

$total_qty = 0.0;
$supplier_count = [];
foreach ($purchases as $row) {
    $total_qty += (float)$row['quantity'];
    if (!empty($row['supplier_id'])) {
        $supplier_count[(int)$row['supplier_id']] = true;
    }
}

require_once 'navbar.php';
require_once 'sidebar.php';
?>

<div class="page-head">
    <div>
        <h1 class="page-title">Purchases</h1>
        <div class="page-sub">Stock received into the Central Godown, with supplier and source reference.</div>
    </div>
    <?php if ($can_manage_godown): ?>
        <a href="godown.php?tab=products" class="btn btn-primary"><i class="fa fa-download mr-1"></i> Receive Stock</a>
    <?php endif; ?>
</div>

<?php if ($f = flash_get()): ?>
    <div class="alert alert-<?php echo e($f['type']); ?>"><?php echo e($f['message']); ?></div>
<?php endif; ?>

<div class="row mb-4">
    <div class="col-md-4 mb-3">
        <div class="widget">
            <div class="widget-icon wi-blue"><i class="fa fa-shopping-bag"></i></div>
            <div class="widget-info">
                <div class="widget-value"><?php echo count($purchases); ?></div>
                <div class="widget-label">Receipts</div>
            </div>
        </div>
    </div>
    <div class="col-md-4 mb-3">
        <div class="widget">
            <div class="widget-icon wi-purple"><i class="fa fa-cubes"></i></div>
            <div class="widget-info">
                <div class="widget-value"><?php echo e(format_quantity($total_qty)); ?></div>
                <div class="widget-label">Quantity Received</div>
            </div>
        </div>
    </div>
    <div class="col-md-4 mb-3">
        <div class="widget">
            <div class="widget-icon wi-green"><i class="fa fa-truck"></i></div>
            <div class="widget-info">
                <div class="widget-value"><?php echo count($supplier_count); ?></div>
                <div class="widget-label">Suppliers</div>
            </div>
        </div>
    </div>
</div>

<div class="card">
    <div class="card-header">
        <form method="GET" action="purchases.php" class="form-inline flex-wrap">
            <input type="text" name="q" class="form-control form-control-sm mr-2 mb-1" placeholder="Receipt, product or supplier" value="<?php echo e($search); ?>">
            <select name="supplier" class="form-control form-control-sm mr-2 mb-1">
                <option value="0">All Suppliers</option>
                <?php foreach ($suppliers as $supplier): ?>
                    <option value="<?php echo (int)$supplier['supplier_id']; ?>" <?php echo $supplier_filter === (int)$supplier['supplier_id'] ? 'selected' : ''; ?>><?php echo e($supplier['company_name']); ?></option>
                <?php endforeach; ?>
            </select>
            <select name="godown" class="form-control form-control-sm mr-2 mb-1">
                <option value="0">All Godowns</option>
                <?php foreach ($godowns as $godown): ?>
                    <option value="<?php echo (int)$godown['warehouse_id']; ?>" <?php echo $godown_filter === (int)$godown['warehouse_id'] ? 'selected' : ''; ?>><?php echo e($godown['name']); ?></option>
                <?php endforeach; ?>
            </select>
            <input type="date" name="from" class="form-control form-control-sm mr-2 mb-1" value="<?php echo e($date_from); ?>" title="Received from">
            <input type="date" name="to" class="form-control form-control-sm mr-2 mb-1" value="<?php echo e($date_to); ?>" title="Received to">
            <button type="submit" class="btn btn-sm btn-primary mb-1"><i class="fa fa-search mr-1"></i> Filter</button>
            <?php if ($search !== '' || $supplier_filter > 0 || $godown_filter > 0 || $date_from !== '' || $date_to !== ''): ?>
                <a href="purchases.php" class="btn btn-sm btn-light mb-1 ml-1">Reset</a>
            <?php endif; ?>
        </form>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover data-table">
                <thead>
                    <tr>
                        <th>Receipt #</th><th>Received</th><th>Supplier</th><th>Product</th><th class="text-right">Qty</th><th>Godown</th><th>Source Reference</th><th>Received By</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($purchases as $row): ?>
                    <tr>
                        <td class="font-weight-semibold"><?php echo e($row['receipt_number']); ?></td>
                        <td><?php echo e(date('M d, Y H:i', strtotime($row['received_at']))); ?></td>
                        <td><?php echo e($row['supplier_name'] ?: '—'); ?></td>
                        <td>
                            <?php echo e($row['product_name']); ?>
                            <small class="text-muted d-block"><?php echo e($row['product_code']); ?></small>
                        </td>
                        <td class="text-right font-weight-semibold"><?php echo e(format_quantity($row['quantity'])); ?></td>
                        <td><?php echo e($row['godown_name']); ?></td>
                        <td><?php echo e($row['source_reference'] ?: '—'); ?></td>
                        <td><?php echo e($row['received_by_name'] ?: ($row['received_by_username'] ?: '—')); ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$purchases): ?>
                    <tr><td colspan="8" class="text-center text-muted py-4">No purchases recorded yet. Receive stock into the Central Godown to see it here.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php require_once 'footer.php'; ?>
