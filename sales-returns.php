<?php
require_once "header.php";
require_once "auth_check.php";
require_permission_any(["manage_pos", "manage_invoices"]);
$page_title = "Sales Returns";
$tenant_id = (int)$current_user->tenant_id;

$receipt = trim((string)($_GET["receipt"] ?? ""));
$sale = null;
$sale_type = null; // pos or invoice
$sale_items = [];
$returnable = [];
$prev_map = [];

if ($receipt !== "") {
    // Try POS receipt first.
    $pos_sale = fetch_one("SELECT o.*, c.first_name, c.last_name, c.company_name, w.name store_name, s.name shop_name FROM pos_orders o LEFT JOIN contacts c ON c.contact_id = o.contact_id LEFT JOIN warehouses w ON w.warehouse_id = o.warehouse_id LEFT JOIN shops s ON s.shop_id = w.shop_id WHERE o.tenant_id = ? AND o.order_number = ?", $tenant_id, $receipt);
    if ($pos_sale && (int)$pos_sale["warehouse_id"] > 0 && !store_is_accessible((int)$pos_sale["warehouse_id"], true)) {
        $pos_sale = null;
    }
    if ($pos_sale && (string)$pos_sale["status"] === "completed") {
        $sale = $pos_sale;
        $sale_type = "pos";
        $sale_items = fetch_all("SELECT oi.*, p.code, p.sku FROM pos_order_items oi LEFT JOIN products p ON p.product_id = oi.product_id WHERE oi.order_id = ?", (int)$sale["order_id"]);
        $prev = fetch_all("SELECT sri.product_id, COALESCE(SUM(sri.quantity),0) AS returned_qty FROM sales_return_items sri JOIN sales_returns sr ON sr.return_id = sri.return_id AND sr.tenant_id = sri.tenant_id WHERE sr.tenant_id = ? AND sr.source_type = ? AND sr.source_id = ? AND sr.status = ? GROUP BY sri.product_id", $tenant_id, "pos", (int)$sale["order_id"], "completed");
        foreach ($prev as $p) { $prev_map[(int)$p["product_id"]] = (float)$p["returned_qty"]; }
        foreach ($sale_items as $it) {
            $pid = (int)$it["product_id"];
            $orig = (float)$it["quantity"];
            $already = $prev_map[$pid] ?? 0.0;
            $returnable[$pid] = max(0, $orig - $already);
        }
    } else {
        $inv = fetch_one("SELECT i.*, c.first_name, c.last_name, c.company_name, w.name store_name, s.name shop_name FROM invoices i LEFT JOIN contacts c ON c.contact_id = i.contact_id LEFT JOIN warehouses w ON w.warehouse_id = i.warehouse_id LEFT JOIN shops s ON s.shop_id = w.shop_id WHERE i.tenant_id = ? AND i.invoice_number = ?", $tenant_id, $receipt);
        if ($inv && (int)($inv["warehouse_id"] ?? 0) > 0 && !store_is_accessible((int)$inv["warehouse_id"], true)) {
            $inv = null;
        }
        if ($inv) {
            $st = fetch_one("SELECT name FROM document_statuses WHERE status_id = ? AND tenant_id = ?", (int)$inv["status_id"], $tenant_id);
            $status_name = $st ? (string)$st["name"] : "";
            if (strcasecmp($status_name, "Cancelled") !== 0) {
                $sale = $inv;
                $sale_type = "invoice";
                $sale_items = fetch_all("SELECT ii.*, p.code, p.sku, p.name product_name FROM invoice_items ii LEFT JOIN products p ON p.product_id = ii.product_id WHERE ii.invoice_id = ?", (int)$sale["invoice_id"]);
                $prev = fetch_all("SELECT sri.product_id, COALESCE(SUM(sri.quantity),0) AS returned_qty FROM sales_return_items sri JOIN sales_returns sr ON sr.return_id = sri.return_id AND sr.tenant_id = sri.tenant_id WHERE sr.tenant_id = ? AND sr.source_type = ? AND sr.source_id = ? AND sr.status = ? GROUP BY sri.product_id", $tenant_id, "invoice", (int)$sale["invoice_id"], "completed");
                foreach ($prev as $p) { $prev_map[(int)$p["product_id"]] = (float)$p["returned_qty"]; }
                foreach ($sale_items as $it) {
                    $pid = (int)$it["product_id"];
                    if ($pid <= 0) continue;
                    $orig = (float)$it["quantity"];
                    $already = $prev_map[$pid] ?? 0.0;
                    $returnable[$pid] = max(0, $orig - $already);
                }
            }
        }
    }
}

$returns = fetch_all("SELECT sr.*, c.first_name, c.last_name, c.company_name, s.name shop_name, u.first_name seller_first, u.last_name seller_last FROM sales_returns sr LEFT JOIN contacts c ON c.contact_id = sr.customer_id LEFT JOIN shops s ON s.shop_id = sr.shop_id LEFT JOIN users u ON u.user_id = sr.created_by WHERE sr.tenant_id = ? ORDER BY sr.created_at DESC LIMIT 50", $tenant_id);

require_once "navbar.php";
require_once "sidebar.php";
$return_token = bin2hex(random_bytes(16));
?>
<div class="page-head">
    <div><h1 class="page-title">Sales Returns</h1><div class="page-sub">Process and view accepted sales returns for POS or invoice sales.</div></div>
</div>
<?php if ($f = flash_get()): ?>
    <div class="alert alert-<?php echo e($f["type"]); ?>"><?php echo e($f["message"]); ?></div>
<?php endif; ?>

<div class="card mb-3">
    <div class="card-header"><h5 class="card-title mb-0">Find Original Sale</h5></div>
    <div class="card-body">
        <form method="GET" action="sales-returns.php" class="form-inline">
            <input type="text" name="receipt" class="form-control mr-2" placeholder="Receipt or invoice number" value="<?php echo e($receipt); ?>">
            <button type="submit" class="btn btn-primary">Search</button>
        </form>
    </div>
</div>

<?php if ($sale): ?>
<div class="card mb-3">
    <div class="card-header"><h5 class="card-title mb-0">Original Sale: <?php echo e($sale_type === "pos" ? $sale["order_number"] : $sale["invoice_number"]); ?></h5></div>
    <div class="card-body">
        <p>Customer: <?php echo e(trim(($sale["first_name"] ?? "") . " " . ($sale["last_name"] ?? "") . " " . ($sale["company_name"] ?? ""))); ?> | Shop/Store: <?php echo e(trim(($sale["shop_name"] ?? "") . " / " . ($sale["store_name"] ?? ""), " /")); ?> | Date: <?php echo e($sale_type === "pos" ? $sale["order_date"] : $sale["issue_date"]); ?></p>
        <form method="POST" action="sales-return-add.php" onsubmit="return confirm("Process this return?");">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="source_type" value="<?php echo e($sale_type); ?>">
            <input type="hidden" name="source_id" value="<?php echo (int)($sale_type === "pos" ? $sale["order_id"] : $sale["invoice_id"]); ?>">
            <input type="hidden" name="submission_token" value="<?php echo e($return_token); ?>">
            <table class="table table-sm">
                <thead><tr><th>Product</th><th>Qty Sold</th><th>Already Returned</th><th>Returnable</th><th>Unit Price</th><th>Return Qty</th><th>Resellable Qty</th></tr></thead>
                <tbody>
                <?php foreach ($sale_items as $it): ?>
                    <?php
                    if ($sale_type === "pos") {
                        $pid = (int)$it["product_id"];
                        $desc = $it["description"];
                        $qty = (float)$it["quantity"];
                        $price = (float)$it["unit_price"];
                    } else {
                        $pid = (int)$it["product_id"];
                        if ($pid <= 0) continue;
                        $desc = $it["description"] ?: ($it["product_name"] ?? "");
                        $qty = (float)$it["quantity"];
                        $price = (float)$it["unit_price"];
                    }
                    $already = $prev_map[$pid] ?? 0.0;
                    $rem = $returnable[$pid] ?? 0;
                    ?>
                    <tr>
                        <td><?php echo e($desc); ?></td>
                        <td><?php echo e($qty); ?></td>
                        <td><?php echo e($already); ?></td>
                        <td><?php echo e($rem); ?></td>
                        <td><?php echo e(money($price, tenant_currency())); ?></td>
                        <td><input type="number" name="quantity[]" class="form-control form-control-sm" min="0" max="<?php echo e($rem); ?>" step="0.01" value="0"><input type="hidden" name="product_id[]" value="<?php echo $pid; ?>"></td>
                        <td><input type="number" name="resale_qty[]" class="form-control form-control-sm" min="0" max="<?php echo e($rem); ?>" step="0.01" value="0"></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <small class="text-muted d-block mb-2">Resellable quantity is the portion physically returned and accepted for resale. It may not exceed the return quantity.</small>
            <button type="submit" class="btn btn-primary"><i class="fa fa-undo mr-1"></i> Process Return</button>
        </form>
    </div>
</div>
<?php elseif ($receipt !== ""): ?>
    <div class="alert alert-warning">Sale not found or not eligible for returns.</div>
<?php endif; ?>

<div class="card">
    <div class="card-header"><h5 class="card-title mb-0">Accepted Returns</h5></div>
    <div class="card-body p-0">
        <table class="table table-sm mb-0">
            <thead><tr><th>Return #</th><th>Source</th><th>Customer</th><th>Shop</th><th>Refund</th><th>Seller</th><th>Date</th></tr></thead>
            <tbody>
            <?php foreach ($returns as $r): ?>
                <tr>
                    <td><?php echo e($r["return_number"]); ?></td>
                    <td><?php echo e(strtoupper($r["source_type"]) . " #" . $r["source_id"]); ?></td>
                    <td><?php echo e(trim(($r["first_name"] ?? "") . " " . ($r["last_name"] ?? "") . " " . ($r["company_name"] ?? ""))); ?></td>
                    <td><?php echo e($r["shop_name"] ?? ""); ?></td>
                    <td><?php echo e(money($r["refund_amount"], tenant_currency())); ?></td>
                    <td><?php echo e(trim(($r["seller_first"] ?? "") . " " . ($r["seller_last"] ?? ""))); ?></td>
                    <td><?php echo e($r["return_date"]); ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$returns): ?>
                <tr><td colspan="7" class="text-center text-muted py-3">No returns yet.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require_once "footer.php"; ?>
