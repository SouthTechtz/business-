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

echo "Migration 024: Wholesale order receipt confirmation\n";
echo "Database: " . DB_NAME . "\n";

try {
    $existing = [];
    $columns = $conn->query(
        "SELECT COLUMN_NAME FROM information_schema.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'orders'"
    );
    while ($column = $columns->fetch_assoc()) {
        $existing[$column['COLUMN_NAME']] = true;
    }

    $additions = [
        'customer_confirmed_at' => 'ALTER TABLE orders ADD COLUMN customer_confirmed_at DATETIME NULL AFTER fulfilled_at',
        'customer_confirmed_by' => 'ALTER TABLE orders ADD COLUMN customer_confirmed_by INT NULL AFTER customer_confirmed_at',
        'customer_confirmation_note' => 'ALTER TABLE orders ADD COLUMN customer_confirmation_note VARCHAR(255) NULL AFTER customer_confirmed_by',
    ];

    foreach ($additions as $name => $sql) {
        if (isset($existing[$name])) {
            echo "  = orders.$name already exists\n";
            continue;
        }
        if (!$conn->query($sql)) {
            throw new RuntimeException("Could not add orders.$name: " . $conn->error);
        }
        echo "  + Added orders.$name\n";
    }

    $stmt = $conn->prepare(
        "INSERT IGNORE INTO schema_migrations (migration) VALUES ('024_wholesale_order_receipt_confirmation')"
    );
    if (!$stmt) {
        throw new RuntimeException($conn->error);
    }
    if (!$stmt->execute()) {
        throw new RuntimeException($stmt->error);
    }
    $stmt->close();

    echo "Migration 024 completed.\n";
} catch (Throwable $ex) {
    fwrite(STDERR, "Migration 024 failed: " . $ex->getMessage() . "\n");
    exit(1);
}
