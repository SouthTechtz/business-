<?php
require_once 'header.php';
require_once 'auth_check.php';
require_permission_any(['manage_godown', 'approve_godown_transfers', 'dispatch_godown_transfers', 'receive_godown_transfers', 'request_godown_transfers']);
$page_title = 'Stock Transfers';

$tenant_id = (int)$current_user->tenant_id;
$statuses = godown_transfer_statuses();
$status = (string)($_GET['status'] ?? '');
if ($status !== '' && !isset($statuses[$status])) {
    $status = '';
}
$issue = (string)($_GET['issue'] ?? '') === '1';
$shop_id = (int)($_GET['shop'] ?? 0);
$shops = accessible_shops(null, true);
$shop_ids = array_map(fn($s) => (int)$s['shop_id'], $shops);
if ($shop_id > 0 && !in_array($shop_id, $shop_ids, true)) {
    $shop_id = 0;
}
$date_from = trim((string)($_GET['from'] ?? ''));
$date_to = trim((string)($_GET['to'] ?? ''));
$valid_date = function (string $value): bool {
    if ($value === '') return true;
    $date = DateTime::createFromFormat('Y-m-d', $value);
    return $date && $date->format('Y-m-d') === $value;
};
if (!$valid_date($date_from)) $date_from = '';
if (!$valid_date($date_to)) $date_to = '';
$q = trim((string)($_GET['q'] ?? ''));

$sql = "SELECT t.*,
               s.name destination_shop_name, s.code destination_shop_code,
               w.name destination_store_name,
               sw.name source_warehouse_name, sw.code source_warehouse_code, sw.location_type source_location_type,
               ssw.name source_shop_name,
               TRIM(CONCAT(COALESCE(ur.first_name, ''), ' ', COALESCE(ur.last_name, ''))) requested_by_name,
               ur.username requested_by_username,
               (SELECT COUNT(*) FROM stock_transfer_items ti
                 WHERE ti.transfer_id = t.transfer_id AND ti.tenant_id = t.tenant_id) item_count,
               (SELECT COALESCE(SUM(ti.requested_quantity), 0) FROM stock_transfer_items ti
                 WHERE ti.transfer_id = t.transfer_id AND ti.tenant_id = t.tenant_id) requested_quantity,
               (SELECT COALESCE(SUM(ti.dispatched_quantity), 0) FROM stock_transfer_items ti
                 WHERE ti.transfer_id = t.transfer_id AND ti.tenant_id = t.tenant_id) dispatched_quantity,
               (SELECT COALESCE(SUM(ti.received_quantity), 0) FROM stock_transfer_items ti
                 WHERE ti.transfer_id = t.transfer_id AND ti.tenant_id = t.tenant_id) received_quantity
        FROM stock_transfers t
        JOIN shops s ON s.shop_id = t.destination_shop_id AND s.tenant_id = t.tenant_id
        JOIN warehouses w ON w.warehouse_id = t.destination_warehouse_id AND w.tenant_id = t.tenant_id
        JOIN warehouses sw ON sw.warehouse_id = t.source_warehouse_id AND sw.tenant_id = t.tenant_id
        LEFT JOIN shops ssw ON ssw.shop_id = sw.shop_id AND ssw.tenant_id = sw.tenant_id
        LEFT JOIN users ur ON ur.user_id = t.requested_by
        WHERE t.tenant_id = ?";
$params = [$tenant_id];

if (!tenant_admin_scope()) {
    $destination_shop_ids = accessible_shop_ids(null, true);
    // Pin source-side scope to non-Godown stores. Godown oversight staff get
    // the Godown source added below; pure requesters must only see transfers
    // for shops they can access, never every request from the same Godown.
    $source_warehouse_ids = accessible_warehouse_ids(null, true, false) ?? [];
    $godown_oversight = can('manage_godown')
        || can('view_godown')
        || can('approve_godown_transfers')
        || can('dispatch_godown_transfers')
        || can('receive_godown_transfers');
    if ($godown_oversight) {
        $source_warehouse_ids = accessible_warehouse_ids(null, true, true) ?? [];
    }
    $scope_parts = [];
    if ($destination_shop_ids) {
        $scope_parts[] = 't.destination_shop_id IN (' . implode(',', array_map('intval', $destination_shop_ids)) . ')';
    }
    if ($source_warehouse_ids) {
        $scope_parts[] = 't.source_warehouse_id IN (' . implode(',', array_map('intval', $source_warehouse_ids)) . ')';
    }
    $sql .= $scope_parts ? ' AND (' . implode(' OR ', $scope_parts) . ')' : ' AND 1 = 0';
}

if ($status !== '') {
    $sql .= ' AND t.status = ?';
    $params[] = $status;
}
if ($issue) {
    $sql .= ' AND t.has_discrepancy = 1';
}
if ($shop_id > 0) {
    $sql .= ' AND t.destination_shop_id = ?';
    $params[] = $shop_id;
}
if ($date_from !== '') {
    $sql .= ' AND t.requested_at >= ?';
    $params[] = $date_from . ' 00:00:00';
}
if ($date_to !== '') {
    $sql .= ' AND t.requested_at <= ?';
    $params[] = $date_to . ' 23:59:59';
}
if ($q !== '') {
    $like = '%' . $q . '%';
    $sql .= " AND (t.transfer_number LIKE ? OR s.name LIKE ? OR w.name LIKE ? OR sw.name LIKE ? OR ur.first_name LIKE ?
                  OR ur.last_name LIKE ? OR ur.username LIKE ?
                  OR EXISTS (SELECT 1 FROM stock_transfer_items qti
                              JOIN products qp ON qp.product_id = qti.product_id
                             WHERE qti.transfer_id = t.transfer_id AND qti.tenant_id = t.tenant_id
                               AND (qp.name LIKE ? OR qp.code LIKE ?)))";
    array_push($params, $like, $like, $like, $like, $like, $like, $like, $like, $like);
}
$sql .= ' ORDER BY t.requested_at DESC, t.transfer_id DESC';
$transfers = fetch_all($sql, ...$params);
if (can('manage_godown')) {
    ensure_central_godown($tenant_id);
}
$godown = find_active_godown();

$has_filter = $status !== '' || $issue || $shop_id > 0 || $date_from !== '' || $date_to !== '' || $q !== '';

require_once 'navbar.php';
require_once 'sidebar.php';
?>

<div class="page-head">
    <div>
        <h1 class="page-title">Stock Transfers</h1>
        <div class="page-sub"><?php echo count($transfers); ?> transfer(s) match the current filters. Approval does not move stock; dispatch deducts the source once; receipt adds only accepted quantities.</div>
    </div>
    <?php if ($godown && (can('manage_godown') || can('request_godown_transfers'))): ?>
        <a href="godown-transfer-add.php" class="btn btn-primary"><i class="fa fa-plus mr-1"></i> <?php echo can('manage_godown') ? 'New Transfer' : 'Request Stock'; ?></a>
    <?php endif; ?>
</div>

<ul class="nav page-tabs">
    <?php if (can('manage_godown')): ?>
    <li class="nav-item"><a class="nav-link" href="godown.php"><i class="fa fa-archive mr-1"></i> Godown</a></li>
    <?php endif; ?>
    <li class="nav-item"><a class="nav-link active" href="godown-transfers.php"><i class="fa fa-exchange mr-1"></i> Transfers</a></li>
    <?php if ($godown && (can('manage_godown') || can('request_godown_transfers'))): ?>
    <li class="nav-item"><a class="nav-link" href="godown-transfer-add.php"><i class="fa fa-plus mr-1"></i> <?php echo can('manage_godown') ? 'New Transfer' : 'Request Stock'; ?></a></li>
    <?php endif; ?>
</ul>

<?php if ($f = flash_get()): ?>
    <div class="alert alert-<?php echo e($f['type']); ?>"><?php echo e($f['message']); ?></div>
<?php endif; ?>

<div class="card">
    <div class="card-header">
        <form method="GET" action="godown-transfers.php" class="form-inline flex-wrap">
            <select name="status" class="form-control form-control-sm mr-2 mb-1">
                <option value="">All Statuses</option>
                <?php foreach ($statuses as $key => $label): ?>
                    <option value="<?php echo e($key); ?>" <?php echo $status === $key ? 'selected' : ''; ?>><?php echo e($label); ?></option>
                <?php endforeach; ?>
            </select>
            <select name="shop" class="form-control form-control-sm mr-2 mb-1">
                <option value="0">All Destination Shops</option>
                <?php foreach ($shops as $shop): ?>
                    <option value="<?php echo (int)$shop['shop_id']; ?>" <?php echo $shop_id === (int)$shop['shop_id'] ? 'selected' : ''; ?>>
                        <?php echo e($shop['name']); ?><?php echo (int)$shop['is_active'] ? '' : ' (inactive)'; ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <label class="mb-1 mr-2 font-weight-normal"><input type="checkbox" name="issue" value="1" <?php echo $issue ? 'checked' : ''; ?>> Discrepancy recorded</label>
            <input type="date" name="from" class="form-control form-control-sm mr-2 mb-1" value="<?php echo e($date_from); ?>" title="Requested from">
            <input type="date" name="to" class="form-control form-control-sm mr-2 mb-1" value="<?php echo e($date_to); ?>" title="Requested to">
            <input type="text" name="q" class="form-control form-control-sm mr-2 mb-1" value="<?php echo e($q); ?>" placeholder="Transfer, shop, store, product...">
            <button type="submit" class="btn btn-sm btn-primary mb-1"><i class="fa fa-filter mr-1"></i> Filter</button>
            <?php if ($has_filter): ?>
                <a href="godown-transfers.php" class="btn btn-sm btn-light mb-1 ml-2">Clear</a>
            <?php endif; ?>
        </form>
        <div class="input-group dt-search ml-auto">
            <div class="input-group-prepend"><span class="input-group-text"><i class="fa fa-search"></i></span></div>
            <input type="text" class="form-control dt-search-input" placeholder="Search transfers...">
        </div>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover data-table">
                <thead>
                    <tr>
                        <th>Transfer #</th><th>Source Location</th><th>Destination</th><th>Items</th>
                        <th>Status</th><th>Requested</th><th>Updated</th><th class="text-right">Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($transfers as $transfer): ?>
                    <tr>
                        <td class="font-weight-semibold">
                            <a href="godown-transfer-view.php?id=<?php echo (int)$transfer['transfer_id']; ?>"><?php echo e($transfer['transfer_number']); ?></a>
                            <?php if ((int)$transfer['has_discrepancy'] === 1): ?>
                                <br><small class="text-danger"><i class="fa fa-exclamation-triangle mr-1"></i>Discrepancy recorded</small>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php echo e($transfer['source_warehouse_name']); ?>
                            <br><small class="text-muted"><?php echo e($transfer['source_shop_name'] ?: ((string)$transfer['source_location_type'] === 'godown' ? 'Central Godown' : 'Store')); ?></small>
                        </td>
                        <td>
                            <?php echo e($transfer['destination_shop_name']); ?>
                            <br><small class="text-muted"><?php echo e($transfer['destination_store_name']); ?></small>
                        </td>
                        <td>
                            <?php echo (int)$transfer['item_count']; ?> line(s)
                            <br><small class="text-muted">
                                <?php if (in_array($transfer['status'], ['received', 'discrepancy'], true)): ?>
                                    <?php echo e(format_quantity($transfer['received_quantity'])); ?> accepted
                                <?php elseif ($transfer['status'] === 'dispatched'): ?>
                                    <?php echo e(format_quantity($transfer['dispatched_quantity'])); ?> in transit
                                <?php else: ?>
                                    <?php echo e(format_quantity($transfer['requested_quantity'])); ?> requested
                                <?php endif; ?>
                            </small>
                        </td>
                        <td>
                            <span class="badge badge-<?php echo e(godown_transfer_status_class($transfer['status'])); ?>"><?php echo e(godown_transfer_status_label($transfer['status'])); ?></span>
                            <?php if ($transfer['status'] === 'dispatched'): ?>
                                <br><small class="text-muted"><i class="fa fa-truck mr-1"></i>In transit</small>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php echo e(date('M d, Y H:i', strtotime($transfer['requested_at']))); ?>
                            <br><small class="text-muted"><?php echo e(trim((string)$transfer['requested_by_name']) ?: ($transfer['requested_by_username'] ?: 'Unknown user')); ?></small>
                        </td>
                        <td><?php echo e(date('M d, Y H:i', strtotime($transfer['updated_at']))); ?></td>
                        <td class="text-right">
                            <a href="godown-transfer-view.php?id=<?php echo (int)$transfer['transfer_id']; ?>" class="btn btn-action btn-light" title="View"><i class="fa fa-eye"></i></a>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$transfers): ?>
                    <tr><td colspan="8" class="text-center text-muted py-4">No transfers match the current filters.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php require_once 'footer.php'; ?>
