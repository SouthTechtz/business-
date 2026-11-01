<?php
/** Phase 7.2.2: invoice integration, sales returns and financial consistency. */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only.\n"); }
require_once __DIR__ . '/../../config.php';
while (ob_get_level() > 0) ob_end_clean();

function m17_fail(string $m){ throw new RuntimeException($m); }
function m17_query(string $sql){ global $conn; if($conn->query($sql)!==true) m17_fail($conn->error."\n".$sql); }
function m17_exec(string $sql,...$p){ global $conn; $st=$conn->prepare($sql); if(!$st) m17_fail($conn->error."\n".$sql); if($p){ $t=""; foreach($p as $v) $t.=is_int($v)?"i":(is_float($v)?"d":"s"); $st->bind_param($t,...$p);} if(!$st->execute()){ $e=$st->error; $st->close(); m17_fail($e."\n".$sql);} $st->close(); }
function m17_col(string $t,string $c): bool { $r=fetch_one("SELECT 1 c FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?", $t,$c); return (bool)$r; }
function m17_idx(string $t,string $i): bool { $r=fetch_one("SELECT 1 c FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND INDEX_NAME=?", $t,$i); return (bool)$r; }
function m17_addcol(string $t,string $c,string $def){ if (m17_col($t,$c)) { echo "  = $t.$c already exists\n"; return; } m17_query("ALTER TABLE `$t` ADD COLUMN `$c` $def"); echo "  + $t.$c\n"; }

echo "Migration 017: Phase 7.2.2 invoice integration and financial consistency\n";
echo "Database: " . DB_NAME . "\n\n";

try {
    m17_addcol('invoices', 'updated_at', "TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP");
    m17_addcol('invoices', 'version', "INT UNSIGNED NOT NULL DEFAULT 1");
    m17_addcol('invoices', 'returned_amount', "DECIMAL(12,2) NOT NULL DEFAULT 0.00");
    m17_addcol('invoices', 'refund_due', "DECIMAL(12,2) NOT NULL DEFAULT 0.00");

    m17_addcol('sales_return_items', 'resale_quantity', "DECIMAL(12,2) NOT NULL DEFAULT 0.00");

    m17_addcol('sales_returns', 'submission_token', "VARCHAR(64) NULL");
    m17_addcol('payments', 'submission_token', "VARCHAR(64) NULL");

    if (!m17_idx('sales_returns', 'uq_sr_submission_token')) {
        try { m17_query("ALTER TABLE sales_returns ADD UNIQUE KEY uq_sr_submission_token (tenant_id, submission_token)"); echo "  + unique sales_returns(tenant_id, submission_token)\n"; }
        catch (Throwable $e) { echo "  ! could not add unique key: " . $e->getMessage() . "\n"; }
    } else {
        echo "  = uq_sr_submission_token already exists\n";
    }
    if (!m17_idx('payments', 'uq_payment_submission_token')) {
        try { m17_query("ALTER TABLE payments ADD UNIQUE KEY uq_payment_submission_token (tenant_id, submission_token)"); echo "  + unique payments(tenant_id, submission_token)\n"; }
        catch (Throwable $e) { echo "  ! could not add unique key: " . $e->getMessage() . "\n"; }
    } else {
        echo "  = uq_payment_submission_token already exists\n";
    }

    m17_query("UPDATE invoices SET version = COALESCE(version, 1), updated_at = COALESCE(updated_at, created_at) WHERE version IS NULL OR updated_at IS NULL");

    $stmt = $conn->prepare("INSERT INTO schema_migrations (migration, applied_at) VALUES (?, NOW()) ON DUPLICATE KEY UPDATE applied_at=applied_at");
    $m = '017_phase722_invoice_integration';
    $stmt->bind_param('s', $m);
    $stmt->execute();
    $stmt->close();

    echo "\nMigration 017 completed successfully.\n";
} catch (Throwable $ex) {
    fwrite(STDERR, "\nMigration 017 FAILED: " . $ex->getMessage() . "\n");
    exit(1);
}
