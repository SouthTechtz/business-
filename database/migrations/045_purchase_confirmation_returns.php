<?php
/**
 * Migration 045: Purchase confirmation and supplier-damage returns.
 *
 * SAFE / ADDITIVE:
 *   - Extends `sales_returns.source_type` with `purchase` and adds
 *     `return_category` so the Sales Returns screen separates customer returns
 *     from damaged/returned goods coming back from a supplier.
 *   - Adds damaged / returned / other quantity and notes to `sales_returns`.
 *   - Adds `returned_quantity` and `other_quantity` to `purchases` and
 *     `godown_receipts`.
 *
 * Existing rows are untouched: every existing return becomes
 * `return_category = customer`.
 *
 * Run from the command line only:
 *   php database/migrations/045_purchase_confirmation_returns.php
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

echo "Migration 045: Purchase confirmation and supplier-damage returns\n";
echo "Database: " . DB_NAME . "\n\n";

function m45_column_exists(mysqli $conn, string $table, string $column): bool
{
    $stmt = $conn->prepare(
        "SELECT COUNT(*) AS c
           FROM information_schema.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE()
            AND TABLE_NAME = ?
            AND COLUMN_NAME = ?"
    );
    if (!$stmt) throw new RuntimeException($conn->error);
    $stmt->bind_param('ss', $table, $column);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return ((int)($row['c'] ?? 0)) > 0;
}

function m45_index_exists(mysqli $conn, string $table, string $index): bool
{
    $stmt = $conn->prepare(
        "SELECT COUNT(*) AS c
           FROM information_schema.STATISTICS
          WHERE TABLE_SCHEMA = DATABASE()
            AND TABLE_NAME = ?
            AND INDEX_NAME = ?"
    );
    if (!$stmt) throw new RuntimeException($conn->error);
    $stmt->bind_param('ss', $table, $index);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return ((int)($row['c'] ?? 0)) > 0;
}

function m45_add_column(mysqli $conn, string $table, string $column, string $definition, string $after): void
{
    if (m45_column_exists($conn, $table, $column)) {
        echo "  = {$table}.{$column} already exists\n";
        return;
    }
    $conn->query("ALTER TABLE `{$table}` ADD COLUMN `{$column}` {$definition} AFTER `{$after}`");
    echo "  + {$table}.{$column} added\n";
}

try {
    // Returns can now come from a supplier delivery as well as a customer sale.
    $conn->query(
        "ALTER TABLE sales_returns
         MODIFY COLUMN source_type ENUM('pos','invoice','order','purchase') NOT NULL DEFAULT 'pos'"
    );
    echo "  = sales_returns.source_type accepts purchase\n";

    m45_add_column($conn, 'sales_returns', 'return_category', "ENUM('customer','supplier_damage') NOT NULL DEFAULT 'customer'", 'source_type');
    m45_add_column($conn, 'sales_returns', 'damaged_quantity', 'DECIMAL(12,2) NOT NULL DEFAULT 0.00', 'refund_bank_name');
    m45_add_column($conn, 'sales_returns', 'returned_quantity', 'DECIMAL(12,2) NOT NULL DEFAULT 0.00', 'damaged_quantity');
    m45_add_column($conn, 'sales_returns', 'other_quantity', 'DECIMAL(12,2) NOT NULL DEFAULT 0.00', 'returned_quantity');
    m45_add_column($conn, 'sales_returns', 'notes', 'TEXT NULL', 'other_quantity');

    if (!m45_index_exists($conn, 'sales_returns', 'idx_sales_returns_category')) {
        $conn->query("ALTER TABLE sales_returns ADD KEY idx_sales_returns_category (tenant_id, return_category)");
        echo "  + sales_returns category index added\n";
    }

    // What arrived, what is being sent back and what is otherwise missing.
    m45_add_column($conn, 'purchases', 'returned_quantity', 'DECIMAL(12,2) NOT NULL DEFAULT 0.00', 'damaged_quantity');
    m45_add_column($conn, 'purchases', 'other_quantity', 'DECIMAL(12,2) NOT NULL DEFAULT 0.00', 'returned_quantity');

    m45_add_column($conn, 'godown_receipts', 'returned_quantity', 'DECIMAL(12,2) NOT NULL DEFAULT 0.00', 'damaged_quantity');
    m45_add_column($conn, 'godown_receipts', 'other_quantity', 'DECIMAL(12,2) NOT NULL DEFAULT 0.00', 'returned_quantity');

    $stmt = $conn->prepare("INSERT IGNORE INTO schema_migrations (migration) VALUES (?)");
    $migration = '045_purchase_confirmation_returns';
    $stmt->bind_param('s', $migration);
    $stmt->execute();
    $stmt->close();

    echo "\n  = 045_purchase_confirmation_returns recorded\n";
    echo "Migration 045 completed successfully. Existing returns are customer returns.\n";
} catch (Throwable $ex) {
    fwrite(STDERR, "\nMigration 045 FAILED: " . $ex->getMessage() . "\n");
    exit(1);
}
