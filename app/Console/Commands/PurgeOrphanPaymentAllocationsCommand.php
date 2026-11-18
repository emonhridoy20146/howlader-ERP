<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Deletes payment_allocations rows whose payment record no longer exists.
 *
 * These rows are excluded from every invoice total (they join to payments), so removing them
 * does not change invoice headers. They are exported to a CSV before deletion so nothing is lost.
 * Preview by default; nothing is written without --apply.
 */
class PurgeOrphanPaymentAllocationsCommand extends Command
{
    protected $signature = 'payment-allocations:purge-orphans
                            {--apply : Export to CSV and delete (default is a preview)}';

    protected $description = 'Export and delete payment allocation rows whose payment record is missing';

    public function handle(): int
    {
        $rows = DB::table('payment_allocations as a')
            ->leftJoin('payments as p', 'p.id', '=', 'a.payment_id')
            ->whereNull('p.id')
            ->orderBy('a.id')
            ->select('a.*')
            ->get();

        if ($rows->isEmpty()) {
            $this->info('No orphan payment allocations found.');

            return self::SUCCESS;
        }

        $this->line(sprintf(
            '%d orphan allocation row(s) across %d invoice(s), total %.2f',
            $rows->count(),
            $rows->pluck('sales_invoice_id')->unique()->count(),
            $rows->sum('allocated_amount')
        ));

        if (! $this->option('apply')) {
            $this->comment('Preview only. Re-run with --apply to export them to CSV and delete.');

            return self::SUCCESS;
        }

        $path = 'orphan-allocations-'.now()->format('Ymd-His').'.csv';
        $columns = array_keys((array) $rows->first());
        $csv = fopen('php://temp', 'w+');
        fputcsv($csv, $columns);
        foreach ($rows as $row) {
            fputcsv($csv, array_values((array) $row));
        }
        rewind($csv);
        Storage::disk('local')->put($path, stream_get_contents($csv));
        fclose($csv);

        $deleted = DB::transaction(
            fn (): int => DB::table('payment_allocations')->whereIn('id', $rows->pluck('id')->all())->delete()
        );

        $this->info("Deleted {$deleted} row(s). Backup: ".Storage::disk('local')->path($path));

        return self::SUCCESS;
    }
}
