<?php

namespace App\Console\Commands;

use App\Models\ManualStockAdjustment;
use App\Models\SerialItem;
use App\Models\StockLedger;
use App\Models\User;
use App\Services\InventoryBalanceService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ReconcilePhantomSerializedStockCommand extends Command
{
    protected $signature = 'inventory:fix-phantom-serials
                            {--dry-run : Preview mismatches without making changes}
                            {--product= : Only check this product ID}
                            {--location= : Only check this location (branch) ID}';

    protected $description = 'Reconcile serialized products so the stock ledger on-hand matches the actual serial count at each location — writes adjust_out for phantom stock and marks excess serials as sold for deficit stock';

    public function handle(InventoryBalanceService $inventoryBalanceService): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $onlyProductId = $this->parseOptionalIntOption('product');
        $onlyLocationId = $this->parseOptionalIntOption('location');

        $actorId = User::query()->where('role', 'admin')->value('id')
            ?? User::query()->whereHas('roles', fn ($q) => $q->where('slug', 'admin'))->value('id');

        if (! $actorId) {
            $this->error('No admin user found to use as the actor. Create one first.');

            return self::FAILURE;
        }

        $allPairs = $this->collectAllSerializedProductLocationPairs($onlyProductId, $onlyLocationId);

        if ($allPairs->isEmpty()) {
            $this->info('No serialized product-location pairs found. Nothing to check.');

            return self::SUCCESS;
        }

        $ledgerByKey = DB::table('stock_ledger as sl')
            ->join('products as p', 'p.id', '=', 'sl.product_id')
            ->where('p.is_serialized', true)
            ->when($onlyProductId !== null, fn ($q) => $q->where('sl.product_id', $onlyProductId))
            ->when($onlyLocationId !== null, fn ($q) => $q->where('sl.location_id', $onlyLocationId))
            ->groupBy('sl.product_id', 'sl.location_id')
            ->select([
                'sl.product_id',
                'sl.location_id',
                DB::raw('SUM(sl.quantity_delta) as ledger_on_hand'),
            ])
            ->get()
            ->keyBy(fn ($r) => (int) $r->product_id.'|'.(int) $r->location_id);

        $serialCountByKey = DB::table('serial_items')
            ->whereNotNull('current_location_id')
            ->whereNotIn('status', ['sold', 'damaged'])
            ->join('products as p', 'p.id', '=', 'serial_items.product_id')
            ->where('p.is_serialized', true)
            ->when($onlyProductId !== null, fn ($q) => $q->where('serial_items.product_id', $onlyProductId))
            ->when($onlyLocationId !== null, fn ($q) => $q->where('serial_items.current_location_id', $onlyLocationId))
            ->groupBy('serial_items.product_id', 'serial_items.current_location_id')
            ->select([
                'serial_items.product_id',
                'serial_items.current_location_id as location_id',
                DB::raw('COUNT(*) as serial_count'),
            ])
            ->get()
            ->keyBy(fn ($r) => (int) $r->product_id.'|'.(int) $r->location_id);

        $productIds = $allPairs->pluck('product_id')->unique();
        $locationIds = $allPairs->pluck('location_id')->unique();
        $productNames = DB::table('products')->whereIn('id', $productIds)->pluck('name', 'id');
        $locationNames = DB::table('locations')->whereIn('id', $locationIds)->pluck('name', 'id');

        $mismatches = [];
        foreach ($allPairs as $pair) {
            $key = (int) $pair->product_id.'|'.(int) $pair->location_id;
            $ledger = isset($ledgerByKey[$key]) ? (int) $ledgerByKey[$key]->ledger_on_hand : 0;
            $serials = isset($serialCountByKey[$key]) ? (int) $serialCountByKey[$key]->serial_count : 0;

            if ($ledger === $serials) {
                continue;
            }

            $mismatches[] = (object) [
                'product_id' => (int) $pair->product_id,
                'location_id' => (int) $pair->location_id,
                'ledger_on_hand' => $ledger,
                'serial_count' => $serials,
                'diff' => $ledger - $serials,
                'product_name' => $productNames[(int) $pair->product_id] ?? '?',
                'location_name' => $locationNames[(int) $pair->location_id] ?? '?',
            ];
        }

        if ($mismatches === []) {
            $this->info('All serialized product ledger balances match their serial counts. No adjustments needed.');

            return self::SUCCESS;
        }

        $overcount = collect($mismatches)->where('diff', '>', 0);
        $undercount = collect($mismatches)->where('diff', '<', 0);

        $this->warn(sprintf(
            'Found %d mismatch(es): %d phantom stock (ledger > serials), %d deficit (ledger < serials, excess serials will be marked sold).',
            count($mismatches),
            $overcount->count(),
            $undercount->count(),
        ));

        $this->newLine();
        $this->table(
            ['Product', 'Location', 'Ledger', 'Serials', 'Action'],
            array_map(fn ($m) => [
                $m->product_name.' (#'.$m->product_id.')',
                $m->location_name.' (#'.$m->location_id.')',
                $m->ledger_on_hand,
                $m->serial_count,
                $m->diff > 0
                    ? 'adjust_out -'.$m->diff
                    : 'mark '.abs($m->diff).' serial(s) sold'.($m->ledger_on_hand < 0 ? ', adjust_in +'.abs($m->ledger_on_hand) : ''),
            ], $mismatches),
        );

        if ($dryRun) {
            $this->newLine();
            $this->comment('Dry run complete — no changes made. Omit --dry-run to apply.');

            return self::SUCCESS;
        }

        $recalcKeys = [];
        $fixCount = 0;

        DB::transaction(function () use ($mismatches, &$recalcKeys, &$fixCount, $actorId) {
            foreach ($mismatches as $m) {
                $key = $m->product_id.'|'.$m->location_id;
                $recalcKeys[$key] = ['product_id' => $m->product_id, 'location_id' => $m->location_id];

                if ($m->diff > 0) {
                    $this->applyOvercount($m, $actorId);
                } else {
                    $this->applyDeficit($m, $actorId);
                }

                $fixCount++;
            }
        });

        foreach ($recalcKeys as $pair) {
            $inventoryBalanceService->recalculate($pair['product_id'], $pair['location_id']);
        }

        $this->newLine();
        $this->info(sprintf(
            'Fixed %d mismatch(es) and recalculated %d balance(s).',
            $fixCount,
            count($recalcKeys),
        ));

        return self::SUCCESS;
    }

    /**
     * Ledger > serials: write adjust_out to bring ledger down.
     */
    private function applyOvercount(object $m, int $actorId): void
    {
        $adjustment = ManualStockAdjustment::create([
            'product_id' => $m->product_id,
            'location_id' => $m->location_id,
            'qty_before' => $m->ledger_on_hand,
            'qty_after' => $m->serial_count,
            'qty_delta' => -$m->diff,
            'reason' => 'Phantom stock: ledger '.$m->ledger_on_hand.' but only '.$m->serial_count.' serial(s) at location — via inventory:fix-phantom-serials',
            'actor_user_id' => $actorId,
        ]);

        StockLedger::create([
            'event_time' => now(),
            'product_id' => $m->product_id,
            'serial_item_id' => null,
            'location_id' => $m->location_id,
            'movement_type' => 'adjust_out',
            'quantity_delta' => -$m->diff,
            'unit_cost' => null,
            'reference_type' => ManualStockAdjustment::class,
            'reference_id' => $adjustment->id,
            'actor_user_id' => $actorId,
        ]);

        if ($this->output->isVerbose()) {
            $this->line(sprintf(
                '  adjust_out %s at %s: -%d (ledger %d → %d)',
                $m->product_name,
                $m->location_name,
                $m->diff,
                $m->ledger_on_hand,
                $m->serial_count,
            ));
        }
    }

    /**
     * Ledger < serials: mark excess serials as sold, then if ledger is
     * negative bring it to zero with adjust_in.
     *
     * Example: ledger = -2, serials = 2
     *   → mark 2 serials sold (serials becomes 0)
     *   → adjust_in +2 (ledger -2 → 0)
     *   → final: ledger=0, serials=0
     *
     * Example: ledger = 1, serials = 3
     *   → mark 2 serials sold (serials becomes 1)
     *   → no ledger adjustment needed (ledger already = 1)
     *   → final: ledger=1, serials=1
     */
    private function applyDeficit(object $m, int $actorId): void
    {
        $excessSerialCount = $m->serial_count - max(0, $m->ledger_on_hand);

        $serialsToMark = SerialItem::query()
            ->where('product_id', $m->product_id)
            ->where('current_location_id', $m->location_id)
            ->whereNotIn('status', ['sold', 'damaged'])
            ->orderByRaw("FIELD(status, 'in_stock', 'reserved', 'draft', 'in_transfer', 'under_service')")
            ->limit($excessSerialCount)
            ->lockForUpdate()
            ->get();

        $markedCount = 0;
        foreach ($serialsToMark as $serial) {
            $serial->update([
                'status' => 'sold',
                'current_location_id' => null,
            ]);
            $markedCount++;

            if ($this->output->isVerbose()) {
                $this->line(sprintf(
                    '  marked sold: serial %s (id=%d) from %s',
                    $serial->serial_number,
                    $serial->id,
                    $m->location_name,
                ));
            }
        }

        $targetLedger = max(0, $m->ledger_on_hand);
        $ledgerFix = $targetLedger - $m->ledger_on_hand;

        if ($ledgerFix > 0) {
            $adjustment = ManualStockAdjustment::create([
                'product_id' => $m->product_id,
                'location_id' => $m->location_id,
                'qty_before' => $m->ledger_on_hand,
                'qty_after' => $targetLedger,
                'qty_delta' => $ledgerFix,
                'reason' => 'Deficit correction: marked '.$markedCount.' serial(s) sold and adjusted ledger '.$m->ledger_on_hand.' → '.$targetLedger.' — via inventory:fix-phantom-serials',
                'actor_user_id' => $actorId,
            ]);

            StockLedger::create([
                'event_time' => now(),
                'product_id' => $m->product_id,
                'serial_item_id' => null,
                'location_id' => $m->location_id,
                'movement_type' => 'adjust_in',
                'quantity_delta' => $ledgerFix,
                'unit_cost' => null,
                'reference_type' => ManualStockAdjustment::class,
                'reference_id' => $adjustment->id,
                'actor_user_id' => $actorId,
            ]);

            if ($this->output->isVerbose()) {
                $this->line(sprintf(
                    '  adjust_in %s at %s: +%d (ledger %d → %d)',
                    $m->product_name,
                    $m->location_name,
                    $ledgerFix,
                    $m->ledger_on_hand,
                    $targetLedger,
                ));
            }
        } elseif ($markedCount > 0 && $this->output->isVerbose()) {
            $this->line(sprintf(
                '  %s at %s: marked %d serial(s) sold, ledger already correct at %d',
                $m->product_name,
                $m->location_name,
                $markedCount,
                $m->ledger_on_hand,
            ));
        }
    }

    private function collectAllSerializedProductLocationPairs(?int $productId, ?int $locationId)
    {
        $fromLedger = DB::table('stock_ledger as sl')
            ->join('products as p', 'p.id', '=', 'sl.product_id')
            ->where('p.is_serialized', true)
            ->when($productId !== null, fn ($q) => $q->where('sl.product_id', $productId))
            ->when($locationId !== null, fn ($q) => $q->where('sl.location_id', $locationId))
            ->select(['sl.product_id', 'sl.location_id'])
            ->distinct()
            ->get();

        $fromSerials = DB::table('serial_items')
            ->join('products as p', 'p.id', '=', 'serial_items.product_id')
            ->where('p.is_serialized', true)
            ->whereNotIn('serial_items.status', ['sold', 'damaged'])
            ->whereNotNull('serial_items.current_location_id')
            ->when($productId !== null, fn ($q) => $q->where('serial_items.product_id', $productId))
            ->when($locationId !== null, fn ($q) => $q->where('serial_items.current_location_id', $locationId))
            ->select(['serial_items.product_id', 'serial_items.current_location_id as location_id'])
            ->distinct()
            ->get();

        return $fromLedger->concat($fromSerials)
            ->unique(fn ($r) => (int) $r->product_id.'|'.(int) $r->location_id)
            ->values();
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
