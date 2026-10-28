<?php
/**
 * Migration 009: Shop requests from the default Godown.
 *
 * SAFE / ADDITIVE:
 *   - Adds the request_godown_transfers permission.
 *   - Grants it to Administrator and Shop Manager roles where those roles
 *     already exist. No tables, stock, transfers or shop data are changed.
 *   - Administrators can grant or revoke it later on Roles & Permissions.
 *
 * Run from the command line only:
 *   php database/migrations/009_godown_shop_requests.php
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

function m9_exec(string $sql, ...$params): void
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

echo "Migration 009: Shop requests from the default Godown\n";
echo "Database: " . DB_NAME . "\n\n";

try {
    m9_exec(
        "INSERT INTO permissions (name, description) VALUES (?, ?)
         ON DUPLICATE KEY UPDATE description = VALUES(description)",
        'request_godown_transfers',
        'Request products from the default Godown for an assigned shop'
    );
    echo "  = permission request_godown_transfers\n";

    // Grant to the two built-in operating roles. Custom roles can be granted
    // access by an administrator on the Roles & Permissions page.
    m9_exec(
        "INSERT INTO role_permissions (role_id, permission_id)
         SELECT r.role_id, p.permission_id
           FROM roles r
           JOIN permissions p ON p.name = 'request_godown_transfers'
          WHERE LOWER(r.name) IN ('administrator', 'shop manager')
            AND NOT EXISTS (
                SELECT 1 FROM role_permissions rp
                 WHERE rp.role_id = r.role_id AND rp.permission_id = p.permission_id
            )"
    );
    echo "  = permission granted to Administrator and Shop Manager roles\n";

    m9_exec("INSERT IGNORE INTO schema_migrations (migration) VALUES ('009_godown_shop_requests')");
    echo "\n  = 009_godown_shop_requests recorded\n";
    echo "\nMigration 009 completed successfully. No stock or shop data was changed.\n";
} catch (Throwable $ex) {
    fwrite(STDERR, "\nMigration 009 FAILED: " . $ex->getMessage() . "\n");
    exit(1);
}
