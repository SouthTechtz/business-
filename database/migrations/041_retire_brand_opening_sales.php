<?php
/**
 * Migration 041: Retire brand-level opening sales.
 *
 * The brand opening-sales feature has been removed from POS, sales, dashboard,
 * reports and backend helpers. The old table is renamed instead of dropped so
 * its historical values remain available for audit and are never used again.
 *
 * Run from the command line only:
 *   php database/migrations/041_retire_brand_opening_sales.php
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

echo "Migration 041: Retire brand opening sales\n";
echo "Database: " . DB_NAME . "\n\n";

try {
    $current = fetch_one(
        "SELECT COUNT(*) c FROM information_schema.TABLES
          WHERE TABLE_SCHEMA = ? AND TABLE_NAME = 'brand_opening_balances'",
        DB_NAME
    );
    if ((int)($current['c'] ?? 0) === 0) {
        echo "  = brand_opening_balances is not present\n";
    } else {
        $archived = fetch_one(
            "SELECT COUNT(*) c FROM information_schema.TABLES
              WHERE TABLE_SCHEMA = ? AND TABLE_NAME = 'brand_opening_balances_removed_041'",
            DB_NAME
        );
        if ((int)($archived['c'] ?? 0) > 0) {
            // Both tables exist: keep the old data but stop using the live name.
            $conn->query("DROP TABLE brand_opening_balances");
            echo "  - brand_opening_balances dropped (archive already existed)\n";
        } else {
            $conn->query("RENAME TABLE brand_opening_balances TO brand_opening_balances_removed_041");
            echo "  = brand_opening_balances renamed to brand_opening_balances_removed_041\n";
        }
    }

    $stmt = $conn->prepare("INSERT IGNORE INTO schema_migrations (migration) VALUES (?)");
    $migration = '041_retire_brand_opening_sales';
    $stmt->bind_param('s', $migration);
    $stmt->execute();
    $stmt->close();
    echo "  = 041_retire_brand_opening_sales recorded\n";
    echo "\nMigration 041 completed successfully. No sales or shop opening balances were changed.\n";
} catch (Throwable $ex) {
    fwrite(STDERR, "\nMigration 041 FAILED: " . $ex->getMessage() . "\n");
    exit(1);
}
