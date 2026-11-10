<?php
require_once 'header.php';
require_once 'auth_check.php';
require_once 'capital-helpers.php';
require_permission('manage_purchases');
$page_title = 'New Purchase';

$tenant_id = (int)$current_user->tenant_id;
$purchase_product_brand = brand_scope_condition('p.brand');
$products = fetch_all(
    "SELECT p.product_id, p.name, p.code, p.min_stock_level, COALESCE(u.is_decimal, 0) is_decimal
       FROM products p
       LEFT JOIN units_of_measurement u ON u.unit_id = p.unit_id AND u.tenant_id = p.tenant_id
      WHERE p.tenant_id = ? AND p.is_active = 1 AND p.is_service = 0{$purchase_product_brand['sql']}
      ORDER BY p.name",
    ...array_merge([$tenant_id], $purchase_product_brand['params'])
);
$suppliers = fetch_all("SELECT supplier_id, company_name FROM suppliers WHERE tenant_id = ? ORDER BY company_name", $tenant_id);
$payment_methods = fetch_all("SELECT * FROM payment_methods WHERE tenant_id = ? ORDER BY payment_method_id", $tenant_id);
$purchase_banks = capital_bank_names($tenant_id, current_brand_scope());
$goods_statuses = purchase_goods_statuses();

$product_id = (int)($_POST['product_id'] ?? 0);
$supplier_id = (int)($_POST['supplier_id'] ?? 0);
$quantity = (float)($_POST['quantity'] ?? 0);
$unit_amount = (float)($_POST['unit_amount'] ?? 0);
$total = round($quantity * $unit_amount, 2);
$amount_paid = (float)($_POST['amount_paid'] ?? 0);
$payment_method_id = (int)($_POST['payment_method_id'] ?? 0) ?: null;
$bank_name = trim((string)($_POST['bank_name'] ?? ''));
$purchase_date = trim((string)($_POST['purchase_date'] ?? ''));
$status = (string)($_POST['status'] ?? 'in_production');
$notes = trim((string)($_POST['notes'] ?? ''));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $product_id = (int)($_POST['product_id'] ?? 0);
    $supplier_id = (int)($_POST['supplier_id'] ?? 0);
    $quantity_input = $_POST['quantity'] ?? '';
    $unit_amount_input = $_POST['unit_amount'] ?? '';
    $amount_paid_input = $_POST['amount_paid'] ?? '0';
    $payment_method_id = (int)($_POST['payment_method_id'] ?? 0) ?: null;
    $bank_name = trim((string)($_POST['bank_name'] ?? ''));
    $quantity = is_numeric($quantity_input) ? (float)$quantity_input : 0;
    $unit_amount = is_numeric($unit_amount_input) ? (float)$unit_amount_input : 0;
    $total = round($quantity * $unit_amount, 2);
    $amount_paid = is_numeric($amount_paid_input) ? (float)$amount_paid_input : 0;
    $purchase_date = trim((string)($_POST['purchase_date'] ?? ''));
    $status = (string)($_POST['status'] ?? 'in_production');
    $notes = trim((string)($_POST['notes'] ?? ''));

    if (!isset($goods_statuses[$status])) {
        $status = 'in_production';
    }

    $valid_product = fetch_one(
        "SELECT p.product_id, p.brand, p.min_stock_level, p.is_service, COALESCE(u.is_decimal, 0) is_decimal
           FROM products p
           LEFT JOIN units_of_measurement u ON u.unit_id = p.unit_id AND u.tenant_id = p.tenant_id
          WHERE p.product_id = ? AND p.tenant_id = ? AND p.is_active = 1{$purchase_product_brand['sql']}",
        ...array_merge([$product_id, $tenant_id], $purchase_product_brand['params'])
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

    if (
        !$valid_product || !$valid_supplier
        || !is_numeric($quantity_input) || !is_numeric($unit_amount_input)
        || !is_numeric($amount_paid_input) || $quantity <= 0 || $quantity > 99999999
        || (!(int)($valid_product['is_decimal'] ?? 0) && abs($quantity - round($quantity)) > 0.0001)
        || (int)($valid_product['is_service'] ?? 1) === 1
        || $unit_amount < 0 || $amount_paid < 0
        || mb_strlen($bank_name) > 150
        || ($method_is_bank && $amount_paid > 0 && $bank_name === '')
    ) {
        flash_set('danger', 'Please provide a valid product, quantity, unit amount and amount paid. Choose the bank account that paid when using a bank method.');
    } else {
        $stmt = $conn->prepare(
            "INSERT INTO purchases
                (tenant_id, product_id, supplier_id, quantity, total, amount_paid, payment_method_id, bank_name, purchase_date, status, notes, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
        );
        $supplier_db = $supplier_id > 0 ? $supplier_id : null;
        $date_db = $purchase_date !== '' ? $purchase_date : null;
        $bank_db = $bank_name !== '' ? $bank_name : null;
        $created_by = (int)$current_user->user_id;
        $stmt->bind_param('iiidddissssi', $tenant_id, $product_id, $supplier_db, $quantity, $total, $amount_paid, $payment_method_id, $bank_db, $date_db, $status, $notes, $created_by);
        if ($stmt->execute()) {
            $purchase_id = (int)$stmt->insert_id;
            $stmt->close();
            audit_log('create', 'purchase', $purchase_id, 'Purchase #' . $purchase_id . ' recorded');
            flash_set('success', 'Purchase recorded successfully. Stock is not changed by purchases; use Godown stock receipts or adjustments to receive goods.');
            redirect('purchases.php');
        }
        $stmt->close();
        flash_set('danger', 'Failed to record purchase.');
    }
}

require_once 'navbar.php';
require_once 'sidebar.php';
?>
<div class="page-head">
    <div><h1 class="page-title">New Purchase</h1></div>
    <a href="purchases.php" class="btn btn-light"><i class="fa fa-arrow-left mr-1"></i> Back</a>
</div>
<style>
    .purchase-form select.form-control {
        background-color: #fff;
        color: #343a40;
        color-scheme: light;
    }

    .purchase-form select.form-control option {
        background-color: #fff;
        color: #343a40;
    }
</style>
<form method="POST" action="purchase-add.php" class="card purchase-form">
    <div class="card-body">
        <?php echo csrf_field(); ?>
        <div class="alert alert-info">
            <i class="fa fa-info-circle mr-1"></i>
            Record your new Purchase
        </div>
        <div class="form-group">
            <label>Product</label>
            <select name="product_id" class="form-control" required>
                <option value="">Select product</option>
                <?php foreach ($products as $p): ?>
                    <option value="<?php echo (int)$p['product_id']; ?>" <?php echo $product_id === (int)$p['product_id'] ? 'selected' : ''; ?>><?php echo e($p['name']); ?> (<?php echo e($p['code']); ?>)</option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="form-group">
            <label>Supplier</label>
            <select name="supplier_id" class="form-control">
                <option value="0">— None —</option>
                <?php foreach ($suppliers as $s): ?>
                    <option value="<?php echo (int)$s['supplier_id']; ?>" <?php echo $supplier_id === (int)$s['supplier_id'] ? 'selected' : ''; ?>><?php echo e($s['company_name']); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="form-row">
            <div class="form-group col-md-3">
                <label>Quantity</label>
                <input type="number" step="0.01" min="0.01" name="quantity" id="purchaseQuantity" class="form-control" value="<?php echo e($quantity ?: ''); ?>" required>
            </div>
            <div class="form-group col-md-3">
                <label>Amount per Product</label>
                <input type="number" step="0.01" min="0" name="unit_amount" id="purchaseUnitAmount" class="form-control" value="<?php echo e($unit_amount); ?>" required>
                <small class="form-text text-muted">Amount for one unit; total is calculated from quantity.</small>
            </div>
            <div class="form-group col-md-3">
                <label>Total Amount</label>
                <input type="number" step="0.01" min="0" id="purchaseTotal" class="form-control" value="<?php echo e(number_format($total, 2, '.', '')); ?>" readonly>
            </div>
            <div class="form-group col-md-3">
                <label>Amount Paid</label>
                <input type="number" step="0.01" min="0" name="amount_paid" class="form-control" value="<?php echo e($amount_paid); ?>">
            </div>
        </div>
        <div class="form-row">
            <div class="form-group col-md-6">
                <label>Purchase Date</label>
                <input type="date" name="purchase_date" class="form-control" value="<?php echo e($purchase_date); ?>">
            </div>
            <div class="form-group col-md-6">
                <label>Goods Status</label>
                <select name="status" class="form-control">
                    <?php foreach ($goods_statuses as $status_key => $status_label): ?>
                        <option value="<?php echo e($status_key); ?>" <?php echo $status === $status_key ? 'selected' : ''; ?>><?php echo e($status_label); ?></option>
                    <?php endforeach; ?>
                </select>
                <small class="form-text text-muted">Track where the goods are: production, transport, or arrival at the Godown.</small>
            </div>
        </div>
        <div class="form-row">
            <div class="form-group col-md-6">
                <label>Payment Method</label>
                <select name="payment_method_id" id="purchasePaymentMethod" class="form-control">
                    <option value="0">— Not specified (bank) —</option>
                    <?php foreach ($payment_methods as $pm): ?>
                        <option value="<?php echo (int)$pm['payment_method_id']; ?>"
                                data-account-type="<?php echo e($pm['account_type'] ?? 'bank'); ?>"
                                <?php echo (int)$payment_method_id === (int)$pm['payment_method_id'] ? 'selected' : ''; ?>>
                            <?php echo e($pm['name']); ?><?php echo (string)($pm['account_type'] ?? 'bank') === 'cash' ? ' (cash)' : ' (bank)'; ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <small class="form-text text-muted">Cash methods reduce cash in hand; other methods reduce the bank balance.</small>
            </div>
            <div class="form-group col-md-6" id="purchaseBankGroup">
                <label>Bank Account <span class="text-muted font-weight-normal">(optional)</span></label>
                <input type="text" name="bank_name" id="purchaseBankName" class="form-control" list="purchaseBanks" maxlength="150" value="<?php echo e($bank_name); ?>" placeholder="e.g. NMB, CRBC, NBC">
                <datalist id="purchaseBanks">
                    <?php foreach ($purchase_banks as $purchase_bank): ?>
                        <option value="<?php echo e($purchase_bank); ?>"></option>
                    <?php endforeach; ?>
                </datalist>
                <small class="form-text text-muted">Choose the bank that paid, so the correct account is reduced. Leave blank to reduce only the brand bank total.</small>
            </div>
        </div>
        <div class="form-group">
            <label>Notes</label>
            <textarea name="notes" class="form-control" rows="3"><?php echo e($notes); ?></textarea>
        </div>
        <button type="submit" class="btn btn-primary"><i class="fa fa-save mr-1"></i> Save Purchase</button>
    </div>
</form>
<script>
    (function () {
        var quantityInput = document.getElementById('purchaseQuantity');
        var unitAmountInput = document.getElementById('purchaseUnitAmount');
        var totalInput = document.getElementById('purchaseTotal');

        function updateTotal() {
            var quantity = parseFloat(quantityInput.value) || 0;
            var unitAmount = parseFloat(unitAmountInput.value) || 0;
            totalInput.value = (quantity * unitAmount).toFixed(2);
        }

        quantityInput.addEventListener('input', updateTotal);
        unitAmountInput.addEventListener('input', updateTotal);

        var methodSelect = document.getElementById('purchasePaymentMethod');
        var bankGroup = document.getElementById('purchaseBankGroup');
        var bankInput = document.getElementById('purchaseBankName');
        function updateBankField() {
            if (!methodSelect || !bankGroup || !bankInput) return;
            var option = methodSelect.options[methodSelect.selectedIndex];
            var isBank = option && option.value !== '0' && option.getAttribute('data-account-type') === 'bank';
            bankGroup.style.display = isBank ? '' : 'none';
            if (!isBank) bankInput.value = '';
        }
        if (methodSelect) {
            methodSelect.addEventListener('change', updateBankField);
            updateBankField();
        }
    })();
</script>
<?php require_once 'footer.php'; ?>
