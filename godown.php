<?php
require_once 'header.php';
require_once 'auth_check.php';
require_permission('manage_godown');
$page_title = 'Central Godown';

$tenant_id = (int)$current_user->tenant_id;
$can_manage = can('manage_godown');

/** Dependency counts that make a store unsafe to turn into a Godown. */
function godown_store_dependencies(int $tenant_id, int $warehouse_id): array
{
    return fetch_one(
        "SELECT
            (SELECT COUNT(*) FROM inventory_stock WHERE tenant_id = ? AND warehouse_id = ?) stock_rows,
            (SELECT COALESCE(SUM(quantity), 0) FROM inventory_stock WHERE tenant_id = ? AND warehouse_id = ?) stock_qty,
            (SELECT COUNT(*) FROM inventory_movements WHERE tenant_id = ? AND warehouse_id = ?) movement_rows,
            (SELECT COUNT(*) FROM pos_orders WHERE tenant_id = ? AND warehouse_id = ?) sale_rows,
            (SELECT COUNT(*) FROM invoices WHERE tenant_id = ? AND warehouse_id = ?) invoice_rows,
            (SELECT COUNT(*) FROM pos_sessions WHERE tenant_id = ? AND warehouse_id = ?) session_rows,
            (SELECT COUNT(*) FROM stock_transfers WHERE tenant_id = ? AND (source_warehouse_id = ? OR destination_warehouse_id = ?)) transfer_rows",
        $tenant_id,
        $warehouse_id,
        $tenant_id,
        $warehouse_id,
        $tenant_id,
        $warehouse_id,
        $tenant_id,
        $warehouse_id,
        $tenant_id,
        $warehouse_id,
        $tenant_id,
        $warehouse_id,
        $tenant_id,
        $warehouse_id,
        $warehouse_id
    ) ?: [];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    if (!form_token_valid()) {
        flash_set('danger', 'This form was already submitted. Refresh the page before trying again.');
        redirect('godown.php');
    }

    $action = (string)($_POST['action'] ?? '');
    $in_transaction = false;

    try {
        if ($action === 'designate_godown') {
            $warehouse_id = (int)($_POST['warehouse_id'] ?? 0);
            $store = find_accessible_store($warehouse_id, false, true);
            if (!$store) {
                throw new RuntimeException('Select an active store you can access.');
            }
            if ((int)($store['is_godown'] ?? 0) === 1) {
                throw new RuntimeException('That store is already the central Godown.');
            }

            $conn->begin_transaction();
            $in_transaction = true;

            $existing = fetch_one(
                "SELECT w.warehouse_id, w.name, s.name shop_name
                   FROM warehouses w
                   JOIN shops s ON s.shop_id = w.shop_id AND s.tenant_id = w.tenant_id
                  WHERE w.tenant_id = ? AND w.is_godown = 1 AND w.is_active = 1
                  FOR UPDATE",
                $tenant_id
            );
            if ($existing && (int)$existing['warehouse_id'] === $warehouse_id) {
                throw new RuntimeException('That store is already the default Godown.');
            }

            // Existing stock and stock movements stay in place and become
            // Godown stock. Sales, invoices, POS sessions and transfers would
            // make the store part of ordinary shop history, so those still
            // block the change.
            $deps = godown_store_dependencies($tenant_id, $warehouse_id);
            $blocked = [];
            foreach ([
                'sale_rows' => 'sales',
                'invoice_rows' => 'invoices',
                'session_rows' => 'POS sessions',
                'transfer_rows' => 'transfers',
            ] as $key => $label) {
                if ((int)($deps[$key] ?? 0) > 0) $blocked[] = $label;
            }
            if ($blocked) {
                throw new RuntimeException('Choose a store without sales, invoices, POS sessions or transfers. This store already has ' . implode(', ', $blocked) . '.');
            }

            // Switching the default Godown is only safe when the current one
            // holds no stock and has no open transfers.
            if ($existing) {
                $current_stock = fetch_one(
                    "SELECT COALESCE(SUM(quantity), 0) qty
                       FROM inventory_stock
                      WHERE tenant_id = ? AND warehouse_id = ?",
                    $tenant_id,
                    (int)$existing['warehouse_id']
                );
                if (abs((float)($current_stock['qty'] ?? 0)) > 0.0001) {
                    throw new RuntimeException('Transfer all stock out of the current default Godown (' . $existing['name'] . ') before choosing another shop.');
                }
                $open = fetch_one(
                    "SELECT COUNT(*) c FROM stock_transfers
                      WHERE tenant_id = ? AND source_warehouse_id = ?
                        AND status IN ('draft', 'approved', 'dispatched', 'discrepancy')",
                    $tenant_id,
                    (int)$existing['warehouse_id']
                );
                if ((int)($open['c'] ?? 0) > 0) {
                    throw new RuntimeException('Finish or cancel all open transfers at the current default Godown before choosing another shop.');
                }

                $stmt = $conn->prepare(
                    "UPDATE warehouses SET is_godown = 0
                      WHERE warehouse_id = ? AND tenant_id = ? AND is_godown = 1"
                );
                $stmt->bind_param('ii', $existing['warehouse_id'], $tenant_id);
                $stmt->execute();
                $stmt->close();
            }

            $stmt = $conn->prepare(
                "UPDATE warehouses
                    SET is_godown = 1
                  WHERE warehouse_id = ? AND tenant_id = ? AND is_active = 1 AND is_godown = 0"
            );
            $stmt->bind_param('ii', $warehouse_id, $tenant_id);
            $stmt->execute();
            $updated = $stmt->affected_rows;
            $stmt->close();
            if ($updated !== 1) {
                throw new RuntimeException('That store could not be designated. It may have changed.');
            }

            $conn->commit();
            $in_transaction = false;
            consume_form_token();
            audit_log(
                'configure',
                'warehouse',
                $warehouse_id,
                'Default Godown set to ' . store_label($store)
                . ' (shop: ' . $store['shop_name'] . ').'
                . ($existing ? ' Previous default: ' . store_label($existing) . '.' : '')
            );
            flash_set(
                'success',
                'Default Godown set to ' . store_label($store) . '.'
                . ($existing ? ' The previous Godown was released.' : '')
            );
            redirect('godown.php');
        }

        if ($action === 'remove_godown') {
            $conn->begin_transaction();
            $in_transaction = true;

            $godown = fetch_one(
                "SELECT w.*, s.name shop_name
                   FROM warehouses w
                   JOIN shops s ON s.shop_id = w.shop_id AND s.tenant_id = w.tenant_id
                  WHERE w.tenant_id = ? AND w.is_godown = 1 AND w.is_active = 1
                  ORDER BY w.warehouse_id
                  LIMIT 1
                  FOR UPDATE",
                $tenant_id
            );
            if (!$godown) {
                throw new RuntimeException('There is no active central Godown to remove.');
            }
            if (!shop_is_accessible((int)$godown['shop_id'], true)) {
                throw new RuntimeException('You do not have access to the shop that owns the central Godown.');
            }

            $stock = fetch_one(
                "SELECT COALESCE(SUM(quantity), 0) qty FROM inventory_stock WHERE tenant_id = ? AND warehouse_id = ?",
                $tenant_id,
                (int)$godown['warehouse_id']
            );
            if (abs((float)($stock['qty'] ?? 0)) > 0.0001) {
                throw new RuntimeException('Transfer all stock out of the Godown before removing its designation.');
            }

            $open = fetch_one(
                "SELECT COUNT(*) c FROM stock_transfers
                  WHERE tenant_id = ? AND source_warehouse_id = ?
                    AND status IN ('draft', 'approved', 'dispatched', 'discrepancy')",
                $tenant_id,
                (int)$godown['warehouse_id']
            );
            if ((int)($open['c'] ?? 0) > 0) {
                throw new RuntimeException('Finish or cancel all open transfers and resolve discrepancies before removing the Godown designation.');
            }

            $stmt = $conn->prepare(
                "UPDATE warehouses SET is_godown = 0 WHERE warehouse_id = ? AND tenant_id = ? AND is_godown = 1"
            );
            $stmt->bind_param('ii', $godown['warehouse_id'], $tenant_id);
            $stmt->execute();
            $stmt->close();

            $conn->commit();
            $in_transaction = false;
            consume_form_token();
            audit_log('configure', 'warehouse', (int)$godown['warehouse_id'], 'Central Godown designation removed');
            flash_set('success', 'Central Godown designation removed. The store remains available as a normal store.');
            redirect('godown.php');
        }

        if ($action === 'receive_stock') {
            $active = fetch_one(
                "SELECT w.* FROM warehouses w
                   JOIN shops s ON s.shop_id = w.shop_id AND s.tenant_id = w.tenant_id
                  WHERE w.tenant_id = ? AND w.is_godown = 1 AND w.is_active = 1 AND s.is_active = 1
                  ORDER BY w.warehouse_id LIMIT 1",
                $tenant_id
            );
            if (!$active || !shop_is_accessible((int)$active['shop_id'], false)) {
                throw new RuntimeException('No active central Godown is available to you.');
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
                "SELECT w.* FROM warehouses w
                   JOIN shops s ON s.shop_id = w.shop_id AND s.tenant_id = w.tenant_id
                  WHERE w.warehouse_id = ? AND w.tenant_id = ? AND w.is_godown = 1
                    AND w.is_active = 1 AND s.is_active = 1
                  FOR UPDATE",
                (int)$active['warehouse_id'],
                $tenant_id
            );
            if (!$locked_godown) {
                throw new RuntimeException('The central Godown is no longer active.');
            }

            $locked_product = fetch_one(
                "SELECT product_id, is_active, is_service FROM products WHERE product_id = ? AND tenant_id = ? FOR UPDATE",
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
                (int)$locked_godown['warehouse_id']
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
                $locked_godown['warehouse_id'],
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
            if ($notes_db !== null) $movement_notes .= ' — ' . $notes_db;

            $stmt = $conn->prepare(
                "INSERT INTO inventory_movements
                    (tenant_id, product_id, warehouse_id, movement_type, quantity, reference_id, reference_type, created_by, notes)
                 VALUES (?, ?, ?, 'godown_receipt', ?, ?, 'godown_receipt', ?, ?)"
            );
            $stmt->bind_param(
                'iiidiis',
                $tenant_id,
                $product_id,
                $locked_godown['warehouse_id'],
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
                . ' into the central Godown (' . $receipt_number . ').'
                . ($source_db !== null ? ' Source: ' . $source_db . '.' : '')
            );
            flash_set('success', 'Received ' . format_quantity($quantity) . ' ' . ($product['short_name'] ?: 'unit(s)')
                . ' of ' . $product['name'] . ' into the central Godown. Receipt ' . $receipt_number . '.');
            redirect('godown.php');
        }

        throw new RuntimeException('Unknown Godown action.');
    } catch (Throwable $ex) {
        if ($in_transaction) {
            $conn->rollback();
        }
        flash_set('danger', $ex->getMessage());
        redirect('godown.php');
    }
}

$godown = find_active_godown();
$tenant_active_godown = fetch_one(
    "SELECT w.warehouse_id, w.name, s.name shop_name
       FROM warehouses w
       JOIN shops s ON s.shop_id = w.shop_id AND s.tenant_id = w.tenant_id
      WHERE w.tenant_id = ? AND w.is_godown = 1 AND w.is_active = 1
      ORDER BY w.warehouse_id LIMIT 1",
    $tenant_id
);

$candidate_shops = accessible_shops(null, false);
$candidate_shop_ids = array_map(fn($s) => (int)$s['shop_id'], $candidate_shops);
$candidate_shop_id = (int)($_GET['shop'] ?? 0);
if ($candidate_shop_id > 0 && !in_array($candidate_shop_id, $candidate_shop_ids, true)) {
    $candidate_shop_id = 0;
}

$candidates = [];
foreach (accessible_stores(null, false, null, true) as $store) {
    if ((int)($store['is_godown'] ?? 0) === 1) continue;
    if ($candidate_shop_id > 0 && (int)$store['shop_id'] !== $candidate_shop_id) continue;

    $deps = godown_store_dependencies($tenant_id, (int)$store['warehouse_id']);
    $blocked_labels = [];
    foreach ([
        'sale_rows' => 'sales',
        'invoice_rows' => 'invoices',
        'session_rows' => 'POS sessions',
        'transfer_rows' => 'transfers',
    ] as $key => $label) {
        if ((int)($deps[$key] ?? 0) > 0) $blocked_labels[] = $label;
    }

    $store['dependencies'] = $deps;
    $store['blocked_labels'] = $blocked_labels;
    $store['eligible'] = !$blocked_labels;
    $store['stock_rows'] = fetch_all(
        "SELECT p.name product_name, p.code product_code, s.quantity,
                COALESCE(u.short_name, '') unit_short
           FROM inventory_stock s
           JOIN products p ON p.product_id = s.product_id AND p.tenant_id = s.tenant_id
           LEFT JOIN units_of_measurement u ON u.unit_id = p.unit_id AND u.tenant_id = p.tenant_id
          WHERE s.tenant_id = ? AND s.warehouse_id = ? AND s.quantity > 0
          ORDER BY p.name",
        $tenant_id,
        (int)$store['warehouse_id']
    );
    $store['stock_total'] = 0.0;
    foreach ($store['stock_rows'] as $stock_row) {
        $store['stock_total'] += (float)$stock_row['quantity'];
    }
    $candidates[] = $store;
}

$candidates_by_shop = [];
foreach ($candidates as $candidate) {
    $candidates_by_shop[(int)$candidate['shop_id']][] = $candidate;
}

$products = fetch_all(
    "SELECT p.product_id, p.code, p.name, p.min_stock_level, u.is_decimal, u.short_name
       FROM products p
       LEFT JOIN units_of_measurement u ON u.unit_id = p.unit_id AND u.tenant_id = p.tenant_id
      WHERE p.tenant_id = ? AND p.is_active = 1 AND p.is_service = 0
      ORDER BY p.name",
    $tenant_id
);

// The stock-position report must still account for stock left behind by a
// product that was later deactivated, so it uses a broader product list than
// the receive form.
$position_products = fetch_all(
    "SELECT p.product_id, p.code, p.name, p.min_stock_level, u.is_decimal, u.short_name
       FROM products p
       LEFT JOIN units_of_measurement u ON u.unit_id = p.unit_id AND u.tenant_id = p.tenant_id
      WHERE p.tenant_id = ? AND p.is_service = 0
      ORDER BY p.name",
    $tenant_id
);

$q = trim((string)($_GET['q'] ?? ''));
$stock_rows = [];
$receipts = [];
$recent_transfers = [];
$shop_stock_rows = [];
$transit_rows = [];
$godown_position_rows = [];
if ($godown) {
    $godown_id = (int)$godown['warehouse_id'];

    $stock_sql = "SELECT s.*, p.name product_name, p.code product_code, p.min_stock_level,
                         COALESCE(u.short_name, '') unit_short, COALESCE(u.is_decimal, 0) is_decimal
                    FROM inventory_stock s
                    JOIN products p ON p.product_id = s.product_id AND p.tenant_id = s.tenant_id
                    LEFT JOIN units_of_measurement u ON u.unit_id = p.unit_id AND u.tenant_id = p.tenant_id
                   WHERE s.tenant_id = ? AND s.warehouse_id = ?";
    $stock_params = [$tenant_id, $godown_id];
    if ($q !== '') {
        $like = '%' . $q . '%';
        $stock_sql .= " AND (p.name LIKE ? OR p.code LIKE ? OR p.sku LIKE ? OR p.barcode LIKE ?)";
        array_push($stock_params, $like, $like, $like, $like);
    }
    $stock_sql .= " ORDER BY p.name";
    $stock_rows = fetch_all($stock_sql, ...$stock_params);

    $godown_position_rows = fetch_all(
        "SELECT product_id, SUM(quantity) qty
           FROM inventory_stock
          WHERE tenant_id = ? AND warehouse_id = ?
          GROUP BY product_id",
        $tenant_id,
        $godown_id
    );

    $receipts = fetch_all(
        "SELECT gr.*, p.name product_name, p.code product_code, COALESCE(u.short_name, '') unit_short,
                TRIM(CONCAT(COALESCE(usr.first_name, ''), ' ', COALESCE(usr.last_name, ''))) received_by_name,
                usr.username received_by_username
           FROM godown_receipts gr
           JOIN products p ON p.product_id = gr.product_id AND p.tenant_id = gr.tenant_id
           LEFT JOIN units_of_measurement u ON u.unit_id = p.unit_id AND u.tenant_id = p.tenant_id
           LEFT JOIN users usr ON usr.user_id = gr.received_by
          WHERE gr.tenant_id = ? AND gr.godown_id = ?
          ORDER BY gr.receipt_id DESC
          LIMIT 25",
        $tenant_id,
        $godown_id
    );

    $recent_transfers = fetch_all(
        "SELECT t.*, s.name destination_shop_name, w.name destination_store_name,
                (SELECT COUNT(*) FROM stock_transfer_items ti WHERE ti.transfer_id = t.transfer_id AND ti.tenant_id = t.tenant_id) item_count,
                (SELECT COALESCE(SUM(ti.requested_quantity), 0) FROM stock_transfer_items ti WHERE ti.transfer_id = t.transfer_id AND ti.tenant_id = t.tenant_id) total_quantity
           FROM stock_transfers t
           JOIN shops s ON s.shop_id = t.destination_shop_id AND s.tenant_id = t.tenant_id
           JOIN warehouses w ON w.warehouse_id = t.destination_warehouse_id AND w.tenant_id = t.tenant_id
          WHERE t.tenant_id = ? AND t.source_warehouse_id = ?
          ORDER BY t.transfer_id DESC
          LIMIT 10",
        $tenant_id,
        $godown_id
    );

    $scope_ids = accessible_warehouse_ids(null, true, false);
    $shop_scope_sql = '';
    $shop_scope_params = [];
    if ($scope_ids !== null) {
        if (!$scope_ids) {
            $shop_scope_sql = ' AND 1 = 0';
        } else {
            $shop_scope_sql = ' AND s.warehouse_id IN (' . implode(',', array_map('intval', $scope_ids)) . ')';
        }
    }
    $shop_stock_rows = fetch_all(
        "SELECT s.product_id, SUM(s.quantity) qty
           FROM inventory_stock s
           JOIN warehouses w ON w.warehouse_id = s.warehouse_id AND w.tenant_id = s.tenant_id
          WHERE s.tenant_id = ? AND w.is_godown = 0{$shop_scope_sql}
          GROUP BY s.product_id",
        $tenant_id,
        ...$shop_scope_params
    );

    $transit_rows = fetch_all(
        "SELECT ti.product_id, SUM(ti.dispatched_quantity) qty
           FROM stock_transfer_items ti
           JOIN stock_transfers t ON t.transfer_id = ti.transfer_id AND t.tenant_id = ti.tenant_id
          WHERE t.tenant_id = ? AND t.source_warehouse_id = ? AND t.status = 'dispatched'
          GROUP BY ti.product_id",
        $tenant_id,
        $godown_id
    );
}

$godown_stock_by_product = [];
foreach ($godown_position_rows as $row) {
    $godown_stock_by_product[(int)$row['product_id']] = (float)$row['qty'];
}
$shop_stock_by_product = [];
foreach ($shop_stock_rows as $row) {
    $shop_stock_by_product[(int)$row['product_id']] = (float)$row['qty'];
}
$transit_by_product = [];
foreach ($transit_rows as $row) {
    $transit_by_product[(int)$row['product_id']] = (float)$row['qty'];
}
$stock_position = [];
foreach ($position_products as $product) {
    $pid = (int)$product['product_id'];
    $godown_qty = $godown_stock_by_product[$pid] ?? 0.0;
    $shop_qty = $shop_stock_by_product[$pid] ?? 0.0;
    $transit_qty = $transit_by_product[$pid] ?? 0.0;
    if (abs($godown_qty) < 0.0001 && abs($shop_qty) < 0.0001 && abs($transit_qty) < 0.0001) continue;
    $stock_position[] = [
        'product' => $product,
        'godown_qty' => $godown_qty,
        'shop_qty' => $shop_qty,
        'transit_qty' => $transit_qty,
    ];
}
$totals = ['godown' => 0.0, 'shop' => 0.0, 'transit' => 0.0];
foreach ($stock_position as $position) {
    $totals['godown'] += $position['godown_qty'];
    $totals['shop'] += $position['shop_qty'];
    $totals['transit'] += $position['transit_qty'];
}

require_once 'navbar.php';
require_once 'sidebar.php';
?>

<div class="page-head">
    <div>
        <h1 class="page-title">Central Godown</h1>
        <div class="page-sub">Central stock is separate from shop POS stock. Receive stock here, then transfer it to shops.</div>
    </div>
    <div>
        <?php if ($godown): ?>
            <a href="godown-transfer-add.php" class="btn btn-primary"><i class="fa fa-exchange mr-1"></i> New Transfer</a>
        <?php endif; ?>
        <a href="godown-transfers.php" class="btn btn-light"><i class="fa fa-list-alt mr-1"></i> Transfers</a>
    </div>
</div>

<ul class="nav page-tabs">
    <li class="nav-item"><a class="nav-link active" href="godown.php"><i class="fa fa-archive mr-1"></i> Godown Stock</a></li>
    <li class="nav-item"><a class="nav-link" href="godown-transfers.php"><i class="fa fa-exchange mr-1"></i> Transfers</a></li>
    <?php if ($godown): ?>
    <li class="nav-item"><a class="nav-link" href="godown-transfer-add.php"><i class="fa fa-plus mr-1"></i> New Transfer</a></li>
    <?php endif; ?>
</ul>

<?php if ($f = flash_get()): ?>
    <div class="alert alert-<?php echo e($f['type']); ?>"><?php echo e($f['message']); ?></div>
<?php endif; ?>

<?php if (!$godown): ?>
    <div class="card mb-4">
        <div class="card-header"><h5 class="card-title mb-0"><i class="fa fa-cog mr-1"></i> Configure the Central Godown</h5></div>
        <div class="card-body">
            <?php if ($tenant_active_godown): ?>
                <div class="alert alert-warning mb-0">
                    This business already has an active central Godown (<?php echo e($tenant_active_godown['name']); ?>
                    at <?php echo e($tenant_active_godown['shop_name']); ?>), but you do not have access to its shop.
                    Ask an administrator to grant shop access or manage the Godown.
                </div>
            <?php elseif (!$candidates): ?>
                <div class="alert alert-warning mb-0">
                    No eligible store is available. Create a new, unused store under a shop first, then return here to designate it as the central Godown.
                    <a href="stores.php" class="alert-link">Open Stores</a>.
                </div>
            <?php else: ?>
                <p class="text-muted">Choose one active, unused store. Its shop relationship stays unchanged and the store becomes the tenant's single active central Godown.</p>
                <form method="POST" action="godown.php" class="form-inline flex-wrap">
                    <?php echo csrf_field(); ?>
                    <?php echo form_token_field(); ?>
                    <input type="hidden" name="action" value="designate_godown">
                    <select name="warehouse_id" class="form-control mr-2 mb-2" required>
                        <option value="">— Select an eligible store —</option>
                        <?php foreach ($candidates as $candidate): ?>
                            <option value="<?php echo (int)$candidate['warehouse_id']; ?>" <?php echo $candidate['eligible'] ? '' : 'disabled'; ?>>
                                <?php echo e(store_label($candidate)); ?><?php echo $candidate['eligible'] ? '' : ' — already has stock or history'; ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <button type="submit" class="btn btn-primary mb-2" onclick="return confirm('Designate this store as the central Godown? This cannot be undone while it holds stock or open transfers.');">
                        <i class="fa fa-check mr-1"></i> Set as Central Godown
                    </button>
                </form>
                <small class="text-muted d-block mt-2">Currently eligible: <?php echo count(array_filter($candidates, fn($c) => $c['eligible'])); ?> store(s).</small>
            <?php endif; ?>
        </div>
    </div>
<?php else: ?>
    <?php
    $godown_deps = godown_store_dependencies($tenant_id, (int)$godown['warehouse_id']);
    $has_any_stock = abs((float)($godown_deps['stock_qty'] ?? 0)) > 0.0001;
    ?>
    <div class="row mb-4">
        <div class="col-md-6 col-xl-3 mb-3">
            <div class="widget">
                <div class="widget-icon wi-amber"><i class="fa fa-archive"></i></div>
                <div class="widget-info">
                    <div class="widget-value"><?php echo e(format_quantity($totals['godown'])); ?></div>
                    <div class="widget-label">Godown Stock</div>
                </div>
            </div>
        </div>
        <div class="col-md-6 col-xl-3 mb-3">
            <div class="widget">
                <div class="widget-icon wi-green"><i class="fa fa-cubes"></i></div>
                <div class="widget-info">
                    <div class="widget-value"><?php echo e(format_quantity($totals['shop'])); ?></div>
                    <div class="widget-label">Shop Warehouse Stock</div>
                </div>
            </div>
        </div>
        <div class="col-md-6 col-xl-3 mb-3">
            <div class="widget">
                <div class="widget-icon wi-blue"><i class="fa fa-truck"></i></div>
                <div class="widget-info">
                    <div class="widget-value"><?php echo e(format_quantity($totals['transit'])); ?></div>
                    <div class="widget-label">In Transit</div>
                </div>
            </div>
        </div>
        <div class="col-md-6 col-xl-3 mb-3">
            <div class="widget">
                <div class="widget-icon wi-purple"><i class="fa fa-building"></i></div>
                <div class="widget-info">
                    <div class="widget-value" style="font-size:1.1rem;"><?php echo e(store_label($godown)); ?></div>
                    <div class="widget-label">Active Central Godown</div>
                </div>
            </div>
        </div>
    </div>

    <div class="row">
        <div class="col-lg-5 mb-4">
            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h5 class="card-title mb-0"><i class="fa fa-download mr-1"></i> Receive Stock</h5>
                    <?php if (!$has_any_stock): ?>
                    <form method="POST" action="godown.php" onsubmit="return confirm('Remove the central Godown designation from this store?');">
                        <?php echo csrf_field(); ?>
                        <?php echo form_token_field(); ?>
                        <input type="hidden" name="action" value="remove_godown">
                        <button type="submit" class="btn btn-sm btn-light text-danger"><i class="fa fa-unlink mr-1"></i> Remove designation</button>
                    </form>
                    <?php endif; ?>
                </div>
                <div class="card-body">
                    <p class="text-muted">Receipts increase only this Godown's stock and create an audited stock movement. They do not create an invoice or purchase order.</p>
                    <form method="POST" action="godown.php" id="godownReceiveForm">
                        <?php echo csrf_field(); ?>
                        <?php echo form_token_field(); ?>
                        <input type="hidden" name="action" value="receive_stock">
                        <div class="form-group">
                            <label>Product <span class="text-danger">*</span></label>
                            <select name="product_id" id="receive_product" class="form-control" required>
                                <option value="">— Select Product —</option>
                                <?php foreach ($products as $product): ?>
                                    <option value="<?php echo (int)$product['product_id']; ?>" data-decimal="<?php echo (int)$product['is_decimal']; ?>">
                                        <?php echo e($product['name'] . ' (' . $product['code'] . ')'); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>Quantity <span class="text-danger">*</span></label>
                            <input type="number" name="quantity" id="receive_quantity" class="form-control" step="1" min="0.01" max="99999999" required>
                            <small class="text-muted" id="receive_unit_hint">Whole units only unless the product uses decimal units.</small>
                        </div>
                        <div class="form-group">
                            <label>Source / Reference</label>
                            <input type="text" name="source_reference" class="form-control" maxlength="191" placeholder="e.g. Supplier delivery note or stock count">
                        </div>
                        <div class="form-group">
                            <label>Notes</label>
                            <textarea name="notes" class="form-control" rows="2" maxlength="2000" placeholder="Optional receiving notes"></textarea>
                        </div>
                        <button type="submit" class="btn btn-primary"><i class="fa fa-check mr-1"></i> Receive into Godown</button>
                    </form>
                </div>
            </div>
        </div>

        <div class="col-lg-7 mb-4">
            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center flex-wrap">
                    <h5 class="card-title mb-0">Godown Stock</h5>
                    <form method="GET" action="godown.php" class="form-inline mt-2 mt-sm-0">
                        <div class="input-group input-group-sm">
                            <input type="text" name="q" class="form-control" value="<?php echo e($q); ?>" placeholder="Search product or code">
                            <div class="input-group-append">
                                <button type="submit" class="btn btn-light"><i class="fa fa-search"></i></button>
                            </div>
                        </div>
                    </form>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover mb-0">
                            <thead><tr><th>Product</th><th>Code</th><th>Quantity</th><th>Unit</th><th>Last Counted</th></tr></thead>
                            <tbody>
                            <?php foreach ($stock_rows as $row): ?>
                                <tr>
                                    <td class="font-weight-semibold"><?php echo e($row['product_name']); ?></td>
                                    <td><span class="badge badge-secondary"><?php echo e($row['product_code']); ?></span></td>
                                    <td class="font-weight-semibold"><?php echo e(format_quantity($row['quantity'])); ?></td>
                                    <td><?php echo e($row['unit_short']); ?></td>
                                    <td><?php echo $row['last_counted_at'] ? e(date('M d, Y H:i', strtotime($row['last_counted_at']))) : '—'; ?></td>
                                </tr>
                            <?php endforeach; ?>
                            <?php if (!$stock_rows): ?>
                                <tr><td colspan="5" class="text-center text-muted py-4">No Godown stock matches this search.</td></tr>
                            <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="card mb-4" id="stock-position">
        <div class="card-header"><h5 class="card-title mb-0">Stock Position — Godown vs Shops vs In Transit</h5></div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover mb-0">
                    <thead><tr><th>Product</th><th>Code</th><th>Godown</th><th>Shop Warehouses</th><th>In Transit</th><th>Unit</th></tr></thead>
                    <tbody>
                    <?php foreach ($stock_position as $position): ?>
                        <tr>
                            <td class="font-weight-semibold"><?php echo e($position['product']['name']); ?></td>
                            <td><span class="badge badge-secondary"><?php echo e($position['product']['code']); ?></span></td>
                            <td><?php echo e(format_quantity($position['godown_qty'])); ?></td>
                            <td><?php echo e(format_quantity($position['shop_qty'])); ?></td>
                            <td><?php echo e(format_quantity($position['transit_qty'])); ?></td>
                            <td><?php echo e($position['product']['short_name']); ?></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$stock_position): ?>
                        <tr><td colspan="6" class="text-center text-muted py-4">No stock or in-transit quantities recorded.</td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="row">
        <div class="col-lg-7 mb-4">
            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h5 class="card-title mb-0">Recent Transfers</h5>
                    <a href="godown-transfers.php" class="btn btn-sm btn-light">View all</a>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover mb-0">
                            <thead><tr><th>Transfer</th><th>Destination</th><th>Qty</th><th>Status</th><th>Date</th></tr></thead>
                            <tbody>
                            <?php foreach ($recent_transfers as $transfer): ?>
                                <tr>
                                    <td><a href="godown-transfer-view.php?id=<?php echo (int)$transfer['transfer_id']; ?>"><?php echo e($transfer['transfer_number']); ?></a></td>
                                    <td><?php echo e($transfer['destination_shop_name'] . ' / ' . $transfer['destination_store_name']); ?></td>
                                    <td><?php echo e(format_quantity($transfer['total_quantity'])); ?></td>
                                    <td><span class="badge badge-<?php echo e(godown_transfer_status_class($transfer['status'])); ?>"><?php echo e(godown_transfer_status_label($transfer['status'])); ?></span></td>
                                    <td><?php echo e(date('M d, Y', strtotime($transfer['requested_at']))); ?></td>
                                </tr>
                            <?php endforeach; ?>
                            <?php if (!$recent_transfers): ?>
                                <tr><td colspan="5" class="text-center text-muted py-4">No transfers yet.</td></tr>
                            <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-lg-5 mb-4">
            <div class="card">
                <div class="card-header"><h5 class="card-title mb-0">Recent Receipts</h5></div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover mb-0">
                            <thead><tr><th>Receipt</th><th>Product</th><th>Qty</th><th>By / Date</th></tr></thead>
                            <tbody>
                            <?php foreach ($receipts as $receipt): ?>
                                <tr>
                                    <td><span class="badge badge-info"><?php echo e($receipt['receipt_number']); ?></span></td>
                                    <td><?php echo e($receipt['product_name']); ?><br><small class="text-muted"><?php echo e($receipt['source_reference'] ?: 'No source reference'); ?></small></td>
                                    <td><?php echo e(format_quantity($receipt['quantity'])); ?></td>
                                    <td><?php echo e($receipt['received_by_name'] ?: ($receipt['received_by_username'] ?: 'Unknown')); ?><br><small class="text-muted"><?php echo e(date('M d, Y H:i', strtotime($receipt['received_at']))); ?></small></td>
                                </tr>
                            <?php endforeach; ?>
                            <?php if (!$receipts): ?>
                                <tr><td colspan="4" class="text-center text-muted py-4">No receipts yet.</td></tr>
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
        function syncQuantityStep() {
            var $product = $('#receive_product');
            var $qty = $('#receive_quantity');
            if (!$product.length || !$qty.length) return;
            var decimal = String($product.find('option:selected').data('decimal')) === '1';
            $qty.attr('step', decimal ? '0.01' : '1');
            $qty.attr('min', decimal ? '0.01' : '1');
            $('#receive_unit_hint').text(decimal
                ? 'This product allows decimal quantities.'
                : 'Whole units only.');
        }
        $('#receive_product').on('change', syncQuantityStep);
        syncQuantityStep();
    });
</script>

<?php require_once 'footer.php'; ?>
