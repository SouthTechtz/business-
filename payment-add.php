<?php
require_once 'header.php';
require_once 'auth_check.php';
require_permission('manage_receipts');
$page_title = 'Record Payment';

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $invoice_id = (int)($_POST['invoice_id'] ?? 0);
    $amount = (float)($_POST['amount'] ?? 0);
    $payment_date = $_POST['payment_date'] ?? date('Y-m-d H:i:s');
    $method_id = (int)($_POST['payment_method_id'] ?? 0);
    $submission_token = trim((string)($_POST['submission_token'] ?? ''));

    if ($submission_token === '') {
        $error = 'Missing payment submission token. Reload and try again.';
    } else {
    $conn->begin_transaction();
    try {
        $locked_invoice = fetch_one(
            "SELECT * FROM invoices WHERE invoice_id = ? AND tenant_id = ? FOR UPDATE",
            $invoice_id,
            $current_user->tenant_id
        );
        if (!$locked_invoice) {
            throw new Exception('Invoice not found.');
        }
        if ((int)($locked_invoice['warehouse_id'] ?? 0) > 0 && !store_is_accessible((int)$locked_invoice['warehouse_id'], true)) {
            throw new Exception('You do not have access to the store for this invoice.');
        }
        $status_name = '';
        if ((int)($locked_invoice['status_id'] ?? 0) > 0) {
            $st = fetch_one("SELECT name FROM document_statuses WHERE status_id = ? AND tenant_id = ?", (int)$locked_invoice['status_id'], $current_user->tenant_id);
            $status_name = $st ? (string)$st['name'] : '';
        }
        if (strcasecmp($status_name, 'Cancelled') === 0 || (float)($locked_invoice['returned_amount'] ?? 0) + 0.0001 >= (float)$locked_invoice['total']) {
            throw new Exception('Payments cannot be recorded against a cancelled or fully returned invoice.');
        }
        if ($amount <= 0) {
            throw new Exception('Payment amount must be greater than zero.');
        }
        if ($amount > (float)$locked_invoice['amount_due'] + 0.0001) {
            throw new Exception('Payment cannot exceed the balance due (' . money($locked_invoice['amount_due'], tenant_currency()) . ').');
        }

        $stmt = $conn->prepare(
            "INSERT INTO payments (tenant_id, invoice_id, contact_id, amount, payment_date, payment_method_id, created_by, submission_token)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)"
        );
        $method_db = $method_id ?: null;
        $stmt->bind_param('iiidssis', $current_user->tenant_id, $invoice_id, $locked_invoice['contact_id'], $amount, $payment_date, $method_db, $current_user->user_id, $submission_token);
        if (!$stmt->execute()) {
            throw new Exception($stmt->error);
        }
        $payment_id = $stmt->insert_id;
        $stmt->close();

        $new_paid = (float)$locked_invoice['amount_paid'] + $amount;
        $new_due = max(0, (float)$locked_invoice['total'] - (float)($locked_invoice['returned_amount'] ?? 0) - $new_paid);
        // amount_due should reflect original total less returns and payments:
        $new_due = max(0, (float)$locked_invoice['total'] - (float)($locked_invoice['returned_amount'] ?? 0) - $new_paid);
        $refund_due = max(0, $new_paid - ((float)$locked_invoice['total'] - (float)($locked_invoice['returned_amount'] ?? 0)));
        $is_paid = $new_due <= 0.0001 ? 1 : 0;

        $stmt = $conn->prepare("UPDATE invoices SET amount_paid = ?, amount_due = ?, is_paid = ?, refund_due = ?, version = version + 1, updated_at = NOW() WHERE invoice_id = ? AND tenant_id = ?");
        $stmt->bind_param('ddidii', $new_paid, $new_due, $is_paid, $refund_due, $invoice_id, $current_user->tenant_id);
        $stmt->execute();
        $stmt->close();

        // Update status to Paid / Partially Paid when relevant
        $status_map = fetch_all(
            "SELECT status_id, name FROM document_statuses WHERE tenant_id = ? AND type = 'invoice'",
            $current_user->tenant_id
        );
        $paid_status = null;
        $partial_status = null;
        foreach ($status_map as $s) {
            if ($s['name'] === 'Paid') $paid_status = (int)$s['status_id'];
            if ($s['name'] === 'Partially Paid') $partial_status = (int)$s['status_id'];
        }
        if ($new_due <= 0.0001 && $paid_status) {
            $stmt = $conn->prepare("UPDATE invoices SET status_id = ? WHERE invoice_id = ? AND tenant_id = ?");
            $stmt->bind_param('iii', $paid_status, $invoice_id, $current_user->tenant_id);
            $stmt->execute();
            $stmt->close();
        } elseif ($new_due > 0.0001 && $partial_status && strcasecmp($status_name, 'Paid') !== 0) {
            $stmt = $conn->prepare("UPDATE invoices SET status_id = ? WHERE invoice_id = ? AND tenant_id = ?");
            $stmt->bind_param('iii', $partial_status, $invoice_id, $current_user->tenant_id);
            $stmt->execute();
            $stmt->close();
        }

        $conn->commit();
        $invoice_number = $locked_invoice['invoice_number'];
        audit_log('create', 'payment', $payment_id, 'Payment of ' . money($amount, tenant_currency()) . ' for invoice ' . $invoice_number);
        flash_set('success', 'Payment recorded successfully.');
        redirect('receipts.php');
    } catch (Throwable $ex) {
        $conn->rollback();
        $error = 'Payment failed: ' . $ex->getMessage();
    }
    }
}

$preset = (int)($_GET['invoice'] ?? 0);
$invoice_sql = "SELECT i.invoice_id, i.invoice_number, i.total, i.amount_due, c.first_name, c.last_name, c.company_name
     FROM invoices i
     LEFT JOIN contacts c ON c.contact_id = i.contact_id
     WHERE i.tenant_id = ? AND i.amount_due > 0";
$invoice_params = [$current_user->tenant_id];
$scope_ids = accessible_warehouse_ids();
if ($scope_ids !== null) {
    if (!$scope_ids) {
        $invoice_sql .= " AND 1 = 0";
    } else {
        $invoice_sql .= " AND i.warehouse_id IN (" . implode(',', array_map('intval', $scope_ids)) . ")";
    }
}
$invoice_sql .= " ORDER BY i.issue_date DESC";
$invoices = fetch_all($invoice_sql, ...$invoice_params);
$methods = fetch_all("SELECT * FROM payment_methods WHERE tenant_id = ?", $current_user->tenant_id);
$payment_token = bin2hex(random_bytes(16));

require_once 'navbar.php';
require_once 'sidebar.php';
$cur = tenant_currency();
?>

<div class="page-head">
    <div>
        <h1 class="page-title">Record Payment</h1>
        <div class="page-sub">Receive a payment against an invoice.</div>
    </div>
    <a href="receipts.php" class="btn btn-light"><i class="fa fa-arrow-left mr-1"></i> Back to Payments</a>
</div>

<?php if ($error): ?>
    <div class="alert alert-danger"><?php echo e($error); ?></div>
<?php endif; ?>

<div class="row">
    <div class="col-lg-6">
        <div class="card">
            <div class="card-body">
                <?php if (!$invoices): ?>
                    <div class="empty-state">
                        <i class="fa fa-check-circle"></i>
                        <p>All invoices are fully paid — no payments needed right now.</p>
                    </div>
                <?php else: ?>
                <form method="POST" action="payment-add.php">
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="submission_token" value="<?php echo e($payment_token); ?>">
                    <div class="form-group">
                        <label>Invoice <span class="text-danger">*</span></label>
                        <select name="invoice_id" class="form-control" required id="payInvoice" onchange="updateBalance()">
                            <option value="">— Select Invoice —</option>
                            <?php foreach ($invoices as $inv): ?>
                                <option value="<?php echo $inv['invoice_id']; ?>" data-due="<?php echo e((float)$inv['amount_due']); ?>" <?php echo $preset == $inv['invoice_id'] ? 'selected' : ''; ?>>
                                    <?php echo e($inv['invoice_number'] . ' — ' . (trim(($inv['first_name'] ?? '') . ' ' . ($inv['last_name'] ?? '')) ?: $inv['company_name'] ?: 'Walk-in Customer') . ' — Due: ' . money($inv['amount_due'], $cur)); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Balance Due</label>
                        <div class="form-control bg-light font-weight-semibold" id="balanceDisplay">—</div>
                    </div>
                    <div class="form-group">
                        <label>Amount <span class="text-danger">*</span></label>
                        <input type="number" name="amount" class="form-control" step="1" min="1" required id="payAmount">
                    </div>
                    <div class="form-group">
                        <label>Payment Date</label>
                        <input type="datetime-local" name="payment_date" class="form-control" value="<?php echo e(date('Y-m-d\TH:i')); ?>">
                    </div>
                    <div class="form-group">
                        <label>Payment Method</label>
                        <select name="payment_method_id" class="form-control">
                            <option value="">— Select —</option>
                            <?php foreach ($methods as $m): ?>
                                <option value="<?php echo $m['payment_method_id']; ?>"><?php echo e($m['name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <button type="submit" class="btn btn-success"><i class="fa fa-check mr-1"></i> Record Payment</button>
                </form>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<script>
    function updateBalance() {
        var opt = $('#payInvoice option:selected');
        $('#balanceDisplay').text('<?php echo e($cur); ?> ' + (opt.data('due') || 0).toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2}));
        $('#payAmount').attr('max', opt.data('due') || 0);
    }
    $(function () { updateBalance(); });
</script>

<?php require_once 'footer.php'; ?>
