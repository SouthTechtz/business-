<?php
/**
 * Migration 037: Brand capital funding and balance sheet support.
 *
 * SAFE / ADDITIVE:
 *   - Creates `capital_movements` for brand capital contributions and
 *     withdrawals (bank name, amount, date, reference, notes).
 *   - Adds `expenses.brand` so each brand's expenses can be reported
 *     separately, and backfills it from the creator's brand scope.
 *   - Adds the `manage_capital` permission and grants it to the
 *     Administrator and both Mini Admin roles.
 *   - Does not change stock, sales, invoices, orders or existing amounts.
 *
 * Run from the command line only:
 *   php database/migrations/037_capital_funding.php
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

function m37_column_exists(string $table, string $column): bool
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

echo "Migration 037: Brand capital funding\n";
echo "Database: " . DB_NAME . "\n\n";

try {
    $conn->query(
        "CREATE TABLE IF NOT EXISTS capital_movements (
            movement_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            tenant_id INT UNSIGNED NOT NULL,
            brand VARCHAR(100) NOT NULL,
            movement_type ENUM('contribution', 'withdrawal') NOT NULL DEFAULT 'contribution',
            bank_name VARCHAR(150) NOT NULL,
            amount DECIMAL(14,2) NOT NULL DEFAULT 0.00,
            entry_date DATE NOT NULL,
            reference VARCHAR(100) NULL,
            notes TEXT NULL,
            created_by INT UNSIGNED NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (movement_id),
            KEY idx_capital_tenant_brand (tenant_id, brand, entry_date),
            KEY idx_capital_tenant_date (tenant_id, entry_date),
            KEY idx_capital_created_by (created_by)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
    echo "  = capital_movements ready\n";

    if (m37_column_exists('expenses', 'brand')) {
        echo "  = expenses.brand already exists\n";
    } else {
        $conn->query("ALTER TABLE expenses ADD COLUMN brand VARCHAR(100) NULL AFTER payment_method_id");
        echo "  + expenses.brand added\n";
    }

    $index = fetch_one(
        "SELECT COUNT(*) c FROM information_schema.STATISTICS
          WHERE TABLE_SCHEMA = ? AND TABLE_NAME = 'expenses' AND INDEX_NAME = 'idx_expenses_brand'",
        DB_NAME
    );
    if ((int)($index['c'] ?? 0) === 0) {
        $conn->query("ALTER TABLE expenses ADD INDEX idx_expenses_brand (tenant_id, brand, expense_date)");
        echo "  + expenses brand index added\n";
    } else {
        echo "  = expenses brand index already exists\n";
    }

    // Attribute existing expenses to the creator's brand when possible.
    $conn->query(
        "UPDATE expenses e
           JOIN users u ON u.user_id = e.created_by AND u.tenant_id = e.tenant_id
            SET e.brand = u.brand_scope
          WHERE (e.brand IS NULL OR e.brand = '')
            AND u.brand_scope IS NOT NULL AND u.brand_scope <> ''"
    );
    echo "  = existing expenses attributed to their creator's brand\n";

    $stmt = $conn->prepare(
        "INSERT INTO permissions (name, description)
         SELECT 'manage_capital', 'Add and manage brand capital funding and view the capital balance sheet'
          WHERE NOT EXISTS (SELECT 1 FROM permissions WHERE name = 'manage_capital')"
    );
    $stmt->execute();
    $stmt->close();
    $permission = fetch_one("SELECT permission_id FROM permissions WHERE name = 'manage_capital' LIMIT 1");
    if (!$permission) {
        throw new RuntimeException('Could not create the manage_capital permission.');
    }
    $permission_id = (int)$permission['permission_id'];
    echo "  = permission manage_capital ready (#{$permission_id})\n";

    $role_names = ['Administrator', 'Mini Admin - GSP', 'Mini Admin - TECHDOKTA'];
    $tenants = fetch_all("SELECT tenant_id FROM tenants ORDER BY tenant_id");
    foreach ($tenants as $tenant) {
        $tenant_id = (int)$tenant['tenant_id'];
        foreach ($role_names as $role_name) {
            $role = fetch_one(
                "SELECT role_id FROM roles WHERE tenant_id = ? AND LOWER(name) = LOWER(?) LIMIT 1",
                $tenant_id,
                $role_name
            );
            if (!$role) continue;
            $role_id = (int)$role['role_id'];
            $exists = fetch_one(
                "SELECT 1 FROM role_permissions WHERE role_id = ? AND permission_id = ?",
                $role_id,
                $permission_id
            );
            if ($exists) continue;
            db_insert('role_permissions', [
                'role_id'       => $role_id,
                'permission_id' => $permission_id,
            ]);
            echo "  + manage_capital granted to '{$role_name}' (tenant {$tenant_id})\n";
        }
    }

    $stmt = $conn->prepare("INSERT IGNORE INTO schema_migrations (migration) VALUES (?)");
    $migration = '037_capital_funding';
    $stmt->bind_param('s', $migration);
    $stmt->execute();
    $stmt->close();
    echo "\n  = 037_capital_funding recorded\n";
    echo "\nMigration 037 completed successfully. No stock or sales data was changed.\n";
} catch (Throwable $ex) {
    fwrite(STDERR, "\nMigration 037 FAILED: " . $ex->getMessage() . "\n");
    exit(1);
}
