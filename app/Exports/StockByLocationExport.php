<?php

namespace App\Exports;

use App\Exports\Concerns\PrependsCompanyHeaderRows;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithTitle;

class StockByLocationExport implements FromCollection, WithEvents, WithHeadings, WithTitle
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
        return $this->rows->map(fn (object $row): array => [
            $row->product_sku,
            $row->product_name,
            $row->location_name,
            (int) $row->on_hand_qty,
            (int) $row->reserved_qty,
            (int) $row->available_qty,
            (int) $row->is_serialized === 1 ? 'Serialized' : 'Non-serialized',
            (int) ($row->reorder_level ?? 0),
            $row->updated_at ? (string) $row->updated_at : '',
        ]);
    }

    public function headings(): array
    {
        return [
            'SKU',
            'Product',
            'Location',
            'On hand',
            'Reserved',
            'Available',
            'Type',
            'Reorder level',
            'Updated',
        ];
    }

    public function title(): string
    {
        return 'Stock by location';
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
