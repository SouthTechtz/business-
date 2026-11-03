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

echo "Migration 020: Allow Godown-to-Shop stock requests\n";
echo "Database: " . DB_NAME . "\n";

try {
    $route_types = fetch_one(
        "SELECT COUNT(*) AS route_columns
           FROM information_schema.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE()
            AND TABLE_NAME = 'transfer_routes'
            AND COLUMN_NAME IN ('from_type', 'to_type')"
    );
    if ((int)($route_types['route_columns'] ?? 0) !== 2) {
        throw new RuntimeException('The transfer_routes table is missing required columns.');
    }

    $conn->begin_transaction();
    $stmt = $conn->prepare(
        "INSERT IGNORE INTO transfer_routes (from_type, to_type) VALUES ('godown', 'shop')"
    );
    if (!$stmt) {
        throw new RuntimeException($conn->error);
    }
    if (!$stmt->execute()) {
        throw new RuntimeException($stmt->error);
    }
    $stmt->close();

    $route = fetch_one(
        "SELECT 1 FROM transfer_routes WHERE from_type = 'godown' AND to_type = 'shop'"
    );
    if (!$route) {
        throw new RuntimeException('Godown-to-Shop route was not installed.');
    }

    $stmt = $conn->prepare(
        "INSERT IGNORE INTO schema_migrations (migration) VALUES ('020_godown_to_shop_transfer_route')"
    );
    if (!$stmt) {
        throw new RuntimeException($conn->error);
    }
    if (!$stmt->execute()) {
        throw new RuntimeException($stmt->error);
    }
    $stmt->close();
    $conn->commit();

    echo "  = Godown-to-Shop transfer route installed and verified\n";
    echo "Migration 020 completed. No stock or transfer records were changed.\n";
} catch (Throwable $ex) {
    $conn->rollback();
    fwrite(STDERR, "Migration 020 failed: " . $ex->getMessage() . "\n");
    exit(1);
}
