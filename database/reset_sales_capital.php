<?php
/**
 * Reset sales and capital data for integrity testing.
 *
 * DATA RESET (not a schema migration). Keeps master data (tenants, users,
 * products, shops, suppliers, customers, leads, orders, purchase records and
 * stock receipts) and clears the money/sales activity:
 *
 *   - Deletes POS orders (items/payments), POS-generated invoices
 *     (items/payments) and customer sales returns (POS / invoice / order).
 *   - Clears storefront order amounts (Amount_paid, returned_amount).
 *   - Deletes capital movements, expenses, bank sales deposits and internal
 *     transfers, and zeroes the amount paid on purchase records.
 *   - Deletes sales-related inventory movements and stock reservations.
 *   - Resets the invoice counter when no invoices remain.
 *   - Sets one shop's stock to a fixed quantity for every sellable product of
 *     the shop's brand and every product that already has a stock row there.
 *
 * CLI only, local database only, and requires --force.
 *
 *   php database/reset_sales_capital.php --force
 *   php database/reset_sales_capital.php --force --shop=11 --qty=10 --tenant=1
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("This reset can only be run from the command line.\n");
}

require_once __DIR__ . '/../config.php';
while (ob_get_level() > 0) {
    ob_end_clean();
}

if (!in_array(DB_HOST, ['127.0.0.1', 'localhost', '::1'], true)) {
    fwrite(STDERR, "Refusing to run against a non-local database.\n");
    exit(1);
}

$force = in_array('--force', $argv ?? [], true);
$options = ['tenant' => 1, 'shop' => 11, 'qty' => 10.0];
foreach (array_slice($argv ?? [], 1) as $arg) {
    if (preg_match('/^--(tenant|shop|qty)=(.+)$/', $arg, $m)) {
        $options[$m[1]] = $m[1] === 'qty' ? (float)$m[2] : (int)$m[2];
    }
}
$tenant_id = (int)$options['tenant'];
$shop_id = (int)$options['shop'];
$target_qty = round((float)$options['qty'], 2);

if (!$force) {
    echo "Refusing to reset without --force.\n";
    echo "This deletes sales, capital, expenses and sales-related stock movements.\n";
    echo "Take a backup first, then run:\n";
    echo "  php database/reset_sales_capital.php --force --shop={$shop_id} --qty={$target_qty} --tenant={$tenant_id}\n";
    exit(1);
}

$shop = fetch_one(
    "SELECT shop_id, name, brand FROM shops WHERE shop_id = ? AND tenant_id = ?",
    $shop_id,
    $tenant_id
);
if (!$shop) {
    fwrite(STDERR, "Shop #{$shop_id} not found for tenant #{$tenant_id}.\n");
    exit(1);
}
$warehouse = fetch_one(
    "SELECT warehouse_id, name FROM warehouses
      WHERE tenant_id = ? AND shop_id = ? AND location_type = 'shop' AND is_active = 1
      ORDER BY warehouse_id LIMIT 1",
    $tenant_id,
    $shop_id
);
if (!$warehouse) {
    fwrite(STDERR, "Shop #{$shop_id} has no active shop inventory warehouse.\n");
    exit(1);
}

echo "Reset sales and capital data\n";
echo "Database: " . DB_NAME . " | Tenant: {$tenant_id}\n";
echo "Test shop: {$shop['name']} (#{$shop_id}) | Warehouse: {$warehouse['name']} (#{$warehouse['warehouse_id']})\n";
echo "Target stock per product: {$target_qty}\n\n";

function rsc_scalar(string $sql, ...$params)
{
    $row = fetch_one($sql, ...$params) ?: [];
    return reset($row);
}
$conn->begin_transaction();
try {
    $deleted = [];

    $conn->query("DELETE FROM sales_returns WHERE tenant_id = {$tenant_id} AND source_type IN ('pos','invoice','order')");
    $deleted['customer sales returns'] = $conn->affected_rows;

    $conn->query(
        "DELETE FROM invoices
          WHERE tenant_id = {$tenant_id}
            AND (reference LIKE 'POS-%'
                 OR reference IN (SELECT order_number FROM pos_orders WHERE tenant_id = {$tenant_id}))"
    );
    $deleted['POS invoices'] = $conn->affected_rows;

    $conn->query("DELETE FROM pos_orders WHERE tenant_id = {$tenant_id}");
    $deleted['POS orders'] = $conn->affected_rows;

    $conn->query("DELETE FROM order_stock_reservations WHERE tenant_id = {$tenant_id}");
    $deleted['stock reservations'] = $conn->affected_rows;

    $deleted['POS sessions'] = 0;
    if (db_table_exists('pos_sessions')) {
        $conn->query("DELETE FROM pos_sessions WHERE tenant_id = {$tenant_id}");
        $deleted['POS sessions'] = $conn->affected_rows;
    }

    $conn->query("UPDATE orders SET Amount_paid = 0, returned_amount = 0 WHERE tenant_id = {$tenant_id}");
    $deleted['storefront order amounts zeroed'] = $conn->affected_rows;

    $conn->query("DELETE FROM capital_movements WHERE tenant_id = {$tenant_id}");
    $deleted['capital movements'] = $conn->affected_rows;

    if (db_table_exists('bank_sales_deposits')) {
        $conn->query("DELETE FROM bank_sales_deposits WHERE tenant_id = {$tenant_id}");
    }
    if (db_table_exists('brand_internal_transfers')) {
        $conn->query("DELETE FROM brand_internal_transfers WHERE tenant_id = {$tenant_id}");
    }

    $conn->query("DELETE FROM expenses WHERE tenant_id = {$tenant_id}");
    $deleted['expenses'] = $conn->affected_rows;

    $conn->query("UPDATE purchases SET amount_paid = 0 WHERE tenant_id = {$tenant_id}");
    $deleted['purchase payments zeroed'] = $conn->affected_rows;

    $conn->query(
        "DELETE FROM inventory_movements
          WHERE tenant_id = {$tenant_id}
            AND (movement_type IN ('sale','sale_cancel','sale_delete','invoice_cancel','order_transport')
                 OR reference_type = 'pos_order')"
    );
    $deleted['sales stock movements'] = $conn->affected_rows;

    $invoices_left = (int)rsc_scalar("SELECT COUNT(*) FROM invoices WHERE tenant_id = ?", $tenant_id);
    $invoice_counter_reset = false;
    if ($invoices_left === 0) {
        $conn->query("UPDATE system_settings SET invoice_next_number = 1001 WHERE tenant_id = {$tenant_id}");
        $invoice_counter_reset = true;
    }

    // Stock for the test shop: every active product of the shop's brand plus
    // every product that already has a stock row at the shop warehouse.
    $products = fetch_all(
        "SELECT p.product_id, p.name, COALESCE(p.min_stock_level, 0) min_stock_level
           FROM products p
          WHERE p.tenant_id = ? AND p.is_active = 1
            AND (
                (p.brand = ? AND ? <> '')
                OR EXISTS (SELECT 1 FROM inventory_stock s
                            WHERE s.tenant_id = p.tenant_id AND s.product_id = p.product_id
                              AND s.warehouse_id = ?)
            )
          ORDER BY p.product_id",
        $tenant_id,
        (string)$shop['brand'],
        (string)$shop['brand'],
        (int)$warehouse['warehouse_id']
    );

    $stock_changes = [];
    $movement_count = 0;
    $warehouse_id = (int)$warehouse['warehouse_id'];
    foreach ($products as $product) {
        $product_id = (int)$product['product_id'];
        $row = fetch_one(
            "SELECT stock_id, quantity FROM inventory_stock
              WHERE tenant_id = ? AND product_id = ? AND warehouse_id = ?",
            $tenant_id,
            $product_id,
            $warehouse_id
        );
        $current = $row ? (float)$row['quantity'] : 0.0;
        $delta = round($target_qty - $current, 2);

        if ($row) {
            $stmt = $conn->prepare(
                "UPDATE inventory_stock SET quantity = ?, last_counted_at = CURRENT_TIMESTAMP
                  WHERE stock_id = ? AND tenant_id = ?"
            );
            $stock_id = (int)$row['stock_id'];
            $stmt->bind_param('dii', $target_qty, $stock_id, $tenant_id);
            $stmt->execute();
            $stmt->close();
        } else {
            $stmt = $conn->prepare(
                "INSERT INTO inventory_stock (tenant_id, product_id, warehouse_id, quantity, reorder_level)
                 VALUES (?, ?, ?, ?, ?)"
            );
            $reorder = (float)$product['min_stock_level'];
            $stmt->bind_param('iiidd', $tenant_id, $product_id, $warehouse_id, $target_qty, $reorder);
            $stmt->execute();
            $stmt->close();
        }

        if (abs($delta) > 0.0001) {
            $movement_type = 'adjustment';
            $notes = 'Sales/stock integrity reset: ' . format_quantity($current) . ' to ' . format_quantity($target_qty);
            $stmt = $conn->prepare(
                "INSERT INTO inventory_movements
                    (tenant_id, product_id, warehouse_id, movement_type, quantity, created_by, notes)
                 VALUES (?, ?, ?, ?, ?, NULL, ?)"
            );
            $stmt->bind_param('iiisds', $tenant_id, $product_id, $warehouse_id, $movement_type, $delta, $notes);
            $stmt->execute();
            $stmt->close();
            $movement_count++;
        }
        $stock_changes[] = [
            'product_id' => $product_id,
            'name' => (string)$product['name'],
            'from' => $current,
            'to' => $target_qty,
        ];
    }

    $conn->commit();

    echo "Deleted / reset:\n";
    foreach ($deleted as $label => $count) {
        echo "  - {$label}: {$count}\n";
    }
    echo "  - invoice counter reset to 1001: " . ($invoice_counter_reset ? 'yes' : 'no (invoices remain)') . "\n";
    echo "\nStock at {$warehouse['name']}:\n";
    foreach ($stock_changes as $change) {
        echo "  - #{$change['product_id']} {$change['name']}: {$change['from']} -> {$change['to']}\n";
    }
    echo "  ({$movement_count} adjustment movement(s) recorded)\n";

    $bank = (float)rsc_scalar(
        "SELECT COALESCE(SUM(CASE WHEN movement_type = 'contribution' THEN amount ELSE -amount END), 0)
           FROM capital_movements WHERE tenant_id = ?",
        $tenant_id
    );
    $stock_value = (float)rsc_scalar(
        "SELECT COALESCE(SUM(s.quantity * p.purchase_price), 0)
           FROM inventory_stock s JOIN products p ON p.product_id = s.product_id AND p.tenant_id = s.tenant_id
          WHERE s.tenant_id = ?",
        $tenant_id
    );
    echo "\nCapital after reset (no expenses/purchases/sales):\n";
    echo "  Capital in Bank: " . number_format($bank, 2, '.', '') . "\n";
    echo "  Stock-Held Capital: " . number_format($stock_value, 2, '.', '') . "\n";
    echo "\nReset completed.\n";
} catch (Throwable $ex) {
    $conn->rollback();
    fwrite(STDERR, "\nReset FAILED: " . $ex->getMessage() . "\n");
    exit(1);
}

    $invoice_counter_reset = false;
    if ($invoices_left === 0) {
        $conn->query("UPDATE system_settings SET invoice_next_number = 1001 WHERE tenant_id = {$tenant_id}");
        $invoice_counter_reset = true;
    }

