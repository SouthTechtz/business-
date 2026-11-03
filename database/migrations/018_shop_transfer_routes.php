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

echo "Migration 018: Enable Shop stock-transfer routes\n";
echo "Database: " . DB_NAME . "\n";

try {
    $types = [];
    foreach (['from_type', 'to_type'] as $column) {
        $stmt = $conn->prepare(
            "SELECT COLUMN_TYPE FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE()
                AND TABLE_NAME = 'transfer_routes'
                AND COLUMN_NAME = ?"
        );
        $stmt->bind_param('s', $column);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$row) {
            throw new RuntimeException("transfer_routes.$column does not exist.");
        }
        $types[$column] = strtolower((string)$row['COLUMN_TYPE']);
        if (!in_array($types[$column], [
            "enum('store','godown')",
            "enum('store','godown','shop')",
        ], true)) {
            throw new RuntimeException("Unexpected transfer_routes.$column definition: " . $types[$column]);
        }
    }

    if ($types['from_type'] !== "enum('store','godown','shop')"
        || $types['to_type'] !== "enum('store','godown','shop')") {
        if (!$conn->query(
            "ALTER TABLE transfer_routes
             MODIFY from_type ENUM('store','godown','shop') NOT NULL,
             MODIFY to_type ENUM('store','godown','shop') NOT NULL"
        )) {
            throw new RuntimeException($conn->error);
        }
        echo "  = Added Shop to both route-type enums\n";
    } else {
        echo "  = Route-type enums already support Shop\n";
    }

    $conn->begin_transaction();
    if (!$conn->query(
        "DELETE FROM transfer_routes
          WHERE NOT (
            (from_type = 'godown' AND to_type = 'store')
            OR (from_type = 'godown' AND to_type = 'shop')
            OR (from_type = 'store' AND to_type IN ('store', 'shop'))
            OR (from_type = 'shop' AND to_type = 'shop')
          )"
    )) {
        throw new RuntimeException($conn->error);
    }
    if (!$conn->query(
        "INSERT IGNORE INTO transfer_routes (from_type, to_type) VALUES
            ('godown', 'store'),
            ('godown', 'shop'),
            ('store', 'store'),
            ('store', 'shop'),
            ('shop', 'shop')"
    )) {
        throw new RuntimeException($conn->error);
    }

    $routes = $conn->query(
        "SELECT from_type, to_type FROM transfer_routes ORDER BY from_type, to_type"
    );
    $actual = [];
    while ($route = $routes->fetch_assoc()) {
        $actual[] = $route['from_type'] . '->' . $route['to_type'];
    }
    $expected = ['godown->shop', 'godown->store', 'shop->shop', 'store->shop', 'store->store'];
    sort($actual);
    sort($expected);
    if ($actual !== $expected) {
        throw new RuntimeException('The resulting transfer route matrix does not match the required rules.');
    }

    $stmt = $conn->prepare(
        "INSERT IGNORE INTO schema_migrations (migration) VALUES ('018_shop_transfer_routes')"
    );
    if (!$stmt->execute()) {
        throw new RuntimeException($stmt->error);
    }
    $stmt->close();
    $conn->commit();

    echo "  = Required route matrix installed and verified\n";
    echo "Migration 018 completed. No stock or transfer records were changed.\n";
} catch (Throwable $ex) {
    $conn->rollback();
    fwrite(STDERR, "Migration 018 failed: " . $ex->getMessage() . "\n");
    exit(1);
}
