# Shops, Warehouses, and POS Session Location — Implementation Record

> Internal implementation document. Do not serve this file from a production web root.
> Production was not modified. All work below is local to `C:\xampp\htdocs\business`.

## 1. Environment verified

| Item | Verified value |
|---|---|
| Application database (`config.php`) | `u839560293_agensupply` on `127.0.0.1:3306` |
| PHP CLI | 8.2.12 (XAMPP, Windows) |
| Database server | MariaDB 10.4.32 (XAMPP) |
| Tenant count | 1 |
| Existing shop | `shop_id=1`, `DEZL INVESTMENT LIMITED`, code `SHOP-001`, active |
| Existing store | `warehouse_id=1`, `Main Shop`, code `STORE-001`, shop 1, active |
| Existing users | 1 (`admin`), mapped to shop 1 |
| Existing open POS session | `session_id=1`, user 1, store 1, `POS-SESSION-20260828-001`, still open |
| Transaction rows | 0 POS orders, 0 invoices, 0 stock/movement rows |
| Existing migrations | `001_phase2_shops_stores.php` already applied and documented |

The user-supplied report of MariaDB 11 is not correct for this machine. The local server is MariaDB
10.4.32. The production PHP/MySQL versions are still unconfirmed and remain a deployment prerequisite.

## 2. Live schema before migration 002

- `shops`: `shop_id`, `tenant_id`, `name`, `code`, `address`, `phone`, `is_active`, timestamps.
- `warehouses`: existing `warehouse_id`/`tenant_id`/`name`, plus `shop_id` (nullable before 002),
  `code`, `is_active`.
- `user_shops`: tenant/user/shop mapping with a unique `(user_id, shop_id)` key.
- `pos_sessions`: existing `warehouse_id`, `user_id`, `session_number`, `start_time`,
  `starting_cash`, `is_closed`. No shop column; the warehouse is the authoritative location.
- `pos_orders` and `pos_order_items`: direct `warehouse_id` provenance columns.
- `invoices` and `invoice_items`: direct `warehouse_id` provenance columns.
- `inventory_stock` and `inventory_movements`: `warehouse_id` already present.
- `manage_shops` permission exists and is granted to the Administrator role.

## 3. Gaps found in the current implementation

1. **No POS session opening workflow.** `sale-add.php` silently created/reused any open session
   for a warehouse at checkout. The session was not tied to the signed-in user, had no shop/store
   selection screen, and had no close operation.
2. **POS location was changeable mid-session.** `sale-add.php` exposed a store selector and
   accepted `warehouse_id` on checkout. `ajax-cart-update.php` trusted a browser-supplied
   `warehouse_id`.
3. **Existing open sessions were not protected.** The old checkout lookup could reuse another
   user's open session for the same warehouse.
4. **Warehouse-to-shop integrity was not enforced by the database.** `warehouses.shop_id` was
   nullable and its foreign key was `ON DELETE SET NULL`; the tenant/shop pair could disagree.
5. **Store/shop deactivation had no dependency warning** in the administration UI.
6. **Inventory had only a warehouse filter**, not a shop filter.
7. **Stock attribution fallbacks and deletion paths needed hardening.** Sale/invoice cancellation
   and deletion could silently fall back to the order/invoice warehouse, and invoice/sale deletion
   could remove records without restoring stock. Concurrent cancellation/edits were not row-locked.
8. **Shop assignment management was gated only by `manage_users`**, not the dedicated
   `manage_shops` permission.

## 4. Implemented changes (file manifest)

### Database

- `database/migrations/002_warehouse_shop_integrity_pos_sessions.php`
  - Create a small `schema_migrations` status table.
  - Backfill any remaining `warehouses.shop_id = NULL` to a shop in the same tenant.
  - Make `warehouses.shop_id` `NOT NULL`.
  - Replace the single-column shop FK with a composite `(shop_id, tenant_id)` FK so a warehouse
    cannot reference a shop from another tenant.
  - Add `pos_sessions (tenant_id, user_id, is_closed)` lookup index.
- `database/migrations/002_warehouse_shop_integrity_pos_sessions_rollback.php`
  - Explicit destructive confirmation; restores the old nullable/single-column FK and removes the
    new index/status row. Does not delete shops, stores, stock, or history.

### Application

- `helpers.php`
  - Add open-POS-session lookup and session-number helpers.
- `pos-session.php` (new)
  - Open/close the signed-in user's POS session, with shop then warehouse selection.
  - Server-side access, tenant, active-state, and warehouse-belongs-to-shop validation.
  - CSRF protection; one open session per user; no mid-session warehouse change.
- `sale-add.php`
  - Require the signed-in user's open session; remove the store selector and checkout
    `warehouse_id`; use the session warehouse for stock checks, deductions, and order provenance;
    lock the session while checking out; add CSRF tokens to POS actions.
- `ajax-cart-update.php`
  - Use the open session warehouse, never a browser-supplied store.
- `ajax-product-search.php`
  - In POS mode (`pos=1`), use the open session warehouse and reject requests without a session.
- `sidebar.php`, `sales.php`
  - Add POS Session navigation/actions.
- `shops.php`, `stores.php`
  - Dependency-aware confirmation before deactivating a shop/store; no hard-delete UI.
- `inventory.php`
  - Add a shop filter alongside the warehouse filter.
- `inventory-adjust.php`
  - Remove the combined across-store stock figure from the product picker; restocking is always
    performed against the explicitly selected store.
- `sale-edit.php`, `sale-cancel.php`, `sale-delete.php`
  - Row-lock the sale; preserve the original item warehouse; reject unknown/inaccessible
    warehouses instead of silently substituting; restore stock exactly once on cancel/delete.
- `invoice-edit.php`, `invoices.php`
  - Row-lock the invoice; preserve original item warehouse; lock stock rows; restore stock when
    deleting an invoice (no payments) before removing it.
- `customers.php`
  - Stop cascading invoice deletion from a customer record; invoice history now blocks customer
    deletion instead of silently removing stock-backed documents.
- `user-add.php`, `user-edit.php`, `user-shops.php`, `users.php`, `shops.php`, `stores.php`
  - Require `manage_shops` for shop-assignment changes and hide assignment controls without it.
  - A user manager without `manage_shops` cannot change existing shop assignments.

## 5. Backfill assumptions

- The existing shop/warehouse mapping from migration 001 is authoritative.
- Any warehouse still missing a shop is mapped to the tenant's first shop; this is an explicit
  inference and is reported by the migration.
- No transaction warehouse values are re-guessed in migration 002. Existing historical provenance
  from migration 001 is preserved.
- The existing open session is left on its current warehouse and remains usable by its owner.
- `pos_sessions.shop_id` is intentionally not added: the warehouse FK is the single source of
  location truth, avoiding a shop/warehouse disagreement.

## 6. Test plan

Follow the test matrix in the request: shops/stores CRUD and dependency confirmation; permissions
and cross-tenant denial; POS session open/close/fixed location; stock check/deduction/cancel/delete
restoration; invoice creation/edit/delete restoration; inventory shop/warehouse filters; CSRF and
manipulated-ID rejection; PHP lint; regression smoke tests. Results are recorded after execution in
section 8 of this document.

## 7. Deployment gate

Do not run either migration on production until a fresh production backup has been restore-tested,
production row counts and PHP/MariaDB versions are confirmed, and the file/migration manifest is
approved. `PHASE1_AUDIT.md`, this document, and migration/seed scripts must not be publicly served.

## 8. Test results

All tests below were run locally on 2026-10-27 against `u839560293_agensupply` using PHP 8.2.12 and
MariaDB 10.4.32. No production system was contacted.

### 8.1 Database migration

- Applied `002_warehouse_shop_integrity_pos_sessions.php` successfully.
- Re-applied it successfully; every step reported “already exists”.
- Ran the rollback with `--confirm=ROLLBACK_PHASE2_INTEGRITY`.
  - The first attempt exposed an order-of-operations bug: the old `ON DELETE SET NULL` FK was being
    restored while `shop_id` was still `NOT NULL`. The rollback script was corrected and rerun
    successfully.
  - The migration was then reapplied twice; both runs succeeded.
- Confirmed the final live schema:
  - `warehouses.shop_id` is `int unsigned NOT NULL`.
  - `fk_wh_shop_tenant` references `shops(shop_id, tenant_id)`.
  - `idx_ps_user_open` exists on `pos_sessions(tenant_id, user_id, is_closed)`.
  - `schema_migrations` contains `001_phase2_shops_stores` and
    `002_warehouse_shop_integrity_pos_sessions`.
- Direct SQL rejection tests:
  - Inserting a tenant-1 warehouse with a tenant-2 shop failed with `ERROR 1452` (FK).
  - Inserting a warehouse with `shop_id = NULL` failed with `ERROR 1048`.
  - No invalid test warehouse rows were created.

### 8.2 Application / HTTP tests

A PowerShell/Python HTTP test harness logged in as temporary shop-scoped and administrator users and
ran **85 assertions**, all passing. Coverage included:

- No-session access: `sale-add.php` redirects to `pos-session.php`; POS search and cart AJAX return
  HTTP 403.
- POS session creation validation:
  - shop/store mismatch rejected;
  - another shop in the same tenant rejected;
  - a shop/store from another tenant rejected;
  - invalid CSRF token rejected with HTTP 403.
- Opening one session at store A1; the POS screen and session page both showed the fixed A1 location.
- POS product search returned stock from A1 only.
- `ajax-cart-update.php` accepted a manipulated `warehouse_id` for store B but still checked stock at
  the session store A1.
- Checkout accepted a manipulated `warehouse_id` but saved the order at A1 and deducted only A1.
- Sale edit increased quantity using the original A1 attribution.
- Sale cancellation restored stock exactly once; a duplicate cancellation did not restore again.
- Deleting a cancelled sale did not restore twice.
- Deleting a live sale restored stock before deletion.
- Invoice creation:
  - an inaccessible store was rejected;
  - an authorized store was saved on both the invoice and its items;
  - stock was deducted from that store.
- Invoice edit deducted only the quantity delta from the original store.
- Invoice deletion restored stock before removing the invoice.
- Inventory shop filter excluded another shop’s stock and did not combine balances.
- Closing the session worked; after closing, `sale-add.php` redirected to the session page.
- After closing A1, a new session could be opened at authorized store A2; the location was fixed to
  A2 and then closed successfully.
- Shop deactivation through the UI preserved its store and was reversible; reactivation succeeded.
- `stores.php` rendered the dependency confirmation counts and JavaScript.
- A role with `manage_users` but without `manage_shops`:
  - did not see shop-assignment checkboxes;
  - could not change existing `user_shops` rows through a forged POST;
  - received HTTP 403 on `user-shops.php`.
- The pre-existing admin POS session (`session_id=1`) remained open and untouched.

### 8.3 PHP syntax

`php -l` passed on all 23 changed/new PHP files, including both migration scripts.

### 8.4 Cleanup and final local state

Temporary shops, stores, users, roles, products, stock, movements, contacts, orders, invoices, and
sessions created for testing were removed. Immediately after cleanup, local state was back to:

- 1 tenant, 1 user, 1 role, 1 shop, 1 warehouse, 1 `user_shops` row
- 0 products, 0 stock rows, 0 movements, 0 orders, 0 invoices, 0 test contacts
- `invoice_next_number = 1002`
- 55 audit-log rows (the pre-test baseline)
- The original admin POS session is still open (`session_id=1`)

After that cleanup, the admin account created a real local shop and store through the application:
`kariakoo` (`SHOP-002`, `shop_id=7`) and `karikoo store` (`STORE-002`, `warehouse_id=11`). Those are
not test fixtures; they were left in place. Current local counts are therefore 2 shops, 2 warehouses,
and 57 audit rows. Nothing was changed on production.

The local PHP test server on port 8899 was stopped. A pre-migration database dump remains at
`C:\Users\San Cleo\AppData\Local\Temp\opencode\shops_warehouses_pre_002.sql`.

### 8.5 Not covered here

- Production backup/restore verification, production row counts, and production PHP/MariaDB versions.
- Reports scoping/date parameterization and the other deferred hotfixes listed in `PHASE1_AUDIT.md`.
- Automated browser-level testing of every responsive layout; the HTTP checks exercised the
  server-side behavior and rendered pages.
- Actual store deactivation was not posted through the UI; shop deactivation was tested end-to-end
  and the store dependency confirmation was rendered and inspected.
- `inventory-adjust.php`’s product-picker display change was syntax-checked only; restocking itself
  was not repeated in the HTTP harness.
- Payment-bearing invoice deletion and customer deletion were not exercised through the UI in this
  test run; their new guards are code-reviewed but should be included in deployment regression.

