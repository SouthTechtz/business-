<?php
require_once 'header.php';
require_once 'auth_check.php';
require_once 'capital-helpers.php';

if (!can_manage_capital()) {
    http_response_code(403);
    die('You do not have permission to close business or bank sales.');
}
$page_title = 'Close Business';
$cur = tenant_currency();
$tenant_id = (int)$current_user->tenant_id;
$is_super_admin = current_brand_scope() === '';

$view_brand = capital_resolve_brand($_GET['brand'] ?? $_POST['brand'] ?? '');
if ($is_super_admin && $view_brand === '') {
    $view_brand = product_brand_options()[0];
}
$business_date = trim((string)($_GET['date'] ?? $_POST['date'] ?? date('Y-m-d')));
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $business_date) || !strtotime($business_date)) {
    $business_date = date('Y-m-d');
}
$msg = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'bank_sales') {
    verify_csrf();
    $post_brand = capital_resolve_brand($_POST['brand'] ?? '');
    $post_date = trim((string)($_POST['date'] ?? ''));
    $bank_input = trim((string)($_POST['bank_name'] ?? ''));
    $amount = (float)($_POST['amount'] ?? 0);
    $reference = trim((string)($_POST['reference'] ?? ''));
    $notes = trim((string)($_POST['notes'] ?? ''));

    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $post_date) || !strtotime($post_date)) {
        $post_date = date('Y-m-d');
    }
    $canonical_bank = bank_canonical_name($tenant_id, $post_brand, $bank_input);

    if ($post_brand === '') {
        $msg = ['danger', 'Select the brand whose sales are being banked.'];
    } elseif ($canonical_bank === '') {
        $msg = ['danger', 'Select an existing bank account. Add capital to a bank first if none exists.'];
    } elseif ($amount <= 0) {
        $msg = ['danger', 'Enter the sales amount to bank.'];
    } elseif (mb_strlen($reference) > 100) {
        $msg = ['danger', 'The reference must be 100 characters or fewer.'];
    } else {
        $conn->begin_transaction();
        try {
            // Lock the day's deposits so two closings cannot bank the same sales twice.
            fetch_all(
                "SELECT deposit_id FROM bank_sales_deposits
                  WHERE tenant_id = ? AND brand = ? AND business_date = ?
                  FOR UPDATE",
                $tenant_id, $post_brand, $post_date
            );
            $day_sales = bank_sales_summary($tenant_id, $post_brand, $post_date);
            $already = bank_sales_deposited($tenant_id, $post_brand, $post_date);
            $unbanked = max(0.0, $day_sales['net'] - $already);
            if ($unbanked <= 0.005) {
                throw new RuntimeException('All sales for ' . $post_date . ' are already banked.');
            }
            if ($amount > $unbanked + 0.005) {
                throw new RuntimeException(
                    'The amount to bank cannot exceed the unbanked sales for this date. Unbanked: '
                    . money($unbanked, $cur)
                );
            }
            $cash_position = brand_capital_breakdown($tenant_id, $post_brand);
            if ($amount > (float)$cash_position['cash'] + 0.005) {
                throw new RuntimeException(
                    'The amount to bank cannot exceed the cash in hand for this brand. Cash in hand: '
                    . money((float)$cash_position['cash'], $cur)
                );
            }
            $deposit_id = db_insert('bank_sales_deposits', [
                'tenant_id'     => $tenant_id,
                'brand'         => $post_brand,
                'bank_name'     => $canonical_bank,
                'business_date' => $post_date,
                'amount'        => $amount,
                'sales_total'   => $day_sales['net'],
                'reference'     => $reference !== '' ? $reference : null,
                'notes'         => $notes !== '' ? $notes : null,
                'created_by'    => (int)$current_user->user_id,
            ]);
            if (!$deposit_id) {
                throw new RuntimeException('Could not bank the sales. Try again.');
            }
            $conn->commit();
        } catch (Throwable $ex) {
            $conn->rollback();
            $msg = ['danger', $ex->getMessage()];
        }
        if (!$msg) {
            audit_log(
                'create',
                'bank_sales_deposit',
                $deposit_id,
                'Sales banked — ' . brand_label($post_brand) . ' / ' . $canonical_bank
                    . ' / ' . $post_date . ' (' . money($amount, $cur) . ')'
            );
            flash_set('success', money($amount, $cur) . ' in sales was added to ' . $canonical_bank . '.');
            redirect('close-business.php?brand=' . urlencode($post_brand) . '&date=' . urlencode($post_date));
        }
    }
}

$sales = bank_sales_summary($tenant_id, $view_brand, $business_date);
$deposited = bank_sales_deposited($tenant_id, $view_brand, $business_date);
$unbanked = max(0.0, $sales['net'] - $deposited);
$bank_accounts = bank_accounts_summary($tenant_id, $view_brand);
$deposits = bank_sales_deposit_history($tenant_id, $view_brand, $business_date, 100);
$scope_brand_label = $view_brand !== '' ? brand_label($view_brand) : 'All Brands';

require_once 'navbar.php';
require_once 'sidebar.php';
?>
<div class="page-head">
    <div>
        <h1 class="page-title">Close Business</h1>
        <div class="page-sub">
            Bank the cash sales for <strong><?php echo e($scope_brand_label); ?></strong> on
            <strong><?php echo e(date('M d, Y', strtotime($business_date))); ?></strong> by selecting the bank account.
        </div>
    </div>
    <div class="d-flex align-items-center flex-wrap" style="gap:8px;">
        <a href="capital.php<?php echo $view_brand !== '' ? '?brand=' . urlencode($view_brand) : ''; ?>" class="btn btn-outline-primary">
            <i class="fa fa-bank mr-1"></i> Capital
        </a>
        <a href="sales.php" class="btn btn-light"><i class="fa fa-arrow-left mr-1"></i> Sales</a>
    </div>
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
        <span class="text-muted small mr-2">Brand:</span>
        <?php foreach (product_brand_options() as $brand_option): ?>
            <a href="close-business.php?brand=<?php echo urlencode($brand_option); ?>&date=<?php echo urlencode($business_date); ?>"
               class="btn btn-sm <?php echo $view_brand === $brand_option ? 'btn-primary' : 'btn-light'; ?>">
                <?php echo e(brand_label($brand_option)); ?>
            </a>
        <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>

<div class="card mb-3">
    <div class="card-body py-2">
        <form method="GET" action="close-business.php" class="form-inline">
            <?php if ($view_brand !== ''): ?><input type="hidden" name="brand" value="<?php echo e($view_brand); ?>"><?php endif; ?>
            <label class="mr-2 small">Business date</label>
            <input type="date" name="date" class="form-control form-control-sm mr-2" value="<?php echo e($business_date); ?>">
            <button class="btn btn-sm btn-light"><i class="fa fa-calendar mr-1"></i> Load</button>
        </form>
    </div>
</div>

<div class="row mb-3">
    <div class="col-md-6 col-xl-3 mb-3">
        <div class="widget">
            <div class="widget-icon wi-green"><i class="fa fa-money"></i></div>
            <div class="widget-info">
                <div class="widget-value" style="font-size:18px;"><?php echo e(money($sales['cash_net'], $cur)); ?></div>
                <div class="widget-label">Cash sales to bank</div>
                <div class="small text-muted" style="margin-top:4px;">Cash receipts less cash refunds</div>
            </div>
        </div>
    </div>
    <div class="col-md-6 col-xl-3 mb-3">
        <div class="widget">
            <div class="widget-icon wi-blue"><i class="fa fa-credit-card"></i></div>
            <div class="widget-info">
                <div class="widget-value" style="font-size:18px;"><?php echo e(money($sales['bank_net'], $cur)); ?></div>
                <div class="widget-label">Non-cash receipts</div>
                <div class="small text-muted" style="margin-top:4px;">Already in the bank</div>
            </div>
        </div>
    </div>
    <div class="col-md-6 col-xl-3 mb-3">
        <div class="widget">
            <div class="widget-icon wi-indigo"><i class="fa fa-check-circle"></i></div>
            <div class="widget-info">
                <div class="widget-value" style="font-size:18px;"><?php echo e(money($deposited, $cur)); ?></div>
                <div class="widget-label">Cash already banked</div>
            </div>
        </div>
    </div>
    <div class="col-md-6 col-xl-3 mb-3">
        <div class="widget">
            <div class="widget-icon wi-amber"><i class="fa fa-inbox"></i></div>
            <div class="widget-info">
                <div class="widget-value" style="font-size:18px;"><?php echo e(money($unbanked, $cur)); ?></div>
                <div class="widget-label">Unbanked cash</div>
            </div>
        </div>
    </div>
</div>


<div class="row">
    <div class="col-lg-5 mb-3">
        <div class="card h-100">
            <div class="card-header"><h5 class="card-title mb-0"><i class="fa fa-check-circle mr-1" style="color:var(--primary)"></i> Close &amp; Bank Sales</h5></div>
            <div class="card-body">
                <?php if (!$bank_accounts['banks']): ?>
                    <div class="alert alert-warning mb-0">
                        No bank account exists for this brand yet.
                        <a href="capital.php<?php echo $view_brand !== '' ? '?brand=' . urlencode($view_brand) : ''; ?>">Add capital to a bank</a> first.
                    </div>
                <?php elseif ($sales['cash_net'] <= 0.005): ?>
                    <div class="alert alert-info mb-0">
                        No cash sales were recorded for <?php echo e(date('M d, Y', strtotime($business_date))); ?>.
                    </div>
                <?php elseif ($unbanked <= 0.005): ?>
                    <div class="alert alert-success mb-0">
                        All cash sales for <?php echo e(date('M d, Y', strtotime($business_date))); ?> are already banked.
                    </div>
                <?php else: ?>
                    <form method="POST" action="close-business.php">
                        <?php echo csrf_field(); ?>
                        <input type="hidden" name="action" value="bank_sales">
                        <input type="hidden" name="brand" value="<?php echo e($view_brand); ?>">
                        <input type="hidden" name="date" value="<?php echo e($business_date); ?>">
                        <div class="form-group">
                            <label>Bank Account <span class="text-danger">*</span></label>
                            <select name="bank_name" class="form-control" required>
                                <option value="">— Select the bank account —</option>
                                <?php foreach ($bank_accounts['banks'] as $bank): ?>
                                    <option value="<?php echo e($bank['bank_name']); ?>">
                                        <?php echo e($bank['bank_name']); ?> — available <?php echo e(money($bank['available'], $cur)); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <small class="text-muted">The cash amount will be added to this bank's available balance.</small>
                        </div>
                        <div class="form-group">
                            <label>Cash to Bank <span class="text-danger">*</span></label>
                            <input type="number" name="amount" class="form-control" min="0.01" max="<?php echo e(number_format($unbanked, 2, '.', '')); ?>" step="0.01" value="<?php echo e(number_format($unbanked, 2, '.', '')); ?>" required>
                            <small class="text-muted">Unbanked cash: <?php echo e(money($unbanked, $cur)); ?>. You may bank part of it.</small>
                        </div>
                        <div class="form-group">
                            <label>Reference</label>
                            <input type="text" name="reference" class="form-control" maxlength="100" placeholder="Deposit slip / reference">
                        </div>
                        <div class="form-group">
                            <label>Notes</label>
                            <textarea name="notes" class="form-control" rows="2" placeholder="Optional note"></textarea>
                        </div>
                        <button type="submit" class="btn btn-success btn-block">
                            <i class="fa fa-check mr-1"></i> Close Business &amp; Add Sales to Bank
                        </button>
                    </form>
                <?php endif; ?>
            </div>
        </div>
    </div>


    <div class="col-lg-7 mb-3">
        <div class="card mb-3">
            <div class="card-header"><h5 class="card-title mb-0"><i class="fa fa-calculator mr-1" style="color:var(--primary)"></i> Sales Breakdown</h5></div>
            <div class="card-body p-0">
                <table class="table table-sm mb-0">
                    <tbody>
                        <tr><td>POS / walk-in cash sales</td><td class="text-right"><?php echo e(money($sales['pos_cash'], $cur)); ?></td></tr>
                        <tr><td>POS / walk-in non-cash sales</td><td class="text-right"><?php echo e(money($sales['pos_bank'], $cur)); ?></td></tr>
                        <tr><td>Invoice cash payments received</td><td class="text-right"><?php echo e(money($sales['invoice_cash'], $cur)); ?></td></tr>
                        <tr><td>Invoice non-cash payments received</td><td class="text-right"><?php echo e(money($sales['invoice_bank'], $cur)); ?></td></tr>
                        <tr><td>Less: cash refunds</td><td class="text-right text-danger"><?php echo e(money($sales['cash_refunds'], $cur)); ?></td></tr>
                        <tr><td>Less: non-cash refunds</td><td class="text-right text-danger"><?php echo e(money($sales['bank_refunds'], $cur)); ?></td></tr>
                        <tr class="font-weight-bold" style="background:#f6f7fb;"><td>Cash to bank</td><td class="text-right"><?php echo e(money($sales['cash_net'], $cur)); ?></td></tr>
                        <tr><td>Non-cash receipts already in bank</td><td class="text-right text-success"><?php echo e(money($sales['bank_net'], $cur)); ?></td></tr>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="card">
            <div class="card-header"><h5 class="card-title mb-0"><i class="fa fa-bank mr-1" style="color:var(--primary)"></i> Bank Accounts</h5></div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-sm mb-0">
                        <thead><tr><th>Bank</th><th class="text-right">Capital</th><th class="text-right">Cash Banked</th><th class="text-right">Money Out</th><th class="text-right">Transfers</th><th class="text-right">Available</th></tr></thead>
                        <tbody>
                        <?php foreach ($bank_accounts['banks'] as $bank): ?>
                            <?php
                            $bank_out = (float)$bank['expenses_out'] + (float)$bank['purchases_out'] + (float)$bank['refunds_out'];
                            $bank_transfers = (float)$bank['transfers_in'] - (float)$bank['transfers_out'];
                            ?>
                            <tr>
                                <td class="font-weight-semibold"><?php echo e($bank['bank_name']); ?></td>
                                <td class="text-right"><?php echo e(money($bank['capital_in'] - $bank['capital_out'], $cur)); ?></td>
                                <td class="text-right"><?php echo e(money($bank['sales_banked'], $cur)); ?></td>
                                <td class="text-right text-danger"><?php echo e(money($bank_out, $cur)); ?></td>
                                <td class="text-right <?php echo $bank_transfers < 0 ? 'text-danger' : ''; ?>"><?php echo e(money($bank_transfers, $cur)); ?></td>
                                <td class="text-right font-weight-semibold"><?php echo e(money($bank['available'], $cur)); ?></td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (!$bank_accounts['banks']): ?>
                            <tr><td colspan="6" class="text-center text-muted py-4">No bank accounts yet.</td></tr>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>


<div class="card">
    <div class="card-header d-flex justify-content-between align-items-center flex-wrap">
        <h5 class="card-title mb-0"><i class="fa fa-history mr-1" style="color:var(--primary)"></i> Sales Banked on <?php echo e(date('M d, Y', strtotime($business_date))); ?></h5>
        <span class="text-muted small"><?php echo count($deposits); ?> deposit(s)</span>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead>
                    <tr>
                        <th>Bank</th>
                        <th class="text-right">Cash Sales for Date</th>
                        <th class="text-right">Banked</th>
                        <th>Reference</th>
                        <th>Entered By</th>
                        <th>Notes</th>
                        <th>Time</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($deposits as $deposit): ?>
                    <tr>
                        <td class="font-weight-semibold"><?php echo e($deposit['bank_name']); ?></td>
                        <td class="text-right"><?php echo e(money($deposit['sales_total'], $cur)); ?></td>
                        <td class="text-right font-weight-semibold text-success"><?php echo e(money($deposit['amount'], $cur)); ?></td>
                        <td><?php echo e($deposit['reference'] ?: '—'); ?></td>
                        <td><?php echo e(trim((string)$deposit['entered_by_name']) ?: ($deposit['entered_by_username'] ?? '—')); ?></td>
                        <td class="small text-muted"><?php echo e($deposit['notes'] ?: '—'); ?></td>
                        <td class="text-nowrap small text-muted"><?php echo e(date('M d, Y H:i', strtotime($deposit['created_at']))); ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$deposits): ?>
                    <tr><td colspan="7" class="text-center text-muted py-4">No sales have been banked for this date yet.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
    <div class="card-body pt-0">
        <div class="small text-muted">Banking cash increases the available balance of the selected bank. Non-cash sales already sit in the bank. Capital withdrawals cannot exceed that available balance.</div>
    </div>
</div>

<?php require_once 'footer.php'; ?>

