<?php
if (!isset($sf_tenant) || !$sf_tenant) {
    http_response_code(503);
    exit('The storefront is not configured.');
}
$sf_settings = $sf_settings ?? storefront_settings($sf_tenant);
$sf_company = trim((string)($sf_settings['company_name'] ?? '')) ?: APP_NAME;
$sf_logo = storefront_logo($sf_settings);
$sf_cart_count = storefront_cart_count();
$sf_search_q = $sf_search_q ?? '';
$sf_title = isset($page_title) && $page_title !== '' ? $page_title : 'Shop';
$sf_tenant_id = $sf_tenant_id ?? (int)$sf_tenant['tenant_id'];
$sf_nav_categories = $sf_nav_categories ?? storefront_categories($sf_tenant_id);
$sf_meta_description = $sf_meta_description ?? ('Browse the ' . $sf_company . ' catalogue and send an order request. No online payment is taken.');
$sf_canonical_url = $sf_canonical_url ?? storefront_absolute_url('index.php');
$sf_logged_in = !empty($_SESSION['user_id']);
$sf_is_catalogue = basename((string)($_SERVER['SCRIPT_NAME'] ?? '')) === 'index.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <base href="<?php echo e(storefront_base_path()); ?>">
    <title><?php echo e($sf_title); ?> &middot; <?php echo e($sf_company); ?></title>
    <meta name="description" content="<?php echo e($sf_meta_description); ?>">
    <link rel="canonical" href="<?php echo e($sf_canonical_url); ?>">
    <link rel="alternate" type="application/json" title="Product feed (JSON)" href="<?php echo e(storefront_url('product-feed.php?format=json')); ?>">
    <link rel="alternate" type="text/csv" title="Product feed (CSV)" href="<?php echo e(storefront_url('product-feed.php?format=csv')); ?>">
    <link rel="icon" type="image/png" href="assets/img/favicon.png">
    <link rel="stylesheet" href="assets/css/bootstrap.min.css?v=<?php echo filemtime('assets/css/bootstrap.min.css'); ?>">
    <link rel="stylesheet" href="assets/css/font-awesome.min.css?v=<?php echo filemtime('assets/css/font-awesome.min.css'); ?>">
    <link rel="stylesheet" href="assets/css/storefront.css?v=<?php echo filemtime('assets/css/storefront.css'); ?>">
    <script defer src="assets/js/storefront.js?v=<?php echo file_exists('assets/js/storefront.js') ? filemtime('assets/js/storefront.js') : time(); ?>"></script>
    <?php echo $sf_head_extra ?? ''; ?>
</head>
<body class="sf-body">
<a class="sf-skip-link" href="#sf-main">Skip to content</a>

<header class="sf-header">
    <div class="sf-topbar">
        <div class="sf-container sf-topbar-inner">
            <span>Order requests only. No online payment is taken.</span>
            <label class="sf-region">
                <span class="sr-only">Currency and region</span>
                <select aria-label="Currency and region" title="Only TZS is configured for this store">
                    <option><?php echo e($sf_currency ?? storefront_currency($sf_tenant, $sf_settings)); ?> &middot; Tanzania</option>
                </select>
            </label>
        </div>
    </div>

    <div class="sf-header-inner">
        <a class="sf-brand" href="index.php" aria-label="<?php echo e($sf_company); ?> Online shop home">
            <?php if ($sf_logo !== ''): ?>
                <img src="<?php echo e($sf_logo); ?>" alt="" class="sf-brand-logo">
            <?php endif; ?>
            <span class="sf-brand-text">
                <strong><?php echo e($sf_company); ?></strong>
                <small>Online shop</small>
            </span>
        </a>

        <div class="sf-search-wrap">
            <form class="sf-search" action="index.php" method="get" role="search" data-suggest-url="storefront-suggest.php">
                <label class="sr-only" for="sf-search-input">Search products</label>
                <input type="search" id="sf-search-input" name="q" value="<?php echo e($sf_search_q); ?>" maxlength="100"
                       placeholder="Search products, codes or SKU" autocomplete="off" role="combobox"
                       aria-autocomplete="list" aria-controls="sf-search-suggest" aria-expanded="false">
                <button type="submit">
                    <i class="fa fa-search" aria-hidden="true"></i>
                    <span class="sr-only">Search</span>
                </button>
            </form>
            <div class="sf-suggest" id="sf-search-suggest" role="listbox" aria-label="Search suggestions" hidden></div>
        </div>

        <div class="sf-actions">
            <a class="sf-header-link" href="<?php echo $sf_logged_in ? 'dashboard.php' : 'login.php'; ?>"
               aria-label="<?php echo $sf_logged_in ? 'Open my account' : 'Log in'; ?>">
                <i class="fa fa-user" aria-hidden="true"></i>
                <span><?php echo $sf_logged_in ? 'Account' : 'Log in'; ?></span>
            </a>
            <a class="sf-cart-link" href="cart.php" aria-label="Cart, <?php echo (int)$sf_cart_count; ?> item(s)">
                <i class="fa fa-shopping-cart" aria-hidden="true"></i>
                <span>Cart</span>
                <?php if ($sf_cart_count > 0): ?>
                    <strong class="sf-cart-count"><?php echo (int)$sf_cart_count; ?></strong>
                <?php endif; ?>
            </a>
        </div>
    </div>

    <nav class="sf-nav" aria-label="Product categories">
        <div class="sf-container sf-nav-inner">
            <a href="index.php"<?php echo empty($sf_category) && empty($sf_search_q) ? ' aria-current="page"' : ''; ?>>All products</a>
            <?php foreach ($sf_nav_categories as $sf_nav_category): ?>
                <a href="index.php?category=<?php echo (int)$sf_nav_category['category_id']; ?>#catalogue"
                   <?php echo isset($sf_category) && (int)$sf_category === (int)$sf_nav_category['category_id'] ? 'aria-current="page"' : ''; ?>>
                    <?php echo e($sf_nav_category['name']); ?>
                </a>
            <?php endforeach; ?>
        </div>
    </nav>
</header>

<?php if ($sf_flash = flash_get()): ?>
    <div class="sf-container sf-flash-wrap">
        <div class="alert alert-<?php echo e($sf_flash['type']); ?> sf-flash" role="alert">
            <?php echo e($sf_flash['message']); ?>
        </div>
    </div>
<?php endif; ?>
