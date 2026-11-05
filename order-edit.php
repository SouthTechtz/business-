<?php
require_once 'header.php';
require_once 'auth_check.php';
require_once 'wholesale.php';
require_permission('manage_orders');
$page_title = 'Edit Order';

$id = (int)($_GET['id'] ?? 0);
$order = fetch_one("SELECT * FROM orders WHERE order_id = ? AND tenant_id = ?", $id, $current_user->tenant_id);
if (!$order) {
    flash_set('danger', 'Order not found.');
    redirect('orders.php');
}
$original_stage = (string)($order['stage'] ?? 'shop');

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $shop_id = !empty($_POST['shop_id']) ? (int)$_POST['shop_id'] : null;
    // Orders always draw stock from the shop's own inventory location.
    $warehouse_id = $shop_id ? shop_inventory_warehouse_id($shop_id) : null;
    $contact_id = !empty($_POST['contact_id']) ? (int)$_POST['contact_id'] : null;
    $customer_name = trim($_POST['customer_name'] ?? '');
    $customer_phone = trim($_POST['customer_phone'] ?? '');
    $customer_location = trim($_POST['customer_location'] ?? '');
    $stage = trim($_POST['stage'] ?? 'shop');
    $order_total_input = $_POST['order_total'] ?? '';
    $amount_paid_input = $_POST['Amount_paid'] ?? '';
    $notes = trim($_POST['notes'] ?? '');
    $valid_stages = ['shop', 'transport', 'completed', 'returned'];

    if ($customer_name === '') {
        $error = 'Customer name is required.';
    } elseif (!is_numeric($order_total_input) || !is_numeric($amount_paid_input)) {
        $error = 'Enter a valid order total and amount paid.';
    } elseif (
        (float)$order_total_input < 0
        || (float)$order_total_input > 9999999999.99
        || (float)$amount_paid_input < 0
        || (float)$amount_paid_input > (float)$order_total_input
    ) {
        $error = 'Amounts must be valid, and amount paid cannot exceed the order total.';
    } elseif (!in_array($stage, $valid_stages, true)) {
        $error = 'Choose a valid order stage.';
    } else {
        $order_total = round((float)$order_total_input, 2);
        $amount_paid = round((float)$amount_paid_input, 2);
        $conn->begin_transaction();
        try {
            $locked_order = fetch_one(
                "SELECT * FROM orders WHERE order_id = ? AND tenant_id = ? FOR UPDATE",
                $id,
                (int)$current_user->tenant_id
            );
            if (!$locked_order || !order_is_visible($locked_order)) {
                throw new RuntimeException('Order not found.');
            }

            $current_stage = (string)($locked_order['stage'] ?? 'shop');
            if ($stage === 'transport' && $current_stage !== 'transport') {
                if (($_POST['confirm_transport'] ?? '') !== '1') {
                    throw new RuntimeException('Confirm that the stock has started moving before setting the order to In transport.');
                }
                if (!$shop_id || !$warehouse_id) {
                    throw new RuntimeException('Assign this order to a shop with an active shop inventory location before moving it into transport.');
                }
                if ((int)$warehouse_id !== (int)$locked_order['warehouse_id']
                    || (int)$shop_id !== (int)$locked_order['shop_id']) {
                    $stmt = $conn->prepare(
                        "UPDATE orders SET shop_id = ?, warehouse_id = ?
                          WHERE order_id = ? AND tenant_id = ?"
                    );
                    if (!$stmt) throw new RuntimeException($conn->error);
                    $stmt->bind_param('iiii', $shop_id, $warehouse_id, $id, $current_user->tenant_id);
                    if (!$stmt->execute()) throw new RuntimeException($stmt->error);
                    $stmt->close();
                }
                dispatch_order_stock_for_transport(
                    $id,
                    (int)$current_user->tenant_id,
                    (int)$current_user->user_id
                );
            } elseif ($current_stage === 'transport' && $stage === 'shop') {
                throw new RuntimeException('An order already in transport cannot be moved back to At the shop.');
            }

            $stmt = $conn->prepare(
                "UPDATE orders SET shop_id = ?, warehouse_id = ?, contact_id = ?, customer_name = ?, customer_phone = ?,
                    customer_location = ?, subtotal = ?, total = ?, stage = ?, Amount_paid = ?, notes = ?
                 WHERE order_id = ? AND tenant_id = ?"
            );
            if (!$stmt) throw new RuntimeException('Could not prepare the order update: ' . $conn->error);
            $stmt->bind_param(
                'iiisssddsdsii',
                $shop_id,
                $warehouse_id,
                $contact_id,
                $customer_name,
                $customer_phone,
                $customer_location,
                $order_total,
                $order_total,
                $stage,
                $amount_paid,
                $notes,
                $id,
                $current_user->tenant_id
            );
            if (!$stmt->execute()) {
                $stmt_error = $stmt->error;
                $stmt->close();
                throw new RuntimeException('Could not update the order: ' . $stmt_error);
            }
            $stmt->close();
            $conn->commit();
            wholesale_notify_order_paid($id, (int)$current_user->tenant_id);
            audit_log('update', 'order', $id, 'Order updated');
            flash_set('success', 'Order updated successfully.');
            redirect('order-view.php?id=' . $id);
        } catch (Throwable $ex) {
            $conn->rollback();
            $error = $ex->getMessage();
        }
    }
    $order = array_merge($order, [
        'shop_id' => $shop_id,
        'warehouse_id' => $warehouse_id,
        'contact_id' => $contact_id,
        'customer_name' => $customer_name,
        'customer_phone' => $customer_phone,
        'customer_location' => $customer_location,
        'total' => $order_total_input,
        'Amount_paid' => $amount_paid_input,
        'stage' => $stage,
        'notes' => $notes,
    ]);
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
            <?php echo csrf_field(); ?>
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
                    <label>Contact Profile (Optional)</label>
                    <select name="contact_id" class="form-control">
                        <option value="">— None —</option>
                        <?php foreach ($contacts as $c): ?>
                            <option value="<?php echo (int)$c['contact_id']; ?>" <?php echo (int)$order['contact_id'] === (int)$c['contact_id'] ? 'selected' : ''; ?>><?php echo e(trim($c['first_name'] . ' ' . $c['last_name']) ?: $c['company_name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="col-md-4 form-group">
                    <label>Order Total <span class="text-danger">*</span></label>
                    <input type="number" name="order_total" class="form-control" step="0.01" min="0"
                           max="9999999999.99" id="editOrderTotal" required value="<?php echo e($order['total']); ?>">
                </div>
                <div class="col-md-4 form-group">
                    <label>Amount Paid <span class="text-danger">*</span></label>
                    <input type="number" name="Amount_paid" class="form-control" step="0.01" min="0" required value="<?php echo e($order['Amount_paid'] ?? 0); ?>">
                </div>
                <div class="col-md-4 form-group">
                    <label>Order Stage <span class="text-danger">*</span></label>
                    <select name="stage" id="editOrderStage" class="form-control"
                            data-original-stage="<?php echo e($original_stage); ?>" required>
                        <option value="shop" <?php echo ($order['stage'] ?? '') === 'shop' ? 'selected' : ''; ?>>At the shop</option>
                        <option value="transport" <?php echo ($order['stage'] ?? '') === 'transport' ? 'selected' : ''; ?>>In transport</option>
                        <option value="completed" <?php echo ($order['stage'] ?? '') === 'completed' ? 'selected' : ''; ?>>Completed</option>
                        <option value="returned" <?php echo ($order['stage'] ?? '') === 'returned' ? 'selected' : ''; ?>>Returned</option>
                    </select>
                </div>
                <div class="col-md-12 form-group d-none" id="editTransportConfirmation">
                    <div class="alert alert-warning mb-0">
                        <div class="custom-control custom-checkbox">
                            <input type="checkbox" class="custom-control-input" id="editConfirmTransport" name="confirm_transport" value="1">
                            <label class="custom-control-label" for="editConfirmTransport">
                                I confirm the stock has started moving. The order quantities will be deducted from the selected shop's inventory.
                            </label>
                        </div>
                    </div>
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

<script>
    $(function () {
        var $stage = $('#editOrderStage');
        var $confirmation = $('#editTransportConfirmation');
        var $checkbox = $('#editConfirmTransport');

        function toggleTransportConfirmation() {
            var needsConfirmation = $stage.val() === 'transport'
                && $stage.data('original-stage') !== 'transport';
            $confirmation.toggleClass('d-none', !needsConfirmation);
            $checkbox.prop('required', needsConfirmation);
            if (!needsConfirmation) $checkbox.prop('checked', false);
        }

        $stage.on('change', toggleTransportConfirmation);
        toggleTransportConfirmation();
    });
</script>

<?php require_once 'footer.php'; ?>