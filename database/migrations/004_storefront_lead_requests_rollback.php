<?php
/**
 * DEV-ONLY rollback for 004_storefront_lead_requests.php.
 *
 * Removes storefront request history and all customer numbers added after
 * migration 004. Run only on a disposable local database after a backup:
 *   php database/migrations/004_storefront_lead_requests_rollback.php --confirm=ROLLBACK_STOREFRONT_LEADS
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("This rollback can only be run from the command line.\n");
}
if (($argv[1] ?? '') !== '--confirm=ROLLBACK_STOREFRONT_LEADS') {
    fwrite(STDERR, "Refusing to run. Pass --confirm=ROLLBACK_STOREFRONT_LEADS after taking a verified backup.\n");
    exit(1);
}

require_once __DIR__ . '/../../config.php';
while (ob_get_level() > 0) {
    ob_end_clean();
}

if (!in_array(DB_HOST, ['127.0.0.1', 'localhost', '::1'], true)) {
    fwrite(STDERR, "Refusing to run against a non-local database.\n");
    exit(1);
}

try {
    if ($conn->query("DROP TABLE IF EXISTS `storefront_lead_request_items`") !== true) {
        throw new RuntimeException($conn->error);
    }
    if ($conn->query("DROP TABLE IF EXISTS `storefront_lead_requests`") !== true) {
        throw new RuntimeException($conn->error);
    }

    $index = fetch_one(
        "SELECT 1 FROM information_schema.STATISTICS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'contacts'
           AND INDEX_NAME = 'uq_contacts_customer_number' LIMIT 1"
    );
    if ($index) {
        if ($conn->query("ALTER TABLE `contacts` DROP INDEX `uq_contacts_customer_number`") !== true) {
            throw new RuntimeException($conn->error);
        }
    }
    $column = fetch_one(
        "SELECT 1 FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'contacts'
           AND COLUMN_NAME = 'customer_number' LIMIT 1"
    );
    if ($column && $conn->query("ALTER TABLE `contacts` DROP COLUMN `customer_number`") !== true) {
        throw new RuntimeException($conn->error);
    }
    $stmt = $conn->prepare("DELETE FROM document_sequences WHERE sequence_key = ?");
    if (!$stmt) throw new RuntimeException($conn->error);
    $sequence_key = 'customer';
    $stmt->bind_param('s', $sequence_key);
    if (!$stmt->execute()) throw new RuntimeException($stmt->error);
    $stmt->close();
    $stmt = $conn->prepare("DELETE FROM schema_migrations WHERE migration = ?");
    if (!$stmt) throw new RuntimeException($conn->error);
    $migration = '004_storefront_lead_requests';
    $stmt->bind_param('s', $migration);
    if (!$stmt->execute()) throw new RuntimeException($stmt->error);
    $stmt->close();
    echo "Rollback 004 completed. Storefront request history and customer numbers were removed.\n";
} catch (Throwable $ex) {
    fwrite(STDERR, "Rollback 004 FAILED: " . $ex->getMessage() . "\n");
    exit(1);
}
