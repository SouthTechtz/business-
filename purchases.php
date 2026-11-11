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
$goods_statuses = purchase_goods_statuses();
$goods_status = (string)($_GET['goods_status'] ?? '');
if ($goods_status !== '' && !isset($goods_statuses[$goods_status])) {
    $goods_status = '';
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
               COALESCE(u.is_decimal, 0) is_decimal, COALESCE(u.short_name, 'unit(s)') unit_name,
               s.company_name supplier_name,
               EXISTS (SELECT 1 FROM godown_receipts gr
                        WHERE gr.tenant_id = pu.tenant_id AND gr.purchase_id = pu.purchase_id) has_receipt,
               EXISTS (SELECT 1 FROM sales_returns sr
                        WHERE sr.tenant_id = pu.tenant_id AND sr.source_type = 'purchase'
                          AND sr.source_id = pu.purchase_id) has_supplier_return
          FROM purchases pu
          JOIN products pr ON pr.product_id = pu.product_id AND pr.tenant_id = pu.tenant_id
          LEFT JOIN units_of_measurement u ON u.unit_id = pr.unit_id AND u.tenant_id = pr.tenant_id
          LEFT JOIN suppliers s ON s.supplier_id = pu.supplier_id AND s.tenant_id = pu.tenant_id
         WHERE pu.tenant_id = ?";
$params = [$tenant_id];
$types = 'i';
if (is_godown_manager()) {
    // The Godown Manager sees their own purchases plus everything still waiting
    // to be confirmed as received.
    $sql .= " AND (pu.created_by = ? OR pu.received_at IS NULL)";
    $params[] = (int)$current_user->user_id;
    $types .= 'i';
}
// Brand-scoped Mini Admin: only purchases of their brand's products.
$purchase_brand_scope = current_brand_scope();
if ($purchase_brand_scope !== '') {
    $sql .= " AND pr.brand = ?";
    $params[] = $purchase_brand_scope;
    $types .= 's';
}

if ($supplier_filter > 0) {
    $sql .= " AND pu.supplier_id = ?";
    $params[] = $supplier_filter;
    $types .= 'i';
}
if ($goods_status !== '') {
    $sql .= " AND pu.status = ?";
    $params[] = $goods_status;
    $types .= 's';
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

$receive_request_id = (int)($_GET['receive'] ?? 0);
$auto_receive = null;
if ($receive_request_id > 0 && can_receive_purchases()) {
    foreach ($purchases as $purchase_row) {
        if ((int)$purchase_row['purchase_id'] === $receive_request_id
            && empty($purchase_row['received_at'])
            && (string)($purchase_row['status'] ?? '') !== 'cancelled') {
            $auto_receive = $purchase_row;
            break;
        }
    }
}

require_once 'navbar.php';
require_once 'sidebar.php';
$cur = tenant_currency();
?>
<div class="page-head">
    <div>
        <h1 class="page-title">Purchases</h1>
        <div class="page-sub"><?php echo count($purchases); ?> purchase(s) match current filters. Purchases record supplier goods and do not change stock automatically.</div>
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
            <select name="goods_status" class="form-control form-control-sm mr-2 mb-1">
                <option value="">All Goods Statuses</option>
                <?php foreach ($goods_statuses as $status_key => $status_label): ?>
                    <option value="<?php echo e($status_key); ?>" <?php echo $goods_status === $status_key ? 'selected' : ''; ?>><?php echo e($status_label); ?></option>
                <?php endforeach; ?>
            </select>
            <input type="date" name="from" class="form-control form-control-sm mr-2 mb-1" value="<?php echo e($date_from); ?>" title="From date">
            <input type="date" name="to" class="form-control form-control-sm mr-2 mb-1" value="<?php echo e($date_to); ?>" title="To date">
            <button type="submit" class="btn btn-sm btn-primary mb-1"><i class="fa fa-filter mr-1"></i> Filter</button>
            <?php if ($q !== '' || $supplier_filter > 0 || $status !== '' || $goods_status !== '' || $date_from !== '' || $date_to !== ''): ?>
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
                        <th>Goods Status</th>
                        <th class="text-right">Amount Paid</th>
                        <th class="text-right">Amount Remained</th>
                        <th class="text-right">Total</th>
                        <th class="text-right">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <tr class="table-top-summary">
                        <td colspan="4" class="text-dark font-weight-bold">
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
                            <td>
                                <?php $row_goods_status = (string)($row['status'] ?? 'in_production'); ?>
                                <?php $row_received = !empty($row['received_at']) || (int)($row['has_receipt'] ?? 0) > 0 || (int)($row['has_supplier_return'] ?? 0) > 0; ?>
                                <span class="badge badge-<?php echo e(purchase_goods_status_class($row_goods_status)); ?>"><?php echo e(purchase_goods_status_label($row_goods_status)); ?></span>
                                <?php if ($row_received): ?>
                                    <span class="badge badge-success" title="Goods received into the Godown">Received</span>
                                <?php elseif ($row_goods_status === 'arrived_godown'): ?>
                                    <span class="badge badge-warning" title="Waiting for the Godown Manager to record the goods received">Receiving due</span>
                                <?php endif; ?>
                            </td>
                            <td class="text-right text-success"><?php echo e(money($paid, $cur)); ?></td>
                            <td class="text-right text-danger"><?php echo e(money($remained, $cur)); ?></td>
                            <td class="text-right font-weight-semibold"><?php echo e(money($total, $cur)); ?></td>
                            <td class="text-right">
                                <?php
                                $purchase_actions = [
                                    ['label' => 'View purchase', 'icon' => 'fa-eye', 'href' => 'purchase-view.php?id=' . (int)$row['purchase_id']],
                                ];
                                if (!$row_received) {
                                    $purchase_actions[] = ['label' => 'Edit purchase', 'icon' => 'fa-pencil', 'href' => 'purchase-edit.php?id=' . (int)$row['purchase_id']];
                                }
                                if (!$row_received && $row_goods_status !== 'cancelled' && can_receive_purchases()) {
                                    $purchase_actions[] = [
                                        'label' => 'Confirm goods received',
                                        'icon' => 'fa-download',
                                        'href' => '#',
                                        'attrs' => [
                                            'data-toggle' => 'modal',
                                            'data-target' => '#purchaseReceiveModal',
                                            'data-purchase-id' => (int)$row['purchase_id'],
                                            'data-purchase-label' => 'Purchase #' . (int)$row['purchase_id'],
                                            'data-product' => (string)$row['product_name'],
                                            'data-ordered' => (float)$row['quantity'],
                                            'data-unit' => (string)$row['unit_name'],
                                            'data-decimal' => (int)$row['is_decimal'],
                                        ],
                                    ];
                                }
                                if (!$row_received) {
                                    $purchase_actions[] = [
                                        'label' => 'Update goods status',
                                        'icon' => 'fa-truck',
                                        'attrs' => [
                                            'data-toggle' => 'modal',
                                            'data-target' => '#purchaseStatusModal',
                                            'data-purchase-id' => (int)$row['purchase_id'],
                                            'data-purchase-label' => 'Purchase #' . (int)$row['purchase_id'],
                                            'data-status' => $row_goods_status,
                                        ],
                                    ];
                                }
                                if (is_system_administrator() && !$row_received) {
                                    $purchase_actions[] = ['type' => 'divider'];
                                    $purchase_actions[] = [
                                        'label' => 'Delete purchase',
                                        'icon' => 'fa-trash',
                                        'danger' => true,
                                        'form' => [
                                            'action' => 'purchase-delete.php',
                                            'method' => 'POST',
                                            'fields' => ['purchase_id' => (int)$row['purchase_id']],
                                            'confirm' => 'Delete this purchase permanently?',
                                        ],
                                    ];
                                }
                                echo row_action_menu($purchase_actions, 'Purchase actions');
                                ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$purchases): ?>
                        <tr><td colspan="8" class="text-center text-muted py-4">No purchases recorded yet. <a href="purchase-add.php">Record the first purchase</a>.</td></tr>
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

<div class="modal fade" id="purchaseStatusModal" tabindex="-1" role="dialog" aria-labelledby="purchaseStatusModalLabel" aria-hidden="true">
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            <form method="POST" action="purchase-status.php">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="action" value="update_status">
                <input type="hidden" name="purchase_id" id="purchaseStatusId" value="">
                <div class="modal-header">
                    <h5 class="modal-title" id="purchaseStatusModalLabel"><i class="fa fa-truck mr-1"></i> Update Goods Status</h5>
                    <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                        <span aria-hidden="true">&times;</span>
                    </button>
                </div>
                <div class="modal-body">
                    <p class="mb-2">Purchase <strong id="purchaseStatusLabel"></strong></p>
                    <div class="form-group mb-0">
                        <label for="purchaseStatusSelect">Goods Status</label>
                        <select name="status" id="purchaseStatusSelect" class="form-control" required>
                            <?php foreach ($goods_statuses as $status_key => $status_label): ?>
                                <option value="<?php echo e($status_key); ?>"><?php echo e($status_label); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-light" data-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary"><i class="fa fa-save mr-1"></i> Save Status</button>
                </div>
            </form>
        </div>
    </div>
</div>
<script>
    $('#purchaseStatusModal').on('show.bs.modal', function (event) {
        var $button = $(event.relatedTarget);
        $('#purchaseStatusId').val($button.data('purchase-id'));
        $('#purchaseStatusLabel').text($button.data('purchase-label'));
        $('#purchaseStatusSelect').val($button.data('status'));
    });
</script>

<div class="modal fade" id="purchaseReceiveModal" tabindex="-1" role="dialog" aria-labelledby="purchaseReceiveModalLabel" aria-hidden="true"
     data-auto="<?php echo $auto_receive ? '1' : '0'; ?>"
     data-auto-id="<?php echo $auto_receive ? (int)$auto_receive['purchase_id'] : ''; ?>"
     data-auto-label="<?php echo $auto_receive ? e('Purchase #' . (int)$auto_receive['purchase_id']) : ''; ?>"
     data-auto-product="<?php echo $auto_receive ? e((string)$auto_receive['product_name']) : ''; ?>"
     data-auto-ordered="<?php echo $auto_receive ? e((string)(float)$auto_receive['quantity']) : ''; ?>"
     data-auto-unit="<?php echo $auto_receive ? e((string)$auto_receive['unit_name']) : ''; ?>"
     data-auto-decimal="<?php echo $auto_receive ? (int)$auto_receive['is_decimal'] : '0'; ?>">
    <div class="modal-dialog modal-lg" role="document">
        <div class="modal-content">
            <form method="POST" action="purchase-receive.php">
                <?php echo csrf_field(); ?>
                <?php echo form_token_field(); ?>
                <input type="hidden" name="purchase_id" id="receivePurchaseId" value="">
                <input type="hidden" name="return_to" value="purchases">
                <div class="modal-header">
                    <h5 class="modal-title" id="purchaseReceiveModalLabel"><i class="fa fa-download mr-1"></i> Confirm Goods Received</h5>
                    <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                        <span aria-hidden="true">&times;</span>
                    </button>
                </div>
                <div class="modal-body">
                    <p class="mb-2">Purchase <strong id="receivePurchaseLabel"></strong> — <span id="receiveProductName"></span></p>
                    <p class="text-muted small">Record what actually arrived. Good units enter stock and stock-held capital. Damaged, returned and other units are recorded as a supplier return and do not enter stock. The goods status becomes <strong>Arrived at Godown</strong> only when you confirm.</p>
                    <div class="form-group">
                        <label>Quantity Received <span class="text-danger">*</span></label>
                        <input type="number" name="received_quantity" id="receiveReceived" class="form-control" min="1" step="1" required>
                        <small class="text-muted">Total that arrived, including damaged and returned units. Ordered: <strong id="receiveOrderedLabel"></strong> <span id="receiveUnitLabel"></span>.</small>
                    </div>
                    <div class="form-row">
                        <div class="form-group col-6">
                            <label>Damaged</label>
                            <input type="number" name="damaged_quantity" id="receiveDamaged" class="form-control receive-deduction" min="0" step="1" value="0">
                            <small class="text-muted">Broken / not sellable.</small>
                        </div>
                        <div class="form-group col-6">
                            <label>To return to supplier</label>
                            <input type="number" name="returned_quantity" id="receiveReturned" class="form-control receive-deduction" min="0" step="1" value="0">
                            <small class="text-muted">Goods going back to the supplier.</small>
                        </div>
                    </div>
                    <div class="form-group">
                        <label>Other (missing / rejected)</label>
                        <input type="number" name="other_quantity" id="receiveOther" class="form-control receive-deduction" min="0" step="1" value="0">
                    </div>
                    <div class="alert alert-info py-2 mb-2">
                        Good quantity to add to stock: <strong id="receiveGoodPreview">0</strong> <span id="receiveGoodUnit"></span>
                    </div>
                    <div class="form-group mb-0">
                        <label>Confirmation Notes</label>
                        <textarea name="notes" class="form-control" rows="2" maxlength="2000" placeholder="Optional note about this delivery"></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-light" data-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-success"><i class="fa fa-check mr-1"></i> Confirm Received</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
$(function () {
    var $modal = $('#purchaseReceiveModal');
    var received = document.getElementById('receiveReceived');
    var deductions = document.querySelectorAll('#purchaseReceiveModal .receive-deduction');
    var preview = document.getElementById('receiveGoodPreview');

    function refreshPreview() {
        var good = parseFloat(received.value) || 0;
        deductions.forEach(function (input) { good -= parseFloat(input.value) || 0; });
        preview.textContent = (good > 0 ? good : 0).toLocaleString(undefined, {
            minimumFractionDigits: 0,
            maximumFractionDigits: 2
        });
    }

    function fill(data) {
        var ordered = parseFloat(data.ordered) || 0;
        var decimal = parseInt(data.decimal, 10) === 1;
        var step = decimal ? '0.01' : '1';
        $('#receivePurchaseId').val(data.id);
        $('#receivePurchaseLabel').text(data.label || '');
        $('#receiveProductName').text(data.product || '');
        $('#receiveOrderedLabel').text(ordered.toLocaleString(undefined, {maximumFractionDigits: 2}));
        $('#receiveUnitLabel, #receiveGoodUnit').text(data.unit || '');
        received.value = ordered.toFixed(decimal ? 2 : 0);
        received.max = ordered.toFixed(decimal ? 2 : 0);
        received.min = decimal ? '0.01' : '1';
        received.step = step;
        deductions.forEach(function (input) {
            input.value = '0';
            input.max = ordered.toFixed(decimal ? 2 : 0);
            input.step = step;
        });
        refreshPreview();
    }

    $('[data-target="#purchaseReceiveModal"]').on('click', function () {
        var $button = $(this);
        fill({
            id: $button.data('purchase-id'),
            label: $button.data('purchase-label'),
            product: $button.data('product'),
            ordered: $button.data('ordered'),
            unit: $button.data('unit'),
            decimal: $button.data('decimal')
        });
    });

    deductions.forEach(function (input) { input.addEventListener('input', refreshPreview); });
    received.addEventListener('input', refreshPreview);

    if (String($modal.data('auto')) === '1') {
        fill({
            id: $modal.data('auto-id'),
            label: $modal.data('auto-label'),
            product: $modal.data('auto-product'),
            ordered: $modal.data('auto-ordered'),
            unit: $modal.data('auto-unit'),
            decimal: $modal.data('auto-decimal')
        });
        $modal.modal('show');
    }
});
</script>

<?php require_once 'footer.php'; ?>
