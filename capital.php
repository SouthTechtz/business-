<?php
require_once 'header.php';
require_once 'auth_check.php';
require_once 'capital-helpers.php';

if (!can_manage_capital()) {
    http_response_code(403);
    die('You do not have permission to manage capital.');
}
$page_title = 'Capital';
$cur = tenant_currency();
$tenant_id = (int)$current_user->tenant_id;
$is_super_admin = current_brand_scope() === '';

$view_brand = capital_resolve_brand($_GET['brand'] ?? '');
$msg = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = (string)($_POST['action'] ?? '');

    if ($action === 'add_movement') {
        $brand = capital_resolve_brand($_POST['brand'] ?? '');
        $movement_type = (string)($_POST['movement_type'] ?? 'contribution');
        $bank_name = trim((string)($_POST['bank_name'] ?? ''));
        $amount = (float)($_POST['amount'] ?? 0);
        $entry_date = trim((string)($_POST['entry_date'] ?? ''));
        $reference = trim((string)($_POST['reference'] ?? ''));
        $notes = trim((string)($_POST['notes'] ?? ''));

        if ($brand === '') {
            $msg = ['danger', 'Select the brand this capital belongs to.'];
        } elseif (!in_array($movement_type, ['contribution', 'withdrawal'], true)) {
            $msg = ['danger', 'Select whether this is capital added or withdrawn.'];
        } elseif ($bank_name === '') {
            $msg = ['danger', 'Enter the bank account for this capital.'];
        } elseif (mb_strlen($bank_name) > 150) {
            $msg = ['danger', 'The bank name must be 150 characters or fewer.'];
        } elseif ($amount <= 0) {
            $msg = ['danger', 'Enter a capital amount greater than zero.'];
        } elseif ($amount > 999999999999.99) {
            $msg = ['danger', 'That capital amount is too large.'];
        } elseif (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $entry_date) || !strtotime($entry_date)) {
            $entry_date = date('Y-m-d');
        } elseif (mb_strlen($reference) > 100) {
            $msg = ['danger', 'The reference must be 100 characters or fewer.'];
        } else {
            $canonical_bank = bank_canonical_name($tenant_id, $brand, $bank_name);
            if ($canonical_bank !== '') {
                $bank_name = $canonical_bank;
            }
            $conn->begin_transaction();
            try {
                if ($movement_type === 'withdrawal') {
                    // Total capital in bank is a brand-level figure. Lock the
                    // brand's movements so two withdrawals cannot overdraw it.
                    fetch_all(
                        "SELECT movement_id FROM capital_movements
                          WHERE tenant_id = ? AND brand = ?
                          FOR UPDATE",
                        $tenant_id, $brand
                    );
                    $position = brand_capital_breakdown($tenant_id, $brand);
                    $available = (float)$position['bank'];
                    if ($amount > $available + 0.005) {
                        throw new RuntimeException(
                            'Withdrawal exceeds the Total Capital in Bank for this brand.'
                            . ' Available: ' . money($available, $cur)
                        );
                    }
                }
                $movement_id = db_insert('capital_movements', [
                    'tenant_id'     => $tenant_id,
                    'brand'         => $brand,
                    'movement_type' => $movement_type,
                    'account_type'  => 'bank',
                    'bank_name'     => $bank_name,
                    'amount'        => $amount,
                    'entry_date'    => $entry_date,
                    'reference'     => $reference !== '' ? $reference : null,
                    'notes'         => $notes !== '' ? $notes : null,
                    'created_by'    => (int)$current_user->user_id,
                ]);
                if (!$movement_id) {
                    throw new RuntimeException('Could not save the capital movement.');
                }
                $conn->commit();
            } catch (Throwable $ex) {
                $conn->rollback();
                $msg = ['danger', $ex->getMessage()];
            }
            if (!$msg) {
                audit_log(
                    'create',
                    'capital',
                    $movement_id,
                    ($movement_type === 'withdrawal' ? 'Capital withdrawn' : 'Capital added')
                        . ' — ' . brand_label($brand) . ' / ' . $bank_name . ' (' . money($amount, $cur) . ')'
                );
                flash_set('success', $movement_type === 'withdrawal'
                    ? 'Capital withdrawal recorded. Total Capital in Bank has been updated.'
                    : 'Capital added successfully. Total Capital in Bank has been updated.');
                redirect('capital.php?brand=' . urlencode($brand));
            }
        }
    } elseif ($action === 'delete_movement') {
        if (!$is_super_admin) {
            $msg = ['danger', 'Only the system administrator can delete a capital movement.'];
        } else {
            $movement_id = (int)($_POST['movement_id'] ?? 0);
            $movement = fetch_one(
                "SELECT * FROM capital_movements WHERE movement_id = ? AND tenant_id = ?",
                $movement_id,
                $tenant_id
            );
            if (!$movement) {
                $msg = ['danger', 'Capital movement not found.'];
            } else {
                $stmt = $conn->prepare("DELETE FROM capital_movements WHERE movement_id = ? AND tenant_id = ?");
                $stmt->bind_param('ii', $movement_id, $tenant_id);
                $stmt->execute();
                $stmt->close();
                audit_log('delete', 'capital', $movement_id, 'Capital movement deleted');
                flash_set('success', 'Capital movement deleted. Total Capital in Bank has been updated.');
                redirect('capital.php?brand=' . urlencode((string)$movement['brand']));
            }
        }
    }
}

$breakdown = brand_capital_breakdown($tenant_id, $view_brand);
$summary = capital_summary($tenant_id, $view_brand);
$balance = capital_balance_sheet($tenant_id, $view_brand);
$movements = capital_movement_history($tenant_id, $view_brand);
$bank_names = capital_bank_names($tenant_id, $view_brand);
$bank_accounts = bank_accounts_summary($tenant_id, $view_brand);
$shop_sales = brand_shop_sales_summary($tenant_id, $view_brand);
$brand_rows = $is_super_admin ? brand_capital_breakdown_by_brand($tenant_id) : [];
$scope_brand_label = $view_brand !== '' ? brand_label($view_brand) : 'All Brands';

require_once 'navbar.php';
require_once 'sidebar.php';
?>


<div class="page-head">
    <div>
        <h1 class="page-title">Capital</h1>
        <div class="page-sub">
            Capital for <strong><?php echo e($scope_brand_label); ?></strong>.
            Total Capital in Bank is money held by the brand; Stock-Held Capital is inventory at cost.
        </div>
    </div>
    <a href="report-print.php?type=balance-sheet<?php echo $view_brand !== '' ? '&brand=' . urlencode($view_brand) : ''; ?>" class="btn btn-outline-primary">
        <i class="fa fa-balance-scale mr-1"></i> Balance Sheet Report
    </a>
</div>

<?php if ($f = flash_get()): ?>
    <div class="alert alert-<?php echo e($f['type']); ?>"><?php echo e($f['message']); ?></div>
<?php endif; ?>
<?php if ($msg): ?>
    <div class="alert alert-<?php echo e($msg[0]); ?>"><?php echo e($msg[1]); ?></div>
<?php endif; ?>

<?php if ($is_super_admin): ?>
<div class="card mb-3">
    <div class="card-body py-2 d-flex align-items-center flex-wrap" style="gap:8px;">
        <span class="text-muted small mr-2">View:</span>
        <a href="capital.php" class="btn btn-sm <?php echo $view_brand === '' ? 'btn-primary' : 'btn-light'; ?>">All Brands</a>
        <?php foreach (product_brand_options() as $brand_option): ?>
            <a href="capital.php?brand=<?php echo urlencode($brand_option); ?>" class="btn btn-sm <?php echo $view_brand === $brand_option ? 'btn-primary' : 'btn-light'; ?>">
                <?php echo e(brand_label($brand_option)); ?>
            </a>
        <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>

<div class="row">
    <div class="col-md-6 col-xl-4 mb-3">
        <div class="widget">
            <div class="widget-icon wi-blue"><i class="fa fa-bank"></i></div>
            <div class="widget-info">
                <div class="widget-value" style="font-size:18px;"><?php echo e(money($breakdown['bank'], $cur)); ?></div>
                <div class="widget-label">Total Capital in Bank</div>
                <div class="small text-muted" style="margin-top:4px;">Capital + sales − expenses − purchases − refunds</div>
            </div>
        </div>
    </div>
    <div class="col-md-6 col-xl-4 mb-3">
        <div class="widget">
            <div class="widget-icon wi-purple"><i class="fa fa-cubes"></i></div>
            <div class="widget-info">
                <div class="widget-value" style="font-size:18px;"><?php echo e(money($breakdown['stock'], $cur)); ?></div>
                <div class="widget-label">Stock-Held Capital</div>
                <div class="small text-muted" style="margin-top:4px;">Inventory on hand at purchase price</div>
            </div>
        </div>
    </div>
    <div class="col-md-6 col-xl-4 mb-3">
        <div class="widget">
            <div class="widget-icon wi-amber"><i class="fa fa-pie-chart"></i></div>
            <div class="widget-info">
                <div class="widget-value" style="font-size:18px;"><?php echo e(money($breakdown['total'], $cur)); ?></div>
                <div class="widget-label">Total Capital</div>
                <div class="small text-muted" style="margin-top:4px;">Bank capital + stock-held capital</div>
            </div>
        </div>
    </div>
</div>

<div class="row">
    <div class="col-lg-4 mb-3">
        <div class="card h-100">
            <div class="card-header"><h5 class="card-title mb-0"><i class="fa fa-plus mr-1" style="color:var(--primary)"></i> Add or Withdraw Capital</h5></div>
            <div class="card-body">
                <form method="POST" action="capital.php<?php echo $view_brand !== '' ? '?brand=' . urlencode($view_brand) : ''; ?>">
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="action" value="add_movement">
                    <?php if ($is_super_admin): ?>
                        <div class="form-group">
                            <label>Brand <span class="text-danger">*</span></label>
                            <select name="brand" class="form-control" required>
                                <option value="">— Select brand —</option>
                                <?php foreach (product_brand_options() as $brand_option): ?>
                                    <option value="<?php echo e($brand_option); ?>" <?php echo $view_brand === $brand_option ? 'selected' : ''; ?>><?php echo e($brand_option); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    <?php else: ?>
                        <div class="form-group">
                            <label>Brand</label>
                            <input type="text" class="form-control" value="<?php echo e(brand_label($view_brand)); ?>" readonly>
                            <input type="hidden" name="brand" value="<?php echo e($view_brand); ?>">
                        </div>
                    <?php endif; ?>
                    <div class="form-group">
                        <label>Movement <span class="text-danger">*</span></label>
                        <select name="movement_type" id="capitalMovementType" class="form-control" required>
                            <option value="contribution">Add capital</option>
                            <option value="withdrawal">Withdraw capital</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Bank Account <span class="text-danger">*</span></label>
                        <input type="text" name="bank_name" id="capitalBankName" class="form-control" list="capitalBanks" maxlength="150" placeholder="e.g. CRDB Bank, NMB, NBC" required>
                        <datalist id="capitalBanks">
                            <?php foreach ($bank_names as $bank_name): ?>
                                <option value="<?php echo e($bank_name); ?>"></option>
                            <?php endforeach; ?>
                        </datalist>
                        <small class="text-muted">All capital is held in the brand's bank capital. Withdrawals cannot exceed the Total Capital in Bank.</small>
                        <div class="small mt-1" id="bankAvailableHint"></div>
                    </div>
                    <div class="form-group">
                        <label>Amount <span class="text-danger">*</span></label>
                        <input type="number" name="amount" id="capitalAmount" class="form-control" min="0.01" step="0.01" required placeholder="0.00">
                    </div>
                    <div class="form-group">
                        <label>Date</label>
                        <input type="date" name="entry_date" class="form-control" value="<?php echo date('Y-m-d'); ?>">
                    </div>
                    <div class="form-group">
                        <label>Reference</label>
                        <input type="text" name="reference" class="form-control" maxlength="100" placeholder="Slip / transaction reference">
                    </div>
                    <div class="form-group">
                        <label>Notes</label>
                        <textarea name="notes" class="form-control" rows="2" placeholder="Optional note"></textarea>
                    </div>
                    <button type="submit" class="btn btn-primary btn-block"><i class="fa fa-check mr-1"></i> Save Capital</button>
                </form>
            </div>
        </div>
    </div>

    <div class="col-lg-8 mb-3">
        <div class="card mb-3">
            <div class="card-header d-flex justify-content-between align-items-center flex-wrap" style="gap:8px;">
                <h5 class="card-title mb-0"><i class="fa fa-bank mr-1" style="color:var(--primary)"></i> Bank Accounts</h5>
                <span class="badge badge-primary"><?php echo e(money($breakdown['bank'], $cur)); ?> Total Capital in Bank</span>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-sm mb-0">
                        <thead>
                            <tr>
                                <th>Bank</th>
                                <th class="text-right">Capital In</th>
                                <th class="text-right">Withdrawn</th>
                                <th class="text-right">Money Out</th>
                                <th class="text-right">Net Movement</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php $named_bank_total = 0.0; ?>
                        <?php foreach ($bank_accounts['banks'] as $bank): ?>
                            <?php
                            $bank_out = (float)$bank['expenses_out'] + (float)$bank['purchases_out'] + (float)$bank['refunds_out'];
                            $named_bank_total += (float)$bank['available'];
                            ?>
                            <tr>
                                <td class="font-weight-semibold"><?php echo e($bank['bank_name']); ?></td>
                                <td class="text-right text-success"><?php echo e(money($bank['capital_in'], $cur)); ?></td>
                                <td class="text-right text-danger"><?php echo e(money($bank['capital_out'], $cur)); ?></td>
                                <td class="text-right text-danger"><?php echo e(money($bank_out, $cur)); ?></td>
                                <td class="text-right font-weight-semibold <?php echo $bank['available'] < 0 ? 'text-danger' : ''; ?>"><?php echo e(money($bank['available'], $cur)); ?></td>
                            </tr>
                        <?php endforeach; ?>
                        <?php
                        $bank_unassigned = (float)$breakdown['bank'] - $named_bank_total;
                        if (abs($bank_unassigned) > 0.005):
                        ?>
                            <tr>
                                <td class="font-weight-semibold text-muted">Sales and cash capital not assigned to a named bank</td>
                                <td class="text-right">—</td>
                                <td class="text-right">—</td>
                                <td class="text-right">—</td>
                                <td class="text-right font-weight-semibold"><?php echo e(money($bank_unassigned, $cur)); ?></td>
                            </tr>
                        <?php endif; ?>
                        <?php if (!$bank_accounts['banks']): ?>
                            <tr><td colspan="5" class="text-center text-muted py-4">No bank account yet. Add bank capital to create one.</td></tr>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>
                <div class="small text-muted p-3">
                    Money Out combines expenses, supplier purchases and refunds paid from the named bank.
                    Sales from every shop are added to Total Capital in Bank automatically and are shown separately when they are not tied to a named bank.
                </div>
            </div>
        </div>

        <div class="card">
            <div class="card-header"><h5 class="card-title mb-0"><i class="fa fa-line-chart mr-1" style="color:var(--primary)"></i> Funding Impact</h5></div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-sm mb-0">
                        <tbody>
                            <tr><td>Capital added</td><td class="text-right text-success"><?php echo e(money($breakdown['capital_contributions'], $cur)); ?></td></tr>
                            <tr><td>Capital withdrawn</td><td class="text-right text-danger"><?php echo e(money($breakdown['capital_withdrawals'], $cur)); ?></td></tr>
                            <tr><td>Sales from all shops</td><td class="text-right text-success"><?php echo e(money($breakdown['sales_total'], $cur)); ?></td></tr>
                            <tr><td>Expenses paid</td><td class="text-right text-danger"><?php echo e(money($breakdown['expenses_total'], $cur)); ?></td></tr>
                            <tr><td>Supplier purchases paid</td><td class="text-right text-danger"><?php echo e(money($breakdown['purchases_total'], $cur)); ?></td></tr>
                            <tr><td>Refunds paid</td><td class="text-right text-danger"><?php echo e(money($breakdown['refunds_total'], $cur)); ?></td></tr>
                            <tr class="font-weight-bold" style="background:#f6f7fb;"><td>Total Capital in Bank</td><td class="text-right"><?php echo e(money($breakdown['bank'], $cur)); ?></td></tr>
                            <tr class="font-weight-bold" style="background:#f6f7fb;"><td>Stock-Held Capital</td><td class="text-right"><?php echo e(money($breakdown['stock'], $cur)); ?></td></tr>
                            <tr class="font-weight-bold" style="background:#eef0f8;"><td>Total Capital</td><td class="text-right"><?php echo e(money($breakdown['total'], $cur)); ?></td></tr>
                        </tbody>
                    </table>
                </div>
                <div class="small text-muted p-3">
                    Capital is equity: it is not counted as sales revenue, so VAT and profit reports stay correct.
                    Buying stock moves money from Total Capital in Bank into Stock-Held Capital.
                    Receiving purchased goods into the godown increases Stock-Held Capital automatically.
                </div>
            </div>
        </div>
    </div>
</div>

<div class="card mb-3">
    <div class="card-header d-flex justify-content-between align-items-center flex-wrap" style="gap:8px;">
        <div>
            <h5 class="card-title mb-0"><i class="fa fa-shopping-basket mr-1" style="color:var(--primary)"></i> Shop Sales</h5>
            <small class="text-muted">Every shop's total sales automatically increases the brand's Total Capital in Bank.</small>
        </div>
        <span class="badge badge-success"><?php echo e(money($shop_sales['sales'], $cur)); ?> total sales</span>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-sm mb-0">
                <thead>
                    <tr>
                        <th>Shop</th>
                        <?php if ($is_super_admin): ?><th>Brand</th><?php endif; ?>
                        <th class="text-right">POS Sales</th>
                        <th class="text-right">Invoice Payments</th>
                        <th class="text-right">Order Payments</th>
                        <th class="text-right">Total Sales</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($shop_sales['shops'] as $shop_row): ?>
                    <tr>
                        <td>
                            <span class="font-weight-semibold"><?php echo e($shop_row['name']); ?></span>
                            <small class="text-muted d-block"><?php echo e($shop_row['code']); ?></small>
                        </td>
                        <?php if ($is_super_admin): ?>
                            <td><span class="badge badge-navy"><?php echo e(brand_label($shop_row['brand'])); ?></span></td>
                        <?php endif; ?>
                        <td class="text-right"><?php echo e(money($shop_row['pos_sales'], $cur)); ?></td>
                        <td class="text-right"><?php echo e(money($shop_row['invoice_sales'], $cur)); ?></td>
                        <td class="text-right"><?php echo e(money($shop_row['order_sales'], $cur)); ?></td>
                        <td class="text-right font-weight-semibold"><?php echo e(money($shop_row['total_sales'], $cur)); ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (abs((float)$shop_sales['unassigned']) > 0.005): ?>
                    <tr>
                        <td class="text-muted">Not linked to a shop</td>
                        <?php if ($is_super_admin): ?><td>—</td><?php endif; ?>
                        <td class="text-right">—</td>
                        <td class="text-right">—</td>
                        <td class="text-right">—</td>
                        <td class="text-right font-weight-semibold"><?php echo e(money($shop_sales['unassigned'], $cur)); ?></td>
                    </tr>
                <?php endif; ?>
                <?php if (!$shop_sales['shops']): ?>
                    <tr><td colspan="<?php echo $is_super_admin ? 6 : 5; ?>" class="text-center text-muted py-4">No shops are available for this brand.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
        <div class="small text-muted p-3">
            POS sales are completed sales. Invoice Payments are money received against invoices. Order Payments are storefront order payments net of returns.
            These figures update the brand's bank capital automatically; no close-business step is needed.
        </div>
    </div>
</div>

<div class="row">
    <div class="col-12 mb-3">
        <div class="card h-100">
            <div class="card-header"><h5 class="card-title mb-0"><i class="fa fa-info-circle mr-1" style="color:var(--primary)"></i> How Capital Works</h5></div>
            <div class="card-body">
                <ul class="mb-0 pl-3">
                    <li class="mb-2"><strong>Total Capital in Bank</strong> is the money recorded for the brand: capital added, less capital withdrawn, plus every shop's sales, less expenses, supplier purchases and refunds.</li>
                    <li class="mb-2">Expenses, supplier purchases and refunds reduce Total Capital in Bank.</li>
                    <li class="mb-2"><strong>Stock-Held Capital</strong> is the value of inventory at purchase price. It increases when purchased goods are received into the godown and decreases when stock is sold or transferred out.</li>
                    <li class="mb-0">There is no Close Business step and no opening balance: capital comes only from recorded funding, sales and spending.</li>
                </ul>
            </div>
        </div>
    </div>
</div>

<?php if ($is_super_admin && $brand_rows): ?>
<div class="card mb-3">
    <div class="card-header"><h5 class="card-title mb-0"><i class="fa fa-th-large mr-1" style="color:var(--primary)"></i> Capital by Brand</h5></div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-sm mb-0">
                <thead>
                    <tr>
                        <th>Brand</th>
                        <th class="text-right">Total Capital in Bank</th>
                        <th class="text-right">Stock-Held Capital</th>
                        <th class="text-right">Total Capital</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($brand_rows as $brand_row): ?>
                    <tr>
                        <td><a href="capital.php?brand=<?php echo urlencode((string)$brand_row['brand']); ?>" class="badge badge-navy"><?php echo e(brand_label($brand_row['brand'])); ?></a></td>
                        <td class="text-right font-weight-semibold"><?php echo e(money($brand_row['bank'], $cur)); ?></td>
                        <td class="text-right"><?php echo e(money($brand_row['stock'], $cur)); ?></td>
                        <td class="text-right font-weight-semibold"><?php echo e(money($brand_row['total'], $cur)); ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php endif; ?>


<div class="card mb-3">
    <div class="card-header d-flex justify-content-between align-items-center flex-wrap">
        <h5 class="card-title mb-0"><i class="fa fa-history mr-1" style="color:var(--primary)"></i> Capital Movement History</h5>
        <span class="text-muted small"><?php echo count($movements); ?> movement(s)</span>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead>
                    <tr>
                        <th>Date</th>
                        <?php if ($is_super_admin): ?><th>Brand</th><?php endif; ?>
                        <th>Type</th>
                        <th>Bank</th>
                        <th>Reference</th>
                        <th class="text-right">Amount</th>
                        <th>Entered By</th>
                        <th>Notes</th>
                        <?php if ($is_super_admin): ?><th class="text-right no-print">Actions</th><?php endif; ?>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($movements as $movement): ?>
                    <?php $is_withdrawal = (string)$movement['movement_type'] === 'withdrawal'; ?>
                    <tr>
                        <td class="text-nowrap"><?php echo e(date('M d, Y', strtotime($movement['entry_date']))); ?></td>
                        <?php if ($is_super_admin): ?>
                            <td><span class="badge badge-navy"><?php echo e(brand_label($movement['brand'])); ?></span></td>
                        <?php endif; ?>
                        <td>
                            <span class="badge <?php echo $is_withdrawal ? 'badge-danger' : 'badge-success'; ?>">
                                <?php echo $is_withdrawal ? 'Withdrawal' : 'Capital added'; ?>
                            </span>
                        </td>
                        <td class="font-weight-semibold"><?php echo e($movement['bank_name'] ?: '—'); ?></td>
                        <td><?php echo e($movement['reference'] ?: '—'); ?></td>
                        <td class="text-right font-weight-semibold <?php echo $is_withdrawal ? 'text-danger' : 'text-success'; ?>">
                            <?php echo $is_withdrawal ? '-' : '+'; ?><?php echo e(money($movement['amount'], $cur)); ?>
                        </td>
                        <td><?php echo e(trim((string)$movement['entered_by_name']) ?: ($movement['entered_by_username'] ?? '—')); ?></td>
                        <td class="small text-muted"><?php echo e($movement['notes'] ?: '—'); ?></td>
                        <?php if ($is_super_admin): ?>
                            <td class="text-right no-print">
                                <?php
                                echo row_action_menu([
                                    [
                                        'label' => 'Delete movement',
                                        'icon' => 'fa-trash',
                                        'danger' => true,
                                        'form' => [
                                            'action' => 'capital.php',
                                            'confirm' => 'Delete this capital movement? Total Capital in Bank will be recalculated.',
                                            'fields' => ['action' => 'delete_movement', 'movement_id' => (int)$movement['movement_id']],
                                        ],
                                    ],
                                ], 'Capital movement actions');
                                ?>
                            </td>
                        <?php endif; ?>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$movements): ?>
                    <tr><td colspan="<?php echo $is_super_admin ? 9 : 7; ?>" class="text-center text-muted py-4">No capital movements yet.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<script>
$(function () {
    var bankCapital = <?php echo json_encode((float)$breakdown['bank'], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
    var bankInput = document.getElementById('capitalBankName');
    var typeSelect = document.getElementById('capitalMovementType');
    var amountInput = document.getElementById('capitalAmount');
    var hint = document.getElementById('bankAvailableHint');
    if (!bankInput || !typeSelect || !amountInput || !hint) return;

    function updateHint() {
        if (typeSelect.value === 'withdrawal') {
            hint.textContent = 'Total Capital in Bank available: ' + Number(bankCapital).toLocaleString(undefined, {
                minimumFractionDigits: 2,
                maximumFractionDigits: 2
            });
            amountInput.max = Math.max(0, bankCapital).toFixed(2);
        } else {
            hint.textContent = 'This amount will be added to Total Capital in Bank.';
            amountInput.removeAttribute('max');
        }
    }

    typeSelect.addEventListener('change', updateHint);
    updateHint();
});
</script>

<?php require_once 'footer.php'; ?>
