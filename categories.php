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
$sf_search_q = trim((string)($_GET['q'] ?? ''));
$sf_category = 0;

$sf_category_cards = fetch_all(
    "SELECT c.category_id, c.name, COUNT(p.product_id) AS product_count,
            (SELECT pi.image
               FROM products pi
              WHERE pi.tenant_id = c.tenant_id AND pi.category_id = c.category_id
                AND pi.is_active = 1 AND pi.image IS NOT NULL AND pi.image <> ''
              ORDER BY pi.product_id DESC
              LIMIT 1) AS image
       FROM product_categories c
       LEFT JOIN products p ON p.category_id = c.category_id
            AND p.tenant_id = c.tenant_id AND p.is_active = 1
      WHERE c.tenant_id = ?
      GROUP BY c.category_id, c.tenant_id, c.name
      ORDER BY c.name ASC",
    $sf_tenant_id
);

$sf_category_images = storefront_category_images($sf_tenant_id, array_column($sf_category_cards, 'category_id'));
$sf_total_products = 0;
foreach ($sf_category_cards as $sf_category_card) {
    $sf_total_products += (int)$sf_category_card['product_count'];
}
$sf_active_categories = count(array_filter(
    $sf_category_cards,
    fn($row) => (int)$row['product_count'] > 0
));

$page_title = 'Product categories';
$sf_meta_description = 'Browse every product category in the ' . $sf_company
    . ' catalogue, see how many products each one holds and start an order request.';
$sf_canonical_url = storefront_absolute_url('categories.php');

require __DIR__ . '/storefront-header.php';
?>
<main class="sf-main" id="sf-main">
    <section class="sf-cat-intro">
        <div class="sf-container">
            <nav aria-label="Breadcrumb">
                <ol class="sf-breadcrumb">
                    <li><a href="index.php">Home</a></li>
                    <li aria-current="page">Product categories</li>
                </ol>
            </nav>
            <h1>Product categories</h1>
            <p>Every category in the <?php echo e($sf_company); ?> catalogue, with live product counts.
               Pick a category to see its products and add them to your order request.</p>
            <div class="sf-cat-intro-stats">
                <div><strong><?php echo (int)$sf_active_categories; ?></strong><span>Categories with stock</span></div>
                <div><strong><?php echo (int)$sf_total_products; ?></strong><span>Active products</span></div>
                <div><strong><?php echo count($sf_category_cards); ?></strong><span>Categories in total</span></div>
            </div>
        </div>
    </section>

    <div class="sf-container sf-cat-body">
        <?php if ($sf_category_cards): ?>
            <div class="sf-category-grid sf-cat-grid">
                <?php foreach ($sf_category_cards as $sf_category_card): ?>
                    <?php
                    $sf_card_images = $sf_category_images[(int)$sf_category_card['category_id']] ?? [];
                    if (!$sf_card_images) {
                        $sf_fallback_image = storefront_image_path($sf_category_card['image'] ?? '');
                        if ($sf_fallback_image !== '') $sf_card_images = [$sf_fallback_image];
                    }
                    $sf_card_count = (int)$sf_category_card['product_count'];
                    ?>
                    <a class="sf-category-tile" data-sf-category-slides href="index.php?category=<?php echo (int)$sf_category_card['category_id']; ?>#catalogue">
                        <?php foreach ($sf_card_images as $sf_image_index => $sf_card_image): ?>
                            <img class="sf-category-slide<?php echo $sf_image_index === 0 ? ' is-active' : ''; ?>" src="<?php echo e($sf_card_image); ?>" alt="" loading="lazy">
                        <?php endforeach; ?>
                        <span class="sf-category-tile-shade"></span>
                        <span class="sf-category-tile-copy">
                            <strong><?php echo e($sf_category_card['name']); ?></strong>
                            <small><?php echo (int)$sf_card_count; ?> product<?php echo $sf_card_count === 1 ? '' : 's'; ?> &middot; View products</small>
                        </span>
                    </a>
                <?php endforeach; ?>
            </div>
        <?php else: ?>
            <div class="sf-empty">
                <h2>No categories yet</h2>
                <p>Categories will appear here once the catalogue has been set up. You can still browse every product.</p>
                <a class="sf-btn sf-btn-primary" href="index.php?all=1#catalogue">Shop all products</a>
            </div>
        <?php endif; ?>

        <section class="sf-wholesale-band sf-cat-cta-band" aria-labelledby="sf-cat-cta-title">
            <div class="sf-wholesale-band-copy">
                <span><i class="fa fa-briefcase" aria-hidden="true"></i> Buying for a business?</span>
                <h2 id="sf-cat-cta-title">Order these categories in bulk with a wholesale account.</h2>
                <p>Tell us what you buy and how often. Our team will confirm volume pricing and delivery.</p>
            </div>
            <div class="sf-wholesale-band-actions">
                <a class="sf-home-btn sf-home-btn-primary" href="wholesale-registration.php">Register for wholesale</a>
                <a class="sf-btn-ghost" href="index.php?all=1#catalogue">Shop all products</a>
            </div>
        </section>
    </div>
</main>
<?php require __DIR__ . '/storefront-footer.php'; ?>
