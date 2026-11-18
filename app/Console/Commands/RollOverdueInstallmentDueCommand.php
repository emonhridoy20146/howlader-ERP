<?php

namespace App\Console\Commands;

use App\Models\InstallmentPlan;
use App\Services\InstallmentOverdueRollForwardService;
use Carbon\Carbon;
use Illuminate\Console\Command;

class RollOverdueInstallmentDueCommand extends Command
{
    protected $signature = 'installments:roll-overdue-due
                            {--date= : Treat installments due before this date (Y-m-d); default: today}
                            {--plan= : Limit to a single installment_plans.id}
                            {--dry-run : Show what would change without saving}';

    protected $description = 'Move unpaid overdue installment balances to the next schedule (missed dues consolidate on the last installment)';

    public function handle(InstallmentOverdueRollForwardService $service): int
    {
        $dateOpt = $this->option('date');
        try {
            $asOf = $dateOpt !== null && (string) $dateOpt !== ''
                ? Carbon::parse((string) $dateOpt)->startOfDay()
                : Carbon::today();
        } catch (\Throwable) {
            $this->error('Invalid --date value (use Y-m-d, e.g. 2026-05-23).');

            return self::FAILURE;
        }

        $planOpt = $this->option('plan');
        $onlyPlanId = is_numeric($planOpt) ? (int) $planOpt : null;

        if ($onlyPlanId !== null && ! InstallmentPlan::query()->whereKey($onlyPlanId)->exists()) {
            $this->error('Installment plan not found: '.$onlyPlanId);

            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');

        if ($dryRun) {
            $this->warn('Dry run — no rows will be updated.');
        }

        $stats = $service->roll($asOf, $onlyPlanId, $dryRun);

        $this->info(sprintf(
            'As of %s: %d plan(s), %d roll(s), %s moved.',
            $asOf->toDateString(),
            $stats['plans_processed'],
            $stats['rolls'],
            number_format($stats['amount_moved'], 2),
        ));

        return self::SUCCESS;
    }
}
