@extends('reports.exports.print-layout')

@php
    $detailTotals = $detailTotals ?? ['amount' => 0.0];
@endphp

@section('report-table')
	<table class="data">
		<thead>
			<tr>
				<th class="num">#</th>
				<th>Date</th>
				<th>Expense account</th>
				<th>Code</th>
				<th class="num">Amount</th>
				<th class="num">Postings</th>
			</tr>
		</thead>
		<tbody>
			@php $lastBranchId = null; $lineNo = 0; @endphp
			@foreach ($rows as $r)
				@if($lastBranchId !== (int) $r->branch_id)
					@php $lastBranchId = (int) $r->branch_id; @endphp
					<tr>
						<th colspan="6" class="left" style="background:#f1f5f9;font-weight:700;padding:8px;">
							{{ $r->branch_name }}@if (! empty($r->branch_code)) ({{ $r->branch_code }})@endif
						</th>
					</tr>
				@endif
				@php $lineNo++; @endphp
				<tr>
					<td class="num">{{ $lineNo }}</td>
					<td>{{ \Illuminate\Support\Carbon::parse($r->entry_date)->toDateString() }}</td>
					<td>{{ $r->account_name }}</td>
					<td>{{ $r->account_code }}</td>
					<td class="num">{{ number_format((float) $r->amount, 2) }}</td>
					<td class="num">{{ (int) $r->slice_entry_count }}</td>
				</tr>
			@endforeach
		</tbody>
		@if(isset($kpis))
		<tfoot>
			<tr>
				<th colspan="4">Totals (grid)</th>
				<th class="num">{{ number_format((float) ($detailTotals['amount'] ?? 0), 2) }}</th>
				<th class="num">—</th>
			</tr>
			<tr>
				<th colspan="4">Distinct postings · Total expense</th>
				<th class="num" colspan="2">
					{{ (int) ($kpis['distinct_entries'] ?? 0) }} ·
					{{ number_format((float) ($kpis['total_amount'] ?? 0), 2) }}
				</th>
			</tr>
		</tfoot>
		@endif
	</table>
@endsection
