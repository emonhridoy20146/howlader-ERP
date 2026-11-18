<?php

namespace App\Services;

use App\Exceptions\InvalidStateException;
use App\Exceptions\SerialNotAvailableException;
use App\Models\StockLedger;
use App\Models\StockTransfer;
use App\Models\StockTransferSerial;
use Illuminate\Support\Facades\DB;

class TransferReceiveService
{
    public function __construct(private readonly InventoryBalanceService $inventoryBalanceService) {}

    public function receive(StockTransfer $transfer, int $actorUserId): void
    {
        DB::transaction(function () use ($transfer, $actorUserId): void {
            if ($transfer->status !== 'issued') {
                throw new InvalidStateException('Transfer must be issued before receiving.');
            }

            $transfer->load('transferSerials');
            $assignments = $transfer->transferSerials->where('status', 'issued');
            if ($assignments->isEmpty()) {
                throw new InvalidStateException('No issued serials found for transfer receive.');
            }

            foreach ($assignments as $assignment) {
                $serial = $assignment->serialItem()->lockForUpdate()->firstOrFail();

                if ($serial->status !== 'in_transfer') {
                    throw new SerialNotAvailableException($serial->serial_number);
                }

                $destLocationId = $transfer->destination_location_id;

                $onHandBefore = (int) StockLedger::where('product_id', $serial->product_id)
                    ->where('location_id', $destLocationId)
                    ->sum('quantity_delta');

                StockLedger::create([
                    'event_time' => now(),
                    'product_id' => $serial->product_id,
                    'serial_item_id' => $serial->id,
                    'location_id' => $destLocationId,
                    'movement_type' => 'transfer_receive',
                    'quantity_delta' => 1,
                    'reference_type' => StockTransfer::LEDGER_REFERENCE_TYPE,
                    'reference_id' => $transfer->id,
                    'actor_user_id' => $actorUserId,
                ]);

                if ($onHandBefore < 0) {
                    $serial->update([
                        'status' => 'sold',
                        'current_location_id' => null,
                    ]);
                } else {
                    $serial->update([
                        'status' => 'in_stock',
                        'current_location_id' => $destLocationId,
                    ]);
                }

                $this->inventoryBalanceService->recalculate($serial->product_id, $destLocationId);

                $assignment->update([
                    'status' => 'received',
                    'received_at' => now(),
                ]);
            }

            $receivedByProduct = StockTransferSerial::query()
                ->where('stock_transfer_id', $transfer->id)
                ->where('status', 'received')
                ->selectRaw('product_id, COUNT(*) as received_qty')
                ->groupBy('product_id')
                ->get()
                ->keyBy('product_id');

            foreach ($transfer->lines as $line) {
                $line->update([
                    'qty_received' => (int) ($receivedByProduct->get($line->product_id)->received_qty ?? 0),
                ]);
            }

            $transfer->update([
                'status' => 'received',
                'received_by' => $actorUserId,
                'received_at' => now(),
            ]);
        });
    }
}
