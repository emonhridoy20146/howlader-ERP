<?php

namespace App\Services;

use App\Http\Controllers\Concerns\AppliesInventoryLocationContext;
use App\Models\AppSetting;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Customer;
use App\Models\InstallmentPlan;
use App\Models\InstallmentSchedule;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\LedgerAccount;
use App\Models\Location;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\SalesInvoice;
use App\Models\StockLedger;
use App\Models\StockTransfer;
use App\Models\Supplier;
use App\Models\User;
use App\Models\Wallet;
use App\Support\CategoryTree;
use App\Support\LedgerBackedInventory;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator as Paginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class ReportDataService
{
    use AppliesInventoryLocationContext;

    public const STOCK_EXPORT_LIMIT = 10000;

    public const REPORT_SLUGS = [
        'stock-by-location',
        'branch-in-stock-pricing',
        'stock-summary',
        'sales-summary',
        'installment-overdue',
        'installment-duration',
        'credit-overdue',
        'low-stock',
        'customer-dues',
        'high-risk-customers',
        'yearly-collection-sheet',
        'invoice-outstanding-by-customer',
        'open-purchase-orders',
        'supplier-dues-and-payments',
        'sales-by-day-branch',
        'customer-journal',
        'monthly-collection',
        'wallet-transactions',
        'customer-payment-by-branch',
        'daily-expenses-by-branch',
        'branch-targets',
        'transfer-ledger',
        'profit-loss-sheet',
        'income-expenditure-statement',
    ];

    /**
     * @return array{q: string, location_id: string, stock_state: string, serialized: string}
     */
    public function stockByLocationFilters(Request $request): array
    {
        return array_merge([
            'q' => trim((string) $request->input('q', '')),
            'location_id' => (string) $request->input('location_id', ''),
            'stock_state' => (string) $request->input('stock_state', ''),
            'serialized' => (string) $request->input('serialized', ''),
        ], $this->stockAsOfFilterPart($request));
    }

    /**
     * @return array{q: string, location_id: string, stock_state: string, serialized: string, product_id: string, category_id: string, brand_id: string, as_of_date: string, as_of_time: string}
     */
    private function normalizeStockReportFilters(array $filters): array
    {
        return array_merge($this->stockSummaryDefaultFilters(), $filters);
    }

    /**
     * @return array{as_of_date: string, as_of_time: string}
     */
    private function stockAsOfFilterPart(Request $request): array
    {
        $date = $this->stockAsOfDateFromRequest($request);

        return [
            'as_of_date' => $date,
            'as_of_time' => $date !== '' ? $this->stockAsOfTimeFromRequest($request) : '',
        ];
    }

    private function stockAsOfDateFromRequest(Request $request): string
    {
        $raw = trim((string) $request->input('as_of_date', ''));
        if ($raw === '') {
            return '';
        }

        try {
            $date = Carbon::parse($raw)->startOfDay();
        } catch (\Throwable) {
            return '';
        }

        if ($date->isAfter(now()->endOfDay())) {
            return '';
        }

        return $date->toDateString();
    }

    private function stockAsOfTimeFromRequest(Request $request): string
    {
        $raw = trim((string) $request->input('as_of_time', ''));
        if ($raw === '') {
            return '';
        }

        if (! preg_match('/^\d{2}:\d{2}$/', $raw)) {
            return '';
        }

        return $raw;
    }

    public function stockAsOfCutoffFromFilters(array $filters): ?Carbon
    {
        $date = trim((string) ($filters['as_of_date'] ?? ''));
        if ($date === '') {
            return null;
        }

        try {
            $cutoff = Carbon::parse($date);
        } catch (\Throwable) {
            return null;
        }

        $time = trim((string) ($filters['as_of_time'] ?? ''));
        if ($time !== '' && preg_match('/^(\d{2}):(\d{2})$/', $time, $matches)) {
            $cutoff = $cutoff->setTime((int) $matches[1], (int) $matches[2], 59);
        } else {
            $cutoff = $cutoff->endOfDay();
        }

        if ($cutoff->isAfter(now())) {
            return now();
        }

        return $cutoff;
    }

    public function stockAsOfDisplayLabel(array $filters): ?string
    {
        $cutoff = $this->stockAsOfCutoffFromFilters($filters);
        if ($cutoff === null) {
            return null;
        }

        if (trim((string) ($filters['as_of_time'] ?? '')) !== '') {
            return $cutoff->format('M j, Y g:i A');
        }

        return $cutoff->format('M j, Y').' (end of day)';
    }

    public function stockReportUsesHistoricalSnapshot(array $filters): bool
    {
        return trim((string) ($filters['as_of_date'] ?? '')) !== '';
    }

    private function stockReportBalancesSubquery(array $filters, bool $includeUnserialedStock = false): QueryBuilder
    {
        $cutoff = $this->stockAsOfCutoffFromFilters($filters);

        return LedgerBackedInventory::balancesForReportSubquery($cutoff?->toDateTimeString(), $includeUnserialedStock);
    }

    /**
     * @param  array<string, mixed>  $parts
     */
    private function appendStockAsOfDateFilterSummary(array $filters, array &$parts): void
    {
        $label = $this->stockAsOfDisplayLabel($filters);
        if ($label === null) {
            return;
        }

        $parts[] = 'Stock as of '.$label.' (ledger snapshot)';
    }

    /**
     * @param  Builder|QueryBuilder  $query
     */
    private function applyStockReportLocationScope($query, array $filters, string $column = 'inventory_balances.location_id'): void
    {
        if ($filters['location_id'] !== '') {
            $query->where($column, (int) $filters['location_id']);

            return;
        }

        $id = $this->contextLocationId();
        if ($id !== null) {
            $query->where($column, $id);

            return;
        }

        $ids = $this->accessibleLocationIds();
        if ($ids !== []) {
            $query->whereIn($column, $ids);
        }
    }

    /**
     * Canonical ledger balances for stock reports: active products at active locations in scope.
     * Uses sellable quantities (same rules as branch in-stock pricing).
     */
    private function activeProductLedgerBalancesQuery(array $filters, bool $includeUnserialedStock = false): QueryBuilder
    {
        $filters = $this->normalizeStockReportFilters($filters);

        $query = DB::query()
            ->fromSub($this->stockReportBalancesSubquery($filters, $includeUnserialedStock), 'inventory_balances')
            ->join('products', 'products.id', '=', 'inventory_balances.product_id')
            ->join('locations', 'locations.id', '=', 'inventory_balances.location_id')
            ->leftJoin('categories', 'categories.id', '=', 'products.category_id')
            ->leftJoin('brands', 'brands.id', '=', 'products.brand_id')
            ->where('products.is_active', true)
            ->where('locations.is_active', true);

        $this->applyStockReportLocationScope($query, $filters);

        return $query
            ->when($filters['serialized'] === '1', fn ($q) => $q->where('products.is_serialized', true))
            ->when($filters['serialized'] === '0', fn ($q) => $q->where('products.is_serialized', false))
            ->when($filters['stock_state'] === 'in_stock', fn ($q) => $q->where('inventory_balances.available_qty', '>', 0))
            ->when($filters['stock_state'] === 'out_of_stock', fn ($q) => $q->where('inventory_balances.available_qty', '<=', 0))
            ->when(
                $filters['stock_state'] === 'low_stock',
                fn ($q) => $q
                    ->where('products.reorder_level', '>', 0)
                    ->whereColumn('inventory_balances.available_qty', '<=', 'products.reorder_level')
            )
            ->when($filters['q'] !== '', function ($q) use ($filters): void {
                $keyword = $filters['q'];
                $q->where(function ($sub) use ($keyword): void {
                    $sub->where('products.sku', 'like', "%{$keyword}%")
                        ->orWhere('products.name', 'like', "%{$keyword}%")
                        ->orWhere('categories.name', 'like', "%{$keyword}%")
                        ->orWhere('brands.name', 'like', "%{$keyword}%")
                        ->orWhere('locations.name', 'like', "%{$keyword}%")
                        ->orWhere('locations.code', 'like', "%{$keyword}%");
                });
            })
            ->when($filters['product_id'] !== '', fn ($q) => $q->where('products.id', (int) $filters['product_id']))
            ->when($filters['category_id'] !== '', fn ($q) => $q->where('products.category_id', (int) $filters['category_id']))
            ->when($filters['brand_id'] !== '', fn ($q) => $q->where('products.brand_id', (int) $filters['brand_id']));
    }

    public function stockByLocationBaseQuery(array $filters): QueryBuilder
    {
        // Stock by location lists physical quantity, so serialized products with no serial records
        // (old/imported stock) are included using their ledger quantity.
        return $this->activeProductLedgerBalancesQuery($filters, true)
            ->select([
                'inventory_balances.product_id',
                'inventory_balances.location_id',
                'inventory_balances.on_hand_qty',
                'inventory_balances.reserved_qty',
                'inventory_balances.available_qty',
                'inventory_balances.updated_at',
                'products.sku as product_sku',
                'products.name as product_name',
                'products.default_purchase_price',
                'products.reorder_level',
                'products.is_serialized',
                'products.category_id',
                'categories.name as category_name',
                'locations.code as location_code',
                'locations.name as location_name',
            ]);
    }

    /**
     * @return array{records: int, locations: int, products: int, on_hand: int, reserved: int, available: int}
     */
    public function stockByLocationKpis(QueryBuilder $baseQuery, int $recordsTotal): array
    {
        $forAgg = clone $baseQuery;

        return [
            'records' => $recordsTotal,
            'locations' => (int) DB::query()->fromSub($forAgg, 'kpi_rows')->selectRaw('COUNT(DISTINCT kpi_rows.location_id) as c')->value('c'),
            'products' => (int) DB::query()->fromSub(clone $baseQuery, 'kpi_rows2')->selectRaw('COUNT(DISTINCT kpi_rows2.product_id) as c')->value('c'),
            'on_hand' => (int) (clone $baseQuery)->sum('inventory_balances.on_hand_qty'),
            'reserved' => (int) (clone $baseQuery)->sum('inventory_balances.reserved_qty'),
            'available' => (int) (clone $baseQuery)->sum('inventory_balances.available_qty'),
        ];
    }

    public function stockByLocationFilterLocations(): Collection
    {
        $filterLocationsQuery = Location::query()->where('is_active', true)->orderBy('name');
        $user = auth()->user();
        if ($user && ! $user->isAdmin() && ! $user->hasPermission('location.view-all')) {
            $filterLocationsQuery->whereIn('id', $user->accessibleLocationIds());
        }

        return $filterLocationsQuery->get(['id', 'code', 'name']);
    }

    public function stockByLocationPaginated(Request $request, int $perPage = 50): array
    {
        $filters = $this->stockByLocationFilters($request);
        $baseQuery = $this->stockByLocationBaseQuery($filters);
        $rows = (clone $baseQuery)
            ->orderBy('locations.code')
            ->orderBy('products.name')
            ->paginate($perPage)->withQueryString();

        $kpis = $this->stockByLocationKpis($baseQuery, $rows->total());

        return [
            'filters' => $filters,
            'rows' => $rows,
            'kpis' => $kpis,
            'filterLocations' => $this->stockByLocationFilterLocations(),
        ];
    }

    /**
     * @return array{rows: Collection<int, object>, truncated: bool, filterSummary: string}
     */
    public function stockByLocationForExport(Request $request): array
    {
        $filters = $this->stockByLocationFilters($request);
        $baseQuery = $this->stockByLocationBaseQuery($filters);
        $total = (clone $baseQuery)->count();
        $truncated = $total > self::STOCK_EXPORT_LIMIT;
        $rows = (clone $baseQuery)
            ->orderBy('locations.code')
            ->orderBy('products.name')
            ->limit(self::STOCK_EXPORT_LIMIT)
            ->get();

        return [
            'rows' => $rows,
            'truncated' => $truncated,
            'totalMatching' => $total,
            'filterSummary' => $this->stockByLocationFilterSummary($filters),
            'kpis' => $this->stockByLocationKpis($baseQuery, $total),
        ];
    }

    /**
     * @param  array{q: string, location_id: string, stock_state: string, serialized: string}  $filters
     */
    public function stockByLocationFilterSummary(array $filters): string
    {
        $parts = [];
        if ($filters['q'] !== '') {
            $parts[] = 'Search: '.$filters['q'];
        }
        if ($filters['location_id'] !== '') {
            $loc = Location::query()->whereKey((int) $filters['location_id'])->first();
            $parts[] = 'Location: '.($loc ? $loc->name : $filters['location_id']);
        }
        if ($filters['stock_state'] !== '') {
            $parts[] = 'Stock: '.str_replace('_', ' ', $filters['stock_state']);
        }
        if ($filters['serialized'] === '1') {
            $parts[] = 'Serialized only';
        } elseif ($filters['serialized'] === '0') {
            $parts[] = 'Non-serialized only';
        }
        $this->appendStockAsOfDateFilterSummary($filters, $parts);

        return $parts !== [] ? implode(' · ', $parts) : 'All filters';
    }

    /**
     * @return array{q: string, location_id: string, category_id: string, as_of_date: string}
     */
    public function branchInStockPricingFilters(Request $request): array
    {
        return array_merge([
            'q' => trim((string) $request->input('q', '')),
            'location_id' => (string) $request->input('location_id', ''),
            'category_id' => (string) $request->input('category_id', ''),
        ], $this->stockAsOfFilterPart($request));
    }

    public function branchInStockPricingBaseQuery(array $filters): Builder
    {
        $filters = $this->normalizeStockReportFilters($filters);

        $query = Product::query()
            ->fromSub($this->stockReportBalancesSubquery($filters), 'inventory_balances')
            ->join('products', 'products.id', '=', 'inventory_balances.product_id')
            ->join('locations', 'locations.id', '=', 'inventory_balances.location_id')
            ->leftJoin('categories', 'categories.id', '=', 'products.category_id')
            ->select([
                'inventory_balances.product_id',
                'inventory_balances.location_id',
                'inventory_balances.on_hand_qty',
                'inventory_balances.reserved_qty',
                'inventory_balances.available_qty',
                'products.sku as product_sku',
                'products.name as product_name',
                'products.category_id',
                'products.default_sale_price',
                'products.maximum_deductible',
                'categories.name as category_name',
                'locations.code as location_code',
                'locations.name as location_name',
            ])
            ->where('products.is_active', true)
            ->where('locations.is_active', true)
            ->where('inventory_balances.available_qty', '>', 0)
            ->when($filters['q'] !== '', function ($query) use ($filters): void {
                $keyword = $filters['q'];
                $query->where(function ($subQuery) use ($keyword): void {
                    $subQuery
                        ->where('products.sku', 'like', "%{$keyword}%")
                        ->orWhere('products.name', 'like', "%{$keyword}%")
                        ->orWhere('categories.name', 'like', "%{$keyword}%")
                        ->orWhere('locations.code', 'like', "%{$keyword}%")
                        ->orWhere('locations.name', 'like', "%{$keyword}%");
                });
            })
            ->when($filters['category_id'] !== '', fn ($query) => $query->where('products.category_id', (int) $filters['category_id']));

        $this->applyStockReportLocationScope($query, $filters);

        return $query;
    }

    /**
     * @return array{records: int, locations: int, categories: int, products: int, on_hand: int, mrp_value: float}
     */
    public function branchInStockPricingKpis(Builder $baseQuery, int $recordsTotal): array
    {
        $forAgg = (clone $baseQuery)->toBase();

        return [
            'records' => $recordsTotal,
            'locations' => (int) DB::query()->fromSub($forAgg, 'kpi_rows')->selectRaw('COUNT(DISTINCT kpi_rows.location_id) as c')->value('c'),
            'categories' => (int) DB::query()->fromSub($forAgg, 'kpi_rows2')->selectRaw('COUNT(DISTINCT kpi_rows2.category_id) as c')->value('c'),
            'products' => (int) DB::query()->fromSub((clone $baseQuery)->toBase(), 'kpi_rows3')->selectRaw('COUNT(DISTINCT kpi_rows3.product_id) as c')->value('c'),
            'on_hand' => (int) (clone $baseQuery)->sum('inventory_balances.on_hand_qty'),
            'mrp_value' => round((float) DB::query()->fromSub($forAgg, 'kpi_rows4')->selectRaw('SUM(kpi_rows4.on_hand_qty * COALESCE(kpi_rows4.default_sale_price, 0)) as total')->value('total'), 2),
        ];
    }

    public function branchInStockPricingPaginated(Request $request, int $perPage = 50): array
    {
        $filters = $this->branchInStockPricingFilters($request);
        $baseQuery = $this->branchInStockPricingBaseQuery($filters);
        $rows = (clone $baseQuery)
            ->orderBy('locations.code')
            ->orderBy('categories.name')
            ->orderBy('products.name')
            ->paginate($perPage)
            ->withQueryString();

        $rows->setCollection($this->enrichBranchInStockPricingRows($rows->getCollection()));

        return [
            'filters' => $filters,
            'rows' => $rows,
            'kpis' => $this->branchInStockPricingKpis($baseQuery, $rows->total()),
            'filterLocations' => $this->stockByLocationFilterLocations(),
            'filterCategories' => $this->stockSummaryFilterCategories(),
        ];
    }

    /**
     * @return array{rows: Collection<int, object>, truncated: bool, filterSummary: string, totalMatching: int}
     */
    public function branchInStockPricingForExport(Request $request): array
    {
        $filters = $this->branchInStockPricingFilters($request);
        $baseQuery = $this->branchInStockPricingBaseQuery($filters);
        $total = (clone $baseQuery)->count();
        $truncated = $total > self::STOCK_EXPORT_LIMIT;
        $rows = $this->enrichBranchInStockPricingRows(
            (clone $baseQuery)
                ->orderBy('locations.code')
                ->orderBy('categories.name')
                ->orderBy('products.name')
                ->limit(self::STOCK_EXPORT_LIMIT)
                ->get()
        );

        return [
            'rows' => $rows,
            'truncated' => $truncated,
            'totalMatching' => $total,
            'filterSummary' => $this->branchInStockPricingFilterSummary($filters),
        ];
    }

    /**
     * @param  array{q: string, location_id: string, category_id: string}  $filters
     */
    public function branchInStockPricingFilterSummary(array $filters): string
    {
        $parts = $this->stockReportUsesHistoricalSnapshot($filters)
            ? ['Ledger on-hand as of date']
            : ['Sellable in-stock only (excludes damaged)'];
        if ($filters['q'] !== '') {
            $parts[] = 'Search: '.$filters['q'];
        }
        if ($filters['location_id'] !== '') {
            $loc = Location::query()->whereKey((int) $filters['location_id'])->first();
            $parts[] = 'Location: '.($loc ? $loc->name : $filters['location_id']);
        }
        if ($filters['category_id'] !== '') {
            $category = Category::query()->whereKey((int) $filters['category_id'])->first();
            $parts[] = 'Category: '.($category ? $category->name : $filters['category_id']);
        }
        $this->appendStockAsOfDateFilterSummary($filters, $parts);

        return implode(' · ', $parts);
    }

    /**
     * @param  Collection<int, object>  $rows
     * @return Collection<int, object>
     */
    private function enrichBranchInStockPricingRows(Collection $rows): Collection
    {
        $globalRaw = AppSetting::query()->where('key', 'global_maximum_deductible')->value('value');
        $globalPercent = ($globalRaw !== null && trim((string) $globalRaw) !== '') ? (float) $globalRaw : null;

        return $rows->map(function (object $row) use ($globalPercent): object {
            $mrp = round((float) ($row->default_sale_price ?? 0), 2);
            $row->mrp = $mrp;

            $percent = ($row->maximum_deductible !== null && $row->maximum_deductible !== '')
                ? (float) $row->maximum_deductible
                : $globalPercent;

            $percent = max(0, min(100, round((float) ($percent ?? 0), 2)));
            $maxDiscountAmount = round($mrp * ($percent / 100), 2);

            $row->max_discount_percent = $percent;
            $row->max_discount_amount = $maxDiscountAmount;
            $row->amount_after_discount = round(max(0, $mrp - $maxDiscountAmount), 2);

            return $row;
        });
    }

    /**
     * @return array{q: string, location_id: string, stock_state: string, serialized: string, product_id: string, category_id: string, brand_id: string, as_of_date: string}
     */
    public function stockSummaryFilters(Request $request): array
    {
        return array_merge([
            'q' => trim((string) $request->input('q', '')),
            'location_id' => (string) $request->input('location_id', ''),
            'stock_state' => (string) $request->input('stock_state', ''),
            'serialized' => (string) $request->input('serialized', ''),
            'product_id' => (string) $request->input('product_id', ''),
            'category_id' => (string) $request->input('category_id', ''),
            'brand_id' => (string) $request->input('brand_id', ''),
        ], $this->stockAsOfFilterPart($request));
    }

    public function stockSummaryFilterCategories(): Collection
    {
        return Category::query()->orderBy('name')->get(['id', 'name']);
    }

    public function stockSummaryFilterBrands(): Collection
    {
        return Brand::query()->orderBy('name')->get(['id', 'name']);
    }

    public function stockSummaryFilterProducts(): Collection
    {
        return Product::query()
            ->where('is_active', true)
            ->orderBy('sku')
            ->get(['id', 'sku', 'name']);
    }

    /**
     * Raw balances × product rows (one row per product per location).
     * A category filter includes that category and every category under it.
     */
    private function stockSummaryFilteredBalances(array $filters): QueryBuilder
    {
        $categoryId = (string) ($filters['category_id'] ?? '');
        if ($categoryId !== '') {
            $filters['category_id'] = '';
        }

        $query = $this->activeProductLedgerBalancesQuery($filters);

        if ($categoryId !== '') {
            $ids = CategoryTree::selfAndDescendantIds((int) $categoryId);
            $query->whereIn('products.category_id', $ids);
        }

        return $query;
    }

    /**
     * One row per product per branch.
     */
    public function stockSummaryProductRowsQuery(array $filters): QueryBuilder
    {
        return $this->stockSummaryFilteredBalances($filters)
            ->select([
                'inventory_balances.location_id',
                'locations.code as location_code',
                'locations.name as location_name',
                'inventory_balances.product_id',
                'products.sku',
                'products.name as product_name',
                'products.category_id',
                'categories.name as category_name',
                'products.brand_id',
                'brands.name as brand_name',
                'inventory_balances.on_hand_qty',
                'inventory_balances.reserved_qty',
                'inventory_balances.available_qty',
            ])
            ->selectRaw('(COALESCE(products.default_purchase_price, 0) * inventory_balances.on_hand_qty) as value_at_cost')
            ->selectRaw('(COALESCE(products.default_sale_price, 0) * inventory_balances.on_hand_qty) as total_stock_value');
    }

    /**
     * @return array{branches: int, categories: int, products: int, on_hand: int, reserved: int, available: int, value_at_cost: float, total_stock_value: float}
     */
    public function stockSummaryKpisFromDetail(QueryBuilder $detailQuery): array
    {
        $totals = DB::query()->fromSub((clone $detailQuery), 'd')
            ->selectRaw('COUNT(DISTINCT d.location_id) as branches')
            ->selectRaw('COUNT(DISTINCT d.category_id) as categories')
            ->selectRaw('COUNT(DISTINCT d.product_id) as products')
            ->selectRaw('COALESCE(SUM(d.on_hand_qty), 0) as on_hand')
            ->selectRaw('COALESCE(SUM(d.reserved_qty), 0) as reserved')
            ->selectRaw('COALESCE(SUM(d.available_qty), 0) as available')
            ->selectRaw('COALESCE(SUM(d.value_at_cost), 0) as value_at_cost')
            ->selectRaw('COALESCE(SUM(d.total_stock_value), 0) as total_stock_value')
            ->first();

        return [
            'branches' => (int) ($totals->branches ?? 0),
            'categories' => (int) ($totals->categories ?? 0),
            'products' => (int) ($totals->products ?? 0),
            'on_hand' => (int) ($totals->on_hand ?? 0),
            'reserved' => (int) ($totals->reserved ?? 0),
            'available' => (int) ($totals->available ?? 0),
            'value_at_cost' => round((float) ($totals->value_at_cost ?? 0), 2),
            'total_stock_value' => round((float) ($totals->total_stock_value ?? 0), 2),
        ];
    }

    /**
     * Default stock-summary filters (context location + accessible branches only).
     *
     * @return array{q: string, location_id: string, stock_state: string, serialized: string, product_id: string, category_id: string, brand_id: string, as_of_date: string}
     */
    public function stockSummaryDefaultFilters(): array
    {
        return [
            'q' => '',
            'location_id' => '',
            'stock_state' => '',
            'serialized' => '',
            'product_id' => '',
            'category_id' => '',
            'brand_id' => '',
            'as_of_date' => '',
            'as_of_time' => '',
        ];
    }

    /**
     * On-hand unit total using the same rules as the stock summary report KPIs.
     */
    public function stockSummaryOnHandTotal(?array $filters = null): int
    {
        return $this->stockSummaryInventoryKpis($filters)['on_hand'];
    }

    /**
     * @return array{on_hand: int, reserved: int, available: int}
     */
    public function stockSummaryInventoryKpis(?array $filters = null): array
    {
        $filters = array_merge($this->stockSummaryDefaultFilters(), $filters ?? []);
        $kpis = $this->stockSummaryKpisFromDetail($this->stockSummaryProductRowsQuery($filters));

        return [
            'on_hand' => (int) ($kpis['on_hand'] ?? 0),
            'reserved' => (int) ($kpis['reserved'] ?? 0),
            'available' => (int) ($kpis['available'] ?? 0),
        ];
    }

    public function stockSummaryPaginated(Request $request): array
    {
        $filters = $this->stockSummaryFilters($request);
        $detail = $this->stockSummaryProductRowsQuery($filters);
        $perPage = $this->resolveReportPerPage($request);
        $rows = (clone $detail)
            ->orderBy('locations.name')
            ->orderBy('products.sku')
            ->paginate($perPage)
            ->withQueryString();
        $kpis = $this->stockSummaryKpisFromDetail($detail);

        return [
            'filters' => $filters,
            'rows' => $rows,
            'kpis' => $kpis,
            'filterLocations' => $this->stockByLocationFilterLocations(),
            'filterProducts' => $this->stockSummaryFilterProducts(),
            'filterCategories' => $this->stockSummaryFilterCategories(),
            'filterBrands' => $this->stockSummaryFilterBrands(),
        ];
    }

    /**
     * @return array{rows: Collection<int, object>, truncated: bool, totalMatching: int, filterSummary: string, filters: array}
     */
    public function stockSummaryForExport(Request $request): array
    {
        $filters = $this->stockSummaryFilters($request);
        $detail = $this->stockSummaryProductRowsQuery($filters);
        $total = (clone $detail)->count();
        $truncated = $total > self::STOCK_EXPORT_LIMIT;
        $rows = (clone $detail)
            ->orderBy('locations.name')
            ->orderBy('products.sku')
            ->limit(self::STOCK_EXPORT_LIMIT)
            ->get();

        return [
            'filters' => $filters,
            'rows' => $rows,
            'truncated' => $truncated,
            'totalMatching' => $total,
            'filterSummary' => $this->stockSummaryFilterSummary($filters),
            'kpis' => $this->stockSummaryKpisFromDetail($detail),
        ];
    }

    /**
     * @param  array{q: string, location_id: string, stock_state: string, serialized: string, product_id?: string, category_id?: string, brand_id?: string}  $filters
     */
    public function stockSummaryFilterSummary(array $filters): string
    {
        $parts = ['By branch'];
        if ($filters['q'] !== '') {
            $parts[] = 'Search: '.$filters['q'];
        }
        if (($filters['product_id'] ?? '') !== '') {
            $p = Product::query()->whereKey((int) $filters['product_id'])->first();
            $parts[] = 'Product: '.($p ? $p->sku.' — '.$p->name : $filters['product_id']);
        }
        if (($filters['category_id'] ?? '') !== '') {
            $c = Category::query()->whereKey((int) $filters['category_id'])->first();
            $parts[] = 'Category: '.($c ? $c->name : $filters['category_id']).' (including subcategories)';
        }
        if (($filters['brand_id'] ?? '') !== '') {
            $b = Brand::query()->whereKey((int) $filters['brand_id'])->first();
            $parts[] = 'Brand: '.($b ? $b->name : $filters['brand_id']);
        }
        if ($filters['location_id'] !== '') {
            $loc = Location::query()->whereKey((int) $filters['location_id'])->first();
            $parts[] = 'Location: '.($loc ? $loc->name : $filters['location_id']);
        }
        if ($filters['stock_state'] !== '') {
            $parts[] = 'Stock: '.str_replace('_', ' ', $filters['stock_state']);
        }
        if ($filters['serialized'] === '1') {
            $parts[] = 'Serialized only';
        } elseif ($filters['serialized'] === '0') {
            $parts[] = 'Non-serialized only';
        }
        $this->appendStockAsOfDateFilterSummary($filters, $parts);

        return implode(' · ', $parts);
    }

    private function resolveReportPerPage(Request $request, int $default = 50): int
    {
        $raw = (string) $request->input('per_page', '');
        $allowed = ['10', '20', '50', '100', '200', '500'];

        return in_array($raw, $allowed, true) ? (int) $raw : $default;
    }

    /**
     * @return array{q: string, date_from: string, date_to: string, branch_id: string, payment_status: string, sale_type: string, exclude_installment: bool}
     */
    public function salesSummaryFilters(Request $request): array
    {
        return [
            'q' => trim((string) $request->input('q', '')),
            'date_from' => (string) $request->input('date_from', ''),
            'date_to' => (string) $request->input('date_to', ''),
            'branch_id' => (string) $request->input('branch_id', ''),
            'payment_status' => (string) $request->input('payment_status', ''),
            'sale_type' => (string) $request->input('sale_type', ''),
            'exclude_installment' => $request->boolean('exclude_installment'),
        ];
    }

    public function salesSummaryBaseQuery(array $filters): Builder
    {
        return $this->salesSummaryBaseQueryWithoutInvoiceDate($filters)
            ->when($filters['date_from'] !== '', fn ($query) => $query->whereDate('invoice_date', '>=', $filters['date_from']))
            ->when($filters['date_to'] !== '', fn ($query) => $query->whereDate('invoice_date', '<=', $filters['date_to']));
    }

    /**
     * @param  array{q: string, date_from: string, date_to: string, branch_id: string, payment_status: string, sale_type: string, exclude_installment: bool}  $filters
     */
    protected function salesSummaryBaseQueryWithoutInvoiceDate(array $filters): Builder
    {
        return SalesInvoice::query()
            ->countedAsPostedSale()
            ->when($filters['exclude_installment'] ?? false, fn ($query) => $query->excludingInstallmentSales())
            ->with(['customer', 'branch'])
            ->when($filters['branch_id'] !== '', fn ($query) => $query->where('branch_id', (int) $filters['branch_id']))
            ->when($filters['branch_id'] === '', fn ($query) => $this->scopeContextBranch($query, 'branch_id'))
            ->when($filters['q'] !== '', function ($query) use ($filters): void {
                $keyword = $filters['q'];
                $query->where(function ($subQuery) use ($keyword): void {
                    $subQuery->where('invoice_number', 'like', "%{$keyword}%")
                        ->orWhereHas('customer', function ($customerQuery) use ($keyword): void {
                            $customerQuery->where('name', 'like', "%{$keyword}%")
                                ->orWhere('phone', 'like', "%{$keyword}%");
                        });
                });
            })
            ->when($filters['payment_status'] !== '', fn ($query) => $query->where('payment_status', $filters['payment_status']))
            ->when($filters['sale_type'] !== '', fn ($query) => $query->where('sale_type', $filters['sale_type']));
    }

    /**
     * @return array{invoices: int, grand_total: float, amount_paid: float, amount_due: float, customers: int}
     */
    public function salesSummaryKpis(Builder $baseQuery, int $invoicesTotal): array
    {
        return [
            'invoices' => $invoicesTotal,
            'grand_total' => (float) (clone $baseQuery)->sum('grand_total'),
            'amount_paid' => (float) (clone $baseQuery)->sum('amount_paid'),
            'amount_due' => (float) (clone $baseQuery)->sum('amount_due'),
            'customers' => (clone $baseQuery)->distinct('customer_id')->count('customer_id'),
        ];
    }

    public function salesSummaryFilterBranches(): Collection
    {
        $filterBranchesQuery = Location::query()->where('is_active', true)->orderBy('name');
        $user = auth()->user();
        if ($user && ! $user->isAdmin() && ! $user->hasPermission('location.view-all')) {
            $filterBranchesQuery->whereIn('id', $user->accessibleLocationIds());
        }

        return $filterBranchesQuery->get(['id', 'code', 'name']);
    }

    /**
     * @return array{filters: array{q: string, date_from: string, date_to: string, branch_id: string, payment_status: string, sale_type: string}, rows: LengthAwarePaginator, kpis: array{invoices: int, grand_total: float, amount_paid: float, amount_due: float, customers: int}, filterBranches: Collection, filterSummary: string}
     */
    public function salesSummaryPaginated(Request $request, int $perPage = 50): array
    {
        $filters = $this->salesSummaryFilters($request);
        $baseQuery = $this->salesSummaryBaseQuery($filters);
        $rows = (clone $baseQuery)
            ->latest('invoice_date')
            ->latest('id')
            ->paginate($perPage)->withQueryString();

        $kpis = $this->salesSummaryKpis($baseQuery, $rows->total());

        return [
            'filters' => $filters,
            'rows' => $rows,
            'kpis' => $kpis,
            'filterBranches' => $this->salesSummaryFilterBranches(),
            'filterSummary' => $this->salesSummaryFilterSummary($filters),
        ];
    }

    /**
     * @return array{rows: Collection<int, SalesInvoice>, filters: array, kpis: array, filterBranches: Collection, filterSummary: string}
     */
    public function salesSummaryForExport(Request $request): array
    {
        $filters = $this->salesSummaryFilters($request);
        $baseQuery = $this->salesSummaryBaseQuery($filters);
        $rows = (clone $baseQuery)
            ->latest('invoice_date')
            ->latest('id')
            ->get();

        $kpis = $this->salesSummaryKpis($baseQuery, $rows->count());

        return [
            'filters' => $filters,
            'rows' => $rows,
            'kpis' => $kpis,
            'filterBranches' => $this->salesSummaryFilterBranches(),
            'filterSummary' => $this->salesSummaryFilterSummary($filters),
        ];
    }

    /**
     * @return array{filters: array, rows: LengthAwarePaginator, kpis: array{invoices: int, grand_total: float, amount_paid: float, amount_due: float, period_payments: float, customers: int}, filterBranches: Collection, filterSummary: string}
     */
    /**
     * @return array{q: string, date_from: string, date_to: string, branch_id: string, payment_status: string, sale_type: string, exclude_installment: bool}
     */
    public function resolveMonthToDateSalesFilters(Request $request): array
    {
        $filters = $this->salesSummaryFilters($request);

        if ($filters['date_from'] === '' && $filters['date_to'] === '') {
            $filters['date_from'] = now()->startOfMonth()->toDateString();
            $filters['date_to'] = now()->endOfMonth()->toDateString();
        } elseif ($filters['date_from'] === '' && $filters['date_to'] !== '') {
            $filters['date_from'] = Carbon::parse($filters['date_to'])->startOfMonth()->toDateString();
        } elseif ($filters['date_from'] !== '' && $filters['date_to'] === '') {
            $filters['date_to'] = Carbon::parse($filters['date_from'])->endOfMonth()->toDateString();
        }

        return $filters;
    }

    public function monthToDateSalesPaginated(Request $request, int $perPage = 50): array
    {
        $filters = $this->resolveMonthToDateSalesFilters($request);
        $periodSalesQuery = $this->monthToDateSalesPeriodInvoiceQuery($filters);
        $rows = $this->monthToDateSalesDisplayLinesQuery($filters)
            ->paginate($perPage)
            ->withQueryString();

        $this->hydrateMonthToDateSalesPaymentLineRows($rows, $filters);
        $kpis = $this->salesSummaryKpis($periodSalesQuery, (clone $periodSalesQuery)->count());
        $kpis['period_payments'] = $this->monthToDateSalesPeriodPaymentsTotal($filters);
        $kpis['previous_collections'] = $this->monthToDateSalesPreviousCollectionTotal($filters);
        $summaryByBranch = $this->monthToDateSalesSummaryByBranch($filters);
        $summaryByType = $this->monthToDateSalesSummaryByType($filters);

        return [
            'filters' => $filters,
            'rows' => $rows,
            'kpis' => $kpis,
            'summaryByBranch' => $summaryByBranch,
            'summaryByType' => $summaryByType,
            'filterBranches' => $this->salesSummaryFilterBranches(),
            'filterSummary' => $this->salesSummaryFilterSummary($filters),
        ];
    }

    /**
     * @return array{rows: Collection<int, SalesInvoice>, filters: array, kpis: array, summaryByBranch: Collection<int, array>, summaryByType: Collection<int, array>, filterBranches: Collection, filterSummary: string}
     */
    public function monthToDateSalesForExport(Request $request): array
    {
        $filters = $this->resolveMonthToDateSalesFilters($request);
        $periodSalesQuery = $this->monthToDateSalesPeriodInvoiceQuery($filters);
        $invoiceRows = $this->applyMonthToDateSalesPaymentDateOrdering(
            (clone $this->monthToDateSalesBaseQuery($filters)),
            $filters,
        )->get();

        $this->enrichMonthToDateSalesRows($invoiceRows, $filters);
        $rows = $this->expandMonthToDateSalesRowsToPaymentLines($invoiceRows, $filters);
        $kpis = $this->salesSummaryKpis($periodSalesQuery, (clone $periodSalesQuery)->count());
        $kpis['period_payments'] = $this->monthToDateSalesPeriodPaymentsTotal($filters);
        $kpis['previous_collections'] = $this->monthToDateSalesPreviousCollectionTotal($filters);
        $summaryByBranch = $this->buildMonthToDateSalesSummaryByBranch($invoiceRows);
        $summaryByType = $this->buildMonthToDateSalesSummaryByType($invoiceRows);

        return [
            'filters' => $filters,
            'rows' => $rows,
            'kpis' => $kpis,
            'summaryByBranch' => $summaryByBranch,
            'summaryByType' => $summaryByType,
            'filterBranches' => $this->salesSummaryFilterBranches(),
            'filterSummary' => $this->salesSummaryFilterSummary($filters),
        ];
    }

    /**
     * @param  array{q: string, date_from: string, date_to: string, branch_id: string, payment_status: string, sale_type: string, exclude_installment: bool}  $filters
     */
    protected function monthToDateSalesBaseQuery(array $filters): Builder
    {
        return $this->monthToDateSalesScopedInvoiceQuery($filters)
            ->where(function (Builder $query) use ($filters): void {
                $query->where(function (Builder $periodSales) use ($filters): void {
                    if ($filters['date_from'] !== '') {
                        $periodSales->whereDate('invoice_date', '>=', $filters['date_from']);
                    }
                    if ($filters['date_to'] !== '') {
                        $periodSales->whereDate('invoice_date', '<=', $filters['date_to']);
                    }
                })->orWhereIn('sales_invoices.id', function ($subQuery) use ($filters): void {
                    $subQuery->select('period_payments.sales_invoice_id')
                        ->fromSub(
                            $this->monthToDateSalesPeriodPaymentsBaseQuery($filters)
                                ->select('payment_allocations.sales_invoice_id')
                                ->distinct(),
                            'period_payments'
                        );
                });
            });
    }

    /**
     * MTD collections include every inbound payment in the period regardless of invoice status.
     * Cash collections on invoices with bad-debt write-offs still appear; write-off amounts
     * are excluded via cash_paid_total.
     *
     * @param  array{q: string, date_from: string, date_to: string, branch_id: string, payment_status: string, sale_type: string, exclude_installment: bool}  $filters
     */
    protected function monthToDateSalesScopedInvoiceQuery(array $filters): Builder
    {
        return SalesInvoice::query()
            ->when($filters['exclude_installment'] ?? false, fn ($query) => $query->excludingInstallmentSales())
            ->when($filters['branch_id'] !== '', fn ($query) => $query->where('branch_id', (int) $filters['branch_id']))
            ->when($filters['branch_id'] === '', fn ($query) => $this->scopeContextBranch($query, 'branch_id'))
            ->when($filters['q'] !== '', function ($query) use ($filters): void {
                $keyword = $filters['q'];
                $query->where(function ($subQuery) use ($keyword): void {
                    $subQuery->where('invoice_number', 'like', "%{$keyword}%")
                        ->orWhereHas('customer', function ($customerQuery) use ($keyword): void {
                            $customerQuery->where('name', 'like', "%{$keyword}%")
                                ->orWhere('phone', 'like', "%{$keyword}%");
                        });
                });
            })
            ->when($filters['payment_status'] !== '', fn ($query) => $query->where('payment_status', $filters['payment_status']))
            ->when($filters['sale_type'] !== '', fn ($query) => $query->where('sale_type', $filters['sale_type']));
    }

    /**
     * @param  array{q: string, date_from: string, date_to: string, branch_id: string, payment_status: string, sale_type: string, exclude_installment: bool}  $filters
     */
    protected function monthToDateSalesPeriodInvoiceQuery(array $filters): Builder
    {
        return $this->monthToDateSalesScopedInvoiceQuery($filters)
            ->when($filters['date_from'] !== '', fn ($query) => $query->whereDate('invoice_date', '>=', $filters['date_from']))
            ->when($filters['date_to'] !== '', fn ($query) => $query->whereDate('invoice_date', '<=', $filters['date_to']));
    }

    /**
     * @param  array{q: string, date_from: string, date_to: string, branch_id: string, payment_status: string, sale_type: string, exclude_installment: bool}  $filters
     */
    protected function applyPeriodPaymentExistsConstraint(Builder $query, array $filters): void
    {
        $query->whereExists(function (QueryBuilder $sub) use ($filters): void {
            $sub->select(DB::raw('1'))
                ->from('payment_allocations')
                ->join('payments', 'payments.id', '=', 'payment_allocations.payment_id')
                ->whereColumn('payment_allocations.sales_invoice_id', 'sales_invoices.id')
                ->where('payments.direction', 'inbound');

            if ($filters['date_from'] !== '') {
                $sub->whereDate('payments.payment_date', '>=', $filters['date_from']);
            }
            if ($filters['date_to'] !== '') {
                $sub->whereDate('payments.payment_date', '<=', $filters['date_to']);
            }
        });
    }

    /**
     * @param  array{q: string, date_from: string, date_to: string, branch_id: string, payment_status: string, sale_type: string, exclude_installment: bool}  $filters
     * @return array{0: string, 1: array<int, string>}
     */
    protected function monthToDateSalesPeriodPaymentDateOrderRaw(array $filters): array
    {
        $sql = '(SELECT MIN(payments.payment_date) FROM payment_allocations
            INNER JOIN payments ON payments.id = payment_allocations.payment_id
            WHERE payment_allocations.sales_invoice_id = sales_invoices.id
            AND payments.direction = ?';
        $bindings = ['inbound'];

        if ($filters['date_from'] !== '') {
            $sql .= ' AND DATE(payments.payment_date) >= ?';
            $bindings[] = $filters['date_from'];
        }
        if ($filters['date_to'] !== '') {
            $sql .= ' AND DATE(payments.payment_date) <= ?';
            $bindings[] = $filters['date_to'];
        }

        $sql .= ')';

        return [$sql, $bindings];
    }

    /**
     * @param  array{q: string, date_from: string, date_to: string, branch_id: string, payment_status: string, sale_type: string, exclude_installment: bool}  $filters
     */
    protected function applyMonthToDateSalesPaymentDateOrdering(Builder $query, array $filters): Builder
    {
        [$paymentDateSql, $paymentDateBindings] = $this->monthToDateSalesPeriodPaymentDateOrderRaw($filters);

        return $query
            ->orderByRaw($paymentDateSql.' ASC', $paymentDateBindings)
            ->orderByRaw('COALESCE((SELECT name FROM locations WHERE locations.id = sales_invoices.branch_id), ?) ASC', ['Unassigned'])
            ->orderBy('sales_invoices.id');
    }

    /**
     * @param  array{q: string, date_from: string, date_to: string, branch_id: string, payment_status: string, sale_type: string, exclude_installment: bool}  $filters
     */
    protected function monthToDateSalesPeriodPaymentsBaseQuery(array $filters): QueryBuilder
    {
        $q = DB::table('payment_allocations')
            ->join('payments', 'payments.id', '=', 'payment_allocations.payment_id')
            ->joinSub(
                $this->monthToDateSalesScopedInvoiceQuery($filters)->select('sales_invoices.id'),
                'scoped_invoices',
                'scoped_invoices.id',
                '=',
                'payment_allocations.sales_invoice_id',
            )
            ->where('payments.direction', 'inbound');

        if ($filters['date_from'] !== '') {
            $q->whereDate('payments.payment_date', '>=', $filters['date_from']);
        }
        if ($filters['date_to'] !== '') {
            $q->whereDate('payments.payment_date', '<=', $filters['date_to']);
        }

        return $q;
    }

    /**
     * @param  array{q: string, date_from: string, date_to: string, branch_id: string, payment_status: string, sale_type: string, exclude_installment: bool}  $filters
     */
    protected function monthToDateSalesPeriodPaymentsTotal(array $filters): float
    {
        $total = $this->monthToDateSalesPeriodPaymentsBaseQuery($filters)
            ->selectRaw('COALESCE(SUM(payment_allocations.allocated_amount + COALESCE(payment_allocations.settlement_discount, 0)), 0) as total')
            ->value('total');

        $syntheticTotal = DB::query()
            ->fromSub($this->monthToDateSalesSyntheticPaidPeriodLinesQuery($filters), 'synthetic_lines')
            ->selectRaw('COALESCE(SUM(period_paid), 0) as total')
            ->value('total');

        return round((float) $total + (float) $syntheticTotal, 2);
    }

    /**
     * @param  array{q: string, date_from: string, date_to: string, branch_id: string, payment_status: string, sale_type: string, exclude_installment: bool}  $filters
     */
    protected function monthToDateSalesPreviousCollectionTotal(array $filters): float
    {
        if ($filters['date_from'] === '') {
            return 0.0;
        }

        $total = $this->monthToDateSalesPeriodPaymentsBaseQuery($filters)
            ->join('sales_invoices', 'sales_invoices.id', '=', 'payment_allocations.sales_invoice_id')
            ->whereDate('sales_invoices.invoice_date', '<', $filters['date_from'])
            ->selectRaw('COALESCE(SUM(payment_allocations.allocated_amount + COALESCE(payment_allocations.settlement_discount, 0)), 0) as total')
            ->value('total');

        return round((float) $total, 2);
    }

    /**
     * @param  array{q: string, date_from: string, date_to: string, branch_id: string, payment_status: string, sale_type: string, exclude_installment: bool}  $filters
     * @param  Collection<int, int>|array<int, int>  $invoiceIds
     * @return Collection<int, array{period_paid: float, period_payment_dates: string}>
     */
    /**
     * One row per invoice per payment date in the filtered period.
     *
     * @param  array{q: string, date_from: string, date_to: string, branch_id: string, payment_status: string, sale_type: string, exclude_installment: bool}  $filters
     */
    protected function monthToDateSalesPeriodPaymentLinesQuery(array $filters): QueryBuilder
    {
        return $this->monthToDateSalesPeriodPaymentsBaseQuery($filters)
            ->groupBy('payment_allocations.sales_invoice_id', DB::raw('DATE(payments.payment_date)'))
            ->selectRaw('payment_allocations.sales_invoice_id as invoice_id')
            ->selectRaw('DATE(payments.payment_date) as period_payment_date')
            ->selectRaw('COALESCE(SUM(payment_allocations.allocated_amount), 0) as period_cash')
            ->selectRaw('COALESCE(SUM(payment_allocations.settlement_discount), 0) as period_discount')
            ->selectRaw('COALESCE(SUM(payment_allocations.allocated_amount + COALESCE(payment_allocations.settlement_discount, 0)), 0) as period_paid')
            ->orderBy('period_payment_date')
            ->orderByRaw('COALESCE((SELECT name FROM locations WHERE locations.id = (SELECT branch_id FROM sales_invoices WHERE sales_invoices.id = payment_allocations.sales_invoice_id)), ?) ASC', ['Unassigned'])
            ->orderBy('payment_allocations.sales_invoice_id');
    }

    /**
     * Paid/settled period invoices without inbound payment allocations dated in the filtered range
     * (e.g. DiziCashier imports, or active installment plans completed without period collections).
     *
     * @param  array{q: string, date_from: string, date_to: string, branch_id: string, payment_status: string, sale_type: string, exclude_installment: bool}  $filters
     */
    protected function monthToDateSalesSyntheticPaidPeriodLinesQuery(array $filters): QueryBuilder
    {
        $invoiceIdsWithPeriodPayments = $this->monthToDateSalesPeriodPaymentsBaseQuery($filters)
            ->select('payment_allocations.sales_invoice_id');

        return DB::table('sales_invoices')
            ->joinSub(
                $this->monthToDateSalesPeriodInvoiceQuery($filters)->select('sales_invoices.id'),
                'scoped_period_invoices',
                'scoped_period_invoices.id',
                '=',
                'sales_invoices.id',
            )
            ->whereNotIn('sales_invoices.id', $invoiceIdsWithPeriodPayments)
            ->where(fn (QueryBuilder $query) => $this->applyMonthToDateSalesSettledForSyntheticRowConstraint($query))
            ->selectRaw('sales_invoices.id as invoice_id')
            ->selectRaw('DATE(sales_invoices.invoice_date) as period_payment_date')
            ->selectRaw($this->monthToDateSalesSyntheticPeriodPaidSelectSql(), ['inbound', 'inbound']);
    }

    protected function monthToDateSalesWithoutInboundAllocationsExistsSql(): string
    {
        return 'NOT EXISTS (
            SELECT 1
            FROM payment_allocations pa
            INNER JOIN payments p ON p.id = pa.payment_id
            WHERE pa.sales_invoice_id = sales_invoices.id
              AND p.direction = ?
        )';
    }

    protected function monthToDateSalesSyntheticPeriodPaidSelectSql(): string
    {
        $paidValueSql = 'CASE
            WHEN '.$this->monthToDateSalesWithoutInboundAllocationsExistsSql().' THEN sales_invoices.amount_paid
            ELSE 0
        END';

        return $paidValueSql.' as period_cash, 0 as period_discount, '.$paidValueSql.' as period_paid';
    }

    protected function applyMonthToDateSalesSettledForSyntheticRowConstraint(QueryBuilder $query): void
    {
        $query->where(function (QueryBuilder $settled): void {
            $settled->where('sales_invoices.payment_status', 'paid')
                ->orWhere('sales_invoices.amount_due', '<=', 0.009)
                ->orWhereRaw('sales_invoices.amount_paid >= sales_invoices.grand_total - 0.009')
                ->orWhere(function (QueryBuilder $headerOnly): void {
                    $headerOnly->where('sales_invoices.amount_paid', '>', 0)
                        ->whereRaw($this->monthToDateSalesWithoutInboundAllocationsExistsSql(), ['inbound']);
                })
                ->orWhereExists(function (QueryBuilder $sub): void {
                    $sub->select(DB::raw('1'))
                        ->from('installment_plans')
                        ->whereColumn('installment_plans.sales_invoice_id', 'sales_invoices.id')
                        ->where('installment_plans.status', 'completed');
                });
        });
    }

    /**
     * @param  array{q: string, date_from: string, date_to: string, branch_id: string, payment_status: string, sale_type: string, exclude_installment: bool}  $filters
     */
    protected function monthToDateSalesDisplayLinesQuery(array $filters): QueryBuilder
    {
        return DB::query()
            ->fromSub(
                $this->monthToDateSalesPeriodPaymentLinesQuery($filters)
                    ->unionAll($this->monthToDateSalesSyntheticPaidPeriodLinesQuery($filters)),
                'mtd_display_lines',
            )
            ->orderBy('period_payment_date')
            ->orderByRaw('COALESCE((SELECT name FROM locations WHERE locations.id = (SELECT branch_id FROM sales_invoices WHERE sales_invoices.id = mtd_display_lines.invoice_id)), ?) ASC', ['Unassigned'])
            ->orderBy('invoice_id');
    }

    /**
     * @param  array{q: string, date_from: string, date_to: string, branch_id: string, payment_status: string, sale_type: string, exclude_installment: bool}  $filters
     * @param  Collection<int, int>|array<int, int>  $invoiceIds
     * @return Collection<int, object{invoice_id: int, period_payment_date: string, period_paid: float}>
     */
    protected function monthToDateSalesPeriodPaymentLinesByInvoiceIds(array $filters, Collection|array $invoiceIds): Collection
    {
        $ids = collect($invoiceIds)->map(fn ($id) => (int) $id)->filter(fn (int $id) => $id > 0)->values();
        if ($ids->isEmpty()) {
            return collect();
        }

        return $this->monthToDateSalesPeriodPaymentsBaseQuery($filters)
            ->whereIn('payment_allocations.sales_invoice_id', $ids->all())
            ->groupBy('payment_allocations.sales_invoice_id', DB::raw('DATE(payments.payment_date)'))
            ->selectRaw('payment_allocations.sales_invoice_id as invoice_id')
            ->selectRaw('DATE(payments.payment_date) as period_payment_date')
            ->selectRaw('COALESCE(SUM(payment_allocations.allocated_amount), 0) as period_cash')
            ->selectRaw('COALESCE(SUM(payment_allocations.settlement_discount), 0) as period_discount')
            ->selectRaw('COALESCE(SUM(payment_allocations.allocated_amount + COALESCE(payment_allocations.settlement_discount, 0)), 0) as period_paid')
            ->orderBy('period_payment_date')
            ->orderBy('payment_allocations.sales_invoice_id')
            ->get()
            ->map(fn (object $row): object => (object) [
                'invoice_id' => (int) $row->invoice_id,
                'period_payment_date' => Carbon::parse((string) $row->period_payment_date)->toDateString(),
                'period_cash' => round((float) $row->period_cash, 2),
                'period_discount' => round((float) $row->period_discount, 2),
                'period_paid' => round((float) $row->period_paid, 2),
            ]);
    }

    /**
     * @param  Collection<int, int>|array<int, int>  $invoiceIds
     * @return Collection<int, Collection<string, array{cash: float, discount: float, total: float}>>
     */
    protected function monthToDateSalesCumulativeAmountsByInvoiceAndDate(Collection|array $invoiceIds): Collection
    {
        $ids = collect($invoiceIds)->map(fn ($id) => (int) $id)->filter(fn (int $id) => $id > 0)->values();
        if ($ids->isEmpty()) {
            return collect();
        }

        $dailyPaid = DB::table('payment_allocations')
            ->join('payments', 'payments.id', '=', 'payment_allocations.payment_id')
            ->whereIn('payment_allocations.sales_invoice_id', $ids->all())
            ->where('payments.direction', 'inbound')
            ->groupBy('payment_allocations.sales_invoice_id', DB::raw('DATE(payments.payment_date)'))
            ->orderBy('payment_allocations.sales_invoice_id')
            ->orderBy(DB::raw('DATE(payments.payment_date)'))
            ->selectRaw('payment_allocations.sales_invoice_id as invoice_id')
            ->selectRaw('DATE(payments.payment_date) as payment_date')
            ->selectRaw('COALESCE(SUM(payment_allocations.allocated_amount), 0) as day_cash')
            ->selectRaw('COALESCE(SUM(payment_allocations.settlement_discount), 0) as day_discount')
            ->get();

        return $dailyPaid
            ->groupBy('invoice_id')
            ->map(function (Collection $days): Collection {
                $runningCash = 0.0;
                $runningDiscount = 0.0;
                $cumulative = collect();

                foreach ($days->sortBy('payment_date') as $day) {
                    $runningCash = round($runningCash + (float) $day->day_cash, 2);
                    $runningDiscount = round($runningDiscount + (float) $day->day_discount, 2);
                    $cumulative->put(
                        Carbon::parse((string) $day->payment_date)->toDateString(),
                        [
                            'cash' => $runningCash,
                            'discount' => $runningDiscount,
                            'total' => round($runningCash + $runningDiscount, 2),
                        ],
                    );
                }

                return $cumulative;
            });
    }

    /**
     * @param  Collection<int, Collection<string, array{cash: float, discount: float, total: float}>>  $cumulativeByInvoice
     */
    protected function monthToDateSalesAmountAtTransaction(
        int $invoiceId,
        string $paymentDate,
        Collection $cumulativeByInvoice,
        string $field = 'total',
    ): float {
        $dates = $cumulativeByInvoice->get($invoiceId, collect());

        $resolve = fn (array $amounts): float => match ($field) {
            'cash' => (float) $amounts['cash'],
            'discount' => (float) $amounts['discount'],
            default => (float) $amounts['total'],
        };

        if ($dates->has($paymentDate)) {
            return $resolve($dates->get($paymentDate));
        }

        $last = $dates
            ->filter(fn (array $amounts, string $date): bool => $date <= $paymentDate)
            ->sortKeys()
            ->last();

        return $last !== null ? $resolve($last) : 0.0;
    }

    /**
     * @param  Collection<int, Collection<string, array{cash: float, discount: float, total: float}>>  $cumulativeByInvoice
     */
    protected function monthToDateSalesPaidAtTransaction(int $invoiceId, string $paymentDate, Collection $cumulativeByInvoice): float
    {
        return $this->monthToDateSalesAmountAtTransaction($invoiceId, $paymentDate, $cumulativeByInvoice, 'total');
    }

    /**
     * Header amount_paid on imported invoices (e.g. DiziCashier S-*) often includes collections
     * not stored as payment allocation rows. Reconcile using grand_total - amount_due.
     *
     * @return array{cash: float, discount: float, total: float}
     */
    protected function monthToDateSalesHeaderBaselineApplied(SalesInvoice $invoice): array
    {
        return $invoice->headerPaymentBaseline();
    }

    /**
     * @param  Collection<int, Collection<string, array{cash: float, discount: float, total: float}>>  $cumulativeByInvoice
     */
    protected function applyMonthToDateSalesTransactionBalances(SalesInvoice $row, string $paymentDate, Collection $cumulativeByInvoice): SalesInvoice
    {
        $baseline = $this->monthToDateSalesHeaderBaselineApplied($row);

        $row->cash_at_transaction = round(
            $this->monthToDateSalesAmountAtTransaction((int) $row->id, $paymentDate, $cumulativeByInvoice, 'cash') + $baseline['cash'],
            2,
        );
        $row->discount_at_transaction = round(
            $this->monthToDateSalesAmountAtTransaction((int) $row->id, $paymentDate, $cumulativeByInvoice, 'discount') + $baseline['discount'],
            2,
        );
        $row->paid_at_transaction = round(
            $this->monthToDateSalesPaidAtTransaction((int) $row->id, $paymentDate, $cumulativeByInvoice) + $baseline['total'],
            2,
        );
        $row->due_at_transaction = round(max(0, (float) $row->grand_total - $row->paid_at_transaction), 2);

        return $row;
    }

    /**
     * @param  LengthAwarePaginator<int, object>|Paginator<int, object>  $paginator
     * @param  array{q: string, date_from: string, date_to: string, branch_id: string, payment_status: string, sale_type: string, exclude_installment: bool}  $filters
     */
    protected function hydrateMonthToDateSalesPaymentLineRows(LengthAwarePaginator $paginator, array $filters): void
    {
        $lines = $paginator->getCollection();
        $invoiceIds = $lines->pluck('invoice_id')->map(fn ($id) => (int) $id)->unique()->values();

        if ($invoiceIds->isEmpty()) {
            $paginator->setCollection(collect());

            return;
        }

        $invoices = $this->monthToDateSalesScopedInvoiceQuery($filters)
            ->whereIn('sales_invoices.id', $invoiceIds->all())
            ->with(['customer', 'branch'])
            ->get()
            ->keyBy('id');

        $cashPaidByInvoice = $this->monthToDateSalesCashPaidByInvoiceIds($invoiceIds);
        $settlementDiscountByInvoice = $this->monthToDateSalesSettlementDiscountByInvoiceIds($invoiceIds);
        $writeoffByInvoice = $this->monthToDateSalesWriteoffByInvoiceIds($invoiceIds);
        $cumulativeByInvoice = $this->monthToDateSalesCumulativeAmountsByInvoiceAndDate($invoiceIds);
        $dateFrom = $filters['date_from'];

        $paginator->setCollection(
            $lines->map(function (object $line) use ($invoices, $cashPaidByInvoice, $settlementDiscountByInvoice, $writeoffByInvoice, $cumulativeByInvoice, $dateFrom): ?SalesInvoice {
                $invoice = $invoices->get((int) $line->invoice_id);
                if (! $invoice instanceof SalesInvoice) {
                    return null;
                }

                $row = clone $invoice;
                $paymentDate = Carbon::parse((string) $line->period_payment_date)->toDateString();
                $row->period_cash = round((float) ($line->period_cash ?? $line->period_paid ?? 0), 2);
                $row->period_discount = round((float) ($line->period_discount ?? 0), 2);
                $row->period_paid = round((float) $line->period_paid, 2);
                $row->period_payment_dates = $paymentDate;
                $row->cash_paid_total = round((float) $cashPaidByInvoice->get($invoice->id, 0.0), 2);
                $row->settlement_discount_total = round((float) $settlementDiscountByInvoice->get($invoice->id, 0.0), 2);
                $row->writeoff_total = round((float) $writeoffByInvoice->get($invoice->id, 0.0), 2);
                $row->is_previous_invoice_collection = $dateFrom !== ''
                    && $invoice->invoice_date !== null
                    && $invoice->invoice_date->toDateString() < $dateFrom;

                return $this->applyMonthToDateSalesRowTransactionBalances($row, $paymentDate, $cumulativeByInvoice);
            })->filter()->values(),
        );
    }

    /**
     * @param  Collection<int, Collection<string, float>>  $cumulativeByInvoice
     */
    protected function applyMonthToDateSalesRowTransactionBalances(
        SalesInvoice $row,
        string $paymentDate,
        Collection $cumulativeByInvoice,
    ): SalesInvoice {
        $row = $this->applyMonthToDateSalesTransactionBalances($row, $paymentDate, $cumulativeByInvoice);

        if (
            $cumulativeByInvoice->get((int) $row->id, collect())->isEmpty()
            && $this->monthToDateSalesHeaderBaselineApplied($row)['total'] <= 0.009
            && $this->monthToDateSalesInvoiceAppearsFullyPaid($row)
        ) {
            $settlementDiscount = round((float) ($row->settlement_discount_total ?? 0), 2);
            $row->paid_at_transaction = round((float) $row->amount_paid, 2);
            $row->cash_at_transaction = round(max(0, $row->paid_at_transaction - $settlementDiscount), 2);
            $row->discount_at_transaction = $settlementDiscount;
            $row->due_at_transaction = round(max(0, (float) $row->grand_total - $row->paid_at_transaction), 2);
        }

        return $row;
    }

    protected function monthToDateSalesInvoiceAppearsFullyPaid(SalesInvoice $invoice): bool
    {
        if ($invoice->payment_status === 'paid' || (float) $invoice->amount_due <= 0.009) {
            return true;
        }

        if ((float) $invoice->amount_paid >= (float) $invoice->grand_total - 0.009) {
            return true;
        }

        if ($invoice->sale_type === 'installment') {
            $invoice->loadMissing('installmentPlan');
            if ($invoice->installmentPlan && $invoice->installmentPlan->status === 'completed') {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  Collection<int, SalesInvoice>  $invoices
     * @param  array{q: string, date_from: string, date_to: string, branch_id: string, payment_status: string, sale_type: string, exclude_installment: bool}  $filters
     * @return Collection<int, SalesInvoice>
     */
    protected function expandMonthToDateSalesRowsToPaymentLines(Collection $invoices, array $filters): Collection
    {
        if ($invoices->isEmpty()) {
            return collect();
        }

        $lines = $this->monthToDateSalesDisplayLinesByInvoiceIds($filters, $invoices->pluck('id'));
        $invoicesById = $invoices->keyBy('id');
        $settlementDiscountByInvoice = $this->monthToDateSalesSettlementDiscountByInvoiceIds($invoices->pluck('id'));
        $cumulativeByInvoice = $this->monthToDateSalesCumulativeAmountsByInvoiceAndDate($invoices->pluck('id'));
        $dateFrom = $filters['date_from'];

        return $lines->map(function (object $line) use ($invoicesById, $settlementDiscountByInvoice, $cumulativeByInvoice, $dateFrom): ?SalesInvoice {
            $invoice = $invoicesById->get((int) $line->invoice_id);
            if (! $invoice instanceof SalesInvoice) {
                return null;
            }

            $row = clone $invoice;
            $paymentDate = (string) $line->period_payment_date;
            $row->period_cash = round((float) ($line->period_cash ?? $line->period_paid ?? 0), 2);
            $row->period_discount = round((float) ($line->period_discount ?? 0), 2);
            $row->period_paid = (float) $line->period_paid;
            $row->period_payment_dates = $paymentDate;
            $row->settlement_discount_total = round((float) $settlementDiscountByInvoice->get($invoice->id, 0.0), 2);
            $row->is_previous_invoice_collection = $dateFrom !== ''
                && $invoice->invoice_date !== null
                && $invoice->invoice_date->toDateString() < $dateFrom;

            return $this->applyMonthToDateSalesRowTransactionBalances($row, $paymentDate, $cumulativeByInvoice);
        })->filter()->values();
    }

    /**
     * @param  array{q: string, date_from: string, date_to: string, branch_id: string, payment_status: string, sale_type: string, exclude_installment: bool}  $filters
     * @param  Collection<int, int>|array<int, int>  $invoiceIds
     * @return Collection<int, object{invoice_id: int, period_payment_date: string, period_paid: float}>
     */
    protected function monthToDateSalesDisplayLinesByInvoiceIds(array $filters, Collection|array $invoiceIds): Collection
    {
        $ids = collect($invoiceIds)->map(fn ($id) => (int) $id)->filter(fn (int $id) => $id > 0)->values();
        if ($ids->isEmpty()) {
            return collect();
        }

        return DB::query()
            ->fromSub(
                $this->monthToDateSalesPeriodPaymentLinesQuery($filters)
                    ->whereIn('payment_allocations.sales_invoice_id', $ids->all())
                    ->unionAll(
                        $this->monthToDateSalesSyntheticPaidPeriodLinesQuery($filters)
                            ->whereIn('sales_invoices.id', $ids->all())
                    ),
                'mtd_display_lines',
            )
            ->orderBy('period_payment_date')
            ->orderBy('invoice_id')
            ->get()
            ->map(fn (object $row): object => (object) [
                'invoice_id' => (int) $row->invoice_id,
                'period_payment_date' => Carbon::parse((string) $row->period_payment_date)->toDateString(),
                'period_cash' => round((float) ($row->period_cash ?? $row->period_paid ?? 0), 2),
                'period_discount' => round((float) ($row->period_discount ?? 0), 2),
                'period_paid' => round((float) $row->period_paid, 2),
            ]);
    }

    /**
     * @param  array{q: string, date_from: string, date_to: string, branch_id: string, payment_status: string, sale_type: string, exclude_installment: bool}  $filters
     * @param  Collection<int, int>|array<int, int>  $invoiceIds
     * @return Collection<int, array{period_paid: float, period_payment_dates: string}>
     */
    protected function monthToDateSalesPeriodPaymentMetaByInvoiceIds(array $filters, Collection|array $invoiceIds): Collection
    {
        $ids = collect($invoiceIds)->map(fn ($id) => (int) $id)->filter(fn (int $id) => $id > 0)->values();
        if ($ids->isEmpty()) {
            return collect();
        }

        return $this->monthToDateSalesDisplayLinesByInvoiceIds($filters, $ids)
            ->groupBy('invoice_id')
            ->mapWithKeys(function (Collection $lines, int $invoiceId): array {
                return [
                    $invoiceId => [
                        'period_paid' => round($lines->sum(fn (object $line): float => (float) $line->period_paid), 2),
                        'period_cash' => round($lines->sum(fn (object $line): float => (float) ($line->period_cash ?? $line->period_paid ?? 0)), 2),
                        'period_discount' => round($lines->sum(fn (object $line): float => (float) ($line->period_discount ?? 0)), 2),
                        'period_payment_dates' => $lines
                            ->pluck('period_payment_date')
                            ->unique()
                            ->sort()
                            ->values()
                            ->join(', '),
                    ],
                ];
            });
    }

    /**
     * @param  array{q: string, date_from: string, date_to: string, branch_id: string, payment_status: string, sale_type: string, exclude_installment: bool}  $filters
     * @param  Collection<int, int>|array<int, int>  $invoiceIds
     * @return Collection<int|string, float>
     */
    protected function monthToDateSalesPeriodPaidByInvoiceIds(array $filters, Collection|array $invoiceIds): Collection
    {
        return $this->monthToDateSalesPeriodPaymentMetaByInvoiceIds($filters, $invoiceIds)
            ->map(fn (array $meta): float => $meta['period_paid']);
    }

    protected function formatPeriodPaymentDates(?string $raw): string
    {
        if ($raw === null || trim($raw) === '') {
            return '';
        }

        return collect(explode(',', $raw))
            ->map(fn (string $date): string => trim($date))
            ->filter(fn (string $date): bool => $date !== '')
            ->map(fn (string $date): string => Carbon::parse($date)->toDateString())
            ->unique()
            ->sort()
            ->values()
            ->join(', ');
    }

    /**
     * @param  LengthAwarePaginator<int, SalesInvoice>|Collection<int, SalesInvoice>  $rows
     * @param  array{q: string, date_from: string, date_to: string, branch_id: string, payment_status: string, sale_type: string, exclude_installment: bool}  $filters
     */
    protected function enrichMonthToDateSalesRows(LengthAwarePaginator|Collection $rows, array $filters): void
    {
        $collection = $rows instanceof LengthAwarePaginator ? $rows->getCollection() : $rows;
        $invoiceIds = $collection->pluck('id');
        $periodPaymentMetaByInvoice = $this->monthToDateSalesPeriodPaymentMetaByInvoiceIds($filters, $invoiceIds);
        $cashPaidByInvoice = $this->monthToDateSalesCashPaidByInvoiceIds($invoiceIds);
        $settlementDiscountByInvoice = $this->monthToDateSalesSettlementDiscountByInvoiceIds($invoiceIds);
        $writeoffByInvoice = $this->monthToDateSalesWriteoffByInvoiceIds($invoiceIds);
        $dateFrom = $filters['date_from'];

        $collection->transform(function (SalesInvoice $invoice) use ($periodPaymentMetaByInvoice, $cashPaidByInvoice, $settlementDiscountByInvoice, $writeoffByInvoice, $dateFrom): SalesInvoice {
            $meta = $periodPaymentMetaByInvoice->get($invoice->id, [
                'period_paid' => 0.0,
                'period_cash' => 0.0,
                'period_discount' => 0.0,
                'period_payment_dates' => '',
            ]);
            $invoice->period_paid = (float) $meta['period_paid'];
            $invoice->period_cash = (float) $meta['period_cash'];
            $invoice->period_discount = (float) $meta['period_discount'];
            $invoice->period_payment_dates = (string) $meta['period_payment_dates'];
            $invoice->cash_paid_total = round((float) $cashPaidByInvoice->get($invoice->id, 0.0), 2);
            $invoice->settlement_discount_total = round((float) $settlementDiscountByInvoice->get($invoice->id, 0.0), 2);
            $invoice->writeoff_total = round((float) $writeoffByInvoice->get($invoice->id, 0.0), 2);
            $invoice->is_previous_invoice_collection = $dateFrom !== ''
                && $invoice->invoice_date !== null
                && $invoice->invoice_date->toDateString() < $dateFrom;

            return $invoice;
        });
    }

    /**
     * @param  Collection<int, int>|array<int, int>  $invoiceIds
     * @return Collection<int, float>
     */
    protected function monthToDateSalesCashPaidByInvoiceIds(Collection|array $invoiceIds): Collection
    {
        $ids = collect($invoiceIds)->map(fn ($id) => (int) $id)->filter(fn (int $id) => $id > 0)->values();
        if ($ids->isEmpty()) {
            return collect();
        }

        return DB::table('payment_allocations')
            ->join('payments', 'payments.id', '=', 'payment_allocations.payment_id')
            ->where('payments.direction', 'inbound')
            ->whereIn('payment_allocations.sales_invoice_id', $ids->all())
            ->groupBy('payment_allocations.sales_invoice_id')
            ->selectRaw('payment_allocations.sales_invoice_id as invoice_id, COALESCE(SUM(payment_allocations.allocated_amount), 0) as cash_paid')
            ->pluck('cash_paid', 'invoice_id')
            ->map(fn ($amount): float => round((float) $amount, 2));
    }

    /**
     * @param  Collection<int, int>|array<int, int>  $invoiceIds
     * @return Collection<int, float>
     */
    protected function monthToDateSalesSettlementDiscountByInvoiceIds(Collection|array $invoiceIds): Collection
    {
        $ids = collect($invoiceIds)->map(fn ($id) => (int) $id)->filter(fn (int $id) => $id > 0)->values();
        if ($ids->isEmpty()) {
            return collect();
        }

        return DB::table('payment_allocations')
            ->join('payments', 'payments.id', '=', 'payment_allocations.payment_id')
            ->where('payments.direction', 'inbound')
            ->whereIn('payment_allocations.sales_invoice_id', $ids->all())
            ->groupBy('payment_allocations.sales_invoice_id')
            ->selectRaw('payment_allocations.sales_invoice_id as invoice_id, COALESCE(SUM(payment_allocations.settlement_discount), 0) as settlement_discount')
            ->pluck('settlement_discount', 'invoice_id')
            ->map(fn ($amount): float => round((float) $amount, 2));
    }

    /**
     * @param  Collection<int, int>|array<int, int>  $invoiceIds
     * @return Collection<int, float>
     */
    protected function monthToDateSalesWriteoffByInvoiceIds(Collection|array $invoiceIds): Collection
    {
        $ids = collect($invoiceIds)->map(fn ($id) => (int) $id)->filter(fn (int $id) => $id > 0)->values();
        if ($ids->isEmpty()) {
            return collect();
        }

        return DB::table('invoice_debt_writeoffs')
            ->whereIn('sales_invoice_id', $ids->all())
            ->groupBy('sales_invoice_id')
            ->selectRaw('sales_invoice_id as invoice_id, COALESCE(SUM(amount), 0) as writeoff_total')
            ->pluck('writeoff_total', 'invoice_id')
            ->map(fn ($amount): float => round((float) $amount, 2));
    }

    /**
     * Cash collected on invoice only — excludes bad-debt write-offs stored on amount_paid.
     */
    protected function monthToDateSalesCashPaidAmount(SalesInvoice $invoice): float
    {
        $fromAlloc = isset($invoice->cash_paid_total)
            ? round((float) $invoice->cash_paid_total, 2)
            : round(max(0, (float) $invoice->amount_paid - (float) ($invoice->settlement_discount_total ?? 0)), 2);

        return round($fromAlloc + $this->monthToDateSalesHeaderBaselineApplied($invoice)['cash'], 2);
    }

    protected function monthToDateSalesSettlementDiscountAmount(SalesInvoice $invoice): float
    {
        $fromAlloc = round((float) ($invoice->settlement_discount_total ?? 0), 2);

        return round($fromAlloc + $this->monthToDateSalesHeaderBaselineApplied($invoice)['discount'], 2);
    }

    protected function monthToDateSalesWriteoffAmount(SalesInvoice $invoice): float
    {
        return round((float) ($invoice->writeoff_total ?? 0), 2);
    }

    /**
     * Balance due aligned with Paid (cash) and Settlement discount so summary rows reconcile.
     */
    protected function monthToDateSalesDueAmount(SalesInvoice $invoice): float
    {
        $grandTotal = round((float) $invoice->grand_total, 2);
        $cash = $this->monthToDateSalesCashPaidAmount($invoice);
        $discount = $this->monthToDateSalesSettlementDiscountAmount($invoice);
        $writeoff = $this->monthToDateSalesWriteoffAmount($invoice);

        return round(max(0, $grandTotal - $cash - $discount - $writeoff), 2);
    }

    /**
     * @param  array{q: string, date_from: string, date_to: string, branch_id: string, payment_status: string, sale_type: string, exclude_installment: bool}  $filters
     * @return Collection<int, array{branch_id: int, branch_name: string, branch_code: string, invoices: int, grand_total: float, amount_paid: float, period_paid: float, amount_due: float, previous_collections: float, by_type: array<int, array>}>
     */
    protected function monthToDateSalesSummaryByBranch(array $filters): Collection
    {
        $rows = $this->applyMonthToDateSalesPaymentDateOrdering(
            (clone $this->monthToDateSalesBaseQuery($filters)),
            $filters,
        )->get();

        $this->enrichMonthToDateSalesRows($rows, $filters);

        return $this->buildMonthToDateSalesSummaryByBranch($rows);
    }

    /**
     * @param  Collection<int, SalesInvoice>  $rows
     * @return Collection<int, array{branch_id: int, branch_name: string, branch_code: string, invoices: int, grand_total: float, amount_paid: float, period_paid: float, amount_due: float, previous_collections: float, by_type: array<int, array>}>
     */
    protected function buildMonthToDateSalesSummaryByBranch(Collection $rows): Collection
    {
        return $rows
            ->groupBy(fn (SalesInvoice $invoice): string => (string) ($invoice->branch_id ?? 0))
            ->map(function (Collection $group, string $branchId): array {
                /** @var SalesInvoice|null $first */
                $first = $group->first();
                $branch = $first?->branch;

                return [
                    'branch_id' => (int) $branchId,
                    'branch_name' => $branch?->name ?? 'Unassigned',
                    'branch_code' => $branch?->code ?? '',
                    'invoices' => $group->count(),
                    'grand_total' => round($group->sum(fn (SalesInvoice $invoice): float => (float) $invoice->grand_total), 2),
                    'amount_paid' => round($group->sum(fn (SalesInvoice $invoice): float => $this->monthToDateSalesCashPaidAmount($invoice)), 2),
                    'settlement_discount' => round($group->sum(fn (SalesInvoice $invoice): float => $this->monthToDateSalesSettlementDiscountAmount($invoice)), 2),
                    'writeoff' => round($group->sum(fn (SalesInvoice $invoice): float => $this->monthToDateSalesWriteoffAmount($invoice)), 2),
                    'period_paid' => round($group->sum(fn (SalesInvoice $invoice): float => (float) ($invoice->period_paid ?? 0)), 2),
                    'period_cash' => round($group->sum(fn (SalesInvoice $invoice): float => (float) ($invoice->period_cash ?? $invoice->period_paid ?? 0)), 2),
                    'period_discount' => round($group->sum(fn (SalesInvoice $invoice): float => (float) ($invoice->period_discount ?? 0)), 2),
                    'amount_due' => round($group->sum(fn (SalesInvoice $invoice): float => $this->monthToDateSalesDueAmount($invoice)), 2),
                    'previous_collections' => round($group->sum(fn (SalesInvoice $invoice): float => ! empty($invoice->is_previous_invoice_collection) ? (float) ($invoice->period_paid ?? 0) : 0.0), 2),
                    'by_type' => $this->buildMonthToDateSalesSummaryByType($group)->values()->all(),
                ];
            })
            ->sortBy('branch_name')
            ->values();
    }

    /**
     * @param  array{q: string, date_from: string, date_to: string, branch_id: string, payment_status: string, sale_type: string, exclude_installment: bool}  $filters
     * @return Collection<int, array{type: string, label: string, invoices: int, grand_total: float, amount_paid: float, period_paid: float, amount_due: float}>
     */
    protected function monthToDateSalesSummaryByType(array $filters): Collection
    {
        $rows = (clone $this->monthToDateSalesBaseQuery($filters))
            ->latest('invoice_date')
            ->latest('id')
            ->get();

        $this->enrichMonthToDateSalesRows($rows, $filters);

        return $this->buildMonthToDateSalesSummaryByType($rows);
    }

    /**
     * @param  Collection<int, SalesInvoice>  $rows
     * @return Collection<int, array{type: string, label: string, invoices: int, grand_total: float, period_paid: float, amount_due: float}>
     */
    protected function buildMonthToDateSalesSummaryByType(Collection $rows): Collection
    {
        return $rows
            ->groupBy(fn (SalesInvoice $invoice): string => (string) $invoice->sale_type)
            ->map(function (Collection $group, string $type): array {
                return [
                    'type' => $type,
                    'label' => $this->monthToDateSalesTypeLabel($type),
                    'invoices' => $group->count(),
                    'grand_total' => round($group->sum(fn (SalesInvoice $invoice): float => (float) $invoice->grand_total), 2),
                    'amount_paid' => round($group->sum(fn (SalesInvoice $invoice): float => $this->monthToDateSalesCashPaidAmount($invoice)), 2),
                    'settlement_discount' => round($group->sum(fn (SalesInvoice $invoice): float => $this->monthToDateSalesSettlementDiscountAmount($invoice)), 2),
                    'writeoff' => round($group->sum(fn (SalesInvoice $invoice): float => $this->monthToDateSalesWriteoffAmount($invoice)), 2),
                    'period_paid' => round($group->sum(fn (SalesInvoice $invoice): float => (float) ($invoice->period_paid ?? 0)), 2),
                    'period_cash' => round($group->sum(fn (SalesInvoice $invoice): float => (float) ($invoice->period_cash ?? $invoice->period_paid ?? 0)), 2),
                    'period_discount' => round($group->sum(fn (SalesInvoice $invoice): float => (float) ($invoice->period_discount ?? 0)), 2),
                    'amount_due' => round($group->sum(fn (SalesInvoice $invoice): float => $this->monthToDateSalesDueAmount($invoice)), 2),
                ];
            })
            ->sortBy(fn (array $row): int => match ($row['type']) {
                'cash' => 1,
                'credit' => 2,
                'installment' => 3,
                default => 99,
            })
            ->values();
    }

    protected function monthToDateSalesTypeLabel(string $type): string
    {
        return match ($type) {
            'default', 'cash' => 'Cash',
            'credit' => 'Credit',
            'installment' => 'Installment',
            default => ucfirst(str_replace('_', ' ', $type)),
        };
    }

    /**
     * @param  array{q: string, date_from: string, date_to: string, branch_id: string, payment_status: string, sale_type: string, exclude_installment: bool}  $filters
     */
    protected function salesSummaryPeriodPaymentsBaseQuery(array $filters): QueryBuilder
    {
        $q = DB::table('payment_allocations')
            ->join('payments', 'payments.id', '=', 'payment_allocations.payment_id')
            ->joinSub(
                $this->salesSummaryBaseQuery($filters)->select('sales_invoices.id'),
                'filtered_invoices',
                'filtered_invoices.id',
                '=',
                'payment_allocations.sales_invoice_id',
            )
            ->where('payments.direction', 'inbound');

        if ($filters['date_from'] !== '') {
            $q->whereDate('payments.payment_date', '>=', $filters['date_from']);
        }
        if ($filters['date_to'] !== '') {
            $q->whereDate('payments.payment_date', '<=', $filters['date_to']);
        }

        return $q;
    }

    /**
     * @param  array{q: string, date_from: string, date_to: string, branch_id: string, payment_status: string, sale_type: string, exclude_installment: bool}  $filters
     * @param  Collection<int, int>|array<int, int>  $invoiceIds
     * @return Collection<int|string, float>
     */
    protected function salesSummaryPeriodPaidByInvoiceIds(array $filters, Collection|array $invoiceIds): Collection
    {
        $ids = collect($invoiceIds)->map(fn ($id) => (int) $id)->filter(fn (int $id) => $id > 0)->values();
        if ($ids->isEmpty()) {
            return collect();
        }

        return $this->salesSummaryPeriodPaymentsBaseQuery($filters)
            ->whereIn('payment_allocations.sales_invoice_id', $ids->all())
            ->groupBy('payment_allocations.sales_invoice_id')
            ->selectRaw('payment_allocations.sales_invoice_id as invoice_id, COALESCE(SUM(payment_allocations.allocated_amount + COALESCE(payment_allocations.settlement_discount, 0)), 0) as period_paid')
            ->pluck('period_paid', 'invoice_id')
            ->map(fn ($value) => round((float) $value, 2));
    }

    /**
     * @param  array{q: string, date_from: string, date_to: string, branch_id: string, payment_status: string, sale_type: string, exclude_installment: bool}  $filters
     */
    protected function salesSummaryPeriodPaymentsTotal(array $filters): float
    {
        $total = $this->salesSummaryPeriodPaymentsBaseQuery($filters)
            ->selectRaw('COALESCE(SUM(payment_allocations.allocated_amount + COALESCE(payment_allocations.settlement_discount, 0)), 0) as total')
            ->value('total');

        return round((float) $total, 2);
    }

    /**
     * @param  LengthAwarePaginator<int, SalesInvoice>|Collection<int, SalesInvoice>  $rows
     * @param  array{q: string, date_from: string, date_to: string, branch_id: string, payment_status: string, sale_type: string, exclude_installment: bool}  $filters
     */
    protected function enrichSalesSummaryRowsWithPeriodPayments(LengthAwarePaginator|Collection $rows, array $filters): void
    {
        $collection = $rows instanceof LengthAwarePaginator ? $rows->getCollection() : $rows;
        $periodPaidByInvoice = $this->salesSummaryPeriodPaidByInvoiceIds($filters, $collection->pluck('id'));

        $collection->transform(function (SalesInvoice $invoice) use ($periodPaidByInvoice): SalesInvoice {
            $invoice->period_paid = round((float) ($periodPaidByInvoice->get($invoice->id) ?? 0), 2);

            return $invoice;
        });
    }

    /**
     * @param  array{q: string, date_from: string, date_to: string, branch_id: string, payment_status: string, sale_type: string}  $filters
     */
    public function salesSummaryFilterSummary(array $filters): string
    {
        $parts = [];
        if ($filters['q'] !== '') {
            $parts[] = 'Search: '.$filters['q'];
        }
        if ($filters['branch_id'] !== '') {
            $b = Location::query()->whereKey((int) $filters['branch_id'])->first();
            $parts[] = 'Branch: '.($b ? $b->name : $filters['branch_id']);
        }
        if ($filters['date_from'] !== '') {
            $parts[] = 'From '.$filters['date_from'];
        }
        if ($filters['date_to'] !== '') {
            $parts[] = 'To '.$filters['date_to'];
        }
        if ($filters['payment_status'] !== '') {
            $parts[] = 'Payment: '.$filters['payment_status'];
        }
        if ($filters['sale_type'] !== '') {
            $parts[] = 'Sale type: '.$filters['sale_type'];
        }

        return $parts !== [] ? implode(' · ', $parts) : 'All filters';
    }

    /**
     * Branch search filters shared by credit overdue invoices and overdue installment schedules.
     *
     * @param  Builder<SalesInvoice>  $query
     * @param  array{q: string, branch_id: string}  $filters
     */
    protected function applyReceivablesReportInvoiceFilters(Builder $query, array $filters): void
    {
        $query
            ->excludingFullyReturned()
            ->when($filters['branch_id'] !== '', fn ($q) => $q->where('branch_id', (int) $filters['branch_id']))
            ->when($filters['branch_id'] === '', fn ($q) => $this->scopeContextBranch($q, 'branch_id'))
            ->when($filters['q'] !== '', function ($q) use ($filters): void {
                $keyword = $filters['q'];
                $q->where(function ($subQuery) use ($keyword): void {
                    $subQuery->where('invoice_number', 'like', "%{$keyword}%")
                        ->orWhereHas('customer', function ($customerQuery) use ($keyword): void {
                            $customerQuery->where('name', 'like', "%{$keyword}%")
                                ->orWhere('phone', 'like', "%{$keyword}%");
                        });
                });
            });
    }

    /**
     * @param  array{q: string, branch_id: string}  $filters
     * @return Builder<InstallmentSchedule>
     */
    public function installmentOverdueBaseQuery(array $filters): Builder
    {
        $today = now()->toDateString();

        return InstallmentSchedule::query()
            ->with([
                'installmentPlan.salesInvoice.customer',
                'installmentPlan.salesInvoice.branch',
            ])
            ->whereDate('installment_schedules.due_date', '<', $today)
            ->whereIn('installment_schedules.status', ['pending', 'partially_paid', 'overdue'])
            ->whereHas('installmentPlan.salesInvoice', function ($q) use ($filters): void {
                $this->applyReceivablesReportInvoiceFilters($q, $filters);
            });
    }

    /**
     * Installment invoices whose last scheduled due date is before today while the plan is still unpaid.
     *
     * @param  array{q: string, branch_id: string}  $filters
     * @return Builder<SalesInvoice>
     */
    public function creditOverdueMaturedInstallmentsBase(array $filters): Builder
    {
        return $this->maturedUnpaidInstallmentInvoicesBase($filters, now()->toDateString())
            ->with($this->monthlyCollectionMaturedOverdueEagerLoads());
    }

    public const INSTALLMENT_OVERDUE_LIST_LIMIT = 200;

    /**
     * @param  array{q: string, branch_id: string}  $filters
     * @return array{schedules: int, remaining: float, due_and_late_fee: float, paid: float}
     */
    public function installmentOverdueKpisForFilters(array $filters): array
    {
        $base = $this->installmentOverdueBaseQuery($filters);
        $aggregate = (clone $base)->toBase()
            ->selectRaw('COUNT(*) as schedule_count')
            ->selectRaw('SUM(CASE WHEN (due_amount + COALESCE(late_fee_amount, 0) - COALESCE(paid_amount, 0)) > 0 THEN due_amount + COALESCE(late_fee_amount, 0) - COALESCE(paid_amount, 0) ELSE 0 END) as remaining_sum')
            ->selectRaw('SUM(due_amount + COALESCE(late_fee_amount, 0)) as due_late_sum')
            ->selectRaw('SUM(COALESCE(paid_amount, 0)) as paid_sum')
            ->first();

        return [
            'schedules' => (int) ($aggregate->schedule_count ?? 0),
            'remaining' => round((float) ($aggregate->remaining_sum ?? 0), 2),
            'due_and_late_fee' => round((float) ($aggregate->due_late_sum ?? 0), 2),
            'paid' => round((float) ($aggregate->paid_sum ?? 0), 2),
        ];
    }

    /**
     * @return array{
     *     rows: Collection<int, InstallmentSchedule>,
     *     filters: array{q: string, branch_id: string},
     *     filterSummary: string,
     *     kpis: array{schedules: int, remaining: float, due_and_late_fee: float, paid: float},
     *     rowLimit: int,
     *     totalMatching: int,
     *     filterBranches: Collection
     * }
     */
    public function installmentOverdueForList(?Request $request = null): array
    {
        $filters = [
            'q' => $request ? trim((string) $request->input('q', '')) : '',
            'branch_id' => $request ? (string) $request->input('branch_id', '') : '',
        ];
        $baseQuery = $this->installmentOverdueBaseQuery($filters);
        $rows = (clone $baseQuery)
            ->orderBy('due_date')
            ->limit(self::INSTALLMENT_OVERDUE_LIST_LIMIT)
            ->get();

        return [
            'rows' => $rows,
            'filters' => $filters,
            'filterSummary' => 'Overdue schedules (max '.self::INSTALLMENT_OVERDUE_LIST_LIMIT.' rows); scoped to branch context',
            'kpis' => $this->installmentOverdueKpisForFilters($filters),
            'rowLimit' => self::INSTALLMENT_OVERDUE_LIST_LIMIT,
            'totalMatching' => (clone $baseQuery)->count(),
            'filterBranches' => $this->salesSummaryFilterBranches(),
        ];
    }

    public function installmentOverdueForExport(?Request $request = null): array
    {
        $filters = [
            'q' => $request ? trim((string) $request->input('q', '')) : '',
            'branch_id' => $request ? (string) $request->input('branch_id', '') : '',
            'sort' => ($request && in_array($request->input('sort'), ['asc', 'desc'], true)) ? $request->input('sort') : 'asc',
        ];
        $baseQuery = $this->installmentOverdueBaseQuery($filters);
        $rows = (clone $baseQuery)
            ->orderBy('due_date', $filters['sort'])
            ->get();

        return [
            'rows' => $rows,
            'filters' => $filters,
            'kpis' => $this->installmentOverdueKpisForFilters($filters),
            'filterBranches' => $this->salesSummaryFilterBranches(),
            'filterSummary' => 'Overdue schedules · scoped to branch context',
            'totalMatching' => $rows->count(),
        ];
    }

    public function installmentOverduePaginated(Request $request): array
    {
        $filters = [
            'q' => trim((string) $request->input('q', '')),
            'branch_id' => (string) $request->input('branch_id', ''),
            'sort' => in_array($request->input('sort'), ['asc', 'desc'], true) ? $request->input('sort') : 'asc',
        ];
        $baseQuery = $this->installmentOverdueBaseQuery($filters);
        $perPage = $this->resolveReportPerPage($request, 20);
        $rows = (clone $baseQuery)
            ->orderBy('due_date', $filters['sort'])
            ->paginate($perPage)
            ->withQueryString();

        return [
            'rows' => $rows,
            'filters' => $filters,
            'kpis' => $this->installmentOverdueKpisForFilters($filters),
            'filterBranches' => $this->salesSummaryFilterBranches(),
        ];
    }

    /**
     * @return array{q: string, branch_id: string, status: string, date_from: string, date_to: string}
     */
    public function installmentDurationFilters(Request $request): array
    {
        return [
            'q' => trim((string) $request->input('q', '')),
            'branch_id' => (string) $request->input('branch_id', ''),
            'status' => (string) $request->input('status', ''),
            'date_from' => (string) $request->input('date_from', ''),
            'date_to' => (string) $request->input('date_to', ''),
        ];
    }

    /**
     * @param  array{q: string, branch_id: string, status: string, date_from: string, date_to: string}  $filters
     * @return Builder<InstallmentPlan>
     */
    public function installmentDurationBaseQuery(array $filters): Builder
    {
        return InstallmentPlan::query()
            ->with([
                'schedules.paymentAllocations.payment',
                'salesInvoice.customer',
                'salesInvoice.branch',
            ])
            ->whereHas('salesInvoice', function (Builder $q) use ($filters): void {
                $this->applyReceivablesReportInvoiceFilters($q, $filters);
                $q->when($filters['date_from'] !== '', fn ($qq) => $qq->whereDate('invoice_date', '>=', $filters['date_from']))
                    ->when($filters['date_to'] !== '', fn ($qq) => $qq->whereDate('invoice_date', '<=', $filters['date_to']));
            })
            ->when(in_array($filters['status'], ['active', 'completed'], true), fn ($q) => $q->where('status', $filters['status']));
    }

    /**
     * @param  array{q: string, branch_id: string, status: string, date_from: string, date_to: string}  $filters
     */
    public function installmentDurationFilterSummary(array $filters): string
    {
        $parts = ['Installment plan duration: planned vs. actual payoff time'];
        if ($filters['q'] !== '') {
            $parts[] = 'Search: '.$filters['q'];
        }
        if ($filters['branch_id'] !== '') {
            $b = Location::query()->whereKey((int) $filters['branch_id'])->first();
            $parts[] = 'Branch: '.($b ? $b->name : $filters['branch_id']);
        }
        if ($filters['status'] !== '') {
            $parts[] = 'Status: '.ucfirst($filters['status']);
        }
        if ($filters['date_from'] !== '' || $filters['date_to'] !== '') {
            $parts[] = 'Invoice date: '.($filters['date_from'] !== '' ? $filters['date_from'] : '…').' to '.($filters['date_to'] !== '' ? $filters['date_to'] : '…');
        }

        return implode(' · ', $parts);
    }

    /**
     * Aggregate KPIs for the filtered set — averaged over the full filtered set,
     * not just the current page or export row cap.
     *
     * @param  array{q: string, branch_id: string, status: string, date_from: string, date_to: string}  $filters
     * @return array{plans: int, active: int, completed: int, avg_planned_days: float, avg_actual_days: float, avg_variance_days: float, overran_count: int}
     */
    public function installmentDurationTotals(array $filters): array
    {
        $plans = $this->installmentDurationBaseQuery($filters)->get();

        $completed = $plans->filter(fn (InstallmentPlan $p) => $p->status === 'completed');
        $plannedDurations = $plans->map(fn (InstallmentPlan $p) => $p->plannedDurationDays())->filter(fn ($v) => $v !== null);
        $actualDurations = $completed->map(fn (InstallmentPlan $p) => $p->actualDurationDays())->filter(fn ($v) => $v !== null);
        $variances = $completed->map(fn (InstallmentPlan $p) => $p->durationVarianceDays())->filter(fn ($v) => $v !== null);

        return [
            'plans' => $plans->count(),
            'active' => $plans->where('status', 'active')->count(),
            'completed' => $completed->count(),
            'avg_planned_days' => $plannedDurations->isNotEmpty() ? round($plannedDurations->avg(), 1) : 0.0,
            'avg_actual_days' => $actualDurations->isNotEmpty() ? round($actualDurations->avg(), 1) : 0.0,
            'avg_variance_days' => $variances->isNotEmpty() ? round($variances->avg(), 1) : 0.0,
            'overran_count' => $variances->filter(fn ($v) => $v > 0)->count(),
        ];
    }

    /**
     * @param  array{q: string, branch_id: string, status: string, date_from: string, date_to: string}  $filters
     * @return array{filters: array, rows: LengthAwarePaginator, kpis: array, filterBranches: Collection<int, Location>}
     */
    public function installmentDurationPaginated(Request $request, int $perPage = 50): array
    {
        $filters = $this->installmentDurationFilters($request);

        $rows = $this->installmentDurationBaseQuery($filters)
            ->latest('id')
            ->paginate($perPage)
            ->withQueryString();

        return [
            'filters' => $filters,
            'rows' => $rows,
            'kpis' => $this->installmentDurationTotals($filters),
            'filterBranches' => $this->salesSummaryFilterBranches(),
        ];
    }

    /**
     * @return array{rows: Collection<int, InstallmentPlan>, filterSummary: string, kpis: array}
     */
    public function installmentDurationForExport(Request $request): array
    {
        $filters = $this->installmentDurationFilters($request);

        $rows = $this->installmentDurationBaseQuery($filters)
            ->latest('id')
            ->limit(self::STOCK_EXPORT_LIMIT)
            ->get();

        return [
            'rows' => $rows,
            'filterSummary' => $this->installmentDurationFilterSummary($filters),
            'kpis' => $this->installmentDurationTotals($filters),
        ];
    }

    /** @var list<string> */
    public const CREDIT_OVERDUE_SECTIONS = ['all', 'credit', 'overdue', 'matured'];

    /**
     * @return array{q: string, branch_id: string, section: string}
     */
    public function creditOverdueFilters(Request $request): array
    {
        return [
            'q' => trim((string) $request->input('q', '')),
            'branch_id' => (string) $request->input('branch_id', ''),
            'section' => $this->normalizeCreditOverdueSection((string) $request->input('section', 'all')),
        ];
    }

    public function normalizeCreditOverdueSection(string $section): string
    {
        $section = strtolower(trim($section));
        if (in_array($section, ['mature', 'matured_plans'], true)) {
            $section = 'matured';
        }
        if (in_array($section, ['installment', 'installments', 'inst'], true)) {
            $section = 'overdue';
        }

        if (! in_array($section, self::CREDIT_OVERDUE_SECTIONS, true)) {
            return 'all';
        }

        return $section;
    }

    /**
     * @param  array{q: string, branch_id: string, section?: string}  $filters
     */
    public function creditOverdueShowsSection(array $filters, string $section): bool
    {
        $active = $this->normalizeCreditOverdueSection((string) ($filters['section'] ?? 'all'));

        return $active === 'all' || $active === $section;
    }

    public function creditOverdueSectionLabel(string $section): string
    {
        return match ($this->normalizeCreditOverdueSection($section)) {
            'credit' => 'Past-due credit only',
            'overdue' => 'Overdue installments only',
            'matured' => 'Matured plans only',
            default => 'All sections',
        };
    }

    public function creditOverdueBaseQuery(array $filters): Builder
    {
        return SalesInvoice::query()
            ->with(['customer', 'branch'])
            ->where('sale_type', 'credit')
            ->whereIn('status', ['posted', 'active'])
            ->where('payment_status', '!=', 'paid')
            ->where('amount_due', '>', 0.009)
            ->whereNotNull('credit_due_date')
            ->whereDate('credit_due_date', '<', now()->toDateString())
            ->tap(fn ($q) => $this->applyReceivablesReportInvoiceFilters($q, $filters));
    }

    /**
     * @param  array{q: string, branch_id: string, section: string}  $filters
     */
    public function creditOverdueFilterSummary(array $filters): string
    {
        $parts = [$this->creditOverdueSectionLabel($filters['section'] ?? 'all')];
        if ($filters['q'] !== '') {
            $parts[] = 'Search: '.$filters['q'];
        }
        if ($filters['branch_id'] !== '') {
            $b = Location::query()->whereKey((int) $filters['branch_id'])->first();
            $parts[] = 'Branch: '.($b ? $b->name : $filters['branch_id']);
        }

        return implode(' · ', $parts);
    }

    /**
     * KPIs for overdue receivables — full filtered totals (not limited by pagination or export row caps).
     *
     * @param  array{q: string, branch_id: string, section?: string}  $filters
     * @return array{invoices: int, amount_due: float, installment_schedules: int, installment_remaining: float, matured_invoices: int, matured_amount_due: float}
     */
    public function creditOverdueKpisForFilters(array $filters): array
    {
        $kpis = [
            'invoices' => 0,
            'amount_due' => 0.0,
            'installment_schedules' => 0,
            'installment_remaining' => 0.0,
            'matured_invoices' => 0,
            'matured_amount_due' => 0.0,
        ];

        if ($this->creditOverdueShowsSection($filters, 'credit')) {
            $baseQuery = $this->creditOverdueBaseQuery($filters);
            $kpis['invoices'] = (clone $baseQuery)->count();
            $kpis['amount_due'] = (float) (clone $baseQuery)->sum('amount_due');
        }

        if ($this->creditOverdueShowsSection($filters, 'overdue')) {
            $instBase = $this->installmentOverdueBaseQuery($filters);
            $installmentRemainingAgg = (clone $instBase)->toBase()
                ->selectRaw('SUM(CASE WHEN (due_amount + COALESCE(late_fee_amount, 0) - COALESCE(paid_amount, 0)) > 0 THEN due_amount + COALESCE(late_fee_amount, 0) - COALESCE(paid_amount, 0) ELSE 0 END) as remaining_sum')
                ->value('remaining_sum');
            $kpis['installment_schedules'] = (clone $instBase)->count();
            $kpis['installment_remaining'] = round((float) ($installmentRemainingAgg ?? 0), 2);
        }

        if ($this->creditOverdueShowsSection($filters, 'matured')) {
            $maturedBase = $this->creditOverdueMaturedInstallmentsBase($filters);
            $kpis['matured_invoices'] = (clone $maturedBase)->count();
            $kpis['matured_amount_due'] = (float) (clone $maturedBase)->sum('amount_due');
        }

        return $kpis;
    }

    /**
     * @return array{filters: array, rows: LengthAwarePaginator<SalesInvoice>, installmentRows: LengthAwarePaginator<InstallmentSchedule>, maturedRows: LengthAwarePaginator<SalesInvoice>, kpis: array, filterBranches: Collection}
     */
    public function creditOverduePaginated(Request $request, int $perPage = 50): array
    {
        $filters = $this->creditOverdueFilters($request);

        $rows = $this->creditOverdueShowsSection($filters, 'credit')
            ? $this->orderCreditOverdueInvoicesByBranch(
                (clone $this->creditOverdueBaseQuery($filters))
            )->paginate($perPage)->withQueryString()
            : $this->emptyCreditOverduePaginator($perPage, 'page');

        $installmentRows = $this->creditOverdueShowsSection($filters, 'overdue')
            ? $this->orderInstallmentSchedulesByBranchAndDueDate(
                (clone $this->installmentOverdueBaseQuery($filters))
            )->paginate($perPage, ['*'], 'inst_page')->withQueryString()
            : $this->emptyCreditOverduePaginator($perPage, 'inst_page');

        $maturedRows = $this->creditOverdueShowsSection($filters, 'matured')
            ? $this->orderCreditOverdueInvoicesByBranch(
                (clone $this->creditOverdueMaturedInstallmentsBase($filters))
            )->paginate($perPage, ['*'], 'matured_page')->withQueryString()
            : $this->emptyCreditOverduePaginator($perPage, 'matured_page');

        $kpis = $this->creditOverdueKpisForFilters($filters);

        return [
            'filters' => $filters,
            'rows' => $rows,
            'installmentRows' => $installmentRows,
            'maturedRows' => $maturedRows,
            'kpis' => $kpis,
            'filterBranches' => $this->salesSummaryFilterBranches(),
        ];
    }

    /**
     * @return array{creditRows: Collection<int, SalesInvoice>, installmentRows: Collection<int, InstallmentSchedule>, maturedRows: Collection<int, SalesInvoice>, filterSummary: string}
     */
    public function creditOverdueForExport(Request $request): array
    {
        $filters = $this->creditOverdueFilters($request);

        $creditRows = $this->creditOverdueShowsSection($filters, 'credit')
            ? $this->orderCreditOverdueInvoicesByBranch(
                $this->creditOverdueBaseQuery($filters)
            )->limit(self::STOCK_EXPORT_LIMIT)->get()
            : collect();

        $installmentRows = $this->creditOverdueShowsSection($filters, 'overdue')
            ? $this->orderInstallmentSchedulesByBranchAndDueDate(
                $this->installmentOverdueBaseQuery($filters)
            )->limit(self::STOCK_EXPORT_LIMIT)->get()
            : collect();

        $maturedRows = $this->creditOverdueShowsSection($filters, 'matured')
            ? $this->orderCreditOverdueInvoicesByBranch(
                $this->creditOverdueMaturedInstallmentsBase($filters)
            )->limit(self::STOCK_EXPORT_LIMIT)->get()
            : collect();

        return [
            'creditRows' => $creditRows,
            'installmentRows' => $installmentRows,
            'maturedRows' => $maturedRows,
            'filterSummary' => $this->creditOverdueFilterSummary($filters),
        ];
    }

    private function emptyCreditOverduePaginator(int $perPage, string $pageName): LengthAwarePaginator
    {
        $page = max(1, (int) request()->input($pageName, 1));

        return new Paginator(
            [],
            0,
            $perPage,
            $page,
            ['path' => request()->url(), 'pageName' => $pageName, 'query' => request()->query()],
        );
    }

    /**
     * @return array{q: string, branch_id: string, date_from: string, date_to: string}
     */
    public function monthlyCollectionFilters(Request $request): array
    {
        return [
            'q' => trim((string) $request->input('q', '')),
            'branch_id' => (string) $request->input('branch_id', ''),
            'date_from' => (string) $request->input('date_from', ''),
            'date_to' => (string) $request->input('date_to', ''),
            'collector_id' => $this->normalizeCollectorFilter($request->input('collector_id')),
        ];
    }

    /**
     * Narrow an invoice query to invoices that received a period payment from the chosen collector.
     *
     * @param  Builder<SalesInvoice>  $invoices
     * @return Builder<SalesInvoice>
     */
    private function applyMonthlyCollectionCollectorFilter(Builder $invoices, array $filters): Builder
    {
        $collectorId = (string) ($filters['collector_id'] ?? '');
        if ($collectorId === '') {
            return $invoices;
        }

        [$rangeStart, $rangeEnd] = $this->monthlyCollectionDateBounds($filters);
        $sub = DB::table('payment_allocations')
            ->join('payments', 'payments.id', '=', 'payment_allocations.payment_id')
            ->where('payments.direction', 'inbound')
            ->whereDate('payments.payment_date', '>=', $rangeStart)
            ->whereDate('payments.payment_date', '<=', $rangeEnd)
            ->select('payment_allocations.sales_invoice_id');
        $this->applyCollectorCondition($sub, $collectorId);

        return $invoices->whereIn('sales_invoices.id', $sub);
    }

    /**
     * Collector names per invoice for payments in the report period.
     *
     * @param  Collection<int, int>|array<int, int>  $invoiceIds
     * @return Collection<int, string>
     */
    public function monthlyCollectionPeriodCollectorsByInvoiceIds(array $filters, Collection|array $invoiceIds): Collection
    {
        $ids = collect($invoiceIds)->map(fn ($id) => (int) $id)->filter(fn (int $id) => $id > 0)->unique()->values();
        if ($ids->isEmpty()) {
            return collect();
        }

        [$rangeStart, $rangeEnd] = $this->monthlyCollectionDateBounds($filters);
        $q = DB::table('payment_allocations')
            ->join('payments', 'payments.id', '=', 'payment_allocations.payment_id')
            ->leftJoin('users', 'users.id', '=', 'payments.recorded_by')
            ->whereIn('payment_allocations.sales_invoice_id', $ids->all())
            ->where('payments.direction', 'inbound')
            ->whereDate('payments.payment_date', '>=', $rangeStart)
            ->whereDate('payments.payment_date', '<=', $rangeEnd)
            ->select(['payment_allocations.sales_invoice_id as invoice_id', DB::raw("COALESCE(NULLIF(users.full_name, ''), users.name) as collector_name")])
            ->distinct();
        $this->applyCollectorCondition($q, (string) ($filters['collector_id'] ?? ''));

        return $q->get()
            ->groupBy('invoice_id')
            ->map(fn (Collection $g): string => $g->map(fn ($r) => $r->collector_name ?: 'Unknown')->unique()->sort()->implode(', '));
    }

    /**
     * Per-collector payment count and amount over the given invoices (sums to the period total).
     *
     * @param  Collection<int, int>  $invoiceIds
     * @return list<array{collector_id: int|null, collector: string, payment_count: int, amount: float}>
     */
    public function monthlyCollectionCollectorTotals(array $filters, Collection $invoiceIds): array
    {
        if ($invoiceIds->isEmpty()) {
            return [];
        }

        [$rangeStart, $rangeEnd] = $this->monthlyCollectionDateBounds($filters);
        $q = DB::table('payment_allocations')
            ->join('payments', 'payments.id', '=', 'payment_allocations.payment_id')
            ->leftJoin('users', 'users.id', '=', 'payments.recorded_by')
            ->whereIn('payment_allocations.sales_invoice_id', $invoiceIds->all())
            ->where('payments.direction', 'inbound')
            ->whereDate('payments.payment_date', '>=', $rangeStart)
            ->whereDate('payments.payment_date', '<=', $rangeEnd);
        $this->applyCollectorCondition($q, (string) ($filters['collector_id'] ?? ''));

        return $q->groupBy('payments.recorded_by')
            ->selectRaw("payments.recorded_by as collector_id, MAX(COALESCE(NULLIF(users.full_name, ''), users.name)) as collector_name, COUNT(DISTINCT payments.id) as payment_count, COALESCE(SUM(payment_allocations.allocated_amount), 0) as amount")
            ->orderByDesc('amount')
            ->get()
            ->map(fn ($r): array => [
                'collector_id' => $r->collector_id !== null ? (int) $r->collector_id : null,
                'collector' => $r->collector_name ?: 'Unknown',
                'payment_count' => (int) $r->payment_count,
                'amount' => round((float) $r->amount, 2),
            ])->all();
    }

    /**
     * @param  array{q: string, branch_id: string, date_from: string, date_to: string}  $filters
     * @return array{0: string, 1: string}
     */
    private function monthlyCollectionDateBounds(array $filters): array
    {
        $dateFrom = trim((string) ($filters['date_from'] ?? ''));
        $dateTo = trim((string) ($filters['date_to'] ?? ''));

        if ($dateFrom === '' && $dateTo === '') {
            return [now()->startOfMonth()->toDateString(), now()->endOfMonth()->toDateString()];
        }

        if ($dateFrom === '') {
            $dateFrom = $dateTo;
        }
        if ($dateTo === '') {
            $dateTo = $dateFrom;
        }

        if ($dateFrom > $dateTo) {
            [$dateFrom, $dateTo] = [$dateTo, $dateFrom];
        }

        return [$dateFrom, $dateTo];
    }

    /**
     * Cash collected on invoices during the monthly collection filter date range.
     *
     * @param  array{q: string, branch_id: string, date_from: string, date_to: string}  $filters
     * @param  Collection<int, int>|array<int, int>  $invoiceIds
     * @return Collection<int, float>
     */
    public function monthlyCollectionPeriodPaymentsByInvoiceIds(array $filters, Collection|array $invoiceIds): Collection
    {
        $ids = collect($invoiceIds)
            ->map(fn ($id) => (int) $id)
            ->filter(fn (int $id) => $id > 0)
            ->unique()
            ->values();

        if ($ids->isEmpty()) {
            return collect();
        }

        [$rangeStart, $rangeEnd] = $this->monthlyCollectionDateBounds($filters);

        return DB::table('payment_allocations')
            ->join('payments', 'payments.id', '=', 'payment_allocations.payment_id')
            ->whereIn('payment_allocations.sales_invoice_id', $ids->all())
            ->where('payments.direction', 'inbound')
            ->whereDate('payments.payment_date', '>=', $rangeStart)
            ->whereDate('payments.payment_date', '<=', $rangeEnd)
            ->tap(fn ($q) => $this->applyCollectorCondition($q, (string) ($filters['collector_id'] ?? '')))
            ->groupBy('payment_allocations.sales_invoice_id')
            ->selectRaw('payment_allocations.sales_invoice_id as invoice_id')
            ->selectRaw('COALESCE(SUM(payment_allocations.allocated_amount), 0) as period_payment')
            ->pluck('period_payment', 'invoice_id')
            ->map(fn ($amount) => round((float) $amount, 2));
    }

    /**
     * @param  iterable<int, LengthAwarePaginator|Collection<int, mixed>>  $rowSets
     * @return Collection<int, int>
     */
    private function monthlyCollectionInvoiceIdsFromRowSets(iterable $rowSets): Collection
    {
        $ids = collect();

        foreach ($rowSets as $rows) {
            $items = $rows instanceof LengthAwarePaginator ? $rows->getCollection() : collect($rows);

            foreach ($items as $row) {
                if ($row instanceof SalesInvoice) {
                    $ids->push((int) $row->id);

                    continue;
                }

                if (is_object($row) && property_exists($row, 'invoice')) {
                    $ids->push((int) ($row->invoice?->id ?? 0));
                }
            }
        }

        return $ids->filter(fn (int $id) => $id > 0)->unique()->values();
    }

    /**
     * @param  array{q: string, branch_id: string, date_from: string, date_to: string}  $filters
     * @param  iterable<int, LengthAwarePaginator|Collection<int, mixed>>  $rowSets
     * @return Collection<int, float>
     */
    private function monthlyCollectionPeriodPaymentsForRowSets(array $filters, iterable $rowSets): Collection
    {
        return $this->monthlyCollectionPeriodPaymentsByInvoiceIds(
            $filters,
            $this->monthlyCollectionInvoiceIdsFromRowSets($rowSets),
        );
    }

    /**
     * @return array{filters: array, thisMonthInstallments: LengthAwarePaginator<InstallmentSchedule>, thisMonthCredits: LengthAwarePaginator<SalesInvoice>, overdueInstallments: LengthAwarePaginator<InstallmentSchedule>, filterBranches: Collection}
     */
    /**
     * Compact monthly collection rows for the dashboard (respects branch context).
     *
     * @return array{
     *     filters: array{q: string, branch_id: string},
     *     thisMonthInstallments: Collection<int, InstallmentSchedule>,
     *     thisMonthCredits: Collection<int, SalesInvoice>,
     *     overdueInstallments: Collection<int, InstallmentSchedule>
     * }
     */
    /**
     * Full-filter summary for monthly collection (screen + print). Not limited to the current page.
     *
     * @return array{
     *     installment_due: float,
     *     installment_paid: float,
     *     installment_remaining: float,
     *     installment_invoices: int,
     *     installment_customers: int,
     *     credit_grand: float,
     *     credit_paid: float,
     *     credit_due: float,
     *     credit_invoices: int,
     *     credit_customers: int,
     *     overdue_installment_due: float,
     *     overdue_installment_paid: float,
     *     overdue_installment_remaining: float,
     *     overdue_installment_invoices: int,
     *     overdue_installment_customers: int,
     *     overdue_credit_grand: float,
     *     overdue_credit_paid: float,
     *     overdue_credit_due: float,
     *     overdue_credit_invoices: int,
     *     overdue_credit_customers: int,
     *     total_due: float,
     *     total_paid: float,
     *     period_paid: float,
     *     total_customers: int,
     *     total_invoices: int
     * }
     */
    public function monthlyCollectionTotals(Request $request): array
    {
        $filters = $this->monthlyCollectionFilters($request);

        $thisMonthInst = $this->monthlyCollectionInstallmentAggregate($filters, 'this_month');
        $overdueInst = $this->monthlyCollectionInstallmentAggregate($filters, 'overdue');
        $thisMonthCredit = $this->monthlyCollectionCreditAggregate($filters, 'this_month');
        $overdueCredit = $this->monthlyCollectionCreditAggregate($filters, 'overdue');
        $maturedOverdue = $this->monthlyCollectionMaturedOverdueAggregate($filters);

        $installmentRemaining = $thisMonthInst['remaining'];
        $creditDue = $thisMonthCredit['due'];
        $overdueInstallmentRemaining = $overdueInst['remaining'];
        $overdueCreditDue = $overdueCredit['due'];
        $maturedOverdueDue = $maturedOverdue['due'];

        $invoiceIds = $thisMonthInst['invoice_ids']
            ->merge($overdueInst['invoice_ids'])
            ->merge($thisMonthCredit['invoice_ids'])
            ->merge($overdueCredit['invoice_ids'])
            ->merge($maturedOverdue['invoice_ids'])
            ->unique()
            ->values();

        $periodPaid = round((float) $this->monthlyCollectionPeriodPaymentsByInvoiceIds($filters, $invoiceIds)->sum(), 2);

        $customerIds = $thisMonthInst['customer_ids']
            ->merge($overdueInst['customer_ids'])
            ->merge($thisMonthCredit['customer_ids'])
            ->merge($overdueCredit['customer_ids'])
            ->merge($maturedOverdue['customer_ids'])
            ->unique()
            ->values();

        $totalPaid = round(
            $thisMonthInst['paid'] + $thisMonthCredit['paid'] + $overdueInst['paid'] + $overdueCredit['paid'] + $maturedOverdue['paid'],
            2,
        );

        return [
            'installment_due' => $thisMonthInst['due'],
            'installment_paid' => $thisMonthInst['paid'],
            'installment_remaining' => $installmentRemaining,
            'installment_invoices' => $thisMonthInst['invoices'],
            'installment_customers' => $thisMonthInst['customers'],
            'credit_grand' => $thisMonthCredit['grand'],
            'credit_paid' => $thisMonthCredit['paid'],
            'credit_due' => $creditDue,
            'credit_invoices' => $thisMonthCredit['invoices'],
            'credit_customers' => $thisMonthCredit['customers'],
            'overdue_installment_due' => $overdueInst['due'],
            'overdue_installment_paid' => $overdueInst['paid'],
            'overdue_installment_remaining' => $overdueInstallmentRemaining,
            'overdue_installment_invoices' => $overdueInst['invoices'],
            'overdue_installment_customers' => $overdueInst['customers'],
            'overdue_credit_grand' => $overdueCredit['grand'],
            'overdue_credit_paid' => $overdueCredit['paid'],
            'overdue_credit_due' => $overdueCreditDue,
            'overdue_credit_invoices' => $overdueCredit['invoices'],
            'overdue_credit_customers' => $overdueCredit['customers'],
            'matured_overdue_grand' => $maturedOverdue['grand'],
            'matured_overdue_paid' => $maturedOverdue['paid'],
            'matured_overdue_due' => $maturedOverdueDue,
            'matured_overdue_invoices' => $maturedOverdue['invoices'],
            'matured_overdue_customers' => $maturedOverdue['customers'],
            'total_due' => round($installmentRemaining + $creditDue + $overdueInstallmentRemaining + $overdueCreditDue + $maturedOverdueDue, 2),
            'total_paid' => $totalPaid,
            'period_paid' => $periodPaid,
            'collector_totals' => $this->monthlyCollectionCollectorTotals($filters, $invoiceIds),
            'total_customers' => $customerIds->count(),
            'total_invoices' => $invoiceIds->count(),
        ];
    }

    /**
     * @param  array{q: string, branch_id: string, date_from: string, date_to: string}  $filters
     * @param  'this_month'|'overdue'  $window
     * @return array{
     *     due: float,
     *     paid: float,
     *     remaining: float,
     *     invoices: int,
     *     customers: int,
     *     invoice_ids: Collection<int, int>,
     *     customer_ids: Collection<int, int>
     * }
     */
    private function monthlyCollectionInstallmentAggregate(array $filters, string $window): array
    {
        [$rangeStart, $rangeEnd] = $this->monthlyCollectionDateBounds($filters);

        $base = InstallmentSchedule::query()
            ->when($window === 'this_month', fn (Builder $q): Builder => $q->whereBetween('installment_schedules.due_date', [$rangeStart, $rangeEnd]))
            ->when($window === 'overdue', fn (Builder $q): Builder => $q->where('installment_schedules.due_date', '<', $rangeStart))
            ->whereIn('installment_schedules.status', ['pending', 'partially_paid', 'overdue'])
            ->whereHas('installmentPlan.salesInvoice', function ($q) use ($filters): void {
                $this->applyReceivablesReportInvoiceFilters($q, $filters);
                $this->applyMonthlyCollectionCollectorFilter($q, $filters);
            });

        $joined = (clone $base)
            ->join('installment_plans as mc_agg_plans', 'mc_agg_plans.id', '=', 'installment_schedules.installment_plan_id')
            ->join('sales_invoices as mc_agg_invoices', 'mc_agg_invoices.id', '=', 'mc_agg_plans.sales_invoice_id');

        $agg = (clone $joined)
            ->selectRaw('
                COALESCE(SUM(installment_schedules.due_amount + COALESCE(installment_schedules.late_fee_amount, 0)), 0) as due_total,
                COALESCE(SUM(installment_schedules.paid_amount), 0) as paid_total,
                COALESCE(SUM(CASE
                    WHEN (installment_schedules.due_amount + COALESCE(installment_schedules.late_fee_amount, 0) - installment_schedules.paid_amount) > 0
                    THEN (installment_schedules.due_amount + COALESCE(installment_schedules.late_fee_amount, 0) - installment_schedules.paid_amount)
                    ELSE 0
                END), 0) as remaining_total,
                COUNT(DISTINCT mc_agg_plans.sales_invoice_id) as invoice_count,
                COUNT(DISTINCT mc_agg_invoices.customer_id) as customer_count
            ')
            ->first();

        $invoiceIds = (clone $joined)
            ->distinct()
            ->orderBy('mc_agg_plans.sales_invoice_id')
            ->pluck('mc_agg_plans.sales_invoice_id')
            ->map(fn ($id): int => (int) $id)
            ->filter(fn (int $id): bool => $id > 0)
            ->values();

        $customerIds = (clone $joined)
            ->whereNotNull('mc_agg_invoices.customer_id')
            ->distinct()
            ->orderBy('mc_agg_invoices.customer_id')
            ->pluck('mc_agg_invoices.customer_id')
            ->map(fn ($id): int => (int) $id)
            ->filter(fn (int $id): bool => $id > 0)
            ->values();

        return [
            'due' => round((float) ($agg->due_total ?? 0), 2),
            'paid' => round((float) ($agg->paid_total ?? 0), 2),
            'remaining' => round((float) ($agg->remaining_total ?? 0), 2),
            'invoices' => (int) ($agg->invoice_count ?? 0),
            'customers' => (int) ($agg->customer_count ?? 0),
            'invoice_ids' => $invoiceIds,
            'customer_ids' => $customerIds,
        ];
    }

    /**
     * @param  array{q: string, branch_id: string, date_from: string, date_to: string}  $filters
     * @param  'this_month'|'overdue'  $window
     * @return array{
     *     grand: float,
     *     paid: float,
     *     due: float,
     *     invoices: int,
     *     customers: int,
     *     invoice_ids: Collection<int, int>,
     *     customer_ids: Collection<int, int>
     * }
     */
    private function monthlyCollectionCreditAggregate(array $filters, string $window): array
    {
        $base = $window === 'this_month'
            ? $this->monthlyCollectionThisMonthCreditsBase($filters)
            : $this->monthlyCollectionOverdueCreditsBase($filters);

        return $this->monthlyCollectionInvoiceAggregate($base);
    }

    /**
     * @param  array{q: string, branch_id: string, date_from: string, date_to: string}  $filters
     * @return array{
     *     grand: float,
     *     paid: float,
     *     due: float,
     *     invoices: int,
     *     customers: int,
     *     invoice_ids: Collection<int, int>,
     *     customer_ids: Collection<int, int>
     * }
     */
    private function monthlyCollectionMaturedOverdueAggregate(array $filters): array
    {
        return $this->monthlyCollectionInvoiceAggregate($this->monthlyCollectionMaturedOverdueInstallmentsBase($filters));
    }

    /**
     * @param  Builder<SalesInvoice>  $base
     * @return array{
     *     grand: float,
     *     paid: float,
     *     due: float,
     *     invoices: int,
     *     customers: int,
     *     invoice_ids: Collection<int, int>,
     *     customer_ids: Collection<int, int>
     * }
     */
    private function monthlyCollectionInvoiceAggregate(Builder $base): array
    {
        $agg = (clone $base)
            ->toBase()
            ->selectRaw('
                COALESCE(SUM(grand_total), 0) as grand_total,
                COALESCE(SUM(amount_paid), 0) as paid_total,
                COALESCE(SUM(amount_due), 0) as due_total,
                COUNT(*) as invoice_count,
                COUNT(DISTINCT customer_id) as customer_count
            ')
            ->first();

        $invoiceIds = (clone $base)
            ->orderBy('id')
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->filter(fn (int $id): bool => $id > 0)
            ->values();

        $customerIds = (clone $base)
            ->whereNotNull('customer_id')
            ->distinct()
            ->orderBy('customer_id')
            ->pluck('customer_id')
            ->map(fn ($id): int => (int) $id)
            ->filter(fn (int $id): bool => $id > 0)
            ->values();

        return [
            'grand' => round((float) ($agg->grand_total ?? 0), 2),
            'paid' => round((float) ($agg->paid_total ?? 0), 2),
            'due' => round((float) ($agg->due_total ?? 0), 2),
            'invoices' => (int) ($agg->invoice_count ?? 0),
            'customers' => (int) ($agg->customer_count ?? 0),
            'invoice_ids' => $invoiceIds,
            'customer_ids' => $customerIds,
        ];
    }

    /**
     * @return list<string>
     */
    private function monthlyCollectionInstallmentEagerLoads(): array
    {
        return [
            'installmentPlan.salesInvoice.customer.defaultGuarantor',
            'installmentPlan.salesInvoice.guarantor',
            'installmentPlan.salesInvoice.branch',
            'installmentPlan.salesInvoice.lines.product',
        ];
    }

    /**
     * @return list<string>
     */
    private function monthlyCollectionCreditEagerLoads(): array
    {
        return [
            'customer.defaultGuarantor',
            'guarantor',
            'branch',
            'lines.product',
        ];
    }

    /**
     * @return list<string>
     */
    private function monthlyCollectionMaturedOverdueEagerLoads(): array
    {
        return array_merge($this->monthlyCollectionCreditEagerLoads(), ['installmentPlan.schedules']);
    }

    public function monthlyCollectionForDashboard(Request $request, int $limit = 10): array
    {
        $filters = $this->monthlyCollectionFilters($request);
        $totals = $this->monthlyCollectionTotals($request);

        $thisMonthInstallments = $this->monthlyCollectionInstallmentQuery($filters, 'this_month', $this->monthlyCollectionInstallmentEagerLoads())->limit($limit)->get();

        $thisMonthCredits = $this->orderMonthlyCollectionCreditsByDueDate(
            $this->monthlyCollectionThisMonthCreditsBase($filters)
                ->with($this->monthlyCollectionCreditEagerLoads()),
        )->limit($limit)->get();

        $overdueInstallments = $this->monthlyCollectionInstallmentQuery($filters, 'overdue', $this->monthlyCollectionInstallmentEagerLoads())->limit($limit)->get();

        $overdueCredits = $this->orderMonthlyCollectionCreditsByDueDate(
            $this->monthlyCollectionOverdueCreditsBase($filters)
                ->with($this->monthlyCollectionCreditEagerLoads()),
        )->limit($limit)->get();

        return [
            'filters' => $filters,
            'totals' => $totals,
            'thisMonthInstallments' => $this->groupMonthlyCollectionInstallmentSchedules($thisMonthInstallments),
            'thisMonthCredits' => $thisMonthCredits,
            'overdueInstallments' => $this->groupMonthlyCollectionInstallmentSchedules($overdueInstallments),
            'overdueCredits' => $overdueCredits,
            'periodPaymentsByInvoiceId' => $this->monthlyCollectionPeriodPaymentsForRowSets($filters, [
                $this->groupMonthlyCollectionInstallmentSchedules($thisMonthInstallments),
                $thisMonthCredits,
                $this->groupMonthlyCollectionInstallmentSchedules($overdueInstallments),
                $overdueCredits,
            ]),
        ];
    }

    public function monthlyCollectionPaginated(Request $request, int $perPage = 50): array
    {
        $filters = $this->monthlyCollectionFilters($request);

        $thisMonthInstallments = $this->paginateGroupedInstallmentRows(
            $this->groupMonthlyCollectionInstallmentSchedules(
                $this->monthlyCollectionInstallmentQuery($filters, 'this_month', $this->monthlyCollectionInstallmentEagerLoads())->get(),
            ),
            $perPage,
            'this_month_inst_page',
        );

        $thisMonthCredits = $this->orderMonthlyCollectionCreditsByDueDate(
            $this->monthlyCollectionThisMonthCreditsBase($filters)
                ->with($this->monthlyCollectionCreditEagerLoads()),
        )->paginate($perPage, ['*'], 'this_month_credit_page')->withQueryString();

        $overdueInstallments = $this->paginateGroupedInstallmentRows(
            $this->groupMonthlyCollectionInstallmentSchedules(
                $this->monthlyCollectionInstallmentQuery($filters, 'overdue', $this->monthlyCollectionInstallmentEagerLoads())->get(),
            ),
            $perPage,
            'overdue_inst_page',
        );

        $overdueCredits = $this->orderMonthlyCollectionCreditsByDueDate(
            $this->monthlyCollectionOverdueCreditsBase($filters)
                ->with($this->monthlyCollectionCreditEagerLoads()),
        )->paginate($perPage, ['*'], 'overdue_credit_page')->withQueryString();

        $maturedOverdueInstallments = $this->orderMonthlyCollectionCreditsByDueDate(
            $this->monthlyCollectionMaturedOverdueInstallmentsBase($filters)
                ->with($this->monthlyCollectionMaturedOverdueEagerLoads()),
        )->paginate($perPage, ['*'], 'matured_overdue_page')->withQueryString();

        return [
            'filters' => $filters,
            'thisMonthInstallments' => $thisMonthInstallments,
            'thisMonthCredits' => $thisMonthCredits,
            'overdueInstallments' => $overdueInstallments,
            'overdueCredits' => $overdueCredits,
            'maturedOverdueInstallments' => $maturedOverdueInstallments,
            'filterBranches' => $this->salesSummaryFilterBranches(),
            'collectors' => $this->paymentCollectorOptions(),
            'periodPaymentsByInvoiceId' => $this->monthlyCollectionPeriodPaymentsForRowSets($filters, [
                $thisMonthInstallments,
                $thisMonthCredits,
                $overdueInstallments,
                $overdueCredits,
                $maturedOverdueInstallments,
            ]),
            'collectorsByInvoiceId' => $this->monthlyCollectionPeriodCollectorsByInvoiceIds($filters, $this->monthlyCollectionInvoiceIdsFromRowSets([
                $thisMonthInstallments,
                $thisMonthCredits,
                $overdueInstallments,
                $overdueCredits,
                $maturedOverdueInstallments,
            ])),
        ];
    }

    /**
     * @return array{filters: array, thisMonthInstallments: Collection<int, InstallmentSchedule>, thisMonthCredits: Collection<int, SalesInvoice>, overdueInstallments: Collection<int, InstallmentSchedule>, filterSummary: string}
     */
    public function monthlyCollectionForExport(Request $request): array
    {
        $filters = $this->monthlyCollectionFilters($request);

        $thisMonthInstallments = $this->monthlyCollectionInstallmentQuery($filters, 'this_month', $this->monthlyCollectionInstallmentEagerLoads())
            ->limit(self::STOCK_EXPORT_LIMIT)
            ->get();

        $thisMonthCredits = $this->orderMonthlyCollectionCreditsByDueDate(
            $this->monthlyCollectionThisMonthCreditsBase($filters)
                ->with($this->monthlyCollectionCreditEagerLoads()),
        )->limit(self::STOCK_EXPORT_LIMIT)->get();

        $overdueInstallments = $this->monthlyCollectionInstallmentQuery($filters, 'overdue', $this->monthlyCollectionInstallmentEagerLoads())
            ->limit(self::STOCK_EXPORT_LIMIT)
            ->get();

        $overdueCredits = $this->orderMonthlyCollectionCreditsByDueDate(
            $this->monthlyCollectionOverdueCreditsBase($filters)
                ->with($this->monthlyCollectionCreditEagerLoads()),
        )->limit(self::STOCK_EXPORT_LIMIT)->get();

        $maturedOverdueInstallments = $this->orderMonthlyCollectionCreditsByDueDate(
            $this->monthlyCollectionMaturedOverdueInstallmentsBase($filters)
                ->with($this->monthlyCollectionMaturedOverdueEagerLoads()),
        )->limit(self::STOCK_EXPORT_LIMIT)->get();

        $thisMonthInstallmentRows = $this->groupMonthlyCollectionInstallmentSchedules($thisMonthInstallments);
        $overdueInstallmentRows = $this->groupMonthlyCollectionInstallmentSchedules($overdueInstallments);

        return [
            'filters' => $filters,
            'thisMonthInstallments' => $thisMonthInstallmentRows,
            'thisMonthCredits' => $thisMonthCredits,
            'overdueInstallments' => $overdueInstallmentRows,
            'overdueCredits' => $overdueCredits,
            'maturedOverdueInstallments' => $maturedOverdueInstallments,
            'filterSummary' => $this->monthlyCollectionFilterSummary($filters),
            'periodPaymentsByInvoiceId' => $this->monthlyCollectionPeriodPaymentsForRowSets($filters, [
                $thisMonthInstallmentRows,
                $thisMonthCredits,
                $overdueInstallmentRows,
                $overdueCredits,
                $maturedOverdueInstallments,
            ]),
            'collectorsByInvoiceId' => $this->monthlyCollectionPeriodCollectorsByInvoiceIds($filters, $this->monthlyCollectionInvoiceIdsFromRowSets([
                $thisMonthInstallmentRows,
                $thisMonthCredits,
                $overdueInstallmentRows,
                $overdueCredits,
                $maturedOverdueInstallments,
            ])),
        ];
    }

    /**
     * @param  array{q: string, branch_id: string, date_from: string, date_to: string}  $filters
     */
    public function monthlyCollectionFilterSummary(array $filters): string
    {
        [$rangeStart, $rangeEnd] = $this->monthlyCollectionDateBounds($filters);
        $parts = ['Monthly Collection', 'Due '.$rangeStart.' to '.$rangeEnd];
        if ($filters['q'] !== '') {
            $parts[] = 'Search: '.$filters['q'];
        }
        if ($filters['branch_id'] !== '') {
            $b = Location::query()->whereKey((int) $filters['branch_id'])->first();
            $parts[] = 'Branch: '.($b ? $b->name : $filters['branch_id']);
        }
        if ($label = $this->collectorFilterLabel($filters['collector_id'] ?? '')) {
            $parts[] = $label;
        }

        return implode(' · ', $parts);
    }

    /**
     * One collection row per invoice (aggregates multiple due schedules on the same sale).
     *
     * @param  Collection<int, InstallmentSchedule>  $schedules
     * @return Collection<int, object{
     *     invoice: SalesInvoice|null,
     *     installment_nos: string,
     *     due_date: Carbon|null,
     *     due_amount: float,
     *     paid_amount: float,
     *     remaining: float,
     *     months_late: int
     * }>
     */
    public function groupMonthlyCollectionInstallmentSchedules(Collection $schedules): Collection
    {
        return $schedules
            ->groupBy(fn (InstallmentSchedule $schedule): int => (int) ($schedule->installmentPlan?->sales_invoice_id ?? 0))
            ->filter(fn (Collection $group, int $invoiceId): bool => $invoiceId > 0)
            ->map(function (Collection $group): object {
                $ordered = $group->sortBy('installment_no')->values();
                $firstDue = $ordered->sortBy('due_date')->first();
                $invoice = $firstDue?->installmentPlan?->salesInvoice;

                return (object) [
                    'invoice' => $invoice,
                    'invoice_date' => $invoice?->invoice_date,
                    'installment_nos' => $ordered->pluck('installment_no')->unique()->sort()->values()->implode(', '),
                    'due_date' => $firstDue?->due_date,
                    'due_amount' => round((float) $ordered->sum(fn (InstallmentSchedule $s): float => (float) $s->due_amount), 2),
                    'paid_amount' => round((float) $ordered->sum(fn (InstallmentSchedule $s): float => (float) $s->paid_amount), 2),
                    'remaining' => round((float) $ordered->sum(fn (InstallmentSchedule $s): float => $s->remainingDue()), 2),
                    'months_late' => $firstDue instanceof InstallmentSchedule ? $firstDue->monthsLate() : 0,
                ];
            })
            ->sortBy(fn (object $row): string => ($row->invoice_date?->toDateString() ?? '9999-12-31')
                .'|'.sprintf('%010d', (int) ($row->invoice?->id ?? 0)))
            ->values();
    }

    /**
     * @param  array{q: string, branch_id: string}  $filters
     * @param  'this_month'|'overdue'  $window
     * @param  list<string>  $with
     * @return Builder<InstallmentSchedule>
     */
    /**
     * @param  array{q: string, branch_id: string}  $filters
     * @return Builder<SalesInvoice>
     */
    private function monthlyCollectionCreditInvoiceBase(array $filters): Builder
    {
        return SalesInvoice::query()
            ->whereIn('sale_type', ['credit', 'default'])
            ->whereIn('status', ['posted', 'active'])
            ->where('payment_status', '!=', 'paid')
            ->where('amount_due', '>', 0.009)
            ->where(function (Builder $q): void {
                $q->where(function (Builder $credit): void {
                    $credit->where('sale_type', 'credit')->whereNotNull('credit_due_date');
                })->orWhere('sale_type', 'default');
            })
            ->tap(fn ($q) => $this->applyReceivablesReportInvoiceFilters($q, $filters))
            ->tap(fn ($q) => $this->applyMonthlyCollectionCollectorFilter($q, $filters));
    }

    /**
     * @param  array{q: string, branch_id: string, date_from: string, date_to: string}  $filters
     * @return Builder<SalesInvoice>
     */
    private function monthlyCollectionThisMonthCreditsBase(array $filters): Builder
    {
        [$rangeStart, $rangeEnd] = $this->monthlyCollectionDateBounds($filters);

        return $this->monthlyCollectionCreditInvoiceBase($filters)
            ->where(function (Builder $q) use ($rangeStart, $rangeEnd): void {
                $q->where(function (Builder $credit) use ($rangeStart, $rangeEnd): void {
                    $credit->where('sale_type', 'credit')
                        ->whereBetween('credit_due_date', [$rangeStart, $rangeEnd]);
                })->orWhere(function (Builder $default) use ($rangeStart, $rangeEnd): void {
                    $default->where('sale_type', 'default')
                        ->whereBetween('invoice_date', [$rangeStart, $rangeEnd]);
                });
            });
    }

    /**
     * @param  array{q: string, branch_id: string, date_from: string, date_to: string}  $filters
     * @return Builder<SalesInvoice>
     */
    private function monthlyCollectionOverdueCreditsBase(array $filters): Builder
    {
        [$rangeStart] = $this->monthlyCollectionDateBounds($filters);

        return $this->monthlyCollectionCreditInvoiceBase($filters)
            ->where(function (Builder $q) use ($rangeStart): void {
                $q->where(function (Builder $credit) use ($rangeStart): void {
                    $credit->where('sale_type', 'credit')
                        ->where('credit_due_date', '<', $rangeStart);
                })->orWhere(function (Builder $default) use ($rangeStart): void {
                    $default->where('sale_type', 'default')
                        ->where('invoice_date', '<', $rangeStart);
                });
            });
    }

    /**
     * Installment invoices whose FINAL scheduled installment due date has already
     * passed (the whole plan has matured) while the plan is still not fully paid.
     * Distinct from {@see self::monthlyCollectionInstallmentQuery()}'s 'overdue'
     * window, which lists individual overdue schedule rows regardless of whether
     * they are the plan's last one.
     *
     * @param  array{q: string, branch_id: string, date_from: string, date_to: string}  $filters
     * @return Builder<SalesInvoice>
     */
    private function monthlyCollectionMaturedOverdueInstallmentsBase(array $filters): Builder
    {
        [$rangeStart] = $this->monthlyCollectionDateBounds($filters);

        return $this->maturedUnpaidInstallmentInvoicesBase($filters, $rangeStart)
            ->tap(fn ($q) => $this->applyMonthlyCollectionCollectorFilter($q, $filters));
    }

    /**
     * @param  array{q: string, branch_id: string}  $filters
     * @return Builder<SalesInvoice>
     */
    private function maturedUnpaidInstallmentInvoicesBase(array $filters, string $allSchedulesDueBefore): Builder
    {
        return SalesInvoice::query()
            ->where('sale_type', 'installment')
            ->whereIn('status', ['posted', 'active'])
            ->where('payment_status', '!=', 'paid')
            ->where('amount_due', '>', 0.009)
            ->whereHas('installmentPlan', function (Builder $q) use ($allSchedulesDueBefore): void {
                $q->where('status', '!=', 'completed')
                    ->where('remaining_amount', '>', 0.009)
                    ->whereHas('schedules')
                    ->whereDoesntHave('schedules', function (Builder $sq) use ($allSchedulesDueBefore): void {
                        $sq->whereDate('due_date', '>=', $allSchedulesDueBefore);
                    });
            })
            ->tap(fn ($q) => $this->applyReceivablesReportInvoiceFilters($q, $filters));
    }

    /**
     * @param  Builder<SalesInvoice>  $query
     * @return Builder<SalesInvoice>
     */
    private function orderCreditOverdueInvoicesByBranch(Builder $query): Builder
    {
        return $query
            ->orderBy('branch_id')
            ->orderByRaw('COALESCE(credit_due_date, invoice_date)')
            ->latest('id');
    }

    /**
     * @param  Builder<InstallmentSchedule>  $query
     * @return Builder<InstallmentSchedule>
     */
    private function orderInstallmentSchedulesByBranchAndDueDate(Builder $query): Builder
    {
        return $query
            ->join('installment_plans as co_plans', 'co_plans.id', '=', 'installment_schedules.installment_plan_id')
            ->join('sales_invoices as co_invoices', 'co_invoices.id', '=', 'co_plans.sales_invoice_id')
            ->orderBy('co_invoices.branch_id')
            ->orderBy('installment_schedules.due_date')
            ->orderByDesc('installment_schedules.id')
            ->select('installment_schedules.*');
    }

    /**
     * @param  Builder<SalesInvoice>  $query
     * @return Builder<SalesInvoice>
     */
    private function orderMonthlyCollectionCreditsByDueDate(Builder $query): Builder
    {
        return $query
            ->orderByRaw('COALESCE(credit_due_date, invoice_date)')
            ->latest('id');
    }

    /**
     * @param  array{q: string, branch_id: string}  $filters
     * @param  'this_month'|'overdue'  $window
     * @param  list<string>  $with
     * @return Builder<InstallmentSchedule>
     */
    private function monthlyCollectionInstallmentQuery(array $filters, string $window, array $with = []): Builder
    {
        [$rangeStart, $rangeEnd] = $this->monthlyCollectionDateBounds($filters);

        $query = InstallmentSchedule::query()
            ->when($with !== [], fn (Builder $q): Builder => $q->with($with))
            ->when($window === 'this_month', fn (Builder $q): Builder => $q->whereBetween('installment_schedules.due_date', [$rangeStart, $rangeEnd]))
            ->when($window === 'overdue', fn (Builder $q): Builder => $q->where('installment_schedules.due_date', '<', $rangeStart))
            ->whereIn('installment_schedules.status', ['pending', 'partially_paid', 'overdue'])
            ->whereHas('installmentPlan.salesInvoice', function ($q) use ($filters): void {
                $this->applyReceivablesReportInvoiceFilters($q, $filters);
                $this->applyMonthlyCollectionCollectorFilter($q, $filters);
            });

        return $this->orderMonthlyCollectionInstallmentsBySalesDate($query);
    }

    /**
     * @param  Builder<InstallmentSchedule>  $query
     * @return Builder<InstallmentSchedule>
     */
    private function orderMonthlyCollectionInstallmentsBySalesDate(Builder $query): Builder
    {
        return $query
            ->join('installment_plans as mc_plans', 'mc_plans.id', '=', 'installment_schedules.installment_plan_id')
            ->join('sales_invoices as mc_invoices', 'mc_invoices.id', '=', 'mc_plans.sales_invoice_id')
            ->orderBy('mc_invoices.invoice_date')
            ->orderBy('mc_invoices.id')
            ->select('installment_schedules.*');
    }

    /**
     * @param  Collection<int, object>  $grouped
     */
    private function paginateGroupedInstallmentRows(Collection $grouped, int $perPage, string $pageName): Paginator
    {
        $page = max(1, (int) request()->input($pageName, 1));

        return new Paginator(
            $grouped->forPage($page, $perPage)->values(),
            $grouped->count(),
            $perPage,
            $page,
            ['path' => request()->url(), 'pageName' => $pageName, 'query' => request()->query()],
        );
    }

    /**
     * @return array{q: string, location_id: string}
     */
    public function lowStockFilters(Request $request): array
    {
        return array_merge([
            'q' => trim((string) $request->input('q', '')),
            'location_id' => (string) $request->input('location_id', ''),
        ], $this->stockAsOfFilterPart($request));
    }

    /**
     * Active products where available qty ≤ reorder level (reorder > 0).
     */
    public function lowStockQueryBuilder(array $filters): QueryBuilder
    {
        $filters = $this->normalizeStockReportFilters($filters);

        $query = $this->activeProductLedgerBalancesQuery($filters)
            ->where('products.reorder_level', '>', 0)
            ->whereColumn('inventory_balances.available_qty', '<=', 'products.reorder_level');

        return $query->select([
            'products.id as product_id',
            'products.sku as product_sku',
            'products.name as product_name',
            'products.reorder_level',
            'locations.id as location_id',
            'locations.code as location_code',
            'locations.name as location_name',
            'inventory_balances.on_hand_qty',
            'inventory_balances.reserved_qty',
            'inventory_balances.available_qty',
        ]);
    }

    /**
     * @return array{filters: array, rows: LengthAwarePaginator, kpis: array, filterLocations: Collection}
     */
    public function lowStockPaginated(Request $request, int $perPage = 50): array
    {
        $filters = $this->lowStockFilters($request);
        $base = $this->lowStockQueryBuilder($filters);
        $rows = (clone $base)
            ->orderBy('inventory_balances.available_qty')
            ->orderBy('products.sku')
            ->paginate($perPage)->withQueryString();

        return [
            'filters' => $filters,
            'rows' => $rows,
            'kpis' => [
                'positions' => $rows->total(),
            ],
            'filterLocations' => $this->stockByLocationFilterLocations(),
        ];
    }

    /**
     * @return array{rows: Collection<int, object>, truncated: bool, totalMatching: int, filterSummary: string}
     */
    public function lowStockForExport(Request $request): array
    {
        $filters = $this->lowStockFilters($request);
        $base = $this->lowStockQueryBuilder($filters);
        $total = (clone $base)->count();
        $truncated = $total > self::STOCK_EXPORT_LIMIT;
        $rows = (clone $base)
            ->orderBy('inventory_balances.available_qty')
            ->orderBy('products.sku')
            ->limit(self::STOCK_EXPORT_LIMIT)
            ->get();

        return [
            'rows' => $rows,
            'truncated' => $truncated,
            'totalMatching' => $total,
            'filterSummary' => $this->lowStockFilterSummary($filters),
        ];
    }

    /**
     * @param  array{q: string, location_id: string}  $filters
     */
    public function lowStockFilterSummary(array $filters): string
    {
        $parts = [];
        if ($filters['q'] !== '') {
            $parts[] = 'Search: '.$filters['q'];
        }
        if ($filters['location_id'] !== '') {
            $loc = Location::query()->whereKey((int) $filters['location_id'])->first();
            $parts[] = 'Location: '.($loc ? $loc->name : $filters['location_id']);
        }
        $this->appendStockAsOfDateFilterSummary($filters, $parts);

        return $parts !== [] ? implode(' · ', $parts) : 'Low stock (available ≤ reorder level)';
    }

    /**
     * @return array{q: string, branch_id: string}
     */
    public function customerDuesFilters(Request $request): array
    {
        return [
            'q' => trim((string) $request->input('q', '')),
            'branch_id' => (string) $request->input('branch_id', ''),
        ];
    }

    /**
     * When scoped to a branch, dues come from open invoices in that branch rather
     * than the customer's portfolio-wide {@see Customer::$outstanding_balance}.
     *
     * @param  array{q: string, branch_id: string}  $filters
     */
    protected function customerDuesUsesBranchScopedAmounts(array $filters): bool
    {
        if ($filters['branch_id'] !== '') {
            return true;
        }

        return $this->contextLocationId() !== null;
    }

    /**
     * @param  array{q: string, branch_id: string}  $filters
     */
    protected function customerDuesOpenInvoiceDueSubquery(array $filters): QueryBuilder
    {
        $q = DB::table('sales_invoices')
            ->leftJoin('locations', 'locations.id', '=', 'sales_invoices.branch_id')
            ->whereIn('sales_invoices.status', ['posted', 'active'])
            ->where('sales_invoices.amount_due', '>', 0.009);

        $this->applyInvoiceOutstandingReportBranchFilter($q, $filters);

        return $q
            ->select([
                'sales_invoices.customer_id',
                'sales_invoices.branch_id',
                'locations.name as branch_name',
                DB::raw('ROUND(SUM(sales_invoices.amount_due), 2) as branch_due'),
                DB::raw('GROUP_CONCAT(DISTINCT sales_invoices.invoice_number ORDER BY sales_invoices.invoice_number SEPARATOR \', \') as invoice_numbers'),
            ])
            ->groupBy('sales_invoices.customer_id', 'sales_invoices.branch_id', 'locations.name')
            ->havingRaw('ROUND(SUM(sales_invoices.amount_due), 2) > 0.009');
    }

    /**
     * @param  array{q: string, branch_id: string}  $filters
     */
    public function customerDuesFilterSummary(array $filters): string
    {
        $parts = ['Customers with outstanding balance'];
        if ($filters['q'] !== '') {
            $parts[] = 'Search: '.$filters['q'];
        }
        if ($filters['branch_id'] !== '') {
            $b = Location::query()->whereKey((int) $filters['branch_id'])->first();
            $parts[] = 'Branch: '.($b ? $b->name : $filters['branch_id']);
        } elseif ($this->contextLocationId() !== null) {
            $b = Location::query()->whereKey($this->contextLocationId())->first();
            $parts[] = 'Branch: '.($b ? $b->name : (string) $this->contextLocationId());
        }
        if ($this->customerDuesUsesBranchScopedAmounts($filters)) {
            $parts[] = 'Open invoice balances in scope';
        }

        return implode(' · ', $parts);
    }

    protected function customerDuesOutstandingSumColumn(array $filters): string
    {
        return $this->customerDuesUsesBranchScopedAmounts($filters)
            ? 'branch_dues.branch_due'
            : 'customers.outstanding_balance';
    }

    protected function applyCustomerDuesBranchAccess(Builder $query): Builder
    {
        $user = auth()->user();
        if (! $user || $user->isAdmin() || $user->hasPermission('location.view-all')) {
            return $query;
        }

        $ids = $user->accessibleLocationIds();
        if ($ids === []) {
            return $query->whereRaw('1 = 0');
        }

        return $query->whereExists(function ($sub) use ($ids): void {
            $sub->selectRaw('1')
                ->from('sales_invoices')
                ->whereColumn('sales_invoices.customer_id', 'customers.id')
                ->whereIn('sales_invoices.branch_id', $ids)
                ->whereIn('sales_invoices.status', ['posted', 'active'])
                ->where('sales_invoices.amount_due', '>', 0.009);
        });
    }

    protected function customerDuesBranchFilterAllowed(int $branchId): bool
    {
        if ($branchId <= 0) {
            return false;
        }

        if (! Location::query()->whereKey($branchId)->where('is_active', true)->exists()) {
            return false;
        }

        $user = auth()->user();
        if (! $user || $user->isAdmin() || $user->hasPermission('location.view-all')) {
            return true;
        }

        return in_array($branchId, $user->accessibleLocationIds(), true);
    }

    public function customerDuesBaseQuery(array $filters): Builder
    {
        $search = function (Builder $query) use ($filters): void {
            if ($filters['q'] === '') {
                return;
            }

            $keyword = $filters['q'];
            $query->where(function ($sub) use ($keyword): void {
                $sub->where('customers.name', 'like', "%{$keyword}%")
                    ->orWhere('customers.phone', 'like', "%{$keyword}%")
                    ->orWhere('customers.customer_code', 'like', "%{$keyword}%");
            });
        };

        if ($this->customerDuesUsesBranchScopedAmounts($filters)) {
            $dueSub = $this->customerDuesOpenInvoiceDueSubquery($filters);

            return Customer::query()
                ->joinSub($dueSub, 'branch_dues', 'branch_dues.customer_id', '=', 'customers.id')
                ->where('customers.is_active', true)
                ->select([
                    'customers.id',
                    'customers.customer_code',
                    'customers.name',
                    'customers.phone',
                    'customers.email',
                    'customers.credit_limit',
                    'customers.is_active',
                    'branch_dues.branch_id',
                    'branch_dues.branch_name',
                    DB::raw('branch_dues.branch_due as outstanding_balance'),
                    'branch_dues.invoice_numbers',
                ])
                ->tap($search);
        }

        return Customer::query()
            ->leftJoin('locations as customer_branch', 'customer_branch.id', '=', 'customers.branch_id')
            ->where('customers.is_active', true)
            ->where('customers.outstanding_balance', '>', 0.009)
            ->tap(fn ($q) => $this->applyCustomerDuesBranchAccess($q))
            ->when($filters['q'] !== '', function ($query) use ($filters): void {
                $keyword = $filters['q'];
                $query->where(function ($sub) use ($keyword): void {
                    $sub->where('customers.name', 'like', "%{$keyword}%")
                        ->orWhere('customers.phone', 'like', "%{$keyword}%")
                        ->orWhere('customers.customer_code', 'like', "%{$keyword}%");
                });
            })
            ->select([
                'customers.id',
                'customers.customer_code',
                'customers.name',
                'customers.phone',
                'customers.email',
                'customers.credit_limit',
                'customers.is_active',
                'customers.outstanding_balance',
                'customers.branch_id',
                'customer_branch.name as branch_name',
                DB::raw('(SELECT GROUP_CONCAT(DISTINCT si.invoice_number ORDER BY si.invoice_number SEPARATOR \', \') FROM sales_invoices si WHERE si.customer_id = customers.id AND si.status IN (\'posted\', \'active\')) as invoice_numbers'),
            ]);
    }

    /**
     * @return array{filters: array{q: string, branch_id: string}, rows: LengthAwarePaginator, kpis: array, filterBranches: Collection<int, Location>}
     */
    public function customerDuesPaginated(Request $request, int $perPage = 50): array
    {
        $filters = $this->customerDuesFilters($request);
        $baseQuery = $this->customerDuesBaseQuery($filters);
        $dueColumn = $this->customerDuesOutstandingSumColumn($filters);
        $rows = (clone $baseQuery)
            ->orderByDesc($dueColumn)
            ->orderBy('customers.customer_code')
            ->paginate($perPage)->withQueryString();

        $kpis = [
            'customers' => $rows->total(),
            'total_outstanding' => (float) (clone $baseQuery)->sum($dueColumn),
            'branch_scoped' => $this->customerDuesUsesBranchScopedAmounts($filters),
        ];

        return [
            'filters' => $filters,
            'rows' => $rows,
            'kpis' => $kpis,
            'filterBranches' => $this->salesSummaryFilterBranches(),
        ];
    }

    /**
     * @return array{rows: Collection<int, Customer>, filterSummary: string, total_outstanding: float}
     */
    public function customerDuesForExport(Request $request): array
    {
        $filters = $this->customerDuesFilters($request);
        $baseQuery = $this->customerDuesBaseQuery($filters);
        $dueColumn = $this->customerDuesOutstandingSumColumn($filters);
        $rows = (clone $baseQuery)
            ->orderByDesc($dueColumn)
            ->orderBy('customers.customer_code')
            ->limit(self::STOCK_EXPORT_LIMIT)
            ->get();

        return [
            'rows' => $rows,
            'filterSummary' => $this->customerDuesFilterSummary($filters),
            'total_outstanding' => (float) (clone $baseQuery)->sum($dueColumn),
        ];
    }

    public const HIGH_RISK_SORTS = ['score', 'overdue_amount', 'max_days_late', 'late_6m', 'name'];

    /**
     * @return array<string, mixed>
     */
    public function highRiskCustomersFilters(Request $request): array
    {
        $grades = array_values(array_intersect((array) $request->input('grade', []), ['A', 'B', 'C', 'D', 'E', 'N/A']));
        $sort = (string) $request->input('sort', 'overdue_amount');

        return [
            'q' => trim((string) $request->input('q', '')),
            'branch_id' => (string) $request->input('branch_id', ''),
            'grade' => $grades,
            'classification' => in_array($request->input('classification'), ['trend', 'isolated', 'any'], true) ? (string) $request->input('classification') : '',
            'status' => in_array($request->input('status'), ['active', 'overdue', 'defaulted', 'closed'], true) ? (string) $request->input('status') : '',
            'overdue_min' => trim((string) $request->input('overdue_min', '')),
            'overdue_max' => trim((string) $request->input('overdue_max', '')),
            'min_days_late' => trim((string) $request->input('min_days_late', '')),
            'has_guarantor' => in_array($request->input('has_guarantor'), ['yes', 'no'], true) ? (string) $request->input('has_guarantor') : '',
            'guarantor' => trim((string) $request->input('guarantor', '')),
            'sort' => in_array($sort, self::HIGH_RISK_SORTS, true) ? $sort : 'overdue_amount',
            'dir' => $request->input('dir') === 'asc' ? 'asc' : 'desc',
        ];
    }

    /**
     * Snapshot rows joined to customers/branches. With no grade or classification filter the
     * default rule applies (grade in config high_risk_grades OR classification = trend).
     *
     * @param  array<string, mixed>  $filters
     */
    public function highRiskCustomersBaseQuery(array $filters): QueryBuilder
    {
        $q = DB::table('customer_risk_snapshots as s')
            ->join('customers', 'customers.id', '=', 's.customer_id')
            ->leftJoin('locations as b', 'b.id', '=', 's.branch_id')
            ->leftJoin('customers as g', 'g.id', '=', 'customers.default_guarantor_id');

        $user = auth()->user();
        if ($user && ! $user->isAdmin() && ! $user->hasPermission('location.view-all')) {
            $ids = $user->accessibleLocationIds();
            $q->where(fn ($w) => $w->whereNull('s.branch_id')->orWhereIn('s.branch_id', $ids ?: [0]));
        }

        if ($filters['branch_id'] !== '' && $this->customerDuesBranchFilterAllowed((int) $filters['branch_id'])) {
            $q->where('s.branch_id', (int) $filters['branch_id']);
        } elseif ($filters['branch_id'] !== '') {
            $q->whereRaw('1 = 0');
        } elseif ($this->contextLocationId() !== null) {
            $q->where('s.branch_id', $this->contextLocationId());
        }

        if ($filters['grade'] !== [] || in_array($filters['classification'], ['trend', 'isolated', 'any'], true)) {
            if ($filters['grade'] !== []) {
                $q->whereIn('s.grade', $filters['grade']);
            }
            if (in_array($filters['classification'], ['trend', 'isolated'], true)) {
                $q->where('s.classification', $filters['classification']);
            }
        } else {
            $grades = config('customer_risk.high_risk_grades', ['D', 'E']);
            $q->where(fn ($w) => $w->whereIn('s.grade', $grades)->orWhere('s.classification', 'trend'));
        }

        if ($filters['status'] !== '') {
            $q->where('s.status', $filters['status']);
        }
        if (is_numeric($filters['overdue_min'])) {
            $q->where('s.overdue_amount', '>=', (float) $filters['overdue_min']);
        }
        if (is_numeric($filters['overdue_max'])) {
            $q->where('s.overdue_amount', '<=', (float) $filters['overdue_max']);
        }
        if (is_numeric($filters['min_days_late'])) {
            $q->where('s.max_days_late', '>=', (int) $filters['min_days_late']);
        }
        if ($filters['has_guarantor'] !== '') {
            $q->where('s.has_guarantor', $filters['has_guarantor'] === 'yes');
        }
        if ($filters['guarantor'] !== '') {
            $kw = '%'.$filters['guarantor'].'%';
            $q->where('g.name', 'like', $kw);
        }
        if ($filters['q'] !== '') {
            $kw = '%'.$filters['q'].'%';
            $q->where(fn ($w) => $w->where('customers.name', 'like', $kw)
                ->orWhere('customers.customer_code', 'like', $kw)
                ->orWhere('customers.phone', 'like', $kw));
        }

        return $q->select([
            'customers.id as customer_id', 'customers.customer_code', 'customers.name', 'customers.phone',
            'b.name as branch_name', 'g.name as guarantor_name',
            's.score', 's.grade', 's.classification', 's.improving', 's.status', 's.outstanding_amount', 's.overdue_amount',
            's.overdue_count', 's.avg_days_late', 's.max_days_late', 's.late_6m', 's.current_streak', 's.has_guarantor', 's.computed_at',
        ]);
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    protected function highRiskCustomersOrdered(QueryBuilder $query, array $filters): QueryBuilder
    {
        $column = match ($filters['sort']) {
            'score' => 's.score',
            'max_days_late' => 's.max_days_late',
            'late_6m' => 's.late_6m',
            'name' => 'customers.name',
            default => 's.overdue_amount',
        };

        return $query->orderBy($column, $filters['dir'])->orderBy('customers.customer_code');
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function highRiskCustomersKpis(array $filters): array
    {
        $base = $this->highRiskCustomersBaseQuery($filters);
        $agg = DB::query()->fromSub($base, 'x')
            ->selectRaw('COUNT(*) as customers, COALESCE(SUM(overdue_amount),0) as total_overdue, COALESCE(SUM(outstanding_amount),0) as total_outstanding, COALESCE(AVG(avg_days_late),0) as avg_days_late')
            ->first();
        $byGrade = DB::query()->fromSub($this->highRiskCustomersBaseQuery($filters), 'x')
            ->selectRaw('grade, COUNT(*) as n')->groupBy('grade')->pluck('n', 'grade')->all();

        return [
            'customers' => (int) $agg->customers,
            'total_overdue' => (float) $agg->total_overdue,
            'total_outstanding' => (float) $agg->total_outstanding,
            'avg_days_late' => round((float) $agg->avg_days_late, 1),
            'by_grade' => $byGrade,
        ];
    }

    /**
     * @return array{filters: array, rows: LengthAwarePaginator, kpis: array, filterBranches: Collection}
     */
    public function highRiskCustomersPaginated(Request $request, int $perPage = 50): array
    {
        $filters = $this->highRiskCustomersFilters($request);
        $rows = $this->highRiskCustomersOrdered($this->highRiskCustomersBaseQuery($filters), $filters)
            ->paginate($perPage)->withQueryString();

        return [
            'filters' => $filters,
            'rows' => $rows,
            'kpis' => $this->highRiskCustomersKpis($filters),
            'filterBranches' => $this->salesSummaryFilterBranches(),
        ];
    }

    /**
     * @return array{rows: Collection, filterSummary: string, kpis: array}
     */
    public function highRiskCustomersForExport(Request $request): array
    {
        $filters = $this->highRiskCustomersFilters($request);
        $rows = $this->highRiskCustomersOrdered($this->highRiskCustomersBaseQuery($filters), $filters)
            ->limit(self::STOCK_EXPORT_LIMIT)->get()
            ->map(function ($r) {
                $r->computed_at = $r->computed_at ? Carbon::parse($r->computed_at) : null;

                return $r;
            });

        return [
            'rows' => $rows,
            'filterSummary' => $this->highRiskCustomersFilterSummary($filters),
            'kpis' => $this->highRiskCustomersKpis($filters),
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    public function highRiskCustomersFilterSummary(array $filters): string
    {
        $parts = [];
        if ($filters['grade'] === [] && ! in_array($filters['classification'], ['trend', 'isolated', 'any'], true)) {
            $parts[] = 'High risk: grade '.implode('/', config('customer_risk.high_risk_grades', ['D', 'E'])).' or late-payment trend';
        }
        if ($filters['grade'] !== []) {
            $parts[] = 'Grade: '.implode(', ', $filters['grade']);
        }
        if (in_array($filters['classification'], ['trend', 'isolated'], true)) {
            $parts[] = 'Pattern: '.$filters['classification'];
        }
        if ($filters['status'] !== '') {
            $parts[] = 'Status: '.$filters['status'];
        }
        if ($filters['q'] !== '') {
            $parts[] = 'Search: '.$filters['q'];
        }
        if ($filters['branch_id'] !== '') {
            $b = Location::query()->whereKey((int) $filters['branch_id'])->first();
            $parts[] = 'Branch: '.($b ? $b->name : $filters['branch_id']);
        }
        if (is_numeric($filters['overdue_min']) || is_numeric($filters['overdue_max'])) {
            $parts[] = 'Overdue: '.($filters['overdue_min'] ?: '0').' – '.($filters['overdue_max'] ?: '∞');
        }
        if (is_numeric($filters['min_days_late'])) {
            $parts[] = 'Max days late ≥ '.$filters['min_days_late'];
        }
        if ($filters['has_guarantor'] !== '') {
            $parts[] = 'Guarantor: '.$filters['has_guarantor'];
        }
        if ($filters['guarantor'] !== '') {
            $parts[] = 'Guarantor name: '.$filters['guarantor'];
        }

        return implode(' · ', $parts);
    }

    /**
     * @return array{q: string, status: string, supplier_id: string, location_id: string, date_from: string, date_to: string}
     */
    public function openPurchaseOrdersFilters(Request $request): array
    {
        return [
            'q' => trim((string) $request->input('q', '')),
            'status' => (string) $request->input('status', ''),
            'supplier_id' => (string) $request->input('supplier_id', ''),
            'location_id' => (string) $request->input('location_id', ''),
            'date_from' => (string) $request->input('date_from', ''),
            'date_to' => (string) $request->input('date_to', ''),
        ];
    }

    public function openPurchaseOrdersBaseQuery(array $filters): Builder
    {
        return PurchaseOrder::query()
            ->with(['supplier', 'destinationLocation', 'payments'])
            ->withSettledPaymentsSum()
            ->when($filters['q'] !== '', function ($query) use ($filters): void {
                $keyword = $filters['q'];
                $query->where(function ($subQuery) use ($keyword): void {
                    $subQuery->where('po_number', 'like', "%{$keyword}%")
                        ->orWhereHas('supplier', function ($supplierQuery) use ($keyword): void {
                            $supplierQuery->where('name', 'like', "%{$keyword}%");
                        });
                });
            })
            ->when(
                $filters['status'] !== '' && in_array($filters['status'], ['draft', 'approved', 'partially_received'], true),
                fn ($query) => $query->where('status', $filters['status'])
            )
            ->when($filters['supplier_id'] !== '', fn ($query) => $query->where('supplier_id', (int) $filters['supplier_id']))
            ->when($filters['location_id'] !== '', fn ($query) => $query->where('destination_location_id', (int) $filters['location_id']))
            ->when($filters['location_id'] === '' && $this->contextLocationId() !== null, fn ($query) => $query->where('destination_location_id', $this->contextLocationId()))
            ->when($filters['date_from'] !== '', fn ($query) => $query->whereDate('ordered_at', '>=', $filters['date_from']))
            ->when($filters['date_to'] !== '', fn ($query) => $query->whereDate('ordered_at', '<=', $filters['date_to']))
            ->whereIn('status', ['draft', 'approved', 'partially_received']);
    }

    public function openPurchaseOrdersFilterSuppliers(): Collection
    {
        return Supplier::query()->orderBy('name')->get(['id', 'name']);
    }

    public function openPurchaseOrdersFilterLocations(): Collection
    {
        return Location::query()->where('is_active', true)->warehouses()->orderBy('name')->get(['id', 'name', 'code']);
    }

    /**
     * @return array{filters: array, rows: LengthAwarePaginator, kpis: array, filterSuppliers: Collection, filterLocations: Collection}
     */
    public function openPurchaseOrdersPaginated(Request $request, int $perPage = 50): array
    {
        $filters = $this->openPurchaseOrdersFilters($request);
        $baseQuery = $this->openPurchaseOrdersBaseQuery($filters);
        $rows = (clone $baseQuery)
            ->orderByDesc('ordered_at')
            ->orderByDesc('id')
            ->paginate($perPage)->withQueryString();

        $kpis = [
            'filtered' => $rows->total(),
            'filtered_amount' => (float) (clone $baseQuery)->sum('total_amount'),
        ];

        return [
            'filters' => $filters,
            'rows' => $rows,
            'kpis' => $kpis,
            'filterSuppliers' => $this->openPurchaseOrdersFilterSuppliers(),
            'filterLocations' => $this->openPurchaseOrdersFilterLocations(),
        ];
    }

    /**
     * @return array{rows: Collection<int, PurchaseOrder>, filterSummary: string}
     */
    public function openPurchaseOrdersForExport(Request $request): array
    {
        $filters = $this->openPurchaseOrdersFilters($request);
        $rows = (clone $this->openPurchaseOrdersBaseQuery($filters))
            ->orderByDesc('ordered_at')
            ->orderByDesc('id')
            ->limit(self::STOCK_EXPORT_LIMIT)
            ->get();

        return [
            'rows' => $rows,
            'filterSummary' => $this->openPurchaseOrdersFilterSummary($filters),
        ];
    }

    /**
     * @param  array{q: string, status: string, supplier_id: string, location_id: string, date_from: string, date_to: string}  $filters
     */
    public function openPurchaseOrdersFilterSummary(array $filters): string
    {
        $parts = ['Open purchase orders (draft / approved / partially received)'];
        if ($filters['q'] !== '') {
            $parts[] = 'Search: '.$filters['q'];
        }
        if ($filters['status'] !== '') {
            $parts[] = 'Status: '.$filters['status'];
        }
        if ($filters['supplier_id'] !== '') {
            $s = Supplier::query()->whereKey((int) $filters['supplier_id'])->value('name');
            $parts[] = 'Supplier: '.($s ?? $filters['supplier_id']);
        }
        if ($filters['location_id'] !== '') {
            $loc = Location::query()->whereKey((int) $filters['location_id'])->first();
            $parts[] = 'Destination: '.($loc ? $loc->name : $filters['location_id']);
        }
        if ($filters['date_from'] !== '') {
            $parts[] = 'From '.$filters['date_from'];
        }
        if ($filters['date_to'] !== '') {
            $parts[] = 'To '.$filters['date_to'];
        }

        return implode(' · ', $parts);
    }

    /**
     * @return array{q: string, branch_id: string}
     */
    public function invoiceOutstandingByCustomerFilters(Request $request): array
    {
        return [
            'q' => trim((string) $request->input('q', '')),
            'branch_id' => (string) $request->input('branch_id', ''),
        ];
    }

    /**
     * @param  QueryBuilder  $q  Query on sales_invoices (with joins allowed before this runs)
     * @param  array{q: string, branch_id: string}  $filters
     * @param  string  $salesInvoicesQualifier  Primary sales_invoices alias or table name prefix (column: {qualifier}.branch_id)
     */
    protected function applyInvoiceOutstandingReportBranchFilter(QueryBuilder $q, array $filters, string $salesInvoicesQualifier = 'sales_invoices'): void
    {
        $branchCol = "{$salesInvoicesQualifier}.branch_id";

        if ($filters['branch_id'] !== '') {
            $bid = (int) $filters['branch_id'];
            if (! $this->customerDuesBranchFilterAllowed($bid)) {
                $q->whereRaw('1 = 0');

                return;
            }
            $q->where($branchCol, $bid);

            return;
        }

        $id = $this->contextLocationId();
        if ($id !== null) {
            $q->where($branchCol, $id);

            return;
        }

        $user = auth()->user();
        if (! $user || $user->isAdmin() || $user->hasPermission('location.view-all')) {
            return;
        }

        $ids = $user->accessibleLocationIds();
        if ($ids === []) {
            $q->whereRaw('1 = 0');
        } else {
            $q->whereIn($branchCol, $ids);
        }
    }

    /**
     * Branch access for journal entries (manual expenses use {@see JournalEntry::$branch_id}).
     *
     * @param  array{q?: string, branch_id: string}  $filters
     * @param  string  $journalEntriesQualifier  Primary journal_entries alias or table name
     */
    protected function applyManualExpenseReportBranchFilter(QueryBuilder $q, array $filters, string $journalEntriesQualifier = 'journal_entries'): void
    {
        $branchCol = "{$journalEntriesQualifier}.branch_id";

        if ($filters['branch_id'] !== '') {
            $bid = (int) $filters['branch_id'];
            if (! $this->customerDuesBranchFilterAllowed($bid)) {
                $q->whereRaw('1 = 0');

                return;
            }
            $q->where($branchCol, $bid);

            return;
        }

        $id = $this->contextLocationId();
        if ($id !== null) {
            $q->where($branchCol, $id);

            return;
        }

        $user = auth()->user();
        if (! $user || $user->isAdmin() || $user->hasPermission('location.view-all')) {
            return;
        }

        $ids = $user->accessibleLocationIds();
        if ($ids === []) {
            $q->whereRaw('1 = 0');
        } else {
            $q->whereIn($branchCol, $ids);
        }
    }

    /**
     * Grouped subquery: one row per customer × branch with open invoice amounts.
     *
     * @param  array{q: string, branch_id: string}  $filters
     */
    protected function invoiceOutstandingByCustomerGroupedSubquery(array $filters): QueryBuilder
    {
        $q = DB::table('sales_invoices')
            ->join('customers', 'customers.id', '=', 'sales_invoices.customer_id')
            ->whereIn('sales_invoices.status', ['posted', 'active'])
            ->where('sales_invoices.amount_due', '>', 0.009)
            ->where('customers.is_active', true);

        $this->applyInvoiceOutstandingReportBranchFilter($q, $filters);

        if ($filters['q'] !== '') {
            $keyword = $filters['q'];
            $q->where(function ($sub) use ($keyword): void {
                $sub->where('customers.name', 'like', "%{$keyword}%")
                    ->orWhere('customers.phone', 'like', "%{$keyword}%")
                    ->orWhere('customers.customer_code', 'like', "%{$keyword}%");
            });
        }

        return $q->select([
            'sales_invoices.customer_id',
            'sales_invoices.branch_id',
            DB::raw('ROUND(SUM(sales_invoices.amount_due), 2) as total_due'),
            DB::raw('COUNT(*) as open_invoice_count'),
        ])
            ->groupBy('sales_invoices.customer_id', 'sales_invoices.branch_id');
    }

    /**
     * One row per invoice for open invoices (posted/active, with unpaid balance).
     * Product names are aggregated into a single comma-separated column.
     *
     * @param  array{q: string, branch_id: string}  $filters
     */
    protected function invoiceOutstandingInvoiceLineDetailBaseQuery(array $filters): QueryBuilder
    {
        $q = DB::table('sales_invoices as si')
            ->join('customers as c', 'c.id', '=', 'si.customer_id')
            ->join('locations as loc', 'loc.id', '=', 'si.branch_id')
            ->leftJoin('sales_invoice_lines as sil', 'sil.sales_invoice_id', '=', 'si.id')
            ->leftJoin('products as p', 'p.id', '=', 'sil.product_id')
            ->whereIn('si.status', ['posted', 'active'])
            ->where('si.amount_due', '>', 0.009)
            ->where('c.is_active', true);

        $this->applyInvoiceOutstandingReportBranchFilter($q, $filters, 'si');

        if ($filters['q'] !== '') {
            $keyword = $filters['q'];
            $q->where(function ($sub) use ($keyword): void {
                $sub->where('c.name', 'like', "%{$keyword}%")
                    ->orWhere('c.phone', 'like', "%{$keyword}%")
                    ->orWhere('c.customer_code', 'like', "%{$keyword}%");
            });
        }

        return $q->select([
            'si.id as sales_invoice_id',
            'si.invoice_number',
            'si.invoice_date',
            'si.grand_total',
            'si.amount_due as invoice_amount_due',
            'si.customer_id',
            'si.branch_id',
            'c.customer_code',
            'c.name as customer_name',
            'c.phone as customer_phone',
            'loc.code as branch_code',
            'loc.name as branch_name',
            DB::raw('GROUP_CONCAT(DISTINCT p.name ORDER BY p.name SEPARATOR \', \') as item_names'),
        ])
            ->groupBy(
                'si.id', 'si.invoice_number', 'si.invoice_date', 'si.grand_total',
                'si.amount_due', 'si.customer_id', 'si.branch_id',
                'c.customer_code', 'c.name', 'c.phone',
                'loc.code', 'loc.name',
            )
            ->orderBy('loc.name')
            ->orderBy('si.invoice_date')
            ->orderBy('si.id');
    }

    /**
     * One row per branch: rolled-up totals for the same scope as the detail rows.
     *
     * @param  array{q: string, branch_id: string}  $filters
     * @return Collection<int, object>
     */
    public function invoiceOutstandingByCustomerBranchTotals(array $filters): Collection
    {
        $inner = $this->invoiceOutstandingByCustomerGroupedSubquery($filters);

        return DB::query()
            ->fromSub($inner, 'agg')
            ->join('locations', 'locations.id', '=', 'agg.branch_id')
            ->select([
                'agg.branch_id',
                'locations.code as branch_code',
                'locations.name as branch_name',
                DB::raw('COUNT(*) as customer_branch_rows'),
                DB::raw('SUM(agg.open_invoice_count) as open_invoice_count'),
                DB::raw('ROUND(SUM(agg.total_due), 2) as total_due'),
            ])
            ->groupBy('agg.branch_id', 'locations.code', 'locations.name')
            ->orderBy('locations.name')
            ->get();
    }

    /**
     * @param  array{q: string, branch_id: string}  $filters
     */
    public function invoiceOutstandingByCustomerFilterSummary(array $filters): string
    {
        $parts = ['Open invoice amounts due by customer and branch'];
        if ($filters['q'] !== '') {
            $parts[] = 'Search: '.$filters['q'];
        }
        if ($filters['branch_id'] !== '') {
            $b = Location::query()->whereKey((int) $filters['branch_id'])->first();
            $parts[] = 'Branch: '.($b ? $b->name : $filters['branch_id']);
        }

        return implode(' · ', $parts);
    }

    /**
     * @param  array{q: string, branch_id: string}  $filters
     */
    public function invoiceOutstandingByCustomerBaseQuery(array $filters): QueryBuilder
    {
        $inner = $this->invoiceOutstandingByCustomerGroupedSubquery($filters);

        return DB::query()
            ->fromSub($inner, 'agg')
            ->join('customers', 'customers.id', '=', 'agg.customer_id')
            ->join('locations', 'locations.id', '=', 'agg.branch_id')
            ->select([
                'agg.customer_id',
                'agg.branch_id',
                'customers.customer_code',
                'customers.name as customer_name',
                'customers.phone as customer_phone',
                'locations.code as branch_code',
                'locations.name as branch_name',
                'agg.total_due',
                'agg.open_invoice_count',
            ])
            ->orderBy('locations.name')
            ->orderByDesc('agg.total_due')
            ->orderBy('customers.customer_code');
    }

    /**
     * @return array{filters: array{q: string, branch_id: string}, rows: LengthAwarePaginator<object>, kpis: array{invoice_rows: int, total_due: float, distinct_customers: int}, branch_totals: Collection<int, object>, filterBranches: Collection<int, Location>}
     */
    public function invoiceOutstandingByCustomerPaginated(Request $request, int $perPage = 50): array
    {
        $filters = $this->invoiceOutstandingByCustomerFilters($request);
        $detailBase = $this->invoiceOutstandingInvoiceLineDetailBaseQuery($filters);

        $paginator = (clone $detailBase)->paginate($perPage)->withQueryString();

        $inner = $this->invoiceOutstandingByCustomerGroupedSubquery($filters);
        $branchTotals = $this->invoiceOutstandingByCustomerBranchTotals($filters);
        $invoiceRowCount = (int) DB::query()->fromSub(clone $detailBase, 'inv_rows')->count();

        return [
            'filters' => $filters,
            'rows' => $paginator,
            'kpis' => [
                'invoice_rows' => $invoiceRowCount,
                'total_due' => round((float) DB::query()->fromSub($inner, 'k')->sum('k.total_due'), 2),
                'distinct_customers' => (int) (DB::query()->fromSub($inner, 'k')->selectRaw('COUNT(DISTINCT k.customer_id) as dc')->value('dc') ?? 0),
            ],
            'branch_totals' => $branchTotals,
            'filterBranches' => $this->salesSummaryFilterBranches(),
        ];
    }

    /**
     * @return array{rows: Collection<int, object>, branch_totals: Collection<int, object>, filterSummary: string, kpis: array{invoice_rows: int, total_due: float, distinct_customers: int}}
     */
    public function invoiceOutstandingByCustomerForExport(Request $request): array
    {
        $filters = $this->invoiceOutstandingByCustomerFilters($request);
        $detailBase = $this->invoiceOutstandingInvoiceLineDetailBaseQuery($filters);
        $rows = (clone $detailBase)->limit(self::STOCK_EXPORT_LIMIT)->get();

        $inner = $this->invoiceOutstandingByCustomerGroupedSubquery($filters);
        $branchTotals = $this->invoiceOutstandingByCustomerBranchTotals($filters);

        $invoiceRowCount = (int) DB::query()->fromSub(clone $detailBase, 'inv_rows')->count();

        return [
            'rows' => $rows,
            'branch_totals' => $branchTotals,
            'filterSummary' => $this->invoiceOutstandingByCustomerFilterSummary($filters),
            'kpis' => [
                'invoice_rows' => $invoiceRowCount,
                'total_due' => round((float) DB::query()->fromSub($inner, 'k')->sum('k.total_due'), 2),
                'distinct_customers' => (int) (DB::query()->fromSub($inner, 'k')->selectRaw('COUNT(DISTINCT k.customer_id) as dc')->value('dc') ?? 0),
            ],
        ];
    }

    /**
     * @return array{q: string, location_id: string}
     */
    public function supplierDuesAndPaymentsFilters(Request $request): array
    {
        return [
            'q' => trim((string) $request->input('q', '')),
            'location_id' => (string) $request->input('location_id', ''),
        ];
    }

    /**
     * @param  array{q: string, location_id: string}  $filters
     */
    protected function applySupplierPurchaseOrderReportLocationFilter(QueryBuilder $q, array $filters): void
    {
        if ($filters['location_id'] !== '') {
            $lid = (int) $filters['location_id'];
            if (! $this->customerDuesBranchFilterAllowed($lid)) {
                $q->whereRaw('1 = 0');

                return;
            }
            $q->where('purchase_orders.destination_location_id', $lid);

            return;
        }

        $id = $this->contextLocationId();
        if ($id !== null) {
            $q->where('purchase_orders.destination_location_id', $id);

            return;
        }

        $user = auth()->user();
        if (! $user || $user->isAdmin() || $user->hasPermission('location.view-all')) {
            return;
        }

        $ids = $user->accessibleLocationIds();
        if ($ids === []) {
            $q->whereRaw('1 = 0');
        } else {
            $q->whereIn('purchase_orders.destination_location_id', $ids);
        }
    }

    /**
     * One row per supplier: PO amount due (after payments & additional costs) and sum of payment lines.
     *
     * @param  array{q: string, location_id: string}  $filters
     */
    protected function supplierDuesAndPaymentsGroupedSubquery(array $filters): QueryBuilder
    {
        $paymentTotals = DB::table('purchase_order_payments')
            ->select('purchase_order_id', DB::raw('SUM(amount + COALESCE(settlement_discount, 0)) as paid_sum'))
            ->groupBy('purchase_order_id');

        $q = DB::table('purchase_orders')
            ->join('suppliers', 'suppliers.id', '=', 'purchase_orders.supplier_id')
            ->leftJoinSub($paymentTotals, 'pop', function ($join): void {
                $join->on('pop.purchase_order_id', '=', 'purchase_orders.id');
            })
            ->whereNotIn('purchase_orders.status', ['draft', 'cancelled'])
            ->where('suppliers.is_active', true);

        $this->applySupplierPurchaseOrderReportLocationFilter($q, $filters);

        if ($filters['q'] !== '') {
            $keyword = $filters['q'];
            $q->where(function ($sub) use ($keyword): void {
                $sub->where('suppliers.name', 'like', "%{$keyword}%")
                    ->orWhere('suppliers.phone', 'like', "%{$keyword}%")
                    ->orWhere('suppliers.contact_person', 'like', "%{$keyword}%");
            });
        }

        return $q
            ->selectRaw('
                purchase_orders.supplier_id,
                SUM(CASE
                    WHEN (purchase_orders.total_amount - COALESCE(pop.paid_sum, 0) - purchase_orders.additional_cost_amount) > 0
                    THEN (purchase_orders.total_amount - COALESCE(pop.paid_sum, 0) - purchase_orders.additional_cost_amount)
                    ELSE 0
                END) as total_due,
                SUM(COALESCE(pop.paid_sum, 0)) as total_paid,
                COUNT(*) as po_count
            ')
            ->groupBy('purchase_orders.supplier_id');
    }

    /**
     * @param  array{q: string, location_id: string}  $filters
     */
    public function supplierDuesAndPaymentsBaseQuery(array $filters): QueryBuilder
    {
        $inner = $this->supplierDuesAndPaymentsGroupedSubquery($filters);

        return DB::query()
            ->fromSub($inner, 'agg')
            ->join('suppliers', 'suppliers.id', '=', 'agg.supplier_id')
            ->select([
                'agg.supplier_id',
                'agg.total_due',
                'agg.total_paid',
                'agg.po_count',
                'suppliers.name',
                'suppliers.phone',
            ])
            ->orderByDesc('agg.total_due')
            ->orderBy('suppliers.name');
    }

    /**
     * @param  array{q: string, location_id: string}  $filters
     */
    public function supplierDuesAndPaymentsFilterSummary(array $filters): string
    {
        $parts = ['Supplier dues & payments (non-draft / non-cancelled POs)'];
        if ($filters['q'] !== '') {
            $parts[] = 'Search: '.$filters['q'];
        }
        if ($filters['location_id'] !== '') {
            $loc = Location::query()->whereKey((int) $filters['location_id'])->first();
            $parts[] = 'Destination: '.($loc ? $loc->name : $filters['location_id']);
        }

        return implode(' · ', $parts);
    }

    /**
     * @return array{filters: array{q: string, location_id: string}, rows: LengthAwarePaginator<object>, kpis: array{suppliers: int, total_due: float, total_paid: float, po_count: int}, filterWarehouseLocations: Collection<int, Location>}
     */
    public function supplierDuesAndPaymentsPaginated(Request $request, int $perPage = 50): array
    {
        $filters = $this->supplierDuesAndPaymentsFilters($request);
        $base = $this->supplierDuesAndPaymentsBaseQuery($filters);
        $rows = (clone $base)->paginate($perPage)->withQueryString();
        $inner = $this->supplierDuesAndPaymentsGroupedSubquery($filters);

        return [
            'filters' => $filters,
            'rows' => $rows,
            'kpis' => [
                'suppliers' => (int) DB::query()->fromSub($inner, 'k')->count(),
                'total_due' => round((float) DB::query()->fromSub($inner, 'k')->sum('k.total_due'), 2),
                'total_paid' => round((float) DB::query()->fromSub($inner, 'k')->sum('k.total_paid'), 2),
                'po_count' => (int) DB::query()->fromSub($inner, 'k')->sum('k.po_count'),
            ],
            'filterWarehouseLocations' => $this->openPurchaseOrdersFilterLocations(),
        ];
    }

    /**
     * @return array{rows: Collection<int, object>, filterSummary: string, total_due: float, total_paid: float}
     */
    public function supplierDuesAndPaymentsForExport(Request $request): array
    {
        $filters = $this->supplierDuesAndPaymentsFilters($request);
        $inner = $this->supplierDuesAndPaymentsGroupedSubquery($filters);
        $rows = (clone $this->supplierDuesAndPaymentsBaseQuery($filters))
            ->limit(self::STOCK_EXPORT_LIMIT)
            ->get();

        return [
            'rows' => $rows,
            'filterSummary' => $this->supplierDuesAndPaymentsFilterSummary($filters),
            'total_due' => round((float) DB::query()->fromSub($inner, 'k')->sum('k.total_due'), 2),
            'total_paid' => round((float) DB::query()->fromSub($inner, 'k')->sum('k.total_paid'), 2),
        ];
    }

    /**
     * @return array{date_from: string, date_to: string, branch_id: string}
     */
    public function salesByDayBranchFilters(Request $request): array
    {
        $from = (string) $request->input('date_from', '');
        $to = (string) $request->input('date_to', '');
        if ($from === '' && $to === '') {
            $from = now()->startOfMonth()->toDateString();
            $to = now()->toDateString();
        }

        return [
            'date_from' => $from,
            'date_to' => $to,
            'branch_id' => (string) $request->input('branch_id', ''),
        ];
    }

    /**
     * Daily totals per branch (posted + active invoices).
     *
     * @param  array{date_from: string, date_to: string, branch_id: string}  $filters
     */
    protected function salesByDayBranchGroupedSubquery(array $filters): QueryBuilder
    {
        $q = DB::table('sales_invoices')
            ->whereIn('sales_invoices.status', ['posted', 'active']);

        $branchOnly = ['q' => '', 'branch_id' => $filters['branch_id']];
        $this->applyInvoiceOutstandingReportBranchFilter($q, $branchOnly);

        if ($filters['date_from'] !== '') {
            $q->whereDate('sales_invoices.invoice_date', '>=', $filters['date_from']);
        }
        if ($filters['date_to'] !== '') {
            $q->whereDate('sales_invoices.invoice_date', '<=', $filters['date_to']);
        }

        return $q
            ->selectRaw('
                sales_invoices.invoice_date,
                sales_invoices.branch_id,
                COUNT(*) as invoice_count,
                ROUND(SUM(sales_invoices.grand_total), 2) as grand_total,
                ROUND(SUM(sales_invoices.amount_paid), 2) as amount_paid,
                ROUND(SUM(sales_invoices.amount_due), 2) as amount_due
            ')
            ->groupBy('sales_invoices.invoice_date', 'sales_invoices.branch_id');
    }

    /**
     * Per product, per day, per branch (posted + active invoice lines).
     *
     * @param  array{date_from: string, date_to: string, branch_id: string}  $filters
     */
    protected function salesByDayBranchProductGroupedSubquery(array $filters): QueryBuilder
    {
        $q = DB::table('sales_invoice_lines')
            ->join('sales_invoices', 'sales_invoices.id', '=', 'sales_invoice_lines.sales_invoice_id')
            ->join('products', 'products.id', '=', 'sales_invoice_lines.product_id')
            ->whereIn('sales_invoices.status', ['posted', 'active']);

        $branchOnly = ['q' => '', 'branch_id' => $filters['branch_id']];
        $this->applyInvoiceOutstandingReportBranchFilter($q, $branchOnly);

        if ($filters['date_from'] !== '') {
            $q->whereDate('sales_invoices.invoice_date', '>=', $filters['date_from']);
        }
        if ($filters['date_to'] !== '') {
            $q->whereDate('sales_invoices.invoice_date', '<=', $filters['date_to']);
        }

        return $q
            ->selectRaw('
                sales_invoices.invoice_date,
                sales_invoices.branch_id,
                sales_invoice_lines.product_id,
                products.name as product_name,
                SUM(sales_invoice_lines.qty) as qty_sold,
                ROUND(SUM(sales_invoice_lines.line_total), 2) as line_total,
                COUNT(DISTINCT sales_invoices.id) as invoice_count
            ')
            ->groupBy(
                'sales_invoices.invoice_date',
                'sales_invoices.branch_id',
                'sales_invoice_lines.product_id',
                'products.name',
            );
    }

    /**
     * @param  array{date_from: string, date_to: string, branch_id: string}  $filters
     */
    public function salesByDayBranchBaseQuery(array $filters): QueryBuilder
    {
        $inner = $this->salesByDayBranchProductGroupedSubquery($filters);

        return DB::query()
            ->fromSub($inner, 'agg')
            ->join('locations', 'locations.id', '=', 'agg.branch_id')
            ->select([
                'agg.invoice_date',
                'agg.branch_id',
                'agg.product_id',
                'agg.product_name',
                'agg.qty_sold',
                'agg.line_total',
                'agg.invoice_count',
                'locations.code as branch_code',
                'locations.name as branch_name',
            ])
            ->orderBy('locations.name')
            ->orderByDesc('agg.invoice_date')
            ->orderBy('agg.product_name');
    }

    /**
     * @param  array{date_from: string, date_to: string, branch_id: string}  $filters
     */
    public function salesByDayBranchFilterSummary(array $filters): string
    {
        $parts = ['Daily sales by branch (posted & active)'];
        if ($filters['date_from'] !== '' || $filters['date_to'] !== '') {
            $range = trim(
                ($filters['date_from'] !== '' ? 'From '.$filters['date_from'] : '')
                .($filters['date_from'] !== '' && $filters['date_to'] !== '' ? ' · ' : '')
                .($filters['date_to'] !== '' ? 'To '.$filters['date_to'] : '')
            );
            if ($range !== '') {
                $parts[] = $range;
            }
        }
        if ($filters['branch_id'] !== '') {
            $b = Location::query()->whereKey((int) $filters['branch_id'])->first();
            $parts[] = 'Branch: '.($b ? $b->name : $filters['branch_id']);
        }
        if ($label = $this->collectorFilterLabel($filters['collector_id'] ?? '')) {
            $parts[] = $label;
        }

        return implode(' · ', $parts);
    }

    /**
     * Allocated amount and payment count per collector for the whole filtered report.
     *
     * @return list<array{collector_id: int|null, collector: string, payment_count: int, amount: float}>
     */
    public function customerPaymentByBranchCollectorTotals(array $filters): array
    {
        return DB::query()->fromSub($this->customerPaymentByBranchBaseQuery($filters), 't')
            ->selectRaw('t.recorded_by as collector_id, MAX(t.recorded_by_name) as collector_name, COUNT(DISTINCT t.payment_id) as payment_count, COALESCE(SUM(t.allocated_amount), 0) as amount')
            ->groupBy('t.recorded_by')
            ->orderByDesc('amount')
            ->get()
            ->map(fn ($r): array => [
                'collector_id' => $r->collector_id !== null ? (int) $r->collector_id : null,
                'collector' => $r->collector_name ?: 'Unknown',
                'payment_count' => (int) $r->payment_count,
                'amount' => round((float) $r->amount, 2),
            ])->all();
    }

    /**
     * @return array{filters: array{date_from: string, date_to: string, branch_id: string}, rows: LengthAwarePaginator<object>, kpis: array{day_rows: int, invoice_count: int, grand_total: float, amount_paid: float, amount_due: float}, productTotals: array{qty_sold: int, line_total: float}, filterBranches: Collection<int, Location>}
     */
    public function salesByDayBranchPaginated(Request $request, int $perPage = 50): array
    {
        $filters = $this->salesByDayBranchFilters($request);
        $base = $this->salesByDayBranchBaseQuery($filters);
        $rows = (clone $base)->paginate($perPage)->withQueryString();
        $innerInvoice = $this->salesByDayBranchGroupedSubquery($filters);
        $innerProduct = $this->salesByDayBranchProductGroupedSubquery($filters);
        $productTotalsRow = DB::query()
            ->fromSub($innerProduct, 't')
            ->selectRaw('COALESCE(SUM(t.qty_sold), 0) as qty_sold, COALESCE(SUM(t.line_total), 0) as line_total')
            ->first();

        return [
            'filters' => $filters,
            'rows' => $rows,
            'kpis' => [
                'day_rows' => (int) DB::query()->fromSub($innerProduct, 'k')->count(),
                'invoice_count' => (int) DB::query()->fromSub($innerInvoice, 'k')->sum('k.invoice_count'),
                'grand_total' => round((float) DB::query()->fromSub($innerInvoice, 'k')->sum('k.grand_total'), 2),
                'amount_paid' => round((float) DB::query()->fromSub($innerInvoice, 'k')->sum('k.amount_paid'), 2),
                'amount_due' => round((float) DB::query()->fromSub($innerInvoice, 'k')->sum('k.amount_due'), 2),
            ],
            'productTotals' => [
                'qty_sold' => (int) round((float) ($productTotalsRow->qty_sold ?? 0)),
                'line_total' => round((float) ($productTotalsRow->line_total ?? 0), 2),
            ],
            'filterBranches' => $this->salesSummaryFilterBranches(),
        ];
    }

    /**
     * @return array{rows: Collection<int, object>, filterSummary: string, kpis: array{day_rows: int, invoice_count: int, grand_total: float, amount_paid: float, amount_due: float}, productTotals: array{qty_sold: int, line_total: float}}
     */
    public function salesByDayBranchForExport(Request $request): array
    {
        $filters = $this->salesByDayBranchFilters($request);
        $innerInvoice = $this->salesByDayBranchGroupedSubquery($filters);
        $innerProduct = $this->salesByDayBranchProductGroupedSubquery($filters);
        $productTotalsRow = DB::query()
            ->fromSub($innerProduct, 't')
            ->selectRaw('COALESCE(SUM(t.qty_sold), 0) as qty_sold, COALESCE(SUM(t.line_total), 0) as line_total')
            ->first();
        $rows = (clone $this->salesByDayBranchBaseQuery($filters))
            ->limit(self::STOCK_EXPORT_LIMIT)
            ->get();

        return [
            'rows' => $rows,
            'filterSummary' => $this->salesByDayBranchFilterSummary($filters),
            'kpis' => [
                'day_rows' => (int) DB::query()->fromSub($innerProduct, 'k')->count(),
                'invoice_count' => (int) DB::query()->fromSub($innerInvoice, 'k')->sum('k.invoice_count'),
                'grand_total' => round((float) DB::query()->fromSub($innerInvoice, 'k')->sum('k.grand_total'), 2),
                'amount_paid' => round((float) DB::query()->fromSub($innerInvoice, 'k')->sum('k.amount_paid'), 2),
                'amount_due' => round((float) DB::query()->fromSub($innerInvoice, 'k')->sum('k.amount_due'), 2),
            ],
            'productTotals' => [
                'qty_sold' => (int) round((float) ($productTotalsRow->qty_sold ?? 0)),
                'line_total' => round((float) ($productTotalsRow->line_total ?? 0), 2),
            ],
        ];
    }

    /**
     * @return array{wallet_id: string, date_from: string, date_to: string, source: string}
     */
    public function walletTransactionsFilters(Request $request): array
    {
        $source = (string) $request->input('source', '');
        if ($source !== '' && ! array_key_exists($source, JournalEntry::sources())) {
            $source = '';
        }

        return [
            'wallet_id' => (string) $request->input('wallet_id', ''),
            'date_from' => (string) $request->input('date_from', now()->startOfMonth()->toDateString()),
            'date_to' => (string) $request->input('date_to', now()->endOfMonth()->toDateString()),
            'source' => $source,
        ];
    }

    /**
     * @return array<string, string>
     */
    public function walletTransactionsFilterSources(): array
    {
        return JournalEntry::sources();
    }

    public function walletTransactionsBaseQuery(array $filters): Builder
    {
        $query = JournalLine::query()
            ->with(['journalEntry.branch'])
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.journal_entry_id')
            ->select('journal_lines.*')
            ->orderBy('journal_entries.entry_date', 'asc')
            ->orderBy('journal_entries.id', 'asc')
            ->orderBy('journal_lines.id', 'asc');

        if ($filters['wallet_id'] !== '') {
            $wallet = Wallet::query()->find((int) $filters['wallet_id']);
            if ($wallet) {
                $query->where('journal_lines.ledger_account_id', $wallet->ledger_account_id);
            } else {
                $query->where('journal_lines.ledger_account_id', 0); // Force empty if invalid wallet
            }
        } else {
            // Force empty if no wallet is selected to prevent huge unindexed scans
            $query->where('journal_lines.ledger_account_id', 0);
        }

        if ($filters['date_from'] !== '') {
            $query->whereDate('journal_entries.entry_date', '>=', $filters['date_from']);
        }

        if ($filters['date_to'] !== '') {
            $query->whereDate('journal_entries.entry_date', '<=', $filters['date_to']);
        }

        return $this->walletTransactionsApplySourceFilter($query, $filters);
    }

    /**
     * @param  array{wallet_id: string, date_from: string, date_to: string, source: string}  $filters
     */
    protected function walletTransactionsApplySourceFilter(Builder $query, array $filters): Builder
    {
        if (($filters['source'] ?? '') !== '') {
            $query->where('journal_entries.source', $filters['source']);
        }

        return $query;
    }

    public function walletTransactionsFilterSummary(array $filters): string
    {
        $parts = [];
        if ($filters['wallet_id'] !== '') {
            $wallet = Wallet::query()->find((int) $filters['wallet_id']);
            $parts[] = 'Wallet: '.($wallet ? $wallet->name : $filters['wallet_id']);
        } else {
            $parts[] = 'Wallet: None selected';
        }

        if ($filters['date_from'] !== '') {
            $parts[] = 'From '.$filters['date_from'];
        }
        if ($filters['date_to'] !== '') {
            $parts[] = 'To '.$filters['date_to'];
        }

        if (($filters['source'] ?? '') !== '') {
            $parts[] = 'Source: '.(JournalEntry::sources()[$filters['source']] ?? $filters['source']);
        }

        return implode(' · ', $parts);
    }

    /**
     * @param  array{wallet_id: string, date_from: string, date_to: string, source: string}  $filters
     * @return Collection<int, array{source: string, label: string, line_count: int, debit: float, credit: float, net: float}>
     */
    public function walletTransactionsSummaryBySource(array $filters): Collection
    {
        if ($filters['wallet_id'] === '') {
            return collect();
        }

        $wallet = Wallet::query()->find((int) $filters['wallet_id']);
        if (! $wallet) {
            return collect();
        }

        $rows = JournalLine::query()
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.journal_entry_id')
            ->where('journal_lines.ledger_account_id', $wallet->ledger_account_id)
            ->when($filters['date_from'] !== '', fn (Builder $query): Builder => $query->whereDate('journal_entries.entry_date', '>=', $filters['date_from']))
            ->when($filters['date_to'] !== '', fn (Builder $query): Builder => $query->whereDate('journal_entries.entry_date', '<=', $filters['date_to']))
            ->tap(fn (Builder $query) => $this->walletTransactionsApplySourceFilter($query, $filters))
            ->selectRaw('journal_entries.source as source')
            ->selectRaw('COUNT(*) as line_count')
            ->selectRaw('COALESCE(SUM(journal_lines.debit), 0) as debit')
            ->selectRaw('COALESCE(SUM(journal_lines.credit), 0) as credit')
            ->groupBy('journal_entries.source')
            ->orderBy('journal_entries.source')
            ->get();

        return $rows->map(function ($row): array {
            $debit = round((float) $row->debit, 2);
            $credit = round((float) $row->credit, 2);

            return [
                'source' => (string) $row->source,
                'label' => JournalEntry::sources()[$row->source] ?? (string) $row->source,
                'line_count' => (int) $row->line_count,
                'debit' => $debit,
                'credit' => $credit,
                'net' => round($debit - $credit, 2),
            ];
        })->values();
    }

    public function walletTransactionsPaginated(Request $request, int $perPage = 50): array
    {
        unset($perPage);

        return $this->walletTransactionsForExport($request);
    }

    /**
     * @return array{
     *     rows: Collection<int, JournalLine>,
     *     filters: array{wallet_id: string, date_from: string, date_to: string, source: string},
     *     openingBalancesByBranch: Collection<string, float>,
     *     globalOpeningBalance: float,
     *     summaryByBranch: Collection<int, array>,
     *     filterSummary: string,
     *     summaryBySource: Collection<int, array>,
     *     truncated: bool,
     *     totalMatching: int
     * }
     */
    public function walletTransactionsForExport(Request $request): array
    {
        $filters = $this->walletTransactionsFilters($request);
        $baseQuery = $this->walletTransactionsBaseQuery($filters);

        $totalMatching = (clone $baseQuery)->count();
        $truncated = $totalMatching > self::STOCK_EXPORT_LIMIT;
        $rows = (clone $baseQuery)->limit(self::STOCK_EXPORT_LIMIT)->get();

        $openingBalancesByBranch = $this->walletTransactionsOpeningBalancesByBranch($filters);
        $globalOpeningBalance = $this->walletTransactionsGlobalOpeningBalance($filters);
        $summaryByBranch = $this->walletTransactionsSummaryByBranch($filters, $rows, $openingBalancesByBranch);

        return [
            'rows' => $rows,
            'filters' => $filters,
            'openingBalancesByBranch' => $openingBalancesByBranch,
            'globalOpeningBalance' => $globalOpeningBalance,
            'summaryByBranch' => $summaryByBranch,
            'filterSummary' => $this->walletTransactionsFilterSummary($filters),
            'summaryBySource' => $this->walletTransactionsSummaryBySource($filters),
            'truncated' => $truncated,
            'totalMatching' => $totalMatching,
        ];
    }

    protected function walletTransactionsWallet(array $filters): ?Wallet
    {
        if ($filters['wallet_id'] === '') {
            return null;
        }

        return Wallet::query()->find((int) $filters['wallet_id']);
    }

    /**
     * Wallet ledger balance before the report period (all branches combined).
     *
     * @param  array{wallet_id: string, date_from: string, date_to: string}  $filters
     */
    protected function walletTransactionsGlobalOpeningBalance(array $filters): float
    {
        $wallet = $this->walletTransactionsWallet($filters);
        if (! $wallet || $filters['date_from'] === '') {
            return 0.0;
        }

        $raw = JournalLine::query()
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.journal_entry_id')
            ->where('journal_lines.ledger_account_id', $wallet->ledger_account_id)
            ->whereDate('journal_entries.entry_date', '<', $filters['date_from'])
            ->tap(fn (Builder $query) => $this->walletTransactionsApplySourceFilter($query, $filters))
            ->selectRaw('COALESCE(SUM(journal_lines.debit), 0) - COALESCE(SUM(journal_lines.credit), 0) as balance')
            ->value('balance');

        return round((float) ($raw ?? 0), 2);
    }

    /**
     * @param  array{wallet_id: string, date_from: string, date_to: string}  $filters
     * @return Collection<string, float>
     */
    protected function walletTransactionsOpeningBalancesByBranch(array $filters): Collection
    {
        $wallet = $this->walletTransactionsWallet($filters);
        if (! $wallet || $filters['date_from'] === '') {
            return collect();
        }

        return JournalLine::query()
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.journal_entry_id')
            ->where('journal_lines.ledger_account_id', $wallet->ledger_account_id)
            ->whereDate('journal_entries.entry_date', '<', $filters['date_from'])
            ->tap(fn (Builder $query) => $this->walletTransactionsApplySourceFilter($query, $filters))
            ->groupBy('journal_entries.branch_id')
            ->selectRaw('journal_entries.branch_id as branch_id')
            ->selectRaw('COALESCE(SUM(journal_lines.debit), 0) - COALESCE(SUM(journal_lines.credit), 0) as balance')
            ->get()
            ->mapWithKeys(fn (object $row): array => [
                $this->walletTransactionsBranchKey($row->branch_id) => round((float) $row->balance, 2),
            ]);
    }

    /**
     * @param  array{wallet_id: string, date_from: string, date_to: string}  $filters
     * @param  Collection<int, JournalLine>  $rows
     * @param  Collection<string, float>  $openingBalancesByBranch
     * @return Collection<int, array{branch_id: ?int, branch_name: string, branch_code: string, line_count: int, debit: float, credit: float, net: float, opening_balance: float, closing_balance: float}>
     */
    protected function walletTransactionsSummaryByBranch(
        array $filters,
        Collection $rows,
        Collection $openingBalancesByBranch,
    ): Collection {
        if ($filters['wallet_id'] === '') {
            return collect();
        }

        $branchIds = $rows
            ->map(fn (JournalLine $line): ?int => $line->journalEntry?->branch_id)
            ->filter()
            ->unique()
            ->values();

        $locations = $branchIds->isEmpty()
            ? collect()
            : Location::query()->whereIn('id', $branchIds)->get(['id', 'name', 'code'])->keyBy('id');

        return $rows
            ->groupBy(fn (JournalLine $line): string => $this->walletTransactionsBranchKey($line->journalEntry?->branch_id))
            ->map(function (Collection $group, string $branchKey) use ($openingBalancesByBranch, $locations): array {
                $branchId = $branchKey === '' ? null : (int) $branchKey;
                $location = $branchId ? $locations->get($branchId) : null;
                $opening = round((float) $openingBalancesByBranch->get($branchKey, 0.0), 2);
                $debit = round($group->sum(fn (JournalLine $line): float => (float) $line->debit), 2);
                $credit = round($group->sum(fn (JournalLine $line): float => (float) $line->credit), 2);

                return [
                    'branch_id' => $branchId,
                    'branch_name' => $location?->name ?? 'Unassigned',
                    'branch_code' => $location?->code ?? '',
                    'line_count' => $group->count(),
                    'debit' => $debit,
                    'credit' => $credit,
                    'net' => round($debit - $credit, 2),
                    'opening_balance' => $opening,
                    'closing_balance' => round($opening + $debit - $credit, 2),
                ];
            })
            ->sortBy('branch_name')
            ->values();
    }

    protected function walletTransactionsBranchKey(mixed $branchId): string
    {
        if ($branchId === null || $branchId === '') {
            return '';
        }

        return (string) (int) $branchId;
    }

    /**
     * @return array{q: string, date_from: string, date_to: string, source_location_id: string, destination_location_id: string, location_id: string, movement_type: string}
     */
    public function transferLedgerFilters(Request $request): array
    {
        $from = (string) $request->input('date_from', '');
        $to = (string) $request->input('date_to', '');
        if ($from === '' && $to === '') {
            $from = now()->startOfMonth()->toDateString();
            $to = now()->toDateString();
        }

        return [
            'q' => trim((string) $request->input('q', '')),
            'date_from' => $from,
            'date_to' => $to,
            'source_location_id' => (string) $request->input('source_location_id', ''),
            'destination_location_id' => (string) $request->input('destination_location_id', ''),
            'location_id' => (string) $request->input('location_id', ''),
            'movement_type' => (string) $request->input('movement_type', ''),
        ];
    }

    /**
     * @param  array{q: string, date_from: string, date_to: string, source_location_id: string, destination_location_id: string, location_id: string, movement_type: string}  $filters
     */
    public function transferLedgerBaseQuery(array $filters): Builder
    {
        $query = StockLedger::query()
            ->join('stock_transfers', function ($join): void {
                $join->on('stock_transfers.id', '=', 'stock_ledger.reference_id')
                    ->where(function ($sub): void {
                        $sub->where('stock_ledger.reference_type', StockTransfer::LEDGER_REFERENCE_TYPE)
                            ->orWhere('stock_ledger.reference_type', StockTransfer::class);
                    });
            })
            ->join('products', 'products.id', '=', 'stock_ledger.product_id')
            ->join('locations as ledger_location', 'ledger_location.id', '=', 'stock_ledger.location_id')
            ->join('locations as source_location', 'source_location.id', '=', 'stock_transfers.source_location_id')
            ->join('locations as dest_location', 'dest_location.id', '=', 'stock_transfers.destination_location_id')
            ->leftJoin('serial_items', 'serial_items.id', '=', 'stock_ledger.serial_item_id')
            ->leftJoin('users as actors', 'actors.id', '=', 'stock_ledger.actor_user_id')
            ->select([
                'stock_ledger.id as ledger_id',
                'stock_ledger.event_time',
                'stock_ledger.movement_type',
                'stock_ledger.quantity_delta',
                'stock_ledger.location_id as ledger_location_id',
                'ledger_location.code as ledger_location_code',
                'ledger_location.name as ledger_location_name',
                'stock_transfers.id as stock_transfer_id',
                'stock_transfers.transfer_number',
                'stock_transfers.status as transfer_status',
                'source_location.id as source_location_id',
                'source_location.code as source_location_code',
                'source_location.name as source_location_name',
                'dest_location.id as destination_location_id',
                'dest_location.code as destination_location_code',
                'dest_location.name as destination_location_name',
                'products.sku as product_sku',
                'products.name as product_name',
                'serial_items.serial_number',
                'actors.name as actor_name',
            ])
            ->when($filters['q'] !== '', function ($query) use ($filters): void {
                $keyword = $filters['q'];
                $query->where(function ($subQuery) use ($keyword): void {
                    $subQuery
                        ->where('stock_transfers.transfer_number', 'like', "%{$keyword}%")
                        ->orWhere('products.sku', 'like', "%{$keyword}%")
                        ->orWhere('products.name', 'like', "%{$keyword}%")
                        ->orWhere('serial_items.serial_number', 'like', "%{$keyword}%")
                        ->orWhere('source_location.name', 'like', "%{$keyword}%")
                        ->orWhere('dest_location.name', 'like', "%{$keyword}%")
                        ->orWhere('ledger_location.name', 'like', "%{$keyword}%");
                });
            })
            ->when($filters['date_from'] !== '', fn ($query) => $query->whereDate('stock_ledger.event_time', '>=', $filters['date_from']))
            ->when($filters['date_to'] !== '', fn ($query) => $query->whereDate('stock_ledger.event_time', '<=', $filters['date_to']))
            ->when($filters['source_location_id'] !== '', fn ($query) => $query->where('stock_transfers.source_location_id', (int) $filters['source_location_id']))
            ->when($filters['destination_location_id'] !== '', fn ($query) => $query->where('stock_transfers.destination_location_id', (int) $filters['destination_location_id']))
            ->when($filters['location_id'] !== '', fn ($query) => $query->where('stock_ledger.location_id', (int) $filters['location_id']))
            ->when($filters['movement_type'] !== '', fn ($query) => $query->where('stock_ledger.movement_type', $filters['movement_type']));

        if ($filters['source_location_id'] === ''
            && $filters['destination_location_id'] === ''
            && $filters['location_id'] === '') {
            $contextId = $this->contextLocationId();
            if ($contextId !== null) {
                $query->where(function ($sub) use ($contextId): void {
                    $sub->where('stock_transfers.source_location_id', $contextId)
                        ->orWhere('stock_transfers.destination_location_id', $contextId);
                });
            } elseif ($ids = $this->accessibleLocationIds()) {
                $query->where(function ($sub) use ($ids): void {
                    $sub->whereIn('stock_transfers.source_location_id', $ids)
                        ->orWhereIn('stock_transfers.destination_location_id', $ids);
                });
            }
        }

        return $query;
    }

    /**
     * @return array{ledger_entries: int, transfers: int, units_issued: int, units_received: int}
     */
    public function transferLedgerKpis(Builder $baseQuery, int $recordsTotal): array
    {
        $forAgg = (clone $baseQuery)->toBase();

        return [
            'ledger_entries' => $recordsTotal,
            'transfers' => (int) DB::query()->fromSub($forAgg, 'kpi_rows')->selectRaw('COUNT(DISTINCT kpi_rows.stock_transfer_id) as c')->value('c'),
            'units_issued' => (int) DB::query()->fromSub($forAgg, 'kpi_issue')->where('kpi_issue.movement_type', 'transfer_issue')->selectRaw('COALESCE(SUM(ABS(kpi_issue.quantity_delta)), 0) as total')->value('total'),
            'units_received' => (int) DB::query()->fromSub($forAgg, 'kpi_receive')->where('kpi_receive.movement_type', 'transfer_receive')->selectRaw('COALESCE(SUM(kpi_receive.quantity_delta), 0) as total')->value('total'),
        ];
    }

    public function transferLedgerPaginated(Request $request, int $perPage = 50): array
    {
        $filters = $this->transferLedgerFilters($request);
        $baseQuery = $this->transferLedgerBaseQuery($filters);
        $rows = (clone $baseQuery)
            ->orderByDesc('stock_ledger.event_time')
            ->orderByDesc('stock_ledger.id')
            ->paginate($perPage)
            ->withQueryString();

        return [
            'filters' => $filters,
            'rows' => $rows,
            'kpis' => $this->transferLedgerKpis($baseQuery, $rows->total()),
            'filterLocations' => $this->stockByLocationFilterLocations(),
            'filterSummary' => $this->transferLedgerFilterSummary($filters),
        ];
    }

    /**
     * @return array{rows: Collection<int, object>, truncated: bool, filterSummary: string, totalMatching: int, kpis: array{ledger_entries: int, transfers: int, units_issued: int, units_received: int}}
     */
    public function transferLedgerForExport(Request $request): array
    {
        $filters = $this->transferLedgerFilters($request);
        $baseQuery = $this->transferLedgerBaseQuery($filters);
        $total = (clone $baseQuery)->count();
        $truncated = $total > self::STOCK_EXPORT_LIMIT;
        $rows = (clone $baseQuery)
            ->orderByDesc('stock_ledger.event_time')
            ->orderByDesc('stock_ledger.id')
            ->limit(self::STOCK_EXPORT_LIMIT)
            ->get();

        return [
            'rows' => $rows,
            'truncated' => $truncated,
            'totalMatching' => $total,
            'filterSummary' => $this->transferLedgerFilterSummary($filters),
            'kpis' => $this->transferLedgerKpis($baseQuery, $total),
        ];
    }

    /**
     * @param  array{q: string, date_from: string, date_to: string, source_location_id: string, destination_location_id: string, location_id: string, movement_type: string}  $filters
     */
    public function transferLedgerFilterSummary(array $filters): string
    {
        $parts = [];
        if ($filters['q'] !== '') {
            $parts[] = 'Search: '.$filters['q'];
        }
        if ($filters['date_from'] !== '') {
            $parts[] = 'From '.$filters['date_from'];
        }
        if ($filters['date_to'] !== '') {
            $parts[] = 'To '.$filters['date_to'];
        }
        if ($filters['source_location_id'] !== '') {
            $location = Location::query()->find((int) $filters['source_location_id']);
            $parts[] = 'Source: '.($location?->name ?? $filters['source_location_id']);
        }
        if ($filters['destination_location_id'] !== '') {
            $location = Location::query()->find((int) $filters['destination_location_id']);
            $parts[] = 'Destination: '.($location?->name ?? $filters['destination_location_id']);
        }
        if ($filters['location_id'] !== '') {
            $location = Location::query()->find((int) $filters['location_id']);
            $parts[] = 'Ledger branch: '.($location?->name ?? $filters['location_id']);
        }
        if ($filters['movement_type'] === 'transfer_issue') {
            $parts[] = 'Movement: Issue (out)';
        } elseif ($filters['movement_type'] === 'transfer_receive') {
            $parts[] = 'Movement: Receive (in)';
        }

        return $parts !== [] ? implode(' · ', $parts) : 'All transfer ledger entries';
    }

    /**
     * Users who have recorded at least one payment (collector filter options).
     *
     * @return Collection<int, User>
     */
    public function paymentCollectorOptions(): Collection
    {
        return User::query()
            ->whereIn('id', DB::table('payments')->whereNotNull('recorded_by')->select('recorded_by'))
            ->get(['id', 'name', 'full_name'])
            ->sortBy(fn ($u) => mb_strtolower($u->displayName()))
            ->values();
    }

    /** '' = everyone, 'unknown' = payments with no recorded collector, otherwise a user id. */
    protected function normalizeCollectorFilter(mixed $value): string
    {
        $value = trim((string) $value);

        return ($value === 'unknown' || ctype_digit($value)) ? $value : '';
    }

    protected function applyCollectorCondition(QueryBuilder|Builder $query, string $collectorId, string $column = 'payments.recorded_by'): void
    {
        if ($collectorId === 'unknown') {
            $query->whereNull($column);
        } elseif ($collectorId !== '') {
            $query->where($column, (int) $collectorId);
        }
    }

    protected function collectorFilterLabel(string $collectorId): ?string
    {
        if ($collectorId === '') {
            return null;
        }
        if ($collectorId === 'unknown') {
            return 'Collected by: Unknown';
        }

        return 'Collected by: '.(User::query()->whereKey((int) $collectorId)->first()?->displayName() ?? $collectorId);
    }

    /**
     * @return array{date_from: string, date_to: string, branch_id: string, collector_id: string}
     */
    public function customerPaymentByBranchFilters(Request $request): array
    {
        $from = (string) $request->input('date_from', '');
        $to = (string) $request->input('date_to', '');
        if ($from === '' && $to === '') {
            $from = now()->startOfMonth()->toDateString();
            $to = now()->toDateString();
        }

        return [
            'date_from' => $from,
            'date_to' => $to,
            'branch_id' => (string) $request->input('branch_id', ''),
            'collector_id' => $this->normalizeCollectorFilter($request->input('collector_id')),
        ];
    }

    /**
     * @param  array{date_from: string, date_to: string, branch_id: string}  $filters
     */
    protected function customerPaymentByBranchBaseQuery(array $filters): QueryBuilder
    {
        $q = DB::table('payment_allocations')
            ->join('payments', 'payments.id', '=', 'payment_allocations.payment_id')
            ->join('sales_invoices', 'sales_invoices.id', '=', 'payment_allocations.sales_invoice_id')
            ->join('customers', 'customers.id', '=', 'payments.customer_id')
            ->join('locations', 'locations.id', '=', 'sales_invoices.branch_id')
            ->leftJoin('payment_methods', 'payment_methods.id', '=', 'payments.payment_method_id')
            ->leftJoin('users', 'users.id', '=', 'payments.recorded_by')
            ->leftJoin('employees as payment_referrers', 'payment_referrers.id', '=', 'payments.referrer_employee_id')
            ->where('payments.direction', 'inbound');

        $branchOnly = ['q' => '', 'branch_id' => $filters['branch_id']];
        $this->applyInvoiceOutstandingReportBranchFilter($q, $branchOnly);
        $this->applyCollectorCondition($q, $filters['collector_id'] ?? '');

        if ($filters['date_from'] !== '') {
            $q->whereDate('payments.payment_date', '>=', $filters['date_from']);
        }
        if ($filters['date_to'] !== '') {
            $q->whereDate('payments.payment_date', '<=', $filters['date_to']);
        }

        return $q
            ->select([
                'payment_allocations.id as allocation_id',
                'payments.id as payment_id',
                'payments.payment_date',
                'payments.payment_number',
                'payments.amount as payment_amount',
                'payments.reference_no',
                DB::raw('COALESCE(payment_methods.name, payments.payment_method) as payment_method_label'),
                'customers.customer_code',
                'customers.name as customer_name',
                'sales_invoices.branch_id',
                'locations.code as branch_code',
                'locations.name as branch_name',
                'sales_invoices.invoice_number',
                'payment_allocations.allocated_amount',
                'payment_allocations.settlement_discount',
                'payments.recorded_by',
                DB::raw("COALESCE(NULLIF(users.full_name, ''), users.name) as recorded_by_name"),
                'payments.referrer_employee_id',
                'payment_referrers.employee_code as payment_referrer_code',
                'payment_referrers.first_name as payment_referrer_first_name',
                'payment_referrers.last_name as payment_referrer_last_name',
            ])
            ->orderBy('locations.name')
            ->orderByDesc('payments.payment_date')
            ->orderBy('payments.payment_number')
            ->orderBy('sales_invoices.invoice_number');
    }

    /**
     * @param  array{date_from: string, date_to: string, branch_id: string}  $filters
     * @return array{line_count: int, payment_count: int, total_allocated: float, total_discount: float}
     */
    protected function customerPaymentByBranchKpis(array $filters): array
    {
        $base = $this->customerPaymentByBranchBaseQuery($filters);

        $agg = DB::query()
            ->fromSub($base, 't')
            ->selectRaw('
                COUNT(*) as line_count,
                COUNT(DISTINCT t.payment_id) as payment_count,
                COALESCE(SUM(t.allocated_amount), 0) as total_allocated,
                COALESCE(SUM(t.settlement_discount), 0) as total_discount
            ')
            ->first();

        return [
            'line_count' => (int) ($agg->line_count ?? 0),
            'payment_count' => (int) ($agg->payment_count ?? 0),
            'total_allocated' => round((float) ($agg->total_allocated ?? 0), 2),
            'total_discount' => round((float) ($agg->total_discount ?? 0), 2),
        ];
    }

    /**
     * @param  array{date_from: string, date_to: string, branch_id: string}  $filters
     */
    public function customerPaymentByBranchFilterSummary(array $filters): string
    {
        $parts = ['Customer payments by branch'];
        if ($filters['date_from'] !== '' || $filters['date_to'] !== '') {
            $range = trim(
                ($filters['date_from'] !== '' ? 'From '.$filters['date_from'] : '')
                .($filters['date_from'] !== '' && $filters['date_to'] !== '' ? ' · ' : '')
                .($filters['date_to'] !== '' ? 'To '.$filters['date_to'] : '')
            );
            if ($range !== '') {
                $parts[] = $range;
            }
        }
        if ($filters['branch_id'] !== '') {
            $b = Location::query()->whereKey((int) $filters['branch_id'])->first();
            $parts[] = 'Branch: '.($b ? $b->name : $filters['branch_id']);
        }

        return implode(' · ', $parts);
    }

    /**
     * @return array{filters: array{date_from: string, date_to: string, branch_id: string}, rows: LengthAwarePaginator<object>, kpis: array{line_count: int, payment_count: int, total_allocated: float, total_discount: float}, filterBranches: Collection<int, Location>}
     */
    public function customerPaymentByBranchPaginated(Request $request, int $perPage = 50): array
    {
        $filters = $this->customerPaymentByBranchFilters($request);
        $base = $this->customerPaymentByBranchBaseQuery($filters);
        $rows = (clone $base)->paginate($perPage)->withQueryString();

        return [
            'filters' => $filters,
            'rows' => $rows,
            'kpis' => $this->customerPaymentByBranchKpis($filters),
            'filterBranches' => $this->salesSummaryFilterBranches(),
            'collectors' => $this->paymentCollectorOptions(),
            'collectorTotals' => $this->customerPaymentByBranchCollectorTotals($filters),
        ];
    }

    /**
     * @return array{rows: Collection<int, object>, filterSummary: string, kpis: array{line_count: int, payment_count: int, total_allocated: float, total_discount: float}}
     */
    public function customerPaymentByBranchForExport(Request $request): array
    {
        $filters = $this->customerPaymentByBranchFilters($request);
        $rows = (clone $this->customerPaymentByBranchBaseQuery($filters))
            ->limit(self::STOCK_EXPORT_LIMIT)
            ->get();

        return [
            'rows' => $rows,
            'filterSummary' => $this->customerPaymentByBranchFilterSummary($filters),
            'kpis' => $this->customerPaymentByBranchKpis($filters),
            'collectorTotals' => $this->customerPaymentByBranchCollectorTotals($filters),
        ];
    }

    /**
     * Same date defaults as {@see salesByDayBranchFilters}; filters manual operating expense postings.
     *
     * @return array{date_from: string, date_to: string, branch_id: string, ledger_account_id: string}
     */
    public function dailyExpenseByBranchFilters(Request $request): array
    {
        return array_merge($this->salesByDayBranchFilters($request), [
            'ledger_account_id' => (string) $request->input('ledger_account_id', ''),
        ]);
    }

    /**
     * @return Collection<int, LedgerAccount>
     */
    public function dailyExpenseByBranchFilterExpenseAccounts(): Collection
    {
        return LedgerAccount::query()
            ->where('type', 'expense')
            ->where('is_active', true)
            ->orderBy('code')
            ->get(['id', 'code', 'name']);
    }

    /**
     * Posted manual expense journal lines: debit rows on expense ledger accounts.
     *
     * @param  array{date_from: string, date_to: string, branch_id: string, ledger_account_id: string}  $filters
     */
    protected function manualExpenseJournalLinesBaseQuery(array $filters): QueryBuilder
    {
        $q = DB::table('journal_lines')
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.journal_entry_id')
            ->join('ledger_accounts', 'ledger_accounts.id', '=', 'journal_lines.ledger_account_id')
            ->where('journal_entries.source', 'manual_expense')
            ->where('journal_entries.status', 'posted')
            ->where('ledger_accounts.type', 'expense')
            ->where('journal_lines.debit', '>', 0);

        $branchOnly = ['branch_id' => $filters['branch_id']];
        $this->applyManualExpenseReportBranchFilter($q, $branchOnly);

        if ($filters['date_from'] !== '') {
            $q->whereDate('journal_entries.entry_date', '>=', $filters['date_from']);
        }
        if ($filters['date_to'] !== '') {
            $q->whereDate('journal_entries.entry_date', '<=', $filters['date_to']);
        }
        if ($filters['ledger_account_id'] !== '') {
            $q->where('journal_lines.ledger_account_id', (int) $filters['ledger_account_id']);
        }

        return $q;
    }

    /**
     * One row per calendar day × branch × expense account.
     *
     * @param  array{date_from: string, date_to: string, branch_id: string, ledger_account_id: string}  $filters
     */
    protected function dailyExpenseByBranchGroupedSubquery(array $filters): QueryBuilder
    {
        return $this->manualExpenseJournalLinesBaseQuery($filters)
            ->select([
                'journal_entries.entry_date',
                'journal_entries.branch_id',
                'journal_lines.ledger_account_id',
                'ledger_accounts.code as account_code',
                'ledger_accounts.name as account_name',
            ])
            ->selectRaw('SUM(journal_lines.debit) as amount')
            ->selectRaw('COUNT(DISTINCT journal_entries.id) as slice_entry_count')
            ->groupBy(
                'journal_entries.entry_date',
                'journal_entries.branch_id',
                'journal_lines.ledger_account_id',
                'ledger_accounts.code',
                'ledger_accounts.name',
            );
    }

    /**
     * @param  array{date_from: string, date_to: string, branch_id: string, ledger_account_id: string}  $filters
     */
    public function dailyExpenseByBranchBaseQuery(array $filters): QueryBuilder
    {
        $inner = $this->dailyExpenseByBranchGroupedSubquery($filters);

        return DB::query()
            ->fromSub($inner, 'agg')
            ->join('locations', 'locations.id', '=', 'agg.branch_id')
            ->select([
                'agg.entry_date',
                'agg.branch_id',
                'agg.ledger_account_id',
                'agg.account_code',
                'agg.account_name',
                'agg.amount',
                'agg.slice_entry_count',
                'locations.code as branch_code',
                'locations.name as branch_name',
            ])
            ->orderBy('locations.name')
            ->orderByDesc('agg.entry_date')
            ->orderBy('agg.account_name');
    }

    /**
     * @param  array{date_from: string, date_to: string, branch_id: string, ledger_account_id: string}  $filters
     */
    public function dailyExpenseByBranchFilterSummary(array $filters): string
    {
        $parts = ['Daily operating expenses by branch (posted manual expense journals)'];
        if ($filters['date_from'] !== '' || $filters['date_to'] !== '') {
            $range = trim(
                ($filters['date_from'] !== '' ? 'From '.$filters['date_from'] : '')
                .($filters['date_from'] !== '' && $filters['date_to'] !== '' ? ' · ' : '')
                .($filters['date_to'] !== '' ? 'To '.$filters['date_to'] : '')
            );
            if ($range !== '') {
                $parts[] = $range;
            }
        }
        if ($filters['branch_id'] !== '') {
            $b = Location::query()->whereKey((int) $filters['branch_id'])->first();
            $parts[] = 'Branch: '.($b ? $b->name : $filters['branch_id']);
        }
        if ($filters['ledger_account_id'] !== '') {
            $account = LedgerAccount::query()->whereKey((int) $filters['ledger_account_id'])->first();
            $parts[] = 'Expense account: '.($account ? $account->code.' — '.$account->name : $filters['ledger_account_id']);
        }

        return implode(' · ', $parts);
    }

    /**
     * @param  array{date_from: string, date_to: string, branch_id: string, ledger_account_id: string}  $filters
     * @return array{detail_rows: int, distinct_entries: int, total_amount: float}
     */
    protected function dailyExpenseByBranchKpis(array $filters): array
    {
        $linesBase = $this->manualExpenseJournalLinesBaseQuery($filters);
        $grouped = $this->dailyExpenseByBranchGroupedSubquery($filters);

        $fromLines = (clone $linesBase)
            ->selectRaw('COUNT(DISTINCT journal_entries.id) as distinct_entries')
            ->selectRaw('COALESCE(SUM(journal_lines.debit), 0) as total_amount')
            ->first();

        return [
            'detail_rows' => (int) DB::query()->fromSub((clone $grouped), 'k')->count(),
            'distinct_entries' => (int) ($fromLines->distinct_entries ?? 0),
            'total_amount' => round((float) ($fromLines->total_amount ?? 0), 2),
        ];
    }

    /**
     * Sum of {@see dailyExpenseByBranchGroupedSubquery} amounts (matches detail grid total).
     *
     * @param  array{date_from: string, date_to: string, branch_id: string, ledger_account_id: string}  $filters
     * @return array{amount: float}
     */
    protected function dailyExpenseByBranchDetailAmountTotal(array $filters): array
    {
        $grouped = $this->dailyExpenseByBranchGroupedSubquery($filters);
        $row = DB::query()
            ->fromSub((clone $grouped), 'g')
            ->selectRaw('COALESCE(SUM(g.amount), 0) as amount')
            ->first();

        return [
            'amount' => round((float) ($row->amount ?? 0), 2),
        ];
    }

    /**
     * @return array{filters: array{date_from: string, date_to: string, branch_id: string, ledger_account_id: string}, rows: LengthAwarePaginator<object>, kpis: array{detail_rows: int, distinct_entries: int, total_amount: float}, detailTotals: array{amount: float}, filterBranches: Collection<int, Location>, filterExpenseAccounts: Collection<int, LedgerAccount>}
     */
    public function dailyExpenseByBranchPaginated(Request $request, int $perPage = 50): array
    {
        $filters = $this->dailyExpenseByBranchFilters($request);
        $base = $this->dailyExpenseByBranchBaseQuery($filters);
        $rows = (clone $base)->paginate($perPage)->withQueryString();

        return [
            'filters' => $filters,
            'rows' => $rows,
            'kpis' => $this->dailyExpenseByBranchKpis($filters),
            'detailTotals' => $this->dailyExpenseByBranchDetailAmountTotal($filters),
            'filterBranches' => $this->salesSummaryFilterBranches(),
            'filterExpenseAccounts' => $this->dailyExpenseByBranchFilterExpenseAccounts(),
        ];
    }

    /**
     * @return array{rows: Collection<int, object>, filterSummary: string, kpis: array{detail_rows: int, distinct_entries: int, total_amount: float}, detailTotals: array{amount: float}}
     */
    public function dailyExpenseByBranchForExport(Request $request): array
    {
        $filters = $this->dailyExpenseByBranchFilters($request);
        $rows = (clone $this->dailyExpenseByBranchBaseQuery($filters))
            ->limit(self::STOCK_EXPORT_LIMIT)
            ->get();

        return [
            'rows' => $rows,
            'filterSummary' => $this->dailyExpenseByBranchFilterSummary($filters),
            'kpis' => $this->dailyExpenseByBranchKpis($filters),
            'detailTotals' => $this->dailyExpenseByBranchDetailAmountTotal($filters),
        ];
    }

    /**
     * @return array{date_from: string, date_to: string, branch_id: string}
     */
    public function profitLossSheetFilters(Request $request): array
    {
        return $this->salesByDayBranchFilters($request);
    }

    /**
     * @return array{date_from: string, date_to: string, branch_id: string}
     */
    public function incomeExpenditureStatementFilters(Request $request): array
    {
        return $this->salesByDayBranchFilters($request);
    }

    /**
     * Branch filter for financial statements: empty branch_id = all accessible branches (ignores header context).
     *
     * @param  array{branch_id: string}  $filters
     */
    protected function applyFinancialReportBranchFilter(QueryBuilder $q, array $filters, string $branchColumn): void
    {
        if ($filters['branch_id'] !== '') {
            $bid = (int) $filters['branch_id'];
            if (! $this->customerDuesBranchFilterAllowed($bid)) {
                $q->whereRaw('1 = 0');

                return;
            }
            $q->where($branchColumn, $bid);

            return;
        }

        $user = auth()->user();
        if (! $user || $user->isAdmin() || $user->hasPermission('location.view-all')) {
            return;
        }

        $ids = $user->accessibleLocationIds();
        if ($ids === []) {
            $q->whereRaw('1 = 0');
        } else {
            $q->whereIn($branchColumn, $ids);
        }
    }

    /**
     * @param  array{date_from: string, date_to: string, branch_id: string}  $filters
     */
    public function financialReportBranchLabel(array $filters): string
    {
        if ($filters['branch_id'] !== '') {
            $branch = Location::query()->whereKey((int) $filters['branch_id'])->first();

            return $branch
                ? trim(($branch->code ? $branch->code.', ' : '').$branch->name)
                : 'Branch '.$filters['branch_id'];
        }

        return 'All branches';
    }

    /**
     * @param  array{date_from: string, date_to: string, branch_id: string}  $filters
     */
    protected function profitLossSheetDateRangeLabel(array $filters): string
    {
        $from = $filters['date_from'] !== '' ? Carbon::parse($filters['date_from'])->format('d-m-Y') : '—';
        $to = $filters['date_to'] !== '' ? Carbon::parse($filters['date_to'])->format('d-m-Y') : '—';

        return 'From: '.$from.' to '.$to;
    }

    /**
     * @param  array{date_from: string, date_to: string, branch_id: string}  $filters
     */
    protected function applyFinancialReportDateFilter(QueryBuilder $q, array $filters, string $dateColumn): void
    {
        if ($filters['date_from'] !== '') {
            $q->whereDate($dateColumn, '>=', $filters['date_from']);
        }
        if ($filters['date_to'] !== '') {
            $q->whereDate($dateColumn, '<=', $filters['date_to']);
        }
    }

    /**
     * @param  array{date_from: string, date_to: string, branch_id: string}  $filters
     */
    protected function profitLossSheetSalesTotal(array $filters): float
    {
        $q = DB::table('sales_invoices')
            ->whereIn('sales_invoices.status', ['posted', 'active']);

        $this->applyFinancialReportBranchFilter($q, $filters, 'sales_invoices.branch_id');
        $this->applyFinancialReportDateFilter($q, $filters, 'sales_invoices.invoice_date');

        return (float) $q->sum('sales_invoices.grand_total');
    }

    /**
     * @param  array{date_from: string, date_to: string, branch_id: string}  $filters
     */
    protected function profitLossSheetCogsTotal(array $filters): float
    {
        $q = DB::table('stock_ledger')
            ->join('sales_invoices', function ($join): void {
                $join->on('sales_invoices.id', '=', 'stock_ledger.reference_id')
                    ->where(function ($sub): void {
                        $sub->where('stock_ledger.reference_type', 'SalesInvoice')
                            ->orWhere('stock_ledger.reference_type', SalesInvoice::class);
                    });
            })
            ->join('products', 'products.id', '=', 'stock_ledger.product_id')
            ->where('stock_ledger.movement_type', 'sale_issue')
            ->whereIn('sales_invoices.status', ['posted', 'active']);

        $this->applyFinancialReportBranchFilter($q, $filters, 'sales_invoices.branch_id');
        $this->applyFinancialReportDateFilter($q, $filters, 'sales_invoices.invoice_date');

        return (float) $q->selectRaw(
            'COALESCE(SUM(ABS(stock_ledger.quantity_delta) * COALESCE(stock_ledger.unit_cost, products.default_purchase_price, 0)), 0) as cogs'
        )->value('cogs');
    }

    /**
     * @param  array{date_from: string, date_to: string, branch_id: string}  $filters
     * @return Collection<int, object{account_code: string, account_name: string, amount: float}>
     */
    protected function profitLossSheetExpenseRows(array $filters): Collection
    {
        $q = DB::table('journal_lines')
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.journal_entry_id')
            ->join('ledger_accounts', 'ledger_accounts.id', '=', 'journal_lines.ledger_account_id')
            ->where('journal_entries.source', 'manual_expense')
            ->where('journal_entries.status', 'posted')
            ->where('ledger_accounts.type', 'expense')
            ->where('journal_lines.debit', '>', 0);

        $this->applyFinancialReportBranchFilter($q, $filters, 'journal_entries.branch_id');
        $this->applyFinancialReportDateFilter($q, $filters, 'journal_entries.entry_date');

        return $q
            ->select([
                'ledger_accounts.code as account_code',
                'ledger_accounts.name as account_name',
            ])
            ->selectRaw('ROUND(SUM(journal_lines.debit), 2) as amount')
            ->groupBy('ledger_accounts.id', 'ledger_accounts.code', 'ledger_accounts.name')
            ->orderBy('ledger_accounts.code')
            ->get();
    }

    /**
     * @param  array{date_from: string, date_to: string, branch_id: string}  $filters
     */
    public function profitLossSheetFilterSummary(array $filters): string
    {
        $parts = [
            $this->profitLossSheetDateRangeLabel($filters),
            'Branch: '.$this->financialReportBranchLabel($filters),
        ];

        return implode(' · ', $parts);
    }

    /**
     * @return array{
     *     filters: array{date_from: string, date_to: string, branch_id: string},
     *     filterSummary: string,
     *     branchLabel: string,
     *     dateRangeLabel: string,
     *     sales: float,
     *     cogs: float,
     *     grossProfit: float,
     *     expenses: Collection<int, object>,
     *     expenseTotal: float,
     *     netProfit: float,
     *     filterBranches: Collection<int, Location>
     * }
     */
    public function profitLossSheetForExport(Request $request): array
    {
        $filters = $this->profitLossSheetFilters($request);
        $sales = round($this->profitLossSheetSalesTotal($filters), 2);
        $cogs = round($this->profitLossSheetCogsTotal($filters), 2);
        $expenses = $this->profitLossSheetExpenseRows($filters);
        $expenseTotal = round((float) $expenses->sum(fn ($row) => (float) $row->amount), 2);
        $grossProfit = round($sales - $cogs, 2);
        $netProfit = round($grossProfit - $expenseTotal, 2);

        return [
            'filters' => $filters,
            'filterSummary' => $this->profitLossSheetFilterSummary($filters),
            'branchLabel' => $this->financialReportBranchLabel($filters),
            'dateRangeLabel' => $this->profitLossSheetDateRangeLabel($filters),
            'sales' => $sales,
            'cogs' => $cogs,
            'grossProfit' => $grossProfit,
            'expenses' => $expenses,
            'expenseTotal' => $expenseTotal,
            'netProfit' => $netProfit,
            'filterBranches' => $this->salesSummaryFilterBranches(),
        ];
    }

    /**
     * @param  array{date_from: string, date_to: string, branch_id: string}  $filters
     */
    protected function incomeExpenditureStatementPeriodBounds(array $filters): array
    {
        $to = $filters['date_to'] !== ''
            ? Carbon::parse($filters['date_to'])->toDateString()
            : now()->toDateString();
        $from = $filters['date_from'] !== ''
            ? Carbon::parse($filters['date_from'])->toDateString()
            : Carbon::parse($to)->startOfMonth()->toDateString();
        $yearFrom = Carbon::parse($to)->startOfYear()->toDateString();

        return compact('from', 'to', 'yearFrom');
    }

    /**
     * @param  array{date_from: string, date_to: string, branch_id: string}  $filters
     */
    protected function incomeExpenditureStatementAccountRows(array $filters, string $accountType, string $amountColumn): Collection
    {
        $bounds = $this->incomeExpenditureStatementPeriodBounds($filters);

        $q = DB::table('journal_lines')
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.journal_entry_id')
            ->join('ledger_accounts', 'ledger_accounts.id', '=', 'journal_lines.ledger_account_id')
            ->where('journal_entries.status', 'posted')
            ->where('ledger_accounts.type', $accountType)
            ->where('journal_lines.'.$amountColumn, '>', 0);

        $this->applyFinancialReportBranchFilter($q, $filters, 'journal_entries.branch_id');

        return $q
            ->select([
                'ledger_accounts.id as ledger_account_id',
                'ledger_accounts.code as account_code',
                'ledger_accounts.name as account_name',
            ])
            ->selectRaw(
                'ROUND(SUM(CASE WHEN journal_entries.entry_date BETWEEN ? AND ? THEN journal_lines.'.$amountColumn.' ELSE 0 END), 2) as month_amount',
                [$bounds['from'], $bounds['to']]
            )
            ->selectRaw(
                'ROUND(SUM(CASE WHEN journal_entries.entry_date BETWEEN ? AND ? THEN journal_lines.'.$amountColumn.' ELSE 0 END), 2) as year_amount',
                [$bounds['yearFrom'], $bounds['to']]
            )
            ->groupBy('ledger_accounts.id', 'ledger_accounts.code', 'ledger_accounts.name')
            ->havingRaw('month_amount > 0 OR year_amount > 0')
            ->orderBy('ledger_accounts.code')
            ->get();
    }

    /**
     * @param  array{date_from: string, date_to: string, branch_id: string}  $filters
     */
    public function incomeExpenditureStatementFilterSummary(array $filters): string
    {
        $bounds = $this->incomeExpenditureStatementPeriodBounds($filters);

        return 'Period: '.Carbon::parse($bounds['from'])->format('d-M-Y')
            .' to '.Carbon::parse($bounds['to'])->format('d-M-Y')
            .' · Branch: '.$this->financialReportBranchLabel($filters);
    }

    /**
     * @return array{
     *     filters: array{date_from: string, date_to: string, branch_id: string},
     *     filterSummary: string,
     *     branchLabel: string,
     *     periodFrom: string,
     *     periodTo: string,
     *     yearFrom: string,
     *     expenditureRows: Collection<int, object>,
     *     incomeRows: Collection<int, object>,
     *     expenditureMonthTotal: float,
     *     expenditureYearTotal: float,
     *     incomeMonthTotal: float,
     *     incomeYearTotal: float,
     *     filterBranches: Collection<int, Location>
     * }
     */
    public function incomeExpenditureStatementForExport(Request $request): array
    {
        $filters = $this->incomeExpenditureStatementFilters($request);
        $bounds = $this->incomeExpenditureStatementPeriodBounds($filters);
        $expenditureRows = $this->incomeExpenditureStatementAccountRows($filters, 'expense', 'debit');
        $incomeRows = $this->incomeExpenditureStatementAccountRows($filters, 'income', 'credit');

        return [
            'filters' => $filters,
            'filterSummary' => $this->incomeExpenditureStatementFilterSummary($filters),
            'branchLabel' => $this->financialReportBranchLabel($filters),
            'periodFrom' => $bounds['from'],
            'periodTo' => $bounds['to'],
            'yearFrom' => $bounds['yearFrom'],
            'expenditureRows' => $expenditureRows,
            'incomeRows' => $incomeRows,
            'expenditureMonthTotal' => round((float) $expenditureRows->sum(fn ($row) => (float) $row->month_amount), 2),
            'expenditureYearTotal' => round((float) $expenditureRows->sum(fn ($row) => (float) $row->year_amount), 2),
            'incomeMonthTotal' => round((float) $incomeRows->sum(fn ($row) => (float) $row->month_amount), 2),
            'incomeYearTotal' => round((float) $incomeRows->sum(fn ($row) => (float) $row->year_amount), 2),
            'filterBranches' => $this->salesSummaryFilterBranches(),
        ];
    }
}
