<?php
/**
 * DEV-ONLY rollback for 007_godown_transfers.php.
 *
 * This removes the central-Godown metadata and transfer/receipt ledgers. It
 * NEVER reverses or recalculates inventory_stock. Any physical stock already
 * received, dispatched, or accepted remains where it is.
 *
 * Because dropping the ledgers permanently loses audit history, the rollback
 * refuses while transfer or receipt rows exist unless the data-loss flag is
 * supplied:
 *
 *   php database/migrations/007_godown_transfers_rollback.php --confirm=ROLLBACK_GODOWN
 *   php database/migrations/007_godown_transfers_rollback.php --confirm=ROLLBACK_GODOWN --allow-data-loss=ROLLBACK_GODOWN_DATA
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("This rollback can only be run from the command line.\n");
}

if (($argv[1] ?? '') !== '--confirm=ROLLBACK_GODOWN') {
    fwrite(STDERR, "Refusing to run. Pass --confirm=ROLLBACK_GODOWN.\n");
    exit(1);
}
$allow_data_loss = (($argv[2] ?? '') === '--allow-data-loss=ROLLBACK_GODOWN_DATA');

require_once __DIR__ . '/../../config.php';
while (ob_get_level() > 0) {
    ob_end_clean();
}

if (!in_array(DB_HOST, ['127.0.0.1', 'localhost', '::1'], true)) {
    fwrite(STDERR, "Refusing to run against a non-local database.\n");
    exit(1);
}

function m7r_query(string $sql): void
{
    global $conn;
    if ($conn->query($sql) !== true) {
        throw new RuntimeException($conn->error . ' | SQL: ' . $sql);
    }
}

function m7r_exec(string $sql, ...$params): void
{
    global $conn;
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        throw new RuntimeException($conn->error . ' | SQL: ' . $sql);
    }
    if ($params) {
        $types = '';
        foreach ($params as $param) {
            $types .= is_int($param) ? 'i' : (is_float($param) ? 'd' : 's');
        }
        $stmt->bind_param($types, ...$params);
    }
    if (!$stmt->execute()) {
        $error = $stmt->error;
        $stmt->close();
        throw new RuntimeException($error . ' | SQL: ' . $sql);
    }
    $stmt->close();
}

function m7r_table_exists(string $table): bool
{
    return (bool)fetch_one(
        "SELECT 1 FROM information_schema.TABLES
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? LIMIT 1",
        $table
    );
}

function m7r_index_exists(string $table, string $index): bool
{
    return (bool)fetch_one(
        "SELECT 1 FROM information_schema.STATISTICS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ? LIMIT 1",
        $table,
        $index
    );
}

function m7r_column_exists(string $table, string $column): bool
{
    return (bool)fetch_one(
        "SELECT 1 FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1",
        $table,
        $column
    );
}

echo "Rollback 007: Central Godown and stock transfers\n";
echo "Database: " . DB_NAME . "\n\n";

try {
    $transfer_count = m7r_table_exists('stock_transfers')
        ? (int)(fetch_one("SELECT COUNT(*) c FROM stock_transfers")['c'] ?? 0)
        : 0;
    $receipt_count = m7r_table_exists('godown_receipts')
        ? (int)(fetch_one("SELECT COUNT(*) c FROM godown_receipts")['c'] ?? 0)
        : 0;

    if (($transfer_count > 0 || $receipt_count > 0) && !$allow_data_loss) {
        fwrite(STDERR, "Refusing to drop " . $transfer_count . " transfer(s) and " . $receipt_count . " receipt(s).\n");
        fwrite(STDERR, "Re-run with --allow-data-loss=ROLLBACK_GODOWN_DATA if losing that history is intended.\n");
        exit(1);
    }

    if (m7r_table_exists('stock_transfer_items')) {
        m7r_query("DROP TABLE `stock_transfer_items`");
        echo "  - stock_transfer_items\n";
    }
    if (m7r_table_exists('stock_transfers')) {
        m7r_query("DROP TABLE `stock_transfers`");
        echo "  - stock_transfers\n";
    }
    if (m7r_table_exists('godown_receipts')) {
        m7r_query("DROP TABLE `godown_receipts`");
        echo "  - godown_receipts\n";
    }

    if (m7r_index_exists('warehouses', 'uq_wh_active_godown')) {
        m7r_query("ALTER TABLE `warehouses` DROP INDEX `uq_wh_active_godown`");
        echo "  - unique active Godown index\n";
    }
    if (m7r_column_exists('warehouses', 'godown_tenant_key')) {
        m7r_query("ALTER TABLE `warehouses` DROP COLUMN `godown_tenant_key`");
        echo "  - warehouses.godown_tenant_key\n";
    }
    if (m7r_column_exists('warehouses', 'is_godown')) {
        m7r_query("ALTER TABLE `warehouses` DROP COLUMN `is_godown`");
        echo "  - warehouses.is_godown\n";
    }
    if (m7r_index_exists('warehouses', 'uq_wh_warehouse_tenant')) {
        m7r_query("ALTER TABLE `warehouses` DROP INDEX `uq_wh_warehouse_tenant`");
        echo "  - unique warehouses(warehouse_id, tenant_id)\n";
    }

    m7r_exec(
        "DELETE rp FROM role_permissions rp
           JOIN permissions p ON p.permission_id = rp.permission_id
          WHERE p.name IN ('manage_godown', 'approve_godown_transfers', 'dispatch_godown_transfers', 'receive_godown_transfers')"
    );
    m7r_exec(
        "DELETE FROM permissions
          WHERE name IN ('manage_godown', 'approve_godown_transfers', 'dispatch_godown_transfers', 'receive_godown_transfers')"
    );
    echo "  - central-Godown permissions\n";

    m7r_exec("DELETE FROM schema_migrations WHERE migration = '007_godown_transfers'");
    echo "  - schema_migrations entry\n";
    echo "\nRollback 007 completed. Physical stock was not reversed or recalculated.\n";
    echo "Any stock previously received into or transferred out of a Godown remains in inventory_stock.\n";
} catch (Throwable $ex) {
    fwrite(STDERR, "\nRollback 007 FAILED: " . $ex->getMessage() . "\n");
    exit(1);
}
