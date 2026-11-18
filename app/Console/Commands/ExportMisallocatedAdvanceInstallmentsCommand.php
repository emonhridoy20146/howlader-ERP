<?php

namespace App\Console\Commands;

use App\Services\InstallmentAdvanceService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\File;

class ExportMisallocatedAdvanceInstallmentsCommand extends Command
{
    protected $signature = 'installments:export-misallocated-advance
                            {--output= : CSV file path (default: storage/app/reports/misallocated-advance-invoices-{date}.csv)}
                            {--all : Include all invoice dates (default: current month only)}';

    protected $description = 'Export native installment invoices where advance was wrongly applied to schedule rows';

    public function handle(InstallmentAdvanceService $service): int
    {
        $query = $service->queryNativePlansWithMisallocatedAdvanceOnSchedules();

        $scopedToThisMonth = ! (bool) $this->option('all');
        if ($scopedToThisMonth) {
            $monthStart = Carbon::now()->startOfMonth()->toDateString();
            $monthEnd = Carbon::now()->endOfMonth()->toDateString();
            $query->whereHas('salesInvoice', function ($q) use ($monthStart, $monthEnd): void {
                $q->whereDate('invoice_date', '>=', $monthStart)
                    ->whereDate('invoice_date', '<=', $monthEnd);
            });
        }

        $plans = $query->get();
        if ($plans->isEmpty()) {
            $scope = $scopedToThisMonth
                ? ' for '.Carbon::now()->format('F Y')
                : '';
            $this->info('No native installment invoices with advance on schedule rows found'.$scope.'.');

            return self::SUCCESS;
        }

        $outputPath = (string) ($this->option('output') ?: storage_path(
            'app/reports/misallocated-advance-invoices-'.Carbon::now()->format('Y-m-d').'.csv',
        ));

        $directory = dirname($outputPath);
        if (! File::isDirectory($directory)) {
            File::makeDirectory($directory, 0755, true);
        }

        $handle = fopen($outputPath, 'w');
        if ($handle === false) {
            $this->error('Could not open CSV for writing: '.$outputPath);

            return self::FAILURE;
        }

        $headers = InstallmentAdvanceService::misallocatedAdvanceExportHeaders();
        fputcsv($handle, $headers);

        foreach ($plans as $plan) {
            $row = $service->misallocatedAdvanceExportRow($plan);
            fputcsv($handle, array_map(
                fn (string $header) => $row[$header] ?? '',
                $headers,
            ));
        }

        fclose($handle);

        $this->info('Exported '.$plans->count().' invoice(s) to '.$outputPath);

        return self::SUCCESS;
    }
}
