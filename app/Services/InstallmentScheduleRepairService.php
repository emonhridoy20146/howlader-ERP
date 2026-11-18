<?php

namespace App\Services;

use App\Models\InstallmentPaymentAllocation;
use App\Models\InstallmentPlan;
use App\Models\InstallmentSchedule;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Rebuilds installment schedule rows corrupted by negative paid/due amounts or overdue roll-forward.
 */
final class InstallmentScheduleRepairService
{
    public function __construct(
        private readonly InstallmentInvoiceSyncService $installmentSync,
        private readonly InstallmentAdvanceService $advanceService,
    ) {}

    /**
     * @return array{repaired: bool, before_remaining: float, after_remaining: float, schedules: int}
     */
    public function repairPlan(InstallmentPlan $plan, bool $dryRun = false): array
    {
        $plan->load(['schedules' => fn ($q) => $q->orderBy('installment_no'), 'salesInvoice']);

        $beforeRemaining = round((float) $plan->remaining_amount, 2);

        if (! $this->needsRepair($plan)) {
            return [
                'repaired' => false,
                'before_remaining' => $beforeRemaining,
                'after_remaining' => $beforeRemaining,
                'schedules' => $plan->schedules->count(),
            ];
        }

        if ($dryRun) {
            return [
                'repaired' => true,
                'before_remaining' => $beforeRemaining,
                'after_remaining' => $this->previewRemainingAfterRepair($plan),
                'schedules' => $plan->schedules->count(),
            ];
        }

        DB::transaction(function () use ($plan): void {
            $lockedPlan = InstallmentPlan::query()
                ->lockForUpdate()
                ->with(['schedules' => fn ($q) => $q->orderBy('installment_no'), 'salesInvoice'])
                ->findOrFail($plan->id);

            $this->advanceService->repairMisallocatedAdvanceOnSchedules($lockedPlan);

            if ($this->hasNegativeScheduleAmounts($lockedPlan)) {
                $this->repairNegativeScheduleAmounts($lockedPlan);
            }

            $this->installmentSync->rebalanceInflatedScheduleDues($lockedPlan->fresh(['schedules', 'salesInvoice']));
            $this->installmentSync->refreshPlanTotals($lockedPlan->fresh(['schedules']));

            $invoice = $lockedPlan->salesInvoice;
            if ($invoice) {
                $this->installmentSync->syncInvoiceHeaderFromPlan(
                    $invoice->fresh(['installmentPlan.schedules']),
                );
            }
        });

        $plan->refresh();

        return [
            'repaired' => true,
            'before_remaining' => $beforeRemaining,
            'after_remaining' => round((float) $plan->remaining_amount, 2),
            'schedules' => $plan->schedules->count(),
        ];
    }

    public function needsRepair(InstallmentPlan $plan): bool
    {
        return $this->advanceService->hasMisallocatedAdvanceOnSchedules($plan)
            || $this->installmentSync->planNeedsReconciliation($plan);
    }

    private function hasNegativeScheduleAmounts(InstallmentPlan $plan): bool
    {
        return $plan->schedules->contains(fn (InstallmentSchedule $s): bool => (float) $s->paid_amount < -0.009)
            || $plan->schedules->contains(fn (InstallmentSchedule $s): bool => (float) $s->due_amount < -0.009);
    }

    private function repairNegativeScheduleAmounts(InstallmentPlan $plan): void
    {
        $expectedFinanced = $this->installmentSync->expectedFinancedAmount($plan);
        $ipaPaidBySchedule = $this->paidAmountsFromAllocations($plan->schedules);
        $dueAmounts = $this->redistributeDueAmounts($plan->schedules, $expectedFinanced);

        foreach ($plan->schedules->values() as $index => $schedule) {
            $locked = InstallmentSchedule::query()->lockForUpdate()->findOrFail($schedule->id);
            $paid = round(max(0, (float) ($ipaPaidBySchedule[$locked->id] ?? $locked->paid_amount)), 2);

            $locked->update([
                'due_amount' => $dueAmounts[$index],
                'paid_amount' => $paid,
                'late_fee_amount' => 0,
            ]);

            $locked->refresh();
            $locked->update(['status' => $locked->computeStatus()]);
        }

        $plan->update(['financed_amount' => $expectedFinanced]);
    }

    /**
     * @param  Collection<int, InstallmentSchedule>  $schedules
     * @return array<int, float>
     */
    private function redistributeDueAmounts(Collection $schedules, float $financed): array
    {
        $count = $schedules->count();
        if ($count === 0) {
            return [];
        }

        $base = round($financed / $count, 2);
        $amounts = array_fill(0, $count, $base);
        $amounts[$count - 1] = round($financed - ($base * ($count - 1)), 2);

        return $amounts;
    }

    /**
     * @param  Collection<int, InstallmentSchedule>  $schedules
     * @return array<int, float>
     */
    private function paidAmountsFromAllocations(Collection $schedules): array
    {
        $scheduleIds = $schedules->pluck('id')->map(fn ($id) => (int) $id)->all();

        if ($scheduleIds === []) {
            return [];
        }

        return InstallmentPaymentAllocation::query()
            ->whereIn('installment_schedule_id', $scheduleIds)
            ->selectRaw('installment_schedule_id, ROUND(COALESCE(SUM(allocated_amount), 0), 2) as total')
            ->groupBy('installment_schedule_id')
            ->pluck('total', 'installment_schedule_id')
            ->map(fn ($total) => (float) $total)
            ->all();
    }

    private function previewRemainingAfterRepair(InstallmentPlan $plan): float
    {
        $expectedFinanced = $this->installmentSync->expectedFinancedAmount($plan);
        $misallocatedAdvance = $this->advanceService->misallocatedAdvanceTotalOnSchedules($plan);
        $totalPaid = round((float) $plan->schedules->sum(fn (InstallmentSchedule $s) => max(0, (float) $s->paid_amount)), 2);
        $totalPaid = round(max(0, $totalPaid - $misallocatedAdvance), 2);

        return max(0, round($expectedFinanced - $totalPaid, 2));
    }
}
