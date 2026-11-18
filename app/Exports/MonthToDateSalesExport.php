<?php

namespace App\Exports;

use App\Exports\Concerns\PrependsCompanyHeaderRows;
use App\Models\SalesInvoice;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithTitle;

class MonthToDateSalesExport implements FromCollection, WithEvents, WithHeadings, WithTitle
{
    use PrependsCompanyHeaderRows;

    /**
     * @param  Collection<int, SalesInvoice>  $rows
     * @param  array<string, string>  $companyPad
     * @param  Collection<int, array>  $summaryByBranch
     * @param  Collection<int, array>  $summaryByType
     */
    public function __construct(
        private readonly Collection $rows,
        private readonly array $companyPad,
        private readonly string $reportTitle,
        private readonly string $subtitle,
        private readonly Collection $summaryByBranch = new Collection,
        private readonly Collection $summaryByType = new Collection,
    ) {}

    public function collection(): Collection
    {
        $blankRow = array_fill(0, 13, '');
        $output = new Collection;

        if ($this->summaryByType->isNotEmpty()) {
            $output->push(['Collection summary by type', '', '', '', '', '', '', '', '', '', '', '', '']);
            foreach ($this->summaryByType as $row) {
                $output->push([
                    $row['label'],
                    (string) $row['invoices'].' invoices',
                    '',
                    '',
                    '',
                    $row['label'],
                    round((float) $row['grand_total'], 2),
                    round((float) ($row['amount_paid'] ?? 0), 2),
                    round((float) ($row['settlement_discount'] ?? 0), 2),
                    round((float) ($row['period_cash'] ?? $row['period_paid'] ?? 0), 2),
                    round((float) ($row['period_discount'] ?? 0), 2),
                    round((float) $row['amount_due'], 2),
                    '',
                ]);
            }
            $output->push([
                'Total',
                (string) $this->summaryByType->sum('invoices').' invoices',
                '',
                '',
                '',
                'All types',
                round((float) $this->summaryByType->sum('grand_total'), 2),
                round((float) $this->summaryByType->sum('amount_paid'), 2),
                round((float) $this->summaryByType->sum('settlement_discount'), 2),
                round((float) $this->summaryByType->sum('period_cash'), 2),
                round((float) $this->summaryByType->sum('period_discount'), 2),
                round((float) $this->summaryByType->sum('amount_due'), 2),
                '',
            ]);
            $output->push($blankRow);
        }

        $groupedRows = $this->rows->groupBy(fn (SalesInvoice $invoice): string => (string) ($invoice->branch_id ?? 0));

        foreach ($this->summaryByBranch as $branchSummary) {
            $branchId = (string) ($branchSummary['branch_id'] ?? 0);
            $branchRows = $groupedRows->get($branchId, collect());
            if ($branchRows->isEmpty()) {
                continue;
            }

            $output->push([
                'Branch: '.$branchSummary['branch_name'],
                '',
                '',
                '',
                '',
                '',
                round((float) $branchSummary['grand_total'], 2),
                round((float) $branchSummary['amount_paid'], 2),
                round((float) ($branchSummary['settlement_discount'] ?? 0), 2),
                round((float) ($branchSummary['period_cash'] ?? $branchSummary['period_paid'] ?? 0), 2),
                round((float) ($branchSummary['period_discount'] ?? 0), 2),
                round((float) $branchSummary['amount_due'], 2),
                (string) $branchSummary['invoices'].' invoices',
            ]);

            foreach ($branchRows as $invoice) {
                $output->push($this->mapInvoiceRow($invoice));
            }

            $output->push($blankRow);
        }

        if ($output->isEmpty()) {
            return $this->rows->map(fn (SalesInvoice $invoice): array => $this->mapInvoiceRow($invoice));
        }

        return $output;
    }

    /**
     * @return array<int, float|string>
     */
    private function mapInvoiceRow(SalesInvoice $invoice): array
    {
        $cashPaid = (float) ($invoice->cash_at_transaction ?? $invoice->cash_paid_total ?? max(0, (float) $invoice->amount_paid - (float) ($invoice->settlement_discount_total ?? 0)));
        $discount = (float) ($invoice->discount_at_transaction ?? $invoice->settlement_discount_total ?? 0);
        $periodCash = (float) ($invoice->period_cash ?? $invoice->period_paid ?? 0);
        $periodDiscount = (float) ($invoice->period_discount ?? 0);

        return [
            $invoice->invoice_number,
            $invoice->period_payment_dates ?: '',
            $invoice->branch?->name ?? '',
            $invoice->customer?->name ?? '',
            $invoice->customer?->phone ?? '',
            ucfirst((string) $invoice->sale_type),
            round((float) $invoice->grand_total, 2),
            round($cashPaid, 2),
            round($discount, 2),
            round($periodCash, 2),
            round($periodDiscount, 2),
            round((float) ($invoice->due_at_transaction ?? $invoice->amount_due), 2),
            ucfirst(str_replace('_', ' ', (string) $invoice->payment_status)),
        ];
    }

    public function headings(): array
    {
        return [
            'Invoice',
            'Payment date',
            'Branch',
            'Customer',
            'Phone',
            'Sale type',
            'Grand total',
            'Paid (cash)',
            'Settlement discount',
            'Cash in period',
            'Discount in period',
            'Due after payment',
            'Payment status',
        ];
    }

    public function title(): string
    {
        return 'Month to date sales';
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
