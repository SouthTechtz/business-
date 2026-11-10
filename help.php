<?php
require_once __DIR__ . '/storefront.php';

$sf_tenant = storefront_tenant();
if (!$sf_tenant) {
    http_response_code(503);
    exit('The storefront is not configured.');
}
$sf_settings = storefront_settings($sf_tenant);
$sf_company = storefront_company_name($sf_tenant, $sf_settings);
$sf_currency = storefront_currency($sf_tenant, $sf_settings);
$sf_tenant_id = (int)$sf_tenant['tenant_id'];
$sf_search_q = '';
$sf_category = 0;

$sf_phone = trim((string)($sf_settings['company_phone'] ?? ''));
$sf_email = trim((string)($sf_settings['company_email'] ?? ''));

$page_title = 'Help';
$sf_meta_description = 'Help with ordering, delivery, returns and wholesale accounts at ' . $sf_company . '.';
$sf_canonical_url = storefront_absolute_url('help.php');

require __DIR__ . '/storefront-header.php';
?>
<main class="sf-main" id="sf-main">
    <div class="sf-container sf-page">
        <nav aria-label="Breadcrumb">
            <ol class="sf-breadcrumb">
                <li><a href="index.php">Home</a></li>
                <li aria-current="page">Help</li>
            </ol>
        </nav>

        <div class="sf-info-hero">
            <span class="sf-info-eyebrow">Help centre</span>
            <h1>How can we help?</h1>
            <p>Answers to common questions about ordering, delivery, returns and wholesale accounts.
               You can also contact our team directly.</p>
        </div>

        <div class="sf-help-grid">
            <section class="sf-help-card">
                <h2><i class="fa fa-shopping-basket" aria-hidden="true"></i> How ordering works</h2>
                <p>Add products to the cart and send an order request. No payment is taken online. Our team checks stock,
                   confirms pricing and delivery, then processes your order.</p>
            </section>
            <section class="sf-help-card">
                <h2><i class="fa fa-truck" aria-hidden="true"></i> Delivery</h2>
                <p>Delivery availability, timing and any fee are confirmed after your request. Provide the correct
                   delivery location when you send the request.</p>
            </section>
            <section class="sf-help-card">
                <h2><i class="fa fa-undo" aria-hidden="true"></i> Returns</h2>
                <p>Contact us before returning an item. Report damaged or incorrect items promptly so we can resolve
                   the issue with you.</p>
            </section>
            <section class="sf-help-card">
                <h2><i class="fa fa-briefcase" aria-hidden="true"></i> Wholesale accounts</h2>
                <p>Businesses can register a wholesale account using their name, phone number and location.
                   After registering, sign in to view order history and create new requests.</p>
            </section>
            <section class="sf-help-card">
                <h2><i class="fa fa-lock" aria-hidden="true"></i> Payments</h2>
                <p>This site does not take online payments. Payment arrangements are confirmed with our team after
                   your order request is reviewed.</p>
            </section>
            <section class="sf-help-card">
                <h2><i class="fa fa-user" aria-hidden="true"></i> Signing in</h2>
                <p>Wholesale customers can sign in with their registered name or phone number and password.
                   Use <a href="login.php">the sign-in page</a> or register from the Wholesales tab.</p>
            </section>
        </div>

        <section class="sf-info-section">
            <h2>Still need help?</h2>
            <div class="sf-info-contact">
                <?php if ($sf_phone !== ''): ?>
                    <p><i class="fa fa-phone" aria-hidden="true"></i> <a href="tel:<?php echo e(preg_replace('/[^0-9+]/', '', $sf_phone)); ?>"><?php echo e($sf_phone); ?></a></p>
                <?php endif; ?>
                <?php if ($sf_email !== ''): ?>
                    <p><i class="fa fa-envelope" aria-hidden="true"></i> <a href="mailto:<?php echo e($sf_email); ?>"><?php echo e($sf_email); ?></a></p>
                <?php endif; ?>
            </div>
            <div class="sf-info-actions">
                <a class="sf-btn sf-btn-primary" href="index.php?all=1#catalogue">Browse the catalogue</a>
                <a class="sf-btn" href="wholesale-registration.php">Register for wholesale</a>
            </div>
        </section>
    </div>
</main>
<?php require __DIR__ . '/storefront-footer.php'; ?>
