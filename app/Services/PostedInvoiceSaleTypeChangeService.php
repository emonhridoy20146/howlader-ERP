<?php

namespace App\Services;

use App\Exceptions\InvalidStateException;
use App\Models\AuditLog;
use App\Models\SalesInvoice;
use Illuminate\Support\Facades\DB;

final class PostedInvoiceSaleTypeChangeService
{
    /**
     * @param  array{
     *     sale_type: string,
     *     credit_due_date?: string|null,
     * }  $data
     */
    public function change(SalesInvoice $invoice, array $data, int $actorUserId): void
    {
        DB::transaction(function () use ($invoice, $data, $actorUserId): void {
            $invoice = SalesInvoice::query()
                ->lockForUpdate()
                ->with(['debtWriteoffs', 'returnRefunds'])
                ->findOrFail($invoice->id);

            $this->assertCanChange($invoice);

            $newType = (string) $data['sale_type'];
            if ($newType === 'installment') {
                throw new InvalidStateException('Installment invoices cannot be created by changing payment type. Create an installment invoice at sale time instead.');
            }

            $oldType = (string) $invoice->sale_type;
            $beforeSnapshot = [
                'sale_type' => $oldType,
                'credit_due_date' => optional($invoice->credit_due_date)?->toDateString(),
                'status' => $invoice->status,
            ];

            if ($newType === 'credit') {
                $creditDueDate = $data['credit_due_date'] ?? null;
                if (empty($creditDueDate)) {
                    throw new InvalidStateException('Credit due date is required.');
                }

                $invoice->update([
                    'sale_type' => 'credit',
                    'credit_due_date' => $creditDueDate,
                    'status' => 'posted',
                    'installment_down_payment' => null,
                    'installment_count' => null,
                    'installment_first_due_date' => null,
                    'installment_frequency' => null,
                    'installment_custom_days' => null,
                    'installment_penalty_enabled' => false,
                    'installment_penalty_rate' => null,
                ]);
            } elseif ($newType === 'default') {
                $invoice->update([
                    'sale_type' => 'default',
                    'credit_due_date' => null,
                    'status' => 'posted',
                    'installment_down_payment' => null,
                    'installment_count' => null,
                    'installment_first_due_date' => null,
                    'installment_frequency' => null,
                    'installment_custom_days' => null,
                    'installment_penalty_enabled' => false,
                    'installment_penalty_rate' => null,
                ]);
            } else {
                throw new InvalidStateException('Unsupported sale type.');
            }

            $invoice->refresh();

            AuditLog::create([
                'actor_user_id' => $actorUserId,
                'action' => 'sales_invoice.sale_type_changed',
                'entity_type' => 'SalesInvoice',
                'entity_id' => $invoice->id,
                'before_snapshot' => $beforeSnapshot,
                'after_snapshot' => [
                    'sale_type' => $invoice->sale_type,
                    'credit_due_date' => optional($invoice->credit_due_date)?->toDateString(),
                    'status' => $invoice->status,
                ],
                'ip_address' => request()->ip(),
            ]);
        });
    }

    public function assertCanChange(SalesInvoice $invoice): void
    {
        if (! in_array($invoice->status, ['posted', 'active'], true)) {
            throw new InvalidStateException('Payment type can only be changed on posted or active invoices.');
        }

        if ($invoice->sale_type === 'installment') {
            throw new InvalidStateException('Installment invoices cannot be converted to another payment type.');
        }

        if ($invoice->debtWriteoffs->isNotEmpty()) {
            throw new InvalidStateException('Cannot change payment type on an invoice with debt write-offs.');
        }

        if ($invoice->returnRefunds->isNotEmpty()) {
            throw new InvalidStateException('Cannot change payment type on an invoice linked to return refunds.');
        }
    }
}
