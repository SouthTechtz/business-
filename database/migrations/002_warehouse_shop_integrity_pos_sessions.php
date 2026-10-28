<?php
/**
 * Migration 002: warehouse/shop integrity and POS session lookup support.
 *
 * SAFE / ADDITIVE with one constraint change:
 *   - Backfills any warehouse that still has no valid shop in its own tenant.
 *   - Makes warehouses.shop_id NOT NULL.
 *   - Replaces the single-column shop FK with a composite (shop_id, tenant_id) FK
 *     so a warehouse cannot point at a shop in another tenant.
 *   - Adds an index used to find the signed-in user's open POS session.
 *   - Records applied migrations in schema_migrations.
 *
 * Run from the command line only:
 *   php database/migrations/002_warehouse_shop_integrity_pos_sessions.php
 *
 * The script is idempotent and does not delete business data.
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

function m2_query(string $sql): void
{
    global $conn;
    if ($conn->query($sql) !== true) {
        throw new RuntimeException($conn->error . ' | SQL: ' . $sql);
    }
}

function m2_exec(string $sql, ...$params): void
{
    global $conn;
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        throw new RuntimeException($conn->error . ' | SQL: ' . $sql);
    }
    if ($params) {
        $types = '';
        foreach ($params as $p) {
            $types .= is_int($p) ? 'i' : (is_float($p) ? 'd' : 's');
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

function m2_column_exists(string $table, string $column): bool
{
    return (bool)fetch_one(
        "SELECT 1 FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?",
        $table,
        $column
    );
}

function m2_index_exists(string $table, string $index): bool
{
    return (bool)fetch_one(
        "SELECT 1 FROM information_schema.STATISTICS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?",
        $table,
        $index
    );
}

function m2_fk_exists(string $table, string $constraint): bool
{
    return (bool)fetch_one(
        "SELECT 1 FROM information_schema.TABLE_CONSTRAINTS
         WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = ?
           AND CONSTRAINT_NAME = ? AND CONSTRAINT_TYPE = 'FOREIGN KEY'",
        $table,
        $constraint
    );
}

function m2_unique_shop_code(int $tenantId, string $base): string
{
    $code = $base;
    $suffix = 1;
    while (fetch_one("SELECT 1 FROM shops WHERE tenant_id = ? AND code = ?", $tenantId, $code)) {
        $suffix++;
        $code = $base . '-' . $suffix;
    }
    return $code;
}

function m2_first_shop(int $tenantId): int
{
    global $conn;
    $shop = fetch_one("SELECT shop_id FROM shops WHERE tenant_id = ? ORDER BY shop_id LIMIT 1", $tenantId);
    if ($shop) {
        return (int)$shop['shop_id'];
    }

    $tenant = fetch_one(
        "SELECT t.name, ss.company_name
         FROM tenants t
         LEFT JOIN system_settings ss ON ss.tenant_id = t.tenant_id
         WHERE t.tenant_id = ?",
        $tenantId
    );
    $name = trim((string)($tenant['company_name'] ?? ''));
    if ($name === '') {
        $name = trim((string)($tenant['name'] ?? ''));
    }
    if ($name === '') {
        $name = 'Main Shop';
    }
    if (function_exists('mb_substr')) {
        $name = mb_substr($name, 0, 191);
    } else {
        $name = substr($name, 0, 191);
    }

    $code = m2_unique_shop_code($tenantId, 'SHOP-001');
    m2_exec("INSERT INTO shops (tenant_id, name, code, is_active) VALUES (?, ?, ?, 1)", $tenantId, $name, $code);
    $shopId = (int)$conn->insert_id;
    echo "  + tenant {$tenantId}: created fallback shop '{$name}' ({$code})\n";
    return $shopId;
}

echo "Migration 002: warehouse/shop integrity and POS sessions\n";
echo "Database: " . DB_NAME . "\n\n";

try {
    if (!m2_column_exists('warehouses', 'shop_id') || !fetch_one("SHOW TABLES LIKE 'shops'")) {
        throw new RuntimeException('Migration 001 must be applied before migration 002.');
    }

    echo "1) Migration status table\n";
    m2_query(
        "CREATE TABLE IF NOT EXISTS `schema_migrations` (
            `migration` varchar(191) NOT NULL,
            `applied_at` timestamp NOT NULL DEFAULT current_timestamp(),
            PRIMARY KEY (`migration`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
    echo "  = schema_migrations ready\n";

    echo "\n2) Backfill invalid warehouse -> shop links\n";
    $tenants = fetch_all(
        "SELECT DISTINCT tenant_id FROM warehouses
         WHERE shop_id IS NULL
            OR shop_id NOT IN (SELECT shop_id FROM shops)
            OR shop_id IN (
                SELECT s2.shop_id FROM shops s2
                WHERE s2.tenant_id <> warehouses.tenant_id
            )
         ORDER BY tenant_id"
    );
    if (!$tenants) {
        echo "  = no invalid warehouse links found\n";
    }
    foreach ($tenants as $row) {
        $tenantId = (int)$row['tenant_id'];
        $shopId = m2_first_shop($tenantId);
        m2_exec(
            "UPDATE warehouses
             SET shop_id = ?
             WHERE tenant_id = ?
               AND (shop_id IS NULL
                    OR shop_id NOT IN (SELECT shop_id FROM shops)
                    OR shop_id IN (SELECT s2.shop_id FROM shops s2 WHERE s2.tenant_id <> ?))",
            $shopId,
            $tenantId,
            $tenantId
        );
        echo "  = tenant {$tenantId}: invalid warehouse links mapped to shop {$shopId}\n";
    }

    $remaining = fetch_one("SELECT COUNT(*) c FROM warehouses WHERE shop_id IS NULL");
    if ((int)($remaining['c'] ?? 0) > 0) {
        throw new RuntimeException('Warehouses still have no shop; refusing to continue.');
    }

    echo "\n3) Shop pair key and warehouse constraints\n";
    if (!m2_index_exists('shops', 'uq_shops_shop_tenant')) {
        m2_query("ALTER TABLE `shops` ADD UNIQUE KEY `uq_shops_shop_tenant` (`shop_id`, `tenant_id`)");
        echo "  + unique shops(shop_id, tenant_id)\n";
    } else {
        echo "  = unique shops(shop_id, tenant_id) already exists\n";
    }

    if (m2_fk_exists('warehouses', 'fk_wh_shop')) {
        m2_query("ALTER TABLE `warehouses` DROP FOREIGN KEY `fk_wh_shop`");
        echo "  - old warehouses.fk_wh_shop (ON DELETE SET NULL)\n";
    } else {
        echo "  = old warehouses.fk_wh_shop not present\n";
    }

    // The column must be non-nullable before the composite FK is introduced.
    $column = fetch_one(
        "SELECT IS_NULLABLE, COLUMN_TYPE FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'warehouses' AND COLUMN_NAME = 'shop_id'"
    );
    if (($column['IS_NULLABLE'] ?? 'YES') === 'YES') {
        m2_query("ALTER TABLE `warehouses` MODIFY `shop_id` int(10) unsigned NOT NULL");
        echo "  ~ warehouses.shop_id is now NOT NULL\n";
    } else {
        echo "  = warehouses.shop_id already NOT NULL\n";
    }

    if (!m2_index_exists('warehouses', 'idx_wh_shop_tenant')) {
        m2_query("ALTER TABLE `warehouses` ADD KEY `idx_wh_shop_tenant` (`shop_id`, `tenant_id`)");
        echo "  + index warehouses(shop_id, tenant_id)\n";
    } else {
        echo "  = index warehouses(shop_id, tenant_id) already exists\n";
    }

    if (!m2_fk_exists('warehouses', 'fk_wh_shop_tenant')) {
        m2_query(
            "ALTER TABLE `warehouses`
             ADD CONSTRAINT `fk_wh_shop_tenant`
             FOREIGN KEY (`shop_id`, `tenant_id`) REFERENCES `shops` (`shop_id`, `tenant_id`)
             ON DELETE RESTRICT"
        );
        echo "  + composite FK warehouses(shop_id, tenant_id) -> shops\n";
    } else {
        echo "  = composite FK warehouses(shop_id, tenant_id) already exists\n";
    }

    echo "\n4) POS session lookup index\n";
    if (!m2_index_exists('pos_sessions', 'idx_ps_user_open')) {
        m2_query("ALTER TABLE `pos_sessions` ADD KEY `idx_ps_user_open` (`tenant_id`, `user_id`, `is_closed`)");
        echo "  + index pos_sessions(tenant_id, user_id, is_closed)\n";
    } else {
        echo "  = index pos_sessions(tenant_id, user_id, is_closed) already exists\n";
    }

    echo "\n5) Record migration\n";
    m2_exec("INSERT IGNORE INTO schema_migrations (migration) VALUES ('001_phase2_shops_stores')");
    m2_exec("INSERT IGNORE INTO schema_migrations (migration) VALUES ('002_warehouse_shop_integrity_pos_sessions')");
    echo "  = 001 + 002 recorded in schema_migrations\n";

    echo "\nMigration 002 completed successfully.\n";
} catch (Throwable $ex) {
    fwrite(STDERR, "\nMigration 002 FAILED: " . $ex->getMessage() . "\n");
    exit(1);
}
