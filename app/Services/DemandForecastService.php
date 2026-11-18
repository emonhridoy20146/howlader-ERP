<?php

namespace App\Services;

use App\Models\DemandForecast;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Monthly demand forecasts from posted/active invoice lines (3‑month moving average).
 */
final class DemandForecastService
{
    /**
     * @param  list<int>|null  $accessibleLocationIds  null = all branches; empty = none
     */
    public function refresh(Carbon $targetMonth, ?int $singleLocationId = null, ?array $accessibleLocationIds = null): int
    {
        $targetMonth = $targetMonth->copy()->startOfMonth();
        $bucketStarts = [
            $targetMonth->copy()->subMonths(3)->toDateString(),
            $targetMonth->copy()->subMonths(2)->toDateString(),
            $targetMonth->copy()->subMonths(1)->toDateString(),
        ];
        $rangeStart = Carbon::parse($bucketStarts[0])->startOfMonth();
        $rangeEnd = Carbon::parse($bucketStarts[2])->endOfMonth();

        $query = DB::table('sales_invoice_lines as sil')
            ->join('sales_invoices as si', 'si.id', '=', 'sil.sales_invoice_id')
            ->whereIn('si.status', ['posted', 'active'])
            ->whereDate('si.invoice_date', '>=', $rangeStart->toDateString())
            ->whereDate('si.invoice_date', '<=', $rangeEnd->toDateString())
            ->when($singleLocationId !== null, fn ($q) => $q->where('si.branch_id', $singleLocationId))
            ->when(
                $singleLocationId === null && $accessibleLocationIds !== null,
                fn ($q) => $q->whereIn('si.branch_id', $accessibleLocationIds ?: [0])
            );

        $rows = $query->get(['sil.product_id as product_id', 'si.branch_id as location_id', 'si.invoice_date as invoice_date', 'sil.qty as qty']);

        /** @var array<int, array<int, array<string, float>>> $map product -> location -> monthStart -> qty */
        $map = [];
        foreach ($rows as $row) {
            $pid = (int) $row->product_id;
            $lid = (int) $row->location_id;
            $monthStart = Carbon::parse($row->invoice_date)->startOfMonth()->toDateString();
            $map[$pid][$lid][$monthStart] = ($map[$pid][$lid][$monthStart] ?? 0.0) + (float) $row->qty;
        }

        $written = 0;
        $now = now();
        foreach ($map as $productId => $byLocation) {
            foreach ($byLocation as $locationId => $byMonth) {
                $a = (float) ($byMonth[$bucketStarts[0]] ?? 0.0);
                $b = (float) ($byMonth[$bucketStarts[1]] ?? 0.0);
                $c = (float) ($byMonth[$bucketStarts[2]] ?? 0.0);
                $predicted = round(($a + $b + $c) / 3.0, 2);

                DemandForecast::query()->updateOrCreate(
                    [
                        'product_id' => $productId,
                        'location_id' => $locationId,
                        'forecast_month' => $targetMonth->toDateString(),
                    ],
                    [
                        'predicted_qty' => $predicted,
                        'algorithm' => 'moving_average_3m',
                        'generated_at' => $now,
                    ],
                );
                $written++;
            }
        }

        return $written;
    }
}
