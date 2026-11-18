<?php

namespace App\Support;

use App\Models\StockLedger;
use App\Services\InventoryBalanceService;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Stock figures derived from {@see StockLedger} + serial reservations (same rules as
 * {@see InventoryBalanceService}), for read-only reporting when inventory_balances rows
 * are missing or stale.
 */
final class LedgerBackedInventory
{
    /**
     * Subquery: one row per (product_id, location_id) with on_hand_qty, reserved_qty, available_qty, updated_at.
     */
    public static function balancesSubquery(): Builder
    {
        $ledger = DB::table('stock_ledger')
            ->selectRaw('product_id, location_id, SUM(quantity_delta) as on_hand_qty, MAX(updated_at) as updated_at')
            ->groupBy('product_id', 'location_id');

        $reserved = DB::table('serial_items')
            ->selectRaw('product_id, current_location_id as location_id, COUNT(*) as reserved_qty')
            ->where('status', 'reserved')
            ->whereNotNull('current_location_id')
            ->groupBy('product_id', 'current_location_id');

        return DB::query()
            ->fromSub($ledger, 'ledger')
            ->leftJoinSub($reserved, 'resv', function ($join): void {
                $join->on('ledger.product_id', '=', 'resv.product_id')
                    ->on('ledger.location_id', '=', 'resv.location_id');
            })
            ->selectRaw(
                'ledger.product_id, ledger.location_id, ledger.on_hand_qty, '
                .'COALESCE(resv.reserved_qty, 0) as reserved_qty, '
                .'CASE WHEN ledger.on_hand_qty > COALESCE(resv.reserved_qty, 0) '
                .'THEN ledger.on_hand_qty - COALESCE(resv.reserved_qty, 0) ELSE 0 END as available_qty, '
                .'ledger.updated_at as updated_at'
            );
    }

    /**
     * Sellable stock for pricing reports: non-serialized uses ledger (minus damaged serials at branch);
     * serialized uses in-stock / reserved serial counts (ledger can be out of sync with no serials).
     *
     * With {@see $includeUnserialedStock}, a serialized product that has NO serial records at the location
     * (old/imported stock) uses its ledger quantity instead of showing 0. Off by default so pricing and
     * sales reports keep treating that quantity as phantom stock.
     */
    public static function sellableBalancesSubquery(bool $includeUnserialedStock = false): Builder
    {
        $damagedAtBranch = DB::table('serial_items')
            ->selectRaw('product_id, current_location_id as location_id, COUNT(*) as damaged_qty')
            ->where('status', 'damaged')
            ->whereNotNull('current_location_id')
            ->groupBy('product_id', 'current_location_id');

        $ledgerSellable = DB::query()
            ->fromSub(self::balancesSubquery(), 'balances')
            ->leftJoinSub($damagedAtBranch, 'dmg', function ($join): void {
                $join->on('balances.product_id', '=', 'dmg.product_id')
                    ->on('balances.location_id', '=', 'dmg.location_id');
            })
            ->selectRaw(
                'balances.product_id, balances.location_id, '
                .'CASE WHEN balances.on_hand_qty > COALESCE(dmg.damaged_qty, 0) '
                .'THEN balances.on_hand_qty - COALESCE(dmg.damaged_qty, 0) ELSE 0 END as on_hand_qty, '
                .'balances.reserved_qty as reserved_qty, '
                .'CASE WHEN '
                .'(CASE WHEN balances.on_hand_qty > balances.reserved_qty '
                .'THEN balances.on_hand_qty - balances.reserved_qty ELSE 0 END) '
                .'> COALESCE(dmg.damaged_qty, 0) '
                .'THEN (CASE WHEN balances.on_hand_qty > balances.reserved_qty '
                .'THEN balances.on_hand_qty - balances.reserved_qty ELSE 0 END) '
                .'- COALESCE(dmg.damaged_qty, 0) ELSE 0 END as available_qty, '
                .'balances.updated_at as updated_at'
            );

        $serialAtBranch = DB::table('serial_items')
            ->join('products', 'products.id', '=', 'serial_items.product_id')
            ->where('products.is_serialized', true)
            ->whereNotNull('serial_items.current_location_id')
            ->groupBy('serial_items.product_id', 'serial_items.current_location_id')
            ->selectRaw(
                'serial_items.product_id, serial_items.current_location_id as location_id, '
                ."COUNT(*) as total_qty, "
                ."SUM(CASE WHEN serial_items.status = 'in_stock' THEN 1 ELSE 0 END) as in_stock_qty, "
                ."SUM(CASE WHEN serial_items.status = 'reserved' THEN 1 ELSE 0 END) as reserved_qty"
            );

        $serialIsAuthoritative = $includeUnserialedStock
            ? 'products.is_serialized AND COALESCE(serial.total_qty, 0) > 0'
            : 'products.is_serialized';

        return DB::query()
            ->fromSub($ledgerSellable, 'balances')
            ->join('products', 'products.id', '=', 'balances.product_id')
            ->leftJoinSub($serialAtBranch, 'serial', function ($join): void {
                $join->on('balances.product_id', '=', 'serial.product_id')
                    ->on('balances.location_id', '=', 'serial.location_id');
            })
            ->selectRaw(
                'balances.product_id, balances.location_id, '
                .'CASE WHEN '.$serialIsAuthoritative.' THEN COALESCE(serial.in_stock_qty, 0) '
                .'ELSE balances.on_hand_qty END as on_hand_qty, '
                .'CASE WHEN '.$serialIsAuthoritative.' THEN COALESCE(serial.reserved_qty, 0) '
                .'ELSE balances.reserved_qty END as reserved_qty, '
                .'CASE WHEN '.$serialIsAuthoritative.' THEN COALESCE(serial.in_stock_qty, 0) '
                .'ELSE balances.available_qty END as available_qty, '
                .'balances.updated_at as updated_at'
            );
    }

    /**
     * Ledger on-hand as of the given cutoff; reserved is not reconstructed for historical snapshots.
     */
    public static function ledgerBalancesAsOfSubquery(string $cutoffDatetime): Builder
    {
        $ledger = DB::table('stock_ledger')
            ->where('event_time', '<=', $cutoffDatetime)
            ->selectRaw('product_id, location_id, SUM(quantity_delta) as on_hand_qty, MAX(updated_at) as updated_at')
            ->groupBy('product_id', 'location_id');

        return DB::query()
            ->fromSub($ledger, 'ledger')
            ->selectRaw(
                'ledger.product_id, ledger.location_id, ledger.on_hand_qty, '
                .'0 as reserved_qty, '
                .'CASE WHEN ledger.on_hand_qty > 0 THEN ledger.on_hand_qty ELSE 0 END as available_qty, '
                .'ledger.updated_at as updated_at'
            );
    }

    /**
     * Current sellable balances, or ledger snapshot when {@see $cutoffDatetime} is set.
     */
    public static function balancesForReportSubquery(?string $cutoffDatetime = null, bool $includeUnserialedStock = false): Builder
    {
        if ($cutoffDatetime === null || trim($cutoffDatetime) === '') {
            return self::sellableBalancesSubquery($includeUnserialedStock);
        }

        return self::ledgerBalancesAsOfSubquery($cutoffDatetime);
    }
}
