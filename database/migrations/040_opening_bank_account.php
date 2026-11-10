<?php
/**
 * Migration 040: Allocate opening bank balances to a bank account.
 *
 * SAFE / ADDITIVE:
 *   - Adds `brand_capital_settings.opening_bank_name`.
 *   - Existing opening bank balances stay unallocated until an administrator
 *     selects the bank account on the Capital page.
 *
 * Run from the command line only:
 *   php database/migrations/040_opening_bank_account.php
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

echo "Migration 040: Opening bank account allocation\n";
echo "Database: " . DB_NAME . "\n\n";

try {
    $exists = fetch_one(
        "SELECT COUNT(*) c FROM information_schema.COLUMNS
          WHERE TABLE_SCHEMA = ? AND TABLE_NAME = 'brand_capital_settings' AND COLUMN_NAME = 'opening_bank_name'",
        DB_NAME
    );
    if ((int)($exists['c'] ?? 0) > 0) {
        echo "  = brand_capital_settings.opening_bank_name already exists\n";
    } else {
        $conn->query(
            "ALTER TABLE brand_capital_settings
             ADD COLUMN opening_bank_name VARCHAR(150) NULL AFTER opening_bank"
        );
        echo "  + brand_capital_settings.opening_bank_name added\n";
    }

    $stmt = $conn->prepare("INSERT IGNORE INTO schema_migrations (migration) VALUES (?)");
    $migration = '040_opening_bank_account';
    $stmt->bind_param('s', $migration);
    $stmt->execute();
    $stmt->close();
    echo "  = 040_opening_bank_account recorded\n";
    echo "\nMigration 040 completed successfully. No balances were changed.\n";
} catch (Throwable $ex) {
    fwrite(STDERR, "\nMigration 040 FAILED: " . $ex->getMessage() . "\n");
    exit(1);
}
