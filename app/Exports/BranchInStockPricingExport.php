<?php

namespace App\Exports;

use App\Exports\Concerns\PrependsCompanyHeaderRows;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithTitle;

class BranchInStockPricingExport implements FromCollection, WithEvents, WithHeadings, WithTitle
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
            $row->location_name,
            $row->category_name ?? 'Uncategorized',
            $row->product_sku,
            $row->product_name,
            (int) $row->on_hand_qty,
            (int) $row->available_qty,
            number_format((float) $row->mrp, 2, '.', ''),
            number_format((float) $row->max_discount_percent, 2, '.', ''),
            number_format((float) $row->max_discount_amount, 2, '.', ''),
            number_format((float) $row->amount_after_discount, 2, '.', ''),
        ]);
    }

    public function headings(): array
    {
        return [
            'Branch',
            'Category',
            'SKU',
            'Product',
            'On hand',
            'Available',
            'MRP',
            'Max discount %',
            'Max discount amount',
            'Amount after discount',
        ];
    }

    public function title(): string
    {
        return 'Branch in-stock pricing';
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
