<?php
/**
 * Migration 011: Product brand and "Date Added" field.
 *
 * SAFE / ADDITIVE:
 *   - Adds `products.brand` (nullable) for the product brand select.
 *   - Renames `products.manufactured_date` to `products.date_added`, keeping
 *     any existing dates. If the old column is missing, adds `date_added`.
 *   - Leaves `expires_date` in place so legacy values are not destroyed; the
 *     product forms no longer edit it.
 *   - No stock, sales, invoices or product prices are changed.
 *
 * Run from the command line only:
 *   php database/migrations/011_product_brand_date_added.php
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("This migration can only be run from the command line.\n");
}

require_once __DIR__ . '/../../config.php';
while (ob_get_level() > 0) {
    ob_end_clean();
}

if (!in_array(DB_HOST, ['127.0.0.1', 'localhost', '::1'], true)) {
    fwrite(STDERR, "Refusing to run against a non-local database.\n");
    exit(1);
}

function m11_column_exists(string $table, string $column): bool
{
    global $conn;
    $stmt = $conn->prepare(
        "SELECT COUNT(*) c
           FROM information_schema.COLUMNS
          WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ?"
    );
    $schema = DB_NAME;
    $stmt->bind_param('sss', $schema, $table, $column);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return (int)($row['c'] ?? 0) > 0;
}

echo "Migration 011: Product brand and Date Added\n";
echo "Database: " . DB_NAME . "\n\n";

try {
    if (m11_column_exists('products', 'brand')) {
        echo "  = products.brand already exists\n";
    } else {
        $conn->query("ALTER TABLE products ADD COLUMN brand VARCHAR(100) NULL AFTER sku");
        echo "  + products.brand added\n";
    }

    $has_manufactured = m11_column_exists('products', 'manufactured_date');
    $has_date_added = m11_column_exists('products', 'date_added');

    if ($has_date_added) {
        echo "  = products.date_added already exists\n";
        if ($has_manufactured) {
            echo "  ! products.manufactured_date still exists; it is no longer used by the app\n";
        }
    } elseif ($has_manufactured) {
        $conn->query("ALTER TABLE products CHANGE manufactured_date date_added DATE NULL");
        echo "  ~ products.manufactured_date renamed to products.date_added (dates kept)\n";
    } else {
        $conn->query("ALTER TABLE products ADD COLUMN date_added DATE NULL AFTER min_stock_level");
        echo "  + products.date_added added\n";
    }

    $stmt = $conn->prepare("INSERT IGNORE INTO schema_migrations (migration) VALUES (?)");
    $migration = '011_product_brand_date_added';
    $stmt->bind_param('s', $migration);
    $stmt->execute();
    $stmt->close();
    echo "\n  = 011_product_brand_date_added recorded\n";
    echo "\nMigration 011 completed successfully. No stock or sales data was changed.\n";
} catch (Throwable $ex) {
    fwrite(STDERR, "\nMigration 011 FAILED: " . $ex->getMessage() . "\n");
    exit(1);
}
