<?php
require_once 'config.php';
require_once 'auth_check.php';
require_permission('manage_pos');
header('Content-Type: application/json; charset=UTF-8');
verify_csrf();

$pid = (int)($_POST['product_id'] ?? 0);
$price = (float)($_POST['price'] ?? 0);
$qty = (float)($_POST['quantity'] ?? 0);

// Resolve the store from the signed-in user's accessible stores.
$stores = accessible_stores(null, false);
$store = $stores ? find_accessible_store((int)$stores[0]['warehouse_id'], false) : null;
if (!$store) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'message' => 'No active store is available for your account.']);
    exit;
}
$warehouse_id = (int)$store['warehouse_id'];

if (!isset($_SESSION['pos_cart'][$pid])) {
    echo json_encode(['ok' => false, 'message' => 'Item not in cart.']);
    exit;
}

$item = $_SESSION['pos_cart'][$pid];
$tax_enabled = tax_enabled();

if ($qty <= 0) {
    unset($_SESSION['pos_cart'][$pid]);
    echo json_encode(['ok' => true, 'removed' => true]);
    exit;
}
if ($price < 0) $price = 0;

if (!$item['is_service']) {
    $product = fetch_one(
        "SELECT p.*, GREATEST(0, COALESCE((SELECT s.quantity FROM inventory_stock s
                                WHERE s.product_id = p.product_id AND s.warehouse_id = ? AND s.tenant_id = p.tenant_id), 0)
                                - COALESCE((SELECT SUM(r.quantity) FROM order_stock_reservations r
                                WHERE r.tenant_id = p.tenant_id AND r.product_id = p.product_id AND r.warehouse_id = ? AND r.status = 'active'), 0)) total_stock
         FROM products p WHERE p.product_id = ? AND p.tenant_id = ?",
        $store['warehouse_id'], $store['warehouse_id'], $pid, $current_user->tenant_id
    );
    if ($product && $qty > (float)$product['total_stock']) {
        echo json_encode(['ok' => false, 'message' => 'Not enough stock for ' . $product['name'] . ' at ' . store_label($store) . ' (available: ' . (float)$product['total_stock'] . ').']);
        exit;
    }
}

$item['price'] = $price;
$item['quantity'] = $qty;
$_SESSION['pos_cart'][$pid] = $item;

$subtotal = 0;
$tax_total = 0;
foreach ($_SESSION['pos_cart'] as $it) {
    $line = $it['price'] * $it['quantity'];
    $subtotal += $line;
    $tax_total += $tax_enabled ? $line * ($it['tax_rate'] / 100) : 0;
}
echo json_encode([
    'ok' => true,
    'subtotal' => round($subtotal, 2),
    'tax' => round($tax_total, 2),
    'grand' => round($subtotal + $tax_total, 2),
    'item_count' => count($_SESSION['pos_cart']),
]);