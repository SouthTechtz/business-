<?php
require_once 'header.php';
require_once 'auth_check.php';
require_permission('manage_invoices');
$page_title = 'New Quotation';

$tenant_id = (int)$current_user->tenant_id;
$tax_enabled = tax_enabled();
$error = '';

$stores = accessible_stores(null, false);
if (!$stores) {
    flash_set('danger', 'No active store is assigned to your account. Ask an administrator to grant shop/store access.');
    redirect('quotations.php');
}
$requested_store_id = (int)($_POST['warehouse_id'] ?? $_SESSION['quotation_warehouse_id'] ?? 0);
if ($requested_store_id <= 0) {
    $requested_store_id = (int)$stores[0]['warehouse_id'];
}
$selected_store = find_accessible_store($requested_store_id, false);
if (!$selected_store) {
    $selected_store = $stores[0];
}
$selected_store_id = (int)$selected_store['warehouse_id'];
$_SESSION['quotation_warehouse_id'] = $selected_store_id;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    if (!form_token_valid()) {
        flash_set('danger', 'This form was already submitted. Refresh the page before trying again.');
        redirect('quotation-add.php');
    }

    $contact_id = (int)($_POST['contact_id'] ?? 0);
    $warehouse_id = (int)($_POST['warehouse_id'] ?? 0);
    $quote_store = find_accessible_store($warehouse_id, false);
    $issue_date = trim((string)($_POST['issue_date'] ?? ''));
    $valid_until = trim((string)($_POST['valid_until'] ?? ''));
    $status = (string)($_POST['status'] ?? 'draft');
    $reference = trim((string)($_POST['reference'] ?? ''));
    $notes = trim((string)($_POST['notes'] ?? ''));
    $terms = trim((string)($_POST['terms'] ?? ''));
    $product_ids = $_POST['product_id'] ?? [];
    $quantities = $_POST['quantity'] ?? [];
    $prices = $_POST['price'] ?? [];

    $allowed_statuses = array_diff(array_keys(quotation_statuses()), ['converted']);
    $date_ok = function (string $value): bool {
        if ($value === '') return false;
        $date = DateTime::createFromFormat('Y-m-d', $value);
        return $date && $date->format('Y-m-d') === $value;
    };

    if (!$quote_store) {
        $error = 'Please select a valid store.';
    } elseif (!$contact_id) {
        $error = 'Please select a customer.';
    } elseif (!$date_ok($issue_date)) {
        $error = 'Please enter a valid issue date.';
    } elseif ($valid_until !== '' && !$date_ok($valid_until)) {
        $error = 'Please enter a valid valid-until date.';
    } elseif ($valid_until !== '' && $valid_until < $issue_date) {
        $error = 'The valid-until date cannot be before the issue date.';
    } elseif (!in_array($status, $allowed_statuses, true)) {
        $error = 'Please select a valid quotation status.';
    } else {
        $customer = fetch_one(
            "SELECT contact_id FROM contacts WHERE contact_id = ? AND tenant_id = ? AND is_customer = 1 AND is_active = 1",
            $contact_id,
            $tenant_id
        );
        if (!$customer) {
            $error = 'Please select an active customer.';
        }
    }

    $line_items = [];
    if (!$error) {
        foreach ($product_ids as $index => $pid) {
            $pid = (int)$pid;
            $qty = (float)($quantities[$index] ?? 0);
            $price = (float)($prices[$index] ?? 0);
            if ($pid <= 0 || $qty <= 0) continue;
            $product = fetch_one(
                "SELECT * FROM products WHERE product_id = ? AND tenant_id = ? AND is_active = 1",
                $pid,
                $tenant_id
            );
            if (!$product) {
                $error = 'One of the selected products is no longer available.';
                break;
            }
            $line_total = $price * $qty;
            $line_tax = $tax_enabled ? $line_total * ((float)$product['tax_rate'] / 100) : 0;
            $line_items[] = [
                'product' => $product,
                'qty'     => $qty,
                'price'   => $price,
                'tax'     => $line_tax,
                'total'   => $line_total + $line_tax,
                'subtotal' => $line_total,
            ];
        }
        if (!$error && !$line_items) {
            $error = 'Add at least one item with a quantity.';
        }
    }

    if (!$error) {
        $subtotal = 0.0;
        $tax_total = 0.0;
        foreach ($line_items as $li) {
            $subtotal += $li['subtotal'];
            $tax_total += $li['tax'];
        }
        $grand_total = $subtotal + $tax_total;

        $conn->begin_transaction();
        try {
            $quotation_number = next_document_number($tenant_id, 'quotation', 'QUO');

            $stmt = $conn->prepare(
                "INSERT INTO quotations
                    (tenant_id, quotation_number, warehouse_id, contact_id, issue_date, valid_until, status,
                     reference, notes, terms, subtotal, tax_amount, total, created_by)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
            );
            $valid_until_db = $valid_until !== '' ? $valid_until : null;
            $stmt->bind_param(
                'isiissssssdddi',
                $tenant_id,
                $quotation_number,
                $warehouse_id,
                $contact_id,
                $issue_date,
                $valid_until_db,
                $status,
                $reference,
                $notes,
                $terms,
                $subtotal,
                $tax_total,
                $grand_total,
                $current_user->user_id
            );
            $stmt->execute();
            $quotation_id = (int)$stmt->insert_id;
            $stmt->close();

            $stmt = $conn->prepare(
                "INSERT INTO quotation_items
                    (tenant_id, quotation_id, warehouse_id, product_id, description, quantity, unit_price, tax_amount, subtotal, total)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
            );
            foreach ($line_items as $li) {
                $pid = (int)$li['product']['product_id'];
                $description = (string)$li['product']['name'];
                $qty = (float)$li['qty'];
                $price = (float)$li['price'];
                $line_tax = (float)$li['tax'];
                $line_subtotal = (float)$li['subtotal'];
                $line_total = (float)$li['total'];
                $stmt->bind_param(
                    'iiiisddddd',
                    $tenant_id,
                    $quotation_id,
                    $warehouse_id,
                    $pid,
                    $description,
                    $qty,
                    $price,
                    $line_tax,
                    $line_subtotal,
                    $line_total
                );
                $stmt->execute();
            }
            $stmt->close();

            $conn->commit();
            consume_form_token();
            audit_log('create', 'quotation', $quotation_id, 'Quotation ' . $quotation_number . ' created');
            flash_set('success', 'Quotation ' . $quotation_number . ' created.');
            redirect('quotation-details.php?id=' . $quotation_id);
        } catch (Throwable $ex) {
            $conn->rollback();
            $error = 'Could not create the quotation: ' . $ex->getMessage();
        }
    }
}

$customers = fetch_all(
    "SELECT contact_id, first_name, last_name, company_name FROM contacts
      WHERE tenant_id = ? AND is_customer = 1 AND is_active = 1 ORDER BY first_name, company_name",
    $tenant_id
);
$products = fetch_all(
    "SELECT * FROM products WHERE tenant_id = ? AND is_active = 1 ORDER BY name",
    $tenant_id
);
$statuses = array_diff_key(quotation_statuses(), ['converted' => true]);

require_once 'navbar.php';
require_once 'sidebar.php';
$cur = tenant_currency();
?>

<div class="page-head">
    <div>
        <h1 class="page-title">New Quotation</h1>
        <div class="page-sub">Prepare a priced quotation. No stock is reserved or deducted until it is converted into an invoice.</div>
    </div>
    <a href="quotations.php" class="btn btn-light"><i class="fa fa-arrow-left mr-1"></i> Back to Quotations</a>
</div>

<?php if ($error): ?>
    <div class="alert alert-danger"><?php echo e($error); ?></div>
<?php endif; ?>

<div class="row">
    <div class="col-lg-10">
        <div class="card">
            <div class="card-body">
                <form method="POST" action="quotation-add.php" id="quotationForm">
                    <?php echo csrf_field(); ?>
                    <?php echo form_token_field(); ?>
                    <div class="row">
                        <div class="col-md-4 form-group">
                            <label>Store <span class="text-danger">*</span></label>
                            <select name="warehouse_id" class="form-control" required>
                                <?php foreach ($stores as $store): ?>
                                    <option value="<?php echo (int)$store['warehouse_id']; ?>" <?php echo (int)$store['warehouse_id'] === $selected_store_id ? 'selected' : ''; ?>><?php echo e(store_label($store)); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <small class="text-muted">Prices are quoted for this store. Stock is checked only when converting.</small>
                        </div>
                        <div class="col-md-4 form-group">
                            <label>Customer <span class="text-danger">*</span></label>
                            <select name="contact_id" class="form-control" required>
                                <option value="">— Select Customer —</option>
                                <?php foreach ($customers as $c): ?>
                                    <option value="<?php echo (int)$c['contact_id']; ?>" <?php echo (int)($_POST['contact_id'] ?? 0) === (int)$c['contact_id'] ? 'selected' : ''; ?>>
                                        <?php echo e(trim(($c['first_name'] ?? '') . ' ' . ($c['last_name'] ?? '')) ?: $c['company_name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4 form-group">
                            <label>Status <span class="text-danger">*</span></label>
                            <select name="status" class="form-control" required>
                                <?php foreach ($statuses as $key => $label): ?>
                                    <option value="<?php echo e($key); ?>" <?php echo (string)($_POST['status'] ?? 'draft') === $key ? 'selected' : ''; ?>><?php echo e($label); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-3 form-group">
                            <label>Issue Date <span class="text-danger">*</span></label>
                            <input type="date" name="issue_date" class="form-control" value="<?php echo e($_POST['issue_date'] ?? date('Y-m-d')); ?>" required>
                        </div>
                        <div class="col-md-3 form-group">
                            <label>Valid Until</label>
                            <input type="date" name="valid_until" class="form-control" value="<?php echo e($_POST['valid_until'] ?? date('Y-m-d', strtotime('+30 days'))); ?>">
                        </div>
                        <div class="col-md-3 form-group">
                            <label>Reference</label>
                            <input type="text" name="reference" class="form-control" value="<?php echo e($_POST['reference'] ?? ''); ?>">
                        </div>
                        <div class="col-md-3 form-group">
                            <label>Terms</label>
                            <input type="text" name="terms" class="form-control" value="<?php echo e($_POST['terms'] ?? ''); ?>" placeholder="e.g. 50% deposit">
                        </div>
                    </div>

                    <h6 class="mt-2 mb-2">Quotation Items</h6>
                    <div class="table-responsive">
                        <table class="table" id="quoteItemsTable">
                            <thead>
                                <tr>
                                    <th>Product</th>
                                    <th style="width:110px;">Quantity</th>
                                    <th style="width:150px;">Unit Price</th>
                                    <th style="width:150px;" class="text-right">Line Total</th>
                                    <th style="width:50px;"></th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr>
                                    <td>
                                        <select name="product_id[]" class="form-control quote-product">
                                            <option value="">— Select Product —</option>
                                            <?php foreach ($products as $p): ?>
                                                <option value="<?php echo (int)$p['product_id']; ?>" data-price="<?php echo e($p['sale_price']); ?>">
                                                    <?php echo e($p['name'] . ' (' . $p['code'] . ')'); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </td>
                                    <td><input type="number" name="quantity[]" class="form-control quote-qty" min="0" step="0.01" value="1"></td>
                                    <td><input type="number" name="price[]" class="form-control quote-price" min="0" step="0.01" value="0"></td>
                                    <td class="quote-total text-right font-weight-semibold">0.00</td>
                                    <td class="text-right"><button type="button" class="btn btn-action btn-light text-danger quote-remove"><i class="fa fa-times"></i></button></td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                    <button type="button" class="btn btn-sm btn-outline-primary mb-3" onclick="addQuoteRow()"><i class="fa fa-plus mr-1"></i> Add Item</button>

                    <div class="d-flex justify-content-end mb-4">
                        <div style="width:340px;">
                            <div class="cart-total-row"><span>Subtotal</span><span id="quoteSubtotal">0.00</span></div>
                            <div class="cart-total-row" id="quoteTaxRow" style="<?php echo $tax_enabled ? '' : 'display:none;'; ?>"><span>Tax</span><span id="quoteTax">0.00</span></div>
                            <div class="cart-total-row cart-grand"><span>Total</span><span id="quoteGrand">0.00</span></div>
                        </div>
                    </div>

                    <div class="form-group">
                        <label>Notes</label>
                        <textarea name="notes" class="form-control" rows="2"><?php echo e($_POST['notes'] ?? ''); ?></textarea>
                    </div>
                    <button type="submit" class="btn btn-primary"><i class="fa fa-check mr-1"></i> Create Quotation</button>
                </form>
            </div>
        </div>
    </div>
</div>

<script>
    var quoteProducts = <?php echo json_encode(array_map(fn($p) => [
        'id' => (int)$p['product_id'],
        'price' => (float)$p['sale_price'],
        'tax' => (float)$p['tax_rate'],
    ], $products)); ?>;
    var quoteTaxEnabled = <?php echo $tax_enabled ? '1' : '0'; ?>;

    function quoteRecalc() {
        var subtotal = 0, tax = 0;
        $('#quoteItemsTable tbody tr').each(function () {
            var price = parseFloat($(this).find('.quote-price').val()) || 0;
            var qty = parseFloat($(this).find('.quote-qty').val()) || 0;
            var pid = $(this).find('.quote-product').val();
            var info = quoteProducts.find(p => p.id == pid);
            var line = price * qty;
            var rate = info && quoteTaxEnabled ? info.tax : 0;
            var lineTax = line * (rate / 100);
            subtotal += line;
            tax += lineTax;
            $(this).find('.quote-total').text((line + lineTax).toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2}));
        });
        $('#quoteSubtotal').text(subtotal.toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2}));
        $('#quoteTax').text(tax.toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2}));
        $('#quoteGrand').text((subtotal + tax).toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2}));
    }

    function addQuoteRow() {
        var clone = $('#quoteItemsTable tbody tr').first().clone();
        clone.find('.quote-product').val('');
        clone.find('.quote-qty').val('1');
        clone.find('.quote-price').val('0');
        clone.find('.quote-total').text('0.00');
        $('#quoteItemsTable tbody').append(clone);
    }

    $(function () {
        $('#quoteItemsTable').on('change', '.quote-product', function () {
            var info = quoteProducts.find(p => p.id == $(this).val());
            if (info) {
                $(this).closest('tr').find('.quote-price').val(info.price.toFixed(2));
            }
            quoteRecalc();
        });
        $('#quoteItemsTable').on('input', '.quote-qty, .quote-price', quoteRecalc);
        $('#quoteItemsTable').on('click', '.quote-remove', function () {
            if ($('#quoteItemsTable tbody tr').length > 1) {
                $(this).closest('tr').remove();
                quoteRecalc();
            }
        });
    });
</script>

<?php require_once 'footer.php'; ?>
