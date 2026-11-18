<?php

namespace App\Console\Commands;

use App\Models\InventoryBalance;
use App\Services\InventoryBalanceService;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class InventoryBalanceCommand extends Command
{
    protected $signature = 'inventory:balance
                            {--product= : Limit to a single product ID}
                            {--location= : Limit to a single location ID}
                            {--breakdown : Show ledger contribution by movement type (receipts, transfers, sales, other)}
                            {--dry-run : Show what would run without updating inventory_balances}';

    protected $description = 'Recalculate per-location quantities from the stock ledger (goods receipts, transfers, sales invoices, returns, adjustments)';

    public function handle(InventoryBalanceService $inventoryBalanceService): int
    {
        $productId = $this->parseOptionalIntOption('product');
        $locationId = $this->parseOptionalIntOption('location');
        $dryRun = (bool) $this->option('dry-run');
        $breakdown = (bool) $this->option('breakdown');

        $pairs = $this->distinctProductLocationPairs($productId, $locationId);

        if ($pairs->isEmpty()) {
            $this->warn('No product/location pairs found for the given filters.');

            return self::SUCCESS;
        }

        $this->info(sprintf('Processing %d product/location pair(s).', $pairs->count()));

        foreach ($pairs as $pair) {
            $pid = (int) $pair->product_id;
            $lid = (int) $pair->location_id;

            if ($breakdown) {
                $this->line('');
                $this->line(sprintf('<fg=cyan>Product %d · Location %d</>', $pid, $lid));
                $this->displayBreakdown($pid, $lid);
            }

            if (! $dryRun) {
                $inventoryBalanceService->recalculate($pid, $lid);
            }
        }

        if ($dryRun) {
            $this->newLine();
            $this->comment('Dry run: inventory_balances were not updated. Run without --dry-run to apply.');
        } else {
            $this->newLine();
            $this->info('inventory_balances recalculated from the stock ledger and serial reservations.');
        }

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

    /**
     * Any location that appears on a ledger row, an existing balance row, or a serial’s current location
     * may need on_hand / reserved / available refreshed.
     *
     * @return Collection<int, object{product_id: int, location_id: int}>
     */
    private function distinctProductLocationPairs(?int $productId, ?int $locationId): Collection
    {
        $map = [];

        $add = function (int $pid, int $lid) use (&$map, $productId, $locationId): void {
            if ($productId !== null && $pid !== $productId) {
                return;
            }
            if ($locationId !== null && $lid !== $locationId) {
                return;
            }
            $map[$pid.'|'.$lid] = (object) ['product_id' => $pid, 'location_id' => $lid];
        };

        $ledgerQuery = DB::table('stock_ledger')->select('product_id', 'location_id')->distinct();
        if ($productId !== null) {
            $ledgerQuery->where('product_id', $productId);
        }
        if ($locationId !== null) {
            $ledgerQuery->where('location_id', $locationId);
        }
        foreach ($ledgerQuery->cursor() as $row) {
            $add((int) $row->product_id, (int) $row->location_id);
        }

        if (DB::getSchemaBuilder()->hasTable('inventory_balances')) {
            $balanceQuery = DB::table('inventory_balances')->select('product_id', 'location_id')->distinct();
            if ($productId !== null) {
                $balanceQuery->where('product_id', $productId);
            }
            if ($locationId !== null) {
                $balanceQuery->where('location_id', $locationId);
            }
            foreach ($balanceQuery->cursor() as $row) {
                $add((int) $row->product_id, (int) $row->location_id);
            }
        }

        if (DB::getSchemaBuilder()->hasTable('serial_items')) {
            $serialQuery = DB::table('serial_items')
                ->select('product_id', 'current_location_id as location_id')
                ->whereNotNull('current_location_id')
                ->distinct();
            if ($productId !== null) {
                $serialQuery->where('product_id', $productId);
            }
            if ($locationId !== null) {
                $serialQuery->where('current_location_id', $locationId);
            }
            foreach ($serialQuery->cursor() as $row) {
                $add((int) $row->product_id, (int) $row->location_id);
            }
        }

        return collect($map)->values()->sortBy(['product_id', 'location_id'])->values();
    }

    private function displayBreakdown(int $productId, int $locationId): void
    {
        $byType = DB::table('stock_ledger')
            ->where('product_id', $productId)
            ->where('location_id', $locationId)
            ->selectRaw('movement_type, SUM(quantity_delta) as qty')
            ->groupBy('movement_type')
            ->pluck('qty', 'movement_type');

        $goodsReceipts = (int) ($byType->get('purchase_receive') ?? 0);
        $transferIssue = (int) ($byType->get('transfer_issue') ?? 0);
        $transferReceive = (int) ($byType->get('transfer_receive') ?? 0);
        $invoices = (int) ($byType->get('sale_issue') ?? 0);

        $labeledKeys = [
            'purchase_receive', 'transfer_issue', 'transfer_receive', 'sale_issue',
        ];
        $otherNet = (int) $byType->except($labeledKeys)->sum();

        $onHandFromLedgerRaw = (int) $byType->sum();
        $onHandFromLedger = max(0, $onHandFromLedgerRaw);

        $row = InventoryBalance::query()
            ->where('product_id', $productId)
            ->where('location_id', $locationId)
            ->first();

        $beforeOnHand = $row ? (int) $row->on_hand_qty : null;
        $beforeAvailable = $row ? (int) $row->available_qty : null;

        $this->table(
            ['Source', 'Quantity effect at this location'],
            [
                ['Goods receipts (posted GRN)', (string) $goodsReceipts],
                ['Transfers — issue (ship out)', (string) $transferIssue],
                ['Transfers — receive (receive in)', (string) $transferReceive],
                ['Sales invoices (posted)', (string) $invoices],
                ['All other ledger types (returns, adjustments, reserve, …)', (string) $otherNet],
                ['<fg=yellow>Net on-hand (stored; ledger sums below zero count as 0)</>', (string) $onHandFromLedger],
            ]
        );

        if ($onHandFromLedgerRaw < 0) {
            $this->line(sprintf(
                '<fg=yellow>Ledger raw sum was %d (negative); balance uses 0 on hand.</>',
                $onHandFromLedgerRaw
            ));
        }

        if ($beforeOnHand !== null) {
            $this->line(sprintf(
                'Stored balance before recalc: on_hand=%d, available=%d',
                $beforeOnHand,
                $beforeAvailable ?? 0
            ));
        } else {
            $this->line('No existing inventory_balances row for this pair (will be created on recalc).');
        }
    }
}
