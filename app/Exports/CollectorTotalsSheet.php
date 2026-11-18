<?php

namespace App\Exports;

use App\Exports\Concerns\PrependsCompanyHeaderRows;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithTitle;

class CollectorTotalsSheet implements FromArray, ShouldAutoSize, WithEvents, WithHeadings, WithTitle
{
    use PrependsCompanyHeaderRows;

    /**
     * @param  list<array{collector: string, payment_count: int, amount: float}>  $totals
     * @param  array<string, string>  $companyPad
     */
    public function __construct(
        private readonly array $totals,
        private readonly array $companyPad,
        private readonly string $reportTitle,
        private readonly string $subtitle,
    ) {}

    public function array(): array
    {
        $rows = array_map(fn (array $t): array => [$t['collector'], $t['payment_count'], $t['amount']], $this->totals);
        $rows[] = ['Total', array_sum(array_column($this->totals, 'payment_count')), round(array_sum(array_column($this->totals, 'amount')), 2)];

        return $rows;
    }

    public function headings(): array
    {
        return ['Collected by', 'Payments', 'Amount'];
    }

    public function title(): string
    {
        return 'By collector';
    }

    protected function exportHeaderMeta(): array
    {
        return [
            'company_pad_name' => $this->companyPad['company_pad_name'] ?? '',
            'company_pad_tagline' => $this->companyPad['company_pad_tagline'] ?? '',
            'reportTitle' => $this->reportTitle,
            'subtitle' => $this->subtitle,
        ];
    }
}
