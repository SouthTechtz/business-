<?php
/**
 * Rollback for migration 037: Brand capital funding.
 *
 * WARNING: this deletes all recorded capital movements.
 *
 * Run from the command line only:
 *   php database/migrations/037_capital_funding_rollback.php
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

echo "Rollback 037: Brand capital funding\n";
echo "Database: " . DB_NAME . "\n\n";

try {
    $conn->query("DROP TABLE IF EXISTS capital_movements");
    echo "  - capital_movements removed\n";

    $permission = fetch_one("SELECT permission_id FROM permissions WHERE name = 'manage_capital' LIMIT 1");
    if ($permission) {
        $permission_id = (int)$permission['permission_id'];
        $stmt = $conn->prepare("DELETE FROM role_permissions WHERE permission_id = ?");
        $stmt->bind_param('i', $permission_id);
        $stmt->execute();
        $stmt->close();
        $stmt = $conn->prepare("DELETE FROM permissions WHERE permission_id = ?");
        $stmt->bind_param('i', $permission_id);
        $stmt->execute();
        $stmt->close();
        echo "  - manage_capital permission removed\n";
    }

    $column = fetch_one(
        "SELECT COUNT(*) c FROM information_schema.COLUMNS
          WHERE TABLE_SCHEMA = ? AND TABLE_NAME = 'expenses' AND COLUMN_NAME = 'brand'",
        DB_NAME
    );
    if ((int)($column['c'] ?? 0) > 0) {
        $conn->query("ALTER TABLE expenses DROP INDEX idx_expenses_brand");
        $conn->query("ALTER TABLE expenses DROP COLUMN brand");
        echo "  - expenses.brand removed\n";
    }

    $stmt = $conn->prepare("DELETE FROM schema_migrations WHERE migration = ?");
    $migration = '037_capital_funding';
    $stmt->bind_param('s', $migration);
    $stmt->execute();
    $stmt->close();

    echo "\nRollback 037 completed.\n";
} catch (Throwable $ex) {
    fwrite(STDERR, "\nRollback 037 FAILED: " . $ex->getMessage() . "\n");
    exit(1);
}
