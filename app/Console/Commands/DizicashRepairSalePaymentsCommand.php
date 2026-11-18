<?php

namespace App\Console\Commands;

use App\Models\SalesInvoice;
use App\Services\Dizicashier\DizicashierSyncService;
use Illuminate\Console\Command;

class DizicashRepairSalePaymentsCommand extends Command
{
    protected $signature = 'dizicash:repair-sale-payments
                            {--dry-run : Show payment changes without writing}
                            {--invoice= : Local sales invoice id or invoice_number (e.g. S-25021)}';

    protected $description = 'Re-sync payment totals and installment schedules on Dizicashier-imported sales from the live API';

    public function handle(DizicashierSyncService $sync): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $invoiceOpt = $this->option('invoice');
        $invoiceId = null;
        $invoiceNumber = null;

        if (is_string($invoiceOpt) && $invoiceOpt !== '') {
            if (is_numeric($invoiceOpt)) {
                $invoiceId = (int) $invoiceOpt;
            } else {
                $invoiceNumber = $invoiceOpt;
            }

            $exists = SalesInvoice::query()
                ->when($invoiceId !== null, fn ($q) => $q->whereKey($invoiceId))
                ->when($invoiceNumber !== null, fn ($q) => $q->where('invoice_number', $invoiceNumber))
                ->whereNotNull('dizicashier_uuid')
                ->exists();

            if (! $exists) {
                $this->error('No Dizicashier-linked invoice found for: '.$invoiceOpt);

                return self::FAILURE;
            }
        }

        $log = function (string $msg): void {
            $this->line($msg);
        };

        try {
            $sync->repairImportedSalePayments($log, $dryRun, $invoiceId, $invoiceNumber);
        } catch (\Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info($dryRun ? 'Dry run finished.' : 'Payment repair finished.');

        return self::SUCCESS;
    }
}
