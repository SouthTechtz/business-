<?php
require_once __DIR__ . '/storefront.php';

$sf_tenant = storefront_tenant();
if (!$sf_tenant) {
    http_response_code(503);
    exit('The storefront is not configured.');
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('cart.php');
}

verify_csrf();

$sf_tenant_id = (int)$sf_tenant['tenant_id'];
$sf_action = (string)($_POST['action'] ?? '');
$sf_cart = storefront_cart();

if ($sf_action === 'add') {
    $sf_product_id = (int)($_POST['product_id'] ?? 0);
    $sf_quantity = max(1, min(999, (int)($_POST['quantity'] ?? 1)));
    $sf_product = storefront_product($sf_tenant_id, $sf_product_id);

    if (!$sf_product) {
        flash_set('danger', 'That product is no longer available.');
    } elseif (!storefront_available($sf_product)) {
        flash_set('warning', 'That product is currently out of stock. Please try again later.');
    } else {
        $sf_max = storefront_max_quantity($sf_product);
        $sf_new_quantity = (int)($sf_cart[$sf_product_id] ?? 0) + $sf_quantity;
        if ($sf_new_quantity > $sf_max) {
            $sf_new_quantity = $sf_max;
            flash_set('warning', $sf_product['name'] . ': only ' . $sf_max . ' available, so the cart was limited to that quantity.');
        } else {
            flash_set('success', $sf_product['name'] . ' was added to your cart.');
        }
        $sf_cart[$sf_product_id] = min($sf_max, $sf_new_quantity);
    }
} elseif ($sf_action === 'update') {
    $sf_quantities = $_POST['qty'] ?? [];
    if (is_array($sf_quantities)) {
        foreach ($sf_cart as $sf_product_id => $sf_old_quantity) {
            if (!array_key_exists($sf_product_id, $sf_quantities)) continue;
            $sf_product = storefront_product($sf_tenant_id, (int)$sf_product_id);
            if (!$sf_product || !storefront_available($sf_product)) {
                unset($sf_cart[$sf_product_id]);
                continue;
            }
            $sf_quantity = (int)$sf_quantities[$sf_product_id];
            $sf_max = storefront_max_quantity($sf_product);
            if ($sf_quantity <= 0) {
                unset($sf_cart[$sf_product_id]);
            } else {
                $sf_cart[$sf_product_id] = min($sf_max, $sf_quantity);
            }
        }
    }
    flash_set('success', 'Your cart was updated.');
} elseif ($sf_action === 'remove') {
    $sf_product_id = (int)($_POST['product_id'] ?? 0);
    if ($sf_product_id > 0) {
        unset($sf_cart[$sf_product_id]);
    }
    flash_set('success', 'The item was removed from your cart.');
} elseif ($sf_action === 'clear') {
    $sf_cart = [];
    flash_set('success', 'Your cart is now empty.');
}

$_SESSION['storefront_cart'] = $sf_cart;
redirect('cart.php');
