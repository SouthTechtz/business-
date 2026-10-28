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
$sf_page_title_parts = [];
if ($sf_search_q !== '') $sf_page_title_parts[] = 'Search results';
if ($sf_category_name !== '') $sf_page_title_parts[] = $sf_category_name;
$page_title = $sf_page_title_parts ? implode(' - ', $sf_page_title_parts) : 'Shop all products';
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

$sf_new_arrivals = storefront_new_arrivals($sf_tenant_id, 4);
$sf_best_sellers = storefront_best_sellers($sf_tenant_id, 4);
$sf_recently_viewed = storefront_recently_viewed($sf_tenant_id, 4);

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
                    <?php if ($sf_search_q !== ''): ?>
                        Results for &ldquo;<?php echo e($sf_search_q); ?>&rdquo;
                    <?php elseif ($sf_category_name !== ''): ?>
                        <?php echo e($sf_category_name); ?>
                    <?php else: ?>
                        Shop all products
                    <?php endif; ?>
                </h1>
                <p>
                    Live catalogue and current shop stock. Prices are in <?php echo e($sf_currency); ?>;
                    the store confirms availability and delivery after your order request.
                </p>
            </div>
            <a class="sf-filter-toggle sf-btn sf-btn-small" href="#catalogue" data-sf-open-filters aria-controls="sf-filters" aria-expanded="false">Filters</a>
        </div>

        <div class="sf-shop-layout" id="catalogue">
            <aside class="sf-filters is-hidden-mobile" id="sf-filters" aria-label="Product filters">
                <div class="sf-filter-head">
                    <h2>Filters</h2>
                    <?php if ($sf_has_filters): ?><a class="sf-filter-clear" href="index.php#catalogue">Clear all</a><?php endif; ?>
                </div>
                <form method="get" action="index.php#catalogue">
                    <?php if ($sf_sort !== 'name'): ?><input type="hidden" name="sort" value="<?php echo e($sf_sort); ?>"><?php endif; ?>
                    <fieldset class="sf-filter-group">
                        <legend>Search</legend>
                        <label class="sr-only" for="filter-q">Search products</label>
                        <input type="search" id="filter-q" name="q" value="<?php echo e($sf_search_q); ?>" maxlength="100" placeholder="Product name or code">
                    </fieldset>

                    <fieldset class="sf-filter-group">
                        <legend>Category</legend>
                        <label class="sr-only" for="filter-category">Category</label>
                        <select id="filter-category" name="category">
                            <option value="0">All categories</option>
                            <?php foreach ($sf_categories as $sf_category_row): ?>
                                <option value="<?php echo (int)$sf_category_row['category_id']; ?>" <?php echo $sf_category === (int)$sf_category_row['category_id'] ? 'selected' : ''; ?>>
                                    <?php echo e($sf_category_row['name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </fieldset>

                    <fieldset class="sf-filter-group">
                        <legend>Price (<?php echo e($sf_currency); ?>)</legend>
                        <div class="sf-price-range">
                            <label><span>Min</span><input type="number" name="min_price" min="0" step="0.01" value="<?php echo $sf_filters['min_price'] !== null ? e($sf_filters['min_price']) : ''; ?>" placeholder="<?php echo e(number_format($sf_filter_options['min_price'], 0, '.', '')); ?>"></label>
                            <label><span>Max</span><input type="number" name="max_price" min="0" step="0.01" value="<?php echo $sf_filters['max_price'] !== null ? e($sf_filters['max_price']) : ''; ?>" placeholder="<?php echo e(number_format($sf_filter_options['max_price'], 0, '.', '')); ?>"></label>
                        </div>
                    </fieldset>

                    <?php if ($sf_filter_options['sizes']): ?>
                        <fieldset class="sf-filter-group">
                            <legend>Size</legend>
                            <label class="sr-only" for="filter-size">Size</label>
                            <select id="filter-size" name="size">
                                <option value="">All sizes</option>
                                <?php foreach ($sf_filter_options['sizes'] as $sf_size): ?>
                                    <option value="<?php echo e($sf_size); ?>" <?php echo $sf_filters['size'] === $sf_size ? 'selected' : ''; ?>><?php echo e($sf_size); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </fieldset>
                    <?php endif; ?>

                    <?php if ($sf_filter_options['colors']): ?>
                        <fieldset class="sf-filter-group">
                            <legend>Colour</legend>
                            <label class="sr-only" for="filter-color">Colour</label>
                            <select id="filter-color" name="color">
                                <option value="">All colours</option>
                                <?php foreach ($sf_filter_options['colors'] as $sf_color): ?>
                                    <option value="<?php echo e($sf_color); ?>" <?php echo $sf_filters['color'] === $sf_color ? 'selected' : ''; ?>><?php echo e($sf_color); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </fieldset>
                    <?php endif; ?>

                    <fieldset class="sf-filter-group">
                        <legend>Availability</legend>
                        <label class="sr-only" for="filter-availability">Availability</label>
                        <select id="filter-availability" name="availability">
                            <option value="">All products</option>
                            <option value="in" <?php echo $sf_filters['availability'] === 'in' ? 'selected' : ''; ?>>In stock</option>
                            <option value="out" <?php echo $sf_filters['availability'] === 'out' ? 'selected' : ''; ?>>Out of stock</option>
                        </select>
                    </fieldset>

                    <div class="sf-filter-actions">
                        <button type="submit" class="sf-btn sf-btn-primary">Apply</button>
                        <a class="sf-btn" href="index.php#catalogue">Clear</a>
                    </div>
                </form>
            </aside>

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

        <?php if (!$sf_has_filters && $sf_total > 4 && $sf_new_arrivals): ?>
            <section class="sf-section" aria-labelledby="new-arrivals-title">
                <div class="sf-section-head">
                    <div>
                        <h2 id="new-arrivals-title">New arrivals</h2>
                        <p>The latest products added to the catalogue.</p>
                    </div>
                    <a class="sf-section-link" href="index.php?sort=newest#catalogue">Sort catalogue by newest &rarr;</a>
                </div>
                <div class="sf-product-grid">
                    <?php foreach ($sf_new_arrivals as $sf_product): ?>
                        <?php storefront_render_product_card($sf_product, $sf_currency); ?>
                    <?php endforeach; ?>
                </div>
            </section>
        <?php endif; ?>

        <?php if (!$sf_has_filters && $sf_total > 4 && $sf_best_sellers): ?>
            <section class="sf-section" aria-labelledby="best-sellers-title">
                <div class="sf-section-head">
                    <div>
                        <h2 id="best-sellers-title">Best sellers</h2>
                        <p>Products with the most units on existing customer orders.</p>
                    </div>
                    <a class="sf-section-link" href="index.php?sort=popular#catalogue">Sort catalogue by popularity &rarr;</a>
                </div>
                <div class="sf-product-grid">
                    <?php foreach ($sf_best_sellers as $sf_product): ?>
                        <?php storefront_render_product_card($sf_product, $sf_currency); ?>
                    <?php endforeach; ?>
                </div>
            </section>
        <?php endif; ?>

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
</main>
<?php require __DIR__ . '/storefront-footer.php'; ?>
