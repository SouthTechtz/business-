<?php
/**
 * DEV-ONLY rollback for 006_order_stock_reservations.php.
 *
 * Drops the reservation ledger only. It never changes inventory_stock,
 * inventory_movements, orders, order_items, invoices, or POS records.
 *
 * Run only on a disposable local database:
 *   php database/migrations/006_order_stock_reservations_rollback.php --confirm=ROLLBACK_ORDER_RESERVATIONS
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("This rollback can only be run from the command line.\n");
}

if (($argv[1] ?? '') !== '--confirm=ROLLBACK_ORDER_RESERVATIONS') {
    fwrite(STDERR, "Refusing to run. Pass --confirm=ROLLBACK_ORDER_RESERVATIONS.\n");
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

function m6r_query(string $sql): void
{
    global $conn;
    if ($conn->query($sql) !== true) {
        throw new RuntimeException($conn->error . ' | SQL: ' . $sql);
    }
}

function m6r_exec(string $sql, ...$params): void
{
    global $conn;
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        throw new RuntimeException($conn->error . ' | SQL: ' . $sql);
    }
    if ($params) {
        $types = '';
        foreach ($params as $param) {
            $types .= is_int($param) ? 'i' : (is_float($param) ? 'd' : 's');
        }
        $stmt->bind_param($types, ...$params);
    }
    if (!$stmt->execute()) {
        $error = $stmt->error;
        $stmt->close();
        throw new RuntimeException($error . ' | SQL: ' . $sql);
    }
    $stmt->close();
}

echo "Rollback 006: Order Stock Reservations\n";
echo "Database: " . DB_NAME . "\n\n";

try {
    $exists = fetch_one(
        "SELECT 1 FROM information_schema.TABLES
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'order_stock_reservations' LIMIT 1"
    );
    if ($exists) {
        m6r_query("DROP TABLE `order_stock_reservations`");
        echo "  - order_stock_reservations\n";
    }
    m6r_exec("DELETE FROM schema_migrations WHERE migration = '006_order_stock_reservations'");
    echo "  - schema_migrations entry\n";
    echo "\nRollback 006 completed. No physical stock was changed.\n";
} catch (Throwable $ex) {
    fwrite(STDERR, "\nRollback 006 FAILED: " . $ex->getMessage() . "\n");
    exit(1);
}
