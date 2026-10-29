# DEZL Business Suite — System State and Review Report

**Report date:** 29 October 2026  
**Project root:** `C:\xampp\htdocs\business`  
**Database reviewed:** local MariaDB `u839560293_agensupply`  
**Review mode:** Read-only. No application code or live table was changed after the instruction to stop modifying the system.

---

## Contents

1. [Executive summary](#1-executive-summary)
2. [Technology stack](#2-technology-stack)
3. [Architecture and request lifecycle](#3-architecture-and-request-lifecycle)
4. [Component inventory](#4-component-inventory)
5. [Data model](#5-data-model)
6. [Storefront assessment](#6-storefront-assessment)
7. [Godown workflow: intended versus actual](#7-godown-workflow-intended-versus-actual)
8. [Security findings](#8-security-findings)
9. [Current data state and recovery](#9-current-data-state-and-recovery)
10. [Migration and schema drift](#10-migration-and-schema-drift)
11. [Inconsistencies and defects](#11-inconsistencies-and-defects)
12. [Recommendations](#12-recommendations)
13. [Verification performed](#13-verification-performed)
14. [Bottom line](#14-bottom-line)

---

## 1. Executive summary

The application is a working, custom PHP/MySQL multi-tenant business suite with a public catalogue storefront. It is not built on a framework; it is a collection of direct PHP endpoints sharing a bootstrap, helper library, authentication guard, and permission system.

### What is solid

- The storefront is catalogue-first and reads real database records.
- Products, filters, search, sorting, product detail, cart, checkout, confirmation, newsletter, product feeds, and clean product URLs are implemented.
- Checkout creates a lead and a `storefront_lead_requests` record. It does not create a fake payment or reserve stock.
- The admin area has working modules for products, inventory, POS, invoices, payments, customers, leads, orders, expenses, reports, users, shops, and audit logs.
- All PHP files pass `php -l` under PHP 8.2.12.

### What is not ready

- The Godown extension is structurally present but **not operational**.
- Database and code disagree about how a Godown is identified and how it is linked to a shop.
- No Godown can currently be designated successfully.
- The shop-requester permission does not exist in the live database.
- Requester-facing transfer pages are guarded by staff-only permissions.
- Migration history is not reproducible: one applied migration has no file, and a new migration reuses its number.
- The `database/` directory is web-accessible, and destructive seed scripts execute on GET.
- Production credentials are present in tracked files.

### Overall assessment

- **Storefront:** complete in code; currently showing demo data because of the seeder reset.
- **Admin/Godown:** incomplete and unsafe to deploy until the schema/code mismatch and permission gaps are fixed.
- **Security:** the web-exposed `database/` directory and tracked secrets must be addressed before any public deployment.

> **State warning:** During the review, an HTTP request to `database/seed_demo.php` executed the demo seeder. That reset tenant-1 demo tables. The live database is currently in the seeded state. No restore has been applied. Details and recovery options are in §9.

---

## 2. Technology stack

| Layer | Actual implementation |
|---|---|
| Runtime | PHP 8.2.12, Apache via XAMPP on Windows |
| Database | MariaDB 10.4.32, InnoDB, `utf8mb4` / `utf8mb4_unicode_ci` |
| Backend style | Procedural PHP with `mysqli`; shared helper functions |
| Framework | None. No Composer, no autoloader, no `vendor/` directory |
| Admin UI | Bootstrap 4-era CSS bundle, jQuery 3.2.1, DataTables, Chart.js, Font Awesome |
| Storefront UI | Custom CSS/JS, flat catalogue-first design, no frontend framework |
| Authentication | PHP sessions, `users`, `roles`, `permissions`, `role_permissions`, `user_roles` |
| Authorization | Permission checks such as `require_permission()` and `can()`; tenant scoping by `tenant_id` |
| Routing | Direct `.php` endpoints; `.htaccess` only rewrites `/product/{id}` |
| Currency | TZS default, with tenant-level `currency_code` |
| Tests | None found. No PHPUnit, test directory, or CI configuration |
| Deployment | Single XAMPP document root; no Docker, no build pipeline |

The application has no REST API layer. AJAX endpoints such as `ajax-search.php` and `ajax-cart-update.php` return data directly to the existing pages.

### Repository and dependencies

- No `composer.json`.
- No `package.json`.
- No `vendor/` directory.
- No `tests/` directory.
- No Docker, CI, or deployment pipeline files found.
- Root `.htaccess` only contains the product URL rewrite and `DirectoryIndex` configuration.

---

## 3. Architecture and request lifecycle

### Admin request flow

```text
Browser
  → Apache
  → PHP page endpoint (e.g. products.php)
  → config.php
       - DB connection
       - session start
       - helpers.php
       - User.php
  → header.php
  → auth_check.php
       - session user lookup
       - active-user check
  → require_permission(...)
  → page logic
       - fetch_one / fetch_all / db_insert
       - POST handling + verify_csrf()
       - flash + redirect
  → HTML + sidebar.php + footer.php
  → MariaDB
```

### Public storefront flow

```text
Browser
  → index.php / product.php / cart.php / checkout.php
  → storefront.php
       - storefront_tenant()
       - storefront_settings()
       - catalogue queries
       - session cart
  → MariaDB
```

The storefront is deliberately separated from staff authentication. It reads live catalogue and stock but only writes a lead request and subscriber records.

### Key architectural characteristics

- **No front controller.** Every page is its own entry point.
- **Shared bootstrap.** `config.php` opens the database, starts the session, and loads `helpers.php` and `User.php`.
- **Permission-based navigation.** `sidebar.php` shows modules based on `can(...)`.
- **Tenant-scoped queries.** Most business queries filter by `tenant_id`; this is application-enforced, not database row-level security.
- **Prepared-statement helpers.** `fetch_one()`, `fetch_all()`, and `db_insert()` use `mysqli` prepared statements for normal application queries.
- **Per-page CSRF.** `csrf_field()` and `verify_csrf()` exist, but enforcement is the responsibility of each POST handler.
- **No automated regression safety net.** Schema changes, permission changes, and workflow changes are verified manually.

---

## 4. Component inventory

| Area | Main files / components | Responsibility |
|---|---|---|
| Bootstrap and shared | `config.php`, `helpers.php`, `User.php`, `auth_check.php` | DB connection, session, helpers, authentication, permissions |
| Layout | `header.php`, `footer.php`, `navbar.php`, `sidebar.php` | Admin shell and permission-aware navigation |
| Storefront | `index.php`, `product.php`, `cart.php`, `checkout.php`, `confirmation.php`, `newsletter-subscribe.php`, `product-feed.php`, `storefront.php`, `storefront.js`, `storefront.css` | Public catalogue, cart, lead-request checkout, feeds |
| Storefront AJAX | `ajax-cart-update.php`, `ajax-product-search.php`, `storefront-cart-action.php` | Cart and catalogue interactions |
| Catalogue | `products.php`, `product-add.php`, `product-edit.php`, `product-view.php`, `product.php` | Product CRUD and product detail |
| Inventory | `inventory.php`, `inventory-adjust.php` | Stock levels, adjustments, movements |
| POS / sales | `sales.php`, `sale-add.php`, `sale-edit.php`, `sale-details.php`, `sale-cancel.php`, `sale-delete.php`, `pos-session.php`, `receipts.php` | Point-of-sale and sales history |
| Invoicing / payments | `invoices.php`, `invoice-add.php`, `invoice-edit.php`, `invoice-details.php`, `invoice-print.php`, `payment-add.php` | Invoice lifecycle and payments |
| CRM | `customers.php`, `customer-add.php`, `customer-edit.php`, `customer-view.php`, `leads.php`, `lead-add.php`, `lead-edit.php`, `lead-view.php`, `lead-action.php`, `lead-verify.php` | Customers, leads, follow-up |
| Orders | `orders.php`, `order-add.php`, `order-edit.php`, `order-view.php`, `order-action.php`, `order-received.php` | Staff order lifecycle and stock reservations |
| Expenses | `expenses.php`, `export-expenses.php` | Expense recording and export |
| Reports | `reports.php`, `report-print.php`, `export-sales.php`, `export-invoices.php`, `export-receipts.php` | Reporting and exports |
| Shops / stores / Godown | `shops.php`, `stores.php`, `godown.php`, `godown-transfer-add.php`, `godown-transfers.php`, `godown-transfer-view.php` | Shop, store, Godown, and transfer workflows |
| Administration | `users.php`, `user-add.php`, `user-edit.php`, `user-view.php`, `permissions.php`, `settings.php`, `save-settings.php`, `profile.php`, `audit-log.php` | Users, roles, settings, audit trail |

The largest files are:

| File | Approximate lines |
|---|---:|
| `helpers.php` | 1,010 |
| `godown.php` | 957 |
| `godown-transfer-view.php` | 901 |
| `lead-action.php` | 670 |
| `invoice-add.php` | 640 |
| `sale-add.php` | 551 |
| `order-add.php` | 510 |
| `storefront.php` | 487 |

---

## 5. Data model

The live database has **51 tables**. They group as follows:

| Domain | Tables |
|---|---|
| Tenancy and access | `tenants`, `users`, `roles`, `permissions`, `role_permissions`, `user_roles`, `user_shops`, `shops` |
| Warehouses and transfers | `warehouses`, `transfer_routes`, `user_godowns`, `stock_transfers`, `stock_transfer_items`, `godown_receipts` |
| Catalogue and inventory | `products`, `product_categories`, `product_variants`, `units_of_measurement`, `tax_rates`, `inventory_stock`, `inventory_movements`, `suppliers` |
| CRM and orders | `contacts`, `contact_types`, `leads`, `activities`, `deals`, `orders`, `order_items`, `order_stock_reservations` |
| POS | `pos_sessions`, `pos_orders`, `pos_order_items`, `pos_payments` |
| Invoicing and payments | `invoices`, `invoice_items`, `payments`, `payment_methods` |
| Expenses and finance | `expenses`, `expense_categories`, `expense_items`, `journal_entries` |
| Storefront | `storefront_lead_requests`, `storefront_lead_request_items`, `storefront_request_notification_reads`, `storefront_subscribers` |
| System | `system_settings`, `audit_logs`, `schema_migrations`, `document_sequences`, `document_statuses` |

### Core relationships

- A **tenant** owns users, shops, products, inventory, orders, invoices, and storefront records.
- A **shop** has one or more **warehouses**.
- A **warehouse** is either a normal store or the central Godown, distinguished by `warehouses.location_type`.
- **Products** belong to a tenant and optionally a category and unit of measurement.
- **Inventory** is tracked per product and warehouse in `inventory_stock`; movements are in `inventory_movements`.
- **Orders** have `order_items` and optional `order_stock_reservations`.
- **Invoices** have `invoice_items` and `payments`.
- **Stock transfers** record source warehouse, destination warehouse, destination shop, status, and actor timestamps.
- **Godown receipts** record stock received into the Godown.
- **Storefront checkout** creates a `leads` row plus `storefront_lead_requests` and `storefront_lead_request_items`. It does not create an order, payment, or reservation.

### Godown-specific schema

The live `warehouses` table contains:

```text
shop_id              nullable
location_type        enum('store','godown'), default 'store'
is_godown            generated virtual column: location_type = 'godown'
godown_tenant_key    generated stored column: one active Godown per tenant
manager_user_id      nullable FK to users
chk_wh_type_shop     CHECK:
                       (location_type='store'  AND shop_id IS NOT NULL)
                    OR (location_type='godown' AND shop_id IS NULL)
```

This means:

- A normal store must have a `shop_id`.
- A Godown must have `shop_id = NULL`.
- `is_godown` is **generated**; it must never be written directly.
- Only one active Godown per tenant is allowed.

The live database also has `transfer_routes` and `user_godowns`, but no application code references either table.

### Access model

The live database has:

- 22 permissions.
- Two roles: `Administrator` and `shop manager`.
- Two users: `admin` and `kariakoo`.
- Four shops: `DEZL INVESTMENT LIMITED`, `kariakoo`, `machinga`, and `mwenge`.
- Two warehouses: `Main Shop` (shop 1) and `karikoo store` (shop 7), both currently `location_type = 'store'`.

---

## 6. Storefront assessment

The storefront is the most complete part of the system.

### Implemented

- Flat, catalogue-first layout.
- Product listing with search, category filters, availability filters, and sorting.
- Product detail pages with clean URLs via `.htaccess`.
- Session cart with quantity limits and freshness checks.
- Checkout that creates a lead request rather than a fake sale.
- Confirmation page.
- Newsletter subscription using `storefront_subscribers`.
- JSON/CSV product feeds.
- Responsive and accessibility-oriented markup.
- TZS currency display based on tenant settings.

### Data honesty

- The storefront reads real catalogue rows; it does not generate products, brands, reviews, or ratings.
- There is no reviews table in the schema. Review/rating display is conditional and does not invent content.
- Out-of-stock and empty states are shown honestly.
- Service products can be ordered without stock; physical products are limited by visible stock.

### Stock visibility

- `storefront_stock_expr()` sums stock only from warehouses where `is_godown = 0`.
- This correctly keeps central Godown stock out of public storefront availability.
- Because no Godown currently exists, all current stock is visible.

### Checkout behaviour

- Creates a lead and a storefront request.
- Does not create an order.
- Does not create a payment.
- Does not reserve stock.
- This matches the stated “order request” model.

### Current content caveat

- The live catalogue is currently demo data because of the seeder reset. The storefront code is fine; the data is not the original catalogue.

---

## 7. Godown workflow: intended versus actual

The intended workflow is:

1. Admin selects a shop/store as the default Godown.
2. The Godown’s available products and quantities are visible.
3. Stock can be transferred from the Godown to any store.
4. Shops can request products from the Godown.
5. Requests move through approval, dispatch, and receipt.

### What is implemented

- `godown.php` contains designation, stock, receipt, and dashboard logic.
- `godown-transfer-add.php` can create transfers and request-style transfers.
- `godown-transfers.php` lists transfers.
- `godown-transfer-view.php` handles workflow actions.
- `stock_transfers` supports `draft`, `approved`, `dispatched`, `received`, `discrepancy`, and `cancelled`.
- `requested_by`, `approved_by`, `dispatched_by`, and `received_by` timestamps exist.
- `godown_receipts` records stock received into the Godown.
- Switching rules exist in code: a Godown should not be switched while it has stock or open transfers.

### What is broken

| Intended behaviour | Actual behaviour |
|---|---|
| Designate a store as Godown | Code writes `warehouses.is_godown = 1`. MariaDB ignores writes to the generated column with warning 1906 and returns `affected_rows = -1`. The designation check fails. |
| Find the active Godown | Helpers join `warehouses` to `shops` on `w.shop_id`. Godown rows must have `shop_id = NULL`, so the join excludes them. |
| Show Godown stock | The page exists, but the Godown cannot be found through the current joins. |
| Transfer from Godown to any store | The transfer page exists, but the source Godown lookup fails. |
| Let shops request stock | `godown-transfer-add.php` accepts `request_godown_transfers`, but that permission does not exist in the live database. |
| Let requesters see their requests | `godown-transfers.php` and `godown-transfer-view.php` require only Godown staff permissions. The sidebar also hides the link. |
| Use configured transfer routes | `transfer_routes` exists but is unused by code. |
| Use Godown managers | `warehouses.manager_user_id` exists but is unused by code. |
| Use per-user Godown access | `user_godowns` exists but is unused by code. |

### Current Godown state

- Live warehouses: `Main Shop` (shop 1) and `karikoo store` (shop 7).
- Both have `location_type = 'store'`.
- Both have `is_godown = 0`.
- There is currently **no Godown**.
- `stock_transfers` has 0 rows.
- `godown_receipts` has 0 rows.
- `user_godowns` has 0 rows.

### Generated-column failure detail

A direct test was run inside a transaction:

```sql
UPDATE warehouses SET is_godown = 1 WHERE warehouse_id = 1;
```

MariaDB returned:

```text
Warning 1906: The value specified for generated column 'is_godown'
in table 'warehouses' has been ignored
```

`ROW_COUNT()` returned `-1`, and `location_type` remained `store`.

This is the root cause of the failed Godown designation.

---

## 8. Security findings

| Severity | Finding | Impact |
|---|---|---|
| **Critical** | `database/` is inside the web root and has no deny rule. | Seed scripts and SQL schema are publicly reachable. |
| **Critical** | `database/seed_demo.php` executes on GET and deletes/recreates tenant-1 business data. | Anyone who requests the URL can reset products, stock, contacts, suppliers, POS, invoices, payments, expenses, and audit logs. This is how the local database was reset. |
| **Critical** | `database/seed_agen.php` truncates an even larger set of tables, including users and roles. | A single request could wipe tenant data and users. |
| **Critical** | `database/schema_business_v2.sql` is downloadable over HTTP. | Schema disclosure; confirmed HTTP 200, approximately 28 KB. |
| **Critical** | Tracked secrets: `config.php`, `u839560293_agensupply.sql`, and `business-project-database-and-system-structure-review.json`. | Production credentials are in Git history and may already be exposed. `.gitignore` does not remove already-tracked files. |
| **High** | `config.php` calls `session_start()` without application-level `httponly`, `secure`, or `samesite` cookie settings. | Session cookies rely entirely on server defaults. |
| **High** | No login throttling or lockout observed. | Password guessing is not rate-limited by the application. |
| **High** | Tenant isolation is enforced only by application queries. | A missed `tenant_id` filter could expose or modify another tenant’s data. |
| **Medium** | `find_tenant_godown()` deliberately looks up a Godown that may belong to another shop within the same tenant. | This is intended for requests, but it needs strict tenant and permission validation. |
| **Medium** | `config.example.php` is syntactically valid but runtime-invalid: `DB_PORT` has no value, the timezone string is invalid, and DB host/name are empty. | New installations fail at runtime. |
| **Medium** | Root `.htaccess` only handles product URL rewriting. | No security headers, no directory deny rules, no HTTPS enforcement. |
| **Medium** | No automated security or regression tests. | Schema and permission regressions are only found manually. |
| **Low** | CSRF enforcement is per-endpoint rather than centralized. | Any new POST handler that forgets `verify_csrf()` is vulnerable. |
| **Low** | Audit logging exists but is sparse and was reset by the seeder. | Limited forensic value after a reset. |

**Immediate security conclusion:** the `database/` directory and the tracked credentials are more urgent than any Godown feature work. The current deployment should not be exposed publicly until those are fixed.

### Verified public exposure

- `http://localhost/business/database/schema_business_v2.sql` returned HTTP 200 with approximately 28,121 bytes.
- `http://localhost/business/database/seed_demo.php` executed the seeder.
- There is no `database/.htaccess` deny rule.
- The root `.htaccess` does not deny access to `database/`.

### Git exposure

The repository has a single visible commit:

```text
c40a476 Intial commit with confir.example file
```

Tracked despite ignore rules:

- `config.php`
- `u839560293_agensupply.sql`
- `business-project-database-and-system-structure-review.json`
- `database/schema_business_v2.sql`

`.gitignore` contains `config.php` and `*.sql`, but already-tracked files remain tracked. The JSON review file exposes production credentials.

---

## 9. Current data state and recovery

### What the demo seeder changed

`database/seed_demo.php` deletes tenant-1 rows from:

`expenses`, `pos_order_items`, `pos_payments`, `payments`, `invoice_items`, `invoices`, `inventory_movements`, `inventory_stock`, `products`, `product_categories`, `suppliers`, `contacts`, `audit_logs`, `pos_orders`, `pos_sessions`.

It then recreates demo contacts, suppliers, categories, products, stock, POS sales, invoices, payments, expenses, and one audit log.

### Live database now

| Table | Current count |
|---|---:|
| Products | 9 demo products |
| Inventory stock rows | 8 |
| Audit logs | 1 |
| Contacts | 6 demo contacts |
| Suppliers | 4 demo suppliers |
| POS orders | 9 |
| POS sessions | 1 |
| Invoices | 8 |
| Invoice items | 13 |
| Payments | 4 |
| Expenses | 26 |
| Orders | 5 |
| Leads | 5 |
| Order items | 5 |
| Storefront lead requests | 4 |
| Storefront request items | 4 |
| Subscribers | 0 |
| Stock transfers | 0 |

All 8 stock rows are at warehouse 1. The original `soap` product is gone from the live catalogue.

### Current demo catalogue

| ID | Product | Code | Sale price |
|---:|---|---|---:|
| 1 | Mineral Water 1.5L | PRD-001 | 1,500.00 |
| 2 | Soda 300ml (Crate) | PRD-002 | 15,000.00 |
| 3 | Mango Juice 1L | PRD-003 | 3,500.00 |
| 4 | LED Bulb 9W | PRD-004 | 3,000.00 |
| 5 | Phone Charger USB | PRD-005 | 7,000.00 |
| 6 | Bluetooth Speaker | PRD-006 | 28,000.00 |
| 7 | Laundry Detergent 2kg | PRD-007 | 12,000.00 |
| 8 | Cooking Oil 5L | PRD-008 | 19,500.00 |
| 9 | Delivery Service (per km) | PRD-009 | 1,000.00 |

### Orphaned references

The surviving order and request history still references the deleted product:

- All 5 `order_items` rows have `product_id = 30` and snapshot name `soap`.
- All 4 `storefront_lead_request_items` rows have `product_id = 30` and snapshot name `soap`.
- Lead 1 has notes `soap`.

There is currently no product with `product_id = 30`. The snapshot names preserve history, but the live product references are orphaned.

### Recovery backups

| File | Timestamp | Contents |
|---|---|---|
| `C:\Users\San Cleo\Downloads\u839560293_agensupply (1).sql` | 28 Oct 2026 11:28 | 1 product `soap` (id 30), 1 stock row of 60 at warehouse 11, 112 audit logs, 5 contacts. No suppliers, POS orders, invoices, or expenses. Includes `location_type`; lacks `transfer_routes` and `user_godowns`. |
| `C:\Users\San Cleo\Downloads\u839560293_agensupply.sql` | 28 Oct 2026 10:50 | Also contains `soap`, but lacks `location_type`; older schema. |

Neither backup is a perfect pre-reset snapshot:

- The 11:28 dump does not contain the 19-unit warehouse-1 stock row that existed before the reset.
- The 11:28 dump lacks `transfer_routes` and `user_godowns`, which the live database has.
- The 10:50 dump predates the Godown separation schema.

A full restore would therefore regress schema and lose stock. A selective data merge is the safer path: restore only the affected data tables, preserve the live schema, and reconcile the missing stock row.

### Restore status

No restore has been applied. The live database is still in the seeded state.

A throwaway comparison database named `agensupply_restore_check` was created from the 11:28 dump before the stop instruction. It contains only a copy for comparison and does not alter the live database. It can be dropped when cleanup is authorized.

---

## 10. Migration and schema drift

| Migration | Status | Problem |
|---|---|---|
| `001_phase2_shops_stores` | Applied | File exists |
| `002_warehouse_shop_integrity_pos_sessions` | Applied | File exists |
| `003_orders_leads` | Applied | File exists |
| `004_storefront_lead_requests` | Applied | File exists |
| `005_storefront_request_notifications` | Applied | File exists |
| `006_order_stock_reservations` | Applied | File exists |
| `007_godown_transfers` | Applied | File exists |
| `008_storefront_subscribers` | Applied | File exists |
| `009_godown_store_separation` | Applied | **No migration file exists** |
| `009_godown_shop_requests` | Not applied | **Reuses number 009 and has never run** |

Additional drift:

- `database/schema_business_v2.sql` is stale. Its `warehouses` table only has `warehouse_id`, `tenant_id`, and `name`; it does not reflect `shop_id`, `location_type`, `is_godown`, `manager_user_id`, or the check constraint.
- The live database has 22 permissions. `request_godown_transfers` is absent.
- The live database has only two roles: `Administrator` and `shop manager`.
- `seed_demo.php` grants permissions to role IDs 2, 3, and 5, but those roles do not exist in the live database. Those grants are silently skipped.
- `transfer_routes`, `user_godowns`, and `manager_user_id` exist but are unused by application code.

This means a fresh environment cannot be rebuilt reliably from the repository. Schema state depends on manual database work that is not captured in migrations.

### Live permission set

The live database contains 22 permissions, including:

```text
manage_users
manage_contacts
manage_suppliers
manage_products
manage_inventory
manage_pos
manage_invoices
manage_receipts
view_reports
view_dashboard
manage_expenses
view_activity_log
manage_settings
manage_shops
manage_orders
manage_leads
manage_godown
approve_godown_transfers
dispatch_godown_transfers
receive_godown_transfers
manage_stores
view_godown
```

`request_godown_transfers` is not present.

The `shop manager` role has 11 permissions. It does not have the Godown requester permission.

---

## 11. Inconsistencies and defects

### Critical

1. **Generated-column write failure**
   - Code writes `warehouses.is_godown`.
   - The database defines `is_godown` as `GENERATED ALWAYS AS (location_type = 'godown') VIRTUAL`.
   - MariaDB ignores the write and returns `affected_rows = -1`.
   - Godown designation therefore fails at the code’s `affected_rows !== 1` check.

2. **Godown rows are invisible to access helpers**
   - `accessible_stores()`, `find_accessible_store()`, `find_active_godown()`, and `find_tenant_godown()` join `warehouses` to `shops` on `w.shop_id`.
   - The schema requires Godown rows to have `shop_id = NULL`.
   - A valid Godown would therefore be excluded from every lookup.

3. **Destructive seeders are web-accessible**
   - `seed_demo.php` and `seed_agen.php` have no CLI guard, authentication, or confirmation.
   - They execute on GET.
   - This already caused the local data reset.

4. **Secrets are tracked**
   - `config.php`, the SQL dump, and the review JSON are tracked despite `.gitignore`.
   - The JSON exposes production credentials.
   - The single initial commit means they are in Git history.

### High

5. **Requester permission is missing**
   - `request_godown_transfers` is defined only in the unapplied migration.
   - The live database has no such permission.
   - Shop managers cannot access request creation.

6. **Requester pages are guarded by staff permissions**
   - `godown-transfers.php` requires `manage_godown`, `approve_godown_transfers`, `dispatch_godown_transfers`, or `receive_godown_transfers`.
   - `godown-transfer-view.php` uses the same staff-only guard.
   - A requester cannot view their own request after creating it.

7. **Sidebar hides the requester path**
   - The transfer link is not exposed to users who only have request permission.

8. **Migration numbering collision**
   - `009_godown_store_separation` is applied but has no file.
   - `009_godown_shop_requests` exists but is unapplied.
   - Running migrations in order is ambiguous.

9. **Stale schema dump**
   - `database/schema_business_v2.sql` does not match the live schema.
   - It is also publicly downloadable.

### Medium

10. **Unused Godown schema features**
    - `transfer_routes`, `user_godowns`, and `manager_user_id` exist but are not used.
    - Either implement them or remove them to avoid false expectations.

11. **`config.example.php` is runtime-invalid**
    - `define('DB_PORT', );` has no value.
    - `date_default_timezone_set('East africa Time')` is invalid.
    - DB host, name, and base URL are empty.

12. **Local data is demo data**
    - The storefront and admin pages now show seeded demo records rather than the original catalogue.
    - Existing order and request history references deleted product 30.

13. **No automated tests**
    - No test suite covers permissions, tenant isolation, generated columns, transfer state transitions, or checkout.
    - `php -l` only checks syntax.

14. **Dual request/order models**
    - Storefront requests use `storefront_lead_requests`.
    - Staff orders use `orders`.
    - Conversion between them exists, but the two models and their stock-reservation behaviour need explicit tests.

15. **Transfer destination redundancy**
    - `stock_transfers` stores both `destination_shop_id` and `destination_warehouse_id`.
    - These can drift unless application logic keeps them consistent.

16. **Generated `is_godown` remains in queries**
    - Even where code only reads `is_godown`, `location_type` would be clearer and less fragile.
    - All access logic should standardize on `location_type` and treat `is_godown` as a derived convenience only.

17. **Demo seeder role grants target missing roles**
    - `seed_demo.php` references role IDs 2, 3, and 5.
    - The live database only has role IDs 1 and 15.
    - Those permission grants are skipped.

---

## 12. Recommendations

### Immediate, before any public deployment

1. **Block web access to `database/`**
   - Add a deny rule for the directory, or move seeders and schema files outside the document root.
   - Add a CLI guard such as:

     ```php
     if (PHP_SAPI !== 'cli') {
         http_response_code(403);
         exit("This script can only be run from the command line.\n");
     }
     ```

   - Apply it to every seed and migration script.

2. **Treat exposed credentials as compromised**
   - Rotate database and production credentials.
   - Remove `config.php`, the SQL dump, and the review JSON from the Git index.
   - Purge them from history if the repository has been shared.
   - Repair `.gitignore` and keep a safe `config.example.php`.

3. **Freeze Godown deployment**
   - Do not ship the current Godown pages until the generated-column and shop-join issues are fixed.

### Next

4. **Fix Godown identity in code**
   - Write `location_type = 'godown'`, never `is_godown`.
   - Change all access queries to handle `shop_id IS NULL` Godown rows.
   - Use `LEFT JOIN shops` where appropriate, and enforce the check constraint.

5. **Reconcile migrations**
   - Create a real migration file for the applied `009_godown_store_separation`.
   - Renumber the shop-request permission migration to `010`.
   - Add a migration runner that records checksums and refuses out-of-order changes.

6. **Complete requester access**
   - Add `request_godown_transfers` to the live permission set.
   - Grant it to the appropriate roles.
   - Update `godown-transfers.php`, `godown-transfer-view.php`, and `sidebar.php` so requesters can see only their own requests and cannot perform staff actions.

7. **Restore data carefully**
   - Restore only the affected data tables from the 11:28 dump.
   - Preserve the live schema.
   - Reconcile the missing 19-unit warehouse-1 stock row.
   - Restore product 30 or explicitly preserve the historical snapshots and clear the orphan references.

8. **Add regression tests**
   - Generated-column behaviour.
   - Godown designation and one-active-Godown constraint.
   - Requester versus staff permission matrix.
   - Tenant isolation.
   - Transfer state transitions.
   - Storefront checkout creating a lead/request only.

9. **Harden the runtime**
   - Set session cookie flags.
   - Add login throttling.
   - Add security headers.
   - Disable detailed error display in production.
   - Audit all POST handlers for CSRF.

10. **Refresh the schema documentation**
    - Regenerate `schema_business_v2.sql` from the migrated schema.
    - Remove the stale dump from the web root.
    - Document the migration chain.

11. **Decide the unused Godown features**
    - Implement `transfer_routes`, `user_godowns`, and `manager_user_id`, or remove them so the schema reflects actual behaviour.

---

## 13. Verification performed

- Inspected the live MariaDB schema: **51 tables**.
- Inspected `warehouses`, `products`, inventory, transfers, orders, storefront, users, roles, and permissions.
- Confirmed `is_godown` is a generated virtual column.
- Ran a test write to `is_godown` inside a transaction and confirmed MariaDB warning 1906 and `affected_rows = -1`.
- Confirmed the `chk_wh_type_shop` constraint requires Godown rows to have `shop_id = NULL`.
- Confirmed both live warehouses are `location_type = 'store'` and there is no Godown.
- Confirmed `request_godown_transfers` is absent from the live permissions table.
- Confirmed `009_godown_store_separation` is applied and `009_godown_shop_requests` is not.
- Confirmed `database/schema_business_v2.sql` is reachable over HTTP (200, approximately 28 KB).
- Confirmed `database/seed_demo.php` executes on GET.
- Checked HTTP responses for the storefront, product feed, and login page (all 200).
- Ran `php -l` across all PHP files: all passed.
- Confirmed no Composer, `vendor/`, `package.json`, or test suite exists.
- Compared both backup dumps read-only against the live database.
- No application code or live data was changed after the instruction to stop modifying the system.

### HTTP checks

| URL | Status | Size |
|---|---:|---:|
| `http://localhost/business/` | 200 | 15,565 bytes |
| `http://localhost/business/product-feed.php` | 200 | 647 bytes |
| `http://localhost/business/login.php` | 200 | 1,835 bytes |
| `http://localhost/business/database/schema_business_v2.sql` | 200 | 28,121 bytes |

### Database checks

| Check | Result |
|---|---|
| Live table count | 51 |
| Godown warehouses | 0 |
| `stock_transfers` rows | 0 |
| `godown_receipts` rows | 0 |
| `user_godowns` rows | 0 |
| `request_godown_transfers` permission | Missing |
| `009_godown_store_separation` | Applied |
| `009_godown_shop_requests` | Not applied |
| Demo products | 9 |
| Demo stock rows | 8 |
| Audit logs after reset | 1 |

---

## 14. Bottom line

The storefront is the completed, usable part of the system. The admin suite is broad and functional, but the Godown extension is currently a schema/code mismatch rather than a working feature.

The most urgent work is not feature development. It is:

1. Closing the web-exposed `database/` directory.
2. Removing tracked secrets and rotating exposed credentials.
3. Reconciling the migration and Godown schema.
4. Restoring the local data from a validated merge.
5. Adding regression tests before any further workflow changes.

Until those are addressed, the storefront can be treated as a working catalogue/request front end, but the Godown workflow and the current security posture are not production-ready.

---

## 15. Post-review implementation update (2026-10-29)

### Godown is now independent

- `godown.php` is a standalone Godown dashboard with Overview, Products, and Transfers tabs.
- Overview: add/edit/delete or archive the Godown, summary cards (Shops, Stores, Total Products, Godown Stock Qty), available shops/stores, and archived Godown history.
- Products: search by name/code/SKU/barcode, category filter, stock-status filter (in/out/at-or-below-minimum), 50-row pagination, receive-stock form, and recent receipts.
- Transfers: status/search filters, transfer history, and links to the full transfer workflow.
- A Godown is stored as `warehouses.location_type = 'godown'` with `shop_id = NULL`. `is_godown` remains generated and is never written directly.
- Deleting is blocked while stock or open transfers exist. A Godown with receipt/transfer/movement history is archived instead of hard-deleted.
- Only one active Godown per tenant is allowed by `uq_wh_active_godown`; archiving or deleting frees the slot.

### Shop requests

- `009_godown_shop_requests` is applied locally: `request_godown_transfers` exists and is granted to Administrator and Shop Manager.
- Requesters can open `godown-transfer-add.php`, create a `draft` transfer for an accessible shop/store, and see only transfers for their own accessible shops.
- Transfers list/view guards and sidebar visibility now include requesters.
- Source-side visibility is limited to Godown oversight staff, so requesters cannot see other shops' requests merely because they share the same Godown.

### Transfer trigger repair

- `trg_transfers_route` failed with `Illegal mix of collations (utf8mb4_unicode_ci vs utf8mb4_general_ci)` when a transfer was created under the app connection.
- `010_fix_transfer_route_collation` recreates the trigger with explicit `utf8mb4_unicode_ci` variables. No data was changed.

### Verified locally

- Created a Godown through the UI, received 12 units, verified in/out/low filters, and archived it with history preserved.
- Created a shop request as a shop manager, then approved, dispatched, and received it as an administrator. Godown stock moved 20 -> 18 and destination stock gained 2.
- Confirmed a shop requester cannot see a transfer destined for another shop.
- All test Godowns, transfers, receipts, stock rows, movements, and sequences were removed afterward; live `warehouses` again contains only Main Shop and karikoo store.

### POS amount paid (2026-10-29)

- `sale-add.php` checkout now has an **Amount Paid** field that defaults to the sale total and recalculates when the cart or discount changes.
- Walk-in customers: the field stays read-only at the full total; a partial or over payment is rejected on both the client and the server.
- Registered customers (`contacts.is_customer = 1 AND is_active = 1` in the current tenant): the field becomes editable and accepts 0 up to the total. Overpayment is rejected; the unpaid remainder is shown as balance.
- Paid amounts are written to `pos_payments` (now also for walk-in sales when the amount is above zero). Paid and Balance Due are derived from `pos_payments`; `pos_orders` has no amount columns.
- `sale-details.php` shows Paid and Balance Due.
- Verified through the UI and HTTP: walk-in partial rejected, walk-in full recorded, registered overpayment rejected, registered partial recorded (paid 17,200 of 17,700, balance 500), auto-fill/readonly/balance switching confirmed with no console errors.
- Both test sales and their stock movements/payments were removed afterward; stock returned to product 1 = 36 and product 2 = 19 at Main Shop.

### Product brand, Date Added, and list totals (2026-10-29)

- `products.brand` was added and the product forms now offer **GSP GROUP** and **TECHDOKTA INVESTMENT**. Invalid posted brands are rejected server-side.
- `products.manufactured_date` was renamed to `products.date_added` (existing dates kept) and the form field is now **Date Added**, defaulting to today on new products.
- The Expiry Date field was removed from the add and edit forms; the `expires_date` column is left in place so no existing value is destroyed.
- The Products list has a Brand column and a sticky **floating totals row** pinned to the bottom of the screen while the list is in view. It shows the product count plus **total purchases** and **total expected sales**, the sums of the unit purchase and sale prices of the products in the list, and recalculates as the table is searched or paged.
- The Products list now has server-side filters for Brand (including “No brand”), Category (a main category also includes its subcategories), Stock status (in/low/out), and Active/Inactive status. A Reset link clears them.
- New products with initial stock always place that stock in the active Central Godown; the Add Product form no longer offers a store selector and shows an error if initial stock is entered without a Godown. Shops and stores receive stock through transfers.
- `product-view.php` shows Brand and Date Added; the product feed now publishes the brand.
- Applied `database/migrations/011_product_brand_date_added.php`; verified create/edit/reject paths and list recalculations, then removed the test product and restored the product auto-increment.

### Inventory tabs and summary cards (2026-10-29)

- Inventory now has **Godown**, **Stores**, and **Shops** tabs. Each tab has a location selector (the Godown, the store, or the shop to inspect), server-side search/category/stock filters, and a Reset link.
- The summary cards are now tab-scoped and follow the active filters: stock quantity (labelled **Godown Stock Qty**, **Store Stock Qty**, or **Shop Stock Qty** depending on the open tab), **Available Products** (products in the current list), **Low Stock**, and **Out of Stock**. The per-listing summary strip below the filters was removed; the global Shops/Stores/Total Products/Godown Qty cards were replaced.
- Verified: default tab, all three tabs, empty-Godown and empty-store states, search (`Mineral` -> 1 product, 36 units), category (Beverages -> 3 products, 64 units), stock filters (out -> 1, low -> 0), karikoo store/shop (8 products, 0 units, 8 out), Godown tab with a temporary Godown (8 products, 5 units, 1 low, 7 out), tab-aware cards (Store/Shop 221 qty, 8 available, 0 low, 1 out; Godown 5 qty, 8 available, 1 low, 7 out), filtered cards (`stock=out` -> 0 qty, 1 available, 1 out; `q=Mineral` -> 36 qty, 1 available), and the inline adjust redirect. Temporary Godown/stock/movement were removed; stock is back to Main Shop product 1 = 36 and 8 stock rows.

### Still outstanding

- `database/` is still web-accessible and contains destructive seeders.
- Tracked secrets still need rotation; migrate to `config.example.php` + local `config.php`.
- Migration numbering is still duplicated: two applied `009_*` entries exist with distinct names.
- No automated regression tests cover the Godown or transfer workflows.
- The local `kariakoo` shop-manager password was reset during testing and must be changed by the owner.
