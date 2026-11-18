<?php

namespace App\Console\Commands;

use App\Models\ManualStockAdjustment;
use App\Models\SerialItem;
use App\Models\StockLedger;
use App\Services\InventoryBalanceService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class RemoveDamagedStockCommand extends Command
{
    protected $signature = 'inventory:remove-damaged
                            {--dry-run : Preview what would be removed without making changes}
                            {--product= : Only process this product ID}
                            {--location= : Only process this location (branch) ID}';

    protected $description = 'Find all serial items marked as damaged that still appear in stock (have a location or positive ledger balance) and write them out, adjusting inventory balances';

    public function handle(InventoryBalanceService $inventoryBalanceService): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $onlyProductId = $this->parseOptionalIntOption('product');
        $onlyLocationId = $this->parseOptionalIntOption('location');

        $damagedWithLocation = SerialItem::query()
            ->where('status', 'damaged')
            ->whereNotNull('current_location_id')
            ->when($onlyProductId !== null, fn ($q) => $q->where('product_id', $onlyProductId))
            ->when($onlyLocationId !== null, fn ($q) => $q->where('current_location_id', $onlyLocationId))
            ->with('product:id,name,sku')
            ->orderBy('product_id')
            ->orderBy('current_location_id')
            ->get();

        $orphanedLedger = DB::table('stock_ledger as sl')
            ->join('serial_items as si', 'si.id', '=', 'sl.serial_item_id')
            ->where('si.status', 'damaged')
            ->whereNull('si.current_location_id')
            ->when($onlyProductId !== null, fn ($q) => $q->where('sl.product_id', $onlyProductId))
            ->when($onlyLocationId !== null, fn ($q) => $q->where('sl.location_id', $onlyLocationId))
            ->groupBy('sl.product_id', 'sl.location_id', 'sl.serial_item_id')
            ->havingRaw('SUM(sl.quantity_delta) > 0')
            ->select([
                'sl.product_id',
                'sl.location_id',
                'sl.serial_item_id',
                DB::raw('SUM(sl.quantity_delta) as net_qty'),
            ])
            ->get();

        if ($damagedWithLocation->isEmpty() && $orphanedLedger->isEmpty()) {
            $this->info('No damaged stock items require adjustment.');

            return self::SUCCESS;
        }

        $recalcKeys = [];
        $totalFixed = 0;

        $this->info(sprintf(
            'Found %d damaged serial(s) still assigned to a location, %d with positive ledger balance but already cleared.',
            $damagedWithLocation->count(),
            $orphanedLedger->count(),
        ));

        if ($dryRun) {
            $this->newLine();
            $this->comment('=== Damaged serials still at a location ===');
            foreach ($damagedWithLocation as $serial) {
                $this->line(sprintf(
                    '  serial_item_id=%d  serial=%s  product=%s (#%d)  location_id=%d',
                    $serial->id,
                    $serial->serial_number,
                    $serial->product?->name ?? '?',
                    $serial->product_id,
                    $serial->current_location_id,
                ));
            }

            if ($orphanedLedger->isNotEmpty()) {
                $this->newLine();
                $this->comment('=== Damaged serials with positive ledger balance (location already cleared) ===');
                foreach ($orphanedLedger as $row) {
                    $this->line(sprintf(
                        '  serial_item_id=%d  product_id=%d  location_id=%d  net_qty=%d',
                        (int) $row->serial_item_id,
                        (int) $row->product_id,
                        (int) $row->location_id,
                        (int) $row->net_qty,
                    ));
                }
            }

            $this->newLine();
            $this->comment('Dry run complete — no changes made. Omit --dry-run to apply.');

            return self::SUCCESS;
        }

        DB::transaction(function () use ($damagedWithLocation, $orphanedLedger, &$recalcKeys, &$totalFixed) {
            foreach ($damagedWithLocation as $serial) {
                $locationId = (int) $serial->current_location_id;
                $productId = (int) $serial->product_id;

                $before = (int) DB::table('stock_ledger')
                    ->where('product_id', $productId)
                    ->where('location_id', $locationId)
                    ->sum('quantity_delta');

                $adjustment = ManualStockAdjustment::create([
                    'product_id' => $productId,
                    'location_id' => $locationId,
                    'qty_before' => $before,
                    'qty_after' => $before - 1,
                    'qty_delta' => -1,
                    'reason' => 'Auto-removed damaged serial via inventory:remove-damaged command',
                    'actor_user_id' => null,
                ]);

                StockLedger::create([
                    'event_time' => now(),
                    'product_id' => $productId,
                    'serial_item_id' => $serial->id,
                    'location_id' => $locationId,
                    'movement_type' => 'damage_out',
                    'quantity_delta' => -1,
                    'unit_cost' => $serial->purchase_cost,
                    'reference_type' => ManualStockAdjustment::class,
                    'reference_id' => $adjustment->id,
                    'actor_user_id' => null,
                ]);

                $serial->update(['current_location_id' => null]);

                $key = $productId.'|'.$locationId;
                $recalcKeys[$key] = ['product_id' => $productId, 'location_id' => $locationId];
                $totalFixed++;

                if ($this->output->isVerbose()) {
                    $this->line(sprintf(
                        '  Wrote out serial %s (id=%d) from location %d',
                        $serial->serial_number,
                        $serial->id,
                        $locationId,
                    ));
                }
            }

            foreach ($orphanedLedger as $row) {
                $productId = (int) $row->product_id;
                $locationId = (int) $row->location_id;
                $serialItemId = (int) $row->serial_item_id;
                $netQty = (int) $row->net_qty;

                $before = (int) DB::table('stock_ledger')
                    ->where('product_id', $productId)
                    ->where('location_id', $locationId)
                    ->sum('quantity_delta');

                $adjustment = ManualStockAdjustment::create([
                    'product_id' => $productId,
                    'location_id' => $locationId,
                    'qty_before' => $before,
                    'qty_after' => $before - $netQty,
                    'qty_delta' => -$netQty,
                    'reason' => 'Corrective damage_out for serial already marked damaged via inventory:remove-damaged command',
                    'actor_user_id' => null,
                ]);

                StockLedger::create([
                    'event_time' => now(),
                    'product_id' => $productId,
                    'serial_item_id' => $serialItemId,
                    'location_id' => $locationId,
                    'movement_type' => 'damage_out',
                    'quantity_delta' => -$netQty,
                    'unit_cost' => null,
                    'reference_type' => ManualStockAdjustment::class,
                    'reference_id' => $adjustment->id,
                    'actor_user_id' => null,
                ]);

                $key = $productId.'|'.$locationId;
                $recalcKeys[$key] = ['product_id' => $productId, 'location_id' => $locationId];
                $totalFixed++;

                if ($this->output->isVerbose()) {
                    $this->line(sprintf(
                        '  Corrective ledger entry for serial_item_id=%d at location %d (qty=%d)',
                        $serialItemId,
                        $locationId,
                        -$netQty,
                    ));
                }
            }
        });

        foreach ($recalcKeys as $pair) {
            $inventoryBalanceService->recalculate($pair['product_id'], $pair['location_id']);
        }

        $this->newLine();
        $this->info(sprintf(
            'Processed %d damaged item(s) and recalculated %d inventory balance(s).',
            $totalFixed,
            count($recalcKeys),
        ));

        return self::SUCCESS;
    }

    private function parseOptionalIntOption(string $name): ?int
    {
        $raw = $this->option($name);
        if ($raw === null || $raw === '') {
            return null;
        }

        return (int) $raw;
    }
}
