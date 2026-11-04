<?php
/**
 * Create an invoice from a completed POS sale.
 *
 * POS sales already deduct stock, so this only records the accounting
 * document. Walk-in sales are fully paid by rule, so their invoice is paid.
 *
 * @return array{invoice_id:int,invoice_number:string,existing:bool}
 */
function create_invoice_from_pos_sale($tenant_id, $order_id, $created_by)
{
    global $conn;

    $tenant_id = (int)$tenant_id;
    $order_id = (int)$order_id;
    $created_by = (int)$created_by;

    $sale = fetch_one(
        "SELECT o.*, c.first_name, c.last_name, c.company_name
           FROM pos_orders o
           LEFT JOIN contacts c ON c.contact_id = o.contact_id
          WHERE o.order_id = ? AND o.tenant_id = ?",
        $order_id,
        $tenant_id
    );
    if (!$sale) throw new RuntimeException('Sale not found.');
    if ($sale['status'] !== 'completed') {
        throw new RuntimeException('Only completed sales can be invoiced.');
    }

    $existing = fetch_one(
        "SELECT invoice_id, invoice_number FROM invoices WHERE tenant_id = ? AND reference = ? LIMIT 1",
        $tenant_id,
        $sale['order_number']
    );
    if ($existing) {
        return [
            'invoice_id' => (int)$existing['invoice_id'],
            'invoice_number' => (string)$existing['invoice_number'],
            'existing' => true,
        ];
    }

    $items = fetch_all("SELECT * FROM pos_order_items WHERE order_id = ? ORDER BY order_item_id", $order_id);
    if (!$items) throw new RuntimeException('This sale has no items to invoice.');

    $paid_row = fetch_one("SELECT COALESCE(SUM(amount), 0) paid FROM pos_payments WHERE order_id = ?", $order_id) ?: [];
    $amount_paid = round((float)($paid_row['paid'] ?? 0), 2);
    $total = round((float)$sale['total'], 2);
    $amount_due = max(0, round($total - $amount_paid, 2));
    $is_paid = $amount_due <= 0.005 ? 1 : 0;

    $settings = fetch_one("SELECT * FROM system_settings WHERE tenant_id = ?", $tenant_id);
    $prefix = $settings && !empty($settings['invoice_prefix']) ? (string)$settings['invoice_prefix'] : 'INV-';
    $next_no = $settings && isset($settings['invoice_next_number']) ? (int)$settings['invoice_next_number'] : 1001;
    $invoice_number = $prefix . $next_no;
    while (fetch_one(
        "SELECT invoice_id FROM invoices WHERE tenant_id = ? AND invoice_number = ? LIMIT 1",
        $tenant_id,
        $invoice_number
    )) {
        $next_no++;
        $invoice_number = $prefix . $next_no;
    }

    $statuses = fetch_all(
        "SELECT status_id, name FROM document_statuses WHERE tenant_id = ? AND type = 'invoice' ORDER BY status_id",
        $tenant_id
    );
    $status_id = null;
    if ($is_paid) {
        foreach ($statuses as $status_row) {
            if (strtolower(trim((string)$status_row['name'])) === 'paid') {
                $status_id = (int)$status_row['status_id'];
                break;
            }
        }
        if ($status_id === null) {
            foreach ($statuses as $status_row) {
                $name = strtolower((string)$status_row['name']);
                if (strpos($name, 'paid') !== false && strpos($name, 'unpaid') === false && strpos($name, 'partial') === false) {
                    $status_id = (int)$status_row['status_id'];
                    break;
                }
            }
        }
    } else {
        foreach ($statuses as $status_row) {
            $name = strtolower((string)$status_row['name']);
            if (strpos($name, 'unpaid') !== false) {
                $status_id = (int)$status_row['status_id'];
                break;
            }
            if ($status_id === null && strpos($name, 'partial') !== false) {
                $status_id = (int)$status_row['status_id'];
            }
        }
    }
    if ($status_id === null && $statuses) $status_id = (int)$statuses[0]['status_id'];

    $reference = (string)$sale['order_number'];
    $notes = 'Invoice generated automatically from POS sale ' . $reference . '.';
    $warehouse_id = (int)($sale['warehouse_id'] ?? 0) ?: null;
    $subtotal = round((float)$sale['subtotal'], 2);
    $tax_amount = round((float)$sale['tax_amount'], 2);
    $issue_date = date('Y-m-d');

    $conn->begin_transaction();
    try {
        $contact_id = (int)($sale['contact_id'] ?? 0);
        if ($contact_id <= 0) {
            $walkin = fetch_one(
                "SELECT contact_id FROM contacts WHERE tenant_id = ? AND is_customer = 1 AND company_name = 'Walk-in Customer' LIMIT 1",
                $tenant_id
            );
            if ($walkin) {
                $contact_id = (int)$walkin['contact_id'];
            } else {
                $customer_number = next_document_number($tenant_id, 'customer', 'CUS');
                $stmt = $conn->prepare(
                    "INSERT INTO contacts (tenant_id, contact_type_id, customer_type, customer_channel, customer_number,
                                           first_name, last_name, company_name, is_customer, is_active)
                     VALUES (?, 1, 'retail', 'walk_in', ?, 'Walk-in', 'Customer', 'Walk-in Customer', 1, 1)"
                );
                if (!$stmt) throw new RuntimeException($conn->error);
                $stmt->bind_param('is', $tenant_id, $customer_number);
                $stmt->execute();
                $contact_id = (int)$stmt->insert_id;
                $stmt->close();
            }
        }

        $stmt = $conn->prepare(
            "INSERT INTO invoices
                (tenant_id, invoice_number, warehouse_id, contact_id, issue_date, due_date, status_id, reference,
                 notes, terms, subtotal, tax_amount, total, amount_due, amount_paid, is_paid, created_by)
             VALUES (?, ?, ?, ?, ?, NULL, ?, ?, ?, NULL, ?, ?, ?, ?, ?, ?, ?)"
        );
        if (!$stmt) throw new RuntimeException($conn->error);
        $stmt->bind_param(
            'isiisissdddddii',
            $tenant_id,
            $invoice_number,
            $warehouse_id,
            $contact_id,
            $issue_date,
            $status_id,
            $reference,
            $notes,
            $subtotal,
            $tax_amount,
            $total,
            $amount_due,
            $amount_paid,
            $is_paid,
            $created_by
        );
        if (!$stmt->execute()) throw new RuntimeException($stmt->error);
        $invoice_id = (int)$stmt->insert_id;
        $stmt->close();

        $stmt = $conn->prepare(
            "INSERT INTO invoice_items
                (tenant_id, invoice_id, warehouse_id, product_id, description, quantity, unit_price,
                 tax_rate_id, tax_amount, subtotal, total, discount)
             VALUES (?, ?, ?, ?, ?, ?, ?, NULL, ?, ?, ?, 0)"
        );
        if (!$stmt) throw new RuntimeException($conn->error);
        foreach ($items as $item) {
            $item_warehouse = (int)($item['warehouse_id'] ?? 0) ?: $warehouse_id;
            $product_id = (int)($item['product_id'] ?? 0) ?: null;
            $description = (string)($item['description'] ?? '');
            $quantity = (float)$item['quantity'];
            $unit_price = (float)$item['unit_price'];
            $item_tax = (float)$item['tax_amount'];
            $item_subtotal = (float)$item['subtotal'];
            $item_total = (float)$item['total'];
            $stmt->bind_param(
                'iiiisddddd',
                $tenant_id,
                $invoice_id,
                $item_warehouse,
                $product_id,
                $description,
                $quantity,
                $unit_price,
                $item_tax,
                $item_subtotal,
                $item_total
            );
            if (!$stmt->execute()) throw new RuntimeException($stmt->error);
        }
        $stmt->close();

        if ($settings) {
            $stmt = $conn->prepare("UPDATE system_settings SET invoice_next_number = ? WHERE tenant_id = ?");
            $next_counter = $next_no + 1;
            $stmt->bind_param('ii', $next_counter, $tenant_id);
            $stmt->execute();
            $stmt->close();
        }

        $conn->commit();
        return ['invoice_id' => $invoice_id, 'invoice_number' => $invoice_number, 'existing' => false];
    } catch (Throwable $exception) {
        $conn->rollback();
        throw $exception;
    }
}
