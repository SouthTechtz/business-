<?php
/**
 * Shared reporting foundation for DEZL BUSINESS SYSTEM.
 */

function report_tenant_id() {
    global $current_user;
    return $current_user ? (int)$current_user->tenant_id : 0;
}

function report_valid_date($value) {
    if (!is_string($value) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
        return false;
    }
    $parts = explode('-', $value);
    return checkdate((int)$parts[1], (int)$parts[2], (int)$parts[0]);
}

function report_resolve_period(array $input) {
    $period = isset($input['period']) ? trim((string)$input['period']) : '';
    $from = isset($input['from']) ? trim((string)$input['from']) : '';
    $to = isset($input['to']) ? trim((string)$input['to']) : '';
    $errors = [];

    switch ($period) {
        case 'today':
            $from = $to = date('Y-m-d');
            break;
        case 'week':
            $from = date('Y-m-d', strtotime('monday this week'));
            $to = date('Y-m-d', strtotime('sunday this week'));
            break;
        case 'month':
            $from = date('Y-m-01');
            $to = date('Y-m-t');
            break;
        case 'year':
            $from = date('Y-01-01');
            $to = date('Y-12-31');
            break;
        case 'all':
            $from = $to = '';
            break;
        default:
            if ($from !== '' && !report_valid_date($from)) {
                $from = '';
                $errors[] = 'The "from" date must be a valid calendar date in YYYY-MM-DD format.';
            }
            if ($to !== '' && !report_valid_date($to)) {
                $to = '';
                $errors[] = 'The "to" date must be a valid calendar date in YYYY-MM-DD format.';
            }
            break;
    }

    if ($from !== '' && $to !== '' && $from > $to) {
        $errors[] = 'The "from" date must be on or before the "to" date.';
        $from = $to = '';
    }

    return [
        'period' => $period,
        'from' => $from,
        'to' => $to,
        'error' => implode(' ', $errors),
    ];
}

function report_date_condition($column, $from, $to) {
    $column = preg_replace('/[^a-zA-Z0-9_.]/', '', (string)$column);
    if ($from !== '' && $to !== '') {
        return ['sql' => " AND DATE($column) BETWEEN ? AND ?", 'params' => [$from, $to]];
    }
    if ($from !== '') {
        return ['sql' => " AND DATE($column) >= ?", 'params' => [$from]];
    }
    if ($to !== '') {
        return ['sql' => " AND DATE($column) <= ?", 'params' => [$to]];
    }
    return ['sql' => '', 'params' => []];
}
function report_accessible_warehouse_ids($include_inactive = true) {
    return accessible_warehouse_ids(null, $include_inactive, false);
}

function report_accessible_sales_warehouse_ids($include_inactive = true) {
    if (tenant_admin_scope()) {
        return null;
    }

    // Sales locations must always be active Shop inventory locations. Store
    // access grants inventory access only and never makes a Store a sales location.
    $ids = [];
    foreach (accessible_shops(null, false) as $shop) {
        $location_id = shop_inventory_warehouse_id((int)$shop['shop_id']);
        if ($location_id) {
            $ids[] = (int)$location_id;
        }
    }

    return array_values(array_unique($ids));
}

function report_sales_warehouse_condition($alias, $column, $include_inactive = true) {
    $alias = preg_replace('/[^a-zA-Z0-9_]/', '', (string)$alias);
    $column = preg_replace('/[^a-zA-Z0-9_]/', '', (string)$column);

    if (tenant_admin_scope()) {
        return [
            'sql' => " AND ($alias.$column IS NULL OR EXISTS (
                SELECT 1 FROM warehouses rw
                JOIN shops rs ON rs.shop_id = rw.shop_id AND rs.tenant_id = rw.tenant_id
                WHERE rw.warehouse_id = $alias.$column
                  AND rw.tenant_id = $alias.tenant_id
                  AND rw.location_type = 'shop'
                  AND rw.is_active = 1
                  AND rs.is_active = 1
            ))",
            'params' => [],
        ];
    }

    $ids = report_accessible_sales_warehouse_ids($include_inactive);
    if (!is_array($ids) || !$ids) {
        return ['sql' => ' AND 1 = 0', 'params' => []];
    }

    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    return [
        'sql' => " AND $alias.$column IN ($placeholders)",
        'params' => array_values($ids),
    ];
}

/**
 * Safe inventory-location condition using the existing unified location access.
 * Optionally preserves rows with no location (for example products with no stock).
 */
function report_inventory_location_condition($alias, $column, $include_inactive = true, $include_null = false) {
    $alias = preg_replace('/[^a-zA-Z0-9_]/', '', (string)$alias);
    $column = preg_replace('/[^a-zA-Z0-9_]/', '', (string)$column);

    if (tenant_admin_scope()) {
        return ['sql' => '', 'params' => []];
    }

    $ids = [];
    foreach (accessible_locations($include_inactive) as $location) {
        $locationId = (int)$location['warehouse_id'];
        if (find_accessible_store($locationId, $include_inactive, true)) {
            $ids[] = $locationId;
        }
    }
    $ids = array_values(array_unique($ids));
    if (!$ids) {
        return ['sql' => $include_null ? " AND $alias.$column IS NULL" : ' AND 1 = 0', 'params' => []];
    }

    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $sql = " AND ($alias.$column IN ($placeholders)";
    if ($include_null) $sql .= " OR $alias.$column IS NULL";
    $sql .= ')';

    return ['sql' => $sql, 'params' => $ids];
}

function report_pos_completed_statuses() {
    return ['completed'];
}

function report_pos_cancelled_statuses() {
    return ['cancelled'];
}

function report_invoice_active_statuses() {
    return ['Draft', 'Sent', 'Partially Paid', 'Paid', 'Overdue'];
}

function report_invoice_excluded_statuses() {
    return ['Cancelled'];
}

function report_lead_confirmed_statuses() {
    return ['confirmed'];
}

function report_order_outstanding_statuses() {
    return ['new', 'under_review', 'confirmed'];
}

function report_order_completed_statuses() {
    return ['fulfilled'];
}

function report_financial_definitions() {
    return [
        'gross_pos_sales' => 'Sum of pos_orders.total where status = completed.',
        'gross_invoice_sales' => 'Sum of invoices.total excluding cancelled or fully returned invoices.',
        'accepted_return_value' => 'Sum of sales_return_items.refund_amount for completed returns.',
        'net_sales' => 'Gross sales less accepted return value.',
        'payments_received' => 'Sum of payments.amount. This is cash received, not sales revenue.',
        'customer_outstanding_balance' => 'max(0, invoice net total - amount_paid).',
        'refund_owed' => 'max(0, amount_paid - invoice net total).',
        'actual_cash_refunded' => 'Not represented by a separate completed-refund workflow in the current schema.',
    ];
}

function report_revenue_source_relationships() {
    return [
        'pos_orders' => 'Independent POS sales channel. Completed POS orders are sales revenue.',
        'invoices' => 'Independent invoice sales channel. Non-cancelled/non-fully-returned invoice totals are sales revenue.',
        'orders' => 'Order requests/fulfillment records. An order with fulfilled_invoice_id is represented by its invoice, not counted separately.',
        'leads' => 'Lead records are prospects. A confirmed lead becomes an order and is not a completed sale by itself.',
        'payments' => 'Cash receipts against invoices. Payments are not sales revenue.',
        'stock_transfers' => 'Inventory movement between locations. Transfers are not sales revenue.',
    ];
}

require_once __DIR__ . '/reporting-sales.php';

function report_inventory_definitions() {
    return [
        'on_hand' => 'Sum of inventory_stock.quantity at a location.',
        'reserved' => 'Sum of active order_stock_reservations.quantity for a location.',
        'available' => 'On-hand quantity less applicable reserved quantity.',
        'in_transit' => 'Dispatched but not yet received stock_transfer quantities. Not included in on-hand stock.',
    ];
}

