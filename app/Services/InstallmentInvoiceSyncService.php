<?php

namespace App\Services;

use App\Models\InstallmentPlan;
use App\Models\InstallmentSchedule;
use App\Models\SalesInvoice;

final class InstallmentInvoiceSyncService
{
    public function expectedFinancedAmount(InstallmentPlan $plan): float
    {
        $invoice = $plan->relationLoaded('salesInvoice') ? $plan->salesInvoice : $plan->salesInvoice()->first();

        if ($invoice instanceof SalesInvoice) {
            $down = (float) ($invoice->installment_down_payment ?? $plan->down_payment ?? 0);

            return max(0, round((float) $invoice->grand_total - $down, 2));
        }

        return max(0, round((float) $plan->financed_amount, 2));
    }

    /**
     * Re-align open schedule balances when roll-forward inflated totals beyond financed amount.
     */
    public function rebalanceInflatedScheduleDues(InstallmentPlan $plan): bool
    {
        $plan->loadMissing(['schedules' => fn ($q) => $q->orderBy('installment_no')]);

        $expectedFinanced = $this->expectedFinancedAmount($plan);
        $totalPaid = round((float) $plan->schedules->sum(fn (InstallmentSchedule $s) => max(0, (float) $s->paid_amount)), 2);
        $correctRemaining = max(0, round($expectedFinanced - $totalPaid, 2));
        $currentRemaining = round((float) $plan->schedules->sum(fn (InstallmentSchedule $s) => $s->remainingDue()), 2);
        $sumDue = round((float) $plan->schedules->sum(fn (InstallmentSchedule $s) => (float) $s->due_amount), 2);
        $invoiceDue = round((float) ($plan->salesInvoice?->amount_due ?? 0), 2);

        if ($plan->salesInvoice && $invoiceDue + 0.02 < $correctRemaining && $currentRemaining > $invoiceDue + 0.02) {
            $correctRemaining = max(0, $invoiceDue);
        }

        if (abs($currentRemaining - $correctRemaining) <= 0.02
            && abs($sumDue - $expectedFinanced) <= 0.02
            && abs((float) $plan->financed_amount - $expectedFinanced) <= 0.009) {
            return false;
        }

        /** @var \Illuminate\Support\Collection<int, InstallmentSchedule> $openSchedules */
        $openSchedules = $plan->schedules
            ->filter(fn (InstallmentSchedule $s) => $s->remainingDue() > 0.009)
            ->values();

        if ($openSchedules->isEmpty() && $correctRemaining <= 0.009) {
            foreach ($plan->schedules as $schedule) {
                $paid = round(max(0, (float) $schedule->paid_amount), 2);
                if ((float) $schedule->due_amount > $paid + 0.009) {
                    InstallmentSchedule::query()->whereKey($schedule->id)->update([
                        'due_amount' => $paid,
                        'status' => $schedule->fresh()->computeStatus(),
                    ]);
                }
            }
        } elseif ($openSchedules->count() === 1) {
            $schedule = $openSchedules->first();
            InstallmentSchedule::query()->whereKey($schedule->id)->update([
                'due_amount' => round((float) $schedule->paid_amount + $correctRemaining, 2),
                'status' => $schedule->fresh()->computeStatus(),
            ]);
        } elseif ($openSchedules->isNotEmpty()) {
            $openRemainingTotal = round((float) $openSchedules->sum(fn (InstallmentSchedule $s) => $s->remainingDue()), 2);
            $assigned = 0.0;

            foreach ($openSchedules as $index => $schedule) {
                if ($index === $openSchedules->count() - 1) {
                    $share = round($correctRemaining - $assigned, 2);
                } else {
                    $weight = $openRemainingTotal > 0.009
                        ? $schedule->remainingDue() / $openRemainingTotal
                        : 1 / $openSchedules->count();
                    $share = round($correctRemaining * $weight, 2);
                    $assigned = round($assigned + $share, 2);
                }

                InstallmentSchedule::query()->whereKey($schedule->id)->update([
                    'due_amount' => round((float) $schedule->paid_amount + $share, 2),
                    'status' => $schedule->fresh()->computeStatus(),
                ]);
            }
        }

        foreach ($plan->schedules()->get() as $schedule) {
            $paid = round(max(0, (float) $schedule->paid_amount), 2);
            if ($schedule->remainingDue() <= 0.009 && (float) $schedule->due_amount > $paid + 0.009) {
                $schedule->update([
                    'due_amount' => $paid,
                    'status' => $schedule->computeStatus(),
                ]);
            }
        }

        $plan->update(['financed_amount' => $expectedFinanced]);

        return true;
    }

    public function refreshPlanTotals(InstallmentPlan $plan): void
    {
        $plan->load('schedules');

        foreach ($plan->schedules as $schedule) {
            $computed = $schedule->computeStatus();
            if ($schedule->status !== $computed) {
                $schedule->update(['status' => $computed]);
            }
        }

        $owing = round((float) $plan->schedules->sum(fn (InstallmentSchedule $s) => $s->remainingDue()), 2);

        $plan->update([
            'remaining_amount' => max(0, $owing),
            'status' => $owing < 0.02 ? 'completed' : 'active',
        ]);
    }

    public function syncInvoiceHeaderFromPlan(SalesInvoice $invoice): void
    {
        if ($invoice->sale_type !== 'installment') {
            return;
        }

        $plan = $invoice->relationLoaded('installmentPlan')
            ? $invoice->installmentPlan
            : $invoice->installmentPlan()->first();

        if (! $plan instanceof InstallmentPlan) {
            return;
        }

        $plan->load('schedules');
        $grandTotal = round((float) $invoice->grand_total, 2);
        $amountPaid = round(max(0, (float) $invoice->amount_paid), 2);
        $scheduleRemaining = round((float) $plan->schedules->sum(fn (InstallmentSchedule $s) => $s->remainingDue()), 2);
        $amountDue = max(0, round($grandTotal - $amountPaid, 2));

        $paymentStatus = match (true) {
            $amountDue < 0.009 && $scheduleRemaining < 0.02 => 'paid',
            $amountPaid < 0.009 => 'unpaid',
            default => 'partial',
        };

        $invoice->update([
            'amount_due' => $amountDue,
            'amount_paid' => $amountPaid,
            'payment_status' => $paymentStatus,
        ]);

        $this->refreshPlanTotals($plan->fresh(['schedules']));
    }

    /**
     * @return array{rebalanced: bool, before_remaining: float, after_remaining: float, before_invoice_due: float, after_invoice_due: float}
     */
    public function reconcilePlanAndInvoice(InstallmentPlan $plan): array
    {
        $plan->loadMissing(['schedules', 'salesInvoice']);
        $invoice = $plan->salesInvoice;

        $beforeRemaining = round((float) $plan->remaining_amount, 2);
        $beforeInvoiceDue = round((float) ($invoice?->amount_due ?? 0), 2);

        $rebalanced = $this->rebalanceInflatedScheduleDues($plan);
        $this->refreshPlanTotals($plan->fresh(['schedules']));

        if ($invoice instanceof SalesInvoice) {
            $this->syncInvoiceHeaderFromPlan($invoice->fresh(['installmentPlan.schedules']));
        }

        $plan->refresh();

        return [
            'rebalanced' => $rebalanced,
            'before_remaining' => $beforeRemaining,
            'after_remaining' => round((float) $plan->remaining_amount, 2),
            'before_invoice_due' => $beforeInvoiceDue,
            'after_invoice_due' => round((float) ($invoice?->fresh()->amount_due ?? 0), 2),
        ];
    }

    public function planNeedsReconciliation(InstallmentPlan $plan): bool
    {
        $plan->loadMissing(['schedules', 'salesInvoice']);

        if ($plan->schedules->isEmpty()) {
            return false;
        }

        $expectedFinanced = $this->expectedFinancedAmount($plan);
        $sumDue = round((float) $plan->schedules->sum(fn (InstallmentSchedule $s) => (float) $s->due_amount), 2);
        $scheduleRemaining = round((float) $plan->schedules->sum(fn (InstallmentSchedule $s) => $s->remainingDue()), 2);
        $planRemaining = round((float) $plan->remaining_amount, 2);
        $invoiceDue = round((float) ($plan->salesInvoice?->amount_due ?? 0), 2);

        if ($plan->schedules->contains(fn (InstallmentSchedule $s): bool => (float) $s->paid_amount < -0.009)) {
            return true;
        }

        if ($plan->schedules->contains(fn (InstallmentSchedule $s): bool => (float) $s->due_amount < -0.009)) {
            return true;
        }

        if (abs((float) $plan->financed_amount - $expectedFinanced) > 0.009) {
            return true;
        }

        if ($sumDue > $expectedFinanced + 0.009) {
            return true;
        }

        $totalPaid = round((float) $plan->schedules->sum(fn (InstallmentSchedule $s) => max(0, (float) $s->paid_amount)), 2);
        $correctRemaining = max(0, round($expectedFinanced - $totalPaid, 2));

        if (abs($scheduleRemaining - $correctRemaining) > 0.02) {
            return true;
        }

        if (abs($scheduleRemaining - $planRemaining) > 0.02) {
            return true;
        }

        if ($plan->salesInvoice && abs($scheduleRemaining - $invoiceDue) > 0.02) {
            return true;
        }

        return false;
    }
}
