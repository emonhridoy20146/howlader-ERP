<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\User;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

/**
 * Single source of truth for the sidebar menu.
 *
 * A group holds subsections, a subsection holds links. Anything the current
 * user cannot reach is stripped here, so Blade only renders what it may show
 * and empty groups never reach the DOM.
 *
 * Visibility on any node may be declared with:
 *   'can'        => [ability, model]  — policy check
 *   'gate'       => 'gate-name'       — named gate
 *   'permission' => 'slug'            — raw permission slug
 *   'report'     => 'report-slug'     — the report subject that unlocks the page
 * Rules on a parent are inherited by everything beneath it.
 */
final class Navigation
{
    /**
     * The menu the given user is allowed to see.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function forUser(?User $user): array
    {
        if (! $user) {
            return [];
        }

        $groups = [];

        foreach (self::definition() as $group) {
            if (! self::passes($group, $user)) {
                continue;
            }

            $sections = [];

            foreach ($group['sections'] as $section) {
                if (! self::passes($section, $user)) {
                    continue;
                }

                $items = [];

                foreach ($section['items'] as $item) {
                    if ($built = self::buildItem($item, $user)) {
                        $items[] = $built;
                    }
                }

                if ($items === []) {
                    continue;
                }

                $sections[] = [
                    'key' => $section['key'],
                    'title' => $section['title'] ?? null,
                    'items' => $items,
                    'search' => array_column($items, 'search'),
                    'active' => self::hasActive($items),
                ];
            }

            if ($sections === []) {
                continue;
            }

            $search = array_merge(...array_column($sections, 'search'));

            $groups[] = [
                'key' => $group['key'],
                'title' => $group['title'],
                'flat' => $group['flat'] ?? false,
                'sections' => $sections,
                'search' => $search,
                'count' => count($search),
                'active' => self::hasActive($sections),
            ];
        }

        return $groups;
    }

    /**
     * Key of the group holding the current page, so the sidebar can open it.
     *
     * @param  array<int, array<string, mixed>>  $groups
     */
    public static function activeGroupKey(array $groups): ?string
    {
        foreach ($groups as $group) {
            if ($group['active'] && ! $group['flat']) {
                return $group['key'];
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>|null
     */
    private static function buildItem(array $item, User $user): ?array
    {
        if (! self::passes($item, $user) || ! Route::has($item['route'])) {
            return null;
        }

        $label = value($item['label'], $user);
        $match = (array) ($item['match'] ?? $item['route']);

        return [
            'key' => $item['route'],
            'label' => $label,
            'url' => route($item['route']),
            'icon' => $item['icon'],
            'active' => request()->routeIs(...$match),
            'search' => Str::lower(trim($label.' '.($item['keywords'] ?? ''))),
        ];
    }

    /**
     * @param  array<string, mixed>  $node
     */
    private static function passes(array $node, User $user): bool
    {
        if (isset($node['can']) && ! $user->can($node['can'][0], $node['can'][1])) {
            return false;
        }

        if (isset($node['gate']) && ! $user->can($node['gate'])) {
            return false;
        }

        if (isset($node['report']) && ! $user->can('view-report', $node['report'])) {
            return false;
        }

        return ! isset($node['permission']) || $user->hasPermission($node['permission']);
    }

    /**
     * @param  array<int, array<string, mixed>>  $nodes
     */
    private static function hasActive(array $nodes): bool
    {
        foreach ($nodes as $node) {
            if ($node['active']) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private static function definition(): array
    {
        return [
            [
                'key' => 'home',
                'title' => 'Home',
                'flat' => true,
                'sections' => [
                    [
                        'key' => 'home.main',
                        'title' => null,
                        'items' => [
                            [
                                'label' => 'Dashboard',
                                'route' => 'dashboard',
                                'icon' => 'home',
                                'keywords' => 'overview home start summary',
                            ],
                        ],
                    ],
                ],
            ],

            [
                'key' => 'sales',
                'title' => 'Sales',
                'sections' => [
                    [
                        'key' => 'sales.invoicing',
                        'title' => 'Invoicing',
                        'items' => [
                            [
                                'label' => 'Invoices',
                                'route' => 'sales.invoices.index',
                                'match' => ['sales.invoices.*'],
                                'icon' => 'document-text',
                                'can' => ['viewAny', \App\Models\SalesInvoice::class],
                                'keywords' => 'sale sell bill pos order new sale',
                            ],
                            [
                                'label' => 'Installments',
                                'route' => 'installments.index',
                                'match' => ['installments.*'],
                                'icon' => 'calendar',
                                'can' => ['viewAny', \App\Models\InstallmentPlan::class],
                                'keywords' => 'emi instalment plan schedule finance',
                            ],
                            [
                                'label' => 'Credits',
                                'route' => 'credit.index',
                                'match' => ['credit.*'],
                                'icon' => 'document-currency',
                                'can' => ['viewAny', \App\Models\SalesInvoice::class],
                                'keywords' => 'credit due date overdue receivable',
                            ],
                        ],
                    ],
                    [
                        'key' => 'sales.collections',
                        'title' => 'Collections',
                        'items' => [
                            [
                                'label' => 'Payment register',
                                'route' => 'payment-register.index',
                                'match' => ['payment-register.*'],
                                'icon' => 'banknotes',
                                'can' => ['viewAny', \App\Models\SalesInvoice::class],
                                'keywords' => 'customer receipt money in collection cash',
                            ],
                        ],
                    ],
                    [
                        'key' => 'sales.after',
                        'title' => 'After sales',
                        'items' => [
                            [
                                'label' => 'Returns',
                                'route' => 'returns.index',
                                'match' => ['returns.*'],
                                'icon' => 'arrow-uturn-left',
                                'can' => ['viewAny', \App\Models\ReturnOrder::class],
                                'keywords' => 'refund exchange rma send back',
                            ],
                            [
                                'label' => 'Service jobs',
                                'route' => 'service-jobs.index',
                                'match' => ['service-jobs.*'],
                                'icon' => 'wrench',
                                'can' => ['viewAny', \App\Models\ServiceJob::class],
                                'keywords' => 'repair warranty ticket maintenance support',
                            ],
                        ],
                    ],
                ],
            ],

            [
                'key' => 'inventory',
                'title' => 'Inventory',
                'sections' => [
                    [
                        'key' => 'inventory.purchasing',
                        'title' => 'Purchasing',
                        'items' => [
                            [
                                'label' => 'Purchase orders',
                                'route' => 'purchase-orders.index',
                                'match' => ['purchase-orders.*'],
                                'icon' => 'clipboard-list',
                                'can' => ['viewAny', \App\Models\PurchaseOrder::class],
                                'keywords' => 'po buying procurement supplier order',
                            ],
                            [
                                'label' => 'Goods receipts',
                                'route' => 'goods-receipts.index',
                                'match' => ['goods-receipts.*'],
                                'icon' => 'archive-box',
                                'can' => ['viewAny', \App\Models\GoodsReceipt::class],
                                'keywords' => 'grn receive receiving inward delivery',
                            ],
                            [
                                'label' => 'Supplier payment register',
                                'route' => 'supplier-payment-register.index',
                                'match' => ['supplier-payment-register.*'],
                                'icon' => 'banknotes',
                                'can' => ['viewAny', \App\Models\PurchaseOrder::class],
                                'keywords' => 'vendor payout money out payable',
                            ],
                        ],
                    ],
                    [
                        'key' => 'inventory.stock',
                        'title' => 'Stock control',
                        'items' => [
                            [
                                'label' => 'Stock check',
                                'route' => 'inventory.stock-check.index',
                                'match' => ['inventory.stock-check.*'],
                                'icon' => 'magnifier-squares',
                                'can' => ['viewAny', \App\Models\Product::class],
                                'keywords' => 'lookup availability quantity on hand search stock',
                            ],
                            [
                                'label' => 'Stock by branch',
                                'route' => 'inventory.stock-levels.index',
                                'match' => ['inventory.stock-levels.*'],
                                'icon' => 'squares',
                                'can' => ['manageStockLevels', \App\Models\Product::class],
                                'keywords' => 'levels balance location warehouse quantity',
                            ],
                            [
                                'label' => 'Transfers',
                                'route' => 'transfers.index',
                                'match' => ['transfers.*'],
                                'icon' => 'arrows-right-left',
                                'can' => ['viewAny', \App\Models\StockTransfer::class],
                                'keywords' => 'move branch to branch shifting relocate',
                            ],
                            [
                                'label' => 'Serial registry',
                                'route' => 'serial-items.index',
                                'match' => ['serial-items.*'],
                                'icon' => 'qr-code',
                                'can' => ['viewAny', \App\Models\SerialItem::class],
                                'keywords' => 'imei barcode serial number tracking unit',
                            ],
                        ],
                    ],
                ],
            ],

            [
                'key' => 'catalog',
                'title' => 'Catalog',
                'sections' => [
                    [
                        'key' => 'catalog.main',
                        'title' => null,
                        'items' => [
                            [
                                'label' => 'Products',
                                'route' => 'products.index',
                                'match' => ['products.*'],
                                'icon' => 'cube',
                                'can' => ['viewAny', \App\Models\Product::class],
                                'keywords' => 'item sku goods price catalogue',
                            ],
                            [
                                'label' => 'Brands',
                                'route' => 'brands.index',
                                'match' => ['brands.*'],
                                'icon' => 'tag',
                                'can' => ['viewAny', \App\Models\Brand::class],
                                'keywords' => 'manufacturer make label',
                            ],
                            [
                                'label' => 'Categories',
                                'route' => 'categories.index',
                                'match' => ['categories.*'],
                                'icon' => 'rectangle-group',
                                'can' => ['viewAny', \App\Models\Category::class],
                                'keywords' => 'group type classification family',
                            ],
                        ],
                    ],
                ],
            ],

            [
                'key' => 'contacts',
                'title' => 'Contacts',
                'sections' => [
                    [
                        'key' => 'contacts.main',
                        'title' => null,
                        'items' => [
                            [
                                'label' => 'Customers',
                                'route' => 'customers.index',
                                'match' => ['customers.*'],
                                'icon' => 'users',
                                'can' => ['viewAny', \App\Models\Customer::class],
                                'keywords' => 'client buyer party account',
                            ],
                            [
                                'label' => 'High-risk customers',
                                'route' => 'reports.high-risk-customers',
                                'match' => ['reports.high-risk-customers'],
                                'icon' => 'exclamation-triangle',
                                'gate' => 'view-customer-risk',
                                'keywords' => 'risk grade late payment trend default collector watchlist',
                            ],
                            [
                                'label' => 'Customer tracking',
                                'route' => 'customer-tracking.index',
                                'match' => ['customer-tracking.*'],
                                'icon' => 'map-pin',
                                'can' => ['viewAnyTracking', \App\Models\Customer::class],
                                'keywords' => 'follow up visit location crm',
                            ],
                            [
                                'label' => 'Suppliers',
                                'route' => 'suppliers.index',
                                'match' => ['suppliers.*'],
                                'icon' => 'truck',
                                'can' => ['viewAny', \App\Models\Supplier::class],
                                'keywords' => 'vendor seller source party',
                            ],
                        ],
                    ],
                ],
            ],

            [
                'key' => 'finance',
                'title' => 'Finance',
                'sections' => [
                    [
                        'key' => 'finance.accounts',
                        'title' => 'Accounts & wallets',
                        'items' => [
                            [
                                'label' => 'Ledger accounts',
                                'route' => 'ledger-accounts.index',
                                'match' => ['ledger-accounts.*'],
                                'icon' => 'currency',
                                'can' => ['viewAny', \App\Models\LedgerAccount::class],
                                'keywords' => 'chart of accounts coa gl accounting',
                            ],
                            [
                                'label' => fn (User $user) => $user->hasPermission('wallet.view') ? 'Wallets' : 'Wallet balances',
                                'route' => 'wallets.index',
                                'match' => ['wallets.*'],
                                'icon' => 'wallet',
                                'can' => ['viewAny', \App\Models\Wallet::class],
                                'keywords' => 'cash bank balance float drawer',
                            ],
                            [
                                'label' => 'Payment methods',
                                'route' => 'payment-methods.index',
                                'match' => ['payment-methods.*'],
                                'icon' => 'credit-card',
                                'can' => ['viewAny', \App\Models\PaymentMethod::class],
                                'keywords' => 'cash card bkash mobile banking channel',
                            ],
                            [
                                'label' => 'Payment → wallet',
                                'route' => 'payment-method-wallet-maps.index',
                                'match' => ['payment-method-wallet-maps.*'],
                                'icon' => 'arrows-right-left',
                                'can' => ['viewAny', \App\Models\PaymentMethodWalletMap::class],
                                'keywords' => 'mapping route method wallet link',
                            ],
                        ],
                    ],
                    [
                        'key' => 'finance.books',
                        'title' => 'Bookkeeping',
                        'items' => [
                            [
                                'label' => 'Journal',
                                'route' => 'journal-entries.index',
                                'match' => ['journal-entries.*'],
                                'icon' => 'clipboard-document',
                                'can' => ['viewAny', \App\Models\JournalEntry::class],
                                'keywords' => 'entry voucher debit credit posting',
                            ],
                            [
                                'label' => 'Daily transactions',
                                'route' => 'daily-transactions.index',
                                'match' => ['daily-transactions.*'],
                                'icon' => 'calendar-grid',
                                'can' => ['viewAny', \App\Models\JournalEntry::class],
                                'keywords' => 'day book cash expense income daybook',
                            ],
                        ],
                    ],
                ],
            ],

            [
                'key' => 'reports',
                'title' => 'Reports',
                'sections' => [
                    [
                        'key' => 'reports.exports',
                        'title' => null,
                        'items' => [
                            [
                                'label' => 'Report downloads',
                                'route' => 'reports.index',
                                'icon' => 'arrow-down-tray',
                                'gate' => 'view-reports',
                                'keywords' => 'export excel csv pdf all reports',
                            ],
                        ],
                    ],
                    [
                        'key' => 'reports.sales',
                        'title' => 'Sales & customers',
                        'items' => [
                            [
                                'label' => 'Sales summary',
                                'route' => 'reports.sales-summary',
                                'icon' => 'chart-bar',
                                'report' => 'sales-summary',
                                'keywords' => 'revenue turnover total',
                            ],
                            [
                                'label' => 'Daily sales by branch',
                                'route' => 'reports.sales-by-day-branch',
                                'icon' => 'chart-squares',
                                'report' => 'sales-by-day-branch',
                                'keywords' => 'day location outlet revenue',
                            ],
                            [
                                'label' => 'Sales forecast',
                                'route' => 'forecasts.index',
                                'match' => ['forecasts.*'],
                                'icon' => 'chart-bar',
                                'can' => ['viewAny', \App\Models\DemandForecast::class],
                                'keywords' => 'demand prediction planning projection',
                            ],
                            [
                                'label' => 'Customer payments by branch',
                                'route' => 'reports.customer-payment-by-branch',
                                'icon' => 'receipt',
                                'report' => 'customer-payment-by-branch',
                                'keywords' => 'collection received money in outlet',
                            ],
                            [
                                'label' => 'Invoice outstanding by customer',
                                'route' => 'reports.invoice-outstanding-by-customer',
                                'icon' => 'document-currency',
                                'report' => 'invoice-outstanding-by-customer',
                                'keywords' => 'unpaid balance due receivable',
                            ],
                            [
                                'label' => 'Customer dues',
                                'route' => 'reports.customer-dues',
                                'icon' => 'table-cells',
                                'report' => 'customer-dues',
                                'keywords' => 'debt owing balance receivable',
                            ],
                            [
                                'label' => 'Yearly collection sheet',
                                'route' => 'reports.yearly-collection-sheet',
                                'icon' => 'table-cells',
                                'report' => 'yearly-collection-sheet',
                                'keywords' => 'year month grid unpaid installment missed ledger samity savings collection',
                            ],
                            [
                                'label' => 'High-risk customers',
                                'route' => 'reports.high-risk-customers',
                                'icon' => 'exclamation-triangle',
                                'report' => 'high-risk-customers',
                                'keywords' => 'risk grade late payment trend default watchlist',
                            ],
                            [
                                'label' => 'Overdue receivables',
                                'route' => 'reports.credit-overdue',
                                'icon' => 'clock',
                                'report' => 'credit-overdue',
                                'keywords' => 'late credit aging debt chase',
                            ],
                            [
                                'label' => 'Installment overdue',
                                'route' => 'reports.installment-overdue',
                                'icon' => 'exclamation-triangle',
                                'report' => 'installment-overdue',
                                'keywords' => 'emi late missed arrears',
                            ],
                        ],
                    ],
                    [
                        'key' => 'reports.inventory',
                        'title' => 'Inventory',
                        'items' => [
                            [
                                'label' => 'Stock summary',
                                'route' => 'reports.stock-summary',
                                'icon' => 'chart-stack',
                                'report' => 'stock-summary',
                                'keywords' => 'quantity valuation on hand total',
                            ],
                            [
                                'label' => 'Stock by location',
                                'route' => 'reports.stock-by-location',
                                'icon' => 'chart-squares',
                                'report' => 'stock-by-location',
                                'keywords' => 'branch warehouse outlet distribution',
                            ],
                            [
                                'label' => 'Low stock',
                                'route' => 'reports.low-stock',
                                'icon' => 'exclamation-circle',
                                'report' => 'low-stock',
                                'keywords' => 'reorder shortage minimum replenish',
                            ],
                        ],
                    ],
                    [
                        'key' => 'reports.purchasing',
                        'title' => 'Purchasing',
                        'items' => [
                            [
                                'label' => 'Open purchase orders',
                                'route' => 'reports.open-purchase-orders',
                                'icon' => 'archive-box',
                                'report' => 'open-purchase-orders',
                                'keywords' => 'pending po outstanding awaiting',
                            ],
                            [
                                'label' => 'Supplier dues & payments',
                                'route' => 'reports.supplier-dues-and-payments',
                                'icon' => 'scale',
                                'report' => 'supplier-dues-and-payments',
                                'keywords' => 'vendor payable owing settlement',
                            ],
                        ],
                    ],
                    [
                        'key' => 'reports.cash',
                        'title' => 'Cash & expenses',
                        'items' => [
                            [
                                'label' => 'Daily expenses by branch',
                                'route' => 'reports.daily-expenses-by-branch',
                                'icon' => 'currency',
                                'report' => 'daily-expenses-by-branch',
                                'keywords' => 'cost spending outlet day',
                            ],
                        ],
                    ],
                ],
            ],

            [
                'key' => 'hr',
                'title' => 'Human Resources',
                'sections' => [
                    [
                        'key' => 'hr.people',
                        'title' => 'People',
                        'items' => [
                            [
                                'label' => 'Employees',
                                'route' => 'hr.employees.index',
                                'match' => ['hr.employees.*'],
                                'icon' => 'users',
                                'can' => ['viewAny', \App\Models\Employee::class],
                                'keywords' => 'staff worker personnel team',
                            ],
                            [
                                'label' => 'Departments',
                                'route' => 'hr.departments.index',
                                'match' => ['hr.departments.*'],
                                'icon' => 'building',
                                'can' => ['viewAny', \App\Models\HrDepartment::class],
                                'keywords' => 'division unit team org',
                            ],
                            [
                                'label' => 'Designations',
                                'route' => 'hr.designations.index',
                                'match' => ['hr.designations.*'],
                                'icon' => 'briefcase',
                                'can' => ['viewAny', \App\Models\HrDesignation::class],
                                'keywords' => 'job title position role grade',
                            ],
                        ],
                    ],
                    [
                        'key' => 'hr.time',
                        'title' => 'Time & leave',
                        'items' => [
                            [
                                'label' => 'Attendance',
                                'route' => 'hr.attendance.index',
                                'match' => ['hr.attendance.*'],
                                'icon' => 'calendar',
                                'can' => ['viewAny', \App\Models\AttendanceRecord::class],
                                'keywords' => 'present absent punch roster timesheet',
                            ],
                            [
                                'label' => 'Leave requests',
                                'route' => 'hr.leave-requests.index',
                                'match' => ['hr.leave-requests.*'],
                                'icon' => 'clipboard-check',
                                'can' => ['viewAny', \App\Models\LeaveRequest::class],
                                'keywords' => 'holiday vacation absence approval',
                            ],
                            [
                                'label' => 'Leave types',
                                'route' => 'hr.leave-types.index',
                                'match' => ['hr.leave-types.*'],
                                'icon' => 'clipboard-list',
                                'can' => ['viewAny', \App\Models\LeaveType::class],
                                'keywords' => 'casual sick annual policy',
                            ],
                        ],
                    ],
                    [
                        'key' => 'hr.payroll',
                        'title' => 'Payroll',
                        'items' => [
                            [
                                'label' => 'Pay scales',
                                'route' => 'hr.pay-scales.index',
                                'match' => ['hr.pay-scales.*'],
                                'icon' => 'currency',
                                'can' => ['viewAny', \App\Models\PayScale::class],
                                'keywords' => 'salary grade band structure wage',
                            ],
                            [
                                'label' => 'Salary sheets',
                                'route' => 'hr.salary-sheets.index',
                                'match' => ['hr.salary-sheets.*'],
                                'icon' => 'document-text',
                                'can' => ['viewAny', \App\Models\SalarySheet::class],
                                'keywords' => 'payslip payroll wage month disbursement',
                            ],
                        ],
                    ],
                ],
            ],

            [
                'key' => 'sms',
                'title' => 'SMS',
                'gate' => 'send-sms',
                'sections' => [
                    [
                        'key' => 'sms.main',
                        'title' => null,
                        'items' => [
                            [
                                'label' => 'Send SMS',
                                'route' => 'sms.index',
                                'match' => ['sms.index'],
                                'icon' => 'chat',
                                'keywords' => 'sms message text bulk single reminder bangla masking',
                            ],
                            [
                                'label' => 'SMS history',
                                'route' => 'sms.history',
                                'match' => ['sms.history', 'sms.batches.*'],
                                'icon' => 'clock',
                                'keywords' => 'sms log sent failed delivery report',
                            ],
                            [
                                'label' => 'SMS templates',
                                'route' => 'sms.templates.index',
                                'match' => ['sms.templates.*'],
                                'icon' => 'clipboard-list',
                                'keywords' => 'sms template draft bangla text',
                            ],
                        ],
                    ],
                ],
            ],

            [
                'key' => 'admin',
                'title' => 'Administration',
                'sections' => [
                    [
                        'key' => 'admin.access',
                        'title' => 'Access control',
                        'items' => [
                            [
                                'label' => 'Users',
                                'route' => 'users.index',
                                'match' => ['users.*'],
                                'icon' => 'user-group',
                                'can' => ['viewAny', \App\Models\User::class],
                                'keywords' => 'login account staff access',
                            ],
                            [
                                'label' => 'Roles',
                                'route' => 'roles.index',
                                'match' => ['roles.*'],
                                'icon' => 'cog',
                                'can' => ['viewAny', \App\Models\Role::class],
                                'keywords' => 'permission rights group policy',
                            ],
                        ],
                    ],
                    [
                        'key' => 'admin.setup',
                        'title' => 'Setup',
                        'items' => [
                            [
                                'label' => 'Branches',
                                'route' => 'locations.index',
                                'match' => ['locations.*'],
                                'icon' => 'map-pin',
                                'can' => ['viewAny', \App\Models\Location::class],
                                'keywords' => 'location outlet shop warehouse store',
                            ],
                            [
                                'label' => 'Company pad settings',
                                'route' => 'settings.company-pad.edit',
                                'match' => ['settings.company-pad.*'],
                                'icon' => 'clipboard',
                                'gate' => 'manage-company-pad-settings',
                                'keywords' => 'letterhead logo header print branding',
                            ],
                        ],
                    ],
                ],
            ],

            [
                'key' => 'account',
                'title' => 'Account',
                'sections' => [
                    [
                        'key' => 'account.main',
                        'title' => null,
                        'items' => [
                            [
                                'label' => 'My profile',
                                'route' => 'profile.edit',
                                'icon' => 'user-circle',
                                'keywords' => 'me personal details name',
                            ],
                            [
                                'label' => 'Change password',
                                'route' => 'profile.password.edit',
                                'icon' => 'lock',
                                'keywords' => 'security credentials reset login',
                            ],
                        ],
                    ],
                ],
            ],
        ];
    }
}
