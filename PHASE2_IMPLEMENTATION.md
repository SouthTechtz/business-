# Phase 2 Implementation — Shops, Stores, and Shop Access

**Status:** Local implementation complete and tested; not deployed to production.
**Date:** 2026-10-27
**Depends on:** `PHASE1_AUDIT.md`
**Database tested:** local MariaDB 10.4.32, `u839560293_agensupply`
**PHP tested:** local PHP CLI 8.2.12

> `PHASE1_AUDIT.md` contains sensitive security findings. Move it out of the public web root before any deployment.
>
> Follow-up: migration `002_warehouse_shop_integrity_pos_sessions.php` and the fixed-location POS
> session changes are documented in `SHOPS_WAREHOUSES_IMPLEMENTATION.md`. Include that record in the
> deployment review; do not apply migration 002 until it has been reviewed and approved.

---

## 1. Delivered scope

This phase adds a shop/store foundation without removing or renaming existing data:

- New `shops` and `user_shops` tables.
- Store metadata and active state on `warehouses`.
- Store provenance (`warehouse_id`) on POS orders/items, invoices/items, and POS sessions.
- A `manage_shops` permission, assigned to local Administrator roles by the migration.
- Shop/store administration pages.
- Per-user shop access management.
- Store-aware POS and invoice creation, editing, cancellation, listing, detail, print, payment, export, search, customer, and dashboard paths.
- Store-aware inventory listing, stock adjustment, and opening-stock attribution for new products.
- CSRF protection on the new shop/store/user-access forms.

The implementation keeps the existing PHP/MySQL architecture and does not introduce a framework, migration runner, Composer dependency, or test framework.

---

## 2. Data model

### New tables

| Table | Purpose |
|---|---|
| `shops` | A tenant-owned business location grouping. Unique `(tenant_id, code)`. |
| `user_shops` | Explicit user-to-shop access. Unique `(user_id, shop_id)` with tenant and user/shop foreign keys. |

### Changed tables

| Table | Change |
|---|---|
| `warehouses` | Added `shop_id`, `code`, `is_active`; unique `(tenant_id, code)`; indexed active lookup; shop FK. |
| `pos_orders` | Added nullable `warehouse_id`; indexed by store/date; store FK. |
| `pos_order_items` | Added nullable `warehouse_id`; store FK. |
| `invoices` | Added nullable `warehouse_id`; indexed by store/date; store FK. |
| `invoice_items` | Added nullable `warehouse_id`; store FK. |
| `pos_sessions` | Reused the pre-existing `warehouse_id` column; added index and store FK. |

`pos_sessions.warehouse_id` existed before Phase 2. The rollback script therefore removes only the Phase 2 index/FK for that table and does **not** drop the column.

### Legacy backfill

The migration is idempotent and performs these documented inferences:

1. Each tenant receives a default shop named from `system_settings.company_name`, falling back to the tenant name, then `Main Shop`.
2. Existing warehouses are attached to that shop. The local existing `Main Shop` became `STORE-001`.
3. Users with no `user_shops` row are mapped to all shops that exist at migration time.
4. Existing POS orders/items, invoices/items, and POS sessions receive the tenant's first store as provenance when their store is missing.
5. No historical transaction amount, number, contact, product, or user identifier is changed.

The first-store attribution for legacy rows is an inference, not a recovered fact. If the business knows the real historical store for old transactions, update those rows deliberately after migration.

---

## 3. Access model

### Tenant-wide access

A user with `manage_users` or `manage_settings` is treated as tenant-wide for shop/store scoping. This preserves the existing Administrator workflow and avoids locking administrators out of shops they have not explicitly mapped.

### Shop-scoped access

Other users see:

- only shops present in `user_shops`;
- only stores belonging to those shops;
- only sales, invoices, payments, inventory, and dashboard figures tied to those stores.

Users with no shop mappings see no shop-scoped transaction rows. POS and invoice creation redirect or fail rather than silently falling back to another store.

### Helpers

`helpers.php` now provides:

- `tenant_admin_scope()`
- `accessible_shops()`, `accessible_shop_ids()`
- `accessible_stores()`, `accessible_warehouse_ids()`
- `find_accessible_store()`, `store_is_accessible()`
- `assert_shop_access()`, `assert_store_access()`
- `next_shop_code()`, `next_store_code()`, `store_label()`
- CSRF helpers: `csrf_token()`, `csrf_field()`, `verify_csrf()`

`accessible_warehouse_ids()` returns `null` for a tenant-wide user and an array for a shop-scoped user. An empty array means no access and is rendered as `1 = 0` in list/export queries.

---

## 4. User-facing changes

### New administration pages

| Page | Purpose |
|---|---|
| `shops.php` | List, add, edit, activate/deactivate shops. |
| `stores.php` | List, add, edit, activate/deactivate stores under a shop. |
| `user-shops.php` | Replace a user's shop mappings. |

All three are reachable from the **Shops & Stores** sidebar entry for users with `manage_shops`; user mapping also requires `manage_users`.

### Updated pages

- `users.php` links to shop-access management.
- `user-add.php` and `user-edit.php` include explicit shop checkboxes.
- `sale-add.php` requires an active accessible store, keeps the cart when the store changes, rechecks stock on quantity changes, and locks/rechecks stock at checkout.
- `sale-edit.php`, `sale-cancel.php`, `sale-details.php`, `sale-delete.php` use the order/item store for stock deltas/restoration and deny inaccessible stores.
- `invoice-add.php`, `invoice-edit.php`, `invoice-details.php`, `invoice-print.php`, `invoices.php`, `export-invoices.php`, `payment-add.php`, and `receipts.php` use store provenance and/or store access checks.
- `sales.php`, `export-sales.php`, `inventory.php`, `inventory-adjust.php`, and `product-add.php` are store-aware.
- `products.php`, `product-view.php`, and `product-edit.php` show only stock and stock movements from accessible stores.
- `export-receipts.php` now exports only payments belonging to accessible-store invoices.
- `customers.php` shows invoice counts and spend only from accessible stores; customer deletion is limited to tenant administrators because contacts are shared.
- `index.php`, `ajax-search.php`, `ajax-product-search.php`, `ajax-cart-update.php`, and `customer-view.php` apply the same store scope.

---

## 5. Write-path behaviour

### POS

1. A sale must have an active accessible store.
2. Stock is read from the selected store, not aggregate stock.
3. Changing store keeps the current cart and displays a notice.
4. Quantity changes revalidate against the current store.
5. Checkout opens a transaction, locks the selected store's stock rows, and rechecks every non-service item.
6. The order, order items, stock deduction, movement, and POS session all use the same store.
7. A failed checkout rolls back and does not leave an order.

### Invoices

1. An invoice must have an active accessible store.
2. Stock checks and deductions are against that store.
3. Invoice and invoice-item rows store the same `warehouse_id`.
4. Editing an existing invoice restores/stock-deducts using the original item store where possible, falling back to the invoice store for legacy rows.
5. Adding/removing invoice items updates stock in the invoice store.
6. Payment entry and invoice deletion are denied when the invoice's store is inaccessible.

### Inventory and products

1. Inventory rows are filtered by accessible stores.
2. Inline adjustment and the adjust/restock page deny stores the user cannot access.
3. New-product opening stock is written to the explicitly selected active accessible store.
4. The product list's opening-stock store defaults to the user's first accessible active store.

---

## 6. Migration

### Backup first

Do not run this against a database without a verified restorable backup. The local test used:

```powershell
& 'C:\xampp\mysql\bin\mysqldump.exe' -u root --routines --triggers u839560293_agensupply > 'C:\path\to\backup.sql'
```

Restore that backup into a disposable database and verify it before production use.

### Apply

```powershell
& 'C:\xampp\php\php.exe' database\migrations\001_phase2_shops_stores.php
```

The migration is idempotent. It was run twice successfully in local testing.

### Validation SQL

```sql
SELECT * FROM shops;
SELECT * FROM user_shops;
SELECT warehouse_id, shop_id, name, code, is_active FROM warehouses;
SELECT COUNT(*) FROM pos_orders WHERE warehouse_id IS NULL;
SELECT COUNT(*) FROM pos_order_items WHERE warehouse_id IS NULL;
SELECT COUNT(*) FROM invoices WHERE warehouse_id IS NULL;
SELECT COUNT(*) FROM invoice_items WHERE warehouse_id IS NULL;
SELECT rp.* FROM role_permissions rp
JOIN permissions p ON p.permission_id = rp.permission_id
WHERE p.name = 'manage_shops';
```

Expected after a clean migration:

- one default shop per tenant;
- every existing store attached to a shop;
- every existing user mapped to at least one shop unless deliberately cleared later;
- no NULL store provenance on historical rows;
- `manage_shops` assigned to Administrator roles.

### Local result

After migration and test cleanup, local state is:

- 1 tenant, 1 user, 1 role;
- 1 shop (`SHOP-001`);
- 1 store (`STORE-001`, `Main Shop`);
- 1 user-shop mapping;
- 0 products, 0 stock rows, 0 movements, 0 POS orders, 0 invoices, 0 payments, 0 contacts;
- `system_settings.invoice_next_number` restored to `1002`.

The local `manage_shops` permission ID may differ by database because it is auto-incremented.

---

## 7. Dev-only rollback

`database/migrations/001_phase2_rollback.php` removes the Phase 2 schema additions and access mappings.

```powershell
& 'C:\xampp\php\php.exe' database\migrations\001_phase2_rollback.php --confirm=ROLLBACK_PHASE2
```

It refuses to run:

- outside CLI;
- without the exact confirmation argument;
- against a non-local `DB_HOST` unless `PHASE2_ROLLBACK_ALLOW_REMOTE=1` is explicitly set.

It drops Phase 2 FKs/indexes/columns, drops `user_shops` and `shops`, and removes `manage_shops`. It **does not** restore business data. Existing warehouse rows are kept but lose their Phase 2 shop/code/active values.

Local rollback was tested, then the migration was rerun twice. The resulting schema and access mapping were verified. A pre-test dump was saved at
`C:\Users\San Cleo\AppData\Local\Temp\opencode\phase2_pre_rollback_test.sql`.

Do not run this rollback against production. Restore a verified production backup instead.

---

## 8. Test evidence

All tests used temporary local data, then the temporary rows were deleted.

### Static checks

`php -l` passed for every changed PHP file and both migration scripts.

### Migration

- Initial migration: passed.
- Second run: passed with all objects reported as already existing.
- Rollback: passed; all Phase 2 objects removed.
- Reapply after rollback: passed.
- Second reapply: passed.

### POS

- Shop-scoped user saw only the assigned store; the other shop's store did not appear.
- Adding 4 units against 3 available was rejected.
- Adding 2 units succeeded; checkout created an order at the selected store, deducted that store only, and wrote store provenance.
- Switching store kept the 6-unit cart.
- Quantity update to 6 against 5 available was rejected.
- Checkout of 6 against 5 available was rejected and created no order.
- Cancellation restored stock to the item's store.

### Invoices, payments, and inventory

- Invoice creation for 2 units against 1 available was rejected.
- Invoice creation for 1 unit succeeded and deducted the selected store.
- Invoice edit increased quantity using the original item store and deducted the delta.
- Adding a new invoice item deducted stock; removing it restored stock.
- Inaccessible invoice payment and detail paths were denied.
- Accessible payment updated `amount_paid`/`amount_due`.
- Inventory page showed only the accessible store.
- Inventory adjustment to an inaccessible store was rejected.
- Accessible restock succeeded.
- New-product opening stock was written to the selected store with an `adjustment` movement.

### Cross-shop isolation

For a user mapped only to the test shop, direct requests to another shop's sale detail/edit/delete, invoice detail/edit/print, and payment entry were denied or redirected with a danger message. Lists, exports, global search, customer view, dashboard, customer invoice totals, product stock displays, and receipt exports excluded the other shop's data. Non-admin customer deletion was rejected.

### Administration

- Shop-scoped `manage_shops` user saw only its accessible shop/store.
- Store creation under the accessible shop succeeded.
- A POST without a CSRF token returned 403 and created no row.
- User-shop mapping changes succeeded and were read back from `user_shops`.
- `user-add.php`/`user-edit.php` rendered shop checkboxes with the expected defaults.

### Known test limitation

The local database did not contain realistic historical data. The first-store backfill was validated structurally, not against real historical store attribution. Validate row counts and sample rows on the production copy before deployment.

---

## 9. Deferred gaps and risks

These are intentionally outside this phase:

- **Reports are not shop-scoped.** `reports.php` and `report-print.php` still aggregate tenant-wide and contain pre-existing unsafe date interpolation. `view_reports` is currently held by the local Administrator role only, which is tenant-wide, but do not grant it to a shop-scoped role until reports are parameterized and scoped.
- **Expenses/suppliers and other non-sales modules** are not store-attributed.
- **Destructive delete paths remain.** Deleting a POS sale or invoice still permanently removes it and does not restore stock. Access is now store-checked, but the data-loss behaviour is unchanged.
- **Legacy forms lack CSRF.** POS, invoice, inventory, payment, and older admin forms do not yet use the CSRF helpers.
- **No stock reservation/transfer/return workflow.** Confirmation, transfer approval, wholesale prices, reservations, returns, and refunds remain future work.
- **No negative-stock policy or full concurrency hardening.** Stock row locks are used in POS/invoice checkout, but the wider ledger has not been hardened.
- **Contacts and the product catalog are shared at tenant level.** A shop-scoped user can see customer records and edit shared products, but transaction, stock, and movement views are store-scoped.
- **User count on dashboard remains tenant-wide.** Transaction and stock figures are store-scoped.
- **Seeded/exposed scripts and public SQL dumps from Phase 1 are unresolved.**
- **Production PHP version is unconfirmed.** Local testing used PHP 8.2.12.
- **Production backup/restore verification is still required.**

---

## 10. Production readiness checklist

Do not deploy until all items are complete and recorded:

1. Take a fresh production backup.
2. Restore that backup into a disposable database.
3. Record pre-migration row counts for every affected table.
4. Confirm the production PHP version supports the syntax used here.
5. Confirm the production MySQL/MariaDB version and SQL mode.
6. Run the migration on the restored copy.
7. Run the validation SQL and compare counts.
8. Test login, POS sale, invoice, payment, inventory, store access, and denied cross-shop access on the restored copy.
9. Relocate `PHASE1_AUDIT.md` outside the public web root.
10. Schedule a maintenance window and deploy only after sign-off.

---

## 11. Changed/new files

**New**

- `database/migrations/001_phase2_shops_stores.php`
- `database/migrations/001_phase2_rollback.php`
- `shops.php`
- `stores.php`
- `user-shops.php`

**Modified**

- `helpers.php`
- `sidebar.php`
- `users.php`
- `user-add.php`
- `user-edit.php`
- `sale-add.php`
- `sale-edit.php`
- `sale-cancel.php`
- `sale-details.php`
- `sale-delete.php`
- `sales.php`
- `export-sales.php`
- `invoice-add.php`
- `invoice-edit.php`
- `invoice-details.php`
- `invoice-print.php`
- `invoices.php`
- `export-invoices.php`
- `payment-add.php`
- `receipts.php`
- `export-receipts.php`
- `inventory.php`
- `inventory-adjust.php`
- `product-add.php`
- `products.php`
- `product-view.php`
- `product-edit.php`
- `customers.php`
- `ajax-product-search.php`
- `ajax-cart-update.php`
- `ajax-search.php`
- `customer-view.php`
- `index.php`
