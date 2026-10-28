# Business System — Model Handoff

**Purpose:** Code-grounded overview of the PHP business management application for future developers or AI assistants.  
**Review date:** 2026-10-27  
**Workspace:** `C:\xampp\htdocs\business`

## 1. Executive summary

This is a server-rendered PHP and MySQL business-management application branded **DEZL BUSINESS SYSTEM**. It provides a public product storefront and guest order requests alongside tenant-scoped staff modules for shops/stores, orders, leads, customers, suppliers, products, inventory, point-of-sale sales, invoices, invoice payments, expenses, reports, audit activity, and company settings.

The application is made up primarily of standalone PHP page endpoints. Shared configuration, authentication, layout, and helper code is included by those endpoints. The database is MySQL/MariaDB, accessed through PHP `mysqli` prepared statements. The UI uses Bootstrap 4, jQuery, DataTables, Chart.js, and Font Awesome assets.

This document describes features visible in the source. It does not certify accounting, tax/TRA, regulatory, deployment, or live-database behavior.

## 2. Navigation map

The root `index.php` is the public product catalogue; browsing and guest order requests do not require staff authentication. Staff sign in at `login.php` and use the protected dashboard and permission-gated sidebar:

```text
Public storefront                     index.php
├── Search / category filter
├── Cart                              cart.php
├── Guest checkout                    checkout.php
└── Staff login                       login.php

Staff application
├── Dashboard                         dashboard.php
├── Users                             users.php
├── Roles & Permissions               permissions.php
├── Customers                         customers.php
├── Leads                             leads.php
├── Suppliers                         suppliers.php
├── Products                          products.php
├── Inventory                         inventory.php
├── Shops & Stores                    shops.php
├── Orders                            orders.php
├── Sales                             sales.php
├── Invoices                          invoices.php
├── Expenses                          expenses.php
├── Payments                          receipts.php
├── Reports                           reports.php
├── Activity Log                      audit-log.php
└── Settings                          settings.php

Top bar
├── Global search                     staff AJAX suggestions
├── My Profile                        profile.php
└── Logout                            logout.php
```

The storefront header exposes cart access and a staff Login link; mobile layout keeps the link visible. The staff sidebar hides links based on permissions; protected endpoints also enforce permissions through `auth_check.php`. A hidden link is not a substitute for checking the route's actual server-side guard before changing access behavior.

### Page/operation routes

| Area | List / entry | Related routes and operations |
|---|---|---|
| Authentication | `login.php` | `logout.php`; `auth_check.php` guards protected pages |
| Public storefront | `index.php` | Public product catalogue, search/category filter, session cart, guest checkout, order-request confirmation |
| Dashboard | `dashboard.php` | KPIs, sales chart, invoice-status chart, low-stock list, recent sales and customers |
| Users | `users.php` | `user-add.php`, `user-edit.php`, `user-view.php`, `permissions.php` |
| Customers | `customers.php` | `customer-add.php`, `customer-edit.php`, `customer-view.php` |
| Leads | `leads.php` | `lead-add.php`, `lead-edit.php`, `lead-view.php`, `lead-action.php` |
| Suppliers | `suppliers.php` | Add, edit, and delete supplier operations are handled by this page |
| Products | `products.php` | `product-add.php`, `product-edit.php`, `product-view.php`; category operations are also in `products.php` |
| Inventory | `inventory.php` | `inventory-adjust.php` for restocking/adjusting stock |
| Shops & Stores | `shops.php` | `stores.php`, `user-shops.php`; manage branches, warehouses, and user access |
| Orders | `orders.php` | `order-add.php`, `order-edit.php`, `order-view.php`, `order-action.php`; customer requests do not automatically reserve stock |
| POS sales | `sales.php` | `sale-add.php`, `sale-details.php`, `sale-edit.php`, `sale-cancel.php`, `sale-delete.php` |
| Invoices | `invoices.php` | `invoice-add.php`, `invoice-details.php`, `invoice-edit.php`, `invoice-print.php` |
| Payments | `receipts.php` | `payment-add.php` |
| Expenses | `expenses.php` | Expense and expense-category operations are handled by this page |
| Reports | `reports.php` | `report-print.php`; spreadsheet-compatible exports below |
| Settings | `settings.php` | `save-settings.php`; payment methods and tax rates are managed here too |
| Search | Top-bar search | `ajax-search.php`; POS product search/cart helpers include `ajax-product-search.php` and `ajax-cart-update.php` |
| Exports | — | `export-sales.php`, `export-invoices.php`, `export-receipts.php`, `export-expenses.php` |

Most list pages offer list filtering/search and detail/edit/create paths. Shared table behavior is initialized in `footer.php`; it uses DataTables with a default page length of 10 and 10/25/50/all options.

## 3. Feature inventory

### Dashboard

- Tenant-scoped counts for users, customers, products, and invoices.
- Current-month completed POS sales total.
- Invoice totals by month and invoice counts by status.
- Low-stock products (up to five) based on each product's minimum stock level.
- Recent POS orders and recently created customers.
- Permission-dependent shortcut to create a sale.
- Users without dashboard permission are redirected to the first available staff module among sales, invoices, payments, customers, or products, or to their profile.

### Public storefront and orders

- The root URL shows the active product catalogue, with search and category filtering; customers do not need a staff account to browse.
- Guests can add items to a session cart and submit an order request with only their name, phone, and location.
- Checkout creates or reuses a tenant customer record (matched by phone digits) with a `CUS-...` customer number, then creates a new storefront Lead. Product/name/description/unit/price/tax snapshots remain attached to that Lead request until confirmation.
- The Leads navigation badge counts unread storefront requests per staff user. Opening the Leads tab marks existing requests as seen; newer requests appear in the badge. Confirming a storefront request converts its Lead and creates one staff-reviewable Order with copied item snapshots; the transaction prevents duplicate Orders. The Orders list does not show the request before confirmation.
- Public requests do not create a POS sale or invoice, deduct or reserve inventory, or collect online payment. The thank-you page displays the Lead reference and customer number.
- The storefront's Login link leads to the existing staff `login.php`; successful staff login continues to the protected `dashboard.php`.
- Staff manage orders and leads with separate `manage_orders` and `manage_leads` permissions. Leads support assignment, follow-up history, outcomes, and links to orders.

### Users, roles, and permissions

- User account administration, profile views/edits, and active/inactive state are represented by dedicated pages.
- Authentication accepts username or email plus password; password checking uses `password_verify`.
- Users can have roles; roles aggregate permissions.
- `permissions.php` creates/deletes roles, assigns/unassigns permissions, and displays users assigned to roles.
- The permission catalog also includes shop and workflow permissions such as `manage_shops`, `manage_orders`, and `manage_leads`, alongside the existing user, sales, inventory, invoice, expense, report, and settings permissions.
- Profile page/update endpoints: `profile.php` and `update-profile.php`.

### Customers and suppliers

- Customer records live in `contacts` (customer flag, identity/company, contact details, address, tax ID, notes, active state).
- Customer list and detail pages are present. The detail page shows related invoice/payment and POS activity and offers context actions where the current user's permission allows.
- Supplier records use a separate `suppliers` table and are managed through `suppliers.php`.

### Products and inventory

- Product records include identifying data, sale price, tax rate, unit, category, service-vs-stock flag, minimum stock level, and active state.
- Product categories are hierarchical; helpers build a depth-limited category chain/tree.
- Product creation and editing use category selectors; product detail pages are available.
- Inventory is modeled per warehouse. Stock adjustment adds quantities and records an inventory movement, with optional production/expiry dates and notes.
- Sales and invoice creation subtract stock for physical products; services are excluded.
- POS and invoice creation check stock availability. The dashboard identifies low-stock items.
- Inventory history is represented in `inventory_movements`; stock records are in `inventory_stock`.

### POS sales

- Product browser/search, cart add/update/remove/clear, customer selection, discount, tax calculation, and checkout.
- Tax can be disabled per tenant in Settings. Per-product tax rate is used when tax is enabled.
- Checkout creates a POS order and line items, creates/uses a POS session, decrements stock for physical goods, and records inventory movement rows.
- Sales can be listed, viewed, edited, cancelled, or deleted. Cancellation restores stock and records a movement.
- POS session, order, order-item, and POS-payment entities exist in the schema.

### Invoices and receipts/payments

- Create, edit, view, print, and list invoices; invoices can include customer, line items, issue/due dates, reference, notes, terms, status, tax, and amounts due/paid.
- Invoice numbering uses a configured prefix and next-number counter.
- Creating an invoice checks/decrements stock for physical products and logs movements.
- Payments are recorded against invoices; payment must be positive and no greater than the current balance. The invoice paid/due values and Paid/Partially Paid status are updated.
- Payment methods are configurable in Settings. Receipts/payment records are listed in `receipts.php`.
- Printable invoice and report views are implemented as HTML print layouts, rather than a dedicated PDF library.

### Expenses

- Expense entry supports category, description, amount/date, payment method, notes, and itemized expense rows.
- Expense categories can be added and deleted subject to usage checks.
- Expense list includes filtering and edit/delete operations.

### Reports, exports, and activity

- Reports page offers today, week, month, year, all-time, and custom date ranges.
- Dashboard-style report totals include POS orders/sales, invoice amounts, outstanding balances, income, expenses, and net income.
- Monthly invoice totals and payments received are shown in tables.
- Printable report types: income statement, sales, payments, expenses, receivables, stock/inventory, and system activity.
- Spreadsheet-compatible `.xls` downloads exist for sales, invoices, receipts, and expenses.
- `audit_logs` records application events; `audit-log.php` displays activity.
- Report labels include “Income Statement (TRA)” and mention tax submission. Source inspection alone does not establish that outputs satisfy any tax authority's official filing requirements.

### Settings and global search

- Company name/contact details, currency, invoice prefix/counter, VAT/tax toggle, and logo upload/removal.
- Payment methods and tax rates can be managed.
- Supported currency choices in the settings UI include TZS, USD, EUR, GBP, KES, and UGX.
- Global search suggests products, customers, suppliers, invoices, sales, expenses, payments, activity, and users, filtered by available permissions and optionally by scope.

## 4. Architecture and file map

```text
config.php
├── DB connection / app constants / timezone / PHP session
├── helpers.php       escaping, money, DB helpers, permissions, flash, audit, categories
└── User.php          user loading, authentication, role and permission checks

header.php            HTML head, CSS/JS, opens application shell
auth_check.php        session validation, current user, cache headers, permission guards
navbar.php            top bar, global search, profile/logout menu
sidebar.php           permission-gated primary navigation
page endpoint         business logic + page content
footer.php            closes layout, initializes DataTables/search suggestions

database/
├── schema_business_v2.sql   schema definition / permission seed data
├── migrations/              additive shop/store and orders/leads migrations
├── seed_demo.php            demo-data seeder
└── seed_agen.php            tenant/business seeder
u839560293_agensupply.sql    database dump

assets/              active-looking copies of CSS, JS, fonts and images
css/, js/, fonts/    duplicate/legacy-looking asset copies also present
images/              logo asset
```

The majority of pages follow the include order `header.php` → `auth_check.php` → permission guard → page logic → `navbar.php`/`sidebar.php` → `footer.php`. Some action-only endpoints load `config.php` and `auth_check.php` directly, then redirect.

Public storefront endpoints share `storefront.php` for configuration/session bootstrap and catalogue/cart helpers, with a separate storefront header/footer and stylesheet. Staff order/lead pages use the shared authenticated application shell.

### Runtime and external packages

- PHP with `mysqli` and a MySQL-compatible database.
- Uses PHP features including arrow functions, `str_starts_with`, and `match`; deployment PHP must support these language features (PHP 8+).
- No Composer or npm manifest was present in the workspace inventory.
- Front-end libraries are checked into the repository; no external API/service integration was identified during this review.
- XAMPP is the local development/runtime environment; the public storefront and guest cart/checkout pages were exercised locally during the later implementation review.

## 5. Data model at a glance

The schema's central tenancy key is `tenant_id`; business records are generally filtered by the current user's tenant.

```text
tenants
├── users ── user_roles ── roles ── role_permissions ── permissions
├── contacts ── invoices ── invoice_items ── products
│                 └── payments
├── suppliers
├── products ── product_categories
│      └── inventory_stock ── warehouses
│             └── inventory_movements
├── shops ── warehouses
│      └── user_shops ── users
├── leads ── activities
└── orders ── order_items
     ├── optional contact / lead / shop / warehouse
     └── optional link to fulfilled invoice
├── pos_sessions ── pos_orders ── pos_order_items
│                            └── pos_payments
├── expenses ── expense_items ── expense_categories
├── system_settings
├── payment_methods / tax_rates / document_statuses
└── audit_logs
```

Additional schema tables include `contact_types`, `units_of_measurement`, `product_variants`, `deals`, `activities`, and `journal_entries`. Their presence in the schema does not imply a navigable or fully implemented UI module.

## 6. Important implementation notes for a future model

1. **Keep tenant scoping intact.** Use the current user’s `tenant_id` in every query and mutation for tenant-owned data. Check related ID ownership, not only the main record.
2. **Preserve permission behavior in both layers.** Navigation visibility is in `sidebar.php`; endpoint authorization is enforced in page/action code with `require_permission()` or `require_permission_any()`. Verify the specific endpoint before extending it.
3. **Stock updates are coupled to sales/invoices.** Changes to checkout, invoice creation, cancellation, or editing can affect `inventory_stock` and `inventory_movements`. Preserve transaction boundaries and test stock accounting.
4. **Invoice balances are coupled to payments.** Payment changes must keep `amount_paid`, `amount_due`, `is_paid`, invoice status, and payment history consistent.
5. **Use shared helpers and conventions.** `e()` for HTML escaping, prepared SQL, `fetch_one`/`fetch_all`/`db_insert`, `flash_set`/`flash_get`, `redirect`, `money`, and `audit_log`.
6. **Settings affect future transactions.** Currency and tax settings affect display/calculation; changing settings should not silently rewrite historical documents.
7. **Source inventory is the evidence.** Do not assume unimplemented screens for schema entities, infer accounting correctness from report names, or treat statements in `handout.html` as verified behavior.

## 7. Verification boundaries and observations

- This document began as a source review; subsequent local verification opened the public storefront and checked guest cart/checkout and staff-login routing. It does not certify production behavior or perform an end-to-end staff transaction test.
- **Database-name difference to resolve before setup/migrations:** `config.php` connects to `u839560293_agensupply`, while `database/schema_business_v2.sql` creates and selects `agen_business_v2`. The repository also contains `u839560293_agensupply.sql`, whose table inventory aligns with the app's tables. Do not import the schema into or change the configured database without confirming the intended environment.
- `default.php` and `start-mysql.bat` are present, but were not validated as app entry points or setup procedures.
- The user handout (`handout.html`) describes “Offline / local,” “TRA-friendly,” and multi-branch capabilities. The code confirms a local PHP/MySQL deployment path, warehouse-level stock, and print-oriented reports; it does not by itself prove regulatory compliance, branch-level features, or an offline guarantee.
- No automated test command or dependency manifest was apparent in the project inventory reviewed.

## 8. Suggested starting points by task

| If the next task concerns… | Start with… |
|---|---|
| Login, roles, access | `login.php`, `auth_check.php`, `User.php`, `permissions.php`, `sidebar.php` |
| Shared query/format behavior | `config.php`, `helpers.php` |
| Page shell and global UI | `header.php`, `navbar.php`, `sidebar.php`, `footer.php`, `assets/css/style.css` |
| POS checkout / stock | `sale-add.php`, `sale-cancel.php`, `sale-edit.php`, `inventory_stock` and `inventory_movements` schema |
| Invoice / payment balances | `invoice-add.php`, `invoice-edit.php`, `payment-add.php`, `invoice-details.php` |
| Report totals/print/export | `reports.php`, `report-print.php`, relevant `export-*.php` |
| Database setup | Confirm the config-vs-schema database name before using either SQL file |
