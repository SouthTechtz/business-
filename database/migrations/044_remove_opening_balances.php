<?php
/**
 * Migration 044: Remove the opening-balance feature.
 *
 * DESTRUCTIVE:
 *   - Drops `shop_opening_balances` (shop opening sales).
 *   - Drops `brand_capital_settings` (brand opening cash / bank).
 *   - Drops `brand_opening_balances_removed_041` and `brand_opening_balances`
 *     if either still exists (retired brand opening sales).
 *
 * Opening balances are no longer used anywhere in the application: capital is
 * derived only from recorded capital movements, sales, expenses, purchases and
 * refunds, and stock value.
 *
 * Because the tables may still contain historical values, the migration aborts
 * unless it is run with --force when any of them has rows.
 *
 * Run from the command line only:
 *   php database/migrations/044_remove_opening_balances.php [--force]
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

echo "Migration 044: Remove the opening-balance feature\n";
echo "Database: " . DB_NAME . "\n\n";

$force = in_array('--force', $argv ?? [], true);
$tables = [
    'shop_opening_balances',
    'brand_capital_settings',
    'brand_opening_balances_removed_041',
    'brand_opening_balances',
];

try {
    $existing = [];
    $total_rows = 0;
    foreach ($tables as $table) {
        $found = fetch_one(
            "SELECT COUNT(*) c FROM information_schema.TABLES
              WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?",
            DB_NAME,
            $table
        );
        if ((int)($found['c'] ?? 0) === 0) {
            echo "  = {$table} is not present\n";
            continue;
        }
        $count = fetch_one("SELECT COUNT(*) c FROM `{$table}`");
        $rows = (int)($count['c'] ?? 0);
        $total_rows += $rows;
        $existing[] = $table;
        echo "  · {$table}: {$rows} row(s)\n";
    }

    if (!$existing) {
        echo "\nNothing to drop. Recording the migration.\n";
    } elseif ($total_rows > 0 && !$force) {
        fwrite(STDERR, "\nRefusing to drop tables that still contain data.\n");
        fwrite(STDERR, "Re-run with --force after taking a backup:\n");
        fwrite(STDERR, "  php database/migrations/044_remove_opening_balances.php --force\n");
        exit(1);
    }

    $conn->begin_transaction();
    foreach ($existing as $table) {
        $conn->query("DROP TABLE `{$table}`");
        echo "  - {$table} dropped\n";
    }
    $conn->commit();

    $stmt = $conn->prepare("INSERT IGNORE INTO schema_migrations (migration) VALUES (?)");
    $migration = '044_remove_opening_balances';
    $stmt->bind_param('s', $migration);
    $stmt->execute();
    $stmt->close();

    echo "\n  = 044_remove_opening_balances recorded\n";
    echo "Migration 044 completed successfully.\n";
} catch (Throwable $ex) {
    try { $conn->rollback(); } catch (Throwable $ignored) {}
    fwrite(STDERR, "\nMigration 044 FAILED: " . $ex->getMessage() . "\n");
    exit(1);
}
