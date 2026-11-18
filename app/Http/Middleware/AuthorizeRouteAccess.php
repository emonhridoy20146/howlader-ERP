<?php

namespace App\Http\Middleware;

use App\Models\AttendanceRecord;
use App\Models\BranchAnnualTarget;
use App\Models\BranchMonthlyTarget;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Customer;
use App\Models\DemandForecast;
use App\Models\Employee;
use App\Models\GoodsReceipt;
use App\Models\HrDepartment;
use App\Models\HrDesignation;
use App\Models\InstallmentPlan;
use App\Models\JournalEntry;
use App\Models\LedgerAccount;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\Location;
use App\Models\PaymentMethod;
use App\Models\PaymentMethodWalletMap;
use App\Models\PayScale;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\ReturnOrder;
use App\Models\Role;
use App\Models\SalarySheet;
use App\Models\SalesInvoice;
use App\Models\SerialItem;
use App\Models\ServiceJob;
use App\Models\StockTransfer;
use App\Models\Supplier;
use App\Models\User;
use App\Models\Wallet;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class AuthorizeRouteAccess
{
    public function handle(Request $request, Closure $next): Response
    {
        $name = (string) optional($request->route())->getName();
        if ($name === '') {
            return $next($request);
        }

        if (Str::is('products.show', $name)) {
            if (
                ! Gate::check('viewAny', Product::class)
                && ! Gate::check('globalSearchView', Product::class)
            ) {
                abort(403);
            }

            return $next($request);
        }

        if (Str::is('reports.*', $name)) {
            $this->authorizeReport($request, $name);

            return $next($request);
        }

        foreach ($this->rules() as $pattern => [$ability, $subject]) {
            if (Str::is($pattern, $name)) {
                if ($subject === null) {
                    Gate::authorize($ability);
                } else {
                    Gate::authorize($ability, $subject);
                }
                break;
            }
        }

        return $next($request);
    }

    /**
     * Reports are gated per subject, and downloading is a separate privilege from
     * reading on screen.
     */
    private function authorizeReport(Request $request, string $routeName): void
    {
        Gate::authorize('view-reports');

        $reportSlug = $routeName === 'reports.index'
            ? null
            : (string) ($request->route('slug') ?? Str::after($routeName, 'reports.'));

        if ($reportSlug === null || $reportSlug === '') {
            return;
        }

        if (in_array($routeName, ['reports.export', 'reports.print'], true)) {
            Gate::authorize('export-reports');
        }

        Gate::authorize('view-report', $reportSlug);
    }

    /**
     * @return array<string, array{0: string, 1: mixed}>
     */
    private function rules(): array
    {
        return [
            'brands.*' => ['viewAny', Brand::class],
            'categories.*' => ['viewAny', Category::class],
            'customers.*' => ['viewAny', Customer::class],
            'customer-tracking.*' => ['viewAnyTracking', Customer::class],
            'suppliers.*' => ['viewAny', Supplier::class],
            'products.*' => ['viewAny', Product::class],
            'inventory.stock-levels.*' => ['manageStockLevels', Product::class],
            'inventory.stock-check.*' => ['viewAny', Product::class],
            'serial-items.*' => ['viewAny', SerialItem::class],
            'locations.annual-targets.*' => ['viewAny', BranchAnnualTarget::class],
            'locations.monthly-targets.*' => ['viewAny', BranchMonthlyTarget::class],
            'locations.*' => ['viewAny', Location::class],
            'payment-methods.*' => ['viewAny', PaymentMethod::class],
            'wallets.*' => ['viewAny', Wallet::class],
            'ledger-accounts.*' => ['viewAny', LedgerAccount::class],
            'payment-method-wallet-maps.*' => ['viewAny', PaymentMethodWalletMap::class],
            'journal-entries.create' => ['create', JournalEntry::class],
            'journal-entries.store' => ['create', JournalEntry::class],
            'journal-entries.*' => ['viewAny', JournalEntry::class],
            'daily-transactions.*' => ['viewAny', JournalEntry::class],
            'purchase-orders.*' => ['viewAny', PurchaseOrder::class],
            'supplier-payment-register.*' => ['viewAny', PurchaseOrder::class],
            'goods-receipts.*' => ['viewAny', GoodsReceipt::class],
            'transfers.*' => ['viewAny', StockTransfer::class],
            'sales.invoices.*' => ['viewAny', SalesInvoice::class],
            'payment-register.*' => ['viewAny', SalesInvoice::class],
            'installments.*' => ['viewAny', InstallmentPlan::class],
            'returns.*' => ['viewAny', ReturnOrder::class],
            'service-jobs.*' => ['viewAny', ServiceJob::class],
            'users.impersonate' => ['viewAny', User::class],
            'users.*' => ['viewAny', User::class],
            'roles.*' => ['viewAny', Role::class],
            'forecasts.*' => ['viewAny', DemandForecast::class],
            'settings.company-pad.*' => ['manage-company-pad-settings', null],
            'hr.departments.*' => ['viewAny', HrDepartment::class],
            'hr.designations.*' => ['viewAny', HrDesignation::class],
            'hr.pay-scales.*' => ['viewAny', PayScale::class],
            'hr.employees.*' => ['viewAny', Employee::class],
            'hr.attendance.*' => ['viewAny', AttendanceRecord::class],
            'hr.leave-types.*' => ['viewAny', LeaveType::class],
            'hr.leave-requests.*' => ['viewAny', LeaveRequest::class],
            'hr.salary-sheets.*' => ['viewAny', SalarySheet::class],
        ];
    }
}
