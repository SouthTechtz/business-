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

echo "Migration 031: Allow order-source sales returns\n";
echo "Database: " . DB_NAME . "\n";

try {
    $column = fetch_one(
        "SELECT COLUMN_TYPE
           FROM information_schema.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'sales_returns' AND COLUMN_NAME = 'source_type'
          LIMIT 1"
    );
    $type = (string)($column['COLUMN_TYPE'] ?? '');

    if (stripos($type, "'order'") === false) {
        if (!$conn->query(
            "ALTER TABLE sales_returns
                MODIFY COLUMN `source_type` ENUM('pos','invoice','order') NOT NULL DEFAULT 'pos'"
        )) {
            throw new RuntimeException('Could not extend sales_returns.source_type: ' . $conn->error);
        }
        echo "  + sales_returns.source_type now includes order\n";
    } else {
        echo "  = sales_returns.source_type already includes order\n";
    }

    // Backfill sales-return records for orders already marked as returned.
    $conn->begin_transaction();
    try {
        $returned_orders = fetch_all(
            "SELECT order_id, tenant_id, Amount_paid, created_by
               FROM orders
              WHERE stage = 'returned'"
        );
        $created = 0;
        foreach ($returned_orders as $order) {
            $existing = fetch_one(
                "SELECT return_id FROM sales_returns
                  WHERE tenant_id = ? AND source_type = 'order' AND source_id = ?
                  LIMIT 1",
                (int)$order['tenant_id'],
                (int)$order['order_id']
            );
            if ($existing) {
                continue;
            }
            sync_order_sales_return(
                (int)$order['order_id'],
                (int)$order['tenant_id'],
                (int)($order['created_by'] ?? 0),
                (float)($order['Amount_paid'] ?? 0)
            );
            $created++;
        }
        $conn->commit();
        echo "  + {$created} existing returned order(s) linked to sales returns\n";
    } catch (Throwable $ex) {
        $conn->rollback();
        throw $ex;
    }

    $stmt = $conn->prepare(
        "INSERT IGNORE INTO schema_migrations (migration) VALUES ('031_order_sales_returns')"
    );
    if (!$stmt) {
        throw new RuntimeException($conn->error);
    }
    if (!$stmt->execute()) {
        throw new RuntimeException($stmt->error);
    }
    $stmt->close();

    echo "Migration 031 completed.\n";
} catch (Throwable $ex) {
    fwrite(STDERR, "Migration 031 failed: " . $ex->getMessage() . "\n");
    exit(1);
}
