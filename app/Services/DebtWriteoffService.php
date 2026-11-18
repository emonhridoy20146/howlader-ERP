<?php

namespace App\Services;

use App\Exceptions\InvalidStateException;
use App\Models\AccountsReceivableLedger;
use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\InstallmentPlan;
use App\Models\InstallmentSchedule;
use App\Models\InstallmentWriteoffAllocation;
use App\Models\InvoiceDebtWriteoff;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\SalesInvoice;
use Illuminate\Support\Facades\DB;

class DebtWriteoffService
{
    public function __construct(
        private readonly JournalService $journalService,
    ) {}

    /**
     * Reduce invoice receivable without cash: updates AR sub-ledger, customer balance, optional installment schedules, and GL.
     *
     * @throws InvalidStateException
     */
    public function writeOffInvoiceReceivable(
        SalesInvoice $invoice,
        float $amount,
        ?string $reason,
        int $actorUserId,
    ): InvoiceDebtWriteoff {
        return DB::transaction(function () use ($invoice, $amount, $reason, $actorUserId): InvoiceDebtWriteoff {
            $invoice = SalesInvoice::lockForUpdate()->findOrFail($invoice->id);

            if (! $invoice->isPayable() || $invoice->amount_due <= 0) {
                throw new InvalidStateException('Debt write-off is only allowed on open receivables (posted/active, not fully paid).');
            }

            $writeAmount = round($amount, 2);
            if ($writeAmount <= 0) {
                throw new InvalidStateException('Write-off amount must be greater than zero.');
            }

            if ($writeAmount > round((float) $invoice->amount_due, 2) + 0.001) {
                throw new InvalidStateException('Write-off cannot exceed the invoice amount due.');
            }

            $beforeSnapshot = [
                'status' => $invoice->status,
                'payment_status' => $invoice->payment_status,
                'amount_paid' => $invoice->amount_paid,
                'amount_due' => $invoice->amount_due,
            ];

            $writeoff = InvoiceDebtWriteoff::create([
                'sales_invoice_id' => $invoice->id,
                'amount' => $writeAmount,
                'reason' => $reason,
                'actor_user_id' => $actorUserId,
            ]);

            $this->journalService->postInvoiceDebtWriteoffGl($writeoff, $invoice, $actorUserId);

            AccountsReceivableLedger::create([
                'customer_id' => $invoice->customer_id,
                'event_time' => now(),
                'event_type' => 'writeoff',
                'amount_delta' => -$writeAmount,
                'reference_type' => InvoiceDebtWriteoff::class,
                'reference_id' => $writeoff->id,
                'actor_user_id' => $actorUserId,
            ]);

            Customer::where('id', $invoice->customer_id)->decrement('outstanding_balance', $writeAmount);

            $newAmountPaid = round((float) $invoice->amount_paid + $writeAmount, 2);
            $newAmountDue = round((float) $invoice->grand_total - $newAmountPaid, 2);
            if ($newAmountDue < 0) {
                $newAmountDue = 0;
            }

            $invoice->update([
                'amount_paid' => $newAmountPaid,
                'amount_due' => $newAmountDue,
                'payment_status' => $newAmountDue <= 0.001 ? 'paid' : 'partial',
            ]);

            if ($invoice->sale_type === 'installment') {
                $plan = InstallmentPlan::query()
                    ->where('sales_invoice_id', $invoice->id)
                    ->lockForUpdate()
                    ->first();
                if ($plan) {
                    $this->applyInstallmentWriteoffWaterfall($plan, $writeAmount, $writeoff);
                }
            }

            $invoice->refresh();

            AuditLog::create([
                'actor_user_id' => $actorUserId,
                'action' => 'sales_invoice.debt_writeoff',
                'entity_type' => 'SalesInvoice',
                'entity_id' => $invoice->id,
                'before_snapshot' => $beforeSnapshot,
                'after_snapshot' => [
                    'status' => $invoice->status,
                    'payment_status' => $invoice->payment_status,
                    'amount_paid' => $invoice->amount_paid,
                    'amount_due' => $invoice->amount_due,
                    'writeoff_id' => $writeoff->id,
                    'writeoff_amount' => $writeAmount,
                ],
                'ip_address' => request()->ip(),
            ]);

            return $writeoff;
        });
    }

    /**
     * Reverse a previously applied debt write-off: restores the invoice balance,
     * undoes exactly what it applied to installment schedules (via the recorded
     * {@see InstallmentWriteoffAllocation} rows), and removes its ledger and
     * journal entries. Mirrors {@see \App\Services\PaymentService::removeAllocationFromInvoice}'s
     * "removal deletes the underlying rows" convention rather than posting a
     * reversing entry.
     *
     * @throws InvalidStateException
     */
    public function reverseInvoiceDebtWriteoff(InvoiceDebtWriteoff $writeoff, int $actorUserId): void
    {
        DB::transaction(function () use ($writeoff, $actorUserId): void {
            $writeoff = InvoiceDebtWriteoff::query()->lockForUpdate()->findOrFail($writeoff->id);
            $invoice = SalesInvoice::query()->lockForUpdate()->findOrFail($writeoff->sales_invoice_id);

            if (! in_array($invoice->status, ['posted', 'active'], true)) {
                throw new InvalidStateException('Write-offs can only be removed from posted or active invoices.');
            }

            $writeAmount = round((float) $writeoff->amount, 2);
            if ($writeAmount <= 0) {
                throw new InvalidStateException('Nothing to reverse for this write-off.');
            }

            $beforeSnapshot = [
                'status' => $invoice->status,
                'payment_status' => $invoice->payment_status,
                'amount_paid' => $invoice->amount_paid,
                'amount_due' => $invoice->amount_due,
            ];

            if ($invoice->sale_type === 'installment') {
                $allocations = InstallmentWriteoffAllocation::query()
                    ->where('invoice_debt_writeoff_id', $writeoff->id)
                    ->get();

                foreach ($allocations as $allocation) {
                    $schedule = InstallmentSchedule::query()->lockForUpdate()->findOrFail($allocation->installment_schedule_id);

                    $newPaid = round((float) $schedule->paid_amount - (float) $allocation->amount, 2);
                    if ($newPaid < -0.01) {
                        throw new InvalidStateException('Cannot safely remove this write-off: installment #'.$schedule->installment_no.' has since changed.');
                    }

                    $schedule->paid_amount = max(0, $newPaid);
                    $schedule->status = $schedule->computeStatus();
                    $schedule->save();
                }

                InstallmentWriteoffAllocation::query()->where('invoice_debt_writeoff_id', $writeoff->id)->delete();

                $plan = InstallmentPlan::query()
                    ->where('sales_invoice_id', $invoice->id)
                    ->lockForUpdate()
                    ->first();
                if ($plan) {
                    $this->refreshInstallmentPlanTotals($plan->fresh(['schedules']));
                }
            }

            $newAmountPaid = round((float) $invoice->amount_paid - $writeAmount, 2);
            if ($newAmountPaid < -0.01) {
                throw new InvalidStateException('Removing this write-off would make the invoice paid amount invalid.');
            }
            $newAmountPaid = max(0, $newAmountPaid);
            $grandTotal = round((float) $invoice->grand_total, 2);
            $newAmountDue = round(max(0, $grandTotal - $newAmountPaid), 2);

            $invoice->update([
                'amount_paid' => $newAmountPaid,
                'amount_due' => $newAmountDue,
                'payment_status' => match (true) {
                    $newAmountDue < 0.009 => 'paid',
                    $newAmountPaid < 0.009 => 'unpaid',
                    default => 'partial',
                },
            ]);

            $entryIds = JournalEntry::query()
                ->where('reference_type', InvoiceDebtWriteoff::class)
                ->where('reference_id', $writeoff->id)
                ->pluck('id');
            if ($entryIds->isNotEmpty()) {
                JournalLine::query()->whereIn('journal_entry_id', $entryIds)->delete();
                JournalEntry::query()->whereIn('id', $entryIds)->delete();
            }

            AccountsReceivableLedger::query()
                ->where('reference_type', InvoiceDebtWriteoff::class)
                ->where('reference_id', $writeoff->id)
                ->delete();

            Customer::where('id', $invoice->customer_id)->increment('outstanding_balance', $writeAmount);

            $writeoff->delete();

            $invoice->refresh();

            AuditLog::create([
                'actor_user_id' => $actorUserId,
                'action' => 'sales_invoice.debt_writeoff_removed',
                'entity_type' => 'SalesInvoice',
                'entity_id' => $invoice->id,
                'before_snapshot' => $beforeSnapshot,
                'after_snapshot' => [
                    'status' => $invoice->status,
                    'payment_status' => $invoice->payment_status,
                    'amount_paid' => $invoice->amount_paid,
                    'amount_due' => $invoice->amount_due,
                    'writeoff_id_removed' => $writeoff->id,
                    'writeoff_amount' => $writeAmount,
                ],
                'ip_address' => request()->ip(),
            ]);
        });
    }

    private function applyInstallmentWriteoffWaterfall(InstallmentPlan $plan, float $amount, InvoiceDebtWriteoff $writeoff): void
    {
        $schedules = InstallmentSchedule::query()
            ->where('installment_plan_id', $plan->id)
            ->lockForUpdate()
            ->orderBy('installment_no')
            ->get();

        $remaining = round($amount, 2);
        foreach ($schedules as $schedule) {
            if ($remaining <= 0.001) {
                break;
            }

            $cap = $schedule->remainingDue();
            if ($cap <= 0.001) {
                continue;
            }

            $apply = min($remaining, $cap);
            $schedule->paid_amount = round((float) $schedule->paid_amount + $apply, 2);
            $schedule->status = $schedule->computeStatus();
            $schedule->save();

            InstallmentWriteoffAllocation::create([
                'installment_schedule_id' => $schedule->id,
                'invoice_debt_writeoff_id' => $writeoff->id,
                'amount' => $apply,
            ]);

            $remaining = round($remaining - $apply, 2);
        }

        $this->refreshInstallmentPlanTotals($plan->fresh());
    }

    private function refreshInstallmentPlanTotals(InstallmentPlan $plan): void
    {
        $plan->load('schedules');
        $owing = (float) $plan->schedules->sum(fn (InstallmentSchedule $s) => $s->remainingDue());
        $plan->remaining_amount = max(0, round($owing, 2));
        $plan->status = $owing < 0.02 ? 'completed' : 'active';
        $plan->save();
    }
}
