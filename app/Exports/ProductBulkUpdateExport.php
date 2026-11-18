<?php

namespace App\Exports;

use App\Models\Product;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithTitle;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class ProductBulkUpdateExport implements FromCollection, ShouldAutoSize, WithHeadings, WithStyles, WithTitle
{
    /**
     * @param  Collection<int, Product>  $products
     */
    public function __construct(
        private readonly Collection $products,
    ) {}

    public function collection(): Collection
    {
        return $this->products->map(fn (Product $product): array => [
            $product->id,
            $product->sku,
            $product->name,
            $product->brand?->name ?? '',
            $product->category?->name ?? '',
            round((float) $product->default_purchase_price, 2),
            round((float) $product->default_sale_price, 2),
            $product->is_active ? 'yes' : 'no',
        ]);
    }

    public function headings(): array
    {
        return [
            'product_id',
            'sku',
            'name',
            'brand',
            'category',
            'purchase_price',
            'sale_price',
            'is_active',
        ];
    }

    public function title(): string
    {
        return 'Products';
    }

    public function styles(Worksheet $sheet): array
    {
        return [
            1 => [
                'font' => ['bold' => true],
                'fill' => [
                    'fillType' => Fill::FILL_SOLID,
                    'startColor' => ['rgb' => 'E2E8F0'],
                ],
            ],
        ];
    }
}
