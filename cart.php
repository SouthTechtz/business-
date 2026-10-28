<?php
require_once __DIR__ . '/storefront.php';

$sf_tenant = storefront_tenant();
if (!$sf_tenant) {
    http_response_code(503);
    exit('The storefront is not configured.');
}
$sf_settings = storefront_settings($sf_tenant);
$sf_currency = storefront_currency($sf_tenant, $sf_settings);
$sf_tenant_id = (int)$sf_tenant['tenant_id'];
$sf_search_q = '';
$sf_category = 0;

list($sf_items, $sf_missing) = storefront_cart_details($sf_tenant_id);
if ($sf_missing) {
    foreach ($sf_missing as $sf_missing_id) {
        unset($_SESSION['storefront_cart'][$sf_missing_id]);
    }
    flash_set('warning', 'One or more items are no longer available and were removed from your cart.');
}
$sf_totals = storefront_cart_totals($sf_items, $sf_settings);
$sf_subtotal = $sf_totals['subtotal'];

$sf_cart_ids = array_map(fn($item) => (int)$item['product_id'], $sf_items);
$sf_cross_sell = storefront_best_sellers($sf_tenant_id, 8);
if (!$sf_cross_sell) $sf_cross_sell = storefront_new_arrivals($sf_tenant_id, 8);
$sf_cross_sell = array_values(array_filter($sf_cross_sell, fn($item) => !in_array((int)$item['product_id'], $sf_cart_ids, true)));
$sf_cross_sell = array_slice($sf_cross_sell, 0, 4);

$page_title = 'Your cart';
$sf_meta_description = 'Review your selected products, quantities and estimated tax before sending an order request.';
$sf_canonical_url = storefront_absolute_url('cart.php');

require __DIR__ . '/storefront-header.php';
?>
<main class="sf-main" id="sf-main">
    <div class="sf-container sf-page">
        <nav aria-label="Breadcrumb">
            <ol class="sf-breadcrumb">
                <li><a href="index.php">Home</a></li>
                <li aria-current="page">Cart</li>
            </ol>
        </nav>

        <div class="sf-page-head">
            <div>
                <h1>Shopping cart</h1>
                <p>Review quantities and estimated tax before sending your order request.</p>
            </div>
            <a class="sf-text-link" href="index.php#catalogue"><i class="fa fa-arrow-left" aria-hidden="true"></i> Continue shopping</a>
        </div>

        <?php if (!$sf_items): ?>
            <div class="sf-empty">
                <h2>Your cart is empty</h2>
                <p>Browse the catalogue and add the products you need.</p>
                <a class="sf-btn sf-btn-primary" href="index.php#catalogue">View all products</a>
            </div>
        <?php else: ?>
            <div class="sf-cart-layout">
                <section class="sf-cart-list" aria-label="Cart items">
                    <h2 class="sr-only">Items in your cart</h2>
                    <?php foreach ($sf_items as $sf_item): ?>
                        <?php
                        $sf_image = storefront_image_path($sf_item['image'] ?? '');
                        $sf_unit = trim((string)($sf_item['unit_short'] ?? '')) ?: trim((string)($sf_item['unit_name'] ?? ''));
                        $sf_item_max = max(1, (int)($sf_item['max_quantity'] ?? 999));
                        ?>
                        <article class="sf-cart-item">
                            <a class="sf-cart-media" href="<?php echo e(storefront_product_url($sf_item)); ?>">
                                <?php if ($sf_image !== ''): ?>
                                    <img src="<?php echo e($sf_image); ?>" alt="<?php echo e($sf_item['name']); ?>" loading="lazy" width="76" height="76">
                                <?php else: ?>
                                    <span class="sf-product-placeholder">No image</span>
                                <?php endif; ?>
                            </a>
                            <div class="sf-cart-info">
                                <h3><a href="<?php echo e(storefront_product_url($sf_item)); ?>"><?php echo e($sf_item['name']); ?></a></h3>
                                <div class="sf-product-code"><?php echo e($sf_item['sku'] ?: $sf_item['code']); ?></div>
                                <div class="sf-cart-unit"><?php echo e(money($sf_item['sale_price'], $sf_currency)); ?><?php echo $sf_unit !== '' ? ' / ' . e($sf_unit) : ''; ?></div>
                            </div>
                            <form method="post" action="storefront-cart-action.php" class="sf-cart-controls">
                                <?php echo csrf_field(); ?>
                                <input type="hidden" name="product_id" value="<?php echo (int)$sf_item['product_id']; ?>">
                                <label class="sf-qty-label">
                                    <span>Qty</span>
                                    <input type="number" name="qty[<?php echo (int)$sf_item['product_id']; ?>]"
                                           value="<?php echo (int)$sf_item['cart_quantity']; ?>" min="1" max="<?php echo $sf_item_max; ?>" step="1"
                                           aria-label="Quantity for <?php echo e($sf_item['name']); ?>">
                                </label>
                                <button type="submit" name="action" value="update" class="sf-btn sf-btn-small">Update</button>
                                <button type="submit" name="action" value="remove" class="sf-btn sf-btn-small sf-btn-quiet">Remove</button>
                            </form>
                            <div class="sf-cart-line-total"><?php echo e(money($sf_item['line_total'], $sf_currency)); ?></div>
                        </article>
                    <?php endforeach; ?>
                </section>

                <aside class="sf-summary" aria-label="Order summary">
                    <h2>Order summary</h2>
                    <div class="sf-summary-row">
                        <span>Items</span>
                        <strong><?php echo (int)storefront_cart_count(); ?></strong>
                    </div>
                    <div class="sf-summary-row">
                        <span>Subtotal</span>
                        <strong><?php echo e(money($sf_subtotal, $sf_currency)); ?></strong>
                    </div>
                    <div class="sf-summary-row">
                        <span>Estimated tax</span>
                        <strong><?php echo e(money($sf_totals['tax'], $sf_currency)); ?></strong>
                    </div>
                    <div class="sf-summary-row">
                        <span>Delivery</span>
                        <strong>Quoted after confirmation</strong>
                    </div>
                    <div class="sf-summary-row sf-summary-total">
                        <span>Estimated total</span>
                        <strong><?php echo e(money($sf_totals['total'], $sf_currency)); ?></strong>
                    </div>
                    <p class="sf-summary-note">Tax is estimated from the product tax rates and may change when the store confirms your order. This is an order request: no payment is taken online and stock is not reserved.</p>
                    <a class="sf-btn sf-btn-primary sf-btn-block" href="checkout.php">Continue to checkout <i class="fa fa-arrow-right" aria-hidden="true"></i></a>
                    <form method="post" action="storefront-cart-action.php" class="sf-clear-form">
                        <?php echo csrf_field(); ?>
                        <button type="submit" name="action" value="clear" class="sf-text-button">Clear cart</button>
                    </form>
                    <a class="sf-summary-back" href="index.php#catalogue">Add more products</a>
                </aside>
            </div>
        <?php endif; ?>

        <?php if ($sf_cross_sell): ?>
            <section class="sf-section sf-cart-crosssell" aria-labelledby="cross-sell-title">
                <div class="sf-section-head">
                    <div>
                        <h2 id="cross-sell-title">You may also like</h2>
                        <p><?php echo $sf_items ? 'Other products from the catalogue.' : 'Popular products from the catalogue.'; ?></p>
                    </div>
                    <a class="sf-section-link" href="index.php#catalogue">View all products &rarr;</a>
                </div>
                <div class="sf-product-grid">
                    <?php foreach ($sf_cross_sell as $sf_product): ?>
                        <?php storefront_render_product_card($sf_product, $sf_currency); ?>
                    <?php endforeach; ?>
                </div>
            </section>
        <?php endif; ?>
    </div>
</main>
<?php require __DIR__ . '/storefront-footer.php'; ?>
