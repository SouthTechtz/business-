<?php
require_once 'header.php';
require_once 'auth_check.php';
require_permission('manage_leads');
if (!tenant_admin_scope() || !can('manage_orders')) {
    http_response_code(403);
    die('Only an authorized system administrator can verify order requests.');
}

$id = (int)($_GET['id'] ?? 0);
$lead = fetch_one(
    "SELECT l.*, c.customer_number, c.mobile, c.phone contact_phone
     FROM leads l LEFT JOIN contacts c ON c.contact_id = l.contact_id AND c.tenant_id = l.tenant_id
     WHERE l.lead_id = ? AND l.tenant_id = ?",
    $id, (int)$current_user->tenant_id
);
$request = $lead ? fetch_one(
    "SELECT r.*, o.order_number FROM storefront_lead_requests r
     LEFT JOIN orders o ON o.order_id = r.converted_order_id AND o.tenant_id = r.tenant_id
     WHERE r.lead_id = ? AND r.tenant_id = ?",
    $id, (int)$current_user->tenant_id
) : null;
if (!$lead || !$request || $lead['source'] !== 'storefront' || $lead['status'] === 'lost') {
    flash_set('danger', 'That lead does not have an active storefront order request to verify.');
    redirect('leads.php');
}

$shops = accessible_shops(null, false);
$shop_id = (int)($_GET['shop_id'] ?? 0);
$warehouse_id = (int)($_GET['warehouse_id'] ?? 0);
if ($shop_id > 0 && !shop_is_accessible($shop_id, false)) $shop_id = 0;
$stores = $shop_id > 0 ? accessible_stores($shop_id, false) : [];
$store = $warehouse_id > 0 ? find_accessible_store($warehouse_id, false) : null;
if (!$store || ($shop_id > 0 && (int)$store['shop_id'] !== $shop_id)) {
    $warehouse_id = 0;
    $store = null;
}
if ($store && $shop_id === 0) {
    $shop_id = (int)$store['shop_id'];
    $stores = accessible_stores($shop_id, false);
}

$items = fetch_all(
    "SELECT ri.*, p.sale_price AS current_price, p.is_service,
            COALESCE(s.quantity, 0) AS on_hand_stock,
            COALESCE(r.reserved_qty, 0) AS reserved_stock,
            GREATEST(0, COALESCE(s.quantity, 0) - COALESCE(r.reserved_qty, 0)) AS available_stock
     FROM storefront_lead_request_items ri
     LEFT JOIN products p ON p.product_id = ri.product_id AND p.tenant_id = ri.tenant_id
     LEFT JOIN inventory_stock s ON s.product_id = ri.product_id AND s.tenant_id = ri.tenant_id AND s.warehouse_id = ?
     LEFT JOIN (
         SELECT product_id, SUM(quantity) reserved_qty
           FROM order_stock_reservations
          WHERE tenant_id = ? AND warehouse_id = ? AND status = 'active'
          GROUP BY product_id
     ) r ON r.product_id = ri.product_id
     WHERE ri.request_id = ? AND ri.tenant_id = ? ORDER BY ri.line_no",
    $warehouse_id, (int)$current_user->tenant_id, $warehouse_id,
    (int)$request['request_id'], (int)$current_user->tenant_id
);
$needed_by_product = [];
$available_by_product = [];
foreach ($items as $item) {
    $product_id = (int)$item['product_id'];
    if ((int)$item['is_service'] === 1 || $product_id <= 0) continue;
    $needed_by_product[$product_id] = ($needed_by_product[$product_id] ?? 0) + (float)$item['quantity'];
    $available_by_product[$product_id] = (float)($item['available_stock'] ?? 0);
}
$short_products = [];
if ($warehouse_id > 0) {
    foreach ($needed_by_product as $product_id => $needed_qty) {
        if (($available_by_product[$product_id] ?? 0) + 0.0001 < $needed_qty) {
            $short_products[$product_id] = true;
        }
    }
}
$insufficient = (bool)$short_products;
$page_title = 'Verify Order Request';
require_once 'navbar.php';
require_once 'sidebar.php';
?>
<div class="page-head">
    <div><h1 class="page-title">Verify Request <?php echo e($lead['lead_number']); ?></h1>
        <div class="page-sub">Review the customer, requested products, and supplying location before creating the order and reserving stock.</div></div>
    <a href="lead-view.php?id=<?php echo $id; ?>" class="btn btn-light"><i class="fa fa-arrow-left mr-1"></i> Back to Lead</a>
</div>
<?php if ($f = flash_get()): ?>
    <div class="alert alert-<?php echo e($f['type']); ?>"><?php echo e($f['message']); ?></div>
<?php endif; ?>
<div class="row"><div class="col-lg-8 mb-3">
    <div class="card"><div class="card-header"><h5 class="card-title">Requested Products</h5></div><div class="card-body p-0"><div class="table-responsive"><table class="table mb-0">
        <thead><tr><th>Product</th><th class="text-right">Qty</th><th class="text-right">Request Price</th><th class="text-right">Current Price</th><th class="text-right">Available in Selected Store</th></tr></thead>
        <tbody><?php foreach ($items as $item): ?>
            <?php $low = $warehouse_id > 0 && !(int)$item['is_service'] && isset($short_products[(int)$item['product_id']]); ?>
            <tr><td><?php echo e($item['product_name_snapshot']); ?><small class="text-muted d-block"><?php echo e($item['product_code_snapshot']); ?></small></td>
                <td class="text-right"><?php echo e((float)$item['quantity']); ?></td><td class="text-right"><?php echo e(money($item['unit_price'], $request['currency_code'])); ?></td>
                <td class="text-right"><?php echo $item['current_price'] === null ? '—' : e(money($item['current_price'], $request['currency_code'])); ?></td>
                <td class="text-right <?php echo $low ? 'text-danger font-weight-bold' : ''; ?>"><?php echo $warehouse_id ? e((float)($item['available_stock'] ?? 0)) : 'Select a store'; ?>
                    <?php if ($warehouse_id): ?><small class="d-block text-muted">On hand <?php echo e((float)($item['on_hand_stock'] ?? 0)); ?> · reserved <?php echo e((float)($item['reserved_stock'] ?? 0)); ?></small><?php endif; ?>
                    <?php if ($low): ?><small class="d-block">Insufficient</small><?php endif; ?>
                </td></tr>
        <?php endforeach; ?></tbody>
    </table></div></div></div>
    <?php if ($warehouse_id && $insufficient): ?><div class="alert alert-warning"><strong>selected shop has low stock for now</strong><br><small>Choose another store or reduce the requested quantity before verifying this order.</small></div><?php endif; ?>
</div><div class="col-lg-4 mb-3">
    <div class="card"><div class="card-header"><h5 class="card-title">Customer / Contact</h5></div><div class="card-body"><strong><?php echo e($lead['contact_name']); ?></strong><br><?php echo e($lead['phone'] ?: $lead['contact_phone'] ?: $lead['mobile'] ?: 'No phone recorded'); ?>
        <?php if ($lead['customer_number']): ?><small class="text-muted d-block">Customer #<?php echo e($lead['customer_number']); ?></small><?php endif; ?>
        <?php if ($lead['location']): ?><small class="text-muted d-block"><?php echo e($lead['location']); ?></small><?php endif; ?></div></div>
    <div class="card"><div class="card-header"><h5 class="card-title">Verification Location</h5></div><div class="card-body">
        <form method="GET" action="lead-verify.php" id="locationForm"><input type="hidden" name="id" value="<?php echo $id; ?>">
            <div class="form-group"><label>Responsible Shop <span class="text-danger">*</span></label><select name="shop_id" id="verifyShop" class="form-control" required><option value="">— Select shop —</option><?php foreach ($shops as $shop): ?><option value="<?php echo (int)$shop['shop_id']; ?>" <?php echo $shop_id === (int)$shop['shop_id'] ? 'selected' : ''; ?>><?php echo e($shop['name']); ?></option><?php endforeach; ?></select></div>
            <div class="form-group"><label>Supplying Store <span class="text-danger">*</span></label><select name="warehouse_id" id="verifyStore" class="form-control" required><option value="">— Select store —</option><?php foreach ($stores as $row): ?><option value="<?php echo (int)$row['warehouse_id']; ?>" <?php echo $warehouse_id === (int)$row['warehouse_id'] ? 'selected' : ''; ?>><?php echo e(store_label($row)); ?></option><?php endforeach; ?></select></div>
            <button type="submit" class="btn btn-outline-primary btn-block">Load Store Availability</button>
        </form><hr>
        <form method="POST" action="lead-action.php" onsubmit="return confirm('Verify this request, create the linked order, and reserve stock at the selected store?');">
            <?php echo csrf_field(); ?><input type="hidden" name="action" value="verify_request"><input type="hidden" name="lead_id" value="<?php echo $id; ?>"><input type="hidden" name="shop_id" value="<?php echo $shop_id; ?>"><input type="hidden" name="warehouse_id" value="<?php echo $warehouse_id; ?>">
            <button type="submit" class="btn btn-success btn-block" <?php echo (!$shop_id || !$warehouse_id || $insufficient) ? 'disabled' : ''; ?>><i class="fa fa-check mr-1"></i> Verify & Create Order</button>
        </form><small class="text-muted d-block mt-2">The order will be created under review using the request’s saved prices. Stock is reserved at the selected store immediately. Physical stock is deducted only when the order is fulfilled by an invoice.</small>
    </div></div>
</div></div>
<script>$(function(){ $('#verifyShop').on('change', function(){ $('#verifyStore').val(''); $('#locationForm').trigger('submit'); }); });</script>
<?php require_once 'footer.php'; ?>
