<?php
require_once 'header.php';
require_once 'auth_check.php';
require_permission('manage_purchases');
$page_title = 'New Purchase';

$tenant_id = (int)$current_user->tenant_id;
$products = fetch_all(
    "SELECT p.product_id, p.name, p.code, p.min_stock_level, COALESCE(u.is_decimal, 0) is_decimal
       FROM products p
       LEFT JOIN units_of_measurement u ON u.unit_id = p.unit_id AND u.tenant_id = p.tenant_id
      WHERE p.tenant_id = ? AND p.is_active = 1 AND p.is_service = 0
      ORDER BY p.name",
    $tenant_id
);
$suppliers = fetch_all("SELECT supplier_id, company_name FROM suppliers WHERE tenant_id = ? ORDER BY company_name", $tenant_id);

$product_id = (int)($_POST['product_id'] ?? 0);
$supplier_id = (int)($_POST['supplier_id'] ?? 0);
$quantity = (float)($_POST['quantity'] ?? 0);
$unit_amount = (float)($_POST['unit_amount'] ?? 0);
$total = round($quantity * $unit_amount, 2);
$amount_paid = (float)($_POST['amount_paid'] ?? 0);
$purchase_date = trim((string)($_POST['purchase_date'] ?? ''));
$notes = trim((string)($_POST['notes'] ?? ''));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $product_id = (int)($_POST['product_id'] ?? 0);
    $supplier_id = (int)($_POST['supplier_id'] ?? 0);
    $quantity_input = $_POST['quantity'] ?? '';
    $unit_amount_input = $_POST['unit_amount'] ?? '';
    $amount_paid_input = $_POST['amount_paid'] ?? '0';
    $quantity = is_numeric($quantity_input) ? (float)$quantity_input : 0;
    $unit_amount = is_numeric($unit_amount_input) ? (float)$unit_amount_input : 0;
    $total = round($quantity * $unit_amount, 2);
    $amount_paid = is_numeric($amount_paid_input) ? (float)$amount_paid_input : 0;
    $purchase_date = trim((string)($_POST['purchase_date'] ?? ''));
    $notes = trim((string)($_POST['notes'] ?? ''));

    $valid_product = fetch_one(
        "SELECT p.product_id, p.min_stock_level, p.is_service, COALESCE(u.is_decimal, 0) is_decimal
           FROM products p
           LEFT JOIN units_of_measurement u ON u.unit_id = p.unit_id AND u.tenant_id = p.tenant_id
          WHERE p.product_id = ? AND p.tenant_id = ? AND p.is_active = 1",
        $product_id,
        $tenant_id
    );
    $valid_supplier = $supplier_id > 0
        ? fetch_one("SELECT supplier_id FROM suppliers WHERE supplier_id = ? AND tenant_id = ?", $supplier_id, $tenant_id)
        : true;

    if (
        !$valid_product || !$valid_supplier
        || !is_numeric($quantity_input) || !is_numeric($unit_amount_input)
        || !is_numeric($amount_paid_input) || $quantity <= 0 || $quantity > 99999999
        || (!(int)($valid_product['is_decimal'] ?? 0) && abs($quantity - round($quantity)) > 0.0001)
        || (int)($valid_product['is_service'] ?? 1) === 1
        || $unit_amount < 0 || $amount_paid < 0
    ) {
        flash_set('danger', 'Please provide a valid product, quantity, unit amount and amount paid.');
    } else {
        $godown = find_active_godown($tenant_id);
        if (!$godown) {
            flash_set('danger', 'No active Central Godown is available to receive this purchase.');
        } else {
            $conn->begin_transaction();
            try {
                $locked_godown = fetch_one(
                    "SELECT warehouse_id FROM warehouses
                      WHERE warehouse_id = ? AND tenant_id = ? AND location_type = 'godown' AND is_active = 1
                      FOR UPDATE",
                    (int)$godown['warehouse_id'],
                    $tenant_id
                );
                $locked_product = fetch_one(
                    "SELECT p.product_id, p.is_active, p.is_service, p.min_stock_level,
                            COALESCE(u.is_decimal, 0) is_decimal
                       FROM products p
                       LEFT JOIN units_of_measurement u ON u.unit_id = p.unit_id AND u.tenant_id = p.tenant_id
                      WHERE p.product_id = ? AND p.tenant_id = ?
                      FOR UPDATE",
                    $product_id,
                    $tenant_id
                );
                if (!$locked_godown || !$locked_product || (int)$locked_product['is_active'] !== 1) {
                    throw new RuntimeException('The selected Godown or product is no longer active.');
                }
                if ((int)$locked_product['is_service'] === 1) {
                    throw new RuntimeException('Services cannot be received into stock.');
                }
                if (!(int)$locked_product['is_decimal'] && abs($quantity - round($quantity)) > 0.0001) {
                    throw new RuntimeException('This product uses whole units only.');
                }

                $stmt = $conn->prepare(
                    "INSERT INTO purchases
                        (tenant_id, product_id, supplier_id, quantity, total, amount_paid, purchase_date, notes, created_by)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)"
                );
                $supplier_db = $supplier_id > 0 ? $supplier_id : null;
                $date_db = $purchase_date !== '' ? $purchase_date : null;
                $created_by = (int)$current_user->user_id;
                $stmt->bind_param('iiidddssi', $tenant_id, $product_id, $supplier_db, $quantity, $total, $amount_paid, $date_db, $notes, $created_by);
                if (!$stmt->execute()) {
                    throw new RuntimeException('Failed to record purchase: ' . $stmt->error);
                }
                $purchase_id = (int)$stmt->insert_id;
                $stmt->close();

                $warehouse_id = (int)$locked_godown['warehouse_id'];
                $reorder_level = (float)$locked_product['min_stock_level'];
                $stmt = $conn->prepare(
                    "INSERT INTO inventory_stock (tenant_id, product_id, warehouse_id, quantity, reorder_level)
                     VALUES (?, ?, ?, 0, ?)
                     ON DUPLICATE KEY UPDATE stock_id = stock_id"
                );
                $stmt->bind_param('iiid', $tenant_id, $product_id, $warehouse_id, $reorder_level);
                if (!$stmt->execute()) {
                    throw new RuntimeException('Could not prepare Godown stock for this purchase: ' . $stmt->error);
                }
                $stmt->close();

                $stmt = $conn->prepare(
                    "UPDATE inventory_stock
                        SET quantity = quantity + ?, last_counted_at = CURRENT_TIMESTAMP
                      WHERE tenant_id = ? AND product_id = ? AND warehouse_id = ?"
                );
                $stmt->bind_param('diii', $quantity, $tenant_id, $product_id, $warehouse_id);
                if (!$stmt->execute() || $stmt->affected_rows !== 1) {
                    throw new RuntimeException('Could not update Godown stock for this purchase.');
                }
                $stmt->close();

                $movement_notes = 'Purchase #' . $purchase_id;
                if ($notes !== '') {
                    $movement_notes .= ' - ' . $notes;
                }
                $stmt = $conn->prepare(
                    "INSERT INTO inventory_movements
                        (tenant_id, product_id, warehouse_id, movement_type, quantity, reference_id, reference_type, created_by, notes)
                     VALUES (?, ?, ?, 'purchase', ?, ?, 'purchase', ?, ?)"
                );
                $stmt->bind_param('iiidiis', $tenant_id, $product_id, $warehouse_id, $quantity, $purchase_id, $created_by, $movement_notes);
                if (!$stmt->execute()) {
                    throw new RuntimeException('Could not record the stock movement for this purchase: ' . $stmt->error);
                }
                $stmt->close();

                $conn->commit();
                flash_set('success', 'Purchase recorded and Godown stock updated successfully.');
                redirect('godown.php?tab=products');
            } catch (Throwable $ex) {
                $conn->rollback();
                flash_set('danger', $ex->getMessage());
            }
        }
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
        <div class="form-group">
            <label>Purchase Date</label>
            <input type="date" name="purchase_date" class="form-control" value="<?php echo e($purchase_date); ?>">
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
    })();
</script>
<?php require_once 'footer.php'; ?>