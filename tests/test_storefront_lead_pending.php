<?php
require_once __DIR__ . '/../config.php';

function storefront_lead_pending_assert($condition, $message) {
    if (!$condition) {
        fwrite(STDERR, "FAIL: $message\n");
        exit(1);
    }
    echo "PASS: $message\n";
}

$tenant = fetch_one("SELECT tenant_id FROM tenants ORDER BY tenant_id LIMIT 1");
if (!$tenant) {
    echo "SKIP: no tenant is available.\n";
    exit(0);
}

$tenant_id = (int)$tenant['tenant_id'];
$lead_number = 'TESTSTOREFRONT_' . bin2hex(random_bytes(6));
$checkout_name = 'Checkout Registered Name';
$conn->begin_transaction();
try {
    $stmt = $conn->prepare(
        "INSERT INTO contacts (tenant_id, contact_type_id, first_name, last_name, phone, is_customer, is_active)
         VALUES (?, 1, 'Older', 'Contact Name', '0000000000', 1, 1)"
    );
    if (!$stmt) throw new RuntimeException($conn->error);
    $stmt->bind_param('i', $tenant_id);
    if (!$stmt->execute()) throw new RuntimeException($stmt->error);
    $contact_id = (int)$stmt->insert_id;
    $stmt->close();

    $lead_id = db_insert('leads', [
        'tenant_id' => $tenant_id,
        'lead_number' => $lead_number,
        'contact_id' => $contact_id,
        'contact_name' => $checkout_name,
        'source' => 'storefront',
        'status' => 'pending',
    ]);
    storefront_lead_pending_assert($lead_id > 0, 'storefront checkout lead uses pending status');

    $currency = 'TZS';
    $subtotal = 12500.0;
    $stmt = $conn->prepare(
        "INSERT INTO storefront_lead_requests (tenant_id, lead_id, currency_code, subtotal)
         VALUES (?, ?, ?, ?)"
    );
    if (!$stmt) throw new RuntimeException($conn->error);
    $stmt->bind_param('iisd', $tenant_id, $lead_id, $currency, $subtotal);
    if (!$stmt->execute()) throw new RuntimeException($stmt->error);
    $request_id = (int)$stmt->insert_id;
    $stmt->close();

    $line_no = 1;
    $product_id = null;
    $code = 'TEST-PRODUCT';
    $name = 'Storefront Test Product';
    $description = 'Test item';
    $unit = 'piece';
    $quantity = 3.0;
    $unit_price = 2500.0;
    $tax_rate = 0.0;
    $line_total = 7500.0;
    $stmt = $conn->prepare(
        "INSERT INTO storefront_lead_request_items
            (tenant_id, request_id, line_no, product_id, product_code_snapshot, product_name_snapshot,
             product_description_snapshot, unit_label_snapshot, quantity, unit_price, tax_rate_snapshot, line_total)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
    );
    if (!$stmt) throw new RuntimeException($conn->error);
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
    $stmt->close();

    $line_no = 2;
    $product_id = null;
    $code = 'TEST-PRODUCT-2';
    $name = 'Storefront Test Product Two';
    $description = 'Test item two';
    $unit = 'piece';
    $quantity = 2.0;
    $unit_price = 2500.0;
    $tax_rate = 0.0;
    $line_total = 5000.0;
    $stmt = $conn->prepare(
        "INSERT INTO storefront_lead_request_items
            (tenant_id, request_id, line_no, product_id, product_code_snapshot, product_name_snapshot,
             product_description_snapshot, unit_label_snapshot, quantity, unit_price, tax_rate_snapshot, line_total)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
    );
    if (!$stmt) throw new RuntimeException($conn->error);
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
    $stmt->close();

    $visible_lead = fetch_one(
        "SELECT l.status,
                (SELECT GROUP_CONCAT(DISTINCT sli.product_name_snapshot SEPARATOR ', ')
                   FROM storefront_lead_request_items sli
                   JOIN storefront_lead_requests slr ON slr.request_id = sli.request_id AND slr.tenant_id = sli.tenant_id
                  WHERE slr.lead_id = l.lead_id AND slr.tenant_id = l.tenant_id) product_names,
                (SELECT COALESCE(SUM(sli.quantity), 0)
                   FROM storefront_lead_request_items sli
                   JOIN storefront_lead_requests slr ON slr.request_id = sli.request_id AND slr.tenant_id = sli.tenant_id
                  WHERE slr.lead_id = l.lead_id AND slr.tenant_id = l.tenant_id) requested_quantity
           FROM leads l
          WHERE l.tenant_id = ? AND l.lead_id = ? AND l.status = 'pending'",
        $tenant_id,
        $lead_id
    );
    storefront_lead_pending_assert(
        $visible_lead
            && $visible_lead['status'] === 'pending'
            && strpos($visible_lead['product_names'], 'Storefront Test Product') !== false
            && strpos($visible_lead['product_names'], 'Storefront Test Product Two') !== false
            && strpos($visible_lead['product_names'], ' x ') === false
            && (float)$visible_lead['requested_quantity'] === 5.0,
        'pending leads queue displays requested products and total quantity'
    );

    $lead_customer = fetch_one(
        "SELECT l.source, l.contact_name, l.company_name,
                c.first_name contact_first, c.last_name contact_last, c.company_name contact_company
           FROM leads l
           JOIN contacts c ON c.contact_id = l.contact_id AND c.tenant_id = l.tenant_id
          WHERE l.tenant_id = ? AND l.lead_id = ?",
        $tenant_id,
        $lead_id
    );
    storefront_lead_pending_assert(
        lead_customer_display_name($lead_customer) === $checkout_name,
        'lead list displays checkout name instead of an older linked contact name'
    );

    storefront_lead_pending_assert(
        $lead_customer['contact_first'] === 'Older'
            && lead_customer_display_name(array_merge($lead_customer, ['source' => 'storefront'])) === $checkout_name,
        'storefront lead keeps its checkout name even when its linked contact has an old name'
    );

    $conn->rollback();
    echo "Storefront pending lead test passed (rolled back).\n";
} catch (Throwable $ex) {
    $conn->rollback();
    fwrite(STDERR, "ERROR: " . $ex->getMessage() . "\n");
    exit(1);
}
