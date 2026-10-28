<?php
require_once 'header.php';
require_once 'auth_check.php';
$page_title = 'Customer Details';

$id = (int)($_GET['id'] ?? 0);
$customer = fetch_one("SELECT * FROM contacts WHERE contact_id = ? AND tenant_id = ?", $id, $current_user->tenant_id);
if (!$customer) {
    flash_set('danger', 'Customer not found.');
    redirect('customers.php');
}

$scope_ids = accessible_warehouse_ids();
$invoice_scope = '';
$order_scope = '';
$payment_sub_scope = '';
if ($scope_ids !== null) {
    $invoice_scope = $scope_ids ? " AND i.warehouse_id IN (" . implode(',', array_map('intval', $scope_ids)) . ")" : " AND 1 = 0";
    $order_scope = $scope_ids ? " AND warehouse_id IN (" . implode(',', array_map('intval', $scope_ids)) . ")" : " AND 1 = 0";
    $payment_sub_scope = $scope_ids ? " AND pi.warehouse_id IN (" . implode(',', array_map('intval', $scope_ids)) . ")" : " AND 1 = 0";
}

$invoices = fetch_all(
    "SELECT i.*, ds.name status_name FROM invoices i
     LEFT JOIN document_statuses ds ON ds.status_id = i.status_id
     WHERE i.contact_id = ? AND i.tenant_id = ?{$invoice_scope} ORDER BY i.issue_date DESC",
    $id, $current_user->tenant_id
);

$payments = fetch_all(
    "SELECT p.*, i.invoice_number, pm.name method_name
     FROM payments p
     JOIN invoices i ON i.invoice_id = p.invoice_id
     LEFT JOIN payment_methods pm ON pm.payment_method_id = p.payment_method_id
     WHERE p.contact_id = ? AND p.tenant_id = ?{$invoice_scope}
     ORDER BY p.payment_date DESC",
    $id, $current_user->tenant_id
);

$orders = fetch_all(
    "SELECT * FROM pos_orders WHERE contact_id = ? AND tenant_id = ?{$order_scope} ORDER BY order_date DESC LIMIT 10",
    $id, $current_user->tenant_id
);

$customer_orders = [];
if (can('manage_orders')) {
    [$cust_order_scope, $cust_order_params] = order_visibility_clause('o');
    $customer_orders = fetch_all(
        "SELECT o.order_id, o.order_number, o.status, o.total, o.currency_code, o.created_at, s.name shop_name
         FROM orders o
         LEFT JOIN shops s ON s.shop_id = o.shop_id AND s.tenant_id = o.tenant_id
         WHERE o.contact_id = ? AND o.tenant_id = ?{$cust_order_scope}
         ORDER BY o.created_at DESC LIMIT 10",
        ...array_merge([$id, (int)$current_user->tenant_id], $cust_order_params)
    );
}

$customer_leads = [];
if (can('manage_leads')) {
    $customer_leads = fetch_all(
        "SELECT lead_id, lead_number, status, next_follow_up_at, created_at
         FROM leads WHERE contact_id = ? AND tenant_id = ? ORDER BY created_at DESC LIMIT 10",
        $id,
        $current_user->tenant_id
    );
}

$totals = fetch_one(
    "SELECT COUNT(i.invoice_id) invoice_count,
            COALESCE(SUM(i.total), 0) total_billed,
            COALESCE(SUM(i.amount_due), 0) total_due,
            COALESCE((SELECT SUM(p.amount) FROM payments p
                      JOIN invoices pi ON pi.invoice_id = p.invoice_id
                      WHERE p.contact_id = ? AND p.tenant_id = ?{$payment_sub_scope}), 0) total_paid
     FROM invoices i WHERE i.contact_id = ? AND i.tenant_id = ?{$invoice_scope}",
    $id, $current_user->tenant_id, $id, $current_user->tenant_id
);

require_once 'navbar.php';
require_once 'sidebar.php';
$cur = tenant_currency();
?>

<div class="page-head">
    <div>
        <h1 class="page-title">Customer Details</h1>
        <div class="page-sub"><?php echo e(trim(($customer['first_name'] ?? '') . ' ' . ($customer['last_name'] ?? '')) ?: $customer['company_name'] ?: 'Unnamed'); ?></div>
    </div>
    <div>
        <?php if (can('manage_contacts')): ?>
            <a href="customer-edit.php?id=<?php echo $id; ?>" class="btn btn-outline-primary"><i class="fa fa-pencil mr-1"></i> Edit</a>
        <?php endif; ?>
        <a href="customers.php" class="btn btn-light"><i class="fa fa-arrow-left mr-1"></i> Back</a>
    </div>
</div>

<?php if ($f = flash_get()): ?>
    <div class="alert alert-<?php echo e($f['type']); ?>"><?php echo e($f['message']); ?></div>
<?php endif; ?>

<div class="row mb-3">
    <div class="col-md-6 col-xl-3 mb-3"><div class="widget"><div class="widget-icon wi-blue"><i class="fa fa-file-text"></i></div><div class="widget-info"><div class="widget-value"><?php echo (int)($totals['invoice_count'] ?? 0); ?></div><div class="widget-label">Invoices</div></div></div></div>
    <div class="col-md-6 col-xl-3 mb-3"><div class="widget"><div class="widget-icon wi-green"><i class="fa fa-money"></i></div><div class="widget-info"><div class="widget-value"><?php echo e(money($totals['total_billed'] ?? 0, $cur)); ?></div><div class="widget-label">Total Billed</div></div></div></div>
    <div class="col-md-6 col-xl-3 mb-3"><div class="widget"><div class="widget-icon wi-purple"><i class="fa fa-check-circle"></i></div><div class="widget-info"><div class="widget-value"><?php echo e(money($totals['total_paid'] ?? 0, $cur)); ?></div><div class="widget-label">Total Paid</div></div></div></div>
    <div class="col-md-6 col-xl-3 mb-3"><div class="widget"><div class="widget-icon wi-red"><i class="fa fa-exclamation-circle"></i></div><div class="widget-info"><div class="widget-value"><?php echo e(money($totals['total_due'] ?? 0, $cur)); ?></div><div class="widget-label">Balance Due</div></div></div></div>
</div>

<div class="row">
    <div class="col-lg-4 mb-3">
        <div class="card">
            <div class="card-header"><h5 class="card-title">Contact Information</h5></div>
            <div class="card-body">
                <table class="table">
                    <tbody>
                        <tr><th>Customer number</th><td><?php echo e($customer['customer_number'] ?: '—'); ?></td></tr>
                        <tr><th>Company</th><td><?php echo e($customer['company_name'] ?: '—'); ?></td></tr>
                        <tr><th>Email</th><td><?php echo e($customer['email'] ?: '—'); ?></td></tr>
                        <tr><th>Phone</th><td><?php echo e($customer['phone'] ?: '—'); ?></td></tr>
                        <tr><th>Mobile</th><td><?php echo e($customer['mobile'] ?: '—'); ?></td></tr>
                        <tr><th>Address</th><td><?php echo e($customer['address'] ?: '—'); ?></td></tr>
                        <tr><th>City</th><td><?php echo e($customer['city'] ?: '—'); ?></td></tr>
                        <tr><th>Country</th><td><?php echo e($customer['country'] ?: '—'); ?></td></tr>
                        <tr><th>Tax ID</th><td><?php echo e($customer['tax_id'] ?: '—'); ?></td></tr>
                        <tr><th>Notes</th><td><?php echo e($customer['notes'] ?: '—'); ?></td></tr>
                        <tr><th>Customer Since</th><td><?php echo e(date('M d, Y', strtotime($customer['created_at']))); ?></td></tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <div class="col-lg-8 mb-3">
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h5 class="card-title">Invoices</h5>
                <?php if (can('manage_invoices')): ?><a href="invoice-add.php?customer=<?php echo $id; ?>" class="btn btn-sm btn-outline-primary"><i class="fa fa-plus mr-1"></i> New Invoice</a><?php endif; ?>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover mb-0">
                        <thead><tr><th>Invoice #</th><th>Date</th><th>Due</th><th>Status</th><th class="text-right">Total</th><th class="text-right">Balance</th></tr></thead>
                        <tbody>
                        <?php if (!$invoices): ?>
                            <tr><td colspan="6" class="text-center text-muted py-4">No invoices yet.</td></tr>
                        <?php else: foreach ($invoices as $inv): ?>
                            <tr>
                                <td><a href="invoice-details.php?id=<?php echo $inv['invoice_id']; ?>"><?php echo e($inv['invoice_number']); ?></a></td>
                                <td><?php echo e(date('M d, Y', strtotime($inv['issue_date']))); ?></td>
                                <td><?php echo !empty($inv['due_date']) && $inv['due_date'] !== '0000-00-00' ? e(date('M d, Y', strtotime($inv['due_date']))) : '—'; ?></td>
                                <td><span class="badge badge-info"><?php echo e($inv['status_name'] ?? 'Unknown'); ?></span></td>
                                <td class="text-right"><?php echo e(money($inv['total'], $cur)); ?></td>
                                <td class="text-right"><?php echo e(money($inv['amount_due'], $cur)); ?></td>
                            </tr>
                        <?php endforeach; endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        <div class="card">
            <div class="card-header"><h5 class="card-title">Payments</h5></div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover mb-0">
                        <thead><tr><th>Date</th><th>Invoice</th><th>Method</th><th class="text-right">Amount</th></tr></thead>
                        <tbody>
                        <?php if (!$payments): ?>
                            <tr><td colspan="4" class="text-center text-muted py-4">No payments yet.</td></tr>
                        <?php else: foreach ($payments as $pmt): ?>
                            <tr>
                                <td><?php echo e(date('M d, Y H:i', strtotime($pmt['payment_date']))); ?></td>
                                <td><?php echo e($pmt['invoice_number']); ?></td>
                                <td><?php echo e($pmt['method_name'] ?? '—'); ?></td>
                                <td class="text-right"><?php echo e(money($pmt['amount'], $cur)); ?></td>
                            </tr>
                        <?php endforeach; endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        <?php if (can('manage_orders')): ?>
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h5 class="card-title">Orders</h5>
                <a href="order-add.php?contact_id=<?php echo $id; ?>" class="btn btn-sm btn-outline-primary"><i class="fa fa-plus mr-1"></i> New Order</a>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover mb-0">
                        <thead><tr><th>Order #</th><th>Date</th><th>Shop</th><th>Status</th><th class="text-right">Total</th></tr></thead>
                        <tbody>
                        <?php if (!$customer_orders): ?>
                            <tr><td colspan="5" class="text-center text-muted py-4">No orders yet.</td></tr>
                        <?php else: foreach ($customer_orders as $co): ?>
                            <tr>
                                <td><a href="order-view.php?id=<?php echo (int)$co['order_id']; ?>"><?php echo e($co['order_number']); ?></a></td>
                                <td><?php echo e(date('M d, Y', strtotime($co['created_at']))); ?></td>
                                <td><?php echo e($co['shop_name'] ?: '—'); ?></td>
                                <td><span class="badge badge-<?php echo e(order_status_class($co['status'])); ?>"><?php echo e(order_statuses()[$co['status']] ?? $co['status']); ?></span></td>
                                <td class="text-right"><?php echo e(money($co['total'], $co['currency_code'] ?: $cur)); ?></td>
                            </tr>
                        <?php endforeach; endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        <?php endif; ?>
        <?php if (can('manage_leads')): ?>
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h5 class="card-title">Leads</h5>
                <a href="lead-add.php?contact_id=<?php echo $id; ?>" class="btn btn-sm btn-outline-primary"><i class="fa fa-plus mr-1"></i> New Lead</a>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover mb-0">
                        <thead><tr><th>Lead #</th><th>Status</th><th>Next Follow-up</th><th>Created</th></tr></thead>
                        <tbody>
                        <?php if (!$customer_leads): ?>
                            <tr><td colspan="4" class="text-center text-muted py-4">No leads yet.</td></tr>
                        <?php else: foreach ($customer_leads as $cl): ?>
                            <tr>
                                <td><a href="lead-view.php?id=<?php echo (int)$cl['lead_id']; ?>"><?php echo e($cl['lead_number']); ?></a></td>
                                <td><span class="badge badge-<?php echo e(lead_status_class($cl['status'])); ?>"><?php echo e(lead_statuses()[$cl['status']] ?? $cl['status']); ?></span></td>
                                <td><?php echo $cl['next_follow_up_at'] ? e(date('M d, Y', strtotime($cl['next_follow_up_at']))) : '—'; ?></td>
                                <td><?php echo e(date('M d, Y', strtotime($cl['created_at']))); ?></td>
                            </tr>
                        <?php endforeach; endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        <?php endif; ?>
        <?php if (can('manage_pos') && $orders): ?>
        <div class="card">
            <div class="card-header"><h5 class="card-title">Recent Sales</h5></div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover mb-0">
                        <thead><tr><th>Order #</th><th>Date</th><th>Status</th><th class="text-right">Total</th></tr></thead>
                        <tbody>
                        <?php foreach ($orders as $ord): ?>
                            <tr>
                                <td><a href="sale-details.php?id=<?php echo $ord['order_id']; ?>"><?php echo e($ord['order_number']); ?></a></td>
                                <td><?php echo e(date('M d, Y H:i', strtotime($ord['order_date']))); ?></td>
                                <td><span class="badge badge-<?php echo $ord['status'] === 'completed' ? 'success' : ($ord['status'] === 'cancelled' ? 'danger' : 'warning'); ?>"><?php echo e($ord['status']); ?></span></td>
                                <td class="text-right"><?php echo e(money($ord['total'], $cur)); ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>

<?php require_once 'footer.php'; ?>
