<?php
/** Phase 6.2: Sales Returns tables. */
declare(strict_types=1);
if (PHP_SAPI !== "cli") { http_response_code(403); exit("CLI only.\n"); }
require_once __DIR__ . "/../../config.php";
while (ob_get_level() > 0) ob_end_clean();
function mfail(string $m){ throw new RuntimeException($m); }
function mquery(string $sql){ global $conn; if($conn->query($sql)!==true) mfail($conn->error."\n".$sql); }
mquery("CREATE TABLE IF NOT EXISTS sales_returns (
  return_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  tenant_id INT UNSIGNED NOT NULL,
  return_number VARCHAR(50) NOT NULL,
  source_type ENUM('pos','invoice') NOT NULL DEFAULT 'pos',
  source_id INT UNSIGNED NOT NULL,
  customer_id INT UNSIGNED NULL,
  shop_id INT UNSIGNED NULL,
  warehouse_id INT UNSIGNED NULL,
  return_date DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  refund_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  status ENUM('completed','cancelled') NOT NULL DEFAULT 'completed',
  created_by INT UNSIGNED NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (return_id),
  UNIQUE KEY uq_return_number (tenant_id, return_number),
  KEY idx_sr_tenant (tenant_id),
  KEY idx_sr_source (tenant_id, source_type, source_id),
  CONSTRAINT fk_sr_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(tenant_id) ON DELETE CASCADE,
  CONSTRAINT fk_sr_shop FOREIGN KEY (shop_id) REFERENCES shops(shop_id) ON DELETE SET NULL,
  CONSTRAINT fk_sr_warehouse FOREIGN KEY (warehouse_id) REFERENCES warehouses(warehouse_id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
mquery("CREATE TABLE IF NOT EXISTS sales_return_items (
  return_item_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  return_id INT UNSIGNED NOT NULL,
  tenant_id INT UNSIGNED NOT NULL,
  product_id INT UNSIGNED NOT NULL,
  quantity DECIMAL(12,2) NOT NULL,
  unit_price DECIMAL(12,2) NOT NULL,
  refund_amount DECIMAL(12,2) NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (return_item_id),
  KEY idx_sri_return (return_id),
  KEY idx_sri_tenant (tenant_id),
  CONSTRAINT fk_sri_return FOREIGN KEY (return_id) REFERENCES sales_returns(return_id) ON DELETE CASCADE,
  CONSTRAINT fk_sri_product FOREIGN KEY (product_id) REFERENCES products(product_id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
// record migration
$stmt = $conn->prepare("INSERT INTO schema_migrations (migration, applied_at) VALUES (?, NOW()) ON DUPLICATE KEY UPDATE applied_at=applied_at");
$m = '016_sales_returns';
$stmt->bind_param('s', $m);
$stmt->execute();
$stmt->close();
echo "Migration 016 complete.\n";
