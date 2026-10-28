<?php
/**
 * Migration 004: Route public storefront requests through Leads.
 *
 * Adds tenant-scoped customer numbers and stores request item snapshots until
 * a staff member confirms the lead and creates its Order.
 *
 * Run from the command line only:
 *   php database/migrations/004_storefront_lead_requests.php
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

function m4_query(string $sql): void
{
    global $conn;
    if ($conn->query($sql) !== true) {
        throw new RuntimeException($conn->error . ' | SQL: ' . $sql);
    }
}

function m4_exec(string $sql, ...$params): void
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

function m4_column_exists(string $table, string $column): bool
{
    return (bool)fetch_one(
        "SELECT 1 FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1",
        $table,
        $column
    );
}

function m4_index_exists(string $table, string $index): bool
{
    return (bool)fetch_one(
        "SELECT 1 FROM information_schema.STATISTICS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ? LIMIT 1",
        $table,
        $index
    );
}

echo "Migration 004: Storefront Leads and Customer Numbers\n";
echo "Database: " . DB_NAME . "\n\n";

try {
    if (!m4_column_exists('contacts', 'customer_number')) {
        m4_query("ALTER TABLE `contacts` ADD COLUMN `customer_number` varchar(30) DEFAULT NULL AFTER `contact_type_id`");
        echo "  + contacts.customer_number\n";
    }
    if (!m4_index_exists('contacts', 'uq_contacts_customer_number')) {
        m4_query("ALTER TABLE `contacts` ADD UNIQUE KEY `uq_contacts_customer_number` (`tenant_id`, `customer_number`)");
        echo "  + unique contacts(tenant_id, customer_number)\n";
    }

    m4_query(
        "CREATE TABLE IF NOT EXISTS `storefront_lead_requests` (
            `request_id` int(10) unsigned NOT NULL AUTO_INCREMENT,
            `tenant_id` int(10) unsigned NOT NULL,
            `lead_id` int(10) unsigned NOT NULL,
            `currency_code` varchar(10) NOT NULL DEFAULT 'TZS',
            `subtotal` decimal(12,2) NOT NULL DEFAULT 0.00,
            `converted_order_id` int(10) unsigned DEFAULT NULL,
            `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
            `converted_at` datetime DEFAULT NULL,
            PRIMARY KEY (`request_id`),
            UNIQUE KEY `uq_storefront_request_lead` (`lead_id`),
            UNIQUE KEY `uq_storefront_request_tenant` (`request_id`, `tenant_id`),
            UNIQUE KEY `uq_storefront_request_order` (`converted_order_id`),
            KEY `idx_storefront_request_tenant` (`tenant_id`, `created_at`),
            CONSTRAINT `fk_sfr_lead` FOREIGN KEY (`lead_id`, `tenant_id`) REFERENCES `leads` (`lead_id`, `tenant_id`) ON DELETE CASCADE,
            CONSTRAINT `fk_sfr_order` FOREIGN KEY (`converted_order_id`) REFERENCES `orders` (`order_id`) ON DELETE SET NULL,
            CONSTRAINT `fk_sfr_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`tenant_id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
    echo "  + storefront_lead_requests\n";

    m4_query(
        "CREATE TABLE IF NOT EXISTS `storefront_lead_request_items` (
            `request_item_id` int(10) unsigned NOT NULL AUTO_INCREMENT,
            `tenant_id` int(10) unsigned NOT NULL,
            `request_id` int(10) unsigned NOT NULL,
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
            `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
            PRIMARY KEY (`request_item_id`),
            UNIQUE KEY `uq_storefront_request_line` (`request_id`, `tenant_id`, `line_no`),
            KEY `idx_storefront_request_product` (`tenant_id`, `product_id`),
            CONSTRAINT `fk_sfri_request` FOREIGN KEY (`request_id`, `tenant_id`) REFERENCES `storefront_lead_requests` (`request_id`, `tenant_id`) ON DELETE CASCADE,
            CONSTRAINT `fk_sfri_product` FOREIGN KEY (`product_id`) REFERENCES `products` (`product_id`) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
    echo "  + storefront_lead_request_items\n";

    $tenants = fetch_all("SELECT tenant_id FROM tenants ORDER BY tenant_id");
    foreach ($tenants as $tenant) {
        m4_exec(
            "INSERT IGNORE INTO document_sequences (tenant_id, sequence_key, next_value) VALUES (?, 'customer', 1)",
            (int)$tenant['tenant_id']
        );
    }
    echo "  + customer number sequences for " . count($tenants) . " tenant(s)\n";

    m4_exec("INSERT IGNORE INTO schema_migrations (migration) VALUES ('004_storefront_lead_requests')");
    echo "\nMigration 004 completed successfully.\n";
} catch (Throwable $ex) {
    fwrite(STDERR, "\nMigration 004 FAILED: " . $ex->getMessage() . "\n");
    exit(1);
}
