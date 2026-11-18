<?php

namespace App\Exports;

use App\Exports\Concerns\PrependsCompanyHeaderRows;
use App\Models\PurchaseOrder;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithTitle;

class OpenPurchaseOrdersExport implements FromCollection, WithEvents, WithHeadings, WithTitle
{
    use PrependsCompanyHeaderRows;

    /**
     * @param  Collection<int, PurchaseOrder>  $rows
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
        return $this->rows->map(function (PurchaseOrder $po): array {
            return [
                $po->po_number,
                $po->ordered_at?->toDateString() ?? '',
                $po->supplier?->name ?? '',
                $po->destinationLocation?->name ?? '',
                $po->status,
                round((float) $po->total_amount, 2),
                round($po->amountPaid(), 2),
                round($po->amountDue(), 2),
            ];
        });
    }

    public function headings(): array
    {
        return [
            'PO #',
            'Ordered',
            'Supplier',
            'Destination',
            'Status',
            'Total',
            'Settled',
            'Due',
        ];
    }

    public function title(): string
    {
        return 'Open POs';
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
