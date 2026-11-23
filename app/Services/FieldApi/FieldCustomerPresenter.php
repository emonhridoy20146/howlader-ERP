<?php

namespace App\Services\FieldApi;

use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\CustomerRiskSnapshot;
use App\Models\FieldPaymentRequest;
use App\Models\InstallmentPaymentAllocation;
use App\Models\InstallmentSchedule;
use App\Models\InstallmentWriteoffAllocation;
use App\Models\Payment;
use App\Models\SalesInvoice;
use App\Services\InstallmentAdvanceService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Read model for the field integration API. Reads existing tables only and returns the
 * ERP's own numbers (amount_due, schedule paid amounts) — it never recomputes balances.
 */
final class FieldCustomerPresenter
{
    /** Invoice kinds field staff can collect on. Cash ("default") sales must be paid in full at the counter. */
    public const RECEIVABLE_TYPES = ['installment', 'credit'];

    public const REMOVED_ACTION = 'sales_invoice.payment_removed';

    private const CHANGE_ACTIONS = [
        self::REMOVED_ACTION,
        'sales_invoice.debt_writeoff',
        'sales_invoice.debt_writeoff_removed',
        'sales_invoice.branch_changed',
    ];

    /** @var array<int, string|null> payment id → payment date of removed field payments (set by present()). */
    private array $fieldDates = [];

    /** @var array<int, float> schedule id → amount written off on that line (set by present()). */
    private array $lineWriteoffs = [];

    /** SQL for "branch of the customer's latest invoice" — the fallback when customers.branch_id is NULL. */
    public static function fallbackBranchSql(): string
    {
        return '(SELECT si_fb.branch_id FROM sales_invoices si_fb WHERE si_fb.customer_id = customers.id'
            .' AND si_fb.deleted_at IS NULL ORDER BY si_fb.invoice_date DESC, si_fb.id DESC LIMIT 1)';
    }

    /** @return Builder<Customer> */
    public function baseQuery(string $scope): Builder
    {
        $q = Customer::query()->select('customers.*')
            ->selectRaw(self::fallbackBranchSql().' AS fallback_branch_id');

        if ($scope !== 'all') {
            $q->whereExists(fn ($s) => $s->selectRaw('1')->from('sales_invoices as si')
                ->whereColumn('si.customer_id', 'customers.id')
                ->whereIn('si.sale_type', self::RECEIVABLE_TYPES)
                ->where('si.status', '!=', 'draft')
                ->whereNull('si.deleted_at'));
        }

        return $q;
    }

    /** @param Builder<Customer> $q */
    public function filterBranch(Builder $q, int $branchId): void
    {
        $q->whereRaw('COALESCE(customers.branch_id, '.self::fallbackBranchSql().') = ?', [$branchId]);
    }

    /**
     * Customers whose own row, or any invoice, schedule line, payment, write-off or payment
     * removal of theirs, changed at or after $since. Payment removals are hard deletes in
     * the ERP, so they are found through the audit log.
     *
     * @param Builder<Customer> $q
     */
    public function filterChangedSince(Builder $q, Carbon $since): void
    {
        $ts = $since->copy()->setTimezone((string) config('app.timezone'))->format('Y-m-d H:i:s');

        $changed = DB::table('customers')->select('id as cid')->where('updated_at', '>=', $ts)
            ->union(DB::table('sales_invoices')->select('customer_id as cid')->where('updated_at', '>=', $ts))
            ->union(DB::table('payments')->select('customer_id as cid')->whereNotNull('customer_id')->where('updated_at', '>=', $ts))
            ->union(DB::table('installment_schedules as s')
                ->join('installment_plans as p', 'p.id', '=', 's.installment_plan_id')
                ->select('p.customer_id as cid')->where('s.updated_at', '>=', $ts))
            ->union(DB::table('audit_logs as a')
                ->join('sales_invoices as si', 'si.id', '=', 'a.entity_id')
                ->select('si.customer_id as cid')
                ->whereIn('a.action', self::CHANGE_ACTIONS)
                ->where('a.created_at', '>=', $ts));

        $q->whereIn('customers.id', DB::query()->fromSub($changed, 'changed')->select('cid'));
    }

    /**
     * $allInvoiceTypes = true also returns cash invoices (field `invoices=all`); default: installment and credit only.
     *
     * @param Collection<int, Customer> $customers
     * @return list<array<string, mixed>>
     */
    public function present(Collection $customers, bool $allInvoiceTypes = false): array
    {
        if ($customers->isEmpty()) {
            return [];
        }

        $ids = $customers->pluck('id')->map(fn ($id) => (int) $id)->all();

        $invoices = SalesInvoice::query()
            ->whereIn('customer_id', $ids)
            ->when(! $allInvoiceTypes, fn ($q) => $q->whereIn('sale_type', self::RECEIVABLE_TYPES))
            ->where('status', '!=', 'draft')
            ->with([
                'lines.product:id,name,model_number',
                'lines.saleLineSerials' => fn ($q) => $q->whereNull('released_at'),
                'lines.saleLineSerials.serialItem:id,serial_number',
                'installmentPlan.schedules',
                // Which payment paid which installment, and on what date (installment_payment_allocations).
                'installmentPlan.schedules.paymentAllocations.payment:id,payment_number,payment_date,direction',
                'guarantor',
            ])
            ->withSum('debtWriteoffs as written_off_sum', 'amount')
            // Same sums the invoice page shows: settlement discounts of live inbound payments, refunds of returns not rolled back.
            ->withSum(['paymentAllocations as settlement_discount_sum' => fn ($q) => $q->forRecordedInboundPayment()], 'settlement_discount')
            ->withSum(['returnRefunds as refunded_sum' => fn ($q) => $q->whereHas('returnOrder', fn ($r) => $r->whereNull('rolled_back_at'))], 'paid_amount')
            ->orderBy('invoice_date')->orderBy('id')
            ->get();
        $invoicesByCustomer = $invoices->groupBy('customer_id');

        $scheduleIds = $invoices->flatMap(fn (SalesInvoice $i) => $i->installmentPlan?->schedules->pluck('id') ?? [])->all();
        $this->lineWriteoffs = $scheduleIds === [] ? [] : InstallmentWriteoffAllocation::query()
            ->whereIn('installment_schedule_id', $scheduleIds)
            ->groupBy('installment_schedule_id')
            ->selectRaw('installment_schedule_id, SUM(amount) AS total')
            ->pluck('total', 'installment_schedule_id')
            ->mapWithKeys(fn ($total, $id) => [(int) $id => (float) $total])
            ->all();
        $invoiceCustomer = $invoices->mapWithKeys(fn (SalesInvoice $i) => [(int) $i->id => (int) $i->customer_id]);

        $limit = max(1, (int) config('field_api.recent_payments_per_customer', 20));
        $ranked = Payment::query()->select('payments.*')
            ->selectRaw('ROW_NUMBER() OVER (PARTITION BY payments.customer_id ORDER BY payments.payment_date DESC, payments.id DESC) AS rn')
            ->whereIn('customer_id', $ids)
            ->where('direction', 'inbound');
        $payments = Payment::query()->fromSub($ranked, 'payments')
            ->where('rn', '<=', $limit)
            ->with(['paymentAllocations', 'paymentMethodRef:id,name,type', 'referrerEmployee:id,first_name,last_name'])
            ->orderByDesc('payment_date')->orderByDesc('id')
            ->get();
        $paymentsByCustomer = $payments->groupBy('customer_id');

        $removed = $invoiceCustomer->isEmpty() ? collect() : AuditLog::query()
            ->where('action', self::REMOVED_ACTION)
            ->whereIn('entity_type', [SalesInvoice::class, 'SalesInvoice'])
            ->whereIn('entity_id', $invoiceCustomer->keys()->all())
            ->orderByDesc('id')
            ->limit(2000)
            ->get();
        $removedByCustomer = $removed->groupBy(fn (AuditLog $a) => $invoiceCustomer[(int) $a->entity_id] ?? 0);

        $paymentIds = $payments->pluck('id')
            ->merge($removed->map(fn (AuditLog $a) => self::removedPaymentId($a)))
            ->filter()->unique()->values()->all();
        $fieldRows = $paymentIds === [] ? collect() : FieldPaymentRequest::query()
            ->whereIn('payment_id', $paymentIds)
            ->get(['payment_id', 'idempotency_key', 'response']);
        $fieldKeys = $fieldRows->mapWithKeys(fn (FieldPaymentRequest $r) => [(int) $r->payment_id => (string) $r->idempotency_key])->all();
        // A removed payment row is gone; for field payments its date is still in the stored response.
        $this->fieldDates = $fieldRows->mapWithKeys(fn (FieldPaymentRequest $r) => [(int) $r->payment_id => $r->response['payment_date'] ?? null])->all();

        $risk = CustomerRiskSnapshot::query()->whereIn('customer_id', $ids)->get()->keyBy('customer_id');
        $guarantorIds = $customers->pluck('default_guarantor_id')->filter()->unique()->values()->all();
        $defaultGuarantors = $guarantorIds === [] ? collect() : Customer::query()->whereIn('id', $guarantorIds)->get()->keyBy('id');

        return $customers->map(fn (Customer $c) => $this->customerRow(
            $c,
            $invoicesByCustomer->get($c->id, collect()),
            $paymentsByCustomer->get($c->id, collect()),
            $removedByCustomer->get($c->id, collect()),
            $risk->get($c->id),
            $c->default_guarantor_id ? $defaultGuarantors->get($c->default_guarantor_id) : null,
            $fieldKeys,
        ))->values()->all();
    }

    /**
     * @param Collection<int, SalesInvoice> $invoices
     * @param Collection<int, Payment> $payments
     * @param Collection<int, AuditLog> $removed
     * @param array<int, string> $fieldKeys
     * @return array<string, mixed>
     */
    private function customerRow(Customer $c, Collection $invoices, Collection $payments, Collection $removed, ?CustomerRiskSnapshot $risk, ?Customer $defaultGuarantor, array $fieldKeys): array
    {
        $fallbackBranch = $c->getAttribute('fallback_branch_id');
        $branchId = $c->branch_id ?? ($fallbackBranch !== null ? (int) $fallbackBranch : null);

        $stamps = collect([$c->updated_at])
            ->merge($invoices->pluck('updated_at'))
            ->merge($invoices->flatMap(fn (SalesInvoice $i) => $i->installmentPlan?->schedules->pluck('updated_at') ?? []))
            ->merge($payments->pluck('updated_at'))
            ->merge($removed->pluck('created_at'))
            ->filter();

        return [
            'id' => (int) $c->id,
            'customer_code' => $c->customer_code,
            'name' => $c->name,
            'phone' => $c->phone,
            'phone_numbers' => array_values(array_map('strval', is_array($c->phone_numbers) ? $c->phone_numbers : [])),
            'branch_id' => $branchId,
            'branch_source' => $c->branch_id ? 'customer' : ($branchId ? 'last_invoice' : null),
            'address' => $c->address,
            'detailed_address' => $c->detailed_address,
            'location_instructions' => $c->location_instructions,
            'latitude' => $c->getRawOriginal('latitude'),
            'longitude' => $c->getRawOriginal('longitude'),
            'is_active' => (bool) $c->is_active,
            'outstanding_balance' => self::money($c->outstanding_balance),
            'default_guarantor' => $defaultGuarantor && (int) $defaultGuarantor->id !== (int) $c->id
                ? self::guarantorRow($defaultGuarantor->id, $defaultGuarantor->name, $defaultGuarantor->normalizedPhoneNumbers(), $defaultGuarantor->address)
                : null,
            'risk' => $risk ? [
                'grade' => $risk->grade,
                'risk_level' => $risk->risk_level,
                'classification' => $risk->classification,
                'score' => $risk->score !== null ? self::money($risk->score) : null,
                'overdue_count' => (int) $risk->overdue_count,
                'max_days_late' => (int) $risk->max_days_late,
                'computed_at' => $risk->computed_at ? Carbon::parse($risk->computed_at)->toIso8601String() : null,
            ] : null,
            'updated_at' => $c->updated_at?->toIso8601String(),
            'changed_at' => $stamps->isEmpty() ? null : Carbon::parse($stamps->max())->toIso8601String(),
            'invoices' => $invoices->map(fn (SalesInvoice $i) => $this->invoiceRow($i, $c, $defaultGuarantor))->values()->all(),
            'recent_payments' => $payments->map(fn (Payment $p) => $this->paymentRow($p, $fieldKeys))->values()->all(),
            'removed_payments' => $removed->map(fn (AuditLog $a) => $this->removedRow($a, $fieldKeys))->values()->all(),
        ];
    }

    /** @return array<string, mixed> */
    private function invoiceRow(SalesInvoice $i, Customer $buyer, ?Customer $defaultGuarantor): array
    {
        $plan = $i->installmentPlan;
        $due = round((float) $i->amount_due, 2);

        return [
            'id' => (int) $i->id,
            'invoice_number' => $i->invoice_number,
            'invoice_date' => $i->invoice_date?->toDateString(),
            'branch_id' => (int) $i->branch_id,
            'sale_type' => $i->sale_type,
            'status' => $i->status,
            'payment_status' => $i->payment_status,
            'collectable' => in_array($i->status, ['posted', 'active'], true) && $due > 0.009,
            'subtotal' => self::money($i->subtotal),
            'discount_total' => self::money($i->discount_total),
            'tax_total' => self::money($i->tax_total),
            'grand_total' => self::money($i->grand_total),
            'amount_paid' => self::money($i->amount_paid),
            'amount_due' => self::money($due),
            'written_off' => self::money($i->getAttribute('written_off_sum') ?? 0),
            'settlement_discount' => self::money($i->getAttribute('settlement_discount_sum') ?? 0),
            'refunded' => self::money($i->getAttribute('refunded_sum') ?? 0),
            'credit_due_date' => $i->credit_due_date?->toDateString(),
            'items' => $i->lines->map(fn ($l) => [
                'line_id' => (int) $l->id,
                'product_id' => (int) $l->product_id,
                'name' => $l->product?->name,
                'model_number' => $l->product?->model_number,
                'qty' => (int) $l->qty,
                'unit_price' => self::money($l->unit_price),
                'discount_amount' => self::money($l->discount_amount),
                'service_charge' => self::money($l->service_charge),
                'tax_amount' => self::money($l->tax_amount),
                'line_total' => self::money($l->line_total),
                'serials' => $l->saleLineSerials->map(fn ($s) => $s->serialItem?->serial_number)->filter()->values()->all(),
            ])->values()->all(),
            'guarantor' => $this->guarantorFor($i, $buyer, $defaultGuarantor),
            'installment_plan' => $plan ? [
                'id' => (int) $plan->id,
                'status' => $plan->status,
                'total_amount' => self::money($plan->total_amount),
                'down_payment' => self::money($plan->down_payment),
                'financed_amount' => self::money($plan->financed_amount),
                'remaining_amount' => self::money($plan->remaining_amount),
                'frequency' => $i->installment_frequency ?: 'monthly',
                'custom_days' => $i->installment_custom_days !== null ? (int) $i->installment_custom_days : null,
                'installment_count' => $plan->schedules->count(),
                'penalty_enabled' => (bool) $plan->penalty_enabled,
                'penalty_rate' => $plan->penalty_rate !== null ? (string) $plan->penalty_rate : null,
                'schedules' => $plan->schedules->map(fn (InstallmentSchedule $s) => [
                    'id' => (int) $s->id,
                    'installment_no' => (int) $s->installment_no,
                    'due_date' => $s->due_date?->toDateString(),
                    'due_amount' => self::money($s->due_amount),
                    'paid_amount' => self::money($s->paid_amount),
                    'late_fee_amount' => self::money($s->late_fee_amount),
                    'remaining_amount' => self::money($s->remainingDue()),
                    'written_off' => self::money($this->lineWriteoffs[(int) $s->id] ?? 0),
                    'payments' => $this->schedulePayments($s),
                    // Stored value; it can be stale (the overdue roll job does not always run). Clients compute their own.
                    'status' => $s->status,
                ])->values()->all(),
            ] : null,
            'updated_at' => $i->updated_at?->toIso8601String(),
        ];
    }

    /**
     * Payments that went to this installment line, oldest first. Allocation rows of a removed
     * (hard-deleted) or outbound payment are left out.
     *
     * @return list<array<string, mixed>>
     */
    private function schedulePayments(InstallmentSchedule $s): array
    {
        return $s->paymentAllocations
            ->filter(fn (InstallmentPaymentAllocation $a) => $a->payment instanceof Payment && $a->payment->direction === 'inbound')
            ->sortBy(fn (InstallmentPaymentAllocation $a) => ($a->payment->payment_date?->toDateString() ?? '').'#'.str_pad((string) $a->payment_id, 12, '0', STR_PAD_LEFT))
            ->map(fn (InstallmentPaymentAllocation $a) => [
                'payment_id' => (int) $a->payment_id,
                'payment_number' => $a->payment->payment_number,
                'payment_date' => $a->payment->payment_date?->toDateString(),
                'amount' => self::money($a->allocated_amount),
            ])->values()->all();
    }

    /** Same order as SalesInvoice::collectionGuarantorDisplayParts(): invoice guarantor, else the customer's default. NID is never returned. */
    private function guarantorFor(SalesInvoice $i, Customer $buyer, ?Customer $default): ?array
    {
        if ($i->guarantor_id && (int) $i->guarantor_id !== (int) $buyer->id && $i->guarantor instanceof Customer) {
            return self::guarantorRow($i->guarantor->id, $i->guarantor->name, $i->guarantor->normalizedPhoneNumbers(), $i->guarantor_address ?: $i->guarantor->address);
        }

        $phones = is_array($i->guarantor_phone_numbers) ? $i->guarantor_phone_numbers : [];
        $hasInline = filled($i->guarantor_name) || $phones !== [];
        $isBuyer = filled($i->guarantor_name) && strcasecmp(trim((string) $i->guarantor_name), trim((string) $buyer->name)) === 0;
        if ($hasInline && ! $isBuyer) {
            return self::guarantorRow(null, $i->guarantor_name, $phones, $i->guarantor_address);
        }

        if ($default && (int) $default->id !== (int) $buyer->id) {
            return self::guarantorRow($default->id, $default->name, $default->normalizedPhoneNumbers(), $default->address);
        }

        return null;
    }

    /** @param array<int, mixed> $phones */
    private static function guarantorRow(?int $customerId, ?string $name, array $phones, ?string $address): array
    {
        return [
            'customer_id' => $customerId,
            'name' => $name,
            'phones' => array_values(array_filter(array_map(fn ($p) => trim((string) $p), $phones), fn ($p) => $p !== '')),
            'address' => $address,
        ];
    }

    /**
     * @param array<int, string> $fieldKeys
     * @return array<string, mixed>
     */
    public function paymentRow(Payment $p, array $fieldKeys): array
    {
        $allocation = $p->paymentAllocations->first();
        $employee = $p->referrerEmployee;

        return [
            'id' => (int) $p->id,
            'payment_number' => $p->payment_number,
            'customer_id' => $p->customer_id !== null ? (int) $p->customer_id : null,
            'sales_invoice_id' => $allocation ? (int) $allocation->sales_invoice_id : null,
            'payment_date' => $p->payment_date?->toDateString(),
            'amount' => self::money($p->amount),
            'settlement_discount' => self::money($allocation?->settlement_discount ?? 0),
            'payment_method' => $p->payment_method,
            'payment_method_id' => $p->payment_method_id !== null ? (int) $p->payment_method_id : null,
            'payment_method_name' => $p->paymentMethodRef?->name,
            'payment_method_type' => $p->paymentMethodRef?->type,
            'collector_employee_id' => $p->referrer_employee_id !== null ? (int) $p->referrer_employee_id : null,
            // a field payment without an ERP employee carries the collector's name as its reference
            'collector_name' => $employee?->fullName() ?? (isset($fieldKeys[(int) $p->id]) ? $p->reference_no : null),
            'reference_no' => $p->reference_no,
            'is_advance' => InstallmentAdvanceService::isAdvanceReference($p->reference_no),
            'source' => isset($fieldKeys[(int) $p->id]) ? 'field_app' : 'office',
            'idempotency_key' => $fieldKeys[(int) $p->id] ?? null,
            'created_at' => $p->created_at?->toIso8601String(),
        ];
    }

    /**
     * @param array<int, string> $fieldKeys
     * @return array<string, mixed>
     */
    private function removedRow(AuditLog $a, array $fieldKeys): array
    {
        $before = is_array($a->before_snapshot) ? $a->before_snapshot : [];
        $id = self::removedPaymentId($a);

        return [
            'id' => $id,
            'payment_number' => $before['payment_number'] ?? null,
            'payment_date' => $id !== null ? ($this->fieldDates[$id] ?? null) : null,
            'sales_invoice_id' => (int) $a->entity_id,
            'amount' => self::money($before['allocation_cash'] ?? 0),
            'removed_at' => $a->created_at?->toIso8601String(),
            'idempotency_key' => $id !== null ? ($fieldKeys[$id] ?? null) : null,
        ];
    }

    private static function removedPaymentId(AuditLog $a): ?int
    {
        $after = is_array($a->after_snapshot) ? $a->after_snapshot : [];
        $before = is_array($a->before_snapshot) ? $a->before_snapshot : [];
        $id = $after['payment_id_removed'] ?? $before['payment_id'] ?? null;

        return $id !== null ? (int) $id : null;
    }

    public static function money(mixed $value): string
    {
        return number_format(round((float) $value, 2), 2, '.', '');
    }
}
