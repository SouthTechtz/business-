<?php
/**
 * Migration 043: Purchase receiving and two-type capital.
 *
 * SAFE / ADDITIVE:
 *   - Adds receiving fields to `purchases` so an arrived purchase can record
 *     the good quantity received and the damaged quantity.
 *   - Adds `purchase_id` and `damaged_quantity` to `godown_receipts` so every
 *     received purchase is linked to its stock receipt.
 *   - Folds any existing `brand_capital_settings.opening_cash` into
 *     `opening_bank` and clears the cash column. Cash in Hand is retired as a
 *     separate capital type; no opening money is lost.
 *
 * Existing purchases, receipts, stock and capital movements are not deleted or
 * recalculated. Purchases already marked arrived_godown keep their stock as it
 * is; only newly received purchases change stock through the receiving screen.
 *
 * Run from the command line only:
 *   php database/migrations/043_purchase_receiving.php
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

echo "Migration 043: Purchase receiving and two-type capital\n";
echo "Database: " . DB_NAME . "\n\n";

function m43_column_exists(mysqli $conn, string $table, string $column): bool
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

function m43_index_exists(mysqli $conn, string $table, string $index): bool
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

function m43_add_column(mysqli $conn, string $table, string $column, string $definition, string $after): void
{
    if (m43_column_exists($conn, $table, $column)) {
        echo "  = {$table}.{$column} already exists\n";
        return;
    }
    $conn->query("ALTER TABLE `{$table}` ADD COLUMN `{$column}` {$definition} AFTER `{$after}`");
    echo "  + {$table}.{$column} added\n";
}

try {
    // Goods actually received and how many of them were damaged.
    m43_add_column($conn, 'purchases', 'received_quantity', 'DECIMAL(12,2) NOT NULL DEFAULT 0.00', 'status');
    m43_add_column($conn, 'purchases', 'damaged_quantity', 'DECIMAL(12,2) NOT NULL DEFAULT 0.00', 'received_quantity');
    m43_add_column($conn, 'purchases', 'received_at', 'DATETIME NULL DEFAULT NULL', 'damaged_quantity');
    m43_add_column($conn, 'purchases', 'received_by', 'INT UNSIGNED NULL DEFAULT NULL', 'received_at');
    m43_add_column($conn, 'purchases', 'received_godown_id', 'INT UNSIGNED NULL DEFAULT NULL', 'received_by');

    if (!m43_index_exists($conn, 'purchases', 'idx_purchases_received_godown')) {
        $conn->query("ALTER TABLE `purchases` ADD KEY `idx_purchases_received_godown` (`tenant_id`, `received_godown_id`)");
        echo "  + purchases received godown index added\n";
    }

    // Link a stock receipt to the purchase it fulfils.
    m43_add_column($conn, 'godown_receipts', 'purchase_id', 'INT UNSIGNED NULL DEFAULT NULL', 'product_id');
    m43_add_column($conn, 'godown_receipts', 'damaged_quantity', 'DECIMAL(12,2) NOT NULL DEFAULT 0.00', 'quantity');

    if (!m43_index_exists($conn, 'godown_receipts', 'idx_godown_receipts_purchase')) {
        $conn->query("ALTER TABLE `godown_receipts` ADD KEY `idx_godown_receipts_purchase` (`tenant_id`, `purchase_id`)");
        echo "  + godown_receipts purchase index added\n";
    }

    // Cash in Hand is retired: fold any opening cash into opening bank capital.
    if (m43_column_exists($conn, 'brand_capital_settings', 'opening_cash')) {
        $conn->query(
            "UPDATE brand_capital_settings
                SET opening_bank = opening_bank + opening_cash,
                    opening_cash = 0.00,
                    updated_at = CURRENT_TIMESTAMP
              WHERE opening_cash <> 0.00"
        );
        $moved = $conn->affected_rows;
        echo "  = opening cash consolidated into opening bank ({$moved} row(s))\n";
    }

    $stmt = $conn->prepare("INSERT IGNORE INTO schema_migrations (migration) VALUES (?)");
    $migration = '043_purchase_receiving';
    $stmt->bind_param('s', $migration);
    $stmt->execute();
    $stmt->close();
    echo "  = 043_purchase_receiving recorded\n";
    echo "\nMigration 043 completed successfully. Existing records were not changed.\n";
} catch (Throwable $ex) {
    fwrite(STDERR, "\nMigration 043 FAILED: " . $ex->getMessage() . "\n");
    exit(1);
}
