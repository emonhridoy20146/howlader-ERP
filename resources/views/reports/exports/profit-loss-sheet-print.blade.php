<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Profit Loss Sheet — {{ $companyPad['company_pad_name'] ?? config('app.name') }}</title>
    @include('reports.exports.partials.financial-print-base', ['financialPrintOrientation' => 'portrait'])
    <style>
        body { font-size: 11px; }
        .pl-header-table { width: 100%; border-collapse: collapse; margin-bottom: 16px; }
        .pl-header-table td { vertical-align: top; }
        .pl-logo { max-height: 56px; max-width: 120px; }
        .pl-center { text-align: center; }
        .pl-company-name { font-size: 18px; font-weight: 700; margin: 0 0 4px; }
        .pl-report-title { font-size: 16px; font-weight: 700; margin: 0 0 4px; }
        .pl-date-range { font-size: 12px; margin: 0 0 4px; }
        .pl-branch-line { font-size: 11px; color: #374151; margin: 0; }
        table.financial-data td.label { width: 68%; }
        table.financial-data td.amount { width: 32%; }
        table.financial-data tr.summary-row td {
            background: #fff;
            border-width: 2px;
        }
        .financial-table-block.compact {
            margin-top: 8px;
        }
    </style>
</head>
<body>
<div class="financial-print-toolbar">
    <a class="financial-print-btn" href="{{ route('reports.profit-loss-sheet', request()->query()) }}">Back</a>
    <button class="financial-print-btn" type="button" onclick="window.print()">Print</button>
</div>

@php
    $logoPath = $companyPad['company_pad_logo_path'] ?? '';
    $logoSrc = ($logoPath !== '' && file_exists(public_path('storage/'.$logoPath)))
        ? asset('storage/'.$logoPath)
        : null;
    $fmt = static fn (float $n): string => number_format($n, 2);
    $paren = static fn (float $n): string => '('.$fmt(abs($n)).')';
@endphp

<div class="financial-print-sheet">
    <div class="financial-print-header">
        <table class="pl-header-table">
            <tr>
                <td style="width: 28%;">
                    @if ($logoSrc)
                        <img class="pl-logo" src="{{ $logoSrc }}" alt="">
                    @endif
                </td>
                <td class="pl-center">
                    <p class="pl-company-name">{{ $companyPad['company_pad_name'] ?? config('app.name') }}</p>
                    <p class="pl-report-title">Profit Loss Sheet</p>
                    <p class="pl-date-range">{{ $dateRangeLabel }}</p>
                    <p class="pl-branch-line">Branch: {{ $branchLabel }}</p>
                </td>
                <td style="width: 28%;"></td>
            </tr>
        </table>
    </div>

    <div class="financial-table-block compact">
        <table class="financial-data">
            <tbody>
                <tr class="section-row">
                    <td colspan="3">Incomes</td>
                </tr>
                <tr class="data-row">
                    <td class="sl">1</td>
                    <td class="label">Sales</td>
                    <td class="num amount">{{ $paren($sales) }}</td>
                </tr>
            </tbody>
            <tfoot>
                <tr class="total-row">
                    <td colspan="2" class="label">Total(৳)</td>
                    <td class="num amount">{{ $paren($sales) }}</td>
                </tr>
            </tfoot>
        </table>
    </div>

    <div class="financial-table-block compact">
        <table class="financial-data">
            <tbody>
                <tr class="section-row">
                    <td colspan="3">Cost of Sales</td>
                </tr>
                <tr class="data-row">
                    <td class="sl">1</td>
                    <td class="label">Cost of Goods Sold</td>
                    <td class="num amount">{{ $paren($cogs) }}</td>
                </tr>
            </tbody>
            <tfoot>
                <tr class="total-row">
                    <td colspan="2" class="label">Total(৳)</td>
                    <td class="num amount">{{ $paren($cogs) }}</td>
                </tr>
            </tfoot>
        </table>
    </div>

    <div class="financial-table-block compact">
        <table class="financial-data">
            <tbody>
                <tr class="summary-row">
                    <td colspan="2" class="label">Gross Profit(৳)</td>
                    <td class="num amount">{{ $fmt($grossProfit) }}</td>
                </tr>
            </tbody>
        </table>
    </div>

    <div class="financial-table-block">
        <table class="financial-data">
            <thead>
                <tr class="section-row">
                    <th colspan="3">Expenses</th>
                </tr>
                <tr>
                    <th class="sl">#</th>
                    <th class="label">Account</th>
                    <th class="num amount">Amount</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($expenses as $expense)
                    <tr class="data-row">
                        <td class="sl">{{ $loop->iteration }}</td>
                        <td class="label">{{ $expense->account_name }}</td>
                        <td class="num amount">{{ $fmt((float) $expense->amount) }}</td>
                    </tr>
                @empty
                    <tr class="data-row">
                        <td colspan="3" style="text-align:center;color:#6b7280;">No expenses in this period</td>
                    </tr>
                @endforelse
            </tbody>
            <tfoot>
                <tr class="total-row">
                    <td colspan="2" class="label">Total(৳)</td>
                    <td class="num amount">{{ $fmt($expenseTotal) }}</td>
                </tr>
            </tfoot>
        </table>
    </div>

    <div class="financial-table-block compact">
        <table class="financial-data">
            <tbody>
                <tr class="summary-row">
                    <td colspan="2" class="label">Net Profit(৳)</td>
                    <td class="num amount">{{ $fmt($netProfit) }}</td>
                </tr>
            </tbody>
        </table>
    </div>

    <div class="financial-print-footer">
        <span>Print Date: {{ now()->format('d-m-Y') }}</span>
    </div>
</div>
@include('reports.exports.partials.print-page-numbers')
</body>
</html>
