<?php

namespace App\Services;

use App\Exceptions\InvalidStateException;
use App\Exceptions\OverpaymentException;
use App\Models\AccountsReceivableLedger;
use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\InstallmentPaymentAllocation;
use App\Models\InstallmentPlan;
use App\Models\InstallmentSchedule;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Models\PaymentMethod;
use App\Models\ReturnRefundAllocation;
use App\Models\SalesInvoice;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class PaymentService
{
    public function __construct(
        private readonly JournalService $journalService,
        private readonly DocumentNumberGenerator $documentNumbers,
        private readonly InstallmentInvoiceSyncService $installmentSync,
    ) {}

    public function recordAndAllocate(SalesInvoice $invoice, array $paymentData, int $actorUserId): Payment
    {
        return DB::transaction(function () use ($invoice, $paymentData, $actorUserId): Payment {
            $installmentTarget = (string) ($paymentData['installment_target'] ?? 'waterfall');
            if ($installmentTarget !== 'advance'
                && InstallmentAdvanceService::isAdvanceReference($paymentData['reference'] ?? null)) {
                $installmentTarget = 'advance';
            }
            $installmentScheduleId = isset($paymentData['installment_schedule_id']) && (int) $paymentData['installment_schedule_id'] > 0
                ? (int) $paymentData['installment_schedule_id']
                : null;
            if ($installmentTarget !== 'schedule') {
                $installmentScheduleId = null;
            }

            $invoice = SalesInvoice::lockForUpdate()->findOrFail($invoice->id);
            $selectedMethod = null;
            if (! empty($paymentData['payment_method_id'])) {
                $selectedMethod = PaymentMethod::query()->findOrFail((int) $paymentData['payment_method_id']);
            }

            $cashAmount = round(max(0, (float) ($paymentData['amount'] ?? 0)), 2);
            $discountAmount = round(max(0, (float) ($paymentData['settlement_discount'] ?? 0)), 2);
            $totalApplied = round($cashAmount + $discountAmount, 2);

            if ($totalApplied <= 0) {
                throw new InvalidStateException('Payment total must be greater than zero.');
            }

            $skipWalletGl = ! empty($paymentData['skip_wallet_gl']);
            $historicalPayment = ! empty($paymentData['historical_payment']);
            $backfillOnly = ! empty($paymentData['backfill_only']) || $historicalPayment;
            $preserveInvoiceTotals = $backfillOnly;
            $allowFlexibleCollection = ! empty($paymentData['allow_flexible_collection']);
            $due = round((float) $invoice->amount_due, 2);
            $maxApplicable = $preserveInvoiceTotals
                ? $invoice->fresh()->unrecordedPaymentAmount()
                : $due;

            if (! $allowFlexibleCollection && $totalApplied > $maxApplicable + 0.001) {
                throw new OverpaymentException(
                    $preserveInvoiceTotals
                        ? 'Amount exceeds the unrecorded paid balance on this invoice.'
                        : 'Payment exceeds amount due.',
                );
            }

            if (! $allowFlexibleCollection && ! $preserveInvoiceTotals && $invoice->requiresFullPaymentOnCollection() && $totalApplied < $due - 0.001) {
                throw new InvalidStateException(
                    'Cash sales must be paid in full. Collect the full outstanding balance ('.number_format($due, 2).').',
                );
            }

            $method = $selectedMethod
                ? $this->mapMethodToPaymentEnum($selectedMethod)
                : $this->normalizeMethod((string) ($paymentData['method'] ?? 'cash'));
            $beforeSnapshot = [
                'status' => $invoice->status,
                'payment_status' => $invoice->payment_status,
                'amount_paid' => $invoice->amount_paid,
                'amount_due' => $invoice->amount_due,
            ];

            $paymentDate = isset($paymentData['date']) ? Carbon::parse((string) $paymentData['date']) : now();

            if (
                $invoice->sale_type === 'installment'
                && ! $backfillOnly
                && ! $historicalPayment
                && ! InstallmentAdvanceService::isAdvanceReference($paymentData['reference'] ?? null)
                && ! app('auth')->user()?->hasPermission('installment.allow-same-day-duplicate')
            ) {
                $todayDate = $paymentDate->toDateString();
                $duplicateExists = Payment::query()
                    ->where('direction', 'inbound')
                    ->whereDate('payment_date', $todayDate)
                    ->where(fn ($q) => $q->whereNull('reference_no')->orWhere('reference_no', '!=', InstallmentAdvanceService::REFERENCE))
                    ->whereHas('paymentAllocations', fn ($q) => $q->where('sales_invoice_id', $invoice->id))
                    ->exists();

                if ($duplicateExists) {
                    throw new InvalidStateException(
                        'A payment has already been recorded today for this installment invoice. Only one payment per day is allowed per invoice.'
                    );
                }
            }

            $payment = Payment::create([
                'payment_number' => $this->documentNumbers->next(
                    DocumentNumberGenerator::TYPE_PAYMENT_INBOUND,
                    'PAY',
                    $paymentDate,
                    'Ymd',
                    5,
                ),
                'customer_id' => $invoice->customer_id,
                'payment_date' => $paymentData['date'] ?? now()->toDateString(),
                'payment_method' => $method,
                'payment_method_id' => $selectedMethod?->id,
                'amount' => $cashAmount,
                'direction' => 'inbound',
                'reference_no' => $paymentData['reference'] ?? null,
                'notes' => match (true) {
                    $skipWalletGl => Payment::formatSkipWalletGlNotes($paymentData['notes'] ?? null, $backfillOnly),
                    $historicalPayment => Payment::formatHistoricalPaymentNotes($paymentData['notes'] ?? null, $backfillOnly),
                    default => $paymentData['notes'] ?? null,
                },
                'recorded_by' => $actorUserId,
                'referrer_employee_id' => isset($paymentData['referrer_employee_id']) && (int) $paymentData['referrer_employee_id'] > 0
                    ? (int) $paymentData['referrer_employee_id']
                    : null,
            ]);

            $allocation = PaymentAllocation::create([
                'payment_id' => $payment->id,
                'sales_invoice_id' => $invoice->id,
                'allocated_amount' => $cashAmount,
                'settlement_discount' => $discountAmount,
            ]);

            $newAmountPaid = round((float) $invoice->amount_paid + $totalApplied, 2);

            if (! $preserveInvoiceTotals) {
                $invoice->update([
                    'amount_paid' => $newAmountPaid,
                ]);

                AccountsReceivableLedger::create([
                    'customer_id' => $invoice->customer_id,
                    'event_time' => $skipWalletGl ? $paymentDate : now(),
                    'event_type' => 'payment_allocated',
                    'amount_delta' => -$totalApplied,
                    'reference_type' => 'Payment',
                    'reference_id' => $payment->id,
                    'actor_user_id' => $actorUserId,
                ]);

                Customer::where('id', $invoice->customer_id)->decrement('outstanding_balance', $totalApplied);
            }

            $invoice->refresh();

            if ($cashAmount > 0 && ! $selectedMethod) {
                throw new InvalidStateException('Payment method is required to record cash collection.');
            }

            if ($cashAmount > 0 && $selectedMethod) {
                if ($skipWalletGl) {
                    $posted = $this->journalService->postHistoricalCustomerPaymentGl(
                        (int) $invoice->branch_id,
                        $cashAmount,
                        $payment,
                        $actorUserId,
                    );

                    if (! $posted && ! $this->journalService->salesPaymentJournalEntry($payment)) {
                        throw new InvalidStateException('Could not post historical payment to the general ledger.');
                    }
                } elseif ($preserveInvoiceTotals) {
                    $wallet = $this->journalService->postHistoricalPaymentToWalletGl(
                        (int) $invoice->branch_id,
                        $cashAmount,
                        (int) $payment->payment_method_id,
                        $payment,
                        $actorUserId,
                    );

                    if (! $wallet) {
                        $wallet = $this->journalService->resolveWalletForPayment(
                            (int) $invoice->branch_id,
                            (int) $payment->payment_method_id,
                        );

                        if (! $wallet && ! $this->journalService->salesPaymentJournalEntry($payment)) {
                            throw new InvalidStateException('Could not post historical payment to the general ledger.');
                        }
                    }

                    if ($wallet) {
                        $payment->update(['wallet_id' => $wallet->id]);
                    }
                } else {
                    $posted = $this->journalService->postCustomerPaymentGl(
                        (int) $invoice->branch_id,
                        $cashAmount,
                        (int) $payment->payment_method_id,
                        $payment,
                        $actorUserId,
                        requireWallet: true,
                    );

                    if (! $posted) {
                        throw new InvalidStateException('Could not post payment to the general ledger.');
                    }

                    $wallet = $this->journalService->resolveWalletForPayment(
                        (int) $invoice->branch_id,
                        (int) $payment->payment_method_id,
                    );
                    if ($wallet) {
                        $payment->update(['wallet_id' => $wallet->id]);
                    }
                }
            }

            if (! $preserveInvoiceTotals && $discountAmount > 0) {
                $this->journalService->postPaymentSettlementDiscountGl(
                    $invoice,
                    $allocation,
                    $discountAmount,
                    $actorUserId,
                    $payment->payment_date->toDateString(),
                );
            }

            if (! $preserveInvoiceTotals) {
                if ($installmentTarget !== 'advance') {
                    $this->syncInstallmentAllocationsFromPayment(
                        $invoice,
                        $payment,
                        $totalApplied,
                        $installmentTarget,
                        $installmentScheduleId,
                        $allowFlexibleCollection,
                    );
                } elseif ($invoice->sale_type === 'installment') {
                    $recordedAdvance = app(InstallmentAdvanceService::class)->recordedAdvanceTotal($invoice->fresh());
                    $configuredAdvance = round(max(0, (float) ($invoice->installment_down_payment ?? 0)), 2);
                    if ($recordedAdvance + 0.009 > $configuredAdvance) {
                        $invoice->update(['installment_down_payment' => $recordedAdvance]);
                    }
                }

                if ($invoice->sale_type === 'installment') {
                    $this->installmentSync->syncInvoiceHeaderFromPlan($invoice->fresh(['installmentPlan.schedules']));
                } else {
                    $newAmountDue = round((float) $invoice->grand_total - $newAmountPaid, 2);
                    if ($newAmountDue < 0) {
                        $newAmountDue = 0;
                    }

                    $invoice->update([
                        'amount_due' => $newAmountDue,
                        'payment_status' => $newAmountDue <= 0 ? 'paid' : 'partial',
                    ]);
                }

                $invoice->refresh();

                $this->assignInvoiceNumberWhenCollected($invoice);
                $invoice->refresh();
            }

            $auditAction = match (true) {
                $backfillOnly => 'sales_invoice.historical_payment_backfilled',
                $historicalPayment, $skipWalletGl => 'sales_invoice.historical_payment_allocated',
                default => 'sales_invoice.payment_allocated',
            };

            AuditLog::create([
                'actor_user_id' => $actorUserId,
                'action' => $auditAction,
                'entity_type' => 'SalesInvoice',
                'entity_id' => $invoice->id,
                'before_snapshot' => $beforeSnapshot,
                'after_snapshot' => [
                    'status' => $invoice->status,
                    'payment_status' => $invoice->payment_status,
                    'amount_paid' => $invoice->amount_paid,
                    'amount_due' => $invoice->amount_due,
                    'payment_id' => $payment->id,
                    'payment_method' => $payment->payment_method,
                    'payment_method_id' => $payment->payment_method_id,
                    'cash_amount' => $cashAmount,
                    'settlement_discount' => $discountAmount,
                    'skip_wallet_gl' => $skipWalletGl,
                    'historical_payment' => $historicalPayment,
                    'backfill_only' => $backfillOnly,
                    'payment_date' => $payment->payment_date?->toDateString(),
                ],
                'ip_address' => request()->ip(),
            ]);

            return $payment;
        });
    }

    /**
     * Change how an inbound payment was collected (updates GL wallet debit; AR credit unchanged).
     */
    public function changePaymentMethod(
        SalesInvoice $invoice,
        Payment $payment,
        int $newPaymentMethodId,
        int $actorUserId,
    ): void {
        DB::transaction(function () use ($invoice, $payment, $newPaymentMethodId, $actorUserId): void {
            $invoice = SalesInvoice::query()->lockForUpdate()->findOrFail($invoice->id);
            $payment = Payment::query()->lockForUpdate()->findOrFail($payment->id);

            if (! in_array($invoice->status, ['posted', 'active'], true)) {
                throw new InvalidStateException('Payment method can only be changed on posted or active invoices.');
            }

            if ((int) $payment->customer_id !== (int) $invoice->customer_id) {
                throw new InvalidStateException('Payment does not belong to this invoice.');
            }

            $allocation = PaymentAllocation::query()
                ->where('payment_id', $payment->id)
                ->where('sales_invoice_id', $invoice->id)
                ->lockForUpdate()
                ->first();

            if (! $allocation) {
                throw new InvalidStateException('Payment is not allocated to this invoice.');
            }

            if (PaymentAllocation::query()->where('payment_id', $payment->id)->where('id', '!=', $allocation->id)->exists()) {
                throw new InvalidStateException('Changing payment method for payments split across invoices is not supported.');
            }

            if (ReturnRefundAllocation::query()->where('payment_id', $payment->id)->exists()) {
                throw new InvalidStateException('Cannot change payment method for a payment linked to a return or refund.');
            }

            if ($payment->skipsWalletGl()) {
                throw new InvalidStateException('Cannot change payment method for a historical payment (wallet was not updated).');
            }

            $selectedMethod = PaymentMethod::query()->findOrFail($newPaymentMethodId);
            $available = PaymentMethod::availableForSalesAtLocation((int) $invoice->branch_id);
            if (! $available->pluck('id')->contains($selectedMethod->id)) {
                throw new InvalidStateException('Selected payment method is not available for this invoice branch.');
            }

            if ((int) $payment->payment_method_id === $selectedMethod->id) {
                return;
            }

            $cashAmount = round((float) $payment->amount, 2);
            $beforeSnapshot = [
                'payment_id' => $payment->id,
                'payment_number' => $payment->payment_number,
                'payment_method_id' => $payment->payment_method_id,
                'payment_method' => $payment->payment_method,
            ];

            $this->deleteJournalEntriesByReference('sales_payment', Payment::class, $payment->id);

            $payment->update([
                'payment_method_id' => $selectedMethod->id,
                'payment_method' => $this->mapMethodToPaymentEnum($selectedMethod),
            ]);

            $freshPayment = $payment->fresh();
            $wallet = null;

            if ($cashAmount > 0) {
                if ($freshPayment->isHistoricalPayment()) {
                    $wallet = $this->journalService->postHistoricalPaymentToWalletGl(
                        (int) $invoice->branch_id,
                        $cashAmount,
                        $selectedMethod->id,
                        $freshPayment,
                        $actorUserId,
                    );
                } else {
                    $posted = $this->journalService->postCustomerPaymentGl(
                        (int) $invoice->branch_id,
                        $cashAmount,
                        $selectedMethod->id,
                        $freshPayment,
                        $actorUserId,
                        requireWallet: true,
                    );

                    if (! $posted) {
                        throw new InvalidStateException('Could not post payment to the general ledger for the new method.');
                    }

                    $wallet = $this->journalService->resolveWalletForPayment(
                        (int) $invoice->branch_id,
                        $selectedMethod->id,
                    );
                }

                if (! $wallet) {
                    throw new InvalidStateException('Could not post payment to the general ledger for the new method.');
                }

                $payment->update(['wallet_id' => $wallet->id]);
            }

            AuditLog::create([
                'actor_user_id' => $actorUserId,
                'action' => 'sales_invoice.payment_method_changed',
                'entity_type' => SalesInvoice::class,
                'entity_id' => $invoice->id,
                'before_snapshot' => $beforeSnapshot,
                'after_snapshot' => [
                    'payment_id' => $payment->id,
                    'payment_method_id' => $payment->payment_method_id,
                    'payment_method' => $payment->payment_method,
                    'wallet_id' => $payment->wallet_id,
                ],
                'ip_address' => request()->ip(),
            ]);
        });
    }

    /**
     * Remove an inbound payment allocation from an invoice (reverses AR, installment schedule slices, journals, deletes the Payment when it has no other links).
     */
    public function removeAllocationFromInvoice(
        SalesInvoice $invoice,
        PaymentAllocation $allocation,
        int $actorUserId,
    ): void {
        DB::transaction(function () use ($invoice, $allocation, $actorUserId): void {
            $allocation = PaymentAllocation::query()->lockForUpdate()->findOrFail($allocation->id);
            $invoice = SalesInvoice::query()->lockForUpdate()->findOrFail($invoice->id);

            if ((int) $allocation->sales_invoice_id !== (int) $invoice->id) {
                throw new InvalidStateException('Payment does not belong to this invoice.');
            }

            if (! in_array($invoice->status, ['posted', 'active'], true)) {
                throw new InvalidStateException('Payments can only be removed from posted or active invoices.');
            }

            $paymentId = (int) $allocation->payment_id;
            $payment = Payment::query()->lockForUpdate()->find($paymentId);
            $orphanedPayment = ! $payment instanceof Payment;

            if (! $orphanedPayment) {
                if ((int) $payment->customer_id !== (int) $invoice->customer_id) {
                    throw new InvalidStateException('Payment customer does not match the invoice.');
                }

                if (PaymentAllocation::query()->where('payment_id', $payment->id)->where('id', '!=', $allocation->id)->exists()) {
                    throw new InvalidStateException('Removing payments split across multiple invoices is not supported.');
                }

                if (ReturnRefundAllocation::query()->where('payment_id', $payment->id)->exists()) {
                    throw new InvalidStateException('Cannot remove a payment linked to a return or refund.');
                }
            } else {
                if (PaymentAllocation::query()->where('payment_id', $paymentId)->where('id', '!=', $allocation->id)->exists()) {
                    throw new InvalidStateException('Removing payments split across multiple invoices is not supported.');
                }

                if (ReturnRefundAllocation::query()->where('payment_id', $paymentId)->exists()) {
                    throw new InvalidStateException('Cannot remove a payment linked to a return or refund.');
                }
            }

            $cash = round((float) $allocation->allocated_amount, 2);
            $discount = round((float) ($allocation->settlement_discount ?? 0), 2);
            $totalApplied = round($cash + $discount, 2);
            if ($totalApplied <= 0) {
                throw new InvalidStateException('Nothing to reverse for this allocation.');
            }

            $isBackfill = ! $orphanedPayment && ($payment->isPaymentBackfill() || $payment->isHistoricalPayment());

            $beforeSnapshot = [
                'amount_paid' => $invoice->amount_paid,
                'amount_due' => $invoice->amount_due,
                'payment_status' => $invoice->payment_status,
                'payment_number' => $orphanedPayment ? null : $payment->payment_number,
                'payment_id' => $paymentId,
                'allocation_cash' => $cash,
                'allocation_discount' => $discount,
                'backfill_only' => $isBackfill,
                'orphaned_payment' => $orphanedPayment,
            ];

            if (! $isBackfill) {
                $newPaid = round((float) $invoice->amount_paid - $totalApplied, 2);
                if ($newPaid < -0.009) {
                    throw new InvalidStateException('Removing this payment would make the invoice paid amount invalid.');
                }
            }

            if (! $isBackfill && $invoice->sale_type === 'installment') {
                $ipas = InstallmentPaymentAllocation::query()
                    ->where('payment_id', $paymentId)
                    ->lockForUpdate()
                    ->orderBy('id')
                    ->get();

                $affectedScheduleIds = [];

                foreach ($ipas as $ipa) {
                    $schedule = InstallmentSchedule::query()->lockForUpdate()->findOrFail((int) $ipa->installment_schedule_id);
                    $plan = InstallmentPlan::query()
                        ->whereKey((int) $schedule->installment_plan_id)
                        ->lockForUpdate()
                        ->firstOrFail();

                    if ((int) $plan->sales_invoice_id !== (int) $invoice->id) {
                        throw new InvalidStateException('Installment allocation tied to another invoice.');
                    }

                    $affectedScheduleIds[(int) $schedule->id] = true;
                    $ipa->delete();
                }

                foreach (array_keys($affectedScheduleIds) as $scheduleId) {
                    $schedule = InstallmentSchedule::query()->lockForUpdate()->findOrFail($scheduleId);
                    $remainingPaid = (float) InstallmentPaymentAllocation::query()
                        ->where('installment_schedule_id', $scheduleId)
                        ->sum('allocated_amount');

                    $schedule->paid_amount = round(max(0, $remainingPaid), 2);
                    $schedule->status = $schedule->computeStatus();
                    $schedule->save();
                }

                $plan = InstallmentPlan::query()->where('sales_invoice_id', $invoice->id)->first();
                if ($plan instanceof InstallmentPlan) {
                    $this->refreshInstallmentPlanTotals($plan->fresh(['schedules']));
                }
            } elseif (! $isBackfill) {
                if (InstallmentPaymentAllocation::query()->where('payment_id', $paymentId)->exists()) {
                    throw new InvalidStateException('This payment still has installment links; reconcile before removing.');
                }
            }

            $this->deleteJournalEntriesByReference('sales_payment_discount', PaymentAllocation::class, $allocation->id);
            $this->deleteJournalEntriesByReference('sales_payment', Payment::class, $paymentId);

            if (! $isBackfill) {
                AccountsReceivableLedger::query()
                    ->where('reference_id', $paymentId)
                    ->where(function ($q): void {
                        $q->where('reference_type', Payment::class)
                            ->orWhere('reference_type', 'Payment');
                    })
                    ->delete();

                Customer::where('id', $invoice->customer_id)->increment('outstanding_balance', $totalApplied);

                $newPaid = round((float) $invoice->amount_paid - $totalApplied, 2);
                $grandTotal = round((float) $invoice->grand_total, 2);
                $newDue = round(max(0, $grandTotal - max(0, $newPaid)), 2);

                if ($invoice->sale_type === 'installment') {
                    $invoice->update([
                        'amount_paid' => max(0, $newPaid),
                    ]);
                    $this->installmentSync->syncInvoiceHeaderFromPlan($invoice->fresh(['installmentPlan.schedules']));
                } else {
                    $paymentStatus = match (true) {
                        $newDue < 0.009 => 'paid',
                        $newPaid < 0.009 => 'unpaid',
                        default => 'partial',
                    };

                    $invoice->update([
                        'amount_paid' => max(0, $newPaid),
                        'amount_due' => $newDue,
                        'payment_status' => $paymentStatus,
                    ]);
                }
            }

            // Always drop the allocation row; deleting only the payment leaves an orphan allocation
            // (no FK cascade) that keeps inflating allocation totals for the invoice.
            $allocation->delete();

            if (! $orphanedPayment) {
                Payment::query()->whereKey($payment->id)->delete();
            }

            $paymentKey = $paymentId;

            $invoiceFresh = $invoice->fresh();

            AuditLog::create([
                'actor_user_id' => $actorUserId,
                'action' => $isBackfill ? 'sales_invoice.historical_payment_backfill_removed' : 'sales_invoice.payment_removed',
                'entity_type' => SalesInvoice::class,
                'entity_id' => $invoice->id,
                'before_snapshot' => $beforeSnapshot,
                'after_snapshot' => [
                    'status' => $invoiceFresh?->status,
                    'payment_status' => $invoiceFresh?->payment_status,
                    'amount_paid' => $invoiceFresh?->amount_paid,
                    'amount_due' => $invoiceFresh?->amount_due,
                    'payment_id_removed' => $paymentKey,
                ],
                'ip_address' => request()->ip(),
            ]);
        });
    }

    private function deleteJournalEntriesByReference(string $source, string $referenceType, int $referenceId): void
    {
        $entryIds = JournalEntry::query()
            ->where('source', $source)
            ->where('reference_type', $referenceType)
            ->where('reference_id', $referenceId)
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();

        if ($entryIds !== []) {
            JournalLine::query()->whereIn('journal_entry_id', $entryIds)->delete();
            JournalEntry::query()->whereIn('id', $entryIds)->delete();
        }
    }

    public function assignInvoiceNumberWhenCollected(SalesInvoice $invoice): void
    {
        if ($invoice->invoice_number !== null && trim((string) $invoice->invoice_number) !== '') {
            return;
        }

        if (! in_array($invoice->payment_status, ['partial', 'paid'], true)) {
            return;
        }

        $invoice->forceFill([
            'invoice_number' => $this->documentNumbers->nextSimpleSequence(
                DocumentNumberGenerator::TYPE_SALES_INVOICE,
                'INV',
                5,
            ),
        ])->save();
    }

    public function allocateUnlinkedPaymentsToInstallmentPlan(SalesInvoice $invoice): void
    {
        if ($invoice->sale_type !== 'installment') {
            return;
        }

        DB::transaction(function () use ($invoice): void {
            $invoice = SalesInvoice::query()->lockForUpdate()->findOrFail($invoice->id);
            $plan = InstallmentPlan::query()
                ->where('sales_invoice_id', $invoice->id)
                ->lockForUpdate()
                ->first();

            if (! $plan instanceof InstallmentPlan) {
                return;
            }

            $allocations = PaymentAllocation::query()
                ->where('sales_invoice_id', $invoice->id)
                ->orderBy('id')
                ->get();

            foreach ($allocations as $allocation) {
                $payment = Payment::query()->lockForUpdate()->find((int) $allocation->payment_id);
                if (! $payment) {
                    continue;
                }

                if (InstallmentAdvanceService::isAdvanceReference($payment->reference_no)) {
                    continue;
                }

                if ($payment->isPaymentBackfill() || $payment->isHistoricalPayment()) {
                    continue;
                }

                $alreadyAllocated = round((float) InstallmentPaymentAllocation::query()
                    ->where('payment_id', $payment->id)
                    ->sum('allocated_amount'), 2);
                $totalApplied = round(
                    (float) $allocation->allocated_amount + (float) ($allocation->settlement_discount ?? 0),
                    2,
                );
                $remaining = round($totalApplied - $alreadyAllocated, 2);

                if ($remaining <= 0.001) {
                    continue;
                }

                $this->syncInstallmentAllocationsFromPayment(
                    $invoice,
                    $payment,
                    $remaining,
                    'waterfall',
                    null,
                );
            }

            $this->installmentSync->syncInvoiceHeaderFromPlan($invoice->fresh(['installmentPlan.schedules']));
        });
    }

    private function syncInstallmentAllocationsFromPayment(
        SalesInvoice $invoice,
        Payment $payment,
        float $amount,
        string $installmentTarget,
        ?int $targetScheduleId,
        bool $allowFlexibleCollection = false,
    ): void {
        if ($invoice->sale_type !== 'installment') {
            return;
        }

        $plan = InstallmentPlan::query()
            ->where('sales_invoice_id', $invoice->id)
            ->lockForUpdate()
            ->first();

        if (! $plan) {
            return;
        }

        $schedules = InstallmentSchedule::query()
            ->where('installment_plan_id', $plan->id)
            ->lockForUpdate()
            ->orderBy('installment_no')
            ->get();

        $remaining = round($amount, 2);

        if ($installmentTarget === 'schedule' && $targetScheduleId) {
            $target = $schedules->firstWhere('id', $targetScheduleId);
            if (! $target) {
                throw new InvalidStateException('Invalid installment selection.');
            }

            $cap = $target->remainingDue();
            $apply = $allowFlexibleCollection ? $remaining : min($remaining, $cap);
            if ($apply > 0) {
                $this->applyInstallmentSlice($target, $apply, $payment->id);
            }
        } else {
            foreach ($schedules as $schedule) {
                if ($remaining <= 0.001) {
                    break;
                }

                $cap = $schedule->remainingDue();
                if ($cap <= 0.001 && ! $allowFlexibleCollection) {
                    continue;
                }

                $apply = $allowFlexibleCollection && $cap <= 0.001
                    ? 0.0
                    : ($allowFlexibleCollection ? min($remaining, max($cap, 0)) : min($remaining, $cap));
                if ($apply <= 0.001) {
                    continue;
                }

                $this->applyInstallmentSlice($schedule, $apply, $payment->id);
                $remaining = round($remaining - $apply, 2);
            }
        }

        $this->refreshInstallmentPlanTotals($plan->fresh());
    }

    private function applyInstallmentSlice(InstallmentSchedule $schedule, float $apply, int $paymentId): void
    {
        $schedule->paid_amount = round((float) $schedule->paid_amount + $apply, 2);
        $schedule->status = $schedule->computeStatus();
        $schedule->save();

        InstallmentPaymentAllocation::create([
            'installment_schedule_id' => $schedule->id,
            'payment_id' => $paymentId,
            'allocated_amount' => $apply,
        ]);
    }

    public function paymentEnumForMethod(PaymentMethod $method): string
    {
        return $this->mapMethodToPaymentEnum($method);
    }

    public function syncInstallmentAllocationsForExistingPayment(SalesInvoice $invoice, Payment $payment): void
    {
        if ($invoice->sale_type !== 'installment') {
            return;
        }

        $allocation = PaymentAllocation::query()
            ->where('payment_id', $payment->id)
            ->where('sales_invoice_id', $invoice->id)
            ->first();

        if (! $allocation) {
            return;
        }

        $totalApplied = round(
            (float) $allocation->allocated_amount + (float) ($allocation->settlement_discount ?? 0),
            2,
        );

        if ($totalApplied <= 0) {
            return;
        }

        if (InstallmentAdvanceService::isAdvanceReference($payment->reference_no)) {
            return;
        }

        if ($payment->isPaymentBackfill() || $payment->isHistoricalPayment()) {
            return;
        }

        $this->syncInstallmentAllocationsFromPayment(
            $invoice,
            $payment,
            $totalApplied,
            'waterfall',
            null,
        );
    }

    private function refreshInstallmentPlanTotals(InstallmentPlan $plan): void
    {
        $this->installmentSync->refreshPlanTotals($plan);
    }

    private function normalizeMethod(string $method): string
    {
        return match ($method) {
            'bank' => 'bank_transfer',
            'mobile_bank' => 'mobile_money',
            default => $method,
        };
    }

    private function mapMethodToPaymentEnum(PaymentMethod $method): string
    {
        return $this->mapTypeToPaymentEnum((string) $method->type);
    }

    private function mapTypeToPaymentEnum(string $type): string
    {
        return match ($type) {
            'bank' => 'bank_transfer',
            'card_terminal' => 'card',
            'mobile_banking' => 'mobile_money',
            default => 'cash',
        };
    }
}
