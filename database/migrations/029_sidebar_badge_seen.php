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

echo "Migration 029: Per-user sidebar badge acknowledgements\n";
echo "Database: " . DB_NAME . "\n";

try {
    $sql = "CREATE TABLE IF NOT EXISTS `sidebar_badge_seen` (
        `tenant_id` INT UNSIGNED NOT NULL,
        `user_id` INT UNSIGNED NOT NULL,
        `section` VARCHAR(40) NOT NULL,
        `seen_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`tenant_id`, `user_id`, `section`),
        CONSTRAINT `fk_sbs_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`tenant_id`) ON DELETE CASCADE,
        CONSTRAINT `fk_sbs_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

    if (!$conn->query($sql)) {
        throw new RuntimeException('Could not create sidebar_badge_seen: ' . $conn->error);
    }
    echo "  + sidebar_badge_seen table ready\n";

    $stmt = $conn->prepare(
        "INSERT IGNORE INTO schema_migrations (migration) VALUES ('029_sidebar_badge_seen')"
    );
    if (!$stmt) {
        throw new RuntimeException($conn->error);
    }
    if (!$stmt->execute()) {
        throw new RuntimeException($stmt->error);
    }
    $stmt->close();

    echo "Migration 029 completed.\n";
} catch (Throwable $ex) {
    fwrite(STDERR, "Migration 029 failed: " . $ex->getMessage() . "\n");
    exit(1);
}
