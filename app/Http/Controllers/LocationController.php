<?php

namespace App\Http\Controllers;

use App\Models\Location;
use App\Models\User;
use App\Services\BranchAnnualTargetProgressService;
use App\Services\BranchMonthlyTargetProgressService;
use App\Services\BranchOverviewService;
use App\Services\BranchSetupService;
use App\Services\LocationListCardMetricsService;
use App\Support\LocationCode;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class LocationController extends Controller
{
    public function index(Request $request): View
    {
        /** @var User|null $user */
        $user = auth()->user();
        $restrictToAccessible = $user && ! $user->isAdmin() && ! $user->hasPermission('location.view-all');
        $accessibleIds = $restrictToAccessible ? $user->accessibleLocationIds() : null;

        $filters = [
            'q' => trim((string) $request->input('q', '')),
            'type' => (string) $request->input('type', ''),
            'is_active' => (string) $request->input('is_active', ''),
        ];

        $editId = $request->integer('edit');
        $editingLocation = null;
        if ($editId) {
            $candidate = Location::with('managerUser')->find($editId);
            if ($candidate && $this->userMayAccessLocationInList($user, $candidate)) {
                $editingLocation = $candidate;
            }
        }

        $locations = Location::query()
            ->with(['paymentMethods' => fn ($query) => $query->select('id', 'location_id', 'name', 'is_active'), 'managerUser:id,name,email'])
            ->when($restrictToAccessible, fn ($query) => $query->whereIn('id', $accessibleIds ?? []))
            ->when($filters['q'] !== '', function ($query) use ($filters): void {
                $keyword = $filters['q'];
                $query->where(function ($subQuery) use ($keyword): void {
                    $subQuery->where('code', 'like', "%{$keyword}%")
                        ->orWhere('name', 'like', "%{$keyword}%")
                        ->orWhere('phone', 'like', "%{$keyword}%")
                        ->orWhere('manager_name', 'like', "%{$keyword}%");
                });
            })
            ->when($filters['type'] !== '', fn ($query) => $query->where('type', $filters['type']))
            ->when($filters['is_active'] !== '', fn ($query) => $query->where('is_active', $filters['is_active'] === '1'))
            ->latest()
            ->paginate($this->resolvePerPage($request, 15))
            ->withQueryString();

        $scoped = function () use ($restrictToAccessible, $accessibleIds) {
            $q = Location::query();
            if ($restrictToAccessible) {
                $q->whereIn('id', $accessibleIds ?? []);
            }

            return $q;
        };

        $kpis = [
            'total' => $scoped()->count(),
            'branches' => $scoped()->where('type', 'branch')->count(),
            'warehouses' => $scoped()->where('type', 'warehouse')->count(),
            'active' => $scoped()->where('is_active', true)->count(),
            'filtered' => $locations->total(),
        ];

        $restrictToAccessibleLocations = $restrictToAccessible;

        $locationCardMetrics = app(LocationListCardMetricsService::class)
            ->forLocations($locations->getCollection());

        $branchTargetProgress = app(BranchAnnualTargetProgressService::class)
            ->forLocationIds(
                $locations->getCollection()
                    ->whereIn('type', ['branch', 'warehouse'])
                    ->pluck('id')
                    ->all()
            );

        $managerUsers = User::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name', 'email']);

        return view('locations.index', compact(
            'locations',
            'editingLocation',
            'filters',
            'kpis',
            'restrictToAccessibleLocations',
            'locationCardMetrics',
            'branchTargetProgress',
            'managerUsers',
        ));
    }

    private function userMayAccessLocationInList(?User $user, Location $location): bool
    {
        if (! $user) {
            return false;
        }

        if ($user->isAdmin() || $user->hasPermission('location.view-all')) {
            return true;
        }

        return in_array((int) $location->id, $user->accessibleLocationIds(), true);
    }

    public function create(): RedirectResponse
    {
        return redirect()->route('locations.index', ['drawer' => 'create']);
    }

    public function show(Request $request, Location $location): View
    {
        $user = auth()->user();
        if ($user && ! $user->isAdmin() && ! $user->hasPermission('location.view-all')
            && ! in_array((int) $location->id, $user->accessibleLocationIds(), true)) {
            abort(403);
        }

        $request->validate([
            'tab' => ['nullable', 'string', 'max:32'],
            'journal_date_from' => ['nullable', 'date'],
            'journal_date_to' => ['nullable', 'date'],
            'journal_source' => ['nullable', 'string', 'max:64'],
            'journal_q' => ['nullable', 'string', 'max:200'],
            'stock_q' => ['nullable', 'string', 'max:200'],
            'sales_date_from' => ['nullable', 'date'],
            'sales_date_to' => ['nullable', 'date'],
            'sales_range' => ['nullable', 'string', 'in:all_time,this_month,months_3,months_6,months_12,custom'],
            'sales_q' => ['nullable', 'string', 'max:200'],
            'expense_date_from' => ['nullable', 'date'],
            'expense_date_to' => ['nullable', 'date'],
            'expense_q' => ['nullable', 'string', 'max:500'],
            'po_date_from' => ['nullable', 'date'],
            'po_date_to' => ['nullable', 'date'],
            'po_status' => ['nullable', 'string', 'max:64'],
            'po_q' => ['nullable', 'string', 'max:200'],
            'grn_date_from' => ['nullable', 'date'],
            'grn_date_to' => ['nullable', 'date'],
            'grn_q' => ['nullable', 'string', 'max:200'],
            'transfer_date_from' => ['nullable', 'date'],
            'transfer_date_to' => ['nullable', 'date'],
            'transfer_q' => ['nullable', 'string', 'max:64'],
            'target_year' => ['nullable', 'integer', 'min:2000', 'max:2100'],
        ]);

        $location->load([
            'managerUser',
            'paymentMethods' => fn ($query) => $query->orderBy('name'),
            'wallets' => fn ($query) => $query->orderBy('code'),
            'users' => fn ($query) => $query->orderBy('name'),
            'accessibleByUsers' => fn ($query) => $query->orderBy('name'),
        ]);

        $branchOverview = app(BranchOverviewService::class);
        $overview = $branchOverview->snapshot($location);
        $perPage = $this->resolvePerPage($request, 15);
        $tables = $branchOverview->paginatedTables($location, $request, $perPage);

        $kpis = $overview['kpis'];
        $financialBalances = $overview['financialBalances'];
        $stockPreviewItems = $overview['stockPreviewItems'];
        $chartLabels = $overview['chartLabels'];
        $chartSales = $overview['chartSales'];
        $chartExpenses = $overview['chartExpenses'];

        $journalEntries = $tables['journalEntries'];
        $stockItems = $tables['stockItems'];
        /** @var array{grand_total: float, invoice_count: int, period_description: string} $salesTotals */
        $salesTotals = $tables['salesTotals'];
        $salesInvoices = $tables['salesInvoices'];
        $expenseEntries = $tables['expenseEntries'];
        $purchaseOrders = $tables['purchaseOrders'];
        $goodsReceipts = $tables['goodsReceipts'];
        $stockTransfers = $tables['stockTransfers'];
        $filters = $tables['filters'];
        $locationTableTotals = $tables['locationTableTotals'];

        $locationPaginationPageParams = ['journal_page', 'stock_page', 'sales_page', 'expense_page', 'po_page', 'grn_page', 'transfer_page'];
        $locationTabUrls = $this->locationShowTabUrls($request, $location);
        $activeLocationTab = $filters['tab'] !== '' ? $filters['tab'] : 'overview';

        $targetYear = (int) ($request->input('target_year') ?: now()->year);
        $supportsAnnualTargets = in_array($location->type, ['branch', 'warehouse'], true);
        $annualTargetProgress = $supportsAnnualTargets
            ? app(BranchAnnualTargetProgressService::class)->forLocation($location, $targetYear)
            : null;
        $annualTargetHistory = $supportsAnnualTargets
            ? app(BranchAnnualTargetProgressService::class)->historyForLocation($location)
            : [];
        $monthlyTargetProgress = $supportsAnnualTargets
            ? app(BranchMonthlyTargetProgressService::class)->forLocation($location, $targetYear)
            : null;

        return view('locations.show', compact(
            'location', 'kpis', 'financialBalances', 'stockPreviewItems', 'journalEntries', 'stockItems',
            'salesTotals', 'salesInvoices', 'expenseEntries', 'purchaseOrders', 'goodsReceipts', 'stockTransfers',
            'chartLabels', 'chartSales', 'chartExpenses', 'filters', 'locationTableTotals',
            'locationPaginationPageParams', 'perPage', 'locationTabUrls', 'activeLocationTab',
            'targetYear', 'annualTargetProgress', 'annualTargetHistory', 'monthlyTargetProgress'
        ));
    }

    /**
     * Build tab URLs that preserve the current query string so pagination and filters keep the active tab.
     *
     * @return array<string, string>
     */
    private function locationShowTabUrls(Request $request, Location $location): array
    {
        $allowed = ['overview', 'transactions', 'stock', 'sales', 'expenses', 'purchasing', 'transfers', 'access', 'targets'];
        $query = $request->query();
        unset($query['page']);

        $urls = [];
        foreach ($allowed as $tab) {
            $merged = $query;
            if ($tab === 'overview') {
                unset($merged['tab']);
            } else {
                $merged['tab'] = $tab;
            }
            $qs = http_build_query($merged);
            $urls[$tab] = route('locations.show', $location).($qs !== '' ? '?'.$qs : '').'#location-activity';
        }

        return $urls;
    }

    public function store(Request $request, BranchSetupService $setupService): RedirectResponse
    {
        if (trim((string) $request->input('code', '')) === '') {
            $request->merge([
                'code' => LocationCode::suggestFromName(trim((string) $request->input('name'))),
            ]);
        }

        $data = $request->validate([
            'code' => ['required', 'string', 'max:20', 'unique:locations,code'],
            'name' => ['required', 'string', 'max:100'],
            'type' => ['required', 'in:warehouse,branch'],
            'address' => ['nullable', 'string'],
            'phone' => ['nullable', 'string', 'max:20'],
            'manager_name' => ['nullable', 'string', 'max:100'],
            'manager_user_id' => ['nullable', 'exists:users,id'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $data['is_active'] = $request->boolean('is_active', true);

        $location = Location::create($data);

        $setupService->setupDefaultCashWalletAndPaymentMethod($location);

        return redirect()->route('locations.index')->with('status', 'Location created successfully.');
    }

    public function edit(Location $location): RedirectResponse
    {
        return redirect()->route('locations.index', ['drawer' => 'edit', 'edit' => $location->id]);
    }

    public function update(Request $request, Location $location): RedirectResponse
    {
        if (trim((string) $request->input('code', '')) === '') {
            $request->merge([
                'code' => LocationCode::suggestFromName(trim((string) $request->input('name')), $location->id),
            ]);
        }

        $data = $request->validate([
            'code' => ['required', 'string', 'max:20', 'unique:locations,code,'.$location->id],
            'name' => ['required', 'string', 'max:100'],
            'type' => ['required', 'in:warehouse,branch'],
            'address' => ['nullable', 'string'],
            'phone' => ['nullable', 'string', 'max:20'],
            'manager_name' => ['nullable', 'string', 'max:100'],
            'manager_user_id' => ['nullable', 'exists:users,id'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $data['is_active'] = $request->boolean('is_active', true);

        $location->update($data);

        return redirect()->route('locations.index')->with('status', 'Location updated successfully.');
    }

    public function destroy(Location $location): RedirectResponse
    {
        $location->delete();

        return redirect()->route('locations.index')->with('status', 'Location deleted.');
    }
}
