<?php

namespace App\Services\FieldApi;

use App\Exceptions\InvalidStateException;
use App\Exceptions\OverpaymentException;
use App\Models\Customer;
use App\Models\Employee;
use App\Models\FieldPaymentRequest;
use App\Models\InstallmentPaymentAllocation;
use App\Models\Payment;
use App\Models\PaymentMethod;
use App\Models\SalesInvoice;
use App\Models\User;
use App\Services\InstallmentAdvanceService;
use App\Services\PaymentService;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Posts one field collection as an ordinary ERP payment through PaymentService::recordAndAllocate
 * (the same path the office payment form uses), made idempotent by the client's key.
 * Business problems become FieldApiError with a stable code; nothing is stored for them, so the
 * same key can be retried after the office fixes the cause.
 */
final class FieldPaymentIntake
{
    public const METHOD_TYPES = ['cash', 'mobile_banking', 'bank', 'card_terminal'];

    public function __construct(
        private readonly PaymentService $payments,
        private readonly FieldCustomerPresenter $presenter,
    ) {}

    /**
     * @param array<string, mixed> $input validated request body
     * @return array{status: int, body: array<string, mixed>}
     */
    public function post(string $key, array $input, User $actor): array
    {
        $hash = self::requestHash($input);

        $replay = $this->replay($key, $hash);
        if ($replay !== null) {
            return $replay;
        }

        try {
            return Cache::lock('field-api:payment:'.$key, 30)->block(5, function () use ($key, $hash, $input, $actor) {
                // Another request with this key may have finished while we waited.
                return $this->replay($key, $hash) ?? $this->postLocked($key, $hash, $input, $actor);
            });
        } catch (LockTimeoutException) {
            throw new FieldApiError('CONCURRENT_REQUEST', 'A request with this Idempotency-Key is in progress.', 409);
        }
    }

    /** @return array{status: int, body: array<string, mixed>}|null */
    private function replay(string $key, string $hash): ?array
    {
        $existing = FieldPaymentRequest::query()->where('idempotency_key', $key)->first();
        if (! $existing) {
            return null;
        }
        if (! hash_equals($existing->request_hash, $hash)) {
            throw new FieldApiError('IDEMPOTENCY_MISMATCH', 'This Idempotency-Key was used with a different payment.', 422);
        }

        return ['status' => 200, 'body' => ['data' => $existing->response, 'idempotent_replay' => true]];
    }

    /**
     * @param array<string, mixed> $input
     * @return array{status: int, body: array<string, mixed>}
     */
    private function postLocked(string $key, string $hash, array $input, User $actor): array
    {
        $invoice = SalesInvoice::query()->find((int) $input['sales_invoice_id']);
        if (! $invoice instanceof SalesInvoice || (int) $invoice->customer_id !== (int) $input['customer_id']) {
            throw new FieldApiError('PLAN_NOT_FOUND', 'Invoice not found for this customer.', 404, ['sales_invoice_id' => (int) $input['sales_invoice_id']]);
        }
        if (! in_array($invoice->sale_type, FieldCustomerPresenter::RECEIVABLE_TYPES, true)
            || ! in_array($invoice->status, ['posted', 'active'], true)) {
            throw new FieldApiError('PLAN_CLOSED', 'This invoice is not open for collection.', 422, ['status' => $invoice->status, 'sale_type' => $invoice->sale_type]);
        }

        $customer = Customer::query()->find((int) $invoice->customer_id);
        if (! $customer instanceof Customer || ! $customer->is_active) {
            throw new FieldApiError('CUSTOMER_INACTIVE', 'Customer is inactive.', 422);
        }

        $amount = round((float) $input['amount'], 2);
        $due = round((float) $invoice->amount_due, 2);
        if ($due <= 0.009) {
            throw new FieldApiError('PLAN_SETTLED', 'Invoice already settled.', 422, ['outstanding' => FieldCustomerPresenter::money($due)]);
        }
        if ($amount > $due + 0.001) {
            throw new FieldApiError('AMOUNT_EXCEEDS_OUTSTANDING', 'Amount exceeds the invoice due.', 422, ['outstanding' => FieldCustomerPresenter::money($due)]);
        }

        $collectedAt = Carbon::parse((string) $input['collected_at'])->setTimezone((string) config('app.timezone'));
        $paymentDate = $collectedAt->toDateString();
        $today = Carbon::today();
        if ($collectedAt->gt(now()->addMinutes(10))) {
            throw new FieldApiError('VALIDATION_FAILED', 'collected_at is in the future.', 422, ['collected_at' => (string) $input['collected_at']]);
        }
        // No window unless FIELD_API_MAX_BACKDATE_DAYS is set (OD-28: any past date).
        $window = config('field_api.max_backdate_days');
        if ($window !== null) {
            $oldest = $today->copy()->subDays(max(0, (int) $window));
            if ($collectedAt->copy()->startOfDay()->lt($oldest)) {
                throw new FieldApiError('PERIOD_LOCKED', 'Collection date is older than the allowed back-date window.', 423, ['oldest_allowed' => $oldest->toDateString()]);
            }
        }

        // A given employee must be an active ERP employee. Without one (a field user not in the ERP's HR), the
        // collector is known by name only: the payment's referrer stays empty (the column is nullable, as on the
        // ERP's own advance payments) and the name is the reference and part of the notes.
        $employee = null;
        if (! empty($input['collector_employee_id'])) {
            $employee = Employee::query()->find((int) $input['collector_employee_id']);
            if (! $employee instanceof Employee || ! $employee->is_active || $employee->employment_status !== 'active') {
                throw new FieldApiError('COLLECTOR_NOT_LINKED', 'Collector is not an active ERP employee.', 422, ['collector_employee_id' => (int) $input['collector_employee_id']]);
            }
        }
        $collectorName = trim((string) ($input['collector_name'] ?? ''));
        if ($employee === null && $collectorName === '') {
            throw new FieldApiError('COLLECTOR_NOT_LINKED', 'Collector has no ERP employee and no name.', 422);
        }

        $method = $this->resolveMethod(
            $invoice,
            (string) $input['method_type'],
            isset($input['payment_method_id']) ? (int) $input['payment_method_id'] : null,
            isset($input['mobile_provider']) ? (string) $input['mobile_provider'] : null,
        );

        if ($invoice->sale_type === 'installment'
            && ! $actor->hasPermission('installment.allow-same-day-duplicate')
            && $this->hasPaymentOn($invoice, $paymentDate)) {
            throw new FieldApiError('DUPLICATE_SAME_DAY', 'A payment is already recorded on this installment invoice for that day.', 422, ['payment_date' => $paymentDate]);
        }

        $notes = trim(implode(' · ', array_filter([
            '[field_app]',
            'Receipt '.$input['field_receipt_no'],
            $employee === null ? 'Collected by '.$collectorName.' (no ERP employee)' : null,
            ! empty($input['transaction_ref']) ? 'Txn '.$input['transaction_ref'] : null,
            ! empty($input['note']) ? (string) $input['note'] : null,
        ])));

        try {
            $response = DB::transaction(function () use ($invoice, $amount, $method, $input, $employee, $collectorName, $paymentDate, $notes, $actor, $key, $hash) {
                $payment = $this->payments->recordAndAllocate($invoice, [
                    'amount' => $amount,
                    'payment_method_id' => $method->id,
                    'reference' => mb_substr($collectorName !== '' ? $collectorName : (string) $employee?->fullName(), 0, 100),
                    'referrer_employee_id' => $employee?->id,
                    'date' => $paymentDate,
                    'installment_target' => 'waterfall',
                    'notes' => $notes,
                ], (int) $actor->id);

                $body = $this->postedBody($payment, $method);
                FieldPaymentRequest::query()->create([
                    'idempotency_key' => $key,
                    'request_hash' => $hash,
                    'payment_id' => $payment->id,
                    'sales_invoice_id' => $invoice->id,
                    'customer_id' => $invoice->customer_id,
                    'field_collection_id' => isset($input['field_collection_id']) ? (string) $input['field_collection_id'] : null,
                    'field_receipt_no' => (string) $input['field_receipt_no'],
                    'response' => $body,
                ]);

                return $body;
            });
        } catch (OverpaymentException $e) {
            throw new FieldApiError('AMOUNT_EXCEEDS_OUTSTANDING', $e->getMessage(), 422);
        } catch (InvalidStateException $e) {
            $code = str_contains($e->getMessage(), 'already been recorded today') ? 'DUPLICATE_SAME_DAY' : 'ERP_REJECTED';
            throw new FieldApiError($code, $e->getMessage(), 422);
        }

        return ['status' => 201, 'body' => ['data' => $response, 'idempotent_replay' => false]];
    }

    /**
     * First active method of the requested type that the invoice branch can take (same list as the office form).
     * For mobile banking, a provider (bkash/nagad/rocket) must match the method's free-text mobile_provider,
     * so a bKash collection never lands in a Nagad wallet.
     */
    private function resolveMethod(SalesInvoice $invoice, string $type, ?int $methodId, ?string $provider): PaymentMethod
    {
        $norm = fn (?string $v) => preg_replace('/[^a-z]/', '', strtolower((string) $v));
        $available = PaymentMethod::availableForSalesAtLocation((int) $invoice->branch_id)
            ->filter(fn (PaymentMethod $m) => $m->type === $type)
            ->when($methodId !== null, fn ($c) => $c->where('id', $methodId))
            ->when($type === 'mobile_banking' && $norm($provider) !== '', fn ($c) => $c->filter(
                fn (PaymentMethod $m) => str_contains($norm($m->mobile_provider).$norm($m->name), $norm($provider))
            ))
            ->values();

        $method = $available->first();
        if (! $method instanceof PaymentMethod) {
            throw new FieldApiError('METHOD_NOT_AVAILABLE', 'No active payment method of this type at the invoice branch.', 422, ['method_type' => $type, 'branch_id' => (int) $invoice->branch_id]);
        }

        return $method;
    }

    private function hasPaymentOn(SalesInvoice $invoice, string $date): bool
    {
        return Payment::query()
            ->where('direction', 'inbound')
            ->whereDate('payment_date', $date)
            ->where(fn ($q) => $q->whereNull('reference_no')->orWhere('reference_no', '!=', InstallmentAdvanceService::REFERENCE))
            ->whereHas('paymentAllocations', fn ($q) => $q->where('sales_invoice_id', $invoice->id))
            ->exists();
    }

    /** @return array<string, mixed> */
    private function postedBody(Payment $payment, PaymentMethod $method): array
    {
        $payment->refresh();
        $allocation = $payment->paymentAllocations()->first();
        $invoice = SalesInvoice::query()->findOrFail((int) $allocation->sales_invoice_id);

        $customerDue = (float) SalesInvoice::query()
            ->where('customer_id', $invoice->customer_id)
            ->whereIn('sale_type', FieldCustomerPresenter::RECEIVABLE_TYPES)
            ->whereIn('status', ['posted', 'active'])
            ->sum('amount_due');

        $slices = InstallmentPaymentAllocation::query()
            ->join('installment_schedules as s', 's.id', '=', 'installment_payment_allocations.installment_schedule_id')
            ->where('installment_payment_allocations.payment_id', $payment->id)
            ->orderBy('s.installment_no')
            ->get(['s.installment_no', 'installment_payment_allocations.allocated_amount']);

        return [
            'id' => (int) $payment->id,
            'payment_number' => $payment->payment_number,
            'sales_invoice_id' => (int) $invoice->id,
            'customer_id' => (int) $invoice->customer_id,
            'amount' => FieldCustomerPresenter::money($payment->amount),
            'payment_date' => $payment->payment_date?->toDateString(),
            'payment_method_id' => (int) $method->id,
            'payment_method_name' => $method->name,
            'posted_at' => $payment->created_at?->toIso8601String(),
            'invoice_amount_due_after' => FieldCustomerPresenter::money($invoice->amount_due),
            'customer_outstanding_after' => FieldCustomerPresenter::money($customerDue),
            'allocations' => $slices->map(fn ($s) => [
                'installment_no' => (int) $s->installment_no,
                'amount' => FieldCustomerPresenter::money($s->allocated_amount),
            ])->values()->all(),
        ];
    }

    /** Fields that define "the same payment" for idempotency (informational fields excluded). */
    public static function requestHash(array $input): string
    {
        $norm = [
            'sales_invoice_id' => (int) $input['sales_invoice_id'],
            'customer_id' => (int) $input['customer_id'],
            'amount' => FieldCustomerPresenter::money($input['amount']),
            'method_type' => (string) $input['method_type'],
            'mobile_provider' => isset($input['mobile_provider']) && $input['mobile_provider'] !== '' ? strtolower((string) $input['mobile_provider']) : null,
            'payment_method_id' => isset($input['payment_method_id']) ? (int) $input['payment_method_id'] : null,
            'transaction_ref' => isset($input['transaction_ref']) && $input['transaction_ref'] !== '' ? (string) $input['transaction_ref'] : null,
            'collected_at' => Carbon::parse((string) $input['collected_at'])->utc()->format('Y-m-d\TH:i:s\Z'),
            'collector_employee_id' => (int) ($input['collector_employee_id'] ?? 0),
        ];

        return hash('sha256', json_encode($norm, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }
}
