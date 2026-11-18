<?php

namespace App\Console\Commands;

use App\Models\SalesInvoice;
use Illuminate\Console\Command;

class SyncReturnedInvoiceStatusCommand extends Command
{
    protected $signature = 'invoices:sync-returned-status
                            {--dry-run : List invoices that would be updated without saving}
                            {--invoice= : Limit to sales_invoices.id or invoice_number}';

    protected $description = 'Mark posted/active invoices as returned when all items were already returned and refunded';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $invoiceOpt = $this->option('invoice');

        $query = SalesInvoice::query()
            ->whereIn('status', ['posted', 'active'])
            ->havingAllItemsReturned()
            ->with(['lines.saleLineSerials', 'returnOrders.returnLines'])
            ->orderBy('id');

        if ($invoiceOpt !== null && (string) $invoiceOpt !== '') {
            $needle = (string) $invoiceOpt;
            if (is_numeric($needle)) {
                $query->where(function ($q) use ($needle): void {
                    $q->whereKey((int) $needle)
                        ->orWhere('invoice_number', $needle);
                });
            } else {
                $query->where('invoice_number', $needle);
            }

            if (! (clone $query)->exists()) {
                $this->error('No matching posted/active invoice with all items returned found for: '.$needle);

                return self::FAILURE;
            }
        }

        $invoices = $query->get();

        if ($invoices->isEmpty()) {
            $this->info('No posted or active invoices need a returned status update.');

            return self::SUCCESS;
        }

        $updated = 0;
        $rows = [];

        foreach ($invoices as $invoice) {
            if (! $invoice->isFullyReturned()) {
                continue;
            }

            $rows[] = [
                $invoice->id,
                $invoice->invoice_number,
                $invoice->invoice_date?->toDateString() ?? '',
                $invoice->status,
                'returned',
            ];

            if (! $dryRun) {
                $invoice->update(['status' => 'returned']);
            }

            $updated++;
        }

        if ($rows === []) {
            $this->info('No posted or active invoices need a returned status update.');

            return self::SUCCESS;
        }

        $this->table(
            ['ID', 'Invoice', 'Date', 'Current status', 'New status'],
            $rows,
        );

        $verb = $dryRun ? 'Would update' : 'Updated';
        $this->info("{$verb} {$updated} invoice(s).");

        if ($dryRun) {
            $this->comment('Run without --dry-run to apply these changes.');
        }

        return self::SUCCESS;
    }
}
