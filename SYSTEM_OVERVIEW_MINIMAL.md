# Business System — Quick Overview

## Purpose

DEZL BUSINESS SYSTEM is a PHP/MySQL application with a public product storefront and guest order requests, plus a staff system for customers, leads, products, inventory, sales, invoices, payments, expenses, and reports. Staff access is tenant-scoped and permission-based.

## Navigation

```text
Public storefront (root URL)
├── Product search and category filters
├── Guest cart and checkout
└── Staff Login

Staff application
├── Dashboard
├── Users
├── Roles & Permissions
├── Customers
├── Leads
├── Suppliers
├── Products
├── Inventory
├── Shops & Stores
├── Orders
├── Sales (POS)
├── Invoices
├── Expenses
├── Payments
├── Reports
├── Activity Log
└── Settings

Top bar: Global Search · My Profile · Logout
```

Sidebar links are permission-dependent; protected pages also apply server-side permission checks.

## Core capabilities

- **Dashboard:** business counts, monthly POS sales, invoice summaries, low-stock items, recent sales and customers.
- **Storefront and orders:** guest checkout collects name, phone, and location; it creates/reuses a numbered customer and queues a Lead with item snapshots. Confirming that Lead creates an Order; the per-user Leads badge clears when opened and counts newer unconfirmed requests. No stock is reserved at request time.
- **Leads:** assignment, follow-up history, status/outcome tracking, storefront-request review, confirmation into Orders, and order links.
- **Administration:** user accounts, roles, permissions, and personal profile.
- **Contacts and catalogue:** customers, suppliers, products/services, categories, units, prices, and tax rates.
- **Inventory:** warehouse-level stock balances, manual restocking/adjustment, stock movement history, and low-stock indicators.
- **Sales and billing:** POS cart and checkout, sale editing/cancellation, invoice creation/editing/printing, and invoice payments.
- **Expenses and reporting:** expense entry, date-filtered reports, print-friendly reports, and spreadsheet-compatible exports.
- **Configuration:** business details, currency, invoice numbering, tax toggle, logo, payment methods, and tax rates.

## Technical structure

- Server-rendered PHP page endpoints with shared configuration, authentication, layout, and helper files.
- MySQL/MariaDB accessed through PHP `mysqli`.
- UI assets include Bootstrap 4, jQuery, DataTables, Chart.js, and Font Awesome.
- Key entry points: `config.php`, `helpers.php`, `User.php`, `auth_check.php`, `header.php`, `navbar.php`, `sidebar.php`, and `footer.php`.
- Public storefront pages: `index.php`, `storefront.php`, `cart.php`, `checkout.php`, and `order-received.php`; staff login remains `login.php`.
- New staff workflows: `orders.php` and `leads.php`; supporting schema changes are in `database/migrations/`.
- Main schema: `database/schema_business_v2.sql`; database dump: `u839560293_agensupply.sql`.

## Important setup note

`config.php` connects to `u839560293_agensupply`, while `database/schema_business_v2.sql` creates/selects `agen_business_v2`. Confirm the intended database before importing or changing configuration.

This is a source-based summary, not a live-system or regulatory-compliance verification.
