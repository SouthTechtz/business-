<?php
/**
 * Migration 010: Fix the stock transfer route trigger collation.
 *
 * SAFE / REPAIR:
 *   - Recreates trg_transfers_route with explicit utf8mb4_unicode_ci
 *     variables so it cannot fail with "Illegal mix of collations" when the
 *     database default collation is utf8mb4_general_ci.
 *   - No tables, stock, transfers, shops or users are changed.
 *
 * Run from the command line only:
 *   php database/migrations/010_fix_transfer_route_collation.php
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

echo "Migration 010: Fix transfer route trigger collation\n";
echo "Database: " . DB_NAME . "\n\n";

try {
    $conn->query('DROP TRIGGER IF EXISTS trg_transfers_route');

    $trigger = <<<'SQL'
CREATE TRIGGER trg_transfers_route
BEFORE INSERT ON stock_transfers
FOR EACH ROW
BEGIN
  DECLARE src_type VARCHAR(10) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
  DECLARE dst_type VARCHAR(10) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
  DECLARE dst_shop INT UNSIGNED;
  DECLARE dst_active TINYINT;

  SELECT location_type INTO src_type
    FROM warehouses
   WHERE warehouse_id = NEW.source_warehouse_id AND tenant_id = NEW.tenant_id;

  SELECT location_type, shop_id, is_active INTO dst_type, dst_shop, dst_active
    FROM warehouses
   WHERE warehouse_id = NEW.destination_warehouse_id AND tenant_id = NEW.tenant_id;

  IF NEW.source_warehouse_id = NEW.destination_warehouse_id THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Source and destination must be different locations.';
  END IF;

  IF dst_active IS NULL OR dst_active <> 1 THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'The destination is not an active location.';
  END IF;

  IF NOT EXISTS (
    SELECT 1 FROM transfer_routes
     WHERE from_type = src_type COLLATE utf8mb4_unicode_ci
       AND to_type = dst_type COLLATE utf8mb4_unicode_ci
  ) THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'This transfer route is not allowed.';
  END IF;

  SET NEW.destination_shop_id = dst_shop;
END
SQL;

    if (!$conn->query($trigger)) {
        throw new RuntimeException($conn->error);
    }
    echo "  = trg_transfers_route recreated with explicit collations\n";

    $stmt = $conn->prepare("INSERT IGNORE INTO schema_migrations (migration) VALUES ('010_fix_transfer_route_collation')");
    $stmt->execute();
    $stmt->close();
    echo "\n  = 010_fix_transfer_route_collation recorded\n";
    echo "\nMigration 010 completed successfully. No stock or shop data was changed.\n";
} catch (Throwable $ex) {
    fwrite(STDERR, "\nMigration 010 FAILED: " . $ex->getMessage() . "\n");
    exit(1);
}
