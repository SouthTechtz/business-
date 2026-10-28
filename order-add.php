<?php
require_once 'header.php';
require_once 'auth_check.php';
require_permission('manage_orders');
$page_title = 'New Order';

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
$leads = fetch_all(
    "SELECT lead_id, lead_number, contact_name FROM leads WHERE tenant_id = ? AND status <> 'converted' ORDER BY created_at DESC LIMIT 200",
    $current_user->tenant_id
);

$prefill_lead_id = (int)($_GET['lead_id'] ?? 0);
$prefill_contact_id = (int)($_GET['contact_id'] ?? 0);
$prefill = [
    'name' => '', 'phone' => '', 'email' => '', 'location' => '',
    'contact_id' => $prefill_contact_id, 'lead_id' => $prefill_lead_id, 'shop_id' => 0, 'warehouse_id' => 0,
];
if ($prefill_lead_id > 0) {
    $lead = fetch_one(
        "SELECT * FROM leads WHERE lead_id = ? AND tenant_id = ?",
        $prefill_lead_id,
        $current_user->tenant_id
    );
    if ($lead) {
        $prefill['name'] = $lead['contact_name'];
        $prefill['phone'] = (string)$lead['phone'];
        $prefill['email'] = (string)$lead['email'];
        $prefill['location'] = (string)$lead['location'];
        $prefill['contact_id'] = (int)($lead['contact_id'] ?? 0);
    }
} elseif ($prefill_contact_id > 0) {
    $contact = fetch_one(
        "SELECT * FROM contacts WHERE contact_id = ? AND tenant_id = ?",
        $prefill_contact_id,
        $current_user->tenant_id
    );
    if ($contact) {
        $prefill['name'] = trim(($contact['first_name'] ?? '') . ' ' . ($contact['last_name'] ?? '')) ?: (string)$contact['company_name'];
        $prefill['phone'] = (string)($contact['phone'] ?: $contact['mobile']);
        $prefill['email'] = (string)$contact['email'];
        $prefill['location'] = trim(($contact['address'] ?? '') . ' ' . ($contact['city'] ?? ''));
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    if (!form_token_valid()) {
        flash_set('danger', 'This form was already submitted. Check the Orders list before trying again.');
        redirect('orders.php');
    }

    $contact_id = (int)($_POST['contact_id'] ?? 0);
    $lead_id = (int)($_POST['lead_id'] ?? 0);
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

    if ($contact_id > 0) {
        $contact = fetch_one(
            "SELECT * FROM contacts WHERE contact_id = ? AND tenant_id = ? AND is_active = 1",
            $contact_id,
            $current_user->tenant_id
        );
        if (!$contact) {
            $error = 'The selected customer does not exist.';
        }
    }
    if ($lead_id > 0) {
        $lead = fetch_one("SELECT * FROM leads WHERE lead_id = ? AND tenant_id = ?", $lead_id, $current_user->tenant_id);
        if (!$lead) {
            $error = 'The selected lead does not exist.';
        }
    }
    if ($error === '') {
        if ($customer_name === '') $error = 'Customer name is required.';
        elseif ($customer_phone === '') $error = 'Customer phone is required.';
        elseif ($customer_location === '') $error = 'Customer location is required.';
        elseif ($customer_email !== '' && !filter_var($customer_email, FILTER_VALIDATE_EMAIL)) $error = 'Customer email is not valid.';
        elseif (mb_strlen($customer_name) > 191 || mb_strlen($customer_phone) > 50 || mb_strlen($customer_location) > 255) $error = 'Customer details are too long.';
    }

    $selected_shop = null;
    if ($error === '') {
        if ($shop_id > 0) {
            $selected_shop = fetch_one("SELECT * FROM shops WHERE shop_id = ? AND tenant_id = ?", $shop_id, $current_user->tenant_id);
            if (!$selected_shop || !shop_is_accessible($shop_id, false)) {
                $error = 'Please select a shop you have access to.';
            }
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
            if ($pid <= 0) {
                $error = 'Choose a product for every line with a quantity.';
                break;
            }
            if (!is_numeric($qtyRaw)) {
                $error = 'Every quantity must be a number greater than zero.';
                break;
            }
            $qty = round((float)$qtyRaw, 2);
            if ($qty <= 0 || $qty > 99999) {
                $error = 'Quantities must be between 0.01 and 99,999.';
                break;
            }
            $product = fetch_one(
                "SELECT p.*, u.name unit_name FROM products p
                 LEFT JOIN units_of_measurement u ON u.unit_id = p.unit_id AND u.tenant_id = p.tenant_id
                 WHERE p.product_id = ? AND p.tenant_id = ? AND p.is_active = 1",
                $pid,
                $current_user->tenant_id
            );
            if (!$product) {
                $error = 'Line ' . ($i + 1) . ' refers to a product that is not available.';
                break;
            }
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
        if ($error === '' && !$line_items) {
            $error = 'Add at least one product line with a quantity.';
        }
        foreach ($line_items as $li) {
            if ($li['quantity'] > 99999) {
                $error = 'A merged product line exceeds the maximum quantity.';
                break;
            }
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
        $currency = tenant_currency();

        $conn->begin_transaction();
        try {
            $order_number = next_document_number($current_user->tenant_id, 'order', 'ORD');

            $stmt = $conn->prepare(
                "INSERT INTO orders
                    (tenant_id, order_number, contact_id, lead_id, customer_name, customer_phone, customer_location,
                     customer_email, source, status, currency_code, subtotal, total, shop_id, warehouse_id, notes,
                     assigned_to, created_by)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'new', ?, ?, ?, ?, ?, ?, ?, ?)"
            );
            $contact_db = $contact_id ?: null;
            $lead_db = $lead_id ?: null;
            $shop_db = $shop_id ?: null;
            $warehouse_db = $warehouse_id ?: null;
            $assigned_db = $assigned_to ?: null;
            $email_db = $customer_email !== '' ? $customer_email : null;
            $stmt->bind_param(
                'isiissssssddiisii',
                $current_user->tenant_id,
                $order_number,
                $contact_db,
                $lead_db,
                $customer_name,
                $customer_phone,
                $customer_location,
                $email_db,
                $source,
                $currency,
                $subtotal,
                $subtotal,
                $shop_db,
                $warehouse_db,
                $notes,
                $assigned_db,
                $current_user->user_id
            );
            if (!$stmt->execute()) throw new RuntimeException($stmt->error);
            $order_id = (int)$stmt->insert_id;
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
                    $order_id,
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

            $conn->commit();
            consume_form_token();
            audit_log('create', 'order', $order_id, 'Order ' . $order_number . ' created');
            if ($lead_id > 0) {
                try {
                    record_lead_activity($lead_id, (int)$current_user->tenant_id, 'order_link', 'Order ' . $order_number . ' created from lead', null, null, $order_id);
                } catch (Throwable $ignored) {
                    // The order exists; a missing history entry must not fail the request.
                }
            }
            flash_set('success', 'Order ' . $order_number . ' recorded. No stock has been reserved or deducted.');
            redirect('order-view.php?id=' . $order_id);
        } catch (Throwable $ex) {
            $conn->rollback();
            $error = 'Could not save the order: ' . $ex->getMessage();
        }
    }
}

require_once 'navbar.php';
require_once 'sidebar.php';
$cur = tenant_currency();
?>

<div class="page-head">
    <div>
        <h1 class="page-title">New Order</h1>
        <div class="page-sub">Record what the customer requested. This does not create a sale, invoice, payment, or stock movement.</div>
    </div>
    <a href="orders.php" class="btn btn-light"><i class="fa fa-arrow-left mr-1"></i> Back to Orders</a>
</div>

<?php if ($error): ?><div class="alert alert-danger"><?php echo e($error); ?></div><?php endif; ?>

<div class="row">
    <div class="col-lg-10">
        <div class="card">
            <div class="card-body">
                <form method="POST" action="order-add.php" id="orderForm">
                    <?php echo csrf_field(); ?>
                    <?php echo form_token_field(); ?>
                    <div class="row">
                        <div class="col-md-4 form-group">
                            <label>Existing Customer (optional)</label>
                            <select name="contact_id" class="form-control" id="orderContact">
                                <option value="">— No linked customer —</option>
                                <?php foreach ($contacts as $c): ?>
                                    <option value="<?php echo (int)$c['contact_id']; ?>"
                                            data-name="<?php echo e(trim(($c['first_name'] ?? '') . ' ' . ($c['last_name'] ?? '')) ?: $c['company_name']); ?>"
                                            data-phone="<?php echo e($c['phone'] ?: $c['mobile']); ?>"
                                            data-email="<?php echo e($c['email']); ?>"
                                            data-location="<?php echo e(trim(($c['address'] ?? '') . ' ' . ($c['city'] ?? ''))); ?>"
                                        <?php echo (int)($_POST['contact_id'] ?? $prefill['contact_id']) === (int)$c['contact_id'] ? 'selected' : ''; ?>>
                                        <?php echo e((trim(($c['first_name'] ?? '') . ' ' . ($c['last_name'] ?? '')) ?: $c['company_name']) . ' — ' . ($c['phone'] ?: $c['mobile'])); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <small class="text-muted">Details below are a snapshot and remain even if the customer changes.</small>
                        </div>
                        <div class="col-md-4 form-group">
                            <label>Customer Name <span class="text-danger">*</span></label>
                            <input type="text" name="customer_name" id="orderName" class="form-control" maxlength="191" required
                                   value="<?php echo e($_POST['customer_name'] ?? $prefill['name']); ?>">
                        </div>
                        <div class="col-md-4 form-group">
                            <label>Phone <span class="text-danger">*</span></label>
                            <input type="text" name="customer_phone" id="orderPhone" class="form-control" maxlength="50" required
                                   value="<?php echo e($_POST['customer_phone'] ?? $prefill['phone']); ?>">
                        </div>
                        <div class="col-md-6 form-group">
                            <label>Location <span class="text-danger">*</span></label>
                            <input type="text" name="customer_location" id="orderLocation" class="form-control" maxlength="255" required
                                   value="<?php echo e($_POST['customer_location'] ?? $prefill['location']); ?>">
                        </div>
                        <div class="col-md-3 form-group">
                            <label>Email</label>
                            <input type="email" name="customer_email" id="orderEmail" class="form-control" maxlength="191"
                                   value="<?php echo e($_POST['customer_email'] ?? $prefill['email']); ?>">
                        </div>
                        <div class="col-md-3 form-group">
                            <label>Source</label>
                            <select name="source" class="form-control">
                                <?php foreach ($sources as $key => $label): ?>
                                    <option value="<?php echo e($key); ?>" <?php echo ($_POST['source'] ?? 'staff') === $key ? 'selected' : ''; ?>><?php echo e($label); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-3 form-group">
                            <label>Shop</label>
                            <select name="shop_id" class="form-control" id="orderShop">
                                <option value="0">— Not decided —</option>
                                <?php foreach ($shops as $shop): ?>
                                    <option value="<?php echo (int)$shop['shop_id']; ?>" <?php echo (int)($_POST['shop_id'] ?? $prefill['shop_id']) === (int)$shop['shop_id'] ? 'selected' : ''; ?>>
                                        <?php echo e($shop['name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-3 form-group">
                            <label>Store</label>
                            <select name="warehouse_id" class="form-control" id="orderStore">
                                <option value="0">— Not decided —</option>
                                <?php foreach ($stores as $store): ?>
                                    <option value="<?php echo (int)$store['warehouse_id']; ?>"
                                            data-shop="<?php echo (int)$store['shop_id']; ?>"
                                        <?php echo (int)($_POST['warehouse_id'] ?? $prefill['warehouse_id']) === (int)$store['warehouse_id'] ? 'selected' : ''; ?>>
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
                                    <option value="<?php echo (int)$u['user_id']; ?>" <?php echo (int)($_POST['assigned_to'] ?? 0) === (int)$u['user_id'] ? 'selected' : ''; ?>>
                                        <?php echo e(trim($u['first_name'] . ' ' . $u['last_name']) ?: $u['username']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-3 form-group">
                            <label>Linked Lead</label>
                            <select name="lead_id" class="form-control">
                                <option value="0">— No lead —</option>
                                <?php foreach ($leads as $l): ?>
                                    <option value="<?php echo (int)$l['lead_id']; ?>" <?php echo (int)($_POST['lead_id'] ?? $prefill['lead_id']) === (int)$l['lead_id'] ? 'selected' : ''; ?>>
                                        <?php echo e($l['lead_number'] . ' — ' . $l['contact_name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <h6 class="mt-2 mb-2">Requested Products</h6>
                    <div class="table-responsive">
                        <table class="table" id="orderItemsTable">
                            <thead>
                                <tr>
                                    <th>Product</th>
                                    <th style="width:110px;">Quantity</th>
                                    <th style="width:130px;" class="text-right">Unit Price</th>
                                    <th style="width:130px;" class="text-right">Line Total</th>
                                    <th style="width:180px;">Line Note</th>
                                    <th style="width:48px;"></th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr>
                                    <td>
                                        <select name="product_id[]" class="form-control order-product">
                                            <option value="">— Select Product —</option>
                                            <?php foreach ($products as $p): ?>
                                                <option value="<?php echo (int)$p['product_id']; ?>" data-price="<?php echo e($p['sale_price']); ?>" data-label="<?php echo e($p['name'] . ' (' . $p['code'] . ')'); ?>">
                                                    <?php echo e($p['name'] . ' (' . $p['code'] . ')'); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </td>
                                    <td><input type="number" name="quantity[]" class="form-control order-qty" min="0.01" step="0.01" value="1"></td>
                                    <td class="text-right order-price">0.00</td>
                                    <td class="text-right order-line font-weight-semibold">0.00</td>
                                    <td><input type="text" name="line_notes[]" class="form-control" maxlength="255" placeholder="Optional"></td>
                                    <td class="text-right"><button type="button" class="btn btn-action btn-light text-danger order-remove"><i class="fa fa-times"></i></button></td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                    <button type="button" class="btn btn-sm btn-outline-primary mb-3" id="orderAddRow"><i class="fa fa-plus mr-1"></i> Add Product Line</button>

                    <div class="d-flex justify-content-end mb-4">
                        <div style="width:320px;">
                            <div class="cart-total-row cart-grand"><span>Indicative Total</span><span id="orderGrand">0.00</span></div>
                            <small class="text-muted d-block text-right">Catalogue prices are snapshots. Final pricing is agreed at confirmation.</small>
                        </div>
                    </div>

                    <div class="form-group">
                        <label>Notes</label>
                        <textarea name="notes" class="form-control" rows="3"><?php echo e($_POST['notes'] ?? ''); ?></textarea>
                    </div>

                    <button type="submit" class="btn btn-primary"><i class="fa fa-check mr-1"></i> Record Order</button>
                </form>
            </div>
        </div>
    </div>
</div>

<script>
    var orderProducts = <?php echo json_encode(array_map(fn($p) => [
        'id' => (int)$p['product_id'],
        'price' => (float)$p['sale_price'],
    ], $products)); ?>;

    function orderRecalc() {
        var grand = 0;
        $('#orderItemsTable tbody tr').each(function () {
            var pid = $(this).find('.order-product').val();
            var info = orderProducts.find(function (p) { return p.id == pid; });
            var qty = parseFloat($(this).find('.order-qty').val()) || 0;
            var price = info ? info.price : 0;
            var total = price * qty;
            grand += total;
            $(this).find('.order-price').text(price.toFixed(2));
            $(this).find('.order-line').text(total.toFixed(2));
        });
        $('#orderGrand').text(grand.toFixed(2));
    }

    function orderAddRow() {
        var clone = $('#orderItemsTable tbody tr').first().clone();
        clone.find('.order-product').val('');
        clone.find('.order-qty').val('1');
        clone.find('input[name="line_notes[]"]').val('');
        clone.find('.order-price, .order-line').text('0.00');
        $('#orderItemsTable tbody').append(clone);
        orderRecalc();
    }

    $(function () {
        $('#orderContact').on('change', function () {
            var opt = $(this).find('option:selected');
            if (!$(this).val()) return;
            $('#orderName').val(opt.data('name') || '');
            $('#orderPhone').val(opt.data('phone') || '');
            $('#orderEmail').val(opt.data('email') || '');
            $('#orderLocation').val(opt.data('location') || '');
        });
        $('#orderShop').on('change', function () {
            var shop = $(this).val();
            $('#orderStore option').each(function () {
                var ok = !shop || shop === '0' || $(this).data('shop') == shop || !$(this).val() || $(this).val() === '0';
                $(this).prop('disabled', !ok);
            });
            var selected = $('#orderStore option:selected');
            if (selected.val() && selected.val() !== '0' && selected.prop('disabled')) {
                $('#orderStore').val('0');
            }
        }).trigger('change');
        $('#orderItemsTable').on('change input', '.order-product, .order-qty', orderRecalc);
        $('#orderItemsTable').on('click', '.order-remove', function () {
            if ($('#orderItemsTable tbody tr').length > 1) {
                $(this).closest('tr').remove();
                orderRecalc();
            }
        });
        $('#orderAddRow').on('click', orderAddRow);
        orderRecalc();
    });
</script>

<?php require_once 'footer.php'; ?>
