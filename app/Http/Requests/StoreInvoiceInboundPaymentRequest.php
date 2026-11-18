<?php

namespace App\Http\Requests;

use App\Models\PaymentMethod;
use App\Models\SalesInvoice;
use Carbon\Carbon;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class StoreInvoiceInboundPaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        $invoice = $this->route('invoice');

        return $invoice instanceof SalesInvoice && $this->user()->can('view', $invoice);
    }

    protected function prepareForValidation(): void
    {
        if (! $this->user()?->can('apply-settlement-discount')) {
            $this->merge(['settlement_discount' => 0]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $dateRules = ['nullable', 'date', 'before_or_equal:today'];

        if (! $this->user()->can('backdate-sales-invoice')) {
            $dateRules[] = 'after_or_equal:'.now()->subDays(2)->toDateString();
        }

        return [
            'amount' => ['required', 'numeric', 'min:0'],
            'settlement_discount' => ['nullable', 'numeric', 'min:0'],
            'payment_method_id' => ['required', 'integer', 'exists:payment_methods,id'],
            'reference' => ['nullable', 'string', 'max:100'],
            'referrer_employee_id' => ['required', 'integer', 'exists:employees,id'],
            'payment_date' => $dateRules,
            'print_after' => ['nullable', 'boolean'],
            'installment_target' => ['nullable', 'in:waterfall,schedule'],
            'installment_schedule_id' => ['nullable', 'integer', 'exists:installment_schedules,id'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v): void {
            /** @var SalesInvoice|null $invoice */
            $invoice = $this->route('invoice');
            if (! $invoice instanceof SalesInvoice) {
                return;
            }

            $flexible = $this->allowsFlexiblePaymentCollection();
            $cash = round(max(0, (float) $this->input('amount', 0)), 2);
            $discount = round(max(0, (float) $this->input('settlement_discount', 0)), 2);
            $total = round($cash + $discount, 2);

            if ($total < 0.01) {
                $v->errors()->add('amount', 'Enter a collected amount and/or settlement discount totaling at least 0.01.');

                return;
            }

            $due = round((float) $invoice->amount_due, 2);
            if (! $flexible && $total > $due + 0.001) {
                $v->errors()->add('amount', 'Cash plus discount cannot exceed the amount due ('.number_format($due, 2).').');

                return;
            }

            if (! $flexible && $invoice->requiresFullPaymentOnCollection() && $total < $due - 0.001) {
                $v->errors()->add('amount', 'Cash sales must be paid in full. Collect the full outstanding balance ('.number_format($due, 2).').');

                return;
            }

            $availableMethods = PaymentMethod::availableForSalesAtLocation((int) $invoice->branch_id);
            $availableMethodIds = $availableMethods->pluck('id')->map(fn ($id) => (int) $id)->all();
            $methodId = (int) $this->input('payment_method_id', 0);

            if (! in_array($methodId, $availableMethodIds, true)) {
                $v->errors()->add('payment_method_id', 'Selected payment method is not available for this location.');

                return;
            }

            $installmentTarget = (string) $this->input('installment_target', 'waterfall');
            $scheduleId = $this->input('installment_schedule_id');

            if ($invoice->sale_type === 'installment' && $invoice->installmentPlan) {
                $invoice->loadMissing('installmentPlan.schedules');

                if ($installmentTarget === 'schedule') {
                    $scheduleIdInt = is_numeric($scheduleId) ? (int) $scheduleId : null;
                    if (! $scheduleIdInt) {
                        $v->errors()->add('installment_schedule_id', 'Choose which installment you are paying.');

                        return;
                    }

                    $eligibleIds = $invoice->installmentPlan
                        ->schedulesSelectableForTargetedPayment($flexible)
                        ->pluck('id')
                        ->all();

                    if (! in_array($scheduleIdInt, $eligibleIds, true)) {
                        $v->errors()->add('installment_schedule_id', 'That installment cannot be paid yet. Pay earlier installments first, or use automatic allocation.');

                        return;
                    }

                    if (! $flexible) {
                        $schedule = $invoice->installmentPlan->schedules->firstWhere('id', $scheduleIdInt);
                        $max = $schedule ? $schedule->remainingDue() : 0.0;

                        if ($total > $max + 0.009) {
                            $v->errors()->add('amount', 'Total cannot exceed the remaining balance for this installment ('.number_format($max, 2).').');
                        }
                    }
                }
            }
        });
    }

    protected function allowsFlexiblePaymentCollection(): bool
    {
        return (bool) $this->user()?->can('collect-flexible-payment');
    }

    /**
     * @return array{amount: float, settlement_discount: float, payment_method_id: int, reference: ?string, referrer_employee_id: ?int, date: string, installment_target: string, installment_schedule_id: ?int}
     */
    public function paymentServicePayload(): array
    {
        $invoice = $this->route('invoice');
        assert($invoice instanceof SalesInvoice);

        $installmentTarget = (string) $this->input('installment_target', 'waterfall');
        $scheduleId = null;

        if ($invoice->sale_type === 'installment' && $invoice->installmentPlan) {
            if ($installmentTarget === 'schedule') {
                $scheduleRaw = $this->input('installment_schedule_id');
                $scheduleId = (is_numeric($scheduleRaw) && (int) $scheduleRaw > 0)
                    ? (int) $scheduleRaw
                    : null;
            }
        } else {
            $installmentTarget = 'waterfall';
        }

        $paymentDate = $this->input('payment_date');
        $resolvedDate = ($paymentDate !== null && (string) $paymentDate !== '')
            ? Carbon::parse((string) $paymentDate)->toDateString()
            : now()->toDateString();

        return [
            'amount' => round(max(0, (float) $this->input('amount', 0)), 2),
            'settlement_discount' => round(max(0, (float) $this->input('settlement_discount', 0)), 2),
            'payment_method_id' => (int) $this->input('payment_method_id'),
            'reference' => $this->input('reference'),
            'referrer_employee_id' => $this->filled('referrer_employee_id')
                ? (int) $this->input('referrer_employee_id')
                : null,
            'date' => $resolvedDate,
            'installment_target' => $installmentTarget,
            'installment_schedule_id' => $scheduleId,
            'allow_flexible_collection' => $this->allowsFlexiblePaymentCollection(),
        ];
    }
}
