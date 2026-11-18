<?php

namespace App\Console\Commands;

use App\Support\LedgerBackedInventory;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Read-only: lists products that have stock for a location (stock ledger or inventory_balances)
 * but are NOT shown on the Stock by location report, with the reason. Never modifies data.
 */
class DiagnoseStockReportGapsCommand extends Command
{
    protected $signature = 'stock:diagnose-report-gaps
                            {location : locations.id (e.g. 4)}';

    protected $description = 'List products with stock at a location that the Stock by location report hides, and why (read-only)';

    public function handle(): int
    {
        $locationId = (int) $this->argument('location');
        $location = DB::table('locations')->where('id', $locationId)->first(['id', 'name', 'code', 'is_active']);
        if (! $location) {
            $this->error('Location not found.');

            return self::FAILURE;
        }

        $ledger = DB::table('stock_ledger')
            ->where('location_id', $locationId)
            ->groupBy('product_id')
            ->selectRaw('product_id, SUM(quantity_delta) as ledger_qty')
            ->pluck('ledger_qty', 'product_id');

        $balance = DB::table('inventory_balances')
            ->where('location_id', $locationId)
            ->pluck('on_hand_qty', 'product_id');

        $reportAvailable = DB::query()
            ->fromSub(LedgerBackedInventory::sellableBalancesSubquery(), 's')
            ->where('s.location_id', $locationId)
            ->pluck('available_qty', 'product_id');

        $serials = DB::table('serial_items')
            ->where('current_location_id', $locationId)
            ->groupBy('product_id', 'status')
            ->selectRaw('product_id, status, COUNT(*) as n')
            ->get()
            ->groupBy('product_id');

        $productIds = $ledger->keys()->merge($balance->keys())->unique()->values();
        $products = DB::table('products')->whereIn('id', $productIds)->get(['id', 'sku', 'name', 'is_serialized', 'is_active'])->keyBy('id');

        $rows = [];
        foreach ($productIds as $productId) {
            $product = $products->get($productId);
            if (! $product) {
                continue;
            }

            $ledgerQty = (float) ($ledger[$productId] ?? 0);
            $balanceQty = (float) ($balance[$productId] ?? 0);
            if ($ledgerQty <= 0 && $balanceQty <= 0) {
                continue;
            }

            $shown = (float) ($reportAvailable[$productId] ?? 0) > 0 && (bool) $product->is_active && (bool) $location->is_active;
            if ($shown) {
                continue;
            }

            $serialByStatus = ($serials[$productId] ?? collect())->pluck('n', 'status');
            $tracked = (int) ($serialByStatus['in_stock'] ?? 0);

            $reason = match (true) {
                ! $product->is_active => 'product is inactive',
                ! $location->is_active => 'location is inactive',
                (bool) $product->is_serialized && $tracked === 0 && $serialByStatus->isEmpty() => 'marked serialized but has NO serial records; report counts serials only',
                (bool) $product->is_serialized && $tracked === 0 => 'marked serialized; serials here are all sold/damaged/other',
                ! $product->is_serialized && $ledgerQty <= 0 && $balanceQty > 0 => 'quantity only in inventory_balances, no stock ledger entries',
                default => 'ledger quantity is reserved/damaged, none available',
            };

            $rows[] = [
                $product->sku,
                mb_strimwidth((string) $product->name, 0, 32, '…'),
                $product->is_serialized ? 'yes' : 'no',
                $product->is_active ? 'yes' : 'no',
                $ledgerQty,
                $balanceQty,
                $serialByStatus->isEmpty() ? '—' : $serialByStatus->map(fn ($n, $s) => "$s:$n")->implode(' '),
                $reason,
            ];
        }

        $this->line("Location {$location->id}: {$location->name} ({$location->code})");

        if ($rows === []) {
            $this->info('No hidden stock: every product with quantity here appears on the report.');

            return self::SUCCESS;
        }

        $this->table(['SKU', 'Product', 'Serialized', 'Active', 'Ledger qty', 'Balance qty', 'Serials here', 'Why hidden'], $rows);
        $this->warn(count($rows).' product(s) have stock here but are hidden from the report. Nothing was changed.');

        return self::SUCCESS;
    }
}
