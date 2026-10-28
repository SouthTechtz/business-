<?php
/**
 * Phase 2 migration: shops, stores, and user-shop access.
 *
 * SAFE / ADDITIVE:
 *   - Creates shops and user_shops.
 *   - Adds shop/store metadata and transaction provenance columns.
 *   - Backfills existing data with one default shop and the existing store(s).
 *   - Adds the manage_shops permission to Administrator roles.
 *
 * Run from the command line only:
 *   php database/migrations/001_phase2_shops_stores.php
 *
 * The script is idempotent. It does not drop or rename existing columns/tables.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("This migration can only be run from the command line.\n");
}

require_once __DIR__ . '/../../config.php';

// config.php starts output buffering; keep CLI output clean.
while (ob_get_level() > 0) {
    ob_end_clean();
}

function phase2_fail(string $message): void
{
    throw new RuntimeException($message);
}

function phase2_query(string $sql): void
{
    global $conn;
    if ($conn->query($sql) !== true) {
        phase2_fail("SQL failed: {$conn->error}\nSQL: {$sql}");
    }
}

function phase2_exec(string $sql, ...$params): void
{
    global $conn;
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        phase2_fail("Prepare failed: {$conn->error}\nSQL: {$sql}");
    }
    if ($params) {
        $types = '';
        foreach ($params as $value) {
            $types .= is_int($value) ? 'i' : (is_float($value) ? 'd' : 's');
        }
        $stmt->bind_param($types, ...$params);
    }
    if (!$stmt->execute()) {
        $error = $stmt->error;
        $stmt->close();
        phase2_fail("Execute failed: {$error}\nSQL: {$sql}");
    }
    $stmt->close();
}

function phase2_table_exists(string $table): bool
{
    $row = fetch_one(
        "SELECT COUNT(*) c FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?",
        DB_NAME,
        $table
    );
    return (int)($row['c'] ?? 0) > 0;
}

function phase2_column_exists(string $table, string $column): bool
{
    $row = fetch_one(
        "SELECT COUNT(*) c FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ?",
        DB_NAME,
        $table,
        $column
    );
    return (int)($row['c'] ?? 0) > 0;
}

function phase2_index_exists(string $table, string $index): bool
{
    $row = fetch_one(
        "SELECT COUNT(*) c FROM information_schema.STATISTICS
         WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND INDEX_NAME = ?",
        DB_NAME,
        $table,
        $index
    );
    return (int)($row['c'] ?? 0) > 0;
}

function phase2_fk_exists(string $table, string $constraint): bool
{
    $row = fetch_one(
        "SELECT COUNT(*) c FROM information_schema.TABLE_CONSTRAINTS
         WHERE CONSTRAINT_SCHEMA = ? AND TABLE_NAME = ? AND CONSTRAINT_NAME = ? AND CONSTRAINT_TYPE = 'FOREIGN KEY'",
        DB_NAME,
        $table,
        $constraint
    );
    return (int)($row['c'] ?? 0) > 0;
}

function phase2_add_column(string $table, string $column, string $definition): void
{
    if (phase2_column_exists($table, $column)) {
        echo "  = {$table}.{$column} already exists\n";
        return;
    }
    phase2_query("ALTER TABLE `{$table}` ADD COLUMN `{$column}` {$definition}");
    echo "  + {$table}.{$column}\n";
}

function phase2_add_index(string $table, string $index, string $definition): void
{
    if (phase2_index_exists($table, $index)) {
        echo "  = index {$table}.{$index} already exists\n";
        return;
    }
    phase2_query("ALTER TABLE `{$table}` ADD {$definition}");
    echo "  + index {$table}.{$index}\n";
}

function phase2_add_fk(string $table, string $constraint, string $definition): void
{
    if (phase2_fk_exists($table, $constraint)) {
        echo "  = foreign key {$table}.{$constraint} already exists\n";
        return;
    }
    phase2_query("ALTER TABLE `{$table}` ADD CONSTRAINT `{$constraint}` {$definition}");
    echo "  + foreign key {$table}.{$constraint}\n";
}

function phase2_unique_shop_code(int $tenantId, string $base): string
{
    $code = $base;
    $suffix = 1;
    while (fetch_one("SELECT 1 FROM shops WHERE tenant_id = ? AND code = ?", $tenantId, $code)) {
        $suffix++;
        $code = $base . '-' . $suffix;
    }
    return $code;
}

function phase2_unique_store_code(int $tenantId, string $base): string
{
    $code = $base;
    $suffix = 1;
    while (fetch_one("SELECT 1 FROM warehouses WHERE tenant_id = ? AND code = ?", $tenantId, $code)) {
        $suffix++;
        $code = $base . '-' . $suffix;
    }
    return $code;
}

echo "Phase 2 migration: shops, stores, and access\n";
echo "Database: " . DB_NAME . "\n\n";

try {
    echo "1) Core tables\n";
    phase2_query(
        "CREATE TABLE IF NOT EXISTS `shops` (
            `shop_id` int(10) unsigned NOT NULL AUTO_INCREMENT,
            `tenant_id` int(10) unsigned NOT NULL,
            `name` varchar(191) NOT NULL,
            `code` varchar(50) NOT NULL,
            `address` text DEFAULT NULL,
            `phone` varchar(50) DEFAULT NULL,
            `is_active` tinyint(1) NOT NULL DEFAULT 1,
            `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
            `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
            PRIMARY KEY (`shop_id`),
            UNIQUE KEY `uq_shop_code` (`tenant_id`, `code`),
            KEY `fk_shops_tenant` (`tenant_id`),
            CONSTRAINT `fk_shops_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`tenant_id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
    echo "  + shops\n";

    phase2_query(
        "CREATE TABLE IF NOT EXISTS `user_shops` (
            `user_shop_id` int(10) unsigned NOT NULL AUTO_INCREMENT,
            `tenant_id` int(10) unsigned NOT NULL,
            `user_id` int(10) unsigned NOT NULL,
            `shop_id` int(10) unsigned NOT NULL,
            `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
            PRIMARY KEY (`user_shop_id`),
            UNIQUE KEY `uq_user_shop` (`user_id`, `shop_id`),
            KEY `fk_us_tenant` (`tenant_id`),
            KEY `fk_us_shop` (`shop_id`),
            CONSTRAINT `fk_us_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`tenant_id`) ON DELETE CASCADE,
            CONSTRAINT `fk_us_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE,
            CONSTRAINT `fk_us_shop` FOREIGN KEY (`shop_id`) REFERENCES `shops` (`shop_id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
    echo "  + user_shops\n";

    echo "\n2) Store and transaction columns\n";
    phase2_add_column('warehouses', 'shop_id', 'int(10) unsigned DEFAULT NULL AFTER `tenant_id`');
    phase2_add_column('warehouses', 'code', 'varchar(50) DEFAULT NULL AFTER `name`');
    phase2_add_column('warehouses', 'is_active', 'tinyint(1) NOT NULL DEFAULT 1 AFTER `code`');

    phase2_add_column('pos_orders', 'warehouse_id', 'int(10) unsigned DEFAULT NULL AFTER `session_id`');
    phase2_add_column('pos_order_items', 'warehouse_id', 'int(10) unsigned DEFAULT NULL AFTER `order_id`');
    phase2_add_column('invoices', 'warehouse_id', 'int(10) unsigned DEFAULT NULL AFTER `invoice_number`');
    phase2_add_column('invoice_items', 'warehouse_id', 'int(10) unsigned DEFAULT NULL AFTER `invoice_id`');

    echo "\n3) Backfill default shops and stores\n";
    $tenants = fetch_all(
        "SELECT t.tenant_id, t.name, ss.company_name
         FROM tenants t
         LEFT JOIN system_settings ss ON ss.tenant_id = t.tenant_id
         ORDER BY t.tenant_id"
    );

    if (!$tenants) {
        echo "  ! No tenants found; nothing to backfill.\n";
    }

    foreach ($tenants as $tenant) {
        $tenantId = (int)$tenant['tenant_id'];
        $companyName = trim((string)($tenant['company_name'] ?? ''));
        $tenantName = trim((string)($tenant['name'] ?? ''));
        $shopName = $companyName !== '' ? $companyName : ($tenantName !== '' ? $tenantName : 'Main Shop');
        $shopName = function_exists('mb_substr') ? mb_substr($shopName, 0, 191) : substr($shopName, 0, 191);

        $shop = fetch_one("SELECT * FROM shops WHERE tenant_id = ? ORDER BY shop_id LIMIT 1", $tenantId);
        if (!$shop) {
            $baseCode = 'SHOP-' . str_pad((string)$tenantId, 3, '0', STR_PAD_LEFT);
            $shopCode = phase2_unique_shop_code($tenantId, $baseCode);
            phase2_exec(
                "INSERT INTO shops (tenant_id, name, code, is_active) VALUES (?, ?, ?, 1)",
                $tenantId,
                $shopName,
                $shopCode
            );
            $shopId = (int)$conn->insert_id;
            echo "  + tenant {$tenantId}: shop '{$shopName}' ({$shopCode})\n";
        } else {
            $shopId = (int)$shop['shop_id'];
            echo "  = tenant {$tenantId}: existing shop '{$shop['name']}'\n";
        }

        $warehouses = fetch_all("SELECT * FROM warehouses WHERE tenant_id = ? ORDER BY warehouse_id", $tenantId);
        if (!$warehouses) {
            $storeCode = phase2_unique_store_code($tenantId, 'STORE-001');
            phase2_exec(
                "INSERT INTO warehouses (tenant_id, shop_id, name, code, is_active) VALUES (?, ?, 'Main Store', ?, 1)",
                $tenantId,
                $shopId,
                $storeCode
            );
            $warehouseId = (int)$conn->insert_id;
            echo "  + tenant {$tenantId}: created default store 'Main Store' ({$storeCode})\n";
        } else {
            foreach ($warehouses as $warehouse) {
                $warehouseId = (int)$warehouse['warehouse_id'];
                $storeShopId = !empty($warehouse['shop_id']) ? (int)$warehouse['shop_id'] : $shopId;
                $code = trim((string)($warehouse['code'] ?? ''));
                if ($code === '') {
                    $code = phase2_unique_store_code($tenantId, 'STORE-' . str_pad((string)$warehouseId, 3, '0', STR_PAD_LEFT));
                }
                phase2_exec(
                    "UPDATE warehouses SET shop_id = ?, code = ?, is_active = COALESCE(is_active, 1) WHERE warehouse_id = ? AND tenant_id = ?",
                    $storeShopId,
                    $code,
                    $warehouseId,
                    $tenantId
                );
                echo "  = tenant {$tenantId}: store '{$warehouse['name']}' -> shop {$storeShopId}, code {$code}\n";
            }
            $warehouseId = (int)$warehouses[0]['warehouse_id'];
        }

        // Every existing user keeps working: map users with no mapping to all shops.
        $shopIds = array_map(fn($row) => (int)$row['shop_id'], fetch_all("SELECT shop_id FROM shops WHERE tenant_id = ? ORDER BY shop_id", $tenantId));
        $users = fetch_all("SELECT user_id FROM users WHERE tenant_id = ? ORDER BY user_id", $tenantId);
        foreach ($users as $user) {
            $userId = (int)$user['user_id'];
            $mapped = fetch_one("SELECT COUNT(*) c FROM user_shops WHERE tenant_id = ? AND user_id = ?", $tenantId, $userId);
            if ((int)($mapped['c'] ?? 0) === 0) {
                foreach ($shopIds as $mappedShopId) {
                    phase2_exec(
                        "INSERT IGNORE INTO user_shops (tenant_id, user_id, shop_id) VALUES (?, ?, ?)",
                        $tenantId,
                        $userId,
                        $mappedShopId
                    );
                }
                echo "  + user {$userId}: mapped to " . count($shopIds) . " shop(s)\n";
            }
        }

        // Historical transactions receive the tenant's first store as provenance.
        // This is an explicit, documented inference for legacy rows.
        phase2_exec(
            "UPDATE pos_sessions SET warehouse_id = ? WHERE tenant_id = ? AND (warehouse_id IS NULL OR warehouse_id NOT IN (SELECT warehouse_id FROM warehouses WHERE tenant_id = ?))",
            $warehouseId,
            $tenantId,
            $tenantId
        );
        phase2_exec("UPDATE pos_orders SET warehouse_id = ? WHERE tenant_id = ? AND warehouse_id IS NULL", $warehouseId, $tenantId);
        phase2_exec(
            "UPDATE pos_order_items oi
             JOIN pos_orders o ON o.order_id = oi.order_id
             SET oi.warehouse_id = o.warehouse_id
             WHERE o.tenant_id = ? AND oi.warehouse_id IS NULL",
            $tenantId
        );
        phase2_exec("UPDATE invoices SET warehouse_id = ? WHERE tenant_id = ? AND warehouse_id IS NULL", $warehouseId, $tenantId);
        phase2_exec(
            "UPDATE invoice_items ii
             JOIN invoices i ON i.invoice_id = ii.invoice_id
             SET ii.warehouse_id = i.warehouse_id
             WHERE i.tenant_id = ? AND ii.warehouse_id IS NULL",
            $tenantId
        );
        echo "  = tenant {$tenantId}: transaction provenance backfilled to store {$warehouseId}\n";
    }

    echo "\n4) Indexes and foreign keys\n";
    phase2_add_index('warehouses', 'uq_wh_code', 'UNIQUE KEY `uq_wh_code` (`tenant_id`, `code`)');
    phase2_add_index('warehouses', 'fk_wh_shop', 'KEY `fk_wh_shop` (`shop_id`)');
    phase2_add_index('warehouses', 'idx_wh_active', 'KEY `idx_wh_active` (`tenant_id`, `is_active`)');
    phase2_add_index('pos_orders', 'fk_orders_wh', 'KEY `fk_orders_wh` (`warehouse_id`)');
    phase2_add_index('pos_orders', 'idx_orders_wh_date', 'KEY `idx_orders_wh_date` (`warehouse_id`, `order_date`)');
    phase2_add_index('pos_order_items', 'fk_poi_wh', 'KEY `fk_poi_wh` (`warehouse_id`)');
    phase2_add_index('invoices', 'fk_invoices_wh', 'KEY `fk_invoices_wh` (`warehouse_id`)');
    phase2_add_index('invoices', 'idx_invoices_wh_date', 'KEY `idx_invoices_wh_date` (`warehouse_id`, `issue_date`)');
    phase2_add_index('invoice_items', 'fk_ii_wh', 'KEY `fk_ii_wh` (`warehouse_id`)');
    phase2_add_index('pos_sessions', 'fk_ps_wh', 'KEY `fk_ps_wh` (`warehouse_id`)');

    phase2_add_fk('warehouses', 'fk_wh_shop', 'FOREIGN KEY (`shop_id`) REFERENCES `shops` (`shop_id`) ON DELETE SET NULL');
    phase2_add_fk('pos_orders', 'fk_orders_wh', 'FOREIGN KEY (`warehouse_id`) REFERENCES `warehouses` (`warehouse_id`) ON DELETE SET NULL');
    phase2_add_fk('pos_order_items', 'fk_poi_wh', 'FOREIGN KEY (`warehouse_id`) REFERENCES `warehouses` (`warehouse_id`) ON DELETE SET NULL');
    phase2_add_fk('invoices', 'fk_invoices_wh', 'FOREIGN KEY (`warehouse_id`) REFERENCES `warehouses` (`warehouse_id`) ON DELETE SET NULL');
    phase2_add_fk('invoice_items', 'fk_ii_wh', 'FOREIGN KEY (`warehouse_id`) REFERENCES `warehouses` (`warehouse_id`) ON DELETE SET NULL');
    phase2_add_fk('pos_sessions', 'fk_ps_wh', 'FOREIGN KEY (`warehouse_id`) REFERENCES `warehouses` (`warehouse_id`) ON DELETE SET NULL');

    echo "\n5) Permission\n";
    $permission = fetch_one("SELECT permission_id FROM permissions WHERE name = 'manage_shops' LIMIT 1");
    if (!$permission) {
        phase2_exec(
            "INSERT INTO permissions (name, description) VALUES ('manage_shops', 'Manage shops, stores, and shop access')"
        );
        $permissionId = (int)$conn->insert_id;
        echo "  + permission manage_shops\n";
    } else {
        $permissionId = (int)$permission['permission_id'];
        echo "  = permission manage_shops already exists\n";
    }

    foreach ($tenants as $tenant) {
        $tenantId = (int)$tenant['tenant_id'];
        $roles = fetch_all(
            "SELECT role_id FROM roles WHERE tenant_id = ? AND LOWER(name) = 'administrator'",
            $tenantId
        );
        if (!$roles) {
            $roles = fetch_all(
                "SELECT DISTINCT r.role_id
                 FROM roles r
                 JOIN role_permissions rp ON rp.role_id = r.role_id
                 JOIN permissions p ON p.permission_id = rp.permission_id
                 WHERE r.tenant_id = ? AND p.name IN ('manage_settings', 'manage_users')",
                $tenantId
            );
        }
        foreach ($roles as $role) {
            phase2_exec(
                "INSERT IGNORE INTO role_permissions (role_id, permission_id) VALUES (?, ?)",
                (int)$role['role_id'],
                $permissionId
            );
            echo "  + role {$role['role_id']}: manage_shops\n";
        }
    }

    echo "\nMigration complete.\n";
    echo "Shops: " . (int)(fetch_one("SELECT COUNT(*) c FROM shops")['c'] ?? 0) . "\n";
    echo "Stores: " . (int)(fetch_one("SELECT COUNT(*) c FROM warehouses")['c'] ?? 0) . "\n";
    echo "User-shop mappings: " . (int)(fetch_one("SELECT COUNT(*) c FROM user_shops")['c'] ?? 0) . "\n";
} catch (Throwable $ex) {
    fwrite(STDERR, "\nMIGRATION FAILED: " . $ex->getMessage() . "\n");
    exit(1);
}
