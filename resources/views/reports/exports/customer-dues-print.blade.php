@extends('reports.exports.print-layout')

@push('print-extra-styles')
	<style>
		.print-recalc-summary {
			margin-bottom: 12px;
			padding: 8px 10px;
			border: 1px solid #94a3b8;
			background: #f8fafc;
			font-size: 8px;
			line-height: 1.45;
			color: #1f2937;
		}
		.print-recalc-summary .print-recalc-title {
			font-size: 9px;
			font-weight: 700;
			text-transform: uppercase;
			letter-spacing: 0.06em;
			color: #374151;
			margin: 0 0 6px;
		}
		.print-recalc-summary .print-recalc-note { margin: 0 0 6px; color: #4b5563; }
		table.print-recalc-meta { width: 100%; border-collapse: collapse; margin: 0; }
		table.print-recalc-meta td {
			padding: 3px 4px 3px 0;
			border: none;
			vertical-align: top;
		}
		table.print-recalc-meta td.k { width: 42%; color: #4b5563; }
		table.print-recalc-meta td.v { font-weight: 600; color: #111827; }
		table.print-recalc-meta td.v.num { text-align: right; font-variant-numeric: tabular-nums; }
		table.print-recalc-meta tr.total td.v { color: #9f1239; font-weight: 700; }
		table.data tr.branch-group td {
			background: #e8e8e8;
			color: #000;
			font-size: 12px;
			font-weight: 700;
			letter-spacing: 0.01em;
			padding: 10px 4px 6px;
			border: none;
			border-bottom: 2px solid #000;
		}
		table.data tr.branch-subtotal td {
			background: #f0f0f0;
			font-size: 9px;
			font-weight: 700;
			padding: 6px 4px 8px;
			border-color: #000;
			border-top: 2px solid #000;
		}
		table.data tr.branch-subtotal .subtotal-label {
			font-size: 7px;
			font-weight: 700;
			letter-spacing: 0.08em;
			text-transform: uppercase;
			color: #222;
		}
	</style>
@endpush

@section('report-table')
	@if(is_array($recalcSummary ?? null) && isset($recalcSummary['ran_at']))
		@php
			$recalcAt = \Illuminate\Support\Carbon::parse($recalcSummary['ran_at'])->timezone(config('app.timezone'))->format('Y-m-d H:i');
		@endphp
		<div class="print-recalc-summary">
			<p class="print-recalc-title">Last balance recalculation</p>
			<p class="print-recalc-note">From <strong>customers:recalculate-outstanding</strong> — portfolio-wide totals (not branch-scoped).</p>
			<table class="print-recalc-meta">
				<tbody>
					<tr><td class="k">Run at</td><td class="v">{{ $recalcAt }}</td></tr>
					<tr><td class="k">Customers adjusted</td><td class="v num">{{ number_format((int) ($recalcSummary['customers_adjusted'] ?? 0)) }}</td></tr>
					<tr><td class="k">Active customers with dues</td><td class="v num">{{ number_format((int) ($recalcSummary['customers_with_dues'] ?? 0)) }}</td></tr>
					<tr><td class="k">Open invoices (posted/active)</td><td class="v num">{{ number_format((int) ($recalcSummary['open_invoices'] ?? 0)) }}</td></tr>
					<tr class="total"><td class="k">Total outstanding (all customers)</td><td class="v num">{{ number_format((float) ($recalcSummary['total_outstanding_all_customers'] ?? 0), 2) }}</td></tr>
				</tbody>
			</table>
		</div>
	@endif

	@php
		$firstRow = $rows->first();
		$hasBranchData = $firstRow !== null && array_key_exists('branch_id', $firstRow instanceof \Illuminate\Database\Eloquent\Model ? $firstRow->getAttributes() : (array) $firstRow);
		$grouped = $hasBranchData
			? $rows->sortBy(fn ($c) => $c->branch_name ?? 'zzz')->groupBy(fn ($c) => (string) ($c->branch_id ?? '0'))
			: collect(['0' => $rows]);
	@endphp

	<table class="data">
		<thead>
			<tr>
				<th class="num">#</th>
				<th>Code</th>
				<th>Customer</th>
				<th>Phone</th>
				<th>Email</th>
				<th>Invoice No(s)</th>
				<th class="num">Credit limit</th>
				<th class="num">Total due</th>
			</tr>
		</thead>
		<tbody>
			@php $lineNo = 0; @endphp
			@foreach ($grouped as $branchRows)
				@php
					$firstRow = $branchRows->first();
					$branchName = $firstRow->branch_name ?? null;
					$branchTotal = $branchRows->sum(fn ($c) => (float) $c->outstanding_balance);
				@endphp
				@if ($hasBranchData)
					<tr class="branch-group">
						<td colspan="8">{{ $branchName ?? 'Unassigned' }}</td>
					</tr>
				@endif
				@foreach ($branchRows as $c)
					@php $lineNo++; @endphp
					<tr>
						<td class="num">{{ $lineNo }}</td>
						<td>{{ $c->customer_code }}</td>
						<td>{{ $c->name }}</td>
						<td>{{ $c->phone }}</td>
						<td>{{ $c->email }}</td>
						<td>{{ $c->invoice_numbers ?? '—' }}</td>
						<td class="num">{{ number_format((float) $c->credit_limit, 2) }}</td>
						<td class="num">{{ number_format((float) $c->outstanding_balance, 2) }}</td>
					</tr>
				@endforeach
				@if ($hasBranchData)
					<tr class="branch-subtotal">
						<td colspan="7"><span class="subtotal-label">{{ $branchName ?? 'Unassigned' }} total</span></td>
						<td class="num">{{ number_format($branchTotal, 2) }}</td>
					</tr>
				@endif
			@endforeach
		</tbody>
		@if($rows->count() > 0)
			<tfoot>
				<tr>
					<th colspan="7" class="num" style="text-align: right; font-weight: 700;">Total due (filtered)</th>
					<td class="num" style="font-weight: 700;">{{ number_format((float) ($totalOutstanding ?? 0), 2) }}</td>
				</tr>
			</tfoot>
		@endif
	</table>
@endsection
