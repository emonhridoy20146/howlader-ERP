<?php

namespace App\Services;

use App\Models\BranchAnnualTarget;
use App\Models\Location;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

final class BranchAnnualTargetProgressService
{
    /**
     * @return array{
     *     target: BranchAnnualTarget|null,
     *     year: int,
     *     sales: array{target: float, actual: float, pct: float|null, remaining: float},
     *     collection: array{target: float, actual: float, pct: float|null, remaining: float}
     * }
     */
    public function forLocation(Location $location, ?int $year = null): array
    {
        $year = $year ?? (int) now()->year;
        $target = BranchAnnualTarget::query()
            ->where('location_id', $location->id)
            ->where('year', $year)
            ->first();

        $actuals = $this->actualsForLocations([(int) $location->id], $year);
        $row = $actuals[(int) $location->id] ?? ['sales' => 0.0, 'collection' => 0.0];

        return [
            'target' => $target,
            'year' => $year,
            'sales' => $this->metricProgress(
                (float) ($target?->sales_target ?? 0),
                $row['sales'],
            ),
            'collection' => $this->metricProgress(
                (float) ($target?->collection_target ?? 0),
                $row['collection'],
            ),
        ];
    }

    /**
     * @param  Collection<int, Location>|list<int>  $locationIds
     * @return array<int, array{
     *     year: int,
     *     has_target: bool,
     *     sales: array{target: float, actual: float, pct: float|null},
     *     collection: array{target: float, actual: float, pct: float|null}
     * }>
     */
    public function forLocationIds(array|Collection $locationIds, ?int $year = null): array
    {
        $ids = collect($locationIds)
            ->map(fn ($item) => $item instanceof Location ? (int) $item->id : (int) $item)
            ->filter()
            ->unique()
            ->values()
            ->all();

        if ($ids === []) {
            return [];
        }

        $year = $year ?? (int) now()->year;

        $targets = BranchAnnualTarget::query()
            ->whereIn('location_id', $ids)
            ->where('year', $year)
            ->get()
            ->keyBy('location_id');

        $actuals = $this->actualsForLocations($ids, $year);

        $out = [];
        foreach ($ids as $id) {
            $target = $targets->get($id);
            $actual = $actuals[$id] ?? ['sales' => 0.0, 'collection' => 0.0];
            $hasTarget = $target && (
                (float) $target->sales_target > 0
                || (float) $target->collection_target > 0
            );

            $out[$id] = [
                'year' => $year,
                'has_target' => $hasTarget,
                'sales' => $this->metricProgress((float) ($target?->sales_target ?? 0), $actual['sales']),
                'collection' => $this->metricProgress((float) ($target?->collection_target ?? 0), $actual['collection']),
            ];
        }

        return $out;
    }

    /**
     * @return list<BranchAnnualTarget>
     */
    public function historyForLocation(Location $location): array
    {
        return BranchAnnualTarget::query()
            ->where('location_id', $location->id)
            ->orderByDesc('year')
            ->get()
            ->all();
    }

    /**
     * @param  list<int>  $locationIds
     * @return array<int, array{sales: float, collection: float}>
     */
    public function actualsForLocations(array $locationIds, int $year): array
    {
        if ($locationIds === []) {
            return [];
        }

        $start = Carbon::create($year, 1, 1)->startOfDay()->toDateString();
        $end = Carbon::create($year, 12, 31)->endOfDay()->toDateString();

        $salesRows = DB::table('sales_invoices')
            ->whereIn('branch_id', $locationIds)
            ->whereIn('status', ['posted', 'active'])
            ->whereBetween('invoice_date', [$start, $end])
            ->groupBy('branch_id')
            ->selectRaw('branch_id, COALESCE(SUM(grand_total), 0) as total')
            ->get();

        $collectionRows = DB::table('payment_allocations')
            ->join('payments', 'payments.id', '=', 'payment_allocations.payment_id')
            ->join('sales_invoices', 'sales_invoices.id', '=', 'payment_allocations.sales_invoice_id')
            ->whereIn('sales_invoices.branch_id', $locationIds)
            ->where('payments.direction', 'inbound')
            ->whereBetween('payments.payment_date', [$start, $end])
            ->groupBy('sales_invoices.branch_id')
            ->selectRaw('sales_invoices.branch_id as branch_id, COALESCE(SUM(payment_allocations.allocated_amount), 0) as total')
            ->get();

        $out = [];
        foreach ($locationIds as $id) {
            $out[$id] = ['sales' => 0.0, 'collection' => 0.0];
        }

        foreach ($salesRows as $row) {
            $out[(int) $row->branch_id]['sales'] = round((float) $row->total, 2);
        }

        foreach ($collectionRows as $row) {
            $out[(int) $row->branch_id]['collection'] = round((float) $row->total, 2);
        }

        return $out;
    }

    /**
     * @return array{target: float, actual: float, pct: float|null, remaining: float}
     */
    private function metricProgress(float $target, float $actual): array
    {
        $actual = round($actual, 2);
        $target = round($target, 2);

        if ($target <= 0) {
            return [
                'target' => $target,
                'actual' => $actual,
                'pct' => null,
                'remaining' => 0.0,
            ];
        }

        $pct = min(999.99, round(($actual / $target) * 100, 2));
        $remaining = max(0.0, round($target - $actual, 2));

        return [
            'target' => $target,
            'actual' => $actual,
            'pct' => $pct,
            'remaining' => $remaining,
        ];
    }
}
