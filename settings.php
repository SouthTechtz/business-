<?php
require_once 'header.php';
require_once 'auth_check.php';
require_permission('manage_settings');
$page_title = 'Settings';

$tenant = fetch_one("SELECT * FROM tenants WHERE tenant_id = ?", $current_user->tenant_id);
$settings = fetch_one("SELECT * FROM system_settings WHERE tenant_id = ?", $current_user->tenant_id);
$currency = $tenant['currency_code'] ?? 'TZS';

$payment_methods = fetch_all("SELECT * FROM payment_methods WHERE tenant_id = ? ORDER BY payment_method_id", $current_user->tenant_id);
$tax_rates = fetch_all("SELECT * FROM tax_rates WHERE tenant_id = ? ORDER BY tax_rate_id", $current_user->tenant_id);

$msg = '';

// Manage payment methods
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($action === 'add_payment_method') {
        $name = trim($_POST['name'] ?? '');
        if ($name !== '') {
            db_insert('payment_methods', ['tenant_id' => $current_user->tenant_id, 'name' => $name]);
            $msg = ['success', 'Payment method added.'];
        }
    } elseif ($action === 'delete_payment_method') {
        $mid = (int)($_POST['method_id'] ?? 0);
        $used = fetch_one("SELECT COUNT(*) c FROM payments WHERE payment_method_id = ? AND tenant_id = ?", $mid, $current_user->tenant_id);
        if (($used['c'] ?? 0) > 0) {
            $msg = ['danger', 'This payment method is used by existing payments.'];
        } else {
            $stmt = $conn->prepare("DELETE FROM payment_methods WHERE payment_method_id = ? AND tenant_id = ?");
            $stmt->bind_param('ii', $mid, $current_user->tenant_id);
            $stmt->execute();
            $msg = ['success', 'Payment method deleted.'];
        }
    } elseif ($action === 'add_tax_rate') {
        $name = trim($_POST['name'] ?? '');
        $rate = (float)($_POST['rate'] ?? 0);
        if ($name !== '') {
            db_insert('tax_rates', ['tenant_id' => $current_user->tenant_id, 'name' => $name, 'rate' => $rate]);
            $msg = ['success', 'Tax rate added.'];
        }
    } elseif ($action === 'delete_tax_rate') {
        $tid = (int)($_POST['tax_id'] ?? 0);
        $used = fetch_one("SELECT COUNT(*) c FROM invoice_items WHERE tax_rate_id = ?", $tid);
        if (($used['c'] ?? 0) > 0) {
            $msg = ['danger', 'This tax rate is used by invoice items.'];
        } else {
            $stmt = $conn->prepare("DELETE FROM tax_rates WHERE tax_rate_id = ? AND tenant_id = ?");
            $stmt->bind_param('ii', $tid, $current_user->tenant_id);
            $stmt->execute();
            $msg = ['success', 'Tax rate deleted.'];
        }
    }
}

require_once 'navbar.php';
require_once 'sidebar.php';
?>

<div class="page-head">
    <div>
        <h1 class="page-title">Settings</h1>
        <div class="page-sub">Company information and system configuration.</div>
    </div>
</div>

<?php if ($msg): ?>
    <div class="alert alert-<?php echo e($msg[0]); ?>"><?php echo e($msg[1]); ?></div>
<?php endif; ?>

<div class="row">
    <div class="col-lg-6 mb-3">
        <div class="card">
            <div class="card-header"><h5 class="card-title">Company Information</h5></div>
            <div class="card-body">
                <form method="POST" action="save-settings.php">
                    <div class="form-group">
                        <label>Company Name <span class="text-danger">*</span></label>
                        <input type="text" name="name" class="form-control" required value="<?php echo e($settings['company_name'] ?? $tenant['name'] ?? ''); ?>">
                    </div>
                    <div class="form-group">
                        <label>Email</label>
                        <input type="email" name="company_email" class="form-control" value="<?php echo e($settings['company_email'] ?? $tenant['email'] ?? ''); ?>">
                    </div>
                    <div class="form-group">
                        <label>Phone</label>
                        <input type="text" name="company_phone" class="form-control" value="<?php echo e($settings['company_phone'] ?? $tenant['phone'] ?? ''); ?>">
                    </div>
                    <div class="form-group">
                        <label>Address</label>
                        <textarea name="company_address" class="form-control" rows="2"><?php echo e($settings['company_address'] ?? $tenant['address'] ?? ''); ?></textarea>
                    </div>
                    <div class="form-group">
                        <label>Default Currency <span class="text-danger">*</span></label>
                        <select name="default_currency" class="form-control">
                            <?php foreach (['TZS', 'USD', 'EUR', 'GBP', 'KES', 'UGX'] as $code): ?>
                                <option value="<?php echo $code; ?>" <?php echo ($settings['default_currency'] ?? $currency) === $code ? 'selected' : ''; ?>><?php echo $code; ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="row">
                        <div class="col-md-6 form-group">
                            <label>Invoice Prefix</label>
                            <input type="text" name="invoice_prefix" class="form-control" value="<?php echo e($settings['invoice_prefix'] ?? 'INV-'); ?>">
                        </div>
                        <div class="col-md-6 form-group">
                            <label>Next Invoice Number</label>
                            <input type="number" name="invoice_next_number" class="form-control" value="<?php echo e($settings['invoice_next_number'] ?? 1001); ?>">
                        </div>
                    </div>
                    <div class="form-group">
                        <div class="custom-control custom-switch">
                            <input type="checkbox" class="custom-control-input" id="applyTaxSwitch" name="apply_tax" value="1"
                                   <?php echo (int)($settings['apply_tax'] ?? 1) === 1 ? 'checked' : ''; ?>>
                            <label class="custom-control-label" for="applyTaxSwitch">Apply Tax (VAT) on sales and invoices</label>
                        </div>
                        <small class="form-text text-muted">Turn off if your business does not charge VAT. New sales and invoices will be recorded without tax; existing records are not changed.</small>
                    </div>
                    <button type="submit" class="btn btn-primary"><i class="fa fa-check mr-1"></i> Save Settings</button>
                </form>
            </div>
        </div>
        <div class="card">
            <div class="card-header"><h5 class="card-title">Business Logo</h5></div>
            <div class="card-body">
                <p class="small text-muted">Shown at the top of printed invoices and reports.</p>
                <?php if (!empty($settings['logo_path']) && file_exists($settings['logo_path'])): ?>
                    <div class="mb-3 p-3 text-center" style="border:1px solid #e2e8f0;border-radius:10px;background:#fff;">
                        <img src="<?php echo e($settings['logo_path']); ?>" alt="Current logo" style="max-height:70px;max-width:220px;object-fit:contain;">
                    </div>
                <?php else: ?>
                    <div class="mb-3 p-3 text-center text-muted" style="border:2px dashed #cbd5e1;border-radius:10px;">No logo uploaded yet</div>
                <?php endif; ?>
                <form method="POST" action="save-settings.php" enctype="multipart/form-data">
                    <input type="hidden" name="name" value="<?php echo e($settings['company_name'] ?? $tenant['name'] ?? ''); ?>">
                    <div class="form-group">
                        <label>Upload Logo (PNG, JPG, SVG, WEBP, GIF &mdash; max 2 MB)</label>
                        <input type="file" name="logo" class="form-control-file" accept="image/png,image/jpeg,image/svg+xml,image/webp,image/gif">
                    </div>
                    <button type="submit" class="btn btn-primary"><i class="fa fa-upload mr-1"></i> Save Logo</button>
                </form>
                <?php if (!empty($settings['logo_path'])): ?>
                    <form method="POST" action="save-settings.php" class="mt-2" onsubmit="return confirm('Remove the current logo?');">
                        <input type="hidden" name="name" value="<?php echo e($settings['company_name'] ?? $tenant['name'] ?? ''); ?>">
                        <input type="hidden" name="remove_logo" value="1">
                        <button type="submit" class="btn btn-outline-danger btn-sm"><i class="fa fa-trash mr-1"></i> Remove Logo</button>
                    </form>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="col-lg-6 mb-3">
        <div class="card">
            <div class="card-header"><h5 class="card-title">Payment Methods</h5></div>
            <div class="card-body">
                <div class="mb-3">
                    <?php foreach ($payment_methods as $pm): ?>
                        <span class="badge badge-primary mr-1 mb-1" style="font-size:13px;padding:7px 12px;">
                            <?php echo e($pm['name']); ?>
                            <form method="POST" action="settings.php" class="d-inline">
                                <input type="hidden" name="action" value="delete_payment_method">
                                <input type="hidden" name="method_id" value="<?php echo $pm['payment_method_id']; ?>">
                                <button type="submit" class="badge-link" title="Delete">&times;</button>
                            </form>
                        </span>
                    <?php endforeach; ?>
                </div>
                <form method="POST" action="settings.php" class="form-inline">
                    <input type="hidden" name="action" value="add_payment_method">
                    <input type="text" name="name" class="form-control mr-2 mb-1" placeholder="e.g. Cheque" required>
                    <button type="submit" class="btn btn-sm btn-outline-primary mb-1"><i class="fa fa-plus mr-1"></i> Add</button>
                </form>
            </div>
        </div>
        <div class="card">
            <div class="card-header"><h5 class="card-title">Tax Rates</h5></div>
            <div class="card-body">
                <div class="mb-3">
                    <?php foreach ($tax_rates as $tr): ?>
                        <span class="badge badge-info mr-1 mb-1" style="font-size:13px;padding:7px 12px;">
                            <?php echo e($tr['name'] . ' (' . (float)$tr['rate'] . '%)'); ?>
                            <form method="POST" action="settings.php" class="d-inline">
                                <input type="hidden" name="action" value="delete_tax_rate">
                                <input type="hidden" name="tax_id" value="<?php echo $tr['tax_rate_id']; ?>">
                                <button type="submit" class="badge-link" title="Delete">&times;</button>
                            </form>
                        </span>
                    <?php endforeach; ?>
                </div>
                <form method="POST" action="settings.php" class="form-inline">
                    <input type="hidden" name="action" value="add_tax_rate">
                    <input type="text" name="name" class="form-control mr-2 mb-1" placeholder="e.g. VAT 18%" required>
                    <input type="number" name="rate" class="form-control mr-2 mb-1" placeholder="Rate %" step="1" min="0" required style="width:110px;">
                    <button type="submit" class="btn btn-sm btn-outline-primary mb-1"><i class="fa fa-plus mr-1"></i> Add</button>
                </form>
            </div>
        </div>
    </div>
</div>

<?php require_once 'footer.php'; ?>
