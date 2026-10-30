<?php
require_once 'header.php';
require_once 'auth_check.php';
require_permission('manage_purchases');
$page_title = 'Purchases';

$tenant_id = (int)$current_user->tenant_id;

$q = trim((string)($_GET['q'] ?? ''));
$supplier_filter = (int)($_GET['supplier'] ?? 0);
$status = (string)($_GET['status'] ?? '');
if ($status !== '' && !in_array($status, ['paid', 'partial', 'unpaid'], true)) {
    $status = '';
}
$date_from = trim((string)($_GET['from'] ?? ''));
$date_to = trim((string)($_GET['to'] ?? ''));
$valid_date = function (string $value): bool {
    if ($value === '') return true;
    $date = DateTime::createFromFormat('Y-m-d', $value);
    return $date && $date->format('Y-m-d') === $value;
};
if (!$valid_date($date_from)) $date_from = '';
if (!$valid_date($date_to)) $date_to = '';

$suppliers = fetch_all(
    "SELECT supplier_id, company_name FROM suppliers WHERE tenant_id = ? ORDER BY company_name",
    $tenant_id
);
$supplier_ids = array_map(fn($row) => (int)$row['supplier_id'], $suppliers);
if ($supplier_filter > 0 && !in_array($supplier_filter, $supplier_ids, true)) {
    $supplier_filter = 0;
}

$sql = "SELECT pu.*, pr.name product_name, pr.code product_code,
               s.company_name supplier_name
          FROM purchases pu
          JOIN products pr ON pr.product_id = pu.product_id AND pr.tenant_id = pu.tenant_id
          LEFT JOIN suppliers s ON s.supplier_id = pu.supplier_id AND s.tenant_id = pu.tenant_id
         WHERE pu.tenant_id = ?";
$params = [$tenant_id];
$types = 'i';

if ($supplier_filter > 0) {
    $sql .= " AND pu.supplier_id = ?";
    $params[] = $supplier_filter;
    $types .= 'i';
}
if ($date_from !== '') {
    $sql .= " AND pu.purchase_date >= ?";
    $params[] = $date_from;
    $types .= 's';
}
if ($date_to !== '') {
    $sql .= " AND pu.purchase_date <= ?";
    $params[] = $date_to;
    $types .= 's';
}
if ($q !== '') {
    $like = '%' . $q . '%';
    $sql .= " AND (pr.name LIKE ? OR pr.code LIKE ? OR s.company_name LIKE ? OR pu.notes LIKE ?)";
    array_push($params, $like, $like, $like, $like);
    $types .= 'ssss';
}
$sql .= " ORDER BY pu.created_at DESC, pu.purchase_id DESC";

$purchases = fetch_all($sql, ...$params);

if ($status !== '') {
    $filtered = [];
    foreach ($purchases as $row) {
        $total = (float)$row['total'];
        $paid = (float)$row['amount_paid'];
        $row_status = 'unpaid';
        if ($total > 0 && $paid >= $total) {
            $row_status = 'paid';
        } elseif ($paid > 0) {
            $row_status = 'partial';
        }
        if ($row_status === $status) {
            $filtered[] = $row;
        }
    }
    $purchases = $filtered;
}

$total_paid_sum = 0.0;
$total_amount_sum = 0.0;
$total_remained_sum = 0.0;
foreach ($purchases as $row) {
    $paid = (float)$row['amount_paid'];
    $total = (float)$row['total'];
    $total_paid_sum += $paid;
    $total_amount_sum += $total;
    $total_remained_sum += ($total - $paid);
}

require_once 'navbar.php';
require_once 'sidebar.php';
$cur = tenant_currency();
?>
<div class="page-head">
    <div>
        <h1 class="page-title">Purchases</h1>
        <div class="page-sub"><?php echo count($purchases); ?> purchase(s) match current filters.</div>
    </div>
    <a href="purchase-add.php" class="btn btn-primary"><i class="fa fa-plus mr-1"></i> New Purchase</a>
</div>

<?php if ($f = flash_get()): ?>
    <div class="alert alert-<?php echo e($f['type']); ?>"><?php echo e($f['message']); ?></div>
<?php endif; ?>

<div class="row mb-3">
    <div class="col-md-3 col-sm-6 mb-2">
        <div class="card"><div class="card-body">
            <div class="text-muted small">Total Purchases</div>
            <div class="h4 mb-0"><?php echo count($purchases); ?></div>
        </div></div>
    </div>
    <div class="col-md-3 col-sm-6 mb-2">
        <div class="card"><div class="card-body">
            <div class="text-muted small">Total Paid</div>
            <div class="h4 mb-0 text-success"><?php echo e(money($total_paid_sum, $cur)); ?></div>
        </div></div>
    </div>
    <div class="col-md-3 col-sm-6 mb-2">
        <div class="card"><div class="card-body">
            <div class="text-muted small">Total Remained</div>
            <div class="h4 mb-0 text-danger"><?php echo e(money($total_remained_sum, $cur)); ?></div>
        </div></div>
    </div>
    <div class="col-md-3 col-sm-6 mb-2">
        <div class="card"><div class="card-body">
            <div class="text-muted small">Total Amount</div>
            <div class="h4 mb-0"><?php echo e(money($total_amount_sum, $cur)); ?></div>
        </div></div>
    </div>
</div>

<style>
    .table-top-summary {
        background-color: var(--light, #f8f9fa) !important;
        font-weight: 600;
        border-bottom: 2px solid var(--primary, #6f42c1) !important;
    }
</style>

<div class="card">
    <div class="card-header">
        <form method="GET" action="purchases.php" class="form-inline flex-wrap">
            <input type="text" name="q" class="form-control form-control-sm mr-2 mb-1" placeholder="Product or supplier" value="<?php echo e($q); ?>">
            <select name="supplier" class="form-control form-control-sm mr-2 mb-1">
                <option value="0">All Suppliers</option>
                <?php foreach ($suppliers as $supplier): ?>
                    <option value="<?php echo (int)$supplier['supplier_id']; ?>" <?php echo $supplier_filter === (int)$supplier['supplier_id'] ? 'selected' : ''; ?>>
                        <?php echo e($supplier['company_name']); ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <select name="status" class="form-control form-control-sm mr-2 mb-1">
                <option value="">All Payment Statuses</option>
                <option value="paid" <?php echo $status === 'paid' ? 'selected' : ''; ?>>Paid</option>
                <option value="partial" <?php echo $status === 'partial' ? 'selected' : ''; ?>>Partially Paid</option>
                <option value="unpaid" <?php echo $status === 'unpaid' ? 'selected' : ''; ?>>Unpaid</option>
            </select>
            <input type="date" name="from" class="form-control form-control-sm mr-2 mb-1" value="<?php echo e($date_from); ?>" title="From date">
            <input type="date" name="to" class="form-control form-control-sm mr-2 mb-1" value="<?php echo e($date_to); ?>" title="To date">
            <button type="submit" class="btn btn-sm btn-primary mb-1"><i class="fa fa-filter mr-1"></i> Filter</button>
            <?php if ($q !== '' || $supplier_filter > 0 || $status !== '' || $date_from !== '' || $date_to !== ''): ?>
                <a href="purchases.php" class="btn btn-sm btn-light mb-1 ml-2">Clear</a>
            <?php endif; ?>
        </form>
        <div class="input-group dt-search ml-auto">
            <div class="input-group-prepend"><span class="input-group-text"><i class="fa fa-search"></i></span></div>
            <input type="text" class="form-control dt-search-input" data-scope="purchases" placeholder="Search purchases...">
        </div>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover data-table" id="purchasesTable">
                <thead>
                    <tr>
                        <th>Product Name</th>
                        <th class="text-right">Quantity</th>
                        <th>Supplier Name</th>
                        <th class="text-right">Amount Paid</th>
                        <th class="text-right">Amount Remained</th>
                        <th class="text-right">Total</th>
                        <th class="text-right">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <tr class="table-top-summary">
                        <td colspan="3" class="text-dark font-weight-bold">
                            <i class="fa fa-calculator mr-1 text-muted"></i> Summary (<span id="purchaseListCount"><?php echo count($purchases); ?></span> purchases shown):
                        </td>
                        <td class="text-right text-success font-weight-bold" id="topTotalPaid">
                            <?php echo e(money($total_paid_sum, $cur)); ?>
                        </td>
                        <td class="text-right text-danger font-weight-bold" id="topTotalRemained">
                            <?php echo e(money($total_remained_sum, $cur)); ?>
                        </td>
                        <td class="text-right text-dark font-weight-bold" id="topTotalAmount">
                            <?php echo e(money($total_amount_sum, $cur)); ?>
                        </td>
                        <td></td>
                    </tr>
                    <?php foreach ($purchases as $row): ?>
                        <?php
                            $paid = (float)$row['amount_paid'];
                            $total = (float)$row['total'];
                            $remained = $total - $paid;
                        ?>
                        <tr data-paid-value="<?php echo e($paid); ?>" data-total-value="<?php echo e($total); ?>" data-remained-value="<?php echo e($remained); ?>">
                            <td class="font-weight-semibold">
                                <?php echo e($row['product_name']); ?>
                                <?php if (!empty($row['product_code'])): ?><br><small class="text-muted"><?php echo e($row['product_code']); ?></small><?php endif; ?>
                            </td>
                            <td class="text-right"><?php echo e(format_quantity($row['quantity'])); ?></td>
                            <td><?php echo e($row['supplier_name'] ?: '—'); ?></td>
                            <td class="text-right text-success"><?php echo e(money($paid, $cur)); ?></td>
                            <td class="text-right text-danger"><?php echo e(money($remained, $cur)); ?></td>
                            <td class="text-right font-weight-semibold"><?php echo e(money($total, $cur)); ?></td>
                            <td class="text-right">
                                <a href="purchase-view.php?id=<?php echo (int)$row['purchase_id']; ?>" class="btn btn-sm btn-light" title="View"><i class="fa fa-eye"></i></a>
                                <a href="purchase-edit.php?id=<?php echo (int)$row['purchase_id']; ?>" class="btn btn-sm btn-primary" title="Edit"><i class="fa fa-pencil"></i></a>
                                <a href="purchase-delete.php?id=<?php echo (int)$row['purchase_id']; ?>" class="btn btn-sm btn-danger" title="Delete" onclick="return confirm('Delete this purchase?');"><i class="fa fa-trash"></i></a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$purchases): ?>
                        <tr><td colspan="7" class="text-center text-muted py-4">No purchases recorded yet. <a href="purchase-add.php">Record the first purchase</a>.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<script>
    $(function () {
        var $table = $('#purchasesTable');
        if (!$table.length) return;
        var currency = <?php echo json_encode($cur); ?>;

        function formatMoney(value) {
            return currency + ' ' + Number(value).toLocaleString(undefined, {
                minimumFractionDigits: 2,
                maximumFractionDigits: 2
            });
        }

        function recalcTopSummary() {
            var paidSum = 0, totalSum = 0, remainedSum = 0, count = 0;
            var rows = $table.find('tbody tr').not('.table-top-summary');

            $(rows).each(function () {
                var pVal = parseFloat($(this).attr('data-paid-value'));
                var tVal = parseFloat($(this).attr('data-total-value'));
                var rVal = parseFloat($(this).attr('data-remained-value'));
                if (!isNaN(pVal)) {
                    paidSum += pVal;
                    totalSum += tVal;
                    remainedSum += rVal;
                    count++;
                }
            });

            $('#purchaseListCount').text(count);
            $('#topTotalPaid').text(formatMoney(paidSum));
            $('#topTotalRemained').text(formatMoney(remainedSum));
            $('#topTotalAmount').text(formatMoney(totalSum));
        }

        $table.on('init.dt draw.dt', recalcTopSummary);
        recalcTopSummary();
    });
</script>

<?php require_once 'footer.php'; ?>
