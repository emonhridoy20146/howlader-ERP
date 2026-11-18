<?php

namespace App\Exports;

use App\Exports\Concerns\PrependsCompanyHeaderRows;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithTitle;

class StockSummaryExport implements FromCollection, WithEvents, WithHeadings, WithTitle
{
    use PrependsCompanyHeaderRows;

    /**
     * @param  Collection<int, object>  $rows
     * @param  array<string, string>  $companyPad
     * @param  array{branches?: int, categories?: int, products?: int, on_hand?: int, reserved?: int, available?: int, value_at_cost?: float, total_stock_value?: float}  $kpis
     */
    public function __construct(
        private readonly Collection $rows,
        private readonly array $companyPad,
        private readonly string $reportTitle,
        private readonly string $subtitle,
        private readonly array $kpis = [],
    ) {}

    public function collection(): Collection
    {
        $output = $this->rows->map(function (object $row): array {
            $branch = trim((string) ($row->location_code ?? ''));
            if ($branch !== '') {
                $branch .= ' — ';
            }
            $branch .= (string) ($row->location_name ?? '');

            return [
                $branch,
                (string) ($row->category_name ?: 'Uncategorized'),
                (string) ($row->sku ?? ''),
                (string) ($row->product_name ?? ''),
                (string) ($row->brand_name ?: '—'),
                (int) $row->on_hand_qty,
                (int) $row->reserved_qty,
                (int) $row->available_qty,
                round((float) ($row->total_stock_value ?? 0), 2),
                round((float) ($row->value_at_cost ?? 0), 2),
            ];
        });

        if ($output->isEmpty()) {
            return $output;
        }

        $output->push([
            'Total',
            '',
            '',
            '',
            '',
            (int) ($this->kpis['on_hand'] ?? 0),
            (int) ($this->kpis['reserved'] ?? 0),
            (int) ($this->kpis['available'] ?? 0),
            round((float) ($this->kpis['total_stock_value'] ?? 0), 2),
            round((float) ($this->kpis['value_at_cost'] ?? 0), 2),
        ]);

        return $output;
    }

    public function headings(): array
    {
        return [
            'Branch',
            'Category',
            'SKU',
            'Product',
            'Brand',
            'On hand',
            'Reserved',
            'Available',
            'Total stock value (default sale)',
            'Value at cost (default purchase)',
        ];
    }

    public function title(): string
    {
        return 'Stock summary';
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
