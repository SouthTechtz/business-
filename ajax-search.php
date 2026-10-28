<?php
require_once 'config.php';
require_once 'auth_check.php';

$q = trim($_GET['q'] ?? '');
$scope = trim($_GET['scope'] ?? 'all');
$results = [];
$inScope = function (...$names) use ($scope) {
    return $scope === '' || $scope === 'all' || in_array($scope, $names, true);
};

if (mb_strlen($q) >= 2) {
    $like = '%' . $q . '%';

    if ($inScope('products') && can('manage_products')) {
        $rows = fetch_all(
            "SELECT product_id, name, code, sku, barcode, sale_price FROM products
             WHERE tenant_id = ? AND (name LIKE ? OR code LIKE ? OR sku LIKE ? OR barcode LIKE ?)
             ORDER BY name LIMIT 6",
            $current_user->tenant_id, $like, $like, $like, $like
        );
        foreach ($rows as $p) {
            $results[] = [
                'group' => 'Products',
                'title' => $p['name'],
                'subtitle' => trim(implode(' • ', array_filter(['Code: ' . $p['code'], $p['sku'] ? 'SKU: ' . $p['sku'] : '', $p['barcode'] ? 'Barcode: ' . $p['barcode'] : '']))),
                'url' => 'product-view.php?id=' . (int)$p['product_id'],
            ];
        }
    }

    if ($inScope('customers') && can('manage_contacts')) {
        $rows = fetch_all(
            "SELECT contact_id, first_name, last_name, company_name, phone FROM contacts
             WHERE tenant_id = ? AND is_customer = 1
               AND (first_name LIKE ? OR last_name LIKE ? OR company_name LIKE ?)
             ORDER BY company_name, first_name LIMIT 6",
            $current_user->tenant_id, $like, $like, $like
        );
        foreach ($rows as $c) {
            $results[] = [
                'group' => 'Customers',
                'title' => trim(($c['first_name'] ?? '') . ' ' . ($c['last_name'] ?? '')) ?: $c['company_name'],
                'subtitle' => trim(implode(' • ', array_filter([$c['company_name'], $c['phone']]))),
                'url' => 'customer-view.php?id=' . (int)$c['contact_id'],
            ];
        }
    }

    if ($inScope('suppliers') && can('manage_suppliers')) {
        $rows = fetch_all(
            "SELECT supplier_id, company_name, first_name, last_name, phone FROM suppliers
             WHERE tenant_id = ? AND (company_name LIKE ? OR first_name LIKE ? OR last_name LIKE ? OR phone LIKE ?)
             ORDER BY company_name LIMIT 4",
            $current_user->tenant_id, $like, $like, $like, $like
        );
        foreach ($rows as $s) {
            $results[] = [
                'group' => 'Suppliers',
                'title' => $s['company_name'],
                'subtitle' => trim(implode(' • ', array_filter([trim(($s['first_name'] ?? '') . ' ' . ($s['last_name'] ?? '')), $s['phone']]))),
                'url' => 'suppliers.php',
            ];
        }
    }

    if ($inScope('invoices') && can('manage_invoices')) {
        $invoice_sql = "SELECT invoice_id, invoice_number, total, amount_due, issue_date FROM invoices
             WHERE tenant_id = ? AND invoice_number LIKE ?";
        $scope_ids = accessible_warehouse_ids();
        if ($scope_ids !== null) {
            $invoice_sql .= $scope_ids ? " AND warehouse_id IN (" . implode(',', array_map('intval', $scope_ids)) . ")" : " AND 1 = 0";
        }
        $invoice_sql .= " ORDER BY invoice_id DESC LIMIT 6";
        $rows = fetch_all($invoice_sql, $current_user->tenant_id, $like);
        foreach ($rows as $i) {
            $results[] = [
                'group' => 'Invoices',
                'title' => $i['invoice_number'],
                'subtitle' => date('M d, Y', strtotime($i['issue_date'])) . ' • Total: ' . number_format((float)$i['total'], 2, '.', '') . ' • Due: ' . number_format((float)$i['amount_due'], 2, '.', ''),
                'url' => 'invoice-details.php?id=' . (int)$i['invoice_id'],
            ];
        }
    }

    if ($inScope('sales') && can('manage_pos')) {
        $sales_sql = "SELECT order_id, order_number, total, status, order_date FROM pos_orders
             WHERE tenant_id = ? AND order_number LIKE ?";
        $scope_ids = accessible_warehouse_ids();
        if ($scope_ids !== null) {
            $sales_sql .= $scope_ids ? " AND warehouse_id IN (" . implode(',', array_map('intval', $scope_ids)) . ")" : " AND 1 = 0";
        }
        $sales_sql .= " ORDER BY order_id DESC LIMIT 6";
        $rows = fetch_all($sales_sql, $current_user->tenant_id, $like);
        foreach ($rows as $o) {
            $results[] = [
                'group' => 'Sales',
                'title' => $o['order_number'],
                'subtitle' => date('M d, Y', strtotime($o['order_date'])) . ' • ' . ucfirst($o['status']) . ' • Total: ' . number_format((float)$o['total'], 2, '.', ''),
                'url' => 'sale-details.php?id=' . (int)$o['order_id'],
            ];
        }
    }

    if ($inScope('expenses') && can('manage_expenses')) {
        $rows = fetch_all(
            "SELECT expense_id, description, amount, expense_date FROM expenses
             WHERE tenant_id = ? AND description LIKE ?
             ORDER BY expense_id DESC LIMIT 5",
            $current_user->tenant_id, $like
        );
        foreach ($rows as $e) {
            $results[] = [
                'group' => 'Expenses',
                'title' => $e['description'],
                'subtitle' => date('M d, Y', strtotime($e['expense_date'])) . ' • ' . number_format((float)$e['amount'], 2, '.', ''),
                'url' => 'expenses.php',
            ];
        }
    }

    if ($inScope('receipts') && can('manage_receipts')) {
        $receipt_sql = "SELECT p.payment_id, p.invoice_id, p.amount, p.payment_date, i.invoice_number FROM payments p
             JOIN invoices i ON i.invoice_id = p.invoice_id
             WHERE p.tenant_id = ? AND i.invoice_number LIKE ?";
        $scope_ids = accessible_warehouse_ids();
        if ($scope_ids !== null) {
            $receipt_sql .= $scope_ids ? " AND i.warehouse_id IN (" . implode(',', array_map('intval', $scope_ids)) . ")" : " AND 1 = 0";
        }
        $receipt_sql .= " ORDER BY p.payment_id DESC LIMIT 5";
        $rows = fetch_all($receipt_sql, $current_user->tenant_id, $like);
        foreach ($rows as $p) {
            $results[] = [
                'group' => 'Payments',
                'title' => 'Payment ' . $p['payment_id'] . ' — ' . $p['invoice_number'],
                'subtitle' => date('M d, Y', strtotime($p['payment_date'])) . ' • ' . number_format((float)$p['amount'], 2, '.', ''),
                'url' => 'invoice-details.php?id=' . (int)$p['invoice_id'] . '#payments',
            ];
        }
    }

    if ($inScope('activity') && (can('view_activity_log') || can('view_reports'))) {
        $rows = fetch_all(
            "SELECT l.log_id, l.action, l.entity_type, l.entity_id, l.details, u.first_name, u.last_name, u.username
             FROM audit_logs l LEFT JOIN users u ON u.user_id = l.user_id
             WHERE l.tenant_id = ? AND (l.action LIKE ? OR l.entity_type LIKE ? OR l.details LIKE ? OR u.username LIKE ?)
             ORDER BY l.log_id DESC LIMIT 6",
            $current_user->tenant_id, $like, $like, $like, $like
        );
        foreach ($rows as $a) {
            $results[] = [
                'group' => 'Activity',
                'title' => ucfirst($a['action']) . ' — ' . ($a['entity_type'] ?? ''),
                'subtitle' => ($a['first_name'] ?? $a['username'] ?? '') . ($a['details'] ? ' • ' . mb_substr($a['details'], 0, 60) : ''),
                'url' => 'audit-log.php',
            ];
        }
    }

    if ($inScope('users') && can('manage_users')) {
        $rows = fetch_all(
            "SELECT user_id, first_name, last_name, email, username FROM users
             WHERE tenant_id = ? AND (first_name LIKE ? OR last_name LIKE ? OR email LIKE ? OR username LIKE ?)
             ORDER BY first_name LIMIT 4",
            $current_user->tenant_id, $like, $like, $like, $like
        );
        foreach ($rows as $u) {
            $results[] = [
                'group' => 'Users',
                'title' => trim(($u['first_name'] ?? '') . ' ' . ($u['last_name'] ?? '')) ?: $u['username'],
                'subtitle' => $u['email'] ?: $u['username'],
                'url' => 'user-view.php?id=' . (int)$u['user_id'],
            ];
        }
    }
}

header('Content-Type: application/json; charset=UTF-8');
echo json_encode(['results' => $results], JSON_UNESCAPED_UNICODE);
