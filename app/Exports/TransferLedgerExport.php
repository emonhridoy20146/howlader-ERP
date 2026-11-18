<?php

namespace App\Exports;

use App\Exports\Concerns\PrependsCompanyHeaderRows;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithTitle;

class TransferLedgerExport implements FromCollection, WithEvents, WithHeadings, WithTitle
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
            optional($row->event_time)->format('Y-m-d H:i:s') ?? (string) $row->event_time,
            $row->transfer_number,
            ucfirst(str_replace('_', ' ', (string) $row->transfer_status)),
            $this->movementLabel((string) $row->movement_type),
            $row->source_location_name,
            $row->destination_location_name,
            $row->ledger_location_name,
            $row->product_sku,
            $row->product_name,
            $row->serial_number ?? '',
            (int) $row->quantity_delta >= 0 ? '+'.(int) $row->quantity_delta : (string) (int) $row->quantity_delta,
            $row->actor_name ?? '',
        ]);
    }

    public function headings(): array
    {
        return [
            'Date/time',
            'Transfer no.',
            'Transfer status',
            'Movement',
            'Source branch',
            'Destination branch',
            'Ledger branch',
            'SKU',
            'Product',
            'Serial',
            'Qty change',
            'Actor',
        ];
    }

    public function title(): string
    {
        return 'Transfer ledger';
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

    private function movementLabel(string $movementType): string
    {
        return match ($movementType) {
            'transfer_issue' => 'Issue (out)',
            'transfer_receive' => 'Receive (in)',
            default => Str::of($movementType)->replace('_', ' ')->title()->toString(),
        };
    }
}
