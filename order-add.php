<?php
require_once 'header.php';
require_once 'auth_check.php';
require_permission('manage_orders');
$page_title = 'New Order';

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
        $conn->begin_transaction();
        try {
            // Generate order number
            $order_number = 'ORD-' . date('Ymd') . '-' . rand(1000, 9999);

            $stmt = $conn->prepare(
                "INSERT INTO orders (tenant_id, shop_id, warehouse_id, contact_id, customer_name, customer_phone, customer_location, status, stage, Amount_paid, notes, created_by, created_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())"
            );
            $stmt->bind_param(
                'iiiissssdsis',
                $current_user->tenant_id,
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
                $current_user->user_id
            );
            $stmt->execute();
            $order_id = $conn->insert_id;
            $stmt->close();

            $conn->commit();
            flash_set('success', 'Order created successfully.');
            redirect('order-view.php?id=' . $order_id);
        } catch (Exception $ex) {
            $conn->rollback();
            $error = 'Failed to create order: ' . $ex->getMessage();
        }
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
        <h1 class="page-title">New Order</h1>
        <div class="page-sub">Record a new client request or order.</div>
    </div>
    <a href="orders.php" class="btn btn-light"><i class="fa fa-arrow-left mr-1"></i> Back to Orders</a>
</div>

<?php if ($error): ?><div class="alert alert-danger"><?php echo e($error); ?></div><?php endif; ?>

<div class="card">
    <div class="card-body">
        <form method="POST" action="order-add.php">
            <div class="row">
                <div class="col-md-4 form-group">
                    <label>Customer Name <span class="text-danger">*</span></label>
                    <input type="text" name="customer_name" class="form-control" required value="<?php echo e($_POST['customer_name'] ?? ''); ?>">
                </div>
                <div class="col-md-4 form-group">
                    <label>Customer Phone</label>
                    <input type="text" name="customer_phone" class="form-control" value="<?php echo e($_POST['customer_phone'] ?? ''); ?>">
                </div>
                <div class="col-md-4 form-group">
                    <label>Customer Location</label>
                    <input type="text" name="customer_location" class="form-control" value="<?php echo e($_POST['customer_location'] ?? ''); ?>">
                </div>

                <div class="col-md-4 form-group">
                    <label>Shop</label>
                    <select name="shop_id" class="form-control">
                        <option value="">— Select Shop —</option>
                        <?php foreach ($shops as $s): ?>
                            <option value="<?php echo (int)$s['shop_id']; ?>"><?php echo e($s['name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-4 form-group">
                    <label>Warehouse / Store</label>
                    <select name="warehouse_id" class="form-control">
                        <option value="">— Select Warehouse —</option>
                        <?php foreach ($warehouses as $w): ?>
                            <option value="<?php echo (int)$w['warehouse_id']; ?>"><?php echo e(store_label($w)); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-4 form-group">
                    <label>Contact Profile (Optional)</label>
                    <select name="contact_id" class="form-control">
                        <option value="">— None —</option>
                        <?php foreach ($contacts as $c): ?>
                            <option value="<?php echo (int)$c['contact_id']; ?>"><?php echo e(trim($c['first_name'] . ' ' . $c['last_name']) ?: $c['company_name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="col-md-4 form-group">
                    <label>Payment Status <span class="text-danger">*</span></label>
                    <select name="status" class="form-control" required>
                        <option value="unpaid">Unpaid</option>
                        <option value="partially_paid">Partially Paid</option>
                        <option value="paid">Paid</option>
                    </select>
                </div>
                <div class="col-md-4 form-group">
                    <label>Amount Paid</label>
                    <input type="number" name="Amount_paid" class="form-control" step="0.01" min="0" value="0.00">
                </div>
                <div class="col-md-4 form-group">
                    <label>Order Stage <span class="text-danger">*</span></label>
                    <select name="stage" class="form-control" required>
                        <option value="shop">At the shop</option>
                        <option value="transport">In transport</option>
                        <option value="completed">Completed</option>
                        <option value="returned">Returned</option>
                    </select>
                </div>

                <div class="col-md-12 form-group">
                    <label>Notes</label>
                    <textarea name="notes" class="form-control" rows="2" placeholder="Additional details or delivery instructions..."></textarea>
                </div>
            </div>
            <button type="submit" class="btn btn-primary"><i class="fa fa-check mr-1"></i> Save Order</button>
        </form>
    </div>
</div>

<?php require_once 'footer.php'; ?>