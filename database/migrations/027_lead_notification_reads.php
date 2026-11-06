<?php
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

echo "Migration 027: Per-user lead notification reads\n";
echo "Database: " . DB_NAME . "\n";

try {
    $sql = "CREATE TABLE IF NOT EXISTS `lead_notification_reads` (
        `tenant_id` INT UNSIGNED NOT NULL,
        `user_id` INT UNSIGNED NOT NULL,
        `lead_id` INT UNSIGNED NOT NULL,
        `read_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`tenant_id`, `user_id`, `lead_id`),
        KEY `idx_lnr_lead` (`tenant_id`, `lead_id`),
        CONSTRAINT `fk_lnr_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`tenant_id`) ON DELETE CASCADE,
        CONSTRAINT `fk_lnr_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE,
        CONSTRAINT `fk_lnr_lead` FOREIGN KEY (`lead_id`) REFERENCES `leads` (`lead_id`) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

    if (!$conn->query($sql)) {
        throw new RuntimeException('Could not create lead_notification_reads: ' . $conn->error);
    }
    echo "  + lead_notification_reads table ready\n";

    $stmt = $conn->prepare(
        "INSERT IGNORE INTO schema_migrations (migration) VALUES ('027_lead_notification_reads')"
    );
    if (!$stmt) {
        throw new RuntimeException($conn->error);
    }
    if (!$stmt->execute()) {
        throw new RuntimeException($stmt->error);
    }
    $stmt->close();

    echo "Migration 027 completed.\n";
} catch (Throwable $ex) {
    fwrite(STDERR, "Migration 027 failed: " . $ex->getMessage() . "\n");
    exit(1);
}
