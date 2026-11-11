<?php
require_once 'header.php';
require_once 'auth_check.php';
require_permission('manage_contacts');
$page_title = 'Edit Customer';

$id = (int)($_GET['id'] ?? 0);
$customer = fetch_one("SELECT * FROM contacts WHERE contact_id = ? AND tenant_id = ? AND is_customer = 1", $id, $current_user->tenant_id);
if (!$customer) {
    flash_set('danger', 'Customer not found.');
    redirect('customers.php');
}

$error = '';

$has_wholesale_account = db_table_exists('wholesaler_accounts') && fetch_one(
    "SELECT 1 FROM wholesaler_accounts WHERE contact_id = ? AND tenant_id = ? LIMIT 1",
    $id,
    $current_user->tenant_id
);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $fields = ['first_name', 'last_name', 'company_name', 'email', 'phone', 'mobile', 'address', 'city', 'state', 'postal_code', 'country', 'tax_id', 'notes'];
    $set = [];
    $vals = [];
    foreach ($fields as $f) {
        $set[] = "`$f` = ?";
        $vals[] = trim($_POST[$f] ?? '');
    }

    $customer_channel = strtolower(trim((string)($_POST['customer_channel'] ?? ($customer['customer_channel'] ?? 'walk_in'))));
    if (!array_key_exists($customer_channel, customer_channel_options())) $customer_channel = 'walk_in';
    // A contact with a wholesale portal account is always wholesale; manual
    // customers are always retail (wholesale is created from the Wholesalers tab).
    $linked_wholesale = db_table_exists('wholesaler_accounts') && fetch_one(
        "SELECT 1 FROM wholesaler_accounts WHERE contact_id = ? AND tenant_id = ? LIMIT 1",
        $id,
        $current_user->tenant_id
    );
    $customer_type = $linked_wholesale ? 'wholesale' : 'retail';
    $set[] = "`customer_type` = ?";
    $vals[] = $customer_type;
    $set[] = "`customer_channel` = ?";
    $vals[] = $customer_channel;

    $vals[] = $id;
    $vals[] = $current_user->tenant_id;
    $stmt = $conn->prepare("UPDATE contacts SET " . implode(', ', $set) . " WHERE contact_id = ? AND tenant_id = ?");
    $types = str_repeat('s', count($fields) + 2) . 'ii';
    $stmt->bind_param($types, ...$vals);
    if ($stmt->execute()) {
        audit_log('update', 'contact', $id, 'Customer updated');
        flash_set('success', 'Customer updated successfully.');
        redirect('customer-view.php?id=' . $id);
    }
    $error = 'Could not update the customer.';
}

require_once 'navbar.php';
require_once 'sidebar.php';
?>

<div class="page-head">
    <div>
        <h1 class="page-title">Edit Customer</h1>
        <div class="page-sub"><?php echo e($customer['first_name'] . ' ' . $customer['last_name']); ?></div>
    </div>
    <a href="customers.php? id=<?php echo $id; ?>" class="btn btn-light"><i class="fa fa-arrow-left mr-1"></i> Back</a>
</div>

<div class="row">
    <div class="col-lg-9">
        <div class="card">
            <div class="card-body">
                <?php if ($error): ?><div class="alert alert-danger"><?php echo e($error); ?></div><?php endif; ?>
                <form method="POST" action="customer-edit.php?id=<?php echo $id; ?>">
                    <div class="row">
                        <div class="col-md-6 form-group"><label>First Name</label><input type="text" name="first_name" class="form-control" value="<?php echo e($customer['first_name']); ?>"></div>
                        <div class="col-md-6 form-group"><label>Last Name</label><input type="text" name="last_name" class="form-control" value="<?php echo e($customer['last_name']); ?>"></div>
                        <div class="col-md-6 form-group"><label>Company Name</label><input type="text" name="company_name" class="form-control" value="<?php echo e($customer['company_name']); ?>"></div>
                        <div class="col-md-6 form-group"><label>Email</label><input type="email" name="email" class="form-control" value="<?php echo e($customer['email']); ?>"></div>
                        <div class="col-md-6 form-group"><label>Phone</label><input type="text" name="phone" class="form-control" value="<?php echo e($customer['phone']); ?>"></div>
                        <div class="col-md-6 form-group"><label>Mobile</label><input type="text" name="mobile" class="form-control" value="<?php echo e($customer['mobile']); ?>"></div>
                        <div class="col-md-12 form-group"><label>Address</label><input type="text" name="address" class="form-control" value="<?php echo e($customer['address']); ?>"></div>
                        <div class="col-md-6 form-group">
                            <label>Customer Channel</label>
                            <select name="customer_channel" class="form-control">
                                <?php foreach (customer_channel_options() as $channel_key => $channel_label): ?>
                                    <option value="<?php echo e($channel_key); ?>" <?php echo ($customer['customer_channel'] ?? 'walk_in') === $channel_key ? 'selected' : ''; ?>><?php echo e($channel_label); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6 form-group">
                            <label>Customer Type</label>
                            <input type="text" class="form-control" readonly value="<?php echo $has_wholesale_account ? 'Wholesale customer' : 'Retail customer'; ?>">
                            <small class="form-text text-muted">
                                <?php echo $has_wholesale_account
                                    ? 'This contact is linked to a wholesale portal account.'
                                    : 'Manual customers are retail. Wholesale customers are registered from Customers → Wholesalers.'; ?>
                            </small>
                        </div>
                        <div class="col-md-4 form-group"><label>City</label><input type="text" name="city" class="form-control" value="<?php echo e($customer['city']); ?>"></div>
                        <div class="col-md-4 form-group"><label>State / Region</label><input type="text" name="state" class="form-control" value="<?php echo e($customer['state']); ?>"></div>
                        <div class="col-md-2 form-group"><label>Postal Code</label><input type="text" name="postal_code" class="form-control" value="<?php echo e($customer['postal_code']); ?>"></div>
                        <div class="col-md-2 form-group"><label>Country</label><input type="text" name="country" class="form-control" value="<?php echo e($customer['country']); ?>"></div>
                        <div class="col-md-6 form-group"><label>Tax ID</label><input type="text" name="tax_id" class="form-control" value="<?php echo e($customer['tax_id']); ?>"></div>
                        <div class="col-md-12 form-group"><label>Notes</label><textarea name="notes" class="form-control" rows="2"><?php echo e($customer['notes']); ?></textarea></div>
                    </div>
                    <button type="submit" class="btn btn-primary"><i class="fa fa-check mr-1"></i> Save Changes</button>
                </form>
            </div>
        </div>
    </div>
</div>

<?php require_once 'footer.php'; ?>
