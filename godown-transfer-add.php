<?php
require_once 'header.php';
require_once 'auth_check.php';
require_permission_any(['manage_godown', 'request_godown_transfers']);
$can_manage_godown = can('manage_godown');
$can_request = can('request_godown_transfers');
$request_shop_id = (int)($_GET['request_shop_id'] ?? 0);
$request_store_id = (int)($_GET['request_store_id'] ?? 0);
$is_location_request = $request_shop_id > 0 || $request_store_id > 0;
$request_destination = null;
$request_destination_type = '';

$tenant_id = (int)$current_user->tenant_id;
if ($request_shop_id > 0 && $request_store_id > 0) {
    http_response_code(400);
    die('Choose one request destination.');
}

if ($is_location_request) {
    if (!$can_request && !$can_manage_godown) {
        http_response_code(403);
        die('You do not have permission to request stock.');
    }
    if ($request_shop_id > 0) {
        $request_shop = fetch_one(
            "SELECT shop_id, name FROM shops WHERE shop_id = ? AND tenant_id = ? AND is_active = 1",
            $request_shop_id,
            $tenant_id
        );
        if (!$request_shop || !shop_is_accessible($request_shop_id, false)) {
            http_response_code(403);
            die('You do not have access to this active Shop.');
        }
        $destination_warehouse_id = shop_inventory_warehouse_id($request_shop_id, $tenant_id);
        $request_destination = $destination_warehouse_id
            ? fetch_one(
                "SELECT w.*, s.name shop_name, s.is_active shop_is_active
                   FROM warehouses w JOIN shops s ON s.shop_id = w.shop_id AND s.tenant_id = w.tenant_id
                  WHERE w.warehouse_id = ? AND w.tenant_id = ? AND w.shop_id = ?
                    AND w.location_type = 'shop' AND w.is_active = 1 AND s.is_active = 1",
                $destination_warehouse_id,
                $tenant_id,
                $request_shop_id
            )
            : null;
        $request_destination_type = 'shop';
    } else {
        $request_destination = find_accessible_store($request_store_id, false, false);
        if (!$request_destination || (string)$request_destination['location_type'] !== 'store') {
            http_response_code(403);
            die('You do not have access to this active Store.');
        }
        $request_destination_type = 'store';
    }
    if (!$request_destination) {
        flash_set('danger', 'The request destination has no active inventory warehouse.');
        redirect($request_shop_id > 0 ? 'shop-view.php?id=' . $request_shop_id : 'store-view.php?id=' . $request_store_id);
    }
    $page_title = 'Request Stock';
    $request_context_params = $request_shop_id > 0
        ? ['request_shop_id' => $request_shop_id]
        : ['request_store_id' => $request_store_id];
    $transfer_add_url = 'godown-transfer-add.php?' . http_build_query($request_context_params);
    $godown = null;
} else {
    $page_title = $can_manage_godown ? 'New Stock Transfer' : 'Request Stock';
    if ($can_manage_godown && !is_godown_manager()) {
        ensure_central_godown($tenant_id);
    }
    $godown = is_godown_manager() ? find_active_godown($tenant_id) : find_tenant_godown($tenant_id);
    if (!$godown && ($can_manage_godown || is_godown_manager())) {
        flash_set('danger', 'Configure an active default Godown before creating a transfer request.');
        redirect($can_manage_godown ? 'godown.php' : 'godown-transfers.php');
    }
    $transfer_add_url = 'godown-transfer-add.php';
}

$stores = accessible_locations(false);
if (!$is_location_request
    && !can('manage_godown')
    && !can('view_godown')
    && !can('dispatch_godown_transfers')
    && !can('approve_godown_transfers')
    && !tenant_admin_scope()) {
    // Store/Shop staff dispatch from their own locations only. Godown stock is
    // dispatched by the Godown team, not by requesting locations.
    $stores = array_values(array_filter(
        $stores,
        fn($location) => (string)($location['location_type'] ?? '') !== 'godown'
    ));
}

if ($is_location_request) {
    $source_options = fetch_all(
        "SELECT w.*, s.name shop_name, s.code shop_code, s.is_active shop_is_active
           FROM warehouses w
           LEFT JOIN shops s ON s.shop_id = w.shop_id AND s.tenant_id = w.tenant_id
          WHERE w.tenant_id = ? AND w.is_active = 1
            AND w.location_type IN ('godown', 'store')
            AND (w.location_type = 'godown' OR s.is_active = 1)
          ORDER BY CASE w.location_type WHEN 'godown' THEN 0 ELSE 1 END, s.name, w.name",
        $tenant_id
    );
    $source_options = array_values(array_filter(
        $source_options,
        fn($location) => (int)$location['warehouse_id'] !== (int)$request_destination['warehouse_id']
            && stock_transfer_destination_allowed((string)$location['location_type'], $request_destination_type)
    ));
} else {
    $source_options = array_values(array_filter(
        $stores,
        fn($location) => in_array((string)$location['location_type'], ['godown', 'store', 'shop'], true)
    ));
}
$source_ids = array_map(fn($row) => (int)$row['warehouse_id'], $source_options);
$source_id = (int)($_GET['source'] ?? $_POST['source_warehouse_id'] ?? 0);
if ($is_location_request) {
    if (!in_array($source_id, $source_ids, true)) $source_id = 0;
} elseif (!in_array($source_id, $source_ids, true)) {
    $preferred_source = 0;
    if ($godown
        && (is_godown_manager() || tenant_admin_scope())
        && in_array((int)$godown['warehouse_id'], $source_ids, true)) {
        $preferred_source = (int)$godown['warehouse_id'];
    } else {
        foreach ($source_options as $option) {
            if ((string)($option['location_type'] ?? '') === 'store') {
                $preferred_source = (int)$option['warehouse_id'];
                break;
            }
        }
        if ($preferred_source <= 0) {
            $preferred_source = (int)($source_ids[0] ?? 0);
        }
    }
    $source_id = $preferred_source;
}
$source = null;
foreach ($source_options as $option) {
    if ((int)$option['warehouse_id'] === $source_id) {
        $source = $option;
        break;
    }
}
if (!$source && !$is_location_request) {
    flash_set('danger', 'No active source location is available to your account.');
    redirect('godown-transfers.php');
}
$source_type = (string)($source['location_type'] ?? '');
$source_label = $source
    ? trim(($source['shop_name'] ?? '') . ' / ' . $source['name'], ' /')
    : 'Choose a supplying location';
$accessible_dests = $is_location_request
    ? []
    : stock_transfer_destination_locations($source_type, $tenant_id, $source_id);
$eligible_destinations = [];
foreach ($accessible_dests as $loc) {
    if ((int)$loc['warehouse_id'] === $source_id) continue;
    if (
        (int)($loc['shop_is_active'] ?? 1) === 1
        && stock_transfer_destination_allowed($source_type, (string)$loc['location_type'])
    ) {
        $eligible_destinations[] = $loc;
    }
}
$locations = $eligible_destinations;
$prefill_dest_id = $is_location_request ? (int)$request_destination['warehouse_id'] : 0;
$prefill_shop_id = (int)($_GET['shop'] ?? 0);
$prefill_location_id = (int)($_GET['store'] ?? 0);
foreach ($locations as $location) {
    $location_id = (int)$location['warehouse_id'];
    if ($prefill_location_id > 0 && $location_id === $prefill_location_id) {
        $prefill_dest_id = $location_id;
        break;
    }
    if ($prefill_shop_id > 0 && (int)$location['shop_id'] === $prefill_shop_id && $prefill_dest_id === 0) {
        $prefill_dest_id = $location_id;
    }
}

$products = fetch_all(
    "SELECT p.product_id, p.code, p.name, COALESCE(u.is_decimal, 0) is_decimal, COALESCE(u.short_name, '') unit_short,
            COALESCE(s.quantity, 0) source_quantity
       FROM products p
       LEFT JOIN units_of_measurement u ON u.unit_id = p.unit_id AND u.tenant_id = p.tenant_id
       LEFT JOIN inventory_stock s ON s.product_id = p.product_id AND s.tenant_id = p.tenant_id AND s.warehouse_id = ?
      WHERE p.tenant_id = ? AND p.is_active = 1 AND p.is_service = 0
      ORDER BY p.name",
    $source_id,
    $tenant_id
);

function render_transfer_line(array $products): string
{
    ob_start();
    ?>
    <tr class="transfer-line">
        <td>
            <select name="product_id[]" class="form-control form-control-sm transfer-product" required>
                <option value="">— Select Product —</option>
                <?php foreach ($products as $product): ?>
                    <option value="<?php echo (int)$product['product_id']; ?>"
                            data-search="<?php echo e(strtolower($product['name'] . ' ' . $product['code'])); ?>"
                            data-decimal="<?php echo (int)$product['is_decimal']; ?>"
                            data-stock="<?php echo e($product['source_quantity']); ?>"
                            data-unit="<?php echo e($product['unit_short']); ?>">
                        <?php echo e($product['name'] . ' (' . $product['code'] . ') - ' . format_quantity($product['source_quantity']) . ' available'); ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <small class="text-muted transfer-available">Available: —</small>
        </td>
        <td style="width: 130px;">
            <input type="number" name="quantity[]" class="form-control form-control-sm transfer-quantity" step="1" min="1" required>
        </td>
        <td>
            <input type="text" name="line_notes[]" class="form-control form-control-sm" maxlength="255" placeholder="Optional line note">
        </td>
        <td class="text-right" style="width: 56px;">
            <button type="button" class="btn btn-sm btn-light transfer-remove" title="Remove line"><i class="fa fa-times"></i></button>
        </td>
    </tr>
    <?php
    return (string)ob_get_clean();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    if (!form_token_valid()) {
        flash_set('danger', 'This transfer was already submitted. Check the transfer list before trying again.');
        redirect('godown-transfers.php');
    }

    $in_transaction = false;
    try {
        $source_warehouse_id = (int)($_POST['source_warehouse_id'] ?? 0);
        $destination_shop_id = 0;
        $destination_warehouse_id = 0;
        $notes = trim((string)($_POST['notes'] ?? ''));
        $product_ids = $_POST['product_id'] ?? [];
        $quantities = $_POST['quantity'] ?? [];
        $line_notes = $_POST['line_notes'] ?? [];

        if (!is_array($product_ids)) $product_ids = [];
        if (!is_array($quantities)) $quantities = [];
        if (!is_array($line_notes)) $line_notes = [];

        if ($is_location_request) {
            $source_row = $source_warehouse_id === $source_id
                ? fetch_one(
                    "SELECT w.*, s.name shop_name, s.is_active shop_is_active
                       FROM warehouses w
                       LEFT JOIN shops s ON s.shop_id = w.shop_id AND s.tenant_id = w.tenant_id
                      WHERE w.warehouse_id = ? AND w.tenant_id = ? AND w.is_active = 1
                        AND w.location_type IN ('godown', 'store')
                        AND (w.location_type = 'godown' OR s.is_active = 1)",
                    $source_warehouse_id,
                    $tenant_id
                )
                : null;
        } else {
            if ($source_warehouse_id !== $source_id) {
                $source_row = null;
            } elseif ($can_request && !$can_manage_godown) {
                $source_row = fetch_one(
                    "SELECT w.*, s.name shop_name, s.is_active shop_is_active
                       FROM warehouses w
                       LEFT JOIN shops s ON s.shop_id = w.shop_id AND s.tenant_id = w.tenant_id
                      WHERE w.warehouse_id = ? AND w.tenant_id = ? AND w.is_active = 1
                        AND w.location_type IN ('godown', 'store', 'shop')
                        AND (w.location_type = 'godown' OR s.is_active = 1)",
                    $source_warehouse_id,
                    $tenant_id
                );
            } else {
                $source_row = find_accessible_store($source_warehouse_id, false, true);
            }
        }
        if (
            !$source_row
            || !in_array($source_warehouse_id, $source_ids, true)
            || !in_array((string)$source_row['location_type'], ['godown', 'store', 'shop'], true)
        ) {
            throw new RuntimeException('Select an active eligible supplying location.');
        }
        $source_label = trim(($source_row['shop_name'] ?? '') . ' / ' . $source_row['name'], ' /');
        if ($is_location_request) {
            $destination_warehouse = $request_destination;
            $destination_warehouse_id = (int)$request_destination['warehouse_id'];
            $destination_shop_id = (int)$request_destination['shop_id'];
            if (!stock_transfer_destination_allowed((string)$source_row['location_type'], $request_destination_type)) {
                throw new RuntimeException('This supplying location is not allowed for the selected request destination.');
            }
        } else {
            $destination_warehouse_id = (int)($_POST['destination_warehouse_id'] ?? 0);
            $destination_warehouse = fetch_one(
                "SELECT w.*, s.name shop_name, s.is_active shop_is_active
                   FROM warehouses w
                   JOIN shops s ON s.shop_id = w.shop_id AND s.tenant_id = w.tenant_id
                  WHERE w.warehouse_id = ? AND w.tenant_id = ? AND w.is_active = 1
                    AND s.is_active = 1 AND w.location_type IN ('store', 'shop')",
                $destination_warehouse_id,
                $tenant_id
            );
            if (!$destination_warehouse) {
                throw new RuntimeException('Select an active Shop or Store destination in this tenant.');
            }
            $accessible_dest_ids = array_map(fn($r) => (int)$r['warehouse_id'], $eligible_destinations);
            if (!in_array($destination_warehouse_id, $accessible_dest_ids, true)) {
                throw new RuntimeException('The destination is not allowed for this source location.');
            }
            $destination_shop_id = (int)($destination_warehouse['shop_id'] ?? 0);
            if (!stock_transfer_destination_allowed((string)$source_row['location_type'], (string)$destination_warehouse['location_type'])) {
                throw new RuntimeException('The destination type is not allowed for this source location.');
            }
        }
        if ($destination_warehouse_id === $source_warehouse_id) {
            throw new RuntimeException('The source and destination cannot be the same location.');
        }
        if (mb_strlen($notes) > 2000) {
            throw new RuntimeException('Notes are too long (maximum 2,000 characters).');
        }

        $merged = [];
        foreach ($product_ids as $index => $product_id) {
            $product_id = (int)$product_id;
            $raw_qty = $quantities[$index] ?? '';
            $line_note = trim((string)($line_notes[$index] ?? ''));
            if ($product_id <= 0 && (string)$raw_qty === '') continue;
            if ($product_id <= 0) {
                throw new RuntimeException('Choose a product for every line with a quantity.');
            }
            if (!is_numeric($raw_qty)) {
                throw new RuntimeException('Every quantity must be a number greater than zero.');
            }
            $quantity = round((float)$raw_qty, 2);
            if ($quantity <= 0 || $quantity > 999999) {
                throw new RuntimeException('Quantities must be between 0.01 and 999,999.');
            }
            if (mb_strlen($line_note) > 255) {
                throw new RuntimeException('Line notes are too long (maximum 255 characters).');
            }

            $product = fetch_one(
                "SELECT p.*, COALESCE(u.is_decimal, 0) is_decimal
                   FROM products p
                   LEFT JOIN units_of_measurement u ON u.unit_id = p.unit_id AND u.tenant_id = p.tenant_id
                  WHERE p.product_id = ? AND p.tenant_id = ? AND p.is_active = 1",
                $product_id,
                $tenant_id
            );
            if (!$product || (int)$product['is_service'] === 1) {
                throw new RuntimeException('One of the selected products is not available for stock transfer.');
            }
            if (!(int)$product['is_decimal'] && abs($quantity - round($quantity)) > 0.0001) {
                throw new RuntimeException($product['name'] . ' uses whole units only.');
            }

            if (isset($merged[$product_id])) {
                $merged[$product_id]['quantity'] = round($merged[$product_id]['quantity'] + $quantity, 2);
                if ($line_note !== '' && $merged[$product_id]['line_notes'] === '') {
                    $merged[$product_id]['line_notes'] = $line_note;
                }
            } else {
                $merged[$product_id] = [
                    'product' => $product,
                    'quantity' => $quantity,
                    'line_notes' => $line_note,
                ];
            }
        }
        if (!$merged) {
            throw new RuntimeException('Add at least one product line with a quantity.');
        }

        if (!$is_location_request) {
            foreach ($merged as $product_id => $line) {
                $stock = fetch_one(
                    "SELECT quantity FROM inventory_stock
                      WHERE tenant_id = ? AND warehouse_id = ? AND product_id = ?",
                    $tenant_id,
                    $source_warehouse_id,
                    (int)$product_id
                );
                $available = (float)($stock['quantity'] ?? 0);
                if ($line['quantity'] > $available + 0.0001) {
                    throw new RuntimeException(
                        'Only ' . format_quantity($available) . ' of ' . $line['product']['name']
                        . ' is available at ' . $source_label . '. Reduce the requested quantity.'
                    );
                }
            }
        }

        $conn->begin_transaction();
        $in_transaction = true;

        $locked_source = fetch_one(
            "SELECT w.*, s.is_active shop_is_active FROM warehouses w
              LEFT JOIN shops s ON s.shop_id = w.shop_id AND s.tenant_id = w.tenant_id
              WHERE w.warehouse_id = ? AND w.tenant_id = ? AND w.location_type IN ('godown', 'store', 'shop')
                AND w.is_active = 1 AND (w.location_type = 'godown' OR s.is_active = 1)
              FOR UPDATE",
            $source_warehouse_id,
            $tenant_id
        );
        if (
            !$locked_source
            || !in_array((string)$locked_source['location_type'], ['godown', 'store', 'shop'], true)
        ) {
            throw new RuntimeException('The source location is no longer active.');
        }
        if ($is_location_request && !stock_transfer_destination_allowed((string)$locked_source['location_type'], $request_destination_type)) {
            throw new RuntimeException('The supplying location is no longer eligible for this request.');
        }
        if (
            $request_shop_id > 0
            && (int)$locked_source['shop_id'] === $request_shop_id
        ) {
            throw new RuntimeException('A Shop cannot request stock from its own locations.');
        }

        $locked_destination = fetch_one(
            "SELECT w.*, s.name shop_name, s.is_active shop_is_active
                FROM warehouses w
                JOIN shops s ON s.shop_id = w.shop_id AND s.tenant_id = w.tenant_id
               WHERE w.warehouse_id = ? AND w.tenant_id = ? AND w.is_active = 1 AND s.is_active = 1
                 AND w.location_type IN ('store', 'shop')
               FOR UPDATE",
            $destination_warehouse_id,
            $tenant_id
        );
        if (
            !$locked_destination
            || (int)$locked_destination['shop_id'] !== $destination_shop_id
            || !stock_transfer_destination_allowed(
                (string)$locked_source['location_type'],
                (string)$locked_destination['location_type']
            )
        ) {
            throw new RuntimeException('The destination is no longer active or is not allowed for this source.');
        }

        $transfer_number = next_document_number($tenant_id, 'stock_transfer', 'TRF');
        $notes_db = $notes !== '' ? $notes : null;

        $stmt = $conn->prepare(
            "INSERT INTO stock_transfers
                (tenant_id, transfer_number, source_warehouse_id, destination_shop_id,
                 destination_warehouse_id, status, notes, requested_by)
             VALUES (?, ?, ?, ?, ?, 'draft', ?, ?)"
        );
        $stmt->bind_param(
            'isiiisi',
            $tenant_id,
            $transfer_number,
            $locked_source['warehouse_id'],
            $destination_shop_id,
            $locked_destination['warehouse_id'],
            $notes_db,
            $current_user->user_id
        );
        $stmt->execute();
        $transfer_id = (int)$stmt->insert_id;
        $stmt->close();

        $line_no = 1;
        $stmt = $conn->prepare(
            "INSERT INTO stock_transfer_items
                (tenant_id, transfer_id, line_no, product_id, requested_quantity, line_notes)
             VALUES (?, ?, ?, ?, ?, ?)"
        );
        foreach ($merged as $product_id => $line) {
            $line_note = $line['line_notes'] !== '' ? $line['line_notes'] : null;
            $stmt->bind_param(
                'iiiids',
                $tenant_id,
                $transfer_id,
                $line_no,
                $product_id,
                $line['quantity'],
                $line_note
            );
            $stmt->execute();
            $line_no++;
        }
        $stmt->close();

        $conn->commit();
        $in_transaction = false;
        consume_form_token();
        audit_log(
            'create',
            'stock_transfer',
            $transfer_id,
            'Transfer ' . $transfer_number . ' requested from ' . $source_label
            . ' to ' . trim($locked_destination['shop_name'] . ' / ' . $locked_destination['name'], ' /')
            . ' with ' . count($merged) . ' product line(s).'
        );
        flash_set('success', $is_location_request
            ? 'Stock request ' . $transfer_number . ' submitted for Inventory review. No stock was moved.'
            : 'Request ' . $transfer_number . ' created. Approval does not move stock.');
        redirect('godown-transfer-view.php?id=' . $transfer_id);
    } catch (Throwable $ex) {
        if ($in_transaction) $conn->rollback();
        flash_set('danger', $ex->getMessage());
        redirect($transfer_add_url);
    }
}

require_once 'navbar.php';
require_once 'sidebar.php';
?>

<div class="page-head">
    <div>
        <h1 class="page-title"><?php echo $is_location_request ? 'Request Stock' : ($can_manage_godown ? 'New Stock Transfer' : 'Send Stock'); ?></h1>
        <div class="page-sub">
            <?php if ($is_location_request): ?>
                Request stock for <?php echo e(trim(($request_destination['shop_name'] ?? '') . ' / ' . $request_destination['name'], ' /')); ?>. Inventory will not change until dispatch.
            <?php else: ?>
                Create a transfer from <?php echo e($source_label); ?> to an eligible destination.
            <?php endif; ?>
        </div>
    </div>
    <a href="godown-transfers.php" class="btn btn-light"><i class="fa fa-arrow-left mr-1"></i> Transfers</a>
</div>

<ul class="nav page-tabs">
    <?php if ($can_manage_godown || can('view_godown')): ?>
    <li class="nav-item"><a class="nav-link" href="godown.php"><i class="fa fa-archive mr-1"></i> Godown</a></li>
    <?php endif; ?>
    <li class="nav-item"><a class="nav-link" href="godown-transfers.php"><i class="fa fa-exchange mr-1"></i> Transfers</a></li>
    <li class="nav-item"><a class="nav-link active" href="<?php echo e($transfer_add_url); ?>"><i class="fa fa-plus mr-1"></i> <?php echo $is_location_request ? 'Request Stock' : ($can_manage_godown ? 'New Transfer' : 'Send Stock'); ?></a></li>
</ul>

<?php if ($f = flash_get()): ?>
    <div class="alert alert-<?php echo e($f['type']); ?>"><?php echo e($f['message']); ?></div>
<?php endif; ?>

<?php if ($is_location_request && !$source_options): ?>
    <div class="alert alert-warning">No active eligible supplying location is available for this destination.</div>
<?php elseif (!$is_location_request && !$eligible_destinations): ?>
    <div class="alert alert-warning">No active destination compatible with this source is available in this tenant.</div>
<?php endif; ?>

<form method="POST" action="<?php echo e($transfer_add_url); ?>" id="transferForm">
    <?php echo csrf_field(); ?>
    <?php echo form_token_field(); ?>

    <div class="row">
        <div class="col-lg-5 mb-4">
            <div class="card">
                <div class="card-header"><h5 class="card-title mb-0">Destination</h5></div>
                <div class="card-body">
                    <div class="form-group">
                        <label><?php echo $is_location_request ? 'Supplying Location' : 'Source Location'; ?> <span class="text-danger">*</span></label>
                        <select name="source_warehouse_id" id="sourceLocation" class="form-control" required data-source-base="<?php echo e($transfer_add_url); ?>">
                            <option value="">— Select <?php echo $is_location_request ? 'Supplying' : 'Source'; ?> Location —</option>
                            <?php foreach ($source_options as $option): ?>
                                <option value="<?php echo (int)$option['warehouse_id']; ?>" <?php echo $source_id === (int)$option['warehouse_id'] ? 'selected' : ''; ?>>
                                    <?php echo e(trim(($option['shop_name'] ?? '') . ' / ' . $option['name'], ' /')); ?> (<?php echo e(ucfirst((string)$option['location_type'])); ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <small class="text-muted"><?php echo $is_location_request ? 'Choose the active supplying location for this request.' : 'Changing the source refreshes allowed destinations and available quantities.'; ?></small>
                    </div>
                    <div class="form-group">
                        <?php if ($is_location_request): ?>
                            <label>Request Destination</label>
                            <div class="form-control-plaintext font-weight-semibold">
                                <?php echo e(trim(($request_destination['shop_name'] ?? '') . ' / ' . $request_destination['name'], ' /')); ?>
                            </div>
                            <small class="text-muted">This is fixed to the Shop or Store that opened this request.</small>
                        <?php else: ?>
                            <label>Destination Location <span class="text-danger">*</span></label>
                            <select name="destination_warehouse_id" id="destination_location" class="form-control" required>
                                <option value="">— Select Destination —</option>
                                <?php foreach ($locations as $loc): ?>
                                    <option value="<?php echo (int)$loc['warehouse_id']; ?>" <?php echo $prefill_dest_id === (int)$loc['warehouse_id'] ? 'selected' : ''; ?>>
                                        <?php echo e(trim(($loc['shop_name'] ?? '') . ' / ' . $loc['name'], ' /') . ' (' . $loc['location_type'] . ')'); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <small class="text-muted">Destination types depend on the selected source location.</small>
                        <?php endif; ?>
                    </div>
                    <div class="form-group mb-0">
                        <label>Notes</label>
                        <textarea name="notes" class="form-control" rows="3" maxlength="2000" placeholder="Optional request or dispatch instructions"></textarea>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-lg-7 mb-4">
            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h5 class="card-title mb-0">Products</h5>
                    <button type="button" id="addTransferLine" class="btn btn-sm btn-light"><i class="fa fa-plus mr-1"></i> Add product</button>
                </div>
                <div class="card-body border-bottom">
                    <label for="productSearch" class="sr-only">Search products</label>
                    <input type="search" id="productSearch" class="form-control form-control-sm" placeholder="Search products by name or code">
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table mb-0">
                            <thead><tr><th>Product</th><th>Quantity</th><th>Line Note</th><th></th></tr></thead>
                            <tbody id="transferLines">
                                <?php echo render_transfer_line($products); ?>
                            </tbody>
                        </table>
                    </div>
                </div>
                <div class="card-footer d-flex justify-content-between align-items-center">
                    <small class="text-muted"><?php echo $is_location_request ? 'No stock is reserved or deducted when you submit. Inventory checks availability at dispatch.' : 'Quantities are checked again at dispatch. A request or approval never changes physical stock.'; ?></small>
                    <button type="submit" class="btn btn-primary" <?php echo ($is_location_request ? (bool)$source_options : (bool)$eligible_destinations) ? '' : 'disabled'; ?>><i class="fa fa-paper-plane mr-1"></i> <?php echo $is_location_request ? 'Submit Request' : 'Create Transfer'; ?></button>
                </div>
            </div>
        </div>
    </div>
</form>

<template id="transferLineTemplate">
    <?php echo render_transfer_line($products); ?>
</template>

<script>
    $(function () {
        $('#sourceLocation').on('change', function () {
            var base = $(this).data('source-base');
            var separator = base.indexOf('?') === -1 ? '?' : '&';
            window.location = base + separator + 'source=' + encodeURIComponent(this.value);
        });

        function filterProductOptions() {
            var term = $('#productSearch').val().trim().toLowerCase();
            $('.transfer-product').each(function () {
                $(this).find('option').each(function () {
                    if (!this.value) return;
                    this.hidden = term !== '' && String($(this).data('search') || '').indexOf(term) === -1;
                });
            });
        }

        $('#productSearch').on('input', filterProductOptions);

        function syncLine($line) {
            var $product = $line.find('.transfer-product');
            var $qty = $line.find('.transfer-quantity');
            var selected = $product.find('option:selected');
            var decimal = String(selected.data('decimal')) === '1';
            var stock = selected.data('stock');
            $qty.attr('step', decimal ? '0.01' : '1');
            $qty.attr('min', decimal ? '0.01' : '1');
            if ($product.val()) {
                $line.find('.transfer-available').text('Available: ' + (stock === undefined ? '0' : stock) + ' ' + (selected.data('unit') || ''));
            } else {
                $line.find('.transfer-available').text('Available: —');
            }
        }

        function bindLine($line) {
            $line.find('.transfer-product').on('change', function () { syncLine($line); });
            $line.find('.transfer-remove').on('click', function () {
                if ($('#transferLines .transfer-line').length <= 1) {
                    $line.find('.transfer-product').val('');
                    $line.find('.transfer-quantity').val('');
                    $line.find('input[type="text"]').val('');
                    syncLine($line);
                    return;
                }
                $line.remove();
            });
            syncLine($line);
        }

        bindLine($('#transferLines .transfer-line').first());

        $('#addTransferLine').on('click', function () {
            var $line = $($('#transferLineTemplate').html()).filter('tr');
            $('#transferLines').append($line);
            bindLine($line);
            filterProductOptions();
        });

        $('#transferForm').on('submit', function () {
            var hasLine = false;
            $('#transferLines .transfer-line').each(function () {
                if ($(this).find('.transfer-product').val()) hasLine = true;
            });
            if (!$('#sourceLocation').val()) {
                showAppAlert('Select a supplying location.', 'Request Stock');
                return false;
            }
            if (!hasLine) {
                showAppAlert('Add at least one product line.', 'Request Stock');
                return false;
            }
        });
    });
</script>

<?php require_once 'footer.php'; ?>
