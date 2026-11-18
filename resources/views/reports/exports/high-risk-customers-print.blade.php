@extends('reports.exports.print-layout')

@section('report-table')
	<p style="margin-bottom: 8px; font-size: 9px;">
		<strong>{{ number_format($kpis['customers']) }}</strong> customers ·
		Total overdue <strong>{{ number_format($kpis['total_overdue'], 2) }}</strong> ·
		Total outstanding <strong>{{ number_format($kpis['total_outstanding'], 2) }}</strong> ·
		Avg days late <strong>{{ $kpis['avg_days_late'] }}</strong>
		@foreach (['A', 'B', 'C', 'D', 'E', 'N/A'] as $g)
			@if (($kpis['by_grade'][$g] ?? 0) > 0) · {{ $g }}: {{ $kpis['by_grade'][$g] }} @endif
		@endforeach
	</p>
	<table class="data">
		<thead>
			<tr>
				<th class="num">#</th><th>Code</th><th>Customer</th><th>Branch</th><th>Phone</th><th>Grade</th><th class="num">Score</th>
				<th>Pattern</th><th>Status</th><th class="num">Outstanding</th><th class="num">Overdue</th><th class="num">Max/avg late</th>
				<th class="num">Lates 6m</th><th class="num">Streak</th><th>Guarantor</th>
			</tr>
		</thead>
		<tbody>
			@forelse ($rows as $r)
				<tr>
					<td class="num">{{ $loop->iteration }}</td>
					<td>{{ $r->customer_code }}</td>
					<td>{{ $r->name }}</td>
					<td>{{ $r->branch_name }}</td>
					<td>{{ $r->phone }}</td>
					<td>{{ $r->grade }}</td>
					<td class="num">{{ $r->score === null ? '—' : number_format((float) $r->score, 1) }}</td>
					<td>{{ ucfirst($r->classification) }}</td>
					<td>{{ ucfirst($r->status) }}</td>
					<td class="num">{{ number_format((float) $r->outstanding_amount, 2) }}</td>
					<td class="num">{{ number_format((float) $r->overdue_amount, 2) }}</td>
					<td class="num">{{ $r->max_days_late }} / {{ $r->avg_days_late }}</td>
					<td class="num">{{ $r->late_6m }}</td>
					<td class="num">{{ $r->current_streak }}</td>
					<td>{{ $r->guarantor_name ?? ($r->has_guarantor ? 'Per invoice' : '') }}</td>
				</tr>
			@empty
				<tr><td colspan="15">No customers match the current filters.</td></tr>
			@endforelse
		</tbody>
	</table>
@endsection
