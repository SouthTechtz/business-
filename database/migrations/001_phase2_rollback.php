<?php
/**
 * DEV-ONLY rollback for 001_phase2_shops_stores.php.
 *
 * This removes the Phase 2 schema additions:
 *   - shops and user_shops tables
 *   - store columns on warehouses
 *   - warehouse_id provenance columns on sales/invoices
 *   - the manage_shops permission
 *
 * IT DOES NOT restore or repair business data. It is intended for a disposable
 * development database after a verified backup. Existing warehouses are kept,
 * but their shop/code/active values are removed. Run only on localhost unless
 * PHASE2_ROLLBACK_ALLOW_REMOTE=1 is explicitly set.
 *
 * Usage:
 *   php database/migrations/001_phase2_rollback.php --confirm=ROLLBACK_PHASE2
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("This rollback can only be run from the command line.\n");
}

if (($argv[1] ?? '') !== '--confirm=ROLLBACK_PHASE2') {
    fwrite(STDERR, "Refusing to run. Pass --confirm=ROLLBACK_PHASE2 after taking a verified backup.\n");
    exit(1);
}

require_once __DIR__ . '/../../config.php';
while (ob_get_level() > 0) {
    ob_end_clean();
}

$allowedHosts = ['127.0.0.1', 'localhost', '::1'];
if (!in_array(DB_HOST, $allowedHosts, true) && getenv('PHASE2_ROLLBACK_ALLOW_REMOTE') !== '1') {
    fwrite(STDERR, "Refusing to run against non-local DB_HOST. Set PHASE2_ROLLBACK_ALLOW_REMOTE=1 only on a disposable database.\n");
    exit(1);
}

function rollback_fail(string $message): void
{
    throw new RuntimeException($message);
}

function rollback_exec(string $sql, string $types = '', ...$params): void
{
    global $conn;
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        rollback_fail($conn->error . ' | ' . $sql);
    }
    if ($types !== '') {
        $stmt->bind_param($types, ...$params);
    }
    if (!$stmt->execute()) {
        rollback_fail($stmt->error . ' | ' . $sql);
    }
    $stmt->close();
}

function rollback_has_table(string $table): bool
{
    global $conn;
    $stmt = $conn->prepare(
        "SELECT COUNT(*) c FROM information_schema.TABLES
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?"
    );
    $stmt->bind_param('s', $table);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return (int)($row['c'] ?? 0) > 0;
}

function rollback_drop_fk(string $table, string $constraint): void
{
    global $conn;
    if (!rollback_has_table($table)) return;
    $stmt = $conn->prepare(
        "SELECT COUNT(*) c FROM information_schema.TABLE_CONSTRAINTS
         WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = ? AND CONSTRAINT_NAME = ?
           AND CONSTRAINT_TYPE = 'FOREIGN KEY'"
    );
    $stmt->bind_param('ss', $table, $constraint);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if ((int)($row['c'] ?? 0) > 0) {
        rollback_exec("ALTER TABLE `{$table}` DROP FOREIGN KEY `{$constraint}`");
        echo "  - FK {$table}.{$constraint}\n";
    }
}

function rollback_drop_index(string $table, string $index): void
{
    global $conn;
    if (!rollback_has_table($table)) return;
    $stmt = $conn->prepare(
        "SELECT COUNT(*) c FROM information_schema.STATISTICS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?"
    );
    $stmt->bind_param('ss', $table, $index);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if ((int)($row['c'] ?? 0) > 0) {
        rollback_exec("ALTER TABLE `{$table}` DROP INDEX `{$index}`");
        echo "  - index {$table}.{$index}\n";
    }
}

function rollback_drop_column(string $table, string $column): void
{
    global $conn;
    if (!rollback_has_table($table)) return;
    $stmt = $conn->prepare(
        "SELECT COUNT(*) c FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?"
    );
    $stmt->bind_param('ss', $table, $column);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if ((int)($row['c'] ?? 0) > 0) {
        rollback_exec("ALTER TABLE `{$table}` DROP COLUMN `{$column}`");
        echo "  - column {$table}.{$column}\n";
    }
}

echo "WARNING: this removes Phase 2 schema and access mappings. Business data is not restored.\n";
echo "Database: " . DB_NAME . " on " . DB_HOST . "\n\n";

try {
    echo "1) Drop Phase 2 foreign keys\n";
    rollback_drop_fk('warehouses', 'fk_wh_shop');
    rollback_drop_fk('pos_orders', 'fk_orders_wh');
    rollback_drop_fk('pos_order_items', 'fk_poi_wh');
    rollback_drop_fk('invoices', 'fk_invoices_wh');
    rollback_drop_fk('invoice_items', 'fk_ii_wh');
    rollback_drop_fk('pos_sessions', 'fk_ps_wh');

    echo "\n2) Drop Phase 2 indexes\n";
    rollback_drop_index('warehouses', 'uq_wh_code');
    rollback_drop_index('warehouses', 'fk_wh_shop');
    rollback_drop_index('warehouses', 'idx_wh_active');
    rollback_drop_index('pos_orders', 'fk_orders_wh');
    rollback_drop_index('pos_orders', 'idx_orders_wh_date');
    rollback_drop_index('pos_order_items', 'fk_poi_wh');
    rollback_drop_index('invoices', 'fk_invoices_wh');
    rollback_drop_index('invoices', 'idx_invoices_wh_date');
    rollback_drop_index('invoice_items', 'fk_ii_wh');
    rollback_drop_index('pos_sessions', 'fk_ps_wh');

    echo "\n3) Drop Phase 2 columns\n";
    rollback_drop_column('pos_orders', 'warehouse_id');
    rollback_drop_column('pos_order_items', 'warehouse_id');
    rollback_drop_column('invoices', 'warehouse_id');
    rollback_drop_column('invoice_items', 'warehouse_id');
    rollback_drop_column('warehouses', 'shop_id');
    rollback_drop_column('warehouses', 'code');
    rollback_drop_column('warehouses', 'is_active');

    echo "\n4) Drop access tables\n";
    rollback_exec("DROP TABLE IF EXISTS `user_shops`");
    rollback_exec("DROP TABLE IF EXISTS `shops`");
    echo "  - shops, user_shops\n";

    echo "\n5) Remove manage_shops permission\n";
    rollback_exec(
        "DELETE rp FROM role_permissions rp
         JOIN permissions p ON p.permission_id = rp.permission_id
         WHERE p.name = 'manage_shops'"
    );
    rollback_exec("DELETE FROM permissions WHERE name = 'manage_shops'");
    echo "  - manage_shops\n";

    echo "\nRollback complete. Restore a verified backup if business data was present.\n";
} catch (Throwable $e) {
    fwrite(STDERR, "ROLLBACK FAILED: " . $e->getMessage() . "\n");
    exit(1);
}
