<?php
/**
 * Migration 008: Storefront newsletter subscribers.
 *
 * SAFE / ADDITIVE:
 *   - Adds only storefront_subscribers.
 *   - Does not change catalogue, stock, orders, invoices or user tables.
 *
 * Run from the command line only:
 *   php database/migrations/008_storefront_subscribers.php
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

echo 'Database: ' . DB_NAME . ' at ' . DB_HOST . ':' . DB_PORT . PHP_EOL;

try {
    $conn->query(
        "CREATE TABLE IF NOT EXISTS storefront_subscribers (
            subscriber_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            tenant_id INT UNSIGNED NOT NULL,
            email VARCHAR(191) NOT NULL,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (subscriber_id),
            UNIQUE KEY uq_storefront_subscriber_email (tenant_id, email),
            KEY idx_storefront_subscriber_tenant (tenant_id, created_at),
            CONSTRAINT fk_storefront_subscriber_tenant FOREIGN KEY (tenant_id)
                REFERENCES tenants (tenant_id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $conn->query(
        "INSERT INTO schema_migrations (migration) VALUES ('008_storefront_subscribers')
         ON DUPLICATE KEY UPDATE migration = VALUES(migration)"
    );

    echo "Migration 008_storefront_subscribers applied.\n";
    echo "Rollback: php database/migrations/008_storefront_subscribers_rollback.php --confirm=ROLLBACK_STOREFRONT_SUBSCRIBERS\n";
} catch (Throwable $ex) {
    fwrite(STDERR, 'Migration failed: ' . $ex->getMessage() . PHP_EOL);
    exit(1);
}
