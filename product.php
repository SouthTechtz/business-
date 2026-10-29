<?php
require_once __DIR__ . '/storefront.php';

$sf_tenant = storefront_tenant();
if (!$sf_tenant) {
    http_response_code(503);
    exit('The storefront is not configured.');
}
$sf_settings = storefront_settings($sf_tenant);
$sf_company = trim((string)($sf_settings['company_name'] ?? '')) ?: APP_NAME;
$sf_currency = storefront_currency($sf_tenant, $sf_settings);
$sf_tenant_id = (int)$sf_tenant['tenant_id'];
$sf_search_q = trim((string)($_GET['q'] ?? ''));
if (mb_strlen($sf_search_q) > 100) $sf_search_q = mb_substr($sf_search_q, 0, 100);
$sf_categories = storefront_categories($sf_tenant_id);

$sf_product_id = (int)($_GET['id'] ?? 0);
$sf_product = $sf_product_id > 0 ? storefront_product($sf_tenant_id, $sf_product_id) : null;

// Check if request is an AJAX/Modal fetch call
$is_modal_request = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest' || isset($_GET['modal']);

if (!$sf_product) {
    http_response_code(404);
    if ($is_modal_request) {
        echo '<div class="sf-modal-error"><h3>Product not found</h3><p>This product may have been removed or is no longer available.</p></div>';
        exit;
    }
    $page_title = 'Product not found';
    $sf_meta_description = 'The requested product is not in this catalogue.';
    $sf_canonical_url = storefront_absolute_url('index.php');
    require __DIR__ . '/storefront-header.php';
    ?>
    <main class="sf-main" id="sf-main">
        <div class="sf-container sf-page">
            <nav aria-label="Breadcrumb">
                <ol class="sf-breadcrumb">
                    <li><a href="index.php">Home</a></li>
                    <li><a href="index.php#catalogue">Shop</a></li>
                    <li>Product not found</li>
                </ol>
            </nav>
            <div class="sf-empty">
                <h1>Product not found</h1>
                <p>This product may have been removed or is no longer available in the catalogue.</p>
                <a class="sf-btn sf-btn-primary" href="index.php#catalogue">View all products</a>
            </div>
        </div>
    </main>
    <?php
    require __DIR__ . '/storefront-footer.php';
    exit;
}

storefront_record_view((int)$sf_product['product_id']);
$sf_category = (int)($sf_product['category_id'] ?? 0);
$sf_available = storefront_available($sf_product);
$sf_max_quantity = storefront_max_quantity($sf_product);
$sf_image = storefront_image_path($sf_product['image'] ?? '');
$sf_image_absolute = storefront_image_url($sf_product['image'] ?? '');
$sf_unit = trim((string)($sf_product['unit_short'] ?? '')) ?: trim((string)($sf_product['unit_name'] ?? ''));
$sf_compare = isset($sf_product['compare_at_price']) ? (float)$sf_product['compare_at_price'] : 0.0;
$sf_is_sale = $sf_compare > (float)$sf_product['sale_price'];
$sf_related = storefront_related_products($sf_tenant_id, $sf_product, 4);
$sf_recently_viewed = storefront_recently_viewed($sf_tenant_id, 4, (int)$sf_product['product_id']);
$sf_product_url = storefront_product_url($sf_product);
$sf_canonical_url = storefront_absolute_url($sf_product_url);
$sf_meta_description = trim((string)$sf_product['description']);
if ($sf_meta_description === '') {
    $sf_meta_description = $sf_product['name'] . ' from ' . $sf_company . '. Request this product online and the store will confirm stock, price and delivery.';
} else {
    $sf_meta_description = mb_substr($sf_meta_description, 0, 155);
}
$page_title = (string)$sf_product['name'];

// If requested via modal/AJAX, output only the clean popup container content
if ($is_modal_request) {
    ?>
    <div class="sf-quickview-content">
        <button type="button" class="sf-modal-close" onclick="closeProductModal()" aria-label="Close">&times;</button>
        <div class="sf-product-layout">
            <div class="sf-product-gallery">
                <div class="sf-gallery-main">
                    <?php if ($sf_image !== ''): ?>
                        <img src="<?php echo e($sf_image); ?>" alt="<?php echo e($sf_product['name']); ?>" width="500" height="400">
                    <?php else: ?>
                        <span class="sf-product-placeholder">No product image</span>
                    <?php endif; ?>
                </div>
            </div>

            <div class="sf-detail">
                <div class="sf-detail-category">
                    <?php echo !empty($sf_product['category_name']) ? e($sf_product['category_name']) : 'Catalogue'; ?>
                </div>
                <h2><?php echo e($sf_product['name']); ?></h2>
                <div class="sf-product-code">SKU <?php echo e(($sf_product['sku'] ?: $sf_product['code'])); ?></div>

                <div class="sf-detail-price">
                    <span class="sf-price"><?php echo e(money($sf_product['sale_price'], $sf_currency)); ?></span>
                    <?php if ($sf_is_sale): ?><span class="sf-price-compare"><?php echo e(money($sf_compare, $sf_currency)); ?></span><?php endif; ?>
                    <?php if ($sf_unit !== ''): ?><span class="sf-unit">/ <?php echo e($sf_unit); ?></span><?php endif; ?>
                </div>

                <p class="sf-detail-summary">
                    <?php echo trim((string)$sf_product['description']) !== '' ? e(mb_substr((string)$sf_product['description'], 0, 160)) : 'Product details are available from the store.'; ?>
                </p>

                <form id="modalCartForm" method="post" action="storefront-cart-action.php" class="sf-add-form">
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="action" value="add">
                    <input type="hidden" name="product_id" value="<?php echo (int)$sf_product['product_id']; ?>">
                    <div class="sf-detail-buy">
                        <label class="sf-qty">
                            <span>Quantity</span>
                            <input type="number" name="quantity" value="1" min="1" max="<?php echo max(1, $sf_max_quantity); ?>" step="1" <?php echo $sf_available ? '' : 'disabled'; ?>>
                        </label>
                        <button type="submit" class="sf-btn sf-btn-primary" <?php echo $sf_available ? '' : 'disabled'; ?>>
                            <i class="fa fa-shopping-cart" aria-hidden="true"></i>
                            <?php echo $sf_available ? 'Add to cart' : 'Out of stock'; ?>
                        </button>
                    </div>
                </form>
                <p class="sf-detail-stock <?php echo $sf_available ? 'in-stock' : 'out-stock'; ?>">
                    <?php echo $sf_available ? 'Available in shop today.' : 'Out of stock.'; ?>
                </p>
            </div>
        </div>
    </div>
    <script>
        // Handle popup form submission via AJAX so it confirms and auto-closes smoothly
        document.getElementById('modalCartForm')?.addEventListener('submit', function(e) {
            e.preventDefault();
            let formData = new FormData(this);
            fetch(this.action, {
                method: 'POST',
                body: formData
            }).then(response => {
                if(response.ok) {
                    if (typeof closeProductModal === 'function') closeProductModal();
                    // Optional: trigger cart count refresh if available
                    location.reload(); 
                }
            }).catch(err => console.error(err));
        });
    </script>
    <?php
    exit;
}

// Full page render fallback if visited directly
$sf_schema_product = [
    '@type' => 'Product',
    'name' => (string)$sf_product['name'],
    'description' => trim((string)$sf_product['description']) ?: (string)$sf_product['name'],
    'sku' => (string)($sf_product['sku'] ?: $sf_product['code']),
    'mpn' => (string)($sf_product['sku'] ?: $sf_product['code']),
    'url' => $sf_canonical_url,
    'category' => (string)($sf_product['category_name'] ?? ''),
    'offers' => [
        '@type' => 'Offer',
        'url' => $sf_canonical_url,
        'priceCurrency' => $sf_currency,
        'price' => number_format((float)$sf_product['sale_price'], 2, '.', ''),
        'availability' => $sf_available ? 'https://schema.org/InStock' : 'https://schema.org/OutOfStock',
        'itemCondition' => 'https://schema.org/NewCondition',
        'seller' => [
            '@type' => 'Organization',
            'name' => $sf_company,
        ],
    ],
];
$sf_properties = [];
if ($sf_image_absolute !== '') $sf_schema_product['image'] = [$sf_image_absolute];
if (trim((string)($sf_product['variant_color'] ?? '')) !== '') $sf_properties[] = ['@type' => 'PropertyValue', 'name' => 'Colour', 'value' => (string)$sf_product['variant_color']];
if (trim((string)($sf_product['variant_size'] ?? '')) !== '') $sf_properties[] = ['@type' => 'PropertyValue', 'name' => 'Size', 'value' => (string)$sf_product['variant_size']];
if ($sf_unit !== '') $sf_properties[] = ['@type' => 'PropertyValue', 'name' => 'Unit', 'value' => $sf_unit];
if ($sf_properties) $sf_schema_product['additionalProperty'] = $sf_properties;

$sf_schema = [
    '@context' => 'https://schema.org',
    '@graph' => [
        $sf_schema_product,
        [
            '@type' => 'BreadcrumbList',
            'itemListElement' => [
                ['@type' => 'ListItem', 'position' => 1, 'name' => 'Home', 'item' => storefront_absolute_url('index.php')],
                ['@type' => 'ListItem', 'position' => 2, 'name' => 'Shop', 'item' => storefront_absolute_url('index.php#catalogue')],
                ['@type' => 'ListItem', 'position' => 3, 'name' => (string)$sf_product['name'], 'item' => $sf_canonical_url],
            ],
        ],
    ],
];
$sf_schema_json = json_encode($sf_schema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
$sf_schema_json = str_replace(['<', '>'], ['\\u003C', '\\u003E'], (string)$sf_schema_json);
$sf_head_extra = '<script type="application/ld+json">' . $sf_schema_json . '</script>';

require __DIR__ . '/storefront-header.php';
?>
<main class="sf-main" id="sf-main">
    <div class="sf-container sf-page">
        <nav aria-label="Breadcrumb">
            <ol class="sf-breadcrumb">
                <li><a href="index.php">Home</a></li>
                <li><a href="index.php#catalogue">Shop</a></li>
                <?php if (!empty($sf_product['category_name'])): ?>
                    <li><a href="index.php?category=<?php echo (int)$sf_product['category_id']; ?>#catalogue"><?php echo e($sf_product['category_name']); ?></a></li>
                <?php endif; ?>
                <li aria-current="page"><?php echo e($sf_product['name']); ?></li>
            </ol>
        </nav>

        <div class="sf-product-layout">
            <div class="sf-product-gallery">
                <div class="sf-gallery-main">
                    <?php if ($sf_image !== ''): ?>
                        <img id="sf-gallery-main" src="<?php echo e($sf_image); ?>" alt="<?php echo e($sf_product['name']); ?>" width="800" height="600">
                    <?php else: ?>
                        <span class="sf-product-placeholder">No product image</span>
                    <?php endif; ?>
                </div>
            </div>

            <div class="sf-detail">
                <div class="sf-detail-category">
                    <?php if (!empty($sf_product['category_name'])): ?>
                        <a href="index.php?category=<?php echo (int)$sf_product['category_id']; ?>#catalogue"><?php echo e($sf_product['category_name']); ?></a>
                    <?php else: ?>
                        Catalogue
                    <?php endif; ?>
                </div>
                <h1><?php echo e($sf_product['name']); ?></h1>
                <div class="sf-product-code">SKU <?php echo e(($sf_product['sku'] ?: $sf_product['code'])); ?></div>

                <div class="sf-detail-price">
                    <span class="sf-price"><?php echo e(money($sf_product['sale_price'], $sf_currency)); ?></span>
                    <?php if ($sf_is_sale): ?><span class="sf-price-compare"><?php echo e(money($sf_compare, $sf_currency)); ?></span><?php endif; ?>
                    <?php if ($sf_unit !== ''): ?><span class="sf-unit">/ <?php echo e($sf_unit); ?></span><?php endif; ?>
                </div>

                <p class="sf-detail-summary">
                    <?php echo trim((string)$sf_product['description']) !== '' ? e(mb_substr((string)$sf_product['description'], 0, 260)) : 'Product details are available from the store. Send an order request and the team will confirm the product specification with you.'; ?>
                </p>

                <dl class="sf-detail-meta">
                    <dt>Availability</dt>
                    <dd>
                        <?php if ($sf_available): ?>
                            <?php echo (int)$sf_product['is_service'] === 1 ? 'Service available' : 'In shop stock'; ?>
                            <?php if ((int)$sf_product['is_service'] !== 1): ?>
                                (<?php echo (int)$sf_max_quantity; ?> <?php echo e($sf_unit !== '' ? $sf_unit : 'units'); ?>)
                            <?php endif; ?>
                        <?php else: ?>
                            Out of stock
                        <?php endif; ?>
                    </dd>
                    <dt>Product code</dt>
                    <dd><?php echo e($sf_product['code']); ?></dd>
                    <?php if (trim((string)($sf_product['variant_color'] ?? '')) !== ''): ?><dt>Colour</dt><dd><?php echo e($sf_product['variant_color']); ?></dd><?php endif; ?>
                    <?php if (trim((string)($sf_product['variant_size'] ?? '')) !== ''): ?><dt>Size</dt><dd><?php echo e($sf_product['variant_size']); ?></dd><?php endif; ?>
                </dl>

                <form method="post" action="storefront-cart-action.php" class="sf-add-form">
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="action" value="add">
                    <input type="hidden" name="product_id" value="<?php echo (int)$sf_product['product_id']; ?>">
                    <div class="sf-detail-buy">
                        <label class="sf-qty">
                            <span>Quantity</span>
                            <input type="number" name="quantity" value="1" min="1" max="<?php echo max(1, $sf_max_quantity); ?>" step="1" <?php echo $sf_available ? '' : 'disabled'; ?>>
                        </label>
                        <button type="submit" class="sf-btn sf-btn-primary" <?php echo $sf_available ? '' : 'disabled'; ?>>
                            <i class="fa fa-shopping-cart" aria-hidden="true"></i>
                            <?php echo $sf_available ? 'Add to cart' : 'Out of stock'; ?>
                        </button>
                    </div>
                </form>
                <p class="sf-detail-stock <?php echo $sf_available ? 'in-stock' : 'out-stock'; ?>">
                    <?php if ($sf_available): ?>
                        <?php echo (int)$sf_product['is_service'] === 1 ? 'No stock limit for this service.' : 'This quantity is available in the shop today.'; ?>
                    <?php else: ?>
                        This product cannot be added to the cart right now.
                    <?php endif; ?>
                </p>
                <p class="sf-detail-links">
                    <a href="cart.php">View cart</a> &middot; <a href="index.php#delivery">Delivery and returns</a>
                </p>
            </div>
        </div>

        <div class="sf-detail-sections">
            <div>
                <section class="sf-detail-section" id="description">
                    <h2>Product details</h2>
                    <p><?php echo trim((string)$sf_product['description']) !== '' ? nl2br(e((string)$sf_product['description'])) : 'The store has not added a longer description for this product. Contact the store if you need more specification detail before ordering.'; ?></p>
                </section>

                <section class="sf-detail-section" id="reviews">
                    <h2>Reviews</h2>
                    <?php if (isset($sf_product['review_count']) && (int)$sf_product['review_count'] > 0): ?>
                        <p><strong><?php echo e(number_format((float)$sf_product['rating'], 1)); ?> out of 5</strong> from <?php echo (int)$sf_product['review_count']; ?> review<?php echo (int)$sf_product['review_count'] === 1 ? '' : 's'; ?>.</p>
                    <?php else: ?>
                        <div class="sf-review-empty">No reviews have been recorded for this product. Reviews are not collected on this storefront yet.</div>
                    <?php endif; ?>
                </section>
            </div>

            <aside>
                <section class="sf-detail-section" id="specs">
                    <h2>Specifications</h2>
                    <table class="sf-spec-table">
                        <tbody>
                            <tr><th scope="row">SKU</th><td><?php echo e($sf_product['sku'] ?: 'Not set'); ?></td></tr>
                            <tr><th scope="row">Product code</th><td><?php echo e($sf_product['code']); ?></td></tr>
                            <?php if (!empty($sf_product['barcode'])): ?><tr><th scope="row">Barcode</th><td><?php echo e($sf_product['barcode']); ?></td></tr><?php endif; ?>
                            <?php if (!empty($sf_product['category_name'])): ?><tr><th scope="row">Category</th><td><?php echo e($sf_product['category_name']); ?></td></tr><?php endif; ?>
                            <?php if ($sf_unit !== ''): ?><tr><th scope="row">Sold by</th><td><?php echo e($sf_unit); ?></td></tr><?php endif; ?>
                            <?php if (trim((string)($sf_product['variant_color'] ?? '')) !== ''): ?><tr><th scope="row">Colour</th><td><?php echo e($sf_product['variant_color']); ?></td></tr><?php endif; ?>
                            <?php if (trim((string)($sf_product['variant_size'] ?? '')) !== ''): ?><tr><th scope="row">Size</th><td><?php echo e($sf_product['variant_size']); ?></td></tr><?php endif; ?>
                        </tbody>
                    </table>
                </section>

                <section class="sf-detail-section" id="shipping">
                    <h2>Shipping and returns</h2>
                    <p>Delivery availability, timing and any fee are confirmed with you after the order request. The checkout form collects the delivery location for that confirmation.</p>
                    <p>Contact the store before returning an item. Return eligibility and timing are confirmed with your order. Report damaged or incorrect items promptly.</p>
                </section>
            </aside>
        </div>

        <?php if ($sf_related): ?>
            <section class="sf-section" aria-labelledby="related-title">
                <div class="sf-section-head">
                    <div>
                        <h2 id="related-title">You may also like</h2>
                        <p>Other products from this catalogue.</p>
                    </div>
                    <a class="sf-section-link" href="index.php#catalogue">View all products &rarr;</a>
                </div>
                <div class="sf-product-grid">
                    <?php foreach ($sf_related as $sf_product): ?>
                        <?php storefront_render_product_card($sf_product, $sf_currency); ?>
                    <?php endforeach; ?>
                </div>
            </section>
        <?php endif; ?>

        <?php if ($sf_recently_viewed): ?>
            <section class="sf-section" aria-labelledby="recent-title">
                <div class="sf-section-head">
                    <div>
                        <h2 id="recent-title">Recently viewed</h2>
                        <p>Products you opened during this visit.</p>
                    </div>
                </div>
                <div class="sf-product-grid">
                    <?php foreach ($sf_recently_viewed as $sf_product): ?>
                        <?php storefront_render_product_card($sf_product, $sf_currency); ?>
                    <?php endforeach; ?>
                </div>
            </section>
        <?php endif; ?>
    </div>
</main>
<?php require __DIR__ . '/storefront-footer.php'; ?>