<?php

use App\Http\Controllers\AttendanceController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\BranchAnnualTargetController;
use App\Http\Controllers\BranchMonthlyTargetController;
use App\Http\Controllers\BrandController;
use App\Http\Controllers\CategoryController;
use App\Http\Controllers\CompanyPadSettingsController;
use App\Http\Controllers\ContextLocationController;
use App\Http\Controllers\CreditInvoiceController;
use App\Http\Controllers\CustomerController;
use App\Http\Controllers\CustomerRiskController;
use App\Http\Controllers\CustomerTrackingController;
use App\Http\Controllers\DailyTransactionController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\EmployeeController;
use App\Http\Controllers\ForecastController;
use App\Http\Controllers\GlobalSearchController;
use App\Http\Controllers\GoodsReceiptController;
use App\Http\Controllers\HrDepartmentController;
use App\Http\Controllers\HrDesignationController;
use App\Http\Controllers\InstallmentPlanController;
use App\Http\Controllers\InventoryStockController;
use App\Http\Controllers\JournalEntryController;
use App\Http\Controllers\LeaveRequestController;
use App\Http\Controllers\LeaveTypeController;
use App\Http\Controllers\LedgerAccountController;
use App\Http\Controllers\LocationController;
use App\Http\Controllers\PaymentController;
use App\Http\Controllers\PaymentMethodController;
use App\Http\Controllers\PaymentMethodWalletMapController;
use App\Http\Controllers\PaymentRegisterController;
use App\Http\Controllers\PayScaleController;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\PurchaseOrderController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\ReturnController;
use App\Http\Controllers\RoleController;
use App\Http\Controllers\SalarySheetController;
use App\Http\Controllers\SalesInvoiceController;
use App\Http\Controllers\SerialItemController;
use App\Http\Controllers\SerialItemLabelBarcodeController;
use App\Http\Controllers\ServiceJobController;
use App\Http\Controllers\StockCheckController;
use App\Http\Controllers\StockTransferController;
use App\Http\Controllers\StorefrontController;
use App\Http\Controllers\SupplierController;
use App\Http\Controllers\SupplierPaymentRegisterController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\UserImpersonationController;
use App\Http\Controllers\WalletController;
use App\Models\HrDepartment;
use App\Models\HrDesignation;
use App\Services\ReportDataService;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return auth()->check()
        ? redirect()->route('dashboard')
        : redirect()->route('login');
});

Route::get('/shop', [StorefrontController::class, 'index'])->name('storefront.index');
Route::get('/shop/products/{product}', [StorefrontController::class, 'show'])->name('storefront.show');

Route::middleware('guest')->group(function () {
    Route::get('/login', [LoginController::class, 'show'])->name('login');
    Route::post('/login', [LoginController::class, 'login'])->name('login.attempt');
});

Route::middleware(['auth', 'active-user', 'route-access'])->group(function () {
    Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');
    Route::get('/search', GlobalSearchController::class)->name('search');
    Route::get('/search/live', [GlobalSearchController::class, 'live'])->name('search.live');
    Route::post('/context/location', [ContextLocationController::class, 'update'])->name('context.location');
    Route::post('/logout', [LoginController::class, 'logout'])->name('logout');
    Route::post('/impersonation/leave', [UserImpersonationController::class, 'destroy'])->name('impersonation.leave');

    Route::get('profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::put('profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::get('profile/password', [ProfileController::class, 'editPassword'])->name('profile.password.edit');
    Route::put('profile/password', [ProfileController::class, 'updatePassword'])->name('profile.password.update');

    Route::post('users/{user}/impersonate', [UserImpersonationController::class, 'store'])->name('users.impersonate');
    Route::resource('users', UserController::class)->only(['index', 'create', 'store', 'edit', 'update']);
    Route::resource('roles', RoleController::class)->only(['index', 'create', 'store', 'edit', 'update']);

    Route::resource('brands', BrandController::class)->except(['show']);
    Route::get('categories/print', [CategoryController::class, 'print'])->name('categories.print');
    Route::get('categories/bulk-products', [CategoryController::class, 'bulkProducts'])->name('categories.bulk-products');
    Route::post('categories/bulk-products', [CategoryController::class, 'updateBulkProducts'])->name('categories.bulk-products.update');
    Route::patch('categories/bulk-products/product', [CategoryController::class, 'patchBulkProduct'])->name('categories.bulk-products.patch');
    Route::post('categories/move-products', [CategoryController::class, 'moveProducts'])->name('categories.move-products');
    Route::resource('categories', CategoryController::class)->except(['show']);
    Route::get('products/bulk-update/export', [ProductController::class, 'exportBulkUpdate'])->name('products.bulk-update.export');
    Route::post('products/bulk-update/import', [ProductController::class, 'importBulkUpdate'])->name('products.bulk-update.import');
    Route::post('products/bulk-update/apply', [ProductController::class, 'applyBulkUpdate'])->name('products.bulk-update.apply');
    Route::get('products/labels', [ProductController::class, 'printLabels'])->name('products.labels');
    Route::get('products/print', [ProductController::class, 'print'])->name('products.print');
    Route::get('serial-items/{serial_item}/label-barcode.png', SerialItemLabelBarcodeController::class)->name('serial-items.label-barcode');
    Route::get('products/barcode/next', [ProductController::class, 'nextBarcode'])->name('products.barcode.next');
    Route::post('products/quick-store', [ProductController::class, 'quickStore'])->name('products.quick-store');
    Route::delete('products/{product}/serials/{serial}', [ProductController::class, 'destroySerial'])->name('products.serials.destroy');
    Route::resource('serial-items', SerialItemController::class)->only(['index', 'show', 'update', 'destroy']);
    Route::post('products/{product}/serials/bulk-destroy', [ProductController::class, 'bulkDestroySerials'])->name('products.serials.bulk-destroy');
    Route::patch('products/{product}/serials/{serial}/sale-price', [ProductController::class, 'updateSerialSalePrice'])->name('products.serials.sale-price');
    Route::post('products/{product}/serials/bulk-sale-price', [ProductController::class, 'bulkUpdateSerialSalePrice'])->name('products.serials.bulk-sale-price');
    Route::resource('products', ProductController::class)->except([]);
    Route::post('products/{product}/toggle-active', [ProductController::class, 'toggleActive'])->name('products.toggle-active');
    Route::resource('suppliers', SupplierController::class);
    Route::resource('customers', CustomerController::class);
    Route::get('customers/{customer}/risk', [CustomerRiskController::class, 'show'])->name('customers.risk');
    Route::get('customers/{customer}/risk/print', [CustomerRiskController::class, 'print'])->name('customers.risk.print');
    Route::get('customers/{customer}/risk/pdf', [CustomerRiskController::class, 'pdf'])->name('customers.risk.pdf');
    Route::get('customers/{customer}/risk/excel', [CustomerRiskController::class, 'excel'])->name('customers.risk.excel');
    Route::post('customers/{customer}/attach-guarantor-to-invoice', [CustomerController::class, 'attachGuarantorToInvoice'])->name('customers.attach-guarantor-to-invoice');
    Route::post('customers/{customer}/guarantors/quick-create', [CustomerController::class, 'quickCreateGuarantor'])->name('customers.guarantors.quick-create');
    Route::get('customer-tracking', [CustomerTrackingController::class, 'index'])->name('customer-tracking.index');
    Route::get('customer-tracking/{customer}/edit', [CustomerTrackingController::class, 'edit'])->name('customer-tracking.edit');
    Route::get('customer-tracking/{customer}', [CustomerTrackingController::class, 'show'])->name('customer-tracking.show');
    Route::put('customer-tracking/{customer}', [CustomerTrackingController::class, 'update'])->name('customer-tracking.update');
    Route::resource('locations', LocationController::class);
    Route::post('locations/{location}/annual-targets', [BranchAnnualTargetController::class, 'store'])->name('locations.annual-targets.store');
    Route::put('locations/{location}/annual-targets/{branchAnnualTarget}', [BranchAnnualTargetController::class, 'update'])->name('locations.annual-targets.update');
    Route::delete('locations/{location}/annual-targets/{branchAnnualTarget}', [BranchAnnualTargetController::class, 'destroy'])->name('locations.annual-targets.destroy');
    Route::post('locations/{location}/monthly-targets', [BranchMonthlyTargetController::class, 'sync'])->name('locations.monthly-targets.sync');
    Route::get('payment-methods/create', [PaymentMethodController::class, 'create'])->name('payment-methods.create');
    Route::get('payment-methods/{paymentMethod}/edit', [PaymentMethodController::class, 'edit'])->name('payment-methods.edit');
    Route::resource('payment-methods', PaymentMethodController::class)->except(['show', 'create', 'edit']);
    Route::resource('wallets', WalletController::class);
    Route::resource('ledger-accounts', LedgerAccountController::class)->except(['show']);
    Route::get('payment-method-wallet-maps/create', [PaymentMethodWalletMapController::class, 'create'])->name('payment-method-wallet-maps.create');
    Route::get('payment-method-wallet-maps/{payment_method_wallet_map}/edit', [PaymentMethodWalletMapController::class, 'edit'])->name('payment-method-wallet-maps.edit');
    Route::get('payment-method-wallet-maps/options', [PaymentMethodWalletMapController::class, 'options'])->name('payment-method-wallet-maps.options');
    Route::resource('payment-method-wallet-maps', PaymentMethodWalletMapController::class)->except(['show', 'create', 'edit']);
    Route::get('journal-entries/reports/detailed', [JournalEntryController::class, 'detailedReport'])->name('journal-entries.reports.detailed');
    Route::get('journal-entries/reports/account-summary', [JournalEntryController::class, 'accountSummaryReport'])->name('journal-entries.reports.account-summary');
    Route::post('journal-entries/{journal_entry}/post', [JournalEntryController::class, 'postDraft'])->name('journal-entries.post');
    Route::delete('journal-entries/{journal_entry}/linked-payment', [JournalEntryController::class, 'destroyLinkedPayment'])->name('journal-entries.linked-payment.destroy');
    Route::resource('journal-entries', JournalEntryController::class)->only(['index', 'show', 'create', 'store', 'edit', 'update', 'destroy']);
    Route::get('daily-transactions', [DailyTransactionController::class, 'index'])->name('daily-transactions.index');
    Route::get('settings/company-pad', [CompanyPadSettingsController::class, 'edit'])->name('settings.company-pad.edit');
    Route::put('settings/company-pad', [CompanyPadSettingsController::class, 'update'])->name('settings.company-pad.update');

    Route::prefix('hr')->name('hr.')->group(function () {
        Route::bind('department', fn (string $value) => HrDepartment::findOrFail($value));
        Route::bind('designation', fn (string $value) => HrDesignation::findOrFail($value));

        Route::resource('departments', HrDepartmentController::class)->except(['show']);
        Route::resource('designations', HrDesignationController::class)->except(['show']);
        Route::resource('pay-scales', PayScaleController::class)->except(['show'])->parameters(['pay-scales' => 'pay_scale']);
        Route::post('employees/{employee}/pay-scale', [EmployeeController::class, 'assignPayScale'])->name('employees.pay-scale.store');
        Route::resource('employees', EmployeeController::class);
        Route::get('attendance', [AttendanceController::class, 'index'])->name('attendance.index');
        Route::get('attendance/monthly-report', [AttendanceController::class, 'monthlyReport'])->name('attendance.monthly-report');
        Route::get('attendance/monthly-report/print', [AttendanceController::class, 'printMonthlyReport'])->name('attendance.monthly-report.print');
        Route::post('attendance', [AttendanceController::class, 'store'])->name('attendance.store');
        Route::resource('leave-types', LeaveTypeController::class)->except(['show'])->parameters(['leave-types' => 'leave_type']);
        Route::post('leave-requests/{leave_request}/approve', [LeaveRequestController::class, 'approve'])->name('leave-requests.approve');
        Route::post('leave-requests/{leave_request}/reject', [LeaveRequestController::class, 'reject'])->name('leave-requests.reject');
        Route::resource('leave-requests', LeaveRequestController::class)->except(['show'])->parameters(['leave-requests' => 'leave_request']);
        Route::post('salary-sheets/{salary_sheet}/finalize', [SalarySheetController::class, 'finalize'])->name('salary-sheets.finalize');
        Route::resource('salary-sheets', SalarySheetController::class)->only(['index', 'store', 'show', 'destroy'])->parameters(['salary-sheets' => 'salary_sheet']);
    });

    Route::resource('purchase-orders', PurchaseOrderController::class)->only(['index', 'create', 'store', 'show', 'edit', 'update', 'destroy']);
    Route::get('purchase-orders/{purchase_order}/receipt', [PurchaseOrderController::class, 'receipt'])->name('purchase-orders.receipt');
    Route::post('purchase-orders/{purchase_order}/approve', [PurchaseOrderController::class, 'approve'])->name('purchase-orders.approve');
    Route::post('purchase-orders/{purchase_order}/payments', [PurchaseOrderController::class, 'storePayment'])->name('purchase-orders.payments.store');
    Route::post('purchase-orders/{purchase_order}/refunds', [PurchaseOrderController::class, 'storeSupplierRefund'])->name('purchase-orders.refunds.store');
    Route::delete('purchase-orders/{purchase_order}/payments/{purchase_order_payment}', [PurchaseOrderController::class, 'destroyPayment'])->name('purchase-orders.payments.destroy');
    Route::post('purchase-orders/{purchase_order}/additional-costs', [PurchaseOrderController::class, 'storeAdditionalCost'])->name('purchase-orders.additional-costs.store');

    Route::resource('goods-receipts', GoodsReceiptController::class)->only(['index', 'create', 'store', 'show', 'edit', 'update', 'destroy']);
    Route::get('goods-receipts/{goods_receipt}/receipt', [GoodsReceiptController::class, 'receipt'])->name('goods-receipts.receipt');
    Route::get('goods-receipts/{goods_receipt}/labels', [GoodsReceiptController::class, 'printLabels'])->name('goods-receipts.labels');
    Route::post('goods-receipts/{grn}/post', [GoodsReceiptController::class, 'post'])->name('goods-receipts.post');

    Route::get('transfers/available-serials', [StockTransferController::class, 'availableSerials'])->name('transfers.available-serials');
    Route::get('transfers/resolve-serial-scan', [StockTransferController::class, 'resolveSerialScan'])->name('transfers.resolve-serial-scan');
    Route::resource('transfers', StockTransferController::class)->only(['index', 'create', 'store', 'show', 'edit', 'update', 'destroy']);
    Route::post('transfers/{transfer}/approve-source', [StockTransferController::class, 'approveSource'])->name('transfers.approve-source');
    Route::post('transfers/{transfer}/approve-destination', [StockTransferController::class, 'approveDestination'])->name('transfers.approve-destination');
    Route::post('transfers/{transfer}/receive-direct', [StockTransferController::class, 'receiveDirect'])->name('transfers.receive-direct');
    Route::post('transfers/{transfer}/issue', [StockTransferController::class, 'issue'])->name('transfers.issue');
    Route::post('transfers/{transfer}/receive', [StockTransferController::class, 'receive'])->name('transfers.receive');
    Route::post('transfers/{transfer}/cancel', [StockTransferController::class, 'cancel'])->name('transfers.cancel');
    Route::get('transfers/{transfer}/receipt', [StockTransferController::class, 'receipt'])->name('transfers.receipt');

    Route::get('inventory/stock-levels', [InventoryStockController::class, 'index'])->name('inventory.stock-levels.index');
    Route::get('inventory/stock-check', [StockCheckController::class, 'index'])->name('inventory.stock-check.index');
    Route::get('inventory/stock-check/checklist', [StockCheckController::class, 'checklist'])->name('inventory.stock-check.checklist');
    Route::post('inventory/stock-check/scan', [StockCheckController::class, 'sessionScan'])->name('inventory.stock-check.scan');
    Route::post('inventory/stock-check/finish', [StockCheckController::class, 'finish'])->name('inventory.stock-check.finish');
    Route::get('inventory/stock-check/report', [StockCheckController::class, 'report'])->name('inventory.stock-check.report');
    Route::get('inventory/stock-check/lookup', [StockCheckController::class, 'lookup'])->name('inventory.stock-check.lookup');
    Route::get('inventory/stock-levels/adjust', [InventoryStockController::class, 'adjust'])->name('inventory.stock-levels.adjust');
    Route::post('inventory/stock-levels/adjust', [InventoryStockController::class, 'store'])->name('inventory.stock-levels.adjust.store');
    Route::post('inventory/stock-levels/sync', [InventoryStockController::class, 'sync'])->name('inventory.stock-levels.sync');

    Route::post('sales/invoices/customers/quick-create', [SalesInvoiceController::class, 'quickCreateCustomer'])->name('sales.invoices.customers.quick-create');
    Route::get('sales/invoices/serial-suggestions', [SalesInvoiceController::class, 'serialSuggestions'])->name('sales.invoices.serial-suggestions');
    Route::get('sales/invoices/resolve-serial-scan', [SalesInvoiceController::class, 'resolveSerialScan'])->name('sales.invoices.resolve-serial-scan');
    Route::get('sales/invoices/trashed', [SalesInvoiceController::class, 'trashed'])->name('sales.invoices.trashed');
    Route::resource('sales/invoices', SalesInvoiceController::class)
        ->only(['index', 'create', 'store', 'show', 'edit', 'update', 'destroy'])
        ->names('sales.invoices');
    Route::get('sales/invoices/{invoice}/print', [SalesInvoiceController::class, 'print'])->name('sales.invoices.print');
    Route::post('sales/invoices/{invoice}/activate-installment', [SalesInvoiceController::class, 'activateInstallment'])->name('sales.invoices.activate-installment');
    Route::post('sales/invoices/{invoice}/guarantor', [SalesInvoiceController::class, 'updateGuarantor'])->name('sales.invoices.guarantor');
    Route::post('sales/invoices/{invoice}/branch', [SalesInvoiceController::class, 'updateBranch'])->name('sales.invoices.branch');
    Route::post('sales/invoices/{invoice}/sale-type', [SalesInvoiceController::class, 'changeSaleType'])->name('sales.invoices.sale-type');
    Route::post('sales/invoices/{invoice}/post', [SalesInvoiceController::class, 'post'])->name('sales.invoices.post');
    Route::post('sales/invoices/{invoice}/write-off', [SalesInvoiceController::class, 'writeOff'])->name('sales.invoices.write-off');
    Route::delete('sales/invoices/{invoice}/write-off/{writeoff}', [SalesInvoiceController::class, 'destroyWriteOff'])->name('sales.invoices.write-off.destroy');
    Route::post('sales/invoices/{invoice}/restore', [SalesInvoiceController::class, 'restore'])->name('sales.invoices.restore')->withTrashed();
    Route::delete('sales/invoices/{invoice}/force-delete', [SalesInvoiceController::class, 'forceDelete'])->name('sales.invoices.force-delete')->withTrashed();
    Route::post('sales/invoices/{invoice}/payments', [PaymentController::class, 'store'])->name('sales.invoices.payments.store');
    Route::post('sales/invoices/{invoice}/historical-payments', [PaymentController::class, 'storeHistorical'])->name('sales.invoices.historical-payments.store');
    Route::patch('sales/invoices/{invoice}/payments/{payment}/payment-method', [PaymentController::class, 'updatePaymentMethod'])
        ->name('sales.invoices.payments.update-method');
    Route::delete('sales/invoices/{invoice}/payment-allocations/{allocation}', [PaymentController::class, 'destroyAllocation'])
        ->name('sales.invoices.payment-allocations.destroy');

    Route::get('payment-register', [PaymentRegisterController::class, 'index'])->name('payment-register.index');
    Route::post('payment-register/invoices/{invoice}/payments', [PaymentRegisterController::class, 'store'])->name('payment-register.payments.store');

    Route::get('supplier-payment-register', [SupplierPaymentRegisterController::class, 'index'])->name('supplier-payment-register.index');

    Route::get('credit', [CreditInvoiceController::class, 'index'])->name('credit.index');
    Route::get('installments', [InstallmentPlanController::class, 'index'])->name('installments.index');
    Route::get('installments/{plan}', [InstallmentPlanController::class, 'show'])->name('installments.show');
    Route::post('sales/invoices/{invoice}/installment-plan', [InstallmentPlanController::class, 'store'])->name('installments.store');

    Route::get('returns/lookup/customers', [ReturnController::class, 'lookupCustomers'])->name('returns.lookup.customers');
    Route::get('returns/lookup/invoices', [ReturnController::class, 'lookupInvoices'])->name('returns.lookup.invoices');
    Route::put('returns/{return}/items', [ReturnController::class, 'updateItems'])->name('returns.items');
    Route::resource('returns', ReturnController::class)->only(['index', 'create', 'store', 'show', 'update']);
    Route::get('returns/{return}/receipt', [ReturnController::class, 'receipt'])->name('returns.receipt');
    Route::post('returns/{return}/approve', [ReturnController::class, 'approve'])->name('returns.approve');
    Route::post('returns/{return}/resolve', [ReturnController::class, 'resolve'])->name('returns.resolve');
    Route::post('returns/{return}/pay-refund', [ReturnController::class, 'payRefund'])->name('returns.pay-refund');
    Route::post('returns/{return}/rollback', [ReturnController::class, 'rollback'])->name('returns.rollback');
    Route::get('service-jobs/serials/search', [ServiceJobController::class, 'serialSearch'])->name('service-jobs.serials.search');
    Route::resource('service-jobs', ServiceJobController::class)->only(['index', 'create', 'store', 'show', 'edit', 'update']);

    Route::get('forecasts', [ForecastController::class, 'index'])->name('forecasts.index');
    Route::post('forecasts/refresh', [ForecastController::class, 'refresh'])->name('forecasts.refresh');

    Route::prefix('reports')->name('reports.')->group(function () {
        Route::get('/', [ReportController::class, 'index'])->name('index');
        Route::get('{slug}/export', [ReportController::class, 'export'])
            ->name('export')
            ->where('slug', implode('|', [...ReportDataService::REPORT_SLUGS, 'item-wise-sales', 'month-to-date-sales', 'representative-wise-sales', 'item-wise-purchase']));
        Route::get('{slug}/print', [ReportController::class, 'print'])
            ->name('print')
            ->where('slug', implode('|', [...ReportDataService::REPORT_SLUGS, 'item-wise-sales', 'month-to-date-sales', 'representative-wise-sales', 'item-wise-purchase']));
        Route::get('customer-risk/{to?}', [CustomerRiskController::class, 'hubRedirect'])
            ->name('customer-risk')
            ->where('to', 'print|pdf|excel');
        Route::get('stock-by-location', [ReportController::class, 'stockByLocation'])->name('stock-by-location');
        Route::get('branch-in-stock-pricing', [ReportController::class, 'branchInStockPricing'])->name('branch-in-stock-pricing');
        Route::get('stock-summary', [ReportController::class, 'stockSummary'])->name('stock-summary');
        Route::get('sales-summary', [ReportController::class, 'salesSummary'])->name('sales-summary');
        Route::get('month-to-date-sales', [ReportController::class, 'monthToDateSales'])->name('month-to-date-sales');
        Route::get('item-wise-sales', [ReportController::class, 'itemWiseSales'])->name('item-wise-sales');
        Route::get('representative-wise-sales', [ReportController::class, 'representativeWiseSales'])->name('representative-wise-sales');
        Route::get('item-wise-purchase', [ReportController::class, 'itemWisePurchase'])->name('item-wise-purchase');
        Route::get('installment-overdue', [ReportController::class, 'installmentOverdue'])->name('installment-overdue');
        Route::get('installment-duration', [ReportController::class, 'installmentDuration'])->name('installment-duration');
        Route::get('credit-overdue', [ReportController::class, 'creditOverdue'])->name('credit-overdue');
        Route::get('low-stock', [ReportController::class, 'lowStock'])->name('low-stock');
        Route::get('customer-dues', [ReportController::class, 'customerDues'])->name('customer-dues');
        Route::get('high-risk-customers', [ReportController::class, 'highRiskCustomers'])->name('high-risk-customers');
        Route::get('yearly-collection-sheet', [ReportController::class, 'yearlyCollectionSheet'])->name('yearly-collection-sheet');
        Route::get('invoice-outstanding-by-customer', [ReportController::class, 'invoiceOutstandingByCustomer'])->name('invoice-outstanding-by-customer');
        Route::get('open-purchase-orders', [ReportController::class, 'openPurchaseOrders'])->name('open-purchase-orders');
        Route::get('supplier-dues-and-payments', [ReportController::class, 'supplierDuesAndPayments'])->name('supplier-dues-and-payments');
        Route::get('sales-by-day-branch', [ReportController::class, 'salesByDayBranch'])->name('sales-by-day-branch');
        Route::get('daily-expenses-by-branch', [ReportController::class, 'dailyExpenseByBranch'])->name('daily-expenses-by-branch');
        Route::get('branch-targets', [ReportController::class, 'branchTargets'])->name('branch-targets');
        Route::get('profit-loss-sheet', [ReportController::class, 'profitLossSheet'])->name('profit-loss-sheet');
        Route::get('income-expenditure-statement', [ReportController::class, 'incomeExpenditureStatement'])->name('income-expenditure-statement');
        Route::get('customer-payment-by-branch', [ReportController::class, 'customerPaymentByBranch'])->name('customer-payment-by-branch');
        Route::get('customer-journal', [ReportController::class, 'customerJournal'])->name('customer-journal');
        Route::get('monthly-collection', [ReportController::class, 'monthlyCollection'])->name('monthly-collection');
        Route::get('wallet-transactions', [ReportController::class, 'walletTransactions'])->name('wallet-transactions');
        Route::get('transfer-ledger', [ReportController::class, 'transferLedger'])->name('transfer-ledger');
    });
});
