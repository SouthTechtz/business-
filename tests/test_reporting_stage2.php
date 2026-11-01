<?php
/** Stage 2 calculations tested with transaction-local fixtures; always rolled back. */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../reporting.php';

$passes = 0;
$failures = 0;
function stage2_assert($condition, $message) {
    global $passes, $failures;
    if (!$condition) { $failures++; echo "FAIL: $message\n"; return; }
    $passes++;
    echo "PASS: $message\n";
}
function stage2_insert($table, $data) {
    $id = db_insert($table, $data);
    if (!$id) throw new RuntimeException("Fixture insert failed: $table");
    return $id;
}
function stage2_delta($after, $before, $key) {
    return (float)$after[$key] - (float)$before[$key];
}

$current_user = User::getUserById(1);
$tenantId = 1;
$productId = 1;
$contactId = 1;
$shopLocationId = 15;
$storeLocationId = 11;
$godownLocationId = 13;
$prefix = 'STAGE2-' . uniqid();
$conn->begin_transaction();

try {
    $before = report_sales_summary('2026-01-01', '2026-01-31');
    $beforeReturnOnly = report_pos_sales_summary('2026-01-20', '2026-01-31');
    $current_user = User::getUserById(20);
    $beforeShop = report_sales_summary('2026-01-01', '2026-01-31');
    $current_user = User::getUserById(1);
    $posA = stage2_insert('pos_orders', ['tenant_id' => $tenantId, 'warehouse_id' => $shopLocationId, 'order_number' => $prefix . '-POS-A', 'order_date' => '2026-01-10 10:00:00', 'subtotal' => 500, 'total' => 500, 'status' => 'completed', 'created_by' => 1]);
    stage2_insert('pos_order_items', ['order_id' => $posA, 'warehouse_id' => $shopLocationId, 'product_id' => $productId, 'description' => 'Stage2 product', 'quantity' => 5, 'unit_price' => 100, 'subtotal' => 500, 'total' => 500]);
    $posB = stage2_insert('pos_orders', ['tenant_id' => $tenantId, 'warehouse_id' => $storeLocationId, 'order_number' => $prefix . '-POS-STORE', 'order_date' => '2026-01-10 10:00:00', 'subtotal' => 900, 'total' => 900, 'status' => 'completed', 'created_by' => 1]);
    stage2_insert('pos_order_items', ['order_id' => $posB, 'warehouse_id' => $storeLocationId, 'product_id' => $productId, 'description' => 'Store sale', 'quantity' => 9, 'unit_price' => 100, 'subtotal' => 900, 'total' => 900]);
    $godownSaleBlocked = false;
    try {
        stage2_insert('pos_orders', ['tenant_id' => $tenantId, 'warehouse_id' => $godownLocationId, 'order_number' => $prefix . '-POS-GODOWN', 'order_date' => '2026-01-10 10:00:00', 'subtotal' => 800, 'total' => 800, 'status' => 'completed', 'created_by' => 1]);
    } catch (Throwable $e) {
        $godownSaleBlocked = strpos($e->getMessage(), 'Godown cannot sell') !== false;
    }
    stage2_assert($godownSaleBlocked, 'Existing database safeguard rejects Godown sales');
    $posD = stage2_insert('pos_orders', ['tenant_id' => $tenantId, 'warehouse_id' => null, 'order_number' => $prefix . '-POS-NULL', 'order_date' => '2026-01-10 10:00:00', 'subtotal' => 50, 'total' => 50, 'status' => 'completed', 'created_by' => 1]);
    stage2_insert('pos_order_items', ['order_id' => $posD, 'warehouse_id' => null, 'product_id' => $productId, 'description' => 'Historical sale', 'quantity' => 1, 'unit_price' => 50, 'subtotal' => 50, 'total' => 50]);
    stage2_insert('pos_orders', ['tenant_id' => $tenantId, 'warehouse_id' => $shopLocationId, 'order_number' => $prefix . '-POS-OUTSIDE', 'order_date' => '2026-02-01 10:00:00', 'subtotal' => 700, 'total' => 700, 'status' => 'completed', 'created_by' => 1]);

    $returnA1 = stage2_insert('sales_returns', ['tenant_id' => $tenantId, 'return_number' => $prefix . '-RA1', 'source_type' => 'pos', 'source_id' => $posA, 'shop_id' => 7, 'warehouse_id' => $shopLocationId, 'return_date' => '2026-01-20 10:00:00', 'refund_amount' => 200, 'status' => 'completed', 'created_by' => 1]);
    stage2_insert('sales_return_items', ['return_id' => $returnA1, 'tenant_id' => $tenantId, 'product_id' => $productId, 'quantity' => 2, 'unit_price' => 100, 'refund_amount' => 200, 'resale_quantity' => 1]);
    $returnA2 = stage2_insert('sales_returns', ['tenant_id' => $tenantId, 'return_number' => $prefix . '-RA2', 'source_type' => 'pos', 'source_id' => $posA, 'shop_id' => 7, 'warehouse_id' => $shopLocationId, 'return_date' => '2026-01-21 10:00:00', 'refund_amount' => 100, 'status' => 'completed', 'created_by' => 1]);
    stage2_insert('sales_return_items', ['return_id' => $returnA2, 'tenant_id' => $tenantId, 'product_id' => $productId, 'quantity' => 1, 'unit_price' => 100, 'refund_amount' => 100, 'resale_quantity' => 1]);
    $returnD = stage2_insert('sales_returns', ['tenant_id' => $tenantId, 'return_number' => $prefix . '-RD', 'source_type' => 'pos', 'source_id' => $posD, 'warehouse_id' => null, 'return_date' => '2026-01-22 10:00:00', 'refund_amount' => 50, 'status' => 'completed', 'created_by' => 1]);
    stage2_insert('sales_return_items', ['return_id' => $returnD, 'tenant_id' => $tenantId, 'product_id' => $productId, 'quantity' => 1, 'unit_price' => 50, 'refund_amount' => 50, 'resale_quantity' => 0]);

    $returnB = stage2_insert('sales_returns', ['tenant_id' => $tenantId, 'return_number' => $prefix . '-RB', 'source_type' => 'pos', 'source_id' => $posB, 'warehouse_id' => $storeLocationId, 'return_date' => '2026-01-22 10:00:00', 'refund_amount' => 900, 'status' => 'completed', 'created_by' => 1]);
    stage2_insert('sales_return_items', ['return_id' => $returnB, 'tenant_id' => $tenantId, 'product_id' => $productId, 'quantity' => 9, 'unit_price' => 100, 'refund_amount' => 900, 'resale_quantity' => 9]);
    $cancelledReturn = stage2_insert('sales_returns', ['tenant_id' => $tenantId, 'return_number' => $prefix . '-RCANCEL', 'source_type' => 'pos', 'source_id' => $posA, 'warehouse_id' => $shopLocationId, 'return_date' => '2026-01-22 10:00:00', 'refund_amount' => 100, 'status' => 'cancelled', 'created_by' => 1]);
    stage2_insert('sales_return_items', ['return_id' => $cancelledReturn, 'tenant_id' => $tenantId, 'product_id' => $productId, 'quantity' => 1, 'unit_price' => 100, 'refund_amount' => 100, 'resale_quantity' => 1]);

    $invoiceA = stage2_insert('invoices', ['tenant_id' => $tenantId, 'invoice_number' => $prefix . '-INV-A', 'warehouse_id' => $shopLocationId, 'contact_id' => $contactId, 'issue_date' => '2026-01-11', 'status_id' => 2, 'subtotal' => 1000, 'tax_amount' => 0, 'total' => 1000, 'amount_due' => 600, 'amount_paid' => 200, 'returned_amount' => 200, 'refund_due' => 0, 'created_by' => 1]);
    stage2_insert('invoice_items', ['tenant_id' => $tenantId, 'invoice_id' => $invoiceA, 'warehouse_id' => $shopLocationId, 'product_id' => $productId, 'description' => 'Stage2 product', 'quantity' => 10, 'unit_price' => 100, 'subtotal' => 1000, 'total' => 1000]);
    $invoiceAReturn = stage2_insert('sales_returns', ['tenant_id' => $tenantId, 'return_number' => $prefix . '-IRA', 'source_type' => 'invoice', 'source_id' => $invoiceA, 'shop_id' => 7, 'warehouse_id' => $shopLocationId, 'return_date' => '2026-01-23 10:00:00', 'refund_amount' => 200, 'status' => 'completed', 'created_by' => 1]);
    stage2_insert('sales_return_items', ['return_id' => $invoiceAReturn, 'tenant_id' => $tenantId, 'product_id' => $productId, 'quantity' => 2, 'unit_price' => 100, 'refund_amount' => 200, 'resale_quantity' => 1]);

    $invoiceB = stage2_insert('invoices', ['tenant_id' => $tenantId, 'invoice_number' => $prefix . '-INV-B', 'warehouse_id' => $shopLocationId, 'contact_id' => $contactId, 'issue_date' => '2026-01-12', 'status_id' => 6, 'subtotal' => 500, 'tax_amount' => 0, 'total' => 500, 'amount_due' => 0, 'amount_paid' => 500, 'returned_amount' => 500, 'refund_due' => 500, 'created_by' => 1]);
    stage2_insert('invoice_items', ['tenant_id' => $tenantId, 'invoice_id' => $invoiceB, 'warehouse_id' => $shopLocationId, 'product_id' => $productId, 'description' => 'Stage2 product', 'quantity' => 5, 'unit_price' => 100, 'subtotal' => 500, 'total' => 500]);
    $invoiceBReturn = stage2_insert('sales_returns', ['tenant_id' => $tenantId, 'return_number' => $prefix . '-IRB', 'source_type' => 'invoice', 'source_id' => $invoiceB, 'shop_id' => 7, 'warehouse_id' => $shopLocationId, 'return_date' => '2026-01-24 10:00:00', 'refund_amount' => 500, 'status' => 'completed', 'created_by' => 1]);
    stage2_insert('sales_return_items', ['return_id' => $invoiceBReturn, 'tenant_id' => $tenantId, 'product_id' => $productId, 'quantity' => 5, 'unit_price' => 100, 'refund_amount' => 500, 'resale_quantity' => 0]);

    stage2_insert('invoices', ['tenant_id' => $tenantId, 'invoice_number' => $prefix . '-INV-CANCEL', 'warehouse_id' => $shopLocationId, 'contact_id' => $contactId, 'issue_date' => '2026-01-12', 'status_id' => 6, 'subtotal' => 300, 'total' => 300, 'amount_due' => 0, 'amount_paid' => 0, 'returned_amount' => 0, 'refund_due' => 0, 'created_by' => 1]);
    $invoiceD = stage2_insert('invoices', ['tenant_id' => $tenantId, 'invoice_number' => $prefix . '-INV-NULL', 'warehouse_id' => null, 'contact_id' => $contactId, 'issue_date' => '2026-01-12', 'status_id' => 2, 'subtotal' => 200, 'total' => 200, 'amount_due' => 200, 'amount_paid' => 0, 'returned_amount' => 0, 'refund_due' => 0, 'created_by' => 1]);
    stage2_insert('invoice_items', ['tenant_id' => $tenantId, 'invoice_id' => $invoiceD, 'warehouse_id' => null, 'product_id' => $productId, 'description' => 'Historical invoice', 'quantity' => 2, 'unit_price' => 100, 'subtotal' => 200, 'total' => 200]);
    stage2_insert('invoices', ['tenant_id' => $tenantId, 'invoice_number' => $prefix . '-INV-OUTSIDE', 'warehouse_id' => $shopLocationId, 'contact_id' => $contactId, 'issue_date' => '2026-02-02', 'status_id' => 2, 'subtotal' => 700, 'total' => 700, 'amount_due' => 700, 'amount_paid' => 0, 'created_by' => 1]);


    $lead = stage2_insert('leads', ['tenant_id' => $tenantId, 'lead_number' => $prefix . '-LEAD', 'contact_name' => 'Stage2 Confirmed Lead', 'phone' => '000', 'status' => 'confirmed', 'created_by' => 1]);
    stage2_insert('orders', ['tenant_id' => $tenantId, 'order_number' => $prefix . '-ORDER-LINKED', 'lead_id' => $lead, 'customer_name' => 'Stage2 Customer', 'customer_phone' => '000', 'customer_location' => 'Test', 'source' => 'staff', 'status' => 'fulfilled', 'total' => 10000, 'shop_id' => 7, 'warehouse_id' => $shopLocationId, 'created_by' => 1, 'fulfilled_invoice_id' => $invoiceA, 'fulfilled_invoice_number' => $prefix . '-INV-A']);
    stage2_insert('orders', ['tenant_id' => $tenantId, 'order_number' => $prefix . '-ORDER-CONFIRMED', 'customer_name' => 'Stage2 Unfulfilled', 'customer_phone' => '000', 'customer_location' => 'Test', 'source' => 'staff', 'status' => 'confirmed', 'total' => 9000, 'shop_id' => 7, 'warehouse_id' => $shopLocationId, 'created_by' => 1]);
    $otherTenant = stage2_insert('tenants', ['name' => $prefix . '-OTHER-TENANT']);
    stage2_insert('pos_orders', ['tenant_id' => $otherTenant, 'warehouse_id' => null, 'order_number' => $prefix . '-OTHER-POS', 'order_date' => '2026-01-10 10:00:00', 'subtotal' => 5000, 'total' => 5000, 'status' => 'completed', 'created_by' => 1]);

    $after = report_sales_summary('2026-01-01', '2026-01-31');
    stage2_assert(stage2_delta($after['pos'], $before['pos'], 'gross_sales') === 550.0, 'POS gross includes eligible Shop and historical NULL sales only');
    stage2_assert(stage2_delta($after['pos'], $before['pos'], 'accepted_returns') === 350.0, 'POS accepted returns include partial and full returns but exclude cancelled returns');
    stage2_assert(stage2_delta($after['pos'], $before['pos'], 'net_sales') === 200.0, 'Net POS sales equals gross less accepted returns');
    stage2_assert($after['pos']['sale_count'] - $before['pos']['sale_count'] === 2, 'POS sale count counts each eligible sale once');
    stage2_assert($after['pos']['return_count'] - $before['pos']['return_count'] === 3, 'Multiple POS returns counted once per return record');
    stage2_assert($after['pos']['returned_qty'] - $before['pos']['returned_qty'] === 4.0, 'POS returned quantity aggregates accepted quantities');
    stage2_assert($after['pos']['resale_qty'] - $before['pos']['resale_qty'] === 2.0, 'POS resale quantity remains distinct from returned quantity');

    stage2_assert(stage2_delta($after['invoice'], $before['invoice'], 'gross_sales') === 1700.0, 'Invoice gross includes active and fully returned invoices, excluding direct cancellation');
    stage2_assert(stage2_delta($after['invoice'], $before['invoice'], 'accepted_returns') === 700.0, 'Invoice accepted returns include partial and full returns at original prices');
    stage2_assert(stage2_delta($after['invoice'], $before['invoice'], 'net_sales') === 1000.0, 'Net Invoice sales includes partial remainder and zero for full return');
    stage2_assert($after['invoice']['sale_count'] - $before['invoice']['sale_count'] === 3, 'Invoice sale count excludes direct cancellation and out-of-range invoice');
    stage2_assert($after['invoice']['outstanding_balance'] - $before['invoice']['outstanding_balance'] === 800.0, 'Outstanding balances use current Invoice balances');
    stage2_assert($after['invoice']['refund_due'] - $before['invoice']['refund_due'] === 500.0, 'Refund due is reported separately from sales and cash refunded');
    stage2_assert($after['invoice']['partial_return_count'] - $before['invoice']['partial_return_count'] === 1, 'Partial Invoice return counted');
    stage2_assert($after['invoice']['full_return_count'] - $before['invoice']['full_return_count'] === 1, 'Full Invoice return counted');
    stage2_assert($after['invoice']['returned_qty'] - $before['invoice']['returned_qty'] === 7.0, 'Invoice returned quantity aggregated');
    stage2_assert($after['invoice']['resale_qty'] - $before['invoice']['resale_qty'] === 1.0, 'Invoice resale quantity excludes non-resale returns');

    stage2_assert(stage2_delta($after, $before, 'gross_sales') === 2250.0, 'Combined gross counts each POS and Invoice sale once');
    stage2_assert(stage2_delta($after, $before, 'accepted_returns') === 1050.0, 'Combined accepted returns use original sale prices, not catalogue price 1500');
    stage2_assert(stage2_delta($after, $before, 'net_sales') === 1200.0, 'Order-linked Invoice, confirmed Lead, and unfulfilled Order do not add revenue');


    $shops = report_sales_by_shop('2026-01-01', '2026-01-31');
    $shopMap = [];
    foreach ($shops as $shop) $shopMap[$shop['shop_name']] = $shop;
    stage2_assert(isset($shopMap['kariakoo']) && (float)$shopMap['kariakoo']['pos_gross_sales'] >= 500.0, 'Authorized Shop receives POS sales attribution');
    stage2_assert(isset($shopMap['kariakoo']) && (float)$shopMap['kariakoo']['invoice_gross_sales'] >= 1500.0, 'Authorized Shop receives Invoice sales attribution');
    stage2_assert(isset($shopMap['Unattributed (historical)']), 'Administrator sees historical NULL sales as explicitly unattributed');
    stage2_assert(!isset($shopMap['karikoo store']) && !isset($shopMap['Makongo']), 'Store and Godown do not appear as sales locations');
    stage2_assert(isset($shopMap['Unattributed (historical)']) && (float)$shopMap['Unattributed (historical)']['net_sales'] >= 200.0, 'Unattributed historical Invoice value is preserved without invented Shop');

    $products = report_sales_by_product('2026-01-01', '2026-01-31');
    $product = null;
    foreach ($products as $row) if ((int)$row['product_id'] === $productId) { $product = $row; break; }
    stage2_assert($product !== null, 'Product sales aggregation returns the fixture product');
    stage2_assert($product && $product['sold_qty'] - 0 >= 23.0, 'Product sold quantity includes POS and Invoice lines once');
    stage2_assert($product && $product['returned_qty'] >= 11.0, 'Product returned quantity aggregates multiple returns across channels');
    stage2_assert($product && $product['resale_qty'] >= 3.0, 'Product resale quantity remains separate from returned quantity');
    stage2_assert($product && $product['net_qty'] >= 12.0, 'Net product quantity subtracts returned, not resale, quantity');
    stage2_assert($product && $product['gross_sales'] >= 2250.0 && $product['accepted_returns'] >= 1050.0 && $product['net_sales'] >= 1200.0, 'Product gross, return, and net values use original sale prices');

    $posRows = report_pos_sale_rows('2026-01-01', '2026-01-31');
    $posRow = null;
    foreach ($posRows as $row) if ((int)$row['order_id'] === $posA) { $posRow = $row; break; }
    stage2_assert($posRow && (float)$posRow['accepted_returns'] === 300.0 && (float)$posRow['net_sales'] === 200.0, 'POS transaction row calculates multiple returns without duplication');
    stage2_assert($posRow && (float)$posRow['returned_qty'] === 3.0 && (float)$posRow['resale_qty'] === 2.0, 'POS transaction row preserves returned versus resale quantities');
    $invoiceRows = report_invoice_sale_rows('2026-01-01', '2026-01-31');
    $invoiceRowA = $invoiceRowB = null;
    foreach ($invoiceRows as $row) {
        if ((int)$row['invoice_id'] === $invoiceA) $invoiceRowA = $row;
        if ((int)$row['invoice_id'] === $invoiceB) $invoiceRowB = $row;
    }
    stage2_assert($invoiceRowA && (float)$invoiceRowA['net_sales'] === 800.0 && $invoiceRowA['return_state'] === 'Partially returned', 'Invoice transaction row reports partial return and net value');
    stage2_assert($invoiceRowB && (float)$invoiceRowB['net_sales'] === 0.0 && $invoiceRowB['return_state'] === 'Fully returned', 'Fully returned Invoice has zero net sales');
    $statusRows = report_invoice_status_summary('2026-01-01', '2026-01-31');
    stage2_assert(is_array($statusRows) && count($statusRows) > 0, 'Invoice status summary executes with return and outstanding fields');

    $current_user = User::getUserById(20);
    $shopSummary = report_sales_summary('2026-01-01', '2026-01-31');
    stage2_assert($shopSummary['pos']['gross_sales'] - $beforeShop['pos']['gross_sales'] === 500.0, 'Shop-scoped user sees Shop POS sales but not historical NULL POS sales');
    stage2_assert($shopSummary['invoice']['gross_sales'] - $beforeShop['invoice']['gross_sales'] === 1500.0, 'Shop-scoped user sees Shop Invoice sales but not historical NULL Invoice sales');
    stage2_assert($shopSummary['pos']['accepted_returns'] - $beforeShop['pos']['accepted_returns'] === 300.0, 'Shop-scoped returns remain attributed to original selling Shop');
    $current_user = User::getUserById(1);

    $returnOnly = report_pos_sales_summary('2026-01-20', '2026-01-31');
    stage2_assert($returnOnly['gross_sales'] - $beforeReturnOnly['gross_sales'] === 0.0 && $returnOnly['accepted_returns'] - $beforeReturnOnly['accepted_returns'] === 350.0, 'Return-date filtering is independent of original sale-date filtering');

    $conn->rollback();
    echo "RESULT: $passes passed, $failures failed; fixtures rolled back\n";
    exit($failures > 0 ? 1 : 0);
} catch (Throwable $e) {
    $conn->rollback();
    echo 'ERROR: ' . $e->getMessage() . "\n";
    exit(1);
}

