<?php
/**
 * Migration 006: Transactional stock reservations for Orders.
 *
 * SAFE / ADDITIVE:
 *   - Adds order_stock_reservations only.
 *   - Does not alter inventory_stock, inventory_movements, invoices, POS tables,
 *     or any existing physical stock quantity.
 *
 * A reservation is created when an order is verified (or confirmed for a
 * staff-created order) and is either consumed when the order is fulfilled by
 * an invoice or released when the order is cancelled/rejected. Physical stock
 * continues to move only through the existing invoice and POS stock-deduction
 * paths.
 *
 * Run from the command line only:
 *   php database/migrations/006_order_stock_reservations.php
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

function m6_query(string $sql): void
{
    global $conn;
    if ($conn->query($sql) !== true) {
        throw new RuntimeException($conn->error . ' | SQL: ' . $sql);
    }
}

function m6_exec(string $sql, ...$params): void
{
    global $conn;
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        throw new RuntimeException($conn->error . ' | SQL: ' . $sql);
    }
    if ($params) {
        $types = '';
        foreach ($params as $param) {
            $types .= is_int($param) ? 'i' : (is_float($param) ? 'd' : 's');
        }
        $stmt->bind_param($types, ...$params);
    }
    if (!$stmt->execute()) {
        $error = $stmt->error;
        $stmt->close();
        throw new RuntimeException($error . ' | SQL: ' . $sql);
    }
    $stmt->close();
}

echo "Migration 006: Order Stock Reservations\n";
echo "Database: " . DB_NAME . "\n\n";

try {
    m6_query(
        "CREATE TABLE IF NOT EXISTS `order_stock_reservations` (
            `reservation_id` int(10) unsigned NOT NULL AUTO_INCREMENT,
            `tenant_id` int(10) unsigned NOT NULL,
            `order_id` int(10) unsigned NOT NULL,
            `order_item_id` int(10) unsigned NOT NULL,
            `product_id` int(10) unsigned NOT NULL,
            `warehouse_id` int(10) unsigned NOT NULL,
            `quantity` decimal(12,2) NOT NULL,
            `status` enum('active','consumed','released') NOT NULL DEFAULT 'active',
            `consumed_invoice_id` int(10) unsigned DEFAULT NULL,
            `created_by` int(10) unsigned DEFAULT NULL,
            `release_reason` varchar(255) DEFAULT NULL,
            `released_at` datetime DEFAULT NULL,
            `consumed_at` datetime DEFAULT NULL,
            `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
            `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
            PRIMARY KEY (`reservation_id`),
            UNIQUE KEY `uq_order_item_reservation` (`order_item_id`),
            UNIQUE KEY `uq_reservation_tenant` (`reservation_id`, `tenant_id`),
            KEY `idx_reservation_stock` (`tenant_id`, `warehouse_id`, `product_id`, `status`),
            KEY `idx_reservation_order` (`tenant_id`, `order_id`, `status`),
            CONSTRAINT `fk_osr_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`tenant_id`) ON DELETE CASCADE,
            CONSTRAINT `fk_osr_order` FOREIGN KEY (`order_id`, `tenant_id`) REFERENCES `orders` (`order_id`, `tenant_id`) ON DELETE CASCADE,
            CONSTRAINT `fk_osr_order_item` FOREIGN KEY (`order_item_id`) REFERENCES `order_items` (`order_item_id`) ON DELETE CASCADE,
            CONSTRAINT `fk_osr_product` FOREIGN KEY (`product_id`) REFERENCES `products` (`product_id`) ON DELETE CASCADE,
            CONSTRAINT `fk_osr_warehouse` FOREIGN KEY (`warehouse_id`) REFERENCES `warehouses` (`warehouse_id`) ON DELETE CASCADE,
            CONSTRAINT `fk_osr_invoice` FOREIGN KEY (`consumed_invoice_id`) REFERENCES `invoices` (`invoice_id`) ON DELETE SET NULL,
            CONSTRAINT `fk_osr_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
    echo "  + order_stock_reservations\n";

    m6_exec("INSERT IGNORE INTO schema_migrations (migration) VALUES ('006_order_stock_reservations')");
    echo "  = 006_order_stock_reservations recorded\n";

    echo "\nMigration 006 completed successfully.\n";
} catch (Throwable $ex) {
    fwrite(STDERR, "\nMigration 006 FAILED: " . $ex->getMessage() . "\n");
    exit(1);
}
