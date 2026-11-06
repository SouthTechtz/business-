<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("This migration can only be run from the command line.\n");
}

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../pos-invoice.php';
while (ob_get_level() > 0) {
    ob_end_clean();
}

if (!in_array(DB_HOST, ['127.0.0.1', 'localhost', '::1'], true)) {
    fwrite(STDERR, "Refusing to run against a non-local database.\n");
    exit(1);
}

echo "Migration 028: Sync POS sale payments with generated invoices\n";
echo "Database: " . DB_NAME . "\n";

try {
    $invoices = fetch_all(
        "SELECT invoice_id, tenant_id, invoice_number, reference, amount_paid
           FROM invoices
          WHERE reference IS NOT NULL AND reference <> ''
          ORDER BY tenant_id, invoice_id"
    );

    $synced = 0;
    $added_total = 0.0;

    $conn->begin_transaction();
    try {
        foreach ($invoices as $invoice) {
            $latest_payment = fetch_one(
                "SELECT payment_method_id, payment_date, created_by
                   FROM payments
                  WHERE invoice_id = ? AND tenant_id = ?
                  ORDER BY payment_id DESC
                  LIMIT 1",
                (int)$invoice['invoice_id'],
                (int)$invoice['tenant_id']
            );
            $payment_info = $latest_payment ? [
                'payment_method_id' => $latest_payment['payment_method_id'],
                'payment_date'      => $latest_payment['payment_date'],
                'created_by'        => $latest_payment['created_by'],
            ] : null;

            $added = sync_pos_sale_payments_from_invoice(
                (int)$invoice['tenant_id'],
                (int)$invoice['invoice_id'],
                $payment_info
            );
            if ($added > 0) {
                $synced++;
                $added_total += $added;
                echo "  + {$invoice['invoice_number']} ({$invoice['reference']}): added "
                    . number_format($added, 2, '.', '') . " to the sale payments\n";
            }
        }
        $conn->commit();
    } catch (Throwable $ex) {
        $conn->rollback();
        throw $ex;
    }

    echo "  + {$synced} invoice(s) synced, total added: " . number_format($added_total, 2, '.', '') . "\n";

    $stmt = $conn->prepare(
        "INSERT IGNORE INTO schema_migrations (migration) VALUES ('028_sync_pos_invoice_payments')"
    );
    if (!$stmt) {
        throw new RuntimeException($conn->error);
    }
    if (!$stmt->execute()) {
        throw new RuntimeException($stmt->error);
    }
    $stmt->close();

    echo "Migration 028 completed.\n";
} catch (Throwable $ex) {
    fwrite(STDERR, "Migration 028 failed: " . $ex->getMessage() . "\n");
    exit(1);
}
