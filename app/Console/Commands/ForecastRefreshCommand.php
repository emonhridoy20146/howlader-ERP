<?php

namespace App\Console\Commands;

use App\Services\DemandForecastService;
use Carbon\Carbon;
use Illuminate\Console\Command;

class ForecastRefreshCommand extends Command
{
    protected $signature = 'forecasts:refresh
        {--month= : Target forecast month (Y-m, first day; default: first day of next calendar month)}
        {--branch= : Limit to a single branch (location id)}';

    protected $description = 'Refresh demand forecasts (3-month moving average from posted/active invoice lines).';

    public function handle(DemandForecastService $service): int
    {
        $monthOpt = $this->option('month');
        try {
            $target = $monthOpt !== null && (string) $monthOpt !== ''
                ? Carbon::parse((string) $monthOpt)->startOfMonth()
                : Carbon::now()->addMonth()->startOfMonth();
        } catch (\Throwable) {
            $this->error('Invalid --month value (use Y-m, e.g. 2026-06).');

            return self::FAILURE;
        }

        $branchOpt = $this->option('branch');
        $branchId = $branchOpt !== null && (string) $branchOpt !== '' ? (int) $branchOpt : null;

        $count = $service->refresh($target, $branchId, null);
        $this->info("Wrote {$count} forecast row(s) for ".$target->format('F Y').'.');

        return self::SUCCESS;
    }
}
