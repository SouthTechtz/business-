<?php
require_once __DIR__ . '/storefront.php';

$sf_tenant = storefront_tenant();
if (!$sf_tenant) {
    http_response_code(503);
    exit('The storefront is not configured.');
}
$sf_settings = storefront_settings($sf_tenant);
$sf_currency = storefront_currency($sf_tenant, $sf_settings);
$sf_tenant_id = (int)$sf_tenant['tenant_id'];

list($sf_items, $sf_missing) = storefront_cart_details($sf_tenant_id);
if ($sf_missing) {
    foreach ($sf_missing as $sf_missing_id) {
        unset($_SESSION['storefront_cart'][$sf_missing_id]);
    }
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $sf_error = 'Some items are no longer available. Please review your cart before submitting.';
    } else {
        flash_set('warning', 'One or more items are no longer available and were removed from your cart.');
    }
}
if (!$sf_items) {
    flash_set('warning', 'Your cart is empty. Add a product before checking out.');
    redirect('cart.php');
}

$sf_error = $sf_error ?? '';
$sf_form = [
    'customer_name'     => '',
    'customer_phone'    => '',
    'customer_location' => '',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    if (!form_token_valid()) {
        flash_set('danger', 'This order request was already submitted. Please check with our team before trying again.');
        redirect('cart.php');
    }

    foreach (array_keys($sf_form) as $sf_field) {
        $sf_raw = $_POST[$sf_field] ?? '';
        $sf_form[$sf_field] = is_string($sf_raw) ? trim($sf_raw) : '';
    }

    if ($sf_error === '') {
        if ($sf_form['customer_name'] === '') {
            $sf_error = 'Please enter your name.';
        } elseif (mb_strlen($sf_form['customer_name']) > 191) {
            $sf_error = 'Your name is too long.';
        } elseif ($sf_form['customer_phone'] === '') {
            $sf_error = 'Please enter a phone number so we can contact you.';
        } elseif (mb_strlen($sf_form['customer_phone']) > 50) {
            $sf_error = 'The phone number is too long.';
        } elseif (preg_replace('/\D+/', '', $sf_form['customer_phone']) === '') {
            $sf_error = 'Enter a valid phone number.';
        } elseif ($sf_form['customer_location'] === '') {
            $sf_error = 'Please enter your delivery location.';
        } elseif (mb_strlen($sf_form['customer_location']) > 255) {
            $sf_error = 'The delivery location is too long.';
        }
    }

    if ($sf_error === '' && !$sf_items) {
        $sf_error = 'Your cart is empty.';
    }

    if ($sf_error === '') {
        $sf_subtotal = storefront_cart_subtotal($sf_items);
        $sf_estimated_tax = storefront_cart_tax($sf_items, $sf_settings);
        $sf_estimated_total = round($sf_subtotal + $sf_estimated_tax, 2);

        $conn->begin_transaction();
        try {
            $sf_phone_key = preg_replace('/\D+/', '', $sf_form['customer_phone']);
            $sf_contacts = fetch_all(
                "SELECT contact_id, phone, mobile, customer_number
                   FROM contacts
                  WHERE tenant_id = ? AND is_active = 1 AND is_customer = 1
                    AND (phone IS NOT NULL OR mobile IS NOT NULL)
                  ORDER BY contact_id",
                $sf_tenant_id
            );
            $sf_contact_id = 0;
            foreach ($sf_contacts as $sf_contact) {
                $sf_existing_phone = preg_replace('/\D+/', '', (string)($sf_contact['phone'] ?? ''));
                $sf_existing_mobile = preg_replace('/\D+/', '', (string)($sf_contact['mobile'] ?? ''));
                if ($sf_phone_key === $sf_existing_phone || $sf_phone_key === $sf_existing_mobile) {
                    $sf_contact_id = (int)$sf_contact['contact_id'];
                    $sf_customer_number = (string)($sf_contact['customer_number'] ?? '');
                    break;
                }
            }

            if ($sf_contact_id > 0) {
                $sf_locked_contact = fetch_one(
                    "SELECT contact_id, customer_number FROM contacts
                      WHERE contact_id = ? AND tenant_id = ? AND is_active = 1
                      FOR UPDATE",
                    $sf_contact_id,
                    $sf_tenant_id
                );
                if (!$sf_locked_contact) {
                    throw new RuntimeException('The matched customer is no longer available.');
                }
                $sf_customer_number = (string)($sf_locked_contact['customer_number'] ?? '');
                if (empty($sf_customer_number)) {
                    $sf_customer_number = next_document_number($sf_tenant_id, 'customer', 'CUS');
                    $stmt = $conn->prepare(
                        "UPDATE contacts SET customer_number = ?
                          WHERE contact_id = ? AND tenant_id = ? AND customer_number IS NULL"
                    );
                    if (!$stmt) throw new RuntimeException($conn->error);
                    $stmt->bind_param('sii', $sf_customer_number, $sf_contact_id, $sf_tenant_id);
                    if (!$stmt->execute()) throw new RuntimeException($stmt->error);
                    if ($stmt->affected_rows !== 1) throw new RuntimeException('Could not assign a customer number.');
                    $stmt->close();
                }
            } else {
                $sf_customer_number = next_document_number($sf_tenant_id, 'customer', 'CUS');
                $sf_name_parts = preg_split('/\s+/', trim($sf_form['customer_name']), 2);
                $sf_first_name = $sf_name_parts[0] ?? $sf_form['customer_name'];
                $sf_last_name = $sf_name_parts[1] ?? null;
                $stmt = $conn->prepare(
                    "INSERT INTO contacts
                        (tenant_id, contact_type_id, customer_number, first_name, last_name, phone, address, is_customer, is_active)
                     VALUES (?, 1, ?, ?, ?, ?, ?, 1, 1)"
                );
                if (!$stmt) throw new RuntimeException($conn->error);
                $stmt->bind_param(
                    'isssss',
                    $sf_tenant_id,
                    $sf_customer_number,
                    $sf_first_name,
                    $sf_last_name,
                    $sf_form['customer_phone'],
                    $sf_form['customer_location']
                );
                if (!$stmt->execute()) throw new RuntimeException($stmt->error);
                $sf_contact_id = (int)$stmt->insert_id;
                $stmt->close();
            }

            $sf_lead_number = next_document_number($sf_tenant_id, 'lead', 'LEAD');
            $stmt = $conn->prepare(
                "INSERT INTO leads
                    (tenant_id, lead_number, contact_id, contact_name, phone, location, source, status)
                 VALUES (?, ?, ?, ?, ?, ?, 'storefront', 'new')"
            );
            if (!$stmt) throw new RuntimeException($conn->error);
            $stmt->bind_param(
                'isisss',
                $sf_tenant_id,
                $sf_lead_number,
                $sf_contact_id,
                $sf_form['customer_name'],
                $sf_form['customer_phone'],
                $sf_form['customer_location']
            );
            if (!$stmt->execute()) throw new RuntimeException($stmt->error);
            $sf_lead_id = (int)$stmt->insert_id;
            $stmt->close();

            $sf_stmt = $conn->prepare(
                "INSERT INTO storefront_lead_requests (tenant_id, lead_id, currency_code, subtotal)
                 VALUES (?, ?, ?, ?)"
            );
            if (!$sf_stmt) throw new RuntimeException($conn->error);
            $sf_stmt->bind_param('iisd', $sf_tenant_id, $sf_lead_id, $sf_currency, $sf_subtotal);
            if (!$sf_stmt->execute()) throw new RuntimeException($sf_stmt->error);
            $sf_request_id = (int)$sf_stmt->insert_id;
            $sf_stmt->close();

            $stmt = $conn->prepare(
                "INSERT INTO storefront_lead_request_items
                    (tenant_id, request_id, line_no, product_id, product_code_snapshot, product_name_snapshot,
                     product_description_snapshot, unit_label_snapshot, quantity, unit_price, tax_rate_snapshot, line_total)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
            );
            if (!$stmt) throw new RuntimeException($conn->error);
            $sf_line_no = 1;
            foreach ($sf_items as $sf_item) {
                $sf_product_id = (int)$sf_item['product_id'];
                $sf_code = (string)$sf_item['code'];
                $sf_name = (string)$sf_item['name'];
                $sf_description = (string)($sf_item['description'] ?? '');
                $sf_unit = (string)($sf_item['unit_name'] ?? '');
                $sf_quantity = (int)$sf_item['cart_quantity'];
                $sf_unit_price = round((float)$sf_item['sale_price'], 2);
                $sf_tax_rate = round((float)$sf_item['tax_rate'], 2);
                $sf_line_total = round($sf_unit_price * $sf_quantity, 2);
                $stmt->bind_param(
                    'iiiissssdddd',
                    $sf_tenant_id,
                    $sf_request_id,
                    $sf_line_no,
                    $sf_product_id,
                    $sf_code,
                    $sf_name,
                    $sf_description,
                    $sf_unit,
                    $sf_quantity,
                    $sf_unit_price,
                    $sf_tax_rate,
                    $sf_line_total
                );
                if (!$stmt->execute()) throw new RuntimeException($stmt->error);
                $sf_line_no++;
            }
            $stmt->close();

            record_lead_activity(
                $sf_lead_id,
                $sf_tenant_id,
                'created',
                'Storefront order request received',
                'Requested items are awaiting staff confirmation.'
            );

            $conn->commit();
            consume_form_token();
            unset($_SESSION['storefront_cart']);
            $_SESSION['storefront_last_order'] = [
                'lead_number'   => $sf_lead_number,
                'customer_number' => $sf_customer_number,
                'customer_name' => $sf_form['customer_name'],
                'total'         => $sf_estimated_total,
                'tax'           => $sf_estimated_tax,
                'currency'      => $sf_currency,
                'item_count'    => count($sf_items),
                'created_at'    => date('Y-m-d H:i:s'),
            ];
            redirect('order-received.php');
        } catch (Throwable $ex) {
            $conn->rollback();
            error_log('Storefront order failed: ' . $ex->getMessage());
            $sf_error = 'We could not submit your order request. Please try again or contact us directly.';
        }
    }
}

$page_title = 'Checkout';
$sf_totals = storefront_cart_totals($sf_items, $sf_settings);
$sf_subtotal = $sf_totals['subtotal'];
$sf_payment_methods = storefront_payment_methods($sf_tenant_id);
$sf_meta_description = 'Send an order request with your contact and delivery details. No account or online payment is required.';
$sf_canonical_url = storefront_absolute_url('checkout.php');

require __DIR__ . '/storefront-header.php';
?>
<main class="sf-main" id="sf-main">
    <div class="sf-container sf-page">
        <nav aria-label="Breadcrumb">
            <ol class="sf-breadcrumb">
                <li><a href="index.php">Home</a></li>
                <li><a href="cart.php">Cart</a></li>
                <li aria-current="page">Checkout</li>
            </ol>
        </nav>

        <div class="sf-page-head">
            <div>
                <h1>Checkout</h1>
                <p class="sf-muted">Send your order request. No account and no online payment are needed.</p>
            </div>
            <a class="sf-text-link" href="cart.php"><i class="fa fa-arrow-left" aria-hidden="true"></i> Back to cart</a>
        </div>

        <?php if ($sf_error !== ''): ?>
            <div class="alert alert-danger sf-checkout-error" role="alert"><?php echo e($sf_error); ?></div>
        <?php endif; ?>

        <div class="sf-checkout-layout">
            <section class="sf-checkout-card">
                <h2>Contact and delivery details</h2>
                <p class="sf-muted sf-form-intro">We use these details only to confirm and arrange your order.</p>
                <form method="post" action="checkout.php" class="sf-form" novalidate>
                    <?php echo csrf_field(); ?>
                    <?php echo form_token_field(); ?>
                    <div class="sf-form-grid">
                        <div class="sf-form-group">
                            <label for="customer_name">Name <span>*</span></label>
                            <input type="text" id="customer_name" name="customer_name" maxlength="191" required
                                   autocomplete="name" value="<?php echo e($sf_form['customer_name']); ?>">
                        </div>
                        <div class="sf-form-group">
                            <label for="customer_phone">Phone number <span>*</span></label>
                            <input type="tel" id="customer_phone" name="customer_phone" maxlength="50" required
                                   autocomplete="tel" value="<?php echo e($sf_form['customer_phone']); ?>">
                        </div>
                        <div class="sf-form-group sf-form-wide">
                            <label for="customer_location">Location <span>*</span></label>
                            <input type="text" id="customer_location" name="customer_location" maxlength="255" required
                                   autocomplete="street-address" value="<?php echo e($sf_form['customer_location']); ?>">
                        </div>
                    </div>
                    <button type="submit" class="sf-btn sf-btn-primary sf-btn-block sf-submit-order">
                        Send order request
                    </button>
                    <p class="sf-form-footnote">By sending this request you agree to be contacted about availability, pricing and delivery. No payment is taken here.</p>
                </form>
            </section>

            <aside class="sf-summary sf-checkout-summary" aria-label="Order summary">
                <h2>Your order</h2>
                <div class="sf-checkout-items">
                    <?php foreach ($sf_items as $sf_item): ?>
                        <div class="sf-checkout-item">
                            <div>
                                <strong><a href="<?php echo e(storefront_product_url($sf_item)); ?>"><?php echo e($sf_item['name']); ?></a></strong>
                                <span><?php echo (int)$sf_item['cart_quantity']; ?> &times; <?php echo e(money($sf_item['sale_price'], $sf_currency)); ?></span>
                            </div>
                            <span><?php echo e(money($sf_item['line_total'], $sf_currency)); ?></span>
                        </div>
                    <?php endforeach; ?>
                </div>
                <div class="sf-summary-row">
                    <span>Subtotal</span>
                    <strong><?php echo e(money($sf_totals['subtotal'], $sf_currency)); ?></strong>
                </div>
                <div class="sf-summary-row">
                    <span>Estimated tax</span>
                    <strong><?php echo e(money($sf_totals['tax'], $sf_currency)); ?></strong>
                </div>
                <div class="sf-summary-row">
                    <span>Delivery</span>
                    <strong>Quoted after confirmation</strong>
                </div>
                <div class="sf-summary-row sf-summary-total">
                    <span>Estimated total</span>
                    <strong><?php echo e(money($sf_totals['total'], $sf_currency)); ?></strong>
                </div>
                <p class="sf-summary-note">Final pricing, tax, availability and delivery are confirmed by the store. This request does not reserve stock and no payment is taken online.</p>
                <?php if ($sf_payment_methods): ?>
                    <div class="sf-payment-methods" aria-label="Payment methods accepted after order confirmation">
                        <span>Pay after confirmation:</span>
                        <?php foreach ($sf_payment_methods as $sf_payment_method): ?>
                            <strong><?php echo e($sf_payment_method['name']); ?></strong>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
                <a class="sf-summary-back" href="cart.php">Edit cart</a>
            </aside>
        </div>
    </div>
</main>
<?php require __DIR__ . '/storefront-footer.php'; ?>
