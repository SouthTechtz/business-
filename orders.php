<?php
require_once 'config.php';
require_once 'auth_check.php';
require_permission('manage_orders');
$page_title = 'Orders';

// Handle AJAX requests for quick modal updates (before any HTML output)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_action']) && $_POST['ajax_action'] === 'quick_update_order') {
    @ini_set('display_errors', 0);
    header('Content-Type: application/json');

    try {
        $order_id = (int)($_POST['order_id'] ?? 0);
        $amount_paid = (float)($_POST['Amount_paid'] ?? 0);
        $stage = trim($_POST['stage'] ?? 'shop');
        $valid_stages = ['shop', 'transport', 'completed', 'returned'];

        if ($order_id <= 0) {
            echo json_encode(['success' => false, 'message' => 'Invalid order ID.']);
            exit;
        }

        $order = fetch_one("SELECT * FROM orders WHERE order_id = ? AND tenant_id = ?", $order_id, (int)$current_user->tenant_id);

        if (!$order) {
            echo json_encode(['success' => false, 'message' => 'Order not found.']);
            exit;
        }

        if (!in_array($stage, $valid_stages, true)) {
            $stage = 'shop';
        }

        $total = (float)$order['total'];

        if ($amount_paid >= $total && $total > 0) {
            $status = 'paid';
        } elseif ($amount_paid > 0) {
            $status = 'partially_paid';
        } else {
            $status = 'unpaid';
        }

        $stmt = $conn->prepare("UPDATE orders SET Amount_paid = ?, status = ?, stage = ? WHERE order_id = ? AND tenant_id = ?");
        if (!$stmt) {
            echo json_encode(['success' => false, 'message' => 'SQL Prepare failed: ' . $conn->error]);
            exit;
        }

        $stmt->bind_param('dssii', $amount_paid, $status, $stage, $order_id, (int)$current_user->tenant_id);

        if ($stmt->execute()) {
            if (function_exists('audit_log')) {
                audit_log('update', 'order', $order_id, 'Quick updated amount paid and stage');
            }
            echo json_encode(['success' => true]);
        } else {
            echo json_encode(['success' => false, 'message' => 'Database update failed: ' . $stmt->error]);
        }
        $stmt->close();
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
    }
    exit;
}

require_once 'header.php';
require_once 'navbar.php';
require_once 'sidebar.php';
?>
$statuses = [
    'unpaid'          => 'Unpaid',
    'partially_paid'  => 'Partially Paid',
    'paid'            => 'Paid'
];

$stages = [
    'shop'      => 'At the shop',
    'transport' => 'In transport',
    'completed' => 'Completed',
    'returned'  => 'Returned'
];

$status = (string)($_GET['status'] ?? '');
if ($status !== '' && !isset($statuses[$status])) {
    $status = '';
}
$shop_id = (int)($_GET['shop'] ?? 0);
$shops = accessible_shops(null, true);
$shop_ids = array_map(fn($s) => (int)$s['shop_id'], $shops);
if ($shop_id > 0 && !in_array($shop_id, $shop_ids, true)) {
    $shop_id = 0;
}
$assigned = (string)($_GET['assigned'] ?? '');
$q = trim((string)($_GET['q'] ?? ''));

$sql = "SELECT o.*, s.name shop_name, s.code shop_code, w.name store_name, w.code store_code,
               c.first_name, c.last_name, c.company_name,
               uc.first_name creator_first, uc.last_name creator_last,
               ua.first_name assignee_first, ua.last_name assignee_last,
               o.Amount_paid
        FROM orders o
        LEFT JOIN shops s ON s.shop_id = o.shop_id AND s.tenant_id = o.tenant_id
        LEFT JOIN warehouses w ON w.warehouse_id = o.warehouse_id AND w.tenant_id = o.tenant_id
        LEFT JOIN contacts c ON c.contact_id = o.contact_id AND c.tenant_id = o.tenant_id
        LEFT JOIN users uc ON uc.user_id = o.created_by
        LEFT JOIN users ua ON ua.user_id = o.assigned_to
        WHERE o.tenant_id = ?";
$params = [(int)$current_user->tenant_id];

[$scope_sql, $scope_params] = order_visibility_clause('o');
$sql .= $scope_sql;
$params = array_merge($params, $scope_params);

if ($status !== '') {
    $sql .= " AND o.status = ?";
    $params[] = $status;
}
if ($shop_id > 0) {
    $sql .= " AND o.shop_id = ?";
    $params[] = $shop_id;
}
if ($assigned === 'me') {
    $sql .= " AND o.assigned_to = ?";
    $params[] = (int)$current_user->user_id;
} elseif ($assigned === 'unassigned') {
    $sql .= " AND o.assigned_to IS NULL";
} elseif (ctype_digit($assigned) && (int)$assigned > 0) {
    $sql .= " AND o.assigned_to = ?";
    $params[] = (int)$assigned;
}
if ($q !== '') {
    $like = '%' . $q . '%';
    $sql .= " AND (o.order_number LIKE ? OR o.customer_name LIKE ? OR o.customer_phone LIKE ?
                  OR o.customer_location LIKE ? OR o.fulfilled_invoice_number LIKE ?)";
    array_push($params, $like, $like, $like, $like, $like);
}
$sql .= " ORDER BY o.created_at DESC, o.order_id DESC";

$orders = fetch_all($sql, ...$params);
$staff = users_with_permission('manage_orders');

// Calculate initial full-list sums for fallback render
$total_paid_sum = 0;
$total_amount_sum = 0;
foreach ($orders as $o) {
    $p_amount = isset($o['Amount_paid']) ? (float)$o['Amount_paid'] : ($o['status'] === 'paid' ? (float)$o['total'] : 0.00);
    $total_paid_sum += $p_amount;
    $total_amount_sum += (float)$o['total'];
}

require_once 'navbar.php';
require_once 'sidebar.php';
$cur = tenant_currency();

function payment_status_class($st) {
    switch ($st) {
        case 'paid': return 'success';
        case 'partially_paid': return 'warning';
        case 'unpaid': default: return 'danger';
    }
}

function order_stage_class($stage) {
    switch ($stage) {
        case 'completed': return 'success'; // Green
        case 'returned': return 'danger';   // Red
        case 'transport': return 'info';
        case 'shop': default: return 'primary';
    }
}
?>

<div class="page-head">
    <div>
        <h1 class="page-title">Orders</h1>
        <div class="page-sub"><?php echo count($orders); ?> order(s) match current filters.</div>
    </div>
    <a href="order-add.php" class="btn btn-primary"><i class="fa fa-plus mr-1"></i> New Order</a>
</div>

<?php if ($f = flash_get()): ?>
    <div class="alert alert-<?php echo e($f['type']); ?>"><?php echo e($f['message']); ?></div>
<?php endif; ?>

<style>
    .table-top-summary {
        background-color: var(--light, #f8f9fa) !important;
        font-weight: 600;
        border-bottom: 2px solid var(--primary, #6f42c1) !important;
    }
</style>

<div class="card">
    <div class="card-header">
        <form method="GET" action="orders.php" class="form-inline flex-wrap">
            <select name="status" class="form-control form-control-sm mr-2 mb-1">
                <option value="">All Payment Statuses</option>
                <?php foreach ($statuses as $key => $label): ?>
                    <option value="<?php echo e($key); ?>" <?php echo $status === $key ? 'selected' : ''; ?>><?php echo e($label); ?></option>
                <?php endforeach; ?>
            </select>
            <select name="shop" class="form-control form-control-sm mr-2 mb-1">
                <option value="0">All Shops</option>
                <?php foreach ($shops as $shop): ?>
                    <option value="<?php echo (int)$shop['shop_id']; ?>" <?php echo $shop_id === (int)$shop['shop_id'] ? 'selected' : ''; ?>>
                        <?php echo e($shop['name']); ?><?php echo (int)$shop['is_active'] ? '' : ' (inactive)'; ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <select name="assigned" class="form-control form-control-sm mr-2 mb-1">
                <option value="">Anyone</option>
                <option value="me" <?php echo $assigned === 'me' ? 'selected' : ''; ?>>Assigned to me</option>
                <option value="unassigned" <?php echo $assigned === 'unassigned' ? 'selected' : ''; ?>>Unassigned</option>
                <?php foreach ($staff as $u): ?>
                    <option value="<?php echo (int)$u['user_id']; ?>" <?php echo $assigned === (string)$u['user_id'] ? 'selected' : ''; ?>>
                        <?php echo e(trim($u['first_name'] . ' ' . $u['last_name']) ?: $u['username']); ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <input type="text" name="q" class="form-control form-control-sm mr-2 mb-1" value="<?php echo e($q); ?>" placeholder="Order, customer, phone...">
            <button type="submit" class="btn btn-sm btn-primary mb-1"><i class="fa fa-filter mr-1"></i> Filter</button>
            <?php if ($status !== '' || $shop_id > 0 || $assigned !== '' || $q !== ''): ?>
                <a href="orders.php" class="btn btn-sm btn-light mb-1 ml-2">Clear</a>
            <?php endif; ?>
        </form>
        <div class="input-group dt-search ml-auto">
            <div class="input-group-prepend"><span class="input-group-text"><i class="fa fa-search"></i></span></div>
            <input type="text" class="form-control dt-search-input" data-scope="orders" placeholder="Search orders...">
        </div>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover data-table" id="ordersTable">
                <thead>
                    <tr>
                        <th>Order #</th><th>Customer</th><th>Shop / Store</th><th>Stage</th>
                        <th>Status</th><th class="text-right">Paid Amount</th><th class="text-right">Total</th><th>Responsible</th><th>Created</th><th class="text-right">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <!-- TOP SUMMARY ROW -->
                    <tr class="table-top-summary">
                        <td colspan="5" class="text-dark font-weight-bold">
                            <i class="fa fa-calculator mr-1 text-muted"></i> Summary (<span id="orderListCount"><?php echo count($orders); ?></span> orders shown):
                        </td>
                        <td class="text-right text-success font-weight-bold" id="topTotalPaid">
                            <?php echo e(money($total_paid_sum, $cur)); ?>
                        </td>
                        <td class="text-right text-dark font-weight-bold" id="topTotalAmount">
                            <?php echo e(money($total_amount_sum, $cur)); ?>
                        </td>
                        <td colspan="3"></td>
                    </tr>

                    <!-- ORDER ROWS -->
                    <?php foreach ($orders as $o): ?>
                        <?php 
                            $paid_amount = isset($o['Amount_paid']) ? (float)$o['Amount_paid'] : ($o['status'] === 'paid' ? (float)$o['total'] : 0.00);
                            $current_stage = isset($o['stage']) && isset($stages[$o['stage']]) ? $o['stage'] : 'shop';
                        ?>
                        <tr data-order-id="<?php echo (int)$o['order_id']; ?>" data-paid-value="<?php echo e($paid_amount); ?>" data-total-value="<?php echo e($o['total']); ?>">
                            <td class="font-weight-semibold"><a href="order-view.php?id=<?php echo (int)$o['order_id']; ?>"><?php echo e($o['order_number']); ?></a></td>
                            <td>
                                <?php echo e($o['customer_name']); ?>
                                <?php if ($o['customer_phone']): ?><br><small class="text-muted"><?php echo e($o['customer_phone']); ?></small><?php endif; ?>
                            </td>
                            <td><?php echo e($o['shop_name'] ? trim(($o['shop_name'] ?? '') . ' / ' . ($o['store_name'] ?? ''), ' /') : 'â€”'); ?></td>
                            <td>
                                <span class="badge badge-<?php echo e(order_stage_class($current_stage)); ?>">
                                    <?php echo e($stages[$current_stage] ?? ucfirst($current_stage)); ?>
                                </span>
                            </td>
                            <td>
                                <span class="badge badge-<?php echo e(payment_status_class($o['status'])); ?>">
                                    <?php echo e($statuses[$o['status']] ?? ucfirst(str_replace('_', ' ', $o['status']))); ?>
                                </span>
                            </td>
                            <td class="text-right font-weight-semibold text-success"><?php echo e(money($paid_amount, $o['currency_code'] ?: $cur)); ?></td>
                            <td class="text-right"><?php echo e(money($o['total'], $o['currency_code'] ?: $cur)); ?></td>
                            <td>
                                <?php
                                $assignee = trim((string)($o['assignee_first'] ?? '') . ' ' . (string)($o['assignee_last'] ?? ''));
                                $creator = trim((string)($o['creator_first'] ?? '') . ' ' . (string)($o['creator_last'] ?? ''));
                                echo e($assignee ?: $creator ?: 'â€”');
                                ?>
                            </td>
                            <td><?php echo e(date('M d, Y H:i', strtotime($o['created_at']))); ?></td>
                            <td class="text-right">
                                <a href="order-view.php?id=<?php echo (int)$o['order_id']; ?>" class="btn btn-action btn-light" title="View"><i class="fa fa-eye"></i></a>
                                <button type="button" class="btn btn-action btn-light btn-quick-edit" title="Update Amount & Stage" 
                                    data-order-id="<?php echo (int)$o['order_id']; ?>"
                                    data-order-number="<?php echo e($o['order_number']); ?>"
                                    data-amount-paid="<?php echo e($paid_amount); ?>"
                                    data-total="<?php echo e(money($o['total'], $o['currency_code'] ?: $cur)); ?>"
                                    data-stage="<?php echo e($current_stage); ?>">
                                    <i class="fa fa-pencil"></i>
                                </button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$orders): ?>
                        <tr><td colspan="10" class="text-center text-muted py-4">No orders match the current filters. <a href="order-add.php">Record the first order</a>.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Quick Update Modal -->
<div class="modal fade" id="quickEditModal" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            <form id="quickEditForm" method="POST" action="orders.php">
                <input type="hidden" name="ajax_action" value="quick_update_order">
                <input type="hidden" name="order_id" id="qe_order_id">
                
                <div class="modal-header">
                    <h5 class="modal-title">Update Order: <span id="qe_order_number"></span></h5>
                    <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                        <span aria-hidden="true">&times;</span>
                    </button>
                </div>
                <div class="modal-body">
                    <div class="form-group">
                        <label>Amount Paid</label>
                        <input type="number" name="Amount_paid" id="qe_amount_paid" class="form-control" step="0.01" min="0" required>
                        <small class="text-muted">Total Order Amount: <span id="qe_total_display"></span></small>
                    </div>
                    <div class="form-group">
                        <label>Order Stage</label>
                        <select name="stage" id="qe_stage" class="form-control" required>
                            <option value="shop">At the shop</option>
                            <option value="transport">In transport</option>
                            <option value="completed">Completed</option>
                            <option value="returned">Returned</option>
                        </select>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary"><i class="fa fa-check mr-1"></i> Save Changes</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
    $(function () {
        var $table = $('#ordersTable');
        if (!$table.length) return;
        var currency = <?php echo json_encode($cur); ?>;

        function formatMoney(value) {
            return currency + ' ' + Number(value).toLocaleString(undefined, {
                minimumFractionDigits: 2,
                maximumFractionDigits: 2
            });
        }

        function recalcTopSummary() {
            var paidSum = 0, totalSum = 0, count = 0;
            var rows = $table.find('tbody tr').not('.table-top-summary');
            
            $(rows).each(function () {
                var pVal = parseFloat($(this).attr('data-paid-value'));
                var tVal = parseFloat($(this).attr('data-total-value'));
                if (!isNaN(pVal)) {
                    paidSum += pVal;
                    totalSum += tVal;
                    count++;
                }
            });
            
            $('#orderListCount').text(count);
            $('#topTotalPaid').text(formatMoney(paidSum));
            $('#topTotalAmount').text(formatMoney(totalSum));
        }

        // Open modal and prefill data on pencil edit icon click
        $(document).on('click', '.btn-quick-edit', function () {
            var btn = $(this);
            $('#qe_order_id').val(btn.data('order-id'));
            $('#qe_order_number').text(btn.data('order-number'));
            $('#qe_amount_paid').val(btn.data('amount-paid'));
            $('#qe_total_display').text(btn.data('total'));
            $('#qe_stage').val(btn.data('stage'));
            $('#quickEditModal').modal('show');
        });

        // Submit quick edit via AJAX
        $('#quickEditForm').on('submit', function (e) {
            e.preventDefault();
            var form = $(this);

            $.ajax({
                url: 'orders.php',
                type: 'POST',
                data: form.serialize(),
                dataType: 'json',
                success: function (res) {
                    if (res.success) {
                        $('#quickEditModal').modal('hide');
                        location.reload();
                    } else {
                        alert(res.message || 'Failed to update order.');
                    }
                },
                error: function () {
                    alert('Server error occurred.');
                }
            });
        });

        $table.on('init.dt draw.dt', recalcTopSummary);
        recalcTopSummary();
    });
</script>

<?php require_once 'footer.php'; ?>
