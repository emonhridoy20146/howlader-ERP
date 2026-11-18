<?php

namespace App\Console\Commands;

use App\Services\Dizicashier\DizicashierSyncService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Config;

/**
 * Standalone stock pull from Dizicashier (GET /api/stock/ — Postman: Products → stocks).
 * Same reconcile logic as dizicash:sync --only=stock; use when you want only ledger/balance updates.
 */
class DizicashStockSyncCommand extends Command
{
    protected $signature = 'dizicash:stock-sync
                            {--dry-run : Fetch counts from the API without writing to the database}
                            {--collection= : Override path to Postman collection JSON for token resolution}';

    protected $description = 'Force-update local stock from Dizicashier GET /api/stock/ (ledger + balances). Requires products/locations with dizicashier_uuid; see sync log line "Stock reconcile prep" if adjusted stays 0.';

    public function handle(DizicashierSyncService $sync): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $collection = $this->option('collection');
        $collectionPath = is_string($collection) && $collection !== '' ? $collection : null;

        if ($collectionPath !== null) {
            Config::set('dizicashier.postman_collection_path', $collectionPath);
        }

        $log = function (string $msg): void {
            $this->line($msg);
        };

        try {
            $sync->syncStockFromDizicashierApi($log, $dryRun);
        } catch (\Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info($dryRun ? 'Dry run finished (no database writes).' : 'Dizicashier stock sync finished.');

        return self::SUCCESS;
    }
}
