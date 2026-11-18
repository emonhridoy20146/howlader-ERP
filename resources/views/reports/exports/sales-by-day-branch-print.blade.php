@extends('reports.exports.print-layout')

@php
    $productTotals = $productTotals ?? ['qty_sold' => 0, 'line_total' => 0.0];
@endphp

@section('report-table')
	<table class="data">
		<thead>
			<tr>
				<th class="num">#</th>
				<th>Date</th>
				<th>Product</th>
				<th class="num">Qty</th>
				<th class="num">Line total</th>
				<th class="num">Inv.</th>
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
					<td>{{ $r->invoice_date }}</td>
					<td>{{ $r->product_name }}</td>
					<td class="num">{{ (int) $r->qty_sold }}</td>
					<td class="num">{{ number_format((float) $r->line_total, 2) }}</td>
					<td class="num">{{ (int) $r->invoice_count }}</td>
				</tr>
			@endforeach
		</tbody>
		@if(isset($kpis))
		<tfoot>
			<tr>
				<th colspan="3">Totals (product lines)</th>
				<th class="num">{{ (int) ($productTotals['qty_sold'] ?? 0) }}</th>
				<th class="num">{{ number_format((float) ($productTotals['line_total'] ?? 0), 2) }}</th>
				<th class="num">—</th>
			</tr>
			<tr>
				<th colspan="4">Invoices · Grand · Paid · Due</th>
				<th class="num" colspan="2">
					{{ (int) ($kpis['invoice_count'] ?? 0) }} ·
					{{ number_format((float) ($kpis['grand_total'] ?? 0), 2) }} ·
					{{ number_format((float) ($kpis['amount_paid'] ?? 0), 2) }} ·
					{{ number_format((float) ($kpis['amount_due'] ?? 0), 2) }}
				</th>
			</tr>
		</tfoot>
		@endif
	</table>
@endsection
