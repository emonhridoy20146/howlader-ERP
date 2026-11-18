<?php

namespace App\Exports;

use App\Exports\Concerns\PrependsCompanyHeaderRows;
use App\Models\InstallmentSchedule;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithTitle;

class InstallmentOverdueExport implements FromCollection, WithEvents, WithHeadings, WithTitle
{
    use PrependsCompanyHeaderRows;

    /**
     * @param  Collection<int, InstallmentSchedule|object>  $rows
     * @param  array<string, string>  $companyPad
     */
    public function __construct(
        private readonly Collection $rows,
        private readonly array $companyPad,
        private readonly string $reportTitle,
        private readonly string $subtitle,
        private readonly Collection $periodPaymentsByInvoiceId = new Collection,
        private readonly ?Collection $collectorsByInvoiceId = null,
    ) {}

    public function collection(): Collection
    {
        return $this->rows->map(function ($row): array {
            if (is_object($row) && property_exists($row, 'invoice')) {
                $invoice = $row->invoice;
                $customer = $invoice?->customer;
                $branch = $invoice?->branch;
                $periodPayment = (float) ($this->periodPaymentsByInvoiceId[(int) ($invoice?->id ?? 0)] ?? 0);

                return [
                    $invoice?->invoice_number ?? '',
                    $invoice?->invoice_date?->toDateString() ?? '',
                    $branch?->name ?? '',
                    $customer?->name ?? '',
                    $customer?->phone ?? '',
                    (string) ($row->installment_nos ?? ''),
                    $row->due_date?->toDateString() ?? '',
                    (int) ($row->months_late ?? 0),
                    round((float) ($row->due_amount ?? 0), 2),
                    0.0,
                    round((float) ($row->paid_amount ?? 0), 2),
                    round((float) ($row->remaining ?? 0), 2),
                    $periodPayment,
                    ...$this->collectorCell((int) ($invoice?->id ?? 0)),
                ];
            }

            /** @var InstallmentSchedule $row */
            $invoice = $row->installmentPlan?->salesInvoice;
            $customer = $invoice?->customer;
            $branch = $invoice?->branch;
            $periodPayment = (float) ($this->periodPaymentsByInvoiceId[(int) ($invoice?->id ?? 0)] ?? 0);

            return [
                $invoice?->invoice_number ?? '',
                $invoice?->invoice_date?->toDateString() ?? '',
                $branch?->name ?? '',
                $customer?->name ?? '',
                $customer?->phone ?? '',
                $row->installment_no,
                $row->due_date?->toDateString() ?? '',
                $row->monthsLate(Carbon::today()),
                round((float) $row->due_amount, 2),
                round((float) $row->late_fee_amount, 2),
                round((float) $row->paid_amount, 2),
                round($row->remainingDue(), 2),
                $periodPayment,
                ...$this->collectorCell((int) ($invoice?->id ?? 0)),
            ];
        });
    }

    /**
     * @return list<string>
     */
    private function collectorCell(int $invoiceId): array
    {
        return $this->collectorsByInvoiceId === null ? [] : [(string) ($this->collectorsByInvoiceId[$invoiceId] ?? '—')];
    }

    public function headings(): array
    {
        return [
            'Invoice',
            'Invoice date',
            'Branch',
            'Customer',
            'Phone',
            '#',
            'Due date',
            'Months late',
            'Due',
            'Late fee',
            'Paid',
            'Remaining',
            'This month payment',
            ...($this->collectorsByInvoiceId === null ? [] : ['Collected by']),
        ];
    }

    public function title(): string
    {
        return 'Installment overdue';
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
