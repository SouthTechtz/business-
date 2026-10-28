<?php
require_once 'header.php';
require_once 'auth_check.php';
require_permission_any(['manage_expenses', 'manage_settings']);
$page_title = 'Expenses';

$cur = tenant_currency();
$msg = '';

$categories = fetch_all("SELECT * FROM expense_categories WHERE tenant_id = ? ORDER BY name", $current_user->tenant_id);
$payment_methods = fetch_all("SELECT * FROM payment_methods WHERE tenant_id = ? ORDER BY payment_method_id", $current_user->tenant_id);

// ---- Actions ----
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'add_expense' || $action === 'edit_expense') {
        $id = (int)($_POST['expense_id'] ?? 0);
        $category_id = (int)($_POST['category_id'] ?? 0) ?: null;
        $expense_date = trim($_POST['expense_date'] ?? '');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $expense_date)) {
            $expense_date = date('Y-m-d');
        }
        $payment_method_id = (int)($_POST['payment_method_id'] ?? 0) ?: null;
        $notes = trim($_POST['notes'] ?? '');

        // Item lines: name, quantity, unit price
        $names = $_POST['item_name'] ?? [];
        $qtys = $_POST['item_qty'] ?? [];
        $prices = $_POST['item_price'] ?? [];
        $items = [];
        foreach ($names as $i => $n) {
            $name = trim((string)$n);
            $qty = (float)($qtys[$i] ?? 1);
            $price = (float)($prices[$i] ?? 0);
            if ($name === '' || $price <= 0) continue;
            if ($qty <= 0) $qty = 1;
            $items[] = ['name' => $name, 'quantity' => $qty, 'unit_price' => $price, 'total' => $qty * $price];
        }
        if (!$items) {
            $msg = ['danger', 'Add at least one item with a name and a unit price.'];
        } else {
            $amount = array_sum(array_column($items, 'total'));
            $desc_parts = array_map(fn($it) => $it['name'] . ' x' . rtrim(rtrim(number_format($it['quantity'], 2, '.', ''), '0'), '.') . ' @' . number_format($it['unit_price'], 0), $items);
            $description = implode('; ', $desc_parts);
            $conn->begin_transaction();
            try {
                if ($action === 'add_expense') {
                    $stmt = $conn->prepare(
                        "INSERT INTO expenses (tenant_id, category_id, description, amount, expense_date, payment_method_id, notes, created_by)
                         VALUES (?, ?, ?, ?, ?, ?, ?, ?)"
                    );
                    $stmt->bind_param('iisdsisi', $current_user->tenant_id, $category_id, $description, $amount, $expense_date, $payment_method_id, $notes, $current_user->user_id);
                    $stmt->execute();
                    $eid = $stmt->insert_id;
                    $stmt->close();
                    audit_log('create', 'expense', $eid, 'Expense recorded: ' . $description . ' (' . money($amount, $cur) . ')');
                    $msg = ['success', 'Expense recorded successfully.'];
                } else {
                    $stmt = $conn->prepare(
                        "UPDATE expenses SET category_id = ?, description = ?, amount = ?, expense_date = ?, payment_method_id = ?, notes = ?
                         WHERE expense_id = ? AND tenant_id = ?"
                    );
                    $stmt->bind_param('isdsisii', $category_id, $description, $amount, $expense_date, $payment_method_id, $notes, $id, $current_user->tenant_id);
                    $stmt->execute();
                    $stmt->close();
                    $stmt = $conn->prepare("DELETE FROM expense_items WHERE expense_id = ? AND tenant_id = ?");
                    $stmt->bind_param('ii', $id, $current_user->tenant_id);
                    $stmt->execute();
                    $stmt->close();
                    $eid = $id;
                    audit_log('update', 'expense', $id, 'Expense updated: ' . $description);
                    $msg = ['success', 'Expense updated successfully.'];
                }
                foreach ($items as $it) {
                    $stmt = $conn->prepare(
                        "INSERT INTO expense_items (tenant_id, expense_id, name, quantity, unit_price, total) VALUES (?, ?, ?, ?, ?, ?)"
                    );
                    $stmt->bind_param('iisddd', $current_user->tenant_id, $eid, $it['name'], $it['quantity'], $it['unit_price'], $it['total']);
                    $stmt->execute();
                    $stmt->close();
                }
                $conn->commit();
            } catch (Exception $ex) {
                $conn->rollback();
                $msg = ['danger', 'Could not save expense: ' . $ex->getMessage()];
            }
        }
    } elseif ($action === 'delete_expense') {
        $id = (int)($_POST['expense_id'] ?? 0);
        $stmt = $conn->prepare("DELETE FROM expenses WHERE expense_id = ? AND tenant_id = ?");
        $stmt->bind_param('ii', $id, $current_user->tenant_id);
        $stmt->execute();
        $stmt->close();
        audit_log('delete', 'expense', $id, 'Expense deleted');
        $msg = ['success', 'Expense deleted.'];
    } elseif ($action === 'add_category') {
        $name = trim($_POST['name'] ?? '');
        if ($name !== '') {
            db_insert('expense_categories', ['tenant_id' => $current_user->tenant_id, 'name' => $name]);
            $msg = ['success', 'Category added.'];
        }
    } elseif ($action === 'delete_category') {
        $cid = (int)($_POST['category_id'] ?? 0);
        $used = fetch_one("SELECT COUNT(*) c FROM expenses WHERE category_id = ? AND tenant_id = ?", $cid, $current_user->tenant_id);
        if (($used['c'] ?? 0) > 0) {
            $msg = ['danger', 'This category is used by existing expenses.'];
        } else {
            $stmt = $conn->prepare("DELETE FROM expense_categories WHERE expense_category_id = ? AND tenant_id = ?");
            $stmt->bind_param('ii', $cid, $current_user->tenant_id);
            $stmt->execute();
            $stmt->close();
            $msg = ['success', 'Category deleted.'];
        }
    }
}

// ---- Filters ----
$month = trim($_GET['month'] ?? '');
$cat_filter = (int)($_GET['category'] ?? 0);
$where = "WHERE e.tenant_id = ?";
$params = [$current_user->tenant_id];
if ($month !== '' && preg_match('/^\d{4}-\d{2}$/', $month)) {
    $where .= " AND DATE_FORMAT(e.expense_date, '%Y-%m') = ?";
    $params[] = $month;
}
if ($cat_filter > 0) {
    $where .= " AND e.category_id = ?";
    $params[] = $cat_filter;
}

$expenses = fetch_all(
    "SELECT e.*, c.name category_name, m.name method_name
     FROM expenses e
     LEFT JOIN expense_categories c ON c.expense_category_id = e.category_id
     LEFT JOIN payment_methods m ON m.payment_method_id = e.payment_method_id
     $where ORDER BY e.expense_date DESC, e.expense_id DESC",
    ...$params
);

// Load item lines for all visible expenses
$exp_ids = array_map(fn($e) => (int)$e['expense_id'], $expenses);
$items_by_expense = [];
if ($exp_ids) {
    $in = implode(',', $exp_ids);
    $item_rows = fetch_all(
        "SELECT expense_id, name, quantity, unit_price, total FROM expense_items
         WHERE tenant_id = ? AND expense_id IN ($in) ORDER BY item_id",
        $current_user->tenant_id
    );
    foreach ($item_rows as $ir) {
        $items_by_expense[(int)$ir['expense_id']][] = $ir;
    }
}

$total_month = 0;
$total_all = 0;
$this_month = date('Y-m');
foreach ($expenses as $ex) {
    $total_all += (float)$ex['amount'];
    if (date('Y-m', strtotime($ex['expense_date'])) === $this_month) {
        $total_month += (float)$ex['amount'];
    }
}

require_once 'navbar.php';
require_once 'sidebar.php';
?>
<div class="page-head">
    <div>
        <h1 class="page-title">Expenses</h1>
        <div class="page-sub">Record and track all company expenses.</div>
    </div>
    <button class="btn btn-primary" data-toggle="modal" data-target="#expenseModal"><i class="fa fa-plus mr-1"></i> New Expense</button>
    <a href="export-expenses.php?<?php echo e(http_build_query(array_filter(['month' => $month, 'category' => $cat_filter > 0 ? $cat_filter : ''], fn($v) => $v !== ''))); ?>" class="btn btn-outline-success"><i class="fa fa-file-excel-o mr-1"></i> Excel</a>
</div>

<?php if ($msg): ?>
    <div class="alert alert-<?php echo e($msg[0]); ?>"><?php echo e($msg[1]); ?></div>
<?php endif; ?>

<div class="row mb-3">
    <div class="col-md-6 col-xl-3 mb-3"><div class="widget"><div class="widget-icon wi-red"><i class="fa fa-calendar"></i></div><div class="widget-info"><div class="widget-value"><?php echo e(money($total_month, $cur)); ?></div><div class="widget-label">Expenses this month</div></div></div></div>
    <div class="col-md-6 col-xl-3 mb-3"><div class="widget"><div class="widget-icon wi-amber"><i class="fa fa-money"></i></div><div class="widget-info"><div class="widget-value"><?php echo e(money($total_all, $cur)); ?></div><div class="widget-label">Total recorded</div></div></div></div>
    <div class="col-md-6 col-xl-3 mb-3"><div class="widget"><div class="widget-icon wi-blue"><i class="fa fa-tags"></i></div><div class="widget-info"><div class="widget-value"><?php echo count($expenses); ?></div><div class="widget-label">Records in filter</div></div></div></div>
    <div class="col-md-6 col-xl-3 mb-3"><div class="widget"><div class="widget-icon wi-purple"><i class="fa fa-folder-open"></i></div><div class="widget-info"><div class="widget-value"><?php echo count($categories); ?></div><div class="widget-label">Categories</div></div></div></div>
</div>

<div class="card">
    <div class="card-header d-flex justify-content-between align-items-center flex-wrap">
        <h5 class="card-title"><i class="fa fa-money mr-1" style="color:var(--primary)"></i> Expense Records</h5>
        <div class="d-flex align-items-center flex-wrap">
            <form method="GET" action="expenses.php" class="form-inline">
                <input type="month" name="month" class="form-control form-control-sm mr-2" value="<?php echo e($month); ?>">
                <select name="category" class="form-control form-control-sm mr-2">
                    <option value="0">All categories</option>
                    <?php foreach ($categories as $c): ?>
                        <option value="<?php echo $c['expense_category_id']; ?>" <?php echo $cat_filter === (int)$c['expense_category_id'] ? 'selected' : ''; ?>><?php echo e($c['name']); ?></option>
                    <?php endforeach; ?>
                </select>
                <button class="btn btn-sm btn-light"><i class="fa fa-filter mr-1"></i>Filter</button>
            </form>
            <button class="btn btn-sm btn-outline-primary ml-2" data-toggle="modal" data-target="#catModal"><i class="fa fa-tags mr-1"></i>Categories</button>
            <div class="input-group dt-search ml-2">
                <div class="input-group-prepend"><span class="input-group-text"><i class="fa fa-search"></i></span></div>
                <input type="text" class="form-control dt-search-input" data-scope="expenses" placeholder="Search expenses...">
            </div>
        </div>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover data-table mb-0">
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Category</th>
                        <th>Description (Items)</th>
                        <th>Paid Via</th>
                        <th class="text-right">Total Amount</th>
                        <th class="no-print">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($expenses as $ex): ?>
                    <?php $lines = $items_by_expense[(int)$ex['expense_id']] ?? []; ?>
                    <tr>
                        <td class="text-nowrap"><?php echo e(preg_match('/^\d{4}-\d{2}-\d{2}$/', $ex['expense_date']) ? date('M d, Y', strtotime($ex['expense_date'])) : '—'); ?></td>
                        <td><span class="badge badge-secondary"><?php echo e($ex['category_name'] ?? 'Uncategorised'); ?></span></td>
                        <td>
                            <?php if ($lines): ?>
                                <?php foreach ($lines as $li): ?>
                                    <div class="small">
                                        <span class="font-weight-semibold"><?php echo e($li['name']); ?></span>
                                        <span class="text-muted">
                                            &times; <?php echo e(rtrim(rtrim(number_format((float)$li['quantity'], 2, '.', ''), '0'), '.')); ?>
                                            @ <?php echo e(number_format((float)$li['unit_price'], 0)); ?>
                                            = <?php echo e(number_format((float)$li['total'], 0)); ?>
                                        </span>
                                    </div>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <span class="font-weight-semibold"><?php echo e($ex['description']); ?></span>
                            <?php endif; ?>
                            <?php if ($ex['notes']): ?><br><small class="text-muted"><?php echo e($ex['notes']); ?></small><?php endif; ?>
                        </td>
                        <td><?php echo e($ex['method_name'] ?? '—'); ?></td>
                        <td class="text-right font-weight-semibold" style="color:var(--danger)"><?php echo e(money($ex['amount'], $cur)); ?></td>
                        <td class="no-print">
                            <button class="btn btn-action btn-light edit-expense" data-id="<?php echo $ex['expense_id']; ?>" data-category="<?php echo (int)$ex['category_id']; ?>" data-description="<?php echo e($ex['description']); ?>" data-amount="<?php echo e($ex['amount']); ?>" data-date="<?php echo e($ex['expense_date']); ?>" data-method="<?php echo (int)$ex['payment_method_id']; ?>" data-notes="<?php echo e($ex['notes']); ?>" data-items='<?php echo e(json_encode($lines)); ?>'><i class="fa fa-pencil"></i></button>
                            <form method="POST" action="expenses.php" class="d-inline" onsubmit="return confirm('Delete this expense?');">
                                <input type="hidden" name="action" value="delete_expense">
                                <input type="hidden" name="expense_id" value="<?php echo $ex['expense_id']; ?>">
                                <button class="btn btn-action btn-light"><i class="fa fa-trash"></i></button>
                            </form>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Add / Edit expense modal -->
<div class="modal fade" id="expenseModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <form method="POST" action="expenses.php">
                <div class="modal-header">
                    <h5 class="modal-title" id="expenseModalTitle">New Expense</h5>
                    <button type="button" class="close" data-dismiss="modal">&times;</button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="action" id="expenseAction" value="add_expense">
                    <input type="hidden" name="expense_id" id="expenseId" value="0">
                    <div class="row">
                        <div class="col-md-6 form-group">
                            <label>Category</label>
                            <select name="category_id" id="expenseCategory" class="form-control">
                                <option value="0">Uncategorised</option>
                                <?php foreach ($categories as $c): ?>
                                    <option value="<?php echo $c['expense_category_id']; ?>"><?php echo e($c['name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6 form-group">
                            <label>Date <span class="text-danger">*</span></label>
                            <input type="date" name="expense_date" id="expenseDate" class="form-control" value="<?php echo date('Y-m-d'); ?>" required>
                        </div>
                    </div>
                    <div class="form-group">
                        <label>Items <span class="text-danger">*</span></label>
                        <div id="expenseItems">
                            <div class="expense-item-row mb-2">
                                <div class="row">
                                    <div class="col-md-5 form-group mb-0"><input type="text" name="item_name[]" class="form-control" placeholder="Item name (e.g. Paint 4L, Brush)"></div>
                                    <div class="col-md-2 form-group mb-0"><input type="number" name="item_qty[]" class="form-control item-qty" placeholder="Qty" min="0" step="any" value="1"></div>
                                    <div class="col-md-3 form-group mb-0"><input type="number" name="item_price[]" class="form-control item-price" placeholder="Unit price" min="0" step="any"></div>
                                    <div class="col-md-2 form-group mb-0">
                                        <button type="button" class="btn btn-outline-danger btn-sm remove-item-row" title="Remove item"><i class="fa fa-times"></i></button>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <button type="button" class="btn btn-sm btn-outline-primary" id="addItemRow"><i class="fa fa-plus mr-1"></i> Add Item</button>
                    </div>
                    <div class="d-flex justify-content-between align-items-center py-2 px-3 mb-3" style="background:#f8fafc;border-radius:10px;">
                        <span class="font-weight-semibold">Total</span>
                        <span class="font-weight-bold" id="expenseTotal" style="font-size:18px;">0.00</span>
                    </div>
                    <div class="row">
                        <div class="col-md-6 form-group">
                            <label>Paid Via</label>
                            <select name="payment_method_id" id="expenseMethod" class="form-control">
                                <option value="0">— Not selected —</option>
                                <?php foreach ($payment_methods as $pm): ?>
                                    <option value="<?php echo $pm['payment_method_id']; ?>"><?php echo e($pm['name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6 form-group">
                            <label>Notes</label>
                            <input type="text" name="notes" id="expenseNotes" class="form-control" placeholder="Optional note">
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-light" data-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary"><i class="fa fa-check mr-1"></i> Save Expense</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Categories modal -->
<div class="modal fade" id="catModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Expense Categories</h5>
                <button type="button" class="close" data-dismiss="modal">&times;</button>
            </div>
            <div class="modal-body">
                <form method="POST" action="expenses.php" class="form-inline mb-3">
                    <input type="hidden" name="action" value="add_category">
                    <input type="text" name="name" class="form-control mr-2" placeholder="New category name" required style="flex:1">
                    <button class="btn btn-sm btn-primary"><i class="fa fa-plus mr-1"></i>Add</button>
                </form>
                <ul class="list-group">
                    <?php foreach ($categories as $c): ?>
                    <li class="list-group-item d-flex justify-content-between align-items-center">
                        <span><i class="fa fa-tag mr-2 text-muted"></i><?php echo e($c['name']); ?></span>
                        <form method="POST" action="expenses.php" class="d-inline" onsubmit="return confirm('Delete this category?');">
                            <input type="hidden" name="action" value="delete_category">
                            <input type="hidden" name="category_id" value="<?php echo $c['expense_category_id']; ?>">
                            <button class="btn btn-action btn-light"><i class="fa fa-trash"></i></button>
                        </form>
                    </li>
                    <?php endforeach; ?>
                </ul>
            </div>
        </div>
    </div>
</div>

<script>
    $(function () {
        function recalcTotal() {
            var total = 0;
            $('#expenseItems .expense-item-row').each(function () {
                var qty = parseFloat($(this).find('.item-qty').val()) || 0;
                var price = parseFloat($(this).find('.item-price').val()) || 0;
                total += qty * price;
            });
            $('#expenseTotal').text(total.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 }));
        }
        function addItemRow(name, qty, price) {
            var row = '<div class="expense-item-row mb-2"><div class="row">' +
                '<div class="col-md-5 form-group mb-0"><input type="text" name="item_name[]" class="form-control" placeholder="Item name (e.g. Paint 4L, Brush)" value="' + (name ? $('<span>').text(name).html() : '') + '"></div>' +
                '<div class="col-md-2 form-group mb-0"><input type="number" name="item_qty[]" class="form-control item-qty" placeholder="Qty" min="0" step="any" value="' + (qty || 1) + '"></div>' +
                '<div class="col-md-3 form-group mb-0"><input type="number" name="item_price[]" class="form-control item-price" placeholder="Unit price" min="0" step="any" value="' + (price || '') + '"></div>' +
                '<div class="col-md-2 form-group mb-0"><button type="button" class="btn btn-outline-danger btn-sm remove-item-row" title="Remove item"><i class="fa fa-times"></i></button></div>' +
                '</div></div>';
            $('#expenseItems').append(row);
            recalcTotal();
        }
        $('#addItemRow').on('click', function () { addItemRow('', 1, ''); });
        $('#expenseItems').on('click', '.remove-item-row', function () {
            $(this).closest('.expense-item-row').remove();
            recalcTotal();
        });
        $('#expenseItems').on('input', '.item-qty, .item-price', recalcTotal);

        $('.edit-expense').on('click', function () {
            $('#expenseModalTitle').text('Edit Expense');
            $('#expenseAction').val('edit_expense');
            $('#expenseId').val($(this).data('id'));
            $('#expenseCategory').val($(this).data('category'));
            var ed = $(this).data('date');
            if (typeof ed !== 'string' || !/^\d{4}-\d{2}-\d{2}$/.test(ed)) {
                var now = new Date();
                ed = now.getFullYear() + '-' + String(now.getMonth() + 1).padStart(2, '0') + '-' + String(now.getDate()).padStart(2, '0');
            }
            $('#expenseDate').val(ed);
            $('#expenseMethod').val($(this).data('method'));
            $('#expenseNotes').val($(this).data('notes') === '0' ? '' : $(this).data('notes'));
            $('#expenseItems').empty();
            var items = $(this).data('items') || [];
            if (items.length) {
                $.each(items, function (_, it) { addItemRow(it.name, it.quantity, it.unit_price); });
            } else {
                addItemRow('', 1, '');
            }
            recalcTotal();
            $('#expenseModal').modal('show');
        });
        $('[data-target="#expenseModal"]').on('click', function () {
            $('#expenseModalTitle').text('New Expense');
            $('#expenseAction').val('add_expense');
            $('#expenseId').val(0);
            $('#expenseItems').empty();
            addItemRow('', 1, '');
            $('#expenseNotes').val('');
            $('#expenseMethod').val(0);
            var now = new Date();
            var localDate = now.getFullYear() + '-' + String(now.getMonth() + 1).padStart(2, '0') + '-' + String(now.getDate()).padStart(2, '0');
            $('#expenseDate').val(localDate);
            $('#expenseCategory').val(0);
        });
    });
</script>
<?php require_once 'footer.php'; ?>
