<?php
/**
 * DEV-ONLY rollback for 005_storefront_request_notifications.php.
 *
 * Run only on a disposable local database:
 *   php database/migrations/005_storefront_request_notifications_rollback.php --confirm=ROLLBACK_STOREFRONT_NOTIFICATIONS
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("This rollback can only be run from the command line.\n");
}
if (($argv[1] ?? '') !== '--confirm=ROLLBACK_STOREFRONT_NOTIFICATIONS') {
    fwrite(STDERR, "Refusing to run. Pass --confirm=ROLLBACK_STOREFRONT_NOTIFICATIONS after taking a verified backup.\n");
    exit(1);
}

require_once __DIR__ . '/../../config.php';
while (ob_get_level() > 0) {
    ob_end_clean();
}
if (!in_array(DB_HOST, ['127.0.0.1', 'localhost', '::1'], true)) {
    fwrite(STDERR, "Refusing to run against a non-local database.\n");
    exit(1);
}

if ($conn->query("DROP TABLE IF EXISTS `storefront_request_notification_reads`") !== true) {
    fwrite(STDERR, "Rollback 005 FAILED: " . $conn->error . "\n");
    exit(1);
}
$stmt = $conn->prepare("DELETE FROM schema_migrations WHERE migration = ?");
if (!$stmt) {
    fwrite(STDERR, "Rollback 005 FAILED: " . $conn->error . "\n");
    exit(1);
}
$migration = '005_storefront_request_notifications';
$stmt->bind_param('s', $migration);
if (!$stmt->execute()) {
    fwrite(STDERR, "Rollback 005 FAILED: " . $stmt->error . "\n");
    exit(1);
}
$stmt->close();
echo "Rollback 005 completed.\n";
