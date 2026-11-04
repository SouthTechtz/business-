<?php
require_once 'header.php';
require_once 'auth_check.php';
require_permission('manage_contacts');
require_once 'wholesale.php';
$page_title = 'Customers';

$tab = (string)($_GET['tab'] ?? 'customers') === 'wholesalers' ? 'wholesalers' : 'customers';
if ($tab === 'wholesalers' && !is_system_administrator()) {
    $tab = 'customers';
}

// Wholesale account actions live in the Wholesalers tab.
if ($tab === 'wholesalers' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = (string)($_POST['action'] ?? '');

    if ($action === 'add_wholesaler') {
        $name = trim((string)($_POST['name'] ?? ''));
        $phone = trim((string)($_POST['phone'] ?? ''));
        $location = trim((string)($_POST['location'] ?? ''));
        $email = trim((string)($_POST['email'] ?? ''));
        $password = (string)($_POST['password'] ?? '');
        $password_confirm = (string)($_POST['password_confirm'] ?? '');

        $add_error = '';
        if ($name === '') {
            $add_error = 'Enter the customer name or business name.';
        } elseif (mb_strlen($name) > 191) {
            $add_error = 'The name is too long.';
        } elseif ($location === '') {
            $add_error = 'Enter the town, district or delivery area.';
        } elseif (strlen(preg_replace('/\D+/', '', $phone)) < 7) {
            $add_error = 'Enter a valid phone number.';
        } elseif ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $add_error = 'Enter a valid email address, or leave it empty.';
        } elseif (strlen($password) < 8) {
            $add_error = 'The password must be at least 8 characters.';
        } elseif ($password !== $password_confirm) {
            $add_error = 'The passwords do not match.';
        } elseif (wholesale_phone_in_use($current_user->tenant_id, $phone)) {
            $add_error = 'That phone number is already used by a customer account.';
        } elseif ($email !== '' && fetch_one(
            "SELECT user_id FROM users WHERE tenant_id = ? AND email = ? LIMIT 1",
            $current_user->tenant_id,
            $email
        )) {
            $add_error = 'That email address is already used by another account.';
        }

        if ($add_error !== '') {
            flash_set('danger', $add_error);
            redirect('customers.php?tab=wholesalers&add=1');
        }

        try {
            $created = create_wholesale_account($current_user->tenant_id, [
                'name'     => $name,
                'phone'    => $phone,
                'location' => $location,
                'email'    => $email,
                'password' => $password,
            ]);
            audit_log('create', 'wholesaler', (int)$created['wholesaler_id'], 'Wholesale customer registered by staff: ' . $name);
            flash_set('success', 'Wholesale customer created. Username: ' . $created['username']);
            redirect('customers.php?tab=wholesalers');
        } catch (Throwable $exception) {
            error_log('Admin wholesale creation failed: ' . $exception->getMessage());
            flash_set('danger', 'Could not create the wholesale customer right now.');
            redirect('customers.php?tab=wholesalers&add=1');
        }
    }

    $wholesaler_id = (int)($_POST['wholesaler_id'] ?? 0);
    $account = $wholesaler_id > 0 ? wholesaler_account_by_id($wholesaler_id, $current_user->tenant_id) : null;

    if (!$account) {
        flash_set('danger', 'Wholesale account not found.');
        redirect('customers.php?tab=wholesalers');
    }

    $new_status = $action === 'suspend' ? 'suspended' : ($action === 'activate' ? 'active' : '');
    if ($new_status === '') {
        flash_set('danger', 'Unknown account action.');
        redirect('customers.php?tab=wholesalers');
    }

    $conn->begin_transaction();
    try {
        $stmt = $conn->prepare("UPDATE wholesaler_accounts SET status = ? WHERE wholesaler_id = ? AND tenant_id = ?");
        $stmt->bind_param('sii', $new_status, $wholesaler_id, $current_user->tenant_id);
        $stmt->execute();
        $stmt->close();

        $active = $new_status === 'active' ? 1 : 0;
        $stmt = $conn->prepare("UPDATE users SET is_active = ? WHERE user_id = ? AND tenant_id = ?");
        $stmt->bind_param('iii', $active, $account['user_id'], $current_user->tenant_id);
        $stmt->execute();
        $stmt->close();

        $conn->commit();
        audit_log(
            $new_status === 'active' ? 'activate' : 'suspend',
            'wholesaler',
            $wholesaler_id,
            'Wholesale account ' . ($new_status === 'active' ? 'activated' : 'suspended') . ': ' . $account['business_name']
        );
        flash_set('success', 'Wholesale account ' . ($new_status === 'active' ? 'activated' : 'suspended') . '.');
    } catch (Throwable $exception) {
        $conn->rollback();
        flash_set('danger', 'Could not update the wholesale account.');
    }
    redirect('customers.php?tab=wholesalers');
}

// Delete customer
if (isset($_GET['delete']) && is_numeric($_GET['delete'])) {
    if (!tenant_admin_scope()) {
        flash_set('danger', 'Only tenant administrators can delete shared customer records.');
        redirect('customers.php');
    }
    $id = (int)$_GET['delete'];
    $conn->begin_transaction();
    try {
        $pmt = fetch_one(
            "SELECT COUNT(*) c FROM payments p
             JOIN invoices i ON i.invoice_id = p.invoice_id
             WHERE p.contact_id = ? AND i.tenant_id = ?",
            $id, $current_user->tenant_id
        );
        $deals = fetch_one("SELECT COUNT(*) c FROM deals WHERE contact_id = ? AND tenant_id = ?", $id, $current_user->tenant_id);
        $order_count = fetch_one("SELECT COUNT(*) c FROM orders WHERE contact_id = ? AND tenant_id = ?", $id, $current_user->tenant_id);
        $lead_count = fetch_one("SELECT COUNT(*) c FROM leads WHERE contact_id = ? AND tenant_id = ?", $id, $current_user->tenant_id);
        if (($pmt['c'] ?? 0) > 0 || ($deals['c'] ?? 0) > 0) {
            throw new Exception('This customer cannot be deleted because they have payments or deals.');
        }
        if ((int)($order_count['c'] ?? 0) > 0 || (int)($lead_count['c'] ?? 0) > 0) {
            throw new Exception('This customer has order or lead history and cannot be deleted.');
        }
        $inv_count = fetch_one(
            "SELECT COUNT(*) c FROM invoices WHERE contact_id = ? AND tenant_id = ?",
            $id,
            $current_user->tenant_id
        );
        if ((int)($inv_count['c'] ?? 0) > 0) {
            throw new Exception('This customer has invoice history and cannot be deleted.');
        }
        $stmt = $conn->prepare("DELETE FROM contacts WHERE contact_id = ? AND tenant_id = ?");
        $stmt->bind_param('ii', $id, $current_user->tenant_id);
        $stmt->execute();
        $conn->commit();
        flash_set('success', 'Customer deleted successfully.');
    } catch (Exception $ex) {
        $conn->rollback();
        flash_set('danger', $ex->getMessage());
    }
    redirect('customers.php');
}

$invoice_scope = '';
$scope_ids = accessible_warehouse_ids();
if ($scope_ids !== null) {
    $invoice_scope = $scope_ids
        ? " AND i.warehouse_id IN (" . implode(',', array_map('intval', $scope_ids)) . ")"
        : " AND 1 = 0";
}
$filter_q = trim((string)($_GET['q'] ?? ''));
if (mb_strlen($filter_q) > 100) $filter_q = mb_substr($filter_q, 0, 100);
$filter_channel = strtolower(trim((string)($_GET['channel'] ?? '')));
if (!array_key_exists($filter_channel, customer_channel_options())) $filter_channel = '';
$cust_status = strtolower(trim((string)($_GET['cust_status'] ?? '')));
if (!in_array($cust_status, ['active', 'inactive'], true)) $cust_status = '';

$customer_where = "c.tenant_id = ? AND c.is_customer = 1 AND c.customer_type = 'retail'";
$customer_params = [$current_user->tenant_id];
if ($filter_q !== '') {
    $like = '%' . $filter_q . '%';
    $customer_where .= " AND (c.first_name LIKE ? OR c.last_name LIKE ? OR c.company_name LIKE ? OR c.phone LIKE ? OR c.mobile LIKE ? OR c.email LIKE ?)";
    array_push($customer_params, $like, $like, $like, $like, $like, $like);
}
if ($filter_channel !== '') {
    $customer_where .= " AND c.customer_channel = ?";
    $customer_params[] = $filter_channel;
}
if ($cust_status !== '') {
    $customer_where .= " AND c.is_active = ?";
    $customer_params[] = $cust_status === 'active' ? 1 : 0;
}

$customers = fetch_all(
    "SELECT c.*,
        (SELECT COUNT(*) FROM invoices i WHERE i.contact_id = c.contact_id AND i.tenant_id = ?{$invoice_scope}) invoice_count,
        (SELECT COALESCE(SUM(i.total), 0) FROM invoices i WHERE i.contact_id = c.contact_id AND i.tenant_id = ?{$invoice_scope}) total_spent
     FROM contacts c
     WHERE {$customer_where}
     ORDER BY c.created_at DESC",
    $current_user->tenant_id, $current_user->tenant_id, ...$customer_params
);

$ws_status = strtolower(trim((string)($_GET['status'] ?? '')));
if (!in_array($ws_status, ['active', 'suspended'], true)) $ws_status = '';
$ws_channel = strtolower(trim((string)($_GET['ws_channel'] ?? '')));
if (!array_key_exists($ws_channel, customer_channel_options())) $ws_channel = '';
$ws_query = trim((string)($_GET['ws_q'] ?? ''));
$ws_accounts = ($tab === 'wholesalers' && is_system_administrator())
    ? wholesale_admin_accounts($current_user->tenant_id, $ws_status, $ws_query, $ws_channel)
    : [];

require_once 'navbar.php';
require_once 'sidebar.php';
?>

<div class="page-head">
    <div>
        <h1 class="page-title">Customers</h1>
        <div class="page-sub">
            <?php if ($tab === 'wholesalers'): ?>
                <?php echo count($ws_accounts); ?> wholesale account(s).
            <?php else: ?>
                <?php echo count($customers); ?> customer(s) registered.
            <?php endif; ?>
        </div>
    </div>
    <?php if ($tab === 'customers'): ?>
        <a href="customer-add.php" class="btn btn-primary"><i class="fa fa-plus mr-1"></i> Add Customer</a>
    <?php else: ?>
        <button type="button" class="btn btn-primary" data-toggle="modal" data-target="#addWholesalerModal">
            <i class="fa fa-plus mr-1"></i> Add Wholesaler
        </button>
    <?php endif; ?>
</div>

<?php if ($f = flash_get()): ?>
    <div class="alert alert-<?php echo e($f['type']); ?>"><?php echo e($f['message']); ?></div>
<?php endif; ?>

<ul class="nav nav-tabs page-tabs mb-3" role="tablist">
    <li class="nav-item">
        <a class="nav-link <?php echo $tab === 'customers' ? 'active' : ''; ?>" href="customers.php">
            <i class="fa fa-address-book mr-1"></i> Customers
        </a>
    </li>
    <?php if (is_system_administrator()): ?>
    <li class="nav-item">
        <a class="nav-link <?php echo $tab === 'wholesalers' ? 'active' : ''; ?>" href="customers.php?tab=wholesalers">
            <i class="fa fa-briefcase mr-1"></i> Wholesalers
        </a>
    </li>
    <?php endif; ?>
</ul>

<?php if ($tab === 'customers'): ?>

<div class="card mb-3">
    <div class="card-body py-3">
        <form method="get" action="customers.php" class="form-inline" style="gap:12px;flex-wrap:wrap;">
            <div class="input-group input-group-sm mr-2" style="min-width:240px;">
                <div class="input-group-prepend"><span class="input-group-text"><i class="fa fa-search"></i></span></div>
                <input type="text" name="q" class="form-control" value="<?php echo e($filter_q); ?>" placeholder="Name, company, phone or email">
            </div>
            <div class="form-group mr-3 mb-0">
                <label class="mr-2 small font-weight-bold">Channel</label>
                <select name="channel" class="form-control form-control-sm">
                    <option value="">All channels</option>
                    <?php foreach (customer_channel_options() as $channel_key => $channel_label): ?>
                        <option value="<?php echo e($channel_key); ?>" <?php echo $filter_channel === $channel_key ? 'selected' : ''; ?>><?php echo e($channel_label); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group mr-3 mb-0">
                <label class="mr-2 small font-weight-bold">Status</label>
                <select name="cust_status" class="form-control form-control-sm">
                    <option value="">All statuses</option>
                    <option value="active" <?php echo $cust_status === 'active' ? 'selected' : ''; ?>>Active</option>
                    <option value="inactive" <?php echo $cust_status === 'inactive' ? 'selected' : ''; ?>>Inactive</option>
                </select>
            </div>
            <button type="submit" class="btn btn-sm btn-primary mr-2"><i class="fa fa-filter mr-1"></i> Apply</button>
            <?php if ($filter_q !== '' || $filter_channel !== '' || $cust_status !== ''): ?>
                <a href="customers.php" class="btn btn-sm btn-light">Reset</a>
            <?php endif; ?>
        </form>
    </div>
</div>

<div class="card">
    <div class="card-header d-flex justify-content-between align-items-center">
        <h5 class="card-title">All Customers</h5>
        <div class="input-group dt-search">
            <div class="input-group-prepend"><span class="input-group-text"><i class="fa fa-search"></i></span></div>
            <input type="text" class="form-control dt-search-input" data-scope="customers" placeholder="Search customers...">
        </div>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover data-table">
                <thead>
                    <tr>
                        <th>Customer #</th><th>Name</th><th>Company</th><th>Email</th><th>Phone</th><th>Location</th><th>Type</th><th>Channel</th><th>Invoices</th><th>Total Spent</th><th class="text-right">Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($customers as $row): ?>
                    <tr>
                        <td><?php echo e($row['customer_number'] ?: '—'); ?></td>
                        <td>
                            <div class="d-flex align-items-center">
                                <span class="avatar mr-2" style="background:var(--purple)"><?php echo e(strtoupper(substr($row['first_name'] ?? $row['company_name'] ?? '?', 0, 1))); ?></span>
                                <?php echo e(trim(($row['first_name'] ?? '') . ' ' . ($row['last_name'] ?? '')) ?: $row['company_name'] ?: 'Unnamed'); ?>
                            </div>
                        </td>
                        <td><?php echo e($row['company_name'] ?: '—'); ?></td>
                        <td><?php echo e($row['email'] ?: '—'); ?></td>
                        <td><?php echo e($row['phone'] ?: '—'); ?></td>
                        <td><?php echo e($row['address'] ?: ($row['city'] ?: '—')); ?></td>
                        <td>
                            <span class="badge <?php echo strtolower((string)($row['customer_type'] ?? 'retail')) === 'wholesale' ? 'badge-purple' : 'badge-secondary'; ?>">
                                <?php echo strtolower((string)($row['customer_type'] ?? 'retail')) === 'wholesale' ? 'Wholesale' : 'Retail'; ?>
                            </span>
                        </td>
                        <td>
                            <span class="badge <?php echo strtolower((string)($row['customer_channel'] ?? 'walk_in')) === 'online' ? 'badge-info' : 'badge-light border'; ?>">
                                <?php echo strtolower((string)($row['customer_channel'] ?? 'walk_in')) === 'online' ? 'Online' : 'Walk-in'; ?>
                            </span>
                        </td>
                        <td><span class="badge badge-primary"><?php echo (int)$row['invoice_count']; ?></span></td>
                        <td><?php echo e(money($row['total_spent'], $cur = tenant_currency())); ?></td>
                        <td class="text-right">
                            <a href="customer-view.php?id=<?php echo $row['contact_id']; ?>" class="btn btn-action btn-light" title="View"><i class="fa fa-eye"></i></a>
                            <a href="customer-edit.php?id=<?php echo $row['contact_id']; ?>" class="btn btn-action btn-light" title="Edit"><i class="fa fa-pencil"></i></a>
                            <?php if (tenant_admin_scope()): ?>
                                <a href="customers.php?delete=<?php echo $row['contact_id']; ?>" class="btn btn-action btn-light text-danger" title="Delete" onclick="return confirm('Delete this customer and their invoices?');"><i class="fa fa-trash"></i></a>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$customers): ?>
                    <tr><td colspan="11" class="text-center text-muted py-4">No customers match this filter. <a href="customers.php">Clear filters</a> or <a href="customer-add.php">add a customer</a>.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php else: ?>

<div class="card mb-3">
    <div class="card-body py-3">
        <form method="get" action="customers.php" class="form-inline" style="gap:10px;flex-wrap:wrap;">
            <input type="hidden" name="tab" value="wholesalers">
            <div class="input-group input-group-sm mr-2" style="min-width:240px;">
                <div class="input-group-prepend"><span class="input-group-text"><i class="fa fa-search"></i></span></div>
                <input type="text" name="ws_q" class="form-control" value="<?php echo e($ws_query); ?>" placeholder="Business, contact, phone, email">
            </div>
            <select name="ws_channel" class="form-control form-control-sm mr-2">
                <option value="">All channels</option>
                <option value="online" <?php echo $ws_channel === 'online' ? 'selected' : ''; ?>>Online</option>
                <option value="walk_in" <?php echo $ws_channel === 'walk_in' ? 'selected' : ''; ?>>Walk-in</option>
            </select>
            <select name="status" class="form-control form-control-sm mr-2">
                <option value="">All statuses</option>
                <option value="active" <?php echo $ws_status === 'active' ? 'selected' : ''; ?>>Active</option>
                <option value="suspended" <?php echo $ws_status === 'suspended' ? 'selected' : ''; ?>>Suspended</option>
            </select>
            <button type="submit" class="btn btn-sm btn-primary mr-2"><i class="fa fa-filter mr-1"></i> Filter</button>
            <?php if ($ws_query !== '' || $ws_status !== '' || $ws_channel !== ''): ?>
                <a href="customers.php?tab=wholesalers" class="btn btn-sm btn-light">Reset</a>
            <?php endif; ?>
        </form>
    </div>
</div>

<div class="card">
    <div class="card-header"><h5 class="card-title">Wholesale accounts</h5></div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead>
                    <tr>
                        <th>Business</th>
                        <th>Contact</th>
                        <th>Phone</th>
                        <th>Channel</th>
                        <th class="text-center">Requests</th>
                        <th class="text-center">Orders</th>
                        <th>Status</th>
                        <th>Created</th>
                        <th class="text-right">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($ws_accounts as $account): ?>
                        <tr>
                            <td>
                                <strong><?php echo e($account['business_name']); ?></strong><br>
                                <small class="text-muted"><?php echo e($account['username']); ?></small>
                            </td>
                            <td>
                                <?php echo e($account['contact_name']); ?><br>
                                <small class="text-muted"><?php echo e($account['email'] ?: '—'); ?></small>
                            </td>
                            <td><?php echo e($account['phone'] ?: '—'); ?></td>
                            <td>
                                <span class="badge <?php echo strtolower((string)$account['customer_channel']) === 'online' ? 'badge-info' : 'badge-secondary'; ?>">
                                    <?php echo strtolower((string)$account['customer_channel']) === 'online' ? 'Online' : 'Walk-in'; ?>
                                </span>
                            </td>
                            <td class="text-center"><?php echo (int)$account['request_count']; ?></td>
                            <td class="text-center"><?php echo (int)$account['order_count']; ?></td>
                            <td>
                                <span class="badge <?php echo strtolower((string)$account['status']) === 'active' ? 'badge-success' : 'badge-danger'; ?>">
                                    <?php echo strtolower((string)$account['status']) === 'active' ? 'Active' : 'Suspended'; ?>
                                </span>
                            </td>
                            <td><?php echo e(date('d M Y', strtotime($account['created_at']))); ?></td>
                            <td class="text-right">
                                <a href="wholesaler-view.php?id=<?php echo (int)$account['wholesaler_id']; ?>" class="btn btn-action btn-light" title="View"><i class="fa fa-eye"></i></a>
                                <form method="post" action="customers.php?tab=wholesalers" class="d-inline" onsubmit="return confirm('<?php echo strtolower((string)$account['status']) === 'active' ? 'Suspend this wholesale account?' : 'Activate this wholesale account?'; ?>');">
                                    <?php echo csrf_field(); ?>
                                    <input type="hidden" name="wholesaler_id" value="<?php echo (int)$account['wholesaler_id']; ?>">
                                    <input type="hidden" name="action" value="<?php echo strtolower((string)$account['status']) === 'active' ? 'suspend' : 'activate'; ?>">
                                    <button type="submit" class="btn btn-action btn-light <?php echo strtolower((string)$account['status']) === 'active' ? 'text-danger' : 'text-success'; ?>" title="<?php echo strtolower((string)$account['status']) === 'active' ? 'Suspend' : 'Activate'; ?>">
                                        <i class="fa <?php echo strtolower((string)$account['status']) === 'active' ? 'fa-ban' : 'fa-check'; ?>"></i>
                                    </button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$ws_accounts): ?>
                        <tr><td colspan="9" class="text-center text-muted py-4">No wholesale accounts match this filter.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<div class="modal fade" id="addWholesalerModal" tabindex="-1" role="dialog" aria-labelledby="addWholesalerModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg" role="document">
        <div class="modal-content">
            <form method="post" action="customers.php?tab=wholesalers">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="action" value="add_wholesaler">
                <div class="modal-header">
                    <h5 class="modal-title" id="addWholesalerModalLabel"><i class="fa fa-briefcase mr-1"></i> Add Wholesaler</h5>
                    <button type="button" class="close" data-dismiss="modal" aria-label="Close"><span aria-hidden="true">&times;</span></button>
                </div>
                <div class="modal-body">
                    <p class="text-muted small">Create a simple wholesale account. Only name, phone and location are required; email is optional.</p>
                    <div class="row">
                        <div class="col-md-12 form-group">
                            <label>Name or business name <span class="text-danger">*</span></label>
                            <input type="text" name="name" class="form-control" maxlength="191" required>
                        </div>
                        <div class="col-md-6 form-group">
                            <label>Phone number <span class="text-danger">*</span></label>
                            <input type="tel" name="phone" class="form-control" maxlength="50" required>
                        </div>
                        <div class="col-md-6 form-group">
                            <label>Location <span class="text-danger">*</span></label>
                            <input type="text" name="location" class="form-control" maxlength="255" required>
                        </div>
                        <div class="col-md-12 form-group">
                            <label>Email <small class="text-muted">(optional)</small></label>
                            <input type="email" name="email" class="form-control" maxlength="191">
                        </div>
                        <div class="col-md-6 form-group">
                            <label>Password <span class="text-danger">*</span></label>
                            <input type="password" name="password" class="form-control" minlength="8" required autocomplete="new-password">
                        </div>
                        <div class="col-md-6 form-group">
                            <label>Confirm password <span class="text-danger">*</span></label>
                            <input type="password" name="password_confirm" class="form-control" minlength="8" required autocomplete="new-password">
                        </div>
                    </div>
                    <small class="text-muted">The customer can sign in with their name or phone number plus this password.</small>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-light" data-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary"><i class="fa fa-check mr-1"></i> Create wholesale customer</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
    $(function () {
        if (new URLSearchParams(window.location.search).get('add') === '1') {
            $('#addWholesalerModal').modal('show');
        }
    });
</script>

<?php endif; ?>

<?php require_once 'footer.php'; ?>
