<?php

namespace App\Http\Requests;

use App\Models\PaymentMethod;
use App\Models\SalesInvoice;
use App\Services\InstallmentAdvanceService;
use Illuminate\Validation\Validator;

class StoreInvoiceHistoricalPaymentRequest extends StoreInvoiceInboundPaymentRequest
{
    public function authorize(): bool
    {
        $invoice = $this->route('invoice');

        return $invoice instanceof SalesInvoice
            && $this->user()?->can('backdate-sales-invoice')
            && $this->user()->can('view', $invoice);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        /** @var SalesInvoice|null $invoice */
        $invoice = $this->route('invoice');
        $invoiceDate = $invoice instanceof SalesInvoice
            ? ($invoice->invoice_date?->toDateString() ?? now()->toDateString())
            : now()->toDateString();

        return array_merge(parent::rules(), [
            'payment_date' => ['required', 'date', 'before_or_equal:today', 'after_or_equal:'.$invoiceDate],
            'notes' => ['nullable', 'string', 'max:500'],
            'skip_wallet_gl' => ['nullable', 'boolean'],
            'installment_target' => ['nullable', 'in:waterfall,schedule,advance'],
            '_form' => ['nullable', 'string'],
        ]);
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v): void {
            /** @var SalesInvoice|null $invoice */
            $invoice = $this->route('invoice');
            if (! $invoice instanceof SalesInvoice) {
                return;
            }

            if (! $invoice->allowsAdminHistoricalPayment()) {
                $v->errors()->add('amount', 'This invoice cannot accept a historical payment.');

                return;
            }

            $cash = round(max(0, (float) $this->input('amount', 0)), 2);
            $discount = round(max(0, (float) $this->input('settlement_discount', 0)), 2);
            $total = round($cash + $discount, 2);

            if ($total < 0.01) {
                $v->errors()->add('amount', 'Enter a collected amount and/or settlement discount totaling at least 0.01.');

                return;
            }

            $max = $invoice->maxHistoricalPaymentAmount();
            if (! $this->allowsFlexiblePaymentCollection() && $total > $max + 0.001) {
                $v->errors()->add('amount', 'Cash plus discount cannot exceed the unrecorded balance ('.number_format($max, 2).').');

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

            if ($invoice->sale_type === 'installment' && $invoice->installmentPlan && $installmentTarget === 'advance') {
                $maxAdvance = app(InstallmentAdvanceService::class)->maxHistoricalAdvanceAmount($invoice);
                if (! $this->allowsFlexiblePaymentCollection() && $total > $maxAdvance + 0.009) {
                    $configured = round(max(0, (float) ($invoice->installment_down_payment ?? 0)), 2);
                    if ($configured > 0.009) {
                        $v->errors()->add('amount', 'Advance cannot exceed the remaining unrecorded advance ('.number_format($maxAdvance, 2).').');
                    } else {
                        $v->errors()->add('amount', 'Advance cannot exceed '.number_format($maxAdvance, 2).'.');
                    }
                }

                return;
            }

            if ($invoice->sale_type === 'installment' && $invoice->installmentPlan) {
                $invoice->loadMissing('installmentPlan.schedules');
                $flexible = (bool) $this->user()?->can('collect-flexible-payment');

                if ($installmentTarget === 'schedule') {
                    $scheduleId = $this->input('installment_schedule_id');
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
                        $scheduleMax = $schedule ? $schedule->remainingDue() : 0.0;

                        if ($total > $scheduleMax + 0.009) {
                            $v->errors()->add('amount', 'Total cannot exceed the remaining balance for this installment ('.number_format($scheduleMax, 2).').');
                        }
                    }
                }
            }
        });
    }

    /**
     * @return array{amount: float, settlement_discount: float, payment_method_id: int, reference: ?string, date: string, installment_target: string, installment_schedule_id: ?int, skip_wallet_gl: bool, historical_payment: bool, backfill_only: bool, notes: ?string}
     */
    public function paymentServicePayload(): array
    {
        /** @var SalesInvoice $invoice */
        $invoice = $this->route('invoice');
        $installmentTarget = (string) $this->input('installment_target', 'waterfall');

        $payload = array_merge(parent::paymentServicePayload(), [
            'skip_wallet_gl' => $this->boolean('skip_wallet_gl'),
            'historical_payment' => true,
            'backfill_only' => true,
            'notes' => $this->input('notes'),
        ]);

        if ($invoice->sale_type === 'installment' && $installmentTarget === 'advance') {
            $payload['installment_target'] = 'advance';
            $payload['installment_schedule_id'] = null;
            $payload['reference'] = InstallmentAdvanceService::REFERENCE;
        }

        $payload['allow_flexible_collection'] = (bool) $this->user()?->can('collect-flexible-payment');

        return $payload;
    }
}
