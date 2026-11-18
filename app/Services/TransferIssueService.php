<?php

namespace App\Services;

use App\Exceptions\InvalidStateException;
use App\Exceptions\SerialLocationMismatchException;
use App\Exceptions\SerialNotAvailableException;
use App\Models\StockLedger;
use App\Models\StockTransfer;
use App\Models\StockTransferSerial;
use Illuminate\Support\Facades\DB;

class TransferIssueService
{
    public function __construct(private readonly InventoryBalanceService $inventoryBalanceService) {}

    public function issue(StockTransfer $transfer, int $actorUserId): void
    {
        DB::transaction(function () use ($transfer, $actorUserId): void {
            if ($transfer->status !== 'approved') {
                throw new InvalidStateException('Transfer must be approved before issuing.');
            }

            $transfer->load('transferSerials');
            $assignments = $transfer->transferSerials->where('status', 'planned');
            if ($assignments->isEmpty()) {
                throw new InvalidStateException('No planned serials found for transfer issue.');
            }

            foreach ($assignments as $assignment) {
                $serial = $assignment->serialItem()->lockForUpdate()->firstOrFail();

                if ($serial->current_location_id !== $transfer->source_location_id) {
                    throw new SerialLocationMismatchException($serial->serial_number);
                }

                if ($serial->status !== 'in_stock') {
                    throw new SerialNotAvailableException($serial->serial_number);
                }

                $serial->update(['status' => 'in_transfer']);

                StockLedger::create([
                    'event_time' => now(),
                    'product_id' => $serial->product_id,
                    'serial_item_id' => $serial->id,
                    'location_id' => $transfer->source_location_id,
                    'movement_type' => 'transfer_issue',
                    'quantity_delta' => -1,
                    'reference_type' => StockTransfer::LEDGER_REFERENCE_TYPE,
                    'reference_id' => $transfer->id,
                    'actor_user_id' => $actorUserId,
                ]);

                $this->inventoryBalanceService->recalculate($serial->product_id, $transfer->source_location_id);

                $assignment->update([
                    'status' => 'issued',
                    'issued_at' => now(),
                ]);
            }

            $issuedByProduct = StockTransferSerial::query()
                ->where('stock_transfer_id', $transfer->id)
                ->where('status', 'issued')
                ->selectRaw('product_id, COUNT(*) as issued_qty')
                ->groupBy('product_id')
                ->get()
                ->keyBy('product_id');

            foreach ($transfer->lines as $line) {
                $line->update([
                    'qty_issued' => (int) ($issuedByProduct->get($line->product_id)->issued_qty ?? 0),
                ]);
            }

            $transfer->update([
                'status' => 'issued',
                'issued_by' => $actorUserId,
                'issued_at' => now(),
            ]);
        });
    }
}
