<?php
require_once __DIR__ . '/../config.test.php';
require_once __DIR__ . '/../User.php';

function shop_transfer_assert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
    echo "PASS: {$message}\n";
}

$manager = fetch_one(
    "SELECT DISTINCT u.user_id, u.tenant_id
       FROM users u
       JOIN user_roles ur ON ur.user_id = u.user_id AND ur.tenant_id = u.tenant_id
       JOIN roles r ON r.role_id = ur.role_id AND r.tenant_id = ur.tenant_id
       JOIN role_permissions rp ON rp.role_id = r.role_id
       JOIN permissions p ON p.permission_id = rp.permission_id
      WHERE u.is_active = 1 AND p.name IN ('manage_inventory', 'manage_shops')
        AND (
            EXISTS (
                SELECT 1 FROM user_shops us
                 WHERE us.tenant_id = u.tenant_id AND us.user_id = u.user_id
            )
            OR EXISTS (
                SELECT 1 FROM user_inventory_locations uil
                JOIN warehouses w ON w.warehouse_id = uil.location_id AND w.tenant_id = uil.tenant_id
                 WHERE uil.tenant_id = u.tenant_id AND uil.user_id = u.user_id
                   AND w.location_type = 'shop'
            )
        )
      ORDER BY u.user_id
      LIMIT 1"
);
if (!$manager) {
    echo "SKIP: no assigned Shop user with inventory-management permission in test database.\n";
    exit(0);
}

$GLOBALS['current_user'] = User::getUserById((int)$manager['user_id']);
$current_user = $GLOBALS['current_user'];
$source = fetch_one(
    "SELECT w.warehouse_id, w.location_type, w.shop_id
       FROM warehouses w
      WHERE w.tenant_id = ? AND w.location_type = 'shop' AND w.is_active = 1
        AND (
            EXISTS (
                SELECT 1 FROM user_shops us
                 WHERE us.tenant_id = w.tenant_id AND us.user_id = ? AND us.shop_id = w.shop_id
            )
            OR EXISTS (
                SELECT 1 FROM user_inventory_locations uil
                 WHERE uil.tenant_id = w.tenant_id AND uil.user_id = ?
                   AND uil.location_id = w.warehouse_id
            )
        )
      ORDER BY w.warehouse_id
      LIMIT 1",
    (int)$manager['tenant_id'],
    (int)$manager['user_id'],
    (int)$manager['user_id']
);
shop_transfer_assert((bool)$source, 'manager has an assigned Shop inventory location');
shop_transfer_assert(can_verify_storefront_order_requests(), 'assigned Shop Manager can verify storefront order requests');
shop_transfer_assert(stock_transfer_destination_allowed('godown', 'shop'), 'Shop can request stock from a Godown');
shop_transfer_assert(stock_transfer_destination_allowed('store', 'shop'), 'Shop can request stock from a Store');
shop_transfer_assert(!stock_transfer_destination_allowed('shop', 'store'), 'Shop request source routes do not permit transfers from another Shop');
shop_transfer_assert(stock_transfer_can_approve_from_location($source), 'assigned Shop manager can approve outgoing transfers');
shop_transfer_assert(stock_transfer_can_dispatch_from_location($source), 'assigned Shop manager can dispatch outgoing transfers');
shop_transfer_assert(stock_transfer_can_cancel_from_location($source), 'assigned Shop manager can cancel undispatched outgoing transfers');
shop_transfer_assert(stock_transfer_can_receive_at_location($source), 'assigned Shop manager can receive incoming transfers');
shop_transfer_assert(stock_transfer_can_resolve_discrepancy($source, null), 'assigned Shop manager can resolve discrepancies for Shop transfers');

$shop_store = fetch_one(
    "SELECT warehouse_id, location_type, shop_id
       FROM warehouses
      WHERE tenant_id = ? AND shop_id = ? AND location_type = 'store' AND is_active = 1
      ORDER BY warehouse_id
      LIMIT 1",
    (int)$manager['tenant_id'],
    (int)$source['shop_id']
);
if ($shop_store) {
    shop_transfer_assert(stock_transfer_can_approve_from_location($shop_store), 'assigned Shop manager can approve transfers from its Store warehouse');
    shop_transfer_assert(stock_transfer_can_dispatch_from_location($shop_store), 'assigned Shop manager can dispatch transfers from its Store warehouse');
    shop_transfer_assert(stock_transfer_can_receive_at_location($shop_store), 'assigned Shop manager can receive transfers into its Store warehouse');
}

$transfer = [
    'tenant_id' => (int)$manager['tenant_id'],
    'source_warehouse_id' => (int)$source['warehouse_id'],
    'destination_shop_id' => 0,
];
shop_transfer_assert(stock_transfer_is_visible($transfer), 'assigned Shop manager can view outgoing transfers');

$godown = ['warehouse_id' => 0, 'location_type' => 'godown', 'shop_id' => null];
shop_transfer_assert(!stock_transfer_shop_staff_for_location($godown), 'Shop assignment does not grant Shop-location access to a Godown');

$store_staff = fetch_one(
    "SELECT DISTINCT u.user_id, u.tenant_id
       FROM users u
       JOIN user_roles ur ON ur.user_id = u.user_id AND ur.tenant_id = u.tenant_id
       JOIN roles r ON r.role_id = ur.role_id AND r.tenant_id = ur.tenant_id
       JOIN role_permissions rp ON rp.role_id = r.role_id
       JOIN permissions p ON p.permission_id = rp.permission_id
      WHERE u.is_active = 1 AND p.name IN ('manage_inventory', 'manage_shops')
        AND NOT EXISTS (
            SELECT 1
              FROM user_roles ur2
              JOIN roles r2 ON r2.role_id = ur2.role_id AND r2.tenant_id = ur2.tenant_id
              JOIN role_permissions rp2 ON rp2.role_id = r2.role_id
              JOIN permissions p2 ON p2.permission_id = rp2.permission_id
             WHERE ur2.user_id = u.user_id AND ur2.tenant_id = u.tenant_id
               AND p2.name IN ('manage_users', 'manage_settings')
        )
        AND NOT EXISTS (
            SELECT 1 FROM user_roles ur3
              JOIN roles r3 ON r3.role_id = ur3.role_id AND r3.tenant_id = ur3.tenant_id
             WHERE ur3.user_id = u.user_id AND ur3.tenant_id = u.tenant_id
               AND LOWER(r3.name) = 'godown manager'
        )
      ORDER BY u.user_id
      LIMIT 1"
);
if ($store_staff) {
    $store_only_location = fetch_one(
        "SELECT w.warehouse_id, w.location_type, w.shop_id
           FROM warehouses w
          WHERE w.tenant_id = ? AND w.location_type = 'store' AND w.is_active = 1
            AND NOT EXISTS (
                SELECT 1 FROM user_shops us
                 WHERE us.tenant_id = w.tenant_id AND us.user_id = ? AND us.shop_id = w.shop_id
            )
            AND NOT EXISTS (
                SELECT 1
                  FROM user_inventory_locations uil
                  JOIN warehouses assigned ON assigned.warehouse_id = uil.location_id
                                           AND assigned.tenant_id = uil.tenant_id
                 WHERE uil.tenant_id = w.tenant_id AND uil.user_id = ?
                   AND assigned.shop_id = w.shop_id AND assigned.location_type = 'shop'
            )
            AND NOT EXISTS (
                SELECT 1 FROM user_inventory_locations uil
                 WHERE uil.tenant_id = w.tenant_id AND uil.user_id = ?
                   AND uil.location_id = w.warehouse_id
            )
          ORDER BY w.warehouse_id
          LIMIT 1",
        (int)$store_staff['tenant_id'],
        (int)$store_staff['user_id'],
        (int)$store_staff['user_id'],
        (int)$store_staff['user_id']
    );
    if ($store_only_location) {
        $conn->begin_transaction();
        try {
            db_insert('user_inventory_locations', [
                'tenant_id' => (int)$store_staff['tenant_id'],
                'user_id' => (int)$store_staff['user_id'],
                'location_id' => (int)$store_only_location['warehouse_id'],
            ]);
            $GLOBALS['current_user'] = User::getUserById((int)$store_staff['user_id']);
            shop_transfer_assert(
                !shop_is_accessible((int)$store_only_location['shop_id'], true),
                'Store assignment does not grant access to the owning Shop'
            );
            shop_transfer_assert(
                stock_transfer_can_approve_from_location($store_only_location),
                'assigned Store manager can approve incoming Shop requests'
            );
            shop_transfer_assert(
                stock_transfer_can_dispatch_from_location($store_only_location),
                'assigned Store manager can dispatch Shop requests'
            );
            shop_transfer_assert(
                stock_transfer_is_visible([
                    'tenant_id' => (int)$store_staff['tenant_id'],
                    'source_warehouse_id' => (int)$store_only_location['warehouse_id'],
                    'destination_shop_id' => 0,
                ]),
                'assigned Store manager can see outgoing requests without access to the destination Shop'
            );
        } finally {
            $conn->rollback();
            $GLOBALS['current_user'] = User::getUserById((int)$manager['user_id']);
        }
    } else {
        echo "SKIP: no Store-only assignment candidate in test database.\n";
    }
}
