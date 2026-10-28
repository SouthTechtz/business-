# Stock, POS, Invoice, and Warehouse Review

**Review date:** 2026-10-27  
**Scope:** `config.php`; POS sale add/edit/cancel; invoice add/edit; inventory adjustment, product opening stock, warehouse selection and low-stock reporting; `database/schema_business_v2.sql`.

## Executive summary

The application stores stock by product and warehouse in `inventory_stock`; stock changes are made directly to its `quantity` value, with selected operations also appending `inventory_movements`. POS and invoice transactions use database transactions, but product line rows do not record which warehouse supplied their stock. Most selling flows choose the tenant's first warehouse, while cart displays and some validation aggregate stock across all warehouses.

The resulting implementation has material stock-integrity risks: available-stock checks can disagree with the warehouse actually decremented; sale/invoice edits and invoice deletion can leave stock and movement history inconsistent; and the low-stock threshold is applied differently by the dashboard and per-warehouse views. The project has manual restocking and opening-stock entry, but no dedicated purchasing/receiving workflow or warehouse-management UI was found in the PHP files.

This is a source review only. No live database, browser workflow, or concurrent transaction test was run.

## 1. Runtime and database configuration

[`config.php`](./config.php) connects with `mysqli` to `127.0.0.1:3306`, uses the `root` account with an empty password, selects database `u839560293_agensupply`, and sets the connection character set to `utf8mb4`. It also sets the timezone to `Africa/Dar_es_Salaam`, starts output buffering and a PHP session, and loads `helpers.php` and `User.php`.

**Setup mismatch:** [`config.php`](./config.php) selects `u839560293_agensupply`, but the reviewed schema file [`schema_business_v2.sql`](./database/schema_business_v2.sql) creates/selects `agen_business_v2`. Confirm which database is intended before importing schema or changing configuration.

## 2. Stock data model

Relevant tables and fields in [`schema_business_v2.sql`](./database/schema_business_v2.sql):

| Table | Role | Key schema facts |
|---|---|---|
| `warehouses` | Tenant-owned warehouse list | `warehouse_id`, `tenant_id`, name |
| `products` | Product/service catalogue | `tenant_id`, `purchase_price`, `min_stock_level`, `is_service` |
| `inventory_stock` | Current stock balance | Product + warehouse + tenant, quantity, reorder level, date fields; unique on `(product_id, warehouse_id)` |
| `inventory_movements` | Stock movement history | Product + warehouse + movement type + quantity + optional generic reference |
| `pos_sessions` | POS session | Has `warehouse_id`, but the order-item table does not |
| `pos_orders` / `pos_order_items` | POS sale and its lines | Order has `session_id`; order lines have product and quantity but no warehouse |
| `invoices` / `invoice_items` | Invoice and its lines | Invoice lines have product and quantity but no warehouse |

Important modeling constraints and omissions:

- The stock row's unique key prevents multiple balances for the same product/warehouse pair.
- The schema has separate foreign keys to tenant, product, and warehouse, but no composite constraint proving that a stock row's product and warehouse belong to the same tenant.
- POS and invoice item rows do not preserve the source warehouse. A later edit or reversal therefore cannot reliably restore the exact warehouse balance originally deducted.
- `inventory_movements.reference_id` and `reference_type` are generic fields, not foreign keys to the referenced document.
- No purchase order, goods receipt, or supplier-stock receipt table appears in this schema.
- There is no database check constraint preventing negative stock quantities.

## 3. Implemented stock workflows

### New POS sale

[`sale-add.php`](./sale-add.php):

1. Adding/updating a cart item checks quantity against the product's stock summed across warehouses.
2. Checkout chooses the tenant's lowest-ID warehouse (`ORDER BY warehouse_id LIMIT 1`), or falls back to ID `1`.
3. Checkout finds or creates an open POS session. When reusing an existing session, it does not use that session's `warehouse_id` for stock deductions.
4. The order and its lines are written in a transaction. Physical-product quantities are deducted from the selected default warehouse and a `sale` movement is inserted. Services are not deducted.

The cart check is not a reservation: stock may change between cart validation and checkout. Checkout itself does not repeat the availability check, use a conditional `quantity >= requested` update, or verify affected stock rows.

### Edit/cancel POS sale

[`sale-edit.php`](./sale-edit.php) adjusts `inventory_stock` inside a transaction when existing item quantities change, items are removed, or new items are added. It selects the first warehouse again. These stock changes do **not** append corresponding `inventory_movements` rows.

[`sale-cancel.php`](./sale-cancel.php) restores quantities and writes `sale_cancel` movements, but selects the current first warehouse rather than deriving the warehouse from the order's POS session. Sale line rows do not record a warehouse.

### Create/edit invoice

[`invoice-add.php`](./invoice-add.php):

- Selects the first warehouse (or falls back to ID `1`).
- Checks physical-product stock in that warehouse, creates the invoice/lines, deducts the stock, and writes `invoice` movement entries, all within a transaction.
- Performs the availability-check loop before the deduction loop. If the same product is submitted on multiple invoice lines, each line can pass against the same original balance even when the combined quantity exceeds it.

[`invoice-edit.php`](./invoice-edit.php) restores stock when lines are reduced/removed and deducts stock when quantities increase or new items are added. It also picks the current first warehouse. Unlike invoice creation, its stock changes do not append movement-history rows.

`invoices.php` also permits deleting an invoice with no payments. Its delete path removes invoice items and the invoice but does not restore stock or create reversal movements.

### Manual stock entry and adjustment

- [`product-add.php`](./product-add.php) can create an opening stock balance in the first tenant warehouse and logs an `adjustment` movement. If no warehouse exists, product creation still succeeds but the requested opening stock is not created.
- [`inventory.php`](./inventory.php) lists balances by warehouse and supports an inline **add-only** adjustment. It updates the balance and writes a `restock` movement.
- [`inventory-adjust.php`](./inventory-adjust.php) lets the operator select a product and warehouse, adds a positive quantity, and writes a `restock` movement. It checks product ownership/service status and whole-vs-decimal units, but does not verify that the submitted warehouse belongs to the current tenant.
- No PHP purchase-order, goods-received, or supplier-linked stock-purchase workflow was found. A product's `purchase_price` is catalogue data, not a purchase record.
- No warehouse create/edit/delete management page or warehouse-selection setting was found. Warehouse rows are read by these workflows and must be provisioned elsewhere.

### Low-stock behavior

- [`index.php`](./index.php) sums stock across all warehouses, compares the aggregate with `products.min_stock_level`, and lists up to five low-stock products.
- [`inventory.php`](./inventory.php) compares each warehouse's individual balance with that same product-level minimum.
- [`product-view.php`](./product-view.php) displays total stock, but applies the same product minimum separately to each warehouse row.
- [`report-print.php`](./report-print.php) reports stock by product/warehouse and compares each warehouse quantity with the product-level minimum.
- Inventory list rows exist only where an `inventory_stock` row exists; a product with no balance row is not shown there. The dashboard's left join can still treat missing stock as zero.

Consequently, a product may be below its threshold in one warehouse but above the threshold in aggregate, causing per-warehouse views and the dashboard to disagree. The schema also has `inventory_stock.reorder_level`, but these UI comparisons use `products.min_stock_level` instead.

## 4. Findings and recommendations

| Priority | Finding | Evidence | Recommendation |
|---|---|---|---|
| **High** | POS stock is validated globally but deducted from one warehouse. A product can pass the all-warehouse cart check while the first warehouse lacks enough stock; checkout does not revalidate that warehouse. Reused POS sessions can also carry a warehouse that is ignored by deduction logic. | `sale-add.php:17-23, 49-54, 88-89, 115-124, 154-161`; schema `pos_sessions.warehouse_id` and order-line fields at `264-313` | Select/validate a specific tenant warehouse for the sale; use that same warehouse for display/check/deduction, and use an atomic conditional update or row lock with affected-row validation. Store the source warehouse on the sale line or otherwise make it recoverable. |
| **High** | Invoice stock can be oversold when one product occurs on multiple lines or requests race. The pre-checks run before any deductions, and deductions do not guard against a negative balance or inspect affected rows. | `invoice-add.php:67-80, 113-127`; schema `inventory_stock.quantity` at `218-235` | Aggregate requested quantities by product/warehouse before checking and use locked or conditional deductions within the transaction. Reject the transaction if any deduction fails. |
| **High** | POS/invoice edits and POS cancellation use the current first warehouse instead of a stored source warehouse. If stock was deducted from another warehouse, reversals can add stock to the wrong balance. | `sale-edit.php:23-24, 41-42, 70-80, 113-119`; `sale-cancel.php:13-15, 28-36`; `invoice-edit.php:30-31, 47-48, 75-85, 117-123`; schema line tables have no `warehouse_id` | Persist warehouse provenance per transaction line and reverse against that provenance. For existing data without provenance, define an explicit reconciliation/migration policy rather than guessing. |
| **High** | Deleting an eligible invoice removes its stock-bearing lines without reversing stock. | `invoices.php:8-24` | Prefer a cancel/void flow that validates payments, restores stock against the recorded source warehouse, writes reversals, and preserves the document/audit trail. |
| **Medium** | The movement ledger is incomplete: sale/invoice edits change balances without movements; invoice deletion also has no reversal movement. A ledger-based reconciliation cannot reproduce the live balance. | `sale-edit.php` stock mutations; `invoice-edit.php` stock mutations; `invoices.php:16-23` | Treat each balance change as a movement and write balance + movement atomically for all adjustments, edits, cancellations, and deletions/voids. |
| **Medium** | `inventory-adjust.php` accepts a posted warehouse ID without checking tenant ownership. Schema foreign keys do not enforce that the stock row's product and warehouse have the same tenant. | `inventory-adjust.php:11, 19-24, 37-62`; schema `inventory_stock` at `218-235` | Verify `warehouse_id` with `tenant_id` before mutation and enforce tenant-consistent relationships at the database layer where practical. |
| **Medium** | Low-stock views use inconsistent aggregation and thresholds; the dashboard uses total across warehouses, while inventory/detail/print views compare each warehouse independently. `reorder_level` is stored but not used for these badges. | `index.php:82-87`; `inventory.php:68-74, 129-132`; `product-view.php:104`; `report-print.php:136-141, 300-302`; schema `inventory_stock.reorder_level` | Define whether the threshold is per warehouse or tenant-wide, use the matching threshold field consistently, and include products with no stock row as zero where relevant. |
| **Low** | Quantity controls are generally whole-unit HTML inputs (`step="1"`), despite server-side unit metadata allowing decimal quantities in adjustment flows. Product opening stock also lacks the decimal-unit validation present in adjustment endpoints. | `inventory-adjust.php` unit check and form; `inventory.php` adjustment form; `product-add.php` opening-stock handling/form | Align client validation with each product's unit and apply the same server-side whole/decimal validation on all stock-entry paths. |
| **Configuration** | Application and schema select different database names. | `config.php:6-10`; `schema_business_v2.sql:2-3` | Confirm the authoritative database and make local/deployment setup explicit before migration or testing. |

## 5. Recommended order of work

1. Confirm the intended database name and warehouse-selection rules.
2. Make warehouse identity durable on POS/invoice stock-bearing lines (or another immutable transaction record), then correct deductions and reversals.
3. Make every deduction atomic and guarded against insufficient balance; handle duplicate invoice lines in the pre-check.
4. Make movement history complete and add a safe invoice cancellation/void path.
5. Standardize low-stock semantics and tenant-validate selected warehouses.
6. Add a dedicated purchase/receiving workflow only if supplier purchases are in scope; current restock is manual stock addition.

## 6. Source map

- Runtime connection: [`config.php`](./config.php)
- POS creation/edit/cancel: [`sale-add.php`](./sale-add.php), [`sale-edit.php`](./sale-edit.php), [`sale-cancel.php`](./sale-cancel.php)
- Invoice creation/edit/delete: [`invoice-add.php`](./invoice-add.php), [`invoice-edit.php`](./invoice-edit.php), [`invoices.php`](./invoices.php)
- Manual inventory operations: [`inventory.php`](./inventory.php), [`inventory-adjust.php`](./inventory-adjust.php), [`product-add.php`](./product-add.php)
- Warehouse/product detail and alerts: [`product-view.php`](./product-view.php), [`index.php`](./index.php), [`report-print.php`](./report-print.php)
- Schema: [`schema_business_v2.sql`](./database/schema_business_v2.sql)
