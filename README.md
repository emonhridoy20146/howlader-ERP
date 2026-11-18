# Howlader Electronics — Inventory Management ERP

**Laravel 13 · PHP 8.3 · MySQL 8 · Alpine.js 3 · Tailwind CSS v4 · Vite 8**

A multi-location retail ERP for an electric appliance business. The system manages every product unit by serial number from warehouse entry through sale, transfer, return, and service. All stock and financial mutations run inside database transactions with row-level locks.

---

## Tech Stack

| Layer | Technology |
|---|---|
| Backend | Laravel 13, PHP 8.3+ |
| Database | MySQL 8 |
| Frontend | Blade (server-rendered) + Alpine.js 3.15 + Tailwind CSS v4 |
| Build | Vite 8 |
| Charts | Chart.js 4.6 |
| Dialogs | SweetAlert2 11 |
| PDF | barryvdh/laravel-dompdf |
| Excel | maatwebsite/excel |
| Barcodes | picqer/php-barcode-generator, endroid/qr-code |
| Queue | Laravel queue worker |

---

## Architecture

- **Append-only stock ledger** — `StockLedger` records every movement; `InventoryBalance` is a cached read model recomputed via `SyncInventoryBalancesCommand`
- **Full policy layer** — 50+ Laravel policies, enforced via `AuthorizeRouteAccess`, `EnsureUserHasPermission`, and `EnsureUserIsActive` middleware
- **Service layer** — 40+ dedicated service classes; controllers delegate all business logic
- **Dizicashier integration** — HTTP client + sync service for bidirectional data sync with an external POS system (`DizicashierSyncService`, `DizicashSyncCommand`, `DizicashStockSyncCommand`)
- **SoftDeletes on SalesInvoice** — deleted invoices are recoverable; force-delete is a separate admin action
- **Serial item pricing** — individual serial units carry their own optional `sale_price` overriding the product default (added 2026-11-17)

---

## Modules

### Dashboard
Real-time KPI tiles scoped to the user's branch context: today's sales, monthly sales, outstanding receivables, draft invoices, overdue installments, upcoming dues (14-day window), low-stock count, pending transfers, open POs, and monthly collection totals. Branch annual/monthly target progress bars visible to users with the `view-dashboard-targets` permission. Attendance summary panel for authorised users.

### Catalog
- **Products** — serialized and non-serialized types; SKU, barcode, brand, category, warranty months, default prices, reorder level, photo. Bulk update via Excel export/import. Per-serial sale price overrides. Toggle active/inactive.
- **Brands** — simple lookup table
- **Categories** — with bulk product reassignment and move-products utility
- **Serial Items** — one record per physical unit; label barcode PNG endpoint; individual or bulk destroy; individual or bulk sale price updates

### Purchasing
- **Suppliers** — with phone numbers
- **Purchase Orders** — draft → approved → partially received → received workflow; payment recording against supplier wallets; additional cost postings; change log on every edit; supplier refunds
- **Goods Receipts** — posted against a PO line; posts stock into the ledger; label printing for received serials

### Inventory
- **Stock Levels** — ledger-backed available quantities per location; manual adjustment with signed before/after quantities
- **Stock Transfers** — draft → source-approved → issued → destination-approved → received; dual-approval workflow; serial scan to build transfer lines; receipt printout
- **Stock Check** — scan-based physical count session; checklist and discrepancy report

### Sales
- **Sales Invoices** — draft → posted workflow; sale types: cash, credit, installment; soft delete with trash/restore; guarantor and NID attachment fields; referrer (employee) tracking; branch reassignment; sale-type change after post
- **Invoice Lines** — serialized line picks specific serial units; discount and service charge fields
- **Serial Scan** — live serial suggestions and barcode resolve endpoint during invoice creation

### Payments & Finance
- **Payments** — inbound payments against invoices; historical payment recording; payment method update; allocation management
- **Payment Register** — bulk collection view across open invoices
- **Supplier Payment Register** — view across open supplier balances
- **Payment Methods** — per-location or global; mapped to wallets
- **Wallets** — per-location cash/digital wallet balances
- **Journal Entries** — manual GL journals with draft/post states; detailed report and account summary report
- **Ledger Accounts** — chart of accounts

### Credit & Installments
- **Credit Invoices** — list of posted invoices with outstanding credit dues
- **Installment Plans** — down payment, instalment count, frequency (weekly/monthly/custom-days), penalty rate; schedule auto-generated on activation
- **Installment Schedules** — per-instalment due date and status (pending → partially paid → paid → overdue); overdue roll-forward via scheduled command
- **Debt Write-off** — write off uncollectable invoice balances; reversed if needed

### Returns & Service
- **Return Orders** — lookup by customer or invoice; approve → resolve workflow; refund payment recording; rollback capability; receipt printout; internal serial generation for serialized products that lack existing records
- **Service Jobs** — warranty/out-of-warranty repair tracking; serial search

### HR
- **Departments & Designations**
- **Pay Scales** — grade-based salary structure
- **Employees** — multi-location assignment; check-in/check-out times; referrer on invoices and payments
- **Attendance** — daily recording; monthly report with print view
- **Leave Types & Requests** — approve/reject workflow
- **Salary Sheets** — generated from pay scale assignments; finalize action

### Reports (25+ report views, all exportable to Excel/PDF)

| Report | Description |
|---|---|
| Stock by Location | Per-product quantities at each location |
| Branch In-Stock Pricing | Current prices for stocked items per branch |
| Stock Summary | Aggregated on-hand / available / reserved KPIs |
| Sales Summary | Revenue by period and branch |
| Month-to-Date Sales | Running MTD sales |
| Item-wise Sales | Sales broken down per product |
| Representative-wise Sales | Sales attributed to employee referrers |
| Item-wise Purchase | Purchase volumes per product |
| Installment Overdue | Overdue instalment schedules |
| Installment Duration | Active plans by duration/count |
| Credit Overdue | Overdue credit invoices |
| Low Stock | Products at or below reorder level |
| Customer Dues | Outstanding balances per customer |
| Invoice Outstanding by Customer | Per-invoice receivable detail |
| High Risk Customers | Flagged customers by risk score |
| Customer Risk | Full risk snapshot — print, PDF, Excel |
| Customer Journal | Transaction history per customer |
| Customer Payment by Branch | Payment inflows broken down by branch |
| Yearly Collection Sheet | Multi-sheet Excel workbook of annual collections |
| Monthly Collection | Month-by-month collection totals |
| Open Purchase Orders | POs not yet fully received |
| Supplier Dues and Payments | AP balances per supplier |
| Sales by Day/Branch | Daily revenue heatmap by branch |
| Daily Expenses by Branch | Expense postings per branch per day |
| Branch Targets | Actual vs annual/monthly target by branch |
| Profit & Loss Sheet | GL-backed P&L |
| Income & Expenditure Statement | Full I&E |
| Wallet Transactions | Per-wallet movement ledger |
| Transfer Ledger | History of all stock movements between locations |

### Configuration & Admin
- **Users** — role-scoped; impersonation for admin debugging
- **Roles & Permissions** — permission catalogue rebuilt via migration; `SyncPolicyPermissions` command syncs policy-level gates to the database
- **Locations** — branch or warehouse type; manager assignment; annual and monthly sales targets
- **Company Pad Settings** — letterhead/footer for printed documents
- **Payment Methods** — wallet mapping per location
- **Demand Forecasting** — refresh via command or UI trigger

### Public Storefront
Unauthenticated product browsing at `/shop` — product listing and detail pages.

---

## User Roles

| Role | Key Access |
|---|---|
| `admin` | Full system; global config; impersonation; PO approval; write-off |
| `branch_manager` | Own branch sales, staff, price overrides, transfer approvals |
| `sales` | Create invoices, record payments, view own branch stock |
| `warehouse` | Post goods receipts, issue/receive transfers, manage serials |
| `accountant` | Financial data, GL journals, reports, write-off marking |

Access is enforced at the route level via the `route-access` middleware and at the model level via 50+ policies.

---

## Key Counts (actual, from codebase)

| Artifact | Count |
|---|---|
| Eloquent models | 60+ |
| Controllers | 50+ |
| Service classes | 40+ |
| Artisan commands | 36 |
| Excel export classes | 30+ |
| Policies | 50+ |
| Database migrations | 100+ |
| Blade view directories | 40+ |

---

## Artisan Commands

**Reconciliation & Repair**
- `InventoryBalanceCommand`, `SyncInventoryBalancesCommand` — rebuild balance cache from ledger
- `ReconcileSerialSalesLedgerCommand`, `ReconcilePhantomSerializedStockCommand` — fix serial integrity gaps
- `RepairCorruptedInstallmentSchedulesCommand`, `RepairInstallmentAdvanceJournalCommand`, `AlignInstallmentPlanToInvoiceDueCommand` — installment data repair
- `RepairOrphanedArLedgerCommand`, `PurgeOrphanPaymentAllocationsCommand`, `FixZeroDuePaymentStatusCommand` — AR/payment cleanup
- `HistoricalPaymentWalletGlRepairService` — GL repair for pre-wallet-system payments
- `CheckInvoiceIntegrityCommand`, `RepairInvoiceCommand` — invoice-level integrity

**Backfill & Migration**
- `BackfillSerialsForOnHandCommand`, `SerialOnHandBackfillService` — assign serial records to existing stock
- `MigrateNonSerializedProductsToSerializedCommand` — convert product type
- `AssignCustomerBranchFromLastInvoiceCommand`, `BackfillPaymentCollectorCommand`

**Sync**
- `DizicashSyncCommand`, `DizicashStockSyncCommand` — Dizicashier POS sync
- `SyncInventoryBalancesCommand`, `SyncReturnedInvoiceStatusCommand`

**Setup**
- `CreateAdminUser`, `SetupBranchWalletsCommand`, `ScaffoldModelPolicies`, `SyncPolicyPermissions`

**Maintenance**
- `RegenerateProductBarcodesCommand`, `AssignSerialLabelCodesCommand`
- `RemoveDamagedStockCommand`, `RemoveSerialsWithoutLocationCommand`
- `RollOverdueInstallmentDueCommand`, `ForecastRefreshCommand`, `RecomputeCustomerRiskCommand`

---

## Setup

```bash
composer install
cp .env.example .env
php artisan key:generate
php artisan migrate
npm install
npm run build
php artisan db:seed   # seeds default roles, ledger accounts
php artisan app:create-admin-user
```

**Development (all processes in parallel):**
```bash
composer dev
# starts: php artisan serve · queue:listen · pail (logs) · npm run dev
```

---

## Current State (as of 2026-11-17)

The core ERP is feature-complete and in active production use. Recent work includes:

- Per-serial sale price management (individual and bulk update)
- Return processing generating internal serials for serialized products that lack existing records
- Stock ledger improvements for returned items
- Product name links from stock reports to product detail pages

**Known gaps / ongoing hardening:**
- Form request validation is thin — only 2 FormRequest classes exist; most validation is inline in controllers
- Dizicashier sync has dedicated repair commands suggesting historical data inconsistencies
- Several reconciliation commands exist for installment and AR data, indicating those flows have had correctness issues that were patched
