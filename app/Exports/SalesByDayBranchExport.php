<?php

namespace App\Exports;

use App\Exports\Concerns\PrependsCompanyHeaderRows;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithTitle;

class SalesByDayBranchExport implements FromCollection, WithEvents, WithHeadings, WithTitle
{
    use PrependsCompanyHeaderRows;

    /**
     * @param  Collection<int, object>  $rows
     * @param  array<string, string>  $companyPad
     */
    public function __construct(
        private readonly Collection $rows,
        private readonly array $companyPad,
        private readonly string $reportTitle,
        private readonly string $subtitle,
    ) {}

    public function collection(): Collection
    {
        return $this->rows->map(fn (object $r): array => [
            (string) ($r->invoice_date ?? ''),
            $r->branch_code ?? '',
            $r->branch_name ?? '',
            $r->product_name ?? '',
            (int) ($r->qty_sold ?? 0),
            round((float) ($r->line_total ?? 0), 2),
            (int) ($r->invoice_count ?? 0),
        ]);
    }

    public function headings(): array
    {
        return [
            'Invoice date',
            'Branch code',
            'Branch',
            'Product',
            'Qty',
            'Line total',
            'Invoices',
        ];
    }

    public function title(): string
    {
        return 'Daily sales';
    }

    /**
     * @return array{company_pad_name: string, company_pad_tagline: string, reportTitle: string, subtitle: string}
     */
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
