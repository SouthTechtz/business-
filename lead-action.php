<?php
require_once 'header.php';
require_once 'auth_check.php';
require_permission('manage_leads');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('leads.php');
}
verify_csrf();

$id = (int)($_POST['lead_id'] ?? 0);
$action = (string)($_POST['action'] ?? '');
$statuses = lead_statuses();

$lead = fetch_one(
    "SELECT * FROM leads WHERE lead_id = ? AND tenant_id = ?",
    $id,
    $current_user->tenant_id
);
if (!$lead) {
    flash_set('danger', 'Lead not found.');
    redirect('leads.php');
}

$back = 'lead-view.php?id=' . $id;

function lead_action_redirect($back, $type, $message) {
    flash_set($type, $message);
    redirect($back);
}

try {
    switch ($action) {
        case 'add_activity':
            $allowed_types = ['call', 'whatsapp', 'email', 'sms', 'meeting', 'note'];
            $type = (string)($_POST['activity_type'] ?? 'note');
            if (!in_array($type, $allowed_types, true)) {
                lead_action_redirect($back, 'danger', 'Choose a valid follow-up type.');
            }
            $subject = trim((string)($_POST['subject'] ?? ''));
            $notes = trim((string)($_POST['notes'] ?? ''));
            $outcome = trim((string)($_POST['outcome'] ?? ''));
            $next = trim((string)($_POST['next_follow_up_at'] ?? ''));

            if ($subject === '' && $notes === '') {
                lead_action_redirect($back, 'danger', 'Enter a subject or notes for the follow-up.');
            }
            if (mb_strlen($subject) > 191 || mb_strlen($outcome) > 100) {
                lead_action_redirect($back, 'danger', 'The subject or outcome is too long.');
            }
            if ($next !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $next)) {
                lead_action_redirect($back, 'danger', 'Next follow-up date is not valid.');
            }

            $is_contact = in_array($type, ['call', 'whatsapp', 'email', 'sms', 'meeting'], true);
            $conn->begin_transaction();
            try {
                record_lead_activity(
                    $id,
                    (int)$current_user->tenant_id,
                    $type,
                    $subject !== '' ? $subject : null,
                    $notes !== '' ? $notes : null,
                    $outcome !== '' ? $outcome : null,
                    null,
                    $next !== '' ? $next : null
                );
                if ($is_contact) {
                    $stmt = $conn->prepare(
                        "UPDATE leads SET last_contacted_at = NOW(),
                                status = CASE WHEN status = 'new' THEN 'contacted' ELSE status END
                          WHERE lead_id = ? AND tenant_id = ?"
                    );
                    $stmt->bind_param('ii', $id, $current_user->tenant_id);
                    if (!$stmt->execute()) throw new RuntimeException($stmt->error);
                    $stmt->close();
                }
                if ($next !== '') {
                    $stmt = $conn->prepare(
                        "UPDATE leads SET next_follow_up_at = ? WHERE lead_id = ? AND tenant_id = ?"
                    );
                    $stmt->bind_param('sii', $next, $id, $current_user->tenant_id);
                    if (!$stmt->execute()) throw new RuntimeException($stmt->error);
                    $stmt->close();
                }
                $conn->commit();
            } catch (Throwable $ex) {
                $conn->rollback();
                throw $ex;
            }
            audit_log('add_activity', 'lead', $id, 'Recorded ' . activity_type_label($type) . ' follow-up');
            lead_action_redirect($back, 'success', 'Follow-up recorded.');
            break;

        case 'assign':
            $assigned_to = (int)($_POST['assigned_to'] ?? 0);
            if ($assigned_to > 0 && !user_has_tenant_permission($assigned_to, 'manage_leads', (int)$current_user->tenant_id)) {
                lead_action_redirect($back, 'danger', 'The selected staff member cannot be assigned leads.');
            }
            $current_assignee = (int)($lead['assigned_to'] ?? 0);
            if ($current_assignee === $assigned_to) {
                lead_action_redirect($back, 'info', 'The lead is already assigned to that staff member.');
            }
            $assignee_name = 'Unassigned';
            if ($assigned_to > 0) {
                $assignee = fetch_one(
                    "SELECT first_name, last_name, username FROM users WHERE user_id = ? AND tenant_id = ?",
                    $assigned_to,
                    $current_user->tenant_id
                );
                $assignee_name = trim(($assignee['first_name'] ?? '') . ' ' . ($assignee['last_name'] ?? '')) ?: ($assignee['username'] ?? ('User #' . $assigned_to));
            }

            $conn->begin_transaction();
            try {
                $stmt = $conn->prepare(
                    "UPDATE leads SET assigned_to = ? WHERE lead_id = ? AND tenant_id = ?"
                );
                $assigned_db = $assigned_to ?: null;
                $stmt->bind_param('iii', $assigned_db, $id, $current_user->tenant_id);
                if (!$stmt->execute()) throw new RuntimeException($stmt->error);
                $stmt->close();
                record_lead_activity(
                    $id,
                    (int)$current_user->tenant_id,
                    'assignment',
                    'Assigned to ' . $assignee_name
                );
                $conn->commit();
            } catch (Throwable $ex) {
                $conn->rollback();
                throw $ex;
            }
            audit_log('assign', 'lead', $id, 'Assigned to ' . $assignee_name);
            lead_action_redirect($back, 'success', 'Lead assigned to ' . $assignee_name . '.');
            break;

        case 'verify':
            // Verification is an administrative approval step before an order
            // is created. Keep it separate from general lead follow-up work.
            if (!tenant_admin_scope()) {
                http_response_code(403);
                die('Only a system administrator can verify leads.');
            }
            if ($lead['source'] === 'storefront' && fetch_one(
                "SELECT request_id FROM storefront_lead_requests WHERE lead_id = ? AND tenant_id = ?",
                $id,
                (int)$current_user->tenant_id
            )) {
                flash_set('info', 'Review this order request and select its shop and store before verification.');
                redirect('lead-verify.php?id=' . $id);
            }
            if (in_array($lead['status'], ['verified', 'converted', 'lost'], true)) {
                lead_action_redirect($back, 'info', 'This lead cannot be verified again.');
            }

            $conn->begin_transaction();
            try {
                $stmt = $conn->prepare(
                    "UPDATE leads SET status = 'verified'
                      WHERE lead_id = ? AND tenant_id = ?
                        AND status NOT IN ('verified', 'converted', 'lost')"
                );
                $stmt->bind_param('ii', $id, $current_user->tenant_id);
                if (!$stmt->execute()) throw new RuntimeException($stmt->error);
                if ($stmt->affected_rows !== 1) {
                    throw new RuntimeException('This lead was changed by someone else. Reload and try again.');
                }
                $stmt->close();
                record_lead_activity(
                    $id,
                    (int)$current_user->tenant_id,
                    'status_change',
                    'Verified by administrator',
                    'Status: ' . ($statuses[$lead['status']] ?? $lead['status']) . ' â†’ Verified'
                );
                $conn->commit();
            } catch (Throwable $ex) {
                $conn->rollback();
                throw $ex;
            }
            audit_log('verify', 'lead', $id, 'Lead verified by administrator');
            lead_action_redirect($back, 'success', 'Lead verified. It is ready to be converted into an Order.');
            break;

        case 'delete':
            $back = 'leads.php';
            if (!in_array((string)$lead['status'], ['new', 'contacted', 'qualified'], true)) {
                lead_action_redirect($back, 'danger', 'Only unverified leads can be deleted.');
            }

            $conn->begin_transaction();
            try {
                $locked_lead = fetch_one(
                    "SELECT * FROM leads WHERE lead_id = ? AND tenant_id = ? FOR UPDATE",
                    $id,
                    (int)$current_user->tenant_id
                );
                if (!$locked_lead) {
                    throw new RuntimeException('This lead no longer exists.');
                }
                if (!in_array((string)$locked_lead['status'], ['new', 'contacted', 'qualified'], true)) {
                    throw new RuntimeException('Only unverified leads can be deleted.');
                }

                $request = fetch_one(
                    "SELECT * FROM storefront_lead_requests WHERE lead_id = ? AND tenant_id = ? FOR UPDATE",
                    $id,
                    (int)$current_user->tenant_id
                );
                if ($request && !empty($request['converted_order_id'])) {
                    throw new RuntimeException('This lead has a converted order request and cannot be deleted.');
                }

                $linked_order = fetch_one(
                    "SELECT order_id FROM orders WHERE lead_id = ? AND tenant_id = ? LIMIT 1 FOR UPDATE",
                    $id,
                    (int)$current_user->tenant_id
                );
                if ($linked_order) {
                    throw new RuntimeException('This lead is linked to an order and cannot be deleted.');
                }

                if ($request) {
                    $stmt = $conn->prepare(
                        "DELETE FROM storefront_lead_request_items WHERE request_id = ? AND tenant_id = ?"
                    );
                    $stmt->bind_param('ii', $request['request_id'], $current_user->tenant_id);
                    if (!$stmt->execute()) throw new RuntimeException($stmt->error);
                    $stmt->close();

                    $stmt = $conn->prepare(
                        "DELETE FROM storefront_lead_requests WHERE request_id = ? AND tenant_id = ?"
                    );
                    $stmt->bind_param('ii', $request['request_id'], $current_user->tenant_id);
                    if (!$stmt->execute()) throw new RuntimeException($stmt->error);
                    $stmt->close();
                }

                $stmt = $conn->prepare(
                    "DELETE FROM activities WHERE lead_id = ? AND tenant_id = ?"
                );
                $stmt->bind_param('ii', $id, $current_user->tenant_id);
                if (!$stmt->execute()) throw new RuntimeException($stmt->error);
                $stmt->close();

                $stmt = $conn->prepare(
                    "DELETE FROM leads WHERE lead_id = ? AND tenant_id = ?"
                );
                $stmt->bind_param('ii', $id, $current_user->tenant_id);
                if (!$stmt->execute()) throw new RuntimeException($stmt->error);
                if ($stmt->affected_rows !== 1) throw new RuntimeException('This lead was changed by someone else.');
                $stmt->close();

                $conn->commit();
            } catch (Throwable $ex) {
                $conn->rollback();
                throw $ex;
            }

            audit_log('delete', 'lead', $id, 'Unverified lead deleted: ' . $lead['lead_number']);
            lead_action_redirect($back, 'success', 'Unverified lead deleted.');
            break;

        case 'verify_request':
            if (!tenant_admin_scope() || !can('manage_orders')) {
                http_response_code(403);
                die('Only an authorized system administrator can verify order requests.');
            }
            if ($lead['source'] !== 'storefront' || in_array($lead['status'], ['lost'], true)) {
                lead_action_redirect($back, 'danger', 'Only an active storefront order request can be verified.');
            }
            $shop_id = (int)($_POST['shop_id'] ?? 0);
            $warehouse_id = (int)($_POST['warehouse_id'] ?? 0);
            $back = 'lead-verify.php?id=' . $id . '&shop_id=' . $shop_id . '&warehouse_id=' . $warehouse_id;
            if ($shop_id <= 0 || !shop_is_accessible($shop_id, false)) {
                lead_action_redirect($back, 'danger', 'Select an active shop you are authorized to manage.');
            }
            // Store is chosen automatically from the confirmed shop; the user does not pick it.
            if ($warehouse_id <= 0) {
                $auto_stores = accessible_stores($shop_id, false);
                if ($auto_stores) {
                    $warehouse_id = (int)$auto_stores[0]['warehouse_id'];
                }
            }
            $store = find_accessible_store($warehouse_id, false);
            if (!$store || (int)$store['shop_id'] !== $shop_id) {
                lead_action_redirect($back, 'danger', 'No active store is available for the selected shop.');
            }

            $reserved_lines = 0;
            $order_number = '';
            $conn->begin_transaction();
            try {
                $locked_lead = fetch_one(
                    "SELECT * FROM leads WHERE lead_id = ? AND tenant_id = ? FOR UPDATE",
                    $id,
                    (int)$current_user->tenant_id
                );
                $request = fetch_one(
                    "SELECT * FROM storefront_lead_requests WHERE lead_id = ? AND tenant_id = ? FOR UPDATE",
                    $id,
                    (int)$current_user->tenant_id
                );
                if (!$locked_lead || !$request || $locked_lead['status'] === 'lost') {
                    throw new RuntimeException('This order request is no longer available for verification.');
                }
                $valid_location = fetch_one(
                    "SELECT s.shop_id, w.warehouse_id
                       FROM shops s
                       JOIN warehouses w ON w.shop_id = s.shop_id AND w.tenant_id = s.tenant_id
                      WHERE s.tenant_id = ? AND s.shop_id = ? AND w.warehouse_id = ?
                        AND s.is_active = 1 AND w.is_active = 1
                      FOR UPDATE",
                    (int)$current_user->tenant_id,
                    $shop_id,
                    $warehouse_id
                );
                if (!$valid_location) {
                    throw new RuntimeException('The selected shop or store is no longer active or does not belong to this tenant.');
                }

                $order_id = (int)($request['converted_order_id'] ?? 0);
                $order = null;
                if ($order_id > 0) {
                    $order = fetch_one(
                        "SELECT * FROM orders WHERE order_id = ? AND tenant_id = ? FOR UPDATE",
                        $order_id,
                        (int)$current_user->tenant_id
                    );
                    if (!$order || (int)$order['lead_id'] !== $id) {
                        throw new RuntimeException('The existing order link is invalid and needs administrator review.');
                    }
                } else {
                    $existing_orders = fetch_all(
                        "SELECT * FROM orders WHERE lead_id = ? AND tenant_id = ? FOR UPDATE",
                        $id,
                        (int)$current_user->tenant_id
                    );
                    if (count($existing_orders) > 1) {
                        throw new RuntimeException('Multiple orders are linked to this lead. Resolve the duplicate links before verification.');
                    }
                    if ($existing_orders) {
                        $order = $existing_orders[0];
                        $order_id = (int)$order['order_id'];
                        if (($order['source'] ?? '') !== 'storefront') {
                            throw new RuntimeException('The linked order is not a storefront order and needs administrator review.');
                        }
                        $stmt = $conn->prepare(
                            "UPDATE storefront_lead_requests SET converted_order_id = ?, converted_at = COALESCE(converted_at, NOW())
                              WHERE request_id = ? AND tenant_id = ? AND converted_order_id IS NULL"
                        );
                        $stmt->bind_param('iii', $order_id, $request['request_id'], $current_user->tenant_id);
                        if (!$stmt->execute()) throw new RuntimeException($stmt->error);
                        if ($stmt->affected_rows !== 1) throw new RuntimeException('Could not restore the request-to-order link.');
                        $stmt->close();
                    }
                }

                if ($order) {
                    if (($order['source'] ?? '') !== 'storefront') {
                        throw new RuntimeException('The existing order is not a storefront order and needs administrator review.');
                    }
                    if (in_array($order['status'], ['confirmed', 'fulfilled'], true)
                        && ((int)$order['shop_id'] !== $shop_id || (int)$order['warehouse_id'] !== $warehouse_id)) {
                        throw new RuntimeException('The existing confirmed order is assigned to a different shop or store.');
                    }
                    if (in_array($order['status'], ['rejected', 'cancelled', 'fulfilled'], true)) {
                        throw new RuntimeException('The linked order is already ' . $order['status'] . '. It will not be duplicated or reopened.');
                    }
                    if (in_array($order['status'], ['new', 'under_review'], true)) {
                        $stmt = $conn->prepare(
                            "UPDATE orders SET status = 'under_review', shop_id = ?, warehouse_id = ?
                              WHERE order_id = ? AND tenant_id = ? AND status IN ('new', 'under_review')"
                        );
                        $stmt->bind_param('iiii', $shop_id, $warehouse_id, $order_id, $current_user->tenant_id);
                        if (!$stmt->execute()) throw new RuntimeException($stmt->error);
                        $stmt->close();
                    }
                    $order_number = (string)$order['order_number'];
                } else {
                    $items = fetch_all(
                        "SELECT * FROM storefront_lead_request_items
                          WHERE request_id = ? AND tenant_id = ? ORDER BY line_no",
                        (int)$request['request_id'],
                        (int)$current_user->tenant_id
                    );
                    if (!$items) throw new RuntimeException('This request has no products to convert.');

                    $order_number = next_document_number($current_user->tenant_id, 'order', 'ORD');
                    $contact_id = $locked_lead['contact_id'] ? (int)$locked_lead['contact_id'] : null;
                    $stmt = $conn->prepare(
                        "INSERT INTO orders (tenant_id, order_number, contact_id, lead_id, customer_name,
                            customer_phone, customer_location, customer_email, source, status, currency_code,
                            subtotal, total, shop_id, warehouse_id, created_by)
                         VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'storefront', 'under_review', ?, ?, ?, ?, ?, ?)"
                    );
                    $stmt->bind_param('isiisssssddiii', $current_user->tenant_id, $order_number, $contact_id, $id,
                        $locked_lead['contact_name'], $locked_lead['phone'], $locked_lead['location'], $locked_lead['email'],
                        $request['currency_code'], $request['subtotal'], $request['subtotal'], $shop_id, $warehouse_id, $current_user->user_id);
                    if (!$stmt->execute()) throw new RuntimeException($stmt->error);
                    $order_id = (int)$stmt->insert_id;
                    $stmt->close();

                    $stmt = $conn->prepare(
                        "INSERT INTO order_items (tenant_id, order_id, line_no, product_id, product_code_snapshot,
                            product_name_snapshot, product_description_snapshot, unit_label_snapshot, quantity,
                            unit_price, tax_rate_snapshot, line_total)
                         SELECT tenant_id, ?, line_no, product_id, product_code_snapshot, product_name_snapshot,
                            product_description_snapshot, unit_label_snapshot, quantity, unit_price,
                            tax_rate_snapshot, line_total
                         FROM storefront_lead_request_items WHERE request_id = ? AND tenant_id = ?"
                    );
                    $stmt->bind_param('iii', $order_id, $request['request_id'], $current_user->tenant_id);
                    if (!$stmt->execute() || $stmt->affected_rows !== count($items)) {
                        throw new RuntimeException('Could not copy every requested product into the order.');
                    }
                    $stmt->close();
                    $stmt = $conn->prepare(
                        "UPDATE storefront_lead_requests SET converted_order_id = ?, converted_at = NOW()
                          WHERE request_id = ? AND tenant_id = ? AND converted_order_id IS NULL"
                    );
                    $stmt->bind_param('iii', $order_id, $request['request_id'], $current_user->tenant_id);
                    if (!$stmt->execute() || $stmt->affected_rows !== 1) throw new RuntimeException('This request was already converted.');
                    $stmt->close();
                }

                $reserved_lines = reserve_order_stock(
                    $order_id,
                    (int)$current_user->tenant_id,
                    $shop_id,
                    $warehouse_id,
                    (int)$current_user->user_id,
                    'selected shop has low stock for now'
                );

                $stmt = $conn->prepare(
                    "UPDATE leads SET status = 'confirmed', converted_at = NOW(), lost_reason = NULL
                      WHERE lead_id = ? AND tenant_id = ? AND status <> 'lost'"
                );
                $stmt->bind_param('ii', $id, $current_user->tenant_id);
                if (!$stmt->execute()) throw new RuntimeException($stmt->error);
                if ($stmt->affected_rows !== 1 && $locked_lead['status'] !== 'converted') {
                    throw new RuntimeException('This lead changed while verification was being completed.');
                }
                $stmt->close();
                record_lead_activity($id, (int)$current_user->tenant_id, 'status_change',
                    'Verified and converted to Order ' . $order_number,
                    (int)$reserved_lines . ' stock line(s) reserved at the selected store; physical stock is deducted only when the order is fulfilled.',
                    null,
                    $order_id
                );
                $conn->commit();
            } catch (Throwable $ex) {
                $conn->rollback();
                throw $ex;
            }
            audit_log(
                'verify_request',
                'lead',
                $id,
                'Verified and converted to Order ' . $order_number . '; ' . (int)$reserved_lines . ' stock line(s) reserved'
            );
            $reservation_message = (int)$reserved_lines > 0
                ? (int)$reserved_lines . ' stock line(s) are reserved at the selected store. Physical stock will be deducted when the order is fulfilled.'
                : 'No physical stock reservation was required.';
            flash_set('success', 'Request verified and converted to Order ' . $order_number . '. ' . $reservation_message);
            redirect('order-view.php?id=' . $order_id);
            break;

        case 'set_status':
            $target = (string)($_POST['status'] ?? '');
            if (!isset($statuses[$target])) {
                lead_action_redirect($back, 'danger', 'Choose a valid lead status.');
            }
            $allowed = lead_allowed_transitions((string)$lead['status']);
            if (!in_array($target, $allowed, true)) {
                lead_action_redirect($back, 'danger', 'That status change is not allowed from ' . $statuses[$lead['status']] . '.');
            }
            $storefront_request_exists = $lead['source'] === 'storefront'
                ? fetch_one(
                    "SELECT request_id FROM storefront_lead_requests WHERE lead_id = ? AND tenant_id = ?",
                    $id,
                    (int)$current_user->tenant_id
                )
                : null;
            if ($storefront_request_exists && in_array($target, ['verified', 'converted'], true)) {
                lead_action_redirect(
                    'lead-verify.php?id=' . $id,
                    'danger',
                    'Storefront requests must be verified from the request page so the responsible shop, store, and stock can be validated.'
                );
            }
            $lost_reason = trim((string)($_POST['lost_reason'] ?? ''));
            if ($target === 'lost' && mb_strlen($lost_reason) < 3) {
                lead_action_redirect($back, 'danger', 'Enter a reason of at least 3 characters when marking a lead lost.');
            }

            $conn->begin_transaction();
            try {
                $activity_order_id = null;
                $activity_notes = null;
                if ($target === 'converted') {
                    if ($storefront_request_exists) {
                        $request = fetch_one(
                            "SELECT * FROM storefront_lead_requests
                              WHERE lead_id = ? AND tenant_id = ? FOR UPDATE",
                            $id,
                            (int)$current_user->tenant_id
                        );
                        if (!$request) {
                            throw new RuntimeException('The storefront request could not be found.');
                        }

                        if (!empty($request['converted_order_id'])) {
                            $activity_order_id = (int)$request['converted_order_id'];
                        } else {
                            $request_items = fetch_all(
                                "SELECT * FROM storefront_lead_request_items
                                  WHERE request_id = ? AND tenant_id = ? ORDER BY line_no",
                                (int)$request['request_id'],
                                (int)$current_user->tenant_id
                            );
                            if (!$request_items) {
                                throw new RuntimeException('The storefront request has no items to convert.');
                            }

                            $order_number = next_document_number($current_user->tenant_id, 'order', 'ORD');
                            $contact_id = $lead['contact_id'] ? (int)$lead['contact_id'] : null;
                            $stmt = $conn->prepare(
                                "INSERT INTO orders
                                    (tenant_id, order_number, contact_id, lead_id, customer_name,
                                     customer_phone, customer_location, source, status, currency_code,
                                     subtotal, total, created_by)
                                 VALUES (?, ?, ?, ?, ?, ?, ?, 'storefront', 'new', ?, ?, ?, ?)"
                            );
                            if (!$stmt) throw new RuntimeException($conn->error);
                            $stmt->bind_param(
                                'isiissssddi',
                                $current_user->tenant_id,
                                $order_number,
                                $contact_id,
                                $id,
                                $lead['contact_name'],
                                $lead['phone'],
                                $lead['location'],
                                $request['currency_code'],
                                $request['subtotal'],
                                $request['subtotal'],
                                $current_user->user_id
                            );
                            if (!$stmt->execute()) throw new RuntimeException($stmt->error);
                            $activity_order_id = (int)$stmt->insert_id;
                            $stmt->close();

                            $stmt = $conn->prepare(
                                "INSERT INTO order_items
                                    (tenant_id, order_id, line_no, product_id, product_code_snapshot,
                                     product_name_snapshot, product_description_snapshot, unit_label_snapshot,
                                     quantity, unit_price, tax_rate_snapshot, line_total)
                                 SELECT tenant_id, ?, line_no, product_id, product_code_snapshot,
                                        product_name_snapshot, product_description_snapshot, unit_label_snapshot,
                                        quantity, unit_price, tax_rate_snapshot, line_total
                                   FROM storefront_lead_request_items
                                  WHERE request_id = ? AND tenant_id = ?"
                            );
                            if (!$stmt) throw new RuntimeException($conn->error);
                            $stmt->bind_param('iii', $activity_order_id, $request['request_id'], $current_user->tenant_id);
                            if (!$stmt->execute()) throw new RuntimeException($stmt->error);
                            if ($stmt->affected_rows !== count($request_items)) {
                                throw new RuntimeException('Could not copy all request items into the Order.');
                            }
                            $stmt->close();

                            $stmt = $conn->prepare(
                                "UPDATE storefront_lead_requests
                                    SET converted_order_id = ?, converted_at = NOW()
                                  WHERE request_id = ? AND tenant_id = ? AND converted_order_id IS NULL"
                            );
                            if (!$stmt) throw new RuntimeException($conn->error);
                            $stmt->bind_param('iii', $activity_order_id, $request['request_id'], $current_user->tenant_id);
                            if (!$stmt->execute()) throw new RuntimeException($stmt->error);
                            if ($stmt->affected_rows !== 1) {
                                throw new RuntimeException('This storefront request was already converted.');
                            }
                            $stmt->close();
                            $activity_notes = 'Storefront request confirmed as Order ' . $order_number . '.';
                        }
                    }

                    $stmt = $conn->prepare(
                        "UPDATE leads SET status = 'confirmed', converted_at = NOW(), lost_reason = NULL
                          WHERE lead_id = ? AND tenant_id = ? AND status = ?"
                    );
                    $stmt->bind_param('iis', $id, $current_user->tenant_id, $lead['status']);
                } elseif ($target === 'lost') {
                    $stmt = $conn->prepare(
                        "UPDATE leads SET status = 'cancelled', lost_reason = ?
                          WHERE lead_id = ? AND tenant_id = ? AND status = ?"
                    );
                    $stmt->bind_param('siis', $lost_reason, $id, $current_user->tenant_id, $lead['status']);
                } elseif ($target === 'new') {
                    $stmt = $conn->prepare(
                        "UPDATE leads SET status = 'new', lost_reason = NULL, converted_at = NULL
                          WHERE lead_id = ? AND tenant_id = ? AND status = ?"
                    );
                    $stmt->bind_param('iis', $id, $current_user->tenant_id, $lead['status']);
                } else {
                    $stmt = $conn->prepare(
                        "UPDATE leads SET status = ?
                          WHERE lead_id = ? AND tenant_id = ? AND status = ?"
                    );
                    $stmt->bind_param('siis', $target, $id, $current_user->tenant_id, $lead['status']);
                }
                if (!$stmt->execute()) throw new RuntimeException($stmt->error);
                if ($stmt->affected_rows !== 1) throw new RuntimeException('This lead was changed by someone else. Reload and try again.');
                $stmt->close();

                record_lead_activity(
                    $id,
                    (int)$current_user->tenant_id,
                    'status_change',
                    'Status: ' . $statuses[$lead['status']] . ' â†’ ' . $statuses[$target],
                    $target === 'lost' ? $lost_reason : $activity_notes,
                    null,
                    $activity_order_id
                );
                $conn->commit();
            } catch (Throwable $ex) {
                $conn->rollback();
                throw $ex;
            }
            audit_log('set_status', 'lead', $id, 'Status changed to ' . $target . ($activity_order_id ? '; Order #' . $activity_order_id . ' created' : ''));
            lead_action_redirect(
                $back,
                'success',
                $activity_order_id && $storefront_request_exists
                    ? 'Request confirmed and Order created.'
                    : 'Lead status updated to ' . $statuses[$target] . '.'
            );
            break;

        case 'link_order':
        case 'unlink_order':
            if (!can('manage_orders')) {
                http_response_code(403);
                die('You do not have permission to manage order links.');
            }
            $order_id = (int)($_POST['order_id'] ?? 0);
            $order = fetch_one(
                "SELECT * FROM orders WHERE order_id = ? AND tenant_id = ?",
                $order_id,
                $current_user->tenant_id
            );
            if (!$order || !order_is_visible($order)) {
                lead_action_redirect($back, 'danger', 'That order is not available to you.');
            }

            $conn->begin_transaction();
            try {
                if ($action === 'link_order') {
                    if (!empty($order['lead_id']) && (int)$order['lead_id'] !== $id) {
                        throw new RuntimeException('That order is already linked to another lead.');
                    }
                    $stmt = $conn->prepare(
                        "UPDATE orders SET lead_id = ?
                          WHERE order_id = ? AND tenant_id = ? AND (lead_id IS NULL OR lead_id = ?)"
                    );
                    $stmt->bind_param('iiii', $id, $order_id, $current_user->tenant_id, $id);
                    if (!$stmt->execute()) throw new RuntimeException($stmt->error);
                    $stmt->close();
                    record_lead_activity(
                        $id,
                        (int)$current_user->tenant_id,
                        'order_link',
                        'Order ' . $order['order_number'] . ' linked to lead',
                        null,
                        null,
                        $order_id
                    );
                    $message = 'Order ' . $order['order_number'] . ' linked.';
                } else {
                    if ((int)($order['lead_id'] ?? 0) !== $id) {
                        throw new RuntimeException('That order is not linked to this lead.');
                    }
                    $stmt = $conn->prepare(
                        "UPDATE orders SET lead_id = NULL
                          WHERE order_id = ? AND tenant_id = ? AND lead_id = ?"
                    );
                    $stmt->bind_param('iii', $order_id, $current_user->tenant_id, $id);
                    if (!$stmt->execute()) throw new RuntimeException($stmt->error);
                    $stmt->close();
                    record_lead_activity(
                        $id,
                        (int)$current_user->tenant_id,
                        'order_link',
                        'Order ' . $order['order_number'] . ' unlinked from lead',
                        null,
                        null,
                        $order_id
                    );
                    $message = 'Order ' . $order['order_number'] . ' unlinked.';
                }
                $conn->commit();
            } catch (Throwable $ex) {
                $conn->rollback();
                throw $ex;
            }
            audit_log($action, 'lead', $id, $message);
            lead_action_redirect($back, 'success', $message);
            break;

        default:
            lead_action_redirect($back, 'danger', 'Unknown lead action.');
    }
} catch (Throwable $ex) {
    lead_action_redirect($back, 'danger', $ex->getMessage());
}

