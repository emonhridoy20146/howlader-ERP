<?php

namespace App\Services\Reports;

use App\Http\Controllers\Concerns\AppliesInventoryLocationContext;
use App\Models\Location;
use App\Models\PurchaseOrderLine;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class PurchaseReportService
{
    use AppliesInventoryLocationContext;

    public const EXPORT_LIMIT = 10000;

    /**
     * @return array{q: string, date_from: string, date_to: string, location_id: string}
     */
    public function itemWisePurchaseFilters(Request $request): array
    {
        return [
            'q' => trim((string) $request->input('q', '')),
            'date_from' => (string) $request->input('date_from', ''),
            'date_to' => (string) $request->input('date_to', ''),
            'location_id' => (string) $request->input('location_id', ''),
        ];
    }

    public function itemWisePurchaseBaseQuery(array $filters): Builder
    {
        return PurchaseOrderLine::query()
            ->join('purchase_orders', 'purchase_orders.id', '=', 'purchase_order_lines.purchase_order_id')
            ->join('products', 'products.id', '=', 'purchase_order_lines.product_id')
            ->whereIn('purchase_orders.status', ['approved', 'partially_received', 'received'])
            ->select([
                'purchase_order_lines.product_id',
                'products.sku',
                'products.name',
                DB::raw('SUM(purchase_order_lines.qty_ordered) as total_qty_ordered'),
                DB::raw('SUM(purchase_order_lines.qty_received) as total_qty_received'),
                DB::raw('SUM(purchase_order_lines.line_total) as total_amount'),
            ])
            ->when($filters['location_id'] !== '', fn ($query) => $query->where('purchase_orders.destination_location_id', (int) $filters['location_id']))
            ->when($filters['location_id'] === '', function ($query) {
                $id = $this->contextLocationId();
                if ($id !== null) {
                    $query->where('purchase_orders.destination_location_id', $id);

                    return;
                }
                $user = auth()->user();
                if ($user && ! $user->isAdmin() && ! $user->hasPermission('location.view-all')) {
                    $ids = $user->accessibleLocationIds();
                    if ($ids !== []) {
                        $query->whereIn('purchase_orders.destination_location_id', $ids);
                    }
                }
            })
            ->when($filters['q'] !== '', function ($query) use ($filters): void {
                $keyword = $filters['q'];
                $query->where(function ($subQuery) use ($keyword): void {
                    $subQuery->where('products.sku', 'like', "%{$keyword}%")
                        ->orWhere('products.name', 'like', "%{$keyword}%");
                });
            })
            ->when($filters['date_from'] !== '', fn ($query) => $query->whereDate('purchase_orders.ordered_at', '>=', $filters['date_from']))
            ->when($filters['date_to'] !== '', fn ($query) => $query->whereDate('purchase_orders.ordered_at', '<=', $filters['date_to']))
            ->groupBy('purchase_order_lines.product_id', 'products.sku', 'products.name');
    }

    public function itemWisePurchaseKpis(Builder $baseQuery): array
    {
        $kpiQuery = DB::table(DB::raw("({$baseQuery->toSql()}) as sub"))
            ->mergeBindings($baseQuery->getQuery());

        return [
            'total_items' => $kpiQuery->count(),
            'total_qty_ordered' => (float) $kpiQuery->sum('total_qty_ordered'),
            'total_qty_received' => (float) $kpiQuery->sum('total_qty_received'),
            'total_amount' => (float) $kpiQuery->sum('total_amount'),
        ];
    }

    public function filterLocations(): Collection
    {
        $filterLocationsQuery = Location::query()->where('is_active', true)->orderBy('name');
        $user = auth()->user();
        if ($user && ! $user->isAdmin() && ! $user->hasPermission('location.view-all')) {
            $filterLocationsQuery->whereIn('id', $user->accessibleLocationIds());
        }

        return $filterLocationsQuery->get(['id', 'code', 'name']);
    }

    public function itemWisePurchasePaginated(Request $request, int $perPage = 50): array
    {
        $filters = $this->itemWisePurchaseFilters($request);
        $baseQuery = $this->itemWisePurchaseBaseQuery($filters);

        $kpis = $this->itemWisePurchaseKpis(clone $baseQuery);

        $rows = (clone $baseQuery)
            ->orderByDesc('total_qty_ordered')
            ->orderBy('products.name')
            ->paginate($perPage)->withQueryString();

        return [
            'filters' => $filters,
            'rows' => $rows,
            'kpis' => $kpis,
            'filterLocations' => $this->filterLocations(),
        ];
    }

    /**
     * @return array{rows: Collection<int, object>, filterSummary: string}
     */
    public function itemWisePurchaseForExport(Request $request): array
    {
        $filters = $this->itemWisePurchaseFilters($request);
        $baseQuery = $this->itemWisePurchaseBaseQuery($filters);
        $rows = (clone $baseQuery)
            ->orderByDesc('total_qty_ordered')
            ->orderBy('products.name')
            ->limit(self::EXPORT_LIMIT)
            ->get();

        return [
            'rows' => $rows,
            'filterSummary' => $this->itemWisePurchaseFilterSummary($filters),
        ];
    }

    /**
     * @param  array{q: string, date_from: string, date_to: string, location_id: string}  $filters
     */
    public function itemWisePurchaseFilterSummary(array $filters): string
    {
        $parts = [];
        if ($filters['q'] !== '') {
            $parts[] = 'Search: '.$filters['q'];
        }
        if ($filters['location_id'] !== '') {
            $b = Location::query()->whereKey((int) $filters['location_id'])->first();
            $parts[] = 'Location: '.($b ? $b->name : $filters['location_id']);
        }
        if ($filters['date_from'] !== '') {
            $parts[] = 'From '.$filters['date_from'];
        }
        if ($filters['date_to'] !== '') {
            $parts[] = 'To '.$filters['date_to'];
        }

        return $parts !== [] ? implode(' · ', $parts) : 'All filters';
    }
}
