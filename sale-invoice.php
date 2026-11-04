<?php
require_once 'header.php';
require_once 'auth_check.php';
require_permission('manage_invoices');
require_once 'pos-invoice.php';
$page_title = 'Generate Invoice';

$id = (int)($_GET['id'] ?? 0);
try {
    $result = create_invoice_from_pos_sale($current_user->tenant_id, $id, $current_user->user_id);
    audit_log(
        'create',
        'invoice',
        $result['invoice_id'],
        'Invoice ' . $result['invoice_number'] . ' generated from POS sale #' . $id
    );
    flash_set(
        'success',
        $result['existing']
            ? 'An invoice already exists for this sale.'
            : 'Invoice ' . $result['invoice_number'] . ' generated successfully.'
    );
    redirect('invoice-details.php?id=' . (int)$result['invoice_id']);
} catch (Throwable $exception) {
    error_log('POS invoice generation failed: ' . $exception->getMessage());
    flash_set('danger', 'Could not generate the invoice: ' . $exception->getMessage());
    redirect('sale-details.php?id=' . $id);
}
