<?php

namespace App\Exports;

use App\Exports\Concerns\PrependsCompanyHeaderRows;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithTitle;

class WalletTransactionsExport implements FromCollection, WithEvents, WithHeadings, WithTitle
{
    use PrependsCompanyHeaderRows;

    public function __construct(
        private readonly Collection $rows,
        private readonly Collection $openingBalancesByBranch,
        private readonly Collection $summaryByBranch,
        private readonly array $companyPad,
        private readonly string $reportTitle,
        private readonly string $subtitle,
        private readonly Collection $summaryBySource = new Collection,
    ) {}

    public function collection(): Collection
    {
        $blankRow = array_fill(0, 7, '');
        $output = new Collection;

        if ($this->summaryBySource->isNotEmpty()) {
            $output->push(['Summary by source', '', '', '', '', '', '']);
            foreach ($this->summaryBySource as $row) {
                $output->push([
                    $row['label'],
                    (string) $row['line_count'].' lines',
                    '',
                    '',
                    round((float) $row['debit'], 2),
                    round((float) $row['credit'], 2),
                    round((float) $row['net'], 2),
                ]);
            }
            $output->push([
                'Total',
                (string) $this->summaryBySource->sum('line_count').' lines',
                '',
                '',
                round((float) $this->summaryBySource->sum('debit'), 2),
                round((float) $this->summaryBySource->sum('credit'), 2),
                round((float) $this->summaryBySource->sum('net'), 2),
            ]);
            $output->push($blankRow);
        }

        $summariesByKey = $this->summaryByBranch->keyBy(
            fn (array $row): string => $row['branch_id'] !== null ? (string) $row['branch_id'] : ''
        );

        $groupedRows = $this->rows->groupBy(
            fn (JournalLine $line): string => $line->journalEntry?->branch_id !== null
                ? (string) $line->journalEntry->branch_id
                : ''
        );

        if ($summariesByKey->isEmpty() && $groupedRows->isNotEmpty()) {
            $summariesByKey = $groupedRows->map(function (Collection $branchRows, string $branchKey): array {
                $opening = round((float) $this->openingBalancesByBranch->get($branchKey, 0.0), 2);
                $debit = round($branchRows->sum(fn (JournalLine $line): float => (float) $line->debit), 2);
                $credit = round($branchRows->sum(fn (JournalLine $line): float => (float) $line->credit), 2);

                return [
                    'branch_id' => $branchKey !== '' ? (int) $branchKey : null,
                    'branch_name' => $branchRows->first()?->journalEntry?->branch?->name ?? 'Unassigned',
                    'branch_code' => $branchRows->first()?->journalEntry?->branch?->code ?? '',
                    'line_count' => $branchRows->count(),
                    'opening_balance' => $opening,
                    'debit' => $debit,
                    'credit' => $credit,
                    'closing_balance' => round($opening + $debit - $credit, 2),
                ];
            })->sortBy('branch_name');
        }

        foreach ($summariesByKey as $branchKey => $summary) {
            $branchRows = $groupedRows->get((string) $branchKey, collect())
                ->sortBy(fn (JournalLine $line): string => sprintf(
                    '%s-%010d-%010d',
                    $line->journalEntry->entry_date->format('Y-m-d'),
                    $line->journalEntry->id,
                    $line->id,
                ))
                ->values();

            $branchLabel = ($summary['branch_name'] ?? 'Unassigned')
                .(! empty($summary['branch_code']) ? ' ('.$summary['branch_code'].')' : '');

            $output->push([
                'Location: '.$branchLabel,
                '',
                '',
                '',
                '',
                '',
                (string) ($summary['line_count'] ?? $branchRows->count()).' lines',
            ]);

            $runningBalance = round((float) ($summary['opening_balance'] ?? $this->openingBalancesByBranch->get((string) $branchKey, 0.0)), 2);
            $output->push([
                'Opening Balance',
                '',
                '',
                '',
                '',
                '',
                $runningBalance,
            ]);

            foreach ($branchRows as $row) {
                $runningBalance = round($runningBalance + (float) $row->debit - (float) $row->credit, 2);
                $output->push([
                    $row->journalEntry->entry_date->format('Y-m-d') ?? '',
                    $row->journalEntry->document_no ?? '',
                    $row->line_memo ?: $row->journalEntry->description,
                    JournalEntry::sources()[$row->journalEntry->source] ?? $row->journalEntry->source,
                    $row->debit > 0 ? round((float) $row->debit, 2) : '',
                    $row->credit > 0 ? round((float) $row->credit, 2) : '',
                    $runningBalance,
                ]);
            }

            $output->push($blankRow);
        }

        if ($output->isEmpty()) {
            $output->push(['No transactions found for the selected period.', '', '', '', '', '', '']);
        }

        return $output;
    }

    public function headings(): array
    {
        return [
            'Date',
            'Document No',
            'Description',
            'Source',
            'Debit',
            'Credit',
            'Balance',
        ];
    }

    public function title(): string
    {
        return 'Wallet Transactions';
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
