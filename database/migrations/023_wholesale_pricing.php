<?php
/**
 * Migration 023: Wholesale product pricing.
 *
 * Adds an optional wholesale price and the minimum order quantity that
 * qualifies for it. Products without these values keep using sale_price.
 *
 * Run from the command line only:
 *   php database/migrations/023_wholesale_pricing.php
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

function m23_column_exists(string $table, string $column): bool
{
    return (bool)fetch_one(
        "SELECT 1 FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1",
        $table,
        $column
    );
}

echo "Migration 023: Wholesale product pricing\n";
echo "Database: " . DB_NAME . "\n\n";

try {
    if (!m23_column_exists('products', 'wholesale_price')) {
        $conn->query("ALTER TABLE `products` ADD COLUMN `wholesale_price` decimal(12,2) DEFAULT NULL AFTER `sale_price`");
        echo "  + products.wholesale_price\n";
    }
    if (!m23_column_exists('products', 'wholesale_min_qty')) {
        $conn->query("ALTER TABLE `products` ADD COLUMN `wholesale_min_qty` decimal(12,2) DEFAULT NULL AFTER `wholesale_price`");
        echo "  + products.wholesale_min_qty\n";
    }
    echo "\nMigration 023 completed.\n";
} catch (Throwable $exception) {
    fwrite(STDERR, "Migration failed: " . $exception->getMessage() . "\n");
    exit(1);
}
