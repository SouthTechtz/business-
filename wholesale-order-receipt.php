<?php
require_once __DIR__ . '/wholesale_auth.php';
require_once __DIR__ . '/pdf-helpers.php';

$tenant_id = (int)$wholesale_user->tenant_id;
$contact_id = (int)($wholesaler['contact_id'] ?? 0);
$order_id = (int)($_GET['id'] ?? 0);
$download = isset($_GET['download']) && (string)$_GET['download'] === '1';

$order = $contact_id > 0 && $order_id > 0
    ? wholesale_order($tenant_id, $order_id, $contact_id)
    : null;

if (!$order) {
    http_response_code(404);
    $page_title = 'Receipt not found';
    require __DIR__ . '/wholesale-portal-header.php';
    ?>
    <div class="ws-empty ws-panel" style="margin-top:10px;">
        <i class="fa fa-search" aria-hidden="true"></i>
        <strong>Receipt not found</strong>
        <p>This order does not exist or is not linked to your account.</p>
        <a class="ws-btn ws-btn-primary" href="wholesale-orders.php">Back to my orders</a>
    </div>
    <?php
    require __DIR__ . '/wholesale-portal-footer.php';
    exit;
}

if (!wholesale_order_is_paid($order)) {
    flash_set('warning', 'A receipt is available once the order has been fully paid.');
    $request_lookup = fetch_one(
        "SELECT request_id FROM storefront_lead_requests
          WHERE converted_order_id = ? AND tenant_id = ?
          ORDER BY request_id LIMIT 1",
        $order_id,
        $tenant_id
    );
    redirect(!empty($request_lookup['request_id'])
        ? 'wholesale-order-view.php?id=' . (int)$request_lookup['request_id']
        : 'wholesale-orders.php');
}

$items = fetch_all(
    "SELECT * FROM order_items
      WHERE tenant_id = ? AND order_id = ?
      ORDER BY line_no ASC, order_item_id ASC",
    $tenant_id,
    $order_id
);
$settings = fetch_one("SELECT * FROM system_settings WHERE tenant_id = ? LIMIT 1", $tenant_id) ?: [];
$company_name = trim((string)($settings['company_name'] ?? '')) ?: system_title($tenant_id);
$company_address = trim((string)($settings['company_address'] ?? ''));
$company_phone = trim((string)($settings['company_phone'] ?? ''));
$company_email = trim((string)($settings['company_email'] ?? ''));
$company_logo = system_logo_path($tenant_id);
$currency = (string)($order['currency_code'] ?? '') ?: tenant_currency();
$receipt_number = 'RCPT-' . preg_replace('/[^A-Za-z0-9-]+/', '-', (string)$order['order_number']);
$paid_at = !empty($order['updated_at']) ? $order['updated_at'] : (!empty($order['created_at']) ? $order['created_at'] : date('Y-m-d H:i:s'));
$customer_name = trim((string)($wholesaler['business_name'] ?? '')) ?: trim((string)($order['customer_name'] ?? ''));
$customer_contact = trim((string)($wholesaler['contact_name'] ?? ''));
$customer_phone = trim((string)($wholesaler['phone'] ?? ($order['customer_phone'] ?? '')));
$customer_email = trim((string)($wholesaler['email'] ?? ($order['customer_email'] ?? '')));
$customer_location = trim((string)($wholesaler['location'] ?? ($order['customer_location'] ?? '')));

if ($download) {
    $chunks = array_chunk($items, 20);
    if (!$chunks) $chunks = [[]];
    $last_page_index = count($chunks) - 1;
    $pages = [];
    $logo = pdf_logo_data($tenant_id);
    $header_x = $logo['data'] !== null ? 140 : 50;

    foreach ($chunks as $page_index => $chunk) {
        $c = '';

        if ($page_index === 0) {
            if ($logo['data'] !== null) {
                $c .= pdf_image(50, 28, $logo['draw_w'], $logo['draw_h']);
            }
            $c .= pdf_text($header_x, 55, $company_name, 16, true);
            if ($company_address !== '') $c .= pdf_text($header_x, 72, $company_address, 9);
            $contact_line = trim($company_email . '  ' . $company_phone);
            if ($contact_line !== '') $c .= pdf_text($header_x, 86, $contact_line, 9);
            $c .= pdf_text(545, 55, 'PAYMENT RECEIPT', 17, true, 'right');
            $c .= pdf_text(545, 78, $receipt_number, 12, true, 'right');
            $c .= pdf_text(545, 94, 'Order: ' . $order['order_number'], 9, false, 'right');
            $c .= pdf_text(545, 107, 'Date: ' . date('M d, Y H:i', strtotime($paid_at)), 9, false, 'right');
            $c .= pdf_text(545, 120, 'Status: PAID IN FULL', 9, false, 'right');
            $c .= pdf_line(50, 132, 545);

            $c .= pdf_text(50, 152, 'RECEIVED FROM', 9, true);
            $c .= pdf_text(50, 168, $customer_name, 11, true);
            if ($customer_contact !== '') $c .= pdf_text(50, 182, $customer_contact, 9);
            if ($customer_email !== '') $c .= pdf_text(50, 195, $customer_email, 9);
            if ($customer_phone !== '') $c .= pdf_text(50, 208, $customer_phone, 9);
            if ($customer_location !== '') $c .= pdf_text(50, 221, $customer_location, 9);
            $c .= pdf_text(545, 152, 'PAYMENT', 9, true, 'right');
            $c .= pdf_text(545, 168, money($order['Amount_paid'], $currency), 11, true, 'right');
            $c .= pdf_text(545, 184, 'Paid in full', 9, false, 'right');

            $table_top = 245;
        } else {
            $c .= pdf_text(50, 55, $company_name . ' — Receipt ' . $receipt_number . ' (continued)', 10, true);
            $table_top = 90;
        }

        $c .= pdf_line(50, $table_top, 545);
        $c .= pdf_text(52, $table_top - 13, '#', 9, true);
        $c .= pdf_text(70, $table_top - 13, 'ITEM', 9, true);
        $c .= pdf_text(300, $table_top - 13, 'CODE', 9, true);
        $c .= pdf_text(390, $table_top - 13, 'QTY', 9, true, 'right');
        $c .= pdf_text(465, $table_top - 13, 'UNIT PRICE', 9, true, 'right');
        $c .= pdf_text(545, $table_top - 13, 'TOTAL', 9, true, 'right');
        $c .= pdf_line(50, $table_top - 19, 545);

        $row_top = $table_top + 3;
        $line_no = $page_index * 20;
        foreach ($chunk as $item) {
            $line_no++;
            $description = (string)$item['product_name_snapshot'];
            if (strlen($description) > 40) {
                $description = substr($description, 0, 37) . '...';
            }
            $code = (string)$item['product_code_snapshot'];
            if (strlen($code) > 16) {
                $code = substr($code, 0, 13) . '...';
            }
            $c .= pdf_text(52, $row_top, (string)$line_no, 9);
            $c .= pdf_text(70, $row_top, $description, 9);
            $c .= pdf_text(300, $row_top, $code, 9);
            $c .= pdf_text(390, $row_top, format_quantity($item['quantity']), 9, false, 'right');
            $c .= pdf_text(465, $row_top, money($item['unit_price'], $currency), 9, false, 'right');
            $c .= pdf_text(545, $row_top, money($item['line_total'], $currency), 9, false, 'right');
            $row_top += 16;
        }
        $c .= pdf_line(50, $row_top, 545);

        if ($page_index === $last_page_index) {
            $total_top = $row_top + 22;
            $c .= pdf_text(380, $total_top, 'Subtotal', 10);
            $c .= pdf_text(545, $total_top, money($order['subtotal'], $currency), 10, false, 'right');
            $total_top += 18;
            $c .= pdf_line(380, $total_top - 9, 545, 0.6);
            $c .= pdf_text(380, $total_top + 4, 'Total', 11, true);
            $c .= pdf_text(545, $total_top + 4, money($order['total'], $currency), 11, true, 'right');
            $total_top += 22;
            $c .= pdf_text(380, $total_top, 'Amount paid', 10, true);
            $c .= pdf_text(545, $total_top, money($order['Amount_paid'], $currency), 10, true, 'right');
            $total_top += 18;
            $c .= pdf_text(380, $total_top, 'Balance due', 10);
            $c .= pdf_text(545, $total_top, money(0, $currency), 10, false, 'right');
            $c .= pdf_text(297, 805, 'Paid in full. Thank you for your business.', 8, false, 'center');
        }

        $pages[] = $c;
    }

    $pdf = pdf_document($pages, 'Receipt ' . $receipt_number, $company_name, $logo['data'], $logo['width'], $logo['height']);

    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    $filename = 'receipt-' . preg_replace('/[^A-Za-z0-9_-]+/', '-', (string)$order['order_number']) . '.pdf';
    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Length: ' . strlen($pdf));
    header('Cache-Control: private, max-age=0, must-revalidate');
    echo $pdf;
    exit;
}

$page_title = 'Receipt ' . $receipt_number;
require __DIR__ . '/wholesale-portal-header.php';
?>
<style>
    @media print {
        .ws-header, .ws-footer, .ws-page-head-actions, .ws-flash-wrap, .no-print { display: none !important; }
        .ws-body { background: #fff; }
        .ws-main { padding: 0; }
        .ws-container { max-width: 100%; padding: 0; }
        .ws-receipt-card { box-shadow: none; border: 0; }
    }
</style>

<div class="ws-page-head">
    <div>
        <h1>Receipt <?php echo e($receipt_number); ?></h1>
        <p>Payment receipt for order <?php echo e($order['order_number']); ?>.</p>
    </div>
    <div class="ws-page-head-actions">
        <a class="ws-btn" href="wholesale-orders.php"><i class="fa fa-arrow-left" aria-hidden="true"></i> Back to orders</a>
        <button type="button" class="ws-btn ws-btn-primary" onclick="window.print()"><i class="fa fa-print" aria-hidden="true"></i> Print</button>
        <a class="ws-btn" href="wholesale-order-receipt.php?id=<?php echo (int)$order_id; ?>&amp;download=1"><i class="fa fa-download" aria-hidden="true"></i> Download PDF</a>
    </div>
</div>

<div class="ws-receipt-card">
    <div class="ws-receipt-head">
        <div class="ws-receipt-company">
            <?php if ($company_logo !== ''): ?>
                <img src="<?php echo e($company_logo); ?>" alt="" class="ws-receipt-logo">
            <?php endif; ?>
            <div>
                <h2><?php echo e($company_name); ?></h2>
                <?php if ($company_address !== ''): ?><p><?php echo e($company_address); ?></p><?php endif; ?>
                <?php if ($company_phone !== '' || $company_email !== ''): ?>
                    <p><?php echo e(trim($company_phone . '  ' . $company_email)); ?></p>
                <?php endif; ?>
            </div>
        </div>
        <div class="ws-receipt-meta">
            <strong>PAYMENT RECEIPT</strong>
            <span><?php echo e($receipt_number); ?></span>
            <span>Order: <?php echo e($order['order_number']); ?></span>
            <span><?php echo e(date('d M Y, H:i', strtotime($paid_at))); ?></span>
            <span class="ws-badge is-success">Paid in full</span>
        </div>
    </div>

    <div class="ws-receipt-parties">
        <div>
            <span class="ws-receipt-label">Received from</span>
            <strong><?php echo e($customer_name); ?></strong>
            <?php if ($customer_contact !== ''): ?><div><?php echo e($customer_contact); ?></div><?php endif; ?>
            <?php if ($customer_phone !== ''): ?><div><?php echo e($customer_phone); ?></div><?php endif; ?>
            <?php if ($customer_email !== ''): ?><div><?php echo e($customer_email); ?></div><?php endif; ?>
            <?php if ($customer_location !== ''): ?><div><?php echo e($customer_location); ?></div><?php endif; ?>
        </div>
        <div class="text-right">
            <span class="ws-receipt-label">Amount paid</span>
            <strong class="ws-receipt-amount"><?php echo e(money($order['Amount_paid'], $currency)); ?></strong>
            <div>Balance due: <?php echo e(money(0, $currency)); ?></div>
        </div>
    </div>

    <div class="ws-table-wrap">
        <table class="ws-table ws-receipt-table">
            <thead>
                <tr><th>#</th><th>Item</th><th>Code</th><th class="text-right">Qty</th><th class="text-right">Unit price</th><th class="text-right">Total</th></tr>
            </thead>
            <tbody>
                <?php $line_no = 0; foreach ($items as $item): $line_no++; ?>
                    <tr>
                        <td><?php echo $line_no; ?></td>
                        <td>
                            <strong><?php echo e($item['product_name_snapshot']); ?></strong>
                            <?php if (!empty($item['unit_label_snapshot'])): ?><br><small class="text-muted"><?php echo e($item['unit_label_snapshot']); ?></small><?php endif; ?>
                        </td>
                        <td><?php echo e($item['product_code_snapshot']); ?></td>
                        <td class="text-right"><?php echo e(format_quantity($item['quantity'])); ?></td>
                        <td class="text-right"><?php echo e(money($item['unit_price'], $currency)); ?></td>
                        <td class="text-right"><strong><?php echo e(money($item['line_total'], $currency)); ?></strong></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$items): ?>
                    <tr><td colspan="6" class="text-muted">No line items were recorded for this order.</td></tr>
                <?php endif; ?>
            </tbody>
            <tfoot>
                <tr>
                    <th colspan="5" class="text-right">Subtotal</th>
                    <th class="text-right"><?php echo e(money($order['subtotal'], $currency)); ?></th>
                </tr>
                <tr>
                    <th colspan="5" class="text-right">Total</th>
                    <th class="text-right"><?php echo e(money($order['total'], $currency)); ?></th>
                </tr>
                <tr>
                    <th colspan="5" class="text-right">Amount paid</th>
                    <th class="text-right"><?php echo e(money($order['Amount_paid'], $currency)); ?></th>
                </tr>
            </tfoot>
        </table>
    </div>

    <div class="ws-receipt-footer">
        <span>Paid in full. Thank you for your business.</span>
        <span><?php echo e($company_name); ?></span>
    </div>
</div>
<?php require __DIR__ . '/wholesale-portal-footer.php'; ?>
