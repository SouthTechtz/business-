<?php
// ============================================================
// Demo data seeder — Business Suite
// Run: php database/seed_demo.php
// Fills the database with realistic demo records so the
// client presentation shows a complete, working business.
// NOTE: This script WIPES demo records (sales, invoices,
// payments, contacts, products, inventory) and re-creates them.
// ============================================================
require_once __DIR__ . '/../config.php';

$conn->query("SET FOREIGN_KEY_CHECKS = 0");
foreach ([
    'expenses', 'pos_order_items', 'pos_payments',
    'payments', 'invoice_items', 'invoices',
    'inventory_movements', 'inventory_stock', 'products',
    'product_categories', 'suppliers', 'contacts', 'audit_logs',
] as $t) {
    $conn->query("DELETE FROM `$t`" . (in_array($t, ['pos_order_items', 'pos_payments']) ? '' : ' WHERE tenant_id = 1'));
}
$conn->query("DELETE FROM pos_orders WHERE tenant_id = 1");
$conn->query("DELETE FROM pos_sessions WHERE tenant_id = 1");
$conn->query("ALTER TABLE pos_orders AUTO_INCREMENT = 1");
$conn->query("ALTER TABLE invoices AUTO_INCREMENT = 1");
$conn->query("SET FOREIGN_KEY_CHECKS = 1");

// Reset counters so demo IDs are deterministic on every run
foreach (['pos_orders', 'pos_sessions', 'pos_order_items', 'pos_payments', 'invoices', 'invoice_items',
          'payments', 'inventory_stock', 'inventory_movements', 'products', 'product_categories',
          'suppliers', 'contacts', 'expenses', 'expense_categories', 'audit_logs'] as $t) {
    $conn->query("ALTER TABLE `$t` AUTO_INCREMENT = 1");
}

$conn->query("UPDATE system_settings SET invoice_next_number = 1001 WHERE tenant_id = 1");

$tid = 1;
$uid = 1;

// ---------- Customers ----------
$customers = [
    ['Neema', 'Mkapa', 'Neema Traders', 'neema@example.com', '+255 712 345 001', 'Dar es Salaam'],
    ['Juma', 'Salum', 'Juma Electronics', 'juma@example.com', '+255 713 345 002', 'Dar es Salaam'],
    ['Baraka', 'Mwinyi', 'Baraka Supermarket', 'baraka@example.com', '+255 714 345 003', 'Arusha'],
    ['Safari', 'Kileo', 'Safari Hotel & Lodge', 'safari@example.com', '+255 715 345 004', 'Mwanza'],
    ['Tumaini', 'Kassim', 'Tumaini Pharmacy', 'tumaini@example.com', '+255 716 345 005', 'Dodoma'],
    ['Azam', 'Rashid', 'Azam Distribution', 'azam@example.com', '+255 717 345 006', 'Mbeya'],
];
$cust_ids = [];
foreach ($customers as $c) {
    $cust_ids[] = db_insert('contacts', [
        'tenant_id' => $tid, 'contact_type_id' => 1, 'first_name' => $c[0],
        'last_name' => $c[1], 'company_name' => $c[2],
        'email' => $c[3], 'phone' => $c[4], 'address' => $c[5],
        'is_customer' => 1, 'created_at' => date('Y-m-d H:i:s'),
    ]);
}

// ---------- Suppliers ----------
$suppliers = [
    ['Nakumatt Wholesale', '+255 718 400 001', 'wholesale@nakumatt.co.tz', 'Dar es Salaam'],
    ['TBL Distributors', '+255 719 400 002', 'orders@tbl.co.tz', 'Dar es Salaam'],
    ['Bakhresa Group', '+255 720 400 003', 'sales@bakhresa.com', 'Mombasa Rd, Dar'],
    ['Kenya Canners', '+255 721 400 004', 'info@kencan.com', 'Arusha'],
];
foreach ($suppliers as $s) {
    db_insert('suppliers', [
        'tenant_id' => $tid, 'company_name' => $s[0], 'phone' => $s[1],
        'email' => $s[2], 'address' => $s[3], 'created_at' => date('Y-m-d H:i:s'),
    ]);
}

// ---------- Categories & Products ----------
$cats = ['Beverages', 'Electronics', 'Household'];
$cat_ids = [];
foreach ($cats as $i => $c) {
    $cat_ids[] = db_insert('product_categories', [
        'tenant_id' => $tid, 'name' => $c,
    ]);
}

$products = [
    ['Mineral Water 1.5L', 900, 1500, 18, 50, 10, $cat_ids[0], 0],
    ['Soda 300ml (Crate)', 12000, 15000, 18, 30, 5, $cat_ids[0], 0],
    ['Mango Juice 1L', 2500, 3500, 18, 40, 8, $cat_ids[0], 0],
    ['LED Bulb 9W', 1800, 3000, 18, 60, 12, $cat_ids[1], 0],
    ['Phone Charger USB', 4500, 7000, 18, 35, 6, $cat_ids[1], 0],
    ['Bluetooth Speaker', 18000, 28000, 18, 15, 3, $cat_ids[1], 0],
    ['Laundry Detergent 2kg', 8500, 12000, 18, 25, 5, $cat_ids[2], 0],
    ['Cooking Oil 5L', 16000, 19500, 18, 20, 4, $cat_ids[2], 0],
    ['Delivery Service (per km)', 0, 1000, 0, 0, 0, $cat_ids[1], 1],
];
$prod_ids = [];
foreach ($products as $i => $p) {
    $pid = db_insert('products', [
        'tenant_id' => $tid, 'code' => 'PRD-' . str_pad($i + 1, 3, '0', STR_PAD_LEFT),
        'name' => $p[0], 'category_id' => $p[6], 'purchase_price' => $p[1],
        'sale_price' => $p[2], 'tax_rate' => $p[3], 'min_stock_level' => $p[5],
        'is_service' => $p[7], 'is_active' => 1, 'created_by' => $uid,
        'created_at' => date('Y-m-d H:i:s'),
    ]);
    $prod_ids[] = $pid;
    if (!$p[7]) {
        db_insert('inventory_stock', [
            'tenant_id' => $tid, 'product_id' => $pid, 'warehouse_id' => 1,
            'quantity' => $p[4], 'reorder_level' => $p[5], 'updated_at' => date('Y-m-d H:i:s'),
        ]);
    }
}

// ---------- POS Sales (last 3 months) ----------
mt_srand(42);
$statuses = ['completed', 'completed', 'completed', 'completed', 'cancelled', 'completed'];
$warehouse_id = 1;

for ($m = 3; $m >= 1; $m--) {
    $sales_this_month = mt_rand(3, 5);
    for ($s = 0; $s < $sales_this_month; $s++) {
        $days_ago = ($m - 1) * 30 + mt_rand(0, 28);
        $date = date('Y-m-d H:i:s', strtotime("-$days_ago days 09:00:00") + mt_rand(0, 40000));
        $contact = $cust_ids[array_rand($cust_ids)];
        $status = $statuses[array_rand($statuses)];

        $sess = fetch_one("SELECT session_id FROM pos_sessions WHERE tenant_id = ? AND is_closed = 0 ORDER BY session_id DESC LIMIT 1", $tid);
        if ($sess) {
            $session_id = (int)$sess['session_id'];
        } else {
            $session_id = db_insert('pos_sessions', [
                'tenant_id' => $tid, 'user_id' => $uid, 'warehouse_id' => $warehouse_id,
                'session_number' => 'POS-SESSION-' . date('Ymd') . '-' . mt_rand(100, 999),
                'start_time' => $date, 'starting_cash' => 0, 'is_closed' => 0,
            ]);
        }

        $order_number = 'POS-' . date('Ymd', strtotime($date)) . '-' . str_pad(mt_rand(1, 999), 3, '0', STR_PAD_LEFT);
        $n_items = mt_rand(1, 3);
        $chosen = array_rand($prod_ids, min($n_items, count($prod_ids)));
        if (!is_array($chosen)) $chosen = [$chosen];
        $subtotal = 0;
        $tax_total = 0;
        foreach ($chosen as $pi) {
            $p = fetch_one("SELECT * FROM products WHERE product_id = ?", $prod_ids[$pi]);
            $qty = $p['is_service'] ? mt_rand(1, 3) : mt_rand(1, 8);
            $line = $p['sale_price'] * $qty;
            $tax = $line * ($p['tax_rate'] / 100);
            $subtotal += $line;
            $tax_total += $tax;
            if (!$p['is_service']) {
                $conn->query("UPDATE inventory_stock SET quantity = GREATEST(quantity - $qty, 0) WHERE product_id = {$p['product_id']} AND warehouse_id = 1");
            }
        }
        $grand = $subtotal + $tax_total;

        $oid = db_insert('pos_orders', [
            'tenant_id' => $tid, 'session_id' => $session_id, 'order_number' => $order_number,
            'contact_id' => $contact, 'order_date' => $date, 'subtotal' => $subtotal,
            'tax_amount' => $tax_total, 'total' => $grand, 'status' => $status,
            'created_by' => $uid, 'created_at' => $date,
        ]);

        foreach ($chosen as $pi) {
            $p = fetch_one("SELECT * FROM products WHERE product_id = ?", $prod_ids[$pi]);
            $qty = $p['is_service'] ? mt_rand(1, 3) : mt_rand(1, 8);
            $line = $p['sale_price'] * $qty;
            $tax = $line * ($p['tax_rate'] / 100);
            db_insert('pos_order_items', [
                'order_id' => $oid, 'product_id' => $p['product_id'], 'description' => $p['name'],
                'quantity' => $qty, 'unit_price' => $p['sale_price'], 'tax_amount' => $tax,
                'subtotal' => $line, 'total' => $line + $tax,
            ]);
        }

        if ($status === 'completed') {
            db_insert('pos_payments', [
                'order_id' => $oid, 'amount' => $grand, 'payment_method_id' => 1,
                'paid_at' => $date, 'created_by' => $uid,
            ]);
        }
    }
}

// ---------- Invoices (last 3 months) ----------
$inv_status = ['paid', 'paid', 'paid', 'partially_paid', 'overdue', 'sent', 'draft', 'cancelled'];
$inv_map = ['paid' => 1, 'partially_paid' => 4, 'overdue' => 5, 'sent' => 2, 'draft' => 1, 'cancelled' => 6];
$next_num = (int)fetch_one("SELECT invoice_next_number FROM system_settings WHERE tenant_id = 1")['invoice_next_number'] ?? 1002;

for ($i = 0; $i < 8; $i++) {
    $days_ago = mt_rand(1, 85);
    $issue = date('Y-m-d', strtotime("-$days_ago days"));
    $due = date('Y-m-d', strtotime("$issue +30 days"));
    $status = $inv_status[$i];
    $contact = $cust_ids[array_rand($cust_ids)];
    $n_items = mt_rand(1, 3);
    $chosen = array_rand($prod_ids, min($n_items, count($prod_ids)));
    if (!is_array($chosen)) $chosen = [$chosen];
    $subtotal = 0;
    $tax_total = 0;
    $items = [];
    foreach ($chosen as $pi) {
        $p = fetch_one("SELECT * FROM products WHERE product_id = ?", $prod_ids[$pi]);
        $qty = $p['is_service'] ? mt_rand(1, 4) : mt_rand(2, 15);
        $line = $p['sale_price'] * $qty;
        $tax = $line * ($p['tax_rate'] / 100);
        $subtotal += $line;
        $tax_total += $tax;
        $items[] = ['p' => $p, 'qty' => $qty, 'line' => $line, 'tax' => $tax];
        if (!$p['is_service'] && in_array($status, ['paid', 'partially_paid', 'sent', 'overdue'])) {
            $conn->query("UPDATE inventory_stock SET quantity = GREATEST(quantity - $qty, 0) WHERE product_id = {$p['product_id']} AND warehouse_id = 1");
        }
    }
    $grand = $subtotal + $tax_total;
    $amount_paid = 0;
    if ($status === 'paid') $amount_paid = $grand;
    if ($status === 'partially_paid') $amount_paid = round($grand / 2, -2);

    $inv_no = 'INV-' . $next_num;
    $next_num++;
    $iid = db_insert('invoices', [
        'tenant_id' => $tid, 'invoice_number' => $inv_no, 'contact_id' => $contact,
        'issue_date' => $issue, 'due_date' => $due, 'status_id' => $inv_map[$status],
        'reference' => 'PRJ-' . mt_rand(100, 999), 'notes' => '',
        'terms' => 'Payment due within 30 days', 'subtotal' => $subtotal,
        'tax_amount' => $tax_total, 'total' => $grand, 'amount_paid' => $amount_paid,
        'amount_due' => $grand - $amount_paid, 'is_paid' => $status === 'paid' ? 1 : 0,
        'created_by' => $uid, 'created_at' => $issue . ' 10:00:00',
    ]);

    foreach ($items as $it) {
        db_insert('invoice_items', [
            'tenant_id' => $tid, 'invoice_id' => $iid, 'product_id' => $it['p']['product_id'],
            'description' => $it['p']['name'], 'quantity' => $it['qty'],
            'unit_price' => $it['p']['sale_price'], 'tax_rate_id' => null,
            'tax_amount' => $it['tax'], 'subtotal' => $it['line'],
            'total' => $it['line'] + $it['tax'], 'discount' => 0,
        ]);
    }

    if ($amount_paid > 0) {
        db_insert('payments', [
            'tenant_id' => $tid, 'invoice_id' => $iid, 'contact_id' => $contact,
            'amount' => $amount_paid, 'payment_date' => $issue, 'payment_method_id' => 1,
            'created_by' => $uid,
        ]);
    }
}

$conn->query("UPDATE system_settings SET invoice_next_number = $next_num WHERE tenant_id = 1");

// ---------- Expenses (last 3 months) ----------
mt_srand(43);
$exp_cats = [];
foreach (fetch_all("SELECT expense_category_id, name FROM expense_categories WHERE tenant_id = 1") as $ec) {
    $exp_cats[$ec['name']] = (int)$ec['expense_category_id'];
}
$expense_template = [
    ['Rent', 'Office & shop rent — monthly', 500000, 1],
    ['Utilities', 'Electricity bill (TANESCO)', 185000, 1],
    ['Utilities', 'Water bill (DAWASCO)', 42000, 1],
    ['Utilities', 'Internet & phone bundles', 80000, 1],
    ['Salaries', 'Salaries — 4 staff (monthly)', 1400000, 1],
    ['Transport', 'Delivery fuel & taxi fare', 95000, 3],
    ['Office Supplies', 'Receipt books, pens, printer paper', 65000, 3],
    ['Marketing', 'WhatsApp & Facebook ads', 150000, 2],
    ['Other', 'Licence renewal & permits', 120000, 1],
];
$exp_count = 0;
for ($m = 3; $m >= 1; $m--) {
    foreach ($expense_template as $i => $tpl) {
        if ($i === 1 && $m === 1) continue; // skip one utility bill for variety
        $days_ago = ($m - 1) * 30 + mt_rand(2, 26);
        $exp_date = date('Y-m-d', strtotime("-$days_ago days"));
        $amount = $tpl[2] * (mt_rand(95, 108) / 100);
        $monthly_rep = mt_rand(0, 1);
        if ($monthly_rep && !in_array($tpl[0], ['Rent', 'Salaries'])) {
            $amount = $amount * mt_rand(3, 8) / 10;
        }
        db_insert('expenses', [
            'tenant_id' => $tid, 'category_id' => $exp_cats[$tpl[0]],
            'description' => $tpl[1], 'amount' => round($amount, -2),
            'expense_date' => $exp_date, 'payment_method_id' => $tpl[3],
            'created_by' => $uid, 'created_at' => $exp_date . ' 12:00:00',
        ]);
        $exp_count++;
    }
}
$expense_total = (float)fetch_one("SELECT SUM(amount) s FROM expenses WHERE tenant_id = 1")['s'];

// ---------- Audit trail ----------
db_insert('audit_logs', [
    'tenant_id' => $tid, 'user_id' => $uid, 'action' => 'create',
    'entity_type' => 'seed', 'entity_id' => 1,
    'details' => 'Demo data loaded for presentation', 'created_at' => date('Y-m-d H:i:s'),
]);

// ---------- Role permissions (idempotent) ----------
// Staff (role 2) must be able to record expenses; manager (5) and accountant (3) get standard access.
$role_perm_map = [
    2 => ['manage_contacts', 'manage_inventory', 'manage_invoices', 'manage_pos', 'manage_products', 'manage_receipts', 'manage_expenses', 'view_dashboard'],
    3 => ['manage_expenses', 'manage_invoices', 'manage_receipts', 'view_dashboard', 'view_reports'],
    5 => ['manage_contacts', 'manage_expenses', 'manage_inventory', 'manage_invoices', 'manage_pos', 'manage_products', 'manage_receipts', 'manage_settings', 'manage_suppliers', 'manage_users', 'view_activity_log', 'view_dashboard', 'view_reports'],
];
foreach ($role_perm_map as $role_id => $perm_names) {
    $role = fetch_one("SELECT role_id FROM roles WHERE role_id = ? AND tenant_id = 1", $role_id);
    if (!$role) continue;
    foreach ($perm_names as $perm_name) {
        $perm = fetch_one("SELECT permission_id FROM permissions WHERE name = ?", $perm_name);
        if ($perm) {
            $conn->query("INSERT IGNORE INTO role_permissions (role_id, permission_id) VALUES (" . (int)$role_id . ", " . (int)$perm['permission_id'] . ")");
        }
    }
}

echo "Demo data loaded successfully.\n";
echo "Customers: " . count($cust_ids) . " · Products: " . count($prod_ids) . "\n";
echo "Sales: " . fetch_one("SELECT COUNT(*) c FROM pos_orders WHERE tenant_id = 1")['c'] . "\n";
echo "Invoices: " . fetch_one("SELECT COUNT(*) c FROM invoices WHERE tenant_id = 1")['c'] . "\n";
echo "Expenses: $exp_count (" . number_format($expense_total, 2) . ")\n";
echo "Next invoice number: INV-$next_num\n";
