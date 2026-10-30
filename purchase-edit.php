<?php
require_once 'header.php';
require_once 'auth_check.php';
require_permission('manage_purchases');
$page_title = 'Edit Purchase';

$tenant_id = (int)$current_user->tenant_id;
$id = (int)($_GET['id'] ?? 0);
$row = fetch_one("SELECT * FROM purchases WHERE purchase_id = ? AND tenant_id = ?", $id, $tenant_id);
if (!$row) {
    flash_set('danger', 'Purchase not found.');
    redirect('purchases.php');
}

$products = fetch_all("SELECT product_id, name, code FROM products WHERE tenant_id = ? AND is_active = 1 ORDER BY name", $tenant_id);
$suppliers = fetch_all("SELECT supplier_id, company_name FROM suppliers WHERE tenant_id = ? ORDER BY company_name", $tenant_id);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $product_id = (int)($_POST['product_id'] ?? 0);
    $supplier_id = (int)($_POST['supplier_id'] ?? 0);
    $quantity = (float)($_POST['quantity'] ?? 0);
    $total = (float)($_POST['total'] ?? 0);
    $amount_paid = (float)($_POST['amount_paid'] ?? 0);
    $purchase_date = trim((string)($_POST['purchase_date'] ?? ''));
    $notes = trim((string)($_POST['notes'] ?? ''));

    if ($product_id <= 0 || $quantity <= 0 || $total < 0) {
        flash_set('danger', 'Please provide a valid product, quantity and total.');
    } else {
        $supplier_db = $supplier_id > 0 ? $supplier_id : null;
        $date_db = $purchase_date !== '' ? $purchase_date : null;
        $stmt = $conn->prepare("UPDATE purchases SET product_id=?, supplier_id=?, quantity=?, total=?, amount_paid=?, purchase_date=?, notes=? WHERE purchase_id=? AND tenant_id=?");
        $stmt->bind_param('iidddssii', $product_id, $supplier_db, $quantity, $total, $amount_paid, $date_db, $notes, $id, $tenant_id);
        if ($stmt->execute()) {
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
        <div class="form-group">
            <label>Notes</label>
            <textarea name="notes" class="form-control" rows="3"><?php echo e($row['notes']); ?></textarea>
        </div>
        <button type="submit" class="btn btn-primary"><i class="fa fa-save mr-1"></i> Update Purchase</button>
    </div>
</form>
<?php require_once 'footer.php'; ?>