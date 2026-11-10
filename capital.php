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
        $account_type = (string)($_POST['account_type'] ?? 'bank');
        $bank_name = trim((string)($_POST['bank_name'] ?? ''));
        $amount = (float)($_POST['amount'] ?? 0);
        $entry_date = trim((string)($_POST['entry_date'] ?? ''));
        $reference = trim((string)($_POST['reference'] ?? ''));
        $notes = trim((string)($_POST['notes'] ?? ''));

        if (!in_array($account_type, ['bank', 'cash'], true)) {
            $account_type = 'bank';
        }
        if ($account_type === 'cash') {
            $bank_name = '';
        }

        if ($brand === '') {
            $msg = ['danger', 'Select the brand this capital belongs to.'];
        } elseif (!in_array($movement_type, ['contribution', 'withdrawal'], true)) {
            $msg = ['danger', 'Select whether this is capital added or withdrawn.'];
        } elseif ($account_type === 'bank' && $bank_name === '') {
            $msg = ['danger', 'Enter the bank name for this capital.'];
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
            $canonical_bank = $account_type === 'bank'
                ? bank_canonical_name($tenant_id, $brand, $bank_name)
                : '';
            if ($account_type === 'bank' && $movement_type === 'withdrawal' && $canonical_bank === '') {
                $msg = ['danger', 'Select an existing bank account with available funds to withdraw from.'];
            } else {
                if ($canonical_bank !== '') {
                    $bank_name = $canonical_bank;
                }
                $conn->begin_transaction();
                try {
                    if ($movement_type === 'withdrawal') {
                        if ($account_type === 'bank') {
                            // Lock this bank's rows so two withdrawals cannot overdraw it.
                            fetch_all(
                                "SELECT movement_id FROM capital_movements
                                  WHERE tenant_id = ? AND brand = ? AND bank_name = ?
                                  FOR UPDATE",
                                $tenant_id, $brand, $bank_name
                            );
                            if (db_table_exists('bank_sales_deposits')) {
                                fetch_all(
                                    "SELECT deposit_id FROM bank_sales_deposits
                                      WHERE tenant_id = ? AND brand = ? AND bank_name = ?
                                      FOR UPDATE",
                                    $tenant_id, $brand, $bank_name
                                );
                            }
                            $available = bank_available_balance($tenant_id, $brand, $bank_name);
                            if ($amount > $available + 0.005) {
                                throw new RuntimeException(
                                    'Withdrawal exceeds the available balance in ' . $bank_name
                                    . '. Available: ' . money($available, $cur)
                                );
                            }
                        } else {
                            // Lock the brand's cash rows, then check the cash position.
                            fetch_all(
                                "SELECT movement_id FROM capital_movements
                                  WHERE tenant_id = ? AND brand = ?
                                  FOR UPDATE",
                                $tenant_id, $brand
                            );
                            $cash_position = brand_capital_breakdown($tenant_id, $brand);
                            $available = (float)$cash_position['cash'];
                            if ($amount > $available + 0.005) {
                                throw new RuntimeException(
                                    'Withdrawal exceeds the cash in hand for this brand.'
                                    . ' Available: ' . money($available, $cur)
                                );
                            }
                        }
                    }
                    $movement_id = db_insert('capital_movements', [
                        'tenant_id'     => $tenant_id,
                        'brand'         => $brand,
                        'movement_type' => $movement_type,
                        'account_type'  => $account_type,
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
                    $where = $account_type === 'cash' ? 'Cash in hand' : $bank_name;
                    audit_log(
                        'create',
                        'capital',
                        $movement_id,
                        ($movement_type === 'withdrawal' ? 'Capital withdrawn' : 'Capital added')
                            . ' — ' . brand_label($brand) . ' / ' . $where . ' (' . money($amount, $cur) . ')'
                    );
                    flash_set('success', $movement_type === 'withdrawal'
                        ? 'Capital withdrawal recorded. The capital balance has been updated.'
                        : 'Capital added successfully. The capital balance has been updated.');
                    redirect('capital.php?brand=' . urlencode($brand));
                }
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
                flash_set('success', 'Capital movement deleted. The capital position has been updated.');
                redirect('capital.php?brand=' . urlencode((string)$movement['brand']));
            }
        }
    } elseif ($action === 'save_opening') {
        $brand = capital_resolve_brand($_POST['brand'] ?? '');
        $opening_cash = (float)($_POST['opening_cash'] ?? 0);
        $opening_bank = (float)($_POST['opening_bank'] ?? 0);
        $opening_bank_name = trim((string)($_POST['opening_bank_name'] ?? ''));
        $notes = trim((string)($_POST['notes'] ?? ''));

        if ($brand === '') {
            $msg = ['danger', 'Select a brand to set its opening balances.'];
        } elseif (!brand_capital_settings_table_ready()) {
            $msg = ['danger', 'Opening balances are not available yet. Run the capital migration first.'];
        } elseif ($opening_cash < 0 || $opening_bank < 0) {
            $msg = ['danger', 'Opening balances cannot be negative.'];
        } elseif ($opening_bank > 0 && $opening_bank_name === '') {
            $msg = ['danger', 'Select the bank account for the opening bank balance.'];
        } elseif (mb_strlen($opening_bank_name) > 150) {
            $msg = ['danger', 'The opening bank name must be 150 characters or fewer.'];
        } elseif ($opening_cash > 999999999999.99 || $opening_bank > 999999999999.99) {
            $msg = ['danger', 'That opening balance is too large.'];
        } else {
            $canonical_opening_bank = $opening_bank_name !== ''
                ? bank_canonical_name($tenant_id, $brand, $opening_bank_name)
                : '';
            if ($canonical_opening_bank !== '') {
                $opening_bank_name = $canonical_opening_bank;
            }
            if (save_brand_capital_settings(
                $tenant_id,
                $brand,
                $opening_cash,
                $opening_bank,
                $opening_bank_name,
                $notes,
                (int)$current_user->user_id
            )) {
                audit_log(
                    'update',
                    'brand_capital_settings',
                    $brand,
                    'Opening capital set — ' . brand_label($brand)
                        . ' / cash ' . money($opening_cash, $cur)
                        . ' / bank ' . money($opening_bank, $cur)
                );
                flash_set('success', 'Opening balances saved for ' . brand_label($brand) . '.');
                redirect('capital.php?brand=' . urlencode($brand));
            }
            $msg = ['danger', 'Could not save the opening balances.'];
        }
    } elseif ($action === 'add_transfer') {
        $brand = capital_resolve_brand($_POST['brand'] ?? '');
        $direction = (string)($_POST['direction'] ?? 'cash_to_bank');
        $bank_name = trim((string)($_POST['bank_name'] ?? ''));
        $amount = (float)($_POST['amount'] ?? 0);
        $entry_date = trim((string)($_POST['entry_date'] ?? ''));
        $reference = trim((string)($_POST['reference'] ?? ''));
        $notes = trim((string)($_POST['notes'] ?? ''));

        $canonical_bank = bank_canonical_name($tenant_id, $brand, $bank_name);

        if ($brand === '') {
            $msg = ['danger', 'Select a brand for this internal transfer.'];
        } elseif (!in_array($direction, ['cash_to_bank', 'bank_to_cash'], true)) {
            $msg = ['danger', 'Select the transfer direction.'];
        } elseif ($bank_name === '') {
            $msg = ['danger', 'Select the bank account for this transfer.'];
        } elseif ($canonical_bank === '') {
            $msg = ['danger', 'Select an existing bank account for this transfer.'];
        } elseif ($amount <= 0) {
            $msg = ['danger', 'Enter a transfer amount greater than zero.'];
        } elseif (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $entry_date) || !strtotime($entry_date)) {
            $entry_date = date('Y-m-d');
        } elseif (mb_strlen($reference) > 100) {
            $msg = ['danger', 'The reference must be 100 characters or fewer.'];
        } else {
            $conn->begin_transaction();
            try {
                $position = brand_capital_breakdown($tenant_id, $brand);
                if ($direction === 'cash_to_bank') {
                    $available = (float)$position['cash'];
                    if ($amount > $available + 0.005) {
                        throw new RuntimeException(
                            'The transfer exceeds the cash in hand for this brand. Available: '
                            . money($available, $cur)
                        );
                    }
                } else {
                    $available = bank_available_balance($tenant_id, $brand, $canonical_bank);
                    if ($amount > $available + 0.005) {
                        throw new RuntimeException(
                            'The transfer exceeds the available balance in ' . $canonical_bank
                            . '. Available: ' . money($available, $cur)
                        );
                    }
                }
                $transfer_id = db_insert('brand_internal_transfers', [
                    'tenant_id'   => $tenant_id,
                    'brand'       => $brand,
                    'direction'   => $direction,
                    'bank_name'   => $canonical_bank,
                    'amount'      => $amount,
                    'entry_date'  => $entry_date,
                    'reference'   => $reference !== '' ? $reference : null,
                    'notes'       => $notes !== '' ? $notes : null,
                    'created_by'  => (int)$current_user->user_id,
                ]);
                if (!$transfer_id) {
                    throw new RuntimeException('Could not save the internal transfer.');
                }
                $conn->commit();
            } catch (Throwable $ex) {
                $conn->rollback();
                $msg = ['danger', $ex->getMessage()];
            }
            if (!$msg) {
                audit_log(
                    'create',
                    'capital_transfer',
                    $transfer_id,
                    'Internal transfer — ' . brand_label($brand) . ' / '
                        . ($direction === 'cash_to_bank' ? 'cash to ' : 'bank to cash from ')
                        . $canonical_bank . ' (' . money($amount, $cur) . ')'
                );
                flash_set('success', 'Internal transfer recorded. Total capital is unchanged.');
                redirect('capital.php?brand=' . urlencode($brand));
            }
        }
    } elseif ($action === 'delete_transfer') {
        if (!$is_super_admin) {
            $msg = ['danger', 'Only the system administrator can delete an internal transfer.'];
        } else {
            $transfer_id = (int)($_POST['transfer_id'] ?? 0);
            $transfer = fetch_one(
                "SELECT * FROM brand_internal_transfers WHERE transfer_id = ? AND tenant_id = ?",
                $transfer_id,
                $tenant_id
            );
            if (!$transfer) {
                $msg = ['danger', 'Internal transfer not found.'];
            } else {
                $stmt = $conn->prepare("DELETE FROM brand_internal_transfers WHERE transfer_id = ? AND tenant_id = ?");
                $stmt->bind_param('ii', $transfer_id, $tenant_id);
                $stmt->execute();
                $stmt->close();
                audit_log('delete', 'capital_transfer', $transfer_id, 'Internal transfer deleted');
                flash_set('success', 'Internal transfer deleted. The capital position has been updated.');
                redirect('capital.php?brand=' . urlencode((string)$transfer['brand']));
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
$transfers = brand_internal_transfer_history($tenant_id, $view_brand);
$opening = brand_capital_settings($tenant_id, $view_brand);
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
            Liquid capital is cash in hand plus bank balance; stock capital is inventory at cost.
        </div>
    </div>
    <div class="d-flex align-items-center flex-wrap" style="gap:8px;">
        <a href="close-business.php<?php echo $view_brand !== '' ? '?brand=' . urlencode($view_brand) : ''; ?>" class="btn btn-success">
            <i class="fa fa-check-circle mr-1"></i> Close Business
        </a>
        <a href="report-print.php?type=balance-sheet<?php echo $view_brand !== '' ? '&brand=' . urlencode($view_brand) : ''; ?>" class="btn btn-outline-primary">
            <i class="fa fa-balance-scale mr-1"></i> Balance Sheet Report
        </a>
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
    <div class="col-md-6 col-xl mb-3">
        <div class="widget">
            <div class="widget-icon wi-green"><i class="fa fa-money"></i></div>
            <div class="widget-info">
                <div class="widget-value" style="font-size:18px;"><?php echo e(money($breakdown['cash'], $cur)); ?></div>
                <div class="widget-label">Cash in Hand</div>
                <div class="small text-muted" style="margin-top:4px;">Registers and drawers</div>
            </div>
        </div>
    </div>
    <div class="col-md-6 col-xl mb-3">
        <div class="widget">
            <div class="widget-icon wi-blue"><i class="fa fa-bank"></i></div>
            <div class="widget-info">
                <div class="widget-value" style="font-size:18px;"><?php echo e(money($breakdown['bank'], $cur)); ?></div>
                <div class="widget-label">Bank Balance</div>
                <div class="small text-muted" style="margin-top:4px;">All brand bank accounts</div>
            </div>
        </div>
    </div>
    <div class="col-md-6 col-xl mb-3">
        <div class="widget">
            <div class="widget-icon wi-indigo"><i class="fa fa-tint"></i></div>
            <div class="widget-info">
                <div class="widget-value" style="font-size:18px;"><?php echo e(money($breakdown['liquid'], $cur)); ?></div>
                <div class="widget-label">Liquid Capital</div>
                <div class="small text-muted" style="margin-top:4px;">Cash + bank</div>
            </div>
        </div>
    </div>
    <div class="col-md-6 col-xl mb-3">
        <div class="widget">
            <div class="widget-icon wi-purple"><i class="fa fa-cubes"></i></div>
            <div class="widget-info">
                <div class="widget-value" style="font-size:18px;"><?php echo e(money($breakdown['stock'], $cur)); ?></div>
                <div class="widget-label">Stock Capital</div>
                <div class="small text-muted" style="margin-top:4px;">Inventory at purchase price</div>
            </div>
        </div>
    </div>
    <div class="col-md-6 col-xl mb-3">
        <div class="widget">
            <div class="widget-icon wi-amber"><i class="fa fa-pie-chart"></i></div>
            <div class="widget-info">
                <div class="widget-value" style="font-size:18px;"><?php echo e(money($breakdown['total'], $cur)); ?></div>
                <div class="widget-label">Total Brand Capital</div>
                <div class="small text-muted" style="margin-top:4px;">Liquid + stock</div>
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
                        <label>Account <span class="text-danger">*</span></label>
                        <select name="account_type" id="capitalAccountType" class="form-control" required>
                            <option value="bank">Bank</option>
                            <option value="cash">Cash in Hand</option>
                        </select>
                        <small class="text-muted">Choose where the money is held.</small>
                    </div>
                    <div class="form-group">
                        <label>Movement <span class="text-danger">*</span></label>
                        <select name="movement_type" id="capitalMovementType" class="form-control" required>
                            <option value="contribution">Add capital</option>
                            <option value="withdrawal">Withdraw capital</option>
                        </select>
                    </div>
                    <div class="form-group" id="capitalBankGroup">
                        <label>Bank Name <span class="text-danger">*</span></label>
                        <input type="text" name="bank_name" id="capitalBankName" class="form-control" list="capitalBanks" maxlength="150" placeholder="e.g. CRDB Bank, NMB, NBC">
                        <datalist id="capitalBanks">
                            <?php foreach ($bank_names as $bank_name): ?>
                                <option value="<?php echo e($bank_name); ?>"></option>
                            <?php endforeach; ?>
                        </datalist>
                        <small class="text-muted">Add capital to create a bank account. Withdrawals must use an existing bank and cannot exceed its available balance.</small>
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
                <span class="badge badge-primary"><?php echo e(money($breakdown['bank'], $cur)); ?> total bank</span>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-sm mb-0">
                        <thead>
                            <tr>
                                <th>Bank</th>
                                <th class="text-right">Opening</th>
                                <th class="text-right">Capital In</th>
                                <th class="text-right">Withdrawn</th>
                                <th class="text-right">Cash Banked</th>
                                <th class="text-right">Money Out</th>
                                <th class="text-right">Transfers</th>
                                <th class="text-right">Available</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($bank_accounts['banks'] as $bank): ?>
                            <?php
                            $bank_out = (float)$bank['expenses_out'] + (float)$bank['purchases_out'] + (float)$bank['refunds_out'];
                            $bank_transfers = (float)$bank['transfers_in'] - (float)$bank['transfers_out'];
                            ?>
                            <tr>
                                <td class="font-weight-semibold"><?php echo e($bank['bank_name']); ?></td>
                                <td class="text-right"><?php echo e(money($bank['opening_in'], $cur)); ?></td>
                                <td class="text-right text-success"><?php echo e(money($bank['capital_in'], $cur)); ?></td>
                                <td class="text-right text-danger"><?php echo e(money($bank['capital_out'], $cur)); ?></td>
                                <td class="text-right"><?php echo e(money($bank['sales_banked'], $cur)); ?></td>
                                <td class="text-right text-danger"><?php echo e(money($bank_out, $cur)); ?></td>
                                <td class="text-right <?php echo $bank_transfers < 0 ? 'text-danger' : ''; ?>"><?php echo e(money($bank_transfers, $cur)); ?></td>
                                <td class="text-right font-weight-semibold <?php echo $bank['available'] < 0 ? 'text-danger' : ''; ?>"><?php echo e(money($bank['available'], $cur)); ?></td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (abs((float)$breakdown['bank_unallocated']) > 0.005): ?>
                            <tr>
                                <td class="font-weight-semibold text-muted">Unallocated bank money</td>
                                <td class="text-right">—</td>
                                <td class="text-right">—</td>
                                <td class="text-right">—</td>
                                <td class="text-right">—</td>
                                <td class="text-right">—</td>
                                <td class="text-right">—</td>
                                <td class="text-right font-weight-semibold"><?php echo e(money($breakdown['bank_unallocated'], $cur)); ?></td>
                            </tr>
                        <?php endif; ?>
                        <?php if (!$bank_accounts['banks'] && abs((float)$breakdown['bank_unallocated']) <= 0.005): ?>
                            <tr><td colspan="8" class="text-center text-muted py-4">No bank account yet. Add bank capital or bank cash to create one.</td></tr>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>
                <div class="small text-muted p-3">
                    Money Out combines bank-paid expenses, supplier purchases and refunds. Transfers are internal movements between cash and bank.
                </div>
            </div>
        </div>

        <div class="card">
            <div class="card-header"><h5 class="card-title mb-0"><i class="fa fa-line-chart mr-1" style="color:var(--primary)"></i> Funding Impact</h5></div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-sm mb-0">
                        <tbody>
                            <tr><td>Opening cash in hand</td><td class="text-right"><?php echo e(money($breakdown['opening_cash'], $cur)); ?></td></tr>
                            <tr><td>Opening bank balance</td><td class="text-right"><?php echo e(money($breakdown['opening_bank'], $cur)); ?></td></tr>
                            <tr><td>Capital added (cash / bank)</td><td class="text-right"><?php echo e(money($breakdown['cash_contributions'], $cur)); ?> / <?php echo e(money($breakdown['bank_contributions'], $cur)); ?></td></tr>
                            <tr><td>Capital withdrawn (cash / bank)</td><td class="text-right text-danger"><?php echo e(money($breakdown['cash_withdrawals'], $cur)); ?> / <?php echo e(money($breakdown['bank_withdrawals'], $cur)); ?></td></tr>
                            <tr><td>Sales received (cash / bank)</td><td class="text-right text-success"><?php echo e(money($breakdown['cash_receipts'], $cur)); ?> / <?php echo e(money($breakdown['bank_receipts'], $cur)); ?></td></tr>
                            <tr><td>Expenses paid (cash / bank)</td><td class="text-right text-danger"><?php echo e(money($breakdown['cash_expenses'], $cur)); ?> / <?php echo e(money($breakdown['bank_expenses'], $cur)); ?></td></tr>
                            <tr><td>Supplier purchases paid (cash / bank)</td><td class="text-right text-danger"><?php echo e(money($breakdown['cash_purchases'], $cur)); ?> / <?php echo e(money($breakdown['bank_purchases'], $cur)); ?></td></tr>
                            <tr><td>Refunds paid (cash / bank)</td><td class="text-right text-danger"><?php echo e(money($breakdown['cash_refunds'], $cur)); ?> / <?php echo e(money($breakdown['bank_refunds'], $cur)); ?></td></tr>
                            <tr><td>Cash banked (Close Business)</td><td class="text-right"><?php echo e(money($breakdown['cash_banked'], $cur)); ?></td></tr>
                            <tr><td>Internal transfers (cash to bank / bank to cash)</td><td class="text-right"><?php echo e(money($breakdown['transfers_cash_to_bank'], $cur)); ?> / <?php echo e(money($breakdown['transfers_bank_to_cash'], $cur)); ?></td></tr>
                            <tr class="font-weight-bold" style="background:#f6f7fb;"><td>Cash in hand</td><td class="text-right"><?php echo e(money($breakdown['cash'], $cur)); ?></td></tr>
                            <tr class="font-weight-bold" style="background:#f6f7fb;"><td>Bank balance</td><td class="text-right"><?php echo e(money($breakdown['bank'], $cur)); ?></td></tr>
                            <tr class="font-weight-bold" style="background:#f6f7fb;"><td>Liquid capital</td><td class="text-right"><?php echo e(money($breakdown['liquid'], $cur)); ?></td></tr>
                            <tr class="font-weight-bold" style="background:#f6f7fb;"><td>Stock capital</td><td class="text-right"><?php echo e(money($breakdown['stock'], $cur)); ?></td></tr>
                            <tr class="font-weight-bold" style="background:#eef0f8;"><td>Total brand capital</td><td class="text-right"><?php echo e(money($breakdown['total'], $cur)); ?></td></tr>
                        </tbody>
                    </table>
                </div>
                <div class="small text-muted p-3">
                    Capital is equity: it increases the money held by the brand, and covers operating costs through that money.
                    It is not counted as sales revenue, so VAT and profit reports stay correct.
                    Buying stock moves money from liquid capital into stock capital without changing total capital.
                </div>
            </div>
        </div>
    </div>
</div>

<div class="row">
    <div class="col-lg-6 mb-3">
        <div class="card h-100">
            <div class="card-header"><h5 class="card-title mb-0"><i class="fa fa-flag-checkered mr-1" style="color:var(--primary)"></i> Opening Balances</h5></div>
            <div class="card-body">
                <?php if ($view_brand === ''): ?>
                    <div class="alert alert-info mb-0">
                        Select a brand above to set its opening cash in hand and opening bank balance.
                    </div>
                <?php else: ?>
                    <form method="POST" action="capital.php?brand=<?php echo urlencode($view_brand); ?>">
                        <?php echo csrf_field(); ?>
                        <input type="hidden" name="action" value="save_opening">
                        <input type="hidden" name="brand" value="<?php echo e($view_brand); ?>">
                        <p class="text-muted small">
                            Opening balances are the starting cash and bank amounts for
                            <strong><?php echo e(brand_label($view_brand)); ?></strong>. They are not sales and are not counted as profit.
                        </p>
                        <div class="row">
                            <div class="col-sm-6 form-group">
                                <label>Opening Cash in Hand</label>
                                <input type="number" name="opening_cash" class="form-control" min="0" step="0.01" value="<?php echo e(number_format((float)$opening['opening_cash'], 2, '.', '')); ?>">
                            </div>
                            <div class="col-sm-6 form-group">
                                <label>Opening Bank Balance</label>
                                <input type="number" name="opening_bank" class="form-control" min="0" step="0.01" value="<?php echo e(number_format((float)$opening['opening_bank'], 2, '.', '')); ?>">
                            </div>
                            <div class="col-sm-6 form-group">
                                <label>Opening Bank Account</label>
                                <input type="text" name="opening_bank_name" class="form-control" list="openingBankNames" maxlength="150" value="<?php echo e($opening['opening_bank_name']); ?>" placeholder="e.g. NMB, CRDB, NBC">
                                <datalist id="openingBankNames">
                                    <?php foreach ($bank_names as $bank_name): ?>
                                        <option value="<?php echo e($bank_name); ?>"></option>
                                    <?php endforeach; ?>
                                </datalist>
                                <small class="text-muted">Required when the opening bank balance is above zero, so withdrawals can use it.</small>
                            </div>
                        </div>
                        <div class="form-group">
                            <label>Notes</label>
                            <textarea name="notes" class="form-control" rows="2" placeholder="Optional note"><?php echo e($opening['notes']); ?></textarea>
                        </div>
                        <?php if (!empty($opening['updated_at'])): ?>
                            <div class="small text-muted mb-2">Last updated <?php echo e(date('M d, Y H:i', strtotime($opening['updated_at']))); ?></div>
                        <?php endif; ?>
                        <button type="submit" class="btn btn-outline-primary"><i class="fa fa-save mr-1"></i> Save Opening Balances</button>
                    </form>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="col-lg-6 mb-3">
        <div class="card h-100">
            <div class="card-header"><h5 class="card-title mb-0"><i class="fa fa-exchange mr-1" style="color:var(--primary)"></i> Internal Transfer</h5></div>
            <div class="card-body">
                <?php if ($view_brand === ''): ?>
                    <div class="alert alert-info mb-0">
                        Select a brand above to move money between its cash in hand and bank.
                    </div>
                <?php else: ?>
                    <form method="POST" action="capital.php?brand=<?php echo urlencode($view_brand); ?>">
                        <?php echo csrf_field(); ?>
                        <input type="hidden" name="action" value="add_transfer">
                        <input type="hidden" name="brand" value="<?php echo e($view_brand); ?>">
                        <p class="text-muted small">
                            Move money between cash in hand and a bank account. Total capital does not change.
                        </p>
                        <div class="row">
                            <div class="col-sm-6 form-group">
                                <label>Direction <span class="text-danger">*</span></label>
                                <select name="direction" class="form-control" required>
                                    <option value="cash_to_bank">Cash to Bank</option>
                                    <option value="bank_to_cash">Bank to Cash</option>
                                </select>
                            </div>
                            <div class="col-sm-6 form-group">
                                <label>Bank Account <span class="text-danger">*</span></label>
                                <select name="bank_name" class="form-control" required>
                                    <option value="">— Select bank —</option>
                                    <?php foreach ($bank_accounts['banks'] as $bank): ?>
                                        <option value="<?php echo e($bank['bank_name']); ?>">
                                            <?php echo e($bank['bank_name']); ?> — available <?php echo e(money($bank['available'], $cur)); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-sm-6 form-group">
                                <label>Amount <span class="text-danger">*</span></label>
                                <input type="number" name="amount" class="form-control" min="0.01" step="0.01" required placeholder="0.00">
                            </div>
                            <div class="col-sm-6 form-group">
                                <label>Date</label>
                                <input type="date" name="entry_date" class="form-control" value="<?php echo date('Y-m-d'); ?>">
                            </div>
                        </div>
                        <div class="form-group">
                            <label>Reference</label>
                            <input type="text" name="reference" class="form-control" maxlength="100" placeholder="Slip / transaction reference">
                        </div>
                        <div class="form-group">
                            <label>Notes</label>
                            <textarea name="notes" class="form-control" rows="2" placeholder="Optional note"></textarea>
                        </div>
                        <button type="submit" class="btn btn-outline-primary"><i class="fa fa-exchange mr-1"></i> Record Transfer</button>
                    </form>
                <?php endif; ?>
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
                        <th class="text-right">Cash in Hand</th>
                        <th class="text-right">Bank</th>
                        <th class="text-right">Liquid Capital</th>
                        <th class="text-right">Stock Capital</th>
                        <th class="text-right">Total Brand Capital</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($brand_rows as $brand_row): ?>
                    <tr>
                        <td><a href="capital.php?brand=<?php echo urlencode((string)$brand_row['brand']); ?>" class="badge badge-navy"><?php echo e(brand_label($brand_row['brand'])); ?></a></td>
                        <td class="text-right"><?php echo e(money($brand_row['cash'], $cur)); ?></td>
                        <td class="text-right"><?php echo e(money($brand_row['bank'], $cur)); ?></td>
                        <td class="text-right font-weight-semibold"><?php echo e(money($brand_row['liquid'], $cur)); ?></td>
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
                        <th>Account</th>
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
                    <?php
                    $is_withdrawal = (string)$movement['movement_type'] === 'withdrawal';
                    $is_cash = (string)($movement['account_type'] ?? 'bank') === 'cash';
                    ?>
                    <tr>
                        <td class="text-nowrap"><?php echo e(date('M d, Y', strtotime($movement['entry_date']))); ?></td>
                        <?php if ($is_super_admin): ?>
                            <td><span class="badge badge-navy"><?php echo e(brand_label($movement['brand'])); ?></span></td>
                        <?php endif; ?>
                        <td>
                            <span class="badge <?php echo $is_cash ? 'badge-success' : 'badge-primary'; ?>">
                                <?php echo $is_cash ? 'Cash in hand' : 'Bank'; ?>
                            </span>
                        </td>
                        <td>
                            <span class="badge <?php echo $is_withdrawal ? 'badge-danger' : 'badge-success'; ?>">
                                <?php echo $is_withdrawal ? 'Withdrawal' : 'Capital added'; ?>
                            </span>
                        </td>
                        <td class="font-weight-semibold"><?php echo $is_cash ? '—' : e($movement['bank_name']); ?></td>
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
                                            'confirm' => 'Delete this capital movement? The capital position will be recalculated.',
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
                    <tr><td colspan="<?php echo $is_super_admin ? 10 : 8; ?>" class="text-center text-muted py-4">No capital movements yet.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<div class="card">
    <div class="card-header d-flex justify-content-between align-items-center flex-wrap">
        <h5 class="card-title mb-0"><i class="fa fa-exchange mr-1" style="color:var(--primary)"></i> Internal Transfer History</h5>
        <span class="text-muted small"><?php echo count($transfers); ?> transfer(s)</span>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead>
                    <tr>
                        <th>Date</th>
                        <?php if ($is_super_admin): ?><th>Brand</th><?php endif; ?>
                        <th>Direction</th>
                        <th>Bank</th>
                        <th>Reference</th>
                        <th class="text-right">Amount</th>
                        <th>Entered By</th>
                        <th>Notes</th>
                        <?php if ($is_super_admin): ?><th class="text-right no-print">Actions</th><?php endif; ?>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($transfers as $transfer): ?>
                    <?php $to_bank = (string)$transfer['direction'] === 'cash_to_bank'; ?>
                    <tr>
                        <td class="text-nowrap"><?php echo e(date('M d, Y', strtotime($transfer['entry_date']))); ?></td>
                        <?php if ($is_super_admin): ?>
                            <td><span class="badge badge-navy"><?php echo e(brand_label($transfer['brand'])); ?></span></td>
                        <?php endif; ?>
                        <td>
                            <span class="badge <?php echo $to_bank ? 'badge-primary' : 'badge-success'; ?>">
                                <?php echo $to_bank ? 'Cash → Bank' : 'Bank → Cash'; ?>
                            </span>
                        </td>
                        <td class="font-weight-semibold"><?php echo e($transfer['bank_name']); ?></td>
                        <td><?php echo e($transfer['reference'] ?: '—'); ?></td>
                        <td class="text-right font-weight-semibold"><?php echo e(money($transfer['amount'], $cur)); ?></td>
                        <td><?php echo e(trim((string)$transfer['entered_by_name']) ?: ($transfer['entered_by_username'] ?? '—')); ?></td>
                        <td class="small text-muted"><?php echo e($transfer['notes'] ?: '—'); ?></td>
                        <?php if ($is_super_admin): ?>
                            <td class="text-right no-print">
                                <?php
                                echo row_action_menu([
                                    [
                                        'label' => 'Delete transfer',
                                        'icon' => 'fa-trash',
                                        'danger' => true,
                                        'form' => [
                                            'action' => 'capital.php',
                                            'confirm' => 'Delete this internal transfer? The capital position will be recalculated.',
                                            'fields' => ['action' => 'delete_transfer', 'transfer_id' => (int)$transfer['transfer_id']],
                                        ],
                                    ],
                                ], 'Internal transfer actions');
                                ?>
                            </td>
                        <?php endif; ?>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$transfers): ?>
                    <tr><td colspan="<?php echo $is_super_admin ? 9 : 7; ?>" class="text-center text-muted py-4">No internal transfers yet.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>


<script>
$(function () {
    var bankBalances = <?php echo json_encode(array_map(
        fn($bank) => [
            'name' => $bank['bank_name'],
            'available' => (float)$bank['available'],
            'formatted' => money($bank['available'], $cur),
        ],
        $bank_accounts['banks']
    ), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
    var accountSelect = document.getElementById('capitalAccountType');
    var bankGroup = document.getElementById('capitalBankGroup');
    var bankInput = document.getElementById('capitalBankName');
    var typeSelect = document.getElementById('capitalMovementType');
    var amountInput = document.getElementById('capitalAmount');
    var hint = document.getElementById('bankAvailableHint');
    if (!accountSelect || !bankGroup || !bankInput || !typeSelect || !amountInput || !hint) return;

    function updateBankHint() {
        var value = bankInput.value.trim().toLowerCase();
        var type = typeSelect.value;
        if (!value) {
            hint.textContent = '';
            amountInput.removeAttribute('max');
            return;
        }
        var match = bankBalances.find(function (bank) {
            return String(bank.name).trim().toLowerCase() === value;
        });
        if (!match) {
            if (type === 'withdrawal') {
                hint.innerHTML = '<span class="text-danger">No existing bank account with this name.</span>';
                amountInput.max = '0';
            } else {
                hint.innerHTML = '<span class="text-muted">A new bank account will be created.</span>';
                amountInput.removeAttribute('max');
            }
            return;
        }
        hint.textContent = 'Available in ' + match.name + ': ' + match.formatted;
        if (type === 'withdrawal') {
            amountInput.max = Number(match.available).toFixed(2);
        } else {
            amountInput.removeAttribute('max');
        }
    }

    function updateAccount() {
        var isBank = accountSelect.value === 'bank';
        bankGroup.style.display = isBank ? '' : 'none';
        bankInput.required = isBank;
        if (!isBank) {
            bankInput.value = '';
            amountInput.removeAttribute('max');
        }
        updateBankHint();
    }

    bankInput.addEventListener('input', updateBankHint);
    typeSelect.addEventListener('change', updateBankHint);
    accountSelect.addEventListener('change', updateAccount);
    updateAccount();
});
</script>

<?php require_once 'footer.php'; ?>
