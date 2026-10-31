<?php
require_once 'header.php';
require_once 'auth_check.php';
require_permission('manage_pos');
$page_title = 'New Sale';

$tax_enabled = tax_enabled();

// Resolve the selling store from the signed-in user's accessible stores.
$stores = accessible_stores(null, false);
$selected_store = $stores ? find_accessible_store((int)$stores[0]['warehouse_id'], false) : null;
if (!$selected_store) {
    flash_set('danger', 'No active store is available for your account. Ask an administrator to grant shop/store access.');
    redirect('index.php');
}
$selected_store_id = (int)$selected_store['warehouse_id'];
$shop_id_for_store = (int)($selected_store['shop_id'] ?? 0);
if ($shop_id_for_store > 0 && shop_inventory_ready($shop_id_for_store)) {
    $shop_wh = shop_inventory_warehouse_id($shop_id_for_store);
    if ($shop_wh) { $selected_store_id = $shop_wh; }
}
$_SESSION['pos_warehouse_id'] = $selected_store_id;

// ---- Cart actions ----
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'set_store') {
        flash_set('danger', 'The POS session location is fixed. Close the session to sell from another shop/store.');
        redirect('sale-add.php');
    }

    if ($action === 'add_item') {
        $pid = (int)($_POST['product_id'] ?? 0);
        $qty = (float)($_POST['quantity'] ?? 1);
        $product = fetch_one(
            "SELECT p.*, GREATEST(0, COALESCE((SELECT s.quantity FROM inventory_stock s
                                    WHERE s.product_id = p.product_id AND s.warehouse_id = ? AND s.tenant_id = p.tenant_id), 0)
                                    - COALESCE((SELECT SUM(r.quantity) FROM order_stock_reservations r
                                    WHERE r.tenant_id = p.tenant_id AND r.product_id = p.product_id AND r.warehouse_id = ? AND r.status = 'active'), 0)) total_stock
             FROM products p WHERE p.product_id = ? AND p.tenant_id = ? AND p.is_active = 1",
            $selected_store_id, $selected_store_id, $pid, $current_user->tenant_id
        );
        if ($product && $qty > 0) {
            if (!$product['is_service'] && $qty > (float)$product['total_stock']) {
                flash_set('danger', 'Not enough stock for ' . $product['name'] . ' at ' . store_label($selected_store) . ' (available: ' . (float)$product['total_stock'] . ').');
                redirect('sale-add.php');
            }
            $_SESSION['pos_cart'][$pid] = [
                'product_id'    => $pid,
                'code'          => $product['code'],
                'name'          => $product['name'],
                'price'         => (float)$product['sale_price'],
                'tax_rate'      => (float)$product['tax_rate'],
                'quantity'      => $qty,
                'is_service'    => (int)$product['is_service'],
            ];
        }
        redirect('sale-add.php');
    }

    if ($action === 'update_item') {
        $pid = (int)($_POST['product_id'] ?? 0);
        $qty = (float)($_POST['quantity'] ?? 0);
        if (isset($_SESSION['pos_cart'][$pid])) {
            if ($qty <= 0) {
                unset($_SESSION['pos_cart'][$pid]);
            } else {
                $item = $_SESSION['pos_cart'][$pid];
                if (!$item['is_service']) {
                    $product = fetch_one(
                        "SELECT p.*, GREATEST(0, COALESCE((SELECT s.quantity FROM inventory_stock s
                                                WHERE s.product_id = p.product_id AND s.warehouse_id = ? AND s.tenant_id = p.tenant_id), 0)
                                                - COALESCE((SELECT SUM(r.quantity) FROM order_stock_reservations r
                                                WHERE r.tenant_id = p.tenant_id AND r.product_id = p.product_id AND r.warehouse_id = ? AND r.status = 'active'), 0)) total_stock
                         FROM products p WHERE p.product_id = ? AND p.tenant_id = ?",
                        $selected_store_id, $selected_store_id, $pid, $current_user->tenant_id
                    );
                    if (!$product || $qty > (float)$product['total_stock']) {
                        $available = $product ? (float)$product['total_stock'] : 0;
                        flash_set('danger', 'Not enough stock for ' . $item['name'] . ' at ' . store_label($selected_store) . ' (available: ' . $available . ').');
                        redirect('sale-add.php');
                    }
                }
                $item['quantity'] = $qty;
                $_SESSION['pos_cart'][$pid] = $item;
            }
        }
        redirect('sale-add.php');
    }

    if ($action === 'remove_item') {
        $pid = (int)($_POST['product_id'] ?? 0);
        unset($_SESSION['pos_cart'][$pid]);
        redirect('sale-add.php');
    }

    if ($action === 'clear_cart') {
        unset($_SESSION['pos_cart']);
        redirect('sale-add.php');
    }

    if ($action === 'checkout') {
        $contact_id = (int)($_POST['contact_id'] ?? 0);
        $cart = $_SESSION['pos_cart'] ?? [];
        if (!$cart) {
            flash_set('danger', 'Your cart is empty.');
            redirect('sale-add.php');
        }

        // A registered, active customer is required before a partial payment
        // can be accepted. Anything else is treated as a walk-in sale.
        if ($contact_id > 0) {
            $registered_customer = fetch_one(
                "SELECT contact_id FROM contacts
                  WHERE contact_id = ? AND tenant_id = ? AND is_customer = 1 AND is_active = 1",
                $contact_id,
                $current_user->tenant_id
            );
            if (!$registered_customer) {
                flash_set('danger', 'Select a valid registered customer, or continue as a walk-in customer.');
                redirect('sale-add.php');
            }
            $contact_id = (int)$registered_customer['contact_id'];
        } else {
            $contact_id = 0;
        }

        $discount = (float)($_POST['discount'] ?? 0);
        if ($discount < 0) $discount = 0;

        // The checkout location always comes from the server-side POS session.
        // A posted warehouse_id is intentionally ignored.
        $subtotal = 0;
        $tax_total = 0;
        $line_items = [];
        foreach ($cart as $pid => $item) {
            $line_total = $item['price'] * $item['quantity'];
            $line_tax = $tax_enabled ? $line_total * ($item['tax_rate'] / 100) : 0;
            $subtotal += $line_total;
            $tax_total += $line_tax;
            $line_items[] = [
                'pid'         => $pid,
                'name'        => $item['name'],
                'qty'         => $item['quantity'],
                'price'       => $item['price'],
                'tax'         => $line_tax,
                'subtotal'    => $line_total,
                'total'       => $line_total + $line_tax,
                'is_service'  => $item['is_service'],
            ];
        }
        $grand_total = $subtotal + $tax_total - $discount;
        if ($grand_total < 0) {
            flash_set('danger', 'Discount cannot exceed the order total.');
            redirect('sale-add.php');
        }

        $amount_paid = isset($_POST['amount_paid']) && $_POST['amount_paid'] !== ''
            ? round((float)$_POST['amount_paid'], 2)
            : $grand_total;
        if ($amount_paid < 0) {
            flash_set('danger', 'Amount paid cannot be negative.');
            redirect('sale-add.php');
        }
        if ($amount_paid > $grand_total + 0.005) {
            flash_set('danger', 'Amount paid cannot exceed the sale total of ' . money($grand_total, tenant_currency()) . '.');
            redirect('sale-add.php');
        }
        if ($contact_id === 0 && abs($amount_paid - $grand_total) > 0.005) {
            flash_set('danger', 'Walk-in customers must pay the full total. Select a registered customer to accept a partial payment.');
            redirect('sale-add.php');
        }

        $order_number = 'POS-' . date('Ymd') . '-' . str_pad(mt_rand(1, 999), 3, '0', STR_PAD_LEFT);

        $conn->begin_transaction();
        try {
            // The session and its location cannot change while checkout runs.
            $locked_session = fetch_one(
                "SELECT session_id, warehouse_id FROM pos_sessions
                 WHERE session_id = ? AND tenant_id = ? AND user_id = ? AND is_closed = 0
                 FOR UPDATE",
                $session_id,
                $current_user->tenant_id,
                $current_user->user_id
            );
            if (!$locked_session || (int)$locked_session['warehouse_id'] !== $selected_store_id) {
                throw new Exception('Your POS session was closed or its location changed. Open a new session and try again.');
            }

            // Lock and re-check store stock inside the transaction. Active
            // order reservations are not sellable by POS.
            $stock_lines = $line_items;
            usort($stock_lines, fn($a, $b) => (int)$a['pid'] <=> (int)$b['pid']);
            foreach ($stock_lines as $li) {
                if ($li['is_service']) continue;
                $stock = fetch_one(
                    "SELECT quantity FROM inventory_stock
                     WHERE product_id = ? AND warehouse_id = ? AND tenant_id = ? FOR UPDATE",
                    $li['pid'], $selected_store_id, $current_user->tenant_id
                );
                $on_hand = $stock ? (float)$stock['quantity'] : 0.0;
                $reserved = reserved_stock_quantity(
                    (int)$current_user->tenant_id,
                    (int)$li['pid'],
                    (int)$selected_store_id
                );
                $available = $on_hand - $reserved;
                if ($available + 0.0001 < (float)$li['qty']) {
                    throw new Exception(
                        'Not enough available stock for ' . $li['name'] . ' at ' . store_label($selected_store)
                        . ' (requested: ' . (float)$li['qty'] . ', available: ' . max(0, $available)
                        . ', on hand: ' . $on_hand . ', reserved by orders: ' . $reserved . ').'
                    );
                }
            }

            $stmt = $conn->prepare(
                "INSERT INTO pos_orders (tenant_id, session_id, warehouse_id, order_number, contact_id, order_date, subtotal, tax_amount, discount, total, status, created_by)
                 VALUES (?, ?, ?, ?, ?, NOW(), ?, ?, ?, ?, 'completed', ?)"
            );
            $contact_db = $contact_id ?: null;
            $stmt->bind_param('iiisiddddi', $current_user->tenant_id, $session_id, $selected_store_id, $order_number, $contact_db, $subtotal, $tax_total, $discount, $grand_total, $current_user->user_id);
            $stmt->execute();
            $order_id = $stmt->insert_id;
            $stmt->close();

            foreach ($line_items as $li) {
                $stmt = $conn->prepare(
                    "INSERT INTO pos_order_items (order_id, warehouse_id, product_id, description, quantity, unit_price, tax_amount, subtotal, total)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)"
                );
                $stmt->bind_param('iiisddddd', $order_id, $selected_store_id, $li['pid'], $li['name'], $li['qty'], $li['price'], $li['tax'], $li['subtotal'], $li['total']);
                $stmt->execute();
                $stmt->close();

                if (!$li['is_service']) {
                    $stmt = $conn->prepare(
                        "UPDATE inventory_stock SET quantity = quantity - ? WHERE product_id = ? AND warehouse_id = ? AND tenant_id = ?"
                    );
                    $stmt->bind_param('diii', $li['qty'], $li['pid'], $selected_store_id, $current_user->tenant_id);
                    $stmt->execute();
                    $stmt->close();

                    $stmt = $conn->prepare(
                        "INSERT INTO inventory_movements (tenant_id, product_id, warehouse_id, movement_type, quantity, reference_id, reference_type, created_by)
                         VALUES (?, ?, ?, 'sale', ?, ?, 'pos_order', ?)"
                    );
                    $stmt->bind_param('iiidii', $current_user->tenant_id, $li['pid'], $selected_store_id, $li['qty'], $order_id, $current_user->user_id);
                    $stmt->execute();
                    $stmt->close();
                }
            }

            if ($amount_paid > 0) {
                $stmt = $conn->prepare(
                    "INSERT INTO pos_payments (order_id, amount, payment_method_id, paid_at, created_by) VALUES (?, ?, ?, NOW(), ?)"
                );
                $method = (int)($_POST['payment_method'] ?? 1);
                $method_db = $method ?: 1;
                $stmt->bind_param('idii', $order_id, $amount_paid, $method_db, $current_user->user_id);
                $stmt->execute();
                $stmt->close();
            }

            $conn->commit();
            unset($_SESSION['pos_cart']);
            audit_log('create', 'pos_order', $order_id, 'Sale ' . $order_number . ' created');
            flash_set('success', 'Sale completed successfully.');
            redirect('sale-details.php?id=' . $order_id);
        } catch (Exception $ex) {
            $conn->rollback();
            flash_set('danger', 'Checkout failed: ' . $ex->getMessage());
            redirect('sale-add.php');
        }
    }
}

// ---- Render ----
$cart = $_SESSION['pos_cart'] ?? [];
$products = fetch_all(
    "SELECT p.*, GREATEST(0, COALESCE((SELECT s.quantity FROM inventory_stock s
                           WHERE s.product_id = p.product_id AND s.warehouse_id = ? AND s.tenant_id = p.tenant_id), 0)
                           - COALESCE((SELECT SUM(r.quantity) FROM order_stock_reservations r
                           WHERE r.tenant_id = p.tenant_id AND r.product_id = p.product_id AND r.warehouse_id = ? AND r.status = 'active'), 0)) total_stock
     FROM products p WHERE p.tenant_id = ? AND p.is_active = 1 ORDER BY p.name",
    $selected_store_id, $selected_store_id, $current_user->tenant_id
);
$customers = fetch_all(
    "SELECT contact_id, first_name, last_name, company_name FROM contacts
     WHERE tenant_id = ? AND is_customer = 1 AND is_active = 1 ORDER BY first_name",
    $current_user->tenant_id
);
$methods = fetch_all("SELECT * FROM payment_methods WHERE tenant_id = ?", $current_user->tenant_id);

$subtotal = 0;
$tax_total = 0;
foreach ($cart as $item) {
    $subtotal += $item['price'] * $item['quantity'];
    $tax_total += $tax_enabled ? ($item['price'] * $item['quantity']) * ($item['tax_rate'] / 100) : 0;
}
$grand_total = $subtotal + $tax_total;
$cur = tenant_currency();

require_once 'navbar.php';
require_once 'sidebar.php';
?>

<div class="page-head">
    <div>
        <h1 class="page-title">New Sale</h1>
        <div class="page-sub">
            Selling from <strong><?php echo e(store_label($selected_store)); ?></strong>
            · Session <?php echo e($open_session['session_number']); ?>
            · Location is fixed
        </div>
    </div>
    <div class="d-flex align-items-center">
        <a href="sales.php" class="btn btn-light"><i class="fa fa-arrow-left mr-1"></i> Back to Sales</a>
    </div>
</div>

<?php if ($f = flash_get()): ?>
    <div class="alert alert-<?php echo e($f['type']); ?>"><?php echo e($f['message']); ?></div>
<?php endif; ?>

<div class="pos-layout">
    <div class="pos-products">
        <div class="pos-search">
            <div class="input-group">
                <input type="text" class="form-control" id="posSearch" placeholder="Search name, code, SKU, barcode, description...">
                <div class="input-group-append">
                    <button type="button" class="btn btn-outline-secondary" id="posSearchBtn" title="Search"><i class="fa fa-search"></i></button>
                </div>
            </div>
        </div>
        <div class="pos-list" id="posGrid">
            <?php foreach ($products as $p): ?>
                <div class="pos-row" data-id="<?php echo (int)$p['product_id']; ?>"
                     onclick="addToCart(<?php echo $p['product_id']; ?>)">
                    <div class="pos-row-info">
                        <div class="p-name"><?php echo e($p['name']); ?></div>
                        <div class="p-detail">
                            <span class="badge badge-secondary"><?php echo e($p['code']); ?></span>
                            <?php if (!empty($p['sku']) && $p['sku'] !== $p['code']): ?>
                                <span class="text-muted">SKU: <?php echo e($p['sku']); ?></span>
                            <?php endif; ?>
                            <?php if ($p['is_service']): ?>
                                <span class="text-muted">Service</span>
                            <?php else: ?>
                                <span class="<?php echo (float)$p['total_stock'] <= (float)$p['min_stock_level'] ? 'text-danger font-weight-semibold' : 'text-muted'; ?>">
                                    Stock: <?php echo e((float)$p['total_stock']); ?>
                                </span>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="pos-row-price"><?php echo e(money($p['sale_price'], $cur)); ?></div>
                    <button class="btn btn-sm btn-primary pos-row-btn" title="Add to cart"><i class="fa fa-plus"></i></button>
                </div>
            <?php endforeach; ?>
            <?php if (!$products): ?>
                <div class="text-muted" id="posNoProducts">No active products.</div>
            <?php endif; ?>
        </div>
    </div>

    <div class="pos-cart">
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h5 class="card-title">Cart (<?php echo count($cart); ?>)</h5>
                <?php if ($cart): ?>
                    <form method="POST" action="sale-add.php" class="d-inline">
                        <?php echo csrf_field(); ?>
                        <input type="hidden" name="action" value="clear_cart">
                        <button type="submit" class="btn btn-sm btn-light text-danger">Clear</button>
                    </form>
                <?php endif; ?>
            </div>
            <div class="card-body">
                <form method="POST" action="sale-add.php" id="addForm">
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="action" value="add_item">
                    <input type="hidden" name="product_id" id="addProductId">
                    <input type="hidden" name="quantity" id="addQuantity" value="1">
                </form>
                <form method="POST" action="sale-add.php" id="removeForm">
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="action" value="remove_item">
                    <input type="hidden" name="product_id" id="removeProductId">
                </form>

                <div class="cart-items" id="cartItems">
                    <?php if (!$cart): ?>
                        <div class="text-center text-muted py-4">
                            <i class="fa fa-shopping-cart" style="font-size:32px;color:#cbd5e1;"></i>
                            <div class="mt-2">Your cart is empty.<br>Click products to add them.</div>
                        </div>
                    <?php else: foreach ($cart as $pid => $item): ?>
                        <div class="cart-row" data-pid="<?php echo $pid; ?>" data-tax="<?php echo e($item['tax_rate']); ?>">
                            <div class="flex-grow-1">
                                <div class="font-weight-semibold" style="font-size:13px;"><?php echo e($item['name']); ?></div>
                                <div class="d-flex align-items-center mt-1" style="gap:6px;">
                                    <input type="number" class="form-control form-control-sm cart-price" value="<?php echo e($item['price']); ?>" min="0" step="any" style="width:110px;">
                                    <span class="text-muted small">&times;</span>
                                    <input type="number" class="form-control form-control-sm cart-qty" value="<?php echo e((int)$item['quantity']); ?>" min="0" step="1" style="width:70px;">
                                </div>
                            </div>
                            <div class="font-weight-semibold cart-line-total" style="font-size:13px;">
                                <?php echo e(money($item['price'] * $item['quantity'] * (1 + $item['tax_rate'] / 100), $cur)); ?>
                            </div>
                            <button type="button" class="btn btn-action btn-light text-danger" onclick="removeItem(<?php echo $pid; ?>)"><i class="fa fa-times"></i></button>
                        </div>
                    <?php endforeach; endif; ?>
                </div>

                <div class="mt-3">
                    <div class="cart-total-row"><span>Subtotal</span><span id="ttSubtotal"><?php echo e(money($subtotal, $cur)); ?></span></div>
                    <div class="cart-total-row" id="ttTaxRow" style="<?php echo $tax_enabled ? '' : 'display:none;'; ?>"><span>Tax</span><span id="ttTax"><?php echo e(money($tax_total, $cur)); ?></span></div>
                    <div class="cart-total-row" id="ttDiscountRow" style="<?php echo $discount_hidden ?? 'display:none;'; ?>"><span>Discount</span><span id="ttDiscount">0.00</span></div>
                    <div class="cart-total-row cart-grand"><span>Total</span><span id="ttGrand"><?php echo e(money($grand_total, $cur)); ?></span></div>
                </div>

                <form method="POST" action="sale-add.php" class="mt-3" id="checkoutForm">
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="action" value="checkout">
                    <div class="form-group">
                        <label>Customer</label>
                        <select name="contact_id" id="customerSelect" class="form-control">
                            <option value="0">Walk-in Customer</option>
                            <?php foreach ($customers as $c): ?>
                                <option value="<?php echo $c['contact_id']; ?>"><?php echo e(trim(($c['first_name'] ?? '') . ' ' . ($c['last_name'] ?? '')) ?: $c['company_name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <small class="form-text text-muted" id="customerPaymentHint">Walk-in customers must pay the full total. Select a registered customer to accept a partial payment.</small>
                    </div>
                    <div class="row">
                        <div class="col-7 form-group">
                            <label>Amount Paid (<?php echo e($cur); ?>)</label>
                            <input type="number" name="amount_paid" id="amountPaidInput" class="form-control"
                                   step="0.01" min="0" max="<?php echo e($grand_total); ?>"
                                   value="<?php echo e($grand_total); ?>" required readonly>
                        </div>
                        <div class="col-5 form-group">
                            <label>Payment Method</label>
                            <select name="payment_method" class="form-control">
                                <?php foreach ($methods as $m): ?>
                                    <option value="<?php echo $m['payment_method_id']; ?>"><?php echo e($m['name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-7 form-group">
                            <label>Discount (<?php echo e($cur); ?>)</label>
                            <input type="number" name="discount" id="discountInput" class="form-control" step="1" min="0" value="0" max="<?php echo (int)$grand_total; ?>">
                        </div>
                        <div class="col-5 form-group">
                            <label>Balance</label>
                            <input type="text" id="balanceDisplay" class="form-control" value="<?php echo e(money(0, $cur)); ?>" readonly>
                        </div>
                    </div>
                    <button type="submit" class="btn btn-success btn-block btn-lg" id="checkoutBtn" <?php echo !$cart ? 'disabled' : ''; ?>>
                        <i class="fa fa-check mr-1"></i> Complete Sale — <span id="checkoutTotal"><?php echo e(money($grand_total, $cur)); ?></span>
                    </button>
                </form>
            </div>
        </div>
    </div>
</div>

<script>
    var curSymbol = '<?php echo e($cur); ?>';
    var taxEnabled = <?php echo $tax_enabled ? '1' : '0'; ?>;
    var warehouseId = <?php echo (int)$selected_store_id; ?>;
    var csrfToken = '<?php echo e(csrf_token()); ?>';
    var searchTimer = null;
    var currentDiscount = 0;
    var lastGrand = <?php echo json_encode(round((float)$grand_total, 2)); ?>;
    var amountEdited = false;

    var fmt = function (n) {
        return n.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    };
    var fmtMoney = function (n) {
        return curSymbol + ' ' + fmt(n);
    };

    function buildRow(p) {
        var stockHtml = p.is_service == 1
            ? '<span class="text-muted">Service</span>'
            : '<span class="' + (parseFloat(p.total_stock) <= parseFloat(p.min_stock_level) ? 'text-danger font-weight-semibold' : 'text-muted') + '">Stock: ' + p.total_stock + '</span>';
        var skuHtml = (p.sku && p.sku !== p.code) ? '<span class="text-muted">SKU: ' + p.sku + '</span>' : '';
        var row = document.createElement('div');
        row.className = 'pos-row';
        row.setAttribute('data-id', p.product_id);
        row.onclick = function () { addToCart(p.product_id); };
        row.innerHTML =
            '<div class="pos-row-info">' +
                '<div class="p-name">' + p.name + '</div>' +
                '<div class="p-detail"><span class="badge badge-secondary">' + p.code + '</span>' + skuHtml + ' ' + stockHtml + '</div>' +
            '</div>' +
            '<div class="pos-row-price">' + fmtMoney(parseFloat(p.sale_price)) + '</div>' +
            '<button class="btn btn-sm btn-primary pos-row-btn" title="Add to cart"><i class="fa fa-plus"></i></button>';
        return row;
    }

    function doProductSearch(q) {
        $.ajax({
            url: 'ajax-product-search.php',
            data: { q: q, warehouse_id: warehouseId, pos: 1 },
            dataType: 'json',
            success: function (res) {
                var grid = $('#posGrid');
                grid.empty();
                if (res && res.products && res.products.length) {
                    $.each(res.products, function (i, p) {
                        grid.append(buildRow(p));
                    });
                } else {
                    grid.append('<div class="text-muted">' + (q ? 'No products found for &quot;' + q + '&quot;.' : 'No active products.') + '</div>');
                }
            }
        });
    }

    function recalcTotals() {
        var subtotal = 0, tax = 0;
        $('#cartItems .cart-row').each(function () {
            var price = parseFloat($(this).find('.cart-price').val()) || 0;
            var qty = parseFloat($(this).find('.cart-qty').val()) || 0;
            var line = price * qty;
            var rate = parseFloat($(this).data('tax')) || 0;
            var lineTax = taxEnabled ? line * rate / 100 : 0;
            subtotal += line;
            tax += lineTax;
            $(this).find('.cart-line-total').text(fmtMoney(line + lineTax));
        });
        $('#ttSubtotal').text(fmtMoney(subtotal));
        $('#ttTax').text(fmtMoney(tax));
        $('#ttTaxRow').css('display', taxEnabled ? '' : 'none');
        var d = currentDiscount;
        if (d > subtotal + tax) d = subtotal + tax;
        $('#ttDiscount').text(fmtMoney(d));
        $('#ttDiscountRow').css('display', d > 0 ? '' : 'none');
        var grand = subtotal + tax - d;
        if (grand < 0) grand = 0;
        $('#ttGrand').text(fmtMoney(grand));
        $('#checkoutTotal').text(fmtMoney(grand));
        syncAmountPaid(grand);
    }

    function isWalkIn() {
        return String($('#customerSelect').val()) === '0';
    }

    function updateBalance() {
        var paid = parseFloat($('#amountPaidInput').val());
        if (isNaN(paid) || paid < 0) paid = 0;
        var balance = lastGrand - paid;
        if (balance < 0) balance = 0;
        $('#balanceDisplay').val(fmtMoney(balance));
    }

    function syncAmountPaid(grand) {
        lastGrand = Math.max(0, parseFloat(grand) || 0);
        var $amount = $('#amountPaidInput');
        $amount.attr('max', lastGrand.toFixed(2));
        if (isWalkIn()) {
            $amount.val(lastGrand.toFixed(2));
            amountEdited = false;
        } else if (!amountEdited) {
            $amount.val(lastGrand.toFixed(2));
        }
        updateBalance();
    }

    function persistRow($row) {
        var pid = $row.data('pid');
        var price = parseFloat($row.find('.cart-price').val()) || 0;
        var qty = parseFloat($row.find('.cart-qty').val()) || 0;
        $.ajax({
            url: 'ajax-cart-update.php',
            method: 'POST',
            data: { product_id: pid, price: price, quantity: qty, warehouse_id: warehouseId, csrf_token: csrfToken },
            dataType: 'json',
            success: function (res) {
                if (res && res.removed) { location.reload(); return; }
                if (res && res.ok) {
                    $('#ttSubtotal').text(fmtMoney(parseFloat(res.subtotal) || 0));
                    $('#ttTax').text(fmtMoney(parseFloat(res.tax) || 0));
                    var grand = (parseFloat(res.subtotal) || 0) + (parseFloat(res.tax) || 0) - currentDiscount;
                    if (grand < 0) grand = 0;
                    $('#ttGrand').text(fmtMoney(grand));
                    $('#checkoutTotal').text(fmtMoney(grand));
                    syncAmountPaid(grand);
                } else if (res && res.message) {
                    alert(res.message);
                    location.reload();
                }
            },
            error: function () { location.reload(); }
        });
    }

    $(function () {
        $('#cartItems').on('input', '.cart-price, .cart-qty', function () {
            recalcTotals();
        });
        $('#cartItems').on('change', '.cart-price, .cart-qty', function () {
            persistRow($(this).closest('.cart-row'));
        });

        $('#posSearch').on('input', function () {
            var q = $(this).val().trim();
            clearTimeout(searchTimer);
            searchTimer = setTimeout(function () { doProductSearch(q); }, 200);
        });
        $('#posSearchBtn').on('click', function () {
            doProductSearch($('#posSearch').val().trim());
        });
        $('#posSearch').on('keydown', function (e) {
            if (e.key === 'Enter') {
                e.preventDefault();
                var q = $(this).val().trim().toLowerCase();
                var row = null;
                if (q !== '') {
                    $('#posGrid .pos-row').each(function () {
                        var code = $(this).find('.badge').text().toLowerCase();
                        if (row === null && (code === q || $(this).text().toLowerCase().split(/\s+/).indexOf(q) !== -1)) {
                            row = $(this);
                        }
                    });
                }
                if (!row) row = $('#posGrid .pos-row').first();
                if (row && row.length) {
                    addToCart(row.data('id'));
                    $(this).val('');
                }
            }
        });

        $('#discountInput').on('input', function () {
            var d = parseFloat($(this).val()) || 0;
            if (d < 0) d = 0;
            currentDiscount = d;
            recalcTotals();
        });

        $('#customerSelect').on('change', function () {
            var walkin = isWalkIn();
            $('#amountPaidInput').prop('readonly', walkin);
            amountEdited = false;
            $('#customerPaymentHint').text(walkin
                ? 'Walk-in customers must pay the full total. Select a registered customer to accept a partial payment.'
                : 'Registered customer: you can enter a partial amount; the balance remains due on this sale.');
            syncAmountPaid(lastGrand);
        });

        $('#amountPaidInput').on('input', function () {
            if (isWalkIn()) {
                $(this).val(lastGrand.toFixed(2));
                return;
            }
            amountEdited = true;
            updateBalance();
        });

        $('#checkoutForm').on('submit', function (e) {
            var paid = parseFloat($('#amountPaidInput').val());
            if (isNaN(paid)) paid = 0;
            if (isWalkIn() && Math.abs(paid - lastGrand) > 0.009) {
                e.preventDefault();
                alert('Walk-in customers must pay the full total.');
                return;
            }
            if (paid < 0 || paid > lastGrand + 0.009) {
                e.preventDefault();
                alert('Amount paid must be between 0 and the sale total.');
            }
        });

        syncAmountPaid(lastGrand);
    });

    function addToCart(pid) {
        $('#addProductId').val(pid);
        $('#addQuantity').val(1);
        $('#addForm').submit();
    }
    function removeItem(pid) {
        $('#removeProductId').val(pid);
        $('#removeForm').submit();
    }
</script>

<?php require_once 'footer.php'; ?>
