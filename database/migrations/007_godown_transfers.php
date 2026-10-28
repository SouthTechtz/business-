<?php
/**
 * Migration 007: Central Godown, stock receipts, and shop transfers.
 *
 * SAFE / ADDITIVE:
 *   - Designates an existing warehouse as the tenant's central Godown. No new
 *     shop type is introduced and warehouses.shop_id remains required.
 *   - Enforces at most one active central Godown per tenant with a generated
 *     key + unique index.
 *   - Adds a receipt ledger and a transfer ledger. It does not move, reset, or
 *     recalculate any existing inventory_stock quantity.
 *   - Adds central-Godown permissions and grants them to Administrator roles.
 *
 * Run from the command line only:
 *   php database/migrations/007_godown_transfers.php
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

function m7_query(string $sql): void
{
    global $conn;
    if ($conn->query($sql) !== true) {
        throw new RuntimeException($conn->error . ' | SQL: ' . $sql);
    }
}

function m7_exec(string $sql, ...$params): void
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

function m7_table_exists(string $table): bool
{
    return (bool)fetch_one(
        "SELECT 1 FROM information_schema.TABLES
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? LIMIT 1",
        $table
    );
}

function m7_column_exists(string $table, string $column): bool
{
    return (bool)fetch_one(
        "SELECT 1 FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1",
        $table,
        $column
    );
}

function m7_index_exists(string $table, string $index): bool
{
    return (bool)fetch_one(
        "SELECT 1 FROM information_schema.STATISTICS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ? LIMIT 1",
        $table,
        $index
    );
}

function m7_fk_exists(string $table, string $constraint): bool
{
    return (bool)fetch_one(
        "SELECT 1 FROM information_schema.TABLE_CONSTRAINTS
         WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = ?
           AND CONSTRAINT_NAME = ? AND CONSTRAINT_TYPE = 'FOREIGN KEY' LIMIT 1",
        $table,
        $constraint
    );
}

echo "Migration 007: Central Godown and stock transfers\n";
echo "Database: " . DB_NAME . "\n\n";

try {
    foreach (['warehouses', 'shops', 'products', 'inventory_stock', 'inventory_movements', 'users', 'roles', 'permissions', 'role_permissions', 'document_sequences'] as $required) {
        if (!m7_table_exists($required)) {
            throw new RuntimeException("Required table `{$required}` is missing. Apply migrations 001-006 first.");
        }
    }

    echo "1) Warehouse central-Godown designation\n";
    if (!m7_column_exists('warehouses', 'is_godown')) {
        m7_query("ALTER TABLE `warehouses` ADD COLUMN `is_godown` tinyint(1) NOT NULL DEFAULT 0 AFTER `is_active`");
        echo "  + warehouses.is_godown\n";
    } else {
        echo "  = warehouses.is_godown already exists\n";
    }

    if (!m7_column_exists('warehouses', 'godown_tenant_key')) {
        m7_query(
            "ALTER TABLE `warehouses`
             ADD COLUMN `godown_tenant_key` int(10) unsigned
             AS (CASE WHEN `is_godown` = 1 AND `is_active` = 1 THEN `tenant_id` ELSE NULL END) STORED
             AFTER `is_godown`"
        );
        echo "  + warehouses.godown_tenant_key (generated)\n";
    } else {
        echo "  = warehouses.godown_tenant_key already exists\n";
    }

    if (!m7_index_exists('warehouses', 'uq_wh_active_godown')) {
        m7_query("ALTER TABLE `warehouses` ADD UNIQUE KEY `uq_wh_active_godown` (`godown_tenant_key`)");
        echo "  + unique active Godown per tenant\n";
    } else {
        echo "  = unique active Godown index already exists\n";
    }

    if (!m7_index_exists('warehouses', 'uq_wh_warehouse_tenant')) {
        m7_query("ALTER TABLE `warehouses` ADD UNIQUE KEY `uq_wh_warehouse_tenant` (`warehouse_id`, `tenant_id`)");
        echo "  + unique warehouses(warehouse_id, tenant_id)\n";
    } else {
        echo "  = warehouses(warehouse_id, tenant_id) key already exists\n";
    }

    echo "\n2) Godown receipts\n";
    if (!m7_table_exists('godown_receipts')) {
        m7_query(
            "CREATE TABLE `godown_receipts` (
                `receipt_id` int(10) unsigned NOT NULL AUTO_INCREMENT,
                `tenant_id` int(10) unsigned NOT NULL,
                `receipt_number` varchar(50) NOT NULL,
                `godown_id` int(10) unsigned NOT NULL,
                `product_id` int(10) unsigned NOT NULL,
                `quantity` decimal(12,2) NOT NULL,
                `source_reference` varchar(191) DEFAULT NULL,
                `notes` text DEFAULT NULL,
                `received_by` int(10) unsigned DEFAULT NULL,
                `received_at` datetime NOT NULL DEFAULT current_timestamp(),
                `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
                PRIMARY KEY (`receipt_id`),
                UNIQUE KEY `uq_godown_receipt_number` (`tenant_id`, `receipt_number`),
                KEY `idx_godown_receipts_godown` (`tenant_id`, `godown_id`, `received_at`),
                KEY `idx_godown_receipts_product` (`tenant_id`, `product_id`, `received_at`),
                CONSTRAINT `fk_gr_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`tenant_id`) ON DELETE CASCADE,
                CONSTRAINT `fk_gr_godown` FOREIGN KEY (`godown_id`, `tenant_id`) REFERENCES `warehouses` (`warehouse_id`, `tenant_id`) ON DELETE RESTRICT,
                CONSTRAINT `fk_gr_product` FOREIGN KEY (`product_id`) REFERENCES `products` (`product_id`) ON DELETE RESTRICT,
                CONSTRAINT `fk_gr_user` FOREIGN KEY (`received_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL,
                CONSTRAINT `chk_gr_quantity` CHECK (`quantity` > 0)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
        echo "  + godown_receipts\n";
    } else {
        echo "  = godown_receipts already exists\n";
    }

    echo "\n3) Stock transfers\n";
    if (!m7_table_exists('stock_transfers')) {
        m7_query(
            "CREATE TABLE `stock_transfers` (
                `transfer_id` int(10) unsigned NOT NULL AUTO_INCREMENT,
                `tenant_id` int(10) unsigned NOT NULL,
                `transfer_number` varchar(50) NOT NULL,
                `source_warehouse_id` int(10) unsigned NOT NULL,
                `destination_shop_id` int(10) unsigned NOT NULL,
                `destination_warehouse_id` int(10) unsigned NOT NULL,
                `status` enum('draft','approved','dispatched','received','discrepancy','cancelled') NOT NULL DEFAULT 'draft',
                `has_discrepancy` tinyint(1) NOT NULL DEFAULT 0,
                `notes` text DEFAULT NULL,
                `cancel_reason` varchar(500) DEFAULT NULL,
                `discrepancy_resolution` text DEFAULT NULL,
                `requested_by` int(10) unsigned DEFAULT NULL,
                `requested_at` datetime NOT NULL DEFAULT current_timestamp(),
                `approved_by` int(10) unsigned DEFAULT NULL,
                `approved_at` datetime DEFAULT NULL,
                `dispatched_by` int(10) unsigned DEFAULT NULL,
                `dispatched_at` datetime DEFAULT NULL,
                `received_by` int(10) unsigned DEFAULT NULL,
                `received_at` datetime DEFAULT NULL,
                `discrepancy_resolved_by` int(10) unsigned DEFAULT NULL,
                `discrepancy_resolved_at` datetime DEFAULT NULL,
                `cancelled_by` int(10) unsigned DEFAULT NULL,
                `cancelled_at` datetime DEFAULT NULL,
                `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
                `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
                PRIMARY KEY (`transfer_id`),
                UNIQUE KEY `uq_stock_transfer_number` (`tenant_id`, `transfer_number`),
                UNIQUE KEY `uq_stock_transfer_tenant` (`transfer_id`, `tenant_id`),
                KEY `idx_transfer_status` (`tenant_id`, `status`, `requested_at`),
                KEY `idx_transfer_source` (`tenant_id`, `source_warehouse_id`, `status`),
                KEY `idx_transfer_destination` (`tenant_id`, `destination_shop_id`, `destination_warehouse_id`, `status`),
                CONSTRAINT `fk_st_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`tenant_id`) ON DELETE CASCADE,
                CONSTRAINT `fk_st_source` FOREIGN KEY (`source_warehouse_id`, `tenant_id`) REFERENCES `warehouses` (`warehouse_id`, `tenant_id`) ON DELETE RESTRICT,
                CONSTRAINT `fk_st_destination_shop` FOREIGN KEY (`destination_shop_id`, `tenant_id`) REFERENCES `shops` (`shop_id`, `tenant_id`) ON DELETE RESTRICT,
                CONSTRAINT `fk_st_destination_store` FOREIGN KEY (`destination_warehouse_id`, `tenant_id`) REFERENCES `warehouses` (`warehouse_id`, `tenant_id`) ON DELETE RESTRICT,
                CONSTRAINT `fk_st_requested_by` FOREIGN KEY (`requested_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL,
                CONSTRAINT `fk_st_approved_by` FOREIGN KEY (`approved_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL,
                CONSTRAINT `fk_st_dispatched_by` FOREIGN KEY (`dispatched_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL,
                CONSTRAINT `fk_st_received_by` FOREIGN KEY (`received_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL,
                CONSTRAINT `fk_st_discrepancy_by` FOREIGN KEY (`discrepancy_resolved_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL,
                CONSTRAINT `fk_st_cancelled_by` FOREIGN KEY (`cancelled_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
        echo "  + stock_transfers\n";
    } else {
        echo "  = stock_transfers already exists\n";
    }

    if (!m7_table_exists('stock_transfer_items')) {
        m7_query(
            "CREATE TABLE `stock_transfer_items` (
                `item_id` int(10) unsigned NOT NULL AUTO_INCREMENT,
                `tenant_id` int(10) unsigned NOT NULL,
                `transfer_id` int(10) unsigned NOT NULL,
                `line_no` smallint(5) unsigned NOT NULL DEFAULT 1,
                `product_id` int(10) unsigned NOT NULL,
                `requested_quantity` decimal(12,2) NOT NULL,
                `dispatched_quantity` decimal(12,2) NOT NULL DEFAULT 0.00,
                `received_quantity` decimal(12,2) NOT NULL DEFAULT 0.00,
                `damaged_quantity` decimal(12,2) NOT NULL DEFAULT 0.00,
                `shortage_quantity` decimal(12,2) NOT NULL DEFAULT 0.00,
                `excess_quantity` decimal(12,2) NOT NULL DEFAULT 0.00,
                `line_notes` text DEFAULT NULL,
                `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
                `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
                PRIMARY KEY (`item_id`),
                UNIQUE KEY `uq_transfer_item_product` (`transfer_id`, `product_id`),
                KEY `idx_transfer_items_product` (`tenant_id`, `product_id`),
                CONSTRAINT `fk_sti_transfer` FOREIGN KEY (`transfer_id`, `tenant_id`) REFERENCES `stock_transfers` (`transfer_id`, `tenant_id`) ON DELETE CASCADE,
                CONSTRAINT `fk_sti_product` FOREIGN KEY (`product_id`) REFERENCES `products` (`product_id`) ON DELETE RESTRICT,
                CONSTRAINT `chk_sti_requested` CHECK (`requested_quantity` > 0),
                CONSTRAINT `chk_sti_dispatched` CHECK (`dispatched_quantity` >= 0),
                CONSTRAINT `chk_sti_received` CHECK (`received_quantity` >= 0 AND `damaged_quantity` >= 0 AND `shortage_quantity` >= 0 AND `excess_quantity` >= 0),
                CONSTRAINT `chk_sti_quantities` CHECK (`received_quantity` + `damaged_quantity` + `shortage_quantity` <= `dispatched_quantity`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
        echo "  + stock_transfer_items\n";
    } else {
        echo "  = stock_transfer_items already exists\n";
    }

    echo "\n4) Permissions\n";
    $permissions = [
        'manage_godown' => 'Configure the central Godown, receive stock, and request transfers',
        'approve_godown_transfers' => 'Approve, cancel, or resolve Godown stock transfers',
        'dispatch_godown_transfers' => 'Dispatch approved Godown stock transfers',
        'receive_godown_transfers' => 'Receive Godown stock transfers and record discrepancies',
    ];
    foreach ($permissions as $name => $description) {
        m7_exec(
            "INSERT INTO permissions (name, description) VALUES (?, ?)
             ON DUPLICATE KEY UPDATE description = VALUES(description)",
            $name,
            $description
        );
        echo "  = permission {$name}\n";
    }

    m7_exec(
        "INSERT INTO role_permissions (role_id, permission_id)
         SELECT r.role_id, p.permission_id
           FROM roles r
           JOIN permissions p ON p.name IN ('manage_godown', 'approve_godown_transfers', 'dispatch_godown_transfers', 'receive_godown_transfers')
          WHERE LOWER(r.name) = 'administrator'
            AND NOT EXISTS (
                SELECT 1 FROM role_permissions rp
                 WHERE rp.role_id = r.role_id AND rp.permission_id = p.permission_id
            )"
    );
    echo "  = Administrator roles granted central-Godown permissions\n";

    m7_exec("INSERT IGNORE INTO schema_migrations (migration) VALUES ('007_godown_transfers')");
    echo "\n  = 007_godown_transfers recorded\n";
    echo "\nMigration 007 completed successfully. No physical stock was changed.\n";
} catch (Throwable $ex) {
    fwrite(STDERR, "\nMigration 007 FAILED: " . $ex->getMessage() . "\n");
    exit(1);
}
