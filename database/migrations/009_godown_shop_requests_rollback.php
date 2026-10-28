<?php
/**
 * DEV-ONLY rollback for 009_godown_shop_requests.php.
 *
 * Removes only the request_godown_transfers permission and its role grants.
 * It does not change shops, stores, stock, transfers or users.
 *
 * Run only on a disposable local database:
 *   php database/migrations/009_godown_shop_requests_rollback.php --confirm=ROLLBACK_GODOWN_REQUESTS
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("This rollback can only be run from the command line.\n");
}

if (($argv[1] ?? '') !== '--confirm=ROLLBACK_GODOWN_REQUESTS') {
    fwrite(STDERR, "Refusing to run. Pass --confirm=ROLLBACK_GODOWN_REQUESTS.\n");
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
    $conn->query(
        "DELETE rp FROM role_permissions rp
           JOIN permissions p ON p.permission_id = rp.permission_id
          WHERE p.name = 'request_godown_transfers'"
    );
    $conn->query("DELETE FROM permissions WHERE name = 'request_godown_transfers'");

    $stmt = $conn->prepare("DELETE FROM schema_migrations WHERE migration = ?");
    $migration = '009_godown_shop_requests';
    $stmt->bind_param('s', $migration);
    $stmt->execute();
    $stmt->close();

    echo "Rollback complete. request_godown_transfers was removed.\n";
} catch (Throwable $ex) {
    fwrite(STDERR, 'Rollback failed: ' . $ex->getMessage() . PHP_EOL);
    exit(1);
}
