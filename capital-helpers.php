<?php
/**
 * Shared capital funding and brand balance-sheet calculations.
 *
 * Capital is equity: it increases cash at bank and the capital balance.
 * It is deliberately NOT treated as sales revenue so profit and VAT reports
 * stay correct.
 */

/** True when the capital table exists (the migration may not have run yet). */
function capital_table_ready() {
    return db_table_exists('capital_movements');
}

/** True when a column exists. Cached because capital screens query it often. */
function capital_column_exists($table, $column) {
    static $cache = [];
    $table = preg_replace('/[^a-zA-Z0-9_]/', '', (string)$table);
    $column = preg_replace('/[^a-zA-Z0-9_]/', '', (string)$column);
    if ($table === '' || $column === '') return false;
    $key = $table . '.' . $column;
    if (array_key_exists($key, $cache)) return $cache[$key];
    $found = false;
    if (db_table_exists($table)) {
        foreach (fetch_all("SHOW COLUMNS FROM `{$table}`") as $row) {
            if (strcasecmp((string)($row['Field'] ?? ''), $column) === 0) {
                $found = true;
                break;
            }
        }
    }
    $cache[$key] = $found;
    return $found;
}

/** SQL expression for a capital movement's account type (old rows are bank). */
function capital_movement_account_expr($alias = '') {
    $prefix = $alias !== '' ? $alias . '.' : '';
    return capital_column_exists('capital_movements', 'account_type')
        ? "COALESCE({$prefix}account_type, 'bank')"
        : "'bank'";
}

/** SQL expression for a payment method's account type. */
function capital_payment_account_expr($alias = 'pm', $default = 'bank') {
    $default = $default === 'cash' ? 'cash' : 'bank';
    return capital_column_exists('payment_methods', 'account_type')
        ? "COALESCE({$alias}.account_type, '{$default}')"
        : "'{$default}'";
}

/**
 * Capital contributions, withdrawals and bank breakdown for a tenant.
 * A Mini Admin is always limited to their own brand.
 */
function capital_summary($tenant_id = null, $brand = '') {
    global $current_user;
    $empty = [
        'contributions'      => 0.0,
        'withdrawals'        => 0.0,
        'net'                => 0.0,
        'cash_contributions' => 0.0,
        'cash_withdrawals'   => 0.0,
        'cash_net'           => 0.0,
        'bank_contributions' => 0.0,
        'bank_withdrawals'   => 0.0,
        'bank_net'           => 0.0,
        'movements'          => 0,
        'banks'              => [],
    ];
    if (!$current_user) return $empty;
    $tenant_id = $tenant_id === null ? (int)$current_user->tenant_id : (int)$tenant_id;
    $brand = capital_resolve_brand($brand);
    if (!capital_table_ready()) return $empty;

    $brand_sql = $brand !== '' ? ' AND cm.brand = ?' : '';
    $params = $brand !== '' ? [$tenant_id, $brand] : [$tenant_id];
    $acct = capital_movement_account_expr('cm');

    $row = fetch_one(
        "SELECT COALESCE(SUM(CASE WHEN cm.movement_type = 'contribution' THEN cm.amount ELSE 0 END), 0) contributions,
                COALESCE(SUM(CASE WHEN cm.movement_type = 'withdrawal' THEN cm.amount ELSE 0 END), 0) withdrawals,
                COALESCE(SUM(CASE WHEN cm.movement_type = 'contribution' AND {$acct} = 'cash' THEN cm.amount ELSE 0 END), 0) cash_contributions,
                COALESCE(SUM(CASE WHEN cm.movement_type = 'withdrawal' AND {$acct} = 'cash' THEN cm.amount ELSE 0 END), 0) cash_withdrawals,
                COALESCE(SUM(CASE WHEN cm.movement_type = 'contribution' AND {$acct} = 'bank' THEN cm.amount ELSE 0 END), 0) bank_contributions,
                COALESCE(SUM(CASE WHEN cm.movement_type = 'withdrawal' AND {$acct} = 'bank' THEN cm.amount ELSE 0 END), 0) bank_withdrawals,
                COUNT(*) movements
           FROM capital_movements cm
          WHERE cm.tenant_id = ?{$brand_sql}",
        ...$params
    ) ?: [];

    $bank_rows = fetch_all(
        "SELECT cm.bank_name,
                COALESCE(SUM(CASE WHEN cm.movement_type = 'contribution' THEN cm.amount ELSE 0 END), 0) contributions,
                COALESCE(SUM(CASE WHEN cm.movement_type = 'withdrawal' THEN cm.amount ELSE 0 END), 0) withdrawals
           FROM capital_movements cm
          WHERE cm.tenant_id = ?{$brand_sql} AND {$acct} = 'bank'
          GROUP BY cm.bank_name
          ORDER BY cm.bank_name",
        ...$params
    );

    $banks = [];
    $bank_net = 0.0;
    foreach ($bank_rows as $bank_row) {
        $net = (float)$bank_row['contributions'] - (float)$bank_row['withdrawals'];
        $bank_net += $net;
        $banks[] = [
            'bank_name'     => (string)$bank_row['bank_name'],
            'contributions' => (float)$bank_row['contributions'],
            'withdrawals'   => (float)$bank_row['withdrawals'],
            'net'           => $net,
        ];
    }

    return [
        'contributions'      => (float)($row['contributions'] ?? 0),
        'withdrawals'        => (float)($row['withdrawals'] ?? 0),
        'net'                => (float)($row['contributions'] ?? 0) - (float)($row['withdrawals'] ?? 0),
        'cash_contributions' => (float)($row['cash_contributions'] ?? 0),
        'cash_withdrawals'   => (float)($row['cash_withdrawals'] ?? 0),
        'cash_net'           => (float)($row['cash_contributions'] ?? 0) - (float)($row['cash_withdrawals'] ?? 0),
        'bank_contributions' => (float)($row['bank_contributions'] ?? 0),
        'bank_withdrawals'   => (float)($row['bank_withdrawals'] ?? 0),
        'bank_net'           => $bank_net,
        'movements'          => (int)($row['movements'] ?? 0),
        'banks'              => $banks,
    ];
}

/** Bank names already used for a tenant/brand (for the form datalist). */
function capital_bank_names($tenant_id = null, $brand = '') {
    global $current_user;
    if (!$current_user) return [];
    $tenant_id = $tenant_id === null ? (int)$current_user->tenant_id : (int)$tenant_id;
    $brand = capital_resolve_brand($brand);
    $names = [];
    $add = function (array $rows) use (&$names) {
        foreach ($rows as $row) {
            $name = trim((string)($row['bank_name'] ?? ''));
            if ($name !== '') $names[strtolower($name)] = $name;
        }
    };

    if (capital_table_ready()) {
        $brand_sql = $brand !== '' ? ' AND cm.brand = ?' : '';
        $params = $brand !== '' ? [$tenant_id, $brand] : [$tenant_id];
        $acct = capital_movement_account_expr('cm');
        $add(fetch_all(
            "SELECT DISTINCT cm.bank_name FROM capital_movements cm
              WHERE cm.tenant_id = ?{$brand_sql} AND cm.bank_name <> '' AND {$acct} = 'bank'",
            ...$params
        ));
    }
    if (db_table_exists('bank_sales_deposits')) {
        $brand_sql = $brand !== '' ? ' AND brand = ?' : '';
        $params = $brand !== '' ? [$tenant_id, $brand] : [$tenant_id];
        $add(fetch_all(
            "SELECT DISTINCT bank_name FROM bank_sales_deposits
              WHERE tenant_id = ?{$brand_sql} AND bank_name <> ''",
            ...$params
        ));
    }
    if (db_table_exists('brand_internal_transfers')) {
        $brand_sql = $brand !== '' ? ' AND brand = ?' : '';
        $params = $brand !== '' ? [$tenant_id, $brand] : [$tenant_id];
        $add(fetch_all(
            "SELECT DISTINCT bank_name FROM brand_internal_transfers
              WHERE tenant_id = ?{$brand_sql} AND bank_name <> ''",
            ...$params
        ));
    }
    if (db_table_exists('expenses') && capital_column_exists('expenses', 'bank_name')) {
        $brand_sql = $brand !== '' ? ' AND e.brand = ?' : '';
        $params = $brand !== '' ? [$tenant_id, $brand] : [$tenant_id];
        $add(fetch_all(
            "SELECT DISTINCT e.bank_name FROM expenses e
              WHERE e.tenant_id = ?{$brand_sql} AND e.bank_name IS NOT NULL AND TRIM(e.bank_name) <> ''",
            ...$params
        ));
    }
    if (db_table_exists('purchases') && capital_column_exists('purchases', 'bank_name')) {
        $brand_sql = $brand !== '' ? ' AND p.brand = ?' : '';
        $params = $brand !== '' ? [$tenant_id, $brand] : [$tenant_id];
        $add(fetch_all(
            "SELECT DISTINCT pu.bank_name FROM purchases pu
              JOIN products p ON p.product_id = pu.product_id AND p.tenant_id = pu.tenant_id
             WHERE pu.tenant_id = ?{$brand_sql} AND pu.bank_name IS NOT NULL AND TRIM(pu.bank_name) <> ''",
            ...$params
        ));
    }
    if (db_table_exists('sales_returns') && capital_column_exists('sales_returns', 'refund_bank_name')) {
        $params = [$tenant_id];
        $brand_sql = capital_return_brand_condition($brand, $tenant_id, $params, 'sr');
        $add(fetch_all(
            "SELECT DISTINCT sr.refund_bank_name AS bank_name FROM sales_returns sr
              WHERE sr.tenant_id = ?{$brand_sql} AND sr.refund_bank_name IS NOT NULL AND TRIM(sr.refund_bank_name) <> ''",
            ...$params
        ));
    }
    if (brand_capital_settings_table_ready() && capital_column_exists('brand_capital_settings', 'opening_bank_name')) {
        $brand_sql = $brand !== '' ? ' AND brand = ?' : '';
        $params = $brand !== '' ? [$tenant_id, $brand] : [$tenant_id];
        $add(fetch_all(
            "SELECT DISTINCT opening_bank_name AS bank_name FROM brand_capital_settings
              WHERE tenant_id = ?{$brand_sql}
                AND opening_bank_name IS NOT NULL AND TRIM(opening_bank_name) <> ''",
            ...$params
        ));
    }

    $names = array_values($names);
    usort($names, 'strcasecmp');
    return $names;
}

/** Capital movement history, newest first. */
function capital_movement_history($tenant_id = null, $brand = '', $limit = 300) {
    global $current_user;
    if (!$current_user || !capital_table_ready()) return [];
    $tenant_id = $tenant_id === null ? (int)$current_user->tenant_id : (int)$tenant_id;
    $brand = capital_resolve_brand($brand);
    $limit = max(1, min(1000, (int)$limit));
    $brand_sql = $brand !== '' ? ' AND cm.brand = ?' : '';
    $params = $brand !== '' ? [$tenant_id, $brand] : [$tenant_id];
    return fetch_all(
        "SELECT cm.*, TRIM(CONCAT(COALESCE(u.first_name, ''), ' ', COALESCE(u.last_name, ''))) entered_by_name,
                u.username entered_by_username, cu.brand_scope creator_brand_scope
           FROM capital_movements cm
           LEFT JOIN users u ON u.user_id = cm.created_by
           LEFT JOIN users cu ON cu.user_id = cm.created_by
          WHERE cm.tenant_id = ?{$brand_sql}
          ORDER BY cm.entry_date DESC, cm.movement_id DESC
          LIMIT {$limit}",
        ...$params
    );
}

/** SQL condition restricting a warehouse column to a brand's shops. */
function capital_shop_warehouse_condition($column, $brand, $tenant_id, array &$params) {
    $column = preg_replace('/[^a-zA-Z0-9_.]/', '', (string)$column);
    if ($brand === '' || $column === '') return '';
    $params[] = (int)$tenant_id;
    $params[] = $brand;
    return " AND {$column} IN (
        SELECT cw.warehouse_id
          FROM warehouses cw
          JOIN shops cs ON cs.shop_id = cw.shop_id AND cs.tenant_id = cw.tenant_id
         WHERE cw.tenant_id = ? AND cs.brand = ?
    )";
}

/** SQL condition restricting orders to a brand (shop or warehouse brand). */
function capital_order_brand_condition($alias, $brand, $tenant_id, array &$params) {
    $alias = preg_replace('/[^a-zA-Z0-9_]/', '', (string)$alias);
    if ($alias === '') $alias = 'o';
    if ($brand === '') return '';
    $params[] = (int)$tenant_id;
    $params[] = $brand;
    $params[] = (int)$tenant_id;
    $params[] = $brand;
    return " AND (
        {$alias}.shop_id IN (
            SELECT os.shop_id FROM shops os
             WHERE os.tenant_id = ? AND os.brand = ?
        )
        OR {$alias}.warehouse_id IN (
            SELECT ow.warehouse_id FROM warehouses ow
              JOIN shops ows ON ows.shop_id = ow.shop_id AND ows.tenant_id = ow.tenant_id
             WHERE ow.tenant_id = ? AND ows.brand = ?
        )
    )";
}

/** SQL condition restricting sales returns to a brand. */
function capital_return_brand_condition($brand, $tenant_id, array &$params, $alias = 'sr') {
    $alias = preg_replace('/[^a-zA-Z0-9_]/', '', (string)$alias);
    if ($alias === '') $alias = 'sr';
    if ($brand === '') return '';
    $params[] = (int)$tenant_id;
    $params[] = $brand;
    $params[] = (int)$tenant_id;
    $params[] = $brand;
    return " AND (
        {$alias}.shop_id IN (
            SELECT rs.shop_id FROM shops rs
             WHERE rs.tenant_id = ? AND rs.brand = ?
        )
        OR {$alias}.warehouse_id IN (
            SELECT rw.warehouse_id FROM warehouses rw
              JOIN shops rs2 ON rs2.shop_id = rw.shop_id AND rs2.tenant_id = rw.tenant_id
             WHERE rw.tenant_id = ? AND rs2.brand = ?
        )
    )";
}

/** True when opening capital settings exist. */
function brand_capital_settings_table_ready() {
    return db_table_exists('brand_capital_settings');
}

/** True when internal transfer records exist. */
function brand_internal_transfers_table_ready() {
    return db_table_exists('brand_internal_transfers');
}

/**
 * Opening cash/bank for a brand. With no brand, the values are summed across
 * every visible brand for the combined view.
 */
function brand_capital_settings($tenant_id = null, $brand = '') {
    global $current_user;
    $empty = [
        'brand'        => '',
        'opening_cash' => 0.0,
        'opening_bank' => 0.0,
        'opening_bank_name' => '',
        'notes'        => '',
        'updated_at'   => null,
        'exists'       => false,
    ];
    if (!$current_user || !brand_capital_settings_table_ready()) return $empty;
    $tenant_id = $tenant_id === null ? (int)$current_user->tenant_id : (int)$tenant_id;
    $brand = capital_resolve_brand($brand);

    if ($brand === '') {
        $row = fetch_one(
            "SELECT COALESCE(SUM(opening_cash), 0) opening_cash,
                    COALESCE(SUM(opening_bank), 0) opening_bank,
                    COUNT(*) rows_count
               FROM brand_capital_settings
              WHERE tenant_id = ?",
            $tenant_id
        ) ?: [];
        return [
            'brand'        => '',
            'opening_cash' => (float)($row['opening_cash'] ?? 0),
            'opening_bank' => (float)($row['opening_bank'] ?? 0),
            'opening_bank_name' => '',
            'notes'        => '',
            'updated_at'   => null,
            'exists'       => (int)($row['rows_count'] ?? 0) > 0,
        ];
    }

    $row = fetch_one(
        "SELECT * FROM brand_capital_settings WHERE tenant_id = ? AND brand = ?",
        $tenant_id,
        $brand
    );
    if (!$row) {
        return array_merge($empty, ['brand' => $brand]);
    }
    return [
        'brand'        => (string)$row['brand'],
        'opening_cash' => (float)$row['opening_cash'],
        'opening_bank' => (float)$row['opening_bank'],
        'opening_bank_name' => capital_column_exists('brand_capital_settings', 'opening_bank_name')
            ? trim((string)($row['opening_bank_name'] ?? ''))
            : '',
        'notes'        => (string)($row['notes'] ?? ''),
        'updated_at'   => $row['updated_at'] ?? null,
        'exists'       => true,
    ];
}

/** Create or update a brand's opening cash and bank balances. */
function save_brand_capital_settings($tenant_id, $brand, $opening_cash, $opening_bank, $opening_bank_name, $notes, $user_id) {
    global $conn;
    $tenant_id = (int)$tenant_id;
    $brand = trim((string)$brand);
    if ($tenant_id <= 0 || $brand === '' || !brand_capital_settings_table_ready()) return false;
    $opening_cash = round((float)$opening_cash, 2);
    $opening_bank = round((float)$opening_bank, 2);
    $opening_bank_name = trim((string)$opening_bank_name);
    $notes = trim((string)$notes);
    $user_id = (int)$user_id;
    $has_bank_name = capital_column_exists('brand_capital_settings', 'opening_bank_name');
    $columns = 'tenant_id, brand, opening_cash, opening_bank, ' . ($has_bank_name ? 'opening_bank_name, ' : '') . 'notes, updated_by, created_at, updated_at';
    $values = '?, ?, ?, ?, ' . ($has_bank_name ? '?, ' : '') . '?, ?, NOW(), NOW()';
    $updates = 'opening_cash = VALUES(opening_cash), opening_bank = VALUES(opening_bank), '
        . ($has_bank_name ? 'opening_bank_name = VALUES(opening_bank_name), ' : '')
        . 'notes = VALUES(notes), updated_by = VALUES(updated_by), updated_at = NOW()';
    $stmt = $conn->prepare(
        "INSERT INTO brand_capital_settings ({$columns})
         VALUES ({$values})
         ON DUPLICATE KEY UPDATE {$updates}"
    );
    if (!$stmt) return false;
    if ($has_bank_name) {
        $stmt->bind_param('isdds' . 's' . 'i', $tenant_id, $brand, $opening_cash, $opening_bank, $opening_bank_name, $notes, $user_id);
    } else {
        $stmt->bind_param('isddsi', $tenant_id, $brand, $opening_cash, $opening_bank, $notes, $user_id);
    }
    $ok = $stmt->execute();
    $stmt->close();
    return (bool)$ok;
}

/** Totals of internal transfers between cash and bank. */
function brand_internal_transfer_totals($tenant_id = null, $brand = '') {
    global $current_user;
    $out = ['cash_to_bank' => 0.0, 'bank_to_cash' => 0.0];
    if (!$current_user || !brand_internal_transfers_table_ready()) return $out;
    $tenant_id = $tenant_id === null ? (int)$current_user->tenant_id : (int)$tenant_id;
    $brand = capital_resolve_brand($brand);
    $brand_sql = $brand !== '' ? ' AND brand = ?' : '';
    $params = $brand !== '' ? [$tenant_id, $brand] : [$tenant_id];
    $rows = fetch_all(
        "SELECT direction, COALESCE(SUM(amount), 0) total
           FROM brand_internal_transfers
          WHERE tenant_id = ?{$brand_sql}
          GROUP BY direction",
        ...$params
    );
    foreach ($rows as $row) {
        $direction = (string)$row['direction'];
        if (isset($out[$direction])) {
            $out[$direction] = (float)$row['total'];
        }
    }
    return $out;
}

/** Internal transfer history, newest first. */
function brand_internal_transfer_history($tenant_id = null, $brand = '', $limit = 200) {
    global $current_user;
    if (!$current_user || !brand_internal_transfers_table_ready()) return [];
    $tenant_id = $tenant_id === null ? (int)$current_user->tenant_id : (int)$tenant_id;
    $brand = capital_resolve_brand($brand);
    $limit = max(1, min(500, (int)$limit));
    $brand_sql = $brand !== '' ? ' AND t.brand = ?' : '';
    $params = $brand !== '' ? [$tenant_id, $brand] : [$tenant_id];
    return fetch_all(
        "SELECT t.*, TRIM(CONCAT(COALESCE(u.first_name, ''), ' ', COALESCE(u.last_name, ''))) entered_by_name,
                u.username entered_by_username
           FROM brand_internal_transfers t
           LEFT JOIN users u ON u.user_id = t.created_by
          WHERE t.tenant_id = ?{$brand_sql}
          ORDER BY t.entry_date DESC, t.transfer_id DESC
          LIMIT {$limit}",
        ...$params
    );
}

/** Sales money received by cash and bank for a brand. */
function capital_sales_receipts($tenant_id, $brand) {
    $tenant_id = (int)$tenant_id;
    $brand = capital_resolve_brand($brand);
    $out = [
        'pos_cash'      => 0.0,
        'pos_bank'      => 0.0,
        'invoice_cash'  => 0.0,
        'invoice_bank'  => 0.0,
        'order_bank'    => 0.0,
        'cash'          => 0.0,
        'bank'          => 0.0,
    ];
    $acct = capital_payment_account_expr('pm', 'bank');

    // POS payments.
    $params = [$tenant_id];
    $wh = capital_shop_warehouse_condition('o.warehouse_id', $brand, $tenant_id, $params);
    $row = fetch_one(
        "SELECT COALESCE(SUM(CASE WHEN {$acct} = 'cash' THEN pp.amount ELSE 0 END), 0) cash_amount,
                COALESCE(SUM(CASE WHEN {$acct} = 'bank' THEN pp.amount ELSE 0 END), 0) bank_amount
           FROM pos_payments pp
           JOIN pos_orders o ON o.order_id = pp.order_id AND o.tenant_id = ?
           LEFT JOIN payment_methods pm ON pm.payment_method_id = pp.payment_method_id AND pm.tenant_id = o.tenant_id
          WHERE o.status = 'completed'{$wh}",
        ...$params
    ) ?: [];
    $out['pos_cash'] = (float)($row['cash_amount'] ?? 0);
    $out['pos_bank'] = (float)($row['bank_amount'] ?? 0);

    // Invoice payments.
    $params = [$tenant_id];
    $wh = capital_shop_warehouse_condition('i.warehouse_id', $brand, $tenant_id, $params);
    $row = fetch_one(
        "SELECT COALESCE(SUM(CASE WHEN {$acct} = 'cash' THEN p.amount ELSE 0 END), 0) cash_amount,
                COALESCE(SUM(CASE WHEN {$acct} = 'bank' THEN p.amount ELSE 0 END), 0) bank_amount
           FROM payments p
           JOIN invoices i ON i.invoice_id = p.invoice_id AND i.tenant_id = p.tenant_id
           LEFT JOIN payment_methods pm ON pm.payment_method_id = p.payment_method_id AND pm.tenant_id = p.tenant_id
          WHERE p.tenant_id = ?{$wh}",
        ...$params
    ) ?: [];
    $out['invoice_cash'] = (float)($row['cash_amount'] ?? 0);
    $out['invoice_bank'] = (float)($row['bank_amount'] ?? 0);

    // Storefront order receipts are treated as bank receipts. Order returns
    // are already netted through returned_amount.
    $params = [$tenant_id];
    $ob = capital_order_brand_condition('o', $brand, $tenant_id, $params);
    $row = fetch_one(
        "SELECT COALESCE(SUM(COALESCE(o.Amount_paid, 0) - COALESCE(o.returned_amount, 0)), 0) t
           FROM orders o
          WHERE o.tenant_id = ? AND o.status NOT IN ('cancelled', 'rejected'){$ob}",
        ...$params
    ) ?: [];
    $out['order_bank'] = (float)($row['t'] ?? 0);

    $out['cash'] = $out['pos_cash'] + $out['invoice_cash'];
    $out['bank'] = $out['pos_bank'] + $out['invoice_bank'] + $out['order_bank'];
    return $out;
}

/** Expenses paid from cash and bank. */
function capital_expense_outflows($tenant_id, $brand) {
    $tenant_id = (int)$tenant_id;
    $brand = capital_resolve_brand($brand);
    $out = ['cash' => 0.0, 'bank' => 0.0];
    if (!db_table_exists('expenses')) return $out;
    $acct = capital_payment_account_expr('pm', 'cash');
    $brand_sql = $brand !== '' ? ' AND e.brand = ?' : '';
    $params = $brand !== '' ? [$tenant_id, $brand] : [$tenant_id];
    $row = fetch_one(
        "SELECT COALESCE(SUM(CASE WHEN {$acct} = 'cash' THEN e.amount ELSE 0 END), 0) cash_amount,
                COALESCE(SUM(CASE WHEN {$acct} = 'bank' THEN e.amount ELSE 0 END), 0) bank_amount
           FROM expenses e
           LEFT JOIN payment_methods pm ON pm.payment_method_id = e.payment_method_id AND pm.tenant_id = e.tenant_id
          WHERE e.tenant_id = ?{$brand_sql}",
        ...$params
    ) ?: [];
    $out['cash'] = (float)($row['cash_amount'] ?? 0);
    $out['bank'] = (float)($row['bank_amount'] ?? 0);
    return $out;
}

/** Supplier payments made from cash and bank (amount actually paid). */
function capital_purchase_outflows($tenant_id, $brand) {
    $tenant_id = (int)$tenant_id;
    $brand = capital_resolve_brand($brand);
    $out = ['cash' => 0.0, 'bank' => 0.0];
    if (!db_table_exists('purchases')) return $out;
    $acct = capital_payment_account_expr('pm', 'cash');
    $brand_sql = $brand !== '' ? ' AND p.brand = ?' : '';
    $params = $brand !== '' ? [$tenant_id, $brand] : [$tenant_id];
    $row = fetch_one(
        "SELECT COALESCE(SUM(CASE WHEN {$acct} = 'cash' THEN pu.amount_paid ELSE 0 END), 0) cash_amount,
                COALESCE(SUM(CASE WHEN {$acct} = 'bank' THEN pu.amount_paid ELSE 0 END), 0) bank_amount
           FROM purchases pu
           JOIN products p ON p.product_id = pu.product_id AND p.tenant_id = pu.tenant_id
           LEFT JOIN payment_methods pm ON pm.payment_method_id = pu.payment_method_id AND pm.tenant_id = pu.tenant_id
          WHERE pu.tenant_id = ?{$brand_sql}",
        ...$params
    ) ?: [];
    $out['cash'] = (float)($row['cash_amount'] ?? 0);
    $out['bank'] = (float)($row['bank_amount'] ?? 0);
    return $out;
}

/** Refunds paid from cash and bank for POS/invoice returns. */
function capital_refund_outflows($tenant_id, $brand) {
    $tenant_id = (int)$tenant_id;
    $brand = capital_resolve_brand($brand);
    $out = ['cash' => 0.0, 'bank' => 0.0];
    if (!db_table_exists('sales_returns')) return $out;
    $acct = capital_payment_account_expr('pm', 'cash');
    $params = [$tenant_id];
    $brand_sql = capital_return_brand_condition($brand, $tenant_id, $params, 'sr');
    $row = fetch_one(
        "SELECT COALESCE(SUM(CASE WHEN {$acct} = 'cash' THEN sr.refund_amount ELSE 0 END), 0) cash_amount,
                COALESCE(SUM(CASE WHEN {$acct} = 'bank' THEN sr.refund_amount ELSE 0 END), 0) bank_amount
           FROM sales_returns sr
           LEFT JOIN payment_methods pm ON pm.payment_method_id = sr.refund_method_id AND pm.tenant_id = sr.tenant_id
          WHERE sr.tenant_id = ? AND sr.status = 'completed'
            AND sr.source_type IN ('pos', 'invoice'){$brand_sql}",
        ...$params
    ) ?: [];
    $out['cash'] = (float)($row['cash_amount'] ?? 0);
    $out['bank'] = (float)($row['bank_amount'] ?? 0);
    return $out;
}

/** Current inventory value at product purchase price. */
function capital_stock_value($tenant_id, $brand) {
    $tenant_id = (int)$tenant_id;
    $brand = capital_resolve_brand($brand);
    $product_sql = $brand !== '' ? ' AND p.brand = ?' : '';
    $params = $brand !== '' ? [$tenant_id, $brand] : [$tenant_id];
    $row = fetch_one(
        "SELECT COALESCE(SUM(s.quantity * p.purchase_price), 0) t
           FROM inventory_stock s
           JOIN products p ON p.product_id = s.product_id AND p.tenant_id = s.tenant_id
          WHERE s.tenant_id = ?{$product_sql}",
        ...$params
    ) ?: [];
    return (float)($row['t'] ?? 0);
}

/**
 * Dual-tier capital: Cash in Hand, Bank Balance, Liquid Capital and Stock
 * Capital for one brand ('' = every brand combined).
 */
function brand_capital_breakdown($tenant_id = null, $brand = '') {
    global $current_user;
    $empty = [
        'brand' => '',
        'opening_cash' => 0.0, 'opening_bank' => 0.0,
        'cash_contributions' => 0.0, 'cash_withdrawals' => 0.0,
        'bank_contributions' => 0.0, 'bank_withdrawals' => 0.0,
        'capital_contributions' => 0.0, 'capital_withdrawals' => 0.0, 'capital_net' => 0.0, 'opening_capital' => 0.0,
        'pos_cash' => 0.0, 'pos_bank' => 0.0, 'invoice_cash' => 0.0, 'invoice_bank' => 0.0,
        'order_bank' => 0.0, 'cash_receipts' => 0.0, 'bank_receipts' => 0.0,
        'cash_expenses' => 0.0, 'bank_expenses' => 0.0,
        'cash_purchases' => 0.0, 'bank_purchases' => 0.0,
        'cash_refunds' => 0.0, 'bank_refunds' => 0.0,
        'cash_banked' => 0.0,
        'transfers_cash_to_bank' => 0.0, 'transfers_bank_to_cash' => 0.0,
        'cash' => 0.0, 'bank' => 0.0, 'liquid' => 0.0, 'stock' => 0.0, 'total' => 0.0,
        'bank_allocated' => 0.0, 'bank_unallocated' => 0.0, 'banks' => [],
    ];
    if (!$current_user) return $empty;
    $tenant_id = $tenant_id === null ? (int)$current_user->tenant_id : (int)$tenant_id;
    $brand = capital_resolve_brand($brand);
    $empty['brand'] = $brand;

    $settings = brand_capital_settings($tenant_id, $brand);
    $capital = capital_summary($tenant_id, $brand);
    $receipts = capital_sales_receipts($tenant_id, $brand);
    $expenses = capital_expense_outflows($tenant_id, $brand);
    $purchases = capital_purchase_outflows($tenant_id, $brand);
    $refunds = capital_refund_outflows($tenant_id, $brand);
    $transfers = brand_internal_transfer_totals($tenant_id, $brand);
    $stock = capital_stock_value($tenant_id, $brand);

    $cash_banked = 0.0;
    if (db_table_exists('bank_sales_deposits')) {
        $brand_sql = $brand !== '' ? ' AND brand = ?' : '';
        $params = $brand !== '' ? [$tenant_id, $brand] : [$tenant_id];
        $row = fetch_one(
            "SELECT COALESCE(SUM(amount), 0) t FROM bank_sales_deposits
              WHERE tenant_id = ?{$brand_sql}",
            ...$params
        ) ?: [];
        $cash_banked = (float)($row['t'] ?? 0);
    }

    $cash = (float)$settings['opening_cash']
        + (float)$capital['cash_contributions']
        - (float)$capital['cash_withdrawals']
        + (float)$receipts['pos_cash']
        + (float)$receipts['invoice_cash']
        - (float)$expenses['cash']
        - (float)$purchases['cash']
        - (float)$refunds['cash']
        - $cash_banked
        - (float)$transfers['cash_to_bank']
        + (float)$transfers['bank_to_cash'];

    $bank = (float)$settings['opening_bank']
        + (float)$capital['bank_contributions']
        - (float)$capital['bank_withdrawals']
        + (float)$receipts['pos_bank']
        + (float)$receipts['invoice_bank']
        + (float)$receipts['order_bank']
        - (float)$expenses['bank']
        - (float)$purchases['bank']
        - (float)$refunds['bank']
        + $cash_banked
        + (float)$transfers['cash_to_bank']
        - (float)$transfers['bank_to_cash'];

    $liquid = $cash + $bank;
    $total = $liquid + $stock;
    $bank_accounts = bank_accounts_summary($tenant_id, $brand);
    $bank_allocated = (float)($bank_accounts['available'] ?? 0);

    return [
        'brand' => $brand,
        'opening_cash' => (float)$settings['opening_cash'],
        'opening_bank' => (float)$settings['opening_bank'],
        'cash_contributions' => (float)$capital['cash_contributions'],
        'cash_withdrawals' => (float)$capital['cash_withdrawals'],
        'bank_contributions' => (float)$capital['bank_contributions'],
        'bank_withdrawals' => (float)$capital['bank_withdrawals'],
        'capital_contributions' => (float)$capital['contributions'],
        'capital_withdrawals' => (float)$capital['withdrawals'],
        'capital_net' => (float)$capital['net'] + (float)$settings['opening_cash'] + (float)$settings['opening_bank'],
        'opening_capital' => (float)$settings['opening_cash'] + (float)$settings['opening_bank'],
        'pos_cash' => (float)$receipts['pos_cash'],
        'pos_bank' => (float)$receipts['pos_bank'],
        'invoice_cash' => (float)$receipts['invoice_cash'],
        'invoice_bank' => (float)$receipts['invoice_bank'],
        'order_bank' => (float)$receipts['order_bank'],
        'cash_receipts' => (float)$receipts['cash'],
        'bank_receipts' => (float)$receipts['bank'],
        'cash_expenses' => (float)$expenses['cash'],
        'bank_expenses' => (float)$expenses['bank'],
        'cash_purchases' => (float)$purchases['cash'],
        'bank_purchases' => (float)$purchases['bank'],
        'cash_refunds' => (float)$refunds['cash'],
        'bank_refunds' => (float)$refunds['bank'],
        'cash_banked' => $cash_banked,
        'transfers_cash_to_bank' => (float)$transfers['cash_to_bank'],
        'transfers_bank_to_cash' => (float)$transfers['bank_to_cash'],
        'cash' => $cash,
        'bank' => $bank,
        'liquid' => $liquid,
        'stock' => $stock,
        'total' => $total,
        'bank_allocated' => $bank_allocated,
        'bank_unallocated' => $bank - $bank_allocated,
        'banks' => $bank_accounts['banks'] ?? [],
    ];
}

/** Dual-tier capital for every brand visible to the signed-in user. */
function brand_capital_breakdown_by_brand($tenant_id = null) {
    global $current_user;
    $tenant_id = $tenant_id === null ? (int)$current_user->tenant_id : (int)$tenant_id;
    $rows = [];
    foreach (capital_visible_brands() as $brand) {
        $rows[] = brand_capital_breakdown($tenant_id, $brand);
    }
    return $rows;
}


/**
 * Brand balance sheet. Assets (cash in hand, bank, stock, receivables)
 * against capital, retained earnings and supplier payables.
 * All figures are limited to the selected brand ('' = every brand).
 */
function capital_balance_sheet($tenant_id = null, $brand = '') {
    global $current_user;
    if (!$current_user) return [];
    $tenant_id = $tenant_id === null ? (int)$current_user->tenant_id : (int)$tenant_id;
    $brand = capital_resolve_brand($brand);
    $summary = capital_summary($tenant_id, $brand);
    $breakdown = brand_capital_breakdown($tenant_id, $brand);

    // POS sales.
    $params = [$tenant_id];
    $wh = capital_shop_warehouse_condition('o.warehouse_id', $brand, $tenant_id, $params);
    $row = fetch_one(
        "SELECT COALESCE(SUM(o.total), 0) t FROM pos_orders o
          WHERE o.tenant_id = ? AND o.status = 'completed'{$wh}",
        ...$params
    );
    $pos_sales = (float)($row['t'] ?? 0);

    // Invoice payments received.
    $params = [$tenant_id];
    $wh = capital_shop_warehouse_condition('i.warehouse_id', $brand, $tenant_id, $params);
    $row = fetch_one(
        "SELECT COALESCE(SUM(p.amount), 0) t
           FROM payments p
           JOIN invoices i ON i.invoice_id = p.invoice_id AND i.tenant_id = p.tenant_id
          WHERE p.tenant_id = ?{$wh}",
        ...$params
    );
    $invoice_payments = (float)($row['t'] ?? 0);

    // Storefront order sales, already net of order returns.
    $params = [$tenant_id];
    $ob = capital_order_brand_condition('o', $brand, $tenant_id, $params);
    $row = fetch_one(
        "SELECT COALESCE(SUM(COALESCE(o.Amount_paid, 0) - COALESCE(o.returned_amount, 0)), 0) t
           FROM orders o
          WHERE o.tenant_id = ? AND o.status NOT IN ('cancelled', 'rejected'){$ob}",
        ...$params
    );
    $order_sales = (float)($row['t'] ?? 0);

    // Accepted POS/invoice returns. Order returns are already netted in
    // order_sales, so they are excluded here to avoid double subtraction.
    $params = [$tenant_id];
    $return_sql = capital_return_brand_condition($brand, $tenant_id, $params);
    $row = fetch_one(
        "SELECT COALESCE(SUM(sr.refund_amount), 0) t FROM sales_returns sr
          WHERE sr.tenant_id = ? AND sr.status = 'completed'
            AND sr.source_type IN ('pos', 'invoice'){$return_sql}",
        ...$params
    );
    $returns = (float)($row['t'] ?? 0);

    // Expenses (brand-attributed).
    $params = [$tenant_id];
    $expense_sql = '';
    if ($brand !== '') {
        $expense_sql = ' AND e.brand = ?';
        $params[] = $brand;
    }
    $row = fetch_one(
        "SELECT COALESCE(SUM(e.amount), 0) t FROM expenses e WHERE e.tenant_id = ?{$expense_sql}",
        ...$params
    );
    $expenses = (float)($row['t'] ?? 0);

    $inventory = (float)$breakdown['stock'];

    // Outstanding invoice balances.
    $params = [$tenant_id];
    $wh = capital_shop_warehouse_condition('i.warehouse_id', $brand, $tenant_id, $params);
    $row = fetch_one(
        "SELECT COALESCE(SUM(i.amount_due), 0) t
           FROM invoices i
           LEFT JOIN document_statuses ds ON ds.status_id = i.status_id AND ds.tenant_id = i.tenant_id
          WHERE i.tenant_id = ? AND i.is_paid = 0
            AND (ds.name IS NULL OR ds.name <> 'Cancelled'){$wh}",
        ...$params
    );
    $receivables = (float)($row['t'] ?? 0);

    // Supplier balances still owed.
    $params = [$tenant_id];
    $product_sql = '';
    if ($brand !== '') {
        $product_sql = ' AND p.brand = ?';
        $params[] = $brand;
    }
    $row = fetch_one(
        "SELECT COALESCE(SUM(GREATEST(pu.total - pu.amount_paid, 0)), 0) t
           FROM purchases pu
           JOIN products p ON p.product_id = pu.product_id AND p.tenant_id = pu.tenant_id
          WHERE pu.tenant_id = ?{$product_sql}",
        ...$params
    );
    $payables = (float)($row['t'] ?? 0);

    $revenue = $pos_sales + $invoice_payments + $order_sales;
    $net_profit = $revenue - $returns - $expenses;
    $opening_capital = (float)$breakdown['opening_cash'] + (float)$breakdown['opening_bank'];
    $capital = $summary['net'] + $opening_capital;
    $cash_in_hand = (float)$breakdown['cash'];
    $bank_balance = (float)$breakdown['bank'];

    $total_assets = $cash_in_hand + $bank_balance + $inventory + $receivables;
    $total_funding = $capital + $net_profit + $payables;

    return [
        'brand'                 => $brand,
        'capital_contributions' => $summary['contributions'],
        'capital_withdrawals'   => $summary['withdrawals'],
        'capital_net'           => $capital,
        'opening_capital'       => $opening_capital,
        'bank_net'              => $summary['bank_net'],
        'bank_available'        => (float)$breakdown['bank_allocated'],
        'banks'                 => $breakdown['banks'],
        'pos_sales'             => $pos_sales,
        'invoice_payments'      => $invoice_payments,
        'order_sales'           => $order_sales,
        'returns'               => $returns,
        'expenses'              => $expenses,
        'net_profit'            => $net_profit,
        'inventory'             => $inventory,
        'stock_capital'         => $inventory,
        'receivables'           => $receivables,
        'payables'              => $payables,
        'cash_in_hand'          => $cash_in_hand,
        'bank_balance'          => $bank_balance,
        'liquid_capital'        => (float)$breakdown['liquid'],
        'total_capital'         => (float)$breakdown['total'],
        'cash'                  => $cash_in_hand,
        'total_assets'          => $total_assets,
        'total_funding'         => $total_funding,
        'difference'            => $total_assets - $total_funding,
        'breakdown'             => $breakdown,
    ];
}

/** Balance sheets for every brand visible to the signed-in user. */
function capital_balance_sheet_by_brand($tenant_id = null) {
    global $current_user;
    $tenant_id = $tenant_id === null ? (int)$current_user->tenant_id : (int)$tenant_id;
    $rows = [];
    foreach (capital_visible_brands() as $brand) {
        $rows[] = capital_balance_sheet($tenant_id, $brand);
    }
    return $rows;
}


/** Find an existing bank account name for a brand (case-insensitive). */
function bank_canonical_name($tenant_id, $brand, $bank_name) {
    $tenant_id = (int)$tenant_id;
    $brand = capital_resolve_brand($brand);
    $bank_name = trim((string)$bank_name);
    if ($tenant_id <= 0 || $brand === '' || $bank_name === '') return '';
    foreach (capital_bank_names($tenant_id, $brand) as $existing) {
        if (strcasecmp(trim($existing), $bank_name) === 0) {
            return $existing;
        }
    }
    return '';
}

/**
 * Cash money that should be banked for one business date.
 * Only cash receipts are banked; non-cash receipts already sit in the bank.
 */
function bank_sales_summary($tenant_id = null, $brand = '', $date = '') {
    global $current_user;
    $empty = [
        'date' => $date,
        'pos_sales' => 0.0,
        'pos_cash' => 0.0,
        'pos_bank' => 0.0,
        'invoice_payments' => 0.0,
        'invoice_cash' => 0.0,
        'invoice_bank' => 0.0,
        'returns' => 0.0,
        'cash_refunds' => 0.0,
        'bank_refunds' => 0.0,
        'cash_receipts' => 0.0,
        'bank_receipts' => 0.0,
        'cash_net' => 0.0,
        'bank_net' => 0.0,
        'gross' => 0.0,
        'net' => 0.0,
    ];
    if (!$current_user) return $empty;
    $tenant_id = $tenant_id === null ? (int)$current_user->tenant_id : (int)$tenant_id;
    $brand = capital_resolve_brand($brand);
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$date)) {
        $date = date('Y-m-d');
    }
    $empty['date'] = $date;
    $acct = capital_payment_account_expr('pm', 'bank');

    // POS payments for the date.
    $params = [$tenant_id, $date];
    $wh = capital_shop_warehouse_condition('o.warehouse_id', $brand, $tenant_id, $params);
    $row = fetch_one(
        "SELECT COALESCE(SUM(CASE WHEN {$acct} = 'cash' THEN pp.amount ELSE 0 END), 0) cash_amount,
                COALESCE(SUM(CASE WHEN {$acct} = 'bank' THEN pp.amount ELSE 0 END), 0) bank_amount
           FROM pos_payments pp
           JOIN pos_orders o ON o.order_id = pp.order_id AND o.tenant_id = ?
           LEFT JOIN payment_methods pm ON pm.payment_method_id = pp.payment_method_id AND pm.tenant_id = o.tenant_id
          WHERE o.status = 'completed' AND DATE(o.order_date) = ?{$wh}",
        ...$params
    ) ?: [];
    $pos_cash = (float)($row['cash_amount'] ?? 0);
    $pos_bank = (float)($row['bank_amount'] ?? 0);

    // Invoice payments received on the date.
    $params = [$tenant_id, $date];
    $wh = capital_shop_warehouse_condition('i.warehouse_id', $brand, $tenant_id, $params);
    $row = fetch_one(
        "SELECT COALESCE(SUM(CASE WHEN {$acct} = 'cash' THEN p.amount ELSE 0 END), 0) cash_amount,
                COALESCE(SUM(CASE WHEN {$acct} = 'bank' THEN p.amount ELSE 0 END), 0) bank_amount
           FROM payments p
           JOIN invoices i ON i.invoice_id = p.invoice_id AND i.tenant_id = p.tenant_id
           LEFT JOIN payment_methods pm ON pm.payment_method_id = p.payment_method_id AND pm.tenant_id = p.tenant_id
          WHERE p.tenant_id = ? AND DATE(p.payment_date) = ?{$wh}",
        ...$params
    ) ?: [];
    $invoice_cash = (float)($row['cash_amount'] ?? 0);
    $invoice_bank = (float)($row['bank_amount'] ?? 0);

    // POS/invoice refunds paid on the date.
    $params = [$tenant_id, $date];
    $return_sql = capital_return_brand_condition($brand, $tenant_id, $params);
    $row = fetch_one(
        "SELECT COALESCE(SUM(CASE WHEN {$acct} = 'cash' THEN sr.refund_amount ELSE 0 END), 0) cash_amount,
                COALESCE(SUM(CASE WHEN {$acct} = 'bank' THEN sr.refund_amount ELSE 0 END), 0) bank_amount
           FROM sales_returns sr
           LEFT JOIN payment_methods pm ON pm.payment_method_id = sr.refund_method_id AND pm.tenant_id = sr.tenant_id
          WHERE sr.tenant_id = ? AND sr.status = 'completed'
            AND sr.source_type IN ('pos', 'invoice')
            AND DATE(sr.return_date) = ?{$return_sql}",
        ...$params
    ) ?: [];
    $cash_refunds = (float)($row['cash_amount'] ?? 0);
    $bank_refunds = (float)($row['bank_amount'] ?? 0);

    $cash_receipts = $pos_cash + $invoice_cash;
    $bank_receipts = $pos_bank + $invoice_bank;
    $cash_net = max(0.0, $cash_receipts - $cash_refunds);
    $bank_net = $bank_receipts - $bank_refunds;

    return [
        'date' => $date,
        'pos_sales' => $pos_cash + $pos_bank,
        'pos_cash' => $pos_cash,
        'pos_bank' => $pos_bank,
        'invoice_payments' => $invoice_cash + $invoice_bank,
        'invoice_cash' => $invoice_cash,
        'invoice_bank' => $invoice_bank,
        'returns' => $cash_refunds + $bank_refunds,
        'cash_refunds' => $cash_refunds,
        'bank_refunds' => $bank_refunds,
        'cash_receipts' => $cash_receipts,
        'bank_receipts' => $bank_receipts,
        'cash_net' => $cash_net,
        'bank_net' => $bank_net,
        'gross' => $cash_receipts,
        'net' => $cash_net,
    ];
}

/** Sales already deposited to banks for one business date. */
function bank_sales_deposited($tenant_id = null, $brand = '', $date = '') {
    global $current_user;
    if (!$current_user || !db_table_exists('bank_sales_deposits')) return 0.0;
    $tenant_id = $tenant_id === null ? (int)$current_user->tenant_id : (int)$tenant_id;
    $brand = capital_resolve_brand($brand);
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$date)) $date = date('Y-m-d');
    $brand_sql = $brand !== '' ? ' AND brand = ?' : '';
    $params = $brand !== '' ? [$tenant_id, $date, $brand] : [$tenant_id, $date];
    $row = fetch_one(
        "SELECT COALESCE(SUM(amount), 0) t FROM bank_sales_deposits
          WHERE tenant_id = ? AND business_date = ?{$brand_sql}",
        ...$params
    );
    return (float)($row['t'] ?? 0);
}

/** Sales still waiting to be banked for a business date. */
function bank_unbanked_sales($tenant_id = null, $brand = '', $date = '') {
    $summary = bank_sales_summary($tenant_id, $brand, $date);
    $deposited = bank_sales_deposited($tenant_id, $brand, $summary['date']);
    return max(0.0, $summary['net'] - $deposited);
}


/**
 * Bank account balances. Each bank gets:
 * capital in - capital out + sales banked + transfers in - transfers out
 * - bank expenses - bank purchases - bank refunds.
 * Withdrawals are limited to the `available` figure of the selected bank.
 */
function bank_accounts_summary($tenant_id = null, $brand = '') {
    global $current_user;
    $empty = [
        'banks' => [],
        'capital_in' => 0.0,
        'opening_in' => 0.0,
        'capital_out' => 0.0,
        'sales_banked' => 0.0,
        'expenses_out' => 0.0,
        'purchases_out' => 0.0,
        'refunds_out' => 0.0,
        'transfers_in' => 0.0,
        'transfers_out' => 0.0,
        'available' => 0.0,
    ];
    if (!$current_user) return $empty;
    $tenant_id = $tenant_id === null ? (int)$current_user->tenant_id : (int)$tenant_id;
    $brand = capital_resolve_brand($brand);

    $banks = [];
    $ensure = function ($name) use (&$banks) {
        $key = strtolower(trim((string)$name));
        if ($key === '') return '';
        if (!isset($banks[$key])) {
            $banks[$key] = [
                'bank_name' => trim((string)$name),
                'capital_in' => 0.0,
                'opening_in' => 0.0,
                'capital_out' => 0.0,
                'sales_banked' => 0.0,
                'expenses_out' => 0.0,
                'purchases_out' => 0.0,
                'refunds_out' => 0.0,
                'transfers_in' => 0.0,
                'transfers_out' => 0.0,
                'available' => 0.0,
            ];
        }
        return $key;
    };

    // Capital movements (bank only).
    if (capital_table_ready()) {
        $brand_sql = $brand !== '' ? ' AND cm.brand = ?' : '';
        $params = $brand !== '' ? [$tenant_id, $brand] : [$tenant_id];
        $acct = capital_movement_account_expr('cm');
        $rows = fetch_all(
            "SELECT cm.bank_name,
                    COALESCE(SUM(CASE WHEN cm.movement_type = 'contribution' THEN cm.amount ELSE 0 END), 0) capital_in,
                    COALESCE(SUM(CASE WHEN cm.movement_type = 'withdrawal' THEN cm.amount ELSE 0 END), 0) capital_out
               FROM capital_movements cm
              WHERE cm.tenant_id = ?{$brand_sql} AND {$acct} = 'bank'
              GROUP BY cm.bank_name",
            ...$params
        );
        foreach ($rows as $row) {
            $key = $ensure($row['bank_name']);
            if ($key === '') continue;
            $banks[$key]['capital_in'] += (float)$row['capital_in'];
            $banks[$key]['capital_out'] += (float)$row['capital_out'];
        }
    }

    // Opening bank balances allocated to the selected bank account(s).
    if (brand_capital_settings_table_ready()) {
        if ($brand !== '') {
            $opening_settings = brand_capital_settings($tenant_id, $brand);
            if ((float)$opening_settings['opening_bank'] > 0 && trim((string)$opening_settings['opening_bank_name']) !== '') {
                $opening_key = $ensure($opening_settings['opening_bank_name']);
                if ($opening_key !== '') {
                    $banks[$opening_key]['opening_in'] += (float)$opening_settings['opening_bank'];
                }
            }
        } elseif (capital_column_exists('brand_capital_settings', 'opening_bank_name')) {
            $opening_rows = fetch_all(
                "SELECT opening_bank, opening_bank_name FROM brand_capital_settings
                  WHERE tenant_id = ? AND opening_bank > 0
                    AND opening_bank_name IS NOT NULL AND TRIM(opening_bank_name) <> ''",
                $tenant_id
            );
            foreach ($opening_rows as $opening_row) {
                $opening_key = $ensure($opening_row['opening_bank_name']);
                if ($opening_key !== '') {
                    $banks[$opening_key]['opening_in'] += (float)$opening_row['opening_bank'];
                }
            }
        }
    }

    // Cash banked through Close Business.
    if (db_table_exists('bank_sales_deposits')) {
        $brand_sql = $brand !== '' ? ' AND brand = ?' : '';
        $params = $brand !== '' ? [$tenant_id, $brand] : [$tenant_id];
        $rows = fetch_all(
            "SELECT bank_name, COALESCE(SUM(amount), 0) sales_banked
               FROM bank_sales_deposits
              WHERE tenant_id = ?{$brand_sql}
              GROUP BY bank_name",
            ...$params
        );
        foreach ($rows as $row) {
            $key = $ensure($row['bank_name']);
            if ($key === '') continue;
            $banks[$key]['sales_banked'] += (float)$row['sales_banked'];
        }
    }

    // Expenses paid from a named bank account.
    if (db_table_exists('expenses') && capital_column_exists('expenses', 'bank_name')) {
        $acct = capital_payment_account_expr('pm', 'cash');
        $brand_sql = $brand !== '' ? ' AND e.brand = ?' : '';
        $params = $brand !== '' ? [$tenant_id, $brand] : [$tenant_id];
        $rows = fetch_all(
            "SELECT e.bank_name, COALESCE(SUM(e.amount), 0) expenses_out
               FROM expenses e
               LEFT JOIN payment_methods pm ON pm.payment_method_id = e.payment_method_id AND pm.tenant_id = e.tenant_id
              WHERE e.tenant_id = ?{$brand_sql} AND {$acct} = 'bank'
                AND e.bank_name IS NOT NULL AND TRIM(e.bank_name) <> ''
              GROUP BY e.bank_name",
            ...$params
        );
        foreach ($rows as $row) {
            $key = $ensure($row['bank_name']);
            if ($key === '') continue;
            $banks[$key]['expenses_out'] += (float)$row['expenses_out'];
        }
    }

    // Supplier payments made from a named bank account.
    if (db_table_exists('purchases') && capital_column_exists('purchases', 'bank_name')) {
        $acct = capital_payment_account_expr('pm', 'cash');
        $brand_sql = $brand !== '' ? ' AND p.brand = ?' : '';
        $params = $brand !== '' ? [$tenant_id, $brand] : [$tenant_id];
        $rows = fetch_all(
            "SELECT pu.bank_name, COALESCE(SUM(pu.amount_paid), 0) purchases_out
               FROM purchases pu
               JOIN products p ON p.product_id = pu.product_id AND p.tenant_id = pu.tenant_id
               LEFT JOIN payment_methods pm ON pm.payment_method_id = pu.payment_method_id AND pm.tenant_id = pu.tenant_id
              WHERE pu.tenant_id = ?{$brand_sql} AND {$acct} = 'bank'
                AND pu.bank_name IS NOT NULL AND TRIM(pu.bank_name) <> ''
              GROUP BY pu.bank_name",
            ...$params
        );
        foreach ($rows as $row) {
            $key = $ensure($row['bank_name']);
            if ($key === '') continue;
            $banks[$key]['purchases_out'] += (float)$row['purchases_out'];
        }
    }

    // Refunds paid from a named bank account.
    if (db_table_exists('sales_returns') && capital_column_exists('sales_returns', 'refund_bank_name')) {
        $acct = capital_payment_account_expr('pm', 'cash');
        $params = [$tenant_id];
        $return_sql = capital_return_brand_condition($brand, $tenant_id, $params, 'sr');
        $rows = fetch_all(
            "SELECT sr.refund_bank_name AS bank_name, COALESCE(SUM(sr.refund_amount), 0) refunds_out
               FROM sales_returns sr
               LEFT JOIN payment_methods pm ON pm.payment_method_id = sr.refund_method_id AND pm.tenant_id = sr.tenant_id
              WHERE sr.tenant_id = ? AND sr.status = 'completed'
                AND sr.source_type IN ('pos', 'invoice')
                AND {$acct} = 'bank'
                AND sr.refund_bank_name IS NOT NULL AND TRIM(sr.refund_bank_name) <> ''{$return_sql}
              GROUP BY sr.refund_bank_name",
            ...$params
        );
        foreach ($rows as $row) {
            $key = $ensure($row['bank_name']);
            if ($key === '') continue;
            $banks[$key]['refunds_out'] += (float)$row['refunds_out'];
        }
    }

    // Internal transfers in and out of each bank.
    if (brand_internal_transfers_table_ready()) {
        $brand_sql = $brand !== '' ? ' AND t.brand = ?' : '';
        $params = $brand !== '' ? [$tenant_id, $brand] : [$tenant_id];
        $rows = fetch_all(
            "SELECT t.bank_name, t.direction, COALESCE(SUM(t.amount), 0) total
               FROM brand_internal_transfers t
              WHERE t.tenant_id = ?{$brand_sql}
              GROUP BY t.bank_name, t.direction",
            ...$params
        );
        foreach ($rows as $row) {
            $key = $ensure($row['bank_name']);
            if ($key === '') continue;
            if ((string)$row['direction'] === 'cash_to_bank') {
                $banks[$key]['transfers_in'] += (float)$row['total'];
            } elseif ((string)$row['direction'] === 'bank_to_cash') {
                $banks[$key]['transfers_out'] += (float)$row['total'];
            }
        }
    }

    $totals = [
        'capital_in' => 0.0,
        'opening_in' => 0.0,
        'capital_out' => 0.0,
        'sales_banked' => 0.0,
        'expenses_out' => 0.0,
        'purchases_out' => 0.0,
        'refunds_out' => 0.0,
        'transfers_in' => 0.0,
        'transfers_out' => 0.0,
        'available' => 0.0,
    ];
    foreach ($banks as &$bank) {
        $bank['available'] = $bank['opening_in'] + $bank['capital_in'] - $bank['capital_out'] + $bank['sales_banked']
            + $bank['transfers_in'] - $bank['transfers_out']
            - $bank['expenses_out'] - $bank['purchases_out'] - $bank['refunds_out'];
        foreach ($totals as $key => $value) {
            $totals[$key] += (float)$bank[$key];
        }
    }
    unset($bank);

    usort($banks, fn($a, $b) => strcasecmp($a['bank_name'], $b['bank_name']));

    return array_merge($empty, $totals, ['banks' => array_values($banks)]);
}

/** Current available balance for one bank account. */
function bank_available_balance($tenant_id, $brand, $bank_name) {
    $bank_name = strtolower(trim((string)$bank_name));
    if ($bank_name === '') return 0.0;
    $summary = bank_accounts_summary($tenant_id, $brand);
    foreach ($summary['banks'] as $bank) {
        if (strtolower(trim($bank['bank_name'])) === $bank_name) {
            return (float)$bank['available'];
        }
    }
    return 0.0;
}

/** Sales banking history for a brand (optionally one date). */
function bank_sales_deposit_history($tenant_id = null, $brand = '', $date = '', $limit = 100) {
    global $current_user;
    if (!$current_user || !db_table_exists('bank_sales_deposits')) return [];
    $tenant_id = $tenant_id === null ? (int)$current_user->tenant_id : (int)$tenant_id;
    $brand = capital_resolve_brand($brand);
    $limit = max(1, min(500, (int)$limit));
    $sql = "SELECT d.*, TRIM(CONCAT(COALESCE(u.first_name, ''), ' ', COALESCE(u.last_name, ''))) entered_by_name,
                   u.username entered_by_username
              FROM bank_sales_deposits d
              LEFT JOIN users u ON u.user_id = d.created_by
             WHERE d.tenant_id = ?";
    $params = [$tenant_id];
    if ($brand !== '') {
        $sql .= " AND d.brand = ?";
        $params[] = $brand;
    }
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$date)) {
        $sql .= " AND d.business_date = ?";
        $params[] = $date;
    }
    $sql .= " ORDER BY d.business_date DESC, d.deposit_id DESC LIMIT {$limit}";
    return fetch_all($sql, ...$params);
}

