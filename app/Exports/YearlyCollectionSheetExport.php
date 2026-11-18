<?php

namespace App\Exports;

use App\Services\Reports\YearlyCollectionSheetService;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;

class YearlyCollectionSheetExport implements WithMultipleSheets
{
    /**
     * @param  array<string, mixed>  $data  {@see YearlyCollectionSheetService::forExport()}
     * @param  array<string, string>  $companyPad
     */
    public function __construct(
        private readonly array $data,
        private readonly array $companyPad,
        private readonly string $subtitle,
    ) {}

    public function sheets(): array
    {
        return [
            new YearlyCollectionGridSheet($this->data, $this->companyPad, $this->subtitle),
            new YearlyCollectionBranchSummarySheet($this->data),
            new YearlyCollectionLegendSheet,
        ];
    }
}
