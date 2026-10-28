<?php
require_once 'header.php';
require_once 'auth_check.php';
require_permission('manage_leads');
$page_title = 'Lead Details';

$id = (int)($_GET['id'] ?? 0);
$lead = fetch_one(
    "SELECT l.*,
            ua.first_name assignee_first, ua.last_name assignee_last,
            uc.first_name creator_first, uc.last_name creator_last,
            c.first_name contact_first, c.last_name contact_last, c.company_name contact_company,
            c.customer_number contact_customer_number, c.phone contact_phone, c.mobile contact_mobile
     FROM leads l
     LEFT JOIN users ua ON ua.user_id = l.assigned_to
     LEFT JOIN users uc ON uc.user_id = l.created_by
     LEFT JOIN contacts c ON c.contact_id = l.contact_id AND c.tenant_id = l.tenant_id
     WHERE l.lead_id = ? AND l.tenant_id = ?",
    $id,
    $current_user->tenant_id
);
if (!$lead) {
    flash_set('danger', 'Lead not found.');
    redirect('leads.php');
}

$can_orders = can('manage_orders');
$storefront_request = fetch_one(
    "SELECT r.*, o.order_id, o.order_number, o.status order_status
       FROM storefront_lead_requests r
       LEFT JOIN orders o ON o.order_id = r.converted_order_id AND o.tenant_id = r.tenant_id
      WHERE r.lead_id = ? AND r.tenant_id = ?",
    $id,
    (int)$current_user->tenant_id
);
$storefront_request_items = $storefront_request
    ? fetch_all(
        "SELECT * FROM storefront_lead_request_items
          WHERE request_id = ? AND tenant_id = ? ORDER BY line_no",
        (int)$storefront_request['request_id'],
        (int)$current_user->tenant_id
    )
    : [];

$linked_orders = [];
$linkable_orders = [];
if ($can_orders) {
    [$scope_sql, $scope_params] = order_visibility_clause('o');

    $linked_orders = fetch_all(
        "SELECT o.order_id, o.order_number, o.customer_name, o.status, o.total, o.currency_code, o.created_at,
                s.name shop_name
         FROM orders o
         LEFT JOIN shops s ON s.shop_id = o.shop_id AND s.tenant_id = o.tenant_id
         WHERE o.tenant_id = ? AND o.lead_id = ?{$scope_sql}
         ORDER BY o.created_at DESC",
        ...array_merge([(int)$current_user->tenant_id, $id], $scope_params)
    );

    $linkable_orders = fetch_all(
        "SELECT o.order_id, o.order_number, o.customer_name, o.customer_phone, o.status, o.total, o.currency_code,
                s.name shop_name
         FROM orders o
         LEFT JOIN shops s ON s.shop_id = o.shop_id AND s.tenant_id = o.tenant_id
         WHERE o.tenant_id = ? AND (o.lead_id IS NULL OR o.lead_id = ?){$scope_sql}
         ORDER BY o.created_at DESC LIMIT 200",
        ...array_merge([(int)$current_user->tenant_id, $id], $scope_params)
    );
}

$activities = fetch_all(
    "SELECT a.*, u.first_name, u.last_name
     FROM activities a
     LEFT JOIN users u ON u.user_id = a.created_by
     WHERE a.tenant_id = ? AND a.lead_id = ?
     ORDER BY a.created_at DESC, a.activity_id DESC",
    $current_user->tenant_id,
    $id
);

$statuses = lead_statuses();
$transitions = lead_allowed_transitions($lead['status']);
if ($storefront_request) {
    $transitions = array_values(array_filter($transitions, function ($transition) {
        return !in_array($transition, ['verified', 'converted'], true);
    }));
}
$can_verify_request = $storefront_request
    && tenant_admin_scope()
    && $can_orders
    && !in_array($lead['status'], ['lost'], true);
$staff = users_with_permission('manage_leads');
$status = $lead['status'];

require_once 'navbar.php';
require_once 'sidebar.php';
?>

<div class="page-head">
    <div>
        <h1 class="page-title">Lead <?php echo e($lead['lead_number']); ?></h1>
        <div class="page-sub">
            <span class="badge badge-<?php echo e(lead_status_class($status)); ?>"><?php echo e($statuses[$status] ?? $status); ?></span>
            &middot; <?php echo e(source_label($lead['source'])); ?>
            &middot; created <?php echo e(date('M d, Y H:i', strtotime($lead['created_at']))); ?>
        </div>
    </div>
    <div>
        <?php if ($can_verify_request): ?>
            <a href="lead-verify.php?id=<?php echo $id; ?>" class="btn btn-success">
                <i class="fa fa-check mr-1"></i> Review &amp; Verify Request
            </a>
        <?php endif; ?>
        <?php if ($can_orders && !$storefront_request): ?>
        <a href="order-add.php?lead_id=<?php echo $id; ?><?php echo $lead['contact_id'] ? '&contact_id=' . (int)$lead['contact_id'] : ''; ?>" class="btn btn-primary">
            <i class="fa fa-shopping-cart mr-1"></i> Create Order
        </a>
        <?php endif; ?>
        <a href="lead-edit.php?id=<?php echo $id; ?>" class="btn btn-outline-primary"><i class="fa fa-pencil mr-1"></i> Edit</a>
        <a href="leads.php" class="btn btn-light"><i class="fa fa-arrow-left mr-1"></i> Back</a>
    </div>
</div>

<?php if ($f = flash_get()): ?>
    <div class="alert alert-<?php echo e($f['type']); ?>"><?php echo e($f['message']); ?></div>
<?php endif; ?>

<div class="row">
    <div class="col-lg-4 mb-3">
        <div class="card">
            <div class="card-header"><h5 class="card-title">Contact</h5></div>
            <div class="card-body">
                <table class="table mb-0">
                    <tbody>
                        <tr><th>Name</th><td><?php echo e($lead['contact_name']); ?></td></tr>
                        <tr><th>Company</th><td><?php echo e($lead['company_name'] ?: '—'); ?></td></tr>
                        <tr><th>Phone</th><td><?php echo e($lead['phone'] ?: ($lead['contact_phone'] ?: ($lead['contact_mobile'] ?: '—'))); ?></td></tr>
                        <tr><th>Email</th><td><?php echo e($lead['email'] ?: '—'); ?></td></tr>
                        <tr><th>Location</th><td><?php echo e($lead['location'] ?: '—'); ?></td></tr>
                        <tr>
                            <th>Customer</th>
                            <td>
                                <?php if ($lead['contact_id']): ?>
                                    <a href="customer-view.php?id=<?php echo (int)$lead['contact_id']; ?>">
                                        <?php echo e(trim(($lead['contact_first'] ?? '') . ' ' . ($lead['contact_last'] ?? '')) ?: ($lead['contact_company'] ?? 'Linked customer')); ?>
                                    </a>
                                    <?php if (!empty($lead['contact_customer_number'])): ?>
                                        <br><small class="text-muted"><?php echo e($lead['contact_customer_number']); ?></small>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <span class="text-muted">Not linked</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <tr><th>Assigned</th><td><?php echo e(trim(($lead['assignee_first'] ?? '') . ' ' . ($lead['assignee_last'] ?? '')) ?: 'Unassigned'); ?></td></tr>
                        <tr><th>Recorded by</th><td><?php echo e(trim(($lead['creator_first'] ?? '') . ' ' . ($lead['creator_last'] ?? '')) ?: '—'); ?></td></tr>
                        <tr><th>Last contacted</th><td><?php echo $lead['last_contacted_at'] ? e(date('M d, Y H:i', strtotime($lead['last_contacted_at']))) : '—'; ?></td></tr>
                        <tr><th>Next follow-up</th><td><?php echo $lead['next_follow_up_at'] ? e(date('M d, Y', strtotime($lead['next_follow_up_at']))) : '—'; ?></td></tr>
                        <tr><th>Converted</th><td><?php echo $lead['converted_at'] ? e(date('M d, Y H:i', strtotime($lead['converted_at']))) : '—'; ?></td></tr>
                        <?php if ($lead['lost_reason']): ?>
                            <tr><th>Lost reason</th><td><?php echo e($lead['lost_reason']); ?></td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
                <?php if (!empty($lead['notes'])): ?>
                    <hr>
                    <div class="small text-muted"><?php echo nl2br(e($lead['notes'])); ?></div>
                <?php endif; ?>
            </div>
        </div>

        <div class="card">
            <div class="card-header"><h5 class="card-title">Assignment</h5></div>
            <div class="card-body">
                <form method="POST" action="lead-action.php">
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="action" value="assign">
                    <input type="hidden" name="lead_id" value="<?php echo $id; ?>">
                    <div class="form-group">
                        <label class="small">Assigned staff member</label>
                        <select name="assigned_to" class="form-control form-control-sm">
                            <option value="0">— Unassigned —</option>
                            <?php foreach ($staff as $u): ?>
                                <option value="<?php echo (int)$u['user_id']; ?>" <?php echo (int)$lead['assigned_to'] === (int)$u['user_id'] ? 'selected' : ''; ?>>
                                    <?php echo e(trim($u['first_name'] . ' ' . $u['last_name']) ?: $u['username']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <button type="submit" class="btn btn-sm btn-outline-primary btn-block">Update Assignment</button>
                </form>
            </div>
        </div>

        <div class="card">
            <div class="card-header"><h5 class="card-title">Status</h5></div>
            <div class="card-body">
                <?php if ($transitions): ?>
                    <form method="POST" action="lead-action.php"<?php echo $storefront_request ? ' onsubmit="return this.querySelector(\'[name=status]\').value !== \'converted\' || confirm(\'Confirm this request and create an Order?\');"' : ''; ?>>
                        <?php echo csrf_field(); ?>
                        <input type="hidden" name="action" value="set_status">
                        <input type="hidden" name="lead_id" value="<?php echo $id; ?>">
                        <div class="form-group">
                            <label class="small">Move to status</label>
                            <select name="status" class="form-control form-control-sm" id="leadStatus" required>
                                <?php foreach ($transitions as $next): ?>
                                    <option value="<?php echo e($next); ?>"><?php echo e($storefront_request && $next === 'converted' ? 'Confirm request & create Order' : ($statuses[$next] ?? $next)); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group" id="lostReasonWrap" style="display:none;">
                            <label class="small">Lost reason <span class="text-danger">*</span></label>
                            <input type="text" name="lost_reason" class="form-control form-control-sm" maxlength="255">
                        </div>
                        <button type="submit" class="btn btn-sm btn-outline-primary btn-block">Update Status</button>
                    </form>
                <?php else: ?>
                    <p class="text-muted small mb-0">This lead is closed. No further status changes are allowed.</p>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="col-lg-8 mb-3">
        <?php if ($storefront_request): ?>
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h5 class="card-title">Storefront Order Request</h5>
                <?php if (!empty($storefront_request['order_id'])): ?>
                    <a href="order-view.php?id=<?php echo (int)$storefront_request['order_id']; ?>" class="btn btn-sm btn-outline-primary">
                        Order <?php echo e($storefront_request['order_number']); ?>
                    </a>
                <?php else: ?>
                    <span class="badge badge-warning">Awaiting confirmation</span>
                <?php endif; ?>
            </div>
            <div class="card-body border-bottom">
                <div class="alert alert-info mb-0">
                    Verification reserves available stock at the selected shop and store. That reservation is consumed when the order is fulfilled by an invoice and released if the order is cancelled or rejected.
                </div>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table mb-0">
                        <thead><tr><th>Product</th><th>Quantity</th><th class="text-right">Unit price</th><th class="text-right">Line total</th></tr></thead>
                        <tbody>
                        <?php foreach ($storefront_request_items as $item): ?>
                            <tr>
                                <td><?php echo e($item['product_name_snapshot']); ?><br><small class="text-muted"><?php echo e($item['product_code_snapshot']); ?></small></td>
                                <td><?php echo e((float)$item['quantity']); ?> <?php echo e($item['unit_label_snapshot'] ?: ''); ?></td>
                                <td class="text-right"><?php echo e(money($item['unit_price'], $storefront_request['currency_code'])); ?></td>
                                <td class="text-right"><?php echo e(money($item['line_total'], $storefront_request['currency_code'])); ?></td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (!$storefront_request_items): ?>
                            <tr><td colspan="4" class="text-center text-muted py-3">No item details are available.</td></tr>
                        <?php endif; ?>
                        </tbody>
                        <tfoot><tr><th colspan="3" class="text-right">Indicative total</th><th class="text-right"><?php echo e(money($storefront_request['subtotal'], $storefront_request['currency_code'])); ?></th></tr></tfoot>
                    </table>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <div class="card">
            <div class="card-header"><h5 class="card-title">Record Follow-up</h5></div>
            <div class="card-body">
                <form method="POST" action="lead-action.php">
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="action" value="add_activity">
                    <input type="hidden" name="lead_id" value="<?php echo $id; ?>">
                    <div class="row">
                        <div class="col-md-3 form-group">
                            <label class="small">Type</label>
                            <select name="activity_type" class="form-control form-control-sm">
                                <option value="call">Call</option>
                                <option value="whatsapp">WhatsApp</option>
                                <option value="email">Email</option>
                                <option value="sms">SMS</option>
                                <option value="meeting">Meeting</option>
                                <option value="note">Note</option>
                            </select>
                        </div>
                        <div class="col-md-5 form-group">
                            <label class="small">Subject</label>
                            <input type="text" name="subject" class="form-control form-control-sm" maxlength="191" placeholder="Short summary">
                        </div>
                        <div class="col-md-4 form-group">
                            <label class="small">Outcome</label>
                            <input type="text" name="outcome" class="form-control form-control-sm" maxlength="100" placeholder="Reached, no answer...">
                        </div>
                        <div class="col-md-9 form-group">
                            <label class="small">Notes</label>
                            <textarea name="notes" class="form-control form-control-sm" rows="2" placeholder="What was discussed? What is the next step?"></textarea>
                        </div>
                        <div class="col-md-3 form-group">
                            <label class="small">Next Follow-up</label>
                            <input type="date" name="next_follow_up_at" class="form-control form-control-sm">
                        </div>
                    </div>
                    <button type="submit" class="btn btn-sm btn-primary"><i class="fa fa-plus mr-1"></i> Add Follow-up</button>
                </form>
            </div>
        </div>

        <?php if ($can_orders): ?>
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h5 class="card-title">Linked Orders</h5>
                <small class="text-muted">Orders are visible only if your shop access allows them.</small>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table mb-0">
                        <thead><tr><th>Order</th><th>Customer</th><th>Shop</th><th>Status</th><th class="text-right">Total</th><th></th></tr></thead>
                        <tbody>
                        <?php foreach ($linked_orders as $o): ?>
                            <tr>
                                <td><a href="order-view.php?id=<?php echo (int)$o['order_id']; ?>" class="font-weight-semibold"><?php echo e($o['order_number']); ?></a></td>
                                <td><?php echo e($o['customer_name']); ?></td>
                                <td><?php echo e($o['shop_name'] ?: '—'); ?></td>
                                <td><span class="badge badge-<?php echo e(order_status_class($o['status'])); ?>"><?php echo e(order_statuses()[$o['status']] ?? $o['status']); ?></span></td>
                                <td class="text-right"><?php echo e(money($o['total'], $o['currency_code'] ?: tenant_currency())); ?></td>
                                <td class="text-right">
                                    <form method="POST" action="order-action.php" onsubmit="return confirm('Unlink this order from the lead?');">
                                        <?php echo csrf_field(); ?>
                                        <input type="hidden" name="action" value="unlink_lead">
                                        <input type="hidden" name="order_id" value="<?php echo (int)$o['order_id']; ?>">
                                        <button type="submit" class="btn btn-sm btn-light text-danger" title="Unlink"><i class="fa fa-unlink"></i></button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (!$linked_orders): ?>
                            <tr><td colspan="6" class="text-center text-muted py-3">No orders are linked to this lead.</td></tr>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <?php if ($linkable_orders): ?>
                <div class="card-body border-top">
                    <form method="POST" action="lead-action.php" class="form-inline">
                        <?php echo csrf_field(); ?>
                        <input type="hidden" name="action" value="link_order">
                        <input type="hidden" name="lead_id" value="<?php echo $id; ?>">
                        <select name="order_id" class="form-control form-control-sm mr-2 mb-1" required>
                            <option value="">— Select an order —</option>
                            <?php foreach ($linkable_orders as $o): ?>
                                <option value="<?php echo (int)$o['order_id']; ?>">
                                    <?php echo e($o['order_number'] . ' — ' . $o['customer_name'] . ' — ' . (order_statuses()[$o['status']] ?? $o['status'])); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <button type="submit" class="btn btn-sm btn-outline-primary mb-1"><i class="fa fa-link mr-1"></i> Link Order</button>
                    </form>
                </div>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <div class="card">
            <div class="card-header"><h5 class="card-title">History</h5></div>
            <div class="card-body">
                <?php if (!$activities): ?>
                    <p class="text-muted mb-0">No activity recorded yet.</p>
                <?php endif; ?>
                <div class="timeline">
                    <?php foreach ($activities as $a): ?>
                        <div class="timeline-item mb-3 pb-3 border-bottom">
                            <div class="d-flex justify-content-between">
                                <div>
                                    <span class="badge badge-light text-uppercase"><?php echo e(activity_type_label($a['activity_type'])); ?></span>
                                    <?php if (!empty($a['subject'])): ?>
                                        <span class="font-weight-semibold ml-1"><?php echo e($a['subject']); ?></span>
                                    <?php endif; ?>
                                </div>
                                <small class="text-muted"><?php echo e(date('M d, Y H:i', strtotime($a['created_at']))); ?></small>
                            </div>
                            <?php if (!empty($a['notes'])): ?>
                                <div class="small mt-1"><?php echo nl2br(e($a['notes'])); ?></div>
                            <?php endif; ?>
                            <div class="small text-muted mt-1">
                                By <?php echo e(trim(($a['first_name'] ?? '') . ' ' . ($a['last_name'] ?? '')) ?: 'System'); ?>
                                <?php if (!empty($a['outcome'])): ?>&middot; Outcome: <?php echo e($a['outcome']); ?><?php endif; ?>
                                <?php if (!empty($a['due_date'])): ?>&middot; Next: <?php echo e($a['due_date']); ?><?php endif; ?>
                                <?php if (!empty($a['order_id']) && $can_orders): ?>
                                    <?php
                                    $linked = fetch_one(
                                        "SELECT order_id, order_number, tenant_id, shop_id, created_by, assigned_to FROM orders
                                         WHERE order_id = ? AND tenant_id = ?",
                                        (int)$a['order_id'],
                                        $current_user->tenant_id
                                    );
                                    ?>
                                    <?php if ($linked && order_is_visible($linked)): ?>
                                        &middot; <a href="order-view.php?id=<?php echo (int)$linked['order_id']; ?>"><?php echo e($linked['order_number']); ?></a>
                                    <?php endif; ?>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
    $(function () {
        $('#leadStatus').on('change', function () {
            $('#lostReasonWrap').toggle($(this).val() === 'lost');
        }).trigger('change');
    });
</script>

<?php require_once 'footer.php'; ?>
