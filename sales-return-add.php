<?php
require_once "header.php";
require_once "auth_check.php";
require_once "capital-helpers.php";
require_permission_any(["manage_pos", "manage_invoices"]);

if ($_SERVER["REQUEST_METHOD"] !== "POST") { redirect("sales-returns.php"); }
verify_csrf();

$tenant_id = (int)$current_user->tenant_id;
$source_type = (string)($_POST["source_type"] ?? "");
$source_id = (int)($_POST["source_id"] ?? 0);
$product_ids = $_POST["product_id"] ?? [];
$quantities = $_POST["quantity"] ?? [];
$resale_qtys = $_POST["resale_qty"] ?? [];
$submission_token = trim((string)($_POST["submission_token"] ?? ""));

if (!in_array($source_type, ["pos", "invoice"], true) || $source_id <= 0) {
    flash_set("danger", "Invalid source transaction.");
    redirect("sales-returns.php");
}
if ($submission_token === "") {
    flash_set("danger", "Missing return submission token. Reload and try again.");
    redirect("sales-returns.php");
}

$dup = fetch_one("SELECT return_id FROM sales_returns WHERE tenant_id = ? AND submission_token = ?", $tenant_id, $submission_token);
if ($dup) {
    flash_set("danger", "This return submission was already processed.");
    redirect("sales-returns.php");
}

$conn->begin_transaction();
try {
    if ($source_type === "pos") {
        $order = fetch_one("SELECT * FROM pos_orders WHERE order_id = ? AND tenant_id = ? FOR UPDATE", $source_id, $tenant_id);
        if (!$order || $order["status"] !== "completed") {
            throw new RuntimeException("Original sale not found or not completed.");
        }
        $warehouse_id = (int)($order["warehouse_id"] ?? 0);
        if ($warehouse_id <= 0 || !store_is_accessible($warehouse_id, true)) {
            throw new RuntimeException("You do not have access to this sale.");
        }
        $original_items = fetch_all("SELECT product_id, quantity, unit_price FROM pos_order_items WHERE order_id = ?", $source_id);
        $customer_id = $order["contact_id"];
        $shop_id = null;
        $wh = fetch_one("SELECT shop_id FROM warehouses WHERE warehouse_id = ? AND tenant_id = ?", $warehouse_id, $tenant_id);
        if ($wh) $shop_id = (int)$wh["shop_id"];
    } else {
        $invoice = fetch_one("SELECT * FROM invoices WHERE invoice_id = ? AND tenant_id = ? FOR UPDATE", $source_id, $tenant_id);
        if (!$invoice) {
            throw new RuntimeException("Original invoice not found.");
        }
        $st = fetch_one("SELECT name FROM document_statuses WHERE status_id = ? AND tenant_id = ?", (int)$invoice["status_id"], $tenant_id);
        $status_name = $st ? (string)$st["name"] : "";
        if (strcasecmp($status_name, "Cancelled") === 0) {
            throw new RuntimeException("Cancelled invoices cannot be returned.");
        }
        $warehouse_id = (int)($invoice["warehouse_id"] ?? 0);
        if ($warehouse_id > 0 && !store_is_accessible($warehouse_id, true)) {
            throw new RuntimeException("You do not have access to this invoice.");
}
        $original_items = fetch_all("SELECT product_id, quantity, unit_price, warehouse_id FROM invoice_items WHERE invoice_id = ?", $source_id);
        $customer_id = $invoice["contact_id"];
        $wh = fetch_one("SELECT shop_id FROM warehouses WHERE warehouse_id = ? AND tenant_id = ?", $warehouse_id, $tenant_id);
        $shop_id = $wh ? (int)$wh["shop_id"] : null;
    }

    $orig_map = [];
    foreach ($original_items as $oi) {
        if ((int)$oi["product_id"] <= 0) continue;
        $orig_map[(int)$oi["product_id"]] = $oi;
    }

    $prev = fetch_all("SELECT sri.product_id, COALESCE(SUM(sri.quantity),0) AS returned_qty FROM sales_return_items sri JOIN sales_returns sr ON sr.return_id = sri.return_id AND sr.tenant_id = sri.tenant_id WHERE sr.tenant_id = ? AND sr.source_type = ? AND sr.source_id = ? AND sr.status = ? GROUP BY sri.product_id", $tenant_id, $source_type, $source_id, "completed");
    $prev_map = [];
    foreach ($prev as $p) { $prev_map[(int)$p["product_id"]] = (float)$p["returned_qty"]; }

    $invoice_location_reliable = false;
    $restore_destination = 0;
    if ($source_type === 'invoice') {
        if ($warehouse_id > 0 && store_is_accessible($warehouse_id, true)) {
            $reliable = true;
            foreach ($original_items as $oi) {
                if ((int)$oi['product_id'] <= 0) continue;
                $item_wh = (int)($oi['warehouse_id'] ?? 0);
                if ($item_wh > 0 && $item_wh !== $warehouse_id) {
                    $reliable = false;
                    break;
                }
            }
            if ($reliable) {
                $invoice_location_reliable = true;
                $restore_destination = $warehouse_id;
            }
        }
    } else {
        $invoice_location_reliable = ($warehouse_id > 0 && store_is_accessible($warehouse_id, true));
        $restore_destination = $invoice_location_reliable ? $warehouse_id : 0;
    }

    $items_to_return = [];
    $total_refund = 0.0;
    foreach ($product_ids as $idx => $pid_raw) {
        $pid = (int)$pid_raw;
        $qty = (float)($quantities[$idx] ?? 0);
        if ($pid <= 0 || $qty <= 0) continue;
        if (!isset($orig_map[$pid])) {
            throw new RuntimeException("Product not in original transaction.");
        }
        $orig_qty = (float)$orig_map[$pid]["quantity"];
        $already = $prev_map[$pid] ?? 0.0;
        $remaining = $orig_qty - $already;
        if ($qty > $remaining + 0.0001) {
            throw new RuntimeException("Return quantity exceeds remaining returnable quantity.");
        }
        $resale = 0;
        $unit_price = (float)$orig_map[$pid]["unit_price"];
        $refund = $qty * $unit_price;
        $items_to_return[] = [
            "product_id" => $pid,
            "quantity" => $qty,
            "unit_price" => $unit_price,
            "refund" => $refund,
            "resale" => $resale,
            "warehouse_id" => $source_type === "invoice" ? $restore_destination : $warehouse_id,
        ];
        $total_refund += $refund;
    }

    if (!$items_to_return) {
        throw new RuntimeException("No items to return.");
    }

    // Refund account: cash by default, bank when a bank payment method is chosen.
    $return_brand = current_brand_scope();
    if ($return_brand === "" && $shop_id) {
        $shop_row = fetch_one("SELECT brand FROM shops WHERE shop_id = ? AND tenant_id = ?", $shop_id, $tenant_id);
        $return_brand = (string)($shop_row["brand"] ?? "");
    }
    $refund_method_id = (int)($_POST["refund_method_id"] ?? 0) ?: null;
    $refund_bank_name = trim((string)($_POST["refund_bank_name"] ?? ""));
    $refund_account = "cash";
    if ($refund_method_id !== null) {
        $refund_method = fetch_one(
            "SELECT account_type FROM payment_methods WHERE payment_method_id = ? AND tenant_id = ?",
            $refund_method_id,
            $tenant_id
        );
        if ($refund_method) {
            $refund_account = (string)($refund_method["account_type"] ?? "bank") === "bank" ? "bank" : "cash";
        } else {
            $refund_method_id = null;
        }
    }
    if ($refund_account !== "bank") {
        $refund_bank_name = "";
    } elseif ($refund_bank_name !== "" && $return_brand !== "") {
        $canonical_bank = bank_canonical_name($tenant_id, $return_brand, $refund_bank_name);
        if ($canonical_bank !== "") {
            $refund_bank_name = $canonical_bank;
        }
    }
    if ($refund_account === "bank" && $refund_bank_name === "") {
        throw new RuntimeException("Select the bank account that paid this refund.");
    }
    if (mb_strlen($refund_bank_name) > 150) {
        throw new RuntimeException("The refund bank name must be 150 characters or fewer.");
    }

    $return_number = "RET-" . date("Ymd") . "-" . time() . "-" . mt_rand(10, 99);
    $return_id = db_insert("sales_returns", [
        "tenant_id" => $tenant_id,
        "return_number" => $return_number,
        "source_type" => $source_type,
        "source_id" => $source_id,
        "customer_id" => $customer_id,
        "shop_id" => $shop_id,
        "warehouse_id" => $warehouse_id > 0 ? $warehouse_id : null,
        "refund_amount" => $total_refund,
        "refund_method_id" => $refund_method_id,
        "refund_bank_name" => $refund_bank_name !== "" ? $refund_bank_name : null,
        "status" => "completed",
        "submission_token" => $submission_token,
        "created_by" => $current_user->user_id,
    ]);
    if (!$return_id) throw new RuntimeException("Could not create return.");

    foreach ($items_to_return as $it) {
        db_insert("sales_return_items", [
            "return_id" => $return_id,
            "tenant_id" => $tenant_id,
            "product_id" => $it["product_id"],
            "quantity" => $it["quantity"],
            "unit_price" => $it["unit_price"],
            "refund_amount" => $it["refund"],
            "resale_quantity" => $it["resale"],
        ]);

        // Returned stock is recorded in sales_return_items only. It is not
        // added back to sellable inventory_stock.
    }

    // Auto-update invoice for invoice-linked returns.
    if ($source_type === "invoice") {
        $prev_returned = fetch_one("SELECT COALESCE(SUM(refund_amount),0) AS s FROM sales_returns WHERE tenant_id = ? AND source_type = 'invoice' AND source_id = ? AND status = 'completed'", $tenant_id, $source_id);
        $cumulative_returned = (float)($prev_returned["s"] ?? 0);
        // Include current return (already inserted and status completed).
        // prev query includes current because status completed; so cumulative is correct.
        $total = (float)$invoice["total"];
        $paid = (float)$invoice["amount_paid"];
        $net_total = max(0, $total - $cumulative_returned);
        $new_due = max(0, $net_total - $paid);
        $refund_due = max(0, $paid - $net_total);
        $is_paid = $new_due <= 0.0001 ? 1 : 0;

        // Full return if all original sale value has been returned.
        $full_return = $cumulative_returned >= $total - 0.01;

        if ($full_return) {
            $cancelled_status = fetch_one("SELECT status_id FROM document_statuses WHERE tenant_id = ? AND type = 'invoice' AND name = 'Cancelled'", $tenant_id);
            $cancelled_status_id = $cancelled_status ? (int)$cancelled_status["status_id"] : (int)$invoice["status_id"];
            $stmt = $conn->prepare("UPDATE invoices SET status_id = ?, amount_due = ?, is_paid = ?, returned_amount = ?, refund_due = ?, version = version + 1, updated_at = NOW() WHERE invoice_id = ? AND tenant_id = ?");
            $stmt->bind_param("ididdii", $cancelled_status_id, $new_due, $is_paid, $cumulative_returned, $refund_due, $source_id, $tenant_id);
            $stmt->execute();
            $stmt->close();
        } else {
            $stmt = $conn->prepare("UPDATE invoices SET amount_due = ?, is_paid = ?, returned_amount = ?, refund_due = ?, version = version + 1, updated_at = NOW() WHERE invoice_id = ? AND tenant_id = ?");
            $stmt->bind_param("diddii", $new_due, $is_paid, $cumulative_returned, $refund_due, $source_id, $tenant_id);
            $stmt->execute();
            $stmt->close();
        }
    }

    $conn->commit();
    audit_log("create", "sales_return", $return_id, "Sales return " . $return_number . " processed");
    flash_set("success", "Sales return processed. Refund: " . number_format($total_refund, 2));
    redirect("sales-returns.php");
} catch (Throwable $e) {
    $conn->rollback();
    flash_set("danger", "Could not process return: " . $e->getMessage());
    redirect("sales-returns.php");
}

