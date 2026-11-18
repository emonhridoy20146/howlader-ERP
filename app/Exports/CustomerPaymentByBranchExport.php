<?php

namespace App\Exports;

use App\Exports\Concerns\PrependsCompanyHeaderRows;
use App\Models\Employee;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithTitle;

class CustomerPaymentByBranchExport implements FromCollection, WithEvents, WithHeadings, WithTitle
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
        return $this->rows->map(fn (object $r): array => [
            (string) ($r->payment_date ?? ''),
            $r->branch_code ?? '',
            $r->branch_name ?? '',
            $r->payment_number ?? '',
            $r->customer_code ?? '',
            $r->customer_name ?? '',
            $r->invoice_number ?? '',
            round((float) ($r->allocated_amount ?? 0), 2),
            round((float) ($r->settlement_discount ?? 0), 2),
            round((float) ($r->payment_amount ?? 0), 2),
            $r->payment_method_label ?? '',
            $r->reference_no ?? '',
            ($r->payment_referrer_code ?? null)
                ? Employee::labelFromParts($r->payment_referrer_code, $r->payment_referrer_first_name, $r->payment_referrer_last_name)
                : '',
            $r->recorded_by_name ?: 'Unknown',
        ]);
    }

    public function headings(): array
    {
        return [
            'Payment date',
            'Branch code',
            'Branch',
            'Payment #',
            'Customer code',
            'Customer',
            'Invoice #',
            'Allocated',
            'Discount',
            'Payment amount',
            'Method',
            'Reference',
            'Ref employee',
            'Collected by',
        ];
    }

    public function title(): string
    {
        return 'Customer payments';
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
