<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Income &amp; expenditure — {{ $companyPad['company_pad_name'] ?? config('app.name') }}</title>
    @include('reports.exports.partials.financial-print-base', ['financialPrintOrientation' => 'landscape'])
    <style>
        body { font-size: 9px; }
        .financial-print-header { text-align: center; margin-bottom: 10px; }
        .financial-print-header .org { font-size: 14px; font-weight: 700; margin: 0 0 2px; }
        .financial-print-header .addr { font-size: 8px; margin: 0 0 8px; color: #374151; }
        .financial-print-header .title {
            font-size: 12px;
            font-weight: 700;
            margin: 0 0 4px;
            text-decoration: underline;
        }
        .financial-print-header .meta { font-size: 9px; margin: 0; }
        table.financial-data td.label { width: 52%; }
        table.financial-data td.period,
        table.financial-data td.year { width: 24%; }
    </style>
</head>
<body>
<div class="financial-print-toolbar">
    <a class="financial-print-btn" href="{{ route('reports.income-expenditure-statement', request()->query()) }}">Back</a>
    <button class="financial-print-btn" type="button" onclick="window.print()">Print</button>
</div>

@php
    $periodLabel = \Illuminate\Support\Carbon::parse($periodFrom)->format('d-M-Y').' To '.\Illuminate\Support\Carbon::parse($periodTo)->format('d-M-Y');
    $yearLabel = \Illuminate\Support\Carbon::parse($yearFrom)->format('d-M-Y').' To '.\Illuminate\Support\Carbon::parse($periodTo)->format('d-M-Y');
    $fmt = static fn (float $n): string => number_format($n, 2);
@endphp

<div class="financial-print-sheet">
    <div class="financial-print-header">
        <p class="org">{{ $companyPad['company_pad_name'] ?? config('app.name') }}</p>
        @if (! empty($companyPad['company_pad_address']))
            <p class="addr">{{ $companyPad['company_pad_address'] }}</p>
        @endif
        <p class="title">Statement of Comprehensive Income &amp; Expenditure</p>
        <p class="meta"><strong>{{ $branchLabel }}</strong></p>
        <p class="meta">{{ $periodLabel }}</p>
    </div>

    <div class="financial-table-block">
        <table class="financial-data">
            <thead>
                <tr>
                    <th colspan="4">Expenditure</th>
                </tr>
                <tr>
                    <th class="sl">SL</th>
                    <th class="label">Expenditure</th>
                    <th class="num period">For the Period</th>
                    <th class="num year">For the Year</th>
                </tr>
            </thead>
            <tbody>
                @php $sl = 0; @endphp
                @forelse ($expenditureRows as $row)
                    @php $sl++; @endphp
                    <tr class="data-row">
                        <td class="sl">{{ $sl }}</td>
                        <td class="label">{{ $row->account_code }}, {{ $row->account_name }}</td>
                        <td class="num period">{{ $fmt((float) $row->month_amount) }}</td>
                        <td class="num year">{{ $fmt((float) $row->year_amount) }}</td>
                    </tr>
                @empty
                    <tr class="data-row">
                        <td colspan="4" style="text-align:center;color:#6b7280;">No expenditure</td>
                    </tr>
                @endforelse
            </tbody>
            <tfoot>
                <tr class="total-row">
                    <td colspan="2">Total expenditure</td>
                    <td class="num period">{{ $fmt($expenditureMonthTotal) }}</td>
                    <td class="num year">{{ $fmt($expenditureYearTotal) }}</td>
                </tr>
            </tfoot>
        </table>
    </div>

    <div class="financial-table-block">
        <table class="financial-data">
            <thead>
                <tr>
                    <th colspan="4">Income</th>
                </tr>
                <tr>
                    <th class="sl">SL</th>
                    <th class="label">Income</th>
                    <th class="num period">For the Period</th>
                    <th class="num year">For the Year</th>
                </tr>
            </thead>
            <tbody>
                @php $sl = 0; @endphp
                @forelse ($incomeRows as $row)
                    @php $sl++; @endphp
                    <tr class="data-row">
                        <td class="sl">{{ $sl }}</td>
                        <td class="label">{{ $row->account_code }}, {{ $row->account_name }}</td>
                        <td class="num period">{{ $fmt((float) $row->month_amount) }}</td>
                        <td class="num year">{{ $fmt((float) $row->year_amount) }}</td>
                    </tr>
                @empty
                    <tr class="data-row">
                        <td colspan="4" style="text-align:center;color:#6b7280;">No income</td>
                    </tr>
                @endforelse
            </tbody>
            <tfoot>
                <tr class="total-row">
                    <td colspan="2">Grand Total</td>
                    <td class="num period">{{ $fmt($incomeMonthTotal) }}</td>
                    <td class="num year">{{ $fmt($incomeYearTotal) }}</td>
                </tr>
            </tfoot>
        </table>
    </div>

    <div class="financial-signatures">
        <div><div class="financial-sign-line">Prepared By</div></div>
        <div><div class="financial-sign-line">Checked By</div></div>
        <div><div class="financial-sign-line">Approved By</div></div>
    </div>

    <div class="financial-print-footer">
        <span>Print Date: {{ now()->format('d-M-Y') }}</span>
        <span>Year to date: {{ $yearLabel }}</span>
    </div>
</div>
@include('reports.exports.partials.print-page-numbers')
</body>
</html>
