<?php

namespace App\Services;

use App\Exceptions\InvalidStateException;
use App\Models\InstallmentPaymentAllocation;
use App\Models\InstallmentPlan;
use App\Models\InstallmentSchedule;
use App\Models\JournalEntry;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Models\PaymentMethod;
use App\Models\SalesInvoice;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class InstallmentAdvanceService
{
    public const REFERENCE = 'Installment advance payment';

    public function __construct(
        private readonly JournalService $journalService,
        private readonly DocumentNumberGenerator $documentNumbers,
        private readonly PaymentService $paymentService,
        private readonly InstallmentInvoiceSyncService $installmentSync,
    ) {}

    public static function isAdvanceReference(?string $reference): bool
    {
        return trim((string) $reference) === self::REFERENCE;
    }

    public function recordedAdvanceTotal(SalesInvoice $invoice): float
    {
        return round((float) Payment::query()
            ->where('direction', 'inbound')
            ->where('reference_no', self::REFERENCE)
            ->whereHas('paymentAllocations', fn ($q) => $q->where('sales_invoice_id', $invoice->id))
            ->sum('amount'), 2);
    }

    public function maxHistoricalAdvanceAmount(SalesInvoice $invoice): float
    {
        $cap = $invoice->maxHistoricalPaymentAmount();
        $configured = round(max(0, (float) ($invoice->installment_down_payment ?? 0)), 2);
        $recorded = $this->recordedAdvanceTotal($invoice);

        if ($configured > 0.009) {
            return min($cap, max(0, round($configured - $recorded, 2)));
        }

        return $cap;
    }

    public function advanceJournalExists(Payment $payment): bool
    {
        return JournalEntry::query()
            ->where('source', 'sales_payment')
            ->where('reference_type', Payment::class)
            ->where('reference_id', $payment->id)
            ->exists();
    }

    public function findAdvancePayment(SalesInvoice $invoice): ?Payment
    {
        $byReference = Payment::query()
            ->where('direction', 'inbound')
            ->where('reference_no', self::REFERENCE)
            ->whereHas('paymentAllocations', fn ($q) => $q->where('sales_invoice_id', $invoice->id))
            ->orderBy('id')
            ->first();

        if ($byReference instanceof Payment) {
            return $byReference;
        }

        $downPayment = round(max(0, (float) ($invoice->installment_down_payment ?? 0)), 2);
        if ($downPayment <= 0) {
            return null;
        }

        return Payment::query()
            ->where('direction', 'inbound')
            ->whereHas('paymentAllocations', fn ($q) => $q->where('sales_invoice_id', $invoice->id))
            ->whereRaw('ABS(amount - ?) <= 0.009', [$downPayment])
            ->orderBy('id')
            ->first();
    }

    public function resolvePaymentMethodForBranch(SalesInvoice $invoice): ?PaymentMethod
    {
        $methods = PaymentMethod::availableForSalesAtLocation((int) $invoice->branch_id);

        return $methods->first();
    }

    public function advancePaymentDate(SalesInvoice $invoice): Carbon
    {
        if ($invoice->invoice_date) {
            return Carbon::parse($invoice->invoice_date)->startOfDay();
        }

        if ($invoice->posted_at) {
            return Carbon::parse($invoice->posted_at)->startOfDay();
        }

        if ($invoice->created_at) {
            return Carbon::parse($invoice->created_at)->startOfDay();
        }

        return now()->startOfDay();
    }

    /**
     * Align advance payment date (and journal entry date) with the invoice create date.
     */
    public function syncAdvancePaymentDate(Payment $payment, SalesInvoice $invoice): Payment
    {
        $paymentDate = $this->advancePaymentDate($invoice)->toDateString();

        if ($payment->payment_date->toDateString() !== $paymentDate) {
            $payment->forceFill(['payment_date' => $paymentDate])->save();
        }

        return $payment->fresh();
    }

    public function syncAdvanceJournalEntryDate(Payment $payment): bool
    {
        $paymentDate = $payment->payment_date->toDateString();

        return JournalEntry::query()
            ->where('source', 'sales_payment')
            ->where('reference_type', Payment::class)
            ->where('reference_id', $payment->id)
            ->whereDate('entry_date', '!=', $paymentDate)
            ->update(['entry_date' => $paymentDate]) > 0;
    }

    /**
     * Post wallet/AR journal for an existing advance payment when missing.
     *
     * @return array{posted: bool, skipped: bool, reason: string|null}
     */
    public function postAdvanceJournalIfMissing(Payment $payment, SalesInvoice $invoice, int $actorUserId): array
    {
        $payment = $this->syncAdvancePaymentDate($payment, $invoice);

        if ($this->advanceJournalExists($payment)) {
            $dateUpdated = $this->syncAdvanceJournalEntryDate($payment);

            return [
                'posted' => false,
                'skipped' => ! $dateUpdated,
                'reason' => $dateUpdated ? 'journal_date_updated' : 'journal_exists',
            ];
        }

        $paymentMethodId = (int) ($payment->payment_method_id ?? 0);
        if ($paymentMethodId <= 0) {
            $method = $this->resolvePaymentMethodForBranch($invoice);
            if (! $method) {
                return ['posted' => false, 'skipped' => true, 'reason' => 'no_wallet_mapped_method'];
            }

            $payment->forceFill(['payment_method_id' => $method->id])->save();
            $paymentMethodId = (int) $method->id;
        }

        $posted = $this->journalService->postCustomerPaymentGl(
            (int) $invoice->branch_id,
            round((float) $payment->amount, 2),
            $paymentMethodId,
            $payment,
            $actorUserId,
            requireWallet: false,
        );

        if (! $posted) {
            return ['posted' => false, 'skipped' => true, 'reason' => 'wallet_unavailable'];
        }

        return ['posted' => true, 'skipped' => false, 'reason' => null];
    }

    /**
     * Create a missing advance payment row and journal without changing invoice totals.
     *
     * @return array{created: bool, payment: Payment|null, journal_posted: bool, skipped: bool, reason: string|null}
     */
    public function backfillMissingAdvancePayment(SalesInvoice $invoice, int $actorUserId): array
    {
        if ($invoice->sale_type !== 'installment') {
            return ['created' => false, 'payment' => null, 'journal_posted' => false, 'skipped' => true, 'reason' => 'not_installment'];
        }

        $downPayment = round(max(0, (float) ($invoice->installment_down_payment ?? 0)), 2);
        if ($downPayment <= 0) {
            return ['created' => false, 'payment' => null, 'journal_posted' => false, 'skipped' => true, 'reason' => 'no_down_payment'];
        }

        $existing = $this->findAdvancePayment($invoice);
        if ($existing instanceof Payment) {
            $journal = $this->postAdvanceJournalIfMissing($existing, $invoice, $actorUserId);

            return [
                'created' => false,
                'payment' => $existing,
                'journal_posted' => $journal['posted'],
                'skipped' => $journal['skipped'],
                'reason' => $journal['reason'],
            ];
        }

        if (round((float) $invoice->amount_paid, 2) + 0.009 < $downPayment) {
            return ['created' => false, 'payment' => null, 'journal_posted' => false, 'skipped' => true, 'reason' => 'invoice_not_paid_enough'];
        }

        $method = $this->resolvePaymentMethodForBranch($invoice);
        if (! $method) {
            return ['created' => false, 'payment' => null, 'journal_posted' => false, 'skipped' => true, 'reason' => 'no_wallet_mapped_method'];
        }

        return DB::transaction(function () use ($invoice, $downPayment, $method, $actorUserId): array {
            $paymentDate = $this->advancePaymentDate($invoice);

            $payment = Payment::create([
                'payment_number' => $this->documentNumbers->next(
                    DocumentNumberGenerator::TYPE_PAYMENT_INBOUND,
                    'PAY',
                    $paymentDate,
                    'Ymd',
                    5,
                ),
                'customer_id' => $invoice->customer_id,
                'payment_date' => $paymentDate->toDateString(),
                'payment_method' => $this->paymentService->paymentEnumForMethod($method),
                'payment_method_id' => $method->id,
                'amount' => $downPayment,
                'direction' => 'inbound',
                'reference_no' => self::REFERENCE,
                'recorded_by' => $actorUserId,
            ]);

            PaymentAllocation::create([
                'payment_id' => $payment->id,
                'sales_invoice_id' => $invoice->id,
                'allocated_amount' => $downPayment,
                'settlement_discount' => 0,
            ]);

            $invoice->loadMissing('installmentPlan.schedules');
            if ($invoice->installmentPlan) {
                $this->paymentService->syncInstallmentAllocationsForExistingPayment(
                    $invoice->fresh(['installmentPlan.schedules']),
                    $payment,
                );
            }

            $journal = $this->postAdvanceJournalIfMissing($payment, $invoice, $actorUserId);

            return [
                'created' => true,
                'payment' => $payment,
                'journal_posted' => $journal['posted'],
                'skipped' => false,
                'reason' => $journal['reason'],
            ];
        });
    }

    public function hasMisallocatedAdvanceOnSchedules(InstallmentPlan $plan): bool
    {
        if (! $this->planIsNativeSale($plan)) {
            return false;
        }

        $scheduleIds = $plan->schedules()->pluck('id')->map(fn ($id) => (int) $id)->all();

        if ($scheduleIds === []) {
            return false;
        }

        return InstallmentPaymentAllocation::query()
            ->whereIn('installment_schedule_id', $scheduleIds)
            ->whereHas('payment', fn ($q) => $q
                ->where('direction', 'inbound')
                ->where('reference_no', self::REFERENCE))
            ->exists();
    }

    public function misallocatedAdvanceTotalOnSchedules(InstallmentPlan $plan): float
    {
        if (! $this->planIsNativeSale($plan)) {
            return 0.0;
        }

        $scheduleIds = $plan->schedules()->pluck('id')->map(fn ($id) => (int) $id)->all();

        if ($scheduleIds === []) {
            return 0.0;
        }

        return round((float) InstallmentPaymentAllocation::query()
            ->whereIn('installment_schedule_id', $scheduleIds)
            ->whereHas('payment', fn ($q) => $q
                ->where('direction', 'inbound')
                ->where('reference_no', self::REFERENCE))
            ->sum('allocated_amount'), 2);
    }

    /**
     * @return \Illuminate\Database\Eloquent\Builder<InstallmentPlan>
     */
    public function queryNativePlansWithMisallocatedAdvanceOnSchedules(): \Illuminate\Database\Eloquent\Builder
    {
        return InstallmentPlan::query()
            ->whereHas('salesInvoice', function ($q): void {
                $q->nativeSales()
                    ->where('sale_type', 'installment')
                    ->whereIn('status', ['active', 'posted']);
            })
            ->whereHas('schedules.paymentAllocations', function ($q): void {
                $q->whereHas('payment', fn ($pq) => $pq
                    ->where('direction', 'inbound')
                    ->where('reference_no', self::REFERENCE));
            })
            ->with([
                'salesInvoice.branch',
                'salesInvoice.customer',
                'schedules',
            ])
            ->orderBy('id');
    }

    /**
     * @return array<string, int|float|string|null>
     */
    public function misallocatedAdvanceExportRow(InstallmentPlan $plan): array
    {
        $plan->loadMissing(['schedules', 'salesInvoice.branch', 'salesInvoice.customer']);
        $invoice = $plan->salesInvoice;

        $misallocated = $this->misallocatedAdvanceTotalOnSchedules($plan);
        $schedulePaid = round((float) $plan->schedules->sum(fn (InstallmentSchedule $s) => max(0, (float) $s->paid_amount)), 2);
        $scheduleRemaining = round((float) $plan->schedules->sum(fn (InstallmentSchedule $s) => $s->remainingDue()), 2);
        $financed = round((float) $plan->financed_amount, 2);
        $correctRemaining = max(0, round($financed - ($schedulePaid - $misallocated), 2));

        $misallocationCount = InstallmentPaymentAllocation::query()
            ->whereIn('installment_schedule_id', $plan->schedules->pluck('id')->map(fn ($id) => (int) $id)->all())
            ->whereHas('payment', fn ($q) => $q
                ->where('direction', 'inbound')
                ->where('reference_no', self::REFERENCE))
            ->count();

        return [
            'invoice_id' => $invoice?->id,
            'invoice_number' => $invoice?->invoice_number,
            'invoice_date' => $invoice?->invoice_date?->toDateString(),
            'branch' => $invoice?->branch?->name,
            'customer_code' => $invoice?->customer?->customer_code,
            'customer_name' => $invoice?->customer?->name,
            'status' => $invoice?->status,
            'payment_status' => $invoice?->payment_status,
            'grand_total' => $invoice ? round((float) $invoice->grand_total, 2) : null,
            'configured_advance' => $invoice ? round((float) ($invoice->installment_down_payment ?? 0), 2) : null,
            'amount_paid' => $invoice ? round((float) $invoice->amount_paid, 2) : null,
            'amount_due' => $invoice ? round((float) $invoice->amount_due, 2) : null,
            'financed_amount' => $financed,
            'schedule_remaining_current' => $scheduleRemaining,
            'schedule_remaining_correct' => $correctRemaining,
            'misallocated_on_schedules' => $misallocated,
            'misallocation_rows' => $misallocationCount,
        ];
    }

    /**
     * @return list<string>
     */
    public static function misallocatedAdvanceExportHeaders(): array
    {
        return [
            'invoice_id',
            'invoice_number',
            'invoice_date',
            'branch',
            'customer_code',
            'customer_name',
            'status',
            'payment_status',
            'grand_total',
            'configured_advance',
            'amount_paid',
            'amount_due',
            'financed_amount',
            'schedule_remaining_current',
            'schedule_remaining_correct',
            'misallocated_on_schedules',
            'misallocation_rows',
        ];
    }

    /**
     * Remove installment schedule rows incorrectly paid from advance collections.
     *
     * @return array{repaired: bool, removed_allocations: int, schedules_updated: int}
     */
    public function repairMisallocatedAdvanceOnSchedules(InstallmentPlan $plan, bool $dryRun = false): array
    {
        $plan->loadMissing(['schedules' => fn ($q) => $q->orderBy('installment_no'), 'salesInvoice']);

        if (! $this->planIsNativeSale($plan)) {
            return ['repaired' => false, 'removed_allocations' => 0, 'schedules_updated' => 0];
        }

        $scheduleIds = $plan->schedules->pluck('id')->map(fn ($id) => (int) $id)->all();
        if ($scheduleIds === []) {
            return ['repaired' => false, 'removed_allocations' => 0, 'schedules_updated' => 0];
        }

        $misallocated = InstallmentPaymentAllocation::query()
            ->whereIn('installment_schedule_id', $scheduleIds)
            ->whereHas('payment', fn ($q) => $q
                ->where('direction', 'inbound')
                ->where('reference_no', self::REFERENCE))
            ->get();

        if ($misallocated->isEmpty()) {
            return ['repaired' => false, 'removed_allocations' => 0, 'schedules_updated' => 0];
        }

        $affectedScheduleIds = $misallocated
            ->pluck('installment_schedule_id')
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();

        if ($dryRun) {
            return [
                'repaired' => true,
                'removed_allocations' => $misallocated->count(),
                'schedules_updated' => count($affectedScheduleIds),
            ];
        }

        DB::transaction(function () use ($plan, $misallocated, $affectedScheduleIds, $scheduleIds): void {
            InstallmentPaymentAllocation::query()
                ->whereIn('id', $misallocated->pluck('id')->map(fn ($id) => (int) $id)->all())
                ->delete();

            $paidBySchedule = InstallmentPaymentAllocation::query()
                ->whereIn('installment_schedule_id', $scheduleIds)
                ->selectRaw('installment_schedule_id, ROUND(COALESCE(SUM(allocated_amount), 0), 2) as total')
                ->groupBy('installment_schedule_id')
                ->pluck('total', 'installment_schedule_id')
                ->map(fn ($total) => (float) $total)
                ->all();

            foreach ($affectedScheduleIds as $scheduleId) {
                $schedule = InstallmentSchedule::query()->lockForUpdate()->find($scheduleId);
                if (! $schedule instanceof InstallmentSchedule) {
                    continue;
                }

                $paid = round(max(0, (float) ($paidBySchedule[$scheduleId] ?? 0)), 2);
                $schedule->update([
                    'paid_amount' => $paid,
                    'status' => $schedule->fresh()->computeStatus(),
                ]);
            }

            $this->installmentSync->refreshPlanTotals($plan->fresh(['schedules']));

            $invoice = $plan->salesInvoice;
            if ($invoice instanceof SalesInvoice) {
                $this->installmentSync->syncInvoiceHeaderFromPlan(
                    $invoice->fresh(['installmentPlan.schedules']),
                );
            }
        });

        return [
            'repaired' => true,
            'removed_allocations' => $misallocated->count(),
            'schedules_updated' => count($affectedScheduleIds),
        ];
    }

    /**
     * Record installment advance during posting when invoice totals are still at zero paid.
     */
    public function recordAdvanceOnPosting(SalesInvoice $invoice, int $paymentMethodId, int $actorUserId, ?string $date = null): ?Payment
    {
        if ($invoice->isDizicashierImport()) {
            return null;
        }

        $downPayment = round(max(0, (float) ($invoice->installment_down_payment ?? 0)), 2);
        if ($downPayment <= 0) {
            return null;
        }

        return $this->paymentService->recordAndAllocate(
            $invoice->fresh(['installmentPlan.schedules']),
            [
                'amount' => $downPayment,
                'payment_method_id' => $paymentMethodId,
                'date' => $date ?? $this->advancePaymentDate($invoice)->toDateString(),
                'reference' => self::REFERENCE,
                'installment_target' => 'advance',
                'referrer_employee_id' => $invoice->referrer_id,
            ],
            $actorUserId,
        );
    }

    private function planIsNativeSale(InstallmentPlan $plan): bool
    {
        $invoice = $plan->relationLoaded('salesInvoice')
            ? $plan->salesInvoice
            : $plan->salesInvoice()->first();

        return $invoice instanceof SalesInvoice && ! $invoice->isDizicashierImport();
    }

}
