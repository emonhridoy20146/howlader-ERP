# Deep Analysis: Gaps, Incomplete Flows & Roadmap

> Produced by scanning all service classes, controllers, models, migrations, routes, and the scheduler.
> No external documentation was used — findings are based solely on what the code actually does today.

---

## How to read this document

Each section identifies a **gap or incomplete flow**, explains what the code _actually_ does vs. what a complete system would do, and states a priority. The roadmap at the bottom groups everything into actionable phases.

---

## 1. General Ledger (GL) — Major Structural Gaps

### 1a. No COGS or Inventory Asset on the GL

**What the code does today:**
When a sale is posted, `JournalService::postSalesInvoiceGl()` posts one entry:
`DR Accounts Receivable → CR Sales Revenue`

That is the only GL impact of a sale. There is no cost-of-goods-sold entry and no inventory asset account in `LedgerAccountCodes`. The chart of accounts has: AR, AP, Sales Revenue, Bad Debt, Payment Discount, Opening Equity, PO Logistics, Historical Payment Clearing — no Inventory Asset, no COGS.

**What is missing:**
- When stock is received (GRN posted), the system writes to the stock ledger only. No journal entry credits AP or debits Inventory Asset.
- When stock is sold, no COGS debit is posted. The P&L report therefore shows only revenue and operating expenses — gross profit is invisible.
- Inventory value does not exist anywhere in the GL. The balance sheet cannot show stock value.

**Impact:** The profit & loss sheet and income & expenditure statement that already exist as reports cannot show true profit because the cost side of sales is missing. These reports are built from GL data and will only reflect revenue and manually-posted expenses.

**Priority:** Critical — the entire double-entry accounting model is incomplete without this.

---

### 1b. GRN Posting Does Not Create a Journal Entry

**What the code does today:**
`GoodsReceiptPostingService::post()` writes to `StockLedger` and updates serial item records. It calls nothing on `JournalService`.

**What is missing:**
When goods arrive and the GRN is posted, the correct accounting entry is:
`DR Inventory Asset → CR Accounts Payable`

Neither leg of this entry is posted. AP is only moved when a purchase order payment is recorded (`JournalService::postSupplierPoPayment()`), not when the obligation arises.

---

### 1c. Stock Transfers Do Not Create Journal Entries

**What the code does today:**
`TransferIssueService` and `TransferReceiveService` write to `StockLedger`. No GL entry is made for inter-location transfers.

**What is missing:**
When stock moves from warehouse to branch, an inventory-in-transit GL entry should be created:
Issue: `DR Inventory In-Transit → CR Warehouse Inventory`
Receive: `DR Branch Inventory → CR Inventory In-Transit`

Without this, inter-branch stock movement is invisible to accounting.

---

### 1d. Salary Sheet Finalization Has No GL Posting

**What the code does today:**
`SalarySheetService::finalize()` sets `status = finalized` and records `finalized_by / finalized_at`. No journal entry is created.

**What is missing:**
Finalizing a salary sheet should post:
`DR Salary Expense (per department/location) → CR Salaries Payable`
When paid out: `DR Salaries Payable → CR Wallet/Bank`

There is no salary payment recording flow at all — the system can generate and finalize a payroll but has no mechanism to record that the money was actually disbursed.

---

### 1e. Service Job Repair Revenue Has No GL Posting

**What the code does today:**
`ServiceJobController` creates and updates service jobs. `repair_cost` is stored. No journal entry is ever created.

**What is missing:**
For `paid_repair` type jobs, the repair cost collected from the customer should post:
`DR Cash/Wallet → CR Service Revenue`
For `warranty_repair`, internal cost should post to a warranty expense account.

---

## 2. Tax System — Field Exists, Logic Does Not

**What the code does today:**
`SalesInvoice` has a `tax_total` column. During invoice create/update in `SalesInvoiceController`, `tax_total` is never calculated — it is only read from the existing invoice value (which defaults to `0`). Tax is only set when records are imported from Dizicashier via sync.

**What is missing:**
- No tax rate configuration (product-level, category-level, or global)
- No automatic calculation of tax on invoice lines
- No tax GL account in the chart of accounts
- No tax report or VAT/GST output

**Impact:** The `tax_total` field on invoices is effectively dead for locally-created invoices. The system cannot produce a tax return or VAT report.

**Priority:** High — if the business is VAT-registered, this is a compliance gap.

---

## 3. Invoice Number Assignment — Unusual Deferred Pattern

**What the code does today:**
`PaymentService::assignInvoiceNumberWhenCollected()` assigns an invoice number only when a payment is first collected (`payment_status = partial or paid`). A posted invoice with no payment has `invoice_number = null`.

**What is missing / is a risk:**
- A posted invoice sent to a customer has no number until money is collected
- It is possible to reference an invoice on paperwork before it has a number
- The print view must handle `null` invoice number gracefully

**Recommendation:** Assign invoice numbers at posting time for cash and credit sales. Installment invoices could retain the deferred assignment until the down payment clears.

---

## 4. Installment Plan — Manual Activation Step

**What the code does today:**
Posting an installment invoice sets `status = active` on the invoice. However, the `InstallmentPlan` record and its schedules are created separately — via `POST sales/invoices/{invoice}/installment-plan` (the `InstallmentPlanController::store` action) or via `POST sales/invoices/{invoice}/activate-installment`. These are separate UI steps after posting.

**What is missing / is a risk:**
- An installment invoice can be posted without an installment plan ever being created
- The dashboard counts overdue schedules, but if the plan was never activated, those invoices show as `active` with no schedule at all — invisible to overdue tracking
- There is no validation on posting that forces plan creation for installment-type sales

**Recommendation:** On posting an installment invoice, auto-create the plan and schedules from the invoice's installment fields, or block posting until the plan is configured.

---

## 5. Service Jobs Module — Shallow Implementation

**What the code does today:**
Service jobs store complaint, diagnosis, work done, repair cost, status, intake/promised/completed dates, assignee. CRUD is complete.

**What is missing:**

| Missing Feature | Description |
|---|---|
| Serial status change | When a job is created, the serial's status is not updated to `under_service`. The `SERIAL_STATUSES_SERVICEABLE` list includes `under_service` but no code transitions to it. |
| Parts tracking | No ability to link spare parts (product records) used in a repair to a service job. No parts cost aggregation. |
| Invoice for paid repairs | `paid_repair` type jobs have a `repair_cost` field but no mechanism to generate a sales invoice or collect payment. |
| GL posting | Repair revenue and warranty expense are never posted to the GL (see §1e). |
| Serial returned to customer | No formal "returned to customer" stock event — the serial remains `sold` or `in_stock` without a formal service-complete-and-return flow. |
| Customer notification | No notification or SMS/email sent on job status change or completion. |

---

## 6. HR & Payroll — Payroll Is Read-Only After Finalization

**What the code does today:**
Salary sheets can be generated (from attendance + pay scales) and finalized. The finalized sheet is a report.

**What is missing:**

| Missing Feature | Description |
|---|---|
| Salary disbursement recording | No way to mark salaries as paid or record the wallet/bank outflow |
| GL posting | Finalization posts no journal entry (see §1d) |
| Overtime / bonus | No fields or calculation for overtime, bonuses, or commission |
| Tax deduction (income tax / TDS) | No deduction calculation or recording |
| Leave balance carry-forward | No leave balance ledger — approved leaves are recorded but balance is not tracked across periods |
| Leave encashment | No mechanism to encash unused leave at year end |
| Attendance from biometric / external | Attendance is manually entered. No integration point for a biometric or HR system. |

---

## 7. Notifications — None Implemented

The system has zero notification infrastructure. No email, no in-app notification, no SMS.

**Things that should trigger notifications but don't:**

| Event | Who should be notified |
|---|---|
| Installment due in N days | Customer (payment reminder) |
| Installment overdue | Branch manager + accountant |
| Low stock at branch | Warehouse + branch manager |
| Service job status changed / completed | Customer |
| Stock transfer approved/issued | Destination branch |
| PO approved | Procurement / warehouse |
| New return approved | Branch manager |
| Salary sheet finalized | Employees (payslip) |

**Priority:** Medium — the system is usable without notifications but they are critical for a retail operation running multiple branches.

---

## 8. Scheduled Tasks — Minimal Coverage

**What is scheduled today (console.php):**
```
installments:roll-overdue-due      — daily
customers:recompute-risk           — daily at 02:30
```

**Commands that exist but are NOT scheduled:**

| Command | Should run |
|---|---|
| `forecasts:refresh` | Weekly or nightly |
| `inventory:sync-balances` | Should run if ledger drift is detected |
| `inventory:balance` | On-demand repair only today |

**Missing scheduled work:**
- No automatic payment reminder queuing
- No low-stock alert generation
- No nightly balance reconciliation check
- No GL integrity check

---

## 9. Storefront (`/shop`) — Display Only, No Commerce

**What the code does today:**
`StorefrontController` renders a public product list and product detail page. It is unauthenticated and read-only.

**What is missing:**
- No cart
- No checkout
- No customer-facing login
- No online payment
- No order/enquiry submission

This module is essentially a product catalogue. If e-commerce is not a goal, the storefront is complete as-is. If it is meant to be a customer-facing ordering system, the entire commerce layer is absent.

---

## 10. Goods Receipt — No Automated AP Posting

**What the code does today:**
Goods receipts post to the stock ledger. PO payments post to `ACCOUNTS_PAYABLE` via `JournalService::postSupplierPoPayment()`. There is an `ACCOUNTS_PAYABLE` ledger account code defined.

**What is missing:**
AP liability is only recognised at the point of payment, not at the point of receipt. The correct flow is:
1. GRN posted → DR Inventory → CR Accounts Payable (obligation arises)
2. PO payment → DR Accounts Payable → CR Wallet/Bank (obligation settled)

Today, step 1 is missing, so AP on the balance sheet is understated until payment is manually recorded.

---

## 11. Return Flow — Restock Location Is Always the Return Branch

**What the code does today:**
`ReturnResolutionService::restockFromReturnLines()` restocks items to `$locked->branch_id` — the branch where the return was processed.

**Potential gap:**
If a customer returns a product at Branch A but that product belongs to Branch B's stock (sold from B's invoice), restocking it at A is correct for Branch A's physical inventory. However, the business may expect returned units to go back to the warehouse for quality inspection before being re-stocked anywhere.

There is no "return to warehouse" option. All returns go into sellable stock immediately.

---

## 12. Supplier Payment Register — Read-Only View

**What the code does today:**
`SupplierPaymentRegisterController` renders a list view. No payment recording from this screen.

**What is missing:**
The payment register is a monitoring view only. To pay a supplier, you must navigate to the specific PO and record the payment there. A payment register that only shows balances but cannot initiate payments is half-finished for a payables workflow.

---

## 13. Audit Log Coverage — Gaps in Non-Financial Flows

**What is audited today:**
Sales invoice posting, payment allocation, payment removal, payment method change, debt write-off and reversal.

**What is NOT in the audit log:**

| Event | Logged? |
|---|---|
| GRN posted / deleted | No |
| Stock transfer status changes | No |
| Serial item manual destruction | No |
| Manual stock adjustment | No |
| Service job status changes | No |
| HR: employee added/terminated | No |
| Salary sheet finalized | No |
| User created / role changed | No |
| Product price changed | No |

---

## 14. Validation Gaps — Thin FormRequest Coverage

Only 2 FormRequest classes exist (`StoreInvoiceHistoricalPaymentRequest`, `StoreInvoiceInboundPaymentRequest`). All other controllers validate inline.

**Known weak areas observed:**

| Controller | Observed gap |
|---|---|
| `ProductController` | No max length on `description`, no validation that `reorder_level` is non-negative |
| `ServiceJobController` | `complaint` has no max length; `completed_date` can precede `intake_date` |
| `SalarySheetController` | No duplicate-period check before generating (handled in service, but only partially) |
| `StockTransferController` | Serial ownership at source branch not re-validated on `issue` action |
| `GoodsReceiptController` | No check that GRN `location_id` matches the PO's `destination_location_id` before posting |
| `InstallmentPlanController` | No guard against creating a second installment plan for the same invoice |

---

## 15. Product Cost Tracking — No Standard Cost or Average Cost

**What the code does today:**
Each serial item stores `purchase_cost` from its GRN line. Non-serialized products use `default_purchase_price` as a snapshot at sale time, but `unit_cost` is `null` on non-serialized `StockLedger` rows for sales.

**What is missing:**
- No weighted average cost calculation
- No standard cost per product
- For non-serialized products, the sale-issue ledger row has `unit_cost = null` — COGS for non-serialized goods cannot be calculated at all even if a COGS account were added
- No inventory valuation report (stock value at cost)

---

## 16. Demand Forecasting — Not Scheduled, No Alert Integration

**What the code does today:**
`DemandForecastService` and `ForecastController` allow manual refresh and viewing of forecasts. The `ForecastRefreshCommand` exists but is not in the scheduler.

**What is missing:**
- Forecasts are not automatically refreshed on a schedule
- Low-stock alerts are based only on reorder level, not on forecast-adjusted lead-time
- Forecast output does not feed into automatic PO suggestions

---

## 17. Multi-Location Pricing — Partially Implemented

**What the code does today:**
`LocationProductPrice` model and table exist. `location_product_prices` stores per-location price overrides.

**What is not fully implemented:**
- When creating a sales invoice, it is not fully clear whether the invoice line picks the branch-specific price or the product default price. This needs tracing through the invoice creation controller to confirm pricing priority.
- No UI to bulk-manage location prices from the inventory view (only via product form)

---

## 18. User Roles — Custom Permission Catalogue, But Coarse Grants

**What the code does today:**
A full permission catalogue exists and is synced via policy. The `rebuild_permission_catalogue` migration (2026-08-30) suggests this was recently overhauled.

**Observed gap:**
The `role` column on `users` is still a string field (not a FK to the roles table), suggesting there may be a dual-track where both a legacy string role AND the new policy/permission system coexist. If some code checks `$user->role === 'admin'` and other code uses `$user->hasPermission(...)`, access control is inconsistently enforced.

---

## Summary Severity Table

| # | Issue | Severity | Effort |
|---|---|---|---|
| 1a | No COGS / Inventory Asset GL | Critical | High |
| 1b | GRN doesn't post to GL | Critical | Medium |
| 1d | Salary finalization doesn't post to GL | High | Low |
| 1e | Service job repair has no GL | Medium | Low |
| 1c | Stock transfer doesn't post to GL | Medium | Medium |
| 2 | Tax system is a dead field | High | High |
| 3 | Deferred invoice number assignment | Medium | Low |
| 4 | Installment plan requires manual activation | High | Low |
| 5 | Service job module is shallow | Medium | Medium |
| 6 | Payroll disbursement not tracked | High | Medium |
| 7 | Zero notification infrastructure | Medium | High |
| 8 | Minimal task scheduling | Low | Low |
| 9 | Storefront is display-only | Low | High |
| 10 | AP liability not recognised at GRN | High | Medium |
| 11 | Returns always go into branch stock | Low | Low |
| 12 | Supplier payment register is read-only | Low | Low |
| 13 | Audit log gaps in non-financial flows | Medium | Medium |
| 14 | Thin FormRequest validation | Medium | Medium |
| 15 | No cost tracking for non-serialized goods | High | High |
| 16 | Demand forecasting not scheduled | Low | Low |
| 17 | Location pricing partially implemented | Low | Low |
| 18 | Dual-track role vs. permission system | Medium | Low |
| 19 | ~~Admin multi-device logout does not invalidate other sessions~~ ✅ Done | Low | Minimal |

---

## Roadmap

### Phase 1 — Critical Accounting Fixes (Fix before financial reports can be trusted)

1. **Add Inventory Asset and COGS accounts** to `LedgerAccountCodes` and seed them in the chart of accounts.
2. **GRN GL posting** — `GoodsReceiptPostingService::post()` should call `JournalService` to post `DR Inventory → CR AP` at the moment of receipt. Rollback must reverse this.
3. **COGS posting on sale** — `SalesPostingService::finalizeSerializedSale()` should post `DR COGS → CR Inventory Asset` using `$locked->purchase_cost`. For non-serialized lines, `unit_cost` must be populated (see Phase 2 for cost tracking).
4. **AP posting on PO approval** (optional but correct) — post `DR Inventory/Accrued Liability → CR AP` when a PO is approved, then settle when payment is recorded.

### Phase 2 — Cost Tracking for Non-Serialized Products

1. Implement **weighted average cost** per product per location, maintained in a new `product_cost_ledger` table or by extending `InventoryBalance`.
2. Populate `unit_cost` on non-serialized `sale_issue` stock ledger rows at posting time.
3. Enable **inventory valuation report** once GL and cost data are reliable.

### Phase 3 — Tax System

1. Add a **tax rate** field to products or categories (or a global setting).
2. Add a `Tax Payable` ledger account.
3. Calculate `tax_total` automatically on invoice create/update from line quantities × tax rate.
4. Post `DR AR → CR Sales Revenue + CR Tax Payable` on invoice posting.
5. Add a **tax summary report** (output VAT by period).

### Phase 4 — HR & Payroll Completion

1. **Salary disbursement** — add a `salary_disbursements` table and UI to record payment from a wallet. Post `DR Salaries Payable → CR Wallet`.
2. **Salary expense GL** — on finalization, post `DR Salary Expense → CR Salaries Payable`.
3. **Leave balance ledger** — track approved/used leave per employee per year.
4. **Overtime / bonus fields** on `SalarySheetLine`.

### Phase 5 — Service Job Completion

1. **Serial status transition** — set `serial_items.status = under_service` when a job is created (intake); revert on return to customer.
2. **Parts tracking** — add `service_job_parts` table linking products/serials to jobs with quantities and costs.
3. **Invoice generation** — for `paid_repair`, allow generating a sales invoice from the job. This reuses the existing invoice posting flow.
4. **GL posting** — tie job invoicing to the existing sales GL flow; post warranty cost to a warranty expense account.

### Phase 6 — Notification System

1. Set up **Laravel Notifications** with mail channel (minimum viable).
2. Add configurable notification triggers: installment due reminder (N days before), overdue installment, low stock alert.
3. Store notification preferences in `app_settings` or a new user-preferences table.
4. Add a **notification log** so dispatched alerts are traceable.

### Phase 7 — Hardening & Validation

1. Convert high-traffic controller validation blocks to **FormRequest classes** (priority: `SalesInvoiceController`, `PurchaseOrderController`, `StockTransferController`).
2. Add **audit log entries** for GRN, transfer state changes, manual adjustments, service job updates, and HR events.
3. Fix **duplicate installment plan** guard — block creating a second plan for an already-active invoice.
4. Fix **GRN location mismatch** — validate that GRN `location_id` matches PO `destination_location_id`.
5. Resolve **dual-track role system** — standardise on the permission catalogue; deprecate the string `role` field or map it to the permission system cleanly.

### Phase 8 — Automation & Scheduling

1. Schedule `forecasts:refresh` weekly.
2. Add a nightly **inventory balance integrity check** that alerts if ledger sum ≠ balance cache for any product/location pair.
3. Add a **daily low-stock alert** command that creates notification records for products below reorder level.
4. Automate installment plan creation on invoice posting (remove the manual activation step).

### Phase 9 — Location Pricing & Storefront (Lower Priority)

1. Confirm and document the pricing priority order in invoice creation (branch override → product default).
2. Add a bulk-price-by-location screen.
3. Decide whether the storefront is a customer catalogue (complete as-is) or an e-commerce channel (needs cart + checkout + online payment integration).

---

## 19. Admin Multi-Device Session — Logout Does Not Invalidate Other Devices ✅ Implemented (2026-11-17)

**What the code does today:**
`LoginController::logout()` calls `Auth::logout()` + `$request->session()->invalidate()` which only destroys the **current request's session row**. Other active sessions for the same admin user in the `sessions` table are untouched.

**What this means:**
- An admin can be logged in on multiple devices simultaneously (no restriction exists)
- Logging out from Device A leaves Device B and C fully active
- Other roles (sales, warehouse, etc.) are completely unaffected by this — their sessions are independent

**Required change:**
Only for the `admin` role — when an admin logs out, delete **all session rows** belonging to that user from the `sessions` table before destroying the current session. Other roles retain the current single-device logout behavior.

**Implementation — single change in `LoginController::logout()`:**

```php
public function logout(Request $request): RedirectResponse
{
    $user = Auth::user();

    if ($user?->isAdmin()) {
        DB::table('sessions')->where('user_id', $user->id)->delete();
    }

    Auth::logout();
    $request->session()->invalidate();
    $request->session()->regenerateToken();

    return redirect()->route('login');
}
```

Also add `use Illuminate\Support\Facades\DB;` to the imports of `LoginController`.

**How it works after the change:**
- Admin logs out from any device → all their session rows deleted from `sessions` table → other devices get redirected to `/login` on their next request
- Non-admin users (branch-manager, sales, warehouse, accountant) → logout only destroys current session, unchanged
- The `sessions` table already has a `user_id` index so the delete query is fast
- No new tables, no config changes, no middleware changes needed

**Severity:** Low — security hardening for admin accounts
**Effort:** Minimal — single method change, one added import
