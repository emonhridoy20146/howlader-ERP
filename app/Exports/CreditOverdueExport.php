<?php

namespace App\Exports;

use App\Exports\Concerns\PrependsCompanyHeaderRows;
use App\Models\SalesInvoice;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithTitle;

class CreditOverdueExport implements FromCollection, WithEvents, WithHeadings, WithTitle
{
    use PrependsCompanyHeaderRows;

    /**
     * @param  Collection<int, SalesInvoice>  $rows
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
        return $this->rows->map(function (SalesInvoice $row): array {
            $customer = $row->customer;
            $branch = $row->branch;
            $due = $row->credit_due_date;
            $monthsLate = $row->creditMonthsLate(Carbon::today());
            $periodPayment = (float) ($this->periodPaymentsByInvoiceId[(int) $row->id] ?? 0);

            return [
                $row->invoice_number,
                $row->invoice_date?->toDateString() ?? '',
                $branch?->name ?? '',
                $customer?->name ?? '',
                $customer?->phone ?? '',
                $due?->toDateString() ?? '',
                $monthsLate,
                $row->payment_status,
                round((float) $row->grand_total, 2),
                round((float) $row->amount_paid, 2),
                round((float) $row->amount_due, 2),
                $periodPayment,
                ...$this->collectorCell((int) $row->id),
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
            'Credit due date',
            'Months late',
            'Payment status',
            'Grand total',
            'Paid',
            'Due',
            'This month payment',
            ...($this->collectorsByInvoiceId === null ? [] : ['Collected by']),
        ];
    }

    public function title(): string
    {
        return 'Credit overdue';
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
