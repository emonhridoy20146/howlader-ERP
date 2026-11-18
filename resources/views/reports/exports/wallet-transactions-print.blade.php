@extends('reports.exports.print-layout')

@section('report-table')
@if (($summaryBySource ?? collect())->isNotEmpty())
<table class="data" style="margin-bottom: 1.5rem;">
    <caption style="caption-side: top; text-align: left; font-weight: bold; padding-bottom: 0.5rem;">Summary by source</caption>
    <thead>
        <tr>
            <th class="num">#</th>
            <th>Source</th>
            <th class="num">Lines</th>
            <th class="num">Debit</th>
            <th class="num">Credit</th>
            <th class="num">Net</th>
        </tr>
    </thead>
    <tbody>
        @foreach ($summaryBySource as $row)
            <tr>
                <td class="num">{{ $loop->iteration }}</td>
                <td>{{ $row['label'] }}</td>
                <td class="num">{{ number_format((int) $row['line_count']) }}</td>
                <td class="num">{{ number_format((float) $row['debit'], 2) }}</td>
                <td class="num">{{ number_format((float) $row['credit'], 2) }}</td>
                <td class="num">{{ number_format((float) $row['net'], 2) }}</td>
            </tr>
        @endforeach
    </tbody>
    <tfoot>
        <tr style="font-weight: bold;">
            <td></td>
            <td>Total</td>
            <td class="num">{{ number_format((int) $summaryBySource->sum('line_count')) }}</td>
            <td class="num">{{ number_format((float) $summaryBySource->sum('debit'), 2) }}</td>
            <td class="num">{{ number_format((float) $summaryBySource->sum('credit'), 2) }}</td>
            <td class="num">{{ number_format((float) $summaryBySource->sum('net'), 2) }}</td>
        </tr>
    </tfoot>
</table>
@endif

@include('reports.partials.wallet-transactions-by-location', [
    'rows' => $rows ?? collect(),
    'summaryByBranch' => $summaryByBranch ?? collect(),
    'openingBalancesByBranch' => $openingBalancesByBranch ?? collect(),
    'compact' => true,
])
@endsection
