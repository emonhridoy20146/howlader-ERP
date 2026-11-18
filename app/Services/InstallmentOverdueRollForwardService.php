<?php

namespace App\Services;

use App\Models\InstallmentPlan;
use App\Models\InstallmentSchedule;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * When an installment is past due and still has a balance, move that balance to the next
 * schedule (zeroing the overdue row). Repeats left-to-right so multiple missed installments
 * consolidate onto the last unpaid schedule.
 */
final class InstallmentOverdueRollForwardService
{
    /**
     * @return array{plans_processed: int, rolls: int, amount_moved: float}
     */
    public function roll(Carbon $asOf, ?int $onlyPlanId = null, bool $dryRun = false): array
    {
        $asOfDate = $asOf->copy()->startOfDay();

        $plans = InstallmentPlan::query()
            ->where('status', 'active')
            ->when($onlyPlanId !== null, fn ($q) => $q->whereKey($onlyPlanId))
            ->with(['schedules' => fn ($q) => $q->orderBy('installment_no')])
            ->orderBy('id')
            ->get();

        $stats = [
            'plans_processed' => 0,
            'rolls' => 0,
            'amount_moved' => 0.0,
        ];

        foreach ($plans as $plan) {
            $planStats = $this->rollPlan($plan->schedules, $asOfDate, $dryRun);
            if ($planStats['rolls'] === 0) {
                continue;
            }

            $stats['plans_processed']++;
            $stats['rolls'] += $planStats['rolls'];
            $stats['amount_moved'] = round($stats['amount_moved'] + $planStats['amount_moved'], 2);

            if (! $dryRun) {
                $this->refreshPlanTotals($plan);
                $invoice = $plan->salesInvoice()->first();
                if ($invoice instanceof \App\Models\SalesInvoice) {
                    app(InstallmentInvoiceSyncService::class)->syncInvoiceHeaderFromPlan(
                        $invoice->fresh(['installmentPlan.schedules']),
                    );
                }
            }
        }

        return $stats;
    }

    /**
     * @param  Collection<int, InstallmentSchedule>  $schedules
     * @return array{rolls: int, amount_moved: float}
     */
    private function rollPlan(Collection $schedules, Carbon $asOfDate, bool $dryRun): array
    {
        $stats = ['rolls' => 0, 'amount_moved' => 0.0];

        if ($schedules->count() < 2) {
            return $stats;
        }

        $ordered = $schedules->values();

        for ($i = 0; $i < $ordered->count() - 1; $i++) {
            $current = $ordered[$i];
            $next = $ordered[$i + 1];

            if (! $this->shouldRoll($current, $asOfDate)) {
                continue;
            }

            if ((float) $current->paid_amount < -0.009 || (float) $current->due_amount < -0.009) {
                continue;
            }

            $toMove = $current->remainingDue();
            if ($toMove < 0.01) {
                continue;
            }

            $stats['rolls']++;
            $stats['amount_moved'] = round($stats['amount_moved'] + $toMove, 2);

            if ($dryRun) {
                continue;
            }

            DB::transaction(function () use ($current, $next): void {
                $lockedCurrent = InstallmentSchedule::query()->lockForUpdate()->findOrFail($current->id);
                $lockedNext = InstallmentSchedule::query()->lockForUpdate()->findOrFail($next->id);

                $move = $lockedCurrent->remainingDue();
                if ($move < 0.01) {
                    return;
                }

                $lockedCurrent->due_amount = max(0, round((float) $lockedCurrent->paid_amount, 2));
                $lockedCurrent->late_fee_amount = 0;
                $lockedCurrent->status = $lockedCurrent->computeStatus();
                $lockedCurrent->save();

                $lockedNext->due_amount = round((float) $lockedNext->due_amount + $move, 2);
                $lockedNext->status = $lockedNext->computeStatus();
                $lockedNext->save();
            });

            // Keep in-memory copies aligned for subsequent rolls in the same plan.
            $current->due_amount = max(0, round((float) $current->paid_amount, 2));
            $current->late_fee_amount = 0;
            $current->status = $current->computeStatus();

            $next->due_amount = round((float) $next->due_amount + $toMove, 2);
            $next->status = $next->computeStatus();
        }

        return $stats;
    }

    private function shouldRoll(InstallmentSchedule $schedule, Carbon $asOfDate): bool
    {
        if ($schedule->isFullyPaid()) {
            return false;
        }

        if (! $schedule->due_date) {
            return false;
        }

        return $schedule->due_date->copy()->startOfDay()->lt($asOfDate);
    }

    private function refreshPlanTotals(InstallmentPlan $plan): void
    {
        $plan->refresh();
        $plan->load('schedules');

        $owing = (float) $plan->schedules->sum(fn (InstallmentSchedule $s) => $s->remainingDue());
        $plan->remaining_amount = max(0, round($owing, 2));
        $plan->status = $owing < 0.02 ? 'completed' : 'active';
        $plan->save();
    }
}
