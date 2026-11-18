<?php

namespace App\Console\Commands;

use App\Models\AuditLog;
use App\Models\Payment;
use Illuminate\Console\Command;

class BackfillPaymentCollectorCommand extends Command
{
    protected $signature = 'payments:backfill-collector {--dry-run : Show counts without writing}';

    protected $description = 'Fill payments.recorded_by from the invoice payment audit-log entry where it is missing';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $total = Payment::query()->whereNull('recorded_by')->count();
        $filled = 0;

        Payment::query()->whereNull('recorded_by')->orderBy('id')->chunkById(500, function ($payments) use ($dryRun, &$filled): void {
            foreach ($payments as $payment) {
                $userId = AuditLog::query()
                    ->whereNotNull('actor_user_id')
                    ->where('after_snapshot->payment_id', $payment->id)
                    ->whereIn('action', [
                        'sales_invoice.payment_allocated',
                        'sales_invoice.historical_payment_allocated',
                        'sales_invoice.historical_payment_backfilled',
                    ])
                    ->orderBy('id')
                    ->value('actor_user_id');

                if ($userId === null) {
                    continue;
                }

                $filled++;
                if (! $dryRun) {
                    Payment::query()->whereKey($payment->id)->update(['recorded_by' => $userId]);
                }
            }
        });

        $this->info(sprintf('%s: %d payments without collector, %d %s, %d left unknown.',
            $dryRun ? 'Dry run' : 'Done', $total, $filled, $dryRun ? 'could be filled' : 'filled', $total - $filled));

        return self::SUCCESS;
    }
}
