<?php
$sf_footer_company = storefront_company_name($sf_tenant, $sf_settings);
$sf_footer_phone = trim((string)($sf_settings['company_phone'] ?? ''));
$sf_footer_email = trim((string)($sf_settings['company_email'] ?? ''));
$sf_footer_address = trim((string)($sf_settings['company_address'] ?? ''));
$sf_footer_categories = storefront_categories($sf_tenant_id);
$sf_payment_methods = storefront_payment_methods($sf_tenant_id);
?>
<footer class="sf-footer" id="footer">
    <div class="sf-container">
        <div class="sf-footer-grid">
            <div class="sf-footer-about" id="contact">
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
<div id="sf-product-modal" class="sf-product-modal hidden" role="dialog" aria-modal="true" aria-labelledby="sf-modal-name">
    <div class="sf-modal-card">
        <div class="sf-modal-header">
            <h2 id="sf-modal-name"></h2>
            <button type="button" id="sf-modal-close" aria-label="Close">&times;</button>
        </div>
        <div class="sf-modal-content">
            <div class="sf-modal-image-wrap">
                <img id="sf-modal-image" src="" alt="">
                <span id="sf-modal-image-placeholder"><i class="fa fa-cube" aria-hidden="true"></i> No product image</span>
            </div>
            <div class="sf-modal-product-info">
                <div class="sf-modal-category" id="sf-modal-category"></div>
                <div class="sf-modal-price-row">
                    <p id="sf-modal-price"></p>
                    <span id="sf-modal-compare"></span>
                    <span id="sf-modal-unit"></span>
                </div>
                <p id="sf-modal-desc"></p>
                <div class="sf-modal-attributes" aria-label="Product details">
                    <div class="sf-modal-attribute" id="sf-detail-code-row"><span>Product code</span><strong id="sf-modal-code"></strong></div>
                    <div class="sf-modal-attribute" id="sf-detail-sku-row"><span>SKU</span><strong id="sf-modal-sku"></strong></div>
                    <div class="sf-modal-attribute" id="sf-detail-barcode-row"><span>Barcode</span><strong id="sf-modal-barcode"></strong></div>
                    <div class="sf-modal-attribute" id="sf-detail-brand-row"><span>Brand</span><strong id="sf-modal-brand"></strong></div>
                    <div class="sf-modal-attribute" id="sf-detail-size-row"><span>Size / pack</span><strong id="sf-modal-size"></strong></div>
                    <div class="sf-modal-attribute" id="sf-detail-color-row"><span>Colour</span><strong id="sf-modal-color"></strong></div>
                    <div class="sf-modal-attribute" id="sf-detail-type-row"><span>Type</span><strong id="sf-modal-type"></strong></div>
                </div>
                <p id="sf-modal-stock"></p>
                <form method="post" action="storefront-cart-action.php" class="sf-add-form">
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="action" value="add">
                    <input type="hidden" name="product_id" id="sf-modal-product-id" value="">
                    <div class="flex items-center gap-3">
                        <label class="text-sm font-medium text-gray-700" for="sf-modal-qty">Qty</label>
                        <input type="number" id="sf-modal-qty" name="quantity" value="1" min="1" max="999" class="w-20 rounded-lg border border-gray-200 px-3 py-2 text-sm">
                        <button type="submit" id="sf-modal-add" class="inline-flex items-center gap-2 rounded-full bg-brand-600 hover:bg-brand-700 text-white font-semibold px-5 py-2.5 shadow-sm transition">
                            <i class="fa fa-shopping-cart" aria-hidden="true"></i> Add to cart
                        </button>
                    </div>
                </form>
                <a id="sf-modal-link" href="#">View full details &rarr;</a>
            </div>
        </div>
    </div>
</div>
<script>
(function () {
    var modal = document.getElementById('sf-product-modal');
    if (!modal) return;
    var closeBtn = document.getElementById('sf-modal-close');
    var img = document.getElementById('sf-modal-image');
    var name = document.getElementById('sf-modal-name');
    var price = document.getElementById('sf-modal-price');
    var unit = document.getElementById('sf-modal-unit');
    var desc = document.getElementById('sf-modal-desc');
    var compare = document.getElementById('sf-modal-compare');
    var category = document.getElementById('sf-modal-category');
    var stock = document.getElementById('sf-modal-stock');
    var pid = document.getElementById('sf-modal-product-id');
    var qty = document.getElementById('sf-modal-qty');
    var addBtn = document.getElementById('sf-modal-add');
    var link = document.getElementById('sf-modal-link');
    var imagePlaceholder = document.getElementById('sf-modal-image-placeholder');
    var detailFields = {
        code: 'code',
        sku: 'sku',
        barcode: 'barcode',
        brand: 'brand',
        size: 'size',
        color: 'color',
        type: 'type'
    };

    function setDetail(field, value) {
        var row = document.getElementById('sf-detail-' + field + '-row');
        var detail = document.getElementById('sf-modal-' + field);
        var text = value ? String(value).trim() : '';
        detail.textContent = text;
        row.hidden = text === '';
    }

    function open(data) {
        name.textContent = data.name || '';
        price.textContent = data.price || '';
        unit.textContent = data.unit ? ('per ' + data.unit) : '';
        desc.textContent = data.description || 'No description available.';
        category.textContent = data.category || 'Catalogue';
        compare.textContent = data.compare || '';
        compare.hidden = !data.compare;
        Object.keys(detailFields).forEach(function (field) {
            setDetail(field, data[detailFields[field]]);
        });
        if (data.image) {
            img.src = data.image;
            img.alt = data.name || '';
            img.style.display = '';
            imagePlaceholder.style.display = 'none';
        } else {
            img.removeAttribute('src');
            img.alt = '';
            img.style.display = 'none';
            imagePlaceholder.style.display = 'flex';
        }
        pid.value = data.product_id || '';
        qty.value = 1;
        qty.max = data.max_qty && data.max_qty > 0 ? data.max_qty : 999;
        if (data.available === '1') {
            stock.textContent = (data.max_qty > 0 && data.max_qty <= 5) ? ('Low stock: ' + data.max_qty + ' left') : 'In stock';
            stock.className = 'sf-modal-stock is-in-stock';
            addBtn.disabled = false;
        } else {
            stock.textContent = 'Out of stock';
            stock.className = 'sf-modal-stock is-out-stock';
            addBtn.disabled = true;
        }
        link.href = data.url || '#';
        modal.classList.remove('hidden');
        document.body.style.overflow = 'hidden';
    }
    function close() {
        modal.classList.add('hidden');
        document.body.style.overflow = '';
    }
    closeBtn && closeBtn.addEventListener('click', close);
    modal.addEventListener('click', function (e) { if (e.target === modal) close(); });
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && !modal.classList.contains('hidden')) close(); });

    document.addEventListener('click', function (e) {
        var card = e.target.closest('[data-sf-product]');
        if (!card) return;
        e.preventDefault();
        try { open(JSON.parse(card.getAttribute('data-sf-product'))); }
        catch (err) { window.location.href = card.getAttribute('data-sf-url') || '#'; }
    });
    document.addEventListener('keydown', function (e) {
        if ((e.key === 'Enter' || e.key === ' ') ) {
            var card = e.target.closest && e.target.closest('[data-sf-product]');
            if (card) { e.preventDefault(); card.click(); }
        }
    });
})();
</script>

</body>
</html>
