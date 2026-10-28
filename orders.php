<?php
require_once 'header.php';
require_once 'auth_check.php';
require_permission('manage_orders');
$page_title = 'Orders';

$statuses = order_statuses();
$status = (string)($_GET['status'] ?? '');
if ($status !== '' && !isset($statuses[$status])) {
    $status = '';
}
$shop_id = (int)($_GET['shop'] ?? 0);
$shops = accessible_shops(null, true);
$shop_ids = array_map(fn($s) => (int)$s['shop_id'], $shops);
if ($shop_id > 0 && !in_array($shop_id, $shop_ids, true)) {
    $shop_id = 0;
}
$assigned = (string)($_GET['assigned'] ?? '');
$q = trim((string)($_GET['q'] ?? ''));

$sql = "SELECT o.*, s.name shop_name, s.code shop_code, w.name store_name, w.code store_code,
               c.first_name, c.last_name, c.company_name,
               uc.first_name creator_first, uc.last_name creator_last,
               ua.first_name assignee_first, ua.last_name assignee_last,
               (SELECT COUNT(*) FROM order_items oi WHERE oi.order_id = o.order_id AND oi.tenant_id = o.tenant_id) item_count,
               (SELECT COALESCE(SUM(oi.quantity), 0) FROM order_items oi WHERE oi.order_id = o.order_id AND oi.tenant_id = o.tenant_id) item_qty
        FROM orders o
        LEFT JOIN shops s ON s.shop_id = o.shop_id AND s.tenant_id = o.tenant_id
        LEFT JOIN warehouses w ON w.warehouse_id = o.warehouse_id AND w.tenant_id = o.tenant_id
        LEFT JOIN contacts c ON c.contact_id = o.contact_id AND c.tenant_id = o.tenant_id
        LEFT JOIN users uc ON uc.user_id = o.created_by
        LEFT JOIN users ua ON ua.user_id = o.assigned_to
        WHERE o.tenant_id = ?";
$params = [(int)$current_user->tenant_id];

[$scope_sql, $scope_params] = order_visibility_clause('o');
$sql .= $scope_sql;
$params = array_merge($params, $scope_params);

if ($status !== '') {
    $sql .= " AND o.status = ?";
    $params[] = $status;
}
if ($shop_id > 0) {
    $sql .= " AND o.shop_id = ?";
    $params[] = $shop_id;
}
if ($assigned === 'me') {
    $sql .= " AND o.assigned_to = ?";
    $params[] = (int)$current_user->user_id;
} elseif ($assigned === 'unassigned') {
    $sql .= " AND o.assigned_to IS NULL";
} elseif (ctype_digit($assigned) && (int)$assigned > 0) {
    $sql .= " AND o.assigned_to = ?";
    $params[] = (int)$assigned;
}
if ($q !== '') {
    $like = '%' . $q . '%';
    $sql .= " AND (o.order_number LIKE ? OR o.customer_name LIKE ? OR o.customer_phone LIKE ?
                  OR o.customer_location LIKE ? OR o.fulfilled_invoice_number LIKE ?)";
    array_push($params, $like, $like, $like, $like, $like);
}
$sql .= " ORDER BY o.created_at DESC, o.order_id DESC";

$orders = fetch_all($sql, ...$params);
$staff = users_with_permission('manage_orders');

require_once 'navbar.php';
require_once 'sidebar.php';
$cur = tenant_currency();
?>

<div class="page-head">
    <div>
        <h1 class="page-title">Orders</h1>
        <div class="page-sub"><?php echo count($orders); ?> order(s) match the current filters. Orders are requests — no stock is deducted here.</div>
    </div>
    <a href="order-add.php" class="btn btn-primary"><i class="fa fa-plus mr-1"></i> New Order</a>
</div>

<?php if ($f = flash_get()): ?>
    <div class="alert alert-<?php echo e($f['type']); ?>"><?php echo e($f['message']); ?></div>
<?php endif; ?>

<div class="card">
    <div class="card-header">
        <form method="GET" action="orders.php" class="form-inline flex-wrap">
            <select name="status" class="form-control form-control-sm mr-2 mb-1">
                <option value="">All Statuses</option>
                <?php foreach ($statuses as $key => $label): ?>
                    <option value="<?php echo e($key); ?>" <?php echo $status === $key ? 'selected' : ''; ?>><?php echo e($label); ?></option>
                <?php endforeach; ?>
            </select>
            <select name="shop" class="form-control form-control-sm mr-2 mb-1">
                <option value="0">All Shops</option>
                <?php foreach ($shops as $shop): ?>
                    <option value="<?php echo (int)$shop['shop_id']; ?>" <?php echo $shop_id === (int)$shop['shop_id'] ? 'selected' : ''; ?>>
                        <?php echo e($shop['name']); ?><?php echo (int)$shop['is_active'] ? '' : ' (inactive)'; ?>
                    </option>
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
            <input type="text" name="q" class="form-control form-control-sm mr-2 mb-1" value="<?php echo e($q); ?>" placeholder="Order, customer, phone...">
            <button type="submit" class="btn btn-sm btn-primary mb-1"><i class="fa fa-filter mr-1"></i> Filter</button>
            <?php if ($status !== '' || $shop_id > 0 || $assigned !== '' || $q !== ''): ?>
                <a href="orders.php" class="btn btn-sm btn-light mb-1 ml-2">Clear</a>
            <?php endif; ?>
        </form>
        <div class="input-group dt-search ml-auto">
            <div class="input-group-prepend"><span class="input-group-text"><i class="fa fa-search"></i></span></div>
            <input type="text" class="form-control dt-search-input" data-scope="orders" placeholder="Search orders...">
        </div>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover data-table">
                <thead>
                    <tr>
                        <th>Order #</th><th>Customer</th><th>Shop / Store</th><th>Items</th>
                        <th>Status</th><th class="text-right">Indicative Total</th><th>Responsible</th><th>Created</th><th class="text-right">Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($orders as $o): ?>
                    <tr>
                        <td class="font-weight-semibold"><a href="order-view.php?id=<?php echo (int)$o['order_id']; ?>"><?php echo e($o['order_number']); ?></a></td>
                        <td>
                            <?php echo e($o['customer_name']); ?>
                            <?php if ($o['customer_phone']): ?><br><small class="text-muted"><?php echo e($o['customer_phone']); ?></small><?php endif; ?>
                        </td>
                        <td><?php echo e($o['shop_name'] ? trim(($o['shop_name'] ?? '') . ' / ' . ($o['store_name'] ?? ''), ' /') : '—'); ?></td>
                        <td><?php echo (int)$o['item_count']; ?> line(s)<br><small class="text-muted"><?php echo e((float)$o['item_qty']); ?> units</small></td>
                        <td><span class="badge badge-<?php echo e(order_status_class($o['status'])); ?>"><?php echo e($statuses[$o['status']] ?? $o['status']); ?></span></td>
                        <td class="text-right"><?php echo e(money($o['total'], $o['currency_code'] ?: $cur)); ?></td>
                        <td>
                            <?php
                            $assignee = trim((string)($o['assignee_first'] ?? '') . ' ' . (string)($o['assignee_last'] ?? ''));
                            $creator = trim((string)($o['creator_first'] ?? '') . ' ' . (string)($o['creator_last'] ?? ''));
                            echo e($assignee ?: $creator ?: '—');
                            ?>
                        </td>
                        <td><?php echo e(date('M d, Y H:i', strtotime($o['created_at']))); ?></td>
                        <td class="text-right">
                            <a href="order-view.php?id=<?php echo (int)$o['order_id']; ?>" class="btn btn-action btn-light" title="View"><i class="fa fa-eye"></i></a>
                            <?php if (in_array($o['status'], ['new', 'under_review'], true)): ?>
                                <a href="order-edit.php?id=<?php echo (int)$o['order_id']; ?>" class="btn btn-action btn-light" title="Edit"><i class="fa fa-pencil"></i></a>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$orders): ?>
                    <tr><td colspan="9" class="text-center text-muted py-4">No orders match the current filters. <a href="order-add.php">Record the first order</a>.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php require_once 'footer.php'; ?>
