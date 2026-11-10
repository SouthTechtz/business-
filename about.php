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
$sf_address = trim((string)($sf_settings['company_address'] ?? ''));

$page_title = 'About us';
$sf_meta_description = 'Learn about ' . $sf_company . ': who we are, how we supply retail and wholesale customers, and how ordering works.';
$sf_canonical_url = storefront_absolute_url('about.php');

require __DIR__ . '/storefront-header.php';
?>
<main class="sf-main" id="sf-main">
    <div class="sf-container sf-page">
        <nav aria-label="Breadcrumb">
            <ol class="sf-breadcrumb">
                <li><a href="index.php">Home</a></li>
                <li aria-current="page">About us</li>
            </ol>
        </nav>

        <div class="sf-info-hero">
            <span class="sf-info-eyebrow">About us</span>
            <h1><?php echo e($sf_company); ?></h1>
            <p>We supply households, shops and businesses with everyday products at fair prices.
               Browse the live catalogue, send an order request and our team confirms stock, pricing and delivery with you.</p>
        </div>

        <div class="sf-info-grid">
            <section class="sf-info-card">
                <i class="fa fa-bullseye" aria-hidden="true"></i>
                <h2>Our mission</h2>
                <p>Make everyday products easy to find and easy to order, for both retail and wholesale customers,
                   with clear prices and real stock information.</p>
            </section>
            <section class="sf-info-card">
                <i class="fa fa-handshake-o" aria-hidden="true"></i>
                <h2>Retail &amp; wholesale</h2>
                <p>Retail customers can shop online or in person. Businesses can register a wholesale account
                   to request volume pricing and manage repeat orders from one place.</p>
            </section>
            <section class="sf-info-card">
                <i class="fa fa-check-circle" aria-hidden="true"></i>
                <h2>How we work</h2>
                <p>No online payment is taken. You send an order request, our team checks availability and confirms
                   the final price and delivery arrangements before the order is fulfilled.</p>
            </section>
        </div>

        <section class="sf-info-section">
            <h2>Visit or contact us</h2>
            <div class="sf-info-contact">
                <?php if ($sf_phone !== ''): ?>
                    <p><i class="fa fa-phone" aria-hidden="true"></i> <a href="tel:<?php echo e(preg_replace('/[^0-9+]/', '', $sf_phone)); ?>"><?php echo e($sf_phone); ?></a></p>
                <?php endif; ?>
                <?php if ($sf_email !== ''): ?>
                    <p><i class="fa fa-envelope" aria-hidden="true"></i> <a href="mailto:<?php echo e($sf_email); ?>"><?php echo e($sf_email); ?></a></p>
                <?php endif; ?>
                <?php if ($sf_address !== ''): ?>
                    <p><i class="fa fa-map-marker" aria-hidden="true"></i> <?php echo e($sf_address); ?></p>
                <?php endif; ?>
            </div>
            <div class="sf-info-actions">
                <a class="sf-btn sf-btn-primary" href="index.php?all=1#catalogue">Browse the catalogue</a>
                <a class="sf-btn" href="wholesale-registration.php">Register for wholesale</a>
                <a class="sf-btn" href="help.php">Get help</a>
            </div>
        </section>
    </div>
</main>
<?php require __DIR__ . '/storefront-footer.php'; ?>
