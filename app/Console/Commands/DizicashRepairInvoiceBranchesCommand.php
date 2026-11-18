<?php

namespace App\Console\Commands;

use App\Services\Dizicashier\DizicashierSyncService;
use Illuminate\Console\Command;

class DizicashRepairInvoiceBranchesCommand extends Command
{
    protected $signature = 'dizicash:repair-invoice-branches
                            {--dry-run : Show branch changes without writing}
                            {--invoice= : Repair a single local sales invoice id}';

    protected $description = 'Fix imported sales invoice branches using Dizicashier CostCenter (outlet), not default item warehouse';

    public function handle(DizicashierSyncService $sync): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $invoiceRaw = $this->option('invoice');
        $invoiceId = is_string($invoiceRaw) && $invoiceRaw !== '' ? (int) $invoiceRaw : null;

        $log = function (string $msg): void {
            $this->line($msg);
        };

        try {
            $sync->repairImportedInvoiceBranches($log, $dryRun, $invoiceId);
        } catch (\Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info($dryRun ? 'Dry run finished.' : 'Branch repair finished.');

        return self::SUCCESS;
    }
}
