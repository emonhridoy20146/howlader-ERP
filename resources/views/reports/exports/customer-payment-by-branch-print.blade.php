@extends('reports.exports.print-layout')

@section('report-table')
	<table class="data">
		<thead>
			<tr>
				<th class="num">#</th>
				<th>Date</th>
				<th>Payment #</th>
				<th>Customer</th>
				<th>Invoice #</th>
				<th class="num">Allocated</th>
				<th class="num">Discount</th>
				<th>Method</th>
				<th>Reference</th>
				<th>Ref employee</th>
				<th>Collected by</th>
			</tr>
		</thead>
		<tbody>
			@php $lastBranchId = null; $lineNo = 0; @endphp
			@foreach ($rows as $r)
				@if($lastBranchId !== (int) $r->branch_id)
					@php $lastBranchId = (int) $r->branch_id; @endphp
					<tr>
						<th colspan="11" class="left" style="background:#f1f5f9;font-weight:700;padding:8px;">
							{{ $r->branch_name }}@if (! empty($r->branch_code)) ({{ $r->branch_code }})@endif
						</th>
					</tr>
				@endif
				@php $lineNo++; @endphp
				<tr>
					<td class="num">{{ $lineNo }}</td>
					<td>{{ $r->payment_date }}</td>
					<td>{{ $r->payment_number }}</td>
					<td>{{ $r->customer_code }} · {{ $r->customer_name }}</td>
					<td>{{ $r->invoice_number }}</td>
					<td class="num">{{ number_format((float) $r->allocated_amount, 2) }}</td>
					<td class="num">{{ (float) $r->settlement_discount > 0 ? number_format((float) $r->settlement_discount, 2) : '—' }}</td>
					<td>{{ $r->payment_method_label ?: '—' }}</td>
					<td>{{ $r->reference_no ?: '—' }}</td>
					<td>
						@if ($r->payment_referrer_code ?? null)
							{{ \App\Models\Employee::labelFromParts($r->payment_referrer_code, $r->payment_referrer_first_name, $r->payment_referrer_last_name) }}
						@else
							—
						@endif
					</td>
					<td>{{ $r->recorded_by_name ?: 'Unknown' }}</td>
				</tr>
			@endforeach
		</tbody>
		@if(isset($kpis))
		<tfoot>
			<tr>
				<th colspan="5">Totals ({{ (int) ($kpis['payment_count'] ?? 0) }} payments · {{ (int) ($kpis['line_count'] ?? 0) }} lines)</th>
				<th class="num">{{ number_format((float) ($kpis['total_allocated'] ?? 0), 2) }}</th>
				<th class="num">{{ number_format((float) ($kpis['total_discount'] ?? 0), 2) }}</th>
				<th colspan="4"></th>
			</tr>
		</tfoot>
		@endif
	</table>
	@include('reports.partials.collector-subtotals', ['totals' => $collectorTotals ?? [], 'print' => true])
@endsection
