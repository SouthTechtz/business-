<?php
/**
 * Migration 003: Orders and Leads.
 *
 * SAFE / ADDITIVE:
 *   - Creates document_sequences (concurrency-safe per-tenant numbering).
 *   - Creates leads (CRM follow-up, separate from orders).
 *   - Creates orders and order_items (customer purchase requests, no stock effects).
 *   - Extends the dormant CRM activities table with lead/order history fields.
 *   - Adds manage_orders and manage_leads permissions and grants them to
 *     existing administrator/sales/CRM roles.
 *   - Records itself in schema_migrations.
 *
 * It does not drop or rewrite existing business tables, and it never touches
 * inventory_stock, inventory_movements, pos_orders, invoices, or payments.
 *
 * Run from the command line only:
 *   php database/migrations/003_orders_leads.php
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

function m3_query(string $sql): void
{
    global $conn;
    if ($conn->query($sql) !== true) {
        throw new RuntimeException($conn->error . ' | SQL: ' . $sql);
    }
}

function m3_exec(string $sql, ...$params): void
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

function m3_table_exists(string $table): bool
{
    return (bool)fetch_one(
        "SELECT 1 FROM information_schema.TABLES
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?",
        $table
    );
}

function m3_column_exists(string $table, string $column): bool
{
    return (bool)fetch_one(
        "SELECT 1 FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?",
        $table,
        $column
    );
}

function m3_index_exists(string $table, string $index): bool
{
    return (bool)fetch_one(
        "SELECT 1 FROM information_schema.STATISTICS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ? LIMIT 1",
        $table,
        $index
    );
}

function m3_fk_exists(string $table, string $constraint): bool
{
    return (bool)fetch_one(
        "SELECT 1 FROM information_schema.TABLE_CONSTRAINTS
         WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = ?
           AND CONSTRAINT_NAME = ? AND CONSTRAINT_TYPE = 'FOREIGN KEY' LIMIT 1",
        $table,
        $constraint
    );
}

function m3_add_column(string $table, string $column, string $definition): void
{
    if (m3_column_exists($table, $column)) {
        echo "  = {$table}.{$column} already exists\n";
        return;
    }
    m3_query("ALTER TABLE `{$table}` ADD COLUMN `{$column}` {$definition}");
    echo "  + {$table}.{$column}\n";
}

function m3_add_index(string $table, string $index, string $columns): void
{
    if (m3_index_exists($table, $index)) {
        echo "  = index {$table}.{$index} already exists\n";
        return;
    }
    m3_query("ALTER TABLE `{$table}` ADD KEY `{$index}` ({$columns})");
    echo "  + index {$table}.{$index}\n";
}

function m3_add_fk(string $table, string $constraint, string $clause): void
{
    if (m3_fk_exists($table, $constraint)) {
        echo "  = FK {$table}.{$constraint} already exists\n";
        return;
    }
    m3_query("ALTER TABLE `{$table}` ADD CONSTRAINT `{$constraint}` {$clause}");
    echo "  + FK {$table}.{$constraint}\n";
}

echo "Migration 003: Orders and Leads\n";
echo "Database: " . DB_NAME . "\n\n";

try {
    echo "1) Document sequences\n";
    m3_query(
        "CREATE TABLE IF NOT EXISTS `document_sequences` (
            `tenant_id` int(10) unsigned NOT NULL,
            `sequence_key` varchar(30) NOT NULL,
            `next_value` int(10) unsigned NOT NULL DEFAULT 1,
            `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
            PRIMARY KEY (`tenant_id`, `sequence_key`),
            CONSTRAINT `fk_docseq_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`tenant_id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
    echo "  + document_sequences\n";

    echo "\n2) Leads\n";
    m3_query(
        "CREATE TABLE IF NOT EXISTS `leads` (
            `lead_id` int(10) unsigned NOT NULL AUTO_INCREMENT,
            `tenant_id` int(10) unsigned NOT NULL,
            `lead_number` varchar(30) NOT NULL,
            `contact_id` int(10) unsigned DEFAULT NULL,
            `contact_name` varchar(191) NOT NULL,
            `company_name` varchar(191) DEFAULT NULL,
            `phone` varchar(50) DEFAULT NULL,
            `email` varchar(191) DEFAULT NULL,
            `location` varchar(255) DEFAULT NULL,
            `source` varchar(20) NOT NULL DEFAULT 'staff',
            `status` varchar(20) NOT NULL DEFAULT 'new',
            `assigned_to` int(10) unsigned DEFAULT NULL,
            `next_follow_up_at` date DEFAULT NULL,
            `last_contacted_at` datetime DEFAULT NULL,
            `notes` text DEFAULT NULL,
            `lost_reason` varchar(255) DEFAULT NULL,
            `converted_at` datetime DEFAULT NULL,
            `created_by` int(10) unsigned DEFAULT NULL,
            `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
            `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
            PRIMARY KEY (`lead_id`),
            UNIQUE KEY `uq_lead_number` (`tenant_id`, `lead_number`),
            UNIQUE KEY `uq_lead_tenant` (`lead_id`, `tenant_id`),
            KEY `idx_leads_status` (`tenant_id`, `status`),
            KEY `idx_leads_assigned` (`tenant_id`, `assigned_to`, `status`),
            KEY `idx_leads_followup` (`tenant_id`, `next_follow_up_at`),
            KEY `idx_leads_created` (`tenant_id`, `created_at`),
            CONSTRAINT `fk_leads_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`tenant_id`) ON DELETE CASCADE,
            CONSTRAINT `fk_leads_contact` FOREIGN KEY (`contact_id`) REFERENCES `contacts` (`contact_id`) ON DELETE SET NULL,
            CONSTRAINT `fk_leads_assigned` FOREIGN KEY (`assigned_to`) REFERENCES `users` (`user_id`) ON DELETE SET NULL,
            CONSTRAINT `fk_leads_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
    echo "  + leads\n";

    echo "\n3) Orders\n";
    m3_query(
        "CREATE TABLE IF NOT EXISTS `orders` (
            `order_id` int(10) unsigned NOT NULL AUTO_INCREMENT,
            `tenant_id` int(10) unsigned NOT NULL,
            `order_number` varchar(30) NOT NULL,
            `contact_id` int(10) unsigned DEFAULT NULL,
            `lead_id` int(10) unsigned DEFAULT NULL,
            `customer_name` varchar(191) NOT NULL,
            `customer_phone` varchar(50) NOT NULL,
            `customer_location` varchar(255) NOT NULL,
            `customer_email` varchar(191) DEFAULT NULL,
            `source` varchar(20) NOT NULL DEFAULT 'staff',
            `status` varchar(20) NOT NULL DEFAULT 'new',
            `currency_code` varchar(10) NOT NULL DEFAULT 'TZS',
            `subtotal` decimal(12,2) NOT NULL DEFAULT 0.00,
            `total` decimal(12,2) NOT NULL DEFAULT 0.00,
            `shop_id` int(10) unsigned DEFAULT NULL,
            `warehouse_id` int(10) unsigned DEFAULT NULL,
            `notes` text DEFAULT NULL,
            `assigned_to` int(10) unsigned DEFAULT NULL,
            `created_by` int(10) unsigned DEFAULT NULL,
            `confirmed_by` int(10) unsigned DEFAULT NULL,
            `confirmed_at` datetime DEFAULT NULL,
            `rejected_by` int(10) unsigned DEFAULT NULL,
            `rejected_at` datetime DEFAULT NULL,
            `rejection_reason` varchar(255) DEFAULT NULL,
            `cancelled_by` int(10) unsigned DEFAULT NULL,
            `cancelled_at` datetime DEFAULT NULL,
            `cancel_reason` varchar(255) DEFAULT NULL,
            `fulfilled_by` int(10) unsigned DEFAULT NULL,
            `fulfilled_at` datetime DEFAULT NULL,
            `fulfilled_invoice_id` int(10) unsigned DEFAULT NULL,
            `fulfilled_invoice_number` varchar(100) DEFAULT NULL,
            `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
            `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
            PRIMARY KEY (`order_id`),
            UNIQUE KEY `uq_order_number` (`tenant_id`, `order_number`),
            UNIQUE KEY `uq_order_tenant` (`order_id`, `tenant_id`),
            UNIQUE KEY `uq_order_invoice` (`fulfilled_invoice_id`),
            KEY `idx_orders_status` (`tenant_id`, `status`, `created_at`),
            KEY `idx_orders_shop` (`shop_id`, `tenant_id`, `status`),
            KEY `idx_orders_assigned` (`tenant_id`, `assigned_to`, `status`),
            KEY `idx_orders_created` (`tenant_id`, `created_at`),
            CONSTRAINT `fk_cord_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`tenant_id`) ON DELETE CASCADE,
            CONSTRAINT `fk_cord_contact` FOREIGN KEY (`contact_id`) REFERENCES `contacts` (`contact_id`) ON DELETE SET NULL,
            CONSTRAINT `fk_cord_lead` FOREIGN KEY (`lead_id`) REFERENCES `leads` (`lead_id`) ON DELETE SET NULL,
            CONSTRAINT `fk_cord_shop` FOREIGN KEY (`shop_id`, `tenant_id`) REFERENCES `shops` (`shop_id`, `tenant_id`),
            CONSTRAINT `fk_cord_wh` FOREIGN KEY (`warehouse_id`) REFERENCES `warehouses` (`warehouse_id`) ON DELETE SET NULL,
            CONSTRAINT `fk_cord_assigned` FOREIGN KEY (`assigned_to`) REFERENCES `users` (`user_id`) ON DELETE SET NULL,
            CONSTRAINT `fk_cord_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL,
            CONSTRAINT `fk_cord_confirmed_by` FOREIGN KEY (`confirmed_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL,
            CONSTRAINT `fk_cord_rejected_by` FOREIGN KEY (`rejected_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL,
            CONSTRAINT `fk_cord_cancelled_by` FOREIGN KEY (`cancelled_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL,
            CONSTRAINT `fk_cord_fulfilled_by` FOREIGN KEY (`fulfilled_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL,
            CONSTRAINT `fk_cord_invoice` FOREIGN KEY (`fulfilled_invoice_id`) REFERENCES `invoices` (`invoice_id`) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
    echo "  + orders\n";

    if (!m3_index_exists('orders', 'uq_order_tenant')) {
        m3_query("ALTER TABLE `orders` ADD UNIQUE KEY `uq_order_tenant` (`order_id`, `tenant_id`)");
        echo "  + unique orders(order_id, tenant_id)\n";
    }

    echo "\n4) Order items\n";
    m3_query(
        "CREATE TABLE IF NOT EXISTS `order_items` (
            `order_item_id` int(10) unsigned NOT NULL AUTO_INCREMENT,
            `tenant_id` int(10) unsigned NOT NULL,
            `order_id` int(10) unsigned NOT NULL,
            `line_no` smallint(5) unsigned NOT NULL,
            `product_id` int(10) unsigned DEFAULT NULL,
            `product_code_snapshot` varchar(100) NOT NULL,
            `product_name_snapshot` varchar(191) NOT NULL,
            `product_description_snapshot` text DEFAULT NULL,
            `unit_label_snapshot` varchar(50) DEFAULT NULL,
            `quantity` decimal(12,2) NOT NULL,
            `unit_price` decimal(12,2) NOT NULL DEFAULT 0.00,
            `tax_rate_snapshot` decimal(5,2) NOT NULL DEFAULT 0.00,
            `line_total` decimal(12,2) NOT NULL DEFAULT 0.00,
            `notes` varchar(255) DEFAULT NULL,
            `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
            PRIMARY KEY (`order_item_id`),
            UNIQUE KEY `uq_order_line` (`order_id`, `tenant_id`, `line_no`),
            KEY `idx_oi_product` (`tenant_id`, `product_id`),
            CONSTRAINT `fk_coi_order` FOREIGN KEY (`order_id`, `tenant_id`) REFERENCES `orders` (`order_id`, `tenant_id`) ON DELETE CASCADE,
            CONSTRAINT `fk_coi_product` FOREIGN KEY (`product_id`) REFERENCES `products` (`product_id`) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
    echo "  + order_items\n";

    echo "\n5) Extend activities for lead/order history\n";
    m3_add_column('activities', 'lead_id', 'int(10) unsigned DEFAULT NULL AFTER `contact_id`');
    m3_add_column('activities', 'order_id', 'int(10) unsigned DEFAULT NULL AFTER `lead_id`');
    m3_add_column('activities', 'activity_type', "varchar(20) NOT NULL DEFAULT 'note' AFTER `subject`");
    m3_add_column('activities', 'notes', 'text DEFAULT NULL AFTER `activity_type`');
    m3_add_column('activities', 'outcome', 'varchar(100) DEFAULT NULL AFTER `notes`');
    m3_add_index('activities', 'idx_act_lead', '`lead_id`, `tenant_id`, `created_at`');
    m3_add_index('activities', 'idx_act_order', '`order_id`');
    m3_add_fk('activities', 'fk_act_lead',
        'FOREIGN KEY (`lead_id`, `tenant_id`) REFERENCES `leads` (`lead_id`, `tenant_id`) ON DELETE CASCADE');
    m3_add_fk('activities', 'fk_act_order',
        'FOREIGN KEY (`order_id`) REFERENCES `orders` (`order_id`) ON DELETE SET NULL');

    echo "\n6) Seed number sequences\n";
    $tenants = fetch_all("SELECT tenant_id FROM tenants ORDER BY tenant_id");
    foreach ($tenants as $tenant) {
        $tid = (int)$tenant['tenant_id'];
        m3_exec(
            "INSERT IGNORE INTO document_sequences (tenant_id, sequence_key, next_value) VALUES (?, 'order', 1)",
            $tid
        );
        m3_exec(
            "INSERT IGNORE INTO document_sequences (tenant_id, sequence_key, next_value) VALUES (?, 'lead', 1)",
            $tid
        );
        echo "  + tenant {$tid}: order/lead sequences\n";
    }
    if (!$tenants) {
        echo "  ! No tenants found; nothing to seed.\n";
    }

    echo "\n7) Permissions\n";
    $newPermissions = [
        'manage_orders' => 'Record and manage customer orders',
        'manage_leads'  => 'Manage leads and customer follow-ups',
    ];
    foreach ($newPermissions as $name => $description) {
        $existing = fetch_one("SELECT permission_id FROM permissions WHERE name = ? LIMIT 1", $name);
        if ($existing) {
            echo "  = permission {$name} already exists\n";
            continue;
        }
        m3_exec("INSERT INTO permissions (name, description) VALUES (?, ?)", $name, $description);
        echo "  + permission {$name}\n";
    }

    // Grant manage_orders to sales/admin roles; manage_leads to CRM/admin roles.
    $grants = [
        'manage_orders' => ['manage_pos', 'manage_invoices', 'manage_settings', 'manage_users'],
        'manage_leads'  => ['manage_contacts', 'manage_settings', 'manage_users'],
    ];
    foreach ($grants as $permission => $sourcePermissions) {
        $permissionRow = fetch_one("SELECT permission_id FROM permissions WHERE name = ? LIMIT 1", $permission);
        if (!$permissionRow) {
            continue;
        }
        $permissionId = (int)$permissionRow['permission_id'];
        $placeholders = implode(',', array_fill(0, count($sourcePermissions), '?'));
        $roles = fetch_all(
            "SELECT DISTINCT r.role_id, r.tenant_id
             FROM roles r
             JOIN role_permissions rp ON rp.role_id = r.role_id
             JOIN permissions p ON p.permission_id = rp.permission_id
             WHERE p.name IN ({$placeholders})
             ORDER BY r.role_id",
            ...$sourcePermissions
        );
        foreach ($roles as $role) {
            m3_exec(
                "INSERT IGNORE INTO role_permissions (role_id, permission_id) VALUES (?, ?)",
                (int)$role['role_id'],
                $permissionId
            );
        }
        echo "  + {$permission} granted to " . count($roles) . " existing role(s)\n";
    }

    echo "\n8) Record migration\n";
    m3_exec("INSERT IGNORE INTO schema_migrations (migration) VALUES ('003_orders_leads')");
    echo "  = 003_orders_leads recorded\n";

    echo "\nMigration 003 completed successfully.\n";
} catch (Throwable $ex) {
    fwrite(STDERR, "\nMigration 003 FAILED: " . $ex->getMessage() . "\n");
    exit(1);
}
