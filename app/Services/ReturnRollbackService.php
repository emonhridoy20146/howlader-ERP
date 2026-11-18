<?php

namespace App\Services;

use App\Exceptions\InvalidStateException;
use App\Models\AuditLog;
use App\Models\Payment;
use App\Models\ReturnOrder;
use App\Models\ReturnRefundAllocation;
use App\Models\SaleLineSerial;
use App\Models\SalesInvoice;
use App\Models\SerialItem;
use App\Models\StockLedger;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class ReturnRollbackService
{
    public function __construct(
        private readonly InventoryBalanceService $inventoryBalanceService,
        private readonly JournalService $journalService,
    ) {}

    public function rollback(ReturnOrder $returnOrder, ?string $notes, int $actorUserId): ReturnOrder
    {
        return DB::transaction(function () use ($returnOrder, $notes, $actorUserId): ReturnOrder {
            /** @var ReturnOrder $locked */
            $locked = ReturnOrder::query()
                ->with([
                    'returnLines.salesInvoiceLine.product',
                    'returnLines.salesInvoiceLine.saleLineSerials',
                    'returnLines.serialItem',
                    'refund.allocations.payment',
                ])
                ->lockForUpdate()
                ->findOrFail($returnOrder->id);

            if (! $locked->canRollback()) {
                throw new InvalidStateException('This return cannot be rolled back.');
            }

            /** @var SalesInvoice $invoice */
            $invoice = SalesInvoice::query()
                ->with(['lines.saleLineSerials', 'returnOrders.returnLines'])
                ->lockForUpdate()
                ->findOrFail($locked->sales_invoice_id);

            $locationId = (int) $locked->branch_id;
            $ledgerTime = Carbon::now();

            foreach ($locked->returnLines as $returnLine) {
                $line = $returnLine->salesInvoiceLine;
                if (! $line) {
                    throw new InvalidStateException('Return line is missing its invoice line.');
                }

                if ($returnLine->serial_item_id) {
                    $this->rollbackSerializedLine($locked, $returnLine, $line, $invoice, $locationId, $actorUserId, $ledgerTime);
                } else {
                    $this->rollbackNonSerializedLine($locked, $returnLine, $line, $locationId, $actorUserId, $ledgerTime);
                }

                $this->inventoryBalanceService->recalculate((int) $line->product_id, $locationId);
            }

            $this->reverseRefundPayments($locked, $actorUserId);

            $invoice = SalesInvoice::query()->find($locked->sales_invoice_id);
            if ($invoice instanceof SalesInvoice) {
                $settlementAmount = round((float) $locked->refund_settlement_amount, 2);
                if ($settlementAmount > 0) {
                    $this->journalService->postReturnSettlementReversalGl(
                        $locked,
                        $invoice,
                        $settlementAmount,
                        $actorUserId,
                    );
                }
            }

            $beforeSnapshot = [
                'status' => $locked->status,
                'refund_status' => $locked->refund_status,
                'refund_paid_amount' => $locked->refund_paid_amount,
                'refund_due_amount' => $locked->refund_due_amount,
                'restocked_at' => optional($locked->restocked_at)?->toDateTimeString(),
            ];

            $locked->update([
                'rolled_back_at' => now(),
                'rolled_back_by' => $actorUserId,
                'rollback_notes' => $notes,
            ]);

            if ($locked->refund) {
                $locked->refund->update([
                    'paid_amount' => 0,
                    'due_amount' => 0,
                    'status' => 'waived',
                    'notes' => trim(($locked->refund->notes ?? '').' Rolled back.'),
                ]);
            }

            $locked->update([
                'refund_paid_amount' => 0,
                'refund_due_amount' => 0,
                'refund_status' => 'waived',
            ]);

            $invoice->refresh(['lines.saleLineSerials', 'returnOrders.returnLines']);
            $invoice->refreshReturnedStatus();

            AuditLog::create([
                'actor_user_id' => $actorUserId,
                'action' => 'return_order.rolled_back',
                'entity_type' => ReturnOrder::class,
                'entity_id' => $locked->id,
                'before_snapshot' => $beforeSnapshot,
                'after_snapshot' => [
                    'status' => $locked->fresh()->status,
                    'rolled_back_at' => now()->toDateTimeString(),
                    'rollback_notes' => $notes,
                ],
                'ip_address' => request()->ip(),
            ]);

            return $locked->fresh(['refund.allocations.payment.wallet', 'returnLines.serialItem']);
        });
    }

    private function rollbackSerializedLine(
        ReturnOrder $returnOrder,
        $returnLine,
        $line,
        SalesInvoice $invoice,
        int $locationId,
        int $actorUserId,
        Carbon $ledgerTime,
    ): void {
        $serial = SerialItem::query()->lockForUpdate()->findOrFail((int) $returnLine->serial_item_id);

        if ((int) $serial->current_location_id !== $locationId) {
            throw new InvalidStateException(
                'Serial '.$serial->serial_number.' is no longer at this return branch; cannot roll back.',
            );
        }

        if ($serial->status !== 'in_stock') {
            throw new InvalidStateException(
                'Serial '.$serial->serial_number.' is not in stock; it may have been sold or moved again.',
            );
        }

        $saleLineSerial = SaleLineSerial::query()
            ->where('sales_invoice_line_id', $line->id)
            ->where('serial_item_id', $serial->id)
            ->lockForUpdate()
            ->first();

        if (! $saleLineSerial) {
            throw new InvalidStateException('Original sale serial link not found for this return line.');
        }

        if ($saleLineSerial->released_at === null) {
            throw new InvalidStateException('This serial is already marked as sold on the invoice.');
        }

        $start = $invoice->invoice_date?->toDateString() ?? $ledgerTime->toDateString();
        $warrantyMonths = (int) ($line->warranty_months_snapshot ?? 0);
        $end = $warrantyMonths > 0
            ? Carbon::parse($start)->addMonths($warrantyMonths)->toDateString()
            : null;

        $serial->update([
            'status' => 'sold',
            'sale_invoice_line_id' => $line->id,
            'warranty_start_date' => $start,
            'warranty_end_date' => $end,
        ]);

        $saleLineSerial->update(['released_at' => null]);

        StockLedger::create([
            'event_time' => $ledgerTime,
            'product_id' => $line->product_id,
            'serial_item_id' => $serial->id,
            'location_id' => $locationId,
            'movement_type' => 'sale_issue',
            'quantity_delta' => -1,
            'unit_cost' => $serial->purchase_cost,
            'reference_type' => ReturnOrder::class,
            'reference_id' => $returnOrder->id,
            'actor_user_id' => $actorUserId,
        ]);
    }

    private function rollbackNonSerializedLine(
        ReturnOrder $returnOrder,
        $returnLine,
        $line,
        int $locationId,
        int $actorUserId,
        Carbon $ledgerTime,
    ): void {
        $qty = (int) $returnLine->qty;
        if ($qty <= 0) {
            throw new InvalidStateException('Invalid return quantity for rollback.');
        }

        // Units that came back with a generated serial (serialized product, old invoice line without serials).
        $generatedSerialIds = StockLedger::query()
            ->where('reference_type', 'ReturnOrder')
            ->where('reference_id', $returnOrder->id)
            ->where('movement_type', 'return_receive')
            ->where('product_id', $line->product_id)
            ->whereNotNull('serial_item_id')
            // skip units already reversed by another line of this same rollback
            ->whereNotIn('serial_item_id', StockLedger::query()
                ->where('reference_type', ReturnOrder::class)
                ->where('reference_id', $returnOrder->id)
                ->where('movement_type', 'sale_issue')
                ->whereNotNull('serial_item_id')
                ->select('serial_item_id'))
            ->orderBy('id')
            ->limit($qty)
            ->pluck('serial_item_id')
            ->unique()
            ->values();

        if ($generatedSerialIds->isNotEmpty()) {
            foreach ($generatedSerialIds as $serialId) {
                $serial = SerialItem::query()->lockForUpdate()->findOrFail((int) $serialId);

                if ($serial->status !== 'in_stock' || (int) $serial->current_location_id !== $locationId) {
                    throw new InvalidStateException(
                        'Cannot roll back: returned unit '.$serial->serial_number.' is no longer in stock at this branch.',
                    );
                }

                $serial->update(['status' => 'sold']);

                StockLedger::create([
                    'event_time' => $ledgerTime,
                    'product_id' => $line->product_id,
                    'serial_item_id' => $serial->id,
                    'location_id' => $locationId,
                    'movement_type' => 'sale_issue',
                    'quantity_delta' => -1,
                    'unit_cost' => $serial->purchase_cost,
                    'reference_type' => ReturnOrder::class,
                    'reference_id' => $returnOrder->id,
                    'actor_user_id' => $actorUserId,
                ]);
            }

            return;
        }

        StockLedger::create([
            'event_time' => $ledgerTime,
            'product_id' => $line->product_id,
            'serial_item_id' => null,
            'location_id' => $locationId,
            'movement_type' => 'sale_issue',
            'quantity_delta' => -$qty,
            'unit_cost' => null,
            'reference_type' => ReturnOrder::class,
            'reference_id' => $returnOrder->id,
            'actor_user_id' => $actorUserId,
        ]);
    }

    private function reverseRefundPayments(ReturnOrder $returnOrder, int $actorUserId): void
    {
        $refund = $returnOrder->refund;
        if (! $refund) {
            return;
        }

        foreach ($refund->allocations as $allocation) {
            $payment = $allocation->payment;
            if (! $payment instanceof Payment) {
                continue;
            }

            $amount = round((float) $allocation->allocated_amount, 2);
            if ($amount <= 0 || ! $payment->wallet_id) {
                continue;
            }

            $this->journalService->postRefundPaymentReversalGl(
                (int) $returnOrder->branch_id,
                $amount,
                (int) $payment->wallet_id,
                $payment,
                $actorUserId,
                $returnOrder,
            );

            $payment->update([
                'notes' => trim(($payment->notes ?? '').' [rolled_back]'),
            ]);

            ReturnRefundAllocation::query()->whereKey($allocation->id)->delete();
            Payment::query()->whereKey($payment->id)->delete();
        }
    }
}
