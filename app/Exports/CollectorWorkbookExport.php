<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\WithMultipleSheets;

/** A report sheet plus a "By collector" subtotal sheet. */
class CollectorWorkbookExport implements WithMultipleSheets
{
    /**
     * @param  list<array{collector: string, payment_count: int, amount: float}>  $collectorTotals
     * @param  array<string, string>  $companyPad
     */
    public function __construct(
        private readonly object $mainSheet,
        private readonly array $collectorTotals,
        private readonly array $companyPad,
        private readonly string $totalsTitle,
        private readonly string $subtitle,
    ) {}

    public function sheets(): array
    {
        return [
            $this->mainSheet,
            new CollectorTotalsSheet($this->collectorTotals, $this->companyPad, $this->totalsTitle, $this->subtitle),
        ];
    }
}
