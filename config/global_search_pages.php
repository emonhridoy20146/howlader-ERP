<?php

use App\Models\Brand;
use App\Models\Category;
use App\Models\Customer;
use App\Models\DemandForecast;
use App\Models\GoodsReceipt;
use App\Models\InstallmentPlan;
use App\Models\JournalEntry;
use App\Models\LedgerAccount;
use App\Models\Location;
use App\Models\PaymentMethod;
use App\Models\PaymentMethodWalletMap;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\ReturnOrder;
use App\Models\SalesInvoice;
use App\Models\SerialItem;
use App\Models\ServiceJob;
use App\Models\StockTransfer;
use App\Models\Supplier;
use App\Models\Wallet;

/**
 * App screens discoverable from global search (matched by label / keywords).
 *
 * @var list<array{
 *     label: string,
 *     group: string,
 *     route: string,
 *     route_params?: array<string, mixed>,
 *     keywords?: list<string>,
 *     gate?: array{0: class-string, 1: string},
 *     permission?: string
 * }>
 */
return [
    'pages' => [
        ['label' => 'Dashboard', 'group' => 'General', 'route' => 'dashboard', 'keywords' => ['home', 'overview']],

        ['label' => 'Branches', 'group' => 'Master data', 'route' => 'locations.index', 'keywords' => ['locations', 'warehouse', 'branch'], 'gate' => [Location::class, 'viewAny']],
        ['label' => 'Brands', 'group' => 'Master data', 'route' => 'brands.index', 'gate' => [Brand::class, 'viewAny']],
        ['label' => 'Categories', 'group' => 'Master data', 'route' => 'categories.index', 'gate' => [Category::class, 'viewAny']],
        ['label' => 'Products', 'group' => 'Master data', 'route' => 'products.index', 'keywords' => ['catalog', 'sku', 'items'], 'gate' => [Product::class, 'viewAny']],
        ['label' => 'Deactivated products', 'group' => 'Master data', 'route' => 'products.index', 'route_params' => ['list' => 'inactive'], 'keywords' => ['inactive', 'deactivated', 'archived'], 'gate' => [Product::class, 'viewAny']],
        ['label' => 'Bulk product price update', 'group' => 'Master data', 'route' => 'products.index', 'keywords' => ['bulk', 'excel', 'import', 'export', 'prices'], 'permission' => 'product.update'],
        ['label' => 'Suppliers', 'group' => 'Master data', 'route' => 'suppliers.index', 'gate' => [Supplier::class, 'viewAny']],
        ['label' => 'Customers', 'group' => 'Master data', 'route' => 'customers.index', 'keywords' => ['customer code', 'code', 'phone', 'buyer'], 'gate' => [Customer::class, 'viewAny']],
        ['label' => 'Payment methods', 'group' => 'Finance', 'route' => 'payment-methods.index', 'keywords' => ['payments'], 'gate' => [PaymentMethod::class, 'viewAny']],
        ['label' => 'Wallets', 'group' => 'Finance', 'route' => 'wallets.index', 'gate' => [Wallet::class, 'viewAny']],
        ['label' => 'Ledger accounts', 'group' => 'Finance', 'route' => 'ledger-accounts.index', 'keywords' => ['chart of accounts', 'coa'], 'gate' => [LedgerAccount::class, 'viewAny']],
        ['label' => 'Payment → wallet mappings', 'group' => 'Finance', 'route' => 'payment-method-wallet-maps.index', 'keywords' => ['routing', 'mapping'], 'gate' => [PaymentMethodWalletMap::class, 'viewAny']],
        ['label' => 'Journal entries', 'group' => 'Finance', 'route' => 'journal-entries.index', 'keywords' => ['journal', 'expense'], 'gate' => [JournalEntry::class, 'viewAny']],
        ['label' => 'Daily transactions', 'group' => 'Finance', 'route' => 'daily-transactions.index', 'keywords' => ['transactions', 'day book'], 'gate' => [JournalEntry::class, 'viewAny']],
        ['label' => 'Company pad settings', 'group' => 'Settings', 'route' => 'settings.company-pad.edit', 'keywords' => ['letterhead', 'print', 'branding'], 'permission' => 'settings.company-pad.manage'],

        ['label' => 'Purchase orders', 'group' => 'Inventory', 'route' => 'purchase-orders.index', 'keywords' => ['po', 'procurement'], 'gate' => [PurchaseOrder::class, 'viewAny']],
        ['label' => 'Supplier payment register', 'group' => 'Inventory', 'route' => 'supplier-payment-register.index', 'keywords' => ['supplier payments', 'payables'], 'gate' => [PurchaseOrder::class, 'viewAny']],
        ['label' => 'Goods receipts', 'group' => 'Inventory', 'route' => 'goods-receipts.index', 'keywords' => ['grn', 'receiving', 'receive stock'], 'gate' => [GoodsReceipt::class, 'viewAny']],
        ['label' => 'Stock transfers', 'group' => 'Inventory', 'route' => 'transfers.index', 'keywords' => ['transfer', 'inter branch'], 'gate' => [StockTransfer::class, 'viewAny']],
        ['label' => 'Stock by branch', 'group' => 'Inventory', 'route' => 'inventory.stock-levels.index', 'keywords' => ['stock levels', 'inventory', 'on hand'], 'permission' => 'inventory.stock-manage'],
        ['label' => 'Stock check', 'group' => 'Inventory', 'route' => 'inventory.stock-check.index', 'keywords' => ['stock check', 'physical count', 'qr', 'barcode', 'scan'], 'permission' => 'product.view-any'],
        ['label' => 'Serial registry', 'group' => 'Inventory', 'route' => 'serial-items.index', 'keywords' => ['serials', 'imei', 'serial numbers'], 'gate' => [SerialItem::class, 'viewAny']],

        ['label' => 'Sales invoices', 'group' => 'Sales', 'route' => 'sales.invoices.index', 'keywords' => ['invoices', 'sales'], 'gate' => [SalesInvoice::class, 'viewAny']],
        ['label' => 'Payment register', 'group' => 'Sales', 'route' => 'payment-register.index', 'keywords' => ['collections', 'customer payments'], 'gate' => [SalesInvoice::class, 'viewAny']],
        ['label' => 'Installments', 'group' => 'Sales', 'route' => 'installments.index', 'keywords' => ['installment plans', 'emi'], 'gate' => [InstallmentPlan::class, 'viewAny']],

        ['label' => 'Returns', 'group' => 'After sales', 'route' => 'returns.index', 'keywords' => ['return orders', 'refund'], 'gate' => [ReturnOrder::class, 'viewAny']],
        ['label' => 'Service jobs', 'group' => 'After sales', 'route' => 'service-jobs.index', 'keywords' => ['repair', 'warranty', 'service'], 'gate' => [ServiceJob::class, 'viewAny']],

        ['label' => 'Reports hub', 'group' => 'Reports', 'route' => 'reports.index', 'keywords' => ['reports', 'export'], 'permission' => 'reports.view'],
        ['label' => 'Sales forecast', 'group' => 'Reports', 'route' => 'forecasts.index', 'keywords' => ['demand', 'forecast'], 'gate' => [DemandForecast::class, 'viewAny']],
        ['label' => 'Stock summary report', 'group' => 'Reports', 'route' => 'reports.stock-summary', 'permission' => 'reports.stock.view'],
        ['label' => 'Stock by location report', 'group' => 'Reports', 'route' => 'reports.stock-by-location', 'permission' => 'reports.stock.view'],
        ['label' => 'Branch in-stock pricing', 'group' => 'Reports', 'route' => 'reports.branch-in-stock-pricing', 'keywords' => ['mrp', 'discount', 'in stock'], 'permission' => 'reports.stock.view'],
        ['label' => 'Transfer ledger', 'group' => 'Reports', 'route' => 'reports.transfer-ledger', 'keywords' => ['transfer', 'stock ledger', 'inter branch'], 'permission' => 'reports.stock.view'],
        ['label' => 'Low stock report', 'group' => 'Reports', 'route' => 'reports.low-stock', 'permission' => 'reports.stock.view'],
        ['label' => 'Sales summary report', 'group' => 'Reports', 'route' => 'reports.sales-summary', 'permission' => 'reports.sales.view'],
        ['label' => 'Month-to-date sales', 'group' => 'Reports', 'route' => 'reports.month-to-date-sales', 'keywords' => ['mtd', 'month sales'], 'permission' => 'reports.sales.view'],
        ['label' => 'Daily sales by branch', 'group' => 'Reports', 'route' => 'reports.sales-by-day-branch', 'permission' => 'reports.sales.view'],
        ['label' => 'Branch targets', 'group' => 'Reports', 'route' => 'reports.branch-targets', 'keywords' => ['target', 'sales target', 'collection target'], 'permission' => 'reports.sales.view'],
        ['label' => 'Daily expenses by branch', 'group' => 'Reports', 'route' => 'reports.daily-expenses-by-branch', 'keywords' => ['expenses'], 'permission' => 'reports.finance.view'],
        ['label' => 'Profit loss sheet', 'group' => 'Reports', 'route' => 'reports.profit-loss-sheet', 'keywords' => ['profit', 'loss', 'p&l'], 'permission' => 'reports.finance.view'],
        ['label' => 'Income & expenditure', 'group' => 'Reports', 'route' => 'reports.income-expenditure-statement', 'keywords' => ['income', 'expenditure'], 'permission' => 'reports.finance.view'],
        ['label' => 'Customer payments by branch', 'group' => 'Reports', 'route' => 'reports.customer-payment-by-branch', 'permission' => 'reports.receivables.view'],
        ['label' => 'Installment overdue', 'group' => 'Reports', 'route' => 'reports.installment-overdue', 'permission' => 'reports.receivables.view'],
        ['label' => 'Overdue receivables', 'group' => 'Reports', 'route' => 'reports.credit-overdue', 'permission' => 'reports.receivables.view'],
        ['label' => 'Customer dues', 'group' => 'Reports', 'route' => 'reports.customer-dues', 'permission' => 'reports.receivables.view'],
        ['label' => 'Invoice outstanding by customer', 'group' => 'Reports', 'route' => 'reports.invoice-outstanding-by-customer', 'permission' => 'reports.receivables.view'],
        ['label' => 'Open purchase orders report', 'group' => 'Reports', 'route' => 'reports.open-purchase-orders', 'permission' => 'reports.payables.view'],
        ['label' => 'Supplier dues & payments', 'group' => 'Reports', 'route' => 'reports.supplier-dues-and-payments', 'permission' => 'reports.payables.view'],

        ['label' => 'Users', 'group' => 'Administration', 'route' => 'users.index', 'permission' => 'user.view-any'],
        ['label' => 'Roles & permissions', 'group' => 'Administration', 'route' => 'roles.index', 'permission' => 'role.view-any', 'keywords' => ['permissions', 'access']],

        ['label' => 'My profile', 'group' => 'Account', 'route' => 'profile.edit', 'keywords' => ['profile', 'account']],
        ['label' => 'Change password', 'group' => 'Account', 'route' => 'profile.password.edit', 'keywords' => ['password', 'security']],
    ],
];
