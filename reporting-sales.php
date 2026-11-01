<?php
/** Stage 2 read-only Sales and Invoice reporting calculations. */

function report_invoice_eligibility_sql($invoiceAlias = 'i', $statusAlias = 'ds') {
    return "({$statusAlias}.name IS NULL OR {$statusAlias}.name <> 'Cancelled' OR ({$invoiceAlias}.total > 0 AND {$invoiceAlias}.returned_amount >= {$invoiceAlias}.total - 0.01))";
}

function report_pos_sales_summary($from = '', $to = '') {
    $tenantId = report_tenant_id();
    $scope = report_sales_warehouse_condition('o', 'warehouse_id');
    $saleDate = report_date_condition('o.order_date', $from, $to);
    $gross = fetch_one(
        "SELECT COUNT(*) sale_count, COALESCE(SUM(o.total), 0) gross_sales
         FROM pos_orders o
         WHERE o.tenant_id = ? AND o.status = 'completed'" . $scope['sql'] . $saleDate['sql'],
        $tenantId, ...array_merge($scope['params'], $saleDate['params'])
    );

    $returnDate = report_date_condition('sr.return_date', $from, $to);
    $returns = fetch_one(
        "SELECT COUNT(DISTINCT sr.return_id) return_count,
                COALESCE(SUM(sri.quantity), 0) returned_qty,
                COALESCE(SUM(sri.resale_quantity), 0) resale_qty,
                COALESCE(SUM(sri.refund_amount), 0) accepted_returns
         FROM sales_returns sr
         JOIN sales_return_items sri ON sri.return_id = sr.return_id AND sri.tenant_id = sr.tenant_id
         JOIN pos_orders o ON o.order_id = sr.source_id AND o.tenant_id = sr.tenant_id
         WHERE sr.tenant_id = ? AND sr.source_type = 'pos' AND sr.status = 'completed'"
            . $scope['sql'] . $returnDate['sql'],
        $tenantId, ...array_merge($scope['params'], $returnDate['params'])
    );

    $grossSales = (float)($gross['gross_sales'] ?? 0);
    $acceptedReturns = (float)($returns['accepted_returns'] ?? 0);
    return [
        'sale_count' => (int)($gross['sale_count'] ?? 0),
        'gross_sales' => $grossSales,
        'return_count' => (int)($returns['return_count'] ?? 0),
        'returned_qty' => (float)($returns['returned_qty'] ?? 0),
        'resale_qty' => (float)($returns['resale_qty'] ?? 0),
        'accepted_returns' => $acceptedReturns,
        'net_sales' => $grossSales - $acceptedReturns,
    ];
}

function report_invoice_sales_summary($from = '', $to = '') {
    $tenantId = report_tenant_id();
    $scope = report_sales_warehouse_condition('i', 'warehouse_id');
    $saleDate = report_date_condition('i.issue_date', $from, $to);
    $eligible = report_invoice_eligibility_sql('i', 'ds');
    $gross = fetch_one(
        "SELECT COUNT(*) sale_count, COALESCE(SUM(i.total), 0) gross_sales,
                COALESCE(SUM(i.amount_due), 0) outstanding_balance,
                COALESCE(SUM(i.refund_due), 0) refund_due,
                COALESCE(SUM(CASE WHEN i.returned_amount > 0 AND i.returned_amount < i.total - 0.01 THEN 1 ELSE 0 END), 0) partial_return_count,
                COALESCE(SUM(CASE WHEN i.total > 0 AND i.returned_amount >= i.total - 0.01 THEN 1 ELSE 0 END), 0) full_return_count
         FROM invoices i
         LEFT JOIN document_statuses ds ON ds.status_id = i.status_id AND ds.tenant_id = i.tenant_id
         WHERE i.tenant_id = ? AND $eligible" . $scope['sql'] . $saleDate['sql'],
        $tenantId, ...array_merge($scope['params'], $saleDate['params'])
    );

    $returnDate = report_date_condition('sr.return_date', $from, $to);
    $returns = fetch_one(
        "SELECT COUNT(DISTINCT sr.return_id) return_count,
                COALESCE(SUM(sri.quantity), 0) returned_qty,
                COALESCE(SUM(sri.resale_quantity), 0) resale_qty,
                COALESCE(SUM(sri.refund_amount), 0) accepted_returns
         FROM sales_returns sr
         JOIN sales_return_items sri ON sri.return_id = sr.return_id AND sri.tenant_id = sr.tenant_id
         JOIN invoices i ON i.invoice_id = sr.source_id AND i.tenant_id = sr.tenant_id
         WHERE sr.tenant_id = ? AND sr.source_type = 'invoice' AND sr.status = 'completed'"
            . $scope['sql'] . $returnDate['sql'],
        $tenantId, ...array_merge($scope['params'], $returnDate['params'])
    );

    $grossSales = (float)($gross['gross_sales'] ?? 0);
    $acceptedReturns = (float)($returns['accepted_returns'] ?? 0);
    return [
        'sale_count' => (int)($gross['sale_count'] ?? 0),
        'gross_sales' => $grossSales,
        'return_count' => (int)($returns['return_count'] ?? 0),
        'returned_qty' => (float)($returns['returned_qty'] ?? 0),
        'resale_qty' => (float)($returns['resale_qty'] ?? 0),
        'accepted_returns' => $acceptedReturns,
        'net_sales' => $grossSales - $acceptedReturns,
        'outstanding_balance' => (float)($gross['outstanding_balance'] ?? 0),
        'refund_due' => (float)($gross['refund_due'] ?? 0),
        'partial_return_count' => (int)($gross['partial_return_count'] ?? 0),
        'full_return_count' => (int)($gross['full_return_count'] ?? 0),
    ];
}

function report_sales_summary($from = '', $to = '') {
    $pos = report_pos_sales_summary($from, $to);
    $invoice = report_invoice_sales_summary($from, $to);
    return [
        'pos' => $pos,
        'invoice' => $invoice,
        'gross_sales' => $pos['gross_sales'] + $invoice['gross_sales'],
        'accepted_returns' => $pos['accepted_returns'] + $invoice['accepted_returns'],
        'net_sales' => $pos['net_sales'] + $invoice['net_sales'],
        'sale_count' => $pos['sale_count'] + $invoice['sale_count'],
        'return_count' => $pos['return_count'] + $invoice['return_count'],
    ];
}

function report_invoice_status_summary($from = '', $to = '') {
    $scope = report_sales_warehouse_condition('i', 'warehouse_id');
    $date = report_date_condition('i.issue_date', $from, $to);
    return fetch_all(
        "SELECT ds.status_id, COALESCE(ds.name, 'Unknown') status_name,
                COUNT(*) invoice_count, COALESCE(SUM(i.total), 0) invoice_total,
                COALESCE(SUM(i.amount_due), 0) outstanding_balance,
                COALESCE(SUM(i.refund_due), 0) refund_due
         FROM invoices i
         LEFT JOIN document_statuses ds ON ds.status_id = i.status_id AND ds.tenant_id = i.tenant_id
         WHERE i.tenant_id = ?" . $scope['sql'] . $date['sql'] . "
         GROUP BY ds.status_id, ds.name ORDER BY ds.sort_order, ds.name",
        report_tenant_id(), ...array_merge($scope['params'], $date['params'])
    );
}


function report_sales_channel_by_shop($channel, $from, $to) {
    $isPos = $channel === 'pos';
    if (!$isPos && $channel !== 'invoice') {
        throw new InvalidArgumentException('Unknown sales channel.');
    }

    $tenantId = report_tenant_id();
    $alias = $isPos ? 'o' : 'i';
    $table = $isPos ? 'pos_orders' : 'invoices';
    $sourceId = $isPos ? 'order_id' : 'invoice_id';
    $scope = report_sales_warehouse_condition($alias, 'warehouse_id');
    $saleDate = report_date_condition($alias . ($isPos ? '.order_date' : '.issue_date'), $from, $to);
    $statusJoin = $isPos ? '' : ' LEFT JOIN document_statuses ds ON ds.status_id = i.status_id AND ds.tenant_id = i.tenant_id';
    $eligible = $isPos ? "o.status = 'completed'" : report_invoice_eligibility_sql('i', 'ds');

    $grossRows = fetch_all(
        "SELECT $alias.warehouse_id, w.shop_id, s.name shop_name,
                COUNT(*) sale_count, COALESCE(SUM($alias.total), 0) gross_sales
         FROM $table $alias
         $statusJoin
         LEFT JOIN warehouses w ON w.warehouse_id = $alias.warehouse_id AND w.tenant_id = $alias.tenant_id
         LEFT JOIN shops s ON s.shop_id = w.shop_id AND s.tenant_id = w.tenant_id
         WHERE $alias.tenant_id = ? AND $eligible" . $scope['sql'] . $saleDate['sql'] . "
         GROUP BY $alias.warehouse_id, w.shop_id, s.name",
        $tenantId, ...array_merge($scope['params'], $saleDate['params'])
    );

    $returnDate = report_date_condition('sr.return_date', $from, $to);
    $returnRows = fetch_all(
        "SELECT $alias.warehouse_id, w.shop_id, s.name shop_name,
                COUNT(DISTINCT sr.return_id) return_count,
                COALESCE(SUM(sri.quantity), 0) returned_qty,
                COALESCE(SUM(sri.resale_quantity), 0) resale_qty,
                COALESCE(SUM(sri.refund_amount), 0) accepted_returns
         FROM sales_returns sr
         JOIN sales_return_items sri ON sri.return_id = sr.return_id AND sri.tenant_id = sr.tenant_id
         JOIN $table $alias ON $alias.$sourceId = sr.source_id AND $alias.tenant_id = sr.tenant_id
         LEFT JOIN warehouses w ON w.warehouse_id = $alias.warehouse_id AND w.tenant_id = $alias.tenant_id
         LEFT JOIN shops s ON s.shop_id = w.shop_id AND s.tenant_id = w.tenant_id
         WHERE sr.tenant_id = ? AND sr.source_type = ? AND sr.status = 'completed'"
            . $scope['sql'] . $returnDate['sql'] . "
         GROUP BY $alias.warehouse_id, w.shop_id, s.name",
        $tenantId, $channel, ...array_merge($scope['params'], $returnDate['params'])
    );

    $rows = [];
    $init = function ($row) use ($isPos, &$rows) {
        $shopId = (int)($row['shop_id'] ?? 0);
        $key = $shopId > 0 ? 'shop:' . $shopId : 'unattributed';
        if (!isset($rows[$key])) {
            $rows[$key] = [
                'shop_id' => $shopId > 0 ? $shopId : null,
                'shop_name' => $shopId > 0 ? (string)$row['shop_name'] : 'Unattributed (historical)',
                'pos_sale_count' => 0, 'pos_gross_sales' => 0.0, 'pos_accepted_returns' => 0.0,
                'invoice_sale_count' => 0, 'invoice_gross_sales' => 0.0, 'invoice_accepted_returns' => 0.0,
                'return_count' => 0, 'returned_qty' => 0.0, 'resale_qty' => 0.0,
            ];
        }
        return $key;
    };
    $prefix = $isPos ? 'pos' : 'invoice';
    foreach ($grossRows as $row) {
        $key = $init($row);
        $rows[$key][$prefix . '_sale_count'] += (int)$row['sale_count'];
        $rows[$key][$prefix . '_gross_sales'] += (float)$row['gross_sales'];
    }
    foreach ($returnRows as $row) {
        $key = $init($row);
        $rows[$key][$prefix . '_accepted_returns'] += (float)$row['accepted_returns'];
        $rows[$key]['return_count'] += (int)$row['return_count'];
        $rows[$key]['returned_qty'] += (float)$row['returned_qty'];
        $rows[$key]['resale_qty'] += (float)$row['resale_qty'];
    }
    return $rows;
}

function report_sales_by_shop($from = '', $to = '') {
    $rows = report_sales_channel_by_shop('pos', $from, $to);
    foreach (report_sales_channel_by_shop('invoice', $from, $to) as $key => $invoiceRow) {
        if (!isset($rows[$key])) {
            $rows[$key] = $invoiceRow;
            continue;
        }
        $rows[$key]['invoice_sale_count'] += $invoiceRow['invoice_sale_count'];
        $rows[$key]['invoice_gross_sales'] += $invoiceRow['invoice_gross_sales'];
        $rows[$key]['invoice_accepted_returns'] += $invoiceRow['invoice_accepted_returns'];
        $rows[$key]['return_count'] += $invoiceRow['return_count'];
        $rows[$key]['returned_qty'] += $invoiceRow['returned_qty'];
        $rows[$key]['resale_qty'] += $invoiceRow['resale_qty'];
    }
    foreach ($rows as &$row) {
        $row['pos_net_sales'] = $row['pos_gross_sales'] - $row['pos_accepted_returns'];
        $row['invoice_net_sales'] = $row['invoice_gross_sales'] - $row['invoice_accepted_returns'];
        $row['net_sales'] = $row['pos_net_sales'] + $row['invoice_net_sales'];
        $row['sale_count'] = $row['pos_sale_count'] + $row['invoice_sale_count'];
    }
    unset($row);
    uasort($rows, fn($a, $b) => [($a['shop_id'] === null ? 1 : 0), $a['shop_name']] <=> [($b['shop_id'] === null ? 1 : 0), $b['shop_name']]);
    return array_values($rows);
}


function report_sales_channel_by_product($channel, $from, $to) {
    $isPos = $channel === 'pos';
    if (!$isPos && $channel !== 'invoice') {
        throw new InvalidArgumentException('Unknown sales channel.');
    }

    $tenantId = report_tenant_id();
    $alias = $isPos ? 'o' : 'i';
    $itemAlias = $isPos ? 'oi' : 'ii';
    $table = $isPos ? 'pos_orders' : 'invoices';
    $itemTable = $isPos ? 'pos_order_items' : 'invoice_items';
    $sourceId = $isPos ? 'order_id' : 'invoice_id';
    $scope = report_sales_warehouse_condition($alias, 'warehouse_id');
    $saleDate = report_date_condition($alias . ($isPos ? '.order_date' : '.issue_date'), $from, $to);
    $statusJoin = $isPos ? '' : ' LEFT JOIN document_statuses ds ON ds.status_id = i.status_id AND ds.tenant_id = i.tenant_id';
    $eligible = $isPos ? "o.status = 'completed'" : report_invoice_eligibility_sql('i', 'ds');

    $grossRows = fetch_all(
        "SELECT COALESCE($itemAlias.product_id, 0) product_id,
                COALESCE(p.name, $itemAlias.description, 'Unspecified product') product_name,
                COALESCE(SUM($itemAlias.quantity), 0) sold_qty,
                COALESCE(SUM($itemAlias.total), 0) gross_sales
         FROM $itemTable $itemAlias
         JOIN $table $alias ON $alias.$sourceId = $itemAlias.$sourceId AND $alias.tenant_id = ?
         $statusJoin
         LEFT JOIN products p ON p.product_id = $itemAlias.product_id AND p.tenant_id = $alias.tenant_id
         WHERE $alias.tenant_id = ? AND $eligible" . $scope['sql'] . $saleDate['sql'] . "
         GROUP BY COALESCE($itemAlias.product_id, 0), COALESCE(p.name, $itemAlias.description, 'Unspecified product')",
        $tenantId, $tenantId, ...array_merge($scope['params'], $saleDate['params'])
    );

    $returnDate = report_date_condition('sr.return_date', $from, $to);
    $returnRows = fetch_all(
        "SELECT COALESCE(sri.product_id, 0) product_id,
                COALESCE(p.name, 'Unspecified product') product_name,
                COALESCE(SUM(sri.quantity), 0) returned_qty,
                COALESCE(SUM(sri.resale_quantity), 0) resale_qty,
                COALESCE(SUM(sri.refund_amount), 0) accepted_returns
         FROM sales_returns sr
         JOIN sales_return_items sri ON sri.return_id = sr.return_id AND sri.tenant_id = sr.tenant_id
         JOIN $table $alias ON $alias.$sourceId = sr.source_id AND $alias.tenant_id = sr.tenant_id
         LEFT JOIN products p ON p.product_id = sri.product_id AND p.tenant_id = sr.tenant_id
         WHERE sr.tenant_id = ? AND sr.source_type = ? AND sr.status = 'completed'"
            . $scope['sql'] . $returnDate['sql'] . "
         GROUP BY COALESCE(sri.product_id, 0), COALESCE(p.name, 'Unspecified product')",
        $tenantId, $channel, ...array_merge($scope['params'], $returnDate['params'])
    );

    $rows = [];
    $init = function ($row) use (&$rows) {
        $productId = (int)$row['product_id'];
        $name = (string)$row['product_name'];
        $key = $productId > 0 ? 'product:' . $productId : 'description:' . $name;
        if (!isset($rows[$key])) {
            $rows[$key] = [
                'product_id' => $productId > 0 ? $productId : null,
                'product_name' => $name,
                'pos_sold_qty' => 0.0, 'pos_gross_sales' => 0.0, 'pos_accepted_returns' => 0.0,
                'invoice_sold_qty' => 0.0, 'invoice_gross_sales' => 0.0, 'invoice_accepted_returns' => 0.0,
                'returned_qty' => 0.0, 'resale_qty' => 0.0,
            ];
        }
        return $key;
    };
    $prefix = $isPos ? 'pos' : 'invoice';
    foreach ($grossRows as $row) {
        $key = $init($row);
        $rows[$key][$prefix . '_sold_qty'] += (float)$row['sold_qty'];
        $rows[$key][$prefix . '_gross_sales'] += (float)$row['gross_sales'];
    }
    foreach ($returnRows as $row) {
        $key = $init($row);
        $rows[$key][$prefix . '_accepted_returns'] += (float)$row['accepted_returns'];
        $rows[$key]['returned_qty'] += (float)$row['returned_qty'];
        $rows[$key]['resale_qty'] += (float)$row['resale_qty'];
    }
    return $rows;
}

function report_sales_by_product($from = '', $to = '') {
    $rows = report_sales_channel_by_product('pos', $from, $to);
    foreach (report_sales_channel_by_product('invoice', $from, $to) as $key => $invoiceRow) {
        if (!isset($rows[$key])) {
            $rows[$key] = $invoiceRow;
            continue;
        }
        $rows[$key]['invoice_sold_qty'] += $invoiceRow['invoice_sold_qty'];
        $rows[$key]['invoice_gross_sales'] += $invoiceRow['invoice_gross_sales'];
        $rows[$key]['invoice_accepted_returns'] += $invoiceRow['invoice_accepted_returns'];
        $rows[$key]['returned_qty'] += $invoiceRow['returned_qty'];
        $rows[$key]['resale_qty'] += $invoiceRow['resale_qty'];
    }
    foreach ($rows as &$row) {
        $row['sold_qty'] = $row['pos_sold_qty'] + $row['invoice_sold_qty'];
        $row['gross_sales'] = $row['pos_gross_sales'] + $row['invoice_gross_sales'];
        $row['accepted_returns'] = $row['pos_accepted_returns'] + $row['invoice_accepted_returns'];
        $row['net_qty'] = $row['sold_qty'] - $row['returned_qty'];
        $row['net_sales'] = $row['gross_sales'] - $row['accepted_returns'];
    }
    unset($row);
    uasort($rows, fn($a, $b) => $b['net_sales'] <=> $a['net_sales'] ?: strcmp($a['product_name'], $b['product_name']));
    return array_values($rows);
}


function report_pos_sale_rows($from = '', $to = '', array $filters = []) {
    $tenantId = report_tenant_id();
    $scope = report_sales_warehouse_condition('o', 'warehouse_id');
    $saleDate = report_date_condition('o.order_date', $from, $to);
    $returnDate = report_date_condition('sr.return_date', $from, $to);

    $sql = "SELECT o.*, c.first_name, c.last_name, c.company_name,
                   w.name location_name, s.name shop_name,
                   COALESCE(oi.items_total, 0) items_total,
                   COALESCE(oi.items_count, 0) items_count,
                   COALESCE(rt.return_count, 0) return_count,
                   COALESCE(rt.returned_qty, 0) returned_qty,
                   COALESCE(rt.resale_qty, 0) resale_qty,
                   COALESCE(rt.accepted_returns, 0) accepted_returns,
                   CASE WHEN o.status = 'completed'
                        THEN o.total - COALESCE(rt.accepted_returns, 0)
                        ELSE 0 END net_sales
            FROM pos_orders o
            LEFT JOIN contacts c ON c.contact_id = o.contact_id AND c.tenant_id = o.tenant_id
            LEFT JOIN warehouses w ON w.warehouse_id = o.warehouse_id AND w.tenant_id = o.tenant_id
            LEFT JOIN shops s ON s.shop_id = w.shop_id AND s.tenant_id = w.tenant_id
            LEFT JOIN (
                SELECT order_id, SUM(quantity) items_count, SUM(total) items_total
                FROM pos_order_items GROUP BY order_id
            ) oi ON oi.order_id = o.order_id
            LEFT JOIN (
                SELECT sr.source_id, COUNT(DISTINCT sr.return_id) return_count,
                       SUM(sri.quantity) returned_qty, SUM(sri.resale_quantity) resale_qty,
                       SUM(sri.refund_amount) accepted_returns
                FROM sales_returns sr
                JOIN sales_return_items sri ON sri.return_id = sr.return_id AND sri.tenant_id = sr.tenant_id
                WHERE sr.tenant_id = ? AND sr.source_type = 'pos' AND sr.status = 'completed'"
                    . $returnDate['sql'] . "
                GROUP BY sr.source_id
            ) rt ON rt.source_id = o.order_id
            WHERE o.tenant_id = ?" . $scope['sql'] . $saleDate['sql'];
    $params = array_merge(
        [$tenantId], $returnDate['params'], [$tenantId],
        $scope['params'], $saleDate['params']
    );

    if (!empty($filters['warehouse_id'])) {
        $sql .= ' AND o.warehouse_id = ?';
        $params[] = (int)$filters['warehouse_id'];
    }
    if (!empty($filters['status']) && in_array($filters['status'], ['pending', 'completed', 'cancelled'], true)) {
        $sql .= ' AND o.status = ?';
        $params[] = $filters['status'];
    }
    if (!empty($filters['customer_id'])) {
        $sql .= ' AND o.contact_id = ?';
        $params[] = (int)$filters['customer_id'];
    }
    $sql .= ' ORDER BY o.order_date DESC, o.order_id DESC';
    return fetch_all($sql, ...$params);
}


function report_invoice_sale_rows($from = '', $to = '', array $filters = []) {
    $tenantId = report_tenant_id();
    $scope = report_sales_warehouse_condition('i', 'warehouse_id');
    $saleDate = report_date_condition('i.issue_date', $from, $to);
    $returnDate = report_date_condition('sr.return_date', $from, $to);
    $eligible = report_invoice_eligibility_sql('i', 'ds');

    $sql = "SELECT i.*, ds.name status_name, c.first_name, c.last_name, c.company_name,
                   w.name location_name, s.name shop_name,
                   COALESCE(rt.return_count, 0) return_count,
                   COALESCE(rt.returned_qty, 0) returned_qty,
                   COALESCE(rt.resale_qty, 0) resale_qty,
                   COALESCE(rt.accepted_returns, 0) accepted_returns,
                   CASE WHEN $eligible
                        THEN i.total - COALESCE(rt.accepted_returns, 0)
                        ELSE 0 END net_sales,
                   CASE WHEN i.total > 0 AND i.returned_amount >= i.total - 0.01 THEN 'Fully returned'
                        WHEN i.returned_amount > 0 THEN 'Partially returned'
                        ELSE 'Not returned' END return_state
            FROM invoices i
            LEFT JOIN document_statuses ds ON ds.status_id = i.status_id AND ds.tenant_id = i.tenant_id
            LEFT JOIN contacts c ON c.contact_id = i.contact_id AND c.tenant_id = i.tenant_id
            LEFT JOIN warehouses w ON w.warehouse_id = i.warehouse_id AND w.tenant_id = i.tenant_id
            LEFT JOIN shops s ON s.shop_id = w.shop_id AND s.tenant_id = w.tenant_id
            LEFT JOIN (
                SELECT sr.source_id, COUNT(DISTINCT sr.return_id) return_count,
                       SUM(sri.quantity) returned_qty, SUM(sri.resale_quantity) resale_qty,
                       SUM(sri.refund_amount) accepted_returns
                FROM sales_returns sr
                JOIN sales_return_items sri ON sri.return_id = sr.return_id AND sri.tenant_id = sr.tenant_id
                WHERE sr.tenant_id = ? AND sr.source_type = 'invoice' AND sr.status = 'completed'"
                    . $returnDate['sql'] . "
                GROUP BY sr.source_id
            ) rt ON rt.source_id = i.invoice_id
            WHERE i.tenant_id = ?" . $scope['sql'] . $saleDate['sql'];
    $params = array_merge(
        [$tenantId], $returnDate['params'], [$tenantId],
        $scope['params'], $saleDate['params']
    );

    if (!empty($filters['warehouse_id'])) {
        $sql .= ' AND i.warehouse_id = ?';
        $params[] = (int)$filters['warehouse_id'];
    }
    if (!empty($filters['status_id'])) {
        $sql .= ' AND i.status_id = ?';
        $params[] = (int)$filters['status_id'];
    }
    if (!empty($filters['search'])) {
        $sql .= ' AND (i.invoice_number LIKE ? OR c.first_name LIKE ? OR c.last_name LIKE ? OR c.company_name LIKE ?)';
        $like = '%' . $filters['search'] . '%';
        array_push($params, $like, $like, $like, $like);
    }
    $sql .= ' ORDER BY i.issue_date DESC, i.invoice_id DESC';
    return fetch_all($sql, ...$params);
}

