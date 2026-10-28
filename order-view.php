<?php
require_once 'header.php';
require_once 'auth_check.php';
require_permission('manage_orders');
$page_title = 'Order Details';

$id = (int)($_GET['id'] ?? 0);
$order = fetch_one(
    "SELECT o.*,
            s.name shop_name, s.code shop_code,
            w.name store_name, w.code store_code,
            c.first_name, c.last_name, c.company_name,
            l.lead_number,
            uc.first_name creator_first, uc.last_name creator_last,
            ua.first_name assignee_first, ua.last_name assignee_last,
            uf.first_name fulfil_first, uf.last_name fulfil_last
     FROM orders o
     LEFT JOIN shops s ON s.shop_id = o.shop_id AND s.tenant_id = o.tenant_id
     LEFT JOIN warehouses w ON w.warehouse_id = o.warehouse_id AND w.tenant_id = o.tenant_id
     LEFT JOIN contacts c ON c.contact_id = o.contact_id AND c.tenant_id = o.tenant_id
     LEFT JOIN leads l ON l.lead_id = o.lead_id AND l.tenant_id = o.tenant_id
     LEFT JOIN users uc ON uc.user_id = o.created_by
     LEFT JOIN users ua ON ua.user_id = o.assigned_to
     LEFT JOIN users uf ON uf.user_id = o.fulfilled_by
     WHERE o.order_id = ? AND o.tenant_id = ?",
    $id,
    $current_user->tenant_id
);
if (!$order) {
    flash_set('danger', 'Order not found.');
    redirect('orders.php');
}
if (!order_is_visible($order)) {
    http_response_code(403);
    die('You do not have access to this order.');
}

$items = fetch_all(
    "SELECT oi.*,
            (SELECT st.quantity FROM inventory_stock st
              WHERE st.product_id = oi.product_id AND st.warehouse_id = ? AND st.tenant_id = oi.tenant_id
              LIMIT 1) current_stock,
            (SELECT COALESCE(SUM(r.quantity), 0) FROM order_stock_reservations r
              WHERE r.tenant_id = oi.tenant_id AND r.product_id = oi.product_id
                AND r.warehouse_id = ? AND r.status = 'active') reserved_stock,
            (SELECT COALESCE(SUM(r.quantity), 0) FROM order_stock_reservations r
              WHERE r.tenant_id = oi.tenant_id AND r.order_id = oi.order_id
                AND r.status = 'active') this_order_reserved,
            p.is_active product_is_active, p.sale_price current_price
     FROM order_items oi
     LEFT JOIN products p ON p.product_id = oi.product_id AND p.tenant_id = oi.tenant_id
     WHERE oi.order_id = ? AND oi.tenant_id = ?
     ORDER BY oi.line_no",
    (int)($order['warehouse_id'] ?? 0),
    (int)($order['warehouse_id'] ?? 0),
    $id,
    $current_user->tenant_id
);
$reservation_rows = fetch_all(
    "SELECT r.*, oi.product_name_snapshot, oi.product_code_snapshot
       FROM order_stock_reservations r
       JOIN order_items oi ON oi.order_item_id = r.order_item_id AND oi.tenant_id = r.tenant_id
      WHERE r.order_id = ? AND r.tenant_id = ?
      ORDER BY r.reservation_id",
    $id,
    $current_user->tenant_id
);
$active_reservation_rows = [];
foreach ($reservation_rows as $reservation_row) {
    if (($reservation_row['status'] ?? '') === 'active') {
        $active_reservation_rows[] = $reservation_row;
    }
}
$has_active_reservation = (bool)$active_reservation_rows;

$statuses = order_statuses();
$status = $order['status'];
$can_edit = in_array($status, ['new', 'under_review'], true);
$shops = accessible_shops(null, false);
$stores = accessible_stores(null, false);
$leads = fetch_all(
    "SELECT lead_id, lead_number, contact_name FROM leads WHERE tenant_id = ? ORDER BY created_at DESC LIMIT 200",
    $current_user->tenant_id
);
$linked_lead = null;
if (!empty($order['lead_id'])) {
    $linked_lead = fetch_one("SELECT * FROM leads WHERE lead_id = ? AND tenant_id = ?", (int)$order['lead_id'], $current_user->tenant_id);
}

$scope_ids = accessible_warehouse_ids();
$invoice_scope = '';
if ($scope_ids !== null) {
    $invoice_scope = $scope_ids
        ? " AND i.warehouse_id IN (" . implode(',', array_map('intval', $scope_ids)) . ")"
        : " AND 1 = 0";
}
$invoices = fetch_all(
    "SELECT i.invoice_id, i.invoice_number, i.total, i.issue_date, i.warehouse_id,
            w.name store_name, ds.name status_name
     FROM invoices i
     LEFT JOIN warehouses w ON w.warehouse_id = i.warehouse_id
     LEFT JOIN document_statuses ds ON ds.status_id = i.status_id
     WHERE i.tenant_id = ?{$invoice_scope}
       AND i.invoice_id NOT IN (
           SELECT o2.fulfilled_invoice_id FROM orders o2
           WHERE o2.fulfilled_invoice_id IS NOT NULL AND o2.order_id <> ?
       )
     ORDER BY i.issue_date DESC, i.invoice_id DESC LIMIT 200",
    $current_user->tenant_id,
    $id
);

$history = fetch_all(
    "SELECT al.*, u.first_name, u.last_name
     FROM audit_logs al
     LEFT JOIN users u ON u.user_id = al.user_id
     WHERE al.tenant_id = ? AND al.entity_type = 'order' AND al.entity_id = ?
     ORDER BY al.created_at DESC, al.log_id DESC LIMIT 50",
    $current_user->tenant_id,
    $id
);

require_once 'navbar.php';
require_once 'sidebar.php';
$cur = $order['currency_code'] ?: tenant_currency();
?>

<div class="page-head">
    <div>
        <h1 class="page-title">Order <?php echo e($order['order_number']); ?></h1>
        <div class="page-sub">
            <span class="badge badge-<?php echo e(order_status_class($status)); ?>"><?php echo e($statuses[$status] ?? $status); ?></span>
            &middot; <?php echo e(source_label($order['source'])); ?>
            &middot; created <?php echo e(date('M d, Y H:i', strtotime($order['created_at']))); ?>
        </div>
    </div>
    <div>
        <?php if ($can_edit): ?>
            <a href="order-edit.php?id=<?php echo $id; ?>" class="btn btn-outline-primary"><i class="fa fa-pencil mr-1"></i> Edit</a>
        <?php endif; ?>
        <?php if (can('manage_invoices') && $order['contact_id']): ?>
            <?php if ($status === 'confirmed'): ?>
                <a href="invoice-add.php?order_id=<?php echo $id; ?>" class="btn btn-success"><i class="fa fa-file-text mr-1"></i> Create Invoice &amp; Fulfil</a>
            <?php elseif (!in_array($status, ['cancelled', 'rejected', 'fulfilled'], true)): ?>
                <a href="invoice-add.php?customer=<?php echo (int)$order['contact_id']; ?>" class="btn btn-outline-success"><i class="fa fa-file-text mr-1"></i> Create Invoice</a>
            <?php endif; ?>
        <?php endif; ?>
        <a href="orders.php" class="btn btn-light"><i class="fa fa-arrow-left mr-1"></i> Back</a>
    </div>
</div>

<?php if ($f = flash_get()): ?>
    <div class="alert alert-<?php echo e($f['type']); ?>"><?php echo e($f['message']); ?></div>
<?php endif; ?>

<div class="row">
    <div class="col-lg-8 mb-3">
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h5 class="card-title">Requested Items</h5>
                <small class="text-muted">Prices are snapshots. Available stock subtracts every active order reservation at this store.</small>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table mb-0">
                        <thead>
                            <tr><th>Product</th><th class="text-right">Qty</th><th class="text-right">Unit Price</th><th class="text-right">Line Total</th><th class="text-right">On Hand</th><th class="text-right">Reserved</th><th class="text-right">Available</th></tr>
                        </thead>
                        <tbody>
                        <?php foreach ($items as $it): ?>
                            <?php
                            $on_hand = $it['current_stock'] === null ? 0.0 : (float)$it['current_stock'];
                            $reserved_total = (float)($it['reserved_stock'] ?? 0);
                            $available = max(0, $on_hand - $reserved_total);
                            ?>
                            <tr>
                                <td>
                                    <span class="font-weight-semibold"><?php echo e($it['product_name_snapshot']); ?></span>
                                    <small class="text-muted d-block">
                                        <?php echo e($it['product_code_snapshot']); ?>
                                        <?php if (!empty($it['unit_label_snapshot'])): ?>&middot; <?php echo e($it['unit_label_snapshot']); ?><?php endif; ?>
                                        <?php if (!$it['product_id']): ?>&middot; product deleted<?php elseif (!(int)$it['product_is_active']): ?>&middot; inactive<?php endif; ?>
                                    </small>
                                    <?php if (!empty($it['notes'])): ?><small class="text-muted d-block">Note: <?php echo e($it['notes']); ?></small><?php endif; ?>
                                </td>
                                <td class="text-right"><?php echo e((float)$it['quantity']); ?></td>
                                <td class="text-right"><?php echo e(money($it['unit_price'], $cur)); ?></td>
                                <td class="text-right font-weight-semibold"><?php echo e(money($it['line_total'], $cur)); ?></td>
                                <td class="text-right"><?php echo $order['warehouse_id'] ? e($on_hand) : '—'; ?></td>
                                <td class="text-right">
                                    <?php if ($order['warehouse_id']): ?>
                                        <?php echo e($reserved_total); ?>
                                        <?php if ((float)($it['this_order_reserved'] ?? 0) > 0): ?>
                                            <small class="text-muted d-block">this order <?php echo e((float)$it['this_order_reserved']); ?></small>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <span class="text-muted">—</span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-right font-weight-semibold <?php echo $order['warehouse_id'] && $available < (float)$it['quantity'] ? 'text-danger' : ''; ?>">
                                    <?php echo $order['warehouse_id'] ? e($available) : '—'; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (!$items): ?>
                            <tr><td colspan="7" class="text-center text-muted py-4">No items on this order.</td></tr>
                        <?php endif; ?>
                        </tbody>
                        <tfoot>
                            <tr><th colspan="3" class="text-right">Indicative Total</th><th class="text-right"><?php echo e(money($order['total'], $cur)); ?></th><th colspan="3"></th></tr>
                        </tfoot>
                    </table>
                </div>
            </div>
        </div>

        <div class="card">
            <div class="card-header"><h5 class="card-title">Order History</h5></div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table mb-0">
                        <thead><tr><th>When</th><th>By</th><th>Action</th><th>Details</th></tr></thead>
                        <tbody>
                        <?php foreach ($history as $h): ?>
                            <tr>
                                <td><?php echo e(date('M d, Y H:i', strtotime($h['created_at']))); ?></td>
                                <td><?php echo e(trim(($h['first_name'] ?? '') . ' ' . ($h['last_name'] ?? '')) ?: 'System'); ?></td>
                                <td><?php echo e(ucwords(str_replace('_', ' ', $h['action']))); ?></td>
                                <td><?php echo e($h['details'] ?: '—'); ?></td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (!$history): ?>
                            <tr><td colspan="4" class="text-center text-muted py-3">No recorded history yet.</td></tr>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <div class="col-lg-4 mb-3">
        <div class="card">
            <div class="card-header"><h5 class="card-title">Customer Snapshot</h5></div>
            <div class="card-body">
                <table class="table mb-0">
                    <tbody>
                        <tr><th>Name</th><td><?php echo e($order['customer_name']); ?></td></tr>
                        <tr><th>Phone</th><td><?php echo e($order['customer_phone']); ?></td></tr>
                        <tr><th>Email</th><td><?php echo e($order['customer_email'] ?: '—'); ?></td></tr>
                        <tr><th>Location</th><td><?php echo e($order['customer_location']); ?></td></tr>
                        <tr>
                            <th>Customer</th>
                            <td>
                                <?php if ($order['contact_id']): ?>
                                    <a href="customer-view.php?id=<?php echo (int)$order['contact_id']; ?>">
                                        <?php echo e(trim(($order['first_name'] ?? '') . ' ' . ($order['last_name'] ?? '')) ?: ($order['company_name'] ?? 'Linked customer')); ?>
                                    </a>
                                <?php else: ?>
                                    <span class="text-muted">Not linked</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="card">
            <div class="card-header"><h5 class="card-title">Operation</h5></div>
            <div class="card-body">
                <table class="table mb-3">
                    <tbody>
                        <tr><th>Shop</th><td><?php echo e($order['shop_name'] ?: '—'); ?></td></tr>
                        <tr><th>Store</th><td><?php echo e($order['store_name'] ?: '—'); ?></td></tr>
                        <tr><th>Responsible</th><td><?php echo e(trim(($order['assignee_first'] ?? '') . ' ' . ($order['assignee_last'] ?? '')) ?: '—'); ?></td></tr>
                        <tr><th>Recorded by</th><td><?php echo e(trim(($order['creator_first'] ?? '') . ' ' . ($order['creator_last'] ?? '')) ?: '—'); ?></td></tr>
                        <tr><th>Confirmed</th><td><?php echo $order['confirmed_at'] ? e(date('M d, Y H:i', strtotime($order['confirmed_at']))) : '—'; ?></td></tr>
                        <tr><th>Closed</th><td><?php echo $order['rejected_at'] ? 'Rejected: ' . e($order['rejection_reason']) : ($order['cancelled_at'] ? 'Cancelled: ' . e($order['cancel_reason']) : ($order['fulfilled_at'] ? 'Fulfilled ' . e(date('M d, Y', strtotime($order['fulfilled_at']))) : '—')); ?></td></tr>
                        <tr>
                            <th>Invoice</th>
                            <td>
                                <?php if ($order['fulfilled_invoice_id']): ?>
                                    <a href="invoice-details.php?id=<?php echo (int)$order['fulfilled_invoice_id']; ?>"><?php echo e($order['fulfilled_invoice_number'] ?: ('#' . $order['fulfilled_invoice_id'])); ?></a>
                                <?php else: ?>
                                    —
                                <?php endif; ?>
                            </td>
                        </tr>
                    </tbody>
                </table>
                <?php if ($reservation_rows): ?>
                    <hr>
                    <h6 class="mb-2">Stock Reservations</h6>
                    <div class="table-responsive">
                        <table class="table table-sm mb-2">
                            <thead><tr><th>Product</th><th class="text-right">Qty</th><th>Status</th></tr></thead>
                            <tbody>
                            <?php foreach ($reservation_rows as $res): ?>
                                <tr>
                                    <td><?php echo e($res['product_name_snapshot']); ?><small class="text-muted d-block"><?php echo e($res['product_code_snapshot']); ?></small></td>
                                    <td class="text-right"><?php echo e((float)$res['quantity']); ?></td>
                                    <td>
                                        <span class="badge badge-<?php echo $res['status'] === 'active' ? 'primary' : ($res['status'] === 'consumed' ? 'success' : 'secondary'); ?>">
                                            <?php echo e(ucfirst($res['status'])); ?>
                                        </span>
                                        <?php if ($res['consumed_invoice_id']): ?><small class="text-muted d-block">Invoice #<?php echo (int)$res['consumed_invoice_id']; ?></small><?php endif; ?>
                                        <?php if ($res['released_at']): ?><small class="text-muted d-block"><?php echo e(date('M d, Y', strtotime($res['released_at']))); ?></small><?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
                <?php if (!empty($order['notes'])): ?>
                    <div class="small text-muted">Notes: <?php echo nl2br(e($order['notes'])); ?></div>
                <?php endif; ?>
            </div>
        </div>

        <div class="card">
            <div class="card-header"><h5 class="card-title">Lead</h5></div>
            <div class="card-body">
                <?php if ($linked_lead): ?>
                    <p class="mb-2">
                        <a href="lead-view.php?id=<?php echo (int)$linked_lead['lead_id']; ?>" class="font-weight-semibold"><?php echo e($linked_lead['lead_number']); ?></a>
                        <span class="badge badge-<?php echo e(lead_status_class($linked_lead['status'])); ?>"><?php echo e(lead_statuses()[$linked_lead['status']] ?? $linked_lead['status']); ?></span><br>
                        <small class="text-muted"><?php echo e($linked_lead['contact_name']); ?></small>
                    </p>
                    <?php if (can('manage_leads')): ?>
                        <form method="POST" action="order-action.php" onsubmit="return confirm('Unlink this lead from the order?');">
                            <?php echo csrf_field(); ?>
                            <input type="hidden" name="action" value="unlink_lead">
                            <input type="hidden" name="order_id" value="<?php echo $id; ?>">
                            <button type="submit" class="btn btn-sm btn-outline-danger"><i class="fa fa-unlink mr-1"></i> Unlink</button>
                        </form>
                    <?php endif; ?>
                <?php else: ?>
                    <?php if (can('manage_leads') && $leads): ?>
                        <form method="POST" action="order-action.php">
                            <?php echo csrf_field(); ?>
                            <input type="hidden" name="action" value="link_lead">
                            <input type="hidden" name="order_id" value="<?php echo $id; ?>">
                            <div class="form-group">
                                <label class="small">Link an existing lead</label>
                                <select name="lead_id" class="form-control form-control-sm" required>
                                    <option value="">— Select lead —</option>
                                    <?php foreach ($leads as $l): ?>
                                        <option value="<?php echo (int)$l['lead_id']; ?>"><?php echo e($l['lead_number'] . ' — ' . $l['contact_name']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <button type="submit" class="btn btn-sm btn-outline-primary"><i class="fa fa-link mr-1"></i> Link Lead</button>
                        </form>
                    <?php else: ?>
                        <p class="text-muted mb-0">Not linked to a lead.</p>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        </div>

        <div class="card">
            <div class="card-header"><h5 class="card-title">Status Actions</h5></div>
            <div class="card-body">
                <?php if ($status === 'new'): ?>
                    <form method="POST" action="order-action.php" class="mb-2">
                        <?php echo csrf_field(); ?>
                        <input type="hidden" name="action" value="start_review">
                        <input type="hidden" name="order_id" value="<?php echo $id; ?>">
                        <button type="submit" class="btn btn-primary btn-block"><i class="fa fa-search mr-1"></i> Start Review</button>
                    </form>
                <?php endif; ?>

                <?php if ($status === 'under_review'): ?>
                    <form method="POST" action="order-action.php" class="mb-3">
                        <?php echo csrf_field(); ?>
                        <input type="hidden" name="action" value="confirm">
                        <input type="hidden" name="order_id" value="<?php echo $id; ?>">
                        <?php if ($has_active_reservation): ?>
                            <div class="alert alert-info py-2 small">
                                Stock is already reserved at the shop and store selected during verification.
                                <strong class="d-block mt-1"><?php echo e(($order['shop_name'] ?: 'Shop #' . (int)$order['shop_id']) . ' / ' . ($order['store_name'] ?: 'Store #' . (int)$order['warehouse_id'])); ?></strong>
                            </div>
                            <input type="hidden" name="shop_id" value="<?php echo (int)$order['shop_id']; ?>">
                            <input type="hidden" name="warehouse_id" value="<?php echo (int)$order['warehouse_id']; ?>">
                        <?php else: ?>
                            <div class="form-group">
                                <label class="small">Confirm Shop <span class="text-danger">*</span></label>
                                <select name="shop_id" class="form-control form-control-sm" id="confirmShop" required>
                                    <option value="">— Select shop —</option>
                                    <?php foreach ($shops as $shop): ?>
                                        <option value="<?php echo (int)$shop['shop_id']; ?>" <?php echo (int)$order['shop_id'] === (int)$shop['shop_id'] ? 'selected' : ''; ?>><?php echo e($shop['name']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="form-group">
                                <label class="small">Confirm Store <span class="text-danger">*</span></label>
                                <select name="warehouse_id" class="form-control form-control-sm" id="confirmStore" required>
                                    <option value="">— Select store —</option>
                                    <?php foreach ($stores as $store): ?>
                                        <option value="<?php echo (int)$store['warehouse_id']; ?>" data-shop="<?php echo (int)$store['shop_id']; ?>"
                                            <?php echo (int)$order['warehouse_id'] === (int)$store['warehouse_id'] ? 'selected' : ''; ?>>
                                            <?php echo e(store_label($store)); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        <?php endif; ?>
                        <div class="form-group">
                            <label class="small">Confirmation Note</label>
                            <input type="text" name="note" class="form-control form-control-sm" maxlength="255" placeholder="Price/availability checked with customer">
                        </div>
                        <button type="submit" class="btn btn-primary btn-block" onclick="return confirm('Confirm this order<?php echo $has_active_reservation ? ' and keep its existing stock reservation' : ' and reserve the requested stock'; ?>? Physical stock will not be deducted.');">
                            <i class="fa fa-check mr-1"></i> <?php echo $has_active_reservation ? 'Confirm Order' : 'Confirm &amp; Reserve Stock'; ?>
                        </button>
                    </form>
                <?php endif; ?>

                <?php if ($status === 'confirmed'): ?>
                    <form method="POST" action="order-action.php" class="mb-3">
                        <?php echo csrf_field(); ?>
                        <input type="hidden" name="action" value="fulfil">
                        <input type="hidden" name="order_id" value="<?php echo $id; ?>">
                        <div class="form-group">
                            <label class="small">Existing Invoice <span class="text-danger">*</span></label>
                            <select name="invoice_id" class="form-control form-control-sm" required>
                                <option value="">— Select invoice —</option>
                                <?php foreach ($invoices as $inv): ?>
                                    <option value="<?php echo (int)$inv['invoice_id']; ?>">
                                        <?php echo e($inv['invoice_number'] . ' — ' . money($inv['total'], $cur) . ' — ' . ($inv['status_name'] ?: 'Unknown')); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <small class="text-muted">Use this for an invoice that already exists. Its items and store must exactly match this order. Fulfilment consumes the reservation without deducting stock again.</small>
                        </div>
                        <button type="submit" class="btn btn-success btn-block" onclick="return confirm('Mark this order fulfilled and consume its stock reservation?');">
                            <i class="fa fa-check-circle mr-1"></i> Link Existing Invoice &amp; Fulfil
                        </button>
                    </form>
                <?php endif; ?>

                <?php if (in_array($status, ['new', 'under_review'], true)): ?>
                    <form method="POST" action="order-action.php" class="mb-2">
                        <?php echo csrf_field(); ?>
                        <input type="hidden" name="action" value="reject">
                        <input type="hidden" name="order_id" value="<?php echo $id; ?>">
                        <div class="form-group">
                            <label class="small">Rejection Reason <span class="text-danger">*</span></label>
                            <input type="text" name="reason" class="form-control form-control-sm" maxlength="255" required>
                        </div>
                        <button type="submit" class="btn btn-outline-danger btn-block"><i class="fa fa-times mr-1"></i> Reject Order</button>
                    </form>
                <?php endif; ?>

                <?php if (in_array($status, ['new', 'under_review', 'confirmed'], true)): ?>
                    <form method="POST" action="order-action.php">
                        <?php echo csrf_field(); ?>
                        <input type="hidden" name="action" value="cancel">
                        <input type="hidden" name="order_id" value="<?php echo $id; ?>">
                        <div class="form-group">
                            <label class="small">Cancellation Reason <span class="text-danger">*</span></label>
                            <input type="text" name="reason" class="form-control form-control-sm" maxlength="255" required>
                        </div>
                        <button type="submit" class="btn btn-outline-secondary btn-block"><i class="fa fa-ban mr-1"></i> Cancel Order</button>
                    </form>
                <?php endif; ?>

                <?php if (!order_allowed_transitions($status)): ?>
                    <p class="text-muted mb-0 small">This order is closed. No further status changes are allowed.</p>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<script>
    $(function () {
        function filterStores() {
            var shop = $('#confirmShop').val();
            $('#confirmStore option').each(function () {
                if (!$(this).val()) return;
                $(this).prop('disabled', !!shop && $(this).data('shop') != shop);
            });
            var selected = $('#confirmStore option:selected');
            if (selected.val() && selected.prop('disabled')) $('#confirmStore').val('');
        }
        $('#confirmShop').on('change', filterStores);
        filterStores();
    });
</script>

<?php require_once 'footer.php'; ?>
