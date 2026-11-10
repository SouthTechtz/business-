<?php
/**
 * Migration 038: Bank sales deposits (Close Business).
 *
 * SAFE / ADDITIVE:
 *   - Creates `bank_sales_deposits`. Each record banks a business day's sales
 *     into a chosen bank account for a brand.
 *   - Bank balances become: capital introduced - capital withdrawn + sales banked.
 *   - Does not change sales, capital or stock records.
 *
 * Run from the command line only:
 *   php database/migrations/038_bank_sales_deposits.php
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

echo "Migration 038: Bank sales deposits\n";
echo "Database: " . DB_NAME . "\n\n";

try {
    $conn->query(
        "CREATE TABLE IF NOT EXISTS bank_sales_deposits (
            deposit_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            tenant_id INT UNSIGNED NOT NULL,
            brand VARCHAR(100) NOT NULL,
            bank_name VARCHAR(150) NOT NULL,
            business_date DATE NOT NULL,
            amount DECIMAL(14,2) NOT NULL DEFAULT 0.00,
            sales_total DECIMAL(14,2) NOT NULL DEFAULT 0.00,
            reference VARCHAR(100) NULL,
            notes TEXT NULL,
            created_by INT UNSIGNED NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (deposit_id),
            KEY idx_bank_deposits_tenant_brand_date (tenant_id, brand, business_date),
            KEY idx_bank_deposits_bank (tenant_id, brand, bank_name),
            KEY idx_bank_deposits_created_by (created_by)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
    echo "  = bank_sales_deposits ready\n";

    $stmt = $conn->prepare("INSERT IGNORE INTO schema_migrations (migration) VALUES (?)");
    $migration = '038_bank_sales_deposits';
    $stmt->bind_param('s', $migration);
    $stmt->execute();
    $stmt->close();
    echo "  = 038_bank_sales_deposits recorded\n";
    echo "\nMigration 038 completed successfully. No sales or capital data was changed.\n";
} catch (Throwable $ex) {
    fwrite(STDERR, "\nMigration 038 FAILED: " . $ex->getMessage() . "\n");
    exit(1);
}
