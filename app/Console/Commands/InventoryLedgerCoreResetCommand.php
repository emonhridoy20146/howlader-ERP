<?php

namespace App\Console\Commands;

use App\Models\GoodsReceipt;
use App\Models\SalesInvoice;
use App\Models\StockLedger;
use App\Models\StockTransfer;
use App\Services\InventoryBalanceService;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Rebuild-on-hand helper: trims stock_ledger down to postings that trace to real invoices, receipts, and transfers.
 *
 * Drops (a) adjustments, returns, sync noise, stray seeds, … and (b) core-shaped orphan rows pointing at deleted
 * goods receipts / sales invoices / stock transfers — e.g. when a posted GRN referenced stock_ledger rows as
 * reference_type "GoodsReceipt" but rollback queried the Eloquent class string and left duplicates behind.
 */
class InventoryLedgerCoreResetCommand extends Command
{
    protected $signature = 'inventory:reset-ledger-core
                            {--dry-run : List rows that would be removed without deleting}
                            {--force : Skip confirmation prompt on a destructive run}
                            {--product= : Only prune ledger rows for this product ID}
                            {--location= : Only prune ledger rows for this location ID}';

    protected $description = 'Rebuild stock ledger from invoices, receipts, and transfers — remove adjustments and orphaned core rows missing parent docs';

    public function handle(InventoryBalanceService $inventoryBalanceService): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $productId = $this->optionalIntOption('product');
        $locationId = $this->optionalIntOption('location');

        $toPurge = $this->ledgerRowsToPurgeQuery($productId, $locationId);

        $total = (int) (clone $toPurge)->count();
        $byMovement = $this->ledgerBreakdownLabels((clone $toPurge)
            ->selectRaw('movement_type, reference_type, COUNT(*) as c')
            ->groupBy(['movement_type', 'reference_type'])
            ->orderBy('movement_type')
            ->orderBy('reference_type')
            ->get());

        $this->line('<fg=white>Keeps only rows backed by rows in '
            .'<fg=cyan>goods_receipts</>, <fg=cyan>sales_invoices</>, or <fg=cyan>stock_transfers</>'
            .' (movement + reference pairing must match). Everything else — including orphaned GRN / invoice stubs — is removed.</>');

        if ($total === 0) {
            $this->info('Nothing to prune: ledger is already constrained to invoices, receipts, and transfers with matching parent records.');

            return self::SUCCESS;
        }

        $this->newLine();
        $this->line(sprintf('<fg=yellow>%s remove %s ledger row%s</>',
            $dryRun ? 'Would' : 'About to',
            number_format($total),
            $total === 1 ? '' : 's',
        ));

        foreach ($byMovement->take(35) as $row) {
            $this->line(sprintf('  %s × %s = %s', $row['movement'], $row['reference'], number_format((int) $row['qty'])));
        }

        $remaining = max(0, $byMovement->count() - 35);
        if ($remaining > 0) {
            $this->line(sprintf('  …and %s more distinct movement × reference pair(s).', number_format($remaining)));
        }

        if ($dryRun) {
            $this->newLine();
            $this->comment('Dry run: stock_ledger was not changed.');

            return self::SUCCESS;
        }

        if (! (bool) $this->option('force') && ! $this->confirm('Delete these ledger rows permanently and recalculate inventory_balances?', false)) {
            $this->warn('Cancelled.');

            return self::FAILURE;
        }

        $pairsPurgedDistinct = (clone $toPurge)->select(['product_id', 'location_id'])->distinct()->get();

        $deleted = (int) DB::transaction(function () use ($toPurge): int {
            return (int) $toPurge->delete();
        });

        $pairsTouched = $this->mergeRecalculationPairs(
            $pairsPurgedDistinct,
            $productId,
            $locationId,
        );

        $this->newLine();
        $this->info(sprintf('Removed %s row(s); recalculating %s product × location pair(s)…',
            number_format($deleted),
            number_format($pairsTouched->count()),
        ));

        foreach ($pairsTouched as $pair) {
            $inventoryBalanceService->recalculate((int) $pair['product_id'], (int) $pair['location_id']);
        }

        $this->info('inventory_balances were recalculated from the cleaned ledger.');
        $this->line('<fg=gray>Serialized rows are unchanged; run <fg=white>php artisan inventory:backfill-serials --recalculate</> if counts disagree.</>');

        return self::SUCCESS;
    }

    /** Rows that either are not legitimate core postings or lack a surviving parent record. */
    private function ledgerRowsToPurgeQuery(?int $productId, ?int $locationId): Builder
    {
        return StockLedger::query()
            ->when($productId !== null, fn (Builder $q) => $q->where('product_id', $productId))
            ->when($locationId !== null, fn (Builder $q) => $q->where('location_id', $locationId))
            ->whereNot(function (Builder $keep): void {
                $keep->where(function (Builder $p): void {
                    $p->where('movement_type', 'purchase_receive')
                        ->where(function (Builder $r): void {
                            $r->where('reference_type', 'GoodsReceipt')
                                ->orWhere('reference_type', GoodsReceipt::class);
                        })
                        ->whereExists(function ($s): void {
                            $s->select(DB::raw('1'))
                                ->from('goods_receipts')
                                ->whereColumn('goods_receipts.id', 'stock_ledger.reference_id');
                        });
                })
                    ->orWhere(function (Builder $sInv): void {
                        $sInv->where('movement_type', 'sale_issue')
                            ->where(function (Builder $r): void {
                                $r->where('reference_type', 'SalesInvoice')
                                    ->orWhere('reference_type', SalesInvoice::class);
                            })
                            ->whereExists(function ($s): void {
                                $s->select(DB::raw('1'))
                                    ->from('sales_invoices')
                                    ->whereColumn('sales_invoices.id', 'stock_ledger.reference_id');
                            });
                    })
                    ->orWhere(function (Builder $xfer): void {
                        $xfer->whereIn('movement_type', ['transfer_issue', 'transfer_receive'])
                            ->where('reference_type', StockTransfer::LEDGER_REFERENCE_TYPE)
                            ->whereExists(function ($s): void {
                                $s->select(DB::raw('1'))
                                    ->from('stock_transfers')
                                    ->whereColumn('stock_transfers.id', 'stock_ledger.reference_id');
                            });
                    });
            });
    }

    private function optionalIntOption(string $name): ?int
    {
        $raw = $this->option($name);
        if ($raw === null || $raw === '') {
            return null;
        }

        return (int) $raw;
    }

    /**
     * @param  Collection<int, object{movement_type: string, reference_type: string, c: numeric-string|int}>  $aggregates
     * @return Collection<int, array{movement: string, reference: string, qty: int}>
     */
    private function ledgerBreakdownLabels(Collection $aggregates): Collection
    {
        return $aggregates->map(static function ($row): array {
            $movement = (string) $row->movement_type;
            $reference = (string) $row->reference_type;
            $count = (int) $row->c;

            return ['movement' => $movement, 'reference' => $reference, 'qty' => $count];
        });
    }

    /**
     * @param  Collection<int, Model|\stdClass>  $purgedDistinctRows
     * @return Collection<int, array{product_id: int, location_id: int}>
     */
    private function mergeRecalculationPairs(Collection $purgedDistinctRows, ?int $productId, ?int $locationId): Collection
    {
        $map = [];

        foreach ($purgedDistinctRows as $row) {
            $key = (int) $row->product_id.'|'.(int) $row->location_id;
            $map[$key] = ['product_id' => (int) $row->product_id, 'location_id' => (int) $row->location_id];
        }

        foreach (
            StockLedger::query()
                ->when($productId !== null, fn ($q) => $q->where('product_id', $productId))
                ->when($locationId !== null, fn ($q) => $q->where('location_id', $locationId))
                ->select(['product_id', 'location_id'])
                ->distinct()
                ->cursor() as $row
        ) {
            $key = (int) $row->product_id.'|'.(int) $row->location_id;
            $map[$key] = ['product_id' => (int) $row->product_id, 'location_id' => (int) $row->location_id];
        }

        return collect(array_values($map))->sortBy(['product_id', 'location_id'])->values();
    }
}
