# Electric Appliance Retail Management System
## Complete AI-Buildable Specification

**Version:** 2.0  
**Stack:** Laravel 13 · MySQL 8 · Redis · Alpine.js 3 · Blade  
**Scale:** 5–20 branches · Medium traffic · Mobile-first  

---

## Table of Contents

1. [Project Overview](#1-project-overview)
2. [Architecture Decisions](#2-architecture-decisions)
3. [Directory Structure](#3-directory-structure)
4. [Environment & Configuration](#4-environment--configuration)
5. [Database Schema (Complete)](#5-database-schema-complete)
6. [Seeders & Factories](#6-seeders--factories)
7. [Authentication & RBAC](#7-authentication--rbac)
8. [Module Specifications](#8-module-specifications)
   - 8.1 Product Catalog
   - 8.2 Supplier Management
   - 8.3 Purchase Orders & Goods Receipt
   - 8.4 Inventory & Serial Tracking
   - 8.5 Stock Transfers
   - 8.6 Sales & Invoicing
   - 8.7 Payments & Receivables
   - 8.8 Installment Engine
   - 8.9 Returns, Warranty & Service
   - 8.10 Reporting & Exports
   - 8.11 Notifications
   - 8.12 Demand Forecasting
9. [Service Layer (Full Logic)](#9-service-layer-full-logic)
10. [Route Definitions](#10-route-definitions)
11. [Request Validation Rules](#11-request-validation-rules)
12. [Blade + Alpine UI Patterns](#12-blade--alpine-ui-patterns)
13. [Jobs & Queues](#13-jobs--queues)
14. [Performance & Indexing](#14-performance--indexing)
15. [External Integrations](#15-external-integrations)
16. [Testing Strategy](#16-testing-strategy)
17. [Deployment & Infrastructure](#17-deployment--infrastructure)
18. [Implementation Milestones](#18-implementation-milestones)

---

## 1. Project Overview

### Purpose
A centralized web application for managing an electric appliance retail business with one warehouse and multiple branches. The system tracks every individual unit (serialized inventory), prevents overselling, manages credit/installment sales, and provides full operational visibility across locations.

### Core Principles
- Every appliance unit is tracked individually by serial number from the moment it enters the warehouse to the moment it is sold or scrapped.
- All stock movements are recorded in an append-only ledger. Balances are derived from the ledger and cached in a fast read model (`inventory_balances`).
- All stock-affecting and money-affecting operations run inside MySQL transactions with row-level locks to prevent race conditions.
- The UI is server-rendered (Blade) and fully mobile-friendly. Alpine.js adds interactivity without a separate SPA build.

### User Roles
| Role | Responsibilities |
|---|---|
| `admin` | Full system access, global config, approvals |
| `branch_manager` | Manage own branch: sales, staff, price overrides, transfer approvals |
| `sales` | Create invoices, record payments, view own branch stock |
| `warehouse` | Post goods receipts, issue/receive transfers, manage serial items |
| `accountant` | View all financial data, generate reports, mark write-offs |

---

## 2. Architecture Decisions

### Why Laravel Monolith
- Strong transactional consistency is required (serial locks across tables).
- UI complexity is moderate — CRUD-heavy with some reactive forms.
- 5–20 branches is medium scale; a monolith is simpler to deploy, test, and maintain.
- Blade + Alpine.js covers all UI needs without a full SPA build pipeline.
- Can extract services into microservices later if needed.

### Tech Stack
| Layer | Technology | Version |
|---|---|---|
| Framework | Laravel | 13.x |
| PHP | PHP | 8.3+ |
| Database | MySQL | 8.0+ |
| Cache / Queue | Redis | 7.x |
| Frontend | Blade + Alpine.js | Alpine 3.x |
| File storage | S3-compatible (MinIO or AWS S3) | — |
| PDF generation | Laravel DomPDF or Browsershot | — |
| Barcode | ZXing JS (browser) or Picqer/PHP barcode | — |
| Email | Laravel Mail + SMTP / Mailgun | — |
| SMS | Vonage / Twilio / local gateway | — |
| Search | MySQL full-text (no Elasticsearch needed at this scale) | — |

### Rendering Model
- All pages: server-rendered Blade templates
- Reactive components (invoice line builder, serial selector, installment schedule builder): Alpine.js `x-data` islands
- Partial updates: JSON responses to `fetch()` calls from Alpine where avoiding full page reload helps UX
- Barcode input: fires on hardware scanner keypress or camera input via `QuaggaJS` in the browser

---

## 3. Directory Structure

```
app/
├── Console/
│   └── Commands/
│       ├── ProcessOverdueInstallments.php
│       ├── SendInstallmentReminders.php
│       └── RefreshDemandForecasts.php
├── Exceptions/
│   └── Handler.php
├── Http/
│   ├── Controllers/
│   │   ├── Auth/
│   │   ├── DashboardController.php
│   │   ├── ProductController.php
│   │   ├── BrandController.php
│   │   ├── CategoryController.php
│   │   ├── SupplierController.php
│   │   ├── CustomerController.php
│   │   ├── PurchaseOrderController.php
│   │   ├── GoodsReceiptController.php
│   │   ├── SerialItemController.php
│   │   ├── StockTransferController.php
│   │   ├── SalesInvoiceController.php
│   │   ├── PaymentController.php
│   │   ├── InstallmentPlanController.php
│   │   ├── ReturnController.php
│   │   ├── ServiceJobController.php
│   │   ├── PriceOverrideController.php
│   │   ├── ReportController.php
│   │   ├── LocationController.php
│   │   └── UserController.php
│   ├── Middleware/
│   │   ├── EnsureEmailIsVerified.php
│   │   └── AuditMiddleware.php
│   └── Requests/
│       ├── Sales/
│       │   ├── CreateInvoiceRequest.php
│       │   └── PostInvoiceRequest.php
│       ├── Installment/
│       │   ├── CreatePlanRequest.php
│       │   └── RescheduleRequest.php
│       ├── Transfer/
│       │   └── CreateTransferRequest.php
│       └── ... (one per controller action)
├── Models/
│   ├── User.php
│   ├── Location.php
│   ├── Product.php
│   ├── Brand.php
│   ├── Category.php
│   ├── Supplier.php
│   ├── Customer.php
│   ├── PurchaseOrder.php
│   ├── PurchaseOrderLine.php
│   ├── GoodsReceipt.php
│   ├── GoodsReceiptLine.php
│   ├── SerialItem.php
│   ├── InventoryBalance.php
│   ├── StockTransfer.php
│   ├── StockTransferLine.php
│   ├── SalesInvoice.php
│   ├── SalesInvoiceLine.php
│   ├── SaleLineSerial.php
│   ├── Payment.php
│   ├── PaymentAllocation.php
│   ├── InstallmentPlan.php
│   ├── InstallmentSchedule.php
│   ├── InstallmentReschedule.php
│   ├── InstallmentPaymentAllocation.php
│   ├── Return.php (ReturnOrder.php to avoid PHP keyword conflict)
│   ├── ReturnLine.php
│   ├── ServiceJob.php
│   ├── LocationProductPrice.php
│   ├── StockLedger.php
│   ├── AccountsReceivableLedger.php
│   └── AuditLog.php
├── Policies/
│   ├── SalesInvoicePolicy.php
│   ├── StockTransferPolicy.php
│   ├── PriceOverridePolicy.php
│   ├── InstallmentPlanPolicy.php
│   └── ReturnOrderPolicy.php
├── Services/
│   ├── SalesPostingService.php
│   ├── GoodsReceiptPostingService.php
│   ├── TransferIssueService.php
│   ├── TransferReceiveService.php
│   ├── InstallmentPlanService.php
│   ├── InstallmentAllocationService.php
│   ├── ReturnProcessingService.php
│   ├── PriceOverrideApprovalService.php
│   ├── InventoryBalanceService.php
│   ├── WarrantyValidationService.php
│   ├── BarcodeService.php
│   ├── DemandForecastService.php
│   └── AuditService.php
├── Actions/
│   ├── PostGoodsReceiptAction.php
│   ├── PostSalesInvoiceAction.php
│   ├── IssueTransferAction.php
│   ├── ReceiveTransferAction.php
│   ├── ApproveReturnAction.php
│   └── WriteOffInstallmentAction.php
├── Jobs/
│   ├── GenerateInvoicePdf.php
│   ├── ExportStockReport.php
│   ├── SendInstallmentDueReminder.php
│   ├── MarkOverdueInstallments.php
│   ├── SendSmsNotification.php
│   └── SendEmailNotification.php
└── Observers/
    └── SerialItemObserver.php

resources/
├── views/
│   ├── layouts/
│   │   ├── app.blade.php          (main authenticated layout)
│   │   └── auth.blade.php         (login/register layout)
│   ├── components/
│   │   ├── card.blade.php
│   │   ├── badge.blade.php
│   │   ├── data-table.blade.php
│   │   ├── modal.blade.php
│   │   ├── serial-selector.blade.php
│   │   ├── barcode-input.blade.php
│   │   ├── invoice-line-builder.blade.php
│   │   └── installment-schedule-builder.blade.php
│   ├── dashboard/
│   ├── products/
│   ├── suppliers/
│   ├── customers/
│   ├── purchase-orders/
│   ├── goods-receipts/
│   ├── transfers/
│   ├── sales/
│   ├── payments/
│   ├── installments/
│   ├── returns/
│   ├── services/
│   └── reports/
└── js/
    ├── app.js
    ├── alpine-components/
    │   ├── invoice-builder.js
    │   ├── serial-picker.js
    │   ├── installment-builder.js
    │   └── barcode-handler.js
    └── helpers/
        └── currency.js
```

---

## 4. Environment & Configuration

### `.env` keys required

```env
APP_NAME="ApplianceRetail"
APP_ENV=production
APP_KEY=
APP_URL=https://your-domain.com

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=appliance_retail
DB_USERNAME=
DB_PASSWORD=

REDIS_HOST=127.0.0.1
REDIS_PORT=6379

CACHE_DRIVER=redis
SESSION_DRIVER=redis
QUEUE_CONNECTION=redis

# S3-compatible storage
AWS_ACCESS_KEY_ID=
AWS_SECRET_ACCESS_KEY=
AWS_DEFAULT_REGION=ap-southeast-1
AWS_BUCKET=appliance-retail
AWS_ENDPOINT=https://s3.your-provider.com

# SMS gateway (Vonage/Twilio/local)
SMS_DRIVER=vonage
VONAGE_KEY=
VONAGE_SECRET=
VONAGE_FROM=

# Email
MAIL_MAILER=smtp
MAIL_HOST=
MAIL_PORT=587
MAIL_USERNAME=
MAIL_PASSWORD=
MAIL_FROM_ADDRESS=noreply@your-domain.com

# Barcode
BARCODE_FORMAT=CODE128

# Feature flags
FEATURE_DEMAND_FORECAST=true
FEATURE_SMS_NOTIFICATIONS=true
FEATURE_LATE_FEES=true
```

### `config/inventory.php` (custom config)

```php
return [
    'serial_statuses' => ['in_stock', 'reserved', 'sold', 'returned', 'damaged', 'in_transfer', 'under_service'],
    'transfer_statuses' => ['draft', 'approved', 'issued', 'received', 'cancelled'],
    'po_statuses' => ['draft', 'approved', 'partially_received', 'received', 'cancelled'],
    'sale_types' => ['cash', 'credit', 'installment'],
    'payment_methods' => ['cash', 'card', 'bank_transfer', 'mobile_money'],
    'low_stock_threshold_days' => 7,      // used by demand forecast
    'price_override_min_margin' => 0.05,  // 5% minimum margin enforced
    'installment_late_fee_percent' => 0.02, // 2% of overdue amount per month
    'max_credit_without_approval' => 50000,
    'barcode_prefix' => 'SN-',
];
```

---

## 5. Database Schema (Complete)

> All tables use `utf8mb4_unicode_ci`. All `id` columns are `bigint unsigned AUTO_INCREMENT PRIMARY KEY` unless otherwise noted. All timestamps use `DATETIME`. Foreign keys enforced. InnoDB engine required for row locking.

### Migration order (run in this sequence)

```
001_create_locations_table
002_create_categories_table
003_create_brands_table
004_create_users_table
005_create_products_table
006_create_location_product_prices_table
007_create_suppliers_table
008_create_customers_table
009_create_purchase_orders_table
010_create_purchase_order_lines_table
011_create_goods_receipts_table
012_create_goods_receipt_lines_table
013_create_serial_items_table
014_create_inventory_balances_table
015_create_stock_transfers_table
016_create_stock_transfer_lines_table
017_create_sales_invoices_table
018_create_sales_invoice_lines_table
019_create_sale_line_serials_table
020_create_payments_table
021_create_payment_allocations_table
022_create_installment_plans_table
023_create_installment_schedules_table
024_create_installment_reschedules_table
025_create_installment_payment_allocations_table
026_create_return_orders_table
027_create_return_lines_table
028_create_service_jobs_table
029_create_stock_ledger_table
030_create_accounts_receivable_ledger_table
031_create_audit_logs_table
032_create_demand_forecasts_table
033_add_indexes
```

### Complete migration definitions

#### `001_create_locations_table`
```php
Schema::create('locations', function (Blueprint $table) {
    $table->id();
    $table->string('code', 20)->unique();
    $table->string('name', 100);
    $table->enum('type', ['warehouse', 'branch']);
    $table->text('address')->nullable();
    $table->string('phone', 20)->nullable();
    $table->string('manager_name', 100)->nullable();
    $table->boolean('is_active')->default(true);
    $table->timestamps();
});
```

#### `002_create_categories_table`
```php
Schema::create('categories', function (Blueprint $table) {
    $table->id();
    $table->string('name', 100);
    $table->foreignId('parent_id')->nullable()->constrained('categories')->nullOnDelete();
    $table->integer('sort_order')->default(0);
    $table->timestamps();
});
```

#### `003_create_brands_table`
```php
Schema::create('brands', function (Blueprint $table) {
    $table->id();
    $table->string('name', 100)->unique();
    $table->string('country_of_origin', 60)->nullable();
    $table->timestamps();
});
```

#### `004_create_users_table`
```php
Schema::create('users', function (Blueprint $table) {
    $table->id();
    $table->string('full_name', 100);
    $table->string('email', 150)->unique();
    $table->string('phone', 20)->nullable();
    $table->string('password');
    $table->enum('role', ['admin', 'branch_manager', 'sales', 'warehouse', 'accountant']);
    $table->foreignId('branch_id')->nullable()->constrained('locations')->nullOnDelete();
    $table->boolean('is_active')->default(true);
    $table->rememberToken();
    $table->timestamp('last_login_at')->nullable();
    $table->timestamps();
});
```

#### `005_create_products_table`
```php
Schema::create('products', function (Blueprint $table) {
    $table->id();
    $table->string('sku', 60)->unique();
    $table->string('barcode', 100)->nullable()->unique();
    $table->foreignId('brand_id')->constrained('brands');
    $table->foreignId('category_id')->constrained('categories');
    $table->string('name', 200);
    $table->string('model_number', 100)->nullable();
    $table->text('description')->nullable();
    $table->unsignedSmallInteger('warranty_months')->default(12);
    $table->decimal('default_purchase_price', 12, 2)->default(0);
    $table->decimal('default_sale_price', 12, 2)->default(0);
    $table->unsignedInteger('reorder_level')->default(0);
    $table->string('unit_of_measure', 20)->default('unit');
    $table->boolean('is_serialized')->default(true); // most appliances are serialized
    $table->boolean('is_active')->default(true);
    $table->timestamps();
});
```

#### `006_create_location_product_prices_table`
```php
Schema::create('location_product_prices', function (Blueprint $table) {
    $table->id();
    $table->foreignId('location_id')->constrained('locations')->cascadeOnDelete();
    $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
    $table->decimal('override_price', 12, 2);
    $table->enum('approval_status', ['pending', 'approved', 'rejected'])->default('pending');
    $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
    $table->timestamp('approved_at')->nullable();
    $table->foreignId('created_by')->constrained('users');
    $table->timestamps();
    $table->unique(['location_id', 'product_id']);
});
```

#### `007_create_suppliers_table`
```php
Schema::create('suppliers', function (Blueprint $table) {
    $table->id();
    $table->string('name', 150);
    $table->string('contact_person', 100)->nullable();
    $table->string('phone', 20)->nullable();
    $table->string('email', 150)->nullable();
    $table->text('address')->nullable();
    $table->string('tax_number', 50)->nullable();
    $table->decimal('outstanding_balance', 12, 2)->default(0); // owed to supplier
    $table->boolean('is_active')->default(true);
    $table->timestamps();
});
```

#### `008_create_customers_table`
```php
Schema::create('customers', function (Blueprint $table) {
    $table->id();
    $table->string('customer_code', 20)->unique();
    $table->string('name', 150);
    $table->string('phone', 20);
    $table->string('email', 150)->nullable();
    $table->text('address')->nullable();
    $table->string('id_type', 40)->nullable(); // NID, passport, etc.
    $table->string('id_number', 80)->nullable();
    $table->decimal('credit_limit', 12, 2)->nullable();
    $table->decimal('outstanding_balance', 12, 2)->default(0); // denormalized, updated on post
    $table->boolean('is_active')->default(true);
    $table->timestamps();
});
```

#### `009_create_purchase_orders_table`
```php
Schema::create('purchase_orders', function (Blueprint $table) {
    $table->id();
    $table->string('po_number', 30)->unique();
    $table->foreignId('supplier_id')->constrained('suppliers');
    $table->foreignId('destination_location_id')->constrained('locations');
    $table->enum('status', ['draft', 'approved', 'partially_received', 'received', 'cancelled'])->default('draft');
    $table->date('ordered_at');
    $table->date('expected_at')->nullable();
    $table->text('notes')->nullable();
    $table->decimal('total_amount', 12, 2)->default(0);
    $table->foreignId('created_by')->constrained('users');
    $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
    $table->timestamp('approved_at')->nullable();
    $table->timestamps();
});
```

#### `010_create_purchase_order_lines_table`
```php
Schema::create('purchase_order_lines', function (Blueprint $table) {
    $table->id();
    $table->foreignId('purchase_order_id')->constrained('purchase_orders')->cascadeOnDelete();
    $table->foreignId('product_id')->constrained('products');
    $table->unsignedInteger('qty_ordered');
    $table->unsignedInteger('qty_received')->default(0);
    $table->decimal('unit_cost', 12, 2);
    $table->decimal('tax_amount', 12, 2)->default(0);
    $table->decimal('line_total', 12, 2)->virtualAs('qty_ordered * unit_cost + tax_amount');
    $table->timestamps();
});
```

#### `011_create_goods_receipts_table`
```php
Schema::create('goods_receipts', function (Blueprint $table) {
    $table->id();
    $table->string('grn_number', 30)->unique();
    $table->foreignId('purchase_order_id')->nullable()->constrained('purchase_orders')->nullOnDelete();
    $table->foreignId('supplier_id')->constrained('suppliers');
    $table->foreignId('location_id')->constrained('locations');
    $table->enum('status', ['draft', 'posted', 'cancelled'])->default('draft');
    $table->date('received_at');
    $table->string('delivery_note_ref', 80)->nullable();
    $table->foreignId('created_by')->constrained('users');
    $table->foreignId('posted_by')->nullable()->constrained('users')->nullOnDelete();
    $table->timestamp('posted_at')->nullable();
    $table->timestamps();
});
```

#### `012_create_goods_receipt_lines_table`
```php
Schema::create('goods_receipt_lines', function (Blueprint $table) {
    $table->id();
    $table->foreignId('goods_receipt_id')->constrained('goods_receipts')->cascadeOnDelete();
    $table->foreignId('product_id')->constrained('products');
    $table->unsignedInteger('qty_received');
    $table->decimal('unit_cost', 12, 2);
    $table->timestamps();
});
```

#### `013_create_serial_items_table`
```php
Schema::create('serial_items', function (Blueprint $table) {
    $table->id();
    $table->foreignId('product_id')->constrained('products');
    $table->string('serial_number', 100)->unique();
    $table->foreignId('current_location_id')->nullable()->constrained('locations')->nullOnDelete();
    $table->enum('status', [
        'in_stock', 'reserved', 'sold', 'returned',
        'damaged', 'in_transfer', 'under_service'
    ])->default('in_stock');
    $table->foreignId('goods_receipt_line_id')->nullable()->constrained('goods_receipt_lines')->nullOnDelete();
    $table->foreignId('sale_invoice_line_id')->nullable()->constrained('sales_invoice_lines')->nullOnDelete();
    $table->date('warranty_start_date')->nullable();
    $table->date('warranty_end_date')->nullable();
    $table->decimal('purchase_cost', 12, 2)->nullable(); // captured at receipt
    $table->timestamps();
    
    $table->index(['product_id', 'current_location_id', 'status']); // critical composite
    $table->index('serial_number');
});
```

#### `014_create_inventory_balances_table`
```php
// Composite PK: no auto-increment id
Schema::create('inventory_balances', function (Blueprint $table) {
    $table->foreignId('product_id')->constrained('products');
    $table->foreignId('location_id')->constrained('locations');
    $table->unsignedInteger('on_hand_qty')->default(0);
    $table->unsignedInteger('reserved_qty')->default(0);
    $table->unsignedInteger('available_qty')->default(0); // on_hand - reserved
    $table->timestamp('updated_at')->useCurrent()->useCurrentOnUpdate();
    
    $table->primary(['product_id', 'location_id']);
});
```

#### `015_create_stock_transfers_table`
```php
Schema::create('stock_transfers', function (Blueprint $table) {
    $table->id();
    $table->string('transfer_number', 30)->unique();
    $table->foreignId('source_location_id')->constrained('locations');
    $table->foreignId('destination_location_id')->constrained('locations');
    $table->enum('status', ['draft', 'approved', 'issued', 'received', 'cancelled'])->default('draft');
    $table->text('reason')->nullable();
    $table->foreignId('requested_by')->constrained('users');
    $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
    $table->foreignId('issued_by')->nullable()->constrained('users')->nullOnDelete();
    $table->foreignId('received_by')->nullable()->constrained('users')->nullOnDelete();
    $table->timestamp('requested_at');
    $table->timestamp('approved_at')->nullable();
    $table->timestamp('issued_at')->nullable();
    $table->timestamp('received_at')->nullable();
    $table->timestamps();
});
```

#### `016_create_stock_transfer_lines_table`
```php
Schema::create('stock_transfer_lines', function (Blueprint $table) {
    $table->id();
    $table->foreignId('stock_transfer_id')->constrained('stock_transfers')->cascadeOnDelete();
    $table->foreignId('product_id')->constrained('products');
    $table->unsignedInteger('qty_requested');
    $table->unsignedInteger('qty_issued')->default(0);
    $table->unsignedInteger('qty_received')->default(0);
    $table->timestamps();
});
```

#### `017_create_sales_invoices_table`
```php
Schema::create('sales_invoices', function (Blueprint $table) {
    $table->id();
    $table->string('invoice_number', 30)->unique();
    $table->date('invoice_date');
    $table->foreignId('branch_id')->constrained('locations');
    $table->foreignId('customer_id')->constrained('customers');
    $table->enum('sale_type', ['cash', 'credit', 'installment'])->default('cash');
    $table->enum('status', [
        'draft', 'posted', 'partially_paid', 'paid', 'cancelled', 'returned'
    ])->default('draft');
    $table->decimal('subtotal', 12, 2)->default(0);
    $table->decimal('discount_total', 12, 2)->default(0);
    $table->decimal('tax_total', 12, 2)->default(0);
    $table->decimal('grand_total', 12, 2)->default(0);
    $table->decimal('amount_paid', 12, 2)->default(0);
    $table->decimal('amount_due', 12, 2)->default(0);
    $table->text('notes')->nullable();
    $table->string('pdf_path', 255)->nullable();
    $table->foreignId('created_by')->constrained('users');
    $table->foreignId('posted_by')->nullable()->constrained('users')->nullOnDelete();
    $table->timestamp('posted_at')->nullable();
    $table->timestamps();
    
    $table->index(['branch_id', 'invoice_date', 'status']); // critical composite
    $table->index(['customer_id', 'status']);
});
```

#### `018_create_sales_invoice_lines_table`
```php
Schema::create('sales_invoice_lines', function (Blueprint $table) {
    $table->id();
    $table->foreignId('sales_invoice_id')->constrained('sales_invoices')->cascadeOnDelete();
    $table->foreignId('product_id')->constrained('products');
    $table->unsignedInteger('qty')->default(1);
    $table->decimal('unit_price', 12, 2);
    $table->decimal('discount_amount', 12, 2)->default(0);
    $table->decimal('tax_amount', 12, 2)->default(0);
    $table->decimal('line_total', 12, 2);
    $table->unsignedSmallInteger('warranty_months_snapshot'); // captured at sale time
    $table->timestamps();
});
```

#### `019_create_sale_line_serials_table`
```php
Schema::create('sale_line_serials', function (Blueprint $table) {
    $table->id();
    $table->foreignId('sales_invoice_line_id')->constrained('sales_invoice_lines')->cascadeOnDelete();
    $table->foreignId('serial_item_id')->constrained('serial_items');
    $table->timestamps();
    
    $table->unique('serial_item_id'); // a serial can only appear on one invoice line
});
```

#### `020_create_payments_table`
```php
Schema::create('payments', function (Blueprint $table) {
    $table->id();
    $table->string('payment_number', 30)->unique();
    $table->foreignId('customer_id')->nullable()->constrained('customers')->nullOnDelete();
    $table->date('payment_date');
    $table->enum('payment_method', ['cash', 'card', 'bank_transfer', 'mobile_money']);
    $table->decimal('amount', 12, 2);
    $table->enum('direction', ['inbound', 'outbound'])->default('inbound');
    $table->string('reference_no', 100)->nullable();
    $table->text('notes')->nullable();
    $table->foreignId('recorded_by')->constrained('users');
    $table->timestamps();
});
```

#### `021_create_payment_allocations_table`
```php
Schema::create('payment_allocations', function (Blueprint $table) {
    $table->id();
    $table->foreignId('payment_id')->constrained('payments')->cascadeOnDelete();
    $table->foreignId('sales_invoice_id')->constrained('sales_invoices');
    $table->decimal('allocated_amount', 12, 2);
    $table->timestamps();
});
```

#### `022_create_installment_plans_table`
```php
Schema::create('installment_plans', function (Blueprint $table) {
    $table->id();
    $table->foreignId('sales_invoice_id')->constrained('sales_invoices');
    $table->foreignId('customer_id')->constrained('customers');
    $table->decimal('total_amount', 12, 2);
    $table->decimal('down_payment', 12, 2)->default(0);
    $table->decimal('financed_amount', 12, 2);
    $table->decimal('remaining_amount', 12, 2);
    $table->boolean('penalty_enabled')->default(false);
    $table->decimal('penalty_rate', 8, 4)->default(0.02); // 2% per month
    $table->unsignedSmallInteger('reschedule_count')->default(0);
    $table->enum('status', ['active', 'completed', 'defaulted', 'cancelled'])->default('active');
    $table->foreignId('created_by')->constrained('users');
    $table->timestamps();
});
```

#### `023_create_installment_schedules_table`
```php
Schema::create('installment_schedules', function (Blueprint $table) {
    $table->id();
    $table->foreignId('installment_plan_id')->constrained('installment_plans')->cascadeOnDelete();
    $table->unsignedSmallInteger('installment_no');
    $table->date('due_date');
    $table->decimal('due_amount', 12, 2);
    $table->decimal('paid_amount', 12, 2)->default(0);
    $table->decimal('late_fee_amount', 12, 2)->default(0);
    $table->enum('status', ['pending', 'partially_paid', 'paid', 'overdue', 'rescheduled'])->default('pending');
    $table->timestamps();
    
    $table->index(['installment_plan_id', 'due_date', 'status']); // critical composite
});
```

#### `024_create_installment_reschedules_table`
```php
Schema::create('installment_reschedules', function (Blueprint $table) {
    $table->id();
    $table->foreignId('installment_plan_id')->constrained('installment_plans');
    $table->json('previous_schedule_snapshot'); // full snapshot before reschedule
    $table->text('reason');
    $table->foreignId('approved_by')->constrained('users');
    $table->foreignId('created_by')->constrained('users');
    $table->timestamps();
});
```

#### `025_create_installment_payment_allocations_table`
```php
Schema::create('installment_payment_allocations', function (Blueprint $table) {
    $table->id();
    $table->foreignId('installment_schedule_id')->constrained('installment_schedules')->cascadeOnDelete();
    $table->foreignId('payment_id')->constrained('payments');
    $table->decimal('allocated_amount', 12, 2);
    $table->timestamps();
});
```

#### `026_create_return_orders_table`
```php
Schema::create('return_orders', function (Blueprint $table) {
    $table->id();
    $table->string('return_number', 30)->unique();
    $table->foreignId('sales_invoice_id')->constrained('sales_invoices');
    $table->foreignId('customer_id')->constrained('customers');
    $table->foreignId('branch_id')->constrained('locations');
    $table->enum('status', ['draft', 'approved', 'restocked', 'rejected', 'refunded'])->default('draft');
    $table->date('return_date');
    $table->text('reason')->nullable();
    $table->foreignId('created_by')->constrained('users');
    $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
    $table->timestamp('approved_at')->nullable();
    $table->timestamps();
});
```

#### `027_create_return_lines_table`
```php
Schema::create('return_lines', function (Blueprint $table) {
    $table->id();
    $table->foreignId('return_order_id')->constrained('return_orders')->cascadeOnDelete();
    $table->foreignId('sales_invoice_line_id')->constrained('sales_invoice_lines');
    $table->foreignId('serial_item_id')->constrained('serial_items');
    $table->enum('resolution', ['restock', 'repair', 'replace', 'scrap']);
    $table->text('condition_notes')->nullable();
    $table->timestamps();
});
```

#### `028_create_service_jobs_table`
```php
Schema::create('service_jobs', function (Blueprint $table) {
    $table->id();
    $table->string('job_number', 30)->unique();
    $table->foreignId('serial_item_id')->constrained('serial_items');
    $table->foreignId('customer_id')->nullable()->constrained('customers')->nullOnDelete();
    $table->foreignId('branch_id')->constrained('locations');
    $table->enum('type', ['warranty_repair', 'paid_repair', 'inspection']);
    $table->enum('status', ['intake', 'diagnosing', 'awaiting_parts', 'repairing', 'done', 'returned_to_customer', 'unrepairable']);
    $table->text('complaint');
    $table->text('diagnosis')->nullable();
    $table->text('work_done')->nullable();
    $table->decimal('repair_cost', 12, 2)->default(0);
    $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
    $table->date('intake_date');
    $table->date('promised_date')->nullable();
    $table->date('completed_date')->nullable();
    $table->timestamps();
});
```

#### `029_create_stock_ledger_table`
```php
Schema::create('stock_ledger', function (Blueprint $table) {
    $table->id();
    $table->timestamp('event_time')->useCurrent();
    $table->foreignId('product_id')->constrained('products');
    $table->foreignId('serial_item_id')->nullable()->constrained('serial_items')->nullOnDelete();
    $table->foreignId('location_id')->constrained('locations');
    $table->enum('movement_type', [
        'purchase_receive', 'sale_issue', 'return_receive',
        'adjust_in', 'adjust_out', 'transfer_issue', 'transfer_receive',
        'damage_out', 'reserve', 'unreserve'
    ]);
    $table->integer('quantity_delta'); // positive = in, negative = out
    $table->decimal('unit_cost', 12, 2)->nullable();
    $table->string('reference_type', 50); // 'GoodsReceipt', 'SalesInvoice', etc.
    $table->unsignedBigInteger('reference_id');
    $table->foreignId('actor_user_id')->constrained('users');
    $table->timestamps();
    
    $table->index(['location_id', 'product_id', 'event_time']); // critical composite
    $table->index(['reference_type', 'reference_id']);
});
```

#### `030_create_accounts_receivable_ledger_table`
```php
Schema::create('accounts_receivable_ledger', function (Blueprint $table) {
    $table->id();
    $table->foreignId('customer_id')->constrained('customers');
    $table->timestamp('event_time')->useCurrent();
    $table->enum('event_type', ['invoice_posted', 'payment_allocated', 'credit_note', 'late_fee', 'writeoff']);
    $table->decimal('amount_delta', 12, 2); // positive = customer owes more, negative = paid
    $table->string('reference_type', 50);
    $table->unsignedBigInteger('reference_id');
    $table->foreignId('actor_user_id')->constrained('users');
    $table->timestamps();
    
    $table->index(['customer_id', 'event_time']);
});
```

#### `031_create_audit_logs_table`
```php
Schema::create('audit_logs', function (Blueprint $table) {
    $table->id();
    $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
    $table->string('action', 100); // e.g. 'invoice.posted', 'serial.status_changed'
    $table->string('entity_type', 80);
    $table->unsignedBigInteger('entity_id');
    $table->json('before_snapshot')->nullable();
    $table->json('after_snapshot')->nullable();
    $table->string('ip_address', 45)->nullable();
    $table->timestamp('created_at')->useCurrent();
    
    $table->index(['entity_type', 'entity_id']);
    $table->index('actor_user_id');
});
```

#### `032_create_demand_forecasts_table`
```php
Schema::create('demand_forecasts', function (Blueprint $table) {
    $table->id();
    $table->foreignId('product_id')->constrained('products');
    $table->foreignId('location_id')->constrained('locations');
    $table->date('forecast_month'); // first day of month
    $table->decimal('predicted_qty', 10, 2);
    $table->decimal('actual_qty', 10, 2)->nullable();
    $table->string('algorithm', 40)->default('moving_average_3m');
    $table->timestamp('generated_at');
    $table->timestamps();
    
    $table->unique(['product_id', 'location_id', 'forecast_month']);
});
```

---

## 6. Seeders & Factories

### DatabaseSeeder
```php
// database/seeders/DatabaseSeeder.php
public function run(): void
{
    $this->call([
        LocationSeeder::class,
        CategorySeeder::class,
        BrandSeeder::class,
        UserSeeder::class,         // creates one admin user
        ProductSeeder::class,      // dev only: sample products
        SupplierSeeder::class,     // dev only: sample suppliers
        CustomerSeeder::class,     // dev only: sample customers
    ]);
}
```

### LocationSeeder (required for prod)
```php
Location::create(['code' => 'WH-01', 'name' => 'Main Warehouse', 'type' => 'warehouse']);
Location::create(['code' => 'BR-01', 'name' => 'Branch 1 – Downtown', 'type' => 'branch']);
```

### UserSeeder (required for prod)
```php
User::create([
    'full_name' => 'System Admin',
    'email' => env('ADMIN_EMAIL', 'admin@example.com'),
    'password' => Hash::make(env('ADMIN_PASSWORD', 'changeme')),
    'role' => 'admin',
]);
```

---

## 7. Authentication & RBAC

### Authentication
Use Laravel's built-in Auth (session-based). No API tokens for the web UI.

```php
// routes/auth.php
Route::get('/login', [LoginController::class, 'showLoginForm'])->name('login');
Route::post('/login', [LoginController::class, 'login']);
Route::post('/logout', [LoginController::class, 'logout'])->name('logout');
```

### Middleware groups
```php
// Apply to all protected routes
Route::middleware(['auth', 'active-user'])->group(function () { ... });
```

### Gates (define in AuthServiceProvider)
```php
Gate::define('manage-users', fn(User $u) => $u->role === 'admin');
Gate::define('approve-transfers', fn(User $u) => in_array($u->role, ['admin', 'branch_manager']));
Gate::define('approve-price-override', fn(User $u) => in_array($u->role, ['admin', 'branch_manager']));
Gate::define('post-goods-receipt', fn(User $u) => in_array($u->role, ['admin', 'warehouse']));
Gate::define('view-all-branches', fn(User $u) => in_array($u->role, ['admin', 'accountant']));
Gate::define('approve-returns', fn(User $u) => in_array($u->role, ['admin', 'branch_manager']));
Gate::define('apply-discount', fn(User $u) => in_array($u->role, ['admin', 'branch_manager', 'sales']));
Gate::define('reschedule-installment', fn(User $u) => in_array($u->role, ['admin', 'branch_manager']));
Gate::define('write-off-debt', fn(User $u) => in_array($u->role, ['admin', 'accountant']));
Gate::define('export-reports', fn(User $u) => in_array($u->role, ['admin', 'accountant', 'branch_manager']));
```

### Policy: SalesInvoicePolicy (example)
```php
class SalesInvoicePolicy
{
    public function view(User $user, SalesInvoice $invoice): bool
    {
        if (in_array($user->role, ['admin', 'accountant'])) return true;
        return $user->branch_id === $invoice->branch_id;
    }
    
    public function post(User $user, SalesInvoice $invoice): bool
    {
        return $invoice->status === 'draft'
            && $invoice->created_by === $user->id
            || in_array($user->role, ['admin']);
    }
    
    public function cancel(User $user, SalesInvoice $invoice): bool
    {
        return in_array($user->role, ['admin', 'branch_manager'])
            && $invoice->status !== 'paid';
    }
}
```

---

## 8. Module Specifications

### 8.1 Product Catalog

**Purpose:** Manage the master list of products, their categories, brands, pricing, and barcode assignments.

**Fields exposed in UI:** SKU, Barcode, Brand, Category, Name, Model Number, Description, Warranty Months, Default Purchase Price, Default Sale Price, Reorder Level, Is Active.

**Business rules:**
- SKU is generated automatically on create: `{BRAND_CODE}-{CATEGORY_CODE}-{SEQUENCE}` e.g. `SAM-REF-0042`
- Barcode can be system-generated (Code128 of the SKU) or manually entered
- Changing `default_sale_price` does NOT change existing posted invoices
- Deactivating a product prevents it from being added to new POs or invoices but does not affect existing records
- The `warranty_months` value is copied (snapshotted) into `sales_invoice_lines.warranty_months_snapshot` at sale time

**Controller actions:** index, create, store, show, edit, update, toggleActive, generateBarcode

**Barcode generation (BarcodeService):**
```php
public function generateForProduct(Product $product): string
{
    // Returns base64-encoded PNG barcode image
    $generator = new BarcodeGeneratorPNG();
    return base64_encode($generator->getBarcode($product->sku, $generator::TYPE_CODE_128));
}
```

---

### 8.2 Supplier Management

**Purpose:** Maintain supplier records, track outstanding payables.

**Business rules:**
- Supplier outstanding balance is updated when a Goods Receipt is posted (debit) and when a supplier payment is recorded (credit)
- A supplier cannot be deleted if they have active POs or GRNs; soft-deactivate instead

**Controller actions:** index, create, store, show, edit, update, toggleActive

---

### 8.3 Purchase Orders & Goods Receipt

**Purchase Order flow:**
1. `draft` → created by warehouse/admin, lists products and quantities
2. `approved` → approved by admin, triggers PO number assignment
3. `partially_received` → at least one GRN posted against this PO
4. `received` → all quantities received
5. `cancelled` → only from draft or approved status

**Goods Receipt flow:**
1. Create GRN (can be linked to PO or standalone)
2. Enter lines (product, qty, unit cost)
3. Enter serial numbers for each unit (one serial per unit)
4. `post` → runs `GoodsReceiptPostingService`

**GoodsReceiptPostingService (full logic):**
```php
public function post(GoodsReceipt $grn, int $actorUserId): void
{
    DB::transaction(function () use ($grn, $actorUserId) {
        if ($grn->status !== 'draft') {
            throw new InvalidStateException('GRN already posted or cancelled.');
        }
        
        foreach ($grn->lines as $line) {
            $serials = $line->serials; // pre-attached serial_items in 'draft' state
            
            if (count($serials) !== $line->qty_received) {
                throw new SerialCountMismatchException(
                    "Serial count mismatch for product {$line->product_id}"
                );
            }
            
            foreach ($serials as $serial) {
                // Lock the serial row
                $serial = SerialItem::lockForUpdate()->findOrFail($serial->id);
                
                if ($serial->status !== 'draft') {
                    throw new SerialAlreadyProcessedException($serial->serial_number);
                }
                
                $serial->update([
                    'status' => 'in_stock',
                    'current_location_id' => $grn->location_id,
                    'purchase_cost' => $line->unit_cost,
                    'goods_receipt_line_id' => $line->id,
                ]);
                
                // Write stock ledger
                StockLedger::create([
                    'event_time' => now(),
                    'product_id' => $line->product_id,
                    'serial_item_id' => $serial->id,
                    'location_id' => $grn->location_id,
                    'movement_type' => 'purchase_receive',
                    'quantity_delta' => 1,
                    'unit_cost' => $line->unit_cost,
                    'reference_type' => 'GoodsReceipt',
                    'reference_id' => $grn->id,
                    'actor_user_id' => $actorUserId,
                ]);
            }
            
            // Update inventory balance
            InventoryBalance::updateOrCreate(
                ['product_id' => $line->product_id, 'location_id' => $grn->location_id],
                []
            )->increment('on_hand_qty', $line->qty_received);
            
            InventoryBalance::where([
                'product_id' => $line->product_id,
                'location_id' => $grn->location_id,
            ])->update([
                'available_qty' => DB::raw('on_hand_qty - reserved_qty')
            ]);
            
            // Update PO received qty
            if ($grn->purchase_order_id) {
                PurchaseOrderLine::where('purchase_order_id', $grn->purchase_order_id)
                    ->where('product_id', $line->product_id)
                    ->increment('qty_received', $line->qty_received);
            }
        }
        
        $grn->update([
            'status' => 'posted',
            'posted_by' => $actorUserId,
            'posted_at' => now(),
        ]);
        
        // Update PO status
        if ($grn->purchase_order_id) {
            $this->updatePoStatus($grn->purchase_order_id);
        }
        
        AuditLog::record('goods_receipt.posted', $grn, $actorUserId);
    });
}
```

---

### 8.4 Inventory & Serial Tracking

**InventoryBalanceService:**
```php
// Always call this after any stock movement, inside the same transaction
public function recalculate(int $productId, int $locationId): void
{
    $onHand = SerialItem::where('product_id', $productId)
        ->where('current_location_id', $locationId)
        ->whereIn('status', ['in_stock', 'reserved'])
        ->count();
        
    $reserved = SerialItem::where('product_id', $productId)
        ->where('current_location_id', $locationId)
        ->where('status', 'reserved')
        ->count();
    
    InventoryBalance::updateOrCreate(
        ['product_id' => $productId, 'location_id' => $locationId],
        [
            'on_hand_qty' => $onHand,
            'reserved_qty' => $reserved,
            'available_qty' => $onHand - $reserved,
        ]
    );
}
```

**Serial status state machine:**
```
draft → in_stock           (on GRN post)
in_stock → reserved        (on invoice creation, before post)
in_stock → in_transfer     (on transfer issue)
reserved → sold            (on invoice post)
reserved → in_stock        (on invoice cancellation)
in_stock → damaged         (on manual adjustment)
sold → returned            (on approved return, resolution: restock)
returned → in_stock        (when return restocked)
in_stock/returned → under_service   (on service job intake)
under_service → in_stock   (on service job completion)
in_transfer → in_stock     (on transfer receipt)
```

**Serial search endpoint (JSON):**
```
GET /api/serials/lookup?q={query}&location_id={id}&status=in_stock
```
Returns: `[{ id, serial_number, product_name, status, location_name }]`
Used by barcode scanner and serial picker modal in sales UI.

---

### 8.5 Stock Transfers

**Flow:** draft → approved → issued → received

**TransferIssueService:**
```php
public function issue(StockTransfer $transfer, array $serialIds, int $actorUserId): void
{
    DB::transaction(function () use ($transfer, $serialIds, $actorUserId) {
        if ($transfer->status !== 'approved') {
            throw new InvalidStateException('Transfer must be approved before issuing.');
        }
        
        foreach ($serialIds as $serialId) {
            $serial = SerialItem::lockForUpdate()->findOrFail($serialId);
            
            // Validate: serial must be in source location and in_stock
            if ($serial->current_location_id !== $transfer->source_location_id) {
                throw new SerialLocationMismatchException($serial->serial_number);
            }
            if ($serial->status !== 'in_stock') {
                throw new SerialNotAvailableException($serial->serial_number);
            }
            
            $serial->update(['status' => 'in_transfer']);
            
            StockLedger::create([
                'event_time' => now(),
                'product_id' => $serial->product_id,
                'serial_item_id' => $serial->id,
                'location_id' => $transfer->source_location_id,
                'movement_type' => 'transfer_issue',
                'quantity_delta' => -1,
                'reference_type' => 'StockTransfer',
                'reference_id' => $transfer->id,
                'actor_user_id' => $actorUserId,
            ]);
            
            $this->inventoryBalanceService->recalculate(
                $serial->product_id, $transfer->source_location_id
            );
        }
        
        $transfer->update([
            'status' => 'issued',
            'issued_by' => $actorUserId,
            'issued_at' => now(),
        ]);
    });
}
```

**TransferReceiveService:**
```php
public function receive(StockTransfer $transfer, array $serialIds, int $actorUserId): void
{
    DB::transaction(function () use ($transfer, $serialIds, $actorUserId) {
        if ($transfer->status !== 'issued') {
            throw new InvalidStateException('Transfer must be issued before receiving.');
        }
        
        foreach ($serialIds as $serialId) {
            $serial = SerialItem::lockForUpdate()->findOrFail($serialId);
            
            if ($serial->status !== 'in_transfer') {
                throw new SerialNotInTransitException($serial->serial_number);
            }
            
            $serial->update([
                'status' => 'in_stock',
                'current_location_id' => $transfer->destination_location_id,
            ]);
            
            StockLedger::create([
                'event_time' => now(),
                'product_id' => $serial->product_id,
                'serial_item_id' => $serial->id,
                'location_id' => $transfer->destination_location_id,
                'movement_type' => 'transfer_receive',
                'quantity_delta' => 1,
                'reference_type' => 'StockTransfer',
                'reference_id' => $transfer->id,
                'actor_user_id' => $actorUserId,
            ]);
            
            $this->inventoryBalanceService->recalculate(
                $serial->product_id, $transfer->destination_location_id
            );
        }
        
        $transfer->update([
            'status' => 'received',
            'received_by' => $actorUserId,
            'received_at' => now(),
        ]);
    });
}
```

---

### 8.6 Sales & Invoicing

**Sale types:**
- `cash`: Full payment at time of sale (or recorded shortly after)
- `credit`: Sold on account; full amount added to AR ledger; customer pays later
- `installment`: Down payment collected; installment plan created for the remainder

**SalesPostingService (complete atomic transaction):**
```php
public function post(SalesInvoice $invoice, int $actorUserId): void
{
    DB::transaction(function () use ($invoice, $actorUserId) {
        // 1. Verify status
        if ($invoice->status !== 'draft') {
            throw new InvalidStateException('Invoice already posted.');
        }
        
        // 2. Lock and validate all serials
        foreach ($invoice->lines as $line) {
            foreach ($line->saleLineSerials as $sls) {
                $serial = SerialItem::lockForUpdate()->findOrFail($sls->serial_item_id);
                
                if ($serial->current_location_id !== $invoice->branch_id) {
                    throw new SerialNotAtBranchException($serial->serial_number);
                }
                if (!in_array($serial->status, ['in_stock', 'reserved'])) {
                    throw new SerialNotAvailableException($serial->serial_number);
                }
            }
        }
        
        // 3. Lock inventory balance rows
        InventoryBalance::where('location_id', $invoice->branch_id)
            ->lockForUpdate()
            ->get();
        
        // 4. Validate serial count matches invoice line qty
        foreach ($invoice->lines as $line) {
            if ($line->saleLineSerials->count() !== $line->qty) {
                throw new SerialCountMismatchException(
                    "Line {$line->id}: expected {$line->qty} serials, got {$line->saleLineSerials->count()}"
                );
            }
        }
        
        // 5. Update serials and write ledger
        foreach ($invoice->lines as $line) {
            foreach ($line->saleLineSerials as $sls) {
                $serial = SerialItem::find($sls->serial_item_id);
                
                $warrantyStart = now()->toDateString();
                $warrantyEnd = now()->addMonths($line->warranty_months_snapshot)->toDateString();
                
                $serial->update([
                    'status' => 'sold',
                    'sale_invoice_line_id' => $line->id,
                    'warranty_start_date' => $warrantyStart,
                    'warranty_end_date' => $warrantyEnd,
                ]);
                
                StockLedger::create([
                    'event_time' => now(),
                    'product_id' => $line->product_id,
                    'serial_item_id' => $serial->id,
                    'location_id' => $invoice->branch_id,
                    'movement_type' => 'sale_issue',
                    'quantity_delta' => -1,
                    'unit_cost' => $serial->purchase_cost,
                    'reference_type' => 'SalesInvoice',
                    'reference_id' => $invoice->id,
                    'actor_user_id' => $actorUserId,
                ]);
            }
            
            $this->inventoryBalanceService->recalculate(
                $line->product_id, $invoice->branch_id
            );
        }
        
        // 6. Update invoice status
        $invoice->update([
            'status' => 'posted',
            'posted_by' => $actorUserId,
            'posted_at' => now(),
            'amount_due' => $invoice->grand_total,
        ]);
        
        // 7. Write AR ledger entry
        AccountsReceivableLedger::create([
            'customer_id' => $invoice->customer_id,
            'event_time' => now(),
            'event_type' => 'invoice_posted',
            'amount_delta' => $invoice->grand_total,
            'reference_type' => 'SalesInvoice',
            'reference_id' => $invoice->id,
            'actor_user_id' => $actorUserId,
        ]);
        
        // 8. Update customer outstanding balance
        Customer::where('id', $invoice->customer_id)
            ->increment('outstanding_balance', $invoice->grand_total);
        
        // 9. Handle initial payment if cash sale with payment data
        if ($invoice->sale_type === 'cash' && request()->has('payment')) {
            $this->paymentService->recordAndAllocate($invoice, request('payment'), $actorUserId);
        }
        
        // 10. Create installment plan if installment sale
        if ($invoice->sale_type === 'installment') {
            $this->installmentPlanService->createFromInvoice(
                $invoice, request('installment_plan'), $actorUserId
            );
        }
        
        // 11. Dispatch PDF generation job
        GenerateInvoicePdf::dispatch($invoice->id);
        
        AuditLog::record('invoice.posted', $invoice, $actorUserId);
    });
}
```

**Invoice number generation:**
```php
// Format: INV-{BRANCH_CODE}-{YYYYMMDD}-{SEQUENCE}
// Example: INV-BR01-20250115-0042
// Use DB advisory lock or atomic sequence table to prevent duplicates
public function generateInvoiceNumber(int $branchId): string
{
    return DB::transaction(function () use ($branchId) {
        $branch = Location::lockForUpdate()->find($branchId);
        $date = now()->format('Ymd');
        $prefix = "INV-{$branch->code}-{$date}-";
        
        $last = SalesInvoice::where('invoice_number', 'like', $prefix . '%')
            ->orderByDesc('invoice_number')
            ->lockForUpdate()
            ->value('invoice_number');
        
        $seq = $last ? (intval(substr($last, -4)) + 1) : 1;
        return $prefix . str_pad($seq, 4, '0', STR_PAD_LEFT);
    });
}
```

**Discount rules:**
- Sales staff can apply discount up to configured `max_discount_percent` (config value, default 5%)
- Discounts above threshold require branch_manager or admin approval
- Discount is recorded per line; `discount_total` on invoice is the sum
- Free approval: any discount on defective/returned items approved by branch_manager

---

### 8.7 Payments & Receivables

**PaymentService:**
```php
public function recordAndAllocate(
    SalesInvoice $invoice,
    array $paymentData,
    int $actorUserId
): Payment {
    return DB::transaction(function () use ($invoice, $paymentData, $actorUserId) {
        $invoice = SalesInvoice::lockForUpdate()->find($invoice->id);
        
        if ($paymentData['amount'] > $invoice->amount_due) {
            throw new OverpaymentException('Payment exceeds amount due.');
        }
        
        $payment = Payment::create([
            'payment_number' => $this->generatePaymentNumber(),
            'customer_id' => $invoice->customer_id,
            'payment_date' => $paymentData['date'] ?? today(),
            'payment_method' => $paymentData['method'],
            'amount' => $paymentData['amount'],
            'direction' => 'inbound',
            'reference_no' => $paymentData['reference'] ?? null,
            'recorded_by' => $actorUserId,
        ]);
        
        PaymentAllocation::create([
            'payment_id' => $payment->id,
            'sales_invoice_id' => $invoice->id,
            'allocated_amount' => $paymentData['amount'],
        ]);
        
        $newAmountPaid = $invoice->amount_paid + $paymentData['amount'];
        $newAmountDue = $invoice->grand_total - $newAmountPaid;
        
        $invoice->update([
            'amount_paid' => $newAmountPaid,
            'amount_due' => $newAmountDue,
            'status' => $newAmountDue <= 0 ? 'paid' : 'partially_paid',
        ]);
        
        // AR ledger
        AccountsReceivableLedger::create([
            'customer_id' => $invoice->customer_id,
            'event_time' => now(),
            'event_type' => 'payment_allocated',
            'amount_delta' => -$paymentData['amount'],
            'reference_type' => 'Payment',
            'reference_id' => $payment->id,
            'actor_user_id' => $actorUserId,
        ]);
        
        Customer::where('id', $invoice->customer_id)
            ->decrement('outstanding_balance', $paymentData['amount']);
        
        return $payment;
    });
}
```

---

### 8.8 Installment Engine

**InstallmentPlanService:**
```php
public function createFromInvoice(
    SalesInvoice $invoice,
    array $planData,
    int $actorUserId
): InstallmentPlan {
    // planData shape:
    // {
    //   down_payment: 5000,
    //   penalty_enabled: true,
    //   schedules: [
    //     { due_date: '2025-02-01', amount: 3000 },
    //     { due_date: '2025-03-01', amount: 3000 },
    //     ...
    //   ]
    // }
    
    $financed = $invoice->grand_total - $planData['down_payment'];
    $scheduleTotal = collect($planData['schedules'])->sum('amount');
    
    if (abs($scheduleTotal - $financed) > 0.01) {
        throw new InstallmentScheduleTotalMismatchException(
            "Schedule total {$scheduleTotal} does not match financed amount {$financed}"
        );
    }
    
    $plan = InstallmentPlan::create([
        'sales_invoice_id' => $invoice->id,
        'customer_id' => $invoice->customer_id,
        'total_amount' => $invoice->grand_total,
        'down_payment' => $planData['down_payment'],
        'financed_amount' => $financed,
        'remaining_amount' => $financed,
        'penalty_enabled' => $planData['penalty_enabled'] ?? false,
        'created_by' => $actorUserId,
    ]);
    
    foreach ($planData['schedules'] as $i => $schedule) {
        InstallmentSchedule::create([
            'installment_plan_id' => $plan->id,
            'installment_no' => $i + 1,
            'due_date' => $schedule['due_date'],
            'due_amount' => $schedule['amount'],
        ]);
    }
    
    // Record down payment
    if ($planData['down_payment'] > 0) {
        $this->paymentService->recordAndAllocate($invoice, [
            'amount' => $planData['down_payment'],
            'method' => $planData['down_payment_method'] ?? 'cash',
            'date' => today()->toDateString(),
        ], $actorUserId);
    }
    
    return $plan;
}
```

**InstallmentAllocationService:**
```php
public function allocatePayment(
    InstallmentPlan $plan,
    Payment $payment,
    int $actorUserId
): void {
    // Allocates to earliest overdue first, then pending, within due_date order
    DB::transaction(function () use ($plan, $payment, $actorUserId) {
        $plan = InstallmentPlan::lockForUpdate()->find($plan->id);
        $remaining = $payment->amount;
        
        $schedules = InstallmentSchedule::where('installment_plan_id', $plan->id)
            ->whereIn('status', ['pending', 'partially_paid', 'overdue'])
            ->orderBy('due_date')
            ->lockForUpdate()
            ->get();
        
        foreach ($schedules as $schedule) {
            if ($remaining <= 0) break;
            
            $owed = $schedule->due_amount + $schedule->late_fee_amount - $schedule->paid_amount;
            $toAllocate = min($remaining, $owed);
            
            InstallmentPaymentAllocation::create([
                'installment_schedule_id' => $schedule->id,
                'payment_id' => $payment->id,
                'allocated_amount' => $toAllocate,
            ]);
            
            $schedule->increment('paid_amount', $toAllocate);
            $newPaid = $schedule->paid_amount + $toAllocate;
            $newStatus = ($newPaid >= $schedule->due_amount + $schedule->late_fee_amount)
                ? 'paid' : 'partially_paid';
            $schedule->update(['status' => $newStatus]);
            
            $remaining -= $toAllocate;
        }
        
        // Update plan remaining
        $plan->decrement('remaining_amount', $payment->amount - $remaining);
        
        // Check if plan complete
        if ($plan->fresh()->remaining_amount <= 0.01) {
            $plan->update(['status' => 'completed']);
        }
    });
}

public function markOverdue(): void
{
    // Called by scheduled job daily
    InstallmentSchedule::where('status', 'pending')
        ->where('due_date', '<', today())
        ->update(['status' => 'overdue']);
    
    // Apply late fees if enabled
    $overdueWithPenalty = InstallmentSchedule::where('status', 'overdue')
        ->whereHas('installmentPlan', fn($q) => $q->where('penalty_enabled', true))
        ->where('late_fee_amount', 0) // not yet charged
        ->get();
    
    foreach ($overdueWithPenalty as $schedule) {
        $fee = $schedule->due_amount * config('inventory.installment_late_fee_percent');
        $schedule->update(['late_fee_amount' => $fee]);
        
        AccountsReceivableLedger::create([
            'customer_id' => $schedule->installmentPlan->customer_id,
            'event_time' => now(),
            'event_type' => 'late_fee',
            'amount_delta' => $fee,
            'reference_type' => 'InstallmentSchedule',
            'reference_id' => $schedule->id,
            'actor_user_id' => 1, // system user
        ]);
    }
}
```

**Reschedule flow:**
```php
public function reschedule(
    InstallmentPlan $plan,
    array $newSchedules,
    string $reason,
    int $approverId
): void {
    DB::transaction(function () use ($plan, $newSchedules, $reason, $approverId) {
        // Snapshot current schedule
        $snapshot = $plan->schedules->toArray();
        
        // Mark old pending/overdue schedules as rescheduled
        $plan->schedules()
            ->whereIn('status', ['pending', 'overdue'])
            ->update(['status' => 'rescheduled']);
        
        // Create reschedule record
        InstallmentReschedule::create([
            'installment_plan_id' => $plan->id,
            'previous_schedule_snapshot' => $snapshot,
            'reason' => $reason,
            'approved_by' => $approverId,
            'created_by' => $approverId,
        ]);
        
        // Create new schedules
        $startNo = $plan->schedules()->max('installment_no') + 1;
        foreach ($newSchedules as $i => $s) {
            InstallmentSchedule::create([
                'installment_plan_id' => $plan->id,
                'installment_no' => $startNo + $i,
                'due_date' => $s['due_date'],
                'due_amount' => $s['amount'],
            ]);
        }
        
        $plan->increment('reschedule_count');
    });
}
```

---

### 8.9 Returns, Warranty & Service

**ReturnProcessingService:**
```php
public function approve(ReturnOrder $return, int $actorUserId): void
{
    DB::transaction(function () use ($return, $actorUserId) {
        foreach ($return->lines as $line) {
            $serial = SerialItem::lockForUpdate()->find($line->serial_item_id);
            
            if ($serial->status !== 'sold') {
                throw new InvalidStateException("Serial {$serial->serial_number} is not in sold state.");
            }
            
            match ($line->resolution) {
                'restock' => $this->handleRestock($serial, $return, $line, $actorUserId),
                'repair'  => $this->handleRepair($serial, $return, $actorUserId),
                'replace' => $this->handleReplace($serial, $return, $actorUserId),
                'scrap'   => $this->handleScrap($serial, $return, $actorUserId),
            };
        }
        
        $return->update([
            'status' => 'approved',
            'approved_by' => $actorUserId,
            'approved_at' => now(),
        ]);
        
        // Reverse AR entry if full return
        // (partial returns: credit note for partial amount)
        $this->reverseArEntry($return, $actorUserId);
    });
}

private function handleRestock(SerialItem $serial, ReturnOrder $return, ReturnLine $line, int $actorUserId): void
{
    $serial->update([
        'status' => 'in_stock',
        'current_location_id' => $return->branch_id,
        'sale_invoice_line_id' => null,
        'warranty_start_date' => null,
        'warranty_end_date' => null,
    ]);
    
    StockLedger::create([
        'event_time' => now(),
        'product_id' => $serial->product_id,
        'serial_item_id' => $serial->id,
        'location_id' => $return->branch_id,
        'movement_type' => 'return_receive',
        'quantity_delta' => 1,
        'reference_type' => 'ReturnOrder',
        'reference_id' => $return->id,
        'actor_user_id' => $actorUserId,
    ]);
    
    $this->inventoryBalanceService->recalculate($serial->product_id, $return->branch_id);
    $return->update(['status' => 'restocked']);
}
```

**WarrantyValidationService:**
```php
public function check(string $serialNumber): array
{
    $serial = SerialItem::where('serial_number', $serialNumber)
        ->with('product')
        ->firstOrFail();
    
    if (!$serial->warranty_end_date) {
        return ['valid' => false, 'reason' => 'No warranty record found'];
    }
    
    $inWarranty = now()->lte(Carbon::parse($serial->warranty_end_date));
    
    return [
        'valid' => $inWarranty,
        'serial_number' => $serial->serial_number,
        'product' => $serial->product->name,
        'warranty_start' => $serial->warranty_start_date,
        'warranty_end' => $serial->warranty_end_date,
        'days_remaining' => $inWarranty ? now()->diffInDays($serial->warranty_end_date) : 0,
        'status' => $serial->status,
    ];
}
```

---

### 8.10 Reporting & Exports

**Available reports:**

| Report | Route | Description |
|---|---|---|
| Stock by location | `GET /reports/stock-by-location` | Current on-hand qty per product per location |
| Low stock alert | `GET /reports/low-stock` | Products below reorder level |
| Sales summary | `GET /reports/sales-summary` | Sales by branch, date range, product |
| Customer dues | `GET /reports/customer-dues` | Outstanding balances per customer |
| Installment overdue | `GET /reports/installment-overdue` | Overdue schedules with customer contact |
| Stock movement | `GET /reports/stock-movement` | Full ledger for a product/location/period |
| Profit & gross margin | `GET /reports/gross-margin` | Revenue minus purchase cost by product |
| Supplier purchases | `GET /reports/supplier-purchases` | PO history, received amounts, outstanding |
| Warranty expiry | `GET /reports/warranty-expiry` | Serials expiring in next N days |
| Installment collection | `GET /reports/installment-collection` | Scheduled vs actual collections by month |

**Export jobs (async):**
```php
// ExportStockReport job
public function handle(): void
{
    $data = InventoryBalance::with(['product.brand', 'product.category', 'location'])
        ->get()
        ->map(fn($b) => [
            'SKU' => $b->product->sku,
            'Product' => $b->product->name,
            'Brand' => $b->product->brand->name,
            'Category' => $b->product->category->name,
            'Location' => $b->location->name,
            'On Hand' => $b->on_hand_qty,
            'Reserved' => $b->reserved_qty,
            'Available' => $b->available_qty,
        ]);
    
    $filename = 'stock-report-' . now()->format('Ymd-His') . '.xlsx';
    Excel::store(new CollectionExport($data), $filename, 's3');
    
    // Notify the user who requested the export
    $this->user->notify(new ExportReadyNotification(
        Storage::disk('s3')->temporaryUrl($filename, now()->addHours(24))
    ));
}
```

---

### 8.11 Notifications

**Notification triggers:**
| Event | Channels | Recipients |
|---|---|---|
| Invoice posted | Email | Customer (receipt) |
| Payment received | SMS + Email | Customer |
| Installment due in 3 days | SMS | Customer |
| Installment overdue | SMS + Email | Customer + Branch Manager |
| Low stock | Email | Branch Manager + Admin |
| Transfer approved | In-app + Email | Warehouse |
| Service job ready | SMS | Customer |
| Warranty expiry warning (30 days) | Email | Customer |

**SMS driver abstraction (`config/sms.php`):**
```php
// Implement SmsDriverInterface with send(string $to, string $message): void
// Drivers: Vonage, Twilio, LocalGateway (for BD operators)
```

---

### 8.12 Demand Forecasting

**DemandForecastService:**
```php
public function refreshForecasts(): void
{
    // 3-month weighted moving average per product per branch
    $products = Product::where('is_active', true)->pluck('id');
    $locations = Location::where('type', 'branch')->where('is_active', true)->pluck('id');
    
    foreach ($products as $productId) {
        foreach ($locations as $locationId) {
            $monthlySales = $this->getMonthlySales($productId, $locationId, 3);
            
            if ($monthlySales->isEmpty()) continue;
            
            // Weighted average: most recent month = 3x weight, previous = 2x, oldest = 1x
            $weights = [3, 2, 1];
            $weighted = 0;
            $totalWeight = 0;
            foreach ($monthlySales->values() as $i => $qty) {
                $w = $weights[$i] ?? 1;
                $weighted += $qty * $w;
                $totalWeight += $w;
            }
            
            $predicted = $totalWeight > 0 ? round($weighted / $totalWeight, 1) : 0;
            
            DemandForecast::updateOrCreate(
                [
                    'product_id' => $productId,
                    'location_id' => $locationId,
                    'forecast_month' => now()->addMonth()->startOfMonth()->toDateString(),
                ],
                [
                    'predicted_qty' => $predicted,
                    'algorithm' => 'moving_average_3m',
                    'generated_at' => now(),
                ]
            );
        }
    }
}
```

---

## 9. Service Layer (Full Logic)

### Service registration (AppServiceProvider)
```php
$this->app->bind(SalesPostingService::class, fn($app) => new SalesPostingService(
    $app->make(InventoryBalanceService::class),
    $app->make(InstallmentPlanService::class),
    $app->make(PaymentService::class),
));
// ... bind all services
```

### Exception classes to create
```
app/Exceptions/
├── InvalidStateException.php
├── SerialCountMismatchException.php
├── SerialAlreadyProcessedException.php
├── SerialNotAvailableException.php
├── SerialNotAtBranchException.php
├── SerialLocationMismatchException.php
├── SerialNotInTransitException.php
├── OverpaymentException.php
├── InstallmentScheduleTotalMismatchException.php
├── CreditLimitExceededException.php
└── InsufficientStockException.php
```

All exceptions extend `RuntimeException`. Handler renders JSON for `/api/*` routes and a Blade error page otherwise.

---

## 10. Route Definitions

```php
// routes/web.php

Route::middleware(['auth', 'active-user'])->group(function () {

    // Dashboard
    Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');

    // Master data
    Route::resource('products', ProductController::class);
    Route::post('products/{product}/toggle-active', [ProductController::class, 'toggleActive']);
    Route::get('products/{product}/barcode', [ProductController::class, 'barcode']);
    Route::resource('brands', BrandController::class);
    Route::resource('categories', CategoryController::class);
    Route::resource('locations', LocationController::class)->middleware('can:manage-users');
    Route::resource('users', UserController::class)->middleware('can:manage-users');
    Route::resource('suppliers', SupplierController::class);
    Route::resource('customers', CustomerController::class);

    // Purchase orders
    Route::resource('purchase-orders', PurchaseOrderController::class);
    Route::post('purchase-orders/{po}/approve', [PurchaseOrderController::class, 'approve']);
    Route::post('purchase-orders/{po}/cancel', [PurchaseOrderController::class, 'cancel']);

    // Goods receipts
    Route::resource('goods-receipts', GoodsReceiptController::class);
    Route::post('goods-receipts/{grn}/post', [GoodsReceiptController::class, 'post']);
    Route::post('goods-receipts/{grn}/cancel', [GoodsReceiptController::class, 'cancel']);

    // Serial items
    Route::get('serials', [SerialItemController::class, 'index']);
    Route::get('serials/{serial}', [SerialItemController::class, 'show']);
    Route::post('serials/{serial}/adjust', [SerialItemController::class, 'adjust'])
        ->middleware('can:manage-users');

    // Stock transfers
    Route::resource('transfers', StockTransferController::class);
    Route::post('transfers/{transfer}/approve', [StockTransferController::class, 'approve'])
        ->middleware('can:approve-transfers');
    Route::post('transfers/{transfer}/issue', [StockTransferController::class, 'issue']);
    Route::post('transfers/{transfer}/receive', [StockTransferController::class, 'receive']);
    Route::post('transfers/{transfer}/cancel', [StockTransferController::class, 'cancel']);

    // Sales invoices
    Route::resource('sales/invoices', SalesInvoiceController::class);
    Route::post('sales/invoices/{invoice}/post', [SalesInvoiceController::class, 'post']);
    Route::post('sales/invoices/{invoice}/cancel', [SalesInvoiceController::class, 'cancel']);
    Route::get('sales/invoices/{invoice}/print', [SalesInvoiceController::class, 'print']);
    Route::post('sales/invoices/{invoice}/payments', [PaymentController::class, 'store']);

    // Installments
    Route::post('sales/invoices/{invoice}/installment-plan', [InstallmentPlanController::class, 'store']);
    Route::get('installments/{plan}', [InstallmentPlanController::class, 'show']);
    Route::post('installments/{plan}/pay', [InstallmentPlanController::class, 'pay']);
    Route::post('installments/{plan}/reschedule', [InstallmentPlanController::class, 'reschedule'])
        ->middleware('can:reschedule-installment');

    // Returns
    Route::resource('returns', ReturnController::class);
    Route::post('returns/{return}/approve', [ReturnController::class, 'approve'])
        ->middleware('can:approve-returns');
    Route::post('returns/{return}/reject', [ReturnController::class, 'reject']);

    // Service jobs
    Route::resource('service-jobs', ServiceJobController::class);
    Route::post('service-jobs/{job}/update-status', [ServiceJobController::class, 'updateStatus']);

    // Price overrides
    Route::resource('price-overrides', PriceOverrideController::class);
    Route::post('price-overrides/{override}/approve', [PriceOverrideController::class, 'approve'])
        ->middleware('can:approve-price-override');

    // Reports
    Route::prefix('reports')->name('reports.')->group(function () {
        Route::get('stock-by-location', [ReportController::class, 'stockByLocation'])->name('stock-by-location');
        Route::get('low-stock', [ReportController::class, 'lowStock'])->name('low-stock');
        Route::get('sales-summary', [ReportController::class, 'salesSummary'])->name('sales-summary');
        Route::get('customer-dues', [ReportController::class, 'customerDues'])->name('customer-dues');
        Route::get('installment-overdue', [ReportController::class, 'installmentOverdue'])->name('installment-overdue');
        Route::get('stock-movement', [ReportController::class, 'stockMovement'])->name('stock-movement');
        Route::get('gross-margin', [ReportController::class, 'grossMargin'])->name('gross-margin');
        Route::get('warranty-expiry', [ReportController::class, 'warrantyExpiry'])->name('warranty-expiry');
        
        // Async export (returns job ID)
        Route::post('export/{type}', [ReportController::class, 'export'])->name('export')
            ->middleware('can:export-reports');
    });
});

// JSON API endpoints for Alpine.js components
Route::middleware(['auth', 'active-user'])->prefix('api')->group(function () {
    Route::get('serials/lookup', [SerialItemController::class, 'lookup']);
    Route::get('products/search', [ProductController::class, 'search']);
    Route::get('customers/search', [CustomerController::class, 'search']);
    Route::get('inventory/{product}/{location}', [InventoryController::class, 'show']);
    Route::get('installment-plans/{plan}/schedules', [InstallmentPlanController::class, 'schedules']);
    Route::get('exports/{jobId}/status', [ReportController::class, 'exportStatus']);
    Route::get('warranty/check/{serial}', [WarrantyController::class, 'check']);
});
```

---

## 11. Request Validation Rules

### CreateInvoiceRequest
```php
public function rules(): array
{
    return [
        'customer_id' => 'required|exists:customers,id',
        'invoice_date' => 'required|date',
        'sale_type' => 'required|in:cash,credit,installment',
        'lines' => 'required|array|min:1',
        'lines.*.product_id' => 'required|exists:products,id',
        'lines.*.qty' => 'required|integer|min:1',
        'lines.*.unit_price' => 'required|numeric|min:0',
        'lines.*.discount_amount' => 'nullable|numeric|min:0',
        'lines.*.serial_ids' => 'required|array',
        'lines.*.serial_ids.*' => [
            'required',
            'exists:serial_items,id',
            Rule::unique('sale_line_serials', 'serial_item_id'), // not already on an invoice
            function ($attribute, $value, $fail) {
                $serial = SerialItem::find($value);
                if (!$serial || !in_array($serial->status, ['in_stock', 'reserved'])) {
                    $fail("Serial {$value} is not available.");
                }
                // Branch check happens in the service
            }
        ],
        // Validate serial count matches qty
        'lines.*' => [function ($attribute, $value, $fail) {
            if (count($value['serial_ids'] ?? []) !== $value['qty']) {
                $fail("Serial count must match quantity for each line.");
            }
        }],
    ];
}
```

### CreateInstallmentPlanRequest
```php
public function rules(): array
{
    return [
        'down_payment' => 'required|numeric|min:0',
        'penalty_enabled' => 'boolean',
        'down_payment_method' => 'required_if:down_payment,>,0|in:cash,card,bank_transfer,mobile_money',
        'schedules' => 'required|array|min:1',
        'schedules.*.due_date' => 'required|date|after:today',
        'schedules.*.amount' => 'required|numeric|min:0.01',
    ];
}

public function withValidator(Validator $validator): void
{
    $validator->after(function ($validator) {
        $invoice = $this->route('invoice');
        $financed = $invoice->grand_total - $this->down_payment;
        $scheduleTotal = collect($this->schedules)->sum('amount');
        
        if (abs($scheduleTotal - $financed) > 0.01) {
            $validator->errors()->add('schedules', 
                "Schedule total ({$scheduleTotal}) must equal financed amount ({$financed})."
            );
        }
        
        // Validate dates are in order
        $dates = collect($this->schedules)->pluck('due_date')->sort()->values();
        $origDates = collect($this->schedules)->pluck('due_date')->values();
        if ($dates->toArray() !== $origDates->toArray()) {
            $validator->errors()->add('schedules', 'Schedule due dates must be in ascending order.');
        }
    });
}
```

### Other key validation rules (summary)

| Field | Rule |
|---|---|
| `serial_number` (on GRN) | `unique:serial_items,serial_number` |
| `payment.amount` | `max:{invoice.amount_due}` (checked in service) |
| `transfer.source_location_id` | Must not equal `destination_location_id` |
| `price_override.override_price` | Must not be below `(default_sale_price * min_margin)` |
| `customer credit` | `outstanding_balance + invoice.grand_total <= credit_limit` |

---

## 12. Blade + Alpine UI Patterns

### Main Layout (`layouts/app.blade.php`)
```html
<!DOCTYPE html>
<html lang="en" x-data="{ sidebarOpen: false }">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>@yield('title') — ApplianceRetail</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="bg-gray-50">
    <!-- Mobile sidebar toggle -->
    <div class="lg:hidden fixed top-0 left-0 right-0 z-40 bg-white border-b px-4 py-3 flex items-center">
        <button @click="sidebarOpen = !sidebarOpen" class="p-2 rounded-lg">☰</button>
        <span class="ml-3 font-semibold">ApplianceRetail</span>
    </div>
    
    <!-- Sidebar -->
    <aside class="fixed inset-y-0 left-0 w-64 bg-gray-900 text-white transform transition-transform duration-200 z-50"
           :class="sidebarOpen ? 'translate-x-0' : '-translate-x-full lg:translate-x-0'">
        @include('layouts.partials.sidebar')
    </aside>
    
    <!-- Main content -->
    <main class="lg:ml-64 pt-16 lg:pt-0 min-h-screen">
        <div class="p-4 lg:p-6">
            @yield('content')
        </div>
    </main>
</body>
</html>
```

### Invoice Line Builder (Alpine component)
```html
<!-- resources/views/sales/partials/line-builder.blade.php -->
<div x-data="invoiceBuilder({{ json_encode($products) }})" class="space-y-4">
    
    <!-- Search & add product -->
    <div class="flex gap-2">
        <input type="text" 
               x-model="search"
               @input.debounce.300="searchProducts()"
               placeholder="Search product or scan barcode..."
               class="flex-1 border rounded-lg px-4 py-3 text-base">
        <button @click="addLine(selectedProduct)"
                :disabled="!selectedProduct"
                class="bg-blue-600 text-white px-6 py-3 rounded-lg disabled:opacity-50">
            Add
        </button>
    </div>
    
    <!-- Product dropdown -->
    <div x-show="results.length" class="border rounded-lg bg-white shadow-lg">
        <template x-for="product in results" :key="product.id">
            <div @click="selectProduct(product)"
                 class="px-4 py-3 hover:bg-gray-50 cursor-pointer border-b last:border-0">
                <div class="font-medium" x-text="product.name"></div>
                <div class="text-sm text-gray-500">
                    <span x-text="product.sku"></span> · 
                    Stock: <span x-text="product.available_qty"></span>
                </div>
            </div>
        </template>
    </div>
    
    <!-- Lines table -->
    <template x-for="(line, index) in lines" :key="line.tempId">
        <div class="border rounded-lg p-4 bg-white">
            <div class="flex justify-between items-start mb-3">
                <div class="font-medium" x-text="line.product_name"></div>
                <button @click="removeLine(index)" class="text-red-500 text-sm">Remove</button>
            </div>
            
            <div class="grid grid-cols-2 gap-3 lg:grid-cols-4">
                <div>
                    <label class="text-sm text-gray-600">Qty</label>
                    <input type="number" x-model.number="line.qty" min="1"
                           @change="updateSerialSlots(line)"
                           class="w-full border rounded px-3 py-2 mt-1">
                </div>
                <div>
                    <label class="text-sm text-gray-600">Unit Price</label>
                    <input type="number" x-model.number="line.unit_price" step="0.01"
                           @input="recalculate(line)"
                           class="w-full border rounded px-3 py-2 mt-1">
                </div>
                <div>
                    <label class="text-sm text-gray-600">Discount</label>
                    <input type="number" x-model.number="line.discount" step="0.01"
                           @input="recalculate(line)"
                           class="w-full border rounded px-3 py-2 mt-1">
                </div>
                <div>
                    <label class="text-sm text-gray-600">Line Total</label>
                    <div class="border rounded px-3 py-2 mt-1 bg-gray-50 font-semibold"
                         x-text="formatCurrency(line.line_total)"></div>
                </div>
            </div>
            
            <!-- Serial number slots -->
            <div class="mt-3">
                <label class="text-sm font-medium text-gray-700">Serial Numbers</label>
                <template x-for="i in line.qty" :key="i">
                    <div class="flex gap-2 mt-2">
                        <input type="text"
                               :placeholder="'Serial ' + i"
                               @keyup.enter="lookupSerial($event.target.value, line, i-1)"
                               @blur="lookupSerial($event.target.value, line, i-1)"
                               class="flex-1 border rounded px-3 py-2 font-mono text-sm"
                               :class="line.serials[i-1]?.valid ? 'border-green-400' : ''">
                        <span x-show="line.serials[i-1]?.valid"
                              class="text-green-500 flex items-center">✓</span>
                    </div>
                </template>
            </div>
            
            <!-- Hidden inputs for form submission -->
            <template x-for="(serial, si) in line.serials" :key="si">
                <input type="hidden" :name="`lines[${index}][serial_ids][]`" :value="serial.id">
            </template>
            <input type="hidden" :name="`lines[${index}][product_id]`" :value="line.product_id">
            <input type="hidden" :name="`lines[${index}][qty]`" :value="line.qty">
            <input type="hidden" :name="`lines[${index}][unit_price]`" :value="line.unit_price">
            <input type="hidden" :name="`lines[${index}][discount_amount]`" :value="line.discount">
        </div>
    </template>
    
    <!-- Invoice totals -->
    <div class="border rounded-lg p-4 bg-gray-50">
        <div class="flex justify-between text-sm">
            <span>Subtotal</span>
            <span x-text="formatCurrency(subtotal)"></span>
        </div>
        <div class="flex justify-between text-sm mt-1">
            <span>Discount</span>
            <span x-text="formatCurrency(totalDiscount)"></span>
        </div>
        <div class="flex justify-between font-bold text-lg mt-2 pt-2 border-t">
            <span>Grand Total</span>
            <span x-text="formatCurrency(grandTotal)"></span>
        </div>
    </div>
</div>
```

### Installment Schedule Builder (Alpine component)
```html
<div x-data="installmentBuilder(@json(['grand_total' => $invoice->grand_total]))" class="space-y-4">
    <div class="grid grid-cols-2 gap-4">
        <div>
            <label class="text-sm font-medium">Down Payment</label>
            <input type="number" x-model.number="downPayment"
                   @input="recalculate()"
                   class="w-full border rounded px-3 py-2 mt-1">
        </div>
        <div>
            <label class="text-sm font-medium">Financed Amount</label>
            <div class="border rounded px-3 py-2 mt-1 bg-gray-50 font-semibold"
                 x-text="formatCurrency(financed)"></div>
        </div>
    </div>
    
    <div class="flex gap-2 items-center">
        <label class="text-sm font-medium">Number of installments</label>
        <input type="number" x-model.number="numInstallments"
               @input="generateEqualSchedule()"
               min="1" max="60" class="w-24 border rounded px-3 py-2">
        <button @click="generateEqualSchedule()" class="text-blue-600 text-sm underline">
            Auto-generate equal schedule
        </button>
    </div>
    
    <template x-for="(s, i) in schedules" :key="i">
        <div class="flex gap-3 items-center">
            <span class="text-sm w-6 text-gray-500" x-text="i+1"></span>
            <input type="date" x-model="s.due_date"
                   :min="minDate"
                   class="border rounded px-3 py-2 flex-1">
            <input type="number" x-model.number="s.amount"
                   step="0.01"
                   @input="recalculate()"
                   class="border rounded px-3 py-2 w-36">
            <button @click="removeSchedule(i)" class="text-red-400">×</button>
            
            <input type="hidden" :name="`schedules[${i}][due_date]`" :value="s.due_date">
            <input type="hidden" :name="`schedules[${i}][amount]`" :value="s.amount">
        </div>
    </template>
    
    <div class="flex justify-between text-sm p-3 rounded"
         :class="balanced ? 'bg-green-50 text-green-700' : 'bg-red-50 text-red-700'">
        <span>Schedule total: <strong x-text="formatCurrency(scheduleTotal)"></strong></span>
        <span>Financed: <strong x-text="formatCurrency(financed)"></strong></span>
        <span x-show="!balanced" class="font-semibold">⚠ Amounts don't balance</span>
        <span x-show="balanced" class="font-semibold">✓ Balanced</span>
    </div>
    
    <input type="hidden" name="down_payment" :value="downPayment">
</div>
```

---

## 13. Jobs & Queues

### Queue configuration
```php
// Use Redis with named queues
// config/queue.php
'connections' => [
    'redis' => [
        'driver' => 'redis',
        'queue' => 'default',
    ]
]

// Named queues in order of priority:
// critical: invoice posting, payment recording
// default: PDF generation, email
// exports: CSV/Excel exports (slow, bulky)
// notifications: SMS, push
```

### Scheduled commands (`Console/Kernel.php`)
```php
$schedule->command('installments:mark-overdue')->dailyAt('00:30');
$schedule->command('installments:send-reminders')->dailyAt('09:00');
$schedule->command('demand:refresh-forecasts')->weeklyOn(0, '02:00'); // Sunday 2am
$schedule->command('reports:snapshot-dashboard')->hourly();
$schedule->command('stock:alert-low')->dailyAt('08:00');
```

### ProcessOverdueInstallments Command
```php
public function handle(): void
{
    $this->info('Marking overdue installments...');
    app(InstallmentAllocationService::class)->markOverdue();
    $this->info('Done.');
}
```

---

## 14. Performance & Indexing

### Migration `033_add_indexes`
```php
// serial_items: most critical for sales and stock checks
Schema::table('serial_items', function (Blueprint $table) {
    $table->index(['product_id', 'current_location_id', 'status'], 'idx_serial_lookup');
    $table->index('serial_number', 'idx_serial_number');
    $table->index('status', 'idx_serial_status');
});

// sales_invoices
Schema::table('sales_invoices', function (Blueprint $table) {
    $table->index(['branch_id', 'invoice_date', 'status'], 'idx_invoice_branch_date');
    $table->index(['customer_id', 'status'], 'idx_invoice_customer');
});

// stock_ledger
Schema::table('stock_ledger', function (Blueprint $table) {
    $table->index(['location_id', 'product_id', 'event_time'], 'idx_ledger_lookup');
    $table->index(['reference_type', 'reference_id'], 'idx_ledger_ref');
});

// installment_schedules
Schema::table('installment_schedules', function (Blueprint $table) {
    $table->index(['installment_plan_id', 'due_date', 'status'], 'idx_schedule_lookup');
    $table->index(['due_date', 'status'], 'idx_schedule_overdue'); // for daily job
});

// payments
Schema::table('payments', function (Blueprint $table) {
    $table->index(['customer_id', 'payment_date'], 'idx_payment_customer');
});

// accounts_receivable_ledger
Schema::table('accounts_receivable_ledger', function (Blueprint $table) {
    $table->index(['customer_id', 'event_time'], 'idx_ar_customer');
});

// audit_logs
Schema::table('audit_logs', function (Blueprint $table) {
    $table->index(['entity_type', 'entity_id'], 'idx_audit_entity');
});
```

### Dashboard caching
```php
// DashboardController
public function index(): View
{
    $stats = Cache::remember('dashboard_stats_' . auth()->user()->getEffectiveBranchId(), 300, function () {
        return [
            'today_sales' => SalesInvoice::where('invoice_date', today())->where('status', 'posted')->sum('grand_total'),
            'overdue_installments' => InstallmentSchedule::where('status', 'overdue')->count(),
            'low_stock_count' => $this->getLowStockCount(),
            'pending_transfers' => StockTransfer::where('status', 'approved')->count(),
        ];
    });
    
    return view('dashboard.index', compact('stats'));
}
```

### MySQL configuration recommendations
```ini
# my.cnf
innodb_buffer_pool_size = 1G        # for medium scale
innodb_log_file_size = 256M
innodb_flush_log_at_trx_commit = 1  # required for transactional integrity
max_connections = 200
slow_query_log = 1
long_query_time = 1
```

---

## 15. External Integrations

### Barcode scanning (browser)
```javascript
// resources/js/alpine-components/barcode-handler.js
// Uses QuaggaJS for camera-based scanning
// Hardware scanner fires keypresses ending in Enter — no library needed

document.addEventListener('keypress', function(e) {
    if (e.key === 'Enter' && window._barcodeBuffer) {
        const barcode = window._barcodeBuffer;
        window._barcodeBuffer = '';
        window.dispatchEvent(new CustomEvent('barcode-scanned', { detail: { barcode } }));
    }
});
```

### PDF Invoice generation
```php
// GenerateInvoicePdf job uses Laravel DomPDF
// Template: resources/views/invoices/pdf.blade.php
// Stores to S3, updates sales_invoices.pdf_path
use Barryvdh\DomPDF\Facade\Pdf;

$pdf = Pdf::loadView('invoices.pdf', ['invoice' => $invoice->load('lines.product', 'customer')]);
$path = "invoices/{$invoice->invoice_number}.pdf";
Storage::disk('s3')->put($path, $pdf->output());
$invoice->update(['pdf_path' => $path]);
```

### Accounting export (optional future integration)
```php
// ExternalAccountingService interface
interface AccountingIntegrationInterface {
    public function syncInvoice(SalesInvoice $invoice): void;
    public function syncPayment(Payment $payment): void;
}
// Implement for QuickBooks, Tally, or local accounting software as needed
```

---

## 16. Testing Strategy

### Test structure
```
tests/
├── Unit/
│   ├── Services/
│   │   ├── SalesPostingServiceTest.php
│   │   ├── InstallmentAllocationServiceTest.php
│   │   ├── GoodsReceiptPostingServiceTest.php
│   │   └── InventoryBalanceServiceTest.php
│   └── Models/
│       └── SerialItemTest.php
├── Feature/
│   ├── SalesInvoiceFlowTest.php      (create → post → pay)
│   ├── InstallmentPlanFlowTest.php   (create → pay → overdue → reschedule)
│   ├── StockTransferFlowTest.php     (create → approve → issue → receive)
│   ├── GoodsReceiptFlowTest.php
│   ├── ReturnFlowTest.php
│   └── ConcurrencyTest.php           (race condition: two sales for same serial)
└── Browser/                           (Dusk, optional)
    └── SalesBranchWorkflowTest.php
```

### Critical test: concurrency (serial double-sell)
```php
public function test_two_concurrent_sales_cannot_both_succeed_for_same_serial(): void
{
    $serial = SerialItem::factory()->inStock()->create();
    
    $results = [];
    $threads = []; // Use process-based parallelism or queue workers
    
    // Simulate two sales attempts for the same serial
    for ($i = 0; $i < 2; $i++) {
        try {
            $invoice = SalesInvoice::factory()->draft()->create();
            app(SalesPostingService::class)->post($invoice, 1);
            $results[] = 'success';
        } catch (\Exception $e) {
            $results[] = 'failed';
        }
    }
    
    // Only one should succeed
    $this->assertCount(1, array_filter($results, fn($r) => $r === 'success'));
    $this->assertEquals('sold', $serial->fresh()->status);
}
```

---

## 17. Deployment & Infrastructure

### Recommended server setup (single server for launch)
```
1× Application server: 4 vCPU, 8GB RAM, Ubuntu 22.04
  - PHP 8.3-FPM
  - Nginx
  - Supervisor (for queue workers)
  
1× Database server: 4 vCPU, 16GB RAM
  - MySQL 8.0
  - Daily automated backups to S3

1× Redis: can run on app server initially
  - Sessions, cache, queues

CDN: Cloudflare (free tier) for static assets
S3: AWS S3 or MinIO (self-hosted) for file storage
```

### Supervisor workers
```ini
[program:laravel-worker-default]
command=php /var/www/artisan queue:work redis --queue=default --sleep=3 --tries=3
numprocs=2
autostart=true

[program:laravel-worker-exports]
command=php /var/www/artisan queue:work redis --queue=exports --sleep=5 --tries=1 --timeout=300
numprocs=1
autostart=true

[program:laravel-worker-notifications]
command=php /var/www/artisan queue:work redis --queue=notifications --sleep=3 --tries=3
numprocs=1
autostart=true
```

### Nginx config
```nginx
server {
    listen 80;
    server_name your-domain.com;
    root /var/www/public;
    index index.php;
    
    client_max_body_size 20M;
    
    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }
    
    location ~ \.php$ {
        fastcgi_pass unix:/var/run/php/php8.3-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        include fastcgi_params;
    }
    
    location ~* \.(css|js|png|jpg|ico|woff2)$ {
        expires 1y;
        add_header Cache-Control "public, immutable";
    }
}
```

---

## 18. Implementation Milestones

### Milestone 1: Foundation (Weeks 1–3)
- [ ] Laravel 13 project setup, Vite, Alpine.js, Blade layouts
- [ ] All migrations run and verified
- [ ] Auth with role-based middleware
- [ ] User, Location, Brand, Category, Product CRUD
- [ ] Supplier and Customer CRUD

### Milestone 2: Inventory Inbound (Weeks 4–5)
- [ ] Purchase Order create/approve workflow
- [ ] Goods Receipt with serial capture
- [ ] GoodsReceiptPostingService with full transaction logic
- [ ] Inventory balance read model working
- [ ] Barcode generation and scan input working

### Milestone 3: Transfers (Week 6)
- [ ] Transfer create/approve/issue/receive workflow
- [ ] TransferIssueService and TransferReceiveService
- [ ] Stock ledger entries verified
- [ ] Serial status state machine enforced

### Milestone 4: Sales & Payments (Weeks 7–9)
- [ ] Invoice line builder (Alpine component)
- [ ] Serial selector modal with lookup
- [ ] SalesPostingService (atomic transaction)
- [ ] Cash payment recording and allocation
- [ ] Credit sale flow with AR ledger
- [ ] Invoice PDF generation and print view

### Milestone 5: Installments (Weeks 10–11)
- [ ] Installment plan creation with custom schedules
- [ ] Schedule builder Alpine component
- [ ] Payment allocation to schedules
- [ ] Overdue detection scheduled command
- [ ] Reschedule flow with approval
- [ ] Installment overdue report

### Milestone 6: Returns, Warranty, Service (Week 12)
- [ ] Return intake with resolution options
- [ ] ReturnProcessingService (restock/repair/replace/scrap)
- [ ] Warranty check endpoint
- [ ] Service job intake and status tracking

### Milestone 7: Reporting & Notifications (Weeks 13–14)
- [ ] All 10 reports implemented
- [ ] Async export to Excel/CSV via queue
- [ ] SMS and Email notification system
- [ ] Dashboard analytics with caching
- [ ] Low stock alert job

### Milestone 8: Integrations & Hardening (Weeks 15–16)
- [ ] Demand forecasting job and display
- [ ] Full audit log review
- [ ] Concurrency tests passing
- [ ] Performance profiling and index tuning
- [ ] End-to-end browser tests
- [ ] Production deployment and monitoring

---

## Appendix A: Number Generation Patterns

All numbered entities use a consistent format. Generate inside a `DB::transaction()->lockForUpdate()` to prevent duplicates.

| Entity | Format | Example |
|---|---|---|
| Invoice | `INV-{BRANCH}-{YYYYMMDD}-{0001}` | `INV-BR01-20250115-0042` |
| GRN | `GRN-{YYYYMMDD}-{0001}` | `GRN-20250115-0007` |
| PO | `PO-{YYMM}-{0001}` | `PO-2501-0023` |
| Transfer | `TRF-{SOURCE}-{DEST}-{0001}` | `TRF-WH01-BR02-0005` |
| Return | `RET-{BRANCH}-{YYMM}-{0001}` | `RET-BR01-2501-0003` |
| Payment | `PAY-{YYYYMMDD}-{0001}` | `PAY-20250115-0088` |
| Service Job | `SVC-{BRANCH}-{0001}` | `SVC-BR01-0014` |
| Customer Code | `CUST-{0001}` | `CUST-0342` |

---

## Appendix B: Data Flow Summary

```
Supplier → PurchaseOrder → GoodsReceipt → SerialItem(in_stock)
                                               ↓
                                         StockTransfer → SerialItem moves to Branch
                                               ↓
Customer → SalesInvoice → SalesPostingService → SerialItem(sold)
                                               ↓
                             AR Ledger + InstallmentPlan (if installment)
                                               ↓
                             Payment → PaymentAllocation → InstallmentAllocation
                                               ↓
                             ReturnOrder → SerialItem(returned/damaged)
                                               ↓
                             ServiceJob → SerialItem(under_service → in_stock)
```

---

*This specification is complete and self-contained. An AI coding agent should be able to implement the full system from this document alone, working through milestones in order, with no ambiguity in schema, business logic, or service contracts.*