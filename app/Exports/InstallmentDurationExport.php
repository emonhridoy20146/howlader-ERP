<?php

namespace App\Exports;

use App\Exports\Concerns\PrependsCompanyHeaderRows;
use App\Models\InstallmentPlan;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithTitle;

class InstallmentDurationExport implements FromCollection, WithEvents, WithHeadings, WithTitle
{
    use PrependsCompanyHeaderRows;

    /**
     * @param  Collection<int, InstallmentPlan>  $rows
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
        return $this->rows->map(function (InstallmentPlan $plan): array {
            $invoice = $plan->salesInvoice;
            $customer = $invoice?->customer;
            $branch = $invoice?->branch;

            return [
                $invoice?->invoice_number ?? '',
                $branch?->name ?? '',
                $customer?->name ?? '',
                $customer?->phone ?? '',
                ucfirst($plan->status),
                $plan->schedules->count(),
                $plan->plannedStartDate()?->toDateString() ?? '',
                $plan->plannedEndDate()?->toDateString() ?? '',
                $plan->plannedDurationDays() ?? '',
                $plan->actualCompletionDate()?->toDateString() ?? '',
                $plan->actualDurationDays() ?? '',
                $plan->durationVarianceDays() ?? '',
                $plan->status === 'active' ? ($plan->elapsedDaysSinceStart() ?? '') : '',
                round((float) $plan->financed_amount, 2),
                round((float) $plan->remaining_amount, 2),
            ];
        });
    }

    public function headings(): array
    {
        return [
            'Invoice',
            'Branch',
            'Customer',
            'Phone',
            'Status',
            'Installments',
            'First due date',
            'Planned end date',
            'Planned duration (days)',
            'Actual payoff date',
            'Actual duration (days)',
            'Variance (days)',
            'Days elapsed (active)',
            'Financed amount',
            'Remaining',
        ];
    }

    public function title(): string
    {
        return 'Installment duration';
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
