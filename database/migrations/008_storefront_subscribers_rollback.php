<?php
/**
 * DEV-ONLY rollback for 008_storefront_subscribers.php.
 *
 * Drops the storefront subscriber table only. It does not change catalogue,
 * stock, orders, leads, invoices or user data.
 *
 * Run only on a disposable local database:
 *   php database/migrations/008_storefront_subscribers_rollback.php --confirm=ROLLBACK_STOREFRONT_SUBSCRIBERS
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("This rollback can only be run from the command line.\n");
}

if (($argv[1] ?? '') !== '--confirm=ROLLBACK_STOREFRONT_SUBSCRIBERS') {
    fwrite(STDERR, "Refusing to run. Pass --confirm=ROLLBACK_STOREFRONT_SUBSCRIBERS.\n");
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

try {
    $conn->query("DROP TABLE IF EXISTS storefront_subscribers");
    $stmt = $conn->prepare("DELETE FROM schema_migrations WHERE migration = ?");
    $migration = '008_storefront_subscribers';
    $stmt->bind_param('s', $migration);
    $stmt->execute();
    $stmt->close();
    echo "Rollback complete. storefront_subscribers was dropped.\n";
} catch (Throwable $ex) {
    fwrite(STDERR, 'Rollback failed: ' . $ex->getMessage() . PHP_EOL);
    exit(1);
}
