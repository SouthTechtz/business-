<?php
/**
 * Rollback for migration 038: Bank sales deposits.
 * WARNING: this deletes every recorded sales banking transaction.
 *
 * Run from the command line only:
 *   php database/migrations/038_bank_sales_deposits_rollback.php
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("This rollback can only be run from the command line.\n");
}

require_once __DIR__ . '/../../config.php';
while (ob_get_level() > 0) {
    ob_end_clean();
}

if (!in_array(DB_HOST, ['127.0.0.1', 'localhost', '::1'], true)) {
    fwrite(STDERR, "Refusing to run against a non-local database.\n");
    exit(1);
}

echo "Rollback 038: Bank sales deposits\n";
try {
    $conn->query("DROP TABLE IF EXISTS bank_sales_deposits");
    echo "  - bank_sales_deposits removed\n";
    $stmt = $conn->prepare("DELETE FROM schema_migrations WHERE migration = ?");
    $migration = '038_bank_sales_deposits';
    $stmt->bind_param('s', $migration);
    $stmt->execute();
    $stmt->close();
    echo "\nRollback 038 completed.\n";
} catch (Throwable $ex) {
    fwrite(STDERR, "\nRollback 038 FAILED: " . $ex->getMessage() . "\n");
    exit(1);
}
