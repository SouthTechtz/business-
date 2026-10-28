<?php
/**
 * Migration 005: Per-user read state for storefront request notifications.
 *
 * Run from the command line only:
 *   php database/migrations/005_storefront_request_notifications.php
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

try {
    $sql = "CREATE TABLE IF NOT EXISTS `storefront_request_notification_reads` (
        `tenant_id` int(10) unsigned NOT NULL,
        `user_id` int(10) unsigned NOT NULL,
        `last_seen_lead_id` int(10) unsigned NOT NULL DEFAULT 0,
        `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
        PRIMARY KEY (`tenant_id`, `user_id`),
        CONSTRAINT `fk_srn_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`tenant_id`) ON DELETE CASCADE,
        CONSTRAINT `fk_srn_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
    if ($conn->query($sql) !== true) {
        throw new RuntimeException($conn->error);
    }

    $stmt = $conn->prepare("INSERT IGNORE INTO schema_migrations (migration) VALUES (?)");
    if (!$stmt) throw new RuntimeException($conn->error);
    $migration = '005_storefront_request_notifications';
    $stmt->bind_param('s', $migration);
    if (!$stmt->execute()) throw new RuntimeException($stmt->error);
    $stmt->close();

    echo "Migration 005 completed successfully.\n";
} catch (Throwable $ex) {
    fwrite(STDERR, "Migration 005 FAILED: " . $ex->getMessage() . "\n");
    exit(1);
}
