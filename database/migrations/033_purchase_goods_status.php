<?php
/**
 * Migration 033: Purchase goods status.
 *
 * SAFE / ADDITIVE:
 *   - Adds `purchases.status` for tracking where the goods are:
 *     ordered, in production, in transport, arrived at the Godown, cancelled.
 *   - Does NOT touch stock, inventory movements, payments or any existing row
 *     values beyond the new status default.
 *
 * Run from the command line only:
 *   php database/migrations/033_purchase_goods_status.php
 */

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

function m33_column_exists(string $table, string $column): bool
{
    global $conn;
    $stmt = $conn->prepare(
        "SELECT COUNT(*) c
           FROM information_schema.COLUMNS
          WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ?"
    );
    $schema = DB_NAME;
    $stmt->bind_param('sss', $schema, $table, $column);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return (int)($row['c'] ?? 0) > 0;
}

echo "Migration 033: Purchase goods status\n";
echo "Database: " . DB_NAME . "\n\n";

try {
    if (m33_column_exists('purchases', 'status')) {
        echo "  = purchases.status already exists\n";
    } else {
        $conn->query("ALTER TABLE purchases ADD COLUMN status VARCHAR(30) NOT NULL DEFAULT 'in_production' AFTER notes");
        echo "  + purchases.status added\n";
    }

    $index = fetch_one(
        "SELECT COUNT(*) c
           FROM information_schema.STATISTICS
          WHERE TABLE_SCHEMA = ? AND TABLE_NAME = 'purchases' AND INDEX_NAME = 'idx_purchases_status'",
        DB_NAME
    );
    if ((int)($index['c'] ?? 0) === 0) {
        $conn->query("ALTER TABLE purchases ADD INDEX idx_purchases_status (tenant_id, status)");
        echo "  + purchases status index added\n";
    } else {
        echo "  = purchases status index already exists\n";
    }

    $stmt = $conn->prepare("INSERT IGNORE INTO schema_migrations (migration) VALUES (?)");
    $migration = '033_purchase_goods_status';
    $stmt->bind_param('s', $migration);
    $stmt->execute();
    $stmt->close();
    echo "\n  = 033_purchase_goods_status recorded\n";
    echo "\nMigration 033 completed successfully. No stock or sales data was changed.\n";
} catch (Throwable $ex) {
    fwrite(STDERR, "\nMigration 033 FAILED: " . $ex->getMessage() . "\n");
    exit(1);
}
