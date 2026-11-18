<?php

namespace App\Console\Commands;

use App\Services\SerialOnHandBackfillService;
use Illuminate\Console\Command;

/**
 * For serialized products, on-hand is driven by the stock ledger while each unit is tracked
 * by a serial row. Aligns counts: adds missing in_stock serials where the ledger has more units,
 * and removes surplus in_stock-only serials where the ledger has fewer — without touching reserved
 * units or serials referenced by non-cancelled stock transfers (see {@see SerialOnHandBackfillService}).
 */
class BackfillSerialsForOnHandCommand extends Command
{
    protected $signature = 'inventory:backfill-serials
                            {--dry-run : Show how many serials would be added or removed without saving}
                            {--product= : Limit to a single products.id}
                            {--location= : Limit to a single locations.id}
                            {--recalculate : Recalculate inventory_balances from the ledger before comparing}';

    protected $description = 'Align serial rows with ledger on-hand at each branch (add missing, trim surplus in-stock)';

    public function handle(SerialOnHandBackfillService $service): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $product = $this->option('product');
        $location = $this->option('location');
        $onlyProductId = is_numeric($product) ? (int) $product : null;
        $onlyLocationId = is_numeric($location) ? (int) $location : null;
        $recalculate = (bool) $this->option('recalculate');

        $result = $service->run($dryRun, $onlyProductId, $onlyLocationId, $recalculate);

        if ($dryRun) {
            $this->comment('Dry run — no writes performed.');
            $this->info('Would add '.$result['created'].' serial(s); '
                .'would remove '.$result['removed'].' surplus in_stock serial(s); '
                .$result['skipped_pairs'].' product/branch pair(s) unchanged.');
        } else {
            $this->info('Added '.$result['created'].' serial(s); '
                .'removed '.$result['removed'].' surplus in_stock serial(s); '
                .$result['skipped_pairs'].' product/branch pair(s) unchanged.');
        }

        if ((int) $result['trim_blocked_reserved'] > 0) {
            $this->warn($result['trim_blocked_reserved'].' extra unit(s) remain because they are reserved; release reservations before trimming.');
        }

        return self::SUCCESS;
    }
}
