<?php

namespace App\Exports;

use Illuminate\Support\Carbon;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithTitle;
use Maatwebsite\Excel\Events\AfterSheet;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Shared\Date;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class YearlyCollectionGridSheet implements FromArray, WithEvents, WithTitle
{
    /** SL .. Invoice expires: every column before the first month. */
    private const FIXED = 14;

    private const DATE_COLUMNS = [4, 7, 14];

    private const MONEY_COLUMNS = [5, 8, 9, 10, 11];

    private const HEADER_ROWS = 6;

    private const FILLS = ['paid' => 'FFDCFCE7', 'partial' => 'FFFEF3C7', 'missed' => 'FFFEE2E2', 'upcoming' => 'FFE5E7EB'];

    /** @var list<array{0: int, 1: int, 2: string, 3: float}> row, column, state, shortfall */
    private array $stateCells = [];

    /** @var list<int> */
    private array $customerRows = [];

    /** @var list<int> */
    private array $branchRows = [];

    /** @var list<int> */
    private array $totalRows = [];

    private int $grandRow = 0;

    /** @var list<array<int, mixed>> */
    private array $rows = [];

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, string>  $companyPad
     */
    public function __construct(private readonly array $data, private readonly array $companyPad, private readonly string $subtitle)
    {
        $this->buildRows();
    }

    public function title(): string
    {
        return 'Yearly Collection';
    }

    public function array(): array
    {
        return $this->rows;
    }

    /** ISO date string to an Excel date serial (blank stays blank). */
    private function date(?string $value): float|int|null
    {
        return $value ? Date::PHPToExcel(Carbon::parse($value)) : null;
    }

    private function width(): int
    {
        return self::FIXED + count($this->data['months']);
    }

    private function pad(array $cells): array
    {
        return array_pad($cells, $this->width(), null);
    }

    private function buildRows(): void
    {
        $months = $this->data['months'];
        $meta = $this->companyPad;
        $this->rows[] = $this->pad([$meta['company_pad_name'] ?? '']);
        $this->rows[] = $this->pad([$meta['company_pad_tagline'] ?? '']);
        $this->rows[] = $this->pad(['Yearly Collection Sheet']);
        $this->rows[] = $this->pad([$this->subtitle]);

        $yearRow = array_fill(0, self::FIXED, null);
        foreach ($this->data['years'] as $year => $count) {
            $yearRow[] = (string) $year;
            for ($i = 1; $i < $count; $i++) {
                $yearRow[] = null;
            }
        }
        $this->rows[] = $this->pad($yearRow);

        $head = ['SL', 'Customer', 'Invoice', 'Invoice date', 'Inst. amount', 'Inst. paid/total', 'Last due', 'Total payable', 'Total paid', 'Total due', 'Outstanding', 'Due inst.', 'No. of installments', 'Invoice expires'];
        foreach ($months as $m) {
            $head[] = $m['month'];
        }
        $this->rows[] = $this->pad($head);

        $sl = 0;
        foreach ($this->data['groups'] as $group) {
            $this->rows[] = $this->pad(['Branch: '.$group['name'].' — '.count($group['customers']).' customer(s) · '.$group['invoices'].' invoice(s)']);
            $this->branchRows[] = count($this->rows);
            foreach ($group['customers'] as $customer) {
                $sl++;
                $this->rows[] = $this->pad([$customer['code'].' — '.$customer['name'].($customer['phone'] ? ' · '.$customer['phone'] : '').($customer['branch'] ? ' · '.$customer['branch'] : '')]);
                $this->customerRows[] = count($this->rows);

                foreach ($customer['invoices'] as $i => $inv) {
                    $cells = [
                        $i === 0 ? $sl : null, $i === 0 ? $customer['name'] : null, $inv['number'], $this->date($inv['date']), $inv['installment_amount'],
                        $inv['inst_paid'].'/'.$inv['inst_total'].($inv['inst_partial'] ? ' ('.$inv['inst_partial'].' partial)' : ''), $this->date($inv['last_due']),
                        $inv['payable'], $inv['paid'], $inv['due'], $inv['outstanding'], $inv['due_inst'], $inv['inst_total'], $this->date($inv['expires']),
                    ];
                    $row = count($this->rows) + 1;
                    foreach ($months as $idx => $m) {
                        $c = $inv['months'][$m['key']];
                        $cells[] = $c['state'] ? $c['amount'] : null;
                        if ($c['state']) {
                            $this->stateCells[] = [$row, self::FIXED + $idx + 1, $c['state'], (float) $c['shortfall']];
                        }
                    }
                    $this->rows[] = $this->pad($cells);
                }

                $this->totalBlock('Customer total', $customer, $months, false);
            }
            $this->totalBlock('Branch total — '.$group['name'], $group, $months, false);
        }

        if ($this->data['customers'] !== []) {
            $this->totalBlock('Grand total', $this->data['grand'], $months, true);
        }
    }

    /** Two rows: paid per month (with the totals) and due per month. */
    private function totalBlock(string $label, array $t, array $months, bool $grand): void
    {
        $paid = array_fill(0, self::FIXED, null);
        $paid[0] = $label.' — paid';
        array_splice($paid, 7, 5, [$t['payable'], $t['paid'], $t['due'], $t['outstanding'], $t['due_inst']]);
        $due = array_fill(0, self::FIXED, null);
        $due[0] = $label.' — due';
        foreach ($months as $m) {
            $paid[] = $t['months'][$m['key']]['paid'] ?: null;
            $due[] = $t['months'][$m['key']]['due'] ?: null;
        }
        $this->rows[] = $this->pad($paid);
        $this->totalRows[] = count($this->rows);
        $this->rows[] = $this->pad($due);
        $this->totalRows[] = count($this->rows);
        if ($grand) {
            $this->grandRow = count($this->rows) - 1;
        }
    }

    public function registerEvents(): array
    {
        return [
            AfterSheet::class => function (AfterSheet $event): void {
                $sheet = $event->sheet->getDelegate();
                $width = $this->width();
                $last = Coordinate::stringFromColumnIndex($width);
                $monthStart = self::FIXED + 1;
                $monthEnd = self::FIXED + count($this->data['months']);
                $lastRow = count($this->rows);

                foreach ([1, 2, 3, 4] as $r) {
                    $sheet->mergeCells("A{$r}:{$last}{$r}");
                }
                $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14);
                $sheet->getStyle('A3')->getFont()->setBold(true)->setSize(12);

                // Year header row: merge each year over its 12 month columns.
                $col = $monthStart;
                foreach ($this->data['years'] as $count) {
                    $from = Coordinate::stringFromColumnIndex($col);
                    $to = Coordinate::stringFromColumnIndex($col + $count - 1);
                    $sheet->mergeCells("{$from}5:{$to}5");
                    $col += $count;
                }
                foreach (range(1, self::FIXED) as $c) {
                    $letter = Coordinate::stringFromColumnIndex($c);
                    $sheet->mergeCells("{$letter}5:{$letter}6");
                }
                $header = $sheet->getStyle("A5:{$last}6");
                $header->getFont()->setBold(true);
                $header->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FFE2E8F0');
                $header->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER)->setVertical(Alignment::VERTICAL_CENTER)->setWrapText(true);
                $header->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);

                foreach ($this->branchRows as $i => $r) {
                    if ($i > 0) {
                        $sheet->setBreak('A'.($r - 1), Worksheet::BREAK_ROW); // break after the previous row, so each branch starts a new printed page
                    }
                    $sheet->mergeCells("A{$r}:".Coordinate::stringFromColumnIndex(7)."{$r}");
                    $sheet->getStyle("A{$r}:{$last}{$r}")->getFont()->setBold(true)->getColor()->setARGB('FFFFFFFF');
                    $sheet->getStyle("A{$r}:{$last}{$r}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FF334155');
                }

                // Customer header + total rows.
                foreach ($this->customerRows as $r) {
                    $sheet->mergeCells("A{$r}:".Coordinate::stringFromColumnIndex(7)."{$r}");
                    $sheet->getStyle("A{$r}:{$last}{$r}")->getFont()->setBold(true);
                    $sheet->getStyle("A{$r}:{$last}{$r}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FFF1F5F9');
                }
                foreach ($this->totalRows as $r) {
                    $sheet->getStyle("A{$r}:{$last}{$r}")->getFont()->setBold(true);
                    $sheet->getStyle("A{$r}:{$last}{$r}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FFF8FAFC');
                }
                if ($this->grandRow) {
                    foreach ([$this->grandRow, $this->grandRow + 1] as $r) {
                        $sheet->getStyle("A{$r}:{$last}{$r}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FFE2E8F0');
                    }
                }

                // State fills and comments.
                foreach ($this->stateCells as [$r, $c, $state, $shortfall]) {
                    $ref = Coordinate::stringFromColumnIndex($c).$r;
                    $sheet->getStyle($ref)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB(self::FILLS[$state]);
                    if ($state === 'upcoming') {
                        $sheet->getStyle($ref)->getFont()->setItalic(true)->getColor()->setARGB('FF6B7280');
                    } elseif ($state === 'missed') {
                        $sheet->getStyle($ref)->getFont()->setBold(true)->getColor()->setARGB('FFB91C1C');
                        $sheet->getComment($ref)->getText()->createTextRun('Missed: due and unpaid');
                    } elseif ($state === 'partial') {
                        $sheet->getComment($ref)->getText()->createTextRun('Partial: shortfall '.number_format($shortfall, 0));
                    }
                }

                // Number formats, widths, freeze, filter, page setup.
                $mFrom = Coordinate::stringFromColumnIndex($monthStart);
                foreach (self::MONEY_COLUMNS as $c) {
                    $letter = Coordinate::stringFromColumnIndex($c);
                    $sheet->getStyle("{$letter}7:{$letter}{$lastRow}")->getNumberFormat()->setFormatCode('#,##0');
                }
                foreach (self::DATE_COLUMNS as $c) {
                    $letter = Coordinate::stringFromColumnIndex($c);
                    $sheet->getStyle("{$letter}7:{$letter}{$lastRow}")->getNumberFormat()->setFormatCode('yyyy-mm-dd');
                }
                $sheet->getStyle("{$mFrom}7:{$last}{$lastRow}")->getNumberFormat()->setFormatCode('#,##0');
                for ($c = 1; $c <= self::FIXED; $c++) {
                    $sheet->getColumnDimensionByColumn($c)->setAutoSize(true);
                }
                for ($c = $monthStart; $c <= $monthEnd; $c++) {
                    $sheet->getColumnDimensionByColumn($c)->setWidth(7.5);
                }
                $sheet->freezePane('D7');
                $sheet->setAutoFilter("A6:{$last}6");
                $sheet->getPageSetup()->setOrientation('landscape')->setFitToPage(true)->setFitToWidth(1)->setFitToHeight(0);
                $sheet->getPageSetup()->setRowsToRepeatAtTopByStartAndEnd(5, 6);
            },
        ];
    }
}
