# Sales & POS Management Audit — DEZL Business System

| Item | Detail |
|---|---|
| **Report date** | 11 November 2026 |
| **Audit scope** | POS (`sale-add.php`, `ajax-cart-update.php`), sales lifecycle (edit / cancel / delete), sales returns, automatic POS invoicing, payment collection, Close Business, and the dual-tier brand capital engine (`capital-helpers.php`, `capital.php`) |
| **Measured against** | The capital and business-rule guidelines supplied with the audit request (Liquid Capital, Stock Capital, Total Brand Capital, operational impacts, brand isolation) |
| **Environment** | PHP 8.2 / MariaDB 10.4, XAMPP on Windows, database `u839560293_agensupply` |
| **Revision audited** | `main` @ `8244391` + uncommitted changes in `capital-helpers.php`, `capital.php`, `close-business.php`, `dashboard.php`, `expenses.php`, `report-print.php`, `reports.php`, `shop-view.php` and new `database/migrations/042_shop_cash_balances.php` |
| **Method** | Static code review of every sales/POS/capital endpoint, schema and index inspection, read-only SQL integrity checks against the live database, and execution of the application's own capital functions through a read-only CLI probe (temporary file, deleted after use) |
| **Data changed by this audit** | None. No INSERT/UPDATE/DELETE was executed against application tables. All probes were `SELECT`-only. |
---

> **Post-audit update (11 November 2026, same day).** After this audit was produced, the product direction changed: the brand capital model was reduced to **two tiers — Total Capital in Bank and Stock-Held Capital — with cash in hand, per-shop cash balances and Close Business removed**. The purchase-receiving workflow requested with that change has been implemented (migration 043 + `purchase-receive.php`). Several findings below therefore describe the previous model and are marked in §0. All sales/POS findings (SP-01, SP-03 … SP-10, SP-13 … SP-27) remain valid and unaddressed.

## 0. Addendum — capital model v2 (implemented after the audit)

### New rules

* **Total Capital in Bank** = capital added − capital withdrawn + all shop sales (POS totals, invoice payments, storefront order payments) − expenses − purchases paid − refunds.
* **Stock-Held Capital** = Σ `inventory_stock.quantity × products.purchase_price`.
* **Total Brand Capital** = Total Capital in Bank + Stock-Held Capital.
* There are **no opening balances** (removed later the same day, migration 044). Opening cash/bank and shop opening sales are gone from the schema, the UI and all reports.
* Withdrawals are capped at the brand's **Total Capital in Bank**.
* Per-bank availability = opening + capital in − capital out − expenses − purchases − refunds; the remainder is shown as **sales/cash capital not assigned to a named bank**.
* Close Business, `brand_to_shop` / `shop_to_brand` transfers and the shop cash ledger are retired from the UI.
* Purchases still do not add stock directly. When goods status becomes **Arrived at Godown**, the Godown Manager or system administrator must record the goods received, including damaged units; only `received − damaged` enters `inventory_stock`, and stock-held capital updates automatically.

### Implementation

| Area | Change |
|---|---|
| `capital-helpers.php` | `brand_capital_breakdown()` returns bank/stock/total only (legacy cash keys zeroed); `capital_balance_sheet()` reports `bank_balance`/`stock_capital`/`total_capital`; `bank_accounts_summary()` no longer reads deposits or internal transfers; new `shop_sales_totals()` and `brand_shop_sales_summary()`; shop-cash functions removed. |
| `capital.php` | Rewritten: three cards (Total Capital in Bank, Stock-Held Capital, Total Capital), bank-only capital movements, bank table with unassigned-sales row, funding impact, shop sales, movement history. Opening-capital form removed. |
| `dashboard.php` | Cash-in-hand / liquid cards and the Shop Cash Balances table replaced by bank + stock + total and a bank-only brand table. |
| `shop-view.php` | Close Business handler and cash balance removed; the card now shows **Total Shop Sales**. |
| `reports.php`, `report-print.php` | Balance sheet and memos report capital in bank + stock-held capital. |
| `sales.php` | Close Business button removed; `close-business.php` deleted. |
| `expenses.php`, `purchase-add.php`, `purchase-edit.php`, `sales-returns.php` | Helper copy updated: every expense/purchase/refund reduces capital in bank. |
| `database/migrations/043_purchase_receiving.php` | Adds `purchases.received_quantity/damaged_quantity/received_at/received_by/received_godown_id`, `godown_receipts.purchase_id/damaged_quantity`, and folds `opening_cash` into `opening_bank`. Applied and recorded. |
| `purchase-receive.php` (new) | Confirmation available to the master administrator, a brand Mini Admin (own brand) and the Godown Manager. Records received / damaged / returned / other quantities; good units enter stock; rejected units create a supplier-damage Sales Return. Validates quantities and decimal rules, locks godown stock and purchase rows, writes `godown_receipts`, `inventory_movements`, `inventory_stock` and the purchase receipt fields in one transaction; blocked on double receipt. |
| `purchase-status.php`, `purchase-add.php`, `purchase-edit.php` | Redirect to the receiving form when the status becomes `arrived_godown` (for users allowed to receive). Status/edit/delete are blocked once goods are received. |
| `purchases.php`, `purchase-view.php` | “Receiving due” / “Received” badges and a **Receive goods** action; the Godown Manager now also sees the receiving queue (arrived, unreceived purchases created by anyone). |

### Verification performed

* Full PHP lint of every root file and migration: clean.
* HTTP render smoke (built-in server + injected sessions) for Administrator, GSP Mini Admin, Godown Manager and Shop Manager on dashboard, capital, shop view, reports, printable balance sheet, sales, purchases, purchase view and the receiving page: no fatals or warnings; the Mini Admin correctly receives **403** on the receiving page.
* End-to-end receiving test, Administrator path: purchase of 5 units, 5 received, 1 damaged → stock **+4**, `GRN-000001`, movement `godown_receipt`, purchase marked received; stock capital **2,112,000 → 2,132,000**; a replay was rejected and changed nothing.
* End-to-end receiving test, Godown Manager path (purchase created by the administrator): stock **+2**, `GRN-000002`, `received_by = 49`.
* Both tests' rows were removed and stock/counters restored; live capital is back to GSP **2,112,000** and TECHDOKTA **7,885,000**.
* Two defects were found and fixed during this work: (1) `purchase-receive.php` passed cast expressions to `bind_param()` (PHP 8 fatal) — all arguments are now variables; (2) `purchase-receive.php` was missing from the Godown Manager page whitelist and the purchase row lock used an aliased condition against an unaliased query — both corrected.

### Status of the findings in this report

| Finding | Status after the change |
|---|---|
| **SP-02** purchase → stock disconnected | **Resolved in code** (receiving workflow). Data follow-up: reconcile the existing 50 godown units of product 5 and the manual `restock` rows against purchases #5/#6. |
| **SP-11** outflows not capped; negative shop cash | **Partly resolved.** Shop cash and the negative unallocated balance no longer exist; withdrawals are capped at total bank capital. **Still open:** expenses and supplier payments are not checked against available bank capital, and the 200,000 purchase with `payment_method_id = NULL` still drives GSP bank capital to **0.00**. |
| SP-12, SP-13, SP-14 … SP-27 | **Unchanged and still open** (cross-brand stock, receipt double-counting on credit collections, returns, invoice edit/cancel, order numbering, payment-method defaults, etc.). |
| SP-01, SP-03 … SP-10 | **Unchanged and still open**; these remain the recommended P0 fixes. |



## Contents

0. [Addendum — capital model v2](#0-addendum--capital-model-v2-implemented-after-the-audit) — two-tier model now live
1. [Executive summary](#1-executive-summary) — scorecard, severity register, top ten findings
2. [Scope, method and evidence base](#2-scope-method-and-evidence-base)
3. [Compliance with the supplied guidelines](#3-compliance-with-the-supplied-guidelines) — Liquid Capital, Stock Capital, Operational impacts, Brand isolation
4. [Detailed findings](#4-detailed-findings) — SP-01 … SP-27
5. [Live data snapshot](#5-live-data-snapshot--what-the-system-currently-reports)
6. [Brand isolation matrix](#6-brand-isolation-matrix)
7. [What is working well](#7-what-is-working-well-do-not-regress)
8. [Remediation plan](#8-remediation-plan)
9. [Appendix A — Reproduction queries](#appendix-a--reproduction-queries)
10. [Appendix B — Schema and migration integrity notes](#appendix-b--schema-and-migration-integrity-notes)
11. [Appendix C — Files reviewed](#appendix-c--files-reviewed)
12. [Appendix D — Limitations and assumptions](#appendix-d--limitations-and-assumptions)


---

## 1. Executive summary

**Verdict: the sales and POS *transaction* core is sound, but the *money* layer built on top of it is not yet trustworthy.** Every POS sale I could test is arithmetically correct, stock is deducted under row locks, stock cannot be oversold at checkout, and brand access control on the POS screen is properly enforced. However, several post-sale and cross-module paths either double-count, fail to move stock, or bypass the cash guards — and the live database already contains a demonstrable breach of the "shops + unallocated = cash in hand" invariant.

### Scorecard

| Area | Result | Basis |
|---|---|---|
| POS cart, pricing, stock deduction | **PASS with one critical control gap** | Stock locked and re-checked; but the cart price is user-editable and trusted at checkout (SP-04) |
| POS totals arithmetic | **PASS** | All 3 live sales reconcile exactly to their line items and payments |
| Automatic POS invoice | **FAIL** | Invoice cannot be safely edited/cancelled; cancelling silently restores stock (SP-01) |
| Sales edit / cancel / delete | **FAIL** | Money, invoice and stock ledger not kept in step (SP-08, SP-09, SP-10) |
| Sales returns | **FAIL** | Returned goods are never put back into stock; refunds ignore tax and amount paid (SP-05, SP-06, SP-07) |
| Shop cash balances | **PARTIAL** | Formula is correct, live data already violates it (SP-11) |
| Brand liquid capital | **PARTIAL** | Arithmetic correct; outflows are not capped by available funds; credit collections double-counted (SP-03, SP-11) |
| Bank balance per account | **PARTIAL** | Card/bank sales never reach a bank account ledger (SP-14) |
| Stock capital | **FAIL** | Purchases never create stock; stock is added by a manual restock with no cost link (SP-02) |
| Total Brand Capital formula | **PASS** | `(cash + bank) + stock` is implemented exactly as specified |
| Internal transfer cash ↔ bank | **PASS** | Validated, locked, total capital unchanged |
| Close Business (shop → brand) | **PASS with caveats** | Double-entry correct; shop balance can be stale after brand-level banking |
| Brand isolation | **PARTIAL** | POS enforced at shop level; stock and historical sales leak across brands (SP-12, SP-18) |
| Withdrawal limits | **PARTIAL** | Capital withdrawal is capped; expenses and purchases are not |

### Severity register

| Severity | Count | IDs |
|---|---|---|
| Critical | 3 | SP-01, SP-02, SP-03 |
| High | 8 | SP-04 … SP-11 |
| Medium | 11 | SP-12 … SP-22 |
| Low | 5 | SP-23 … SP-27 |

### The ten findings that matter most

1. **SP-01** — Cancelling or editing an auto-generated POS invoice restores stock *again* while the POS sale remains completed.
2. **SP-02** — Supplier purchases never increase `inventory_stock`; stock capital and purchase spending are disconnected.
3. **SP-03** — Collecting a credit balance on a POS invoice writes the money to **both** `payments` and `pos_payments`, so capital counts it twice.
4. **SP-04** — Any POS user can change the unit price on screen and the server accepts it without limit or audit.
5. **SP-05** — Sales returns refund cash but never add the goods back to stock, and the refund ignores tax and discount.
6. **SP-06** — A return can pay out cash for goods that were never paid for (credit sales).
7. **SP-07** — Returning against the POS sale leaves the auto-invoice fully "Paid", so the same goods can be returned twice through two channels.
8. **SP-08/09/10** — Edit, cancel and delete change or remove a sale without reversing money, invoice, or the stock ledger; delete leaves orphan invoices and orphan returns.
9. **SP-11** — Brand-level cash expenses and purchases are not checked against available cash. Live result: **GSP cash in hand = 0 while its shops hold 42,200 and one shop is at −5,000**.
10. **SP-12** — **955,000** of stock physically sits in shops of the *other* brand, where the POS brand rule forbids selling it.


---

## 2. Scope, method and evidence base

### 2.1 Code reviewed

POS and sales: `sale-add.php`, `ajax-cart-update.php`, `ajax-product-search.php`, `sale-edit.php`, `sale-cancel.php`, `sale-delete.php`, `sale-details.php`, `sales.php`, `sale-receipt.php`, `sale-invoice.php`, `pos-invoice.php`, `sales-returns.php`, `sales-return-add.php`, `payment-add.php`, `receipts.php`, `invoice-cancel.php`, `invoice-edit.php`, `reporting-sales.php`, `export-sales.php`.

Money and capital: `capital.php`, `capital-helpers.php`, `close-business.php`, `expenses.php`, `purchase-add.php`, `purchases.php`, `inventory-adjust.php`, `helpers.php` (access/brand/capital helpers), `database/migrations/016`, `028`, `037`, `038`, `039`, `042`, `database/schema_business_v2.sql`.

### 2.2 Data reviewed

Live tenant 1 (`u839560293_agensupply`) — 4 shops, 7 warehouses (1 godown, 2 stores, 4 shop warehouses), 6 products, 22 stock rows, 3 completed POS sales, 3 POS payments, 3 auto-generated invoices, 1 storefront order return, 7 capital movements, 2 expenses, 2 purchases, 0 invoice payments, 0 shop cash transfers, 0 bank deposits.

### 2.3 Integrity checks executed

All checks are reproducible from Appendix A. Summary of results:

| Check | Result |
|---|---|
| `pos_orders` header vs. sum of line items (subtotal, tax, total) | **0 mismatches** (3/3 orders reconcile) |
| Line `total` = `subtotal + tax_amount` | **0 mismatches** (4/4 items) |
| `pos_payments` vs. order total (overpay / underpay) | **0 overpaid, 0 credit sales** in current data |
| Invoice header vs. line items vs. `amount_paid` | **0 mismatches** (3/3) |
| Orphan payments / items / invoices / stock rows | **0** |
| Orders without a warehouse or without an invoice | **0** |
| Duplicate POS order numbers | **0** today (no unique constraint — see SP-15) |
| Negative stock rows | **0** |
| Zero-cost stock on hand | **2 products, 20 units, value 0** (SP-21) |
| Stock held in a shop of another brand | **955,000 at purchase price** (SP-12) |
| Shop cash balance & unallocated brand cash | **Negative in live data** (SP-11) |

---

## 3. Compliance with the supplied guidelines

Legend: **Met** = implemented and evidenced · **Partial** = implemented with a material gap · **Not met** = the rule is broken or absent.

### 3.1 Liquid Capital

| Guideline | Verdict | Where it is implemented | Gap |
|---|---|---|---|
| **Cash in Hand** — total physical cash for the whole brand | **Partial** | `brand_capital_breakdown()` `capital-helpers.php:641-651` | Formula is correct, but brand-level expenses (SP-11) and cash purchases can drive it below zero, and there is no cash-overdraft guard on those two paths. In live data GSP cash in hand is **0** while shops hold **42,200**. |
| **Shops Balance** = distributed cash + sales − expenses − sales returns | **Partial** | `shop_cash_balances()` `capital-helpers.php:1335-1470` | Formula includes the required terms and additionally invoice cash and shop→brand collections. Gaps: sales returns never return goods to stock (SP-05); storefront order refunds are not shop-attributed (SP-14); brand-level cash banking is not netted from the shop that produced the cash, so a shop balance can be swept twice (SP-11); negative balances are allowed. |
| **Bank Balance** — funds in bank accounts allocated to the brand | **Partial** | `brand_capital_breakdown()` `capital-helpers.php:653-664`, `bank_accounts_summary()` `:1033-1252` | Brand-level total is right, but per-account `available` (`:1241-1243`) only includes capital movements, opening bank, banked cash, expenses, purchases, refunds and internal transfers. POS/invoice **bank** receipts are never allocated to an account, so `bank_unallocated` grows and those funds cannot be withdrawn (SP-14). |
| **Internal transfers** cash ↔ bank without changing total capital | **Met** | `capital.php:215-293`; totals in `brand_internal_transfer_totals()` `:402-423` | Validated against unallocated cash / per-bank balance, row-locked, and +/− applied symmetrically at `:650-664`. Only limitation: backdating is unrestricted (SP-19). |
| **Close Business transfer** — a shop close updates brand cash in hand | **Met** | `close_shop_cash()` `capital-helpers.php:1653-1669`; `close-business.php:34-64` | Correct double entry: the shop's derived balance is moved to brand unallocated cash, so `cash in hand = Σ shop balances + unallocated` still holds. Caveat: the sweep can collect cash that the brand already banked (SP-11). |

### 3.2 Stock Capital

| Guideline | Verdict | Evidence |
|---|---|---|
| Stock Capital = quantity × purchase price | **Met (formula) / Partial (validity)** | `capital_stock_value()` `capital-helpers.php:578-591` uses `SUM(s.quantity * p.purchase_price)` exactly as specified. Validity gaps: purchases never create stock (SP-02), zero-price products contribute 0 (SP-21), and goods in another brand's shops are counted for the owner brand only (SP-12). |
| Total Brand Capital = (Cash + Bank) + Stock | **Met** | `capital-helpers.php:666-667` — `$liquid = $cash + $bank; $total = $liquid + $stock;` |

### 3.3 Operational impacts

| Guideline | Verdict | Evidence |
|---|---|---|
| Completed sales increase the shop's balance | **Partial** | `shop_cash_balances()` adds `pos_cash` and `invoice_cash` per shop (`:1365-1419`). Only money **received** is added — correct for a cash balance — but a credit sale adds nothing until collected, and the collection is then counted twice (SP-03). |
| Withdrawal ≤ available amount | **Partial** | Capital withdrawal is enforced for bank (`capital.php:84-90`) and for cash (`:100-106`) using `brand_unallocated_cash`. Shop transfers are enforced (`capital-helpers.php:1601-1617`). **Expenses and supplier purchases have no such check** (SP-11). |
| Expenses and supplier purchases deduct from liquid capital | **Partial** | `capital_expense_outflows()` `:510-529`, `capital_purchase_outflows()` `:532-552`. Deduction logic is right, but account attribution depends on a nullable `payment_method_id` and defaults asymmetrically (receipts → bank, outflows → cash) (SP-13). |
| Purchasing stock shifts money from liquid into stock capital | **Not met** | `purchase-add.php:102-110` writes only the `purchases` row; no `inventory_stock`/`inventory_movements` write exists in any purchase file, and `inventory-adjust.php:62-92` restocks with no purchase reference and no cost. Live proof: purchase #5 paid **200,000** for 30 units, yet the godown holds 50 units of that product with no purchase link (SP-02). |

### 3.4 Brand isolation

| Guideline | Verdict | Evidence |
|---|---|---|
| All metrics strictly isolated per brand | **Partial** | POS is well isolated: `accessible_shops()` filters by brand (`helpers.php:1109-1118`), `shop_is_accessible()` rejects shops of another brand (`:1318-1321`), `sale-add.php:90-93` and `:243-253` reject out-of-brand products. Weak points: `pos_orders` stores no brand and derives it from the shop's *current* brand (SP-18), `sale-edit.php:134-139` lets any active product be added to any sale (SP-08), and stock sits across brand boundaries (SP-12). |
| Mini Admins and Administrators see brand-scoped figures | **Met** | `capital_resolve_brand()` forces the Mini Admin's brand (`helpers.php:705-710`); capital, expenses, sales list and reports all apply the scope. |

---

## 4. Detailed findings

Each finding states what happens, where, the demonstrated or logical impact, and the required correction. Line references are to the audited revision.

### 4.1 Critical

#### SP-01 — Editing or cancelling an auto-generated POS invoice adjusts stock a second time

**What happens.** Every completed POS sale creates an invoice automatically (`sale-add.php:336` → `create_invoice_from_pos_sale()`). That invoice is marked paid by writing `invoices.amount_paid` directly; **no `payments` row is created** (`pos-invoice.php:133-160`). The invoice is therefore fully editable and cancellable:

* `invoice-cancel.php:24-28` refuses cancellation only when `payments` rows exist. For a POS invoice that count is **always zero**, so the guard never fires.
* `invoice-cancel.php:52-70` then adds every line quantity back to `inventory_stock` and marks the invoice `Cancelled` with `amount_due = 0`.
* `invoice-edit.php:88-137` and `:160-190` adjust `inventory_stock` for quantity changes and removals in exactly the same way.

The POS sale itself is untouched: `pos_orders.status` remains `completed`, `pos_payments` remains, stock deduction remains — and now stock has been credited back as well.

**Live evidence.** All three live invoices (`INV-1001`, `INV-1002`, `INV-1003`) reference POS orders and have **0** payment rows, so the cancellation guard would pass on all of them.

**Impact.** Free stock inflation; a paid sale with a cancelled invoice; capital remains credited while the document trail says cancelled. Any user holding `manage_invoices` — a different permission from `manage_pos` — can do this.

**Required fix.** Give `invoices` a real `pos_order_id` (or block on `reference LIKE 'POS-%'`) and reject edit/cancel for POS-generated invoices, routing the user to `sale-edit.php` / `sale-cancel.php`, which already own the stock movement.

#### SP-02 — Supplier purchases never increase stock, so stock capital is not connected to purchase spending

**What happens.** `purchase-add.php:102-110` inserts the `purchases` row and nothing else. No purchase-related file writes `inventory_stock` or `inventory_movements` (grep over `purchase-add.php`, `purchase-edit.php`, `purchase-status.php`, `purchase-view.php`, `purchases.php` returns no stock writes). There is no database trigger for it either (only `*_no_godown` guards exist). The sole stock-in path is `inventory-adjust.php:62-92`, which:

* writes movement type `restock`,
* has no purchase reference, no unit cost and no supplier,
* does not validate the product's brand against the warehouse's brand.

**Live evidence.** Purchase #5 (`product_id 5`, 30 units, total 300,000, paid 200,000, `status = arrived_godown`, `payment_method_id = NULL`) exists, yet the godown holds **50** units of that product and the only related ledger entry is a manual `restock` of 10 with no reference. Products `B` and `C` have 10 units each at `purchase_price = 0`, so their contribution to stock capital is zero.

**Impact.** The stated rule *"purchasing stock shifts money from liquid capital into stock capital"* is not implemented. Liquid capital falls at purchase time; stock capital only rises if somebody separately remembers to restock. Total Brand Capital therefore drops by the purchase amount and never recovers, and stock capital cannot be audited against purchasing history.

**Required fix.** Create the stock receipt from the purchase (on create for `arrived_godown`, or a dedicated receive action), with a `purchase_id` on `inventory_movements` and an immutable `unit_cost` snapshot. Reconcile existing manual restocks with purchases.

#### SP-03 — Credit-balance collections on POS invoices are counted twice in cash, bank and revenue

**What happens.** When a balance is collected on a POS-generated invoice:

1. `payment-add.php:49-58` inserts a `payments` row.
2. `payment-add.php:95-101` calls `sync_pos_sale_payments_from_invoice()`, which inserts the same amount into `pos_payments` (`pos-invoice.php:223-296`).
3. `capital_sales_receipts()` sums **both** tables for the brand: `pos_payments` at `capital-helpers.php:462-475` and `payments` at `:477-490`.
4. `shop_cash_balances()` also sums both, per shop: `:1365-1419`.
5. The balance sheet adds POS **sale totals** plus invoice payments: `$revenue = $pos_sales + $invoice_payments + $order_sales;` at `:824`.

**Worked example.** A wholesale POS sale of 100,000 with 40,000 paid at the till, then 60,000 collected on the invoice:

* `pos_payments` = 40,000 + 60,000 (mirror) = **100,000**
* `payments` = **60,000**
* Capital counts **160,000** of cash for 100,000 actually received.
* Revenue counts **100,000 (sale) + 60,000 (payment)** = 160,000, overstating profit by 60,000.

**Live evidence.** No credit sale exists yet (`payments` is empty), so the defect has not yet corrupted production figures — but the code path is live and reachable from the invoice payment screen.

**Required fix.** Adopt one ledger. Either stop mirroring into `pos_payments`, or exclude invariants from `capital_sales_receipts`/`shop_cash_balances` for invoices whose `reference` matches a POS order — and fix the balance-sheet revenue to count the sale once (`o.total`) plus non-POS invoice payments only.


### 4.2 High

#### SP-04 — POS price override is user-controlled and unvalidated

* The cart renders an **editable** price input (`sale-add.php:514`) for every user with `manage_pos`.
* On change it posts that value to `ajax-cart-update.php` (`sale-add.php:710-716`).
* The endpoint accepts any non-negative number (`ajax-cart-update.php:9`, `:36`) and stores it in the session (`:53-55`). It never compares against `products.sale_price`, a floor, a discount limit, or a permission.
* Checkout consumes the session price verbatim (`sale-add.php:191-207`) and does **not** re-read the product.

**Impact.** A cashier can sell any item at any price (including zero) with no override record. Revenue, gross margin, shop cash and capital all inherit the manipulated figure, and there is no audit trail to distinguish a legitimate discount from shrinkage.

**Fix.** Re-read `sale_price` server-side at checkout; model a discount/override as an explicit field with an approval permission, a cap, a mandatory reason and an audit entry.

#### SP-05 — Sales returns never put goods back into stock; refunds ignore tax and discount

`sales-return-add.php:199-202` states: *"Returned stock is recorded in sales_return_items only. It is not added back to sellable inventory_stock."* No other file restocks on return. Additionally `:119` computes the refund as `qty × unit_price`, so tax is never refunded (live order #5 carries tax) and a sale-level discount is not pro-rated.

**Impact.** Stock capital is permanently understated after every return, while cash is reduced by the refund — Total Brand Capital falls twice. Customers are short-changed on taxed items.

**Fix.** Restock to the original warehouse (`:79-100` already computes `$restore_destination` but never uses it for stock), write an `inventory_movements` row, and refund the tax-inclusive, discount-adjusted amount.

#### SP-06 — A refund can exceed what the customer actually paid

`sales-return-add.php:104-129` validates only that the return quantity does not exceed the un-returned quantity, and derives the refund from the selling price. There is no comparison with `pos_payments` / `invoices.amount_paid`.

**Impact.** A wholesale credit sale (partially or wholly unpaid) can be "returned" and paid out in cash — a direct cash-loss vector.

**Fix.** Cap cumulative refunds at cumulative money received for that sale; return the unpaid portion as a credit note instead of cash.

#### SP-07 — A POS sale return does not update its auto-generated invoice

The invoice reconciliation block runs only for `source_type === 'invoice'` (`sales-return-add.php:205-233`). A return recorded against the **sale** leaves the invoice at `amount_paid = total`, `returned_amount = 0`, `is_paid = 1`.

**Impact.** The invoice reports fully paid while cash has been refunded. Because the previous-returns map is keyed by `(source_type, source_id)` (`:75-77`), the same goods can be returned once through the sale and once through the invoice.

**Fix.** Resolve the invoice through `reference = order_number` and update both sides in one transaction, or make the invoice the only return channel for invoiced sales.

#### SP-08 — Sale edit changes money without adjusting payments, invoice, stock ledger or brand

`sale-edit.php:177-182` updates `subtotal`, `tax_amount`, `discount` and `total` only. It does **not**:

* adjust or validate `pos_payments`, so `paid` can exceed or fall below the new total with no credit/refund entry (status stays `completed`);
* update the auto-generated invoice, which keeps the old total and paid amount;
* write any `inventory_movements` row for the stock changes at `:57-83`, `:85-115`, `:158-160`;
* enforce the shop's brand for added products — `:134-139` selects any active product of the tenant, so a GSP shop sale can be edited to include TECHDOKTA goods.

**Impact.** Sale, invoice, payment and stock history drift apart with only a generic "Sale updated" log; brand isolation is bypassed.

**Fix.** Recalculate against payments, post an adjustment/refund document for the delta, sync the invoice, write stock movements, apply `shop_brand()` filtering, and cap edits by role/time.

#### SP-09 — Deleting a sale destroys the audit trail and orphans invoices and returns

`sale-delete.php:62-75` hard-deletes items, payments and the order. It does not cancel the auto-generated invoice, and `sales_returns.source_id` has no foreign key (`database/migrations/016_sales_returns.php:14`), so returns keep pointing at a vanished sale. The page is also reachable by **GET without CSRF** (`:1-11`).

**Impact.** Cash disappears from capital (the order no longer exists) with no reversal document; a paid invoice remains; orphan returns keep reducing cash forever. Any cross-site link can delete a sale.

**Fix.** Replace hard delete with a reversal workflow (refund + invoice cancel + stock restore + status), require POST + CSRF + confirmation, and add a foreign key or exclusive-arc check for returns.

#### SP-10 — Cancelling a sale leaves the money and the invoice untouched

`sale-cancel.php:63-70` only flips `pos_orders.status` to `cancelled`. `pos_payments` rows are kept, no refund document is written, and the invoice remains paid. Capital queries filter `o.status = 'completed'` (`capital-helpers.php:471`), so the cash silently drops out of brand capital.

**Impact.** The books show less cash with no cash-out record; the invoice says paid; the customer refund is invisible to audit. Cancellation is a GET request with no CSRF token (`sale-cancel.php:1-11`).

**Fix.** Force a refund/return document as part of cancellation, cancel or credit the invoice, and require POST + CSRF.


#### SP-11 — Outflows are not capped by available cash; live shop balances are already negative

Only these paths validate availability:

| Path | Guard |
|---|---|
| Capital withdrawal (bank) | `capital.php:84-90` via `bank_available_balance()` |
| Capital withdrawal (cash) | `capital.php:100-106` via `brand_unallocated_cash()` |
| Internal cash → bank | `capital.php:243-261` |
| Shop → brand collection | `capital-helpers.php:1610-1616` |
| Brand → shop distribution | `capital-helpers.php:1601-1608` |
| Bank brand cash | `close-business.php:91-97` |

**Expenses** (`expenses.php:117-121`) and **supplier payments** (`purchase-add.php:102-110`) have **no** availability check at all, and `expenses.php` allows an expense to be pinned to a shop with no float.

**Live evidence (GSP GROUP).**

| Metric | Amount |
|---|---|
| Cash contributions | 157,800.00 |
| POS cash received | 52,200.00 |
| Cash expenses (method Cash) | −10,000.00 |
| Cash purchases (method NULL → default cash) | −200,000.00 |
| **Cash in hand** | **0.00** |
| Shop balance — Kariakoo GPS | 47,200.00 |
| Shop balance — Survey GSP | **−5,000.00** |
| Allocated to shops | 42,200.00 |
| **Brand unallocated cash** | **−42,200.00** |

TECHDOKTA is healthy for comparison (cash 150,000; bank 4,000,000; allocated 150,000; unallocated 0).

**Impact.** `cash in hand = Σ shop balances + unallocated` still adds up, but a negative unallocated amount means the brand has spent cash that physically sat in shops. The system permits this silently, then blocks legitimate transfers ("collect cash from shops first"), and lets a shop balance go below zero.

**Fix.** A single availability check applied to every cash/bank outflow; reject a shop expense that exceeds that shop's balance unless a float is transferred first; treat negative balances as errors in the UI and block Close Business on a negative shop.


---

### 4.3 Medium

#### SP-12 — 955,000 of stock physically sits in shops of the other brand

POS restricts a branded shop to its own products (`sale-add.php:90-93`, `:243-253`), but stock transfers do not enforce brand consistency. Live result:

| Product brand | Warehouse brand | Value at purchase price |
|---|---|---|
| GSP GROUP | TECHDOKTA INVESTMENT | **280,000** |
| TECHDOKTA INVESTMENT | GSP GROUP | **675,000** |

Product 4 (TECHDOKTA) holds 5 units in each of Kariakoo GPS (wh 15) and Survey GSP (wh 17), plus 5 in a GPS store (wh 11). Products 1 and 5 (GSP) hold 5 units each in Survey TECHDOKTA (wh 14) and its store (wh 1).

**Impact.** The owner brand's stock capital includes goods that no accessible shop can sell (dead stock), while the shop's own brand reports zero for goods it physically holds.

**Fix.** Validate brand compatibility on every stock transfer/receipt; add a "stock in wrong-brand location" exception report; decide the treatment for the existing 955,000.

#### SP-13 — Payment-method fallback is unsafe and cash/bank defaults are asymmetric

* `sale-add.php:323-324` falls back to `payment_method_id = 1` when the form posts nothing, without checking that the method belongs to this tenant or is active. Method IDs are global, so in a multi-tenant deployment ID 1 can belong to another tenant.
* `capital_payment_account_expr()` is called with default **bank** for receipts (`capital-helpers.php:460`) but **cash** for expenses, purchases and refunds (`:515`, `:537`, `:560`).

**Live evidence.** Purchase #5 (`amount_paid = 200,000`, `payment_method_id = NULL`) is deducted from **cash**, which is precisely what reduced GSP cash in hand to zero.

**Impact.** A missing or foreign payment method silently misclassifies money between the two tiers, and the two directions disagree about the default.

**Fix.** Require a valid, tenant-owned method on every money transaction; snapshot `account_type` onto the transaction row; make the fallback explicit and consistent (or refuse the transaction).

#### SP-14 — Card/bank receipts never reach the bank-account ledger; storefront cash is treated as bank

* **Bank allocation.** `bank_accounts_summary()` builds per-account balances from capital movements, opening bank, banked cash, expenses, purchases, refunds and internal transfers only (`capital-helpers.php:1033-1252`; `available` at `:1241-1243`). POS and invoice **bank** receipts are added to the brand bank total (`:656-658`) but never to any account, so `bank_unallocated` grows and `bank_available_balance()` — used to cap withdrawals and internal transfers — understates real funds.
* **Storefront orders.** `order_bank` treats every order receipt as bank: `SUM(Amount_paid − returned_amount)` (`:492-505`). Orders carry `shop_id`/`warehouse_id` but no receipt method. Live return `RET-000001` (20,000, shop 11, warehouse 17) therefore reduced brand **bank** and left shop 11's cash untouched, although the money was collected/refunded at that shop.

**Impact.** Per-bank balances are wrong, withdrawals of card money are blocked, and shop cash is overstated whenever a storefront order is settled in cash at a shop.

**Fix.** Allocate bank receipts to a named bank account (or a virtual "card clearing" account); record an order receipt with method and route order refunds through the same cash/bank model.

#### SP-15 — No unique POS order number; invoice linkage is by string

`pos_orders` has no unique key on `order_number` (verified index list: only `PRIMARY`, tenant/contact/session/warehouse keys). Numbers are generated as `'POS-' . date('Ymd') . '-' . str_pad(mt_rand(1, 999), 3, '0', STR_PAD_LEFT)` (`sale-add.php:229`) — a birthday collision at roughly 0.3 % per pair of same-day sales.

Invoices are linked by `WHERE reference = order_number LIMIT 1` (`pos-invoice.php:31-35`, `:241-248`), and `invoices.reference` has no index or uniqueness.

**Impact.** A duplicate number makes the "existing invoice" check return **another sale's invoice**, and the payment sync credit the wrong sale.

**Fix.** Add `UNIQUE (tenant_id, order_number)` and `invoices.pos_order_id`; replace reference-string matching with the foreign key; use a sequence, not `mt_rand`.

#### SP-16 — POS sessions / shifts exist in the schema but are never used

`sale-add.php:283` always writes `session_id = NULL`. `pos_sessions` exists and helpers already read it (`helpers.php:1543-1558`). There is no open/close drawer, no expected-cash calculation and no per-cashier reconciliation.

**Impact.** Cash accountability stops at the shop aggregate; a cashier cannot be reconciled at end of shift, and a shortage can only be detected against the whole shop.

**Fix.** Open a POS session at till open, stamp it on orders, and require a closing count that produces a variance record.

#### SP-17 — Checkout does not revalidate product status (only brand and stock)

`sale-add.php:191-207` builds totals from the session cart. At commit it revalidates only the shop warehouse (`:234-239`), the brand (`:243-253`) and stock (`:255-280`). A product deactivated (or price-changed) after being added stays sellable at the old price.

**Fix.** Re-select each product inside the checkout transaction and reject inactive/deleted items.


#### SP-18 — POS orders store no shop or brand, so history is reclassified when a shop's brand changes

`pos_orders` has only `tenant_id` and `warehouse_id`. Brand scope is resolved *at query time* through `warehouses → shops.brand` (`capital-helpers.php:241-252`), used by every capital query. Changing a shop's brand therefore moves all its historical sales, cash and expenses into the other brand's capital report.

**Fix.** Snapshot `shop_id` and `brand` on `pos_orders`, `pos_payments` and `sales_returns` at posting time and report on the snapshot.

#### SP-19 — Unlimited backdating with no period lock

`capital_movements.entry_date`, `brand_internal_transfers.entry_date`, `shop_cash_transfers.entry_date` and `bank_sales_deposits.business_date` accept any valid date (`capital.php:51`, `:236`; `close-business.php:19-31`). There is no closed-period concept.

**Impact.** Prior reported capital can be restated at will; the audit trail cannot distinguish a correction from an original entry.

**Fix.** Limit backdating by policy, keep `effective_date` separate from `created_at`, and lock closed periods.

#### SP-20 — Transfer and capital tables have no referential integrity

`shop_cash_transfers`, `brand_internal_transfers`, `capital_movements`, `bank_sales_deposits` and `brand_capital_settings` carry `tenant_id`, `brand` (free text) and `shop_id`/`created_by` with **no foreign keys** (verified against `information_schema.REFERENTIAL_CONSTRAINTS`; `migrations/042:100-118` creates the table without constraints).

**Impact.** A renamed brand or deleted shop silently orphans financial history; `shop_id` can point anywhere, including another tenant.

**Fix.** Add FKs (tenant/shop/user) with `ON DELETE RESTRICT`, and a `brands` table referenced by ID.

#### SP-21 — Zero-cost stock and retroactive revaluation

Products `B` (TECHDOKTA) and `C` (GSP) hold 10 godown units each at `purchase_price = 0`, contributing **0** to stock capital. `capital_stock_value()` uses the *current* product price, so any later price edit retroactively revalues all historical stock.

**Impact.** Stock capital can be understated or restated by editing a product.

**Fix.** Require a purchase price for stock-holding products, snapshot cost on receipt (moving average or FIFO), and value stock from the cost layer.

#### SP-22 — The stock movement ledger is not complete

`inventory_movements` has 11 rows while 22 stock rows exist. There are no opening-balance movements, and `sale-edit.php` writes no movement rows for its stock changes (`:57-160`).

**Impact.** Stock cannot be reconstructed or audited from the ledger; a variance cannot be traced to a user action.

**Fix.** Make `inventory_movements` the single source of truth, insert a movement for every stock change (including edits and opening balances), and add a reconciliation report.

---

### 4.4 Low

| ID | Finding | Evidence | Fix |
|---|---|---|---|
| **SP-23** | The server-rendered POS total ignores the discount (`$grand_total = $subtotal + $tax_total`); JS corrects it on the first interaction, so the initial paint can be wrong. | `sale-add.php:379-385` | Include the posted discount in the render. |
| **SP-24** | POS lines store `tax_amount` but no `tax_rate`; `sale-edit.php:118-121` re-derives the rate from the amounts, which is fragile when a line has a zero taxable base. | `pos_order_items` schema; `sale-edit.php:117-129` | Snapshot `tax_rate` per line. |
| **SP-25** | Invoice reporting identifies POS invoices by `reference NOT LIKE 'POS-%'` rather than a key, so a hand-typed reference starting with `POS-` vanishes from invoice reports. | `reporting-sales.php:5-8` | Use `pos_order_id`. |
| **SP-26** | `invoices.reference` has no index; every POS invoice creation scans the invoice table. | index inspection | Add `INDEX (tenant_id, reference)`. |
| **SP-27** | Storefront `orders.Amount_paid` is a free numeric field with no receipt record, so it cannot be tied to a bank/cash movement. | `orders` schema; `capital-helpers.php:492-505` | Create a receipt row per order payment. |


---

## 5. Live data snapshot — what the system currently reports

The figures below were produced by calling the application's own functions (`brand_capital_breakdown()`, `brand_shop_cash_summary()`) with the Administrator account, read-only.

### 5.1 GSP GROUP

| Line | Amount (TZS) |
|---|---|
| Opening cash / bank | 0.00 / 0.00 |
| Cash contributions | 157,800.00 |
| POS cash receipts | 52,200.00 |
| Cash expenses | (10,000.00) |
| Cash purchases | (200,000.00) |
| **Cash in Hand** | **0.00** |
| **Bank Balance** | **0.00** |
| **Liquid Capital** | **0.00** |
| **Stock Capital** | **2,112,000.00** |
| **Total Brand Capital** | **2,112,000.00** |
| Allocated to shops | 42,200.00 |
| Brand unallocated cash | **(42,200.00)** |

Shop breakdown:

| Shop | Distributed | Collected | POS cash | Invoice cash | Refunds | Expenses | Balance |
|---|---|---|---|---|---|---|---|
| Kariakoo GPS | 0.00 | 0.00 | 52,200.00 | 0.00 | 0.00 | 5,000.00 | **47,200.00** |
| Survey GSP | 0.00 | 0.00 | 0.00 | 0.00 | 0.00 | 5,000.00 | **(5,000.00)** |

### 5.2 TECHDOKTA INVESTMENT

| Line | Amount (TZS) |
|---|---|
| Bank contributions / withdrawals | 6,000,000.00 / (2,000,000.00) |
| POS cash receipts | 150,000.00 |
| **Cash in Hand** | **150,000.00** |
| **Bank Balance** | **4,000,000.00** |
| **Liquid Capital** | **4,150,000.00** |
| **Stock Capital** | **3,735,000.00** |
| **Total Brand Capital** | **7,885,000.00** |
| Allocated / unallocated | 150,000.00 / 0.00 |

Shop breakdown:

| Shop | POS cash | Balance |
|---|---|---|
| kariakoo TECHDOKTA | 150,000.00 | **150,000.00** |
| Survey TECHDOKTA | 0.00 | 0.00 |

### 5.3 POS documents on file

| Order | Warehouse → Shop | Total | Paid | Tax | Invoice | Invoice paid | Payment rows |
|---|---|---|---|---|---|---|---|
| POS-20261109-132 | 18 → kariakoo TECHDOKTA | 75,000.00 | 75,000.00 | 0.00 | INV-1001 | 75,000.00 | **0** |
| POS-20261110-082 | 15 → Kariakoo GPS | 52,200.00 | 52,200.00 | 200.00 | INV-1002 | 52,200.00 | **0** |
| POS-20261110-721 | 18 → kariakoo TECHDOKTA | 75,000.00 | 75,000.00 | 0.00 | INV-1003 | 75,000.00 | **0** |

All header/line/payment arithmetic reconciles. The "0 payment rows" column is the SP-01 exposure: every one of these invoices passes the cancellation guard.

### 5.4 What the numbers prove

1. **Total Brand Capital arithmetic is correct** and follows the specified formula.
2. **The shop-cash partition is already broken in production data** (GSP unallocated −42,200; Survey GSP −5,000), which is only possible because outflow paths bypass the availability check.
3. **Stock capital (2.1 M and 3.7 M) is not backed by purchase records** — it is quantity × current price on manually restocked goods, including 20 zero-cost units.
4. **Cash received is not the same as sales made** — the tier figures are cash-basis, while the P&L revenue adds sale totals *and* later collections, so the two views diverge as soon as a credit sale is collected.


---

## 6. Brand isolation matrix

| Surface | Isolation mechanism | Result |
|---|---|---|
| POS shop selector | `accessible_shops()` filters by brand / mapping (`helpers.php:1100-1137`) | **Pass** |
| POS direct shop switching (`set_shop`) | `shop_is_accessible($requested, false)` (`sale-add.php:55-71`) | **Pass** |
| POS product list | `AND p.brand = ?` for branded shops (`sale-add.php:47-49`, `:360-370`) | **Pass** |
| POS add-to-cart | Server-side brand check (`sale-add.php:90-93`) | **Pass** |
| POS checkout | Brand revalidated inside the transaction (`sale-add.php:241-253`) | **Pass** |
| POS AJAX price/qty update | No brand re-check, but the item must already be in the cart | **Weak** |
| Sale edit — add item | No brand filter (`sale-edit.php:134-139`, `:200-207`) | **Fail** (SP-08) |
| Sales list | Warehouse accessibility scoped (`sales.php:19-31`, `:66-72`) | **Pass** |
| Sale cancel/delete | `store_is_accessible()` on the sale's warehouse (`sale-cancel.php:13-17`, `sale-delete.php:12-15`) | **Pass** |
| Sales returns | Original sale/invoice store accessibility (`sales-return-add.php:41-66`) | **Pass** |
| Invoice cancel/edit of POS invoices | Store accessibility only; no POS linkage check | **Weak** (SP-01) |
| Capital screens | `capital_resolve_brand()` locks a Mini Admin to their brand (`helpers.php:705-710`); every capital query adds a brand or shop-warehouse condition (`capital-helpers.php:241-296`) | **Pass** |
| Expenses | Brand/shop cross-validation (`expenses.php:63-97`) | **Pass** |
| Reporting | `report_accessible_sales_warehouse_ids()` scope | **Pass** |
| Stock | No brand consistency on receipts/transfers; 955,000 misplaced | **Fail** (SP-12) |
| Historical POS sales | Brand derived live from the shop record | **Weak** (SP-18) |

---

## 7. What is working well (do not regress)

1. **Stock safety at checkout.** `sale-add.php:255-280` locks each stock row `FOR UPDATE`, subtracts active order reservations, sorts the lines to avoid deadlocks, and re-checks inside the same transaction that inserts the order. Overselling is genuinely prevented.
2. **Location is server-owned.** The posted `warehouse_id` is deliberately ignored (`sale-add.php:186-187`); the shop warehouse is re-derived at checkout from the session and revalidated (`:234-239`).
3. **Cart structure is sound.** Adding an item always uses the database `sale_price` (`:98-106`); only the explicit override path is unsafe.
4. **Arithmetic is consistent.** Orders, items, payments, invoices and invoice items all reconcile in live data, and POS invoices are excluded from invoice sales reports by design (`reporting-sales.php:5-8`).
5. **Payment guards.** Partial payment is restricted to registered wholesale customers (`sale-add.php:162-180`, `:224-227`); overpayment is rejected; the server repeats every client-side check.
6. **Duplicate-submission protection.** Payments use a unique `submission_token` (`uq_payment_submission_token`), returns use one too (`sales-return-add.php:22-31`), and stock/order writes are wrapped in transactions.
7. **Credit-balance modelling.** A POS credit sale creates an invoice with the correct `amount_paid`/`amount_due`, and receivables appear on the balance sheet.
8. **Capital engine structure.** Separate cash/bank expressions, guarded schema checks (`capital_table_ready()`, `*_table_ready()`), and a consistent `(cash + bank) + stock` identity make the tier model easy to reason about.
9. **Shop cash double entry.** `brand_to_shop` / `shop_to_brand` transfers genuinely reclassify cash without changing brand totals, and the collection path is locked and balance-checked.
10. **Reporting channel separation.** POS sales come from `pos_orders` and invoices from `invoices` with POS invoices excluded, so revenue is not double-counted in *reports* (the double count is confined to capital and the balance sheet, SP-03).


---

## 8. Remediation plan

Ordered so that each phase removes a class of misstatement. Sizes are indicative for one developer familiar with the codebase.

### P0 — Stop the bleeding (1–3 days)

| # | Action | Closes | Acceptance test |
|---|---|---|---|
| 1 | Block `invoice-cancel.php` and `invoice-edit.php` for POS-generated invoices (guard on `invoices.pos_order_id` or `reference LIKE 'POS-%'`), pointing the user to the sale. | SP-01 | Cancelling `INV-1001` is refused; `inventory_stock` unchanged; sale still `completed`. |
| 2 | Remove the mirror insert in `sync_pos_sale_payments_from_invoice()` **or** exclude mirrored rows from `capital_sales_receipts()` / `shop_cash_balances()`. | SP-03 | After collecting 60,000 on a 100,000 credit sale: cash increases by 60,000 once; balance-sheet revenue is 100,000, not 160,000. |
| 3 | Convert `sale-cancel.php` and `sale-delete.php` to POST + CSRF and require a refund/reversal record; block delete when an invoice or return exists. | SP-09, SP-10 | A GET to either endpoint performs no state change; deleting an invoiced sale is refused. |
| 4 | Restore stock on every sales return and refund the tax-inclusive amount. | SP-05 | Return 1 unit of a taxed line: stock +1 at the original warehouse, one movement row, refund = gross amount. |
| 5 | Add a cash/bank availability check to `expenses.php` and `purchase-add.php` (reuse `brand_unallocated_cash()` / `bank_available_balance()`); reject shop expenses above that shop's balance. | SP-11 | The 5,000 expense on Survey GSP is refused while its balance is 0. |

### P1 — Make the money provable (1–2 weeks)

| # | Action | Closes |
|---|---|---|
| 6 | Re-read `sale_price` at checkout; add an explicit, permissioned, capped and audited price-override field. | SP-04 |
| 7 | Cap refunds at money received; make POS-linked invoice returns and sale returns mutually exclusive. | SP-06, SP-07 |
| 8 | On sale edit, recalculate against payments, post an adjustment document, sync the invoice, write stock movements, and apply the shop brand filter. | SP-08 |
| 9 | Add `invoices.pos_order_id` + `UNIQUE (tenant_id, order_number)` + `INDEX (tenant_id, reference)`; replace string-based linkage. | SP-15 |
| 10 | Snapshot `shop_id` and `brand` on `pos_orders`, `pos_payments`, `sales_returns`; report on snapshots. | SP-18 |
| 11 | Record supplier payments against stock receipts (purchase → `inventory_movements` with `purchase_id` and unit cost). | SP-02 |
| 12 | Allocate POS/invoice bank receipts to a named bank account; record storefront order receipts and refunds with a method. | SP-14 |

### P2 — Harden and reconcile (2–4 weeks)

| # | Action | Closes |
|---|---|---|
| 13 | Require a valid tenant-owned payment method on every money transaction; snapshot `account_type`. | SP-13 |
| 14 | Brand-consistency validation on stock receipts/transfers plus a "wrong-brand stock" report; clear the 955,000. | SP-12 |
| 15 | Cost layers (moving average or FIFO); require a cost for stock-holding products. | SP-21 |
| 16 | Complete `inventory_movements` (opening balances, edits) and add a stock reconciliation report. | SP-22 |
| 17 | POS session open/close with expected vs. counted cash and variance. | SP-16 |
| 18 | Foreign keys on the five capital/transfer tables; brand registry table. | SP-20 |
| 19 | Period lock + backdating policy. | SP-19 |
| 20 | Re-check product active status and price inside the checkout transaction. | SP-17 |
| 21 | Low items: discount in the initial render, `tax_rate` snapshot, replace `POS-%` inference, order receipt rows. | SP-23 … SP-27 |

### Regression tests to add

Extend the existing `tests/test_pos_smoke.php` suite with cases that have no coverage today:

1. Sell → cancel invoice → assert stock unchanged and sale still `completed` (must be **refused**).
2. Credit sale → collect balance on invoice → brand cash increases by the collected amount **once**.
3. Sale → return one line → stock restored, refund includes tax, invoice `returned_amount` updated.
4. Return on an unpaid credit sale → cash refund refused / converted to credit.
5. Expense above shop balance → validation error, balances unchanged.
6. Purchase `arrived_godown` → stock and stock capital increase by `quantity × cost`.
7. Edit a sale upward → adjustment/receivable exists and invoice total matches.
8. Brand isolation: a GSP Mini Admin must not see or edit a TECHDOKTA sale, and cannot add a TECHDOKTA product through `sale-edit.php`.
9. Concurrency: two POS checkouts for the last unit → exactly one succeeds.
10. Duplicate order number insertion → rejected by the unique key.

### Data cleanup required (after the fixes, with a backup)

1. GSP: correct the 200,000 purchase's payment account (or record the supplier liability), then restore the shop float so no shop is negative.
2. Clear the 955,000 cross-brand stock by transferring it back to the owning brand's locations.
3. Give products `B` and `C` a real purchase price, or remove them from stock and revalue.
4. Reconcile the 50 godown units of product 5 against purchases #5 and #6.
5. Decide the treatment of storefront return `RET-000001` (its refund method was never recorded).


---

## Appendix A — Reproduction queries

All statements are read-only. Run against `u839560293_agensupply`.

```sql
-- 1. POS header vs line items vs payments (fan-out safe)
SELECT o.order_id, o.order_number, o.subtotal,
       (SELECT COALESCE(SUM(i.subtotal),0) FROM pos_order_items i WHERE i.order_id=o.order_id) item_subtotal,
       o.tax_amount,
       (SELECT COALESCE(SUM(i.tax_amount),0) FROM pos_order_items i WHERE i.order_id=o.order_id) item_tax,
       o.discount, o.total,
       (SELECT COALESCE(SUM(i.total),0) FROM pos_order_items i WHERE i.order_id=o.order_id) item_total,
       (SELECT COALESCE(SUM(p.amount),0) FROM pos_payments p WHERE p.order_id=o.order_id) paid
FROM pos_orders o;

-- 2. Invoice vs items vs payments (POS invoices have no payment rows)
SELECT v.invoice_id, v.invoice_number, v.total, v.amount_paid, v.amount_due,
       (SELECT COALESCE(SUM(i.total),0) FROM invoice_items i WHERE i.invoice_id=v.invoice_id) item_total,
       (SELECT COUNT(*) FROM payments p WHERE p.invoice_id=v.invoice_id) payment_rows,
       v.reference
FROM invoices v;

-- 3. Orphans and missing links
SELECT (SELECT COUNT(*) FROM pos_payments p LEFT JOIN pos_orders o ON o.order_id=p.order_id WHERE o.order_id IS NULL) orphan_pos_payments,
       (SELECT COUNT(*) FROM invoices v WHERE v.reference LIKE 'POS-%'
          AND NOT EXISTS (SELECT 1 FROM pos_orders o WHERE o.order_number=v.reference)) orphan_pos_invoices,
       (SELECT COUNT(*) FROM pos_orders o
          WHERE NOT EXISTS (SELECT 1 FROM invoices v WHERE v.reference=o.order_number)) orders_without_invoice,
       (SELECT COUNT(*) FROM pos_orders o WHERE o.warehouse_id IS NULL) orders_without_warehouse;

-- 4. Stock held by a shop of another brand
SELECT p.brand product_brand, sh.brand shop_brand, SUM(s.quantity*p.purchase_price) value
FROM inventory_stock s
JOIN products p ON p.product_id=s.product_id
JOIN warehouses w ON w.warehouse_id=s.warehouse_id
JOIN shops sh ON sh.shop_id=w.shop_id
WHERE p.brand<>sh.brand
GROUP BY p.brand, sh.brand;

-- 5. Zero-cost stock
SELECT s.product_id, p.name, p.brand, s.warehouse_id, s.quantity, p.purchase_price
FROM inventory_stock s JOIN products p ON p.product_id=s.product_id
WHERE p.purchase_price<=0 AND s.quantity>0;

-- 6. Indexes on the two linkage tables
SELECT TABLE_NAME, INDEX_NAME, NON_UNIQUE, GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX) cols
FROM information_schema.STATISTICS
WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN ('pos_orders','invoices')
GROUP BY TABLE_NAME, INDEX_NAME, NON_UNIQUE;

-- 7. Payment methods and their tier
SELECT payment_method_id, tenant_id, name, account_type FROM payment_methods;
```

Capital figures were produced with a temporary CLI probe (`User::getUserById(1)` → `brand_capital_breakdown()` / `brand_shop_cash_summary()` / `bank_accounts_summary()`), deleted after use. The same numbers are visible in `capital.php` and `close-business.php` for the corresponding brand.

---

## Appendix B — Schema and migration integrity notes

Reference tables observed (row counts at audit time):

| Table | Rows | Notes |
|---|---|---|
| `pos_orders` | 3 | No `shop_id`/`brand`; no unique `order_number` |
| `pos_order_items` | 4 | FK to order `ON DELETE CASCADE` |
| `pos_payments` | 3 | No `tenant_id`; FK to order `ON DELETE CASCADE` |
| `invoices` | 3 | `reference` not indexed; no `pos_order_id` |
| `payments` | 0 | Unique `(tenant_id, submission_token)` |
| `sales_returns` | 1 | `source_id` has no FK (polymorphic) |
| `inventory_stock` | 22 | Unique `(product_id, warehouse_id)` |
| `inventory_movements` | 11 | Incomplete ledger (no opening balances) |
| `shop_cash_transfers` | 0 | No FKs |
| `capital_movements` | 7 | No FKs |
| `bank_sales_deposits` | 0 | No FKs |
| `brand_capital_settings` | 0 | No opening balances configured |

Migration history anomalies (from `schema_migrations` vs `database/migrations/`):

* `009_godown_store_separation` is recorded but has **no file**.
* Files `013_lead_confirmation_shop_only`, `014_independent_shop_locations`, `015_unified_location_access`, `019_remove_unused_product_variants`, `021_wholesale_accounts`, `022_customer_types`, `023_wholesale_pricing` exist but are **not recorded** as applied, although their objects appear present (e.g. `shops.brand`, `customer_type`).
* `042_shop_cash_balances` is not committed to git (`??` in `git status`) while its tables/columns already exist in the live database.

Implication: a rebuild from migrations will not reproduce the current schema. Add a schema-diff check to CI before deployment.


---

## Appendix C — Files reviewed

`sale-add.php`, `ajax-cart-update.php`, `ajax-product-search.php`, `sale-edit.php`, `sale-cancel.php`, `sale-delete.php`, `sale-details.php`, `sales.php`, `sale-receipt.php`, `sale-invoice.php`, `pos-invoice.php`, `sales-returns.php`, `sales-return-add.php`, `payment-add.php`, `receipts.php`, `invoice-cancel.php`, `invoice-edit.php`, `invoice-add.php`, `reporting-sales.php`, `export-sales.php`, `capital.php`, `capital-helpers.php`, `close-business.php`, `expenses.php`, `purchase-add.php`, `purchases.php`, `purchase-edit.php`, `inventory-adjust.php`, `helpers.php`, `database/schema_business_v2.sql`, and migrations `016`, `028`, `037`, `038`, `039`, `042`.

---

## Appendix D — Limitations and assumptions

1. **No write testing.** I did not execute POS, return or capital operations against the live database, so every defect above is established from code paths plus read-only data. Each fix must be verified with the regression tests in §8.
2. **Single tenant.** Only tenant 1 exists locally; cross-tenant isolation (e.g. the global `payment_method_id = 1` fallback) is reasoned from the schema, not observed.
3. **Cash-basis design.** The tier model reports money *received*, not sales *invoiced*. That is defensible for a cash balance, but it must be stated in the UI and must not be mixed with accrual revenue on the balance sheet (SP-03).
4. **Storefront orders** were reviewed only where they feed brand capital; the lead/order lifecycle itself is outside this audit.
5. **Performance** was not profiled; the missing `invoices.reference` index is the only query concern raised.

---

*End of report.*

