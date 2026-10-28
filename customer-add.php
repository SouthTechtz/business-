<?php
require_once 'header.php';
require_once 'auth_check.php';
require_permission('manage_contacts');
$page_title = 'Add Customer';

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $data = [
        'tenant_id'      => $current_user->tenant_id,
        'contact_type_id'=> 1,
        'first_name'     => trim($_POST['first_name'] ?? ''),
        'last_name'      => trim($_POST['last_name'] ?? ''),
        'company_name'   => trim($_POST['company_name'] ?? ''),
        'email'          => trim($_POST['email'] ?? ''),
        'phone'          => trim($_POST['phone'] ?? ''),
        'mobile'         => trim($_POST['mobile'] ?? ''),
        'address'        => trim($_POST['address'] ?? ''),
        'city'           => trim($_POST['city'] ?? ''),
        'state'          => trim($_POST['state'] ?? ''),
        'postal_code'    => trim($_POST['postal_code'] ?? ''),
        'country'        => trim($_POST['country'] ?? ''),
        'tax_id'         => trim($_POST['tax_id'] ?? ''),
        'notes'          => trim($_POST['notes'] ?? ''),
        'is_customer'    => 1,
        'is_active'      => 1,
    ];
    if ($data['first_name'] === '' && $data['company_name'] === '') {
        $error = 'Either a name or company is required.';
    } else {
        $id = db_insert('contacts', $data);
        if (!$id) {
            $error = 'Could not create the customer. Please try again.';
        } else {
            audit_log('create', 'contact', $id, 'Customer created');
            flash_set('success', 'Customer created successfully.');
            redirect('customers.php');
        }
    }
}

require_once 'navbar.php';
require_once 'sidebar.php';
?>

<div class="page-head">
    <div>
        <h1 class="page-title">Add Customer</h1>
        <div class="page-sub">Register a new customer.</div>
    </div>
    <a href="customers.php" class="btn btn-light"><i class="fa fa-arrow-left mr-1"></i> Back to Customers</a>
</div>

<div class="row">
    <div class="col-lg-9">
        <div class="card">
            <div class="card-body">
                <?php if ($error): ?><div class="alert alert-danger"><?php echo e($error); ?></div><?php endif; ?>
                <form method="POST" action="customer-add.php">
                    <div class="row">
                        <div class="col-md-6 form-group">
                            <label>First Name</label>
                            <input type="text" name="first_name" class="form-control" value="<?php echo e($_POST['first_name'] ?? ''); ?>">
                        </div>
                        <div class="col-md-6 form-group">
                            <label>Last Name</label>
                            <input type="text" name="last_name" class="form-control" value="<?php echo e($_POST['last_name'] ?? ''); ?>">
                        </div>
                        <div class="col-md-6 form-group">
                            <label>Company Name</label>
                            <input type="text" name="company_name" class="form-control" value="<?php echo e($_POST['company_name'] ?? ''); ?>">
                        </div>
                        <div class="col-md-6 form-group">
                            <label>Email</label>
                            <input type="email" name="email" class="form-control" value="<?php echo e($_POST['email'] ?? ''); ?>">
                        </div>
                        <div class="col-md-6 form-group">
                            <label>Phone</label>
                            <input type="text" name="phone" class="form-control" value="<?php echo e($_POST['phone'] ?? ''); ?>">
                        </div>
                        <div class="col-md-6 form-group">
                            <label>Mobile</label>
                            <input type="text" name="mobile" class="form-control" value="<?php echo e($_POST['mobile'] ?? ''); ?>">
                        </div>
                        <div class="col-md-12 form-group">
                            <label>Address</label>
                            <input type="text" name="address" class="form-control" value="<?php echo e($_POST['address'] ?? ''); ?>">
                        </div>
                        <div class="col-md-4 form-group">
                            <label>City</label>
                            <input type="text" name="city" class="form-control" value="<?php echo e($_POST['city'] ?? ''); ?>">
                        </div>
                        <div class="col-md-4 form-group">
                            <label>State / Region</label>
                            <input type="text" name="state" class="form-control" value="<?php echo e($_POST['state'] ?? ''); ?>">
                        </div>
                        <div class="col-md-2 form-group">
                            <label>Postal Code</label>
                            <input type="text" name="postal_code" class="form-control" value="<?php echo e($_POST['postal_code'] ?? ''); ?>">
                        </div>
                        <div class="col-md-2 form-group">
                            <label>Country</label>
                            <input type="text" name="country" class="form-control" value="<?php echo e($_POST['country'] ?? ''); ?>">
                        </div>
                        <div class="col-md-6 form-group">
                            <label>Tax ID</label>
                            <input type="text" name="tax_id" class="form-control" value="<?php echo e($_POST['tax_id'] ?? ''); ?>">
                        </div>
                        <div class="col-md-12 form-group">
                            <label>Notes</label>
                            <textarea name="notes" class="form-control" rows="2"><?php echo e($_POST['notes'] ?? ''); ?></textarea>
                        </div>
                    </div>
                    <button type="submit" class="btn btn-primary"><i class="fa fa-check mr-1"></i> Create Customer</button>
                </form>
            </div>
        </div>
    </div>
</div>

<?php require_once 'footer.php'; ?>
