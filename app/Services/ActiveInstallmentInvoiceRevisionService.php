<?php

namespace App\Services;

use App\Exceptions\InvalidStateException;
use App\Models\AccountsReceivableLedger;
use App\Models\Customer;
use App\Models\InstallmentPaymentAllocation;
use App\Models\InstallmentPlan;
use App\Models\InstallmentSchedule;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Models\SalesInvoice;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

final class ActiveInstallmentInvoiceRevisionService
{
    public function __construct(
        private readonly PaymentService $paymentService,
        private readonly InstallmentInvoiceSyncService $installmentSync,
        private readonly JournalService $journalService,
    ) {}

    /**
     * Rebuild schedule rows from invoice installment settings and re-apply payment allocations.
     */
    public function regeneratePlanFromInvoiceConfig(SalesInvoice $invoice, int $actorUserId): void
    {
        if ($invoice->sale_type !== 'installment') {
            return;
        }

        DB::transaction(function () use ($invoice, $actorUserId): void {
            $invoice = SalesInvoice::query()
                ->lockForUpdate()
                ->with('installmentPlan')
                ->findOrFail($invoice->id);

            $count = (int) ($invoice->installment_count ?? 0);
            if ($count < 1 || ! $invoice->installment_first_due_date) {
                throw new InvalidStateException('Installment count and first due date are required.');
            }

            $downPayment = round(max(0, (float) ($invoice->installment_down_payment ?? 0)), 2);
            $financedAmount = max(0, round((float) $invoice->grand_total - $downPayment, 2));

            $plan = $invoice->installmentPlan;
            if (! $plan instanceof InstallmentPlan) {
                $plan = InstallmentPlan::create([
                    'sales_invoice_id' => $invoice->id,
                    'customer_id' => $invoice->customer_id,
                    'total_amount' => $invoice->grand_total,
                    'down_payment' => $downPayment,
                    'financed_amount' => $financedAmount,
                    'remaining_amount' => $financedAmount,
                    'penalty_enabled' => (bool) $invoice->installment_penalty_enabled,
                    'penalty_rate' => (float) ($invoice->installment_penalty_rate ?? 0.02),
                    'created_by' => $actorUserId,
                ]);
            } else {
                $paymentIds = PaymentAllocation::query()
                    ->where('sales_invoice_id', $invoice->id)
                    ->pluck('payment_id')
                    ->map(fn ($id) => (int) $id)
                    ->all();

                if ($paymentIds !== []) {
                    InstallmentPaymentAllocation::query()
                        ->whereIn('payment_id', $paymentIds)
                        ->delete();
                }

                InstallmentSchedule::query()
                    ->where('installment_plan_id', $plan->id)
                    ->delete();

                $plan->update([
                    'total_amount' => $invoice->grand_total,
                    'down_payment' => $downPayment,
                    'financed_amount' => $financedAmount,
                    'penalty_enabled' => (bool) $invoice->installment_penalty_enabled,
                    'penalty_rate' => (float) ($invoice->installment_penalty_rate ?? 0.02),
                ]);
            }

            if ($financedAmount > 0.009) {
                $this->createSchedulesForPlan($plan, $invoice, $count, $financedAmount);
            } else {
                $plan->update([
                    'remaining_amount' => 0,
                    'status' => 'completed',
                ]);
            }

            // Adjust the advance payment BEFORE re-allocating so syncInvoiceHeaderFromPlan
            // sees the correct amount_paid when it runs inside reconcilePlanAndInvoice.
            $this->syncAdvancePaymentAmount($invoice->fresh(), $downPayment, $actorUserId);

            $this->paymentService->allocateUnlinkedPaymentsToInstallmentPlan(
                $invoice->fresh(['installmentPlan.schedules']),
            );
            $this->installmentSync->reconcilePlanAndInvoice($plan->fresh(['schedules', 'salesInvoice']));
        });
    }

    private function syncAdvancePaymentAmount(SalesInvoice $invoice, float $newDownPayment, int $actorUserId): void
    {
        $advancePayment = Payment::query()
            ->where('direction', 'inbound')
            ->where('reference_no', InstallmentAdvanceService::REFERENCE)
            ->whereHas('paymentAllocations', fn ($q) => $q->where('sales_invoice_id', $invoice->id))
            ->lockForUpdate()
            ->first();

        if (! $advancePayment instanceof Payment) {
            return;
        }

        $oldAmount = round((float) $advancePayment->amount, 2);
        $diff = round($newDownPayment - $oldAmount, 2);

        if (abs($diff) <= 0.009) {
            return;
        }

        // Update the payment amount and its allocation.
        $advancePayment->update(['amount' => $newDownPayment]);

        PaymentAllocation::query()
            ->where('payment_id', $advancePayment->id)
            ->where('sales_invoice_id', $invoice->id)
            ->update(['allocated_amount' => $newDownPayment]);

        // Reverse old GL journal and post a new one.
        $entryIds = JournalEntry::query()
            ->where('source', 'sales_payment')
            ->where('reference_type', Payment::class)
            ->where('reference_id', $advancePayment->id)
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();

        if ($entryIds !== []) {
            JournalLine::query()->whereIn('journal_entry_id', $entryIds)->delete();
            JournalEntry::query()->whereIn('id', $entryIds)->delete();
        }

        if ($newDownPayment > 0.009 && $advancePayment->payment_method_id) {
            $this->journalService->postCustomerPaymentGl(
                (int) $invoice->branch_id,
                $newDownPayment,
                (int) $advancePayment->payment_method_id,
                $advancePayment->fresh(),
                $actorUserId,
                requireWallet: false,
            );
        }

        // Fix AR ledger entry for this advance payment.
        AccountsReceivableLedger::query()
            ->where('reference_id', $advancePayment->id)
            ->where(function ($q): void {
                $q->where('reference_type', Payment::class)
                    ->orWhere('reference_type', 'Payment');
            })
            ->update(['amount_delta' => -$newDownPayment]);

        // Fix invoice amount_paid and customer outstanding balance.
        $invoice->update([
            'amount_paid' => round(max(0, (float) $invoice->amount_paid - $oldAmount + $newDownPayment), 2),
        ]);

        Customer::query()
            ->whereKey((int) $invoice->customer_id)
            ->increment('outstanding_balance', round($oldAmount - $newDownPayment, 2));
    }

    private function createSchedulesForPlan(
        InstallmentPlan $plan,
        SalesInvoice $invoice,
        int $count,
        float $financedAmount,
    ): void {
        $baseAmount = round($financedAmount / $count, 2);
        $scheduledTotal = $baseAmount * $count;
        $delta = round($financedAmount - $scheduledTotal, 2);
        $startDate = Carbon::parse($invoice->installment_first_due_date);
        $frequency = (string) ($invoice->installment_frequency ?? 'monthly');
        $customDays = (int) ($invoice->installment_custom_days ?? 0);

        for ($i = 1; $i <= $count; $i++) {
            $amount = $baseAmount;
            if ($i === $count) {
                $amount = round($amount + $delta, 2);
            }

            InstallmentSchedule::create([
                'installment_plan_id' => $plan->id,
                'installment_no' => $i,
                'due_date' => $this->computeInstallmentDueDate($startDate, $i - 1, $frequency, $customDays)->toDateString(),
                'due_amount' => $amount,
            ]);
        }
    }

    private function computeInstallmentDueDate(Carbon $startDate, int $offset, string $frequency, int $customDays): Carbon
    {
        return match ($frequency) {
            'daily' => $startDate->copy()->addDays($offset),
            'weekly' => $startDate->copy()->addWeeks($offset),
            'custom' => $startDate->copy()->addDays($offset * max($customDays, 1)),
            default => $startDate->copy()->addMonths($offset),
        };
    }
}
