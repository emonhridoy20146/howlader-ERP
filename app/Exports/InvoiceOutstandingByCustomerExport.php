<?php

namespace App\Exports;

use App\Exports\Concerns\PrependsCompanyHeaderRows;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithTitle;

class InvoiceOutstandingByCustomerExport implements FromCollection, WithEvents, WithHeadings, WithTitle
{
    use PrependsCompanyHeaderRows;

    private const W = 10;

    /**
     * @param  Collection<int, object>  $rows
     * @param  Collection<int, object>  $branchTotals
     * @param  array<string, string>  $companyPad
     */
    public function __construct(
        private readonly Collection $rows,
        private readonly Collection $branchTotals,
        private readonly array $companyPad,
        private readonly string $reportTitle,
        private readonly string $subtitle,
    ) {}

    /** @param  array<int, mixed>  $cells */
    private function pad(array $cells): array
    {
        while (count($cells) < self::W) {
            $cells[] = '';
        }

        return array_slice($cells, 0, self::W);
    }

    public function collection(): Collection
    {
        $detail = $this->rows->map(fn (object $r): array => [
            $r->invoice_number ?? '',
            isset($r->invoice_date) ? (string) $r->invoice_date : '',
            $r->branch_code ?? '',
            $r->branch_name ?? '',
            $r->customer_code ?? '',
            $r->customer_name ?? '',
            $r->customer_phone ?? '',
            $r->item_names ?? '',
            round((float) ($r->grand_total ?? 0), 2),
            round((float) ($r->invoice_amount_due ?? 0), 2),
        ]);

        if ($this->branchTotals->isEmpty()) {
            return $detail;
        }

        $out = $detail->values();
        $out->push($this->pad([]));
        $out->push($this->pad(['(Branch roll-up)', 'Branch code', 'Branch', 'Cust×br rows', 'Open invoices', 'Amount due']));

        foreach ($this->branchTotals as $b) {
            $out->push($this->pad([
                '',
                $b->branch_code ?? '',
                $b->branch_name ?? '',
                (int) ($b->customer_branch_rows ?? 0),
                (int) ($b->open_invoice_count ?? 0),
                round((float) ($b->total_due ?? 0), 2),
            ]));
        }

        $out->push($this->pad([
            '',
            '',
            'All (filtered)',
            (int) $this->branchTotals->sum('customer_branch_rows'),
            (int) $this->branchTotals->sum('open_invoice_count'),
            round((float) $this->branchTotals->sum('total_due'), 2),
        ]));

        return $out;
    }

    public function headings(): array
    {
        return [
            'Invoice #',
            'Invoice date',
            'Branch code',
            'Branch',
            'Customer code',
            'Customer',
            'Phone',
            'Items',
            'Grand total',
            'Balance due',
        ];
    }

    public function title(): string
    {
        return 'Invoice outstanding';
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
