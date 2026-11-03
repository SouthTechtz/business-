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

echo "Migration 019: Remove unused product variants table\n";
echo "Database: " . DB_NAME . "\n";

try {
    if (!$conn->query(
        "CREATE TABLE IF NOT EXISTS schema_migrations (
            migration VARCHAR(191) NOT NULL,
            applied_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (migration)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    )) {
        throw new RuntimeException($conn->error);
    }

    $applied = fetch_one(
        "SELECT 1 FROM schema_migrations WHERE migration = ?",
        '019_remove_unused_product_variants'
    );
    if ($applied) {
        echo "Migration 019 is already applied.\n";
        exit(0);
    }

    $table = fetch_one(
        "SELECT 1 FROM information_schema.tables
          WHERE table_schema = DATABASE() AND table_name = 'product_variants'"
    );
    if ($table) {
        $count = fetch_one("SELECT COUNT(*) AS row_count FROM product_variants");
        $row_count = (int)($count['row_count'] ?? 0);
        if ($row_count > 0) {
            throw new RuntimeException(
                "product_variants contains {$row_count} row(s). No data was changed; "
                . 'the table was retained to preserve existing records.'
            );
        }
        if (!$conn->query("DROP TABLE product_variants")) {
            throw new RuntimeException($conn->error);
        }
        echo "  = Removed the empty product_variants table\n";
    } else {
        echo "  = product_variants is already absent\n";
    }

    $stmt = $conn->prepare(
        "INSERT INTO schema_migrations (migration) VALUES (?)"
    );
    if (!$stmt) {
        throw new RuntimeException($conn->error);
    }
    $migration = '019_remove_unused_product_variants';
    $stmt->bind_param('s', $migration);
    if (!$stmt->execute()) {
        $error = $stmt->error;
        $stmt->close();
        throw new RuntimeException($error);
    }
    $stmt->close();

    echo "Migration 019 completed. No product or stock records were changed.\n";
} catch (Throwable $ex) {
    fwrite(STDERR, "Migration 019 failed: " . $ex->getMessage() . "\n");
    exit(1);
}
