<?php
require_once __DIR__ . '/storefront.php';

$sf_tenant = storefront_tenant();
if (!$sf_tenant) {
    http_response_code(503);
    exit('The storefront is not configured.');
}

$sf_is_ajax = (isset($_SERVER['HTTP_X_REQUESTED_WITH'])
        && strtolower((string)$_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest')
    || strpos((string)($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json') !== false;

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    if ($sf_is_ajax) {
        http_response_code(405);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => false, 'message' => 'Invalid request method.']);
        exit;
    }
    redirect('cart.php');
}

verify_csrf();

$sf_tenant_id = (int)$sf_tenant['tenant_id'];
$sf_action = (string)($_POST['action'] ?? '');
$sf_cart = storefront_cart();
$sf_product_id = 0;
$sf_ok = true;
$sf_message = 'Your cart was updated.';
$sf_message_type = 'success';

/** Record the result for either a flash message (normal POST) or a JSON response (AJAX). */
$sf_notify = function ($type, $message, $ok = true) use (&$sf_message, &$sf_message_type, &$sf_ok, $sf_is_ajax) {
    $sf_message = $message;
    $sf_message_type = $type;
    $sf_ok = $ok;
    if (!$sf_is_ajax) {
        flash_set($type, $message);
    }
};

if ($sf_action === 'add') {
    $sf_product_id = (int)($_POST['product_id'] ?? 0);
    $sf_quantity = max(1, min(999, (int)($_POST['quantity'] ?? 1)));
    $sf_product = storefront_product($sf_tenant_id, $sf_product_id);

    if (!$sf_product) {
        $sf_notify('danger', 'That product is no longer available.', false);
    } elseif (!storefront_available($sf_product)) {
        $sf_notify('warning', 'That product is currently out of stock. Please try again later.', false);
    } else {
        $sf_max = storefront_max_quantity($sf_product);
        $sf_new_quantity = (int)($sf_cart[$sf_product_id] ?? 0) + $sf_quantity;
        if ($sf_new_quantity > $sf_max) {
            $sf_new_quantity = $sf_max;
            $sf_notify('warning', $sf_product['name'] . ': only ' . $sf_max . ' available, so the cart was limited to that quantity.');
        } else {
            $sf_notify('success', $sf_product['name'] . ' was added to your cart.');
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
    $sf_notify('success', 'Your cart was updated.');
} elseif ($sf_action === 'remove') {
    $sf_product_id = (int)($_POST['product_id'] ?? 0);
    if ($sf_product_id > 0) {
        unset($sf_cart[$sf_product_id]);
    }
    $sf_notify('success', 'The item was removed from your cart.');
} elseif ($sf_action === 'clear') {
    $sf_cart = [];
    $sf_notify('success', 'Your cart is now empty.');
} else {
    $sf_notify('danger', 'The cart action was not recognised.', false);
}

$_SESSION['storefront_cart'] = $sf_cart;

if ($sf_is_ajax) {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode([
        'success'     => $sf_ok,
        'type'        => $sf_message_type,
        'message'     => $sf_message,
        'cart_count'  => storefront_cart_count(),
        'product_id'  => $sf_product_id,
        'quantity'    => $sf_product_id > 0 ? (int)($sf_cart[$sf_product_id] ?? 0) : 0,
        'cart'        => (object)$sf_cart,
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

redirect('cart.php');
