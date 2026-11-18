<?php

namespace App\Exports;

use App\Exports\Concerns\PrependsCompanyHeaderRows;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithTitle;

class CustomerJournalExport implements FromCollection, WithEvents, WithHeadings, WithTitle
{
    use PrependsCompanyHeaderRows;

    public function __construct(
        private readonly Collection $rows,
        private readonly array $companyPad,
        private readonly string $reportTitle,
        private readonly string $subtitle,
    ) {}

    public function collection(): Collection
    {
        return $this->rows->map(fn ($row): array => [
            $row->event_time?->format('Y-m-d H:i') ?? '',
            ucfirst(str_replace('_', ' ', $row->event_type)),
            $row->reference_type.' #'.$row->reference_id,
            $row->debit_amount > 0 ? round($row->debit_amount, 2) : '',
            $row->credit_amount > 0 ? round($row->credit_amount, 2) : '',
            round($row->running_balance, 2),
        ]);
    }

    public function headings(): array
    {
        return [
            'Date',
            'Event',
            'Reference',
            'Debit',
            'Credit',
            'Balance',
        ];
    }

    public function title(): string
    {
        return 'Customer Journal';
    }

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
