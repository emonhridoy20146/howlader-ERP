<?php

namespace App\Console\Commands;

use App\Models\Product;
use App\Services\SerialOnHandBackfillService;
use App\Support\ProductBarcode;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * One-off migration: products previously marked non-serialized are aligned with the app
 * default (serialized), get an internal barcode when missing, and receive {@see SerialItem}
 * rows so on-hand matches serial-tracked units (same rules as {@see SerialOnHandBackfillService}).
 */
class MigrateNonSerializedProductsToSerializedCommand extends Command
{
    protected $signature = 'products:migrate-to-serialized
                            {--dry-run : List planned updates and serial backfill counts without saving}
                            {--recalculate : Recalculate inventory_balances from the ledger before backfilling serials}';

    protected $description = 'Set is_serialized on non-serialized products, assign missing barcodes, and backfill serial rows for on-hand stock';

    public function handle(SerialOnHandBackfillService $backfill): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $recalculate = (bool) $this->option('recalculate');

        $count = Product::query()->where('is_serialized', false)->count();
        if ($count === 0) {
            $this->info('No products with is_serialized = false.');

            return self::SUCCESS;
        }

        $this->info('Found '.$count.' non-serialized product(s).');

        $wouldAssignBarcode = 0;
        $wouldSerialize = $count;
        if ($dryRun && $recalculate) {
            $this->warn('--recalculate is ignored with --dry-run (recalculation updates inventory_balances).');
        }
        $estimatedSerials = $backfill->estimateDeficitNonSerializedProducts(! $dryRun && $recalculate);

        if ($dryRun) {
            $wouldAssignBarcode = (int) Product::query()
                ->where('is_serialized', false)
                ->where(function ($q): void {
                    $q->whereNull('barcode')->orWhere('barcode', '');
                })
                ->count();

            $this->table(
                ['Measure', 'Count'],
                [
                    ['Products to mark serialized', (string) $wouldSerialize],
                    ['Products missing barcode (would assign)', (string) $wouldAssignBarcode],
                    ['Serial rows backfill would create (approx.)', (string) $estimatedSerials],
                ]
            );
            $this->comment('Run without --dry-run to apply.');

            return self::SUCCESS;
        }

        $assignedBarcodes = 0;
        $updated = 0;

        Product::query()
            ->where('is_serialized', false)
            ->orderBy('id')
            ->chunkById(100, function ($products) use (&$assignedBarcodes, &$updated): void {
                foreach ($products as $product) {
                    DB::transaction(function () use ($product, &$assignedBarcodes, &$updated): void {
                        /** @var Product $product */
                        $barcode = trim((string) ($product->barcode ?? ''));
                        if ($barcode === '') {
                            $product->barcode = ProductBarcode::generateUnique();
                            $assignedBarcodes++;
                        }
                        $product->is_serialized = true;
                        $product->save();
                        $updated++;
                    });
                }
            });

        $this->info('Updated '.$updated.' product(s); assigned '.$assignedBarcodes.' new barcode(s).');

        $backfillResult = $backfill->run(false, null, null, $recalculate);
        $this->info('Backfill: created '.$backfillResult['created'].' serial row(s); '
            .'removed '.$backfillResult['removed'].' surplus serial(s); '
            .$backfillResult['skipped_pairs'].' product/location pair(s) unchanged.');

        return self::SUCCESS;
    }
}
