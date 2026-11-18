<?php

namespace App\Console\Commands;

use App\Models\InstallmentPlan;
use App\Services\InstallmentScheduleRepairService;
use Illuminate\Console\Command;

class RepairCorruptedInstallmentSchedulesCommand extends Command
{
    protected $signature = 'installments:repair-corrupted-schedules
                            {--dry-run : Report repairs without saving}
                            {--invoice= : Limit to sales_invoices.id or invoice_number}
                            {--plan= : Limit to installment_plans.id}';

    protected $description = 'Fix native installment schedules with advance wrongly applied, negative payments, or inflated remaining totals';

    public function handle(InstallmentScheduleRepairService $service): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $invoiceOpt = $this->option('invoice');
        $planOpt = $this->option('plan');

        $query = InstallmentPlan::query()
            ->with(['salesInvoice', 'schedules'])
            ->orderBy('id');

        if ($planOpt !== null && is_numeric($planOpt)) {
            $query->whereKey((int) $planOpt);
        }

        if ($invoiceOpt !== null && (string) $invoiceOpt !== '') {
            $needle = (string) $invoiceOpt;
            $query->whereHas('salesInvoice', function ($q) use ($needle): void {
                if (is_numeric($needle)) {
                    $q->where(function ($inner) use ($needle): void {
                        $inner->whereKey((int) $needle)
                            ->orWhere('invoice_number', $needle);
                    });
                } else {
                    $q->where('invoice_number', $needle);
                }
            });
        }

        if ($planOpt !== null && is_numeric($planOpt) && ! InstallmentPlan::query()->whereKey((int) $planOpt)->exists()) {
            $this->error('Installment plan not found: '.$planOpt);

            return self::FAILURE;
        }

        if ($invoiceOpt !== null && (string) $invoiceOpt !== '' && ! (clone $query)->exists()) {
            $this->error('No installment plan found for invoice: '.$invoiceOpt);

            return self::FAILURE;
        }

        $plans = $query->get()->filter(fn (InstallmentPlan $plan): bool => $service->needsRepair($plan));

        if ($plans->isEmpty()) {
            $this->info('No corrupted installment plans found.');

            return self::SUCCESS;
        }

        if ($dryRun) {
            $this->warn('Dry run — no rows will be updated.');
        }

        $changes = [];

        foreach ($plans as $plan) {
            $result = $service->repairPlan($plan, $dryRun);
            if (! $result['repaired']) {
                continue;
            }

            $invoice = $plan->salesInvoice;
            $changes[] = [
                'plan_id' => (int) $plan->id,
                'invoice_id' => (int) ($invoice?->id ?? 0),
                'invoice_number' => (string) ($invoice?->invoice_number ?? '—'),
                'schedules' => $result['schedules'],
                'before' => number_format($result['before_remaining'], 2),
                'after' => number_format($result['after_remaining'], 2),
            ];
        }

        $this->info('Corrupted installment plans found: '.$plans->count());

        if ($changes !== []) {
            $this->table(
                ['Plan', 'Invoice ID', 'Invoice #', 'Schedules', 'Remaining before', 'Remaining after'],
                $changes,
            );
        }

        if ($dryRun) {
            $this->comment('Dry run complete. Run without --dry-run to apply.');
        } else {
            $this->info('Repaired '.count($changes).' installment plan(s).');
        }

        return self::SUCCESS;
    }
}
