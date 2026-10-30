<?php
require_once 'header.php';
require_once 'auth_check.php';
require_permission_any(['manage_godown', 'view_godown']);
$page_title = 'Central Godown';

$tenant_id = (int)$current_user->tenant_id;
$can_manage = can('manage_godown');

// The system always keeps one active Central Godown available.
if ($can_manage) {
    ensure_central_godown($tenant_id);
}

// Reordered tabs: Products first by default, followed by overview and transfers
$valid_tabs = ['products', 'overview', 'transfers'];
$tab = (string)($_GET['tab'] ?? 'products');
if (!in_array($tab, $valid_tabs, true)) {
    $tab = 'products';
}

/** Load a Godown row within the current tenant. */
function godown_find(int $tenant_id, int $warehouse_id): ?array
{
    return fetch_one(
        "SELECT w.*, TRIM(CONCAT(COALESCE(u.first_name, ''), ' ', COALESCE(u.last_name, ''))) manager_name,
                u.username manager_username
           FROM warehouses w
           LEFT JOIN users u ON u.user_id = w.manager_user_id
          WHERE w.warehouse_id = ? AND w.tenant_id = ? AND w.location_type = 'godown'",
        $warehouse_id,
        $tenant_id
    );
}

/** Counts that decide whether a Godown can be deleted or must be archived. */
function godown_dependencies(int $tenant_id, int $warehouse_id): array
{
    $params = [];
    for ($i = 0; $i < 6; $i++) {
        $params[] = $tenant_id;
        $params[] = $warehouse_id;
    }
    return fetch_one(
        "SELECT
            (SELECT COUNT(*) FROM inventory_stock WHERE tenant_id = ? AND warehouse_id = ?) stock_rows,
            (SELECT COALESCE(SUM(quantity), 0) FROM inventory_stock WHERE tenant_id = ? AND warehouse_id = ?) stock_qty,
            (SELECT COUNT(*) FROM stock_transfers
              WHERE tenant_id = ? AND source_warehouse_id = ?
                AND status IN ('draft', 'approved', 'dispatched', 'discrepancy')) open_transfers,
            (SELECT COUNT(*) FROM stock_transfers
              WHERE tenant_id = ? AND source_warehouse_id = ?) transfer_rows,
            (SELECT COUNT(*) FROM godown_receipts
              WHERE tenant_id = ? AND godown_id = ?) receipt_rows,
            (SELECT COUNT(*) FROM inventory_movements
              WHERE tenant_id = ? AND warehouse_id = ?) movement_rows",
        ...$params
    ) ?: [];
}

/** Keep the optional per-user Godown mapping in step with the selected manager. */
function godown_sync_manager(int $tenant_id, int $warehouse_id, int $manager_id): void
{
    global $conn;
    $stmt = $conn->prepare("DELETE FROM user_godowns WHERE tenant_id = ? AND warehouse_id = ?");
    $stmt->bind_param('ii', $tenant_id, $warehouse_id);
    $stmt->execute();
    $stmt->close();

    if ($manager_id > 0) {
        $stmt = $conn->prepare(
            "INSERT IGNORE INTO user_godowns (tenant_id, user_id, warehouse_id) VALUES (?, ?, ?)"
        );
        $stmt->bind_param('iii', $tenant_id, $manager_id, $warehouse_id);
        $stmt->execute();
        $stmt->close();
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    if (!form_token_valid()) {
        flash_set('danger', 'This form was already submitted. Refresh the page before trying again.');
        redirect('godown.php');
    }
    if (!$can_manage) {
        http_response_code(403);
        die('You do not have permission to manage the central Godown.');
    }

    $action = (string)($_POST['action'] ?? '');
    $in_transaction = false;

    try {
        if ($action === 'create_godown') {
            $name = trim((string)($_POST['name'] ?? ''));
            $code = strtoupper(trim((string)($_POST['code'] ?? '')));
            $address = trim((string)($_POST['address'] ?? ''));
            $phone = trim((string)($_POST['phone'] ?? ''));
            $manager_id = (int)($_POST['manager_user_id'] ?? 0);

            if ($name === '') {
                throw new RuntimeException('Godown name is required.');
            }
            if (mb_strlen($name) > 191) {
                throw new RuntimeException('Godown name is too long (maximum 191 characters).');
            }
            if ($code !== '' && mb_strlen($code) > 50) {
                throw new RuntimeException('Godown code is too long (maximum 50 characters).');
            }
            if (mb_strlen($phone) > 50) {
                throw new RuntimeException('Phone number is too long (maximum 50 characters).');
            }

            $existing = fetch_one(
                "SELECT warehouse_id, name FROM warehouses
                  WHERE tenant_id = ? AND location_type = 'godown' AND is_active = 1
                  LIMIT 1",
                $tenant_id
            );
            if ($existing) {
                throw new RuntimeException(
                    'This business already has an active Godown (' . $existing['name']
                    . '). Delete or archive it before adding another.'
                );
            }

            if ($manager_id > 0) {
                $manager = fetch_one(
                    "SELECT user_id FROM users WHERE user_id = ? AND tenant_id = ? AND is_active = 1",
                    $manager_id,
                    $tenant_id
                );
                if (!$manager) {
                    throw new RuntimeException('Select an active user as the Godown manager.');
                }
            } else {
                $manager_id = 0;
            }

            if ($code === '') {
                do {
                    $code = 'GDN-' . date('Ymd') . '-' . strtoupper(bin2hex(random_bytes(2)));
                    $duplicate = fetch_one(
                        "SELECT 1 FROM warehouses WHERE tenant_id = ? AND code = ?",
                        $tenant_id,
                        $code
                    );
                } while ($duplicate);
            } else {
                $duplicate = fetch_one(
                    "SELECT 1 FROM warehouses WHERE tenant_id = ? AND code = ?",
                    $tenant_id,
                    $code
                );
                if ($duplicate) {
                    throw new RuntimeException('That Godown code is already in use.');
                }
            }

            $address_db = $address !== '' ? $address : null;
            $phone_db = $phone !== '' ? $phone : null;
            $manager_db = $manager_id > 0 ? $manager_id : null;

            $stmt = $conn->prepare(
                "INSERT INTO warehouses
                    (tenant_id, shop_id, name, code, address, phone, manager_user_id, is_active, location_type)
                 VALUES (?, NULL, ?, ?, ?, ?, ?, 1, 'godown')"
            );
            $stmt->bind_param('issssi', $tenant_id, $name, $code, $address_db, $phone_db, $manager_db);
            if (!$stmt->execute()) {
                throw new RuntimeException('The Godown could not be created.');
            }
            $warehouse_id = (int)$stmt->insert_id;
            $stmt->close();

            godown_sync_manager($tenant_id, $warehouse_id, $manager_id);
            consume_form_token();
            audit_log('create', 'warehouse', $warehouse_id, 'Godown created: ' . $name . ' (' . $code . ')');
            flash_set('success', 'Godown ' . $name . ' created successfully.');
            redirect('godown.php?tab=products');
        }

        if ($action === 'update_godown') {
            $warehouse_id = (int)($_POST['warehouse_id'] ?? 0);
            $godown = godown_find($tenant_id, $warehouse_id);
            if (!$godown) {
                throw new RuntimeException('Godown not found.');
            }

            $name = trim((string)($_POST['name'] ?? ''));
            $code = strtoupper(trim((string)($_POST['code'] ?? '')));
            $address = trim((string)($_POST['address'] ?? ''));
            $phone = trim((string)($_POST['phone'] ?? ''));
            $manager_id = (int)($_POST['manager_user_id'] ?? 0);

            if ($name === '') {
                throw new RuntimeException('Godown name is required.');
            }
            if (mb_strlen($name) > 191) {
                throw new RuntimeException('Godown name is too long (maximum 191 characters).');
            }
            if ($code !== '' && mb_strlen($code) > 50) {
                throw new RuntimeException('Godown code is too long (maximum 50 characters).');
            }
            if (mb_strlen($phone) > 50) {
                throw new RuntimeException('Phone number is too long (maximum 50 characters).');
            }
            if ($code !== '') {
                $duplicate = fetch_one(
                    "SELECT 1 FROM warehouses WHERE tenant_id = ? AND code = ? AND warehouse_id <> ?",
                    $tenant_id,
                    $code,
                    $warehouse_id
                );
                if ($duplicate) {
                    throw new RuntimeException('That Godown code is already in use.');
                }
            } else {
                $code = $godown['code'] ?: ('GDN-' . $warehouse_id);
            }

            if ($manager_id > 0) {
                $manager = fetch_one(
                    "SELECT user_id FROM users WHERE user_id = ? AND tenant_id = ? AND is_active = 1",
                    $manager_id,
                    $tenant_id
                );
                if (!$manager) {
                    throw new RuntimeException('Select an active user as the Godown manager.');
                }
            } else {
                $manager_id = 0;
            }

            $address_db = $address !== '' ? $address : null;
            $phone_db = $phone !== '' ? $phone : null;
            $manager_db = $manager_id > 0 ? $manager_id : null;

            $conn->begin_transaction();
            $in_transaction = true;
            $stmt = $conn->prepare(
                "UPDATE warehouses
                    SET name = ?, code = ?, address = ?, phone = ?, manager_user_id = ?
                  WHERE warehouse_id = ? AND tenant_id = ? AND location_type = 'godown'"
            );
            $stmt->bind_param(
                'ssssiii',
                $name,
                $code,
                $address_db,
                $phone_db,
                $manager_db,
                $warehouse_id,
                $tenant_id
            );
            $stmt->execute();
            $stmt->close();
            godown_sync_manager($tenant_id, $warehouse_id, $manager_id);
            $conn->commit();
            $in_transaction = false;

            consume_form_token();
            audit_log('update', 'warehouse', $warehouse_id, 'Godown updated: ' . $name . ' (' . $code . ')');
            flash_set('success', 'Godown details updated.');
            redirect('godown.php?tab=overview');
        }

        if ($action === 'delete_godown') {
            $warehouse_id = (int)($_POST['warehouse_id'] ?? 0);
            $godown = godown_find($tenant_id, $warehouse_id);
            if (!$godown) {
                throw new RuntimeException('Godown not found.');
            }

            $deps = godown_dependencies($tenant_id, $warehouse_id);
            if (abs((float)($deps['stock_qty'] ?? 0)) > 0.0001) {
                throw new RuntimeException(
                    'Move or adjust all stock out of ' . $godown['name'] . ' before deleting it.'
                );
            }
            if ((int)($deps['open_transfers'] ?? 0) > 0) {
                throw new RuntimeException(
                    'Finish or cancel all open transfers at ' . $godown['name'] . ' before deleting it.'
                );
            }

            $has_history = (int)($deps['transfer_rows'] ?? 0) > 0
                || (int)($deps['receipt_rows'] ?? 0) > 0
                || (int)($deps['movement_rows'] ?? 0) > 0;

            if ($has_history) {
                $stmt = $conn->prepare(
                    "UPDATE warehouses SET is_active = 0
                      WHERE warehouse_id = ? AND tenant_id = ? AND location_type = 'godown'"
                );
                $stmt->bind_param('ii', $warehouse_id, $tenant_id);
                $stmt->execute();
                $stmt->close();
                audit_log(
                    'configure',
                    'warehouse',
                    $warehouse_id,
                    'Godown archived with history preserved: ' . $godown['name'] . ' (' . $godown['code'] . ')'
                );
                flash_set(
                    'success',
                    'Godown ' . $godown['name'] . ' archived. Its receipt and transfer history is preserved.'
                );
            } else {
                $conn->begin_transaction();
                $in_transaction = true;
                $stmt = $conn->prepare("DELETE FROM user_godowns WHERE tenant_id = ? AND warehouse_id = ?");
                $stmt->bind_param('ii', $tenant_id, $warehouse_id);
                $stmt->execute();
                $stmt->close();
                $stmt = $conn->prepare(
                    "DELETE FROM warehouses
                      WHERE warehouse_id = ? AND tenant_id = ? AND location_type = 'godown'"
                );
                $stmt->bind_param('ii', $warehouse_id, $tenant_id);
                $stmt->execute();
                $stmt->close();
                $conn->commit();
                $in_transaction = false;
                audit_log(
                    'delete',
                    'warehouse',
                    $warehouse_id,
                    'Godown deleted: ' . $godown['name'] . ' (' . $godown['code'] . ')'
                );
                flash_set('success', 'Godown ' . $godown['name'] . ' deleted.');
            }

            consume_form_token();
            redirect('godown.php?tab=overview');
        }

        if ($action === 'receive_stock') {
            $active = find_active_godown($tenant_id);
            if (!$active) {
                throw new RuntimeException('Create an active Godown before receiving stock.');
            }

            $product_id = (int)($_POST['product_id'] ?? 0);
            $quantity = round((float)($_POST['quantity'] ?? 0), 2);
            $source_reference = trim((string)($_POST['source_reference'] ?? ''));
            $notes = trim((string)($_POST['notes'] ?? ''));

            $product = fetch_one(
                "SELECT p.*, u.is_decimal, u.short_name
                   FROM products p
                   LEFT JOIN units_of_measurement u ON u.unit_id = p.unit_id AND u.tenant_id = p.tenant_id
                  WHERE p.product_id = ? AND p.tenant_id = ? AND p.is_active = 1",
                $product_id,
                $tenant_id
            );
            if (!$product) {
                throw new RuntimeException('Select an active product.');
            }
            if ((int)$product['is_service'] === 1) {
                throw new RuntimeException('Services cannot hold stock.');
            }
            if ($quantity <= 0 || $quantity > 99999999) {
                throw new RuntimeException('Quantity must be greater than zero and no more than 99,999,999.');
            }
            if (!(int)($product['is_decimal'] ?? 0) && abs($quantity - round($quantity)) > 0.0001) {
                throw new RuntimeException('This product uses whole units only.');
            }
            if (mb_strlen($source_reference) > 191) {
                throw new RuntimeException('The source/reference is too long (maximum 191 characters).');
            }
            if (mb_strlen($notes) > 2000) {
                throw new RuntimeException('Notes are too long (maximum 2,000 characters).');
            }

            $conn->begin_transaction();
            $in_transaction = true;

            $locked_godown = fetch_one(
                "SELECT * FROM warehouses
                  WHERE warehouse_id = ? AND tenant_id = ? AND location_type = 'godown' AND is_active = 1
                  FOR UPDATE",
                (int)$active['warehouse_id'],
                $tenant_id
            );
            if (!$locked_godown) {
                throw new RuntimeException('The central Godown is no longer active.');
            }

            $locked_product = fetch_one(
                "SELECT product_id, is_active, is_service FROM products
                  WHERE product_id = ? AND tenant_id = ? FOR UPDATE",
                $product_id,
                $tenant_id
            );
            if (!$locked_product || (int)$locked_product['is_active'] !== 1 || (int)$locked_product['is_service'] === 1) {
                throw new RuntimeException('The selected product is no longer available for stock.');
            }

            $stmt = $conn->prepare(
                "INSERT INTO inventory_stock (tenant_id, product_id, warehouse_id, quantity, reorder_level)
                 VALUES (?, ?, ?, 0.00, ?)
                 ON DUPLICATE KEY UPDATE stock_id = stock_id"
            );
            $min_level = (float)($product['min_stock_level'] ?? 0);
            $godown_warehouse_id = (int)$locked_godown['warehouse_id'];
            $stmt->bind_param('iiid', $tenant_id, $product_id, $godown_warehouse_id, $min_level);
            $stmt->execute();
            $stmt->close();

            $stock = fetch_one(
                "SELECT stock_id, quantity FROM inventory_stock
                  WHERE tenant_id = ? AND product_id = ? AND warehouse_id = ?
                  FOR UPDATE",
                $tenant_id,
                $product_id,
                $godown_warehouse_id
            );
            if (!$stock) {
                throw new RuntimeException('The Godown stock row could not be locked.');
            }

            $receipt_number = next_document_number($tenant_id, 'godown_receipt', 'GRN');
            $source_db = $source_reference !== '' ? $source_reference : null;
            $notes_db = $notes !== '' ? $notes : null;

            $stmt = $conn->prepare(
                "INSERT INTO godown_receipts
                    (tenant_id, receipt_number, godown_id, product_id, quantity, source_reference, notes, received_by)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)"
            );
            $stmt->bind_param(
                'isiidssi',
                $tenant_id,
                $receipt_number,
                $godown_warehouse_id,
                $product_id,
                $quantity,
                $source_db,
                $notes_db,
                $current_user->user_id
            );
            $stmt->execute();
            $receipt_id = (int)$stmt->insert_id;
            $stmt->close();

            $stmt = $conn->prepare(
                "UPDATE inventory_stock
                    SET quantity = quantity + ?, last_counted_at = CURRENT_TIMESTAMP
                  WHERE stock_id = ? AND tenant_id = ?"
            );
            $stmt->bind_param('dii', $quantity, $stock['stock_id'], $tenant_id);
            $stmt->execute();
            $stmt->close();

            $movement_notes = 'Receipt ' . $receipt_number;
            if ($source_db !== null) $movement_notes .= ' from ' . $source_db;
            if ($notes_db !== null) $movement_notes .= ' - ' . $notes_db;

            $stmt = $conn->prepare(
                "INSERT INTO inventory_movements
                    (tenant_id, product_id, warehouse_id, movement_type, quantity, reference_id, reference_type, created_by, notes)
                 VALUES (?, ?, ?, 'godown_receipt', ?, ?, 'godown_receipt', ?, ?)"
            );
            $stmt->bind_param(
                'iiidiis',
                $tenant_id,
                $product_id,
                $godown_warehouse_id,
                $quantity,
                $receipt_id,
                $current_user->user_id,
                $movement_notes
            );
            $stmt->execute();
            $stmt->close();

            $conn->commit();
            $in_transaction = false;
            consume_form_token();
            audit_log(
                'receive',
                'godown_receipt',
                $receipt_id,
                'Received ' . format_quantity($quantity) . ' of ' . $product['name']
                . ' into ' . $locked_godown['name'] . ' (' . $receipt_number . ').'
                . ($source_db !== null ? ' Source: ' . $source_db . '.' : '')
            );
            flash_set(
                'success',
                'Received ' . format_quantity($quantity) . ' ' . ($product['short_name'] ?: 'unit(s)')
                . ' of ' . $product['name'] . ' into ' . $locked_godown['name'] . '. Receipt ' . $receipt_number . '.'
            );
            redirect('godown.php?tab=products');
        }

        throw new RuntimeException('Unknown Godown action.');
    } catch (Throwable $ex) {
        if ($in_transaction) {
            $conn->rollback();
        }
        flash_set('danger', $ex->getMessage());
        redirect('godown.php' . ($tab !== 'products' ? '?tab=' . urlencode($tab) : ''));
    }
}

$godown = find_active_godown($tenant_id, false);

$archived_godowns = $can_manage
    ? fetch_all(
        "SELECT w.*,
                TRIM(CONCAT(COALESCE(u.first_name, ''), ' ', COALESCE(u.last_name, ''))) manager_name,
                u.username manager_username,
                (SELECT COALESCE(SUM(s.quantity), 0) FROM inventory_stock s
                  WHERE s.tenant_id = w.tenant_id AND s.warehouse_id = w.warehouse_id) stock_qty
           FROM warehouses w
           LEFT JOIN users u ON u.user_id = w.manager_user_id
          WHERE w.tenant_id = ? AND w.location_type = 'godown' AND w.is_active = 0
          ORDER BY w.updated_at DESC, w.name",
        $tenant_id
    )
    : [];

$shops = accessible_shops(null, false);
$stores = accessible_stores(null, false);

$shop_store_counts = [];
foreach (fetch_all(
    "SELECT shop_id, COUNT(*) c FROM warehouses
      WHERE tenant_id = ? AND location_type = 'store' AND is_active = 1
      GROUP BY shop_id",
    $tenant_id
) as $row) {
    $shop_store_counts[(int)$row['shop_id']] = (int)$row['c'];
}

$store_stock = [];
foreach (fetch_all(
    "SELECT warehouse_id, COUNT(*) line_count, COALESCE(SUM(quantity), 0) qty FROM inventory_stock
      WHERE tenant_id = ? GROUP BY warehouse_id",
    $tenant_id
) as $row) {
    $store_stock[(int)$row['warehouse_id']] = [
        'lines' => (int)$row['line_count'],
        'qty' => (float)$row['qty'],
    ];
}

$stock_summary = ['qty' => 0.0, 'products' => 0];
if ($godown) {
    $row = fetch_one(
        "SELECT COALESCE(SUM(quantity), 0) qty,
                COUNT(DISTINCT CASE WHEN quantity <> 0 THEN product_id END) products
           FROM inventory_stock
          WHERE tenant_id = ? AND warehouse_id = ?",
        $tenant_id,
        (int)$godown['warehouse_id']
    ) ?: [];
    $stock_summary['qty'] = (float)($row['qty'] ?? 0);
    $stock_summary['products'] = (int)($row['products'] ?? 0);
}

$godown_managers = $can_manage
    ? fetch_all(
        "SELECT user_id, username, first_name, last_name FROM users
          WHERE tenant_id = ? AND is_active = 1
          ORDER BY first_name, last_name, username",
        $tenant_id
    )
    : [];

/* Product tab data */
$q = trim((string)($_GET['q'] ?? ''));
$category_id = (int)($_GET['category'] ?? 0);
$stock_filter = (string)($_GET['stock'] ?? '');
if (!in_array($stock_filter, ['in', 'out', 'low'], true)) {
    $stock_filter = '';
}
$page = max(1, (int)($_GET['page'] ?? 1));
$per_page = 50;
$offset = ($page - 1) * $per_page;

$categories = fetch_all(
    "SELECT category_id, name FROM product_categories WHERE tenant_id = ? ORDER BY name",
    $tenant_id
);

$product_from = " FROM products p
    LEFT JOIN product_categories c ON c.category_id = p.category_id AND c.tenant_id = p.tenant_id
    LEFT JOIN units_of_measurement u ON u.unit_id = p.unit_id AND u.tenant_id = p.tenant_id
    LEFT JOIN inventory_stock s ON s.product_id = p.product_id
        AND s.tenant_id = p.tenant_id AND s.warehouse_id = ?";
$product_where = " WHERE p.tenant_id = ? AND p.is_service = 0";
$product_params = [$godown ? (int)$godown['warehouse_id'] : 0, $tenant_id];

if ($q !== '') {
    $like = '%' . $q . '%';
    $product_where .= " AND (p.name LIKE ? OR p.code LIKE ? OR p.sku LIKE ? OR p.barcode LIKE ?)";
    array_push($product_params, $like, $like, $like, $like);
}
if ($category_id > 0) {
    $product_where .= " AND p.category_id = ?";
    $product_params[] = $category_id;
}
if ($stock_filter === 'in') {
    $product_where .= " AND COALESCE(s.quantity, 0) > 0";
} elseif ($stock_filter === 'out') {
    $product_where .= " AND COALESCE(s.quantity, 0) <= 0";
} elseif ($stock_filter === 'low') {
    $product_where .= " AND COALESCE(s.quantity, 0) > 0 AND COALESCE(s.quantity, 0) <= p.min_stock_level";
}

$product_count_row = fetch_one(
    "SELECT COUNT(*) c" . $product_from . $product_where,
    ...$product_params
);
$product_count = (int)($product_count_row['c'] ?? 0);
$product_pages = max(1, (int)ceil($product_count / $per_page));
if ($page > $product_pages) {
    $page = $product_pages;
    $offset = ($page - 1) * $per_page;
}
$product_rows = $godown ? fetch_all(
    "SELECT p.product_id, p.code, p.name, p.sku, p.barcode, p.min_stock_level, p.is_active,
            c.name category_name, COALESCE(u.short_name, '') unit_short,
            COALESCE(u.is_decimal, 0) is_decimal,
            COALESCE(s.quantity, 0) godown_qty, s.last_counted_at"
    . $product_from . $product_where
    . " ORDER BY p.name, p.product_id LIMIT ? OFFSET ?",
    ...array_merge($product_params, [$per_page, $offset])
) : [];

$receive_products = $godown ? fetch_all(
    "SELECT p.product_id, p.code, p.name, COALESCE(u.is_decimal, 0) is_decimal,
            COALESCE(u.short_name, '') unit_short
       FROM products p
       LEFT JOIN units_of_measurement u ON u.unit_id = p.unit_id AND u.tenant_id = p.tenant_id
      WHERE p.tenant_id = ? AND p.is_active = 1 AND p.is_service = 0
      ORDER BY p.name",
    $tenant_id
) : [];

$recent_receipts = $godown ? fetch_all(
    "SELECT gr.*, p.name product_name, p.code product_code, COALESCE(u.short_name, '') unit_short,
            TRIM(CONCAT(COALESCE(usr.first_name, ''), ' ', COALESCE(usr.last_name, ''))) received_by_name,
            usr.username received_by_username
       FROM godown_receipts gr
       JOIN products p ON p.product_id = gr.product_id AND p.tenant_id = gr.tenant_id
       LEFT JOIN units_of_measurement u ON u.unit_id = p.unit_id AND u.tenant_id = p.tenant_id
       LEFT JOIN users usr ON usr.user_id = gr.received_by
      WHERE gr.tenant_id = ? AND gr.godown_id = ?
      ORDER BY gr.receipt_id DESC
      LIMIT 15",
    $tenant_id,
    (int)$godown['warehouse_id']
) : [];

/* Transfer tab data */
$statuses = godown_transfer_statuses();
$transfer_status = (string)($_GET['status'] ?? '');
if ($transfer_status !== '' && !isset($statuses[$transfer_status])) {
    $transfer_status = '';
}
$transfer_q = trim((string)($_GET['tq'] ?? ''));

$godown_transfers = [];
if ($godown) {
    $transfer_sql = "SELECT t.*,
            s.name destination_shop_name, s.code destination_shop_code,
            w.name destination_store_name,
            (SELECT COUNT(*) FROM stock_transfer_items ti
              WHERE ti.transfer_id = t.transfer_id AND ti.tenant_id = t.tenant_id) item_count,
            (SELECT COALESCE(SUM(ti.requested_quantity), 0) FROM stock_transfer_items ti
              WHERE ti.transfer_id = t.transfer_id AND ti.tenant_id = t.tenant_id) total_quantity
       FROM stock_transfers t
       JOIN shops s ON s.shop_id = t.destination_shop_id AND s.tenant_id = t.tenant_id
       JOIN warehouses w ON w.warehouse_id = t.destination_warehouse_id AND w.tenant_id = t.tenant_id
      WHERE t.tenant_id = ? AND t.source_warehouse_id = ?";
    $transfer_params = [$tenant_id, (int)$godown['warehouse_id']];

    if ($transfer_status !== '') {
        $transfer_sql .= " AND t.status = ?";
        $transfer_params[] = $transfer_status;
    }
    if ($transfer_q !== '') {
        $like = '%' . $transfer_q . '%';
        $transfer_sql .= " AND (t.transfer_number LIKE ? OR s.name LIKE ? OR w.name LIKE ?
            OR EXISTS (SELECT 1 FROM stock_transfer_items qti
                        JOIN products qp ON qp.product_id = qti.product_id
                       WHERE qti.transfer_id = t.transfer_id AND qti.tenant_id = t.tenant_id
                         AND (qp.name LIKE ? OR qp.code LIKE ?)))";
        array_push($transfer_params, $like, $like, $like, $like, $like);
    }
    $transfer_sql .= " ORDER BY t.requested_at DESC, t.transfer_id DESC LIMIT 200";
    $godown_transfers = fetch_all($transfer_sql, ...$transfer_params);
}

$page_query = ['tab' => 'products'];
if ($q !== '') $page_query['q'] = $q;
if ($category_id > 0) $page_query['category'] = $category_id;
if ($stock_filter !== '') $page_query['stock'] = $stock_filter;
$page_base = 'godown.php?' . http_build_query($page_query);

require_once 'navbar.php';
require_once 'sidebar.php';
?>

<div class="page-head">
    <div>
        <h1 class="page-title">Central Godown</h1>
        <div class="page-sub">The Godown is an independent warehouse. Its stock is separate from shop POS stock and is moved to shops through transfers.</div>
    </div>
    <div>
        <?php if ($godown && $can_manage): ?>
            <a href="godown-transfer-add.php" class="btn btn-primary"><i class="fa fa-exchange mr-1"></i> New Transfer</a>
        <?php endif; ?>
        <a href="godown-transfers.php" class="btn btn-light"><i class="fa fa-list-alt mr-1"></i> All Transfers</a>
    </div>
</div>

<ul class="nav page-tabs">
    <li class="nav-item">
        <a class="nav-link <?php echo $tab === 'products' ? 'active' : ''; ?>" href="godown.php?tab=products">
            <i class="fa fa-cubes mr-1"></i> Products
        </a>
    </li>
    <li class="nav-item">
        <a class="nav-link <?php echo $tab === 'overview' ? 'active' : ''; ?>" href="godown.php?tab=overview">
            <i class="fa fa-archive mr-1"></i> Overview
        </a>
    </li>
    <li class="nav-item">
        <a class="nav-link <?php echo $tab === 'transfers' ? 'active' : ''; ?>" href="godown.php?tab=transfers">
            <i class="fa fa-exchange mr-1"></i> Transfers
        </a>
    </li>
</ul>

<?php if ($f = flash_get()): ?>
    <div class="alert alert-<?php echo e($f['type']); ?>"><?php echo e($f['message']); ?></div>
<?php endif; ?>

<?php if ($tab === 'products'): ?>
    <?php if (!$godown): ?>
        <div class="alert alert-warning">
            No Central Godown has been created yet. By default, an empty system godown needs to be set up.
            <a href="godown.php?tab=overview" class="alert-link">Go to Overview to create one</a>.
        </div>
    <?php else: ?>
        <?php
            // Summary metrics follow the same filters as the product table below.
            $metrics = fetch_one(
                "SELECT
                    COUNT(p.product_id) total_products,
                    COALESCE(SUM(COALESCE(s.quantity, 0)), 0) total_qty,
                    COALESCE(SUM(CASE WHEN COALESCE(s.quantity, 0) > 0 AND COALESCE(s.quantity, 0) <= p.min_stock_level THEN 1 ELSE 0 END), 0) low_stock_count,
                    COALESCE(SUM(CASE WHEN COALESCE(s.quantity, 0) <= 0 THEN 1 ELSE 0 END), 0) out_stock_count"
                . $product_from . $product_where,
                ...$product_params
            ) ?: ['total_products' => 0, 'total_qty' => 0, 'low_stock_count' => 0, 'out_stock_count' => 0];
        ?>

        <!-- Summary Cards Row -->
        <div class="row mb-4">
            <div class="col-sm-6 col-xl-3 mb-3 mb-xl-0">
                <div class="card border-left-primary shadow-sm h-100 py-2">
                    <div class="card-body">
                        <div class="row no-gutters align-items-center">
                            <div class="col mr-2">
                                <div class="text-xs font-weight-bold text-primary text-uppercase mb-1">Available Products</div>
                                <div class="h5 mb-0 font-weight-bold text-gray-800"><?php echo (int)$metrics['total_products']; ?></div>
                            </div>
                            <div class="col-auto">
                                <i class="fa fa-cubes fa-2x text-gray-300"></i>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-sm-6 col-xl-3 mb-3 mb-xl-0">
                <div class="card border-left-success shadow-sm h-100 py-2">
                    <div class="card-body">
                        <div class="row no-gutters align-items-center">
                            <div class="col mr-2">
                                <div class="text-xs font-weight-bold text-success text-uppercase mb-1">Godown Stock Qty</div>
                                <div class="h5 mb-0 font-weight-bold text-gray-800"><?php echo e(format_quantity($metrics['total_qty'])); ?></div>
                            </div>
                            <div class="col-auto">
                                <i class="fa fa-archive fa-2x text-gray-300"></i>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-sm-6 col-xl-3 mb-3 mb-sm-0">
                <div class="card border-left-warning shadow-sm h-100 py-2">
                    <div class="card-body">
                        <div class="row no-gutters align-items-center">
                            <div class="col mr-2">
                                <div class="text-xs font-weight-bold text-warning text-uppercase mb-1">Low Stock</div>
                                <div class="h5 mb-0 font-weight-bold text-gray-800"><?php echo (int)$metrics['low_stock_count']; ?></div>
                            </div>
                            <div class="col-auto">
                                <i class="fa fa-exclamation-triangle fa-2x text-gray-300"></i>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-sm-6 col-xl-3">
                <div class="card border-left-danger shadow-sm h-100 py-2">
                    <div class="card-body">
                        <div class="row no-gutters align-items-center">
                            <div class="col mr-2">
                                <div class="text-xs font-weight-bold text-danger text-uppercase mb-1">Out of Stock</div>
                                <div class="h5 mb-0 font-weight-bold text-gray-800"><?php echo (int)$metrics['out_stock_count']; ?></div>
                            </div>
                            <div class="col-auto">
                                <i class="fa fa-times-circle fa-2x text-gray-300"></i>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <!-- End Summary Cards Row -->

        <?php if ($can_manage): ?>
            <details class="card mb-4">
                <summary class="card-header" style="cursor: pointer;">
                    <span class="font-weight-semibold"><i class="fa fa-download mr-1"></i> Receive Stock into <?php echo e($godown['name']); ?></span>
                    <span class="text-muted small ml-2">Increases Godown stock and records an audit movement</span>
                </summary>
                <div class="card-body">
                    <p class="text-muted">Receipts increase only this Godown's stock. They do not create an invoice, purchase order, or payment.</p>
                    <form method="POST" action="godown.php?tab=products" id="godownReceiveForm">
                        <?php echo csrf_field(); ?>
                        <?php echo form_token_field(); ?>
                        <input type="hidden" name="action" value="receive_stock">
                        <div class="row">
                            <div class="col-md-5 form-group">
                                <label>Product <span class="text-danger">*</span></label>
                                <select name="product_id" id="receive_product" class="form-control" required>
                                    <option value="">— Select Product —</option>
                                    <?php foreach ($receive_products as $product): ?>
                                        <option value="<?php echo (int)$product['product_id']; ?>" data-decimal="<?php echo (int)$product['is_decimal']; ?>">
                                            <?php echo e($product['name'] . ' (' . $product['code'] . ')'); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-3 form-group">
                                <label>Quantity <span class="text-danger">*</span></label>
                                <input type="number" name="quantity" id="receive_quantity" class="form-control" step="1" min="0.01" max="99999999" required>
                                <small class="text-muted" id="receive_unit_hint">Whole units unless the product uses decimals.</small>
                            </div>
                            <div class="col-md-4 form-group">
                                <label>Source / Reference</label>
                                <input type="text" name="source_reference" class="form-control" maxlength="191" placeholder="Supplier delivery note or stock count">
                            </div>
                            <div class="col-12 form-group">
                                <label>Notes</label>
                                <textarea name="notes" class="form-control" rows="2" maxlength="2000" placeholder="Optional receiving notes"></textarea>
                            </div>
                        </div>
                        <button type="submit" class="btn btn-primary"><i class="fa fa-check mr-1"></i> Receive into Godown</button>
                    </form>
                </div>
            </details>
        <?php endif; ?>

        <div class="card mb-4">
            <div class="card-header">
                <form method="GET" action="godown.php" class="form-inline flex-wrap">
                    <input type="hidden" name="tab" value="products">
                    <input type="text" name="q" class="form-control form-control-sm mr-2 mb-1" value="<?php echo e($q); ?>" placeholder="Search product, code, SKU or barcode">
                    <select name="category" class="form-control form-control-sm mr-2 mb-1">
                        <option value="0">All Categories</option>
                        <?php foreach ($categories as $category): ?>
                            <option value="<?php echo (int)$category['category_id']; ?>" <?php echo $category_id === (int)$category['category_id'] ? 'selected' : ''; ?>>
                                <?php echo e($category['name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <select name="stock" class="form-control form-control-sm mr-2 mb-1">
                        <option value="">All Stock Levels</option>
                        <option value="in" <?php echo $stock_filter === 'in' ? 'selected' : ''; ?>>In Stock</option>
                        <option value="out" <?php echo $stock_filter === 'out' ? 'selected' : ''; ?>>Out of Stock</option>
                        <option value="low" <?php echo $stock_filter === 'low' ? 'selected' : ''; ?>>At or Below Minimum</option>
                    </select>
                    <button type="submit" class="btn btn-sm btn-primary mb-1"><i class="fa fa-filter mr-1"></i> Filter</button>
                    <?php if ($q !== '' || $category_id > 0 || $stock_filter !== ''): ?>
                        <a href="godown.php?tab=products" class="btn btn-sm btn-light mb-1 ml-2">Clear</a>
                    <?php endif; ?>
                </form>
                <div class="small text-muted mt-2"><?php echo $product_count; ?> product(s) match. Page <?php echo $page; ?> of <?php echo $product_pages; ?>.</div>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover mb-0">
                        <thead>
                            <tr>
                                <th>Product</th>
                                <th>Code</th>
                                <th>Category</th>
                                <th>Unit</th>
                                <th>Godown Qty</th>
                                <th>Min</th>
                                <th>Status</th>
                                <th>Last Counted</th>
                                <th class="text-right">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($product_rows as $row): ?>
                            <?php
                            $qty = (float)$row['godown_qty'];
                            $min = (float)$row['min_stock_level'];
                            if (!$row['is_active']) {
                                $status_label = 'Inactive';
                                $status_class = 'secondary';
                            } elseif ($qty <= 0) {
                                $status_label = 'Out of Stock';
                                $status_class = 'danger';
                            } elseif ($qty <= $min) {
                                $status_label = 'Low';
                                $status_class = 'warning';
                            } else {
                                $status_label = 'In Stock';
                                $status_class = 'success';
                            }
                            ?>
                            <tr>
                                <td class="font-weight-semibold"><?php echo e($row['name']); ?></td>
                                <td><span class="badge badge-secondary"><?php echo e($row['code']); ?></span></td>
                                <td><?php echo e($row['category_name'] ?: '—'); ?></td>
                                <td><?php echo e($row['unit_short']); ?></td>
                                <td class="font-weight-semibold"><?php echo e(format_quantity($qty)); ?></td>
                                <td><?php echo e(format_quantity($min)); ?></td>
                                <td><span class="badge badge-<?php echo e($status_class); ?>"><?php echo e($status_label); ?></span></td>
                                <td><?php echo $row['last_counted_at'] ? e(date('M d, Y H:i', strtotime($row['last_counted_at']))) : '—'; ?></td>
                                <td class="text-right">
                                    <?php if ($can_manage && $godown): ?>
                                        <button type="button" 
                                        class="btn btn-sm btn-light open-adjust-modal" 
                                        data-product-id="<?php echo (int)$row['product_id']; ?>"
                                        data-product-name="<?php echo e($row['name'] . ' (' . $row['code'] . ')'); ?>"
                                        data-decimal="<?php echo (int)($row['is_decimal'] ?? 0); ?>"
                                        title="Adjust Stock">
                                            <i class="fa fa-sliders mr-1"></i> Adjust
                                        </button>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (!$product_rows): ?>
                            <tr><td colspan="9" class="text-center text-muted py-4">No products match the current filters.</td></tr>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <?php if ($product_pages > 1): ?>
                <div class="card-footer">
                    <nav>
                        <ul class="pagination pagination-sm mb-0">
                            <li class="page-item <?php echo $page <= 1 ? 'disabled' : ''; ?>">
                                <a class="page-link" href="<?php echo e($page_base . '&page=' . max(1, $page - 1)); ?>">Previous</a>
                            </li>
                            <li class="page-item disabled"><span class="page-link"><?php echo $page; ?> / <?php echo $product_pages; ?></span></li>
                            <li class="page-item <?php echo $page >= $product_pages ? 'disabled' : ''; ?>">
                                <a class="page-link" href="<?php echo e($page_base . '&page=' . min($product_pages, $page + 1)); ?>">Next</a>
                            </li>
                        </ul>
                    </nav>
                </div>
            <?php endif; ?>
        </div>

        <?php if ($recent_receipts): ?>
            <div class="card mb-4">
                <div class="card-header"><h5 class="card-title mb-0"><i class="fa fa-history mr-1"></i> Recent Receipts</h5></div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover mb-0">
                            <thead><tr><th>Receipt</th><th>Product</th><th>Qty</th><th>Source</th><th>By / Date</th></tr></thead>
                            <tbody>
                            <?php foreach ($recent_receipts as $receipt): ?>
                                <tr>
                                    <td><span class="badge badge-info"><?php echo e($receipt['receipt_number']); ?></span></td>
                                    <td><?php echo e($receipt['product_name']); ?></td>
                                    <td class="font-weight-semibold"><?php echo e(format_quantity($receipt['quantity'])); ?></td>
                                    <td><?php echo e($receipt['source_reference'] ?: '—'); ?></td>
                                    <td>
                                        <?php echo e($receipt['received_by_name'] ?: ($receipt['received_by_username'] ?: 'Unknown')); ?>
                                        <br><small class="text-muted"><?php echo e(date('M d, Y H:i', strtotime($receipt['received_at']))); ?></small>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        <?php endif; ?>
    <?php endif; ?>
<?php endif; ?>

<?php if ($tab === 'overview'): ?>
    <?php if (!$godown): ?>
        <div class="card mb-4">
            <div class="card-header">
                <h5 class="card-title mb-0"><i class="fa fa-plus-circle mr-1"></i> Initialize Central Godown</h5>
            </div>
            <div class="card-body">
                <p class="text-muted">By default, the system requires an active central Godown. Set up your primary empty warehouse below.</p>
                <?php if (!$can_manage): ?>
                    <div class="alert alert-warning mb-0">You can view the Godown configuration but do not have permission to create one.</div>
                <?php else: ?>
                    <form method="POST" action="godown.php">
                        <?php echo csrf_field(); ?>
                        <?php echo form_token_field(); ?>
                        <input type="hidden" name="action" value="create_godown">
                        <div class="row">
                            <div class="col-md-6 form-group">
                                <label>Godown Name <span class="text-danger">*</span></label>
                                <input type="text" name="name" class="form-control" maxlength="191" value="<?php echo e($_POST['name'] ?? 'Central Godown'); ?>" required>
                            </div>
                            <div class="col-md-6 form-group">
                                <label>Code <small class="text-muted">(optional)</small></label>
                                <input type="text" name="code" class="form-control" maxlength="50" value="<?php echo e($_POST['code'] ?? ''); ?>" placeholder="Auto-generated when left blank">
                            </div>
                            <div class="col-md-6 form-group">
                                <label>Manager <small class="text-muted">(optional)</small></label>
                                <select name="manager_user_id" class="form-control">
                                    <option value="0">— No manager —</option>
                                    <?php foreach ($godown_managers as $manager): ?>
                                        <option value="<?php echo (int)$manager['user_id']; ?>" <?php echo (int)($_POST['manager_user_id'] ?? 0) === (int)$manager['user_id'] ? 'selected' : ''; ?>>
                                            <?php echo e(trim($manager['first_name'] . ' ' . $manager['last_name']) ?: $manager['username']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-6 form-group">
                                <label>Phone</label>
                                <input type="text" name="phone" class="form-control" maxlength="50" value="<?php echo e($_POST['phone'] ?? ''); ?>">
                            </div>
                            <div class="col-12 form-group">
                                <label>Address</label>
                                <textarea name="address" class="form-control" rows="2"><?php echo e($_POST['address'] ?? ''); ?></textarea>
                            </div>
                        </div>
                        <button type="submit" class="btn btn-primary"><i class="fa fa-check mr-1"></i> Create Central Godown</button>
                    </form>
                <?php endif; ?>
            </div>
        </div>
    <?php else: ?>
        <div class="row">
            <div class="col-lg-7 mb-4">
                <div class="card h-100">
                    <div class="card-header d-flex justify-content-between align-items-center flex-wrap">
                        <h5 class="card-title mb-0"><i class="fa fa-archive mr-1"></i> <?php echo e($godown['name']); ?></h5>
                        <span class="badge badge-success">Active Godown</span>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <div class="col-sm-6 mb-3">
                                <div class="text-muted small">Code</div>
                                <div class="font-weight-semibold"><?php echo e($godown['code'] ?: '—'); ?></div>
                            </div>
                            <div class="col-sm-6 mb-3">
                                <div class="text-muted small">Manager</div>
                                <div class="font-weight-semibold"><?php echo e(trim((string)($godown['manager_name'] ?? '')) ?: ($godown['manager_username'] ?: 'Not assigned')); ?></div>
                            </div>
                            <div class="col-sm-6 mb-3">
                                <div class="text-muted small">Phone</div>
                                <div><?php echo e($godown['phone'] ?: '—'); ?></div>
                            </div>
                            <div class="col-sm-6 mb-3">
                                <div class="text-muted small">Created</div>
                                <div><?php echo e(date('M d, Y', strtotime($godown['created_at']))); ?></div>
                            </div>
                            <div class="col-12 mb-3">
                                <div class="text-muted small">Address</div>
                                <div><?php echo $godown['address'] ? nl2br(e($godown['address'])) : '<span class="text-muted">—</span>'; ?></div>
                            </div>
                            <div class="col-sm-6 mb-2">
                                <div class="text-muted small">Stocked products</div>
                                <div class="font-weight-semibold"><?php echo $stock_summary['products']; ?></div>
                            </div>
                            <div class="col-sm-6 mb-2">
                                <div class="text-muted small">Total quantity</div>
                                <div class="font-weight-semibold"><?php echo e(format_quantity($stock_summary['qty'])); ?></div>
                            </div>
                        </div>
                        <div class="mt-2">
                            <a href="godown.php?tab=products" class="btn btn-light btn-sm"><i class="fa fa-cubes mr-1"></i> View Products</a>
                            <a href="godown.php?tab=transfers" class="btn btn-light btn-sm"><i class="fa fa-exchange mr-1"></i> Transfer History</a>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-lg-5 mb-4">
                <?php if ($can_manage): ?>
                    <div class="card mb-4">
                        <div class="card-header"><h5 class="card-title mb-0"><i class="fa fa-pencil mr-1"></i> Edit Godown</h5></div>
                        <div class="card-body">
                            <form method="POST" action="godown.php?tab=overview">
                                <?php echo csrf_field(); ?>
                                <?php echo form_token_field(); ?>
                                <input type="hidden" name="action" value="update_godown">
                                <input type="hidden" name="warehouse_id" value="<?php echo (int)$godown['warehouse_id']; ?>">
                                <div class="form-group">
                                    <label>Name <span class="text-danger">*</span></label>
                                    <input type="text" name="name" class="form-control" maxlength="191" value="<?php echo e($godown['name']); ?>" required>
                                </div>
                                <div class="form-row">
                                    <div class="form-group col-sm-6">
                                        <label>Code</label>
                                        <input type="text" name="code" class="form-control" maxlength="50" value="<?php echo e($godown['code']); ?>">
                                    </div>
                                    <div class="form-group col-sm-6">
                                        <label>Phone</label>
                                        <input type="text" name="phone" class="form-control" maxlength="50" value="<?php echo e($godown['phone']); ?>">
                                    </div>
                                </div>
                                <div class="form-group">
                                    <label>Manager</label>
                                    <select name="manager_user_id" class="form-control">
                                        <option value="0">— No manager —</option>
                                        <?php foreach ($godown_managers as $manager): ?>
                                            <option value="<?php echo (int)$manager['user_id']; ?>" <?php echo (int)($godown['manager_user_id'] ?? 0) === (int)$manager['user_id'] ? 'selected' : ''; ?>>
                                                <?php echo e(trim($manager['first_name'] . ' ' . $manager['last_name']) ?: $manager['username']); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="form-group">
                                    <label>Address</label>
                                    <textarea name="address" class="form-control" rows="2"><?php echo e($godown['address']); ?></textarea>
                                </div>
                                <button type="submit" class="btn btn-primary btn-sm"><i class="fa fa-save mr-1"></i> Save Changes</button>
                            </form>
                        </div>
                    </div>
                <?php endif; ?>
                <?php if ($can_manage): ?>
                    <div class="card border-danger">
                        <div class="card-header"><h5 class="card-title mb-0 text-danger"><i class="fa fa-trash mr-1"></i> Delete Godown</h5></div>
                        <div class="card-body">
                            <p class="text-muted small mb-3">
                                Stock must be zero and all transfers must be closed. If the Godown has receipt or transfer history,
                                it is archived instead of being removed so the history stays intact.
                            </p>
                            <form method="POST" action="godown.php?tab=overview" onsubmit="return confirm('Delete or archive this Godown? This cannot be undone from this page.');">
                                <?php echo csrf_field(); ?>
                                <?php echo form_token_field(); ?>
                                <input type="hidden" name="action" value="delete_godown">
                                <input type="hidden" name="warehouse_id" value="<?php echo (int)$godown['warehouse_id']; ?>">
                                <button type="submit" class="btn btn-outline-danger btn-sm"><i class="fa fa-trash mr-1"></i> Delete Godown</button>
                            </form>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    <?php endif; ?>

    <?php if ($archived_godowns): ?>
        <div class="card mb-4">
            <div class="card-header"><h5 class="card-title mb-0"><i class="fa fa-archive mr-1"></i> Archived Godowns</h5></div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover mb-0">
                        <thead><tr><th>Godown</th><th>Code</th><th>Manager</th><th>Stock Qty</th><th>Archived</th></tr></thead>
                        <tbody>
                        <?php foreach ($archived_godowns as $archived): ?>
                            <tr>
                                <td class="font-weight-semibold"><?php echo e($archived['name']); ?></td>
                                <td><span class="badge badge-secondary"><?php echo e($archived['code'] ?: '—'); ?></span></td>
                                <td><?php echo e(trim((string)$archived['manager_name']) ?: ($archived['manager_username'] ?: '—')); ?></td>
                                <td><?php echo e(format_quantity($archived['stock_qty'])); ?></td>
                                <td><?php echo e(date('M d, Y H:i', strtotime($archived['updated_at']))); ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    <?php endif; ?>
<?php endif; ?>

<?php if ($tab === 'transfers'): ?>
    <?php if ($godown): ?>
        <div class="card mb-4">
            <div class="card-header">
                <form method="GET" action="godown.php" class="form-inline flex-wrap">
                    <input type="hidden" name="tab" value="transfers">
                    <select name="status" class="form-control form-control-sm mr-2 mb-1">
                        <option value="">All Statuses</option>
                        <?php foreach ($statuses as $key => $label): ?>
                            <option value="<?php echo e($key); ?>" <?php echo $transfer_status === $key ? 'selected' : ''; ?>><?php echo e($label); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <input type="text" name="tq" class="form-control form-control-sm mr-2 mb-1" value="<?php echo e($transfer_q); ?>" placeholder="Transfer, shop, store, product...">
                    <button type="submit" class="btn btn-sm btn-primary mb-1"><i class="fa fa-filter mr-1"></i> Filter</button>
                    <?php if ($transfer_status !== '' || $transfer_q !== ''): ?>
                        <a href="godown.php?tab=transfers" class="btn btn-sm btn-light mb-1 ml-2">Clear</a>
                    <?php endif; ?>
                </form>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover mb-0">
                        <thead>
                            <tr><th>Transfer</th><th>Destination</th><th>Items</th><th>Status</th><th>Requested</th><th></th></tr>
                        </thead>
                        <tbody>
                        <?php foreach ($godown_transfers as $transfer): ?>
                            <tr>
                                <td class="font-weight-semibold"><?php echo e($transfer['transfer_number']); ?></td>
                                <td>
                                    <?php echo e($transfer['destination_shop_name']); ?>
                                    <br><small class="text-muted"><?php echo e($transfer['destination_store_name']); ?></small>
                                </td>
                                <td>
                                    <?php echo (int)$transfer['item_count']; ?> line(s)
                                    <br><small class="text-muted"><?php echo e(format_quantity($transfer['total_quantity'])); ?> requested</small>
                                </td>
                                <td><span class="badge badge-<?php echo e(godown_transfer_status_class($transfer['status'])); ?>"><?php echo e(godown_transfer_status_label($transfer['status'])); ?></span></td>
                                <td><?php echo e(date('M d, Y H:i', strtotime($transfer['requested_at']))); ?></td>
                                <td class="text-right"><a href="godown-transfer-view.php?id=<?php echo (int)$transfer['transfer_id']; ?>" class="btn btn-action btn-light" title="View"><i class="fa fa-eye"></i></a></td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (!$godown_transfers): ?>
                            <tr><td colspan="6" class="text-center text-muted py-4">No transfers match the current filters.</td></tr>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    <?php else: ?>
        <div class="alert alert-warning">
            Create an active Godown before creating or reviewing transfers.
            <a href="godown.php?tab=overview" class="alert-link">Go to Overview</a>.
        </div>
    <?php endif; ?>

    <div class="row">
        <div class="col-lg-5 mb-4">
            <div class="card h-100">
                <div class="card-header"><h5 class="card-title mb-0"><i class="fa fa-building mr-1"></i> Available Shops</h5></div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover mb-0">
                            <thead><tr><th>Shop</th><th>Code</th><th>Stores</th><th>Phone</th></tr></thead>
                            <tbody>
                            <?php foreach ($shops as $shop): ?>
                                <tr>
                                    <td class="font-weight-semibold"><?php echo e($shop['name']); ?></td>
                                    <td><span class="badge badge-secondary"><?php echo e($shop['code']); ?></span></td>
                                    <td><?php echo (int)($shop_store_counts[(int)$shop['shop_id']] ?? 0); ?></td>
                                    <td><?php echo e($shop['phone'] ?: '—'); ?></td>
                                </tr>
                            <?php endforeach; ?>
                            <?php if (!$shops): ?>
                                <tr><td colspan="4" class="text-center text-muted py-4">No shops available.</td></tr>
                            <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-lg-7 mb-4">
            <div class="card h-100">
                <div class="card-header"><h5 class="card-title mb-0"><i class="fa fa-home mr-1"></i> Available Stores</h5></div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover mb-0">
                            <thead><tr><th>Store</th><th>Shop</th><th>Stock Lines</th><th>Stock Qty</th><th></th></tr></thead>
                            <tbody>
                            <?php foreach ($stores as $store): ?>
                                <?php $stock = $store_stock[(int)$store['warehouse_id']] ?? ['lines' => 0, 'qty' => 0.0]; ?>
                                <tr>
                                    <td class="font-weight-semibold"><?php echo e($store['name']); ?></td>
                                    <td><?php echo e($store['shop_name']); ?></td>
                                    <td><?php echo (int)$stock['lines']; ?></td>
                                    <td><?php echo e(format_quantity($stock['qty'])); ?></td>
                                    <td class="text-right">
                                        <?php if ($godown && $can_manage): ?>
                                            <a href="godown-transfer-add.php?shop=<?php echo (int)$store['shop_id']; ?>" class="btn btn-action btn-light" title="New transfer to this shop"><i class="fa fa-exchange"></i></a>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            <?php if (!$stores): ?>
                                <tr><td colspan="5" class="text-center text-muted py-4">No stores available.</td></tr>
                            <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
<?php endif; ?>

<script>
    $(function () {
        function syncReceiveUnit() {
            var $product = $('#receive_product');
            var $qty = $('#receive_quantity');
            var decimal = String($product.find('option:selected').data('decimal')) === '1';
            $qty.attr('step', decimal ? '0.01' : '1');
            $qty.attr('min', decimal ? '0.01' : '1');
            $('#receive_unit_hint').text(decimal ? 'Decimal quantities are allowed for this product.' : 'Whole units only for this product.');
        }
        $('#receive_product').on('change', syncReceiveUnit);
        syncReceiveUnit();
    });
</script>

<!-- Adjust Stock Modal -->
<div class="modal fade" id="adjustStockModal" tabindex="-1" role="dialog" aria-labelledby="adjustStockModalLabel" aria-hidden="true">
    <div class="modal-dialog" role="document">
        <div class="modal-content">

           <form id="ajaxAdjustForm" method="POST" action="inventory-adjust.php">
                <?php echo csrf_field(); ?>
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fa fa-sliders mr-1"></i> Adjust Stock</h5>
                    <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                        <span aria-hidden="true">&times;</span>
                    </button>
                </div>
                <div class="modal-body">
                    <div id="adjustModalAlert" class="alert d-none"></div>
                    
                    <!-- Make sure product_id and warehouse_id are explicitly present -->
                    <input type="hidden" name="product_id" id="modal_product_id" value="">
                    <input type="hidden" name="warehouse_id" id="modal_warehouse_id" value="<?php echo isset($godown) ? (int)$godown['warehouse_id'] : ''; ?>">
                    
                    <div class="form-group" id="modal_product_name_group">
                        <label class="font-weight-semibold">Product</label>
                        <input type="text" id="modal_product_name" class="form-control-plaintext font-weight-bold" readonly>
                    </div>

                    <div class="form-group">
                        <label>Quantity to Add <span class="text-danger">*</span></label>
                        <input type="number" name="quantity" id="modal_quantity" class="form-control" step="0.01" min="0.01" required>
                        <small class="text-muted" id="modal_unit_hint">Enter quantity to add to stock.</small>
                    </div>

                    <div class="form-group">
                        <label>Notes</label>
                        <textarea name="notes" class="form-control" rows="2" placeholder="e.g. Stock adjustment / count correction"></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary btn-sm" data-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary btn-sm" id="saveAdjustBtn"><i class="fa fa-check mr-1"></i> Save Stock</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
    $(function () {
        function syncReceiveUnit() {
            var $product = $('#receive_product');
            var $qty = $('#receive_quantity');
            var decimal = String($product.find('option:selected').data('decimal')) === '1';
            $qty.attr('step', decimal ? '0.01' : '1');
            $qty.attr('min', decimal ? '0.01' : '1');
            $('#receive_unit_hint').text(decimal ? 'Decimal quantities are allowed for this product.' : 'Whole units only for this product.');
        }
        $('#receive_product').on('change', syncReceiveUnit);
        syncReceiveUnit();

        // Trigger modal popup from product rows
        $(document).on('click', '.open-adjust-modal', function (e) {
            e.preventDefault();
            var productId = $(this).data('product-id');
            var productName = $(this).data('product-name');
            var isDecimal = String($(this).data('decimal')) === '1';

            $('#modal_product_id').val(productId);
            $('#modal_product_name').val(productName);
            $('#modal_quantity').attr('step', isDecimal ? '0.01' : '1');
            $('#modal_quantity').attr('min', isDecimal ? '0.01' : '1');
            $('#modal_unit_hint').text(isDecimal ? 'Decimal quantities are allowed for this product.' : 'Whole units only for this product.');
            $('#adjustModalAlert').addClass('d-none').removeClass('alert-success alert-danger');
            
            $('#adjustStockModal').modal('show');
        });

        // Handle AJAX form submission for popup adjustment
        $('#ajaxAdjustForm').on('submit', function (e) {
            e.preventDefault();
            var $form = $(this);
            var $btn = $('#saveAdjustBtn');
            $btn.prop('disabled', true).html('<i class="fa fa-spinner fa-spin mr-1"></i> Saving...');
            $('#adjustModalAlert').addClass('d-none');

            $.ajax({
                type: 'POST',
                url: 'inventory-adjust.php',
                data: $form.serialize(),
                dataType: 'json',
                success: function (res) {
                    if (res.success) {
                        $('#adjustModalAlert').removeClass('d-none').removeClass('alert-danger').addClass('alert-success').text(res.message);
                        setTimeout(function () {
                            $('#adjustStockModal').modal('hide');
                            location.reload();
                        }, 700);
                    } else {
                        // Show the exact database or validation error returned by PHP!
                        $('#adjustModalAlert').removeClass('d-none').removeClass('alert-success').addClass('alert-danger').text(res.message);
                        $btn.prop('disabled', false).html('<i class="fa fa-check mr-1"></i> Save Stock');
                    }
                },
                error: function (xhr, status, error) {
                    // If PHP threw a fatal error or crashed, show what came back
                    var errorMsg = 'Server communication error.';
                    if (xhr.responseText) {
                        console.error(xhr.responseText); // Log full HTML/error to browser console for debugging
                        errorMsg = 'Server error occurred. Check browser console for details.';
                    }
                    $('#adjustModalAlert').removeClass('d-none').removeClass('alert-success').addClass('alert-danger').text(errorMsg);
                    $btn.prop('disabled', false).html('<i class="fa fa-check mr-1"></i> Save Stock');
                }
            });
        });
    });
</script>



<?php require_once 'footer.php'; ?>