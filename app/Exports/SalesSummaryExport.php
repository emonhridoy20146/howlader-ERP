<?php

namespace App\Exports;

use App\Exports\Concerns\PrependsCompanyHeaderRows;
use App\Models\SalesInvoice;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithTitle;

class SalesSummaryExport implements FromCollection, WithEvents, WithHeadings, WithTitle
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
    ) {}

    public function collection(): Collection
    {
        return $this->rows->map(fn (SalesInvoice $invoice): array => [
            $invoice->invoice_number,
            $invoice->invoice_date?->toDateString() ?? '',
            $invoice->branch?->name ?? '',
            $invoice->customer?->name ?? '',
            $invoice->customer?->phone ?? '',
            ucfirst((string) $invoice->sale_type),
            round((float) $invoice->grand_total, 2),
            round((float) $invoice->amount_paid, 2),
            round((float) $invoice->amount_due, 2),
            ucfirst(str_replace('_', ' ', (string) $invoice->payment_status)),
        ]);
    }

    public function headings(): array
    {
        return [
            'Invoice',
            'Date',
            'Branch',
            'Customer',
            'Phone',
            'Sale type',
            'Grand total',
            'Paid',
            'Due',
            'Payment status',
        ];
    }

    public function title(): string
    {
        return 'Sales summary';
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
