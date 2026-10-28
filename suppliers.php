<?php
require_once 'header.php';
require_once 'auth_check.php';
require_permission('manage_suppliers');
$page_title = 'Suppliers';

$msg = '';

// Create
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add') {
    $data = [
        'tenant_id'    => $current_user->tenant_id,
        'company_name' => trim($_POST['company_name'] ?? ''),
        'first_name'   => trim($_POST['first_name'] ?? ''),
        'last_name'    => trim($_POST['last_name'] ?? ''),
        'email'        => trim($_POST['email'] ?? ''),
        'phone'        => trim($_POST['phone'] ?? ''),
        'address'      => trim($_POST['address'] ?? ''),
        'tax_id'       => trim($_POST['tax_id'] ?? ''),
    ];
    if ($data['company_name'] === '') {
        $msg = ['danger', 'Company name is required.'];
    } else {
        $dup = fetch_one("SELECT COUNT(*) c FROM suppliers WHERE tenant_id = ? AND email = ? AND email != ''", $current_user->tenant_id, $data['email']);
        if (($dup['c'] ?? 0) > 0) {
            $msg = ['danger', 'A supplier with this email already exists.'];
        } else {
            $id = db_insert('suppliers', $data);
            $msg = $id ? ['success', 'Supplier added.'] : ['danger', 'Could not add supplier.'];
        }
    }
}

// Edit
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'edit') {
    $id = (int)($_POST['supplier_id'] ?? 0);
    $fields = ['company_name', 'first_name', 'last_name', 'email', 'phone', 'address', 'tax_id'];
    $set = [];
    $vals = [];
    foreach ($fields as $f) {
        $set[] = "`$f` = ?";
        $vals[] = trim($_POST[$f] ?? '');
    }
    $vals[] = $id;
    $vals[] = $current_user->tenant_id;
    $stmt = $conn->prepare("UPDATE suppliers SET " . implode(', ', $set) . " WHERE supplier_id = ? AND tenant_id = ?");
    $stmt->bind_param(str_repeat('s', count($fields)) . 'ii', ...$vals);
    $msg = $stmt->execute() ? ['success', 'Supplier updated.'] : ['danger', 'Could not update supplier.'];
}

// Delete
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete') {
    $id = (int)($_POST['supplier_id'] ?? 0);
    $stmt = $conn->prepare("DELETE FROM suppliers WHERE supplier_id = ? AND tenant_id = ?");
    $stmt->bind_param('ii', $id, $current_user->tenant_id);
    $msg = $stmt->execute() ? ['success', 'Supplier deleted.'] : ['danger', 'Could not delete supplier.'];
}

$suppliers = fetch_all("SELECT * FROM suppliers WHERE tenant_id = ? ORDER BY company_name", $current_user->tenant_id);

require_once 'navbar.php';
require_once 'sidebar.php';
?>

<div class="page-head">
    <div>
        <h1 class="page-title">Suppliers</h1>
        <div class="page-sub"><?php echo count($suppliers); ?> supplier(s) registered.</div>
    </div>
</div>

<?php if ($msg): ?>
    <div class="alert alert-<?php echo e($msg[0]); ?>"><?php echo e($msg[1]); ?></div>
<?php endif; ?>

<div class="row">
    <div class="col-lg-8 mb-3">
        <div class="card">
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover data-table">
                        <thead>
                            <tr><th>Company</th><th>Contact Person</th><th>Email</th><th>Phone</th><th>Tax ID</th><th class="text-right">Actions</th></tr>
                        </thead>
                        <tbody>
                        <?php foreach ($suppliers as $s): ?>
                            <tr>
                                <td class="font-weight-semibold"><?php echo e($s['company_name']); ?></td>
                                <td><?php echo e(trim(($s['first_name'] ?? '') . ' ' . ($s['last_name'] ?? '')) ?: '—'); ?></td>
                                <td><?php echo e($s['email'] ?: '—'); ?></td>
                                <td><?php echo e($s['phone'] ?: '—'); ?></td>
                                <td><?php echo e($s['tax_id'] ?: '—'); ?></td>
                                <td class="text-right">
                                    <button class="btn btn-action btn-light" data-toggle="modal" data-target="#editModal<?php echo $s['supplier_id']; ?>"><i class="fa fa-pencil"></i></button>
                                    <form method="POST" action="suppliers.php" class="d-inline" onsubmit="return confirm('Delete this supplier?');">
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="supplier_id" value="<?php echo $s['supplier_id']; ?>">
                                        <button type="submit" class="btn btn-action btn-light text-danger"><i class="fa fa-trash"></i></button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (!$suppliers): ?>
                            <tr><td colspan="6" class="text-center text-muted py-4">No suppliers yet.</td></tr>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
    <div class="col-lg-4 mb-3">
        <div class="card">
            <div class="card-header"><h5 class="card-title">Add Supplier</h5></div>
            <div class="card-body">
                <form method="POST" action="suppliers.php">
                    <input type="hidden" name="action" value="add">
                    <div class="form-group"><label>Company Name <span class="text-danger">*</span></label><input type="text" name="company_name" class="form-control" required></div>
                    <div class="form-group"><label>Contact First Name</label><input type="text" name="first_name" class="form-control"></div>
                    <div class="form-group"><label>Contact Last Name</label><input type="text" name="last_name" class="form-control"></div>
                    <div class="form-group"><label>Email</label><input type="email" name="email" class="form-control"></div>
                    <div class="form-group"><label>Phone</label><input type="text" name="phone" class="form-control"></div>
                    <div class="form-group"><label>Address</label><textarea name="address" class="form-control" rows="2"></textarea></div>
                    <div class="form-group"><label>Tax ID</label><input type="text" name="tax_id" class="form-control"></div>
                    <button type="submit" class="btn btn-primary"><i class="fa fa-plus mr-1"></i> Add Supplier</button>
                </form>
            </div>
        </div>
    </div>
</div>

<?php foreach ($suppliers as $s): ?>
<div class="modal fade" id="editModal<?php echo $s['supplier_id']; ?>" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header"><h5 class="modal-title">Edit Supplier</h5><button type="button" class="close" data-dismiss="modal">&times;</button></div>
            <form method="POST" action="suppliers.php">
                <div class="modal-body">
                    <input type="hidden" name="action" value="edit">
                    <input type="hidden" name="supplier_id" value="<?php echo $s['supplier_id']; ?>">
                    <div class="form-group"><label>Company Name <span class="text-danger">*</span></label><input type="text" name="company_name" class="form-control" value="<?php echo e($s['company_name']); ?>" required></div>
                    <div class="form-group"><label>Contact First Name</label><input type="text" name="first_name" class="form-control" value="<?php echo e($s['first_name']); ?>"></div>
                    <div class="form-group"><label>Contact Last Name</label><input type="text" name="last_name" class="form-control" value="<?php echo e($s['last_name']); ?>"></div>
                    <div class="form-group"><label>Email</label><input type="email" name="email" class="form-control" value="<?php echo e($s['email']); ?>"></div>
                    <div class="form-group"><label>Phone</label><input type="text" name="phone" class="form-control" value="<?php echo e($s['phone']); ?>"></div>
                    <div class="form-group"><label>Address</label><textarea name="address" class="form-control" rows="2"><?php echo e($s['address']); ?></textarea></div>
                    <div class="form-group"><label>Tax ID</label><input type="text" name="tax_id" class="form-control" value="<?php echo e($s['tax_id']); ?>"></div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-light" data-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Save</button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endforeach; ?>

<?php require_once 'footer.php'; ?>
