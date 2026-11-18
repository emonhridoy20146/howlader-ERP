<?php

namespace App\Services;

use App\Models\BranchMonthlyTarget;
use App\Models\Location;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

final class BranchMonthlyTargetProgressService
{
    /**
     * @return array{
     *     year: int,
     *     months: array<int, array{
     *         month: int,
     *         label: string,
     *         is_current: bool,
     *         target: BranchMonthlyTarget|null,
     *         sales: array{target: float, actual: float, pct: float|null, remaining: float},
     *         collection: array{target: float, actual: float, pct: float|null, remaining: float}
     *     }>
     * }
     */
    public function forLocation(Location $location, ?int $year = null): array
    {
        $year = $year ?? (int) now()->year;

        $targets = BranchMonthlyTarget::query()
            ->where('location_id', $location->id)
            ->where('year', $year)
            ->orderBy('month')
            ->get()
            ->keyBy('month');

        $actuals = $this->actualsForLocationByMonth((int) $location->id, $year);
        $currentMonth = (int) now()->month;
        $currentYear = (int) now()->year;

        $months = [];
        for ($month = 1; $month <= 12; $month++) {
            $target = $targets->get($month);
            $actual = $actuals[$month] ?? ['sales' => 0.0, 'collection' => 0.0];

            $months[$month] = [
                'month' => $month,
                'label' => Carbon::create($year, $month, 1)->format('F'),
                'is_current' => $year === $currentYear && $month === $currentMonth,
                'target' => $target,
                'sales' => $this->metricProgress(
                    (float) ($target?->sales_target ?? 0),
                    $actual['sales'],
                ),
                'collection' => $this->metricProgress(
                    (float) ($target?->collection_target ?? 0),
                    $actual['collection'],
                ),
            ];
        }

        return [
            'year' => $year,
            'months' => $months,
        ];
    }

    /**
     * @param  Collection<int, Location>|list<int>  $locationIds
     * @return array<int, array{
     *     year: int,
     *     month: int,
     *     label: string,
     *     has_target: bool,
     *     sales: array{target: float, actual: float, pct: float|null, remaining: float},
     *     collection: array{target: float, actual: float, pct: float|null, remaining: float}
     * }>
     */
    public function forLocationIds(array|Collection $locationIds, ?int $year = null, ?int $month = null): array
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
        $month = $month ?? (int) now()->month;
        $label = Carbon::create($year, $month, 1)->format('F Y');

        $targets = BranchMonthlyTarget::query()
            ->whereIn('location_id', $ids)
            ->where('year', $year)
            ->where('month', $month)
            ->get()
            ->keyBy('location_id');

        $actuals = $this->actualsForLocationsInMonth($ids, $year, $month);

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
                'month' => $month,
                'label' => $label,
                'has_target' => $hasTarget,
                'sales' => $this->metricProgress((float) ($target?->sales_target ?? 0), $actual['sales']),
                'collection' => $this->metricProgress((float) ($target?->collection_target ?? 0), $actual['collection']),
            ];
        }

        return $out;
    }

    /**
     * @return array<int, array{sales: float, collection: float}>
     */
    public function actualsForLocationByMonth(int $locationId, int $year): array
    {
        $out = [];
        for ($month = 1; $month <= 12; $month++) {
            $bucket = $this->actualsForLocationsInMonth([$locationId], $year, $month);
            $out[$month] = $bucket[$locationId] ?? ['sales' => 0.0, 'collection' => 0.0];
        }

        return $out;
    }

    /**
     * @param  list<int>  $locationIds
     * @return array<int, array{sales: float, collection: float}>
     */
    public function actualsForLocationsInMonth(array $locationIds, int $year, int $month): array
    {
        $out = [];
        foreach ($locationIds as $id) {
            $out[$id] = ['sales' => 0.0, 'collection' => 0.0];
        }

        if ($locationIds === []) {
            return $out;
        }

        $start = Carbon::create($year, $month, 1)->startOfDay()->toDateString();
        $end = Carbon::create($year, $month, 1)->endOfMonth()->toDateString();

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
