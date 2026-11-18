@extends('reports.exports.print-layout')

@php
    $colCount = 2 + ($showMonthly ? 4 : 0) + ($showAnnual ? 4 : 0);
@endphp

@section('report-table')
	<table class="data">
		<thead>
			<tr>
				<th rowspan="2" class="num">#</th>
				<th rowspan="2">Branch</th>
				@if ($showMonthly)
					<th colspan="4" class="center">{{ $monthLabel }}</th>
				@endif
				@if ($showAnnual)
					<th colspan="4" class="center">Annual {{ $filters['year'] }}</th>
				@endif
			</tr>
			<tr>
				@if ($showMonthly)
					<th class="num">Sales %</th>
					<th class="num">Sales actual / target</th>
					<th class="num">Collection %</th>
					<th class="num">Collection actual / target</th>
				@endif
				@if ($showAnnual)
					<th class="num">Sales %</th>
					<th class="num">Sales actual / target</th>
					<th class="num">Collection %</th>
					<th class="num">Collection actual / target</th>
				@endif
			</tr>
		</thead>
		<tbody>
			@php $lineNo = 0; @endphp
			@foreach ($rows as $row)
				@continue(! $row['has_target'])
				@php $lineNo++; $loc = $row['location']; $m = $row['monthly']; $a = $row['annual']; @endphp
				<tr>
					<td class="num">{{ $lineNo }}</td>
					<td class="left">{{ $loc->name }} ({{ $loc->code }})</td>
					@if ($showMonthly)
						<td class="num">{{ ($m['has_target'] ?? false) && $m['sales']['pct'] !== null ? number_format($m['sales']['pct'], 1).'%' : '—' }}</td>
						<td class="num">
							@if ($m['has_target'] ?? false)
								{{ number_format($m['sales']['actual'], 0) }} / {{ number_format($m['sales']['target'], 0) }}
							@else
								—
							@endif
						</td>
						<td class="num">{{ ($m['has_target'] ?? false) && $m['collection']['pct'] !== null ? number_format($m['collection']['pct'], 1).'%' : '—' }}</td>
						<td class="num">
							@if ($m['has_target'] ?? false)
								{{ number_format($m['collection']['actual'], 0) }} / {{ number_format($m['collection']['target'], 0) }}
							@else
								—
							@endif
						</td>
					@endif
					@if ($showAnnual)
						<td class="num">{{ ($a['has_target'] ?? false) && $a['sales']['pct'] !== null ? number_format($a['sales']['pct'], 1).'%' : '—' }}</td>
						<td class="num">
							@if ($a['has_target'] ?? false)
								{{ number_format($a['sales']['actual'], 0) }} / {{ number_format($a['sales']['target'], 0) }}
							@else
								—
							@endif
						</td>
						<td class="num">{{ ($a['has_target'] ?? false) && $a['collection']['pct'] !== null ? number_format($a['collection']['pct'], 1).'%' : '—' }}</td>
						<td class="num">
							@if ($a['has_target'] ?? false)
								{{ number_format($a['collection']['actual'], 0) }} / {{ number_format($a['collection']['target'], 0) }}
							@else
								—
							@endif
						</td>
					@endif
				</tr>
			@endforeach
		</tbody>
		@if(isset($kpis))
		<tfoot>
			@if ($showMonthly)
			<tr>
				<th class="left" colspan="2">Month totals</th>
				<th class="num">{{ $kpis['monthly_sales_pct'] !== null ? number_format($kpis['monthly_sales_pct'], 1).'%' : '—' }}</th>
				<th class="num">{{ number_format($kpis['monthly_sales_actual'], 0) }} / {{ number_format($kpis['monthly_sales_target'], 0) }}</th>
				<th class="num">{{ $kpis['monthly_collection_pct'] !== null ? number_format($kpis['monthly_collection_pct'], 1).'%' : '—' }}</th>
				<th class="num">{{ number_format($kpis['monthly_collection_actual'], 0) }} / {{ number_format($kpis['monthly_collection_target'], 0) }}</th>
				@if ($showAnnual)
					<th class="num" colspan="4"></th>
				@endif
			</tr>
			@endif
			@if ($showAnnual)
			<tr>
				<th class="left" colspan="2">Annual totals</th>
				@if ($showMonthly)
					<th class="num" colspan="4"></th>
				@endif
				<th class="num">{{ $kpis['annual_sales_pct'] !== null ? number_format($kpis['annual_sales_pct'], 1).'%' : '—' }}</th>
				<th class="num">{{ number_format($kpis['annual_sales_actual'], 0) }} / {{ number_format($kpis['annual_sales_target'], 0) }}</th>
				<th class="num">{{ $kpis['annual_collection_pct'] !== null ? number_format($kpis['annual_collection_pct'], 1).'%' : '—' }}</th>
				<th class="num">{{ number_format($kpis['annual_collection_actual'], 0) }} / {{ number_format($kpis['annual_collection_target'], 0) }}</th>
			</tr>
			@endif
		</tfoot>
		@endif
	</table>
@endsection
