<?php

namespace App\Exports;

use App\Models\InstallmentSchedule;
use App\Models\SalesInvoice;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;

class ReceivablesOverdueExcelExport implements WithMultipleSheets
{
    /**
     * @param  Collection<int, SalesInvoice>  $creditRows
     * @param  Collection<int, InstallmentSchedule>  $installmentRows
     * @param  Collection<int, SalesInvoice>  $maturedRows
     * @param  array<string, string>  $companyPad
     */
    public function __construct(
        private readonly Collection $creditRows,
        private readonly Collection $installmentRows,
        private readonly Collection $maturedRows,
        private readonly array $companyPad,
        private readonly string $subtitle,
    ) {}

    /**
     * @return array<int, CreditOverdueExport|InstallmentOverdueExport|InstallmentMaturedOverdueExport>
     */
    public function sheets(): array
    {
        $sheets = [];

        if ($this->creditRows->isNotEmpty()) {
            $sheets[] = new CreditOverdueExport(
                $this->creditRows,
                $this->companyPad,
                'Credit overdue',
                $this->subtitle
            );
        }

        if ($this->installmentRows->isNotEmpty()) {
            $sheets[] = new InstallmentOverdueExport(
                $this->installmentRows,
                $this->companyPad,
                'Installment overdue',
                $this->subtitle
            );
        }

        if ($this->maturedRows->isNotEmpty()) {
            $sheets[] = new InstallmentMaturedOverdueExport(
                $this->maturedRows,
                $this->companyPad,
                'Matured installment overdue',
                $this->subtitle
            );
        }

        if ($sheets === []) {
            $sheets[] = new CreditOverdueExport(
                collect(),
                $this->companyPad,
                'Credit overdue',
                $this->subtitle
            );
        }

        return $sheets;
    }
}
