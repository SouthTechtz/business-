<?php
require_once __DIR__ . '/storefront.php';

$sf_tenant = storefront_tenant();
if (!$sf_tenant) {
    http_response_code(503);
    exit('The storefront is not configured.');
}
$sf_settings = storefront_settings($sf_tenant);
$sf_last_order = $_SESSION['storefront_last_order'] ?? null;
if (!is_array($sf_last_order) || empty($sf_last_order['lead_number'])) {
    flash_set('info', 'Submit an order request to see its confirmation here.');
    redirect('index.php');
}
$sf_phone = trim((string)($sf_settings['company_phone'] ?? ''));
$sf_email = trim((string)($sf_settings['company_email'] ?? ''));

$page_title = 'Order received';
$sf_meta_description = 'Your storefront order request was received.';
$sf_canonical_url = storefront_absolute_url('index.php');
require __DIR__ . '/storefront-header.php';
?>
<main class="sf-main" id="sf-main">
    <div class="sf-container sf-page">
        <nav aria-label="Breadcrumb">
            <ol class="sf-breadcrumb">
                <li><a href="index.php">Home</a></li>
                <li aria-current="page">Order received</li>
            </ol>
        </nav>

        <div class="sf-received">
            <h1>Thank you<?php echo $sf_last_order['customer_name'] !== '' ? ', ' . e($sf_last_order['customer_name']) : ''; ?>.</h1>
            <p class="sf-received-lead">Your order request has been received. The store will contact you to confirm availability, pricing and delivery.</p>

            <div class="sf-received-card">
                <div>
                    <span>Request reference</span>
                    <strong><?php echo e($sf_last_order['lead_number']); ?></strong>
                </div>
                <div>
                    <span>Customer number</span>
                    <strong><?php echo e($sf_last_order['customer_number']); ?></strong>
                </div>
                <div>
                    <span>Items</span>
                    <strong><?php echo (int)$sf_last_order['item_count']; ?></strong>
                </div>
                <div>
                    <span>Estimated total</span>
                    <strong><?php echo e(money($sf_last_order['total'], $sf_last_order['currency'])); ?></strong>
                </div>
            </div>

            <?php if (!empty($sf_last_order['tax'])): ?>
                <p class="sf-received-note">The estimated total includes <?php echo e(money($sf_last_order['tax'], $sf_last_order['currency'])); ?> estimated tax. Delivery charges are confirmed by the store.</p>
            <?php endif; ?>
            <p class="sf-received-note">Keep your request reference and customer number handy. Your request is now in the store's order queue. No payment has been taken and no stock has been reserved.</p>
            <?php if ($sf_phone !== '' || $sf_email !== ''): ?>
                <p class="sf-received-note">
                    Questions about this request?
                    <?php if ($sf_phone !== ''): ?>Call <a href="tel:<?php echo e(preg_replace('/[^0-9+]/', '', $sf_phone)); ?>"><?php echo e($sf_phone); ?></a>.<?php endif; ?>
                    <?php if ($sf_email !== ''): ?>Email <a href="mailto:<?php echo e($sf_email); ?>"><?php echo e($sf_email); ?></a>.<?php endif; ?>
                </p>
            <?php endif; ?>
            <div class="sf-received-actions">
                <a class="sf-btn sf-btn-primary" href="index.php#catalogue">Continue shopping</a>
                <?php if ($sf_phone !== ''): ?>
                    <a class="sf-btn sf-btn-outline" href="tel:<?php echo e(preg_replace('/[^0-9+]/', '', $sf_phone)); ?>">Call the store</a>
                <?php endif; ?>
            </div>
        </div>
    </div>
</main>
<?php require __DIR__ . '/storefront-footer.php'; ?>
