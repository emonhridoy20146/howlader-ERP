<?php

namespace App\Exports\Concerns;

use Maatwebsite\Excel\Events\AfterSheet;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Fill;

trait PrependsCompanyHeaderRows
{
    /**
     * @return array<int, string>
     */
    abstract public function headings(): array;

    /**
     * @return array{company_pad_name: string, company_pad_tagline: string, reportTitle: string, subtitle: string}
     */
    abstract protected function exportHeaderMeta(): array;

    public function registerEvents(): array
    {
        return [
            AfterSheet::class => function (AfterSheet $event): void {
                $sheet = $event->sheet->getDelegate();
                $headingCount = max(1, count($this->headings()));
                $lastCol = Coordinate::stringFromColumnIndex($headingCount);

                $sheet->insertNewRowBefore(1, 4);

                $meta = $this->exportHeaderMeta();

                $sheet->setCellValue('A1', $meta['company_pad_name']);
                $sheet->setCellValue('A2', $meta['company_pad_tagline']);
                $sheet->setCellValue('A3', $meta['reportTitle']);
                $sheet->setCellValue('A4', $meta['subtitle']);

                foreach (['A1', 'A2', 'A3', 'A4'] as $cell) {
                    $sheet->getStyle($cell)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_LEFT);
                    $sheet->getStyle($cell)->getAlignment()->setVertical(Alignment::VERTICAL_CENTER);
                }

                $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14);
                $sheet->getStyle('A3')->getFont()->setBold(true)->setSize(12);

                $sheet->mergeCells("A1:{$lastCol}1");
                $sheet->mergeCells("A2:{$lastCol}2");
                $sheet->mergeCells("A3:{$lastCol}3");
                $sheet->mergeCells("A4:{$lastCol}4");

                $headingRow = 5;
                $sheet->getStyle("A{$headingRow}:{$lastCol}{$headingRow}")->getFont()->setBold(true);
                $sheet->getStyle("A{$headingRow}:{$lastCol}{$headingRow}")->getFill()
                    ->setFillType(Fill::FILL_SOLID)
                    ->getStartColor()->setARGB('FFE2E8F0');
            },
        ];
    }
}
