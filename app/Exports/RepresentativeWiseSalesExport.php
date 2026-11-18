<?php

namespace App\Exports;

use App\Models\SalesInvoice;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromView;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithColumnFormatting;
use Maatwebsite\Excel\Concerns\WithStyles;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class RepresentativeWiseSalesExport implements FromView, ShouldAutoSize, WithColumnFormatting, WithStyles
{
    /**
     * @param  Collection<int, object>  $rows
     * @param  Collection<string, Collection<int, SalesInvoice>>  $invoiceDetailsByReferrer
     * @param  array<string, string>  $companyPad
     */
    public function __construct(
        private readonly Collection $rows,
        private readonly Collection $invoiceDetailsByReferrer,
        private readonly array $companyPad,
        private readonly string $reportTitle,
        private readonly string $subtitle,
    ) {}

    public function view(): View
    {
        return view('reports.exports.representative-wise-sales-excel', [
            'rows' => $this->rows,
            'invoiceDetailsByReferrer' => $this->invoiceDetailsByReferrer,
            'companyPad' => $this->companyPad,
            'reportTitle' => $this->reportTitle,
            'subtitle' => $this->subtitle,
        ]);
    }

    public function styles(Worksheet $sheet): array
    {
        return [
            1 => ['font' => ['bold' => true, 'size' => 16]],
            2 => ['font' => ['italic' => true, 'size' => 11]],
            4 => ['font' => ['bold' => true]],
        ];
    }

    public function columnFormats(): array
    {
        return [
            'B' => NumberFormat::FORMAT_NUMBER,
            'C' => NumberFormat::FORMAT_NUMBER_00,
            'D' => NumberFormat::FORMAT_NUMBER_00,
            'E' => NumberFormat::FORMAT_NUMBER_00,
        ];
    }
}
