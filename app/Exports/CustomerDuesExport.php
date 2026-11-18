<?php

namespace App\Exports;

use App\Exports\Concerns\PrependsCompanyHeaderRows;
use App\Models\Customer;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithTitle;

class CustomerDuesExport implements FromCollection, WithEvents, WithHeadings, WithTitle
{
    use PrependsCompanyHeaderRows;

    /**
     * @param  Collection<int, Customer>  $rows
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
        return $this->rows->map(fn (Customer $c): array => [
            $c->customer_code,
            $c->name,
            $c->phone ?? '',
            $c->email ?? '',
            round((float) $c->credit_limit, 2),
            round((float) $c->outstanding_balance, 2),
        ]);
    }

    public function headings(): array
    {
        return [
            'Code',
            'Customer',
            'Phone',
            'Email',
            'Credit limit',
            'Outstanding',
        ];
    }

    public function title(): string
    {
        return 'Customer dues';
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
