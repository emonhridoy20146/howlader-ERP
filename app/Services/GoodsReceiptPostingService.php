<?php

namespace App\Services;

use App\Exceptions\InvalidStateException;
use App\Exceptions\SerialCountMismatchException;
use App\Models\GoodsReceipt;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderLine;
use App\Models\SerialItem;
use App\Models\StockLedger;
use Carbon\Carbon;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;

class GoodsReceiptPostingService
{
    /**
     * @param  DateTimeInterface|null  $ledgerEventTime  When set (e.g. Dizicashier import), stock ledger rows and posted_at use this time instead of now().
     */
    public function post(GoodsReceipt $grn, int $actorUserId, ?DateTimeInterface $ledgerEventTime = null): void
    {
        $eventTime = $ledgerEventTime !== null ? Carbon::parse($ledgerEventTime) : now();

        DB::transaction(function () use ($grn, $actorUserId, $eventTime): void {
            $grn->load('lines.product', 'lines.serials');
            $purchaseOrder = null;

            if ($grn->status !== 'draft') {
                throw new InvalidStateException('GRN already posted or cancelled.');
            }

            if ($grn->purchase_order_id) {
                $purchaseOrder = PurchaseOrder::query()
                    ->lockForUpdate()
                    ->with('lines')
                    ->findOrFail($grn->purchase_order_id);

                $poLinesByProduct = $purchaseOrder->lines->groupBy('product_id');
                $incomingQtyByProduct = $grn->lines
                    ->groupBy('product_id')
                    ->map(fn ($rows) => (int) $rows->sum('qty_received'));

                foreach ($incomingQtyByProduct as $productId => $incomingQty) {
                    $poLines = $poLinesByProduct->get($productId);

                    if (! $poLines || $poLines->isEmpty()) {
                        throw new InvalidStateException('Product '.$productId.' is not part of the selected purchase order.');
                    }

                    $remainingQty = (int) $poLines->sum(
                        fn (PurchaseOrderLine $line): int => max(0, (int) $line->qty_ordered - (int) $line->qty_received)
                    );
                    if ($incomingQty > $remainingQty) {
                        throw new InvalidStateException('Received quantity exceeds pending PO quantity for product '.$productId.'.');
                    }
                }
            }

            foreach ($grn->lines as $line) {
                $isSerialized = (bool) ($line->product?->is_serialized);

                if ($isSerialized && $line->serials->count() !== $line->qty_received) {
                    throw new SerialCountMismatchException('Serial count mismatch for product '.$line->product_id);
                }

                if ($isSerialized) {
                    foreach ($line->serials as $serial) {
                        $lockedSerial = SerialItem::lockForUpdate()->findOrFail($serial->id);

                        if ($lockedSerial->status !== 'draft') {
                            throw new InvalidStateException('Serial already processed: '.$lockedSerial->serial_number);
                        }

                        $lockedSerial->update([
                            'status' => 'in_stock',
                            'current_location_id' => $grn->location_id,
                            'purchase_cost' => $line->unit_cost,
                            'goods_receipt_line_id' => $line->id,
                        ]);

                        StockLedger::create([
                            'event_time' => $eventTime,
                            'product_id' => $line->product_id,
                            'serial_item_id' => $lockedSerial->id,
                            'location_id' => $grn->location_id,
                            'movement_type' => 'purchase_receive',
                            'quantity_delta' => 1,
                            'unit_cost' => $line->unit_cost,
                            'reference_type' => 'GoodsReceipt',
                            'reference_id' => $grn->id,
                            'actor_user_id' => $actorUserId,
                        ]);
                    }
                } else {
                    StockLedger::create([
                        'event_time' => $eventTime,
                        'product_id' => $line->product_id,
                        'serial_item_id' => null,
                        'location_id' => $grn->location_id,
                        'movement_type' => 'purchase_receive',
                        'quantity_delta' => (int) $line->qty_received,
                        'unit_cost' => $line->unit_cost,
                        'reference_type' => 'GoodsReceipt',
                        'reference_id' => $grn->id,
                        'actor_user_id' => $actorUserId,
                    ]);
                }

                app(InventoryBalanceService::class)->recalculate($line->product_id, $grn->location_id);

                if ($grn->purchase_order_id) {
                    $remainingToApply = (int) $line->qty_received;
                    $poLines = PurchaseOrderLine::query()
                        ->where('purchase_order_id', $grn->purchase_order_id)
                        ->where('product_id', $line->product_id)
                        ->orderBy('id')
                        ->lockForUpdate()
                        ->get();

                    foreach ($poLines as $poLine) {
                        if ($remainingToApply <= 0) {
                            break;
                        }
                        $pendingQty = max(0, (int) $poLine->qty_ordered - (int) $poLine->qty_received);
                        if ($pendingQty <= 0) {
                            continue;
                        }
                        $applyQty = min($pendingQty, $remainingToApply);
                        $poLine->increment('qty_received', $applyQty);
                        $remainingToApply -= $applyQty;
                    }

                    if ($remainingToApply > 0) {
                        throw new InvalidStateException('Received quantity exceeds pending PO quantity for product '.$line->product_id.'.');
                    }
                }
            }

            $grn->update([
                'status' => 'posted',
                'posted_by' => $actorUserId,
                'posted_at' => $eventTime,
            ]);

            if ($purchaseOrder) {
                $purchaseOrder->refresh()->load('lines');
                $totalOrdered = (int) $purchaseOrder->lines->sum('qty_ordered');
                $totalReceived = (int) $purchaseOrder->lines->sum('qty_received');

                $nextStatus = $totalReceived >= $totalOrdered
                    ? 'received'
                    : ($totalReceived > 0 ? 'partially_received' : 'approved');

                if ($purchaseOrder->status !== $nextStatus) {
                    $purchaseOrder->update(['status' => $nextStatus]);
                }
            }
        });
    }

    public function rollbackAndDelete(GoodsReceipt $grn, ?int $actorUserId = null): void
    {
        DB::transaction(function () use ($grn): void {
            $grn = GoodsReceipt::query()
                ->lockForUpdate()
                ->with(['lines.serials', 'lines.product'])
                ->findOrFail($grn->id);

            $lineIds = $grn->lines->pluck('id')->map(fn ($id) => (int) $id)->all();
            $serials = collect();
            if ($lineIds !== []) {
                $serials = SerialItem::query()
                    ->whereIn('goods_receipt_line_id', $lineIds)
                    ->lockForUpdate()
                    ->get();
            }

            $locationId = (int) $grn->location_id;
            $productIds = $grn->lines->pluck('product_id')->map(fn ($id) => (int) $id)->unique()->values()->all();
            $purchaseOrder = null;

            if ($grn->purchase_order_id) {
                $purchaseOrder = PurchaseOrder::query()
                    ->lockForUpdate()
                    ->with('lines')
                    ->find($grn->purchase_order_id);
            }

            if ($grn->status === 'posted') {
                foreach ($serials as $serial) {
                    if ((int) ($serial->sale_invoice_line_id ?? 0) > 0) {
                        throw new InvalidStateException('Cannot delete posted GRN because one or more serials are already used in sales.');
                    }

                    if (! in_array((string) $serial->status, ['draft', 'in_stock'], true)) {
                        throw new InvalidStateException('Cannot delete posted GRN because one or more serials are no longer in stock.');
                    }
                }

                StockLedger::query()
                    ->where('reference_id', $grn->id)
                    ->where(function ($q): void {
                        // Postings use short string `'GoodsReceipt'`; older buggy rollbacks queried the class string only.
                        $q->where('reference_type', 'GoodsReceipt')
                            ->orWhere('reference_type', GoodsReceipt::class);
                    })
                    ->delete();

                if ($purchaseOrder) {
                    foreach ($grn->lines as $line) {
                        $poLine = $purchaseOrder->lines->firstWhere('product_id', (int) $line->product_id);
                        if (! $poLine) {
                            continue;
                        }

                        $nextQty = max(0, (int) $poLine->qty_received - (int) $line->qty_received);
                        $poLine->update(['qty_received' => $nextQty]);
                    }
                }
            }

            foreach ($serials as $serial) {
                $serial->delete();
            }

            $grn->delete();

            $balance = app(InventoryBalanceService::class);
            foreach ($productIds as $productId) {
                $balance->recalculate((int) $productId, $locationId);
            }

            if ($purchaseOrder) {
                $purchaseOrder->refresh()->load('lines');
                $totalOrdered = (int) $purchaseOrder->lines->sum('qty_ordered');
                $totalReceived = (int) $purchaseOrder->lines->sum('qty_received');
                $nextStatus = $totalReceived >= $totalOrdered
                    ? 'received'
                    : ($totalReceived > 0 ? 'partially_received' : 'approved');

                if ($purchaseOrder->status !== $nextStatus) {
                    $purchaseOrder->update(['status' => $nextStatus]);
                }
            }
        });
    }
}
