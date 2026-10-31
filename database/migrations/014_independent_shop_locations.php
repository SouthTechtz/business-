<?php
/** Phase 2: independent shop inventory locations. */
declare(strict_types=1);
if (PHP_SAPI !== "cli") { http_response_code(403); exit("CLI only.\n"); }
require_once __DIR__ . "/../../config.php";
while (ob_get_level() > 0) ob_end_clean();
function mfail(string $m){ throw new RuntimeException($m); }
function mquery(string $sql){ global $conn; if($conn->query($sql)!==true) mfail($conn->error."\n".$sql); }
function mexec(string $sql,...$p){ global $conn; $st=$conn->prepare($sql); if(!$st) mfail($conn->error."\n".$sql); if($p){ $t=""; foreach($p as $v) $t.=is_int($v)?"i":(is_float($v)?"d":"s"); $st->bind_param($t,...$p);} if(!$st->execute()) { $e=$st->error; $st->close(); mfail($e."\n".$sql);} $st->close(); }
$col = fetch_one("SELECT COLUMN_TYPE FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='warehouses' AND COLUMN_NAME='location_type'");
if ($col && strpos($col['COLUMN_TYPE'], "'shop'") === false) {
    mquery("ALTER TABLE warehouses MODIFY location_type ENUM('store','godown','shop') NOT NULL DEFAULT 'store'");
    echo "Altered location_type enum.\n";
} else {
    echo "location_type already supports shop.\n";
}
try { mquery("ALTER TABLE warehouses DROP CONSTRAINT chk_wh_type_shop"); echo "Dropped old check constraint.\n"; } catch (Throwable $e) { echo "Check constraint already dropped or not present.\n"; }
mquery("ALTER TABLE warehouses ADD CONSTRAINT chk_wh_type_shop CHECK (location_type='store' AND shop_id IS NOT NULL OR location_type='godown' AND shop_id IS NULL OR location_type='shop' AND shop_id IS NOT NULL)");
echo "Added updated check constraint.\n";
$shops = fetch_all("SELECT shop_id, tenant_id, name, code, is_active FROM shops");
$created = 0;
foreach ($shops as $sh) {
    $exists = fetch_one("SELECT warehouse_id FROM warehouses WHERE tenant_id=? AND shop_id=? AND location_type='shop' LIMIT 1", (int)$sh['tenant_id'], (int)$sh['shop_id']);
    if ($exists) continue;
    $code = trim((string)$sh['code']);
    if ($code === '') $code = 'SHOPLOC-' . $sh['shop_id'];
    $dup = fetch_one("SELECT 1 FROM warehouses WHERE tenant_id=? AND code=?", (int)$sh['tenant_id'], $code);
    if ($dup) $code = $code . '-' . $sh['shop_id'];
    mexec("INSERT INTO warehouses (tenant_id, shop_id, name, code, is_active, location_type) VALUES (?,?,?,?,?, 'shop')",
        (int)$sh['tenant_id'], (int)$sh['shop_id'], (string)$sh['name'], $code, (int)$sh['is_active']);
    $created++;
}
echo "Created $created shop inventory locations.\n";
echo "Done.\n";
