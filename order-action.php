<?php
require_once 'header.php';
require_once 'auth_check.php';
require_permission('manage_orders');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('orders.php');
}
verify_csrf();

$id = (int)($_POST['order_id'] ?? 0);
$action = (string)($_POST['action'] ?? '');

$order = fetch_one(
    "SELECT * FROM orders WHERE order_id = ? AND tenant_id = ?",
    $id,
    $current_user->tenant_id
);
if (!$order) {
    flash_set('danger', 'Order not found.');
    redirect('orders.php');
}
if (!order_is_visible($order)) {
    http_response_code(403);
    die('You do not have access to this order.');
}

$back = 'order-view.php?id=' . $id;
$status = (string)$order['status'];

function order_action_redirect($back, $type, $message) {
    flash_set($type, $message);
    redirect($back);
}

try {
    switch ($action) {
        case 'start_review':
            if ($status !== 'new') {
                order_action_redirect($back, 'danger', 'Only a new order can start review.');
            }
            $stmt = $conn->prepare(
                "UPDATE orders SET status = 'under_review'
                  WHERE order_id = ? AND tenant_id = ? AND status = 'new'"
            );
            $stmt->bind_param('ii', $id, $current_user->tenant_id);
            if (!$stmt->execute()) throw new RuntimeException($stmt->error);
            $changed = $stmt->affected_rows;
            $stmt->close();
            if ($changed !== 1) throw new RuntimeException('This order was changed by someone else. Reload and try again.');
            audit_log('start_review', 'order', $id, 'Order moved to under review');
            order_action_redirect($back, 'success', 'Order is now under review. Verify price, availability, shop, and store before confirming.');
            break;

        case 'confirm':
            if ($status !== 'under_review') {
                order_action_redirect($back, 'danger', 'Only an order under review can be confirmed.');
            }
            $shop_id = (int)($_POST['shop_id'] ?? 0);
            $warehouse_id = (int)($_POST['warehouse_id'] ?? 0);
            $note = trim((string)($_POST['note'] ?? ''));
            if ($shop_id <= 0 || !shop_is_accessible($shop_id, false)) {
                order_action_redirect($back, 'danger', 'Select an active shop you have access to before confirming.');
            }
            if ($warehouse_id <= 0) {
                order_action_redirect($back, 'danger', 'Select the store that will supply this order.');
            }
            $store = find_accessible_store($warehouse_id, false);
            if (!$store) {
                order_action_redirect($back, 'danger', 'Select an active store you have access to.');
            }
            if ((int)$store['shop_id'] !== $shop_id) {
                order_action_redirect($back, 'danger', 'The selected store does not belong to the selected shop.');
            }

            $had_active_reservation = false;
            $conn->begin_transaction();
            try {
                $locked = fetch_one(
                    "SELECT * FROM orders WHERE order_id = ? AND tenant_id = ? FOR UPDATE",
                    $id,
                    $current_user->tenant_id
                );
                if (!$locked || $locked['status'] !== 'under_review') {
                    throw new RuntimeException('This order is no longer under review. Reload and try again.');
                }
                $existing_reservations = fetch_all(
                    "SELECT DISTINCT warehouse_id
                       FROM order_stock_reservations
                      WHERE order_id = ? AND tenant_id = ? AND status = 'active'",
                    $id,
                    (int)$current_user->tenant_id
                );
                $had_active_reservation = (bool)$existing_reservations;
                if ($had_active_reservation
                    && (count($existing_reservations) !== 1
                        || (int)$existing_reservations[0]['warehouse_id'] !== $warehouse_id)) {
                    throw new RuntimeException('Stock is already reserved at the store selected during verification. Reload the order and confirm that store.');
                }
                $reserved_lines = reserve_order_stock(
                    $id,
                    (int)$current_user->tenant_id,
                    $shop_id,
                    $warehouse_id,
                    (int)$current_user->user_id
                );
                $stmt = $conn->prepare(
                    "UPDATE orders SET status = 'confirmed', shop_id = ?, warehouse_id = ?,
                        confirmed_by = ?, confirmed_at = NOW()
                      WHERE order_id = ? AND tenant_id = ? AND status = 'under_review'"
                );
                $stmt->bind_param(
                    'iiiii',
                    $shop_id,
                    $warehouse_id,
                    $current_user->user_id,
                    $id,
                    $current_user->tenant_id
                );
                if (!$stmt->execute()) throw new RuntimeException($stmt->error);
                $changed = $stmt->affected_rows;
                $stmt->close();
                if ($changed !== 1) throw new RuntimeException('This order was changed by someone else. Reload and try again.');
                $conn->commit();
            } catch (Throwable $ex) {
                $conn->rollback();
                throw $ex;
            }
            $reservation_message = $had_active_reservation
                ? 'The stock reservation from verification is retained.'
                : (int)$reserved_lines . ' stock line(s) are now reserved.';
            audit_log('confirm', 'order', $id, 'Order confirmed for shop #' . $shop_id . ', store #' . $warehouse_id
                . '; ' . ($had_active_reservation ? 'verification reservation retained' : (int)$reserved_lines . ' stock line(s) reserved')
                . ($note !== '' ? ' — ' . $note : ''));
            order_action_redirect($back, 'success', 'Order confirmed. ' . $reservation_message . ' No physical stock was deducted.');
            break;

        case 'reject':
            if (!in_array($status, ['new', 'under_review'], true)) {
                order_action_redirect($back, 'danger', 'This order can no longer be rejected.');
            }
            $reason = trim((string)($_POST['reason'] ?? ''));
            if (mb_strlen($reason) < 3) {
                order_action_redirect($back, 'danger', 'Enter a rejection reason of at least 3 characters.');
            }
            $conn->begin_transaction();
            try {
                $locked = fetch_one(
                    "SELECT * FROM orders WHERE order_id = ? AND tenant_id = ? FOR UPDATE",
                    $id,
                    $current_user->tenant_id
                );
                if (!$locked || !in_array($locked['status'], ['new', 'under_review'], true)) {
                    throw new RuntimeException('This order was changed by someone else. Reload and try again.');
                }
                $stmt = $conn->prepare(
                    "UPDATE orders SET status = 'rejected', rejected_by = ?, rejected_at = NOW(), rejection_reason = ?
                      WHERE order_id = ? AND tenant_id = ? AND status IN ('new', 'under_review')"
                );
                $stmt->bind_param('isii', $current_user->user_id, $reason, $id, $current_user->tenant_id);
                if (!$stmt->execute()) throw new RuntimeException($stmt->error);
                $changed = $stmt->affected_rows;
                $stmt->close();
                if ($changed !== 1) throw new RuntimeException('This order was changed by someone else. Reload and try again.');
                release_order_reservations($id, (int)$current_user->tenant_id, 'Order rejected: ' . $reason);
                $conn->commit();
            } catch (Throwable $ex) {
                $conn->rollback();
                throw $ex;
            }
            audit_log('reject', 'order', $id, 'Order rejected: ' . $reason);
            order_action_redirect($back, 'success', 'Order rejected. Any stock reservation was released.');
            break;

        case 'cancel':
            if (!in_array($status, ['new', 'under_review', 'confirmed'], true)) {
                order_action_redirect($back, 'danger', 'This order can no longer be cancelled.');
            }
            $reason = trim((string)($_POST['reason'] ?? ''));
            if (mb_strlen($reason) < 3) {
                order_action_redirect($back, 'danger', 'Enter a cancellation reason of at least 3 characters.');
            }
            $conn->begin_transaction();
            try {
                $locked = fetch_one(
                    "SELECT * FROM orders WHERE order_id = ? AND tenant_id = ? FOR UPDATE",
                    $id,
                    $current_user->tenant_id
                );
                if (!$locked || !in_array($locked['status'], ['new', 'under_review', 'confirmed'], true)) {
                    throw new RuntimeException('This order was changed by someone else. Reload and try again.');
                }
                $stmt = $conn->prepare(
                    "UPDATE orders SET status = 'cancelled', cancelled_by = ?, cancelled_at = NOW(), cancel_reason = ?
                      WHERE order_id = ? AND tenant_id = ? AND status IN ('new', 'under_review', 'confirmed')"
                );
                $stmt->bind_param('isii', $current_user->user_id, $reason, $id, $current_user->tenant_id);
                if (!$stmt->execute()) throw new RuntimeException($stmt->error);
                $changed = $stmt->affected_rows;
                $stmt->close();
                if ($changed !== 1) throw new RuntimeException('This order was changed by someone else. Reload and try again.');
                release_order_reservations($id, (int)$current_user->tenant_id, 'Order cancelled: ' . $reason);
                $conn->commit();
            } catch (Throwable $ex) {
                $conn->rollback();
                throw $ex;
            }
            audit_log('cancel', 'order', $id, 'Order cancelled: ' . $reason);
            order_action_redirect($back, 'success', 'Order cancelled. Any stock reservation was released.');
            break;

        case 'fulfil':
            if ($status !== 'confirmed') {
                order_action_redirect($back, 'danger', 'Only a confirmed order can be fulfilled.');
            }
            $invoice_id = (int)($_POST['invoice_id'] ?? 0);
            if ($invoice_id <= 0) {
                order_action_redirect($back, 'danger', 'Select the invoice that fulfils this order.');
            }
            $invoice = fetch_one(
                "SELECT i.*, ds.name status_name
                 FROM invoices i
                 LEFT JOIN document_statuses ds ON ds.status_id = i.status_id
                 WHERE i.invoice_id = ? AND i.tenant_id = ?",
                $invoice_id,
                $current_user->tenant_id
            );
            if (!$invoice) {
                order_action_redirect($back, 'danger', 'The selected invoice does not exist.');
            }
            if (stripos((string)$invoice['status_name'], 'cancel') !== false) {
                order_action_redirect($back, 'danger', 'A cancelled invoice cannot fulfil an order.');
            }
            $invoice_scope = accessible_warehouse_ids();
            if ($invoice_scope !== null) {
                $invoice_store = (int)($invoice['warehouse_id'] ?? 0);
                if ($invoice_store <= 0 || !in_array($invoice_store, $invoice_scope, true)) {
                    order_action_redirect($back, 'danger', 'You do not have access to the store for that invoice.');
                }
            }
            if (!empty($order['warehouse_id']) && !empty($invoice['warehouse_id'])
                && (int)$order['warehouse_id'] !== (int)$invoice['warehouse_id']) {
                order_action_redirect($back, 'danger', 'The invoice store does not match the store confirmed for this order.');
            }

            $conn->begin_transaction();
            try {
                $lock = fetch_one(
                    "SELECT * FROM orders WHERE order_id = ? AND tenant_id = ? FOR UPDATE",
                    $id,
                    $current_user->tenant_id
                );
                if (!$lock || $lock['status'] !== 'confirmed') {
                    throw new RuntimeException('This order is no longer awaiting fulfilment.');
                }
                $locked_invoice = fetch_one(
                    "SELECT i.*, ds.name status_name
                       FROM invoices i
                       LEFT JOIN document_statuses ds ON ds.status_id = i.status_id
                      WHERE i.invoice_id = ? AND i.tenant_id = ?
                      FOR UPDATE",
                    $invoice_id,
                    $current_user->tenant_id
                );
                if (!$locked_invoice) throw new RuntimeException('The selected invoice no longer exists.');
                if (stripos((string)$locked_invoice['status_name'], 'cancel') !== false) {
                    throw new RuntimeException('A cancelled invoice cannot fulfil an order.');
                }
                if (!empty($lock['warehouse_id']) && !empty($locked_invoice['warehouse_id'])
                    && (int)$lock['warehouse_id'] !== (int)$locked_invoice['warehouse_id']) {
                    throw new RuntimeException('The invoice store does not match the store confirmed for this order.');
                }
                if (!order_invoice_matches($id, (int)$current_user->tenant_id, $invoice_id)) {
                    throw new RuntimeException('The invoice items and quantities do not exactly match this order. Create the invoice from this order and try again.');
                }
                $used = fetch_one(
                    "SELECT order_id FROM orders WHERE tenant_id = ? AND fulfilled_invoice_id = ? LIMIT 1",
                    $current_user->tenant_id,
                    $invoice_id
                );
                if ($used) {
                    throw new RuntimeException('That invoice is already linked to another order.');
                }

                $active = order_reservation_rows($id, (int)$current_user->tenant_id, ['active'], true);
                $expected = fetch_one(
                    "SELECT COUNT(*) c
                       FROM order_items oi
                       LEFT JOIN products p ON p.product_id = oi.product_id AND p.tenant_id = oi.tenant_id
                      WHERE oi.order_id = ? AND oi.tenant_id = ? AND COALESCE(p.is_service, 0) = 0",
                    $id,
                    $current_user->tenant_id
                );
                $expected_count = (int)($expected['c'] ?? 0);
                if ($active && count($active) !== $expected_count) {
                    throw new RuntimeException('This order has an incomplete reservation. Review the order before fulfilling it.');
                }
                if ($active) {
                    $consumed = consume_order_reservations($id, (int)$current_user->tenant_id, $invoice_id);
                    if ($consumed !== count($active)) {
                        throw new RuntimeException('The stock reservation changed while fulfilment was being completed.');
                    }
                }

                $invoice_number = (string)($locked_invoice['invoice_number'] ?? ('#' . $invoice_id));
                $stmt = $conn->prepare(
                    "UPDATE orders SET status = 'fulfilled', fulfilled_by = ?, fulfilled_at = NOW(),
                        fulfilled_invoice_id = ?, fulfilled_invoice_number = ?
                      WHERE order_id = ? AND tenant_id = ? AND status = 'confirmed'"
                );
                $stmt->bind_param(
                    'iisii',
                    $current_user->user_id,
                    $invoice_id,
                    $invoice_number,
                    $id,
                    $current_user->tenant_id
                );
                if (!$stmt->execute()) throw new RuntimeException($stmt->error);
                if ($stmt->affected_rows !== 1) throw new RuntimeException('This order was changed by someone else.');
                $stmt->close();
                $conn->commit();
            } catch (Throwable $ex) {
                $conn->rollback();
                throw $ex;
            }
            audit_log('fulfil', 'order', $id, 'Order fulfilled with invoice ' . $invoice_number . '; reservations consumed');
            order_action_redirect($back, 'success', 'Order fulfilled and linked to invoice ' . $invoice_number . '. Its reservations were consumed; this action did not deduct stock again.');
            break;

        case 'link_lead':
            if (!can('manage_leads')) {
                http_response_code(403);
                die('You do not have permission to link leads.');
            }
            $lead_id = (int)($_POST['lead_id'] ?? 0);
            $lead = fetch_one(
                "SELECT * FROM leads WHERE lead_id = ? AND tenant_id = ?",
                $lead_id,
                $current_user->tenant_id
            );
            if (!$lead) {
                order_action_redirect($back, 'danger', 'The selected lead does not exist.');
            }
            if (!empty($order['lead_id'])) {
                order_action_redirect($back, 'danger', 'This order is already linked to a lead. Unlink it first.');
            }
            $conn->begin_transaction();
            try {
                $stmt = $conn->prepare(
                    "UPDATE orders SET lead_id = ?
                      WHERE order_id = ? AND tenant_id = ? AND lead_id IS NULL"
                );
                $stmt->bind_param('iii', $lead_id, $id, $current_user->tenant_id);
                if (!$stmt->execute()) throw new RuntimeException($stmt->error);
                if ($stmt->affected_rows !== 1) throw new RuntimeException('This order was changed by someone else.');
                $stmt->close();
                record_lead_activity(
                    $lead_id,
                    (int)$current_user->tenant_id,
                    'order_link',
                    'Order ' . $order['order_number'] . ' linked to lead',
                    null,
                    null,
                    $id
                );
                $conn->commit();
            } catch (Throwable $ex) {
                $conn->rollback();
                throw $ex;
            }
            audit_log('link_lead', 'order', $id, 'Linked to lead ' . $lead['lead_number']);
            order_action_redirect($back, 'success', 'Lead ' . $lead['lead_number'] . ' linked to this order.');
            break;

        case 'unlink_lead':
            if (!can('manage_leads')) {
                http_response_code(403);
                die('You do not have permission to unlink leads.');
            }
            if (empty($order['lead_id'])) {
                order_action_redirect($back, 'danger', 'This order is not linked to a lead.');
            }
            $lead_id = (int)$order['lead_id'];
            $conn->begin_transaction();
            try {
                $stmt = $conn->prepare(
                    "UPDATE orders SET lead_id = NULL
                      WHERE order_id = ? AND tenant_id = ? AND lead_id = ?"
                );
                $stmt->bind_param('iii', $id, $current_user->tenant_id, $lead_id);
                if (!$stmt->execute()) throw new RuntimeException($stmt->error);
                if ($stmt->affected_rows !== 1) throw new RuntimeException('This order was changed by someone else.');
                $stmt->close();
                record_lead_activity(
                    $lead_id,
                    (int)$current_user->tenant_id,
                    'order_link',
                    'Order ' . $order['order_number'] . ' unlinked from lead',
                    null,
                    null,
                    $id
                );
                $conn->commit();
            } catch (Throwable $ex) {
                $conn->rollback();
                throw $ex;
            }
            audit_log('unlink_lead', 'order', $id, 'Unlinked from lead #' . $lead_id);
            order_action_redirect($back, 'success', 'Lead unlinked from this order.');
            break;

        default:
            order_action_redirect($back, 'danger', 'Unknown order action.');
    }
} catch (Throwable $ex) {
    order_action_redirect($back, 'danger', $ex->getMessage());
}
