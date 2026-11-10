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
$brand_filter = trim((string)($_GET['brand'] ?? ''));
if ($brand_filter !== '' && !in_array($brand_filter, product_brand_options(), true)) {
    $brand_filter = '';
}
$brand_scope = shop_brand_scope_for_user();

$sql = "SELECT l.*,
               (SELECT GROUP_CONCAT(DISTINCT sli.product_name_snapshot SEPARATOR ', ')
                  FROM storefront_lead_request_items sli
                  JOIN storefront_lead_requests slr ON slr.request_id = sli.request_id AND slr.tenant_id = sli.tenant_id
                 WHERE slr.lead_id = l.lead_id AND slr.tenant_id = l.tenant_id) product_names,
               (SELECT GROUP_CONCAT(DISTINCT p.brand SEPARATOR ', ')
                  FROM storefront_lead_request_items sli
                  JOIN storefront_lead_requests slr ON slr.request_id = sli.request_id AND slr.tenant_id = sli.tenant_id
                  JOIN products p ON p.product_id = sli.product_id AND p.tenant_id = sli.tenant_id
                 WHERE slr.lead_id = l.lead_id AND slr.tenant_id = l.tenant_id
                   AND p.brand IS NOT NULL AND p.brand <> '') product_brands,
               (SELECT COALESCE(SUM(sli.quantity), 0)
                  FROM storefront_lead_request_items sli
                  JOIN storefront_lead_requests slr ON slr.request_id = sli.request_id AND slr.tenant_id = sli.tenant_id
                 WHERE slr.lead_id = l.lead_id AND slr.tenant_id = l.tenant_id) requested_quantity,
               ua.first_name assignee_first, ua.last_name assignee_last,
               uc.first_name creator_first, uc.last_name creator_last,
               c.first_name contact_first, c.last_name contact_last, c.company_name contact_company,
               c.phone contact_phone, c.mobile contact_mobile, c.email contact_email,
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
              AND l.status NOT IN ('confirmed', 'cancelled')";
} elseif ($follow === 'today') {
    $sql .= " AND l.next_follow_up_at = CURDATE() AND l.status NOT IN ('confirmed', 'cancelled')";
} elseif ($follow === 'upcoming') {
    $sql .= " AND l.next_follow_up_at >= CURDATE() AND l.status NOT IN ('confirmed', 'cancelled')";
}
if ($q !== '') {
    $like = '%' . $q . '%';
    $sql .= " AND (l.lead_number LIKE ? OR l.contact_name LIKE ? OR l.company_name LIKE ?
                  OR l.phone LIKE ? OR l.email LIKE ? OR l.location LIKE ?)";
    array_push($params, $like, $like, $like, $like, $like, $like);
}

// Branded leads are only visible to shops selling the same brand. Leads with no
// brand information stay visible so older leads are not hidden.
$branded_lead_exists = "EXISTS (SELECT 1 FROM storefront_lead_request_items bi
                                 JOIN storefront_lead_requests br ON br.request_id = bi.request_id AND br.tenant_id = bi.tenant_id
                                 JOIN products bp ON bp.product_id = bi.product_id AND bp.tenant_id = bi.tenant_id
                                WHERE br.lead_id = l.lead_id AND br.tenant_id = l.tenant_id
                                  AND bp.brand IS NOT NULL AND bp.brand <> '')";
if ($brand_filter !== '') {
    $sql .= " AND EXISTS (SELECT 1 FROM storefront_lead_request_items fi
                           JOIN storefront_lead_requests fr ON fr.request_id = fi.request_id AND fr.tenant_id = fi.tenant_id
                           JOIN products fp ON fp.product_id = fi.product_id AND fp.tenant_id = fi.tenant_id
                          WHERE fr.lead_id = l.lead_id AND fr.tenant_id = l.tenant_id
                            AND fp.brand = ?)";
    $params[] = $brand_filter;
}
if ($brand_scope !== null) {
    if (!$brand_scope) {
        $sql .= " AND NOT $branded_lead_exists";
    } else {
        $brand_placeholders = implode(',', array_fill(0, count($brand_scope), '?'));
        $sql .= " AND (NOT $branded_lead_exists OR EXISTS (
                            SELECT 1 FROM storefront_lead_request_items si
                              JOIN storefront_lead_requests sr ON sr.request_id = si.request_id AND sr.tenant_id = si.tenant_id
                              JOIN products sp ON sp.product_id = si.product_id AND sp.tenant_id = si.tenant_id
                             WHERE sr.lead_id = l.lead_id AND sr.tenant_id = l.tenant_id
                               AND sp.brand IN ($brand_placeholders)))";
        foreach ($brand_scope as $scope_brand) {
            $params[] = $scope_brand;
        }
    }
}
$sql .= " ORDER BY
            CASE WHEN l.next_follow_up_at IS NOT NULL AND l.next_follow_up_at < CURDATE()
                      AND l.status NOT IN ('confirmed', 'cancelled') THEN 0 ELSE 1 END,
            l.next_follow_up_at IS NULL, l.next_follow_up_at, l.created_at DESC";
$leads = fetch_all($sql, ...$params);
$staff = users_with_permission('manage_leads');
$can_orders = can('manage_orders');

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
            <select name="brand" class="form-control form-control-sm mr-2 mb-1">
                <option value="">All Brands</option>
                <?php foreach (brand_filter_options() as $brand_option): ?>
                    <option value="<?php echo e($brand_option); ?>" <?php echo $brand_filter === $brand_option ? 'selected' : ''; ?>><?php echo e(brand_label($brand_option)); ?></option>
                <?php endforeach; ?>
            </select>
            <input type="text" name="q" class="form-control form-control-sm mr-2 mb-1" value="<?php echo e($q); ?>" placeholder="Lead, name, phone...">
            <button type="submit" class="btn btn-sm btn-primary mb-1"><i class="fa fa-filter mr-1"></i> Filter</button>
            <?php if ($status !== '' || $assigned !== '' || $follow !== '' || $q !== '' || $brand_filter !== ''): ?>
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
                        <th>Customer Name</th><th>Phone</th><th>Requested Products</th><th>Brand</th><th>Quantity</th><th>Location</th>
                        <th>Status</th><th>Orders Placed</th><th>Next Follow-up</th><th>Date</th><th class="text-right">Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($leads as $l): ?>
                    <?php
                    $overdue = $l['next_follow_up_at']
                        && $l['next_follow_up_at'] < date('Y-m-d')
                        && !in_array($l['status'], ['confirmed', 'cancelled'], true);
                    $can_delete_lead = in_array((string)$l['status'], ['pending', 'follow_up', 'cancelled'], true);
                    $customer_name = lead_customer_display_name($l);
                    $contact_phone = $l['phone'] ?: ($l['contact_phone'] ?: $l['contact_mobile']);
                    $product_name = trim((string)($l['product_names'] ?? ''));
                    if ($product_name === '') $product_name = '—';
                    $lead_brands = array_values(array_filter(array_map('trim', explode(',', (string)($l['product_brands'] ?? '')))));
                    ?>
                    <tr>
                        <td>
                            <?php if (!empty($l['contact_id'])): ?>
                                <a href="customer-view.php?id=<?php echo (int)$l['contact_id']; ?>" class="font-weight-semibold"><?php echo e($customer_name); ?></a>
                            <?php else: ?>
                                <span class="font-weight-semibold"><?php echo e($customer_name); ?></span>
                            <?php endif; ?>
                        </td>
                        <td><?php echo e($contact_phone ?: 'Phone not recorded'); ?></td>
                        <td><?php echo e($product_name); ?></td>
                        <td>
                            <?php if ($lead_brands): ?>
                                <?php foreach ($lead_brands as $lead_brand): ?>
                                    <span class="badge badge-navy"><?php echo e(brand_label($lead_brand)); ?></span>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <span class="text-muted small">—</span>
                            <?php endif; ?>
                        </td>
                        <td><?php echo (float)$l['requested_quantity'] > 0 ? e(format_quantity((float)$l['requested_quantity'])) : '—'; ?></td>
                        <td><?php echo e($l['location'] ?: '—'); ?></td>
                        <td><span class="badge badge-<?php echo e(lead_status_class($l['status'])); ?>"><?php echo e($statuses[$l['status']] ?? $l['status']); ?></span></td>
                        <td><?php echo $can_orders ? (int)$l['order_count'] : '—'; ?></td>
                        <td>
                            <?php if ($l['next_follow_up_at']): ?>
                                <span class="<?php echo $overdue ? 'text-danger font-weight-semibold' : ''; ?>">
                                    <?php echo e(date('M d, Y', strtotime($l['next_follow_up_at']))); ?>
                                </span>
                                <?php if ($overdue): ?><br><small class="text-danger">Overdue</small><?php endif; ?>
                            <?php else: ?>
                                <span class="text-muted">—</span>
                            <?php endif; ?>
                        </td>
                        <td><?php echo e(date('M d, Y', strtotime($l['created_at']))); ?></td>
                        <td class="text-right">
                            <?php
                            $lead_actions = [
                                ['label' => 'View lead', 'icon' => 'fa-eye', 'href' => 'lead-view.php?id=' . (int)$l['lead_id']],
                                ['label' => 'Edit lead', 'icon' => 'fa-pencil', 'href' => 'lead-edit.php?id=' . (int)$l['lead_id']],
                            ];
                            if ($can_delete_lead) {
                                $lead_actions[] = ['type' => 'divider'];
                                $lead_actions[] = [
                                    'label' => 'Delete lead',
                                    'icon' => 'fa-trash',
                                    'danger' => true,
                                    'attrs' => [
                                        'data-toggle' => 'modal',
                                        'data-target' => '#deleteLeadModal',
                                        'data-lead-id' => (int)$l['lead_id'],
                                        'data-lead-number' => $l['lead_number'],
                                    ],
                                ];
                            }
                            echo row_action_menu($lead_actions, 'Lead actions for ' . $l['lead_number']);
                            ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$leads): ?>
                    <tr><td colspan="11" class="text-center text-muted py-4">No leads match the current filters. <a href="lead-add.php">Record the first lead</a>.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<div class="modal fade" id="deleteLeadModal" tabindex="-1" role="dialog" aria-labelledby="deleteLeadModalLabel" aria-hidden="true">
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            <form method="POST" action="lead-action.php">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="lead_id" id="deleteLeadId" value="">
                <div class="modal-header">
                    <h5 class="modal-title text-danger" id="deleteLeadModalLabel">
                        <i class="fa fa-trash mr-1"></i> Delete Lead
                    </h5>
                    <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                        <span aria-hidden="true">&times;</span>
                    </button>
                </div>
                <div class="modal-body">
                    <div class="alert alert-warning mb-0" role="alert">
                        Delete lead <strong id="deleteLeadNumber"></strong>? Its follow-up history and any unconverted storefront request will also be deleted. Leads linked to orders cannot be deleted.
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-light" data-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-danger"><i class="fa fa-trash mr-1"></i> Delete Lead</button>
                </div>
            </form>
        </div>
    </div>
</div>
<script>
    $('#deleteLeadModal').on('show.bs.modal', function (event) {
        var $button = $(event.relatedTarget);
        $('#deleteLeadId').val($button.data('lead-id'));
        $('#deleteLeadNumber').text($button.data('lead-number'));
    });
</script>

<?php require_once 'footer.php'; ?>
