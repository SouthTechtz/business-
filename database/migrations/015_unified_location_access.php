<?php
/** Phase 3: unified inventory location access. */
declare(strict_types=1);
if (PHP_SAPI !== "cli") { http_response_code(403); exit("CLI only.\n"); }
require_once __DIR__ . "/../../config.php";
while (ob_get_level() > 0) ob_end_clean();
function mfail(string $m){ throw new RuntimeException($m); }
function mquery(string $sql){ global $conn; if($conn->query($sql)!==true) mfail($conn->error."\n".$sql); }
function mexec(string $sql,...$p){ global $conn; $st=$conn->prepare($sql); if(!$st) mfail($conn->error."\n".$sql); if($p){ $t=""; foreach($p as $v) $t.=is_int($v)?"i":(is_float($v)?"d":"s"); $st->bind_param($t,...$p);} if(!$st->execute()) { $e=$st->error; $st->close(); mfail($e."\n".$sql);} $st->close(); }
mquery("CREATE TABLE IF NOT EXISTS user_inventory_locations (
  user_location_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  tenant_id INT UNSIGNED NOT NULL,
  user_id INT UNSIGNED NOT NULL,
  location_id INT UNSIGNED NOT NULL,
  assigned_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (user_location_id),
  UNIQUE KEY uq_user_location (tenant_id, user_id, location_id),
  KEY idx_uil_tenant (tenant_id),
  KEY idx_uil_user (user_id),
  KEY idx_uil_location (location_id),
  CONSTRAINT fk_uil_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(tenant_id) ON DELETE CASCADE,
  CONSTRAINT fk_uil_user FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE,
  CONSTRAINT fk_uil_location FOREIGN KEY (location_id) REFERENCES warehouses(warehouse_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
echo "Created user_inventory_locations.\n";
// Migrate user_godowns -> unified
$migrated = 0;
$g = fetch_all("SELECT tenant_id, user_id, warehouse_id FROM user_godowns");
foreach ($g as $r) {
    $exists = fetch_one("SELECT 1 FROM user_inventory_locations WHERE tenant_id=? AND user_id=? AND location_id=?", (int)$r['tenant_id'], (int)$r['user_id'], (int)$r['warehouse_id']);
    if ($exists) continue;
    mexec("INSERT INTO user_inventory_locations (tenant_id, user_id, location_id) VALUES (?,?,?)", (int)$r['tenant_id'], (int)$r['user_id'], (int)$r['warehouse_id']);
    $migrated++;
}
echo "Migrated $migrated godown assignments.\n";
// Migrate user_shops -> shop location
$m2 = 0;
$shops = fetch_all("SELECT us.tenant_id, us.user_id, s.shop_id FROM user_shops us JOIN shops s ON s.shop_id=us.shop_id AND s.tenant_id=us.tenant_id");
foreach ($shops as $r) {
    $loc = fetch_one("SELECT warehouse_id FROM warehouses WHERE tenant_id=? AND shop_id=? AND location_type='shop' LIMIT 1", (int)$r['tenant_id'], (int)$r['shop_id']);
    if (!$loc) continue;
    $exists = fetch_one("SELECT 1 FROM user_inventory_locations WHERE tenant_id=? AND user_id=? AND location_id=?", (int)$r['tenant_id'], (int)$r['user_id'], (int)$loc['warehouse_id']);
    if ($exists) continue;
    mexec("INSERT INTO user_inventory_locations (tenant_id, user_id, location_id) VALUES (?,?,?)", (int)$r['tenant_id'], (int)$r['user_id'], (int)$loc['warehouse_id']);
    $m2++;
}
echo "Migrated $m2 shop assignments.\n";
echo "Done.\n";
