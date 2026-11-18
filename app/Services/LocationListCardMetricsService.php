<?php

namespace App\Services;

use App\Models\Location;
use App\Support\LedgerBackedInventory;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

final class LocationListCardMetricsService
{
    /**
     * Lightweight aggregates for location index cards (batched queries).
     *
     * @param  Collection<int, Location>  $locations
     * @return array<int, array{
     *     on_hand: int,
     *     sku_lines: int,
     *     sales_30d: float,
     *     sales_invoices_30d: int,
     *     outstanding: float,
     *     transfers_30d: int,
     *     performance: array{label: string, tone: string, band: string}
     * }>
     */
    public function forLocations(Collection $locations): array
    {
        if ($locations->isEmpty()) {
            return [];
        }

        $ids = $locations->pluck('id')->map(fn ($id) => (int) $id)->unique()->values()->all();
        $since = Carbon::now()->subDays(30)->startOfDay()->toDateString();

        $stockByLoc = $this->stockAggregates($ids);
        $salesByLoc = $this->salesAggregates($ids, $since);
        $outstandingByLoc = $this->outstandingByLocation($ids);
        $transfersByLoc = $this->transferTouchesByLocation($ids, $since);

        $byId = [];
        foreach ($ids as $id) {
            $stock = $stockByLoc[$id] ?? ['on_hand' => 0, 'sku_lines' => 0];
            $sales = $salesByLoc[$id] ?? ['total' => 0.0, 'count' => 0];
            $byId[$id] = [
                'on_hand' => $stock['on_hand'],
                'sku_lines' => $stock['sku_lines'],
                'sales_30d' => $sales['total'],
                'sales_invoices_30d' => $sales['count'],
                'outstanding' => $outstandingByLoc[$id] ?? 0.0,
                'transfers_30d' => $transfersByLoc[$id] ?? 0,
            ];
        }

        $scores = [];
        foreach ($locations as $loc) {
            $id = (int) $loc->id;
            $scores[$id] = $this->activityScore((string) $loc->type, $byId[$id]);
        }

        $performance = $this->performanceLabels($scores);

        $out = [];
        foreach ($ids as $id) {
            $out[$id] = array_merge($byId[$id], ['performance' => $performance[$id]]);
        }

        return $out;
    }

    /**
     * @return array<int, array{on_hand: int, sku_lines: int}>
     */
    private function stockAggregates(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $rows = DB::query()
            ->fromSub(LedgerBackedInventory::balancesSubquery(), 'ib')
            ->whereIn('ib.location_id', $ids)
            ->groupBy('ib.location_id')
            ->selectRaw('ib.location_id as location_id, COALESCE(SUM(ib.on_hand_qty), 0) as on_hand, COUNT(*) as sku_lines')
            ->get();

        $map = [];
        foreach ($rows as $r) {
            $map[(int) $r->location_id] = [
                'on_hand' => (int) $r->on_hand,
                'sku_lines' => (int) $r->sku_lines,
            ];
        }

        return $map;
    }

    /**
     * @return array<int, array{total: float, count: int}>
     */
    private function salesAggregates(array $ids, string $since): array
    {
        if ($ids === []) {
            return [];
        }

        $rows = DB::table('sales_invoices')
            ->whereIn('branch_id', $ids)
            ->whereDate('invoice_date', '>=', $since)
            ->groupBy('branch_id')
            ->selectRaw('branch_id, COALESCE(SUM(grand_total), 0) as total, COUNT(*) as cnt')
            ->get();

        $map = [];
        foreach ($rows as $r) {
            $map[(int) $r->branch_id] = [
                'total' => round((float) $r->total, 2),
                'count' => (int) $r->cnt,
            ];
        }

        return $map;
    }

    /**
     * @return array<int, float>
     */
    private function outstandingByLocation(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $rows = DB::table('sales_invoices')
            ->whereIn('branch_id', $ids)
            ->groupBy('branch_id')
            ->selectRaw('branch_id, COALESCE(SUM(amount_due), 0) as due')
            ->get();

        $map = [];
        foreach ($rows as $r) {
            $map[(int) $r->branch_id] = round((float) $r->due, 2);
        }

        return $map;
    }

    /**
     * Count stock transfers touching each location (as source or destination) in range.
     *
     * @return array<int, int>
     */
    private function transferTouchesByLocation(array $ids, string $since): array
    {
        if ($ids === []) {
            return [];
        }

        $counts = array_fill_keys($ids, 0);

        $fromRows = DB::table('stock_transfers')
            ->whereDate('requested_at', '>=', $since)
            ->whereIn('source_location_id', $ids)
            ->groupBy('source_location_id')
            ->selectRaw('source_location_id as lid, COUNT(*) as c')
            ->get();

        foreach ($fromRows as $r) {
            $counts[(int) $r->lid] += (int) $r->c;
        }

        $toRows = DB::table('stock_transfers')
            ->whereDate('requested_at', '>=', $since)
            ->whereIn('destination_location_id', $ids)
            ->groupBy('destination_location_id')
            ->selectRaw('destination_location_id as lid, COUNT(*) as c')
            ->get();

        foreach ($toRows as $r) {
            $counts[(int) $r->lid] += (int) $r->c;
        }

        return $counts;
    }

    /**
     * @param  array<string, mixed>  $m
     */
    private function activityScore(string $type, array $m): float
    {
        $sales = (float) ($m['sales_30d'] ?? 0);
        $transfers = (int) ($m['transfers_30d'] ?? 0);
        $onHand = (float) ($m['on_hand'] ?? 0);

        if ($type === 'warehouse') {
            return $sales + $transfers * 400.0 + min($onHand, 80_000.0) * 0.03;
        }

        return $sales + $transfers * 120.0 + min($onHand, 80_000.0) * 0.02;
    }

    /**
     * Relative bands vs other locations on the same page (tertiles).
     *
     * @param  array<int, float>  $scores
     * @return array<int, array{label: string, tone: string, band: string}>
     */
    private function performanceLabels(array $scores): array
    {
        if ($scores === []) {
            return [];
        }

        $maxScore = max($scores);
        if ($maxScore <= 0.0) {
            return collect($scores)
                ->map(fn () => ['label' => 'Quiet', 'tone' => 'slate', 'band' => 'low'])
                ->all();
        }

        /** @var list<array{id: int, score: float}> $ranked */
        $ranked = [];
        foreach ($scores as $id => $score) {
            $ranked[] = ['id' => (int) $id, 'score' => (float) $score];
        }
        usort($ranked, static fn ($a, $b) => $b['score'] <=> $a['score']);

        $n = count($ranked);
        $upper = max(1, (int) ceil($n / 3));
        $middle = max($upper + 1, (int) ceil(2 * $n / 3));

        $out = [];
        foreach ($ranked as $idx => $row) {
            if ($idx < $upper) {
                $label = ['label' => 'Strong', 'tone' => 'emerald', 'band' => 'high'];
            } elseif ($idx < $middle) {
                $label = ['label' => 'Steady', 'tone' => 'amber', 'band' => 'mid'];
            } else {
                $label = ['label' => 'Quiet', 'tone' => 'slate', 'band' => 'low'];
            }
            $out[(int) $row['id']] = $label;
        }

        return $out;
    }
}
