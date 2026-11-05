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

echo "Migration 025: Wholesale payment notifications\n";
echo "Database: " . DB_NAME . "\n";

try {
    $sql = "CREATE TABLE IF NOT EXISTS `wholesale_notifications` (
        `notification_id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
        `tenant_id` INT UNSIGNED NOT NULL,
        `user_id` INT UNSIGNED NOT NULL,
        `contact_id` INT UNSIGNED NULL,
        `order_id` INT UNSIGNED NULL,
        `type` VARCHAR(40) NOT NULL DEFAULT 'order_paid',
        `title` VARCHAR(191) NOT NULL,
        `message` TEXT NULL,
        `read_at` DATETIME NULL,
        `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`notification_id`),
        UNIQUE KEY `uniq_wholesale_notification` (`tenant_id`, `user_id`, `order_id`, `type`),
        KEY `idx_wholesale_notification_user` (`tenant_id`, `user_id`, `read_at`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

    if (!$conn->query($sql)) {
        throw new RuntimeException('Could not create wholesale_notifications: ' . $conn->error);
    }
    echo "  + wholesale_notifications table ready\n";

    // Backfill notifications for wholesale orders that are already paid in full.
    $backfill = "INSERT IGNORE INTO wholesale_notifications
        (tenant_id, user_id, contact_id, order_id, type, title, message, created_at)
        SELECT o.tenant_id, w.user_id, o.contact_id, o.order_id, 'order_paid',
               CONCAT('Payment received for ', o.order_number),
               'Full payment has been received for this order. Your receipt is ready to view or download.',
               NOW()
          FROM orders o
          JOIN wholesaler_accounts w
            ON w.contact_id = o.contact_id AND w.tenant_id = o.tenant_id
         WHERE o.total > 0 AND COALESCE(o.Amount_paid, 0) >= o.total";
    if (!$conn->query($backfill)) {
        throw new RuntimeException('Could not backfill wholesale notifications: ' . $conn->error);
    }
    echo "  + Existing paid wholesale orders checked\n";

    $stmt = $conn->prepare(
        "INSERT IGNORE INTO schema_migrations (migration) VALUES ('025_wholesale_payment_notifications')"
    );
    if (!$stmt) {
        throw new RuntimeException($conn->error);
    }
    if (!$stmt->execute()) {
        throw new RuntimeException($stmt->error);
    }
    $stmt->close();

    echo "Migration 025 completed.\n";
} catch (Throwable $ex) {
    fwrite(STDERR, "Migration 025 failed: " . $ex->getMessage() . "\n");
    exit(1);
}
