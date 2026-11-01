<?php
require_once 'header.php';
require_once 'auth_check.php';
require_permission('manage_invoices');
$page_title = 'New Invoice';

$tax_enabled = tax_enabled();
$error = '';

$order_id = (int)($_GET['order_id'] ?? $_POST['order_id'] ?? 0);
if ($order_id > 0 && !can('manage_orders')) {
    http_response_code(403);
    die('You do not have permission to fulfil orders.');
}
$linked_order = null;
$linked_order_items = [];
$linked_order_contact = null;
if ($order_id > 0) {
    $linked_order = fetch_one(
        "SELECT * FROM orders WHERE order_id = ? AND tenant_id = ?",
        $order_id,
        $current_user->tenant_id
    );
    if (!$linked_order || !order_is_visible($linked_order)) {
        flash_set('danger', 'That order was not found or you do not have access to it.');
        redirect('orders.php');
    }
    if ($linked_order['status'] !== 'confirmed') {
        flash_set('danger', 'Only a confirmed order can be fulfilled with a new invoice.');
        redirect('order-view.php?id=' . $order_id);
    }
    if (!empty($linked_order['fulfilled_invoice_id'])) {
        flash_set('danger', 'That order already has a fulfilment invoice.');
        redirect('order-view.php?id=' . $order_id);
    }
    if ((int)$linked_order['contact_id'] <= 0) {
        flash_set('danger', 'Link a customer to the order before creating its fulfilment invoice.');
        redirect('order-view.php?id=' . $order_id);
    }
    if ((int)$linked_order['warehouse_id'] <= 0) {
        flash_set('danger', 'The order has no confirmed store.');
        redirect('order-view.php?id=' . $order_id);
    }
    $linked_order_items = fetch_all(
        "SELECT oi.*, p.is_service, p.is_active product_active, p.name current_name, p.code current_code
           FROM order_items oi
           LEFT JOIN products p ON p.product_id = oi.product_id AND p.tenant_id = oi.tenant_id
          WHERE oi.order_id = ? AND oi.tenant_id = ?
          ORDER BY oi.line_no",
        $order_id,
        $current_user->tenant_id
    );
    if (!$linked_order_items) {
        flash_set('danger', 'That order has no products to invoice.');
        redirect('order-view.php?id=' . $order_id);
    }
    $linked_order_contact = fetch_one(
        "SELECT contact_id, first_name, last_name, company_name
           FROM contacts WHERE contact_id = ? AND tenant_id = ?",
        (int)$linked_order['contact_id'],
        $current_user->tenant_id
    );
}

$shops = accessible_shops(null, true);
$stores = accessible_stores(null, false);
if (!$stores) {
    flash_set('danger', 'No active store is assigned to your account. Ask an administrator to grant shop/store access.');
    redirect('invoices.php');
}
$requested_store_id = $linked_order
    ? (int)$linked_order['warehouse_id']
    : (int)($_POST['warehouse_id'] ?? $_SESSION['invoice_warehouse_id'] ?? 0);
if ($requested_store_id <= 0) {
    $requested_store_id = (int)$stores[0]['warehouse_id'];
}
$selected_store = find_accessible_store($requested_store_id, false);
if (!$selected_store) {
    if ($linked_order) {
        flash_set('danger', 'The order store is no longer active or accessible.');
        redirect('order-view.php?id=' . $order_id);
    }
    $selected_store = $stores[0];
}
$selected_store_id = (int)$selected_store['warehouse_id'];
if (!$linked_order) {
    $_SESSION['invoice_warehouse_id'] = $selected_store_id;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $contact_id = $linked_order
        ? (int)$linked_order['contact_id']
        : (int)($_POST['contact_id'] ?? 0);
$shop_id = $linked_order ? null : (!empty($_POST['shop_id']) ? (int)$_POST['shop_id'] : null);
    $warehouse_id = $linked_order
        ? (int)$linked_order['warehouse_id']
        : 0;
    if (!$linked_order) {
        if ($shop_id) {
            $shop_row = fetch_one("SELECT * FROM shops WHERE shop_id = ? AND tenant_id = ?", $shop_id, $current_user->tenant_id);
            if (!$shop_row || !(int)$shop_row['is_active']) {
                $error = 'The selected shop is not active or does not belong to your tenant.';
                $shop_id = null;
            } elseif (!shop_is_accessible($shop_id, true)) {
                $error = 'You do not have access to the selected shop.';
                $shop_id = null;
            } else {
                $resolved = shop_inventory_warehouse_id($shop_id, (int)$current_user->tenant_id);
                if (!$resolved) {
                    $error = 'The selected shop does not have an active inventory location.';
                    $shop_id = null;
                } else {
                    $loc = find_accessible_store($resolved, false);
                    if (!$loc) {
                        $error = 'The selected shop inventory location is not active or accessible.';
                        $shop_id = null;
                    } else {
                        $warehouse_id = (int)$resolved;
                        $invoice_store = $loc;
                    }
                }
            }
        }
        if ($warehouse_id <= 0) {
            $warehouse_id = (int)($_POST['warehouse_id'] ?? $selected_store_id);
            $invoice_store = find_accessible_store($warehouse_id, false);
        }
    } else {
        $invoice_store = find_accessible_store($warehouse_id, false);
    }
    $issue_date = $_POST['issue_date'] ?? date('Y-m-d');
    $due_date = $_POST['due_date'] ?? '';
    $status_id = (int)($_POST['status_id'] ?? 0);
    $reference = trim($_POST['reference'] ?? '');
    $notes = trim($_POST['notes'] ?? '');
    $terms = trim($_POST['terms'] ?? '');
    $product_ids = $_POST['product_id'] ?? [];
    $quantities = $_POST['quantity'] ?? [];
    $prices = $_POST['price'] ?? [];
    if ($linked_order) {
        $reference = (string)$linked_order['order_number'];
    }

    if (!$invoice_store) {
        $error = 'Please select a valid store.';
    } elseif (!$contact_id) {
        $error = 'Please select a customer.';
    } elseif (!$status_id) {
        $error = 'Please select a status.';
    } else {
        $status = fetch_one("SELECT * FROM document_statuses WHERE status_id = ? AND tenant_id = ? AND type = 'invoice'", $status_id, $current_user->tenant_id);
        if (!$status) {
            $error = 'Invalid status selected.';
        } elseif ($linked_order && stripos((string)$status['name'], 'cancel') !== false) {
            $error = 'A cancelled invoice status cannot be used to fulfil an order.';
        } else {
            // Build line items
            $line_items = [];
            $subtotal = 0;
            $tax_total = 0;
            if ($linked_order) {
                foreach ($linked_order_items as $oi) {
                    $pid = (int)$oi['product_id'];
                    $qty = (float)$oi['quantity'];
                    $price = (float)$oi['unit_price'];
                    if ($pid <= 0 || empty($oi['current_name']) || (int)$oi['product_active'] !== 1) {
                        $error = 'A product on this order was deleted or deactivated. Update the order before invoicing.';
                        break;
                    }
                    if ($qty <= 0) {
                        $error = 'An order line has an invalid quantity.';
                        break;
                    }
                    $product = [
                        'product_id' => $pid,
                        'name'       => $oi['product_name_snapshot'] ?: $oi['current_name'],
                        'is_service' => (int)$oi['is_service'],
                        'tax_rate'   => (float)$oi['tax_rate_snapshot'],
                    ];
                    $line_total = $price * $qty;
                    $line_tax = $tax_enabled ? $line_total * ((float)$oi['tax_rate_snapshot'] / 100) : 0;
                    $subtotal += $line_total;
                    $tax_total += $line_tax;
                    $line_items[] = [
                        'product' => $product,
                        'qty'     => $qty,
                        'price'   => $price,
                        'tax'     => $line_tax,
                        'total'   => $line_total + $line_tax,
                    ];
                }
            } else {
                for ($i = 0; $i < count($product_ids); $i++) {
                    $pid = (int)$product_ids[$i];
                    $qty = (float)($quantities[$i] ?? 0);
                    $price = (float)($prices[$i] ?? 0);
                    if (!$pid || $qty <= 0) continue;
                    $product = fetch_one("SELECT * FROM products WHERE product_id = ? AND tenant_id = ?", $pid, $current_user->tenant_id);
                    if (!$product) continue;
                    $line_total = $price * $qty;
                    $line_tax = $tax_enabled ? $line_total * ((float)$product['tax_rate'] / 100) : 0;
                    $subtotal += $line_total;
                    $tax_total += $line_tax;
                    $line_items[] = [
                        'product' => $product,
                        'qty'     => $qty,
                        'price'   => $price,
                        'tax'     => $line_tax,
                        'total'   => $line_total + $line_tax,
                    ];
                }
            }
            if ($error) {
                // Keep the field-level validation message set above.
            } elseif (!$line_items) {
                $error = 'Add at least one item with a quantity.';
            } else {
                $grand_total = $subtotal + $tax_total;

                // Invoice number
                $settings = fetch_one("SELECT * FROM system_settings WHERE tenant_id = ?", $current_user->tenant_id);
                $prefix = 'INV-';
                $next_no = 1001;
                if ($settings && isset($settings['invoice_prefix'])) $prefix = $settings['invoice_prefix'];
                if ($settings && isset($settings['invoice_next_number'])) $next_no = (int)$settings['invoice_next_number'];
                $invoice_number = $prefix . $next_no;

                $conn->begin_transaction();
                try {
                    if ($linked_order) {
                        $locked_order = fetch_one(
                            "SELECT * FROM orders WHERE order_id = ? AND tenant_id = ? FOR UPDATE",
                            $order_id,
                            $current_user->tenant_id
                        );
                        if (!$locked_order || $locked_order['status'] !== 'confirmed' || !empty($locked_order['fulfilled_invoice_id'])) {
                            throw new Exception('This order is no longer awaiting fulfilment.');
                        }
                        if ((int)$locked_order['warehouse_id'] !== $warehouse_id) {
                            throw new Exception('The selected store does not match the confirmed order store.');
                        }
                    }

                    // Aggregate duplicate product lines before checking stock so
                    // two lines cannot each pass against the same available qty.
                    $line_totals = [];
                    $line_names = [];
                    $non_service_lines = 0;
                    foreach ($line_items as $li) {
                        if (!empty($li['product']['is_service'])) continue;
                        $pid = (int)$li['product']['product_id'];
                        $line_totals[$pid] = ($line_totals[$pid] ?? 0.0) + (float)$li['qty'];
                        $line_names[$pid] = $li['product']['name'];
                        $non_service_lines++;
                    }

                    $active_reservations = [];
                    if ($linked_order) {
                        $active_reservations = order_reservation_rows(
                            $order_id,
                            (int)$current_user->tenant_id,
                            ['active'],
                            true
                        );
                        if ($active_reservations) {
                            $reservation_totals = [];
                            foreach ($active_reservations as $reservation) {
                                $rpid = (int)$reservation['product_id'];
                                $reservation_totals[$rpid] = ($reservation_totals[$rpid] ?? 0.0)
                                    + (float)$reservation['quantity'];
                            }
                            $reservation_mismatch = count($active_reservations) !== $non_service_lines
                                || count($reservation_totals) !== count($line_totals);
                            if (!$reservation_mismatch) {
                                foreach ($line_totals as $pid => $qty) {
                                    if (!array_key_exists($pid, $reservation_totals)
                                        || abs($qty - $reservation_totals[$pid]) > 0.0001) {
                                        $reservation_mismatch = true;
                                        break;
                                    }
                                }
                            }
                            if ($reservation_mismatch) {
                                throw new Exception('This order has an incomplete stock reservation and cannot be fulfilled.');
                            }
                        }
                    }

                    // Stock checks against the selected store, with row locks.
                    // Reservations from other orders reduce what this document
                    // may take. The order being fulfilled may use its own hold.
                    ksort($line_totals);
                    foreach ($line_totals as $pid => $requested_qty) {
                        $stock = fetch_one(
                            "SELECT quantity FROM inventory_stock
                             WHERE product_id = ? AND warehouse_id = ? AND tenant_id = ? FOR UPDATE",
                            $pid, $warehouse_id, $current_user->tenant_id
                        );
                        $reserved = reserved_stock_quantity(
                            (int)$current_user->tenant_id,
                            $pid,
                            (int)$warehouse_id,
                            $linked_order ? $order_id : 0
                        );
                        $on_hand = $stock ? (float)$stock['quantity'] : 0.0;
                        $available = $on_hand - $reserved;
                        if ($available + 0.0001 < $requested_qty) {
                            throw new Exception(
                                'Insufficient available stock for ' . $line_names[$pid]
                                . ' at ' . store_label($invoice_store)
                                . ' (requested: ' . $requested_qty
                                . ', available: ' . max(0, $available)
                                . ', on hand: ' . $on_hand
                                . ', reserved by other orders: ' . $reserved . ').'
                            );
                        }
                    }

                    $stmt = $conn->prepare(
                        "INSERT INTO invoices (tenant_id, invoice_number, warehouse_id, contact_id, issue_date, due_date, status_id, reference, notes, terms, subtotal, tax_amount, total, amount_due, amount_paid, is_paid, created_by)
                         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, 0, ?)"
                    );
                    $due_db = $due_date ?: null;
                    $stmt->bind_param('isiississsddddi',
                        $current_user->tenant_id, $invoice_number, $warehouse_id, $contact_id, $issue_date, $due_db,
                        $status_id, $reference, $notes, $terms, $subtotal, $tax_total, $grand_total, $grand_total,
                        $current_user->user_id
                    );
                    $stmt->execute();
                    $invoice_id = $stmt->insert_id;
                    $stmt->close();

                    foreach ($line_items as $li) {
                        $product = $li['product'];
                        $stmt = $conn->prepare(
                            "INSERT INTO invoice_items (tenant_id, invoice_id, warehouse_id, product_id, description, quantity, unit_price, tax_rate_id, tax_amount, subtotal, total, discount)
                             VALUES (?, ?, ?, ?, ?, ?, ?, NULL, ?, ?, ?, 0)"
                        );
                        $desc = $product['name'];
                        $li_subtotal = $li['price'] * $li['qty'];
                        $stmt->bind_param('iiiisddddd',
                            $current_user->tenant_id, $invoice_id, $warehouse_id, $product['product_id'], $desc, $li['qty'],
                            $li['price'], $li['tax'], $li_subtotal, $li['total']
                        );
                        $stmt->execute();
                        $stmt->close();

                        if (empty($product['is_service'])) {
                            $stmt = $conn->prepare(
                                "UPDATE inventory_stock SET quantity = quantity - ? WHERE product_id = ? AND warehouse_id = ? AND tenant_id = ?"
                            );
                            $stmt->bind_param('diii', $li['qty'], $product['product_id'], $warehouse_id, $current_user->tenant_id);
                            $stmt->execute();
                            $stmt->close();

                            $stmt = $conn->prepare(
                                "INSERT INTO inventory_movements (tenant_id, product_id, warehouse_id, movement_type, quantity, reference_id, reference_type, created_by)
                                 VALUES (?, ?, ?, 'invoice', ?, ?, 'invoice', ?)"
                            );
                            $stmt->bind_param('iiidii', $current_user->tenant_id, $product['product_id'], $warehouse_id, $li['qty'], $invoice_id, $current_user->user_id);
                            $stmt->execute();
                            $stmt->close();
                        }
                    }

                    // Increment invoice counter
                    if ($settings && isset($settings['invoice_next_number'])) {
                        $stmt = $conn->prepare("UPDATE system_settings SET invoice_next_number = invoice_next_number + 1 WHERE tenant_id = ?");
                        $stmt->bind_param('i', $current_user->tenant_id);
                        $stmt->execute();
                        $stmt->close();
                    }

                    if ($linked_order) {
                        if ($active_reservations) {
                            $consumed = consume_order_reservations($order_id, (int)$current_user->tenant_id, $invoice_id);
                            if ($consumed !== count($active_reservations)) {
                                throw new Exception('The order reservation changed while the invoice was being created.');
                            }
                        }

                        $stmt = $conn->prepare(
                            "UPDATE orders SET status = 'fulfilled', fulfilled_by = ?, fulfilled_at = NOW(),
                                fulfilled_invoice_id = ?, fulfilled_invoice_number = ?
                              WHERE order_id = ? AND tenant_id = ? AND status = 'confirmed' AND fulfilled_invoice_id IS NULL"
                        );
                        $stmt->bind_param(
                            'iisii',
                            $current_user->user_id,
                            $invoice_id,
                            $invoice_number,
                            $order_id,
                            $current_user->tenant_id
                        );
                        if (!$stmt->execute()) throw new Exception($stmt->error);
                        if ($stmt->affected_rows !== 1) {
                            throw new Exception('The order changed while the invoice was being created.');
                        }
                        $stmt->close();
                    }

                    $conn->commit();
                    audit_log('create', 'invoice', $invoice_id, 'Invoice ' . $invoice_number . ' created'
                        . ($linked_order ? ' for order ' . $linked_order['order_number'] : ''));
                    if ($linked_order) {
                        audit_log('fulfil', 'order', $order_id, 'Order fulfilled by invoice ' . $invoice_number . '; stock deducted once through invoice');
                        flash_set('success', 'Invoice ' . $invoice_number . ' created and order ' . $linked_order['order_number'] . ' fulfilled. Stock was deducted once and the reservation was consumed.');
                    } else {
                        flash_set('success', 'Invoice ' . $invoice_number . ' created successfully.');
                    }
                    redirect('invoice-details.php?id=' . $invoice_id);
                } catch (Throwable $ex) {
                    $conn->rollback();
                    $error = $ex->getMessage();
                }
            }
        }
    }
}

$customers = fetch_all(
    "SELECT contact_id, first_name, last_name, company_name FROM contacts
     WHERE tenant_id = ? AND is_customer = 1 AND is_active = 1 ORDER BY first_name",
    $current_user->tenant_id
);
$statuses = fetch_all(
    "SELECT * FROM document_statuses WHERE tenant_id = ? AND type = 'invoice' ORDER BY sort_order",
    $current_user->tenant_id
);
$products = fetch_all(
    "SELECT p.*, GREATEST(0, COALESCE((SELECT s.quantity FROM inventory_stock s
                           WHERE s.product_id = p.product_id AND s.warehouse_id = ? AND s.tenant_id = p.tenant_id), 0)
                           - COALESCE((SELECT SUM(r.quantity) FROM order_stock_reservations r
                           WHERE r.tenant_id = p.tenant_id AND r.product_id = p.product_id AND r.warehouse_id = ? AND r.status = 'active'), 0)) total_stock
     FROM products p WHERE p.tenant_id = ? AND p.is_active = 1 ORDER BY p.name",
    $selected_store_id, $selected_store_id, $current_user->tenant_id
);
$preset_customer = $linked_order
    ? (int)$linked_order['contact_id']
    : (int)($_POST['contact_id'] ?? $_GET['customer'] ?? 0);
$order_preview_subtotal = 0.0;
$order_preview_tax = 0.0;
if ($linked_order) {
    foreach ($linked_order_items as $oi) {
        $line_total = (float)$oi['unit_price'] * (float)$oi['quantity'];
        $order_preview_subtotal += $line_total;
        if ($tax_enabled) {
            $order_preview_tax += $line_total * ((float)$oi['tax_rate_snapshot'] / 100);
        }
    }
}

require_once 'navbar.php';
require_once 'sidebar.php';
$cur = tenant_currency();
?>

<div class="page-head">
    <div>
        <h1 class="page-title"><?php echo $linked_order ? 'Fulfil Order' : 'New Invoice'; ?></h1>
        <div class="page-sub">
            <?php if ($linked_order): ?>
                Create the invoice for order <strong><?php echo e($linked_order['order_number']); ?></strong> from <strong><?php echo e(store_label($selected_store)); ?></strong>.
            <?php else: ?>
                Create an invoice for a customer from <strong><?php echo e(store_label($selected_store)); ?></strong>.
            <?php endif; ?>
        </div>
    </div>
    <a href="<?php echo $linked_order ? 'order-view.php?id=' . (int)$order_id : 'invoices.php'; ?>" class="btn btn-light"><i class="fa fa-arrow-left mr-1"></i> <?php echo $linked_order ? 'Back to Order' : 'Back to Invoices'; ?></a>
</div>

<?php if ($linked_order): ?>
    <div class="alert alert-info">
        The invoice lines, quantities, prices, and store are copied from the confirmed order. Creating this invoice deducts physical stock once and consumes the order’s reservation in the same transaction.
    </div>
<?php endif; ?>

<?php if ($error): ?>
    <div class="alert alert-danger"><?php echo e($error); ?></div>
<?php endif; ?>

<div class="row">
    <div class="col-lg-9">
        <div class="card">
            <div class="card-body">
                <form method="POST" action="invoice-add.php" id="invoiceForm">
                    <?php echo csrf_field(); ?>
                    <?php if ($linked_order): ?><input type="hidden" name="order_id" value="<?php echo (int)$order_id; ?>"><?php endif; ?>
<div class="row">
                        <div class="col-md-4 form-group">
                            <label>Shop (optional)</label>
                            <?php if ($linked_order): ?>
                                <div class="form-control-plaintext">Order-linked sale uses the shop and store already saved on the order.</div>
                            <?php else: ?>
                                <select name="shop_id" class="form-control">
                                    <option value="">— No Shop / use selected store —</option>
                                    <?php foreach ($shops as $sh): ?>
                                        <option value="<?php echo (int)$sh['shop_id']; ?>" <?php echo (int)($_POST['shop_id'] ?? 0) === (int)$sh['shop_id'] ? 'selected' : ''; ?>><?php echo e($sh['name']); ?><?php echo (int)$sh['is_active'] !== 1 ? ' (inactive)' : ''; ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <small class="text-muted">If a shop is selected, its own inventory location is used.</small>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-4 form-group">
                            <label>Store <span class="text-danger">*</span></label>
                            <?php if ($linked_order): ?>
                                <input type="hidden" name="warehouse_id" value="<?php echo (int)$selected_store_id; ?>">
                                <div class="form-control-plaintext"><?php echo e(store_label($selected_store)); ?></div>
                                <small class="text-muted">Fixed to the store confirmed on this order. Stock is deducted from this store.</small>
                            <?php else: ?>
                                <select name="warehouse_id" class="form-control" required>
                                    <?php foreach ($stores as $store): ?>
                                        <option value="<?php echo (int)$store['warehouse_id']; ?>" <?php echo (int)$store['warehouse_id'] === $selected_store_id ? 'selected' : ''; ?>><?php echo e(store_label($store)); ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <small class="text-muted">Stock is deducted from this store.</small>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-4 form-group">
                            <label>Customer <span class="text-danger">*</span></label>
                            <?php if ($linked_order): ?>
                                <input type="hidden" name="contact_id" value="<?php echo (int)$linked_order['contact_id']; ?>">
                                <div class="form-control-plaintext">
                                    <?php echo e($linked_order_contact ? (trim(($linked_order_contact['first_name'] ?? '') . ' ' . ($linked_order_contact['last_name'] ?? '')) ?: $linked_order_contact['company_name']) : ('Customer #' . (int)$linked_order['contact_id'])); ?>
                                </div>
                            <?php else: ?>
                                <select name="contact_id" class="form-control" required>
                                    <option value="">— Select Customer —</option>
                                    <?php foreach ($customers as $c): ?>
                                        <option value="<?php echo $c['contact_id']; ?>" <?php echo $preset_customer == $c['contact_id'] ? 'selected' : ''; ?>>
                                            <?php echo e(trim(($c['first_name'] ?? '') . ' ' . ($c['last_name'] ?? '')) ?: $c['company_name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            <?php endif; ?>
                        </div>
                        <div class="col-md-3 form-group">
                            <label>Issue Date <span class="text-danger">*</span></label>
                            <input type="date" name="issue_date" class="form-control" value="<?php echo e($_POST['issue_date'] ?? date('Y-m-d')); ?>" required>
                        </div>
                        <div class="col-md-3 form-group">
                            <label>Due Date</label>
                            <input type="date" name="due_date" class="form-control" value="<?php echo e($_POST['due_date'] ?? ''); ?>">
                        </div>
                        <div class="col-md-2 form-group">
                            <label>Status <span class="text-danger">*</span></label>
                            <select name="status_id" class="form-control" required>
                                <option value="">—</option>
                                <?php foreach ($statuses as $s): ?>
                                    <option value="<?php echo $s['status_id']; ?>" <?php echo (int)($_POST['status_id'] ?? 0) === (int)$s['status_id'] ? 'selected' : ''; ?>><?php echo e($s['name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6 form-group">
                            <label>Reference</label>
                            <input type="text" name="reference" class="form-control" value="<?php echo e($linked_order ? $linked_order['order_number'] : ($_POST['reference'] ?? '')); ?>" <?php echo $linked_order ? 'readonly' : ''; ?>>
                        </div>
                        <div class="col-md-6 form-group">
                            <label>Terms</label>
                            <input type="text" name="terms" class="form-control" value="<?php echo e($_POST['terms'] ?? ''); ?>">
                        </div>
                    </div>

                    <h6 class="mt-2 mb-2">Invoice Items</h6>
                    <?php if ($linked_order): ?>
                        <div class="table-responsive">
                            <table class="table">
                                <thead>
                                    <tr>
                                        <th>Product</th>
                                        <th style="width:110px;" class="text-right">Quantity</th>
                                        <th style="width:140px;" class="text-right">Unit Price</th>
                                        <th style="width:140px;" class="text-right">Tax</th>
                                        <th style="width:150px;" class="text-right">Line Total</th>
                                    </tr>
                                </thead>
                                <tbody>
                                <?php foreach ($linked_order_items as $oi): ?>
                                    <?php
                                    $li_total = (float)$oi['unit_price'] * (float)$oi['quantity'];
                                    $li_tax = $tax_enabled ? $li_total * ((float)$oi['tax_rate_snapshot'] / 100) : 0;
                                    ?>
                                    <tr>
                                        <td>
                                            <span class="font-weight-semibold"><?php echo e($oi['product_name_snapshot']); ?></span>
                                            <small class="text-muted d-block"><?php echo e($oi['product_code_snapshot']); ?></small>
                                        </td>
                                        <td class="text-right"><?php echo e((float)$oi['quantity']); ?></td>
                                        <td class="text-right"><?php echo e(money($oi['unit_price'], $cur)); ?></td>
                                        <td class="text-right"><?php echo e(money($li_tax, $cur)); ?></td>
                                        <td class="text-right font-weight-semibold"><?php echo e(money($li_total + $li_tax, $cur)); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        <div class="d-flex justify-content-end mb-4">
                            <div style="width:320px;">
                                <div class="cart-total-row"><span>Subtotal</span><span><?php echo e(money($order_preview_subtotal, $cur)); ?></span></div>
                                <?php if ($tax_enabled): ?><div class="cart-total-row"><span>Tax</span><span><?php echo e(money($order_preview_tax, $cur)); ?></span></div><?php endif; ?>
                                <div class="cart-total-row cart-grand"><span>Total</span><span><?php echo e(money($order_preview_subtotal + $order_preview_tax, $cur)); ?></span></div>
                            </div>
                        </div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table" id="invItemsTable">
                                <thead>
                                    <tr>
                                        <th>Product</th>
                                        <th style="width:110px;">Quantity</th>
                                        <th style="width:140px;">Unit Price</th>
                                        <th style="width:150px;" class="text-right">Line Total</th>
                                        <th style="width:50px;"></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr>
                                        <td>
                                            <select name="product_id[]" class="form-control inv-product">
                                                <option value="">— Select Product —</option>
                                                <?php foreach ($products as $p): ?>
                                                    <option value="<?php echo $p['product_id']; ?>" data-price="<?php echo e($p['sale_price']); ?>">
                                                        <?php echo e($p['name'] . ' (' . $p['code'] . ')'); ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </td>
                                        <td><input type="number" name="quantity[]" class="form-control inv-qty" min="0" step="1" value="1"></td>
                                        <td><input type="number" name="price[]" class="form-control inv-price" min="0" step="1" value="0"></td>
                                        <td class="inv-total text-right font-weight-semibold">0.00</td>
                                        <td class="text-right"><button type="button" class="btn btn-action btn-light text-danger inv-remove"><i class="fa fa-times"></i></button></td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                        <button type="button" class="btn btn-sm btn-outline-primary mb-3" onclick="addInvoiceRow()"><i class="fa fa-plus mr-1"></i> Add Item</button>

                        <div class="d-flex justify-content-end mb-4">
                            <div style="width:320px;">
                                <div class="cart-total-row"><span>Subtotal</span><span id="invSubtotal">0.00</span></div>
                                <div class="cart-total-row" id="invTaxRow" style="<?php echo $tax_enabled ? '' : 'display:none;'; ?>"><span>Tax</span><span id="invTax">0.00</span></div>
                                <div class="cart-total-row cart-grand"><span>Total</span><span id="invGrand">0.00</span></div>
                            </div>
                        </div>
                    <?php endif; ?>

                    <div class="form-group">
                        <label>Notes</label>
                        <textarea name="notes" class="form-control" rows="2"><?php echo e($_POST['notes'] ?? ''); ?></textarea>
                    </div>
                    <button type="submit" class="btn btn-primary"><i class="fa fa-check mr-1"></i> <?php echo $linked_order ? 'Create Invoice &amp; Fulfil Order' : 'Create Invoice'; ?></button>
                </form>
            </div>
        </div>
    </div>
</div>

<?php if (!$linked_order): ?>
<script>
    var productData = <?php echo json_encode(array_map(fn($p) => [
        'id' => (int)$p['product_id'],
        'price' => (float)$p['sale_price'],
        'tax' => (float)$p['tax_rate'],
        'stock' => (float)$p['total_stock'],
        'service' => (int)$p['is_service'],
    ], $products)); ?>;
    var taxEnabled = <?php echo $tax_enabled ? '1' : '0'; ?>;

    function recalcTotals() {
        var subtotal = 0, tax = 0;
        $('#invItemsTable tbody tr').each(function () {
            var price = parseFloat($(this).find('.inv-price').val()) || 0;
            var qty = parseFloat($(this).find('.inv-qty').val()) || 0;
            var pid = $(this).find('.inv-product').val();
            var info = productData.find(p => p.id == pid);
            var t = price * qty;
            var taxRate = info && taxEnabled ? info.tax : 0;
            var lineTax = t * (taxRate / 100);
            subtotal += t;
            tax += lineTax;
            $(this).find('.inv-total').text((t + lineTax).toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2}));
        });
        $('#invSubtotal').text(subtotal.toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2}));
        $('#invTax').text(tax.toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2}));
        $('#invGrand').text((subtotal + tax).toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2}));
    }

    function addInvoiceRow() {
        var first = $('#invItemsTable tbody tr').first();
        var clone = first.clone();
        clone.find('.inv-product').val('');
        clone.find('.inv-qty').val('1');
        clone.find('.inv-price').val('0');
        clone.find('.inv-total').text('0.00');
        $('#invItemsTable tbody').append(clone);
    }

    $(function () {
        $('#invItemsTable').on('change', '.inv-product', function () {
            var info = productData.find(p => p.id == $(this).val());
            if (info) {
                $(this).closest('tr').find('.inv-price').val(info.price.toFixed(2));
            }
            recalcTotals();
        });
        $('#invItemsTable').on('input', '.inv-qty, .inv-price', recalcTotals);
        $('#invItemsTable').on('click', '.inv-remove', function () {
            if ($('#invItemsTable tbody tr').length > 1) {
                $(this).closest('tr').remove();
                recalcTotals();
            }
        });
    });
</script>
<?php endif; ?>

<?php require_once 'footer.php'; ?>
