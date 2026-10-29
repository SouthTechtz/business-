<?php
require_once 'header.php';
require_once 'auth_check.php';
require_permission('manage_contacts');
$page_title = 'Add Customer';

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name        = trim($_POST['name'] ?? '');
    $phone       = trim($_POST['phone'] ?? '');
    $location    = trim($_POST['location'] ?? '');
    $description = trim($_POST['description'] ?? '');

    if ($name === '') {
        $error = 'Name is required.';
    } elseif ($phone === '') {
        $error = 'Phone number is required.';
    } elseif ($location === '') {
        $error = 'Location is required.';
    } else {
        $data = [
            'tenant_id'       => $current_user->tenant_id,
            'contact_type_id' => 1,
            'first_name'      => $name,
            'phone'           => $phone,
            'address'         => $location,
            'notes'           => $description,
            'is_customer'     => 1,
            'is_active'       => 1,
        ];
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
                            <label>Name <span class="text-danger">*</span></label>
                            <input type="text" name="name" class="form-control" maxlength="100" value="<?php echo e($_POST['name'] ?? ''); ?>" required>
                        </div>
                        <div class="col-md-6 form-group">
                            <label>Phone Number <span class="text-danger">*</span></label>
                            <input type="text" name="phone" class="form-control" maxlength="50" value="<?php echo e($_POST['phone'] ?? ''); ?>" required>
                        </div>
                        <div class="col-md-12 form-group">
                            <label>Location <span class="text-danger">*</span></label>
                            <input type="text" name="location" class="form-control" value="<?php echo e($_POST['location'] ?? ''); ?>" required>
                        </div>
                        <div class="col-md-12 form-group">
                            <label>Description <small class="text-muted">(optional)</small></label>
                            <textarea name="description" class="form-control" rows="3"><?php echo e($_POST['description'] ?? ''); ?></textarea>
                        </div>
                    </div>
                    <button type="submit" class="btn btn-primary"><i class="fa fa-check mr-1"></i> Create Customer</button>
                </form>
            </div>
        </div>
    </div>
</div>

<?php require_once 'footer.php'; ?>
