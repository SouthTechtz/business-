<?php
require_once 'header.php';
require_once 'auth_check.php';
require_once 'capital-helpers.php';
require_permission('manage_purchases');
$page_title = 'Edit Purchase';

$tenant_id = (int)$current_user->tenant_id;
$id = (int)($_GET['id'] ?? 0);
$purchase_scope = is_godown_manager() ? ' AND created_by = ?' : '';
$purchase_params = [$id, $tenant_id];
if (is_godown_manager()) {
    $purchase_params[] = (int)$current_user->user_id;
}
$purchase_brand_scope = current_brand_scope();
if ($purchase_brand_scope !== '') {
    $purchase_scope .= ' AND product_id IN (SELECT product_id FROM products WHERE tenant_id = ? AND brand = ?)';
    $purchase_params[] = $tenant_id;
    $purchase_params[] = $purchase_brand_scope;
}
$row = fetch_one("SELECT * FROM purchases WHERE purchase_id = ? AND tenant_id = ?{$purchase_scope}", ...$purchase_params);
if (!$row) {
    flash_set('danger', 'Purchase not found.');
    redirect('purchases.php');
}
if (purchase_is_received($id, $tenant_id)) {
    flash_set('danger', 'This purchase has been confirmed received, so it can no longer be edited.');
    redirect('purchase-view.php?id=' . $id);
}

$products_brand = brand_scope_condition('brand');
$products = fetch_all(
    "SELECT product_id, name, code FROM products
      WHERE tenant_id = ? AND is_active = 1{$products_brand['sql']}
      ORDER BY name",
    ...array_merge([$tenant_id], $products_brand['params'])
);
$suppliers = fetch_all("SELECT supplier_id, company_name FROM suppliers WHERE tenant_id = ? ORDER BY company_name", $tenant_id);
$payment_methods = fetch_all("SELECT * FROM payment_methods WHERE tenant_id = ? ORDER BY payment_method_id", $tenant_id);
$purchase_banks = capital_bank_names($tenant_id, current_brand_scope());
$goods_statuses = purchase_goods_statuses();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $product_id = (int)($_POST['product_id'] ?? 0);
    $supplier_id = (int)($_POST['supplier_id'] ?? 0);
    $quantity = (float)($_POST['quantity'] ?? 0);
    $total = (float)($_POST['total'] ?? 0);
    $amount_paid = (float)($_POST['amount_paid'] ?? 0);
    $payment_method_id = (int)($_POST['payment_method_id'] ?? 0) ?: null;
    $bank_name = trim((string)($_POST['bank_name'] ?? ''));
    $purchase_date = trim((string)($_POST['purchase_date'] ?? ''));
    $status = (string)($_POST['status'] ?? 'in_production');
    $notes = trim((string)($_POST['notes'] ?? ''));

    if (!isset($goods_statuses[$status])) {
        $status = 'in_production';
    }
    // Arrival is only recorded when the receiving popup is confirmed. The edit
    // keeps the purchase's current status until then, so Cancel changes nothing.
    $status_to_save = $status === 'arrived_godown'
        ? (string)($row['status'] ?? 'in_production')
        : $status;

    $valid_product = fetch_one(
        "SELECT product_id, brand FROM products WHERE product_id = ? AND tenant_id = ? AND is_active = 1",
        $product_id,
        $tenant_id
    );
    $valid_supplier = $supplier_id > 0
        ? fetch_one("SELECT supplier_id FROM suppliers WHERE supplier_id = ? AND tenant_id = ?", $supplier_id, $tenant_id)
        : true;

    $valid_method = null;
    if ($payment_method_id !== null) {
        $valid_method = fetch_one(
            "SELECT payment_method_id, name, account_type FROM payment_methods
              WHERE payment_method_id = ? AND tenant_id = ?",
            $payment_method_id,
            $tenant_id
        );
        if (!$valid_method) {
            $payment_method_id = null;
        }
    }
    $method_is_bank = $valid_method && (string)($valid_method['account_type'] ?? 'bank') === 'bank';
    if (!$method_is_bank) {
        $bank_name = '';
    } elseif ($bank_name !== '' && $valid_product) {
        $canonical_bank = bank_canonical_name($tenant_id, (string)$valid_product['brand'], $bank_name);
        if ($canonical_bank !== '') {
            $bank_name = $canonical_bank;
        }
    }

    if (!$valid_product || !$valid_supplier || $quantity <= 0 || $total < 0 || mb_strlen($bank_name) > 150
        || ($method_is_bank && $amount_paid > 0 && $bank_name === '')) {
        flash_set('danger', 'Please provide a valid product, quantity and total. Choose the bank account that paid when using a bank method.');
    } else {
        $supplier_db = $supplier_id > 0 ? $supplier_id : null;
        $date_db = $purchase_date !== '' ? $purchase_date : null;
        $bank_db = $bank_name !== '' ? $bank_name : null;
        $update_scope = is_godown_manager() ? ' AND created_by = ?' : '';
        $stmt = $conn->prepare("UPDATE purchases SET product_id=?, supplier_id=?, quantity=?, total=?, amount_paid=?, payment_method_id=?, bank_name=?, purchase_date=?, status=?, notes=? WHERE purchase_id=? AND tenant_id=?{$update_scope}");
        if (is_godown_manager()) {
            $stmt->bind_param('iidddissssiii', $product_id, $supplier_db, $quantity, $total, $amount_paid, $payment_method_id, $bank_db, $date_db, $status_to_save, $notes, $id, $tenant_id, $current_user->user_id);
        } else {
            $stmt->bind_param('iidddissssii', $product_id, $supplier_db, $quantity, $total, $amount_paid, $payment_method_id, $bank_db, $date_db, $status_to_save, $notes, $id, $tenant_id);
        }
        if ($stmt->execute()) {
            audit_log('update', 'purchase', $id, 'Purchase #' . $id . ' updated');
            if ($status === 'arrived_godown') {
                flash_set('success', 'Purchase updated. Confirm what actually arrived to add good units to stock.');
                if (can_receive_purchases()) {
                    redirect('purchases.php?receive=' . $id);
                }
                flash_set('warning', 'Purchase updated. An administrator, Mini Admin or the Godown Manager must confirm the goods received.');
                redirect('purchases.php');
            }
            flash_set('success', 'Purchase updated successfully.');
            redirect('purchases.php');
        } else {
            flash_set('danger', 'Failed to update purchase: ' . $stmt->error);
        }
        $stmt->close();
    }
}

require_once 'navbar.php';
require_once 'sidebar.php';
?>
<div class="page-head">
    <div><h1 class="page-title">Edit Purchase</h1></div>
    <a href="purchases.php" class="btn btn-light"><i class="fa fa-arrow-left mr-1"></i> Back</a>
</div>
<form method="POST" action="purchase-edit.php?id=<?php echo (int)$id; ?>" class="card">
    <div class="card-body">
        <?php echo csrf_field(); ?>
        <div class="form-group">
            <label>Product</label>
            <select name="product_id" class="form-control" required>
                <?php foreach ($products as $p): ?>
                    <option value="<?php echo (int)$p['product_id']; ?>" <?php echo (int)$row['product_id'] === (int)$p['product_id'] ? 'selected' : ''; ?>><?php echo e($p['name']); ?> (<?php echo e($p['code']); ?>)</option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="form-group">
            <label>Supplier</label>
            <select name="supplier_id" class="form-control">
                <option value="0">— None —</option>
                <?php foreach ($suppliers as $s): ?>
                    <option value="<?php echo (int)$s['supplier_id']; ?>" <?php echo (int)$row['supplier_id'] === (int)$s['supplier_id'] ? 'selected' : ''; ?>><?php echo e($s['company_name']); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="form-row">
            <div class="form-group col-md-4">
                <label>Quantity</label>
                <input type="number" step="0.01" min="0" name="quantity" class="form-control" value="<?php echo e($row['quantity']); ?>" required>
            </div>
            <div class="form-group col-md-4">
                <label>Total Amount</label>
                <input type="number" step="0.01" min="0" name="total" class="form-control" value="<?php echo e($row['total']); ?>" required>
            </div>
            <div class="form-group col-md-4">
                <label>Amount Paid</label>
                <input type="number" step="0.01" min="0" name="amount_paid" class="form-control" value="<?php echo e($row['amount_paid']); ?>">
            </div>
        </div>
        <div class="form-group">
            <label>Purchase Date</label>
            <input type="date" name="purchase_date" class="form-control" value="<?php echo e($row['purchase_date']); ?>">
        </div>
        <div class="form-row">
            <div class="form-group col-md-6">
                <label>Payment Method</label>
                <select name="payment_method_id" id="purchasePaymentMethod" class="form-control">
                    <option value="0">— Not specified (bank) —</option>
                    <?php foreach ($payment_methods as $pm): ?>
                        <option value="<?php echo (int)$pm['payment_method_id']; ?>"
                                data-account-type="<?php echo e($pm['account_type'] ?? 'bank'); ?>"
                                <?php echo (int)($row['payment_method_id'] ?? 0) === (int)$pm['payment_method_id'] ? 'selected' : ''; ?>>
                            <?php echo e($pm['name']); ?><?php echo (string)($pm['account_type'] ?? 'bank') === 'cash' ? ' (cash)' : ' (bank)'; ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <small class="form-text text-muted">Every purchase payment reduces the brand's total capital in bank.</small>
            </div>
            <div class="form-group col-md-6" id="purchaseBankGroup">
                <label>Bank Account <span class="text-muted font-weight-normal">(optional)</span></label>
                <input type="text" name="bank_name" id="purchaseBankName" class="form-control" list="purchaseBanks" maxlength="150" value="<?php echo e($row['bank_name'] ?? ''); ?>" placeholder="e.g. NMB, CRBC, NBC">
                <datalist id="purchaseBanks">
                    <?php foreach ($purchase_banks as $purchase_bank): ?>
                        <option value="<?php echo e($purchase_bank); ?>"></option>
                    <?php endforeach; ?>
                </datalist>
                <small class="form-text text-muted">Choose the bank that paid, so the correct account is reduced. Leave blank to reduce only the brand bank total.</small>
            </div>
        </div>
        <div class="form-group">
            <label>Goods Status</label>
            <select name="status" class="form-control">
                <?php foreach ($goods_statuses as $status_key => $status_label): ?>
                    <option value="<?php echo e($status_key); ?>" <?php echo (string)($row['status'] ?? 'in_production') === $status_key ? 'selected' : ''; ?>><?php echo e($status_label); ?></option>
                <?php endforeach; ?>
            </select>
            <small class="form-text text-muted">Update where the goods are: production, transport, or arrival at the Godown.</small>
        </div>
        <div class="form-group">
            <label>Notes</label>
            <textarea name="notes" class="form-control" rows="3"><?php echo e($row['notes']); ?></textarea>
        </div>
        <button type="submit" class="btn btn-primary"><i class="fa fa-save mr-1"></i> Update Purchase</button>
    </div>
</form>
<script>
    (function () {
        var methodSelect = document.getElementById('purchasePaymentMethod');
        var bankGroup = document.getElementById('purchaseBankGroup');
        var bankInput = document.getElementById('purchaseBankName');
        if (!methodSelect || !bankGroup || !bankInput) return;
        function updateBankField() {
            var option = methodSelect.options[methodSelect.selectedIndex];
            var isBank = option && option.value !== '0' && option.getAttribute('data-account-type') === 'bank';
            bankGroup.style.display = isBank ? '' : 'none';
            if (!isBank) bankInput.value = '';
        }
        methodSelect.addEventListener('change', updateBankField);
        updateBankField();
    })();
</script>
<?php require_once 'footer.php'; ?>