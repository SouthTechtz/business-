<?php
require_once "header.php";
require_once "auth_check.php";
require_permission("manage_invoices");

if ($_SERVER["REQUEST_METHOD"] !== "POST") { redirect("invoices.php"); }
verify_csrf();

$tenant_id = (int)$current_user->tenant_id;
$invoice_id = (int)($_POST["invoice_id"] ?? 0);
$back = "invoice-details.php?id=" . $invoice_id;

$invoice = fetch_one("SELECT * FROM invoices WHERE invoice_id = ? AND tenant_id = ?", $invoice_id, $tenant_id);
if (!$invoice) {
    flash_set("danger", "Invoice not found.");
    redirect("invoices.php");
}
$invoice_warehouse_id = (int)($invoice["warehouse_id"] ?? 0);
if ($invoice_warehouse_id > 0 && !store_is_accessible($invoice_warehouse_id, true)) {
    flash_set("danger", "You do not have access to this invoice.");
    redirect("invoices.php");
}

$payment_count = fetch_one("SELECT COUNT(*) c FROM payments WHERE invoice_id = ? AND tenant_id = ?", $invoice_id, $tenant_id);
if ((int)($payment_count["c"] ?? 0) > 0) {
    flash_set("danger", "This invoice has payment records. Cancellation with payment reversal is not supported.");
    redirect($back);
}

$linked = fetch_one("SELECT order_id FROM orders WHERE fulfilled_invoice_id = ? AND tenant_id = ?", $invoice_id, $tenant_id);
if ($linked) {
    flash_set("danger", "This invoice is linked to an order. Order-linked invoice cancellation is not supported in this workflow.");
    redirect($back);
}

$cancelled_status = fetch_one("SELECT status_id FROM document_statuses WHERE tenant_id = ? AND type = 'invoice' AND name = 'Cancelled'", $tenant_id);
if (!$cancelled_status) {
    flash_set("danger", "Cancelled status not configured.");
    redirect($back);
}
$cancelled_status_id = (int)$cancelled_status["status_id"];

$conn->begin_transaction();
try {
    $locked = fetch_one("SELECT * FROM invoices WHERE invoice_id = ? AND tenant_id = ? FOR UPDATE", $invoice_id, $tenant_id);
    if (!$locked) throw new RuntimeException("Invoice not found.");
    if ((int)$locked["status_id"] === $cancelled_status_id) {
        $conn->commit();
        flash_set("success", "Invoice is already cancelled.");
        redirect($back);
    }
    $items = fetch_all("SELECT * FROM invoice_items WHERE invoice_id = ? AND tenant_id = ?", $invoice_id, $tenant_id);
    foreach ($items as $it) {
        $pid = (int)$it["product_id"];
        $wh = (int)$it["warehouse_id"];
        $qty = (float)$it["quantity"];
        if ($pid <= 0 || $wh <= 0) continue;
        $prod = fetch_one("SELECT is_service FROM products WHERE product_id = ? AND tenant_id = ?", $pid, $tenant_id);
        if ($prod && (int)$prod["is_service"] === 1) continue;
        $exists = fetch_one("SELECT stock_id FROM inventory_stock WHERE tenant_id = ? AND warehouse_id = ? AND product_id = ?", $tenant_id, $wh, $pid);
        if ($exists) {
            $stmt = $conn->prepare("UPDATE inventory_stock SET quantity = quantity + ? WHERE tenant_id = ? AND warehouse_id = ? AND product_id = ?");
            $stmt->bind_param("diii", $qty, $tenant_id, $wh, $pid);
            $stmt->execute();
            $stmt->close();
        } else {
            db_insert("inventory_stock", ["tenant_id"=>$tenant_id,"warehouse_id"=>$wh,"product_id"=>$pid,"quantity"=>$qty]);
        }
        db_insert("inventory_movements", ["tenant_id"=>$tenant_id,"product_id"=>$pid,"warehouse_id"=>$wh,"movement_type"=>"invoice_cancel","quantity"=>$qty,"reference_id"=>$invoice_id,"reference_type"=>"invoice","created_by"=>$current_user->user_id,"notes"=>"Invoice cancelled"]);
    }
    $stmt = $conn->prepare("UPDATE invoices SET status_id = ?, amount_due = 0 WHERE invoice_id = ? AND tenant_id = ?");
    $stmt->bind_param("iii", $cancelled_status_id, $invoice_id, $tenant_id);
    $stmt->execute();
    $stmt->close();
    $conn->commit();
    audit_log("cancel", "invoice", $invoice_id, "Invoice cancelled");
    flash_set("success", "Invoice cancelled and stock restored.");
    redirect($back);
} catch (Throwable $ex) {
    $conn->rollback();
    flash_set("danger", "Cancellation failed: " . $ex->getMessage());
    redirect($back);
}
