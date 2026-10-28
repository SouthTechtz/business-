<?php
$sf_footer_company = trim((string)($sf_settings['company_name'] ?? '')) ?: APP_NAME;
$sf_footer_phone = trim((string)($sf_settings['company_phone'] ?? ''));
$sf_footer_email = trim((string)($sf_settings['company_email'] ?? ''));
$sf_footer_address = trim((string)($sf_settings['company_address'] ?? ''));
$sf_footer_categories = storefront_categories($sf_tenant_id);
$sf_payment_methods = storefront_payment_methods($sf_tenant_id);
?>
<footer class="sf-footer" id="footer">
    <div class="sf-container">
        <div class="sf-footer-grid">
            <div class="sf-footer-about">
                <div class="sf-footer-brand"><?php echo e($sf_footer_company); ?></div>
                <p>Browse the catalogue and send an order request. The store confirms availability, final pricing, delivery and payment with you before an order is fulfilled.</p>
                <?php if ($sf_footer_phone !== ''): ?>
                    <p><a href="tel:<?php echo e(preg_replace('/[^0-9+]/', '', $sf_footer_phone)); ?>"><?php echo e($sf_footer_phone); ?></a></p>
                <?php endif; ?>
                <?php if ($sf_footer_email !== ''): ?>
                    <p><a href="mailto:<?php echo e($sf_footer_email); ?>"><?php echo e($sf_footer_email); ?></a></p>
                <?php endif; ?>
                <?php if ($sf_footer_address !== ''): ?>
                    <p><?php echo e($sf_footer_address); ?></p>
                <?php endif; ?>
            </div>

            <div>
                <h2 class="sf-footer-title">Shop</h2>
                <ul class="sf-footer-links">
                    <li><a href="index.php">All products</a></li>
                    <?php foreach (array_slice($sf_footer_categories, 0, 6) as $sf_footer_category): ?>
                        <li><a href="index.php?category=<?php echo (int)$sf_footer_category['category_id']; ?>#catalogue"><?php echo e($sf_footer_category['name']); ?></a></li>
                    <?php endforeach; ?>
                    <li><a href="cart.php">Cart</a></li>
                </ul>
            </div>

            <div>
                <h2 class="sf-footer-title">Ordering</h2>
                <ul class="sf-footer-links">
                    <li><a href="index.php#ordering">How ordering works</a></li>
                    <li><a href="index.php#delivery">Delivery</a></li>
                    <li><a href="index.php#returns">Returns</a></li>
                    <li><a href="index.php#privacy">Privacy</a></li>
                    <li><a href="product-feed.php?format=json">Product feed (JSON)</a></li>
                    <li><a href="product-feed.php?format=csv">Product feed (CSV)</a></li>
                </ul>
            </div>

            <div>
                <h2 class="sf-footer-title">Store updates</h2>
                <p class="sf-footer-note">Occasional catalogue and stock updates. Enter your email to subscribe.</p>
                <form class="sf-newsletter" id="newsletter" method="post" action="newsletter-subscribe.php">
                    <?php echo csrf_field(); ?>
                    <?php echo form_token_field(); ?>
                    <label class="sr-only" for="newsletter-email">Email address</label>
                    <input type="email" id="newsletter-email" name="email" maxlength="191" required
                           autocomplete="email" placeholder="you@example.com">
                    <button type="submit">Subscribe</button>
                </form>
                <p class="sf-footer-note">By subscribing you agree to receive store updates. Ask us to remove your address at any time.</p>
            </div>
        </div>

        <div class="sf-footer-policies" id="policies">
            <div id="ordering">
                <h2 class="sf-footer-title">How ordering works</h2>
                <p>Add products to the cart and send an order request. The store checks stock and confirms pricing, delivery and payment with you. No payment is taken online and no stock is reserved by submitting the request.</p>
            </div>
            <div id="delivery">
                <h2 class="sf-footer-title">Delivery</h2>
                <p>Delivery availability, timing and any fee are confirmed with you after your order request. The checkout form collects the delivery location for that confirmation.</p>
            </div>
            <div id="returns">
                <h2 class="sf-footer-title">Returns</h2>
                <p>Contact the store before returning an item. Return eligibility and timing are confirmed with your order. Products that are damaged or incorrect on delivery should be reported promptly.</p>
            </div>
            <div id="privacy">
                <h2 class="sf-footer-title">Privacy</h2>
                <p>We use the name, phone number and delivery location you submit to respond to your order request. We do not take payment details on this site.</p>
            </div>
        </div>

        <div class="sf-footer-bottom">
            <div>
                <span>&copy; <?php echo date('Y'); ?> <?php echo e($sf_footer_company); ?></span>
                <span class="sf-footer-sep">Prices in <?php echo e($sf_currency ?? storefront_currency($sf_tenant, $sf_settings)); ?>; staff confirm the final order.</span>
            </div>
            <div class="sf-payment-methods" aria-label="Payment methods accepted after order confirmation">
                <span>Pay after confirmation:</span>
                <?php foreach ($sf_payment_methods as $sf_payment_method): ?>
                    <strong><?php echo e($sf_payment_method['name']); ?></strong>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
</footer>
</body>
</html>
