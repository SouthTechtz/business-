<?php
$sf_bar_categories = $sf_categories ?? storefront_categories((int)$sf_tenant_id);
$sf_bar_q = trim((string)($_GET['q'] ?? ''));
$sf_bar_category = (int)($_GET['category'] ?? 0);
$sf_bar_availability = (string)($_GET['availability'] ?? '');
$sf_bar_sort = (string)($_GET['sort'] ?? 'name');
$sf_bar_active = $sf_bar_q !== '' || $sf_bar_category > 0 || $sf_bar_availability !== '';
$sf_bar_reset = 'index.php?all=1#catalogue';
?>
<section class="sf-filterbar" aria-label="Search and filter products">
    <div class="sf-container">
        <form class="sf-filterbar-form" action="index.php" method="get" role="search">
            <input type="hidden" name="all" value="1">
            <div class="sf-filterbar-search">
                <label class="sr-only" for="sf-filterbar-q">Search products</label>
                <input type="search" id="sf-filterbar-q" name="q" maxlength="100" value="<?php echo e($sf_bar_q); ?>"
                       placeholder="Search products, codes or SKU" autocomplete="off">
                <button type="submit"><i class="fa fa-search" aria-hidden="true"></i><span class="sr-only">Search</span></button>
            </div>
            <div class="sf-filterbar-field">
                <label for="sf-filterbar-category">Category</label>
                <select id="sf-filterbar-category" name="category">
                    <option value="0">All categories</option>
                    <?php foreach ($sf_bar_categories as $sf_bar_cat): ?>
                        <option value="<?php echo (int)$sf_bar_cat['category_id']; ?>" <?php echo $sf_bar_category === (int)$sf_bar_cat['category_id'] ? 'selected' : ''; ?>>
                            <?php echo e($sf_bar_cat['name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="sf-filterbar-field">
                <label for="sf-filterbar-availability">Availability</label>
                <select id="sf-filterbar-availability" name="availability">
                    <option value="">All products</option>
                    <option value="in" <?php echo $sf_bar_availability === 'in' ? 'selected' : ''; ?>>In stock</option>
                    <option value="out" <?php echo $sf_bar_availability === 'out' ? 'selected' : ''; ?>>Out of stock</option>
                </select>
            </div>
            <div class="sf-filterbar-field">
                <label for="sf-filterbar-sort">Sort by</label>
                <select id="sf-filterbar-sort" name="sort">
                    <option value="name" <?php echo $sf_bar_sort === 'name' ? 'selected' : ''; ?>>Name</option>
                    <option value="newest" <?php echo $sf_bar_sort === 'newest' ? 'selected' : ''; ?>>Newest</option>
                    <option value="price_asc" <?php echo $sf_bar_sort === 'price_asc' ? 'selected' : ''; ?>>Price: low to high</option>
                    <option value="price_desc" <?php echo $sf_bar_sort === 'price_desc' ? 'selected' : ''; ?>>Price: high to low</option>
                    <option value="popular" <?php echo $sf_bar_sort === 'popular' ? 'selected' : ''; ?>>Popularity</option>
                </select>
            </div>
            <button type="submit" class="sf-filterbar-apply"><i class="fa fa-filter" aria-hidden="true"></i> Apply</button>
            <?php if ($sf_bar_active): ?>
                <a class="sf-filterbar-reset" href="<?php echo e($sf_bar_reset); ?>">Clear</a>
            <?php endif; ?>
        </form>
    </div>
</section>
