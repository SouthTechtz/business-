<?php
require_once 'header.php';
require_once 'auth_check.php';
require_permission('manage_orders');
$page_title = 'Edit Order';

$id = (int)($_GET['id'] ?? 0);
$order = fetch_one("SELECT * FROM orders WHERE order_id = ? AND tenant_id = ?", $id, $current_user->tenant_id);
if (!$order) {
    flash_set('danger', 'Order not found.');
    redirect('orders.php');
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $shop_id = !empty($_POST['shop_id']) ? (int)$_POST['shop_id'] : null;
    $warehouse_id = !empty($_POST['warehouse_id']) ? (int)$_POST['warehouse_id'] : null;
    $contact_id = !empty($_POST['contact_id']) ? (int)$_POST['contact_id'] : null;
    $customer_name = trim($_POST['customer_name'] ?? '');
    $customer_phone = trim($_POST['customer_phone'] ?? '');
    $customer_location = trim($_POST['customer_location'] ?? '');
    $status = trim($_POST['status'] ?? 'unpaid');
    $stage = trim($_POST['stage'] ?? 'shop');
    $amount_paid = (float)($_POST['Amount_paid'] ?? 0);
    $notes = trim($_POST['notes'] ?? '');

    if ($customer_name === '') {
        $error = 'Customer name is required.';
    } else {
        $stmt = $conn->prepare(
            "UPDATE orders SET shop_id = ?, warehouse_id = ?, contact_id = ?, customer_name = ?, customer_phone = ?, customer_location = ?, status = ?, stage = ?, Amount_paid = ?, notes = ?
             WHERE order_id = ? AND tenant_id = ?"
        );
        $stmt->bind_param(
            'iiisssssdsii',
            $shop_id,
            $warehouse_id,
            $contact_id,
            $customer_name,
            $customer_phone,
            $customer_location,
            $status,
            $stage,
            $amount_paid,
            $notes,
            $id,
            $current_user->tenant_id
        );
        if ($stmt->execute()) {
            audit_log('update', 'order', $id, 'Order updated');
            flash_set('success', 'Order updated successfully.');
            redirect('order-view.php?id=' . $id);
        }
        $error = 'Could not update the order.';
    }
}

$shops = accessible_shops(null, true);
$warehouses = accessible_stores(null, false);
$contacts = fetch_all("SELECT contact_id, first_name, last_name, company_name FROM contacts WHERE tenant_id = ? ORDER BY first_name", $current_user->tenant_id);

require_once 'navbar.php';
require_once 'sidebar.php';
?>

<div class="page-head">
    <div>
        <h1 class="page-title">Edit Order</h1>
        <div class="page-sub"><?php echo e($order['order_number']); ?></div>
    </div>
    <a href="order-view.php?id=<?php echo $id; ?>" class="btn btn-light"><i class="fa fa-arrow-left mr-1"></i> Back</a>
</div>

<?php if ($error): ?><div class="alert alert-danger"><?php echo e($error); ?></div><?php endif; ?>

<div class="card">
    <div class="card-body">
        <form method="POST" action="order-edit.php?id=<?php echo $id; ?>">
            <div class="row">
                <div class="col-md-4 form-group">
                    <label>Customer Name <span class="text-danger">*</span></label>
                    <input type="text" name="customer_name" class="form-control" required value="<?php echo e($order['customer_name']); ?>">
                </div>
                <div class="col-md-4 form-group">
                    <label>Customer Phone</label>
                    <input type="text" name="customer_phone" class="form-control" value="<?php echo e($order['customer_phone']); ?>">
                </div>
                <div class="col-md-4 form-group">
                    <label>Customer Location</label>
                    <input type="text" name="customer_location" class="form-control" value="<?php echo e($order['customer_location']); ?>">
                </div>

                <div class="col-md-4 form-group">
                    <label>Shop</label>
                    <select name="shop_id" class="form-control">
                        <option value="">— Select Shop —</option>
                        <?php foreach ($shops as $s): ?>
                            <option value="<?php echo (int)$s['shop_id']; ?>" <?php echo (int)$order['shop_id'] === (int)$s['shop_id'] ? 'selected' : ''; ?>><?php echo e($s['name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-4 form-group">
                    <label>Warehouse / Store</label>
                    <select name="warehouse_id" class="form-control">
                        <option value="">— Select Warehouse —</option>
                        <?php foreach ($warehouses as $w): ?>
                            <option value="<?php echo (int)$w['warehouse_id']; ?>" <?php echo (int)$order['warehouse_id'] === (int)$w['warehouse_id'] ? 'selected' : ''; ?>><?php echo e(store_label($w)); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-4 form-group">
                    <label>Contact Profile (Optional)</label>
                    <select name="contact_id" class="form-control">
                        <option value="">— None —</option>
                        <?php foreach ($contacts as $c): ?>
                            <option value="<?php echo (int)$c['contact_id']; ?>" <?php echo (int)$order['contact_id'] === (int)$c['contact_id'] ? 'selected' : ''; ?>><?php echo e(trim($c['first_name'] . ' ' . $c['last_name']) ?: $c['company_name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="col-md-4 form-group">
                    <label>Payment Status <span class="text-danger">*</span></label>
                    <select name="status" class="form-control" required>
                        <option value="unpaid" <?php echo $order['status'] === 'unpaid' ? 'selected' : ''; ?>>Unpaid</option>
                        <option value="partially_paid" <?php echo $order['status'] === 'partially_paid' ? 'selected' : ''; ?>>Partially Paid</option>
                        <option value="paid" <?php echo $order['status'] === 'paid' ? 'selected' : ''; ?>>Paid</option>
                    </select>
                </div>
                <div class="col-md-4 form-group">
                    <label>Amount Paid</label>
                    <input type="number" name="Amount_paid" class="form-control" step="0.01" min="0" value="<?php echo e($order['Amount_paid'] ?? 0); ?>">
                </div>
                <div class="col-md-4 form-group">
                    <label>Order Stage <span class="text-danger">*</span></label>
                    <select name="stage" class="form-control" required>
                        <option value="shop" <?php echo ($order['stage'] ?? '') === 'shop' ? 'selected' : ''; ?>>At the shop</option>
                        <option value="transport" <?php echo ($order['stage'] ?? '') === 'transport' ? 'selected' : ''; ?>>In transport</option>
                        <option value="completed" <?php echo ($order['stage'] ?? '') === 'completed' ? 'selected' : ''; ?>>Completed</option>
                        <option value="returned" <?php echo ($order['stage'] ?? '') === 'returned' ? 'selected' : ''; ?>>Returned</option>
                    </select>
                </div>

                <div class="col-md-12 form-group">
                    <label>Notes</label>
                    <textarea name="notes" class="form-control" rows="2"><?php echo e($order['notes']); ?></textarea>
                </div>
            </div>
            <button type="submit" class="btn btn-primary"><i class="fa fa-check mr-1"></i> Save Changes</button>
        </form>
    </div>
</div>

<?php require_once 'footer.php'; ?>