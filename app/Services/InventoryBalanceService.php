<?php

namespace App\Services;

use App\Models\SerialItem;
use App\Models\StockLedger;
use Illuminate\Support\Facades\DB;

class InventoryBalanceService
{
    public function recalculate(int $productId, int $locationId): void
    {
        $onHand = (int) StockLedger::where('product_id', $productId)
            ->where('location_id', $locationId)
            ->sum('quantity_delta');

        $reserved = (int) SerialItem::where('product_id', $productId)
            ->where('current_location_id', $locationId)
            ->where('status', 'reserved')
            ->count();

        // Match {@see LedgerBackedInventory::balancesSubquery()}: sellable units when over-reserved cap at 0.
        $available = $onHand > $reserved ? $onHand - $reserved : 0;

        DB::table('inventory_balances')->updateOrInsert(
            ['product_id' => $productId, 'location_id' => $locationId],
            [
                'on_hand_qty' => $onHand,
                'reserved_qty' => $reserved,
                'available_qty' => $available,
                'updated_at' => now(),
            ]
        );
    }
}
