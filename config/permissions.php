<?php

/**
 * The permission catalogue.
 *
 * Every slug the application can check must be declared here. `App\Support\PermissionRegistry`
 * reads this file, `PermissionSyncService` writes it into the `permissions` table, and the role
 * editor renders it verbatim. `PermissionCatalogCoverageTest` fails the build if a policy ability
 * resolves to a slug that is missing below, so the catalogue cannot silently drift from the code.
 */
$crudLabels = [
    'view-any' => ['label' => 'View list', 'description' => 'Open the module and browse its list screen.'],
    'view' => ['label' => 'Open details', 'description' => 'Open a single record.'],
    'create' => ['label' => 'Create', 'description' => 'Add new records.'],
    'update' => ['label' => 'Edit', 'description' => 'Change existing records.'],
    'delete' => ['label' => 'Delete', 'description' => 'Permanently remove records.', 'sensitive' => true],
];

/**
 * Expand a resource prefix into its standard CRUD rows, in a stable order.
 *
 * @param  list<string>  $abilities
 * @return array<string, array{label: string, description: string, sensitive?: bool}>
 */
$crud = static function (string $prefix, array $abilities = ['view-any', 'view', 'create', 'update', 'delete']) use ($crudLabels): array {
    $rows = [];
    foreach ($abilities as $ability) {
        $rows[$prefix.'.'.$ability] = $crudLabels[$ability];
    }

    return $rows;
};

return [

    /*
    |--------------------------------------------------------------------------
    | Modules
    |--------------------------------------------------------------------------
    |
    | Presentation order for the role editor. Each group is one resource or one
    | coherent capability; each row is exactly one permission slug.
    |
    */

    'modules' => [

        'administration' => [
            'label' => 'Administration',
            'description' => 'User accounts, roles, audit trail and global application settings.',
            'groups' => [
                'user' => [
                    'label' => 'User accounts',
                    'permissions' => $crud('user') + [
                        'user.impersonate' => [
                            'label' => 'Sign in as another user',
                            'description' => 'Impersonate any account to reproduce what that user sees.',
                            'sensitive' => true,
                        ],
                    ],
                ],
                'role' => [
                    'label' => 'Roles',
                    'permissions' => $crud('role') + [
                        'role.assign-permissions' => [
                            'label' => 'Change role permissions',
                            'description' => 'Tick and untick permissions on a role. Without this a user can rename roles but not change what they unlock.',
                            'sensitive' => true,
                        ],
                    ],
                ],
                'audit-log' => [
                    'label' => 'Audit log',
                    'permissions' => $crud('audit-log'),
                ],
                'settings' => [
                    'label' => 'Application settings',
                    'permissions' => $crud('app-setting') + [
                        'settings.company-pad.manage' => [
                            'label' => 'Company pad & print branding',
                            'description' => 'Letterhead, print layout and the global maximum discount cap.',
                            'sensitive' => true,
                        ],
                    ],
                ],
            ],
        ],

        'catalog' => [
            'label' => 'Catalog',
            'description' => 'Products, serial registry and the classification behind them.',
            'groups' => [
                'product' => [
                    'label' => 'Products',
                    'permissions' => $crud('product') + [
                        'product.global-search-view' => [
                            'label' => 'Open a product from global search only',
                            'description' => 'Look up a product and read its detail page without access to the catalog list.',
                        ],
                        'product.bulk-update' => [
                            'label' => 'Bulk price update',
                            'description' => 'Export, import and apply price changes across many products at once.',
                            'sensitive' => true,
                        ],
                        'product.view-purchase-price' => [
                            'label' => 'See purchase price',
                            'description' => 'Show cost price on the product page instead of only sale price and discount limit.',
                            'sensitive' => true,
                        ],
                    ],
                ],
                'serial-item' => [
                    'label' => 'Serial registry',
                    'permissions' => $crud('serial-item'),
                ],
                'brand' => [
                    'label' => 'Brands',
                    'permissions' => $crud('brand'),
                ],
                'category' => [
                    'label' => 'Categories',
                    'permissions' => $crud('category'),
                ],
            ],
        ],

        'partners' => [
            'label' => 'Customers & suppliers',
            'description' => 'Trading partner records and customer location tracking.',
            'groups' => [
                'customer' => [
                    'label' => 'Customers',
                    'permissions' => $crud('customer'),
                ],
                'customer-tracking' => [
                    'label' => 'Customer location tracking',
                    'permissions' => [
                        'customer.tracking.view' => [
                            'label' => 'View tracked locations',
                            'description' => 'Open the tracking map and see recorded customer coordinates.',
                            'sensitive' => true,
                        ],
                        'customer.tracking.update' => [
                            'label' => 'Edit tracked locations',
                            'description' => 'Move or clear a customer pin on the tracking map.',
                            'sensitive' => true,
                        ],
                    ],
                ],
                'supplier' => [
                    'label' => 'Suppliers',
                    'permissions' => $crud('supplier'),
                ],
            ],
        ],

        'branches' => [
            'label' => 'Branches',
            'description' => 'Branch records, branch-level pricing and sales targets.',
            'groups' => [
                'location' => [
                    'label' => 'Branches',
                    'permissions' => $crud('location') + [
                        'location.view-all' => [
                            'label' => 'Work across every branch',
                            'description' => 'See data for all branches instead of only the branches assigned to this user.',
                            'sensitive' => true,
                        ],
                    ],
                ],
                'location-product-price' => [
                    'label' => 'Branch product pricing',
                    'permissions' => $crud('location-product-price'),
                ],
                'branch-annual-target' => [
                    'label' => 'Annual targets',
                    'permissions' => $crud('branch-annual-target'),
                ],
                'branch-monthly-target' => [
                    'label' => 'Monthly targets',
                    'permissions' => $crud('branch-monthly-target'),
                ],
            ],
        ],

        'purchasing' => [
            'label' => 'Purchasing',
            'description' => 'Purchase orders, supplier payments and goods receipts.',
            'groups' => [
                'purchase-order' => [
                    'label' => 'Purchase orders',
                    'permissions' => $crud('purchase-order') + [
                        'purchase-order.approve' => [
                            'label' => 'Approve purchase order',
                            'description' => 'Move a draft purchase order to approved.',
                            'sensitive' => true,
                        ],
                        'purchase-order.record-payment' => [
                            'label' => 'Record supplier payment',
                            'description' => 'Pay a supplier against a purchase order, including refunds received.',
                            'sensitive' => true,
                        ],
                        'purchase-order.manage-additional-cost' => [
                            'label' => 'Add landed costs',
                            'description' => 'Attach freight, duty and other costs that change unit cost.',
                            'sensitive' => true,
                        ],
                    ],
                ],
                'purchase-order-line' => [
                    'label' => 'Purchase order lines',
                    'permissions' => $crud('purchase-order-line'),
                ],
                'goods-receipt' => [
                    'label' => 'Goods receipts',
                    'permissions' => $crud('goods-receipt') + [
                        'goods-receipt.post' => [
                            'label' => 'Post receipt into stock',
                            'description' => 'Commit a goods receipt so serials and quantities become live stock.',
                            'sensitive' => true,
                        ],
                    ],
                ],
                'goods-receipt-line' => [
                    'label' => 'Goods receipt lines',
                    'permissions' => $crud('goods-receipt-line'),
                ],
            ],
        ],

        'inventory' => [
            'label' => 'Inventory',
            'description' => 'Stock on hand, the movement ledger and inter-branch transfers.',
            'groups' => [
                'stock-levels' => [
                    'label' => 'Stock levels',
                    'permissions' => [
                        'inventory.stock-manage' => [
                            'label' => 'Manage stock levels & adjustments',
                            'description' => 'Open stock by branch, post manual adjustments, delete serials and resync balances.',
                            'sensitive' => true,
                        ],
                    ] + $crud('inventory-balance'),
                ],
                'stock-ledger' => [
                    'label' => 'Stock ledger',
                    'permissions' => $crud('stock-ledger'),
                ],
                'stock-transfer' => [
                    'label' => 'Stock transfers',
                    'permissions' => $crud('stock-transfer') + [
                        'stock-transfer.approve' => [
                            'label' => 'Approve either side',
                            'description' => 'Approve a transfer for any branch this user can act for.',
                            'sensitive' => true,
                        ],
                        'stock-transfer.approve-source' => [
                            'label' => 'Approve sending branch',
                            'description' => 'Sign off the branch the stock leaves.',
                            'sensitive' => true,
                        ],
                        'stock-transfer.approve-destination' => [
                            'label' => 'Approve receiving branch',
                            'description' => 'Sign off the branch the stock arrives at.',
                            'sensitive' => true,
                        ],
                        'stock-transfer.issue' => [
                            'label' => 'Issue stock',
                            'description' => 'Release approved stock from the sending branch.',
                        ],
                        'stock-transfer.receive' => [
                            'label' => 'Receive stock',
                            'description' => 'Book stock in at the receiving branch.',
                        ],
                        'stock-transfer.cancel' => [
                            'label' => 'Cancel transfer',
                            'description' => 'Cancel a draft or approved transfer before it is issued.',
                            'sensitive' => true,
                        ],
                    ],
                ],
                'stock-transfer-line' => [
                    'label' => 'Transfer lines',
                    'permissions' => $crud('stock-transfer-line') + $crud('stock-transfer-serial'),
                ],
            ],
        ],

        'sales' => [
            'label' => 'Sales & billing',
            'description' => 'Invoicing, pricing authority, customer payments and installments.',
            'groups' => [
                'sales-invoice' => [
                    'label' => 'Sales invoices',
                    'permissions' => $crud('sales-invoice') + [
                        'sales-invoice.post' => [
                            'label' => 'Post invoice',
                            'description' => 'Commit a draft invoice: deduct stock and raise the receivable.',
                            'sensitive' => true,
                        ],
                        'sales-invoice.print' => [
                            'label' => 'Print invoice',
                            'description' => 'Open the printable invoice.',
                        ],
                        'sales-invoice.activate-installment' => [
                            'label' => 'Activate installment billing',
                            'description' => 'Turn a posted invoice into an installment plan.',
                            'sensitive' => true,
                        ],
                        'sales-invoice.revise-active' => [
                            'label' => 'Revise a posted invoice',
                            'description' => 'Reopen and edit an invoice that has already been posted or activated.',
                            'sensitive' => true,
                        ],
                        'sales-invoice.change-sale-type' => [
                            'label' => 'Change sale type after posting',
                            'description' => 'Switch a posted invoice between cash and credit.',
                            'sensitive' => true,
                        ],
                        'sales-invoice.view-trashed' => [
                            'label' => 'View deleted invoices',
                            'description' => 'Open the trash and browse invoices that have been soft-deleted.',
                            'sensitive' => true,
                        ],
                        'sales-invoice.restore' => [
                            'label' => 'Restore deleted invoice',
                            'description' => 'Bring a soft-deleted invoice back out of the trash.',
                            'sensitive' => true,
                        ],
                        'sales-invoice.force-delete' => [
                            'label' => 'Permanently delete invoice',
                            'description' => 'From the trash, irreversibly erase an invoice and roll back its stock, receivable, payment and journal effects.',
                            'sensitive' => true,
                        ],
                        'sales.invoice.backdate' => [
                            'label' => 'Back-date invoices & payments',
                            'description' => 'Set an invoice or payment date earlier than today.',
                            'sensitive' => true,
                        ],
                    ],
                ],
                'sales-invoice-line' => [
                    'label' => 'Invoice lines',
                    'permissions' => $crud('sales-invoice-line') + $crud('sale-line-serial'),
                ],
                'sales-pricing' => [
                    'label' => 'Pricing authority',
                    'permissions' => [
                        'sales.discount.apply' => [
                            'label' => 'Apply discounts',
                            'description' => 'Give line or invoice discounts within the configured cap.',
                            'sensitive' => true,
                        ],
                        'sales.maximum-discount.override' => [
                            'label' => 'Exceed the discount cap',
                            'description' => 'Discount beyond the product or global maximum.',
                            'sensitive' => true,
                        ],
                        'sales.settlement-discount.apply' => [
                            'label' => 'Apply settlement discount',
                            'description' => 'Write down a balance while collecting payment on the payment register.',
                            'sensitive' => true,
                        ],
                        'pricing.override.approve' => [
                            'label' => 'Approve price overrides',
                            'description' => 'Authorise selling below the price floor.',
                            'sensitive' => true,
                        ],
                        'sales.invoice.view-line-profit' => [
                            'label' => 'See cost & profit on invoice lines',
                            'description' => 'Show purchase price and margin instead of only the discount limit.',
                            'sensitive' => true,
                        ],
                        'debt.write-off' => [
                            'label' => 'Write off receivable debt',
                            'description' => 'Clear an outstanding invoice balance as uncollectable.',
                            'sensitive' => true,
                        ],
                        'debt.write-off.remove' => [
                            'label' => 'Remove a debt write-off',
                            'description' => 'Undo a write-off on an invoice, restoring the balance and reversing its ledger and journal entries.',
                            'sensitive' => true,
                        ],
                    ],
                ],
                'payment' => [
                    'label' => 'Customer payments',
                    'permissions' => $crud('payment') + [
                        'payment.collect-flexible' => [
                            'label' => 'Collect outside the schedule',
                            'description' => 'Take part payments and amounts that do not match the due installment.',
                            'sensitive' => true,
                        ],
                        'sales.payment-allocation.update' => [
                            'label' => 'Change payment method after the fact',
                            'description' => 'Re-point a recorded payment at a different method or wallet.',
                            'sensitive' => true,
                        ],
                        'sales.payment-allocation.delete' => [
                            'label' => 'Delete a recorded payment',
                            'description' => 'Remove a payment allocation from an invoice or purchase order.',
                            'sensitive' => true,
                        ],
                    ] + $crud('payment-allocation'),
                ],
                'installment' => [
                    'label' => 'Installments',
                    'permissions' => $crud('installment-plan') + [
                        'installment.allow-same-day-duplicate' => [
                            'label' => 'Allow same-day duplicate installment payment',
                            'description' => 'Bypass the one-payment-per-day-per-invoice restriction on installment invoices.',
                            'sensitive' => true,
                        ],
                        'installment-plan.reschedule' => [
                            'label' => 'Reschedule installments',
                            'description' => 'Move due dates or restructure an active plan.',
                            'sensitive' => true,
                        ],
                    ] + $crud('installment-schedule') + $crud('installment-payment-allocation'),
                ],
                'accounts-receivable-ledger' => [
                    'label' => 'Receivable ledger',
                    'permissions' => $crud('accounts-receivable-ledger'),
                ],
            ],
        ],

        'aftersales' => [
            'label' => 'After sales',
            'description' => 'Returns, refunds and service jobs.',
            'groups' => [
                'return-order' => [
                    'label' => 'Returns',
                    'permissions' => $crud('return-order') + [
                        'return-order.approve' => [
                            'label' => 'Approve return',
                            'description' => 'Accept a submitted return request.',
                            'sensitive' => true,
                        ],
                        'return-order.resolve' => [
                            'label' => 'Resolve return',
                            'description' => 'Complete the return by restocking, exchanging or writing off.',
                            'sensitive' => true,
                        ],
                        'return-order.pay-refund' => [
                            'label' => 'Pay refund',
                            'description' => 'Release money back to the customer.',
                            'sensitive' => true,
                        ],
                        'return-order.rollback' => [
                            'label' => 'Roll back a resolved return',
                            'description' => 'Undo a completed return and reverse its stock and money effects.',
                            'sensitive' => true,
                        ],
                    ],
                ],
                'service-job' => [
                    'label' => 'Service jobs',
                    'permissions' => $crud('service-job'),
                ],
            ],
        ],

        'finance' => [
            'label' => 'Finance & treasury',
            'description' => 'Wallets, chart of accounts, payment routing and the journal.',
            'groups' => [
                'wallet' => [
                    'label' => 'Wallets',
                    'permissions' => $crud('wallet') + [
                        'wallet.view-balance' => [
                            'label' => 'See balances only',
                            'description' => 'Read current wallet balances without opening detail pages or transaction history.',
                        ],
                        'wallet.owner-capital.manage' => [
                            'label' => 'Operate the owner capital wallet',
                            'description' => 'Move money in and out of owner capital.',
                            'sensitive' => true,
                        ],
                    ],
                ],
                'ledger-account' => [
                    'label' => 'Ledger accounts',
                    'permissions' => $crud('ledger-account'),
                ],
                'payment-method' => [
                    'label' => 'Payment methods',
                    'permissions' => $crud('payment-method') + [
                        'payment-method.toggle-active' => [
                            'label' => 'Enable or disable a method',
                            'description' => 'Turn a payment method on or off without editing it.',
                        ],
                    ],
                ],
                'payment-method-wallet-map' => [
                    'label' => 'Payment routing',
                    'permissions' => $crud('payment-method-wallet-map'),
                ],
                'journal-entry' => [
                    'label' => 'Journal entries',
                    'permissions' => $crud('journal-entry', ['view-any', 'view', 'update', 'delete']) + [
                        'journal-entry.create-expense' => [
                            'label' => 'Post operating expenses',
                            'description' => 'Create expense entries.',
                            'sensitive' => true,
                        ],
                        'journal-entry.create-income' => [
                            'label' => 'Post other income',
                            'description' => 'Create income entries.',
                            'sensitive' => true,
                        ],
                        'journal-entry.create-transfer' => [
                            'label' => 'Post wallet transfers',
                            'description' => 'Move money between wallets.',
                            'sensitive' => true,
                        ],
                        'journal-entry.create-opening-balance' => [
                            'label' => 'Post wallet opening balances',
                            'description' => 'Seed a wallet with its starting balance.',
                            'sensitive' => true,
                        ],
                        'journal-entry.post' => [
                            'label' => 'Post a draft entry',
                            'description' => 'Commit a draft journal entry to the ledger.',
                            'sensitive' => true,
                        ],
                        'journal-entry.delete-posted' => [
                            'label' => 'Delete a posted entry',
                            'description' => 'Remove a journal entry that has already been posted.',
                            'sensitive' => true,
                        ],
                    ],
                ],
            ],
        ],

        'reports' => [
            'label' => 'Reports',
            'description' => 'Reporting hub, split by subject so financial statements can stay restricted.',
            'groups' => [
                'reports' => [
                    'label' => 'Report access',
                    'permissions' => [
                        'reports.view' => [
                            'label' => 'Open the reports hub',
                            'description' => 'Reach the reports section. Each subject below still needs its own permission.',
                        ],
                        'reports.stock.view' => [
                            'label' => 'Stock reports',
                            'description' => 'Stock by location, stock summary, branch in-stock pricing, low stock, transfer ledger.',
                        ],
                        'reports.sales.view' => [
                            'label' => 'Sales reports',
                            'description' => 'Sales summary, month to date, item-wise, representative-wise, daily sales by branch, branch targets.',
                        ],
                        'reports.receivables.view' => [
                            'label' => 'Receivables reports',
                            'description' => 'Customer dues, outstanding by customer, overdue credit and installments, collections, customer journal.',
                            'sensitive' => true,
                        ],
                        'reports.customer_risk.view' => [
                            'label' => 'Customer profile & risk',
                            'description' => 'Full customer profile with guarantors, installment punctuality, late-payment trend, risk grade and suggested installment limit.',
                            'sensitive' => true,
                        ],
                        'reports.yearly_collection.view' => [
                            'label' => 'Yearly collection sheet',
                            'description' => 'Unpaid installment invoices by customer with a month-by-month paid/missed grid across a year range.',
                            'sensitive' => true,
                        ],
                        'reports.payables.view' => [
                            'label' => 'Purchase & payable reports',
                            'description' => 'Open purchase orders, supplier dues and payments, item-wise purchase.',
                            'sensitive' => true,
                        ],
                        'reports.finance.view' => [
                            'label' => 'Financial statements',
                            'description' => 'Profit & loss, income & expenditure, daily expenses, wallet transactions.',
                            'sensitive' => true,
                        ],
                        'reports.export' => [
                            'label' => 'Download & print reports',
                            'description' => 'Export to Excel or print. Applies only to reports the user can already view.',
                            'sensitive' => true,
                        ],
                    ],
                ],
                'demand-forecast' => [
                    'label' => 'Demand forecast',
                    'permissions' => $crud('demand-forecast'),
                ],
            ],
        ],

        'hr' => [
            'label' => 'Human resources',
            'description' => 'Employee records, attendance, leave and payroll.',
            'groups' => [
                'employee' => [
                    'label' => 'Employees',
                    'permissions' => $crud('employee'),
                ],
                'hr-org' => [
                    'label' => 'Organisation setup',
                    'permissions' => $crud('hr-department')
                        + $crud('hr-designation')
                        + $crud('pay-scale')
                        + $crud('employee-pay-scale-assignment'),
                ],
                'attendance-record' => [
                    'label' => 'Attendance',
                    'permissions' => $crud('attendance-record'),
                ],
                'leave' => [
                    'label' => 'Leave',
                    'permissions' => $crud('leave-type') + $crud('leave-request') + [
                        'leave-request.approve' => [
                            'label' => 'Approve leave',
                            'description' => 'Grant a submitted leave request.',
                        ],
                        'leave-request.reject' => [
                            'label' => 'Reject leave',
                            'description' => 'Decline a submitted leave request.',
                        ],
                    ],
                ],
                'salary-sheet' => [
                    'label' => 'Payroll',
                    'permissions' => $crud('salary-sheet') + [
                        'salary-sheet.generate' => [
                            'label' => 'Generate salary sheet',
                            'description' => 'Build a draft payroll run for a period.',
                            'sensitive' => true,
                        ],
                        'salary-sheet.finalize' => [
                            'label' => 'Finalise salary sheet',
                            'description' => 'Lock a payroll run so it can no longer be edited.',
                            'sensitive' => true,
                        ],
                    ],
                ],
            ],
        ],

        'dashboard' => [
            'label' => 'Dashboard',
            'description' => 'Optional widgets on the landing dashboard.',
            'groups' => [
                'dashboard' => [
                    'label' => 'Widgets',
                    'permissions' => [
                        'dashboard.attendance.view' => [
                            'label' => 'Attendance widget',
                            'description' => 'Show today\'s attendance summary on the dashboard.',
                        ],
                        'dashboard.targets.view' => [
                            'label' => 'Targets widget',
                            'description' => 'Show branch target progress on the dashboard.',
                        ],
                    ],
                ],
            ],
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Report subjects
    |--------------------------------------------------------------------------
    |
    | Maps each report route name / export slug onto the permission that unlocks
    | it. Anything not listed falls back to `reports.view`.
    |
    */

    'report_subjects' => [
        'stock-by-location' => 'reports.stock.view',
        'branch-in-stock-pricing' => 'reports.stock.view',
        'stock-summary' => 'reports.stock.view',
        'low-stock' => 'reports.stock.view',
        'transfer-ledger' => 'reports.stock.view',

        'sales-summary' => 'reports.sales.view',
        'month-to-date-sales' => 'reports.sales.view',
        'item-wise-sales' => 'reports.sales.view',
        'representative-wise-sales' => 'reports.sales.view',
        'sales-by-day-branch' => 'reports.sales.view',
        'branch-targets' => 'reports.sales.view',

        'customer-dues' => 'reports.receivables.view',
        'invoice-outstanding-by-customer' => 'reports.receivables.view',
        'credit-overdue' => 'reports.receivables.view',
        'installment-overdue' => 'reports.receivables.view',
        'monthly-collection' => 'reports.receivables.view',
        'customer-payment-by-branch' => 'reports.receivables.view',
        'customer-journal' => 'reports.receivables.view',
        'customer-risk' => 'reports.customer_risk.view',
        'high-risk-customers' => 'reports.customer_risk.view',
        'yearly-collection-sheet' => 'reports.yearly_collection.view',

        'open-purchase-orders' => 'reports.payables.view',
        'supplier-dues-and-payments' => 'reports.payables.view',
        'item-wise-purchase' => 'reports.payables.view',

        'profit-loss-sheet' => 'reports.finance.view',
        'income-expenditure-statement' => 'reports.finance.view',
        'daily-expenses-by-branch' => 'reports.finance.view',
        'wallet-transactions' => 'reports.finance.view',
    ],

    /*
    |--------------------------------------------------------------------------
    | Admin bypass exceptions
    |--------------------------------------------------------------------------
    |
    | Administrators short-circuit every gate. These abilities are excluded so
    | the policy method still runs — either because it enforces a document state
    | guard that must apply to everyone, or because the capability is deliberately
    | opt-in even for admins.
    |
    */

    'admin_bypass_exceptions' => [
        'override-maximum-discount',
        'overrideMaximumDiscount',
        'changeSaleType',
        'reviseActive',
    ],

    /*
    |--------------------------------------------------------------------------
    | Retired slugs
    |--------------------------------------------------------------------------
    |
    | Removed from the catalogue. `aliases` below decides what an existing role
    | receives in their place; the sync command prunes the rows themselves.
    |
    */

    'retired' => [
        'base-model.view-any',
        'base-model.view',
        'base-model.create',
        'base-model.update',
        'base-model.delete',
        'users.manage',
        'users.create',
        'roles.manage',
        'permissions.assign',
        'returns.approve',
        'transfers.approve',
        'goods-receipts.post',
        'installments.reschedule',
        'journal-entry.create',
        'journal-entry.create-wallet-opening-balance',
    ],

    /*
    |--------------------------------------------------------------------------
    | Aliases
    |--------------------------------------------------------------------------
    |
    | Old slug => the canonical slugs a role holding it should end up with. Used
    | once by the upgrade migration and by `permissions:sync` when re-running.
    |
    */

    'aliases' => [
        'users.manage' => ['user.view-any', 'user.view', 'user.update', 'user.impersonate'],
        'users.create' => ['user.create'],
        'roles.manage' => ['role.view-any', 'role.view', 'role.create', 'role.update', 'role.delete', 'role.assign-permissions'],
        'permissions.assign' => ['role.assign-permissions'],
        'returns.approve' => ['return-order.approve', 'return-order.resolve', 'return-order.pay-refund', 'return-order.rollback'],
        'transfers.approve' => ['stock-transfer.approve'],
        'goods-receipts.post' => ['goods-receipt.post'],
        'installments.reschedule' => ['installment-plan.reschedule'],
        'journal-entry.create' => [
            'journal-entry.create-expense',
            'journal-entry.create-income',
            'journal-entry.create-transfer',
            'journal-entry.create-opening-balance',
        ],
        'journal-entry.create-wallet-opening-balance' => ['journal-entry.create-opening-balance'],
    ],

];
