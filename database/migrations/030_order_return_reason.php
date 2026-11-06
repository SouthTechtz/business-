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

echo "Migration 030: Order return reason and sales reversal\n";
echo "Database: " . DB_NAME . "\n";

try {
    $columns = [];
    foreach (fetch_all("SHOW COLUMNS FROM orders") as $column) {
        $columns[(string)$column['Field']] = true;
    }

    $additions = [
        'return_reason'   => "ALTER TABLE orders ADD COLUMN `return_reason` VARCHAR(255) NULL AFTER `cancel_reason`",
        'returned_at'     => "ALTER TABLE orders ADD COLUMN `returned_at` DATETIME NULL AFTER `return_reason`",
        'returned_by'     => "ALTER TABLE orders ADD COLUMN `returned_by` INT UNSIGNED NULL AFTER `returned_at`",
        'returned_amount' => "ALTER TABLE orders ADD COLUMN `returned_amount` DECIMAL(15,2) NOT NULL DEFAULT 0.00 AFTER `returned_by`",
    ];

    foreach ($additions as $column => $sql) {
        if (isset($columns[$column])) {
            echo "  = orders.{$column} already exists\n";
            continue;
        }
        if (!$conn->query($sql)) {
            throw new RuntimeException("Could not add orders.{$column}: " . $conn->error);
        }
        echo "  + orders.{$column} added\n";
    }

    // Backfill existing returned orders so their payment reversal is visible.
    if (!$conn->query(
        "UPDATE orders
            SET returned_at = COALESCE(returned_at, updated_at, created_at),
                return_reason = COALESCE(NULLIF(return_reason, ''), 'Legacy return (reason not recorded)'),
                returned_amount = CASE WHEN returned_amount = 0 THEN COALESCE(Amount_paid, 0) ELSE returned_amount END
          WHERE stage = 'returned'"
    )) {
        throw new RuntimeException('Could not backfill returned orders: ' . $conn->error);
    }
    echo "  = returned orders backfilled\n";

    $stmt = $conn->prepare(
        "INSERT IGNORE INTO schema_migrations (migration) VALUES ('030_order_return_reason')"
    );
    if (!$stmt) {
        throw new RuntimeException($conn->error);
    }
    if (!$stmt->execute()) {
        throw new RuntimeException($stmt->error);
    }
    $stmt->close();

    echo "Migration 030 completed.\n";
} catch (Throwable $ex) {
    fwrite(STDERR, "Migration 030 failed: " . $ex->getMessage() . "\n");
    exit(1);
}
