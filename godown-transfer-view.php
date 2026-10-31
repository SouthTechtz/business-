<?php
require_once 'header.php';
require_once 'auth_check.php';
require_permission_any(['manage_godown', 'approve_godown_transfers', 'dispatch_godown_transfers', 'receive_godown_transfers', 'request_godown_transfers']);
$page_title = 'Stock Transfer';

$tenant_id = (int)$current_user->tenant_id;
$transfer_id = (int)($_GET['id'] ?? $_POST['transfer_id'] ?? 0);
if ($transfer_id <= 0) {
    flash_set('danger', 'Transfer not found.');
    redirect('godown-transfers.php');
}

function transfer_load(int $transfer_id, int $tenant_id): ?array
{
    return fetch_one(
        "SELECT t.*,
                s.name destination_shop_name, s.code destination_shop_code,
                w.name destination_store_name, w.code destination_store_code,
                sw.name source_warehouse_name, sw.code source_warehouse_code,
                sw.location_type source_location_type, sw.is_active source_is_active,
                ssw.name source_shop_name,
                TRIM(CONCAT(COALESCE(ur.first_name, ''), ' ', COALESCE(ur.last_name, ''))) requested_by_name,
                ur.username requested_by_username,
                TRIM(CONCAT(COALESCE(ua.first_name, ''), ' ', COALESCE(ua.last_name, ''))) approved_by_name,
                ua.username approved_by_username,
                TRIM(CONCAT(COALESCE(ud.first_name, ''), ' ', COALESCE(ud.last_name, ''))) dispatched_by_name,
                ud.username dispatched_by_username,
                TRIM(CONCAT(COALESCE(urec.first_name, ''), ' ', COALESCE(urec.last_name, ''))) received_by_name,
                urec.username received_by_username,
                TRIM(CONCAT(COALESCE(ures.first_name, ''), ' ', COALESCE(ures.last_name, ''))) discrepancy_resolved_by_name,
                ures.username discrepancy_resolved_by_username,
                TRIM(CONCAT(COALESCE(ucan.first_name, ''), ' ', COALESCE(ucan.last_name, ''))) cancelled_by_name,
                ucan.username cancelled_by_username
           FROM stock_transfers t
           JOIN shops s ON s.shop_id = t.destination_shop_id AND s.tenant_id = t.tenant_id
           JOIN warehouses w ON w.warehouse_id = t.destination_warehouse_id AND w.tenant_id = t.tenant_id
           JOIN warehouses sw ON sw.warehouse_id = t.source_warehouse_id AND sw.tenant_id = t.tenant_id
           LEFT JOIN shops ssw ON ssw.shop_id = sw.shop_id AND ssw.tenant_id = sw.tenant_id
           LEFT JOIN users ur ON ur.user_id = t.requested_by
           LEFT JOIN users ua ON ua.user_id = t.approved_by
           LEFT JOIN users ud ON ud.user_id = t.dispatched_by
           LEFT JOIN users urec ON urec.user_id = t.received_by
           LEFT JOIN users ures ON ures.user_id = t.discrepancy_resolved_by
           LEFT JOIN users ucan ON ucan.user_id = t.cancelled_by
          WHERE t.transfer_id = ? AND t.tenant_id = ?",
        $transfer_id,
        $tenant_id
    );
}

function transfer_items(int $transfer_id, int $tenant_id, bool $lock = false, bool $product_order = false): array
{
    $sql = "SELECT ti.*, p.name product_name, p.code product_code, p.min_stock_level,
                   COALESCE(u.is_decimal, 0) is_decimal, COALESCE(u.short_name, '') unit_short
              FROM stock_transfer_items ti
              JOIN products p ON p.product_id = ti.product_id AND p.tenant_id = ti.tenant_id
              LEFT JOIN units_of_measurement u ON u.unit_id = p.unit_id AND u.tenant_id = p.tenant_id
             WHERE ti.transfer_id = ? AND ti.tenant_id = ?";
    $sql .= $product_order ? ' ORDER BY ti.product_id' : ' ORDER BY ti.line_no, ti.item_id';
    if ($lock) $sql .= ' FOR UPDATE';
    return fetch_all($sql, $transfer_id, $tenant_id);
}

function transfer_source_access(array $transfer): ?array
{
    return find_accessible_store((int)$transfer['source_warehouse_id'], true, true);
}

function transfer_destination_access(array $transfer): ?array
{
    return find_accessible_store((int)$transfer['destination_warehouse_id'], true, false);
}

$transfer = transfer_load($transfer_id, $tenant_id);
if (!$transfer) {
    http_response_code(404);
    die('Transfer not found.');
}
if (!stock_transfer_is_visible($transfer)) {
    http_response_code(403);
    die('You do not have access to this transfer.');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    if (!form_token_valid()) {
        flash_set('danger', 'This action was already submitted. Check the transfer status before trying again.');
        redirect('godown-transfer-view.php?id=' . $transfer_id);
    }

    $action = (string)($_POST['action'] ?? '');
    $in_transaction = false;

    try {
        $conn->begin_transaction();
        $in_transaction = true;

        $locked = fetch_one(
            "SELECT * FROM stock_transfers WHERE transfer_id = ? AND tenant_id = ? FOR UPDATE",
            $transfer_id,
            $tenant_id
        );
        if (!$locked || !stock_transfer_is_visible($locked)) {
            throw new RuntimeException('Transfer not found or access changed.');
        }

        if ($action === 'approve') {
            if (!can('approve_godown_transfers')) {
                throw new RuntimeException('You do not have permission to approve transfers.');
            }
            $source = transfer_source_access($locked);
            if (!$source) {
                throw new RuntimeException('You do not have access to the source location.');
            }
            if ($locked['status'] !== 'draft') {
                throw new RuntimeException('Only a requested transfer can be approved.');
            }

            $source_lock = fetch_one(
                "SELECT w.* FROM warehouses w
                  WHERE w.warehouse_id = ? AND w.tenant_id = ? AND w.location_type IN ('godown', 'store')
                    AND w.is_active = 1
                  FOR UPDATE",
                (int)$locked['source_warehouse_id'],
                $tenant_id
            );
            if (!$source_lock) {
                throw new RuntimeException('The source is no longer an active Godown or store.');
            }
            $destination_lock = fetch_one(
                "SELECT w.* FROM warehouses w
                   JOIN shops s ON s.shop_id = w.shop_id AND s.tenant_id = w.tenant_id
                  WHERE w.warehouse_id = ? AND w.tenant_id = ? AND w.shop_id = ?
                    AND w.is_active = 1 AND s.is_active = 1 AND w.location_type = 'store'
                  FOR UPDATE",
                (int)$locked['destination_warehouse_id'],
                $tenant_id,
                (int)$locked['destination_shop_id']
            );
            if (!$destination_lock) {
                throw new RuntimeException('The destination store is no longer active.');
            }
            $item_count = fetch_one(
                "SELECT COUNT(*) c FROM stock_transfer_items WHERE transfer_id = ? AND tenant_id = ?",
                $transfer_id,
                $tenant_id
            );
            if ((int)($item_count['c'] ?? 0) <= 0) {
                throw new RuntimeException('This transfer has no product lines.');
            }

            $stmt = $conn->prepare(
                "UPDATE stock_transfers
                    SET status = 'approved', approved_by = ?, approved_at = CURRENT_TIMESTAMP
                  WHERE transfer_id = ? AND tenant_id = ? AND status = 'draft'"
            );
            $stmt->bind_param('iii', $current_user->user_id, $transfer_id, $tenant_id);
            $stmt->execute();
            if ($stmt->affected_rows !== 1) {
                $stmt->close();
                throw new RuntimeException('The transfer status changed before approval.');
            }
            $stmt->close();

            $conn->commit();
            $in_transaction = false;
            consume_form_token();
            audit_log('approve', 'stock_transfer', $transfer_id, 'Transfer ' . $locked['transfer_number'] . ' approved. No stock changed.');
            flash_set('success', 'Transfer approved. Physical stock is unchanged until dispatch.');
            redirect('godown-transfer-view.php?id=' . $transfer_id);
        }

        if ($action === 'dispatch') {
            if (!can('dispatch_godown_transfers')) {
                throw new RuntimeException('You do not have permission to dispatch transfers.');
            }
            $source = transfer_source_access($locked);
            if (!$source) {
                throw new RuntimeException('You do not have access to the source location.');
            }
            if ($locked['status'] !== 'approved') {
                throw new RuntimeException('Only an approved transfer can be dispatched.');
            }

            $source_lock = fetch_one(
                "SELECT w.* FROM warehouses w
                  WHERE w.warehouse_id = ? AND w.tenant_id = ? AND w.location_type IN ('godown', 'store')
                    AND w.is_active = 1
                  FOR UPDATE",
                (int)$locked['source_warehouse_id'],
                $tenant_id
            );
            if (!$source_lock) {
                throw new RuntimeException('The source is no longer an active Godown or store.');
            }
            $destination_lock = fetch_one(
                "SELECT w.* FROM warehouses w
                   JOIN shops s ON s.shop_id = w.shop_id AND s.tenant_id = w.tenant_id
                  WHERE w.warehouse_id = ? AND w.tenant_id = ? AND w.shop_id = ?
                    AND w.is_active = 1 AND s.is_active = 1 AND w.location_type = 'store'
                  FOR UPDATE",
                (int)$locked['destination_warehouse_id'],
                $tenant_id,
                (int)$locked['destination_shop_id']
            );
            if (!$destination_lock) {
                throw new RuntimeException('The destination store is no longer active.');
            }

            $items = transfer_items($transfer_id, $tenant_id, true, true);
            if (!$items) {
                throw new RuntimeException('This transfer has no product lines.');
            }

            foreach ($items as $item) {
                $stock = fetch_one(
                    "SELECT stock_id, quantity FROM inventory_stock
                      WHERE tenant_id = ? AND warehouse_id = ? AND product_id = ?
                      FOR UPDATE",
                    $tenant_id,
                    (int)$locked['source_warehouse_id'],
                    (int)$item['product_id']
                );
                $on_hand = (float)($stock['quantity'] ?? 0);
                $reserved = reserved_stock_quantity($tenant_id, (int)$item['product_id'], (int)$locked['source_warehouse_id']);
                $available = $on_hand - $reserved;
                $needed = (float)$item['requested_quantity'];
                if (!$stock || $available + 0.0001 < $needed) {
                    throw new RuntimeException(
                        'Insufficient stock at the source for ' . $item['product_name'] . ': '
                        . format_quantity(max(0, $available)) . ' available, '
                        . format_quantity($needed) . ' required. Dispatch cancelled; no stock was moved.'
                    );
                }

                $stmt = $conn->prepare(
                    "UPDATE inventory_stock SET quantity = quantity - ?
                      WHERE stock_id = ? AND tenant_id = ? AND quantity >= ?"
                );
                $stmt->bind_param('diid', $needed, $stock['stock_id'], $tenant_id, $needed);
                $stmt->execute();
                if ($stmt->affected_rows !== 1) {
                    $stmt->close();
                    throw new RuntimeException('Source stock changed during dispatch. No stock was moved.');
                }
                $stmt->close();

                $stmt = $conn->prepare(
                    "UPDATE stock_transfer_items SET dispatched_quantity = requested_quantity
                      WHERE item_id = ? AND tenant_id = ? AND transfer_id = ?"
                );
                $stmt->bind_param('iii', $item['item_id'], $tenant_id, $transfer_id);
                $stmt->execute();
                $stmt->close();

                $movement_notes = 'Transfer ' . $locked['transfer_number'] . ' dispatched to '
                    . $transfer['destination_shop_name'] . ' / ' . $transfer['destination_store_name'];
                $stmt = $conn->prepare(
                    "INSERT INTO inventory_movements
                        (tenant_id, product_id, warehouse_id, movement_type, quantity, reference_id, reference_type, created_by, notes)
                     VALUES (?, ?, ?, 'godown_transfer_out', ?, ?, 'stock_transfer', ?, ?)"
                );
                $stmt->bind_param(
                    'iiidiis',
                    $tenant_id,
                    $item['product_id'],
                    $locked['source_warehouse_id'],
                    $needed,
                    $transfer_id,
                    $current_user->user_id,
                    $movement_notes
                );
                $stmt->execute();
                $stmt->close();
            }

            $stmt = $conn->prepare(
                "UPDATE stock_transfers
                    SET status = 'dispatched', dispatched_by = ?, dispatched_at = CURRENT_TIMESTAMP
                  WHERE transfer_id = ? AND tenant_id = ? AND status = 'approved'"
            );
            $stmt->bind_param('iii', $current_user->user_id, $transfer_id, $tenant_id);
            $stmt->execute();
            if ($stmt->affected_rows !== 1) {
                $stmt->close();
                throw new RuntimeException('The transfer status changed before dispatch.');
            }
            $stmt->close();

            $conn->commit();
            $in_transaction = false;
            consume_form_token();
            audit_log('dispatch', 'stock_transfer', $transfer_id, 'Transfer ' . $locked['transfer_number'] . ' dispatched; source stock deducted once and now in transit.');
            flash_set('success', 'Transfer dispatched. Source stock was deducted and the quantities are now in transit.');
            redirect('godown-transfer-view.php?id=' . $transfer_id);
        }

        if ($action === 'receive') {
            if (!can('receive_godown_transfers')) {
                throw new RuntimeException('You do not have permission to receive transfers.');
            }
            $destination = transfer_destination_access($locked);
            if (!$destination) {
                throw new RuntimeException('You do not have access to the destination store.');
            }
            if ($locked['status'] !== 'dispatched') {
                throw new RuntimeException('Only a dispatched transfer can be received.');
            }

            $destination_lock = fetch_one(
                "SELECT w.* FROM warehouses w
                   JOIN shops s ON s.shop_id = w.shop_id AND s.tenant_id = w.tenant_id
                  WHERE w.warehouse_id = ? AND w.tenant_id = ? AND w.shop_id = ? AND w.location_type = 'store'
                  FOR UPDATE",
                (int)$locked['destination_warehouse_id'],
                $tenant_id,
                (int)$locked['destination_shop_id']
            );
            if (!$destination_lock) {
                throw new RuntimeException('The destination store is invalid.');
            }

            $items = transfer_items($transfer_id, $tenant_id, true, true);
            if (!$items) {
                throw new RuntimeException('This transfer has no product lines.');
            }

            $received_input = $_POST['received_quantity'] ?? [];
            $damaged_input = $_POST['damaged_quantity'] ?? [];
            $excess_input = $_POST['excess_quantity'] ?? [];
            $line_note_input = $_POST['line_notes'] ?? [];
            if (!is_array($received_input)) $received_input = [];
            if (!is_array($damaged_input)) $damaged_input = [];
            if (!is_array($excess_input)) $excess_input = [];
            if (!is_array($line_note_input)) $line_note_input = [];

            $has_discrepancy = false;
            $total_accepted = 0.0;
            $total_damaged = 0.0;
            $total_shortage = 0.0;
            $total_excess = 0.0;

            foreach ($items as $item) {
                $item_id = (int)$item['item_id'];
                $dispatched = round((float)$item['dispatched_quantity'], 2);
                $raw_received = $received_input[$item_id] ?? 0;
                $raw_damaged = $damaged_input[$item_id] ?? 0;
                $raw_excess = $excess_input[$item_id] ?? 0;
                $raw_line_note = $line_note_input[$item_id] ?? '';
                foreach ([
                    'accepted' => $raw_received,
                    'damaged' => $raw_damaged,
                    'excess' => $raw_excess,
                ] as $label => $raw_value) {
                    if (!is_scalar($raw_value) || !is_numeric((string)$raw_value)) {
                        throw new RuntimeException('Receipt ' . $label . ' quantity must be a number.');
                    }
                }
                if (!is_scalar($raw_line_note)) {
                    throw new RuntimeException('Line notes must be text.');
                }
                $received = round((float)$raw_received, 2);
                $damaged = round((float)$raw_damaged, 2);
                $excess = round((float)$raw_excess, 2);
                $line_note = trim((string)$raw_line_note);

                if ($received < 0 || $damaged < 0 || $excess < 0) {
                    throw new RuntimeException('Received, damaged, and excess quantities cannot be negative.');
                }
                if ($received > 99999999 || $damaged > 99999999 || $excess > 99999999) {
                    throw new RuntimeException('One of the receipt quantities is too large.');
                }
                if (!(int)$item['is_decimal']) {
                    foreach (['received' => $received, 'damaged' => $damaged, 'excess' => $excess] as $label => $value) {
                        if (abs($value - round($value)) > 0.0001) {
                            throw new RuntimeException($item['product_name'] . ' uses whole units only (' . $label . ' quantity).');
                        }
                    }
                }
                if ($received + $damaged > $dispatched + 0.0001) {
                    throw new RuntimeException(
                        'Accepted plus damaged cannot exceed dispatched quantity for ' . $item['product_name'] . '.'
                    );
                }
                if (mb_strlen($line_note) > 500) {
                    throw new RuntimeException('Line notes are too long (maximum 500 characters).');
                }

                $shortage = round(max(0, $dispatched - $received - $damaged), 2);
                if ($shortage > 0 || $damaged > 0 || $excess > 0) {
                    $has_discrepancy = true;
                }

                $total_accepted += $received;
                $total_damaged += $damaged;
                $total_shortage += $shortage;
                $total_excess += $excess;

                $stmt = $conn->prepare(
                    "UPDATE stock_transfer_items
                        SET received_quantity = ?, damaged_quantity = ?, shortage_quantity = ?,
                            excess_quantity = ?, line_notes = ?
                      WHERE item_id = ? AND tenant_id = ? AND transfer_id = ?"
                );
                $note_db = $line_note !== '' ? $line_note : null;
                $stmt->bind_param(
                    'ddddsiii',
                    $received,
                    $damaged,
                    $shortage,
                    $excess,
                    $note_db,
                    $item_id,
                    $tenant_id,
                    $transfer_id
                );
                $stmt->execute();
                $stmt->close();

                if ($received > 0) {
                    $stmt = $conn->prepare(
                        "INSERT INTO inventory_stock (tenant_id, product_id, warehouse_id, quantity, reorder_level)
                         VALUES (?, ?, ?, 0.00, ?)
                         ON DUPLICATE KEY UPDATE stock_id = stock_id"
                    );
                    $min_level = (float)($item['min_stock_level'] ?? 0);
                    $stmt->bind_param(
                        'iiid',
                        $tenant_id,
                        $item['product_id'],
                        $locked['destination_warehouse_id'],
                        $min_level
                    );
                    $stmt->execute();
                    $stmt->close();

                    $stock = fetch_one(
                        "SELECT stock_id, quantity FROM inventory_stock
                          WHERE tenant_id = ? AND warehouse_id = ? AND product_id = ?
                          FOR UPDATE",
                        $tenant_id,
                        (int)$locked['destination_warehouse_id'],
                        (int)$item['product_id']
                    );
                    if (!$stock) {
                        throw new RuntimeException('The destination stock row could not be locked.');
                    }

                    $stmt = $conn->prepare(
                        "UPDATE inventory_stock
                            SET quantity = quantity + ?, last_counted_at = CURRENT_TIMESTAMP
                          WHERE stock_id = ? AND tenant_id = ?"
                    );
                    $stmt->bind_param('dii', $received, $stock['stock_id'], $tenant_id);
                    $stmt->execute();
                    $stmt->close();

                    $movement_notes = 'Transfer ' . $locked['transfer_number'] . ' received from '
                        . $transfer['source_warehouse_name'] . '; accepted ' . format_quantity($received)
                        . ', damaged ' . format_quantity($damaged)
                        . ', shortage ' . format_quantity($shortage)
                        . ', excess recorded ' . format_quantity($excess);
                    $stmt = $conn->prepare(
                        "INSERT INTO inventory_movements
                            (tenant_id, product_id, warehouse_id, movement_type, quantity, reference_id, reference_type, created_by, notes)
                         VALUES (?, ?, ?, 'godown_transfer_in', ?, ?, 'stock_transfer', ?, ?)"
                    );
                    $stmt->bind_param(
                        'iiidiis',
                        $tenant_id,
                        $item['product_id'],
                        $locked['destination_warehouse_id'],
                        $received,
                        $transfer_id,
                        $current_user->user_id,
                        $movement_notes
                    );
                    $stmt->execute();
                    $stmt->close();
                }
            }

            $new_status = $has_discrepancy ? 'discrepancy' : 'received';
            $stmt = $conn->prepare(
                "UPDATE stock_transfers
                    SET status = ?, has_discrepancy = ?, received_by = ?, received_at = CURRENT_TIMESTAMP
                  WHERE transfer_id = ? AND tenant_id = ? AND status = 'dispatched'"
            );
            $flag = $has_discrepancy ? 1 : 0;
            $stmt->bind_param('siiii', $new_status, $flag, $current_user->user_id, $transfer_id, $tenant_id);
            $stmt->execute();
            if ($stmt->affected_rows !== 1) {
                $stmt->close();
                throw new RuntimeException('The transfer status changed before receipt.');
            }
            $stmt->close();

            $conn->commit();
            $in_transaction = false;
            consume_form_token();
            audit_log(
                'receive',
                'stock_transfer',
                $transfer_id,
                'Transfer ' . $locked['transfer_number'] . ' received: accepted ' . format_quantity($total_accepted)
                . ', damaged ' . format_quantity($total_damaged)
                . ', shortage ' . format_quantity($total_shortage)
                . ', excess recorded ' . format_quantity($total_excess)
                . '. Only accepted quantities were added to destination stock.'
            );
            flash_set(
                $has_discrepancy ? 'warning' : 'success',
                'Receipt recorded. Accepted quantities were added to the destination once.'
                . ($has_discrepancy ? ' A discrepancy was recorded for authorized resolution.' : '')
            );
            redirect('godown-transfer-view.php?id=' . $transfer_id);
        }

        if ($action === 'cancel') {
            if (!can('approve_godown_transfers') && !can('manage_godown')) {
                throw new RuntimeException('You do not have permission to cancel transfers.');
            }
            $source = transfer_source_access($locked);
            if (!$source) {
                throw new RuntimeException('You do not have access to the source location.');
            }
            if (!in_array($locked['status'], ['draft', 'approved'], true)) {
                throw new RuntimeException('Only requested or approved transfers can be cancelled. Dispatched stock cannot be erased.');
            }
            $reason = trim((string)($_POST['cancel_reason'] ?? ''));
            if (mb_strlen($reason) < 3) {
                throw new RuntimeException('Enter a cancellation reason (at least 3 characters).');
            }
            if (mb_strlen($reason) > 500) {
                throw new RuntimeException('The cancellation reason is too long (maximum 500 characters).');
            }

            $stmt = $conn->prepare(
                "UPDATE stock_transfers
                    SET status = 'cancelled', cancel_reason = ?, cancelled_by = ?, cancelled_at = CURRENT_TIMESTAMP
                  WHERE transfer_id = ? AND tenant_id = ? AND status IN ('draft', 'approved')"
            );
            $stmt->bind_param('siii', $reason, $current_user->user_id, $transfer_id, $tenant_id);
            $stmt->execute();
            if ($stmt->affected_rows !== 1) {
                $stmt->close();
                throw new RuntimeException('The transfer status changed before cancellation.');
            }
            $stmt->close();

            $conn->commit();
            $in_transaction = false;
            consume_form_token();
            audit_log('cancel', 'stock_transfer', $transfer_id, 'Transfer ' . $locked['transfer_number'] . ' cancelled: ' . $reason);
            flash_set('success', 'Transfer cancelled. No stock was moved.');
            redirect('godown-transfer-view.php?id=' . $transfer_id);
        }

        if ($action === 'resolve_discrepancy') {
            if (!can('approve_godown_transfers') && !can('manage_godown')) {
                throw new RuntimeException('You do not have permission to resolve discrepancies.');
            }
            $source = transfer_source_access($locked);
            if (!$source) {
                throw new RuntimeException('You do not have access to the source location.');
            }
            if ($locked['status'] !== 'discrepancy') {
                throw new RuntimeException('Only an open discrepancy can be resolved.');
            }
            $resolution = trim((string)($_POST['discrepancy_resolution'] ?? ''));
            if (mb_strlen($resolution) < 3) {
                throw new RuntimeException('Describe the resolution (at least 3 characters).');
            }
            if (mb_strlen($resolution) > 2000) {
                throw new RuntimeException('The resolution is too long (maximum 2,000 characters).');
            }

            $stmt = $conn->prepare(
                "UPDATE stock_transfers
                    SET status = 'received', discrepancy_resolution = ?,
                        discrepancy_resolved_by = ?, discrepancy_resolved_at = CURRENT_TIMESTAMP
                  WHERE transfer_id = ? AND tenant_id = ? AND status = 'discrepancy'"
            );
            $stmt->bind_param('siii', $resolution, $current_user->user_id, $transfer_id, $tenant_id);
            $stmt->execute();
            if ($stmt->affected_rows !== 1) {
                $stmt->close();
                throw new RuntimeException('The discrepancy was already resolved or changed.');
            }
            $stmt->close();

            $conn->commit();
            $in_transaction = false;
            consume_form_token();
            audit_log('resolve', 'stock_transfer', $transfer_id, 'Discrepancy on transfer ' . $locked['transfer_number'] . ' resolved: ' . $resolution . '. Original quantities were preserved.');
            flash_set('success', 'Discrepancy resolved. Original quantities and stock movements were preserved.');
            redirect('godown-transfer-view.php?id=' . $transfer_id);
        }

        throw new RuntimeException('Unknown transfer action.');
    } catch (Throwable $ex) {
        if ($in_transaction) $conn->rollback();
        flash_set('danger', $ex->getMessage());
        redirect('godown-transfer-view.php?id=' . $transfer_id);
    }
}

$transfer = transfer_load($transfer_id, $tenant_id);
if (!$transfer) {
    http_response_code(404);
    die('Transfer not found.');
}
$items = transfer_items($transfer_id, $tenant_id);
$source_store = transfer_source_access($transfer);
$destination_store = transfer_destination_access($transfer);

$can_approve = $source_store && can('approve_godown_transfers');
$can_dispatch = $source_store && can('dispatch_godown_transfers');
$can_receive = $destination_store && can('receive_godown_transfers');
$can_cancel = $source_store && (can('approve_godown_transfers') || can('manage_godown'));
$can_resolve = $source_store && (can('approve_godown_transfers') || can('manage_godown'));
$can_manage_godown = can('manage_godown');
$can_request_godown = can('request_godown_transfers');

$source_stock_by_product = [];
$show_source_stock = $source_store && in_array($transfer['status'], ['draft', 'approved'], true);
if ($show_source_stock && $items) {
    $product_ids = array_values(array_unique(array_map(fn($i) => (int)$i['product_id'], $items)));
    $placeholders = implode(',', array_fill(0, count($product_ids), '?'));
    $stock_params = array_merge([$tenant_id, (int)$transfer['source_warehouse_id']], $product_ids);
    $rows = fetch_all(
        "SELECT product_id, quantity FROM inventory_stock
          WHERE tenant_id = ? AND warehouse_id = ? AND product_id IN ({$placeholders})",
        ...$stock_params
    );
    foreach ($rows as $row) {
        $source_stock_by_product[(int)$row['product_id']] = (float)$row['quantity'];
    }
}

$timeline = [
    ['key' => 'requested', 'label' => 'Requested', 'at' => $transfer['requested_at'], 'by' => trim((string)$transfer['requested_by_name']) ?: $transfer['requested_by_username']],
    ['key' => 'approved', 'label' => 'Approved', 'at' => $transfer['approved_at'], 'by' => trim((string)$transfer['approved_by_name']) ?: $transfer['approved_by_username']],
    ['key' => 'dispatched', 'label' => 'Dispatched', 'at' => $transfer['dispatched_at'], 'by' => trim((string)$transfer['dispatched_by_name']) ?: $transfer['dispatched_by_username']],
    ['key' => 'received', 'label' => 'Received', 'at' => $transfer['received_at'], 'by' => trim((string)$transfer['received_by_name']) ?: $transfer['received_by_username']],
];

require_once 'navbar.php';
require_once 'sidebar.php';
?>

<div class="page-head">
    <div>
        <h1 class="page-title"><?php echo e($transfer['transfer_number']); ?></h1>
        <div class="page-sub">
            <?php echo e(trim(($transfer['source_shop_name'] ?? '') . ' / ' . $transfer['source_warehouse_name'], ' /')); ?> → <?php echo e($transfer['destination_shop_name'] . ' / ' . $transfer['destination_store_name']); ?>
            <span class="badge badge-<?php echo e(godown_transfer_status_class($transfer['status'])); ?> ml-2"><?php echo e(godown_transfer_status_label($transfer['status'])); ?></span>
            <?php if ((int)$transfer['has_discrepancy'] === 1): ?>
                <span class="badge badge-danger ml-1">Discrepancy recorded</span>
            <?php endif; ?>
        </div>
    </div>
    <a href="godown-transfers.php" class="btn btn-light"><i class="fa fa-arrow-left mr-1"></i> Transfers</a>
</div>

<ul class="nav page-tabs">
    <?php if ($can_manage_godown): ?>
    <li class="nav-item"><a class="nav-link" href="godown.php"><i class="fa fa-archive mr-1"></i> Godown</a></li>
    <?php endif; ?>
    <li class="nav-item"><a class="nav-link" href="godown-transfers.php"><i class="fa fa-exchange mr-1"></i> Transfers</a></li>
    <?php if (($can_manage_godown || $can_request_godown) && find_active_godown()): ?>
    <li class="nav-item"><a class="nav-link" href="godown-transfer-add.php"><i class="fa fa-plus mr-1"></i> <?php echo $can_manage_godown ? 'New Transfer' : 'Request Stock'; ?></a></li>
    <?php endif; ?>
</ul>

<?php if ($f = flash_get()): ?>
    <div class="alert alert-<?php echo e($f['type']); ?>"><?php echo e($f['message']); ?></div>
<?php endif; ?>

<div class="row">
    <div class="col-lg-8 mb-4">
        <div class="card mb-4">
            <div class="card-header"><h5 class="card-title mb-0">Transfer Details</h5></div>
            <div class="card-body">
                <div class="row">
                    <div class="col-sm-6 mb-3">
                        <div class="text-muted small">Source Location</div>
                        <div class="font-weight-semibold"><?php echo e($transfer['source_warehouse_name']); ?></div>
                        <div class="text-muted small"><?php echo e($transfer['source_shop_name'] ?: 'Independent Godown'); ?> <?php echo $transfer['source_warehouse_code'] ? '(' . e($transfer['source_warehouse_code']) . ')' : ''; ?></div>
                    </div>
                    <div class="col-sm-6 mb-3">
                        <div class="text-muted small">Destination</div>
                        <div class="font-weight-semibold"><?php echo e($transfer['destination_shop_name']); ?></div>
                        <div class="text-muted small"><?php echo e($transfer['destination_store_name']); ?> <?php echo $transfer['destination_store_code'] ? '(' . e($transfer['destination_store_code']) . ')' : ''; ?></div>
                    </div>
                    <div class="col-sm-6 mb-3">
                        <div class="text-muted small">Requested by</div>
                        <div><?php echo e(trim((string)$transfer['requested_by_name']) ?: ($transfer['requested_by_username'] ?: 'Unknown user')); ?></div>
                        <div class="text-muted small"><?php echo e(date('M d, Y H:i', strtotime($transfer['requested_at']))); ?></div>
                    </div>
                    <div class="col-sm-6 mb-3">
                        <div class="text-muted small">Last updated</div>
                        <div><?php echo e(date('M d, Y H:i', strtotime($transfer['updated_at']))); ?></div>
                    </div>
                    <div class="col-12">
                        <div class="text-muted small">Request notes</div>
                        <div><?php echo $transfer['notes'] !== null && $transfer['notes'] !== '' ? nl2br(e($transfer['notes'])) : '<span class="text-muted">—</span>'; ?></div>
                    </div>
                </div>
            </div>
        </div>

        <?php if ($transfer['status'] === 'draft'): ?>
        <div class="card mb-4">
            <div class="card-header"><h5 class="card-title mb-0"><i class="fa fa-check-circle mr-1"></i> Approval</h5></div>
            <div class="card-body">
                <p class="text-muted">Approval records authorization only. It does not reserve or change physical stock.</p>
                <?php if ($can_approve): ?>
                <form method="POST" action="godown-transfer-view.php?id=<?php echo $transfer_id; ?>" class="mb-3">
                    <?php echo csrf_field(); ?>
                    <?php echo form_token_field(); ?>
                    <input type="hidden" name="action" value="approve">
                    <button type="submit" class="btn btn-primary"><i class="fa fa-check mr-1"></i> Approve Transfer</button>
                </form>
                <?php endif; ?>
                <?php if ($can_cancel): ?>
                <form method="POST" action="godown-transfer-view.php?id=<?php echo $transfer_id; ?>" onsubmit="return confirm('Cancel this request? No stock has moved.');">
                    <?php echo csrf_field(); ?>
                    <?php echo form_token_field(); ?>
                    <input type="hidden" name="action" value="cancel">
                    <div class="form-group">
                        <label>Cancellation reason</label>
                        <input type="text" name="cancel_reason" class="form-control" maxlength="500" required>
                    </div>
                    <button type="submit" class="btn btn-outline-danger"><i class="fa fa-times mr-1"></i> Cancel Request</button>
                </form>
                <?php endif; ?>
                <?php if (!$can_approve && !$can_cancel): ?>
                    <div class="alert alert-warning mb-0">You can view this transfer but cannot approve or cancel it.</div>
                <?php endif; ?>
            </div>
        </div>
        <?php elseif ($transfer['status'] === 'approved'): ?>
        <div class="card mb-4">
            <div class="card-header"><h5 class="card-title mb-0"><i class="fa fa-truck mr-1"></i> Dispatch</h5></div>
            <div class="card-body">
                <p class="text-muted">Dispatch deducts each product from the source location once and puts the quantities in transit. If any product is short, the whole dispatch is blocked.</p>
                <?php if ($can_dispatch): ?>
                <form method="POST" action="godown-transfer-view.php?id=<?php echo $transfer_id; ?>" class="mb-3" onsubmit="return confirm('Dispatch this transfer? Source stock will be deducted now.');">
                    <?php echo csrf_field(); ?>
                    <?php echo form_token_field(); ?>
                    <input type="hidden" name="action" value="dispatch">
                    <button type="submit" class="btn btn-primary"><i class="fa fa-truck mr-1"></i> Dispatch Transfer</button>
                </form>
                <?php endif; ?>
                <?php if ($can_cancel): ?>
                <form method="POST" action="godown-transfer-view.php?id=<?php echo $transfer_id; ?>" onsubmit="return confirm('Cancel this approved transfer? No stock has moved.');">
                    <?php echo csrf_field(); ?>
                    <?php echo form_token_field(); ?>
                    <input type="hidden" name="action" value="cancel">
                    <div class="form-group">
                        <label>Cancellation reason</label>
                        <input type="text" name="cancel_reason" class="form-control" maxlength="500" required>
                    </div>
                    <button type="submit" class="btn btn-outline-danger"><i class="fa fa-times mr-1"></i> Cancel Transfer</button>
                </form>
                <?php endif; ?>
                <?php if (!$can_dispatch && !$can_cancel): ?>
                    <div class="alert alert-warning mb-0">You can view this transfer but cannot dispatch or cancel it.</div>
                <?php endif; ?>
            </div>
        </div>
        <?php elseif ($transfer['status'] === 'dispatched'): ?>
        <div class="card mb-4">
            <div class="card-header"><h5 class="card-title mb-0"><i class="fa fa-download mr-1"></i> Receive at Destination</h5></div>
            <div class="card-body">
                <?php if ($can_receive): ?>
                <p class="text-muted">Enter accepted quantities. Damaged, shortage, and excess are recorded separately. Only accepted quantities are added to destination stock, and this receipt can happen only once.</p>
                <form method="POST" action="godown-transfer-view.php?id=<?php echo $transfer_id; ?>" id="receiveTransferForm">
                    <?php echo csrf_field(); ?>
                    <?php echo form_token_field(); ?>
                    <input type="hidden" name="action" value="receive">
                    <div class="table-responsive">
                        <table class="table mb-3">
                            <thead>
                                <tr>
                                    <th>Product</th><th>Dispatched</th><th>Accepted</th><th>Damaged</th><th>Excess</th><th>Shortage</th><th>Line Note</th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($items as $item): ?>
                                <?php
                                $item_id = (int)$item['item_id'];
                                $dispatched = (float)$item['dispatched_quantity'];
                                $step = (int)$item['is_decimal'] ? '0.01' : '1';
                                ?>
                                <tr class="receive-line" data-dispatched="<?php echo e($dispatched); ?>" data-decimals="<?php echo (int)$item['is_decimal'] ? 2 : 0; ?>">
                                    <td>
                                        <div class="font-weight-semibold"><?php echo e($item['product_name']); ?></div>
                                        <small class="text-muted"><?php echo e($item['product_code']); ?></small>
                                    </td>
                                    <td><?php echo e(format_quantity($dispatched)); ?> <?php echo e($item['unit_short']); ?></td>
                                    <td style="min-width: 100px;">
                                        <input type="number" name="received_quantity[<?php echo $item_id; ?>]" class="form-control form-control-sm receive-accepted" step="<?php echo $step; ?>" min="0" value="<?php echo e($dispatched); ?>" required>
                                    </td>
                                    <td style="min-width: 95px;">
                                        <input type="number" name="damaged_quantity[<?php echo $item_id; ?>]" class="form-control form-control-sm receive-damaged" step="<?php echo $step; ?>" min="0" value="0" required>
                                    </td>
                                    <td style="min-width: 95px;">
                                        <input type="number" name="excess_quantity[<?php echo $item_id; ?>]" class="form-control form-control-sm receive-excess" step="<?php echo $step; ?>" min="0" value="0" required>
                                        <small class="text-muted">not added</small>
                                    </td>
                                    <td><span class="badge badge-secondary receive-shortage">0</span></td>
                                    <td><input type="text" name="line_notes[<?php echo $item_id; ?>]" class="form-control form-control-sm" maxlength="500" placeholder="Damage/shortage detail"></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <button type="submit" class="btn btn-primary"><i class="fa fa-check mr-1"></i> Record Receipt</button>
                </form>
                <?php else: ?>
                    <div class="alert alert-warning mb-0">You can view this in-transit transfer but do not have receive access at the destination.</div>
                <?php endif; ?>
            </div>
        </div>
        <?php elseif ($transfer['status'] === 'discrepancy'): ?>
        <div class="card mb-4 border-danger">
            <div class="card-header bg-white"><h5 class="card-title mb-0 text-danger"><i class="fa fa-exclamation-triangle mr-1"></i> Discrepancy Requires Resolution</h5></div>
            <div class="card-body">
                <p class="text-muted">The physical receipt is complete and accepted stock was already added. This state is for authorized review; resolving it will not alter quantities or stock movements.</p>
                <?php if ($can_resolve): ?>
                <form method="POST" action="godown-transfer-view.php?id=<?php echo $transfer_id; ?>">
                    <?php echo csrf_field(); ?>
                    <?php echo form_token_field(); ?>
                    <input type="hidden" name="action" value="resolve_discrepancy">
                    <div class="form-group">
                        <label>Resolution note <span class="text-danger">*</span></label>
                        <textarea name="discrepancy_resolution" class="form-control" rows="3" maxlength="2000" required placeholder="Explain the review, approval, or follow-up action"></textarea>
                    </div>
                    <button type="submit" class="btn btn-danger"><i class="fa fa-check mr-1"></i> Resolve Discrepancy</button>
                </form>
                <?php else: ?>
                    <div class="alert alert-warning mb-0">You can view this discrepancy but do not have permission to resolve it.</div>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>

        <div class="card mb-4">
            <div class="card-header"><h5 class="card-title mb-0">Product Lines</h5></div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table mb-0">
                        <thead>
                            <tr>
                                <th>Product</th><th>Requested</th><th>Dispatched</th><th>Accepted</th><th>Damaged</th><th>Shortage</th><th>Excess</th>
                                <?php if ($show_source_stock): ?><th>Source On Hand</th><?php endif; ?>
                                <th>Line Note</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($items as $item): ?>
                            <tr>
                                <td>
                                    <div class="font-weight-semibold"><?php echo e($item['product_name']); ?></div>
                                    <small class="text-muted"><?php echo e($item['product_code']); ?></small>
                                </td>
                                <td><?php echo e(format_quantity($item['requested_quantity'])); ?> <?php echo e($item['unit_short']); ?></td>
                                <td><?php echo e(format_quantity($item['dispatched_quantity'])); ?></td>
                                <td><?php echo e(format_quantity($item['received_quantity'])); ?></td>
                                <td><?php echo e(format_quantity($item['damaged_quantity'])); ?></td>
                                <td><?php echo e(format_quantity($item['shortage_quantity'])); ?></td>
                                <td><?php echo e(format_quantity($item['excess_quantity'])); ?></td>
                                <?php if ($show_source_stock): ?>
                                    <td><?php echo e(format_quantity($source_stock_by_product[(int)$item['product_id']] ?? 0)); ?></td>
                                <?php endif; ?>
                                <td><?php echo $item['line_notes'] ? nl2br(e($item['line_notes'])) : '<span class="text-muted">—</span>'; ?></td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (!$items): ?>
                            <tr><td colspan="<?php echo $show_source_stock ? 9 : 8; ?>" class="text-center text-muted py-4">No product lines.</td></tr>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <div class="col-lg-4 mb-4">
        <div class="card mb-4">
            <div class="card-header"><h5 class="card-title mb-0">Progress</h5></div>
            <div class="card-body">
                <?php if ($transfer['status'] === 'cancelled'): ?>
                    <div class="alert alert-secondary">
                        Cancelled <?php echo e(date('M d, Y H:i', strtotime($transfer['cancelled_at']))); ?><br>
                        <small><?php echo e(trim((string)$transfer['cancelled_by_name']) ?: ($transfer['cancelled_by_username'] ?: 'Unknown user')); ?></small>
                    </div>
                <?php endif; ?>
                <ul class="list-unstyled mb-0">
                <?php foreach ($timeline as $step): ?>
                    <li class="mb-3 d-flex">
                        <div class="mr-3 text-center" style="width: 20px;">
                            <i class="fa <?php echo $step['at'] ? 'fa-check-circle text-success' : 'fa-circle-o text-muted'; ?>"></i>
                        </div>
                        <div>
                            <div class="font-weight-semibold"><?php echo e($step['label']); ?></div>
                            <?php if ($step['at']): ?>
                                <div class="text-muted small"><?php echo e(date('M d, Y H:i', strtotime($step['at']))); ?></div>
                                <div class="text-muted small"><?php echo e($step['by'] ?: 'Unknown user'); ?></div>
                            <?php else: ?>
                                <div class="text-muted small">Pending</div>
                            <?php endif; ?>
                        </div>
                    </li>
                <?php endforeach; ?>
                </ul>
                <?php if ($transfer['has_discrepancy'] && $transfer['discrepancy_resolved_at']): ?>
                    <hr>
                    <div class="font-weight-semibold text-success"><i class="fa fa-check mr-1"></i>Discrepancy resolved</div>
                    <div class="text-muted small"><?php echo e(date('M d, Y H:i', strtotime($transfer['discrepancy_resolved_at']))); ?></div>
                    <div class="text-muted small"><?php echo e(trim((string)$transfer['discrepancy_resolved_by_name']) ?: ($transfer['discrepancy_resolved_by_username'] ?: 'Unknown user')); ?></div>
                    <div class="mt-2"><?php echo nl2br(e((string)$transfer['discrepancy_resolution'])); ?></div>
                <?php endif; ?>
            </div>
        </div>

        <?php if ($transfer['status'] === 'cancelled' && $transfer['cancel_reason']): ?>
        <div class="card mb-4">
            <div class="card-header"><h5 class="card-title mb-0">Cancellation</h5></div>
            <div class="card-body"><?php echo nl2br(e($transfer['cancel_reason'])); ?></div>
        </div>
        <?php endif; ?>

        <div class="card">
            <div class="card-header"><h5 class="card-title mb-0">Audit Trail</h5></div>
            <div class="card-body small text-muted">
                Requesting, approval, dispatch, receipt, cancellation, and discrepancy resolution are recorded in the audit log and stock movement ledger.
            </div>
        </div>
    </div>
</div>

<script>
    $(function () {
        function updateShortage($line) {
            var dispatched = parseFloat($line.data('dispatched')) || 0;
            var accepted = parseFloat($line.find('.receive-accepted').val()) || 0;
            var damaged = parseFloat($line.find('.receive-damaged').val()) || 0;
            var shortage = Math.max(0, dispatched - accepted - damaged);
            var decimals = parseInt($line.data('decimals'), 10) || 0;
            $line.find('.receive-shortage').text(shortage.toFixed(decimals).replace(/\.?0+$/, '') || '0');
        }
        $('.receive-line').each(function () { updateShortage($(this)); });
        $(document).on('input change', '.receive-accepted, .receive-damaged', function () {
            updateShortage($(this).closest('.receive-line'));
        });
    });
</script>

<div class="mb-3"><a href="transfer-note.php?id=<?php echo (int)$transfer_id; ?>" class="btn btn-secondary" target="_blank"><i class="fa fa-print mr-1"></i> Goods Transfer Note</a></div>
<?php require_once 'footer.php'; ?>
