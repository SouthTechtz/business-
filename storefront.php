<?php
require_once __DIR__ . '/config.php';

// ============================================================
// Public storefront helpers
//
// The storefront only creates order requests. It reads live catalogue
// stock and pricing but never reserves stock, creates sales, invoices or
// changes staff permissions.
// ============================================================

/** The tenant whose catalogue is exposed at the public root URL. */
function storefront_tenant() {
    static $loaded = false;
    static $tenant = null;
    if ($loaded) return $tenant;
    $loaded = true;

    $tenant = fetch_one(
        "SELECT t.*
           FROM tenants t
           LEFT JOIN system_settings s ON s.tenant_id = t.tenant_id
          ORDER BY (s.setting_id IS NULL) ASC, t.tenant_id ASC
          LIMIT 1"
    );
    return $tenant;
}

/** Storefront settings with safe defaults when no settings row exists. */
function storefront_settings($tenant) {
    $tenant_id = (int)($tenant['tenant_id'] ?? 0);
    $settings = $tenant_id > 0
        ? fetch_one("SELECT * FROM system_settings WHERE tenant_id = ? LIMIT 1", $tenant_id)
        : null;

    if (!$settings) {
        $settings = [
            'company_name'     => (string)($tenant['name'] ?? APP_NAME),
            'company_email'    => (string)($tenant['email'] ?? ''),
            'company_phone'    => (string)($tenant['phone'] ?? ''),
            'company_address'  => (string)($tenant['address'] ?? ''),
            'default_currency' => (string)($tenant['currency_code'] ?? 'TZS'),
            'logo_path'        => null,
            'apply_tax'        => 0,
        ];
    }
    return $settings;
}

/** Currency used by the public catalogue. */
function storefront_currency($tenant, $settings = null) {
    if (is_array($settings) && !empty($settings['default_currency'])) {
        return (string)$settings['default_currency'];
    }
    return (string)($tenant['currency_code'] ?? 'TZS');
}

/** Base path for this installation, with a trailing slash. */
function storefront_base_path() {
    static $base = null;
    if ($base !== null) return $base;

    $script = str_replace('\\', '/', (string)($_SERVER['SCRIPT_NAME'] ?? '/index.php'));
    $dir = rtrim(str_replace('\\', '/', dirname($script)), '/');
    if ($dir === '.' || $dir === '/') $dir = '';
    $base = $dir . '/';
    return $base;
}

/** Local URL for a storefront path. */
function storefront_url($path = '') {
    return storefront_base_path() . ltrim((string)$path, '/');
}

/** Absolute URL for feeds, structured data and canonical links. */
function storefront_absolute_url($path = '') {
    $scheme = (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off') ? 'https' : 'http';
    $host = (string)($_SERVER['HTTP_HOST'] ?? 'localhost');
    return $scheme . '://' . $host . storefront_url($path);
}

/** Cart contents as product_id => quantity, with defensive cleanup. */
function storefront_cart() {
    $raw = $_SESSION['storefront_cart'] ?? [];
    if (!is_array($raw)) return [];

    $cart = [];
    foreach ($raw as $product_id => $quantity) {
        $product_id = (int)$product_id;
        $quantity = (int)$quantity;
        if ($product_id > 0 && $quantity > 0) {
            $cart[$product_id] = min(999, $quantity);
        }
    }
    return $cart;
}

/** Total number of units in the public cart. */
function storefront_cart_count() {
    return array_sum(storefront_cart());
}

/** Path to a valid, locally stored image; empty when unavailable. */
function storefront_image_path($path) {
    $path = trim((string)$path);
    if ($path === '') return '';
    $path = str_replace('\\', '/', $path);
    if (strpos($path, '..') !== false) return '';
    if (preg_match('#^https?://#i', $path)) return $path;
    return file_exists(__DIR__ . '/' . ltrim($path, '/')) ? ltrim($path, '/') : '';
}

/** Absolute image URL for feeds and structured data. */
function storefront_image_url($path) {
    $path = storefront_image_path($path);
    if ($path === '') return '';
    if (preg_match('#^https?://#i', $path)) return $path;
    return storefront_absolute_url($path);
}

/** The configured logo, or the built-in logo fallback. */
function storefront_logo($settings) {
    $logo = storefront_image_path($settings['logo_path'] ?? '');
    if ($logo !== '') return $logo;
    return file_exists(__DIR__ . '/assets/images/logo.png') ? 'assets/images/logo.png' : '';
}

/** Public product path. The .htaccess rewrite also accepts the query-string fallback. */
function storefront_product_url($product) {
    $id = (int)($product['product_id'] ?? 0);
    $name = (string)($product['name'] ?? '');
    $slug = strtolower(trim((string)preg_replace('/[^a-z0-9]+/i', '-', $name), '-'));
    return 'product/' . $id . ($slug !== '' ? '/' . $slug : '');
}

/** Correlated stock expression used by catalogue filters and cards. */
function storefront_stock_expr() {
    return "COALESCE((
        SELECT SUM(s.quantity)
          FROM inventory_stock s
          JOIN warehouses sw ON sw.warehouse_id = s.warehouse_id AND sw.tenant_id = s.tenant_id
         WHERE s.product_id = p.product_id
           AND s.tenant_id = p.tenant_id
           AND sw.is_godown = 0
    ), 0)";
}

/** Actual units sold, excluding cancelled orders, for popularity sorting. */
function storefront_sold_expr() {
    return "COALESCE((
        SELECT SUM(oi.quantity)
          FROM order_items oi
          JOIN orders o ON o.order_id = oi.order_id AND o.tenant_id = oi.tenant_id
         WHERE oi.product_id = p.product_id
           AND oi.tenant_id = p.tenant_id
           AND o.status <> 'cancelled'
    ), 0)";
}

/** Common SELECT for public product rows. */
function storefront_product_select() {
    return "SELECT p.*, c.name AS category_name, u.name AS unit_name, u.short_name AS unit_short,
                   " . storefront_stock_expr() . " AS stock_qty,
                   " . storefront_sold_expr() . " AS sold_qty
              FROM products p
              LEFT JOIN product_categories c ON c.category_id = p.category_id AND c.tenant_id = p.tenant_id
              LEFT JOIN units_of_measurement u ON u.unit_id = p.unit_id AND u.tenant_id = p.tenant_id";
}

/** Normalise catalogue filters supplied by a browser. */
function storefront_normalise_filters($filters = []) {
    $clean = [];
    $query = trim((string)($filters['q'] ?? ''));
    $clean['q'] = mb_substr($query, 0, 100);

    $clean['category'] = max(0, (int)($filters['category'] ?? 0));
    $clean['min_price'] = isset($filters['min_price']) && $filters['min_price'] !== ''
        ? max(0, (float)$filters['min_price']) : null;
    $clean['max_price'] = isset($filters['max_price']) && $filters['max_price'] !== ''
        ? max(0, (float)$filters['max_price']) : null;
    if ($clean['min_price'] !== null && $clean['max_price'] !== null && $clean['min_price'] > $clean['max_price']) {
        [$clean['min_price'], $clean['max_price']] = [$clean['max_price'], $clean['min_price']];
    }

    $clean['size'] = mb_substr(trim((string)($filters['size'] ?? '')), 0, 50);
    $clean['color'] = mb_substr(trim((string)($filters['color'] ?? '')), 0, 50);
    $availability = (string)($filters['availability'] ?? '');
    $clean['availability'] = in_array($availability, ['in', 'out'], true) ? $availability : '';
    $sort = (string)($filters['sort'] ?? '');
    $clean['sort'] = in_array($sort, ['newest', 'price_asc', 'price_desc', 'popular', 'name'], true) ? $sort : 'name';
    $clean['exclude_id'] = max(0, (int)($filters['exclude_id'] ?? 0));
    $clean['sold_only'] = !empty($filters['sold_only']);

    return $clean;
}

/** Build the shared WHERE clause for catalogue queries. */
function storefront_catalogue_where($tenant_id, array $filters, array &$params) {
    $filters = storefront_normalise_filters($filters);
    $stock_sql = storefront_stock_expr();
    $sold_sql = storefront_sold_expr();

    $sql = " WHERE p.tenant_id = ? AND p.is_active = 1";
    $params = [(int)$tenant_id];

    if ($filters['q'] !== '') {
        $like = '%' . $filters['q'] . '%';
        $sql .= " AND (p.name LIKE ? OR p.code LIKE ? OR p.sku LIKE ? OR p.barcode LIKE ? OR p.description LIKE ?)";
        array_push($params, $like, $like, $like, $like, $like);
    }
    if ($filters['category'] > 0) {
        $sql .= " AND p.category_id = ?";
        $params[] = (int)$filters['category'];
    }
    if ($filters['min_price'] !== null) {
        $sql .= " AND p.sale_price >= ?";
        $params[] = (float)$filters['min_price'];
    }
    if ($filters['max_price'] !== null) {
        $sql .= " AND p.sale_price <= ?";
        $params[] = (float)$filters['max_price'];
    }
    if ($filters['size'] !== '') {
        $sql .= " AND p.variant_size = ?";
        $params[] = $filters['size'];
    }
    if ($filters['color'] !== '') {
        $sql .= " AND p.variant_color = ?";
        $params[] = $filters['color'];
    }
    if ($filters['availability'] === 'in') {
        $sql .= " AND {$stock_sql} > 0";
    } elseif ($filters['availability'] === 'out') {
        $sql .= " AND {$stock_sql} <= 0";
    }
    if ($filters['exclude_id'] > 0) {
        $sql .= " AND p.product_id <> ?";
        $params[] = (int)$filters['exclude_id'];
    }
    if ($filters['sold_only']) {
        $sql .= " AND {$sold_sql} > 0";
    }

    return $sql;
}

/** Count matching catalogue products. */
function storefront_catalogue_count($tenant_id, $filters = []) {
    $params = [];
    $where = storefront_catalogue_where($tenant_id, $filters, $params);
    $row = fetch_one("SELECT COUNT(*) AS total FROM products p" . $where, ...$params);
    return (int)($row['total'] ?? 0);
}

/** Fetch a page of catalogue products with filters and sorting. */
function storefront_catalogue($tenant_id, $filters = [], $limit = 24, $offset = 0) {
    $filters = storefront_normalise_filters($filters);
    $params = [];
    $where = storefront_catalogue_where($tenant_id, $filters, $params);

    $order = match ($filters['sort']) {
        'newest' => 'p.created_at DESC, p.product_id DESC',
        'price_asc' => 'p.sale_price ASC, p.name ASC',
        'price_desc' => 'p.sale_price DESC, p.name ASC',
        'popular' => 'sold_qty DESC, p.name ASC',
        default => 'p.name ASC, p.product_id ASC',
    };

    $limit = max(1, min(100, (int)$limit));
    $offset = max(0, (int)$offset);
    $params[] = $limit;
    $params[] = $offset;

    return fetch_all(
        storefront_product_select() . $where . " ORDER BY {$order} LIMIT ? OFFSET ?",
        ...$params
    );
}

/** Backwards-compatible compact catalogue lookup. */
function storefront_products($tenant_id, $query = '', $category_id = 0) {
    return storefront_catalogue($tenant_id, [
        'q' => $query,
        'category' => $category_id,
    ], 200, 0);
}

/** One active public product, or null when it is not in this tenant's catalogue. */
function storefront_product($tenant_id, $product_id) {
    return fetch_one(
        storefront_product_select() . " WHERE p.tenant_id = ? AND p.product_id = ? AND p.is_active = 1 LIMIT 1",
        (int)$tenant_id,
        (int)$product_id
    );
}

/** Public product categories for the tenant. */
function storefront_categories($tenant_id) {
    return fetch_all(
        "SELECT category_id, name
           FROM product_categories
          WHERE tenant_id = ?
          ORDER BY name ASC",
        (int)$tenant_id
    );
}

/** Price and variant options available to catalogue filters. */
function storefront_filter_options($tenant_id) {
    $tenant_id = (int)$tenant_id;
    $prices = fetch_one(
        "SELECT MIN(sale_price) AS min_price, MAX(sale_price) AS max_price
           FROM products
          WHERE tenant_id = ? AND is_active = 1",
        $tenant_id
    ) ?: [];
    $sizes = fetch_all(
        "SELECT DISTINCT variant_size AS value
           FROM products
          WHERE tenant_id = ? AND is_active = 1 AND variant_size IS NOT NULL AND variant_size <> ''
          ORDER BY variant_size",
        $tenant_id
    );
    $colors = fetch_all(
        "SELECT DISTINCT variant_color AS value
           FROM products
          WHERE tenant_id = ? AND is_active = 1 AND variant_color IS NOT NULL AND variant_color <> ''
          ORDER BY variant_color",
        $tenant_id
    );

    return [
        'min_price' => isset($prices['min_price']) ? (float)$prices['min_price'] : 0.0,
        'max_price' => isset($prices['max_price']) ? (float)$prices['max_price'] : 0.0,
        'sizes' => array_values(array_filter(array_map(fn($row) => (string)$row['value'], $sizes))),
        'colors' => array_values(array_filter(array_map(fn($row) => (string)$row['value'], $colors))),
    ];
}

/** Whether an active product can be added to an order request. */
function storefront_available($product) {
    if (!$product) return false;
    if ((int)$product['is_service'] === 1) return true;
    return (float)($product['stock_qty'] ?? 0) > 0;
}

/** Maximum order quantity for a product, respecting visible shop stock. */
function storefront_max_quantity($product) {
    if (!$product || (int)$product['is_service'] === 1) return 999;
    return max(0, min(999, (int)floor((float)($product['stock_qty'] ?? 0))));
}

/** Current cart rows with fresh catalogue prices. Returns [items, missing_product_ids]. */
function storefront_cart_details($tenant_id) {
    $items = [];
    $missing = [];
    foreach (storefront_cart() as $product_id => $quantity) {
        $product = storefront_product($tenant_id, $product_id);
        if (!$product) {
            $missing[] = (int)$product_id;
            continue;
        }
        $product['cart_quantity'] = (int)$quantity;
        $product['max_quantity'] = storefront_max_quantity($product);
        if ($product['max_quantity'] > 0 && $product['cart_quantity'] > $product['max_quantity']) {
            $product['cart_quantity'] = $product['max_quantity'];
        }
        $product['line_total'] = round((float)$product['sale_price'] * $product['cart_quantity'], 2);
        $items[] = $product;
    }
    return [$items, $missing];
}

/** Sum of the current cart lines. */
function storefront_cart_subtotal($items) {
    $subtotal = 0.0;
    foreach ($items as $item) {
        $subtotal += (float)$item['line_total'];
    }
    return round($subtotal, 2);
}

/** Estimated tax from each product's stored tax rate. */
function storefront_cart_tax($items, $settings) {
    if ((int)($settings['apply_tax'] ?? 0) !== 1) return 0.0;
    $tax = 0.0;
    foreach ($items as $item) {
        $rate = max(0.0, (float)($item['tax_rate'] ?? 0));
        $tax += (float)$item['line_total'] * ($rate / 100);
    }
    return round($tax, 2);
}

/** Cart money totals. */
function storefront_cart_totals($items, $settings) {
    $subtotal = storefront_cart_subtotal($items);
    $tax = storefront_cart_tax($items, $settings);
    return [
        'subtotal' => $subtotal,
        'tax' => $tax,
        'total' => round($subtotal + $tax, 2),
    ];
}

/** Payment options configured for the business. */
function storefront_payment_methods($tenant_id) {
    return fetch_all(
        "SELECT name FROM payment_methods WHERE tenant_id = ? ORDER BY payment_method_id",
        (int)$tenant_id
    );
}

/** Latest active products. */
function storefront_new_arrivals($tenant_id, $limit = 4) {
    return storefront_catalogue($tenant_id, ['sort' => 'newest'], $limit, 0);
}

/** Products with real completed/non-cancelled order history. */
function storefront_best_sellers($tenant_id, $limit = 4) {
    return storefront_catalogue($tenant_id, ['sort' => 'popular', 'sold_only' => true], $limit, 0);
}

/** Products from the same category, falling back to the rest of the catalogue. */
function storefront_related_products($tenant_id, $product, $limit = 4) {
    $filters = ['exclude_id' => (int)($product['product_id'] ?? 0), 'sort' => 'popular'];
    if ((int)($product['category_id'] ?? 0) > 0) {
        $filters['category'] = (int)$product['category_id'];
        $related = storefront_catalogue($tenant_id, $filters, $limit, 0);
        if ($related) return $related;
    }
    return storefront_catalogue($tenant_id, $filters, $limit, 0);
}

/** Record a product view in this session. */
function storefront_record_view($product_id) {
    $product_id = (int)$product_id;
    if ($product_id <= 0) return;
    $views = $_SESSION['storefront_recently_viewed'] ?? [];
    if (!is_array($views)) $views = [];
    $views = array_values(array_filter(array_map('intval', $views), fn($id) => $id > 0 && $id !== $product_id));
    array_unshift($views, $product_id);
    $_SESSION['storefront_recently_viewed'] = array_slice($views, 0, 12);
}

/** Products this visitor viewed recently. */
function storefront_recently_viewed($tenant_id, $limit = 4, $exclude_id = 0) {
    $views = $_SESSION['storefront_recently_viewed'] ?? [];
    if (!is_array($views) || !$views) return [];

    $products = [];
    $seen = [];
    foreach ($views as $product_id) {
        $product_id = (int)$product_id;
        if ($product_id <= 0 || $product_id === (int)$exclude_id || isset($seen[$product_id])) continue;
        $seen[$product_id] = true;
        $product = storefront_product($tenant_id, $product_id);
        if ($product) {
            $products[] = $product;
            if (count($products) >= max(1, (int)$limit)) break;
        }
    }
    return $products;
}

/** Render one product card for the catalogue and related sections. */
function storefront_render_product_card($product, $currency) {
    $image = storefront_image_path($product['image'] ?? '');
    $available = storefront_available($product);
    $max_qty = storefront_max_quantity($product);
    $unit = trim((string)($product['unit_short'] ?? '')) ?: trim((string)($product['unit_name'] ?? ''));
    $url = storefront_product_url($product);
    $sku = trim((string)($product['sku'] ?? '')) ?: trim((string)($product['code'] ?? ''));
    $compare = isset($product['compare_at_price']) ? (float)$product['compare_at_price'] : 0.0;
    $is_sale = $compare > (float)$product['sale_price'];
    $rating = isset($product['rating']) ? (float)$product['rating'] : 0.0;
    $reviews = isset($product['review_count']) ? (int)$product['review_count'] : 0;

    echo '<article class="sf-product-card">';
    echo '<div class="sf-product-media">';
    if ($image !== '') {
        echo '<a href="' . e($url) . '" aria-label="View ' . e($product['name']) . '">';
        echo '<img src="' . e($image) . '" alt="' . e($product['name']) . '" loading="lazy" width="400" height="300">';
        echo '</a>';
    } else {
        echo '<span class="sf-product-placeholder">No image</span>';
    }
    if ((int)$product['is_service'] === 1) {
        echo '<span class="sf-product-tag">Service</span>';
    } elseif ($is_sale) {
        echo '<span class="sf-product-tag is-sale">Sale</span>';
    }
    echo '</div>';

    echo '<div class="sf-product-body">';
    if (!empty($product['category_name'])) {
        echo '<div class="sf-product-category">' . e($product['category_name']) . '</div>';
    }
    echo '<h3><a href="' . e($url) . '">' . e($product['name']) . '</a></h3>';
    echo '<div class="sf-product-code">' . e($sku !== '' ? 'SKU ' . $sku : 'Code ' . $product['code']) . '</div>';
    if (trim((string)$product['description']) !== '') {
        echo '<p class="sf-product-desc">' . e(mb_substr((string)$product['description'], 0, 120)) . (mb_strlen((string)$product['description']) > 120 ? '...' : '') . '</p>';
    } else {
        echo '<p class="sf-product-desc">See the product page for details.</p>';
    }
    $variant = [];
    if (trim((string)($product['variant_size'] ?? '')) !== '') $variant[] = 'Size ' . $product['variant_size'];
    if (trim((string)($product['variant_color'] ?? '')) !== '') $variant[] = 'Colour ' . $product['variant_color'];
    if ($variant) {
        echo '<div class="sf-product-variant">' . e(implode(' / ', $variant)) . '</div>';
    }
    if ($reviews > 0) {
        echo '<div class="sf-rating"><strong>' . e(number_format($rating, 1)) . ' out of 5</strong><span>(' . $reviews . ' review' . ($reviews === 1 ? '' : 's') . ')</span></div>';
    }
    echo '<div class="sf-product-meta">';
    echo '<span class="sf-price">' . e(money($product['sale_price'], $currency)) . '</span>';
    if ($is_sale) {
        echo '<span class="sf-price-compare">' . e(money($compare, $currency)) . '</span>';
    }
    if ($unit !== '') echo '<span class="sf-unit">/ ' . e($unit) . '</span>';
    echo '</div>';

    echo '<div class="sf-product-footer">';
    if ($available) {
        if ((int)$product['is_service'] !== 1 && $max_qty > 0 && $max_qty <= 5) {
            echo '<span class="sf-stock low-stock">Low stock: ' . $max_qty . ' left</span>';
        } else {
            echo '<span class="sf-stock in-stock">' . ((int)$product['is_service'] === 1 ? 'Service available' : 'In stock') . '</span>';
        }
    } else {
        echo '<span class="sf-stock out-stock">Out of stock</span>';
    }
    echo '<form method="post" action="storefront-cart-action.php" class="sf-add-form">';
    echo csrf_field();
    echo '<input type="hidden" name="action" value="add">';
    echo '<input type="hidden" name="product_id" value="' . (int)$product['product_id'] . '">';
    echo '<input type="hidden" name="quantity" value="1">';
    echo '<button type="submit" class="sf-btn sf-btn-add" ' . ($available ? '' : 'disabled') . '>';
    echo '<i class="fa fa-shopping-cart" aria-hidden="true"></i> ' . ($available ? 'Add to cart' : 'Unavailable');
    echo '</button></form>';
    echo '</div>';
    echo '</div></article>';
}
