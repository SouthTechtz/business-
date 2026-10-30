<?php
/**
 * Migration 012: Quotations and default Central Godown.
 *
 * SAFE / ADDITIVE:
 *   - Creates isolated `quotations` and `quotation_items` tables. These tables
 *     are never joined by invoice, sales or report queries, so existing totals
 *     are unchanged.
 *   - Ensures every tenant has one active Central Godown. A Godown is an
 *     independent warehouse (`location_type = 'godown'`, `shop_id = NULL`) and
 *     is created empty. Existing Godowns are never renamed or modified.
 *   - No stock, sale, invoice or payment rows are changed.
 *
 * Run from the command line only:
 *   php database/migrations/012_quotations_default_godown.php
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

function m12_table_exists(string $table): bool
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

echo "Migration 012: Quotations and default Central Godown\n";
echo "Database: " . DB_NAME . "\n\n";

try {
    if (m12_table_exists('quotations')) {
        echo "  = quotations already exists\n";
    } else {
        $conn->query(
            "CREATE TABLE quotations (
                quotation_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                tenant_id INT UNSIGNED NOT NULL,
                quotation_number VARCHAR(100) NOT NULL,
                warehouse_id INT UNSIGNED DEFAULT NULL,
                contact_id INT UNSIGNED NOT NULL,
                issue_date DATE NOT NULL,
                valid_until DATE DEFAULT NULL,
                status ENUM('draft','sent','accepted','rejected','expired','converted') NOT NULL DEFAULT 'draft',
                reference VARCHAR(191) DEFAULT NULL,
                notes TEXT DEFAULT NULL,
                terms TEXT DEFAULT NULL,
                subtotal DECIMAL(12,2) NOT NULL DEFAULT 0.00,
                tax_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
                total DECIMAL(12,2) NOT NULL DEFAULT 0.00,
                converted_invoice_id INT UNSIGNED DEFAULT NULL,
                converted_at DATETIME DEFAULT NULL,
                created_by INT UNSIGNED DEFAULT NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (quotation_id),
                UNIQUE KEY uq_quotation_number (tenant_id, quotation_number),
                KEY idx_quotations_tenant_date (tenant_id, issue_date),
                KEY fk_q_wh (warehouse_id),
                KEY fk_q_contact (contact_id),
                KEY fk_q_invoice (converted_invoice_id),
                CONSTRAINT fk_q_tenant FOREIGN KEY (tenant_id) REFERENCES tenants (tenant_id) ON DELETE CASCADE,
                CONSTRAINT fk_q_wh FOREIGN KEY (warehouse_id) REFERENCES warehouses (warehouse_id) ON DELETE SET NULL,
                CONSTRAINT fk_q_contact FOREIGN KEY (contact_id) REFERENCES contacts (contact_id),
                CONSTRAINT fk_q_invoice FOREIGN KEY (converted_invoice_id) REFERENCES invoices (invoice_id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
        echo "  + quotations created\n";
    }

    if (m12_table_exists('quotation_items')) {
        echo "  = quotation_items already exists\n";
    } else {
        $conn->query(
            "CREATE TABLE quotation_items (
                quotation_item_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                tenant_id INT UNSIGNED NOT NULL,
                quotation_id INT UNSIGNED NOT NULL,
                warehouse_id INT UNSIGNED DEFAULT NULL,
                product_id INT UNSIGNED DEFAULT NULL,
                description VARCHAR(255) DEFAULT NULL,
                quantity DECIMAL(12,2) NOT NULL,
                unit_price DECIMAL(12,2) NOT NULL,
                tax_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
                subtotal DECIMAL(12,2) NOT NULL,
                total DECIMAL(12,2) NOT NULL,
                discount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
                PRIMARY KEY (quotation_item_id),
                KEY fk_qi_quotation (quotation_id),
                KEY fk_qi_product (product_id),
                KEY fk_qi_wh (warehouse_id),
                CONSTRAINT fk_qi_quotation FOREIGN KEY (quotation_id) REFERENCES quotations (quotation_id) ON DELETE CASCADE,
                CONSTRAINT fk_qi_product FOREIGN KEY (product_id) REFERENCES products (product_id) ON DELETE SET NULL,
                CONSTRAINT fk_qi_wh FOREIGN KEY (warehouse_id) REFERENCES warehouses (warehouse_id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
        echo "  + quotation_items created\n";
    }

    // Provision an empty default Godown for every tenant that has none.
    $tenants = $conn->query("SELECT tenant_id FROM tenants ORDER BY tenant_id");
    $created = 0;
    if ($tenants) {
        while ($tenant = $tenants->fetch_assoc()) {
            $tenant_id = (int)$tenant['tenant_id'];
            $stmt = $conn->prepare(
                "SELECT warehouse_id FROM warehouses
                  WHERE tenant_id = ? AND location_type = 'godown' AND is_active = 1
                  ORDER BY warehouse_id LIMIT 1"
            );
            $stmt->bind_param('i', $tenant_id);
            $stmt->execute();
            $existing = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if ($existing) {
                echo "  = tenant {$tenant_id} already has Godown #" . (int)$existing['warehouse_id'] . "\n";
                continue;
            }

            $n = 1;
            do {
                $code = 'GODOWN-' . str_pad((string)$n, 3, '0', STR_PAD_LEFT);
                $stmt = $conn->prepare("SELECT 1 FROM warehouses WHERE tenant_id = ? AND code = ?");
                $stmt->bind_param('is', $tenant_id, $code);
                $stmt->execute();
                $taken = $stmt->get_result()->fetch_assoc();
                $stmt->close();
                $n++;
            } while ($taken);

            $name = 'Central Godown';
            $stmt = $conn->prepare(
                "INSERT INTO warehouses (tenant_id, shop_id, name, code, location_type, is_active)
                 VALUES (?, NULL, ?, ?, 'godown', 1)"
            );
            $stmt->bind_param('iss', $tenant_id, $name, $code);
            $stmt->execute();
            $stmt->close();
            $created++;
            echo "  + tenant {$tenant_id}: empty default Godown {$code} created\n";
        }
    }
    echo "  = default Godown provisioning complete ({$created} created)\n";

    $stmt = $conn->prepare("INSERT IGNORE INTO schema_migrations (migration) VALUES (?)");
    $migration = '012_quotations_default_godown';
    $stmt->bind_param('s', $migration);
    $stmt->execute();
    $stmt->close();
    echo "\n  = 012_quotations_default_godown recorded\n";
    echo "\nMigration 012 completed successfully. No stock or financial rows were changed.\n";
} catch (Throwable $ex) {
    fwrite(STDERR, "\nMigration 012 FAILED: " . $ex->getMessage() . "\n");
    exit(1);
}
