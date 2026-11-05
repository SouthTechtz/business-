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

echo "Migration 026: System notifications\n";
echo "Database: " . DB_NAME . "\n";

try {
    $sql = "CREATE TABLE IF NOT EXISTS `notifications` (
        `notification_id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
        `tenant_id` INT UNSIGNED NOT NULL,
        `user_id` INT UNSIGNED NOT NULL,
        `actor_user_id` INT UNSIGNED NULL,
        `type` VARCHAR(50) NOT NULL DEFAULT 'system',
        `title` VARCHAR(191) NOT NULL,
        `message` TEXT NULL,
        `link` VARCHAR(255) NULL,
        `icon` VARCHAR(50) NOT NULL DEFAULT 'fa-bell',
        `dedupe_key` VARCHAR(191) NULL,
        `read_at` DATETIME NULL,
        `toast_seen_at` DATETIME NULL,
        `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`notification_id`),
        UNIQUE KEY `uniq_notification_dedupe` (`tenant_id`, `user_id`, `dedupe_key`),
        KEY `idx_notification_user` (`tenant_id`, `user_id`, `read_at`, `created_at`),
        KEY `idx_notification_toast` (`tenant_id`, `user_id`, `toast_seen_at`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

    if (!$conn->query($sql)) {
        throw new RuntimeException('Could not create notifications: ' . $conn->error);
    }
    echo "  + notifications table ready\n";

    // Wholesale notifications use the same bottom toast queue as staff alerts.
    $column = $conn->query(
        "SELECT COLUMN_NAME FROM information_schema.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE()
            AND TABLE_NAME = 'wholesale_notifications'
            AND COLUMN_NAME = 'toast_seen_at'
          LIMIT 1"
    );
    if ($column && $column->num_rows === 0) {
        if (!$conn->query("ALTER TABLE `wholesale_notifications` ADD COLUMN `toast_seen_at` DATETIME NULL AFTER `read_at`")) {
            throw new RuntimeException('Could not add toast_seen_at to wholesale_notifications: ' . $conn->error);
        }
        echo "  + wholesale_notifications.toast_seen_at added\n";
    } else {
        echo "  + wholesale_notifications.toast_seen_at already present\n";
    }

    $stmt = $conn->prepare(
        "INSERT IGNORE INTO schema_migrations (migration) VALUES ('026_system_notifications')"
    );
    if (!$stmt) {
        throw new RuntimeException($conn->error);
    }
    if (!$stmt->execute()) {
        throw new RuntimeException($stmt->error);
    }
    $stmt->close();

    echo "Migration 026 completed.\n";
} catch (Throwable $ex) {
    fwrite(STDERR, "Migration 026 failed: " . $ex->getMessage() . "\n");
    exit(1);
}
