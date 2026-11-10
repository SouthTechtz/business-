<?php
/**
 * Migration 036: Brand-scoped Mini Administrators.
 *
 * SAFE / ADDITIVE:
 *   - Adds `users.brand_scope` (nullable). When set, the account is a
 *     "Mini Admin" that can see and operate every module, but only for the
 *     shops, products, stock, sales and orders that belong to that brand.
 *   - Creates the roles `Mini Admin - GSP` and `Mini Admin - TECHDOKTA` with
 *     every operational permission except user, role, settings and shop
 *     configuration.
 *   - Creates one login account per brand when it does not already exist.
 *   - Does not change products, stock, orders, invoices, shops or sales.
 *
 * Run from the command line only:
 *   php database/migrations/036_brand_administrators.php
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

function m36_column_exists(string $table, string $column): bool
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

/** A readable temporary password for a newly created Mini Admin account. */
function m36_temp_password(): string
{
    $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789';
    $letters = '';
    for ($i = 0; $i < 10; $i++) {
        $letters .= $alphabet[random_int(0, strlen($alphabet) - 1)];
    }
    return $letters . random_int(10, 99) . '!';
}

$brand_accounts = [
    'GSP GROUP' => [
        'role'       => 'Mini Admin - GSP',
        'username'   => 'gsp.miniadmin',
        'email'      => 'gsp.miniadmin@local',
        'first_name' => 'GSP',
        'last_name'  => 'Mini Admin',
        'password'   => null,
    ],
    'TECHDOKTA INVESTMENT' => [
        'role'       => 'Mini Admin - TECHDOKTA',
        'username'   => 'techdokta.miniadmin',
        'email'      => 'techdokta.miniadmin@local',
        'first_name' => 'TECHDOKTA',
        'last_name'  => 'Mini Admin',
        'password'   => null,
    ],
];

// Every operational permission. Deliberately excluded:
// manage_users, manage_settings, manage_shops, manage_stores, manage_godown.
$mini_admin_permissions = [
    'manage_contacts',
    'manage_suppliers',
    'manage_products',
    'manage_inventory',
    'manage_pos',
    'manage_invoices',
    'manage_receipts',
    'view_reports',
    'view_dashboard',
    'manage_expenses',
    'view_activity_log',
    'manage_orders',
    'manage_leads',
    'view_godown',
    'approve_godown_transfers',
    'dispatch_godown_transfers',
    'receive_godown_transfers',
    'request_godown_transfers',
    'manage_purchases',
];

echo "Migration 036: Brand-scoped Mini Administrators\n";
echo "Database: " . DB_NAME . "\n\n";

try {
    if (m36_column_exists('users', 'brand_scope')) {
        echo "  = users.brand_scope already exists\n";
    } else {
        $conn->query("ALTER TABLE users ADD COLUMN brand_scope VARCHAR(100) NULL AFTER phone");
        echo "  + users.brand_scope added\n";
    }

    $index = fetch_one(
        "SELECT COUNT(*) c
           FROM information_schema.STATISTICS
          WHERE TABLE_SCHEMA = ? AND TABLE_NAME = 'users' AND INDEX_NAME = 'idx_users_brand_scope'",
        DB_NAME
    );
    if ((int)($index['c'] ?? 0) === 0) {
        $conn->query("ALTER TABLE users ADD INDEX idx_users_brand_scope (tenant_id, brand_scope)");
        echo "  + users brand scope index added\n";
    } else {
        echo "  = users brand scope index already exists\n";
    }

    // Permission ids by name (the permissions table is shared by all tenants).
    $permission_rows = fetch_all("SELECT permission_id, name FROM permissions");
    $permission_ids = [];
    foreach ($permission_rows as $row) {
        $permission_ids[(string)$row['name']] = (int)$row['permission_id'];
    }
    $grant_ids = [];
    $missing_permissions = [];
    foreach ($mini_admin_permissions as $name) {
        if (isset($permission_ids[$name])) {
            $grant_ids[] = $permission_ids[$name];
        } else {
            $missing_permissions[] = $name;
        }
    }
    if ($missing_permissions) {
        throw new RuntimeException(
            'These permissions are missing from the database: ' . implode(', ', $missing_permissions)
        );
    }

    $tenants = fetch_all("SELECT tenant_id FROM tenants ORDER BY tenant_id");
    foreach ($tenants as $tenant) {
        $tenant_id = (int)$tenant['tenant_id'];
        foreach ($brand_accounts as $brand => &$account) {
            $role_name = $account['role'];
            $role = fetch_one(
                "SELECT role_id FROM roles WHERE tenant_id = ? AND LOWER(name) = LOWER(?) LIMIT 1",
                $tenant_id,
                $role_name
            );
            if ($role) {
                $role_id = (int)$role['role_id'];
                echo "  = role '{$role_name}' already exists for tenant {$tenant_id}\n";
            } else {
                $role_id = (int)db_insert('roles', [
                    'tenant_id'   => $tenant_id,
                    'name'        => $role_name,
                    'description' => 'Brand administrator with full visibility of ' . $brand . ' only.',
                ]);
                if ($role_id <= 0) {
                    throw new RuntimeException("Could not create role {$role_name}.");
                }
                echo "  + role '{$role_name}' created for tenant {$tenant_id}\n";
            }

            foreach ($grant_ids as $permission_id) {
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
            }
            echo "    - {$role_name}: " . count($grant_ids) . " operational permissions granted\n";

            $existing_user = fetch_one(
                "SELECT user_id, brand_scope FROM users WHERE tenant_id = ? AND username = ? LIMIT 1",
                $tenant_id,
                $account['username']
            );
            if ($existing_user) {
                $user_id = (int)$existing_user['user_id'];
                if (trim((string)($existing_user['brand_scope'] ?? '')) === '') {
                    $stmt = $conn->prepare("UPDATE users SET brand_scope = ? WHERE user_id = ? AND tenant_id = ?");
                    $stmt->bind_param('sii', $brand, $user_id, $tenant_id);
                    $stmt->execute();
                    $stmt->close();
                    echo "  ~ {$account['username']} brand scope set to {$brand}\n";
                } else {
                    echo "  = {$account['username']} already exists\n";
                }
            } else {
                $account['password'] = m36_temp_password();
                $user_id = (int)db_insert('users', [
                    'tenant_id'     => $tenant_id,
                    'username'      => $account['username'],
                    'email'         => $account['email'],
                    'password_hash' => password_hash($account['password'], PASSWORD_DEFAULT),
                    'first_name'    => $account['first_name'],
                    'last_name'     => $account['last_name'],
                    'phone'         => null,
                    'brand_scope'   => $brand,
                    'is_active'     => 1,
                ]);
                if ($user_id <= 0) {
                    throw new RuntimeException("Could not create user {$account['username']}.");
                }
                echo "  + user '{$account['username']}' created for tenant {$tenant_id}\n";
            }

            $mapped = fetch_one(
                "SELECT 1 FROM user_roles WHERE user_id = ? AND role_id = ? AND tenant_id = ?",
                $user_id,
                $role_id,
                $tenant_id
            );
            if (!$mapped) {
                db_insert('user_roles', [
                    'user_id'   => $user_id,
                    'role_id'   => $role_id,
                    'tenant_id' => $tenant_id,
                ]);
            }

            // Brand shops are the visible shops for this account, so map them
            // explicitly as well. This keeps reports consistent even if the
            // brand-scope helper is ever bypassed.
            $brand_shops = fetch_all(
                "SELECT shop_id FROM shops WHERE tenant_id = ? AND brand = ?",
                $tenant_id,
                $brand
            );
            foreach ($brand_shops as $brand_shop) {
                $shop_id = (int)$brand_shop['shop_id'];
                $mapped_shop = fetch_one(
                    "SELECT 1 FROM user_shops WHERE tenant_id = ? AND user_id = ? AND shop_id = ?",
                    $tenant_id,
                    $user_id,
                    $shop_id
                );
                if (!$mapped_shop) {
                    db_insert('user_shops', [
                        'tenant_id' => $tenant_id,
                        'user_id'   => $user_id,
                        'shop_id'   => $shop_id,
                    ]);
                }
                $location_id = shop_inventory_warehouse_id($shop_id, $tenant_id);
                if ($location_id) {
                    $mapped_location = fetch_one(
                        "SELECT 1 FROM user_inventory_locations WHERE tenant_id = ? AND user_id = ? AND location_id = ?",
                        $tenant_id,
                        $user_id,
                        (int)$location_id
                    );
                    if (!$mapped_location) {
                        db_insert('user_inventory_locations', [
                            'tenant_id'   => $tenant_id,
                            'user_id'     => $user_id,
                            'location_id' => (int)$location_id,
                        ]);
                    }
                }
            }
        }
        unset($account);
    }




    $stmt = $conn->prepare("INSERT IGNORE INTO schema_migrations (migration) VALUES (?)");
    $migration = '036_brand_administrators';
    $stmt->bind_param('s', $migration);
    $stmt->execute();
    $stmt->close();
    echo "\n  = 036_brand_administrators recorded\n";

    echo "\nMigration 036 completed successfully. No stock or sales data was changed.\n";

    $new_credentials = [];
    foreach ($brand_accounts as $brand => $account) {
        if (!empty($account['password'])) {
            $new_credentials[] = sprintf(
                "  %-22s %-24s %s",
                $account['username'],
                $brand,
                $account['password']
            );
        }
    }
    if ($new_credentials) {
        echo "\nNew Mini Admin logins (change the password after first sign-in):\n";
        echo "  " . str_pad('USERNAME', 22) . str_pad('BRAND', 24) . "TEMPORARY PASSWORD\n";
        echo implode("\n", $new_credentials) . "\n";
    } else {
        echo "\nMini Admin logins already existed; no passwords were changed.\n";
    }
} catch (Throwable $ex) {
    fwrite(STDERR, "\nMigration 036 FAILED: " . $ex->getMessage() . "\n");
    exit(1);
}
