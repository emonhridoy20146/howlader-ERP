<?php

namespace App\Exports;

use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;

class MonthlyCollectionExcelExport implements WithMultipleSheets
{
    public function __construct(
        private readonly Collection $thisMonthInstallmentRows,
        private readonly Collection $thisMonthCreditRows,
        private readonly Collection $overdueInstallmentRows,
        private readonly Collection $overdueCreditRows,
        private readonly array $companyPad,
        private readonly string $subtitle,
        private readonly Collection $periodPaymentsByInvoiceId = new Collection,
        private readonly Collection $maturedOverdueInstallmentRows = new Collection,
        private readonly Collection $collectorsByInvoiceId = new Collection,
        private readonly array $collectorTotals = [],
    ) {}

    public function sheets(): array
    {
        return [
            new InstallmentOverdueExport(
                $this->thisMonthInstallmentRows,
                $this->companyPad,
                'This Month Installments',
                $this->subtitle,
                $this->periodPaymentsByInvoiceId,
                $this->collectorsByInvoiceId,
            ),
            new CreditOverdueExport(
                $this->thisMonthCreditRows,
                $this->companyPad,
                'This Month Credits',
                $this->subtitle,
                $this->periodPaymentsByInvoiceId,
                $this->collectorsByInvoiceId,
            ),
            new InstallmentOverdueExport(
                $this->overdueInstallmentRows,
                $this->companyPad,
                'Overdue Installments',
                $this->subtitle,
                $this->periodPaymentsByInvoiceId,
                $this->collectorsByInvoiceId,
            ),
            new CreditOverdueExport(
                $this->overdueCreditRows,
                $this->companyPad,
                'Overdue Credits',
                $this->subtitle,
                $this->periodPaymentsByInvoiceId,
                $this->collectorsByInvoiceId,
            ),
            new InstallmentMaturedOverdueExport(
                $this->maturedOverdueInstallmentRows,
                $this->companyPad,
                'Overdue (Matured)',
                $this->subtitle,
                $this->periodPaymentsByInvoiceId,
                $this->collectorsByInvoiceId,
            ),
            new CollectorTotalsSheet($this->collectorTotals, $this->companyPad, 'Monthly Collection — by collector', $this->subtitle),
        ];
    }
}
