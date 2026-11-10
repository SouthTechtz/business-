<?php
/**
 * Migration 034: Brand opening balances.
 *
 * SAFE / ADDITIVE:
 *   - Creates `brand_opening_balances` holding an opening sales amount per
 *     tenant brand (GSP / TECHDOKTA). Administrators can edit the amounts.
 *   - Does not change products, stock, orders, invoices or existing sales.
 *
 * Run from the command line only:
 *   php database/migrations/034_brand_opening_balances.php
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

echo "Migration 034: Brand opening balances\n";
echo "Database: " . DB_NAME . "\n\n";

try {
    $conn->query(
        "CREATE TABLE IF NOT EXISTS brand_opening_balances (
            balance_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            tenant_id INT UNSIGNED NOT NULL,
            brand VARCHAR(100) NOT NULL,
            opening_sales DECIMAL(14,2) NOT NULL DEFAULT 0.00,
            notes TEXT NULL,
            updated_by INT UNSIGNED NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (balance_id),
            UNIQUE KEY uq_brand_opening_tenant_brand (tenant_id, brand),
            KEY idx_brand_opening_tenant (tenant_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
    );
    echo "  = brand_opening_balances ready\n";

    $stmt = $conn->prepare("INSERT IGNORE INTO schema_migrations (migration) VALUES (?)");
    $migration = '034_brand_opening_balances';
    $stmt->bind_param('s', $migration);
    $stmt->execute();
    $stmt->close();
    echo "\n  = 034_brand_opening_balances recorded\n";
    echo "\nMigration 034 completed successfully. No stock or sales data was changed.\n";
} catch (Throwable $ex) {
    fwrite(STDERR, "\nMigration 034 FAILED: " . $ex->getMessage() . "\n");
    exit(1);
}
