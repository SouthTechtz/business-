# Phase 1 Audit — DEZL Business System

**Date:** 2026-10-27  
**Scope:** Read-only architecture, database, inventory, sales, pricing, reporting, and security audit of `C:\xampp\htdocs\business`.  
**Status:** Phase 1 only. No application code, configuration, schema, or production data was changed. Local MariaDB was started solely to inspect the existing database read-only.  
**Evidence:** 56 PHP files, `database/schema_business_v2.sql`, `u839560293_agensupply.sql`, live local MariaDB 10.4.32 (`u839560293_agensupply`), `SYSTEM_OVERVIEW.md`, `STOCK_FLOW_REVIEW.md`. All PHP files pass `php -l` under PHP 8.2.12.

---

## 0. Executive summary

The application is a working single-store PHP/MySQL business suite. It is **not ready** for multi-shop operations, online order leads, reservations, transfers, wholesale pricing, or returns until a small number of foundational integrity and security problems are fixed. The most serious issues are not missing features; they are ways the current system can silently lose or corrupt stock, sales, and payment history.

**Highest-priority findings**

1. **Unauthenticated destructive scripts and a SQL dump are inside the web root.** `database/seed_agen.php` and `database/seed_demo.php` require only `config.php` and then truncate business tables. `u839560293_agensupply.sql` and `schema_business_v2.sql` are also web-accessible. There is no `.htaccess`. If the deployment includes these paths, anyone who knows the URL can wipe or download the database.
2. **The stock ledger is incomplete.** POS edits, invoice edits, POS deletes, invoice deletes, customer deletes, and product deletes change or remove stock without corresponding `inventory_movements`. Balances therefore cannot be reconciled to the ledger.
3. **Selling flows do not agree on a store.** Cart availability checks aggregate all warehouses; checkout, invoice, cancel, and edit use the tenant's lowest-numbered warehouse, not the session or user's chosen store.
4. **Concurrency is unsafe.** There is no `SELECT ... FOR UPDATE`, no affected-row check, and several read-modify-write updates. Concurrent sales can oversell; concurrent payments can overpay; concurrent invoice creation can collide.
5. **Prices are client-controlled.** `ajax-cart-update.php` stores a price posted by the browser, and invoice add/edit accept posted unit prices. The server does not re-derive the price at checkout.
6. **Normal UI actions destroy history.** Deleting a customer deletes their invoices; deleting a sale deletes its order/items/payments without restoring stock; deleting a product cascades `inventory_stock` and `inventory_movements`.
7. **Tenant isolation has gaps.** Contact IDs, warehouse IDs, and role IDs from POST are not always verified against the current tenant.
8. **The supplied production dump is not a valid backup.** It contains `pos_order_items` rows without `pos_orders`, and no products, while `AUTO_INCREMENT` counters show prior data. The local database is similarly partial.

**Verdict:** Do not start Phase 2 by changing the live schema. First obtain a verified production backup, remove the web-root exposure, and fix the inventory write paths. Phase 2 (shops/stores) is additive and can be prepared in parallel, but every sale, invoice, transfer, reservation, and return must use one centralized, transactional stock service.

---

## 1. Environment and database identity

### 1.1 Confirmed runtime configuration

`config.php` currently:

- `DB_HOST=127.0.0.1`, `DB_PORT=3306`, `DB_USER=root`, empty password, `DB_NAME=u839560293_agensupply` (lines 6–10).
- `BASE_URL=https://agengeneralsuplly.dezl.org/` (line 12), `APP_NAME=DEZL BUSINESS SYSTEM` (line 13), timezone `Africa/Dar_es_Salaam` (line 15).
- The local application bootstraps successfully against the local MariaDB: `users=1`, `products=0` (verified by a CLI bootstrap against the current config).
- **The production database credentials are not in this local config.** This is a local-dev configuration that points at a database named like the production database. It must not be deployed as-is. `u839560293_agensupply` is the runtime DB name; `agen_business_v2` is not present locally.
- **Security note:** `root` with an empty password is a local-only convenience, not a deployable configuration. `config.php:22–24` also calls `die("Connection failed: " . $conn->connect_error)`, which can disclose host/user details.

### 1.2 Database and PHP version mismatch

- Local live DB: MariaDB **10.4.32**, InnoDB, `utf8mb4_unicode_ci`.
- Dump header: MariaDB **11.8.9**, phpMyAdmin 5.2.2, PHP **7.2.34** (`u839560293_agensupply.sql` lines 1–8).
- Application source uses `match` (`save-settings.php:37`, `invoice-print.php`), `str_starts_with` (`save-settings.php:26,53`), and arrow functions (`helpers.php`, multiple pages). It therefore requires **PHP 8.0+** (7.4 for arrows; 8.0 for `match`/`str_starts_with`). Local CLI is 8.2.12 and all files pass `php -l`.
- **Unknown / must verify:** the Hostinger site PHP version. If it is 7.2/7.4, the application will fatal on the above constructs. Do not deploy until 8.0+ is confirmed (8.1/8.2 preferred).

### 1.3 Local database state (live inspection)

| Entity | Rows | Notes |
|---|---:|---|
| tenants | 1 | `DEZL INVESTMENT LIMITED`, TZS |
| users | 1 | one active admin |
| warehouses | 1 | `Main Shop` (id 1) |
| pos_sessions | 1 | open (`is_closed=0`), warehouse 1, started 2026-08-28 |
| system_settings | 1 | invoice prefix `INV-`, next number `1002`, tax on |
| products | 0 | |
| inventory_stock | 0 | |
| inventory_movements | 0 | |
| pos_orders / pos_order_items | 0 / 0 | |
| invoices / invoice_items / payments | 0 / 0 / 0 | |
| contacts | 0 | |
| audit_logs | 55 | logins, 2 sale creates, 1 sale delete, 1 invoice create, expenses |

The audit log proves transaction activity existed, but the transaction tables are empty. The local database is a **partial/cleared state**, not a coherent snapshot. It is not a reliable baseline for historical behavior.

Other live data: 13 permissions, Administrator role has 12; `manage_contacts` is missing. Six document statuses, four payment methods, eight expense categories, two tax rates, five units of measure, two product categories, three contact types.

### 1.4 The supplied dump is internally inconsistent

`u839560293_agensupply.sql`:

- `warehouses` contains `(1, 1, 'Main Shop')`.
- `pos_order_items` contains rows 1–14 referencing `order_id` 1–8 and `product_id` 1–19.
- There are **no** `pos_orders` rows and **no** `products` rows; there are no `inventory_stock` or `inventory_movements` rows.
- `pos_order_items` has FK `fk_poi_order` to `pos_orders`; importing with FK checks enabled fails at that INSERT. The dump does not disable FK checks.
- `AUTO_INCREMENT` counters (orders 9, items 15, products 25, stock 25, movements 19, invoices 2, contacts 6) confirm prior data existed.
- **Conclusion:** do not treat this dump as a backup, and do not run migrations based on it. Obtain a fresh, verified production dump and a restore test.

### 1.5 Schema-file drift

- `database/schema_business_v2.sql` creates and uses `agen_business_v2` (lines 2–3).
- It seeds a different tenant (`My Business`, `admin@mybusiness.local`, lines 550–551, 612) from the live `DEZL INVESTMENT LIMITED`.
- The runtime DB is `u839560293_agensupply`; `agen_business_v2` does not exist locally.
- The schema file is a **parallel/dev schema**, not the source of truth. Regenerate a baseline from the verified live DB before writing migrations.

### 1.6 Permissions data defect

The live Administrator role has 12 of 13 permissions. `manage_contacts` is not assigned. The Customers menu is hidden by `sidebar.php`, and `customers.php:4` would return 403. The seeder assigns all 13 (`seed_demo.php:299`), so this is a data defect, not intended behavior.

---

## 2. Architecture and authorization

- **Pattern:** 56 standalone PHP page endpoints; no router, framework, Composer, autoloader, migrations, or tests. Shared bootstrap is `config.php` → `helpers.php` + `User.php`. Protected pages include `header.php`, then `auth_check.php`; layout uses `navbar.php`/`sidebar.php`/`footer.php`.
- **Authentication:** session `user_id`; `User::getUserById()` loads the user; `auth_check.php` rejects missing/inactive users. Password verification uses `password_verify` (`User.php:48`). Login does **not** call `session_regenerate_id()` (`login.php:14–20`); cookie flags are not set (`config.php:18`); there is no rate limiting or lockout.
- **Authorization:** global `permissions` (13 rows, no `tenant_id`) + tenant-scoped `roles` + `role_permissions` + `user_roles`. `User::hasPermission()` caches the union of role permissions (`User.php:82–112`). Pages call `require_permission()`; sidebar gating is cosmetic.
- **Tenant context:** `$current_user->tenant_id` is used in most queries. This is the correct anchor, but there are gaps in §8.
- **Include-order fragility:** pages include `header.php` (which emits HTML) before `auth_check.php`; `ob_start()` + `redirect()` mostly hides it, but any warning/error can leak output before redirect. Prefer auth before layout.
- **No CSRF protection** anywhere; several state-changing actions are GET links (sale cancel, invoice/product/customer delete). This is both CSRF and accidental-action risk.

Known permissions: `manage_users`, `manage_contacts`, `manage_suppliers`, `manage_products`, `manage_inventory`, `manage_pos`, `manage_invoices`, `manage_receipts`, `view_reports`, `view_dashboard`, `manage_expenses`, `view_activity_log`, `manage_settings`.

---

## 3. Inventory and sales schema

### 3.1 Inventory location model

`warehouses` is the only stock-location entity:

- Columns: `warehouse_id`, `tenant_id`, `name` only. No code, address, active flag, shop/branch, or default flag.
- One row: `Main Shop`.
- No UI exists to create, edit, or close warehouses. Multi-store is currently impossible without direct SQL.

`inventory_stock`:

- One row per `(product_id, warehouse_id)` via `uq_stock`; not tenant-inclusive, but product/warehouse are tenant-bound.
- Columns: `quantity`, `reorder_level`, `production_date`, `expires_at`, `last_counted_at`, `updated_at`.
- No `CHECK (quantity >= 0)`. SQL mode is non-strict (`NO_ZERO_IN_DATE,NO_ZERO_DATE,NO_ENGINE_SUBSTITUTION`), so negative values are possible.
- Single-row batch fields mean restocking overwrites `production_date`/`expires_at`; the model cannot track multiple batches or expiry. This is a known limitation; do not build expiry-dependent features without a separate approved batch design.

`inventory_movements`:

- `movement_type` is free text; quantity is a **positive magnitude** (see §4.5).
- `reference_id`/`reference_type` are polymorphic and have no FK.
- FKs `fk_im_product`, `fk_im_wh`, `fk_im_tenant` are all **ON DELETE CASCADE**.
- No index on `created_at`, `(reference_type, reference_id)`, or `(tenant_id, created_at)`.

### 3.2 Sales, invoice, and payment tables

- `pos_sessions.warehouse_id` is nullable and **has no index or FK**. Sessions are never closed: no `UPDATE pos_sessions ... is_closed` exists. Checkout reuses the latest open session regardless of user or store (`sale-add.php:118`). The live DB has one session open since 2026-08-28.
- `pos_orders`: no `warehouse_id`; `order_number` has **no unique constraint**; generated as `POS-YYYYMMDD-NNN` with `mt_rand(1,999)` (`sale-add.php:129`), so collisions are likely at moderate daily volume.
- `pos_order_items`: no `tenant_id`, no `warehouse_id`, no FK on `product_id` (product deletion leaves orphan line references).
- `invoices`: no `warehouse_id`; `invoice_number` unique per tenant; `status_id` FK `SET NULL`.
- `invoice_items`: has `tenant_id`, but no `warehouse_id`, no FK on `product_id`; `tax_rate_id` is always inserted as `NULL` (`invoice-add.php:103–104`), and line `discount` exists but is always 0.
- `payments`: invoice-only (`invoice_id NOT NULL`), cascades on invoice delete.
- `pos_payments`: separate POS payment ledger; inserted only when `contact_id` is set (`sale-add.php:170–179`), so walk-in cash sales have no payment row.
- No `refunds`, `credit_notes`, reservations, transfer, return, lead/order, shop, or wholesale tables/columns exist.

### 3.3 Constraints that destroy history

Verified delete rules:

- `inventory_stock` and `inventory_movements` → `products`, `warehouses`, `tenants` **CASCADE**.
- `pos_orders` → `tenants` CASCADE; `pos_order_items`/`pos_payments` → `pos_orders` CASCADE.
- `invoices` → `tenants` CASCADE; `invoice_items`/`payments` → `invoices` CASCADE.
- `contacts` → `invoices` RESTRICT (but the app deletes invoices manually first; see §4).
- `invoice_items.product_id` and `pos_order_items.product_id` have **no FK**, so product deletion leaves dangling line references while cascading stock/movement history.

### 3.4 Missing indexes

No indexes on: `pos_sessions.warehouse_id`; `pos_order_items.product_id`; `invoice_items.product_id`; `pos_orders.order_date`; `payments.payment_date`; `inventory_movements.created_at`/reference; `audit_logs.created_at`. Reporting uses `DATE()`/`DATE_FORMAT()` on columns, which also prevents index use.

---

## 4. Stock-changing code paths (confirmed trace)

| # | Flow | File / lines | Warehouse used | Stock change | Movement written? | Transaction? | Main defects |
|---|---|---|---|---|---|---|---|
| 1 | POS add/update cart | `sale-add.php:13–61`, `ajax-cart-update.php:7–40` | None (checks **sum of all warehouses**) | None | n/a | n/a | Aggregate availability disagrees with store used; client can overwrite price |
| 2 | POS checkout | `sale-add.php:76–189` | Lowest `warehouse_id` for tenant (`87–89`) | `quantity = quantity - ?` (`152–158`) | `sale` (`160–166`) | Yes | No re-check at checkout; no affected-row check; fallback to warehouse id 1; payment only for registered customer; random order number |
| 3 | POS cancel | `sale-cancel.php:13–47` | Lowest warehouse (`13–15`) | `quantity = quantity + ?` | `sale_cancel` | Yes | Restores to wrong store after multi-store; no idempotency guard beyond order status; product-deleted edge |
| 4 | POS delete | `sale-delete.php:13–28` | None | **None** | **None** | Yes | Deletes order/items/payments; stock is not restored; history lost |
| 5 | POS edit | `sale-edit.php:23–144` | Lowest warehouse (`23–25`) | +/- deltas (`41–88`) | **None** | Yes | No movements; only checks positive delta; read-then-write; no lock; posted prices |
| 6 | Invoice add | `invoice-add.php:40–139` | Lowest warehouse (`67–69`) | `quantity = quantity - ?` (`115–121`) | `invoice` (`123–129`) | Yes | Posted prices; per-line check allows duplicate product lines to oversell; no affected-row check; contact not tenant-validated |
| 7 | Invoice edit | `invoice-edit.php:30–158` | Lowest warehouse (`30–32`) | +/- deltas (`41–90`) | **None** | Yes | No movements; no lock; posted prices; recomputes tax at current product rate; product lookup lacks tenant |
| 8 | Invoice delete | `invoices.php:8–27` | None | **None** | **None** | No | Deletes items/invoice; stock not restored; only blocked if payments exist |
| 9 | Customer delete | `customers.php:8–42` | None | **None** | **None** | Yes | Deletes the customer's invoices/items to satisfy FK; stock not restored; destroys receivables history |
| 10 | Product delete | `products.php:41–50` | None | Cascades stock + movements | Cascade | No | Destroys all stock/movement history; leaves orphan line product IDs |
| 11 | Manual restock | `inventory.php:8–61` | Selected stock row | `quantity = quantity + ?` | `restock` | Yes | Good atomic increment; validates decimal units; no store-access restriction |
| 12 | Adjust/restock | `inventory-adjust.php:9–68` | Posted `warehouse_id` | Absolute `quantity = ?` (race) | `restock` | Yes | Read-modify-write; warehouse not tenant-validated; only positive quantities (no downward correction UI) |
| 13 | Product opening stock | `product-add.php:47–72` | First tenant warehouse | Insert stock | `adjustment` | Yes | No decimal-unit validation; silently skips if no warehouse |

### 4.1 POS price path (important)

- Cart add stores `sale_price` from the product (`sale-add.php:30`).
- `ajax-cart-update.php` accepts `price` from POST (`line 8`) and writes it into the session (`line 38`); checkout uses the session price (`sale-add.php:94–108`). A crafted request can set any non-negative price.
- Invoice add/edit take `price[]`/`new_price[]` from POST (`invoice-add.php:20`, `invoice-edit.php:24,28`) and store it directly (`invoice-add.php:107–110`, `invoice-edit.php:93–100`). No server-side price lookup is used.

### 4.2 Movement convention and ledger completeness

Observed movement types in code: `sale`, `sale_cancel`, `invoice`, `restock`, `adjustment`. All inserts use a **positive quantity**; direction is implied by the type:

- Out: `sale`, `invoice`.
- In: `sale_cancel`, `restock`, `adjustment` (opening/addition).

There is no `return`, `transfer`, `writeoff`, or edit-reversal type.

Movements are **not** written for: POS edit, invoice edit, POS delete, invoice delete, customer delete, product delete. Therefore:

- `SUM(inventory_stock.quantity)` cannot be reconciled to `inventory_movements` today.
- Any future reconciliation must first backfill/normalize the ledger and define a direction map.

### 4.3 Negative stock and rounding behavior

- No `FOR UPDATE` anywhere in the codebase (verified).
- SQL mode is non-strict; no `CHECK` on `inventory_stock.quantity`.
- Checkout and invoice deduction do not check `affected_rows`; if the stock row is missing, the sale still succeeds (the movement insert may succeed because the warehouse exists) → stock silently not deducted.
- If no warehouse exists for the tenant, code falls back to `warehouse_id = 1`; if that belongs to another tenant, the movement can be recorded against a cross-tenant warehouse while the balance update matches nothing.
- Whole-unit (`is_decimal=0`) is enforced only in manual stock operations (`inventory.php`, `inventory-adjust.php`), not in POS/invoice quantities.

### 4.4 Duplicate/invalid line behavior

`invoice-add.php` builds line items from POST and checks stock per line. Two lines for the same product each pass independently, so the total can exceed on-hand. The POS cart is keyed by product ID and merges duplicates, so this affects invoices rather than POS.

---

## 5. Low-stock logic

Two thresholds exist but are used inconsistently:

- `products.min_stock_level` is the **only** threshold actually compared.
- `inventory_stock.reorder_level` is written and displayed (`inventory-adjust.php:51–54`, `product-add.php:60–62`, `product-view.php:105`) but **never read in a comparison**.
- Dashboard (`index.php:80–89`): sums stock across **all warehouses** per product, compares to `p.min_stock_level`, limit 5.
- Inventory (`inventory.php`): per selected warehouse, compares to `p.min_stock_level`.
- Product view (`product-view.php:101–107`): per warehouse badge vs `p.min_stock_level`; displays `reorder_level`.
- Stock report (`report-print.php:133–142`): one row per product/warehouse; no low-stock flag; shows `p.min_stock_level`.
- `is_service=0` is excluded in dashboard/report; service stock is blocked in manual stock.

Result: "low stock" means different things on different pages, and it has no shop/store concept. This must be standardized before location-aware alerts.

---

## 6. Pricing and tax

- Products have one selling price (`sale_price`); no wholesale price, price tier, customer class, or effective dates.
- Transaction lines store `unit_price` snapshots, so a normal product-price edit does **not** alter historical POS/invoice lines. This is good and must be preserved.
- However, invoice edit recomputes tax using the **current** product `tax_rate` (`invoice-edit.php:92–94`), so editing an old invoice can change its historical tax. It also writes the posted unit price.
- Tax is `product.tax_rate` percent applied when `system_settings.apply_tax=1`; `invoice_items.tax_rate_id` is always `NULL`, and the `tax_rates` table is only managed in Settings. The tax-rate table is not the sales source of truth.
- POS header discount is supported (`pos_orders.discount`); invoice line `discount` is always 0. No discount policy or authorization exists.
- `pos_payments` is only written for registered customers, so cash/mobile walk-in payments are absent from the payments ledger. `reports.php` "payments by month" reads only `payments` (invoice payments), not `pos_payments` (`reports.php:59–65`).

---

## 7. Reporting and sales sources of truth

- Two parallel sales ledgers: `pos_orders`/`pos_order_items`/`pos_payments` and `invoices`/`invoice_items`/`payments`.
- Dashboard month-to-date sales uses `pos_orders` (`index.php:42–48`); the 12-month chart uses `invoices` (`index.php:50–62`); the invoice-status chart uses invoices. These are different populations shown together.
- `reports.php` monthly sales is invoice-based; POS summary is separate; the income statement (`report-print.php:32–43`) adds completed POS totals + invoice payments. Today they are separate channels, but any future flow that writes both for one order will double-count.
- Exports (`export-sales.php`, `export-invoices.php`, `export-receipts.php`, `export-expenses.php`) use prepared statements and are tenant-scoped; they are not the main risk.
- `reports.php` date filters are interpolated raw into SQL (`lines 11, 33–37, 71, 78`), allowing SQL injection for a user with `view_reports`. `report-print.php` validates dates with a regex (good).
- Date filtering uses `DATE()`/`DATE_FORMAT()` on columns, which defeats indexes; acceptable at small scale, but will matter.

---

## 8. Security and tenant isolation

### Critical / High

1. **Unauthenticated destructive scripts + public dump** — `database/seed_agen.php` and `seed_demo.php` require only `config.php`, then `TRUNCATE`/`DELETE` business tables (`seed_agen.php:12–18`); `u839560293_agensupply.sql` and `schema_business_v2.sql` are in the web root. No `.htaccess` exists anywhere. If the deployment includes these paths, anyone can wipe or download the DB. **Immediate remediation required before any feature work.**
2. **SQL injection** — `reports.php` raw `from`/`to` interpolation (`lines 37, 71, 78`). Requires `view_reports`, but is exploitable by a staff account and can read other tenants' data.
3. **Client-controlled prices** — POS `ajax-cart-update.php:8,38`; invoice `invoice-add.php:20`, `invoice-edit.php:24,28`. Revenue manipulation / arbitrary discounts.
4. **CSRF / unsafe GET** — no tokens; `sale-cancel.php?id=`, `sale-delete.php?id=`, `invoices.php?delete=`, `products.php?delete=`, `customers.php?delete=` are GET. A logged-in admin can be tricked into deleting or cancelling records.
5. **Destructive deletes** — see §4; normal UI actions erase financial/inventory history.
6. **Cross-tenant reference gaps** —
   - `permissions.php:48–85` toggles `role_permissions` without verifying `$rid` belongs to the current tenant; a crafted POST can alter another tenant's role.
   - `sale-add.php`, `sale-edit.php`, and `invoice-add.php` accept `contact_id` without a tenant lookup; `invoices`/`pos_orders` FKs do not enforce tenant.
   - `inventory-adjust.php` accepts `warehouse_id` without verifying it belongs to the tenant.
   - `invoice-edit.php:43` fetches a product by ID without a tenant filter.
7. **Admin missing `manage_contacts`** — live data defect; the only admin cannot open Customers.
8. **Seed scripts and `.sql` dump in web root** — also information disclosure (schema, audit details).

### Medium

9. No `session_regenerate_id()` at login; no `HttpOnly`/`Secure`/`SameSite` cookie flags; no login throttling/lockout.
10. DB exception messages are shown to users (`sale-add.php:188`, `sale-edit.php:147`, `invoice-add.php:146`, `invoice-edit.php:158`, `inventory.php:59`, `inventory-adjust.php:67`, `save-settings.php:79`); can reveal SQL/schema.
11. Logo upload trusts the client-supplied MIME (`save-settings.php:32`) and accepts SVG (line 31); an SVG served from `uploads/` can execute script in the app origin (stored XSS). No `uploads/` dir exists yet.
12. `config.php` local `root`/empty password + production URL; if this file is ever deployed, the DB is exposed. No environment separation.
13. `seed_agen.php` and `seed_demo.php` are re-runnable and destructive; `seed_demo` also deletes data (`19–30`) and inserts demo records.
14. No audit entries for stock adjustments, product edits, invoice edits/deletes, sale edits/deletes (only create/cancel/selected actions).
15. `pos_orders.order_number` is random and non-unique; collision can merge/lookup the wrong sale in reports or links.
16. `pos_sessions` never closes, so session/store provenance is unreliable.

### Low

17. `default.php` is a Hostinger placeholder page, not an app route.
18. Duplicate asset directories (`css/`, `js/`, `fonts/`, `img/`, `images/` plus `assets/`); dead assets increase maintenance risk.
19. `activities`, `deals`, `journal_entries` are referenced only for deletion guards; no module uses them. `product_variants` has only a delete path. `tax_rates` is managed but not used by sales.
20. Docs/`.bat` in web root (information disclosure).
21. No automated tests, migrations, or CI; no `.gitignore`/repo metadata.

---

## 9. Data-loss and historical-record risks

These are the behaviors that must change before new features:

- **Never hard-delete** products, warehouses/stores, customers, sales, or invoices that have history. Prefer deactivation/void/cancel with explicit reversal movements.
- **Change cascades** on `inventory_stock`/`inventory_movements` from `ON DELETE CASCADE` to `RESTRICT` (or soft-delete only), after confirming no orphan cleanup is needed.
- **Stop deleting invoices when deleting a customer** (`customers.php:22–34`). Block the customer deletion instead.
- **Stop deleting sales** (`sale-delete.php`); use cancel with stock reversal once, or restrict deletion to sales with no payments/movements and no history.
- **Stop deleting invoices** (`invoices.php:8–27`); use a Void/Cancelled status and a reversing stock movement if stock was deducted.
- **Keep line-level `unit_price` snapshots** on every transaction; never recompute historical lines from current product prices.
- **Preserve `warehouse_id=1`** and all existing stock/movement rows when introducing shops.
- **Do not run `seed_agen.php`/`seed_demo.php`** against any environment containing real data. Remove them from the web root immediately.

---

## 10. Confirmed facts, inferences, unknowns

### Confirmed

- Runtime DB `u839560293_agensupply`; local MariaDB 10.4.32; InnoDB/utf8mb4.
- Local state: 1 tenant, 1 admin, 1 warehouse `Main Shop`, 1 open POS session, 0 products/stock/movements/orders/invoices/contacts; 55 audit logs.
- One stock row per product per warehouse; positive movement magnitudes; types `sale`, `sale_cancel`, `invoice`, `restock`, `adjustment`.
- POS/invoice flows use the lowest-numbered warehouse; cart checks aggregate all warehouses; no `FOR UPDATE`; no affected-row checks.
- Sale/invoice edits update stock without movements; deletes/customer/product deletes remove or cascade history.
- No wholesale price, shops, reservations, transfers, returns, leads, refunds, or price-type columns/tables.
- `reorder_level` is never compared; `min_stock_level` is used inconsistently across pages.
- `invoices`/`pos_orders` have no warehouse; `pos_sessions.warehouse_id` has no index/FK; sessions never close.
- `invoice_items.tax_rate_id` is always NULL; tax comes from `products.tax_rate`.
- Client-posted prices are accepted by POS cart update and invoice add/edit.
- No CSRF tokens; no session regeneration; no rate limiting; errors leak DB messages.
- Admin role is missing `manage_contacts`.
- `database/seed_*.php` and SQL dumps are web-accessible and destructive/sensitive; no `.htaccess`.
- All PHP files pass `php -l` on PHP 8.2.12.

### Inferences (verify)

- Historical POS/invoice stock was deducted from the tenant's lowest warehouse at the time (because the code always did this), so backfilling provenance to the lowest warehouse is likely correct. Verify against any available movement ledger before finalizing.
- The local DB was partially imported or cleared; the exact cause is unknown.
- Production has historical products/orders/invoices beyond the dump (AUTO_INCREMENT implies this), but row counts and integrity are unknown.
- Hostinger PHP may be 7.2/7.4 based on the dump header; unverified.
- The single open POS session is reused for all sales; no close workflow exists.

### Unknown (blocking/decision)

See §16. Key unknowns: negative-stock policy, wholesale selection rules, online fulfillment document, refunds, shop access, transfer approval roles, low-stock threshold source, production data/backup, PHP version.

---

## 11. Findings register (prioritized)

| ID | Severity | Finding | Evidence | Impact | Phase |
|---|---|---|---|---|---|
| F01 | Critical | Public destructive seed scripts + SQL dump in web root | `database/seed_agen.php:7,12–18`; `seed_demo.php:10–30`; no `.htaccess`; `*.sql` in root | DB wipe / schema disclosure | Immediate hotfix |
| F02 | Critical | Incomplete stock ledger (edits/deletes) | `sale-edit`, `invoice-edit`, `sale-delete`, `invoices.php:8–27` | Unreconcilable stock, hidden losses | 3 |
| F03 | Critical | History destroyed by deletes/cascades | `sale-delete`, `customers.php:22–34`, `products.php:41–50`, FK CASCADE | Loss of audit/financial/stock history | 3 |
| F04 | Critical | SQL injection in reports | `reports.php:37,71,78` | Cross-tenant data theft | 3 hotfix |
| F05 | High | Client-controlled prices | `ajax-cart-update.php:8,38`; `invoice-add.php:20`; `invoice-edit.php:24,28` | Revenue manipulation | 5 |
| F06 | High | No concurrency control | no `FOR UPDATE`; `sale-add:152`; `invoice-add:115`; `inventory-adjust:43` | Oversell/negative stock/duplicate | 3 |
| F07 | High | Store attribution ambiguous/wrong | `sale-add:87–89`; `sale-cancel:13–15`; `invoice-add:67–69`; `pos_sessions` | Stock deducted from wrong store | 2–3 |
| F08 | High | No CSRF; GET deletes | no tokens; `sale-cancel`, `sale-delete`, `invoices.php:122`, `products.php:41`, `customers.php:8` | Forced actions | 3 |
| F09 | High | Cross-tenant reference gaps | `permissions.php:48–85`; contact/warehouse IDs | Cross-tenant mutation/leak | 3 |
| F10 | High | Missing admin permission | live DB: 12/13, `manage_contacts` absent | Customers inaccessible | Immediate data fix |
| F11 | High | One open POS session forever; no store on order | `pos_sessions`; `sale-add:118`; no close code | Unreliable provenance | 2 |
| F12 | High | Hardcoded local root/empty config + prod URL | `config.php:6–10` | Credential/deployment risk | Immediate |
| F13 | Medium | Two low-stock definitions; reorder_level unused | `index:80–89`; `inventory`; `product-view:104`; `report-print:133` | Wrong alerts by location | 2–3 |
| F14 | Medium | Two sales/payment ledgers | `payments` vs `pos_payments`; `reports:59–78` | Understated/duplicate reporting | 5/8 |
| F15 | Medium | Order number collisions | `sale-add:129`; no unique index | Duplicate references | 3 |
| F16 | Medium | Invoice counter concurrency | `invoice-add:60–65,133–137`; unique invoice no | Failed invoices/duplicates | 3 |
| F17 | Medium | Session/cookie hardening absent | `login.php:14–20`; `config.php:18` | Session hijack/fixation | 3 |
| F18 | Medium | Error messages leak DB details | catch blocks | Info disclosure | 3 |
| F19 | Medium | SVG upload + client MIME | `save-settings.php:31–43`; `settings.php:138` | Stored XSS | 3 |
| F20 | Medium | No unit-decimal enforcement in sales/invoices | `is_decimal` only in stock pages | Fractional whole units | 3 |
| F21 | Medium | No audit for stock/edit/delete | `audit_log` usage | Weak accountability | 3 |
| F22 | Medium | Reports use non-sargable date filters | `DATE()`/`DATE_FORMAT()` | Slow at scale | 8 |
| F23 | Low | Placeholder `default.php`, duplicate asset dirs | `default.php`; dirs | Confusion/maintenance | 8 |
| F24 | Low | Unused tables/modules | `activities`, `deals`, `journal_entries`, `product_variants`, `tax_rates` | Dead weight | 8 |
| F25 | Low | No migrations/tests/CI | no dirs/files | Risky changes | All |

---

## 12. Target data model (proposal — not executed)

Design principles:

- Keep `warehouses` as the store table; do not create a parallel stock-location system. A **shop** is an operating location; a **store** is a stock location belonging to a shop (`warehouses.shop_id`).
- Preserve all existing IDs (`warehouse_id=1`, tenant 1) and historical rows.
- One centralized inventory service for every stock mutation; positive movement magnitudes + type-to-direction map.
- Additive migrations first; destructive/constraint changes only after backup and cleanup.
- One sales source of truth per order; do not write both a POS order and an invoice for the same fulfillment unless explicitly linked.

### 12.1 Shops and stores

```sql
CREATE TABLE shops (
  shop_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  tenant_id INT UNSIGNED NOT NULL,
  name VARCHAR(191) NOT NULL,
  code VARCHAR(50) NOT NULL,
  address TEXT NULL,
  phone VARCHAR(50) NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (shop_id),
  UNIQUE KEY uq_shop_code (tenant_id, code),
  UNIQUE KEY uq_shop_name (tenant_id, name),
  KEY fk_shop_tenant (tenant_id),
  CONSTRAINT fk_shop_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(tenant_id) ON DELETE CASCADE
);

ALTER TABLE warehouses
  ADD COLUMN shop_id INT UNSIGNED NULL AFTER tenant_id,
  ADD COLUMN code VARCHAR(50) NULL AFTER name,
  ADD COLUMN is_active TINYINT(1) NOT NULL DEFAULT 1 AFTER code,
  ADD UNIQUE KEY uq_store_code (tenant_id, shop_id, code),
  ADD KEY fk_store_shop (shop_id),
  ADD CONSTRAINT fk_store_shop FOREIGN KEY (shop_id) REFERENCES shops(shop_id) ON DELETE RESTRICT;

CREATE TABLE user_shops (
  tenant_id INT UNSIGNED NOT NULL,
  user_id INT UNSIGNED NOT NULL,
  shop_id INT UNSIGNED NOT NULL,
  PRIMARY KEY (tenant_id, user_id, shop_id),
  KEY fk_us_shop (shop_id),
  CONSTRAINT fk_us_user FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE,
  CONSTRAINT fk_us_shop FOREIGN KEY (shop_id) REFERENCES shops(shop_id) ON DELETE RESTRICT,
  CONSTRAINT fk_us_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(tenant_id) ON DELETE CASCADE
);
```

- Backfill: one shop per tenant (name from `system_settings.company_name` or `tenants.name`); `warehouses.shop_id` = that shop; `code='STORE-'||warehouse_id`; `is_active=1`.
- `user_shops`: map every existing user to every existing shop; admins (`manage_shops`) bypass. This avoids lockout; restrict later.
- New permission `manage_shops`; helpers `current_shop()`, `assert_shop_access($shop_id)`; store dropdowns filter by accessible shops and `is_active`.
- New UI: Shops/Stores management. Keep the `warehouses` table/ID for compatibility; label "Stores" in UI.

### 12.2 Transaction provenance

```sql
ALTER TABLE pos_orders
  ADD COLUMN warehouse_id INT UNSIGNED NULL AFTER session_id,
  ADD COLUMN price_type ENUM('retail','wholesale') NOT NULL DEFAULT 'retail' AFTER warehouse_id,
  ADD KEY fk_order_wh (warehouse_id),
  ADD CONSTRAINT fk_order_wh FOREIGN KEY (warehouse_id) REFERENCES warehouses(warehouse_id) ON DELETE RESTRICT;

ALTER TABLE pos_order_items
  ADD COLUMN warehouse_id INT UNSIGNED NULL AFTER product_id,
  ADD KEY fk_poi_wh (warehouse_id),
  ADD KEY fk_poi_product (product_id),
  ADD CONSTRAINT fk_poi_wh FOREIGN KEY (warehouse_id) REFERENCES warehouses(warehouse_id) ON DELETE RESTRICT;

ALTER TABLE invoices
  ADD COLUMN warehouse_id INT UNSIGNED NULL AFTER contact_id,
  ADD COLUMN price_type ENUM('retail','wholesale') NOT NULL DEFAULT 'retail' AFTER warehouse_id,
  ADD KEY fk_invoice_wh (warehouse_id),
  ADD CONSTRAINT fk_invoice_wh FOREIGN KEY (warehouse_id) REFERENCES warehouses(warehouse_id) ON DELETE RESTRICT;

ALTER TABLE invoice_items
  ADD COLUMN warehouse_id INT UNSIGNED NULL AFTER product_id,
  ADD KEY fk_ii_wh (warehouse_id),
  ADD KEY fk_ii_product (product_id),
  ADD CONSTRAINT fk_ii_wh FOREIGN KEY (warehouse_id) REFERENCES warehouses(warehouse_id) ON DELETE RESTRICT;
```

- Backfill from the **lowest warehouse ID per tenant** (actual historical deduction), not from the session. Record the mapping in a migration audit table. If a movement ledger exists and disagrees, use the movement's warehouse and flag the row.
- New code: header + line warehouse must be non-null; store selection is explicit; items from different stores are not allowed in one sale (or are split; decision).
- Add `source`/`lead_id` to the chosen sales document if online fulfillment uses POS/invoice.

### 12.3 Pricing

```sql
ALTER TABLE products
  ADD COLUMN wholesale_price DECIMAL(12,2) NULL AFTER sale_price;
```

- `price_type` on transaction headers/lead items; server resolves price:
  - retail → `products.sale_price`
  - wholesale → `products.wholesale_price` (block if NULL unless a fallback is approved)
- Never accept a price from the browser. If manual price override is a real requirement, add a dedicated permission + reason + audit, and store the overridden price on the line.
- Preserve `unit_price` snapshots; invoice edits keep original price/tax unless the line/product or quantity changes, and use the line's original effective tax rate, not the current product rate.
- No wholesale eligibility/minimum quantity until defined by the business.

### 12.4 Online orders, leads, reservations

```sql
CREATE TABLE leads (
  lead_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  tenant_id INT UNSIGNED NOT NULL,
  reference VARCHAR(40) NOT NULL,
  source ENUM('online_order') NOT NULL DEFAULT 'online_order',
  customer_name VARCHAR(191) NOT NULL,
  customer_phone VARCHAR(50) NOT NULL,
  customer_email VARCHAR(191) NULL,
  customer_location VARCHAR(255) NOT NULL,
  lead_status ENUM('new','contacted','confirmed','cancelled','unreachable') NOT NULL DEFAULT 'new',
  fulfillment_status ENUM('unfulfilled','reserved','ready','fulfilled','cancelled') NOT NULL DEFAULT 'unfulfilled',
  payment_status ENUM('unpaid','partial','paid','refunded') NOT NULL DEFAULT 'unpaid',
  notes TEXT NULL,
  assigned_to INT UNSIGNED NULL,
  confirmed_by INT UNSIGNED NULL,
  confirmed_at DATETIME NULL,
  cancelled_at DATETIME NULL,
  converted_order_id INT UNSIGNED NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (lead_id),
  UNIQUE KEY uq_lead_ref (tenant_id, reference),
  KEY idx_lead_queue (tenant_id, lead_status, created_at),
  CONSTRAINT fk_lead_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(tenant_id) ON DELETE CASCADE
);

CREATE TABLE lead_items (
  lead_item_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  tenant_id INT UNSIGNED NOT NULL,
  lead_id INT UNSIGNED NOT NULL,
  product_id INT UNSIGNED NULL,
  description_snapshot VARCHAR(255) NOT NULL,
  quantity DECIMAL(12,2) NOT NULL,
  unit_price_estimated DECIMAL(12,2) NOT NULL,
  unit_price_confirmed DECIMAL(12,2) NULL,
  price_type ENUM('retail','wholesale') NOT NULL DEFAULT 'retail',
  warehouse_id INT UNSIGNED NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (lead_item_id),
  KEY idx_lead_item_lead (lead_id),
  KEY idx_lead_item_product (product_id),
  CONSTRAINT fk_li_lead FOREIGN KEY (lead_id) REFERENCES leads(lead_id) ON DELETE CASCADE,
  CONSTRAINT fk_li_product FOREIGN KEY (product_id) REFERENCES products(product_id) ON DELETE SET NULL
);

CREATE TABLE stock_reservations (
  reservation_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  tenant_id INT UNSIGNED NOT NULL,
  lead_id INT UNSIGNED NOT NULL,
  product_id INT UNSIGNED NOT NULL,
  warehouse_id INT UNSIGNED NOT NULL,
  quantity DECIMAL(12,2) NOT NULL,
  status ENUM('active','released','consumed','expired') NOT NULL DEFAULT 'active',
  created_by INT UNSIGNED NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  released_at DATETIME NULL,
  consumed_at DATETIME NULL,
  notes VARCHAR(255) NULL,
  PRIMARY KEY (reservation_id),
  UNIQUE KEY uq_reservation (lead_id, product_id, warehouse_id),
  KEY idx_res_avail (tenant_id, product_id, warehouse_id, status),
  CONSTRAINT fk_res_lead FOREIGN KEY (lead_id) REFERENCES leads(lead_id) ON DELETE CASCADE,
  CONSTRAINT fk_res_product FOREIGN KEY (product_id) REFERENCES products(product_id) ON DELETE RESTRICT,
  CONSTRAINT fk_res_wh FOREIGN KEY (warehouse_id) REFERENCES warehouses(warehouse_id) ON DELETE RESTRICT
);
```

- **Guest checkout:** public `order.php`/`order-submit.php`/`order-thanks.php`; CSRF token, honeypot/rate limit, server validation, no account. Store contact snapshot on `leads`; do not force a `contacts` row. Reference `ONL-YYYYMMDD-XXXX` with unique constraint + retry. Support phone/email from `system_settings.company_phone/email`; do not invent.
- **Lead states:** statuses above; keep lead/contact, fulfillment, and payment separate. Submission alone = `new`, no reservation.
- **Confirmation:** staff selects store(s), server re-prices, locks stock rows, checks `quantity - active_reservations >= requested`, inserts/updates reservation, sets `confirmed`/`reserved`. If insufficient: block confirmation (or require an approved partial rule).
- **Cancellation:** release active reservations exactly once (`UPDATE ... WHERE status='active'` with affected rows), set cancelled.
- **Fulfillment:** in one transaction, re-lock reservation, re-check stock, create exactly one sales document, deduct stock with a movement, mark reservation `consumed`, link `converted_order_id`, create payment if applicable. No second deduction.
- **Availability:** `on_hand - SUM(active reservations)` per product/store. In-transit transfers are not available at either store.

### 12.5 Stock transfers

```sql
CREATE TABLE stock_transfers (
  transfer_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  tenant_id INT UNSIGNED NOT NULL,
  transfer_number VARCHAR(40) NOT NULL,
  from_warehouse_id INT UNSIGNED NOT NULL,
  to_warehouse_id INT UNSIGNED NOT NULL,
  status ENUM('draft','requested','approved','rejected','dispatched','in_transit','partially_received','received','cancelled','discrepancy') NOT NULL DEFAULT 'draft',
  requested_by INT UNSIGNED NULL,
  requested_at DATETIME NULL,
  approved_by INT UNSIGNED NULL,
  approved_at DATETIME NULL,
  dispatched_by INT UNSIGNED NULL,
  dispatched_at DATETIME NULL,
  received_by INT UNSIGNED NULL,
  received_at DATETIME NULL,
  notes TEXT NULL,
  rejection_reason VARCHAR(255) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (transfer_id),
  UNIQUE KEY uq_transfer_no (tenant_id, transfer_number),
  KEY idx_transfer_src (tenant_id, from_warehouse_id, status),
  KEY idx_transfer_dst (tenant_id, to_warehouse_id, status),
  CONSTRAINT fk_tr_from FOREIGN KEY (from_warehouse_id) REFERENCES warehouses(warehouse_id) ON DELETE RESTRICT,
  CONSTRAINT fk_tr_to FOREIGN KEY (to_warehouse_id) REFERENCES warehouses(warehouse_id) ON DELETE RESTRICT,
  CONSTRAINT fk_tr_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(tenant_id) ON DELETE CASCADE
);

CREATE TABLE stock_transfer_items (
  item_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  tenant_id INT UNSIGNED NOT NULL,
  transfer_id INT UNSIGNED NOT NULL,
  product_id INT UNSIGNED NOT NULL,
  quantity_requested DECIMAL(12,2) NOT NULL,
  quantity_approved DECIMAL(12,2) NOT NULL DEFAULT 0,
  quantity_dispatched DECIMAL(12,2) NOT NULL DEFAULT 0,
  quantity_received DECIMAL(12,2) NOT NULL DEFAULT 0,
  quantity_discrepancy DECIMAL(12,2) NOT NULL DEFAULT 0,
  notes VARCHAR(255) NULL,
  PRIMARY KEY (item_id),
  KEY idx_tr_item (transfer_id),
  KEY idx_tr_product (product_id),
  CONSTRAINT fk_ti_transfer FOREIGN KEY (transfer_id) REFERENCES stock_transfers(transfer_id) ON DELETE CASCADE,
  CONSTRAINT fk_ti_product FOREIGN KEY (product_id) REFERENCES products(product_id) ON DELETE RESTRICT
);
```

- State machine: `requested → approved/rejected → dispatched/in_transit → partially_received/received → completed/discrepancy`; finalize names in Phase 6.
- Approval changes no stock. Dispatch locks transfer + source stock, deducts `quantity_dispatched` once, writes `transfer_out`. Receipt locks transfer + destination stock, adds `quantity_received`, writes `transfer_in`; discrepancy recorded, not silently written off. In-transit = dispatched - received.
- Validate tenant/shop access, source ≠ destination, product/store tenant consistency, availability, and state transition with affected-row checks. No duplicate dispatch/receipt.

### 12.6 Returns and refunds

```sql
CREATE TABLE product_returns (
  return_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  tenant_id INT UNSIGNED NOT NULL,
  return_number VARCHAR(40) NOT NULL,
  source_type ENUM('pos_order','invoice') NOT NULL,
  source_id INT UNSIGNED NOT NULL,
  contact_id INT UNSIGNED NULL,
  status ENUM('requested','received','inspected','approved','rejected','resolved','cancelled') NOT NULL DEFAULT 'requested',
  reason VARCHAR(255) NULL,
  requested_by INT UNSIGNED NULL,
  inspected_by INT UNSIGNED NULL,
  approved_by INT UNSIGNED NULL,
  received_at DATETIME NULL,
  resolved_at DATETIME NULL,
  notes TEXT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (return_id),
  UNIQUE KEY uq_return_no (tenant_id, return_number),
  KEY idx_return_source (tenant_id, source_type, source_id),
  CONSTRAINT fk_ret_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(tenant_id) ON DELETE CASCADE
);

CREATE TABLE product_return_items (
  return_item_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  tenant_id INT UNSIGNED NOT NULL,
  return_id INT UNSIGNED NOT NULL,
  source_item_id INT UNSIGNED NOT NULL,
  product_id INT UNSIGNED NOT NULL,
  warehouse_id INT UNSIGNED NOT NULL,
  quantity_requested DECIMAL(12,2) NOT NULL,
  quantity_received DECIMAL(12,2) NOT NULL DEFAULT 0,
  quantity_approved DECIMAL(12,2) NOT NULL DEFAULT 0,
  condition_type ENUM('sellable','damaged','defective','expired','other') NOT NULL DEFAULT 'sellable',
  disposition ENUM('restock','write_off','return_to_supplier') NOT NULL DEFAULT 'restock',
  unit_price DECIMAL(12,2) NOT NULL,
  refund_amount DECIMAL(12,2) NOT NULL DEFAULT 0,
  restocked_qty DECIMAL(12,2) NOT NULL DEFAULT 0,
  notes VARCHAR(255) NULL,
  PRIMARY KEY (return_item_id),
  KEY idx_ret_item (return_id),
  KEY idx_ret_source_item (source_item_id),
  CONSTRAINT fk_ri_return FOREIGN KEY (return_id) REFERENCES product_returns(return_id) ON DELETE CASCADE,
  CONSTRAINT fk_ri_product FOREIGN KEY (product_id) REFERENCES products(product_id) ON DELETE RESTRICT
);

CREATE TABLE refunds (
  refund_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  tenant_id INT UNSIGNED NOT NULL,
  return_id INT UNSIGNED NOT NULL,
  invoice_id INT UNSIGNED NULL,
  pos_order_id INT UNSIGNED NULL,
  amount DECIMAL(12,2) NOT NULL,
  payment_method_id INT UNSIGNED NULL,
  refunded_at DATETIME NOT NULL,
  created_by INT UNSIGNED NULL,
  notes VARCHAR(255) NULL,
  PRIMARY KEY (refund_id),
  KEY idx_refund_return (return_id),
  CONSTRAINT fk_refund_return FOREIGN KEY (return_id) REFERENCES product_returns(return_id) ON DELETE RESTRICT
);
```

- Eligibility: per source line, `SUM(approved/restocked returned qty) <= sold qty`; lock and check. Duplicate returns blocked by status + unique item/return mapping.
- Restock only `quantity_approved` with `disposition='restock'` and `condition='sellable'`; movement `return_restock` (in). Damaged/defective → no sellable stock (write-off movement if approved). Rejected → no stock/refund.
- Refunds: separate `refunds`; never mutate original `payments`/`pos_payments`; invoice credit note/adjustment only if approved. One refund per approved return item; idempotent.

### 12.7 Movement types / inventory service

- Add `movement_types` lookup: `code`, `direction` (+1/-1), `description`, `is_active`. Backfill existing types; keep positive magnitudes.
- Proposed new codes: `sale`, `sale_cancel`, `invoice`, `invoice_cancel`, `sale_edit_in`, `sale_edit_out`, `invoice_edit_in`, `invoice_edit_out`, `restock`, `adjustment_in`, `adjustment_out`, `transfer_out`, `transfer_in`, `return_restock`, `writeoff`, `opening_balance`.
- Central `inventory_apply($conn, $tenant, $product, $warehouse, $qty, $type, $refType, $refId, $userId, $notes)`:
  - validate tenant/product/store/service/decimal;
  - `SELECT quantity FROM inventory_stock WHERE ... FOR UPDATE` (or atomic `UPDATE ... SET quantity = quantity + ? WHERE ...` + `affected_rows`);
  - if out and result < 0 → throw (policy);
  - insert movement in the same transaction; return movement ID.
- Central `inventory_reverse($movementId)` for cancel/void, idempotent via a `reversed_by_movement_id`/unique reversal key (add column or a `movement_reversals` table).
- Add indexes; add `CHECK (quantity > 0)` on movements; add `CHECK (quantity >= 0)` on stock only after cleanup/policy approval.
- Change `inventory_stock`/`inventory_movements` FKs to `RESTRICT` for products/warehouses; replace hard deletes with deactivation.

### 12.8 Permissions

Add (global `permissions`, assign to Administrator per tenant):

- `manage_shops` (shops/stores, user-shop access)
- `manage_leads` (online orders/leads)
- `manage_transfers` (request/dispatch/receive)
- `approve_transfers` (optional, separation of duties)
- `manage_returns` (request/inspect/approve)
- `approve_returns` (optional)
- `manage_pricing` (wholesale/manual price override), if manual override is approved
- `view_all_shops` (optional) or use `user_shops` + admin bypass

Update `sidebar.php` with new sections; pages must call `require_permission()` + `assert_shop_access()`; do not rely on hidden links.

---

## 13. Migration plan

### 13.1 Preconditions (M0)

1. Confirm the intended environment: production `u839560293_agensupply`; local dev DB name; whether `agen_business_v2` is retired.
2. Obtain a **fresh** production dump (`mysqldump --single-transaction --routines --triggers --events`) and verify row counts, FK validity, `CHECK TABLE`, and restore into a staging DB.
3. Record baseline row counts and checksums for `products`, `inventory_stock`, `inventory_movements`, `pos_orders`, `pos_order_items`, `invoices`, `invoice_items`, `payments`, `pos_payments`, `contacts`, `warehouses`.
4. Regenerate `schema_business_v2.sql` from the verified schema (or rename it to make clear it is a baseline); stop treating the old file as authoritative.
5. Move `database/seed_*.php`, `*.sql`, `*.md`, and `start-mysql.bat` outside the web root or deny access (immediate security hotfix). Remove/relocate `default.php` if not needed.
6. Confirm PHP 8.0+ on production; confirm backup/restore procedure with hosting.
7. Freeze writes during migration (maintenance window) or use online DDL with careful locking; test on MariaDB 10.4 and 11.8 if both are in play.
8. Do **not** run `seed_agen.php`/`seed_demo.php` anywhere with real data.

### 13.2 Migration sequence

| Migration | Type | Purpose | Reversible? |
|---|---|---|---|
| M0 baseline | process | backup/restore test, schema regeneration, web-root cleanup | n/a |
| M1 shops/stores | additive DDL + backfill | create `shops`, `warehouses.shop_id/code/is_active`, `user_shops`; one shop per tenant; map warehouse 1 | Yes: drop new tables/columns; keep mapping table |
| M2 provenance | additive DDL + backfill | `warehouse_id`/`price_type` on POS/invoice headers and lines; backfill lowest warehouse; indexes/FKs RESTRICT | Yes: drop columns/FKs (backfill mapping retained) |
| M3 inventory hardening | indexes, helper, constraint prep | movement types/indexes, FK cascade→RESTRICT, deactivation, audit; no data deletion | Mostly; FK change reversible but dangerous |
| M4 pricing | additive DDL + backfill | `products.wholesale_price`; `price_type` backfill retail | Yes |
| M5 leads/reservations | new tables + permissions | guest checkout, leads, reservations, support contacts | Yes: drop new tables/permissions |
| M6 transfers | new tables + permissions | transfer lifecycle/state machine | Yes |
| M7 returns/refunds | new tables + permissions | return/refund/disposition | Yes |
| M8 constraints/idempotency | uniques/checks after cleanup | unique order number, state guards, stock/movement checks, reservation unique | Partially; unique creation may fail on dirty data |
| M9 reporting/views/indexes | indexes/views/docs | store-aware low stock, dashboard cards, report views | Yes |

**Ordering rationale:** M1/M2 are additive and safe; M3 fixes the write path before any new stock-changing feature; M4 is needed before M5 fulfillment; M5/M6/M7 build on the M3 service; M8 should be last, after data cleanup. Do not implement M5–M7 before M3 is in place.

### 13.3 Backfill mappings and assumptions

- **Shop:** one per tenant. Name = `system_settings.company_name` (fallback `tenants.name`); code `SHOP-<tenant_id>-01`. Store = existing warehouse, code `STORE-<warehouse_id>`.
- **Warehouse provenance:** historical POS/invoice flows always used the **lowest warehouse ID** for the tenant. Backfill `warehouse_id` to that ID; do not use `pos_sessions.warehouse_id` as proof (code ignored it). Keep a `_migration_provenance` table with old/new values and the reason.
- **Price type:** all existing rows `retail`; `wholesale_price=NULL`.
- **Reorder level:** backfill `inventory_stock.reorder_level` from `products.min_stock_level` where reorder is 0/NULL; choose the final rule (per-store reorder with product fallback) before M8.
- **Users:** map all existing users to all existing shops; admin bypass; then restrict.
- **Permissions:** add new permissions and assign to Administrator; fix the missing `manage_contacts` assignment as a separate data correction.
- **Movements:** do not rewrite historical types; add a direction map. If any type is unknown, quarantine it in a `movement_type_unknown` report before enforcing validation.
- **Invoices/POS historical:** do not reprice/re-tax. Preserve `unit_price`, `tax_amount`, `total`. If an old invoice has no warehouse, set the lowest warehouse and mark `provenance_inferred=1` (add a nullable flag if needed) for audit.

### 13.4 Rollback/recovery

- Take a full backup immediately before each migration; test restore.
- Additive migrations: provide `down` SQL that drops new FKs/columns/tables in reverse order; never drop historical tables/columns in the same release.
- Backfill migrations: keep mapping tables (`_mig_shop_map`, `_mig_store_map`, `_mig_provenance`) and a row-count reconciliation report; do not delete them until the release is accepted.
- Constraint changes: rollback only on staging, not production, unless a verified backup exists. Re-adding cascade is itself a data-loss risk; prefer forward-fix.
- Document exact commands and a "stop" criterion (e.g., row-count mismatch > 0, FK errors, negative stock count increase).

### 13.5 Deployment gates

- No migration on production until: fresh backup verified, staging restore passed, row counts reconciled, web-root seeds/dumps removed, PHP version confirmed, and a rollback rehearsal completed.
- Use a maintenance window for M2/M3/M8; M1/M4/M5–M7 are additive but still require a backup.
- Never run `seed_*` against production; remove them from the deployed artifact.

---

## 14. Phase plan (2–8)

**Phase 2 — Shop/store model**  
Scope: M1 + UI/selection/access. Files: migrations, `shops.php`/`shop-edit.php`, `warehouses.php`/`store-edit.php` (or extend settings), `helpers.php` (`current_shop`, `assert_shop_access`, `accessible_shops`), `sidebar.php`, POS/invoice store selectors, `inventory.php` store filter. Preserve warehouse 1. Deliverable: one shop + one store, multiple stores supported, access checks, store-aware inventory lists. Do not change sale/invoice stock writes yet (Phase 3), except to require a store selection.

**Phase 3 — Inventory integrity and security hotfixes**  
Scope: M3 + F02/F03/F04/F06/F08/F09/F15/F16/F17/F18/F20/F21. Files: new `lib/inventory.php`, `sale-add/edit/cancel/delete`, `invoice-add/edit`, `invoices.php`, `customers.php`, `products.php`, `inventory*.php`, `reports.php`, `helpers.php`, login/auth, `save-settings.php`. Centralize stock writes, add `FOR UPDATE`/atomic updates, movements for every change, reversing entries, no hard deletes, CSRF tokens, prepared date filters, tenant validation, session hardening, audit logs. Deliverable: every stock change is atomic + ledgered; deletes no longer destroy history; hotfixes deployed.

**Phase 4 — Online orders and Leads**  
Scope: M5 (leads/lead_items, public pages, permissions, dashboard card). No reservations yet; submission only. Deliverable: guest checkout, thank-you page, lead queue, contact snapshots, support contacts, CSRF/rate limit.

**Phase 5 — Pricing, reservations, fulfillment**  
Scope: M4 + M5 reservations + integration. Server-side retail/wholesale pricing; reservation on confirmation; fulfillment creates exactly one sales document and deducts once; cancellation releases once. Deliverable: complete order-to-fulfillment flow with no duplicate stock/sales.

**Phase 6 — Transfers**  
Scope: M6 + UI/state machine. Deliverable: request → approve → dispatch → receive/discrepancy with in-transit tracking and idempotency.

**Phase 7 — Returns**  
Scope: M7 + UI. Deliverable: request → receive/inspect → approve/reject → restock/write-off → refund/credit, with eligibility and duplicate protection.

**Phase 8 — Dashboard/reporting/final validation**  
Scope: M9, clickable cards, store-aware low stock, sales/payment source consolidation, indexes, acceptance tests, deployment/rollback docs. Deliverable: tested release with known limitations.

---

## 15. Acceptance test plan

### 15.1 Test environment

- Create a disposable `dezl_test` DB from the verified schema (no production data), with fixtures: 1 tenant, 2 shops, 3 stores (two in shop A, one in shop B), products (physical + service, retail/wholesale, whole/decimal), stock rows, one customer, one supplier, invoice statuses, payment methods.
- Use PHP CLI scripts under `tests/` (no framework required) with assertions; each test runs in a transaction and rolls back, or recreates fixtures. If PHPUnit is approved later, wrap the same tests.
- Concurrency: run two PHP CLI processes against the test DB with a deliberate delay/`SLEEP` inside the lock window; assert one succeeds and one fails/blocks, and stock never goes negative.
- Never point tests at production. Add a hard guard in test bootstrap: DB name must start with `dezl_test`.

### 15.2 Acceptance matrix

The 47 acceptance tests from the brief are adopted as the release gate:

| IDs | Area | Pass condition | Phase |
|---|---|---|---|
| 1–5 | Existing functionality | Login/RBAC, POS, invoices/payments/reports/expenses, stock add/deduct/adjust still work; baseline counts/data preserved after migration | 2–3 |
| 6–8 | Shops/stores | Shop can contain multiple stores; each store has a separate balance; shop total equals sum of its stores; business total equals sum of authorized shops | 2 |
| 9 | Stock isolation | Stock received in store A is not visible as on-hand in store B or another shop | 2–3 |
| 10 | Correct store attribution | POS/invoice created in store B deducts store B, not the first store; movement records the same store | 3 |
| 11 | Access isolation | Unauthorized shop/store access returns 403/empty and leaks no data; authorized users see only their shops | 2–3 |
| 12 | Store lifecycle | Deactivating a store preserves history, removes it from new-sale selectors, and keeps old reports readable | 2–3 |
| 13 | Atomic balance + movement | Forced movement failure rolls back the balance; no half-written stock change | 3 |
| 14 | Concurrent oversell | Two transactions for the last unit: one succeeds, one fails/blocks; stock never goes negative | 3 |
| 15 | Duplicate request | Replayed checkout/cancel/confirm/fulfill does not double-deduct/restore/reserve | 3–7 |
| 16 | Movement signs/types | Each type has the correct direction; balances reconcile; reports show expected in/out | 3 |
| 17 | Ledger reconciliation | For each product/store, on-hand equals opening + in − out | 3 |
| 18 | Service products | No stock rows/movements/low-stock alerts; sale/invoice allowed without stock changes | 3 |
| 19–20 | Guest order | Guest submits name/phone/location/products; lead appears with snapshot; no account required; server-calculated total | 4 |
| 21 | No auto-confirmation | Submission alone does not confirm the order, reserve stock, or create a sale | 4 |
| 22 | Reservation on confirmation | Staff confirmation reserves selected store/qty; available-to-promise drops; physical on-hand unchanged | 5 |
| 23 | Concurrent confirmation | Two confirmations cannot reserve the same units twice | 5 |
| 24 | Cancellation | Cancel releases active reservation exactly once; on-hand unchanged; status cancelled | 5 |
| 25 | Fulfillment | Fulfillment consumes reservation, deducts on-hand once, and creates exactly one sales document | 5 |
| 26 | Thank-you page | Shows reference, support contact, and "awaiting confirmation" wording; no false confirmation | 4 |
| 27–28 | Pricing | Retail uses retail price; wholesale uses wholesale price; server resolves it | 5 |
| 29 | Price tampering | Posted price is ignored/rejected; totals cannot be changed from the browser | 5 |
| 30 | Historical price | Product price change does not alter existing transaction lines | 5 |
| 31 | Transfer approval | No dispatch before approval; approval changes no physical stock | 6 |
| 32 | Dispatch | Dispatch deducts source once and records `transfer_out` | 6 |
| 33 | In transit | Dispatched stock is not available at source or destination | 6 |
| 34 | Receipt | Receipt credits only received qty; partial receipt works | 6 |
| 35 | Discrepancy | Short/damaged receipt is recorded and traceable; no silent write-off | 6 |
| 36 | Transfer idempotency | Replayed dispatch/receipt does not double-post | 6 |
| 37 | Transfer isolation | Transfers cannot move stock across tenants or to unauthorized shops | 6 |
| 38 | Return eligibility | Return qty cannot exceed sold minus prior approved returns | 7 |
| 39 | Return disposition | Rejected/non-restockable items do not increase sellable stock | 7 |
| 40 | Restock movement | Approved sellable return creates exactly one `return_restock`; on-hand increases once | 7 |
| 41 | Duplicate refund | Replayed refund does not pay twice; original sale/payment remains traceable | 7 |
| 42 | Return traceability | Return links to original sale/invoice and preserves line prices | 7 |
| 43 | Dashboard cards | Cards navigate to filtered pages; counts respect tenant, permissions, and store access | 8 |
| 44 | Dashboard totals | Shop/store/business totals match fixtures; low-stock counts match per-store rule | 8 |
| 45 | Sidebar/permissions | Links appear only with permission; direct URL without permission returns 403 | 2–8 |
| 46 | Migration/rehearsal | Migrations run on a staging clone; rollback documented and rehearsed; row counts reconciled | 3–8 |
| 47 | Production safety | No migration/test/seed runs against production; tests guard DB name | All |

### 15.3 Extra tests beyond the brief

- Ledger reconciliation after every flow: `on_hand == opening + sum(in) - sum(out)`.
- Idempotency: replay checkout/cancel/dispatch/receive/confirm/fulfill/return/refund; assert one movement/one document.
- Cross-tenant: forged contact/warehouse/role/lead/transfer IDs → 403/404, no mutation.
- CSRF: missing/invalid token → 419/403, no mutation.
- Price tampering: POST price=0/negative/huge → server price used or request rejected.
- SQL injection: `from`/`to` payloads → no error/leak, parameterized.
- Service products: no stock rows/movements; no low-stock alerts.
- Deleting/deactivating store/product/customer with history → blocked/deactivated, history intact.
- Session: login regenerates ID; logout invalidates; cookie flags.
- Upload: spoofed MIME/SVG → rejected or sanitized.
- Report numbers: low stock per store, shop rollup, business rollup match fixtures.

---

## 16. Blocking business decisions

These are the questions that must be answered before the dependent phase. Recommended defaults are suggestions, not decisions.

1. **Production environment and backup (blocks all migrations).**  
   Confirm that `u839560293_agensupply` is the production database, provide a fresh verified dump and row counts, and confirm the local/dev database name. The supplied dump is not usable.  
   *Recommendation:* freeze feature work until a restore test passes.

2. **Immediate security hotfix (recommended before Phase 2).**  
   May I remove/move `database/seed_*.php`, `*.sql`, `*.md`, and `start-mysql.bat` outside the web root, add deny rules, and fix the missing `manage_contacts` assignment? This is a small, non-feature change that closes the wipe/download/exposure risk.  
   *Recommendation:* authorize this one hotfix now; defer all other changes.

3. **Negative stock policy.**  
   Should sales/invoices/transfers be blocked when stock would go negative, or allowed with a permission and reason?  
   *Recommendation:* block all normal flows; allow negative only through an explicit, audited adjustment permission. Do not silently allow.

4. **Online order fulfillment document (blocks Phase 5).**  
   When staff confirms and fulfills an online lead, should the system create a POS order, an invoice, or either depending on payment terms?  
   *Recommendation:* exactly one. Use POS order for immediate payment/collection; invoice for credit terms. Never both automatically. Store `source='online'` and `lead_id`.

5. **Wholesale price rules (blocks Phase 5).**  
   Who selects retail vs wholesale: staff per transaction, customer flag, minimum quantity, or a combination? What happens if `wholesale_price` is NULL: block or fall back to retail?  
   *Recommendation:* staff selects per transaction; block wholesale when no wholesale price exists; add customer flags/minimums only after the business defines them.

6. **Returns/refunds (blocks Phase 7).**  
   What is the return window? Must goods be received/inspected before refund? Which conditions are restockable? How are refunds paid (cash/mobile/original method/invoice credit)?  
   *Recommendation:* receive + inspect before refund; restock only sellable; refunds in a separate `refunds` table; no mutation of original payments; invoice credit note if the sale was invoiced.

7. **Shop access (blocks Phase 2).**  
   Should non-admin users be restricted to assigned shops via `user_shops`, and should existing users initially get all shops?  
   *Recommendation:* yes to `user_shops`; map all existing users to all shops to avoid lockout, then restrict; admin bypass; enforce server-side.

8. **Transfer approval (blocks Phase 6).**  
   Who can approve a transfer? Can the requester approve their own transfer? Is a separate `approve_transfers` permission required?  
   *Recommendation:* separate permission; no self-approval for non-admin; every transition audited.

9. **Low-stock source of truth (blocks store-aware alerts).**  
   Use per-store `inventory_stock.reorder_level`, product `min_stock_level`, or per-store with product fallback?  
   *Recommendation:* per-store `reorder_level`; fall back to product `min_stock_level` when reorder is 0/NULL; backfill from product min.

10. **Lead statuses and support contact (blocks Phase 4).**  
    Confirm the lead/fulfillment/payment statuses and whether the public thank-you page should show `system_settings.company_phone/email` (currently populated) or another support contact.  
    *Recommendation:* use the existing company phone/email; if empty, show a generic "we will contact you" message, not invented details.

11. **Order/invoice numbering.**  
    Approve unique order numbers with a new sequence (e.g., `POS-YYYYMMDD-0001` + retry) and atomic invoice-number allocation.  
    *Recommendation:* yes; uniqueness is required for reliable lookup and reporting.

12. **Manual price override.**  
    Is manual price override a legitimate business need? If yes, who may do it, and must a reason be recorded?  
    *Recommendation:* no override by default; if approved, dedicated permission + reason + audit, and never for online orders.

13. **Sales source of truth / reporting.**  
    Should invoices remain a separate sales channel, or should invoice creation be derived from POS/online fulfillment?  
    *Recommendation:* keep them separate for now, but link fulfillment to exactly one document and reconcile reports explicitly.

14. **Production PHP version and deployment config.**  
    Confirm PHP 8.0+ and how production credentials are injected (the repo `config.php` is local root/empty).  
    *Recommendation:* use environment variables or a non-web-root config; never deploy the local root/empty config.

---

## 17. Phase 1 deliverable checklist

- [x] Project structure and entry points mapped
- [x] Config, authentication, permissions, and helpers inspected
- [x] Schema, constraints, indexes, and SQL mode inspected
- [x] Live local database inspected (read-only)
- [x] Stock paths traced from UI to SQL
- [x] POS/invoice/payment/expense/cancel flows traced
- [x] Movement types and signs identified
- [x] Low-stock logic identified
- [x] Service-product handling identified
- [x] Orphan/cascade/inconsistent-stock risks identified
- [x] Findings register and migration plan written
- [x] Acceptance test matrix mapped
- [ ] Functional changes — intentionally not done

**No application code, configuration, schema, or data was modified.** Local MariaDB was started for inspection; this audit report is the only new project file.

**Recommended next step:** review this report, answer the blocking questions in §16, and authorize either (a) the small security hotfix first, then Phase 2, or (b) Phase 2 after the hotfix. Do not begin schema changes until a verified production backup and restore test exist.
