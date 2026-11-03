<?php
require_once __DIR__ . '/../config.test.php';
require_once __DIR__ . '/../User.php';

function godown_manager_assert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
    echo "PASS: {$message}\n";
}

$manager = fetch_one(
    "SELECT u.user_id, u.tenant_id
       FROM users u
       JOIN user_roles ur ON ur.user_id = u.user_id AND ur.tenant_id = u.tenant_id
       JOIN roles r ON r.role_id = ur.role_id AND r.tenant_id = ur.tenant_id
      WHERE u.is_active = 1 AND LOWER(TRIM(r.name)) = 'godown manager'
      ORDER BY u.user_id
      LIMIT 1"
);
if (!$manager) {
    echo "SKIP: no active Godown Manager is assigned in the test database.\n";
    exit(0);
}

$GLOBALS['current_user'] = User::getUserById((int)$manager['user_id']);
$current_user = $GLOBALS['current_user'];
$assigned_ids = godown_manager_location_ids((int)$manager['tenant_id'], false);

godown_manager_assert(is_godown_manager(), 'Godown Manager role is recognized');
godown_manager_assert(can('manage_godown'), 'Godown management permission remains available');
godown_manager_assert(can('manage_inventory'), 'Godown inventory permission remains available');
godown_manager_assert(!can('manage_contacts'), 'unrelated customer permissions are restricted');
godown_manager_assert(!can('manage_products'), 'product administration permissions are restricted');
godown_manager_assert(
    can('manage_expenses') === $current_user->hasPermission('manage_expenses'),
    'expense access follows the assigned role permissions'
);
godown_manager_assert(
    can('manage_purchases') === $current_user->hasPermission('manage_purchases'),
    'purchase access follows the assigned role permissions'
);
godown_manager_assert(
    can('manage_suppliers') === $current_user->hasPermission('manage_suppliers'),
    'supplier access follows the assigned role permissions'
);
godown_manager_assert(
    can('manage_invoices') === $current_user->hasPermission('manage_invoices'),
    'invoice access follows the assigned role permissions'
);
godown_manager_assert(!tenant_admin_scope(), 'Godown Manager does not get tenant-wide administrator scope');
godown_manager_assert(godown_manager_page_allowed('godown.php'), 'Godown page is allowed');
godown_manager_assert(godown_manager_page_allowed('ajax-godown-product-search.php'), 'Godown product search is allowed');
godown_manager_assert(godown_manager_page_allowed('inventory.php'), 'Godown inventory page is allowed');
godown_manager_assert(godown_manager_page_allowed('expenses.php'), 'Godown expenses page is allowed');
godown_manager_assert(godown_manager_page_allowed('export-expenses.php'), 'Godown expense export is allowed');
godown_manager_assert(godown_manager_page_allowed('purchases.php'), 'Godown purchases page is allowed');
godown_manager_assert(godown_manager_page_allowed('purchase-add.php'), 'Godown purchase creation is allowed');
godown_manager_assert(godown_manager_page_allowed('purchase-edit.php'), 'Godown purchase editing is allowed');
godown_manager_assert(godown_manager_page_allowed('purchase-view.php'), 'Godown purchase details are allowed');
godown_manager_assert(godown_manager_page_allowed('purchase-delete.php'), 'Godown purchase deletion route is allowed');
godown_manager_assert(godown_manager_page_allowed('suppliers.php'), 'Godown suppliers page is allowed');
godown_manager_assert(godown_manager_page_allowed('invoices.php'), 'Godown invoices page is allowed');
godown_manager_assert(godown_manager_page_allowed('invoice-add.php'), 'Godown invoice creation is allowed');
godown_manager_assert(godown_manager_page_allowed('quotations.php'), 'Godown quotations page is allowed');
godown_manager_assert(godown_manager_page_allowed('quotation-add.php'), 'Godown quotation creation is allowed');
godown_manager_assert(godown_manager_page_allowed('inventory-adjust.php'), 'Godown stock adjustment page is allowed');
godown_manager_assert(!godown_manager_page_allowed('customers.php'), 'unrelated pages are blocked');

$locations = accessible_locations(false, (int)$manager['tenant_id']);
godown_manager_assert((bool)$assigned_ids, 'manager has an active assigned Godown');
godown_manager_assert((bool)$locations, 'assigned Godown is visible');
godown_manager_assert(
    count(array_filter($locations, fn($location) => (string)$location['location_type'] !== 'godown')) === 0,
    'accessible locations contain no Shop or Store'
);
godown_manager_assert(
    count(array_diff(array_map(fn($location) => (int)$location['warehouse_id'], $locations), $assigned_ids)) === 0,
    'accessible locations are limited to assigned Godowns'
);
godown_manager_assert(
    count(array_diff(
        array_map(fn($location) => (int)$location['warehouse_id'], accessible_stores(null, false, null, true)),
        $assigned_ids
    )) === 0,
    'invoice and quotation locations are limited to assigned Godowns'
);
godown_manager_assert(!accessible_shops(null, false), 'Shop list is hidden from the Godown Manager');
godown_manager_assert(!accessible_stores(null, false), 'Store list is hidden from the Godown Manager');

$active_godown = find_active_godown((int)$manager['tenant_id']);
godown_manager_assert(
    $active_godown && in_array((int)$active_godown['warehouse_id'], $assigned_ids, true),
    'active Godown resolution stays within assigned locations'
);
