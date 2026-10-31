<?php
require_once 'header.php';
require_once 'auth_check.php';
require_permission('manage_leads');
$page_title = 'Leads';

$statuses = lead_statuses();
$status = (string)($_GET['status'] ?? '');
if ($status !== '' && !isset($statuses[$status])) {
    $status = '';
}
$assigned = (string)($_GET['assigned'] ?? '');
$q = trim((string)($_GET['q'] ?? ''));
$follow = (string)($_GET['follow'] ?? '');

$latest_storefront_request = fetch_one(
    "SELECT COALESCE(MAX(l.lead_id), 0) AS last_seen_lead_id
       FROM leads l
       JOIN storefront_lead_requests r ON r.lead_id = l.lead_id AND r.tenant_id = l.tenant_id
      WHERE l.tenant_id = ? AND l.source = 'storefront'",
    (int)$current_user->tenant_id
);
$last_seen_lead_id = (int)($latest_storefront_request['last_seen_lead_id'] ?? 0);
$stmt = $conn->prepare(
    "INSERT INTO storefront_request_notification_reads (tenant_id, user_id, last_seen_lead_id)
     VALUES (?, ?, ?)
     ON DUPLICATE KEY UPDATE last_seen_lead_id = GREATEST(last_seen_lead_id, VALUES(last_seen_lead_id))"
);
if (!$stmt) throw new RuntimeException($conn->error);
$stmt->bind_param('iii', $current_user->tenant_id, $current_user->user_id, $last_seen_lead_id);
if (!$stmt->execute()) {
    $error = $stmt->error;
    $stmt->close();
    throw new RuntimeException($error);
}
$stmt->close();

$sql = "SELECT l.*,
               (SELECT GROUP_CONCAT(DISTINCT sli.product_name_snapshot SEPARATOR ', ')
                  FROM storefront_lead_request_items sli
                  JOIN storefront_lead_requests slr ON slr.request_id = sli.request_id AND slr.tenant_id = sli.tenant_id
                 WHERE slr.lead_id = l.lead_id AND slr.tenant_id = l.tenant_id) product_names,
               ua.first_name assignee_first, ua.last_name assignee_last,
               uc.first_name creator_first, uc.last_name creator_last,
               c.first_name contact_first, c.last_name contact_last, c.company_name contact_company,
               c.phone contact_phone, c.mobile contact_mobile, c.email contact_email,
               EXISTS(
                   SELECT 1 FROM storefront_lead_requests sr
                    WHERE sr.lead_id = l.lead_id AND sr.tenant_id = l.tenant_id
               ) has_storefront_request,
               EXISTS(
                   SELECT 1 FROM storefront_lead_requests sr
                    WHERE sr.lead_id = l.lead_id AND sr.tenant_id = l.tenant_id
                      AND sr.converted_order_id IS NOT NULL
               ) has_converted_request,
               (SELECT COUNT(*) FROM orders o WHERE o.lead_id = l.lead_id AND o.tenant_id = l.tenant_id) order_count
        FROM leads l
        LEFT JOIN users ua ON ua.user_id = l.assigned_to
        LEFT JOIN users uc ON uc.user_id = l.created_by
        LEFT JOIN contacts c ON c.contact_id = l.contact_id AND c.tenant_id = l.tenant_id
        WHERE l.tenant_id = ?";
$params = [(int)$current_user->tenant_id];

if ($status !== '') {
    $sql .= " AND l.status = ?";
    $params[] = $status;
} else {
    // Converted leads have become Orders. Keep the active Leads queue focused
    // on requests that still need handling; conversion history remains
    // available through the Converted status filter.
    $sql .= " AND l.status <> 'converted'";
}
if ($assigned === 'me') {
    $sql .= " AND l.assigned_to = ?";
    $params[] = (int)$current_user->user_id;
} elseif ($assigned === 'unassigned') {
    $sql .= " AND l.assigned_to IS NULL";
} elseif (ctype_digit($assigned) && (int)$assigned > 0) {
    $sql .= " AND l.assigned_to = ?";
    $params[] = (int)$assigned;
}
if ($follow === 'overdue') {
    $sql .= " AND l.next_follow_up_at IS NOT NULL AND l.next_follow_up_at < CURDATE()
              AND l.status NOT IN ('converted', 'lost')";
} elseif ($follow === 'today') {
    $sql .= " AND l.next_follow_up_at = CURDATE() AND l.status NOT IN ('converted', 'lost')";
} elseif ($follow === 'upcoming') {
    $sql .= " AND l.next_follow_up_at >= CURDATE() AND l.status NOT IN ('converted', 'lost')";
}
if ($q !== '') {
    $like = '%' . $q . '%';
    $sql .= " AND (l.lead_number LIKE ? OR l.contact_name LIKE ? OR l.company_name LIKE ?
                  OR l.phone LIKE ? OR l.email LIKE ? OR l.location LIKE ?)";
    array_push($params, $like, $like, $like, $like, $like, $like);
}
$sql .= " ORDER BY
            CASE WHEN l.next_follow_up_at IS NOT NULL AND l.next_follow_up_at < CURDATE()
                      AND l.status NOT IN ('converted', 'lost') THEN 0 ELSE 1 END,
            l.next_follow_up_at IS NULL, l.next_follow_up_at, l.created_at DESC";
$leads = fetch_all($sql, ...$params);
$staff = users_with_permission('manage_leads');
$can_orders = can('manage_orders');
$can_verify_leads = tenant_admin_scope();

require_once 'navbar.php';
require_once 'sidebar.php';
?>

<div class="page-head">
    <div>
        <h1 class="page-title">Leads</h1>
        <div class="page-sub"><?php echo count($leads); ?> lead(s) match the current filters. Leads track follow-up, not stock or sales.</div>
    </div>
    <a href="lead-add.php" class="btn btn-primary"><i class="fa fa-plus mr-1"></i> New Lead</a>
</div>

<?php if ($f = flash_get()): ?>
    <div class="alert alert-<?php echo e($f['type']); ?>"><?php echo e($f['message']); ?></div>
<?php endif; ?>

<div class="card">
    <div class="card-header">
        <form method="GET" action="leads.php" class="form-inline flex-wrap">
            <select name="status" class="form-control form-control-sm mr-2 mb-1">
                <option value="">All Statuses</option>
                <?php foreach ($statuses as $key => $label): ?>
                    <option value="<?php echo e($key); ?>" <?php echo $status === $key ? 'selected' : ''; ?>><?php echo e($label); ?></option>
                <?php endforeach; ?>
            </select>
            <select name="assigned" class="form-control form-control-sm mr-2 mb-1">
                <option value="">Anyone</option>
                <option value="me" <?php echo $assigned === 'me' ? 'selected' : ''; ?>>Assigned to me</option>
                <option value="unassigned" <?php echo $assigned === 'unassigned' ? 'selected' : ''; ?>>Unassigned</option>
                <?php foreach ($staff as $u): ?>
                    <option value="<?php echo (int)$u['user_id']; ?>" <?php echo $assigned === (string)$u['user_id'] ? 'selected' : ''; ?>>
                        <?php echo e(trim($u['first_name'] . ' ' . $u['last_name']) ?: $u['username']); ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <select name="follow" class="form-control form-control-sm mr-2 mb-1">
                <option value="">Any Follow-up</option>
                <option value="overdue" <?php echo $follow === 'overdue' ? 'selected' : ''; ?>>Overdue</option>
                <option value="today" <?php echo $follow === 'today' ? 'selected' : ''; ?>>Due today</option>
                <option value="upcoming" <?php echo $follow === 'upcoming' ? 'selected' : ''; ?>>Upcoming</option>
            </select>
            <input type="text" name="q" class="form-control form-control-sm mr-2 mb-1" value="<?php echo e($q); ?>" placeholder="Lead, name, phone...">
            <button type="submit" class="btn btn-sm btn-primary mb-1"><i class="fa fa-filter mr-1"></i> Filter</button>
            <?php if ($status !== '' || $assigned !== '' || $follow !== '' || $q !== ''): ?>
                <a href="leads.php" class="btn btn-sm btn-light mb-1 ml-2">Clear</a>
            <?php endif; ?>
        </form>
        <div class="input-group dt-search ml-auto">
            <div class="input-group-prepend"><span class="input-group-text"><i class="fa fa-search"></i></span></div>
            <input type="text" class="form-control dt-search-input" data-scope="leads" placeholder="Search leads...">
        </div>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover data-table">
                <thead>
                    <tr>
                        <th>Customer Name</th><th>Phone</th><th>Product Name</th><th>Location</th>
                        <th>Status</th><th>Orders Placed</th><th>Next Follow-up</th><th>Date</th><th class="text-right">Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($leads as $l): ?>
                    <?php
                    $overdue = $l['next_follow_up_at']
                        && $l['next_follow_up_at'] < date('Y-m-d')
                        && !in_array($l['status'], ['converted', 'lost'], true);
                    $can_delete_lead = in_array($l['status'], ['new', 'contacted', 'qualified'], true)
                        && (int)$l['order_count'] === 0
                        && empty($l['has_converted_request']);
                    $contact_person = trim((string)($l['contact_first'] ?? '') . ' ' . (string)($l['contact_last'] ?? ''));
                    $customer_name = trim((string)($l['contact_company'] ?? ''));
                    if ($customer_name === '') $customer_name = trim((string)($l['company_name'] ?? ''));
                    if ($customer_name === '') $customer_name = $contact_person ?: (string)$l['contact_name'];
                    $contact_phone = $l['phone'] ?: ($l['contact_phone'] ?: $l['contact_mobile']);
                    $contact_email = $l['email'] ?: $l['contact_email'];
                    $product_name = trim((string)($l['product_names'] ?? ''));
                    if ($product_name === '') $product_name = 'â€”';

                    $lead_preview = [
                        'lead_number' => (string)$l['lead_number'],
                        'customer_name' => $customer_name,
                        'phone' => $contact_phone ?: 'Phone not recorded',
                        'location' => (string)($l['location'] ?: 'â€”'),
                        'product' => $product_name,
                        'status' => (string)($statuses[$l['status']] ?? $l['status']),
                        'status_key' => (string)$l['status'],

                        'orders' => $can_orders ? (int)$l['order_count'] : 0,
                        'next_follow_up' => $l['next_follow_up_at'] ? date('M d, Y', strtotime($l['next_follow_up_at'])) : 'â€”',
                        'followup_raw' => $l['next_follow_up_at'] ? (string)$l['next_follow_up_at'] : '',

                        'created' => date('M d, Y', strtotime($l['created_at'])),
                        'notes' => trim((string)($l['notes'] ?? '')),
                    ];
                    ?>
                    <tr class="lead-row" data-lead="<?php echo e(json_encode($lead_preview, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)); ?>" style="cursor:pointer;">
                        <td>
                            <?php if (!empty($l['contact_id'])): ?>
                                <a href="customer-view.php?id=<?php echo (int)$l['contact_id']; ?>" class="font-weight-semibold"><?php echo e($customer_name); ?></a>
                            <?php else: ?>
                                <span class="font-weight-semibold"><?php echo e($customer_name); ?></span>
                            <?php endif; ?>
                        </td>
                        <td><?php echo e($contact_phone ?: 'Phone not recorded'); ?></td>
                        <td><?php echo e($product_name); ?></td>
                        <td><?php echo e($l['location'] ?: 'â€”'); ?></td>
                        <td><span class="badge badge-<?php echo e(lead_status_class($l['status'])); ?>"><?php echo e($statuses[$l['status']] ?? $l['status']); ?></span></td>
                        <td><?php echo $can_orders ? (int)$l['order_count'] : 'â€”'; ?></td>
                        <td>
                            <?php if ($l['next_follow_up_at']): ?>
                                <span class="<?php echo $overdue ? 'text-danger font-weight-semibold' : ''; ?>">
                                    <?php echo e(date('M d, Y', strtotime($l['next_follow_up_at']))); ?>
                                </span>
                                <?php if ($overdue): ?><br><small class="text-danger">Overdue</small><?php endif; ?>
                            <?php else: ?>
                                <span class="text-muted">â€”</span>
                            <?php endif; ?>
                        </td>
                        <td><?php echo e(date('M d, Y', strtotime($l['created_at']))); ?></td>
                        <td class="text-right">
                            <div class="dropdown d-inline-block">
                                <button type="button" class="btn btn-action btn-light" data-toggle="dropdown" aria-haspopup="true" aria-expanded="false" title="Lead actions" aria-label="Lead actions for <?php echo e($l['lead_number']); ?>">
                                    <i class="fa fa-ellipsis-v"></i>
                                </button>
                                <div class="dropdown-menu dropdown-menu-right">
                                    <a class="dropdown-item" href="lead-view.php?id=<?php echo (int)$l['lead_id']; ?>"><i class="fa fa-eye mr-2"></i>View</a>
                                    <a class="dropdown-item" href="lead-edit.php?id=<?php echo (int)$l['lead_id']; ?>"><i class="fa fa-pencil mr-2"></i>Edit</a>
                                    <?php if ($can_delete_lead): ?>
                                        <div class="dropdown-divider"></div>
                                        <form method="POST" action="lead-action.php" onsubmit="return confirm('Delete this unverified lead permanently? Any storefront request and follow-up history for it will also be removed.');">
                                            <?php echo csrf_field(); ?>
                                            <input type="hidden" name="action" value="delete">
                                            <input type="hidden" name="lead_id" value="<?php echo (int)$l['lead_id']; ?>">
                                            <button type="submit" class="dropdown-item text-danger"><i class="fa fa-trash mr-2"></i>Delete</button>
                                        </form>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$leads): ?>
                    <tr><td colspan="9" class="text-center text-muted py-4">No leads match the current filters. <a href="lead-add.php">Record the first lead</a>.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Lead Preview Modal -->
<div class="modal fade" id="leadPreviewModal" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog modal-lg" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Lead Preview: <span id="lp_lead_number"></span></h5>
                <button type="button" class="close" data-dismiss="modal" aria-label="Close"><span aria-hidden="true">&times;</span></button>
            </div>
            <div class="modal-body">
                <dl class="row mb-3">
                    <dt class="col-sm-3">Customer Name</dt><dd class="col-sm-9" id="lp_customer_name"></dd>
                    <dt class="col-sm-3">Phone</dt><dd class="col-sm-9" id="lp_phone"></dd>
                    <dt class="col-sm-3">Location</dt><dd class="col-sm-9" id="lp_location"></dd>
                    <dt class="col-sm-3">Product Name</dt><dd class="col-sm-9" id="lp_product"></dd>
                    <dt class="col-sm-3">Status</dt><dd class="col-sm-9" id="lp_status"></dd>
                    <dt class="col-sm-3">Orders Placed</dt><dd class="col-sm-9" id="lp_orders"></dd>
                    <dt class="col-sm-3">Next Follow-up</dt><dd class="col-sm-9" id="lp_next_follow_up"></dd>
                    <dt class="col-sm-3">Created Date</dt><dd class="col-sm-9" id="lp_created"></dd>
                    <dt class="col-sm-3">Notes</dt><dd class="col-sm-9" id="lp_notes"></dd>
                </dl>

                <form method="POST" action="lead-action.php" class="mb-2">
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="action" value="set_status">
                    <input type="hidden" name="lead_id" id="lp_lead_id_status" value="">
                    <div class="form-row">
                        <div class="form-group col-md-6">
                            <label>Update Status</label>
                            <select name="status" id="lp_status_select" class="form-control">
                                <?php foreach (lead_statuses() as $key => $label): ?>
                                    <option value="<?php echo e($key); ?>"><?php echo e($label); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group col-md-6 d-flex align-items-end">
                            <button type="submit" class="btn btn-primary"><i class="fa fa-check mr-1"></i> Update Status</button>
                        </div>
                    </div>
                </form>

                <form method="POST" action="lead-action.php" class="mb-2">
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="action" value="add_activity">
                    <input type="hidden" name="lead_id" id="lp_lead_id_followup" value="">
                    <input type="hidden" name="activity_type" value="note">
                    <input type="hidden" name="subject" value="Follow-up scheduled">
                    <div class="form-group">
                        <label>Set Follow-up Date</label>
                        <div class="input-group">
                            <input type="date" name="next_follow_up_at" id="lp_followup_date" class="form-control">
                            <div class="input-group-append">
                                <button type="submit" class="btn btn-info"><i class="fa fa-calendar mr-1"></i> Save Date</button>
                            </div>
                        </div>
                    </div>
                </form>

                <a id="lp_edit_link" href="#" class="btn btn-light"><i class="fa fa-pencil mr-1"></i> Edit Lead Details</a>
            </div>
        </div>
    </div>
</div>

<script>
    $(function () {
        $(document).on('click', '.lead-row, .btn-lead-preview', function (e) {
            if ($(e.target).closest('a, button, form, .dropdown').length && !$(e.target).closest('.btn-lead-preview').length) {
                if (!$(e.target).closest('.btn-lead-preview').length) return;
            }
            var $row = $(this).closest('.lead-row');
            if (!$row.length) return;
            e.preventDefault();
            e.stopPropagation();
            var data = {};
            try { data = JSON.parse($row.attr('data-lead')); } catch (err) { return; }
            $('#lp_lead_number').text(data.lead_number || '');
            $('#lp_customer_name').text(data.customer_name || '');
            $('#lp_phone').text(data.phone || '');
            $('#lp_location').text(data.location || '');
            $('#lp_product').text(data.product || '');
            $('#lp_status').text(data.status || '');
            $('#lp_orders').text(data.orders || 0);
            $('#lp_next_follow_up').text(data.next_follow_up || '');
            $('#lp_created').text(data.created || '');
            $('#lp_notes').text(data.notes || '—');
            $('#lp_lead_id_status').val(data.lead_id || '');
            $('#lp_lead_id_followup').val(data.lead_id || '');
            $('#lp_status_select').val(data.status_key || '');
            $('#lp_followup_date').val(data.followup_raw || '');
            $('#lp_edit_link').attr('href', 'lead-edit.php?id=' + (data.lead_id || ''));
            $('#leadPreviewModal').modal('show');
        });
    });
</script>


<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script>$(function(){ $(document).on("change", "#lp_status_select", function(){ if (this.value === "confirmed") { var form = $(this).closest("form"); Swal.fire({title:"Confirm order?", text:"Do you want to confirm the order and process the lead?", icon:"question", showCancelButton:true}).then(function(result){ if (result.isConfirmed) { form.submit(); } }); } }); });</script>
<?php require_once 'footer.php'; ?>
