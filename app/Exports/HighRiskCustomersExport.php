<?php

namespace App\Exports;

use App\Exports\Concerns\PrependsCompanyHeaderRows;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithTitle;

class HighRiskCustomersExport implements FromCollection, WithEvents, WithHeadings, WithTitle
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
        return $this->rows->map(fn ($r): array => [
            $r->customer_code,
            $r->name,
            $r->branch_name ?? '',
            $r->phone ?? '',
            $r->grade,
            $r->score === null ? '' : round((float) $r->score, 1),
            $r->classification.($r->improving ? ' (improving)' : ''),
            $r->status,
            round((float) $r->outstanding_amount, 2),
            round((float) $r->overdue_amount, 2),
            (int) $r->max_days_late,
            round((float) $r->avg_days_late, 1),
            (int) $r->late_6m,
            (int) $r->current_streak,
            $r->guarantor_name ?? ($r->has_guarantor ? 'Per invoice' : ''),
            $r->computed_at?->format('Y-m-d H:i') ?? '',
        ]);
    }

    public function headings(): array
    {
        return ['Code', 'Customer', 'Branch', 'Phone', 'Grade', 'Score', 'Pattern', 'Status', 'Outstanding', 'Overdue', 'Max days late', 'Avg days late', 'Lates (6m)', 'Streak', 'Guarantor', 'Computed at'];
    }

    public function title(): string
    {
        return 'High-risk customers';
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
