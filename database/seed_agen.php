<?php
// ============================================================
// AGEN GENERAL SUPPLY — Seed / Demo data
// Paint & tinting supply business
// Re-runnable: wipes tenant 1 business data and recreates it.
// ============================================================
require_once __DIR__ . '/../config.php';

$tid = 1;
$uid = 1;

function rc($table) { $GLOBALS['conn']->query("SET FOREIGN_KEY_CHECKS=0"); $GLOBALS['conn']->query("TRUNCATE TABLE `$table`"); $GLOBALS['conn']->query("SET FOREIGN_KEY_CHECKS=1"); }

$tables = ['pos_payments','pos_order_items','pos_orders','pos_sessions','invoice_items','payments','invoices',
           'expense_items','expenses','inventory_movements','inventory_stock','products','product_variants',
           'product_categories','units_of_measurement','warehouses','suppliers','contacts','audit_logs',
           'document_statuses','payment_methods','tax_rates','expense_categories','user_roles','users'];
foreach ($tables as $t) rc($t);

$conn->query("ALTER TABLE products AUTO_INCREMENT = 1");
$conn->query("UPDATE system_settings SET invoice_next_number = 1001 WHERE tenant_id = $tid");

// ---------- Company ----------
$conn->query("UPDATE tenants SET name = 'AGEN GENERAL SUPPLY', email = 'info@agensupply.co.tz', phone = '+255 784 000 000', address = 'Nyerere Road, Dar es Salaam', currency_code = 'TZS' WHERE tenant_id = $tid");
$stmt = $conn->prepare("UPDATE system_settings SET company_name = ?, company_email = ?, company_phone = ?, company_address = ?, default_currency = 'TZS', apply_tax = 1 WHERE tenant_id = $tid");
$name = 'AGEN GENERAL SUPPLY'; $email = 'info@agensupply.co.tz'; $phone = '+255 784 000 000'; $addr = 'Nyerere Road, Dar es Salaam';
$stmt->bind_param('ssss', $name, $email, $phone, $addr);
$stmt->execute();

// ---------- Admin user ----------
$stmt = $conn->prepare("INSERT INTO users (tenant_id, username, email, password_hash, first_name, last_name, phone, is_active) VALUES (?, ?, ?, ?, ?, ?, ?, 1)");
$hash = password_hash('Admin@2026', PASSWORD_DEFAULT);
$un = 'admin'; $em = 'admin@agensupply.co.tz'; $fn = 'System'; $ln = 'Administrator'; $ph = '+255 784 000 000';
$stmt->bind_param('issssss', $tid, $un, $em, $hash, $fn, $ln, $ph);
$stmt->execute();
$stmt = $conn->prepare("INSERT INTO user_roles (user_id, role_id, tenant_id) VALUES (?, 1, ?)");
$stmt->bind_param('ii', $uid, $tid);
$stmt->execute();

// ---------- Supporting data ----------
$methods = ['Cash', 'Mobile Money', 'Bank Transfer', 'Card'];
foreach ($methods as $m) { db_insert('payment_methods', ['tenant_id' => $tid, 'name' => $m]); }
$taxes = [['No Tax', 0], ['VAT 18%', 18]];
foreach ($taxes as $t) { db_insert('tax_rates', ['tenant_id' => $tid, 'name' => $t[0], 'rate' => $t[1]]); }
$units = [['Pieces', 'pcs', 0], ['Litre', 'L', 1], ['Kilogram', 'kg', 1], ['Box', 'box', 0], ['Roll', 'roll', 0]];
foreach ($units as $u) { db_insert('units_of_measurement', ['tenant_id' => $tid, 'name' => $u[0], 'short_name' => $u[1], 'is_decimal' => $u[2]]); }
db_insert('warehouses', ['tenant_id' => $tid, 'name' => 'Main Shop']);

$doc_statuses = [
    ['Draft', 1, 0], ['Sent', 2, 0], ['Partially Paid', 3, 0], ['Paid', 4, 1], ['Overdue', 5, 0], ['Cancelled', 6, 1],
];
foreach ($doc_statuses as $d) { db_insert('document_statuses', ['tenant_id' => $tid, 'type' => 'invoice', 'name' => $d[0], 'sort_order' => $d[1], 'is_closed' => $d[2]]); }

// Expense categories (paint shop)
$exp_cats = ['Stock Purchase', 'Rent', 'Utilities', 'Salaries', 'Transport', 'Tinting Supplies', 'Marketing', 'Other'];
foreach ($exp_cats as $c) { db_insert('expense_categories', ['tenant_id' => $tid, 'name' => $c]); }

// ---------- Product categories (3 levels: Brand -> Line -> Pack) ----------
function catId($name) {
    $row = fetch_one("SELECT category_id FROM product_categories WHERE tenant_id = 1 AND name = ? LIMIT 1", $name);
    return $row ? (int)$row['category_id'] : null;
}
function addCat($name, $parent = null) {
    return db_insert('product_categories', ['tenant_id' => 1, 'parent_category_id' => $parent, 'name' => $name]);
}

$brands = [
    'Plascon' => ['Weather Guard', 'Silk', 'Wall Seal', 'Double Velvet'],
    'Crown' => ['Easy Wash', 'Lux'],
    'Kansai' => ['Supertex', 'Hydrolux'],
];
$packs = ['0.5L', '4L', '20L'];
$brand_ids = [];
foreach ($brands as $brand => $lines) {
    $bid = addCat($brand);
    $brand_ids[$brand] = $bid;
    foreach ($lines as $line) {
        $lid = addCat($line, $bid);
        foreach ($packs as $pack) {
            addCat($pack, $lid);
        }
    }
}
addCat('Thinners');
addCat('Putty & Fillers');
addCat('Painting Tools');
addCat('Primers');

// ---------- Products (color variants under leaf pack categories) ----------
$colors = ['White', 'Snow White', 'Off White', 'Cream', 'Sky Blue', 'Light Grey', 'Sea Green', 'Chocolate', 'Red Oxide', 'Deep Black'];
$warehouse_id = (int)fetch_one("SELECT warehouse_id FROM warehouses WHERE tenant_id = $tid LIMIT 1")['warehouse_id'];

function leafCat($brand, $line, $pack) {
    $c = fetch_one("SELECT c.category_id FROM product_categories c
                    JOIN product_categories l ON l.category_id = c.parent_category_id
                    JOIN product_categories b ON b.category_id = l.parent_category_id
                    WHERE b.name = ? AND l.name = ? AND c.name = ? AND c.tenant_id = 1 LIMIT 1", $brand, $line, $pack);
    return $c ? (int)$c['category_id'] : null;
}

$products = [
    // brand, line, pack, base purchase, base sale, colors to create
    ['Plascon', 'Weather Guard', '4L',  38000, 45000, ['White', 'Snow White', 'Off White', 'Sky Blue']],
    ['Plascon', 'Weather Guard', '20L', 165000, 195000, ['White', 'Cream', 'Light Grey']],
    ['Plascon', 'Silk', '4L', 42000, 50000, ['White', 'Off White', 'Sea Green']],
    ['Plascon', 'Silk', '20L', 182000, 215000, ['White', 'Chocolate']],
    ['Plascon', 'Wall Seal', '4L', 25000, 30000, ['White']],
    ['Plascon', 'Wall Seal', '20L', 98000, 118000, ['White']],
    ['Crown', 'Easy Wash', '4L', 36000, 43000, ['White', 'Cream']],
    ['Crown', 'Easy Wash', '20L', 148000, 176000, ['White']],
    ['Crown', 'Lux', '4L', 20000, 26000, ['White', 'Red Oxide']],
    ['Kansai', 'Supertex', '4L', 30000, 37000, ['White', 'Deep Black']],
    ['Kansai', 'Supertex', '20L', 125000, 149000, ['White']],
    ['Kansai', 'Hydrolux', '4L', 33000, 40000, ['White', 'Light Grey']],
];

$prod_id = 1;
foreach ($products as $p) {
    $cid = leafCat($p[0], $p[1], $p[2]);
    if (!$cid) { echo "MISSING leaf: {$p[0]} / {$p[1]} / {$p[2]}\n"; continue; }
    foreach ($p[5] as $color) {
        $code = 'PRD-' . str_pad($prod_id, 3, '0', STR_PAD_LEFT);
        $sale = $p[4] + ($color === 'Deep Black' ? 2000 : 0);
        $name = "{$p[0]} {$p[1]} {$p[2]} {$color}";
        $pid = db_insert('products', [
            'tenant_id' => $tid, 'category_id' => $cid, 'code' => $code, 'name' => $name,
            'barcode' => '6' . str_pad((string)$prod_id, 5, '0', STR_PAD_LEFT),
            'sku' => strtoupper(substr(str_replace(' ', '', $p[0]), 0, 3)) . '-' . $p[2] . '-' . str_replace(' ', '', $color),
            'unit_id' => 2, 'purchase_price' => $p[3], 'sale_price' => $sale,
            'tax_rate' => 18, 'min_stock_level' => 5,
            'variant_size' => $p[2], 'variant_color' => $color,
            'is_active' => 1, 'is_service' => 0, 'created_by' => 1,
        ]);
        $stock = 20 + ($prod_id % 9) * 4;
        $stmt = $conn->prepare("INSERT INTO inventory_stock (tenant_id, product_id, warehouse_id, quantity, reorder_level) VALUES (?, ?, ?, ?, 5)");
        $stmt->bind_param('iiid', $tid, $pid, $warehouse_id, $stock);
        $stmt->execute();
        $prod_id++;
    }
}

// ---------- Customers ----------
$customers = [
    ['Juma', 'Mkwawa', 'Juma Hardware', '+255 712 111 222'],
    ['Neema', 'John', 'Neema Decor', '+255 713 222 333'],
    ['Peter', 'Msigwa', 'Msigwa Construction', '+255 714 333 444'],
    ['Amina', 'Hassan', 'Amina Interiors', '+255 715 444 555'],
    ['Walk-in', '', '', ''],
];
foreach ($customers as $c) {
    db_insert('contacts', [
        'tenant_id' => $tid, 'first_name' => $c[0], 'last_name' => $c[1],
        'company_name' => $c[2] ?: null, 'phone' => $c[3] ?: null,
        'is_customer' => 1, 'is_active' => 1,
    ]);
}

// ---------- A few sales (POS) for the dashboard/reports ----------
$today = date('Y-m-d');
$sales_seed = [
    // days ago, customer idx (1-based), items as [[product id, qty]]
    [0, 1, [[1, 2], [13, 1]]],
    [0, 2, [[7, 1]]],
    [1, 0, [[4, 1], [16, 2]]],
    [2, 3, [[10, 3]]],
    [3, 0, [[2, 1]]],
    [4, 4, [[19, 2], [6, 1]]],
];
$product_ids = fetch_all("SELECT product_id, sale_price, tax_rate, purchase_price FROM products WHERE tenant_id = $tid ORDER BY product_id");
$stmt = $conn->prepare("INSERT INTO pos_sessions (tenant_id, user_id, warehouse_id, session_number, start_time, starting_cash) VALUES (?, 1, ?, ?, NOW(), 0)");
$sess_num = 'POS-SESSION-' . date('Ymd') . '-001';
$stmt->bind_param('iis', $tid, $warehouse_id, $sess_num);
$stmt->execute();
$session_id = $stmt->insert_id;
foreach ($sales_seed as $i => $s) {
    $day = date('Y-m-d', strtotime("$today -{$s[0]} days"));
    $order_number = 'POS-' . str_replace('-', '', $day) . '-' . str_pad(100 + $i, 3, '0', STR_PAD_LEFT);
    $cid = $s[1] > 0 ? $s[1] : null;
    $sub = 0; $tax = 0; $lines = [];
    foreach ($s[2] as $it) {
        $pid = $it[0]; $qty = $it[1];
        $pr = $product_ids[$pid - 1];
        $lt = $pr['sale_price'] * $qty;
        $lta = $lt * ($pr['tax_rate'] / 100);
        $sub += $lt; $tax += $lta;
        $lines[] = [$pid, $pr['sale_price'], $pr['tax_rate'], $qty, $lta, $lt, $lt + $lta];
    }
    $total = $sub + $tax;
    $created_at = "$day 10:00:00";
    $stmt = $conn->prepare("INSERT INTO pos_orders (tenant_id, session_id, order_number, contact_id, order_date, subtotal, tax_amount, discount, total, status, created_by, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, 0, ?, 'completed', 1, ?)");
    $stmt->bind_param('iisisddds', $tid, $session_id, $order_number, $cid, $day, $sub, $tax, $total, $created_at);
    $stmt->execute();
    $oid = $stmt->insert_id;
    foreach ($lines as $l) {
        $stmt = $conn->prepare("INSERT INTO pos_order_items (order_id, product_id, description, quantity, unit_price, tax_amount, subtotal, total) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
        $desc = "Product {$l[0]}";
        $stmt->bind_param('iisddddd', $oid, $l[0], $desc, $l[3], $l[1], $l[4], $l[5], $l[6]);
        $stmt->execute();
        $stmt = $conn->prepare("UPDATE inventory_stock SET quantity = quantity - ? WHERE product_id = ? AND warehouse_id = ? AND tenant_id = ?");
        $stmt->bind_param('diii', $l[3], $l[0], $warehouse_id, $tid);
        $stmt->execute();
        $stmt = $conn->prepare("INSERT INTO inventory_movements (tenant_id, product_id, warehouse_id, movement_type, quantity, reference_id, reference_type, created_by, created_at) VALUES (?, ?, ?, 'sale', ?, ?, 'pos_order', 1, ?)");
        $stmt->bind_param('iiidid', $tid, $l[0], $warehouse_id, $l[3], $oid, $created_at);
        $stmt->execute();
    }
    if ($s[1] > 0) {
        $stmt = $conn->prepare("INSERT INTO pos_payments (order_id, amount, payment_method_id, paid_at, created_by) VALUES (?, ?, 1, ?, 1)");
        $paid_at = "$day 10:05:00";
        $stmt->bind_param('ids', $oid, $total, $paid_at);
        $stmt->execute();
    }
}

// ---------- A few expenses ----------
$exp_items = [
    // category idx (1-based), date days ago, items [name, qty, price]
    [1, 0, [['Weather Guard 20L White (restock)', 3, 165000], ['Silk 4L (restock)', 2, 42000]]],
    [2, 0, [['Shop rent', 1, 400000]]],
    [4, 1, [['Fuel', 20, 3000]]],
    [6, 2, [['Tinting paste', 5, 18000], ['Colour cards', 10, 1500]]],
    [5, 3, [['Deliveries', 3, 12000]]],
    [3, 4, [['Staff salary', 1, 350000]]],
];
$exp_cat_ids = fetch_all("SELECT expense_category_id FROM expense_categories WHERE tenant_id = $tid ORDER BY expense_category_id");
foreach ($exp_items as $i => $e) {
    $day = date('Y-m-d', strtotime("$today -{$e[1]} days"));
    $cat = $exp_cat_ids[$e[0] - 1]['expense_category_id'];
    $items = $e[2];
    $total = 0; $desc_parts = [];
    foreach ($items as $it) { $total += $it[1] * $it[2]; $desc_parts[] = "{$it[0]} x{$it[1]} @{$it[2]}"; }
    $desc = implode('; ', $desc_parts);
    $stmt = $conn->prepare("INSERT INTO expenses (tenant_id, category_id, description, amount, expense_date, payment_method_id, notes, created_by, created_at) VALUES (?, ?, ?, ?, ?, 1, NULL, 1, ?)");
    $created_at = "$day 09:00:00";
    $stmt->bind_param('iisdss', $tid, $cat, $desc, $total, $day, $created_at);
    $stmt->execute();
    $eid = $stmt->insert_id;
    foreach ($items as $it) {
        db_insert('expense_items', [
            'tenant_id' => $tid, 'expense_id' => $eid, 'name' => $it[0],
            'quantity' => $it[1], 'unit_price' => $it[2], 'total' => $it[1] * $it[2],
        ]);
    }
}

echo "AGEN seed complete. Products: " . count($product_ids) . ", sales: " . count($sales_seed) . ", expenses: " . count($exp_items) . PHP_EOL;