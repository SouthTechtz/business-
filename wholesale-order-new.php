<?php
require_once __DIR__ . '/wholesale_auth.php';
require_once __DIR__ . '/storefront.php';
require_once __DIR__ . '/notification-helpers.php';

$tenant_id = (int)$wholesale_user->tenant_id;
$contact_id = (int)($wholesaler['contact_id'] ?? 0);
$currency = tenant_currency();
$search_q = trim((string)($_GET['q'] ?? ''));
if (mb_strlen($search_q) > 100) $search_q = mb_substr($search_q, 0, 100);

$error = '';
$posted_qty = [];
$posted_location = (string)($wholesaler['location'] ?? '');
$posted_notes = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    if (!form_token_valid()) {
        flash_set('danger', 'This order request was already submitted. Check your order history before trying again.');
        redirect('wholesale-orders.php');
    }

    $posted_location = trim((string)($_POST['location'] ?? ''));
    $posted_notes = trim((string)($_POST['notes'] ?? ''));
    $raw_items = $_POST['items'] ?? [];
    if (!is_array($raw_items)) $raw_items = [];

    $lines = [];
    foreach ($raw_items as $product_id => $quantity) {
        $product_id = (int)$product_id;
        $quantity = (float)$quantity;
        if ($product_id <= 0 || $quantity <= 0) continue;
        $posted_qty[$product_id] = $quantity;
        $product = storefront_product($tenant_id, $product_id);
        if (!$product) continue;
        $quantity = min(9999, $quantity);
        $unit_price = round(product_line_price($product, $quantity), 2);
        $lines[] = [
            'product'    => $product,
            'quantity'   => $quantity,
            'unit_price' => $unit_price,
            'line_total' => round($unit_price * $quantity, 2),
        ];
    }

    if (!$lines) {
        $error = 'Add a quantity for at least one product before submitting your order request.';
    } elseif ($posted_location === '') {
        $error = 'Please confirm the delivery location for this order.';
    } elseif (mb_strlen($posted_location) > 255) {
        $error = 'The delivery location is too long.';
    } elseif (mb_strlen($posted_notes) > 2000) {
        $error = 'Please keep order notes under 2,000 characters.';
    } else {
        $subtotal = 0.0;
        foreach ($lines as $line) $subtotal += $line['line_total'];
        $subtotal = round($subtotal, 2);

        try {
            $conn->begin_transaction();
            $lead_number = next_document_number($tenant_id, 'lead', 'LEAD');
            $stmt = $conn->prepare(
                "INSERT INTO leads
                    (tenant_id, lead_number, contact_id, contact_name, company_name, phone, email, location, source, status, notes, created_by)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'wholesale', 'pending', ?, ?)"
            );
            if (!$stmt) throw new RuntimeException($conn->error);
            $notes = $posted_notes !== '' ? $posted_notes : null;
            $contact_name = (string)$wholesaler['contact_name'];
            $company_name = (string)$wholesaler['business_name'];
            $phone = (string)($wholesaler['phone'] ?? '');
            $email = (string)($wholesaler['email'] ?? '');
            $stmt->bind_param(
                'isissssssi',
                $tenant_id,
                $lead_number,
                $contact_id,
                $contact_name,
                $company_name,
                $phone,
                $email,
                $posted_location,
                $notes,
                $wholesale_user->user_id
            );
            if (!$stmt->execute()) throw new RuntimeException($stmt->error);
            $lead_id = (int)$stmt->insert_id;
            $stmt->close();

            // An online order makes the customer an online customer.
            if ($contact_id > 0) {
                $stmt = $conn->prepare("UPDATE contacts SET customer_channel = 'online' WHERE contact_id = ? AND tenant_id = ?");
                if ($stmt) {
                    $stmt->bind_param('ii', $contact_id, $tenant_id);
                    $stmt->execute();
                    $stmt->close();
                }
            }

            $stmt = $conn->prepare(
                "INSERT INTO storefront_lead_requests (tenant_id, lead_id, currency_code, subtotal)
                 VALUES (?, ?, ?, ?)"
            );
            if (!$stmt) throw new RuntimeException($conn->error);
            $stmt->bind_param('iisd', $tenant_id, $lead_id, $currency, $subtotal);
            if (!$stmt->execute()) throw new RuntimeException($stmt->error);
            $request_id = (int)$stmt->insert_id;
            $stmt->close();

            $stmt = $conn->prepare(
                "INSERT INTO storefront_lead_request_items
                    (tenant_id, request_id, line_no, product_id, product_code_snapshot, product_name_snapshot,
                     product_description_snapshot, unit_label_snapshot, quantity, unit_price, tax_rate_snapshot, line_total)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
            );
            if (!$stmt) throw new RuntimeException($conn->error);
            $line_no = 1;
            foreach ($lines as $line) {
                $product = $line['product'];
                $product_id = (int)$product['product_id'];
                $code = (string)$product['code'];
                $name = (string)$product['name'];
                $description = (string)($product['description'] ?? '');
                $unit = (string)(trim((string)($product['unit_short'] ?? '')) ?: trim((string)($product['unit_name'] ?? '')));
                $quantity = $line['quantity'];
                $unit_price = $line['unit_price'];
                $tax_rate = round((float)($product['tax_rate'] ?? 0), 2);
                $line_total = $line['line_total'];
                $stmt->bind_param(
                    'iiiissssdddd',
                    $tenant_id,
                    $request_id,
                    $line_no,
                    $product_id,
                    $code,
                    $name,
                    $description,
                    $unit,
                    $quantity,
                    $unit_price,
                    $tax_rate,
                    $line_total
                );
                if (!$stmt->execute()) throw new RuntimeException($stmt->error);
                $line_no++;
            }
            $stmt->close();

            record_lead_activity(
                $lead_id,
                $tenant_id,
                'created',
                'Wholesale portal order request received',
                'The wholesale customer submitted this request from their account portal.'
            );

            $conn->commit();
            notification_notify_order_request(
                $tenant_id,
                $lead_id,
                $lead_number,
                $company_name,
                $subtotal,
                $currency,
                'wholesale',
                (int)$wholesale_user->user_id
            );
            consume_form_token();
            audit_log('create', 'wholesale_order', $request_id, 'Wholesale order request ' . $lead_number);
            flash_set('success', 'Order request ' . $lead_number . ' was sent. Our team will confirm stock and pricing.');
            redirect('wholesale-order-view.php?id=' . $request_id);
        } catch (Throwable $exception) {
            $conn->rollback();
            $error = 'We could not submit your order request right now. Please try again.';
        }
    }
}

$products = storefront_catalogue($tenant_id, ['q' => $search_q, 'sort' => 'name'], 40, 0);
$page_title = 'Create order request';

require __DIR__ . '/wholesale-portal-header.php';
?>
<div class="ws-page-head">
    <div>
        <h1>Create order request</h1>
        <p>Choose products and quantities. Our team confirms wholesale pricing, stock and delivery.</p>
    </div>
    <div class="ws-page-head-actions">
        <a class="ws-btn" href="wholesale-dashboard.php"><i class="fa fa-arrow-left" aria-hidden="true"></i> Dashboard</a>
        <a class="ws-btn" href="wholesale-orders.php"><i class="fa fa-list-alt" aria-hidden="true"></i> My orders</a>
    </div>
</div>

<?php if ($error !== ''): ?>
    <div class="ws-form-error" role="alert"><?php echo e($error); ?></div>
<?php endif; ?>

<form method="post" action="wholesale-order-new.php">
    <?php echo csrf_field(); ?>
    <?php echo form_token_field(); ?>
    <?php if ($search_q !== ''): ?><input type="hidden" name="q" value="<?php echo e($search_q); ?>"><?php endif; ?>

    <section class="ws-panel" aria-labelledby="ws-catalogue-title">
        <div class="ws-panel-head">
            <div>
                <h2 id="ws-catalogue-title">Catalogue</h2>
                <p>Enter the quantity you need next to each product.</p>
            </div>
            <span class="text-muted" style="font-size:12px;"><?php echo count($products); ?> product(s)</span>
        </div>
        <div class="ws-catalog-bar">
            <input type="search" name="q" form="ws-product-search" value="<?php echo e($search_q); ?>" placeholder="Search products, codes or SKU" aria-label="Search products">
            <button type="submit" form="ws-product-search" class="ws-btn"><i class="fa fa-search" aria-hidden="true"></i> Search</button>
            <?php if ($search_q !== ''): ?>
                <a class="ws-btn ws-btn-soft" href="wholesale-order-new.php">Clear search</a>
            <?php endif; ?>
        </div>

        <?php if ($products): ?>
            <div class="ws-product-grid">
                <?php foreach ($products as $product): ?>
                    <?php
                    $image = storefront_image_path($product['image'] ?? '');
                    $unit = trim((string)($product['unit_short'] ?? '')) ?: trim((string)($product['unit_name'] ?? ''));
                    $max = storefront_max_quantity($product);
                    $qty_value = isset($posted_qty[(int)$product['product_id']]) ? (float)$posted_qty[(int)$product['product_id']] : 0;
                    ?>
                    <div class="ws-product-row">
                        <span class="ws-product-thumb">
                            <?php if ($image !== ''): ?>
                                <img src="<?php echo e($image); ?>" alt="" loading="lazy">
                            <?php else: ?>
                                <i class="fa fa-cube" aria-hidden="true"></i>
                            <?php endif; ?>
                        </span>
                        <div class="ws-product-info">
                            <strong><?php echo e($product['name']); ?></strong>
                            <small><?php echo e($product['code']); ?><?php echo $unit !== '' ? ' · per ' . e($unit) : ''; ?></small>
                            <div class="ws-product-price"><?php echo e(money($product['sale_price'], $currency)); ?></div>
                            <?php if (product_has_wholesale_price($product)): ?>
                                <small class="ws-product-wholesale">
                                    Wholesale <?php echo e(money($product['wholesale_price'], $currency)); ?>
                                    at <?php echo e(format_quantity($product['wholesale_min_qty'])); ?>+ units
                                </small>
                            <?php endif; ?>
                        </div>
                        <div class="ws-product-qty">
                            <label for="ws-qty-<?php echo (int)$product['product_id']; ?>">Qty</label>
                            <input type="number" id="ws-qty-<?php echo (int)$product['product_id']; ?>"
                                   name="items[<?php echo (int)$product['product_id']; ?>]"
                                   min="0" max="9999" step="1" inputmode="numeric"
                                   value="<?php echo $qty_value > 0 ? e($qty_value) : ''; ?>" placeholder="0">
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php else: ?>
            <div class="ws-empty">
                <i class="fa fa-search" aria-hidden="true"></i>
                <strong>No products found</strong>
                <p><?php echo $search_q !== '' ? 'Try a different search word.' : 'The catalogue is empty right now.'; ?></p>
            </div>
        <?php endif; ?>
    </section>

    <section class="ws-panel" aria-labelledby="ws-delivery-title">
        <div class="ws-panel-head"><h2 id="ws-delivery-title">Delivery &amp; notes</h2></div>
        <div class="ws-panel-body">
            <div class="ws-form-grid">
                <div class="ws-field is-wide">
                    <label for="ws-location">Delivery location <span aria-hidden="true">*</span></label>
                    <input type="text" id="ws-location" name="location" maxlength="255" required
                           value="<?php echo e($posted_location); ?>" placeholder="Town, district or delivery address">
                    <span class="ws-hint">Defaults to your registered delivery area. Our team confirms delivery with you.</span>
                </div>
                <div class="ws-field is-wide">
                    <label for="ws-notes">Order notes</label>
                    <textarea id="ws-notes" name="notes" maxlength="2000" placeholder="Delivery instructions, packaging needs or preferred timing..."><?php echo e($posted_notes); ?></textarea>
                </div>
            </div>
            <div class="ws-form-actions">
                <button type="submit" class="ws-btn ws-btn-primary"><i class="fa fa-paper-plane" aria-hidden="true"></i> Submit order request</button>
                <a class="ws-btn" href="wholesale-dashboard.php">Cancel</a>
                <span class="ws-hint">Submitting a request does not take payment or reserve stock.</span>
            </div>
        </div>
    </section>
</form>

<form id="ws-product-search" method="get" action="wholesale-order-new.php" style="display:none;" aria-hidden="true"></form>
<?php require __DIR__ . '/wholesale-portal-footer.php'; ?>
