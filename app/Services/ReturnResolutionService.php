<?php

namespace App\Services;

use App\Exceptions\InvalidStateException;
use App\Models\Payment;
use App\Models\ReturnOrder;
use App\Models\ReturnRefund;
use App\Models\ReturnRefundAllocation;
use App\Models\SaleLineSerial;
use App\Models\SalesInvoice;
use App\Models\SerialItem;
use App\Models\StockLedger;
use App\Models\Wallet;
use App\Services\JournalService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class ReturnResolutionService
{
    public function __construct(
        private readonly InventoryBalanceService $inventoryBalanceService,
        private readonly DocumentNumberGenerator $documentNumbers,
        private readonly JournalService $journalService,
    ) {}

    /**
     * @param  array{
     *   settlement_mode:string,
     *   refund_amount_mode?:string,
     *   refund_percentage?:float|int|string,
     *   refund_flat_amount?:float|int|string,
     *   pay_now_amount:float|int|string,
     *   wallet_id:int|null,
     *   payment_date:string|null,
     *   reference_no:string|null,
     *   resolution_notes:string|null
     * }  $data
     */
    public function resolve(ReturnOrder $returnOrder, array $data, int $actorUserId): ReturnOrder
    {
        return DB::transaction(function () use ($returnOrder, $data, $actorUserId): ReturnOrder {
            /** @var ReturnOrder $locked */
            $locked = ReturnOrder::query()
                ->with([
                    'returnLines.salesInvoiceLine.product',
                    'returnLines.salesInvoiceLine.saleLineSerials.serialItem',
                    'returnLines.serialItem',
                    'refund',
                ])
                ->lockForUpdate()
                ->findOrFail($returnOrder->id);

            if ($locked->status !== 'draft') {
                throw new InvalidStateException('Only draft returns can be resolved.');
            }

            if ($locked->returnLines->isEmpty()) {
                throw new InvalidStateException('Configure which invoice lines to return before resolving.');
            }

            /** @var SalesInvoice $invoice */
            $invoice = SalesInvoice::query()
                ->with('lines.saleLineSerials')
                ->lockForUpdate()
                ->findOrFail($locked->sales_invoice_id);

            $this->restockFromReturnLines($locked, $invoice, (int) $locked->branch_id, $actorUserId);

            $lineValueBase = $this->refundBaseFromReturnLines($locked);
            $settlementBase = $this->refundSettlementBaseFromPaidShare($lineValueBase, $invoice);
            [$settlementAmount, $refundPercentage] = $this->resolveSettlementAmount($settlementBase, $data);
            $payNowAmount = round((float) $data['pay_now_amount'], 2);

            if ($settlementAmount > $settlementBase + 0.001) {
                throw new InvalidStateException(
                    'Settlement cannot exceed the refundable paid share ('.number_format($settlementBase, 2).').',
                );
            }

            if ($payNowAmount > $settlementAmount) {
                throw new InvalidStateException('Immediate refund cannot exceed settlement amount.');
            }

            $cashRemaining = $this->refundablePaidCashRemaining($invoice, $locked->id);
            if ($payNowAmount > $cashRemaining + 0.001) {
                throw new InvalidStateException(
                    'Refund payment cannot exceed the remaining paid amount on this invoice ('.number_format($cashRemaining, 2).' available).',
                );
            }

            if (($data['settlement_mode'] ?? '') === 'full') {
                $payNowAmount = $settlementAmount;
            }

            $dueAmount = round($settlementAmount - $payNowAmount, 2);

            if (($data['settlement_mode'] ?? '') === 'partial_final') {
                $payNowAmount = $settlementAmount;
                $dueAmount = 0.00;
            }

            $refundStatus = $this->computeRefundStatus($settlementAmount, $payNowAmount, $dueAmount);

            if ($settlementAmount > 0) {
                $this->journalService->postReturnSettlementGl(
                    $locked,
                    $invoice,
                    $settlementAmount,
                    $actorUserId,
                );
            }

            $refund = ReturnRefund::query()->updateOrCreate(
                ['return_order_id' => $locked->id],
                [
                    'customer_id' => $locked->customer_id,
                    'sales_invoice_id' => $locked->sales_invoice_id,
                    'obligation_amount' => $settlementAmount,
                    'paid_amount' => $payNowAmount,
                    'due_amount' => $dueAmount,
                    'status' => $refundStatus,
                    'notes' => $data['resolution_notes'] ?? null,
                ]
            );

            if ($payNowAmount > 0) {
                $payment = $this->createOutboundRefundPayment($locked, $data, $payNowAmount, $actorUserId);
                ReturnRefundAllocation::create([
                    'return_refund_id' => $refund->id,
                    'payment_id' => $payment->id,
                    'allocated_amount' => $payNowAmount,
                ]);
                $this->journalService->postRefundPaymentGl(
                    (int) $locked->branch_id,
                    $payNowAmount,
                    (int) $payment->wallet_id,
                    $payment,
                    $actorUserId
                );
            }

            $locked->update([
                'status' => $dueAmount > 0 ? 'approved' : 'refunded',
                'approved_by' => $actorUserId,
                'approved_at' => now(),
                'accepted_by' => $actorUserId,
                'accepted_at' => now(),
                'restocked_at' => now(),
                'refund_base_amount' => $lineValueBase,
                'refund_percentage' => $refundPercentage,
                'refund_settlement_amount' => $settlementAmount,
                'refund_paid_amount' => $payNowAmount,
                'refund_due_amount' => $dueAmount,
                'refund_status' => $refundStatus,
                'resolution_notes' => $data['resolution_notes'] ?? null,
            ]);

            $invoice->refresh(['lines.saleLineSerials', 'returnOrders.returnLines']);
            $invoice->refreshReturnedStatus();

            return $locked->fresh(['refund']);
        });
    }

    public function refundBaseForReturnOrder(ReturnOrder $returnOrder): float
    {
        $returnOrder->loadMissing('returnLines.salesInvoiceLine');

        return $this->refundBaseFromReturnLines($returnOrder);
    }

    /**
     * Refund cap for settlement: customer's paid amount allocated to returned lines (not invoice line totals).
     */
    public function refundSettlementBaseForReturnOrder(ReturnOrder $returnOrder): float
    {
        $returnOrder->loadMissing(['returnLines.salesInvoiceLine', 'salesInvoice']);

        $lineValueBase = $this->refundBaseFromReturnLines($returnOrder);
        $invoice = $returnOrder->salesInvoice;
        if (! $invoice) {
            return $lineValueBase;
        }

        return $this->refundSettlementBaseFromPaidShare($lineValueBase, $invoice, (int) $returnOrder->id);
    }

    public function calculateSettlementAmount(ReturnOrder $returnOrder, array $data): float
    {
        $settlementBase = $this->refundSettlementBaseForReturnOrder($returnOrder);

        return $this->resolveSettlementAmount($settlementBase, $data)[0];
    }

    /**
     * Settlement obligation still available on this invoice (excludes rolled-back returns).
     */
    public function refundablePaidObligationRemaining(SalesInvoice $invoice, ?int $excludeReturnOrderId = null): float
    {
        $amountPaid = round((float) $invoice->amount_paid, 2);
        $alreadyCommitted = $this->sumActiveReturnRefunds($invoice, 'refund_settlement_amount', $excludeReturnOrderId);

        return max(0.0, round($amountPaid - $alreadyCommitted, 2));
    }

    /**
     * Cash still available to pay out as refunds on this invoice.
     */
    public function refundablePaidCashRemaining(SalesInvoice $invoice, ?int $excludeReturnOrderId = null): float
    {
        $amountPaid = round((float) $invoice->amount_paid, 2);
        $alreadyPaidOut = $this->sumActiveReturnRefunds($invoice, 'refund_paid_amount', $excludeReturnOrderId);

        return max(0.0, round($amountPaid - $alreadyPaidOut, 2));
    }

    private function refundSettlementBaseFromPaidShare(float $lineValueBase, SalesInvoice $invoice, ?int $excludeReturnOrderId = null): float
    {
        $lineValueBase = round($lineValueBase, 2);
        $grandTotal = round((float) $invoice->grand_total, 2);
        $amountPaid = round((float) $invoice->amount_paid, 2);

        if ($lineValueBase <= 0.001 || $grandTotal <= 0.001 || $amountPaid <= 0.001) {
            return 0.0;
        }

        $returnShare = min(1.0, $lineValueBase / $grandTotal);
        $lineSharePaid = round($amountPaid * $returnShare, 2);
        $remaining = $this->refundablePaidObligationRemaining($invoice, $excludeReturnOrderId);

        return round(min($lineSharePaid, $remaining), 2);
    }

    private function sumActiveReturnRefunds(SalesInvoice $invoice, string $column, ?int $excludeReturnOrderId = null): float
    {
        if (! in_array($column, ['refund_settlement_amount', 'refund_paid_amount'], true)) {
            throw new \InvalidArgumentException('Unsupported refund sum column.');
        }

        return (float) ReturnOrder::query()
            ->where('sales_invoice_id', $invoice->id)
            ->whereIn('status', ['approved', 'refunded'])
            ->whereNull('rolled_back_at')
            ->when($excludeReturnOrderId !== null, fn ($query) => $query->where('id', '!=', $excludeReturnOrderId))
            ->sum($column);
    }

    public function payPending(ReturnOrder $returnOrder, array $data, int $actorUserId): ReturnOrder
    {
        return DB::transaction(function () use ($returnOrder, $data, $actorUserId): ReturnOrder {
            /** @var ReturnOrder $locked */
            $locked = ReturnOrder::query()
                ->with('refund')
                ->lockForUpdate()
                ->findOrFail($returnOrder->id);

            $refund = $locked->refund;
            if (! $refund) {
                throw new InvalidStateException('No refund obligation exists for this return.');
            }

            $payAmount = round((float) $data['pay_amount'], 2);
            if ($payAmount <= 0) {
                throw new InvalidStateException('Payment amount must be greater than zero.');
            }

            /** @var SalesInvoice $invoice */
            $invoice = SalesInvoice::query()->lockForUpdate()->findOrFail($locked->sales_invoice_id);

            $maxPayable = min($locked->refundAmountPayable(), $this->refundablePaidCashRemaining($invoice, $locked->id));
            if ($maxPayable <= 0) {
                throw new InvalidStateException('No refund balance is due on this return.');
            }
            if ($payAmount > $maxPayable) {
                throw new InvalidStateException(
                    'Payment exceeds the remaining refundable paid amount ('.number_format($maxPayable, 2).' available).',
                );
            }

            $obligation = round((float) $refund->obligation_amount, 2);
            if ($obligation > 0) {
                $this->journalService->postReturnSettlementGl($locked, $invoice, $obligation, $actorUserId);
            }

            $payment = $this->createOutboundRefundPayment($locked, $data, $payAmount, $actorUserId);
            ReturnRefundAllocation::create([
                'return_refund_id' => $refund->id,
                'payment_id' => $payment->id,
                'allocated_amount' => $payAmount,
            ]);
            $this->journalService->postRefundPaymentGl(
                (int) $locked->branch_id,
                $payAmount,
                (int) $payment->wallet_id,
                $payment,
                $actorUserId
            );

            $newPaid = round((float) $refund->paid_amount + $payAmount, 2);
            $newDue = round((float) $refund->obligation_amount - $newPaid, 2);
            $newStatus = $newDue <= 0 ? 'paid' : 'partial';

            $refund->update([
                'paid_amount' => $newPaid,
                'due_amount' => max(0, $newDue),
                'status' => $newStatus,
            ]);

            $locked->update([
                'refund_paid_amount' => $newPaid,
                'refund_due_amount' => max(0, $newDue),
                'refund_status' => $newStatus,
                'status' => $newDue <= 0 ? 'refunded' : $locked->status,
            ]);

            SalesInvoice::query()
                ->with(['lines.saleLineSerials', 'returnOrders.returnLines'])
                ->find($locked->sales_invoice_id)
                ?->refreshReturnedStatus();

            return $locked->fresh(['refund']);
        });
    }

    private function restockFromReturnLines(ReturnOrder $locked, SalesInvoice $invoice, int $locationId, int $actorUserId): void
    {
        $invoiceLineIds = $invoice->lines->pluck('id')->map(fn ($id) => (int) $id)->all();

        foreach ($locked->returnLines as $returnLine) {
            $line = $returnLine->salesInvoiceLine;
            if (! $line || ! in_array((int) $line->id, $invoiceLineIds, true)) {
                throw new InvalidStateException('Return line does not belong to this invoice.');
            }

            if ($returnLine->serial_item_id) {
                $allowedSerialIds = $line->saleLineSerials->pluck('serial_item_id')->map(fn ($id) => (int) $id)->all();
                if (! in_array((int) $returnLine->serial_item_id, $allowedSerialIds, true)) {
                    throw new InvalidStateException('Serial is not part of this invoice line.');
                }

                $serial = SerialItem::query()->lockForUpdate()->findOrFail($returnLine->serial_item_id);
                $serial->update([
                    'status' => 'in_stock',
                    'current_location_id' => $locationId,
                    'sale_invoice_line_id' => null,
                ]);

                SaleLineSerial::query()
                    ->where('sales_invoice_line_id', $line->id)
                    ->where('serial_item_id', $serial->id)
                    ->whereNull('released_at')
                    ->update(['released_at' => now()]);

                StockLedger::create([
                    'event_time' => now(),
                    'product_id' => $line->product_id,
                    'serial_item_id' => $serial->id,
                    'location_id' => $locationId,
                    'movement_type' => 'return_receive',
                    'quantity_delta' => 1,
                    'unit_cost' => $serial->purchase_cost,
                    'reference_type' => 'ReturnOrder',
                    'reference_id' => $locked->id,
                    'actor_user_id' => $actorUserId,
                ]);
            } else {
                $qty = (int) $returnLine->qty;
                if ($qty <= 0) {
                    throw new InvalidStateException('Invalid return quantity for a non-serialized line.');
                }
                if ($qty > (int) $line->qty) {
                    throw new InvalidStateException('Return quantity exceeds invoiced quantity.');
                }

                if ($line->product?->is_serialized) {
                    // Old invoice line with no serial records for a serialized product: give each returned
                    // unit a generated internal serial so it is tracked as real, sellable stock.
                    $product = $line->product;
                    for ($unit = 0; $unit < $qty; $unit++) {
                        $serial = SerialItem::create([
                            'product_id' => $line->product_id,
                            'serial_number' => $this->nextInternalSerialCode(),
                            'serial_source' => 'internal',
                            'current_location_id' => $locationId,
                            'status' => 'in_stock',
                            'sale_invoice_line_id' => null,
                            'purchase_cost' => (float) ($product->default_purchase_price ?? 0),
                        ]);

                        StockLedger::create([
                            'event_time' => now(),
                            'product_id' => $line->product_id,
                            'serial_item_id' => $serial->id,
                            'location_id' => $locationId,
                            'movement_type' => 'return_receive',
                            'quantity_delta' => 1,
                            'unit_cost' => $serial->purchase_cost,
                            'reference_type' => 'ReturnOrder',
                            'reference_id' => $locked->id,
                            'actor_user_id' => $actorUserId,
                        ]);
                    }
                } else {
                    StockLedger::create([
                        'event_time' => now(),
                        'product_id' => $line->product_id,
                        'serial_item_id' => null,
                        'location_id' => $locationId,
                        'movement_type' => 'return_receive',
                        'quantity_delta' => $qty,
                        'unit_cost' => null,
                        'reference_type' => 'ReturnOrder',
                        'reference_id' => $locked->id,
                        'actor_user_id' => $actorUserId,
                    ]);
                }
            }

            $this->inventoryBalanceService->recalculate((int) $line->product_id, $locationId);
        }
    }

    private function nextInternalSerialCode(): string
    {
        for ($attempt = 0; $attempt < 20; $attempt++) {
            $candidate = $this->documentNumbers->next(
                DocumentNumberGenerator::TYPE_SERIAL_BACKFILL,
                'AST',
                now(),
                'Ymd',
                6,
            );
            if (! SerialItem::query()->where('serial_number', $candidate)->exists()) {
                return $candidate;
            }
        }

        throw new \RuntimeException('Unable to generate a unique serial number for the returned unit. Please retry.');
    }

    private function refundBaseFromReturnLines(ReturnOrder $locked): float
    {
        $total = 0.0;
        foreach ($locked->returnLines as $returnLine) {
            $line = $returnLine->salesInvoiceLine;
            if (! $line) {
                continue;
            }
            $lineQty = (float) $line->qty;
            $lineTotal = (float) $line->line_total;
            if ($returnLine->serial_item_id) {
                $total += $lineQty > 0 ? round($lineTotal / $lineQty, 2) : $lineTotal;
            } else {
                $units = (int) $returnLine->qty;
                $total += $lineQty > 0 ? round($lineTotal * ($units / $lineQty), 2) : 0.0;
            }
        }

        return round($total, 2);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{0: float, 1: float}
     */
    private function resolveSettlementAmount(float $baseAmount, array $data): array
    {
        $baseAmount = round($baseAmount, 2);
        $mode = (string) ($data['refund_amount_mode'] ?? 'percent');

        if ($mode === 'flat') {
            $settlementAmount = round(max(0, (float) ($data['refund_flat_amount'] ?? 0)), 2);
            if ($settlementAmount > $baseAmount + 0.001) {
                throw new InvalidStateException(
                    'Refund amount cannot exceed the refundable paid share ('.number_format($baseAmount, 2).').',
                );
            }

            $refundPercentage = $baseAmount > 0.009
                ? round(($settlementAmount / $baseAmount) * 100, 2)
                : 0.0;

            return [$settlementAmount, $refundPercentage];
        }

        $refundPercentage = round((float) ($data['refund_percentage'] ?? 0), 2);
        $settlementAmount = round(($baseAmount * $refundPercentage) / 100, 2);

        return [$settlementAmount, $refundPercentage];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function createOutboundRefundPayment(ReturnOrder $returnOrder, array $data, float $amount, int $actorUserId): Payment
    {
        $wallet = Wallet::query()->findOrFail((int) $data['wallet_id']);

        $refDate = isset($data['payment_date']) && $data['payment_date'] !== ''
            ? Carbon::parse((string) $data['payment_date'])
            : now();

        return Payment::create([
            'payment_number' => $this->documentNumbers->next(
                DocumentNumberGenerator::TYPE_PAYMENT_REFUND,
                'REF',
                $refDate,
                'Ymd',
                5,
            ),
            'customer_id' => $returnOrder->customer_id,
            'payment_date' => $data['payment_date'] ?? now()->toDateString(),
            'payment_method' => $this->mapWalletKindToPaymentEnum($wallet->kind),
            'payment_method_id' => null,
            'wallet_id' => $wallet->id,
            'amount' => $amount,
            'direction' => 'outbound',
            'reference_no' => $data['reference_no'] ?? null,
            'notes' => 'Refund for '.$returnOrder->return_number,
            'recorded_by' => $actorUserId,
        ]);
    }

    private function computeRefundStatus(float $settlementAmount, float $paid, float $due): string
    {
        if ($settlementAmount <= 0.0) {
            return 'waived';
        }
        if ($due <= 0.0 && $paid > 0.0) {
            return 'paid';
        }
        if ($paid > 0.0) {
            return 'partial';
        }

        return 'unpaid';
    }

    private function mapWalletKindToPaymentEnum(string $kind): string
    {
        return match ($kind) {
            'bank' => 'bank_transfer',
            'card_terminal' => 'card',
            'mobile_banking' => 'mobile_money',
            default => 'cash',
        };
    }
}
