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

$sf_filters = storefront_normalise_filters([
    'q'            => $_GET['q'] ?? '',
    'category'     => $_GET['category'] ?? 0,
    'min_price'    => $_GET['min_price'] ?? '',
    'max_price'    => $_GET['max_price'] ?? '',
    'size'         => $_GET['size'] ?? '',
    'color'        => $_GET['color'] ?? '',
    'availability' => $_GET['availability'] ?? '',
    'sort'         => $_GET['sort'] ?? 'name',
]);
$sf_search_q = $sf_filters['q'];
$sf_category = $sf_filters['category'];
$sf_sort = $sf_filters['sort'];
$sf_categories = storefront_categories($sf_tenant_id);
$sf_all_products_view = !empty($_GET['all']);
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
$sf_filter_options = storefront_filter_options($sf_tenant_id);

$sf_category_name = '';
foreach ($sf_categories as $sf_category_row) {
    if ((int)$sf_category_row['category_id'] === $sf_category) {
        $sf_category_name = (string)$sf_category_row['name'];
        break;
    }
}
if ($sf_category > 0 && $sf_category_name === '') {
    $sf_category = 0;
    $sf_filters['category'] = 0;
}

$sf_per_page = 24;
$sf_total = storefront_catalogue_count($sf_tenant_id, $sf_filters);
$sf_total_pages = max(1, (int)ceil($sf_total / $sf_per_page));
$sf_page = max(1, (int)($_GET['page'] ?? 1));
if ($sf_page > $sf_total_pages && $sf_total > 0) {
    $sf_page = $sf_total_pages;
}
$sf_products = $sf_total > 0
    ? storefront_catalogue($sf_tenant_id, $sf_filters, $sf_per_page, ($sf_page - 1) * $sf_per_page)
    : [];

$sf_has_filters = $sf_search_q !== '' || $sf_category > 0
    || $sf_filters['min_price'] !== null || $sf_filters['max_price'] !== null
    || $sf_filters['size'] !== '' || $sf_filters['color'] !== ''
    || $sf_filters['availability'] !== '';
$sf_show_category_home = !$sf_all_products_view && !$sf_has_filters && $sf_sort === 'name' && $sf_page === 1;
$sf_page_title_parts = [];
if ($sf_search_q !== '') $sf_page_title_parts[] = 'Search results';
if ($sf_category_name !== '') $sf_page_title_parts[] = $sf_category_name;
$page_title = $sf_page_title_parts ? implode(' - ', $sf_page_title_parts) : ($sf_show_category_home ? 'Shop online' : 'Shop all products');
$sf_meta_description = 'Shop ' . (count($sf_categories) > 0 ? 'the ' . $sf_company . ' catalogue' : 'products')
    . '. Add items to the cart and send an order request; the store confirms stock, price and delivery.';
$sf_canonical_url = storefront_absolute_url('index.php');

$sf_query_params = [
    'q' => $sf_search_q,
    'category' => $sf_category ?: null,
    'min_price' => $sf_filters['min_price'],
    'max_price' => $sf_filters['max_price'],
    'size' => $sf_filters['size'],
    'color' => $sf_filters['color'],
    'availability' => $sf_filters['availability'],
    'sort' => $sf_sort !== 'name' ? $sf_sort : null,
];
if ($sf_all_products_view) $sf_query_params['all'] = '1';
$sf_query_params = array_filter($sf_query_params, fn($value) => $value !== null && $value !== '');
$sf_page_url = function ($page) use ($sf_query_params) {
    $params = $sf_query_params;
    if ((int)$page > 1) $params['page'] = (int)$page;
    return 'index.php' . ($params ? '?' . http_build_query($params) : '') . '#catalogue';
};
$sf_clear_filter_url = function ($key) use ($sf_query_params) {
    $params = $sf_query_params;
    unset($params[$key]);
    return 'index.php' . ($params ? '?' . http_build_query($params) : '') . '#catalogue';
};

$sf_recently_viewed = storefront_recently_viewed($sf_tenant_id, 4);
$sf_arrivals = storefront_new_arrivals($sf_tenant_id, 5);
if (!$sf_arrivals) {
    $sf_arrivals = storefront_catalogue($sf_tenant_id, ['sort' => 'name'], 5, 0);
}

$sf_head_extra = '';
if ($sf_products) {
    $sf_item_list = [];
    foreach ($sf_products as $sf_index => $sf_list_product) {
        $sf_item_list[] = [
            '@type' => 'ListItem',
            'position' => $sf_index + 1,
            'url' => storefront_absolute_url(storefront_product_url($sf_list_product)),
            'name' => (string)$sf_list_product['name'],
        ];
    }
    $sf_list_json = json_encode([
        '@context' => 'https://schema.org',
        '@type' => 'ItemList',
        'name' => $page_title,
        'itemListElement' => $sf_item_list,
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    $sf_list_json = str_replace(['<', '>'], ['\\u003C', '\\u003E'], (string)$sf_list_json);
    $sf_head_extra = '<script type="application/ld+json">' . $sf_list_json . '</script>';
}

require __DIR__ . '/storefront-header.php';
?>
<main class="sf-main" id="sf-main">
    <?php if ($sf_show_category_home): ?>
        <?php
        $sf_home_categories = $sf_category_cards;
        usort($sf_home_categories, function ($a, $b) {
            $count_compare = (int)$b['product_count'] <=> (int)$a['product_count'];
            return $count_compare !== 0 ? $count_compare : strcasecmp((string)$a['name'], (string)$b['name']);
        });
        $sf_home_categories = array_slice($sf_home_categories, 0, 5);
        $sf_arrivals = array_slice($sf_arrivals, 0, 5);
        $sf_popular = storefront_popular_products(
            $sf_tenant_id,
            array_column($sf_arrivals, 'product_id'),
            5
        );
        ?>
        <section class="sf-home-hero" aria-labelledby="sf-hero-title">
            <div class="sf-container sf-home-hero-inner">
                <div class="sf-home-copy">
                    <span class="sf-home-eyebrow"><i class="fa fa-star" aria-hidden="true"></i> Retail &amp; wholesale supply</span>
                    <h1 id="sf-hero-title">Everyday products, at prices that work for your business.</h1>
                    <p>Browse the <?php echo e($sf_company); ?> catalogue, build an order request in minutes and let our team confirm stock, pricing and delivery. No online payment is taken.</p>
                    <div class="sf-home-actions">
                        <a class="sf-home-btn sf-home-btn-primary" href="index.php?all=1#catalogue">
                            <i class="fa fa-shopping-bag" aria-hidden="true"></i> Shop all products
                        </a>
                        <a class="sf-home-btn sf-btn" href="wholesale-registration.php">
                            <i class="fa fa-briefcase" aria-hidden="true"></i> Register for wholesale
                        </a>
                    </div>
                    <div class="sf-home-assurance">
                        <span><i class="fa fa-check-circle" aria-hidden="true"></i> Live shop stock</span>
                        <span><i class="fa fa-check-circle" aria-hidden="true"></i> Wholesale pricing on request</span>
                        <span><i class="fa fa-check-circle" aria-hidden="true"></i> Delivery confirmed by our team</span>
                    </div>
                </div>
            </div>
        </section>

        <div class="sf-container">
            <div class="sf-value-strip">
                <div class="sf-value-card">
                    <i class="fa fa-truck" aria-hidden="true"></i>
                    <div><strong>Delivery arranged with you</strong><span>We confirm timing and any fee after your order request.</span></div>
                </div>
                <div class="sf-value-card">
                    <i class="fa fa-warehouse" aria-hidden="true"></i>
                    <div><strong>Real shop stock</strong><span>Availability shown here comes from live shop inventory.</span></div>
                </div>
                <div class="sf-value-card">
                    <i class="fa fa-handshake-o" aria-hidden="true"></i>
                    <div><strong>Wholesale accounts welcome</strong><span>Register your business for bulk pricing and repeat orders.</span></div>
                </div>
            </div>
        </div>

        <div class="sf-container sf-category-home" id="catalogue">
            <section aria-labelledby="sf-category-title">
                <div class="sf-home-section-heading">
                    <span>Explore</span>
                    <h2 id="sf-category-title">Popular Categories</h2>
                </div>
                <?php if ($sf_home_categories): ?>
                    <div class="sf-category-grid sf-category-grid--home">
                        <?php foreach ($sf_home_categories as $sf_category_card): ?>
                            <?php
                            $sf_card_images = $sf_category_images[(int)$sf_category_card['category_id']] ?? [];
                            if (!$sf_card_images) {
                                $sf_fallback_image = storefront_image_path($sf_category_card['image'] ?? '');
                                if ($sf_fallback_image !== '') $sf_card_images = [$sf_fallback_image];
                            }
                            ?>
                            <a class="sf-category-tile" data-sf-category-slides href="index.php?category=<?php echo (int)$sf_category_card['category_id']; ?>#catalogue">
                                <?php foreach ($sf_card_images as $sf_image_index => $sf_category_image): ?>
                                    <img class="sf-category-slide<?php echo $sf_image_index === 0 ? ' is-active' : ''; ?>" src="<?php echo e($sf_category_image); ?>" alt="" loading="lazy">
                                <?php endforeach; ?>
                                <span class="sf-category-tile-shade"></span>
                                <span class="sf-category-tile-copy">
                                    <strong><?php echo e($sf_category_card['name']); ?></strong>
                                    <small><?php echo (int)$sf_category_card['product_count']; ?> product<?php echo (int)$sf_category_card['product_count'] === 1 ? '' : 's'; ?></small>
                                </span>
                            </a>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <div class="sf-empty">
                        <h2>Product categories are coming soon</h2>
                        <p>Browse all available products instead.</p>
                        <a class="sf-btn sf-btn-primary" href="index.php?all=1#catalogue">Shop all products</a>
                    </div>
                <?php endif; ?>
            </section>

            <?php if ($sf_total > 0): ?>
                <div class="sf-category-home-all">
                    <a href="categories.php" class="sf-btn sf-btn-primary">See All Categories<i class="fa fa-arrow-right" aria-hidden="true"></i></a>
                </div>
            <?php endif; ?>
        </div>

        <div class="sf-arrivals-band">
            <div class="sf-container">
                <section class="sf-section sf-arrivals" aria-labelledby="sf-arrivals-title">
                    <div class="sf-section-head">
                        <div>
                            <h2 id="sf-arrivals-title">New Arrivals</h2>
                            <p>The latest products added to the catalogue.</p>
                        </div>
                        <a class="sf-section-link" href="index.php?all=1&sort=newest#catalogue">See all new stock &rarr;</a>
                    </div>
                    <?php if ($sf_arrivals): ?>
                        <div class="sf-product-grid sf-product-grid--five">
                            <?php foreach ($sf_arrivals as $sf_arrival): ?>
                                <?php storefront_render_product_card($sf_arrival, $sf_currency); ?>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <div class="sf-empty">
                            <h2>No products yet</h2>
                            <p>The catalogue will appear here as soon as items are added.</p>
                        </div>
                    <?php endif; ?>
                </section>
            </div>
        </div>

        <?php if ($sf_popular): ?>
        <div class="sf-arrivals-band sf-popular-band">
            <div class="sf-container">
                <section class="sf-section sf-arrivals sf-popular" aria-labelledby="sf-popular-title">
                    <div class="sf-section-head">
                        <div>
                            <h2 id="sf-popular-title">Popular Products</h2>
                            <p>Available favourites customers search for and view most.</p>
                        </div>
                        <a class="sf-section-link" href="index.php?all=1&sort=popular#catalogue">See all popular products &rarr;</a>
                    </div>
                    <div class="sf-product-grid sf-product-grid--five">
                        <?php foreach ($sf_popular as $sf_popular_product): ?>
                            <?php storefront_render_product_card($sf_popular_product, $sf_currency); ?>
                        <?php endforeach; ?>
                    </div>
                </section>
            </div>
        </div>
        <?php endif; ?>

        <div class="sf-container">
            <section class="sf-wholesale-band" aria-labelledby="sf-wholesale-band-title">
                <div class="sf-wholesale-band-copy">
                    <span><i class="fa fa-briefcase" aria-hidden="true"></i> Buy in bulk</span>
                    <h2 id="sf-wholesale-band-title">Run a shop, hotel or office? Open a wholesale account.</h2>
                    <p>Register your business once and our team will contact you about volume pricing, product availability and delivery for repeat orders.</p>
                </div>
                <div class="sf-wholesale-band-actions">
                    <a class="sf-home-btn sf-home-btn-primary" href="wholesale-registration.php">Register for wholesale</a>
                    <a class="sf-btn-ghost" href="categories.php">Browse categories</a>
                </div>
            </section>
        </div>
    <?php else: ?>
    <div class="sf-container sf-page">
        <nav aria-label="Breadcrumb">
            <ol class="sf-breadcrumb">
                <li><a href="index.php">Home</a></li>
                <li><a href="index.php#catalogue">Shop</a></li>
                <?php if ($sf_search_q !== ''): ?>
                    <li>Search: <?php echo e($sf_search_q); ?></li>
                <?php elseif ($sf_category_name !== ''): ?>
                    <li><?php echo e($sf_category_name); ?></li>
                <?php endif; ?>
            </ol>
        </nav>

        <div class="sf-catalogue-head">
            <div>
                <h1>
                    <i class="fa fa-th-large sf-title-icon" aria-hidden="true"></i>
                    <?php if ($sf_search_q !== ''): ?>
                        Results for &ldquo;<?php echo e($sf_search_q); ?>&rdquo;
                    <?php elseif ($sf_category_name !== ''): ?>
                        <?php echo e($sf_category_name); ?>
                    <?php else: ?>
                        Catalogue
                    <?php endif; ?>
                </h1>
                <p>
                    Live catalogue and current shop stock. Prices are in <?php echo e($sf_currency); ?>;
                    the store confirms availability and delivery after your order request.
                </p>
            </div>
        </div>

        <div class="sf-shop-layout" id="catalogue">
            <section class="sf-results" aria-label="Products">
                <div class="sf-results-bar">
                    <p class="sf-result-count">
                        <?php echo (int)$sf_total; ?> product<?php echo $sf_total === 1 ? '' : 's'; ?> found
                        <?php if ($sf_total > 0): ?> &middot; showing <?php echo (int)(($sf_page - 1) * $sf_per_page + 1); ?>&ndash;<?php echo (int)min($sf_total, $sf_page * $sf_per_page); ?><?php endif; ?>
                    </p>
                    <form class="sf-sort" method="get" action="index.php#catalogue">
                        <?php foreach ($sf_query_params as $sf_key => $sf_value): ?>
                            <?php if ($sf_key !== 'sort'): ?><input type="hidden" name="<?php echo e($sf_key); ?>" value="<?php echo e($sf_value); ?>"><?php endif; ?>
                        <?php endforeach; ?>
                        <label for="sf-sort">Sort by</label>
                        <select id="sf-sort" name="sort" onchange="this.form.submit()">
                            <option value="name" <?php echo $sf_sort === 'name' ? 'selected' : ''; ?>>Name</option>
                            <option value="newest" <?php echo $sf_sort === 'newest' ? 'selected' : ''; ?>>Newest</option>
                            <option value="price_asc" <?php echo $sf_sort === 'price_asc' ? 'selected' : ''; ?>>Price: low to high</option>
                            <option value="price_desc" <?php echo $sf_sort === 'price_desc' ? 'selected' : ''; ?>>Price: high to low</option>
                            <option value="popular" <?php echo $sf_sort === 'popular' ? 'selected' : ''; ?>>Popularity</option>
                        </select>
                    </form>
                </div>

                <?php if ($sf_has_filters): ?>
                    <div class="sf-active-filters" aria-label="Active filters">
                        <?php if ($sf_search_q !== ''): ?><span class="sf-filter-chip">Search: <?php echo e($sf_search_q); ?> <a href="<?php echo e($sf_clear_filter_url('q')); ?>" aria-label="Remove search filter">&times;</a></span><?php endif; ?>
                        <?php if ($sf_category_name !== ''): ?><span class="sf-filter-chip"><?php echo e($sf_category_name); ?> <a href="<?php echo e($sf_clear_filter_url('category')); ?>" aria-label="Remove category filter">&times;</a></span><?php endif; ?>
                        <?php if ($sf_filters['min_price'] !== null): ?><span class="sf-filter-chip">Min <?php echo e(money($sf_filters['min_price'], $sf_currency)); ?> <a href="<?php echo e($sf_clear_filter_url('min_price')); ?>" aria-label="Remove minimum price filter">&times;</a></span><?php endif; ?>
                        <?php if ($sf_filters['max_price'] !== null): ?><span class="sf-filter-chip">Max <?php echo e(money($sf_filters['max_price'], $sf_currency)); ?> <a href="<?php echo e($sf_clear_filter_url('max_price')); ?>" aria-label="Remove maximum price filter">&times;</a></span><?php endif; ?>
                        <?php if ($sf_filters['size'] !== ''): ?><span class="sf-filter-chip">Size <?php echo e($sf_filters['size']); ?> <a href="<?php echo e($sf_clear_filter_url('size')); ?>" aria-label="Remove size filter">&times;</a></span><?php endif; ?>
                        <?php if ($sf_filters['color'] !== ''): ?><span class="sf-filter-chip">Colour <?php echo e($sf_filters['color']); ?> <a href="<?php echo e($sf_clear_filter_url('color')); ?>" aria-label="Remove colour filter">&times;</a></span><?php endif; ?>
                        <?php if ($sf_filters['availability'] !== ''): ?><span class="sf-filter-chip"><?php echo $sf_filters['availability'] === 'in' ? 'In stock' : 'Out of stock'; ?> <a href="<?php echo e($sf_clear_filter_url('availability')); ?>" aria-label="Remove availability filter">&times;</a></span><?php endif; ?>
                    </div>
                <?php endif; ?>

                <?php if (!$sf_products): ?>
                    <div class="sf-empty">
                        <h2>No products match these filters</h2>
                        <p>Try a different search term, category or price range.</p>
                        <a class="sf-btn sf-btn-primary" href="index.php#catalogue">View all products</a>
                    </div>
                <?php else: ?>
                    <div class="sf-product-grid">
                        <?php foreach ($sf_products as $sf_product): ?>
                            <?php storefront_render_product_card($sf_product, $sf_currency); ?>
                        <?php endforeach; ?>
                    </div>

                    <?php if ($sf_total_pages > 1): ?>
                        <nav class="sf-pagination" aria-label="Product pages">
                            <span class="sf-result-count">Page <?php echo (int)$sf_page; ?> of <?php echo (int)$sf_total_pages; ?></span>
                            <div class="sf-page-links">
                                <?php if ($sf_page > 1): ?>
                                    <a href="<?php echo e($sf_page_url($sf_page - 1)); ?>" aria-label="Previous page">&larr; Previous</a>
                                <?php else: ?>
                                    <span class="is-disabled">&larr; Previous</span>
                                <?php endif; ?>
                                <?php for ($sf_i = 1; $sf_i <= $sf_total_pages; $sf_i++): ?>
                                    <?php if ($sf_i === $sf_page): ?>
                                        <span aria-current="page"><?php echo (int)$sf_i; ?></span>
                                    <?php elseif ($sf_i === 1 || $sf_i === $sf_total_pages || abs($sf_i - $sf_page) <= 2): ?>
                                        <a href="<?php echo e($sf_page_url($sf_i)); ?>"><?php echo (int)$sf_i; ?></a>
                                    <?php elseif ($sf_i === 2 || $sf_i === $sf_total_pages - 1): ?>
                                        <span>&hellip;</span>
                                    <?php endif; ?>
                                <?php endfor; ?>
                                <?php if ($sf_page < $sf_total_pages): ?>
                                    <a href="<?php echo e($sf_page_url($sf_page + 1)); ?>" aria-label="Next page">Next &rarr;</a>
                                <?php else: ?>
                                    <span class="is-disabled">Next &rarr;</span>
                                <?php endif; ?>
                            </div>
                        </nav>
                    <?php endif; ?>
                <?php endif; ?>
            </section>
        </div>

        <?php if (!$sf_has_filters && $sf_total > 4 && $sf_recently_viewed): ?>
            <section class="sf-section" aria-labelledby="recently-viewed-title">
                <div class="sf-section-head">
                    <div>
                        <h2 id="recently-viewed-title">Recently viewed</h2>
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
    <?php endif; ?>

    <div class="sf-container">
        <section class="sf-about-section" id="about-us" aria-labelledby="about-us-title">
            <div class="sf-about-heading">
                <span>About us</span>
                <h2 id="about-us-title">The company behind this store</h2>
                <p>This online catalogue is presented by:</p>
            </div>
            <div class="sf-about-companies">
                <div><?php echo e($sf_company); ?></div>
            </div>
        </section>
    </div>
</main>
<?php require __DIR__ . '/storefront-footer.php'; ?>
