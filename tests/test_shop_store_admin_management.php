<?php
require_once __DIR__ . '/../config.test.php';

function location_admin_assert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
    echo "PASS: {$message}\n";
}

$referenced_location = fetch_one(
    "SELECT w.warehouse_id, w.tenant_id
       FROM warehouses w
      WHERE w.location_type IN ('shop', 'store')
      ORDER BY w.warehouse_id
      LIMIT 1"
);
if (!$referenced_location) {
    echo "SKIP: no shop/store location fixture in test database.\n";
    exit(0);
}
location_admin_assert(
    location_reference_count(
        'warehouses',
        'warehouse_id',
        (int)$referenced_location['warehouse_id'],
        (int)$referenced_location['tenant_id']
    ) > 0,
    'existing shop/store dependencies prevent hard deletion'
);

$shop = fetch_one(
    "SELECT shop_id, tenant_id FROM shops ORDER BY shop_id LIMIT 1"
);
if ($shop) {
    $conn->begin_transaction();
    try {
        $shop_code = 'TEST-DELETE-' . strtoupper(bin2hex(random_bytes(4)));
        $stmt = $conn->prepare(
            "INSERT INTO shops (tenant_id, name, code, is_active)
             VALUES (?, 'Temporary deletion guard fixture', ?, 1)"
        );
        if (!$stmt) {
            throw new RuntimeException($conn->error);
        }
        $stmt->bind_param('is', $shop['tenant_id'], $shop_code);
        if (!$stmt->execute()) {
            throw new RuntimeException($stmt->error);
        }
        $empty_shop_id = (int)$stmt->insert_id;
        $stmt->close();
        location_admin_assert(
            location_reference_count('shops', 'shop_id', $empty_shop_id, (int)$shop['tenant_id'], 'warehouses') === 0,
            'unused shop has no blocking references'
        );

        $code = 'TEST-DELETE-' . strtoupper(bin2hex(random_bytes(4)));
        $stmt = $conn->prepare(
            "INSERT INTO warehouses (tenant_id, shop_id, name, code, is_active, location_type, is_godown)
             VALUES (?, ?, 'Temporary deletion guard fixture', ?, 1, 'store', 0)"
        );
        if (!$stmt) {
            throw new RuntimeException($conn->error);
        }
        $stmt->bind_param('iis', $shop['tenant_id'], $shop['shop_id'], $code);
        if (!$stmt->execute()) {
            throw new RuntimeException($stmt->error);
        }
        $empty_location_id = (int)$stmt->insert_id;
        $stmt->close();

        location_admin_assert(
            location_reference_count(
                'warehouses',
                'warehouse_id',
                $empty_location_id,
                (int)$shop['tenant_id']
            ) === 0,
            'unused store location has no blocking references'
        );
    } finally {
        $conn->rollback();
    }
}

$non_admin = fetch_one(
    "SELECT u.user_id
       FROM users u
      WHERE u.is_active = 1
        AND EXISTS (
            SELECT 1
              FROM user_roles ur
              JOIN role_permissions rp ON rp.role_id = ur.role_id
              JOIN permissions p ON p.permission_id = rp.permission_id
             WHERE ur.user_id = u.user_id AND ur.tenant_id = u.tenant_id
               AND p.name = 'manage_shops'
        )
        AND NOT EXISTS (
            SELECT 1
              FROM user_roles ur
              JOIN role_permissions rp ON rp.role_id = ur.role_id
              JOIN permissions p ON p.permission_id = rp.permission_id
             WHERE ur.user_id = u.user_id AND ur.tenant_id = u.tenant_id
               AND p.name IN ('manage_users', 'manage_settings')
        )
      ORDER BY u.user_id
      LIMIT 1"
);
if ($non_admin) {
    $GLOBALS['current_user'] = User::getUserById((int)$non_admin['user_id']);
    location_admin_assert(!tenant_admin_scope(), 'shop manager is excluded from admin-only location management');
} else {
    echo "SKIP: no non-administrator with manage_shops in test database.\n";
}

$admin = fetch_one(
    "SELECT u.user_id
       FROM users u
      WHERE u.is_active = 1
        AND EXISTS (
            SELECT 1
              FROM user_roles ur
              JOIN role_permissions rp ON rp.role_id = ur.role_id
              JOIN permissions p ON p.permission_id = rp.permission_id
             WHERE ur.user_id = u.user_id AND ur.tenant_id = u.tenant_id
               AND p.name IN ('manage_users', 'manage_settings')
        )
      ORDER BY u.user_id
      LIMIT 1"
);
if ($admin) {
    $GLOBALS['current_user'] = User::getUserById((int)$admin['user_id']);
    location_admin_assert(tenant_admin_scope(), 'system administrator can access location management');
} else {
    echo "SKIP: no system administrator fixture in test database.\n";
}

echo "Shop/store admin management test passed.\n";
