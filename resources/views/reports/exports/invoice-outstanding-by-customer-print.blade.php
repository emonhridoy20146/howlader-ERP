@extends('reports.exports.print-layout')

@section('report-table')
	@if (isset($branchTotals) && $branchTotals->isNotEmpty())
		<p class="report-heading-small" style="margin-top:0">Totals by branch</p>
		<table class="data" style="margin-top:4px">
			<thead>
				<tr>
					<th class="num">#</th>
					<th>Branch</th>
					<th>Br code</th>
					<th class="num">Cust×br</th>
					<th class="num">Open inv.</th>
					<th class="num">Due</th>
				</tr>
			</thead>
			<tbody>
				@foreach ($branchTotals as $b)
					<tr>
						<td class="num">{{ $loop->iteration }}</td>
						<td>{{ $b->branch_name }}</td>
						<td>{{ $b->branch_code }}</td>
						<td class="num">{{ (int) $b->customer_branch_rows }}</td>
						<td class="num">{{ (int) $b->open_invoice_count }}</td>
						<td class="num">{{ number_format((float) $b->total_due, 2) }}</td>
					</tr>
				@endforeach
			</tbody>
			<tfoot>
				<tr>
					<th colspan="3">All (filtered)</th>
					<th class="num">{{ (int) $branchTotals->sum('customer_branch_rows') }}</th>
					<th class="num">{{ (int) $branchTotals->sum('open_invoice_count') }}</th>
					<th class="num">{{ number_format((float) ($kpis['total_due'] ?? 0), 2) }}</th>
				</tr>
			</tfoot>
		</table>
	@endif

	<p class="report-heading-small" style="margin-top:14px">Open invoices (invoice date order within branch)</p>
	<table class="data">
		<thead>
			<tr>
				<th class="num">#</th>
				<th>Invoice</th>
				<th>Date</th>
				<th>Cust</th>
				<th>Phone</th>
				<th>Items</th>
				<th class="num">Grand total</th>
				<th class="num">Balance due</th>
			</tr>
		</thead>
		<tbody>
			@php $prevBranchId = null; $lineNo = 0; @endphp
			@foreach ($rows as $r)
				@if ($prevBranchId !== $r->branch_id)
					<tr>
						<td colspan="8" style="background:#e2e8f0;font-weight:700;padding:6px 4px;border:1px solid #94a3b8">
							<span style="font-size:7px;text-transform:uppercase;color:#475569">Location · </span>{{ $r->branch_name }} <span style="font-family:monospace;font-weight:600;color:#64748b">{{ $r->branch_code }}</span>
						</td>
					</tr>
					@php $prevBranchId = $r->branch_id; @endphp
				@endif
				@php $lineNo++; @endphp
				<tr>
					<td class="num">{{ $lineNo }}</td>
					<td style="font-family:monospace;font-size:8px">{{ $r->invoice_number }}</td>
					<td>{{ \Illuminate\Support\Carbon::parse($r->invoice_date)->format('Y-m-d') }}</td>
					<td><span style="font-family:monospace;font-size:7px">{{ $r->customer_code }}</span><br>{{ $r->customer_name }}</td>
					<td>{{ $r->customer_phone }}</td>
					<td>{{ $r->item_names ?: '—' }}</td>
					<td class="num">{{ number_format((float) $r->grand_total, 2) }}</td>
					<td class="num">{{ number_format((float) $r->invoice_amount_due, 2) }}</td>
				</tr>
			@endforeach
		</tbody>
	</table>
@endsection
