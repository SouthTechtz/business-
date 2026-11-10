<?php
/**
 * Migration 039: Dual-tier brand capital (liquid cash/bank + stock).
 *
 * SAFE / ADDITIVE:
 *   - Adds an account type to capital movements and payment methods so money
 *     can be tracked as Cash in Hand or Bank.
 *   - Adds optional payment method / bank fields to purchases, expenses and
 *     sales returns so outflows reduce the correct liquid account.
 *   - Creates brand opening capital settings (cash and bank).
 *   - Creates internal transfers between Cash in Hand and Bank.
 *
 * Existing capital movements are treated as bank movements, which preserves
 * every current balance. No sales, stock or expense records are changed.
 *
 * Run from the command line only:
 *   php database/migrations/039_brand_capital_tiers.php
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

echo "Migration 039: Dual-tier brand capital\n";
echo "Database: " . DB_NAME . "\n\n";

function m39_column_exists(mysqli $conn, string $table, string $column): bool
{
    $stmt = $conn->prepare(
        "SELECT COUNT(*) AS c
           FROM information_schema.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE()
            AND TABLE_NAME = ?
            AND COLUMN_NAME = ?"
    );
    if (!$stmt) {
        throw new RuntimeException($conn->error);
    }
    $stmt->bind_param('ss', $table, $column);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return ((int)($row['c'] ?? 0)) > 0;
}

function m39_add_column(mysqli $conn, string $table, string $column, string $definition): void
{
    if (m39_column_exists($conn, $table, $column)) {
        echo "  = {$table}.{$column} already exists\n";
        return;
    }
    $conn->query("ALTER TABLE `{$table}` ADD COLUMN `{$column}` {$definition}");
    echo "  + {$table}.{$column} added\n";
}

try {
    // Capital movements: cash or bank.
    m39_add_column(
        $conn,
        'capital_movements',
        'account_type',
        "ENUM('bank','cash') NOT NULL DEFAULT 'bank' AFTER `movement_type`"
    );

    // Payment methods: classify each method as cash or bank.
    m39_add_column(
        $conn,
        'payment_methods',
        'account_type',
        "ENUM('cash','bank') NOT NULL DEFAULT 'bank' AFTER `name`"
    );
    $conn->query(
        "UPDATE payment_methods
            SET account_type = 'cash'
          WHERE LOWER(TRIM(name)) IN ('cash', 'cash in hand', 'petty cash')"
    );
    echo "  = payment methods classified (Cash -> cash, others -> bank)\n";

    // Purchases: how the supplier was paid and from which bank.
    m39_add_column(
        $conn,
        'purchases',
        'payment_method_id',
        "INT UNSIGNED NULL AFTER `amount_paid`"
    );
    m39_add_column(
        $conn,
        'purchases',
        'bank_name',
        "VARCHAR(150) NULL AFTER `payment_method_id`"
    );

    // Expenses: which bank paid, when the method is a bank method.
    m39_add_column(
        $conn,
        'expenses',
        'bank_name',
        "VARCHAR(150) NULL AFTER `payment_method_id`"
    );

    // Sales returns: how the refund was paid.
    m39_add_column(
        $conn,
        'sales_returns',
        'refund_method_id',
        "INT UNSIGNED NULL AFTER `refund_amount`"
    );
    m39_add_column(
        $conn,
        'sales_returns',
        'refund_bank_name',
        "VARCHAR(150) NULL AFTER `refund_method_id`"
    );

    // Opening cash / bank per brand.
    $conn->query(
        "CREATE TABLE IF NOT EXISTS brand_capital_settings (
            setting_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            tenant_id INT UNSIGNED NOT NULL,
            brand VARCHAR(100) NOT NULL,
            opening_cash DECIMAL(14,2) NOT NULL DEFAULT 0.00,
            opening_bank DECIMAL(14,2) NOT NULL DEFAULT 0.00,
            notes TEXT NULL,
            updated_by INT UNSIGNED NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (setting_id),
            UNIQUE KEY uniq_brand_capital_settings (tenant_id, brand),
            KEY idx_brand_capital_settings_updated_by (updated_by)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
    echo "  = brand_capital_settings ready\n";

    // Internal transfers between cash and bank.
    $conn->query(
        "CREATE TABLE IF NOT EXISTS brand_internal_transfers (
            transfer_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            tenant_id INT UNSIGNED NOT NULL,
            brand VARCHAR(100) NOT NULL,
            direction ENUM('cash_to_bank','bank_to_cash') NOT NULL,
            bank_name VARCHAR(150) NOT NULL,
            amount DECIMAL(14,2) NOT NULL DEFAULT 0.00,
            entry_date DATE NOT NULL,
            reference VARCHAR(100) NULL,
            notes TEXT NULL,
            created_by INT UNSIGNED NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (transfer_id),
            KEY idx_brand_transfers_tenant_brand_date (tenant_id, brand, entry_date),
            KEY idx_brand_transfers_bank (tenant_id, brand, bank_name),
            KEY idx_brand_transfers_created_by (created_by)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
    echo "  = brand_internal_transfers ready\n";

    $stmt = $conn->prepare("INSERT IGNORE INTO schema_migrations (migration) VALUES (?)");
    $migration = '039_brand_capital_tiers';
    $stmt->bind_param('s', $migration);
    $stmt->execute();
    $stmt->close();
    echo "  = 039_brand_capital_tiers recorded\n";
    echo "\nMigration 039 completed successfully. Existing records were not changed.\n";
} catch (Throwable $ex) {
    fwrite(STDERR, "\nMigration 039 FAILED: " . $ex->getMessage() . "\n");
    exit(1);
}
