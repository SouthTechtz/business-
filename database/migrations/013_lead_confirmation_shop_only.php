<?php
/**
 * Migration 013: Lead confirmation statuses and shop-only order linkage.
 *
 * SAFE / REPAIR:
 *   - Normalises legacy lead statuses (new/contacted/qualified/verified/
 *     converted/lost) to the statuses the application actually uses
 *     (pending/follow_up/confirmed/cancelled).
 *   - Ensures `leads.status` defaults to `pending`.
 *   - Ensures `orders.shop_id` exists as a nullable shop link, with its index
 *     and foreign key, so a confirmed lead can create an order without needing
 *     a store/warehouse selection.
 *   - `orders.warehouse_id` stays nullable: stock reservation is optional when
 *     a shop has no active store.
 *
 * No stock, invoice, payment or order amount is changed.
 *
 * Run from the command line only:
 *   php database/migrations/013_lead_confirmation_shop_only.php
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

function m13_table_exists(string $table): bool
{
    global $conn;
    $stmt = $conn->prepare(
        "SELECT COUNT(*) c
           FROM information_schema.TABLES
          WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?"
    );
    $schema = DB_NAME;
    $stmt->bind_param('ss', $schema, $table);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return (int)($row['c'] ?? 0) > 0;
}

function m13_column_exists(string $table, string $column): bool
{
    global $conn;
    $stmt = $conn->prepare(
        "SELECT COUNT(*) c
           FROM information_schema.COLUMNS
          WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ?"
    );
    $schema = DB_NAME;
    $stmt->bind_param('sss', $schema, $table, $column);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return (int)($row['c'] ?? 0) > 0;
}

function m13_index_exists(string $table, string $index): bool
{
    global $conn;
    $stmt = $conn->prepare(
        "SELECT COUNT(*) c
           FROM information_schema.STATISTICS
          WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND INDEX_NAME = ?"
    );
    $schema = DB_NAME;
    $stmt->bind_param('sss', $schema, $table, $index);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return (int)($row['c'] ?? 0) > 0;
}

function m13_fk_exists(string $table, string $constraint): bool
{
    global $conn;
    $stmt = $conn->prepare(
        "SELECT COUNT(*) c
           FROM information_schema.TABLE_CONSTRAINTS
          WHERE CONSTRAINT_SCHEMA = ? AND TABLE_NAME = ? AND CONSTRAINT_NAME = ?
            AND CONSTRAINT_TYPE = 'FOREIGN KEY'"
    );
    $schema = DB_NAME;
    $stmt->bind_param('sss', $schema, $table, $constraint);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return (int)($row['c'] ?? 0) > 0;
}

echo "Migration 013: Lead confirmation statuses and shop-only order linkage\n";
echo "Database: " . DB_NAME . "\n\n";

try {
    if (!m13_table_exists('leads')) {
        echo "  ! leads table is missing; run migration 003 first\n";
        exit(1);
    }

    echo "1) Normalise lead statuses\n";
    $status_map = [
        'new'       => 'pending',
        'verified'  => 'pending',
        'contacted' => 'follow_up',
        'qualified' => 'follow_up',
        'converted' => 'confirmed',
        'lost'      => 'cancelled',
    ];
    foreach ($status_map as $old => $new) {
        $stmt = $conn->prepare("UPDATE leads SET status = ? WHERE status = ?");
        $stmt->bind_param('ss', $new, $old);
        if (!$stmt->execute()) {
            throw new RuntimeException($stmt->error);
        }
        $changed = $stmt->affected_rows;
        $stmt->close();
        if ($changed > 0) {
            echo "  + {$old} -> {$new} ({$changed} lead(s))\n";
        }
    }

    $conn->query("ALTER TABLE leads MODIFY `status` varchar(20) NOT NULL DEFAULT 'pending'");
    echo "  = leads.status default is pending\n";

    // Older data was sometimes written with foreign-key checks disabled. A
    // missing contact must not block an otherwise valid lead confirmation.
    $conn->query(
        "UPDATE leads l
           LEFT JOIN contacts c ON c.contact_id = l.contact_id
            SET l.contact_id = NULL
          WHERE l.contact_id IS NOT NULL AND c.contact_id IS NULL"
    );
    echo "  + orphaned leads.contact_id repaired (" . (int)$conn->affected_rows . ")\n";
    if (m13_table_exists('orders')) {
        $conn->query(
            "UPDATE orders o
               LEFT JOIN contacts c ON c.contact_id = o.contact_id
                SET o.contact_id = NULL
              WHERE o.contact_id IS NOT NULL AND c.contact_id IS NULL"
        );
        echo "  + orphaned orders.contact_id repaired (" . (int)$conn->affected_rows . ")\n";
    }

    // Deleted products can leave request/order lines pointing at a missing
    // product. The line keeps its name/price snapshots, so detach the optional
    // product reference instead of blocking order creation.
    if (m13_table_exists('storefront_lead_request_items')) {
        $conn->query(
            "UPDATE storefront_lead_request_items i
               LEFT JOIN products p ON p.product_id = i.product_id
                SET i.product_id = NULL
              WHERE i.product_id IS NOT NULL AND p.product_id IS NULL"
        );
        echo "  + orphaned request item product references repaired (" . (int)$conn->affected_rows . ")\n";
    }
    if (m13_table_exists('order_items')) {
        $conn->query(
            "UPDATE order_items oi
               LEFT JOIN products p ON p.product_id = oi.product_id
                SET oi.product_id = NULL
              WHERE oi.product_id IS NOT NULL AND p.product_id IS NULL"
        );
        echo "  + orphaned order item product references repaired (" . (int)$conn->affected_rows . ")\n";
    }

    if (!m13_index_exists('leads', 'idx_leads_status')) {
        $conn->query("ALTER TABLE leads ADD KEY `idx_leads_status` (`tenant_id`, `status`)");
        echo "  + idx_leads_status\n";
    } else {
        echo "  = idx_leads_status already exists\n";
    }

    if (m13_table_exists('orders')) {
        echo "\n2) Orders shop link\n";
        if (!m13_column_exists('orders', 'shop_id')) {
            $conn->query("ALTER TABLE orders ADD COLUMN `shop_id` int(10) unsigned DEFAULT NULL AFTER `total`");
            echo "  + orders.shop_id added\n";
        } else {
            echo "  = orders.shop_id already exists\n";
        }

        if (!m13_index_exists('orders', 'idx_orders_shop')) {
            $conn->query("ALTER TABLE orders ADD KEY `idx_orders_shop` (`shop_id`, `tenant_id`, `status`)");
            echo "  + idx_orders_shop\n";
        } else {
            echo "  = idx_orders_shop already exists\n";
        }

        $shop_unique = $conn->query(
            "SELECT COUNT(*) c
               FROM information_schema.STATISTICS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'shops'
                AND INDEX_NAME = 'uq_shop_tenant' AND NON_UNIQUE = 0"
        );
        $has_shop_unique = $shop_unique && (int)($shop_unique->fetch_assoc()['c'] ?? 0) > 0;
        if (!$has_shop_unique) {
            $shop_unique = $conn->query(
                "SELECT COUNT(*) c
                   FROM information_schema.STATISTICS
                  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'shops'
                    AND COLUMN_NAME = 'tenant_id' AND NON_UNIQUE = 0
                  GROUP BY INDEX_NAME HAVING COUNT(*) = 2 LIMIT 1"
            );
            $has_shop_unique = $shop_unique && (int)($shop_unique->fetch_assoc()['c'] ?? 0) > 0;
        }

        if (!m13_fk_exists('orders', 'fk_cord_shop') && $has_shop_unique) {
            try {
                $conn->query(
                    "ALTER TABLE orders
                       ADD CONSTRAINT `fk_cord_shop` FOREIGN KEY (`shop_id`, `tenant_id`)
                       REFERENCES `shops` (`shop_id`, `tenant_id`)"
                );
                echo "  + fk_cord_shop\n";
            } catch (Throwable $ex) {
                echo "  ! could not add fk_cord_shop: " . $ex->getMessage() . "\n";
            }
        } else {
            echo "  = fk_cord_shop already exists or no matching shops unique key\n";
        }

        $wh_nullable = $conn->query(
            "SELECT IS_NULLABLE
               FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'orders' AND COLUMN_NAME = 'warehouse_id'"
        );
        $wh_row = $wh_nullable ? $wh_nullable->fetch_assoc() : null;
        if ($wh_row && strtoupper((string)$wh_row['IS_NULLABLE']) !== 'YES') {
            $conn->query("ALTER TABLE orders MODIFY `warehouse_id` int(10) unsigned DEFAULT NULL");
            echo "  + orders.warehouse_id made nullable\n";
        } else {
            echo "  = orders.warehouse_id is nullable\n";
        }
    }

    echo "\nMigration 013 completed successfully.\n";
} catch (Throwable $ex) {
    fwrite(STDERR, "\nMigration 013 failed: " . $ex->getMessage() . "\n");
    exit(1);
}
