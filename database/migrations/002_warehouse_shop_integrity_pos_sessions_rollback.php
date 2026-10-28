<?php
/**
 * Rollback for migration 002.
 *
 * DESTRUCTIVE / EXPLICIT CONFIRMATION REQUIRED.
 *
 * This only reverses the structural changes made by migration 002. It does NOT:
 *   - delete shops, warehouses, stock, sales, invoices, or movements;
 *   - restore historical warehouse links that migration 002 corrected;
 *   - remove fallback shops created by migration 002.
 *
 * Run from the command line only:
 *   php database/migrations/002_warehouse_shop_integrity_pos_sessions_rollback.php --confirm=ROLLBACK_PHASE2_INTEGRITY
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("This rollback can only be run from the command line.\n");
}

$confirm = null;
foreach ($argv as $arg) {
    if (strpos($arg, '--confirm=') === 0) {
        $confirm = substr($arg, strlen('--confirm='));
    }
}
if ($confirm !== 'ROLLBACK_PHASE2_INTEGRITY') {
    fwrite(STDERR, "Refusing to roll back. Pass --confirm=ROLLBACK_PHASE2_INTEGRITY\n");
    exit(1);
}

require_once __DIR__ . '/../../config.php';

while (ob_get_level() > 0) {
    ob_end_clean();
}

function m2r_query(string $sql): void
{
    global $conn;
    if ($conn->query($sql) !== true) {
        throw new RuntimeException($conn->error . ' | SQL: ' . $sql);
    }
}

function m2r_index_exists(string $table, string $index): bool
{
    return (bool)fetch_one(
        "SELECT 1 FROM information_schema.STATISTICS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?",
        $table,
        $index
    );
}

function m2r_fk_exists(string $table, string $constraint): bool
{
    return (bool)fetch_one(
        "SELECT 1 FROM information_schema.TABLE_CONSTRAINTS
         WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = ?
           AND CONSTRAINT_NAME = ? AND CONSTRAINT_TYPE = 'FOREIGN KEY'",
        $table,
        $constraint
    );
}

echo "Rollback 002: warehouse/shop integrity and POS session index\n";
echo "Database: " . DB_NAME . "\n\n";
echo "WARNING: this reverses schema constraints only. Business data is preserved.\n\n";

try {
    echo "1) Restore old warehouse shop foreign key\n";
    if (m2r_fk_exists('warehouses', 'fk_wh_shop_tenant')) {
        m2r_query("ALTER TABLE `warehouses` DROP FOREIGN KEY `fk_wh_shop_tenant`");
        echo "  - dropped composite FK\n";
    }

    echo "\n2) Make shop_id nullable again\n";
    m2r_query("ALTER TABLE `warehouses` MODIFY `shop_id` int(10) unsigned NULL");
    echo "  ~ warehouses.shop_id is nullable\n";

    if (!m2r_index_exists('warehouses', 'fk_wh_shop')) {
        m2r_query("ALTER TABLE `warehouses` ADD KEY `fk_wh_shop` (`shop_id`)");
        echo "  + index warehouses(shop_id)\n";
    }

    if (!m2r_fk_exists('warehouses', 'fk_wh_shop')) {
        m2r_query(
            "ALTER TABLE `warehouses`
             ADD CONSTRAINT `fk_wh_shop`
             FOREIGN KEY (`shop_id`) REFERENCES `shops` (`shop_id`) ON DELETE SET NULL"
        );
        echo "  + old FK warehouses.shop_id -> shops.shop_id (ON DELETE SET NULL)\n";
    }

    echo "\n3) Remove migration-002 indexes/keys\n";
    if (m2r_index_exists('warehouses', 'idx_wh_shop_tenant')) {
        m2r_query("ALTER TABLE `warehouses` DROP INDEX `idx_wh_shop_tenant`");
        echo "  - index warehouses(shop_id, tenant_id)\n";
    }
    if (m2r_index_exists('shops', 'uq_shops_shop_tenant')) {
        m2r_query("ALTER TABLE `shops` DROP INDEX `uq_shops_shop_tenant`");
        echo "  - unique shops(shop_id, tenant_id)\n";
    }
    if (m2r_index_exists('pos_sessions', 'idx_ps_user_open')) {
        m2r_query("ALTER TABLE `pos_sessions` DROP INDEX `idx_ps_user_open`");
        echo "  - index pos_sessions(tenant_id, user_id, is_closed)\n";
    }

    echo "\n4) Remove migration status row\n";
    $stmt = $conn->prepare("DELETE FROM schema_migrations WHERE migration = ?");
    if ($stmt) {
        $name = '002_warehouse_shop_integrity_pos_sessions';
        $stmt->bind_param('s', $name);
        $stmt->execute();
        $stmt->close();
        echo "  - 002 status row\n";
    }

    echo "\nRollback 002 completed. Migration 001 remains applied.\n";
} catch (Throwable $ex) {
    fwrite(STDERR, "\nRollback 002 FAILED: " . $ex->getMessage() . "\n");
    exit(1);
}
