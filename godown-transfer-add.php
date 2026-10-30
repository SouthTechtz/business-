<?php
require_once 'header.php';
require_once 'auth_check.php';
require_permission_any(['manage_godown', 'request_godown_transfers']);
$can_manage_godown = can('manage_godown');
$can_request = can('request_godown_transfers');
$page_title = $can_manage_godown ? 'New Stock Transfer' : 'Request Stock';

$tenant_id = (int)$current_user->tenant_id;
ensure_central_godown($tenant_id);
$godown = find_tenant_godown();
if (!$godown) {
    flash_set('danger', 'Configure an active default Godown before creating a transfer request.');
    redirect($can_manage_godown ? 'godown.php' : 'godown-transfers.php');
}

$shops = accessible_shops(null, false);
$stores = accessible_stores(null, false);

// Source may be the Central Godown, another shop, or any available store.
$source_options = fetch_all(
    "SELECT w.*, s.name shop_name, s.code shop_code
       FROM warehouses w
       LEFT JOIN shops s ON s.shop_id = w.shop_id AND s.tenant_id = w.tenant_id
      WHERE w.tenant_id = ? AND w.is_active = 1
        AND w.location_type IN ('godown', 'store')
      ORDER BY CASE w.location_type WHEN 'godown' THEN 0 ELSE 1 END, w.name",
    $tenant_id
);
$source_ids = array_map(fn($row) => (int)$row['warehouse_id'], $source_options);
$source_id = (int)($_GET['source'] ?? $_POST['source_warehouse_id'] ?? 0);
if (!in_array($source_id, $source_ids, true)) {
    $source_id = (int)$godown['warehouse_id'];
}
$source = null;
foreach ($source_options as $option) {
    if ((int)$option['warehouse_id'] === $source_id) {
        $source = $option;
        break;
    }
}
if (!$source) {
    $source = $godown;
    $source_id = (int)$godown['warehouse_id'];
}
$source_label = trim(($source['shop_name'] ?? '') . ' / ' . $source['name'], ' /');

$products = fetch_all(
    "SELECT p.product_id, p.code, p.name, COALESCE(u.is_decimal, 0) is_decimal, COALESCE(u.short_name, '') unit_short,
            COALESCE(s.quantity, 0) godown_quantity
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
                            data-decimal="<?php echo (int)$product['is_decimal']; ?>"
                            data-stock="<?php echo e($product['godown_quantity']); ?>"
                            data-unit="<?php echo e($product['unit_short']); ?>">
                        <?php echo e($product['name'] . ' (' . $product['code'] . ') - ' . format_quantity($product['godown_quantity']) . ' available'); ?>
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
        $destination_shop_id = (int)($_POST['destination_shop_id'] ?? 0);
        $destination_warehouse_id = (int)($_POST['destination_warehouse_id'] ?? 0);
        $notes = trim((string)($_POST['notes'] ?? ''));
        $product_ids = $_POST['product_id'] ?? [];
        $quantities = $_POST['quantity'] ?? [];
        $line_notes = $_POST['line_notes'] ?? [];

        if (!is_array($product_ids)) $product_ids = [];
        if (!is_array($quantities)) $quantities = [];
        if (!is_array($line_notes)) $line_notes = [];

        $source_row = fetch_one(
            "SELECT w.*, s.name shop_name FROM warehouses w
              LEFT JOIN shops s ON s.shop_id = w.shop_id AND s.tenant_id = w.tenant_id
             WHERE w.warehouse_id = ? AND w.tenant_id = ? AND w.is_active = 1
               AND w.location_type IN ('godown', 'store')",
            $source_warehouse_id,
            $tenant_id
        );
        if (!$source_row) {
            throw new RuntimeException('Select an active Godown or store as the source location.');
        }
        $source_label = trim(($source_row['shop_name'] ?? '') . ' / ' . $source_row['name'], ' /');
        if ($destination_shop_id <= 0) {
            throw new RuntimeException('Select the destination shop.');
        }
        if (!shop_is_accessible($destination_shop_id, false)) {
            throw new RuntimeException('Select an active shop you have access to.');
        }
        $destination_shop = fetch_one(
            "SELECT * FROM shops WHERE shop_id = ? AND tenant_id = ? AND is_active = 1",
            $destination_shop_id,
            $tenant_id
        );
        if (!$destination_shop) {
            throw new RuntimeException('The selected destination shop is not active.');
        }

        $destination_store = find_accessible_store($destination_warehouse_id, false, false);
        if (!$destination_store) {
            throw new RuntimeException('Select an active shop store you can access. The central Godown cannot be a destination.');
        }
        if ((int)$destination_store['shop_id'] !== $destination_shop_id) {
            throw new RuntimeException('The destination store does not belong to the selected shop.');
        }
        if ((string)$destination_store['location_type'] !== 'store') {
            throw new RuntimeException('The central Godown cannot be a transfer destination.');
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

        $conn->begin_transaction();
        $in_transaction = true;

        $locked_source = fetch_one(
            "SELECT w.* FROM warehouses w
              WHERE w.warehouse_id = ? AND w.tenant_id = ? AND w.location_type IN ('godown', 'store')
                AND w.is_active = 1
              FOR UPDATE",
            $source_warehouse_id,
            $tenant_id
        );
        if (!$locked_source) {
            throw new RuntimeException('The source location is no longer active.');
        }

        $locked_destination = fetch_one(
            "SELECT w.* FROM warehouses w
               JOIN shops s ON s.shop_id = w.shop_id AND s.tenant_id = w.tenant_id
              WHERE w.warehouse_id = ? AND w.tenant_id = ? AND w.shop_id = ?
                AND w.is_active = 1 AND s.is_active = 1 AND w.location_type = 'store'
              FOR UPDATE",
            $destination_warehouse_id,
            $tenant_id,
            $destination_shop_id
        );
        if (!$locked_destination) {
            throw new RuntimeException('The destination store is no longer active.');
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
            . ' to ' . $destination_shop['name'] . ' / ' . $locked_destination['name']
            . ' with ' . count($merged) . ' product line(s).'
        );
        flash_set(
            'success',
            'Request ' . $transfer_number . ' created. The source location team will review it. Approval does not move stock.'
        );
        redirect('godown-transfer-view.php?id=' . $transfer_id);
    } catch (Throwable $ex) {
        if ($in_transaction) $conn->rollback();
        flash_set('danger', $ex->getMessage());
        redirect('godown-transfer-add.php');
    }
}

$prefill_shop_id = (int)($_GET['shop'] ?? 0);
$prefill_store_id = (int)($_GET['store'] ?? 0);
if ($prefill_store_id > 0) {
    $prefill_store = find_accessible_store($prefill_store_id, false, false);
    if (!$prefill_store) {
        $prefill_store_id = 0;
    } elseif ($prefill_shop_id <= 0) {
        $prefill_shop_id = (int)$prefill_store['shop_id'];
    }
}
if ($prefill_shop_id > 0 && !shop_is_accessible($prefill_shop_id, false)) {
    $prefill_shop_id = 0;
    $prefill_store_id = 0;
}

require_once 'navbar.php';
require_once 'sidebar.php';
?>

<div class="page-head">
    <div>
        <h1 class="page-title"><?php echo $can_manage_godown ? 'New Stock Transfer' : 'Request Stock'; ?></h1>
        <div class="page-sub">Request stock from <?php echo e($source_label); ?> for a shop store.</div>
    </div>
    <a href="godown-transfers.php" class="btn btn-light"><i class="fa fa-arrow-left mr-1"></i> Transfers</a>
</div>

<ul class="nav page-tabs">
    <li class="nav-item"><a class="nav-link" href="godown.php"><i class="fa fa-archive mr-1"></i> Godown</a></li>
    <li class="nav-item"><a class="nav-link" href="godown-transfers.php"><i class="fa fa-exchange mr-1"></i> Transfers</a></li>
    <li class="nav-item"><a class="nav-link active" href="godown-transfer-add.php"><i class="fa fa-plus mr-1"></i> <?php echo $can_manage_godown ? 'New Transfer' : 'Request Stock'; ?></a></li>
</ul>

<?php if ($f = flash_get()): ?>
    <div class="alert alert-<?php echo e($f['type']); ?>"><?php echo e($f['message']); ?></div>
<?php endif; ?>

<?php if (!$shops || !$stores): ?>
    <div class="alert alert-warning">No active destination shop/store is assigned to your account. Ask an administrator for shop access first.</div>
<?php endif; ?>

<form method="POST" action="godown-transfer-add.php" id="transferForm">
    <?php echo csrf_field(); ?>
    <?php echo form_token_field(); ?>

    <div class="row">
        <div class="col-lg-5 mb-4">
            <div class="card">
                <div class="card-header"><h5 class="card-title mb-0">Destination</h5></div>
                <div class="card-body">
                    <div class="form-group">
                        <label>Source Location <span class="text-danger">*</span></label>
                        <select name="source_warehouse_id" id="sourceLocation" class="form-control" required onchange="window.location='godown-transfer-add.php?source=' + encodeURIComponent(this.value);">
                            <?php foreach ($source_options as $option): ?>
                                <option value="<?php echo (int)$option['warehouse_id']; ?>" <?php echo $source_id === (int)$option['warehouse_id'] ? 'selected' : ''; ?>>
                                    <?php echo e(trim(($option['shop_name'] ?? '') . ' / ' . $option['name'], ' /')); ?><?php echo (string)$option['location_type'] === 'godown' ? ' (Godown)' : ''; ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <small class="text-muted">Request from the Central Godown, another shop, or any available store.</small>
                    </div>
                    <div class="form-group">
                        <label>Destination Shop <span class="text-danger">*</span></label>
                        <select name="destination_shop_id" id="destination_shop" class="form-control" required>
                            <option value="">— Select Shop —</option>
                            <?php foreach ($shops as $shop): ?>
                                <option value="<?php echo (int)$shop['shop_id']; ?>" <?php echo $prefill_shop_id === (int)$shop['shop_id'] ? 'selected' : ''; ?>><?php echo e($shop['name'] . ' (' . $shop['code'] . ')'); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Destination Store <span class="text-danger">*</span></label>
                        <select name="destination_warehouse_id" id="destination_store" class="form-control" required>
                            <option value="">— Select Destination Store —</option>
                            <?php foreach ($stores as $store): ?>
                                <option value="<?php echo (int)$store['warehouse_id']; ?>" data-shop="<?php echo (int)$store['shop_id']; ?>" <?php echo ($prefill_shop_id === (int)$store['shop_id'] ? '' : 'disabled') . ' ' . ($prefill_store_id === (int)$store['warehouse_id'] ? 'selected' : ''); ?>>
                                    <?php echo e($store['shop_name'] . ' / ' . $store['name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <small class="text-muted">Only ordinary shop stores appear here. POS and orders cannot use the central Godown.</small>
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
                    <small class="text-muted">Quantities are checked again at dispatch. A request or approval never changes physical stock.</small>
                    <button type="submit" class="btn btn-primary" <?php echo ($shops && $stores) ? '' : 'disabled'; ?>><i class="fa fa-paper-plane mr-1"></i> Create Request</button>
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
        function filterStores() {
            var shopId = $('#destination_shop').val();
            var $store = $('#destination_store');
            $store.find('option').each(function () {
                if (!this.value) return;
                var match = String($(this).data('shop')) === String(shopId);
                $(this).prop('disabled', !match).toggle(match);
            });
            if (!shopId) {
                $store.val('');
            } else if (!$store.find('option:selected').length || $store.find('option:selected').prop('disabled')) {
                $store.val('');
            }
        }

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

        $('#destination_shop').on('change', filterStores);
        filterStores();
        bindLine($('#transferLines .transfer-line').first());

        $('#addTransferLine').on('click', function () {
            var $line = $($('#transferLineTemplate').html()).filter('tr');
            $('#transferLines').append($line);
            bindLine($line);
        });

        $('#transferForm').on('submit', function () {
            var hasLine = false;
            $('#transferLines .transfer-line').each(function () {
                if ($(this).find('.transfer-product').val()) hasLine = true;
            });
            if (!hasLine) {
                alert('Add at least one product line.');
                return false;
            }
        });
    });
</script>

<?php require_once 'footer.php'; ?>
