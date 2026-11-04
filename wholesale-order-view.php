<?php
require_once __DIR__ . '/wholesale_auth.php';

$tenant_id = (int)$wholesale_user->tenant_id;
$contact_id = (int)($wholesaler['contact_id'] ?? 0);
$currency = tenant_currency();
$request_id = (int)($_GET['id'] ?? 0);
$request_row = $contact_id && $request_id ? wholesale_request($tenant_id, $request_id, $contact_id) : null;

if (!$request_row) {
    http_response_code(404);
    $page_title = 'Order request not found';
    require __DIR__ . '/wholesale-portal-header.php';
    ?>
    <div class="ws-empty ws-panel" style="margin-top:10px;">
        <i class="fa fa-search" aria-hidden="true"></i>
        <strong>Order request not found</strong>
        <p>This request does not exist or is not linked to your account.</p>
        <a class="ws-btn ws-btn-primary" href="wholesale-orders.php">Back to my orders</a>
    </div>
    <?php
    require __DIR__ . '/wholesale-portal-footer.php';
    exit;
}

$items = wholesale_request_items($tenant_id, $request_id);
$status_meta = wholesale_status_meta($request_row['status']);
$subtotal = 0.0;
foreach ($items as $item) $subtotal += (float)$item['line_total'];
if ($subtotal <= 0) $subtotal = (float)$request_row['subtotal'];

$has_order = !empty($request_row['order_id']);
$order_stage_meta = $has_order ? wholesale_order_stage_meta($request_row['order_stage'] ?? 'shop') : null;
$order_received = $has_order && !empty($request_row['order_customer_confirmed_at']);
$can_confirm_receipt = $has_order && wholesale_order_can_confirm_receipt([
    'status' => $request_row['order_status'] ?? '',
    'stage' => $request_row['order_stage'] ?? 'shop',
    'customer_confirmed_at' => $request_row['order_customer_confirmed_at'] ?? null,
]);

$page_title = 'Request ' . $request_row['lead_number'];
require __DIR__ . '/wholesale-portal-header.php';
?>
<div class="ws-page-head">
    <div>
        <h1>Request <?php echo e($request_row['lead_number']); ?></h1>
        <p>Submitted <?php echo e(date('d M Y, H:i', strtotime($request_row['created_at']))); ?></p>
    </div>
    <div class="ws-page-head-actions">
        <a class="ws-btn" href="wholesale-orders.php"><i class="fa fa-arrow-left" aria-hidden="true"></i> Back to orders</a>
        <a class="ws-btn ws-btn-primary" href="wholesale-order-new.php"><i class="fa fa-plus" aria-hidden="true"></i> New request</a>
    </div>
</div>

<div class="ws-grid-2">
    <section class="ws-panel" aria-labelledby="ws-request-items">
        <div class="ws-panel-head">
            <h2 id="ws-request-items">Items</h2>
            <span class="ws-badge is-neutral"><?php echo e($status_meta['label']); ?></span>
        </div>
        <?php if ($items): ?>
            <div class="ws-table-wrap">
                <table class="ws-table">
                    <thead>
                        <tr><th>Product</th><th>Code</th><th class="text-right">Qty</th><th class="text-right">Unit price</th><th class="text-right">Total</th></tr>
                    </thead>
                    <tbody>
                        <?php foreach ($items as $item): ?>
                            <tr>
                                <td>
                                    <strong><?php echo e($item['product_name_snapshot']); ?></strong>
                                    <?php if (!empty($item['unit_label_snapshot'])): ?><br><small class="text-muted"><?php echo e($item['unit_label_snapshot']); ?></small><?php endif; ?>
                                </td>
                                <td><?php echo e($item['product_code_snapshot']); ?></td>
                                <td class="text-right"><?php echo e(format_quantity($item['quantity'])); ?></td>
                                <td class="text-right"><?php echo e(money($item['unit_price'], $currency)); ?></td>
                                <td class="text-right"><strong><?php echo e(money($item['line_total'], $currency)); ?></strong></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                    <tfoot>
                        <tr>
                            <th colspan="4" class="text-right">Request total</th>
                            <th class="text-right"><?php echo e(money($subtotal, $request_row['currency_code'] ?: $currency)); ?></th>
                        </tr>
                    </tfoot>
                </table>
            </div>
        <?php else: ?>
            <div class="ws-panel-body"><p class="text-muted mb-0">No line items were recorded for this request.</p></div>
        <?php endif; ?>
    </section>

    <div>
        <section class="ws-panel" aria-labelledby="ws-request-details">
            <div class="ws-panel-head"><h2 id="ws-request-details">Details</h2></div>
            <div class="ws-panel-body">
                <dl class="ws-detail-list">
                    <div><dt>Request</dt><dd><?php echo e($request_row['lead_number']); ?></dd></div>
                    <div><dt>Status</dt><dd><?php echo e($status_meta['label']); ?></dd></div>
                    <div><dt>Submitted</dt><dd><?php echo e(date('d M Y, H:i', strtotime($request_row['created_at']))); ?></dd></div>
                    <div><dt>Delivery area</dt><dd><?php echo e($request_row['location'] ?: ($wholesaler['location'] ?: '—')); ?></dd></div>
                    <div><dt>Confirmed order</dt><dd><?php echo e($request_row['order_number'] ?: 'Not yet'); ?></dd></div>
                    <?php if ($has_order): ?>
                        <div><dt>Order total</dt><dd><?php echo e(money($request_row['order_total'], $request_row['order_currency_code'] ?: $currency)); ?></dd></div>
                        <div><dt>Order status</dt><dd><?php echo e(ucfirst((string)$request_row['order_status'])); ?></dd></div>
                        <div>
                            <dt>Order stage</dt>
                            <dd><span class="ws-badge <?php echo e($order_stage_meta['class']); ?>"><?php echo e($order_stage_meta['label']); ?></span></dd>
                        </div>
                        <div>
                            <dt>Delivery confirmation</dt>
                            <dd>
                                <?php if ($order_received): ?>
                                    <span class="ws-badge is-success">Received</span>
                                    <br><small class="text-muted"><?php echo e(date('d M Y, H:i', strtotime($request_row['order_customer_confirmed_at']))); ?></small>
                                <?php elseif ($can_confirm_receipt): ?>
                                    Awaiting your confirmation
                                <?php else: ?>
                                    <span class="text-muted">—</span>
                                <?php endif; ?>
                            </dd>
                        </div>
                    <?php endif; ?>
                </dl>
            </div>
        </section>

        <?php if ($can_confirm_receipt): ?>
            <section class="ws-panel" aria-labelledby="ws-confirm-receipt">
                <div class="ws-panel-head">
                    <div>
                        <h2 id="ws-confirm-receipt">Delivery confirmation</h2>
                        <p>Confirm once the order has arrived. This marks it as received and completed for your account.</p>
                    </div>
                </div>
                <div class="ws-panel-body">
                    <form method="POST" action="wholesale-order-confirm.php" onsubmit="return confirm('Confirm that you have received this order?');">
                        <?php echo csrf_field(); ?>
                        <input type="hidden" name="order_id" value="<?php echo (int)$request_row['order_id']; ?>">
                        <input type="hidden" name="request_id" value="<?php echo (int)$request_row['request_id']; ?>">
                        <button type="submit" class="ws-btn ws-btn-primary"><i class="fa fa-check" aria-hidden="true"></i> Confirm order received</button>
                    </form>
                </div>
            </section>
        <?php endif; ?>

        <?php if (!empty($request_row['notes'])): ?>
            <section class="ws-panel" aria-labelledby="ws-request-notes">
                <div class="ws-panel-head"><h2 id="ws-request-notes">Your notes</h2></div>
                <div class="ws-panel-body">
                    <p class="mb-0" style="font-size:13px;color:var(--ws-muted);white-space:pre-wrap;"><?php echo e($request_row['notes']); ?></p>
                </div>
            </section>
        <?php endif; ?>
    </div>
</div>
<?php require __DIR__ . '/wholesale-portal-footer.php'; ?>
