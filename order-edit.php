<?php
require_once 'header.php';
require_once 'auth_check.php';
require_permission('manage_orders');
$page_title = 'Edit Order';

$id = (int)($_GET['id'] ?? ($_POST['order_id'] ?? 0));
$order = fetch_one(
    "SELECT * FROM orders WHERE order_id = ? AND tenant_id = ?",
    $id,
    $current_user->tenant_id
);
if (!$order) {
    flash_set('danger', 'Order not found.');
    redirect('orders.php');
}
if (!order_is_visible($order)) {
    http_response_code(403);
    die('You do not have access to this order.');
}
if (!in_array($order['status'], ['new', 'under_review'], true)) {
    flash_set('danger', 'Only new or under-review orders can be edited.');
    redirect('order-view.php?id=' . $id);
}

$error = '';
$sources = ['staff' => 'Staff entry', 'phone' => 'Phone', 'whatsapp' => 'WhatsApp', 'walk_in' => 'Walk-in', 'email' => 'Email'];
$contacts = fetch_all(
    "SELECT contact_id, first_name, last_name, company_name, phone, mobile, email, address, city
     FROM contacts WHERE tenant_id = ? AND is_active = 1 ORDER BY first_name, company_name",
    $current_user->tenant_id
);
$products = fetch_all(
    "SELECT p.product_id, p.code, p.name, p.description, p.sale_price, p.tax_rate, u.name unit_name
     FROM products p
     LEFT JOIN units_of_measurement u ON u.unit_id = p.unit_id AND u.tenant_id = p.tenant_id
     WHERE p.tenant_id = ? AND p.is_active = 1 ORDER BY p.name",
    $current_user->tenant_id
);
$shops = accessible_shops(null, false);
$stores = accessible_stores(null, false);
$staff = users_with_permission('manage_orders');
$items = fetch_all(
    "SELECT * FROM order_items WHERE order_id = ? AND tenant_id = ? ORDER BY line_no",
    $id,
    $current_user->tenant_id
);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    $contact_id = (int)($_POST['contact_id'] ?? 0);
    $customer_name = trim($_POST['customer_name'] ?? '');
    $customer_phone = trim($_POST['customer_phone'] ?? '');
    $customer_location = trim($_POST['customer_location'] ?? '');
    $customer_email = trim($_POST['customer_email'] ?? '');
    $source = (string)($_POST['source'] ?? 'staff');
    $shop_id = (int)($_POST['shop_id'] ?? 0);
    $warehouse_id = (int)($_POST['warehouse_id'] ?? 0);
    $assigned_to = (int)($_POST['assigned_to'] ?? 0);
    $notes = trim($_POST['notes'] ?? '');
    $product_ids = $_POST['product_id'] ?? [];
    $quantities = $_POST['quantity'] ?? [];
    $line_notes = $_POST['line_notes'] ?? [];

    if (!isset($sources[$source])) $source = 'staff';
    if (!is_array($product_ids)) $product_ids = [];
    if (!is_array($quantities)) $quantities = [];
    if (!is_array($line_notes)) $line_notes = [];

    if ($contact_id > 0 && !fetch_one(
        "SELECT 1 FROM contacts WHERE contact_id = ? AND tenant_id = ? AND is_active = 1",
        $contact_id,
        $current_user->tenant_id
    )) {
        $error = 'The selected customer does not exist.';
    }
    if ($error === '') {
        if ($customer_name === '') $error = 'Customer name is required.';
        elseif ($customer_phone === '') $error = 'Customer phone is required.';
        elseif ($customer_location === '') $error = 'Customer location is required.';
        elseif ($customer_email !== '' && !filter_var($customer_email, FILTER_VALIDATE_EMAIL)) $error = 'Customer email is not valid.';
        elseif (mb_strlen($customer_name) > 191 || mb_strlen($customer_phone) > 50 || mb_strlen($customer_location) > 255) $error = 'Customer details are too long.';
    }

    if ($error === '') {
        if ($shop_id > 0) {
            if (!shop_is_accessible($shop_id, false)) $error = 'Please select a shop you have access to.';
        }
        if ($error === '' && $warehouse_id > 0) {
            $store = find_accessible_store($warehouse_id, false);
            if (!$store) {
                $error = 'Please select an active store you have access to.';
            } elseif ($shop_id > 0 && (int)$store['shop_id'] !== $shop_id) {
                $error = 'The selected store does not belong to the selected shop.';
            } else {
                $shop_id = (int)$store['shop_id'];
            }
        }
    }
    if ($error === '' && $assigned_to > 0) {
        if (!user_has_tenant_permission($assigned_to, 'manage_orders', (int)$current_user->tenant_id)) {
            $error = 'The selected staff member cannot be assigned orders.';
        }
    }

    $line_items = [];
    if ($error === '') {
        $merged = [];
        foreach ($product_ids as $i => $pid) {
            $pid = (int)$pid;
            $qtyRaw = $quantities[$i] ?? '';
            $line_note = trim((string)($line_notes[$i] ?? ''));
            if ($pid <= 0 && (string)$qtyRaw === '') continue;
            if ($pid <= 0) { $error = 'Choose a product for every line with a quantity.'; break; }
            if (!is_numeric($qtyRaw)) { $error = 'Every quantity must be a number greater than zero.'; break; }
            $qty = round((float)$qtyRaw, 2);
            if ($qty <= 0 || $qty > 99999) { $error = 'Quantities must be between 0.01 and 99,999.'; break; }
            $product = fetch_one(
                "SELECT p.*, u.name unit_name FROM products p
                 LEFT JOIN units_of_measurement u ON u.unit_id = p.unit_id AND u.tenant_id = p.tenant_id
                 WHERE p.product_id = ? AND p.tenant_id = ? AND p.is_active = 1",
                $pid,
                $current_user->tenant_id
            );
            if (!$product) { $error = 'Line ' . ($i + 1) . ' refers to a product that is not available.'; break; }
            if (isset($merged[$pid])) {
                $merged[$pid]['quantity'] = round($merged[$pid]['quantity'] + $qty, 2);
                if ($line_note !== '' && $merged[$pid]['notes'] === '') $merged[$pid]['notes'] = mb_substr($line_note, 0, 255);
                continue;
            }
            $unit_price = round((float)$product['sale_price'], 2);
            $merged[$pid] = [
                'product_id' => $pid,
                'product_code_snapshot' => (string)$product['code'],
                'product_name_snapshot' => (string)$product['name'],
                'product_description_snapshot' => (string)($product['description'] ?? ''),
                'unit_label_snapshot' => (string)($product['unit_name'] ?? ''),
                'quantity' => $qty,
                'unit_price' => $unit_price,
                'tax_rate_snapshot' => round((float)$product['tax_rate'], 2),
                'line_total' => round($unit_price * $qty, 2),
                'notes' => mb_substr($line_note, 0, 255),
            ];
        }
        $line_items = array_values($merged);
        if ($error === '' && !$line_items) $error = 'Add at least one product line with a quantity.';
        foreach ($line_items as $li) {
            if ($li['quantity'] > 99999) { $error = 'A merged product line exceeds the maximum quantity.'; break; }
        }
    }

    if ($error === '') {
        $subtotal = 0.0;
        foreach ($line_items as &$li) {
            $li['line_total'] = round($li['unit_price'] * $li['quantity'], 2);
            $subtotal += $li['line_total'];
        }
        unset($li);
        $subtotal = round($subtotal, 2);

        $conn->begin_transaction();
        try {
            $lock = fetch_one(
                "SELECT status FROM orders WHERE order_id = ? AND tenant_id = ? FOR UPDATE",
                $id,
                $current_user->tenant_id
            );
            if (!$lock || !in_array($lock['status'], ['new', 'under_review'], true)) {
                throw new RuntimeException('The order is no longer editable.');
            }
            $had_active_reservation = (bool)order_reservation_rows(
                $id,
                (int)$current_user->tenant_id,
                ['active'],
                true
            );
            $reserved_lines = 0;

            $contact_db = $contact_id ?: null;
            $shop_db = $shop_id ?: null;
            $warehouse_db = $warehouse_id ?: null;
            $assigned_db = $assigned_to ?: null;
            $email_db = $customer_email !== '' ? $customer_email : null;

            $stmt = $conn->prepare(
                "UPDATE orders SET
                    contact_id = ?, customer_name = ?, customer_phone = ?, customer_location = ?, customer_email = ?,
                    source = ?, subtotal = ?, total = ?, shop_id = ?, warehouse_id = ?, notes = ?, assigned_to = ?
                 WHERE order_id = ? AND tenant_id = ? AND status IN ('new', 'under_review')"
            );
            $stmt->bind_param(
                'isssssddiisiii',
                $contact_db,
                $customer_name,
                $customer_phone,
                $customer_location,
                $email_db,
                $source,
                $subtotal,
                $subtotal,
                $shop_db,
                $warehouse_db,
                $notes,
                $assigned_db,
                $id,
                $current_user->tenant_id
            );
            if (!$stmt->execute()) throw new RuntimeException($stmt->error);
            $stmt->close();

            $stmt = $conn->prepare("DELETE FROM order_items WHERE order_id = ? AND tenant_id = ?");
            $stmt->bind_param('ii', $id, $current_user->tenant_id);
            if (!$stmt->execute()) throw new RuntimeException($stmt->error);
            $stmt->close();

            $line_no = 1;
            $stmt = $conn->prepare(
                "INSERT INTO order_items
                    (tenant_id, order_id, line_no, product_id, product_code_snapshot, product_name_snapshot,
                     product_description_snapshot, unit_label_snapshot, quantity, unit_price, tax_rate_snapshot,
                     line_total, notes)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
            );
            foreach ($line_items as $li) {
                $line_note = $li['notes'] !== '' ? $li['notes'] : null;
                $stmt->bind_param(
                    'iiiissssdddds',
                    $current_user->tenant_id,
                    $id,
                    $line_no,
                    $li['product_id'],
                    $li['product_code_snapshot'],
                    $li['product_name_snapshot'],
                    $li['product_description_snapshot'],
                    $li['unit_label_snapshot'],
                    $li['quantity'],
                    $li['unit_price'],
                    $li['tax_rate_snapshot'],
                    $li['line_total'],
                    $line_note
                );
                if (!$stmt->execute()) throw new RuntimeException($stmt->error);
                $line_no++;
            }
            $stmt->close();

            if ($had_active_reservation) {
                if (!$shop_db || !$warehouse_db) {
                    throw new RuntimeException('Select the shop and store that will supply this order before saving it.');
                }
                $reserved_lines = reserve_order_stock(
                    $id,
                    (int)$current_user->tenant_id,
                    (int)$shop_db,
                    (int)$warehouse_db,
                    (int)$current_user->user_id,
                    'selected shop has low stock for now'
                );
            }

            $conn->commit();
            audit_log('update', 'order', $id, 'Order ' . $order['order_number'] . ' updated'
                . ($had_active_reservation ? '; ' . (int)$reserved_lines . ' stock line(s) re-reserved' : ''));
            flash_set('success', 'Order updated. ' . ($had_active_reservation
                ? (int)$reserved_lines . ' stock line(s) are reserved at the selected store.'
                : 'No stock was changed.'));
            redirect('order-view.php?id=' . $id);
        } catch (Throwable $ex) {
            $conn->rollback();
            $error = 'Could not update the order: ' . $ex->getMessage();
        }
    }

    // Rebuild the form state from the submitted POST.
    $order = array_merge($order, [
        'contact_id' => $contact_id,
        'customer_name' => $customer_name,
        'customer_phone' => $customer_phone,
        'customer_location' => $customer_location,
        'customer_email' => $customer_email,
        'source' => $source,
        'shop_id' => $shop_id,
        'warehouse_id' => $warehouse_id,
        'assigned_to' => $assigned_to,
        'notes' => $notes,
    ]);
}

require_once 'navbar.php';
require_once 'sidebar.php';
$cur = $order['currency_code'] ?: tenant_currency();
$posted_items = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($product_ids) && is_array($product_ids)) {
    foreach ($product_ids as $i => $pid) {
        $posted_items[] = [
            'product_id' => (int)$pid,
            'quantity' => $quantities[$i] ?? '',
            'notes' => $line_notes[$i] ?? '',
        ];
    }
} else {
    foreach ($items as $it) {
        $posted_items[] = [
            'product_id' => (int)($it['product_id'] ?? 0),
            'quantity' => (float)$it['quantity'],
            'notes' => (string)($it['notes'] ?? ''),
        ];
    }
}
if (!$posted_items) $posted_items[] = ['product_id' => 0, 'quantity' => 1, 'notes' => ''];
?>

<div class="page-head">
    <div>
        <h1 class="page-title">Edit Order <?php echo e($order['order_number']); ?></h1>
        <div class="page-sub">Update the request before it is confirmed. Product lines are re-snapshotted from the current catalogue.</div>
    </div>
    <a href="order-view.php?id=<?php echo $id; ?>" class="btn btn-light"><i class="fa fa-arrow-left mr-1"></i> Back to Order</a>
</div>

<?php if ($error): ?><div class="alert alert-danger"><?php echo e($error); ?></div><?php endif; ?>

<div class="card">
    <div class="card-body">
        <form method="POST" action="order-edit.php?id=<?php echo $id; ?>" id="orderEditForm">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="order_id" value="<?php echo $id; ?>">
            <div class="row">
                <div class="col-md-4 form-group">
                    <label>Existing Customer</label>
                    <select name="contact_id" class="form-control" id="editContact">
                        <option value="">— No linked customer —</option>
                        <?php foreach ($contacts as $c): ?>
                            <option value="<?php echo (int)$c['contact_id']; ?>"
                                    data-name="<?php echo e(trim(($c['first_name'] ?? '') . ' ' . ($c['last_name'] ?? '')) ?: $c['company_name']); ?>"
                                    data-phone="<?php echo e($c['phone'] ?: $c['mobile']); ?>"
                                    data-email="<?php echo e($c['email']); ?>"
                                    data-location="<?php echo e(trim(($c['address'] ?? '') . ' ' . ($c['city'] ?? ''))); ?>"
                                <?php echo (int)$order['contact_id'] === (int)$c['contact_id'] ? 'selected' : ''; ?>>
                                <?php echo e((trim(($c['first_name'] ?? '') . ' ' . ($c['last_name'] ?? '')) ?: $c['company_name']) . ' — ' . ($c['phone'] ?: $c['mobile'])); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-4 form-group">
                    <label>Customer Name <span class="text-danger">*</span></label>
                    <input type="text" name="customer_name" id="editName" class="form-control" maxlength="191" required value="<?php echo e($order['customer_name']); ?>">
                </div>
                <div class="col-md-4 form-group">
                    <label>Phone <span class="text-danger">*</span></label>
                    <input type="text" name="customer_phone" id="editPhone" class="form-control" maxlength="50" required value="<?php echo e($order['customer_phone']); ?>">
                </div>
                <div class="col-md-6 form-group">
                    <label>Location <span class="text-danger">*</span></label>
                    <input type="text" name="customer_location" id="editLocation" class="form-control" maxlength="255" required value="<?php echo e($order['customer_location']); ?>">
                </div>
                <div class="col-md-3 form-group">
                    <label>Email</label>
                    <input type="email" name="customer_email" id="editEmail" class="form-control" maxlength="191" value="<?php echo e($order['customer_email']); ?>">
                </div>
                <div class="col-md-3 form-group">
                    <label>Source</label>
                    <select name="source" class="form-control">
                        <?php foreach ($sources as $key => $label): ?>
                            <option value="<?php echo e($key); ?>" <?php echo $order['source'] === $key ? 'selected' : ''; ?>><?php echo e($label); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3 form-group">
                    <label>Shop</label>
                    <select name="shop_id" class="form-control" id="editShop">
                        <option value="0">— Not decided —</option>
                        <?php foreach ($shops as $shop): ?>
                            <option value="<?php echo (int)$shop['shop_id']; ?>" <?php echo (int)$order['shop_id'] === (int)$shop['shop_id'] ? 'selected' : ''; ?>><?php echo e($shop['name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3 form-group">
                    <label>Store</label>
                    <select name="warehouse_id" class="form-control" id="editStore">
                        <option value="0">— Not decided —</option>
                        <?php foreach ($stores as $store): ?>
                            <option value="<?php echo (int)$store['warehouse_id']; ?>" data-shop="<?php echo (int)$store['shop_id']; ?>"
                                <?php echo (int)$order['warehouse_id'] === (int)$store['warehouse_id'] ? 'selected' : ''; ?>>
                                <?php echo e(store_label($store)); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3 form-group">
                    <label>Assigned Staff</label>
                    <select name="assigned_to" class="form-control">
                        <option value="0">— Unassigned —</option>
                        <?php foreach ($staff as $u): ?>
                            <option value="<?php echo (int)$u['user_id']; ?>" <?php echo (int)$order['assigned_to'] === (int)$u['user_id'] ? 'selected' : ''; ?>>
                                <?php echo e(trim($u['first_name'] . ' ' . $u['last_name']) ?: $u['username']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <h6 class="mt-2 mb-2">Requested Products</h6>
            <div class="table-responsive">
                <table class="table" id="editItemsTable">
                    <thead>
                        <tr>
                            <th>Product</th><th style="width:110px;">Quantity</th>
                            <th style="width:130px;" class="text-right">Unit Price</th>
                            <th style="width:130px;" class="text-right">Line Total</th>
                            <th style="width:180px;">Line Note</th><th style="width:48px;"></th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($posted_items as $pi): ?>
                        <tr>
                            <td>
                                <select name="product_id[]" class="form-control edit-product">
                                    <option value="">— Select Product —</option>
                                    <?php foreach ($products as $p): ?>
                                        <option value="<?php echo (int)$p['product_id']; ?>" data-price="<?php echo e($p['sale_price']); ?>"
                                            <?php echo (int)$pi['product_id'] === (int)$p['product_id'] ? 'selected' : ''; ?>>
                                            <?php echo e($p['name'] . ' (' . $p['code'] . ')'); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </td>
                            <td><input type="number" name="quantity[]" class="form-control edit-qty" min="0.01" step="0.01" value="<?php echo e($pi['quantity']); ?>"></td>
                            <td class="text-right edit-price">0.00</td>
                            <td class="text-right edit-line font-weight-semibold">0.00</td>
                            <td><input type="text" name="line_notes[]" class="form-control" maxlength="255" value="<?php echo e($pi['notes']); ?>"></td>
                            <td class="text-right"><button type="button" class="btn btn-action btn-light text-danger edit-remove"><i class="fa fa-times"></i></button></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <button type="button" class="btn btn-sm btn-outline-primary mb-3" id="editAddRow"><i class="fa fa-plus mr-1"></i> Add Product Line</button>

            <div class="d-flex justify-content-end mb-4">
                <div style="width:320px;">
                    <div class="cart-total-row cart-grand"><span>Indicative Total</span><span id="editGrand">0.00</span></div>
                </div>
            </div>

            <div class="form-group">
                <label>Notes</label>
                <textarea name="notes" class="form-control" rows="3"><?php echo e($order['notes']); ?></textarea>
            </div>

            <button type="submit" class="btn btn-primary"><i class="fa fa-check mr-1"></i> Save Changes</button>
        </form>
    </div>
</div>

<script>
    var editProducts = <?php echo json_encode(array_map(fn($p) => ['id' => (int)$p['product_id'], 'price' => (float)$p['sale_price']], $products)); ?>;

    function editRecalc() {
        var grand = 0;
        $('#editItemsTable tbody tr').each(function () {
            var pid = $(this).find('.edit-product').val();
            var info = editProducts.find(function (p) { return p.id == pid; });
            var qty = parseFloat($(this).find('.edit-qty').val()) || 0;
            var price = info ? info.price : 0;
            var total = price * qty;
            grand += total;
            $(this).find('.edit-price').text(price.toFixed(2));
            $(this).find('.edit-line').text(total.toFixed(2));
        });
        $('#editGrand').text(grand.toFixed(2));
    }

    $(function () {
        $('#editContact').on('change', function () {
            var opt = $(this).find('option:selected');
            if (!$(this).val()) return;
            $('#editName').val(opt.data('name') || '');
            $('#editPhone').val(opt.data('phone') || '');
            $('#editEmail').val(opt.data('email') || '');
            $('#editLocation').val(opt.data('location') || '');
        });
        function filterStores() {
            var shop = $('#editShop').val();
            $('#editStore option').each(function () {
                if (!$(this).val()) return;
                $(this).prop('disabled', !!shop && shop !== '0' && $(this).data('shop') != shop);
            });
            var selected = $('#editStore option:selected');
            if (selected.val() && selected.prop('disabled')) $('#editStore').val('0');
        }
        $('#editShop').on('change', filterStores);
        filterStores();
        $('#editItemsTable').on('change input', '.edit-product, .edit-qty', editRecalc);
        $('#editItemsTable').on('click', '.edit-remove', function () {
            if ($('#editItemsTable tbody tr').length > 1) {
                $(this).closest('tr').remove();
                editRecalc();
            }
        });
        $('#editAddRow').on('click', function () {
            var clone = $('#editItemsTable tbody tr').first().clone();
            clone.find('.edit-product').val('');
            clone.find('.edit-qty').val('1');
            clone.find('input[name="line_notes[]"]').val('');
            clone.find('.edit-price, .edit-line').text('0.00');
            $('#editItemsTable tbody').append(clone);
            editRecalc();
        });
        editRecalc();
    });
</script>

<?php require_once 'footer.php'; ?>
