<?php
require_once 'header.php';
require_once 'auth_check.php';
require_permission('manage_settings');
if (!tenant_admin_scope()) {
    http_response_code(403);
    die('Only system administrators can manage shop opening balances.');
}
$page_title = 'Shop Opening Balances';

$tenant_id = (int)$current_user->tenant_id;
$cur = tenant_currency();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = (string)($_POST['action'] ?? '');

    if ($action === 'save_shop_opening_balances') {
        $amounts = $_POST['shop_opening_sales'] ?? [];
        $notes = $_POST['shop_notes'] ?? [];
        $shops_for_balances = accessible_shops(null, true);
        $conn->begin_transaction();
        try {
            $stmt = $conn->prepare(
                "INSERT INTO shop_opening_balances
                    (tenant_id, shop_id, opening_sales, notes, updated_by)
                 VALUES (?, ?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE
                    opening_sales = VALUES(opening_sales),
                    notes = VALUES(notes),
                    updated_by = VALUES(updated_by),
                    updated_at = CURRENT_TIMESTAMP"
            );
            if (!$stmt) {
                throw new RuntimeException($conn->error);
            }
            foreach ($shops_for_balances as $shop_row) {
                $shop_id = (int)$shop_row['shop_id'];
                if ($shop_id <= 0 || !array_key_exists($shop_id, $amounts)) continue;
                $raw = $amounts[$shop_id];
                $amount = is_numeric($raw) ? (float)$raw : 0.0;
                if ($amount < 0) $amount = 0.0;
                if ($amount > 999999999999.99) {
                    throw new RuntimeException('Opening sales for ' . $shop_row['name'] . ' is too large.');
                }
                $note = trim((string)($notes[$shop_id] ?? ''));
                $note_db = $note !== '' ? $note : null;
                $updated_by = (int)$current_user->user_id;
                $stmt->bind_param('iidsi', $tenant_id, $shop_id, $amount, $note_db, $updated_by);
                if (!$stmt->execute()) {
                    throw new RuntimeException($stmt->error);
                }
            }
            $stmt->close();
            $conn->commit();
            audit_log('update', 'shop_opening_balance', 0, 'Shop opening balances updated');
            flash_set('success', 'Shop opening balances saved successfully.');
        } catch (Throwable $ex) {
            $conn->rollback();
            flash_set('danger', 'Could not save shop opening balances: ' . $ex->getMessage());
        }
        redirect('shop-opening-balances.php');
    }
}

$shops_for_balances = accessible_shops(null, true);
$shop_balances = shop_opening_balances($tenant_id);
$shop_opening_total = 0.0;
foreach ($shop_balances as $row) {
    $shop_opening_total += (float)$row['opening_sales'];
}

require_once 'navbar.php';
require_once 'sidebar.php';
?>
<div class="page-head">
    <div>
        <h1 class="page-title">Shop Opening Balances</h1>
        <div class="page-sub">Opening sales recorded before this system, set per specific shop. Included in that shop's sales totals and the main dashboard.</div>
    </div>
    <a href="dashboard.php" class="btn btn-light"><i class="fa fa-arrow-left mr-1"></i> Back to Dashboard</a>
</div>

<?php if ($f = flash_get()): ?>
    <div class="alert alert-<?php echo e($f['type']); ?>"><?php echo e($f['message']); ?></div>
<?php endif; ?>

<div class="row">
    <div class="col-lg-9 mb-3">
        <form method="POST" action="shop-opening-balances.php" class="card">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="action" value="save_shop_opening_balances">
            <div class="card-header">
                <h5 class="card-title">Opening Sales by Shop</h5>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table mb-0">
                        <thead>
                            <tr>
                                <th style="width:220px;">Shop</th>
                                <th style="width:150px;">Brand</th>
                                <th style="width:220px;">Opening Sales (<?php echo e($cur); ?>)</th>
                                <th>Notes</th>
                                <th>Last Updated</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($shops_for_balances as $shop_row): ?>
                            <?php
                            $balance_row = $shop_balances[(int)$shop_row['shop_id']] ?? ['opening_sales' => 0, 'notes' => null, 'updated_at' => null];
                            ?>
                            <tr>
                                <td class="font-weight-semibold">
                                    <?php echo e($shop_row['name']); ?>
                                    <div class="small text-muted"><?php echo e($shop_row['code']); ?><?php echo (int)$shop_row['is_active'] ? '' : ' &middot; Inactive'; ?></div>
                                </td>
                                <td>
                                    <?php if (!empty($shop_row['brand'])): ?>
                                        <span class="badge badge-navy"><?php echo e(brand_label($shop_row['brand'])); ?></span>
                                    <?php else: ?>
                                        <span class="text-muted small">All brands</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <div class="input-group input-group-sm">
                                        <div class="input-group-prepend"><span class="input-group-text"><?php echo e($cur); ?></span></div>
                                        <input type="number" name="shop_opening_sales[<?php echo (int)$shop_row['shop_id']; ?>]" class="form-control"
                                               step="0.01" min="0" value="<?php echo e(number_format((float)$balance_row['opening_sales'], 2, '.', '')); ?>">
                                    </div>
                                </td>
                                <td>
                                    <input type="text" name="shop_notes[<?php echo (int)$shop_row['shop_id']; ?>]" class="form-control form-control-sm"
                                           maxlength="255" placeholder="Optional note"
                                           value="<?php echo e($balance_row['notes'] ?? ''); ?>">
                                </td>
                                <td class="small text-muted">
                                    <?php echo !empty($balance_row['updated_at']) ? e(date('M d, Y H:i', strtotime($balance_row['updated_at']))) : '—'; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (!$shops_for_balances): ?>
                            <tr><td colspan="5" class="text-center text-muted py-4">No shops available.</td></tr>
                        <?php endif; ?>
                        </tbody>
                        <tfoot>
                            <tr>
                                <th colspan="2">Total shop opening sales</th>
                                <th><?php echo e(money($shop_opening_total, $cur)); ?></th>
                                <th colspan="2"></th>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </div>
            <div class="card-footer d-flex justify-content-between align-items-center flex-wrap" style="gap:8px;">
                <small class="text-muted">These amounts are added to the shop's sales totals and shown on the main dashboard. They are not tied to a date.</small>
                <button type="submit" class="btn btn-primary"><i class="fa fa-save mr-1"></i> Save Shop Opening Balances</button>
            </div>
        </form>
    </div>
    <div class="col-lg-3 mb-3">
        <div class="card">
            <div class="card-header"><h5 class="card-title mb-0">How it works</h5></div>
            <div class="card-body small text-muted">
                <p class="mb-2">Opening sales represent sales completed before this system was used. They are added to the selected shop's totals.</p>
                <p class="mb-0">Brand opening sales are no longer used. Only shop-level opening balances are applied to sales, reports and the dashboard.</p>
            </div>
        </div>
    </div>
</div>

<?php require_once 'footer.php'; ?>

