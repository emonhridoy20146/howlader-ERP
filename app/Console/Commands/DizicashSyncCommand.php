<?php

namespace App\Console\Commands;

use App\Services\Dizicashier\DizicashierSyncService;
use Carbon\Carbon;
use Illuminate\Console\Command;

/**
 * Sync master data and transactions from Dizicashier into this inventory database.
 *
 * Configure DIZICASHIER_USERNAME, DIZICASHIER_PASSWORD, DIZICASHIER_IMPORT_USER_ID, and DIZICASHIER_BASE_URL.
 * Stock levels: GET /api/stock/ (see Dizicash.postman_collection.json → Products → stocks), controlled by config('dizicashier.stock_api_query'); use --only=stock to run that step alone.
 * Access tokens are obtained via login and cached under storage/app/private (see dizicashier.token_storage_path).
 */
class DizicashSyncCommand extends Command
{
    protected $signature = 'dizicash:sync
                            {--dry-run : List counts without writing to the database}
                            {--since= : Import only transactions on/after this date (Y-m-d)}
                            {--only= : Comma-separated: locations,products,customers,suppliers,transactions,transfers,stock}
                            {--collection= : Override path to Postman collection JSON for token resolution}';

    protected $description = 'Synchronize Dizicashier API data into the local inventory database';

    public function handle(DizicashierSyncService $sync): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $sinceRaw = $this->option('since');
        $since = is_string($sinceRaw) && $sinceRaw !== '' ? Carbon::parse($sinceRaw)->startOfDay() : null;
        $onlyRaw = (string) ($this->option('only') ?? '');
        $only = $onlyRaw !== ''
            ? array_values(array_filter(array_map('trim', explode(',', $onlyRaw))))
            : [];
        $collection = $this->option('collection');
        $collectionPath = is_string($collection) && $collection !== '' ? $collection : null;

        $log = function (string $msg): void {
            $this->line($msg);
        };

        try {
            $sync->run($log, $dryRun, $since, $only, $collectionPath);
        } catch (\Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info('Dizicashier sync finished.');

        return self::SUCCESS;
    }
}
