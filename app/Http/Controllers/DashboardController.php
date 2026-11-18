<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\AppliesInventoryLocationContext;
use App\Models\InstallmentSchedule;
use App\Models\Location;
use App\Models\PurchaseOrder;
use App\Support\LedgerBackedInventory;
use App\Models\SalesInvoice;
use App\Models\StockTransfer;
use App\Services\AttendanceReportService;
use App\Services\BranchAnnualTargetProgressService;
use App\Services\BranchMonthlyTargetProgressService;
use App\Services\ReportDataService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class DashboardController extends Controller
{
    use AppliesInventoryLocationContext;

    public function index(): View
    {
        $locationId = $this->contextLocationId();
        $contextLocation = $locationId !== null ? Location::query()->find($locationId) : null;

        $today = Carbon::today();
        $monthStart = Carbon::now()->startOfMonth();

        $invoiceScope = SalesInvoice::query()
            ->tap(fn ($q) => $this->scopeContextBranch($q, 'branch_id'));

        $todaySales = (float) (clone $invoiceScope)
            ->countedAsPostedSale()
            ->whereDate('invoice_date', $today)
            ->sum('grand_total');

        $monthSales = (float) (clone $invoiceScope)
            ->countedAsPostedSale()
            ->whereBetween('invoice_date', [$monthStart, Carbon::now()->endOfDay()])
            ->sum('grand_total');

        $outstandingReceivable = (float) (clone $invoiceScope)
            ->whereIn('status', ['posted', 'active'])
            ->where('payment_status', '!=', 'paid')
            ->sum('amount_due');

        $draftInvoices = (int) (clone $invoiceScope)
            ->where('status', 'draft')
            ->count();

        $scheduleLocationFilter = function ($query) use ($locationId): void {
            $query->whereHas('installmentPlan.salesInvoice', function ($q) use ($locationId): void {
                if ($locationId !== null) {
                    $q->where('branch_id', $locationId);

                    return;
                }
                $user = auth()->user();
                if ($user && ! $user->isAdmin() && ! $user->hasPermission('location.view-all')) {
                    $ids = $user->accessibleLocationIds();
                    if ($ids !== []) {
                        $q->whereIn('branch_id', $ids);
                    }
                }
            });
        };

        $overdueSchedulesCount = InstallmentSchedule::query()
            ->where('status', 'overdue')
            ->whereHas('installmentPlan', fn ($q) => $q->where('status', 'active'))
            ->tap($scheduleLocationFilter)
            ->count();

        $upcomingWindowEnd = $today->copy()->addDays(14);

        $upcomingDuesCount = InstallmentSchedule::query()
            ->whereIn('status', ['pending', 'partially_paid'])
            ->whereBetween('due_date', [$today, $upcomingWindowEnd])
            ->whereHas('installmentPlan', fn ($q) => $q->where('status', 'active'))
            ->tap($scheduleLocationFilter)
            ->count();

        $upcomingDues = InstallmentSchedule::query()
            ->with([
                'installmentPlan.salesInvoice:id,invoice_number,customer_id,branch_id',
                'installmentPlan.salesInvoice.customer:id,name',
            ])
            ->whereIn('status', ['pending', 'partially_paid'])
            ->whereBetween('due_date', [$today, $upcomingWindowEnd])
            ->whereHas('installmentPlan', fn ($q) => $q->where('status', 'active'))
            ->tap($scheduleLocationFilter)
            ->orderBy('due_date')
            ->orderBy('installment_no')
            ->limit(8)
            ->get();

        $recentOverdues = InstallmentSchedule::query()
            ->with([
                'installmentPlan.salesInvoice:id,invoice_number,customer_id,branch_id',
                'installmentPlan.salesInvoice.customer:id,name',
            ])
            ->where('status', 'overdue')
            ->whereHas('installmentPlan', fn ($q) => $q->where('status', 'active'))
            ->tap($scheduleLocationFilter)
            ->orderBy('due_date')
            ->limit(6)
            ->get();

        $lowStockQuery = DB::query()
            ->fromSub(\App\Support\LedgerBackedInventory::sellableBalancesSubquery(), 'inventory_balances')
            ->join('products', 'products.id', '=', 'inventory_balances.product_id')
            ->join('locations', 'locations.id', '=', 'inventory_balances.location_id')
            ->where('products.is_active', true)
            ->whereColumn('inventory_balances.available_qty', '<=', 'products.reorder_level')
            ->when($locationId !== null, fn ($q) => $q->where('inventory_balances.location_id', $locationId))
            ->when(
                $locationId === null,
                function ($q): void {
                    $user = auth()->user();
                    if ($user && ! $user->isAdmin() && ! $user->hasPermission('location.view-all')) {
                        $ids = $user->accessibleLocationIds();
                        if ($ids !== []) {
                            $q->whereIn('inventory_balances.location_id', $ids);
                        }
                    }
                }
            );

        $lowStockCount = (clone $lowStockQuery)->count();

        $lowStockItems = (clone $lowStockQuery)
            ->orderBy('inventory_balances.available_qty')
            ->orderBy('products.sku')
            ->limit(10)
            ->get([
                'products.id as product_id',
                'products.sku',
                'products.name',
                'locations.name as location_name',
                'inventory_balances.available_qty',
                'products.reorder_level',
            ]);

        $transferScope = StockTransfer::query()->tap(fn ($q) => $this->scopeContextTransfer($q));

        $pendingTransfersCount = (clone $transferScope)
            ->whereIn('status', ['draft', 'approved', 'issued'])
            ->count();

        $pendingTransfers = (clone $transferScope)
            ->whereIn('status', ['draft', 'approved', 'issued'])
            ->with(['sourceLocation:id,code,name', 'destinationLocation:id,code,name'])
            ->latest('requested_at')
            ->limit(6)
            ->get();

        $openPurchaseOrdersCount = PurchaseOrder::query()
            ->whereIn('status', ['draft', 'approved', 'partially_received'])
            ->tap(fn ($q) => $this->scopeContextDestination($q, 'destination_location_id'))
            ->count();

        $collectionRequest = Request::create('/', 'GET', [
            'branch_id' => $locationId !== null ? (string) $locationId : '',
        ]);
        $monthlyCollection = app(ReportDataService::class)->monthlyCollectionForDashboard($collectionRequest);
        $collectionTotals = $monthlyCollection['totals'];

        $branchTargetProgress = [];
        $branchMonthlyTargetProgress = [];
        $branchTargetLocations = collect();
        if (auth()->user()?->can('view-dashboard-targets')) {
            $targetLocationIds = $locationId !== null
                ? [$locationId]
                : Location::query()
                    ->whereIn('type', ['branch', 'warehouse'])
                    ->where('is_active', true)
                    ->when(
                        ($user = auth()->user()) && ! $user->isAdmin() && ! $user->hasPermission('location.view-all'),
                        fn ($q) => $q->whereIn('id', $user->accessibleLocationIds())
                    )
                    ->orderBy('name')
                    ->pluck('id')
                    ->all();

            $branchTargetProgress = app(BranchAnnualTargetProgressService::class)
                ->forLocationIds($targetLocationIds);

            $branchMonthlyTargetProgress = app(BranchMonthlyTargetProgressService::class)
                ->forLocationIds($targetLocationIds);

            $branchTargetLocations = $targetLocationIds !== []
                ? Location::query()->whereIn('id', $targetLocationIds)->orderBy('name')->get(['id', 'code', 'name', 'type'])
                : collect();
        }

        $stockInventory = app(ReportDataService::class)->stockSummaryInventoryKpis();

        $attendanceToday = null;
        if (auth()->user()?->can('view-dashboard-attendance')) {
            $attendanceToday = app(AttendanceReportService::class)
                ->todayDashboardSummary(auth()->user(), $locationId);
        }

        $stats = [
            'today_sales' => $todaySales,
            'month_sales' => $monthSales,
            'outstanding_receivable' => $outstandingReceivable,
            'draft_invoices' => $draftInvoices,
            'product_quantity' => $stockInventory['on_hand'],
            'product_in_stock' => $stockInventory['available'],
            'product_on_hold' => $stockInventory['reserved'],
            'overdue_installments' => $overdueSchedulesCount,
            'upcoming_dues' => $upcomingDuesCount,
            'low_stock_count' => $lowStockCount,
            'pending_transfers' => $pendingTransfersCount,
            'open_purchase_orders' => $openPurchaseOrdersCount,
            'collection_installment_due' => $collectionTotals['installment_remaining'],
            'collection_credit_due' => $collectionTotals['credit_due'],
            'collection_overdue_installment' => $collectionTotals['overdue_installment_remaining'],
            'collection_overdue_credit' => $collectionTotals['overdue_credit_due'],
            'collection_total_due' => $collectionTotals['total_due'],
        ];

        return view('dashboard.index', compact(
            'contextLocation',
            'stats',
            'upcomingDues',
            'recentOverdues',
            'lowStockItems',
            'pendingTransfers',
            'upcomingWindowEnd',
            'monthlyCollection',
            'branchTargetProgress',
            'branchMonthlyTargetProgress',
            'branchTargetLocations',
            'attendanceToday',
        ));
    }
}
