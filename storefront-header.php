<?php
if (!isset($sf_tenant) || !$sf_tenant) {
    http_response_code(503);
    exit('The storefront is not configured.');
}
$sf_settings = $sf_settings ?? storefront_settings($sf_tenant);
$sf_company = storefront_company_name($sf_tenant, $sf_settings);
$sf_logo = storefront_logo($sf_settings);
$sf_cart_count = storefront_cart_count();
$sf_search_q = $sf_search_q ?? '';
$sf_title = isset($page_title) && $page_title !== '' ? $page_title : 'Shop';
$sf_tenant_id = $sf_tenant_id ?? (int)$sf_tenant['tenant_id'];
$sf_nav_categories = $sf_nav_categories ?? storefront_categories($sf_tenant_id);
$sf_meta_description = $sf_meta_description ?? ('Browse the ' . $sf_company . ' catalogue and send an order request. No online payment is taken.');
$sf_canonical_url = $sf_canonical_url ?? storefront_absolute_url('index.php');
$sf_is_catalogue = basename((string)($_SERVER['SCRIPT_NAME'] ?? '')) === 'index.php';

// Which tab should be highlighted on this request?
$sf_current_script = basename((string)($_SERVER['SCRIPT_NAME'] ?? 'index.php'));
$sf_is_shop_all = $sf_is_catalogue && !empty($_GET['all']);
$sf_is_category_filter = $sf_is_catalogue && !empty($sf_category) && !$sf_is_shop_all;
$sf_tab_home = $sf_is_catalogue && !$sf_is_shop_all && !$sf_is_category_filter && $sf_search_q === '';
$sf_tab_catalogue = $sf_is_catalogue && !$sf_tab_home;
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
    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=figtree:400,500,600,700,800,900|plus-jakarta-sans:400,500,600,700,800,900&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/bootstrap.min.css?v=<?php echo filemtime('assets/css/bootstrap.min.css'); ?>">
    <link rel="stylesheet" href="assets/css/font-awesome.min.css?v=<?php echo filemtime('assets/css/font-awesome.min.css'); ?>">
    <link rel="stylesheet" href="assets/css/storefront.css?v=<?php echo filemtime('assets/css/storefront.css'); ?>">
    <link rel="stylesheet" href="assets/css/visionshop-tailwind.css?v=<?php echo filemtime('assets/css/visionshop-tailwind.css'); ?>">
    <script defer src="assets/js/storefront.js?v=<?php echo file_exists('assets/js/storefront.js') ? filemtime('assets/js/storefront.js') : time(); ?>"></script>
    <?php echo $sf_head_extra ?? ''; ?>
</head>
<body class="sf-body">
<a class="sf-skip-link" href="#sf-main">Skip to content</a>

<header class="sf-header">
    <div class="sf-topbar">
        <div class="sf-container sf-topbar-inner">
            <span>Order requests only. No online payment is taken.</span>
            <nav class="sf-company-links" aria-label="About our company">
                <a href="index.php#about-us"><?php echo e($sf_company); ?></a>
            </nav>
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

        <div class="sf-actions">
            <a class="sf-header-link" href="login.php" aria-label="Log in" title="Log in">
                <i class="fa fa-user" aria-hidden="true"></i>
                <span class="sr-only">Log in</span>
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

    <div class="sf-tabbar">
        <div class="sf-container">
            <nav class="sf-tabs" aria-label="Shop navigation">
                <a class="sf-tab" href="index.php" <?php echo $sf_tab_home ? 'aria-current="page"' : ''; ?>>
                    <i class="fa fa-home" aria-hidden="true"></i><span>Home</span>
                </a>
                <a class="sf-tab" href="index.php?all=1#catalogue" <?php echo $sf_tab_catalogue ? 'aria-current="page"' : ''; ?>>
                    <i class="fa fa-th-large" aria-hidden="true"></i><span>Catalogue</span>
                </a>
                <a class="sf-tab" href="categories.php" <?php echo $sf_current_script === 'categories.php' ? 'aria-current="page"' : ''; ?>>
                    <i class="fa fa-tags" aria-hidden="true"></i><span>Categories</span>
                </a>
                <a class="sf-tab" href="wholesale-registration.php" <?php echo $sf_current_script === 'wholesale-registration.php' ? 'aria-current="page"' : ''; ?>>
                    <i class="fa fa-briefcase" aria-hidden="true"></i><span>Wholesales</span>
                </a>
                <a class="sf-tab" href="about.php" <?php echo $sf_current_script === 'about.php' ? 'aria-current="page"' : ''; ?>>
                    <i class="fa fa-building" aria-hidden="true"></i><span>About Us</span>
                </a>
                <a class="sf-tab" href="help.php" <?php echo $sf_current_script === 'help.php' ? 'aria-current="page"' : ''; ?>>
                    <i class="fa fa-question-circle" aria-hidden="true"></i><span>Help</span>
                </a>
            </nav>
        </div>
    </div>
</header>

<?php if ($sf_flash = flash_get()): ?>
    <div class="sf-container sf-flash-wrap">
        <div class="alert alert-<?php echo e($sf_flash['type']); ?> sf-flash" role="alert">
            <?php echo e($sf_flash['message']); ?>
        </div>
    </div>
<?php endif; ?>
