<?php

namespace App\Console\Commands;

use App\Models\Product;
use App\Support\ProductBarcode;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class RegenerateProductBarcodesCommand extends Command
{
    protected $signature = 'products:regenerate-barcodes
                            {--dry-run : Show planned barcode changes without saving}';

    protected $description = 'Reassign all product barcodes to the compact internal format (8-digit, leading 9)';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $pattern = ProductBarcode::pattern();
        $total = Product::query()->count();

        if ($total === 0) {
            $this->info('No products found.');

            return self::SUCCESS;
        }

        $alreadyStandard = Product::query()
            ->whereNotNull('barcode')
            ->where('barcode', '!=', '')
            ->get(['id', 'barcode'])
            ->filter(fn (Product $product): bool => (bool) preg_match($pattern, (string) $product->barcode))
            ->count();

        $this->info(sprintf(
            'Found %d product(s); %d already match %s.',
            $total,
            $alreadyStandard,
            ProductBarcode::prefix().str_repeat('0', ProductBarcode::formatLength() - strlen(ProductBarcode::prefix()))
        ));

        if ($dryRun) {
            $sample = Product::query()
                ->orderBy('id')
                ->limit(5)
                ->get(['id', 'sku', 'barcode']);

            $rows = [];
            $sequence = 1;
            foreach ($sample as $product) {
                $rows[] = [
                    (string) $product->id,
                    (string) $product->sku,
                    (string) ($product->barcode ?? '—'),
                    ProductBarcode::format($sequence),
                ];
                $sequence++;
            }

            $this->table(['ID', 'SKU', 'Current barcode', 'New barcode'], $rows);
            $this->comment('Run without --dry-run to apply to all products.');

            return self::SUCCESS;
        }

        $updated = 0;

        DB::transaction(function () use (&$updated): void {
            $sequence = 1;

            Product::query()
                ->orderBy('id')
                ->chunkById(100, function ($products) use (&$sequence, &$updated): void {
                    foreach ($products as $product) {
                        /** @var Product $product */
                        $product->barcode = ProductBarcode::format($sequence);
                        $product->save();
                        $sequence++;
                        $updated++;
                    }
                });
        });

        $this->info('Updated '.$updated.' product barcode(s).');

        return self::SUCCESS;
    }
}
