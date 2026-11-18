<?php

namespace App\Exports;

use App\Exports\Concerns\PrependsCompanyHeaderRows;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithTitle;

class CustomerRiskSheet implements FromArray, ShouldAutoSize, WithEvents, WithHeadings, WithTitle
{
    use PrependsCompanyHeaderRows;

    /**
     * @param  list<string>  $headings
     * @param  list<array<int, mixed>>  $rows
     * @param  array<string, string>  $companyPad
     */
    public function __construct(
        private readonly string $sheetTitle,
        private readonly array $headings,
        private readonly array $rows,
        private readonly array $companyPad,
        private readonly string $subtitle,
    ) {}

    public function array(): array
    {
        return $this->rows;
    }

    public function headings(): array
    {
        return $this->headings;
    }

    public function title(): string
    {
        return $this->sheetTitle;
    }

    protected function exportHeaderMeta(): array
    {
        return [
            'company_pad_name' => $this->companyPad['company_pad_name'] ?? '',
            'company_pad_tagline' => $this->companyPad['company_pad_tagline'] ?? '',
            'reportTitle' => 'Customer Profile & Risk — '.$this->sheetTitle,
            'subtitle' => $this->subtitle,
        ];
    }
}
