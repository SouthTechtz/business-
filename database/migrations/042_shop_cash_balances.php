<?php
/**
 * Migration 042: Per-shop cash balances.
 *
 * SAFE / ADDITIVE:
 *   - Adds `expenses.shop_id`. A NULL shop means the expense is brand-level
 *     and stays in the brand's unallocated cash.
 *   - Creates `shop_cash_transfers` for cash the system administrator gives to
 *     a shop and cash a shop hands back to the brand when it closes business.
 *
 * Purchases are deliberately excluded from shop cash: a supplier payment is a
 * brand-level outflow and never reduces a shop balance.
 *
 * Existing sales, refunds and expenses are not changed. Because shop balances
 * are derived from those existing rows, all history is included from the first
 * page load after this migration.
 *
 * Run from the command line only:
 *   php database/migrations/042_shop_cash_balances.php
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

echo "Migration 042: Per-shop cash balances\n";
echo "Database: " . DB_NAME . "\n\n";

function m42_column_exists(mysqli $conn, string $table, string $column): bool
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

function m42_index_exists(mysqli $conn, string $table, string $index): bool
{
    $stmt = $conn->prepare(
        "SELECT COUNT(*) AS c
           FROM information_schema.STATISTICS
          WHERE TABLE_SCHEMA = DATABASE()
            AND TABLE_NAME = ?
            AND INDEX_NAME = ?"
    );
    if (!$stmt) {
        throw new RuntimeException($conn->error);
    }
    $stmt->bind_param('ss', $table, $index);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return ((int)($row['c'] ?? 0)) > 0;
}

function m42_add_shop_column(mysqli $conn, string $table, string $after): void
{
    if (m42_column_exists($conn, $table, 'shop_id')) {
        echo "  = {$table}.shop_id already exists\n";
        return;
    }
    $conn->query("ALTER TABLE `{$table}` ADD COLUMN `shop_id` INT UNSIGNED NULL AFTER `{$after}`");
    echo "  + {$table}.shop_id added\n";
    if (!m42_index_exists($conn, $table, "idx_{$table}_shop")) {
        $conn->query("ALTER TABLE `{$table}` ADD KEY `idx_{$table}_shop` (`tenant_id`, `shop_id`)");
        echo "  + {$table} shop index added\n";
    }
}

try {
    // Expenses can now be tied to a shop. Blank means the brand paid it from
    // unallocated cash.
    m42_add_shop_column($conn, 'expenses', 'brand');

    // Cash movements between the brand and a shop.
    $conn->query(
        "CREATE TABLE IF NOT EXISTS shop_cash_transfers (
            transfer_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            tenant_id INT UNSIGNED NOT NULL,
            brand VARCHAR(100) NOT NULL,
            shop_id INT UNSIGNED NOT NULL,
            direction ENUM('brand_to_shop','shop_to_brand') NOT NULL,
            amount DECIMAL(14,2) NOT NULL DEFAULT 0.00,
            entry_date DATE NOT NULL,
            reference VARCHAR(100) NULL,
            notes TEXT NULL,
            created_by INT UNSIGNED NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (transfer_id),
            KEY idx_shop_cash_transfers_brand_date (tenant_id, brand, entry_date),
            KEY idx_shop_cash_transfers_shop_date (tenant_id, shop_id, entry_date),
            KEY idx_shop_cash_transfers_created_by (created_by)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
    echo "  = shop_cash_transfers ready\n";

    $stmt = $conn->prepare("INSERT IGNORE INTO schema_migrations (migration) VALUES (?)");
    $migration = '042_shop_cash_balances';
    $stmt->bind_param('s', $migration);
    $stmt->execute();
    $stmt->close();
    echo "  = 042_shop_cash_balances recorded\n";
    echo "\nMigration 042 completed successfully. Existing records were not changed.\n";
} catch (Throwable $ex) {
    fwrite(STDERR, "\nMigration 042 FAILED: " . $ex->getMessage() . "\n");
    exit(1);
}
