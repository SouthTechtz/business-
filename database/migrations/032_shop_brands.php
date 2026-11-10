<?php
/**
 * Migration 032: Shop brand assignment.
 *
 * SAFE / ADDITIVE:
 *   - Adds `shops.brand` (nullable). A shop with a brand sells only products
 *     carrying that brand. A shop with no brand is unrestricted (sells all
 *     brands), which keeps existing behaviour until an administrator assigns
 *     a brand.
 *   - No products, stock, orders, leads or sales are changed.
 *
 * Run from the command line only:
 *   php database/migrations/032_shop_brands.php
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

function m32_column_exists(string $table, string $column): bool
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

echo "Migration 032: Shop brand assignment\n";
echo "Database: " . DB_NAME . "\n\n";

try {
    if (m32_column_exists('shops', 'brand')) {
        echo "  = shops.brand already exists\n";
    } else {
        $conn->query("ALTER TABLE shops ADD COLUMN brand VARCHAR(100) NULL AFTER code");
        echo "  + shops.brand added\n";
    }

    $index = fetch_one(
        "SELECT COUNT(*) c
           FROM information_schema.STATISTICS
          WHERE TABLE_SCHEMA = ? AND TABLE_NAME = 'shops' AND INDEX_NAME = 'idx_shops_brand'",
        DB_NAME
    );
    if ((int)($index['c'] ?? 0) === 0) {
        $conn->query("ALTER TABLE shops ADD INDEX idx_shops_brand (tenant_id, brand)");
        echo "  + shops brand index added\n";
    } else {
        echo "  = shops brand index already exists\n";
    }

    $stmt = $conn->prepare("INSERT IGNORE INTO schema_migrations (migration) VALUES (?)");
    $migration = '032_shop_brands';
    $stmt->bind_param('s', $migration);
    $stmt->execute();
    $stmt->close();
    echo "\n  = 032_shop_brands recorded\n";
    echo "\nMigration 032 completed successfully. No stock or sales data was changed.\n";
} catch (Throwable $ex) {
    fwrite(STDERR, "\nMigration 032 FAILED: " . $ex->getMessage() . "\n");
    exit(1);
}
