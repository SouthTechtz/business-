<?php
/**
 * Migration 022: Customer segmentation (retail / wholesale).
 *
 * Adds customer_type and customer_channel to contacts and backfills existing
 * records. Wholesale contacts that already have a portal profile become
 * wholesale; retail contacts with storefront history become online.
 *
 * Run from the command line only:
 *   php database/migrations/022_customer_types.php
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

function m22_column_exists(string $table, string $column): bool
{
    return (bool)fetch_one(
        "SELECT 1 FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1",
        $table,
        $column
    );
}

function m22_index_exists(string $table, string $index): bool
{
    return (bool)fetch_one(
        "SELECT 1 FROM information_schema.STATISTICS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ? LIMIT 1",
        $table,
        $index
    );
}

echo "Migration 022: Customer segmentation\n";
echo "Database: " . DB_NAME . "\n\n";

try {
    if (!m22_column_exists('contacts', 'customer_type')) {
        $conn->query("ALTER TABLE `contacts` ADD COLUMN `customer_type` varchar(20) NOT NULL DEFAULT 'retail' AFTER `contact_type_id`");
        echo "  + contacts.customer_type\n";
    }
    if (!m22_column_exists('contacts', 'customer_channel')) {
        $conn->query("ALTER TABLE `contacts` ADD COLUMN `customer_channel` varchar(20) DEFAULT 'walk_in' AFTER `customer_type`");
        echo "  + contacts.customer_channel\n";
    }
    if (!m22_index_exists('contacts', 'idx_contacts_customer_type')) {
        $conn->query("ALTER TABLE `contacts` ADD KEY `idx_contacts_customer_type` (`tenant_id`, `customer_type`)");
        echo "  + index contacts(tenant_id, customer_type)\n";
    }
    if (!m22_index_exists('contacts', 'idx_contacts_customer_channel')) {
        $conn->query("ALTER TABLE `contacts` ADD KEY `idx_contacts_customer_channel` (`tenant_id`, `customer_channel`)");
        echo "  + index contacts(tenant_id, customer_channel)\n";
    }

    // Wholesale portal contacts are wholesale regardless of previous defaults.
    $has_wholesaler_table = fetch_one(
        "SELECT 1 FROM information_schema.TABLES
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'wholesaler_accounts' LIMIT 1"
    );
    if ($has_wholesaler_table) {
        $conn->query(
            "UPDATE contacts c
               JOIN wholesaler_accounts w ON w.contact_id = c.contact_id AND w.tenant_id = c.tenant_id
                SET c.customer_type = 'wholesale',
                    c.customer_channel = CASE WHEN c.customer_channel IS NULL THEN 'online' ELSE c.customer_channel END"
        );
        echo "  ~ wholesale contacts marked from portal accounts\n";
    }

    // Retail contacts with online storefront history become online customers.
    $conn->query(
        "UPDATE contacts c
            SET c.customer_channel = 'online'
          WHERE c.customer_type = 'retail'
            AND EXISTS (
                SELECT 1 FROM leads l
                 WHERE l.contact_id = c.contact_id
                   AND l.tenant_id = c.tenant_id
                   AND l.source = 'storefront'
            )"
    );
    echo "  ~ retail contacts with storefront history marked online\n";

    // Everything else stays retail / walk_in (the column defaults).
    $counts = fetch_one(
        "SELECT SUM(customer_type = 'retail' AND customer_channel = 'online') AS online_retail,
                SUM(customer_type = 'wholesale') AS wholesale,
                COUNT(*) AS total
           FROM contacts"
    );
    echo "  = customers: total " . (int)($counts['total'] ?? 0)
        . ", retail/online " . (int)($counts['online_retail'] ?? 0)
        . ", wholesale " . (int)($counts['wholesale'] ?? 0) . "\n";

    echo "\nMigration 022 completed.\n";
} catch (Throwable $exception) {
    fwrite(STDERR, "Migration failed: " . $exception->getMessage() . "\n");
    exit(1);
}
