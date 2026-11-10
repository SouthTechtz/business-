<?php
/**
 * Rollback for migration 036: Brand-scoped Mini Administrators.
 *
 * Removes the two seeded Mini Admin logins and roles. The users.brand_scope
 * column is only dropped when no other account still uses a brand scope.
 *
 * Run from the command line only:
 *   php database/migrations/036_brand_administrators_rollback.php
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("This rollback can only be run from the command line.\n");
}

require_once __DIR__ . '/../../config.php';
while (ob_get_level() > 0) {
    ob_end_clean();
}

if (!in_array(DB_HOST, ['127.0.0.1', 'localhost', '::1'], true)) {
    fwrite(STDERR, "Refusing to run against a non-local database.\n");
    exit(1);
}

$usernames = ['gsp.miniadmin', 'techdokta.miniadmin'];
$role_names = ['Mini Admin - GSP', 'Mini Admin - TECHDOKTA'];

echo "Rollback 036: Brand-scoped Mini Administrators\n";
echo "Database: " . DB_NAME . "\n\n";

try {
    foreach (fetch_all("SELECT tenant_id FROM tenants ORDER BY tenant_id") as $tenant) {
        $tenant_id = (int)$tenant['tenant_id'];
        foreach ($usernames as $username) {
            $user = fetch_one(
                "SELECT user_id FROM users WHERE tenant_id = ? AND username = ? LIMIT 1",
                $tenant_id,
                $username
            );
            if (!$user) continue;
            $user_id = (int)$user['user_id'];
            foreach (['user_roles', 'user_shops', 'user_inventory_locations', 'user_godowns'] as $table) {
                $stmt = $conn->prepare("DELETE FROM `$table` WHERE tenant_id = ? AND user_id = ?");
                $stmt->bind_param('ii', $tenant_id, $user_id);
                $stmt->execute();
                $stmt->close();
            }
            $stmt = $conn->prepare("DELETE FROM users WHERE user_id = ? AND tenant_id = ?");
            $stmt->bind_param('ii', $user_id, $tenant_id);
            $stmt->execute();
            $stmt->close();
            echo "  - user '{$username}' removed from tenant {$tenant_id}\n";
        }

        foreach ($role_names as $role_name) {
            $role = fetch_one(
                "SELECT role_id FROM roles WHERE tenant_id = ? AND LOWER(name) = LOWER(?) LIMIT 1",
                $tenant_id,
                $role_name
            );
            if (!$role) continue;
            $role_id = (int)$role['role_id'];
            $stmt = $conn->prepare("DELETE FROM role_permissions WHERE role_id = ?");
            $stmt->bind_param('i', $role_id);
            $stmt->execute();
            $stmt->close();
            $stmt = $conn->prepare("DELETE FROM user_roles WHERE role_id = ?");
            $stmt->bind_param('i', $role_id);
            $stmt->execute();
            $stmt->close();
            $stmt = $conn->prepare("DELETE FROM roles WHERE role_id = ? AND tenant_id = ?");
            $stmt->bind_param('ii', $role_id, $tenant_id);
            $stmt->execute();
            $stmt->close();
            echo "  - role '{$role_name}' removed from tenant {$tenant_id}\n";
        }
    }

    $stmt = $conn->prepare("DELETE FROM schema_migrations WHERE migration = ?");
    $migration = '036_brand_administrators';
    $stmt->bind_param('s', $migration);
    $stmt->execute();
    $stmt->close();

    $remaining = fetch_one("SELECT COUNT(*) c FROM users WHERE brand_scope IS NOT NULL AND brand_scope <> ''");
    if ((int)($remaining['c'] ?? 0) === 0) {
        $column = fetch_one(
            "SELECT COUNT(*) c FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = ? AND TABLE_NAME = 'users' AND COLUMN_NAME = 'brand_scope'",
            DB_NAME
        );
        if ((int)($column['c'] ?? 0) > 0) {
            $conn->query("ALTER TABLE users DROP INDEX idx_users_brand_scope");
            $conn->query("ALTER TABLE users DROP COLUMN brand_scope");
            echo "  - users.brand_scope removed\n";
        }
    } else {
        echo "  = users.brand_scope kept: " . (int)$remaining['c'] . " other account(s) still use it\n";
    }

    echo "\nRollback 036 completed.\n";
} catch (Throwable $ex) {
    fwrite(STDERR, "\nRollback 036 FAILED: " . $ex->getMessage() . "\n");
    exit(1);
}
