<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithTitle;

class YearlyCollectionLegendSheet implements FromArray, ShouldAutoSize, WithHeadings, WithTitle
{
    public function array(): array
    {
        return [
            ['Paid (green)', 'Inbound payment allocated to the invoice in that month. Down payments are included unless excluded in the filters.'],
            ['Partial (amber)', 'Something was paid in the month but a schedule due that month is still short. The cell holds the amount paid; the comment shows the shortfall.'],
            ['Missed (red)', 'A schedule due in the month (due date up to today) was not settled by month end and nothing was paid on the invoice that month. The cell holds the unpaid due amount.'],
            ['Upcoming (grey)', 'A schedule due later than today inside the range. The cell holds the amount still unpaid.'],
            ['Total payable', 'The invoice grand total, including any down payment. Customer and grand totals add each invoice once.'],
            ['Inst. paid/total', 'Fully paid installments out of all installments in the plan; "(n partial)" counts installments with some payment that are not yet fully settled.'],
            ['Last due', 'Most recent installment due date up to today.'],
            ['No. of installments', 'Total number of installments in the plan.'],
            ['Invoice expires', 'Due date of the last installment of the plan.'],
            ['Total paid', "The invoice's recorded paid amount (all payments to date, including any down payment). The month cells show payments per month, so they may add up to less."],
            ['Total due', 'Unpaid amount today on schedules due inside the range with a due date up to today.'],
            ['Outstanding', 'Remaining unpaid amount on the whole plan, inside or outside the range.'],
            ['Due inst.', 'Number of schedules counted in Total due.'],
            ['Included invoices', 'Non-deleted, non-cancelled installment invoices that still have an unpaid schedule (unless "Include fully paid" is on). Write-offs count as settled.'],
        ];
    }

    public function headings(): array
    {
        return ['Term', 'Definition'];
    }

    public function title(): string
    {
        return 'Legend & Definitions';
    }
}
