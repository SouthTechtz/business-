<?php
/**
 * Migration 021: Wholesale customer accounts.
 *
 * Creates the wholesaler profile table and ensures each tenant has a
 * "Wholesaler" role (no staff permissions).
 *
 * Run from the command line only:
 *   php database/migrations/021_wholesale_accounts.php
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

echo "Migration 021: Wholesale customer accounts\n";
echo "Database: " . DB_NAME . "\n\n";

try {
    $conn->query(
        "CREATE TABLE IF NOT EXISTS `wholesaler_accounts` (
            `wholesaler_id` int(10) unsigned NOT NULL AUTO_INCREMENT,
            `tenant_id` int(10) unsigned NOT NULL,
            `user_id` int(10) unsigned NOT NULL,
            `contact_id` int(10) unsigned DEFAULT NULL,
            `business_name` varchar(191) NOT NULL,
            `business_type` varchar(100) DEFAULT NULL,
            `contact_name` varchar(191) NOT NULL,
            `phone` varchar(50) DEFAULT NULL,
            `email` varchar(191) DEFAULT NULL,
            `location` varchar(255) DEFAULT NULL,
            `monthly_volume` varchar(100) DEFAULT NULL,
            `product_interest` varchar(255) DEFAULT NULL,
            `notes` text DEFAULT NULL,
            `status` varchar(20) NOT NULL DEFAULT 'active',
            `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
            `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
            PRIMARY KEY (`wholesaler_id`),
            UNIQUE KEY `uq_wholesaler_user` (`user_id`),
            UNIQUE KEY `uq_wholesaler_contact` (`tenant_id`, `contact_id`),
            KEY `idx_wholesaler_tenant_status` (`tenant_id`, `status`),
            CONSTRAINT `fk_wholesaler_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`tenant_id`) ON DELETE CASCADE,
            CONSTRAINT `fk_wholesaler_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE,
            CONSTRAINT `fk_wholesaler_contact` FOREIGN KEY (`contact_id`) REFERENCES `contacts` (`contact_id`) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
    echo "  + wholesaler_accounts\n";

    $roles = fetch_all("SELECT tenant_id FROM tenants");
    $created = 0;
    foreach ($roles as $tenant) {
        $tenant_id = (int)$tenant['tenant_id'];
        $existing = fetch_one(
            "SELECT role_id FROM roles WHERE tenant_id = ? AND LOWER(name) = 'wholesaler' LIMIT 1",
            $tenant_id
        );
        if ($existing) continue;
        $stmt = $conn->prepare("INSERT INTO roles (tenant_id, name, description) VALUES (?, 'Wholesaler', 'Wholesale customer portal access')");
        if (!$stmt) throw new RuntimeException($conn->error);
        $stmt->bind_param('i', $tenant_id);
        $stmt->execute();
        $stmt->close();
        $created++;
    }
    echo "  + Wholesaler role for {$created} tenant(s)\n";
    echo "\nMigration 021 completed.\n";
} catch (Throwable $exception) {
    fwrite(STDERR, "Migration failed: " . $exception->getMessage() . "\n");
    exit(1);
}
