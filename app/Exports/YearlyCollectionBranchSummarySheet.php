<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithTitle;
use Maatwebsite\Excel\Events\AfterSheet;
use PhpOffice\PhpSpreadsheet\Style\Fill;

/** One row per branch plus the grand total. */
class YearlyCollectionBranchSummarySheet implements FromArray, ShouldAutoSize, WithEvents, WithHeadings, WithTitle
{
    /** @param array<string, mixed> $data */
    public function __construct(private readonly array $data) {}

    public function array(): array
    {
        $rows = [];
        foreach ($this->data['groups'] as $i => $g) {
            $rows[] = [$i + 1, $g['name'], count(array_unique(array_column($g['customers'], 'id'))), $g['invoices'], $g['payable'], $g['paid'], $g['due'], $g['outstanding'], $g['due_inst'], $g['missed_months']];
        }
        $t = $this->data['grand'];
        $rows[] = [null, 'Grand total', $t['customers'], $t['invoices'], $t['payable'], $t['paid'], $t['due'], $t['outstanding'], $t['due_inst'], $t['missed_months']];

        return $rows;
    }

    public function headings(): array
    {
        return ['#', 'Branch', 'Customers', 'Invoices', 'Total payable', 'Total paid', 'Total due', 'Outstanding', 'Due inst.', 'Unpaid months'];
    }

    public function title(): string
    {
        return 'Branch summary';
    }

    public function registerEvents(): array
    {
        return [
            AfterSheet::class => function (AfterSheet $event): void {
                $sheet = $event->sheet->getDelegate();
                $last = count($this->data['groups']) + 2;
                $sheet->getStyle('A1:J1')->getFont()->setBold(true);
                $sheet->getStyle('A1:J1')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FFE2E8F0');
                $sheet->getStyle("A{$last}:J{$last}")->getFont()->setBold(true);
                $sheet->getStyle("A{$last}:J{$last}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FFE2E8F0');
                $sheet->getStyle("E2:H{$last}")->getNumberFormat()->setFormatCode('#,##0');
                $sheet->freezePane('A2');
            },
        ];
    }
}
