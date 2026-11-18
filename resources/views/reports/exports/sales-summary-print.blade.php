@extends('reports.exports.print-layout')

@section('report-table')
	@isset ($kpis)
		<div style="margin:0 0 14px;padding:10px 12px;border:1px solid #86efac;background:#ecfdf5;border-radius:6px;font-size:9px;line-height:1.45;color:#14532d;">
			<strong style="font-size:10px;display:block;margin-bottom:6px;text-transform:uppercase;letter-spacing:0.04em;">Totals (all invoices in this report)</strong>
			<table style="width:100%;max-width:520px;border-collapse:collapse;margin:0;" role="presentation">
				<tr>
					<td style="padding:3px 6px 3px 0;font-weight:600;">Total sale</td>
					<td class="num" style="padding:3px 0;text-align:right;font-weight:700;">{{ number_format((float) $kpis['grand_total'], 2) }}</td>
				</tr>
				<tr>
					<td style="padding:3px 6px 3px 0;font-weight:600;">Total paid</td>
					<td class="num" style="padding:3px 0;text-align:right;font-weight:700;color:#047857;">{{ number_format((float) $kpis['amount_paid'], 2) }}</td>
				</tr>
				<tr>
					<td style="padding:3px 6px 3px 0;font-weight:600;">Total due</td>
					<td class="num" style="padding:3px 0;text-align:right;font-weight:700;color:#92400e;">{{ number_format((float) $kpis['amount_due'], 2) }}</td>
				</tr>
			</table>
			<p style="margin:8px 0 0;color:#166534;font-size:8px;">
				Posted invoices (count): {{ number_format((int) ($kpis['invoices'] ?? 0)) }} · Customers: {{ number_format((int) ($kpis['customers'] ?? 0)) }}
			</p>
		</div>
	@endisset

	@php
		$grouped = $rows->groupBy(fn ($inv) => $inv->branch?->name ?? 'Unknown Branch');
	@endphp

	@foreach ($grouped as $branchName => $branchRows)
		@php
			$branchTotal   = $branchRows->sum('grand_total');
			$branchPaid    = $branchRows->sum('amount_paid');
			$branchDue     = $branchRows->sum('amount_due');
		@endphp

		<table class="data" style="margin-bottom:0;">
			<thead>
				<tr>
					<th colspan="9" style="background:#1e3a5f;color:#fff;text-align:left;padding:5px 8px;font-size:10px;letter-spacing:0.03em;">
						{{ $branchName }}
					</th>
				</tr>
				<tr>
					<th>#</th>
					<th>Invoice</th>
					<th>Date</th>
					<th>Customer</th>
					<th>Type</th>
					<th class="num">Total</th>
					<th class="num">Paid</th>
					<th class="num">Due</th>
					<th>Payment</th>
				</tr>
			</thead>
			<tbody>
				@foreach ($branchRows as $i => $invoice)
					<tr>
						<td>{{ $i + 1 }}</td>
						<td>{{ $invoice->invoice_number }}</td>
						<td>{{ $invoice->invoice_date?->toDateString() }}</td>
						<td>{{ $invoice->customer?->name }} @if($invoice->customer?->phone)<br><span style="font-size:8px;">{{ $invoice->customer->phone }}</span>@endif</td>
						<td>{{ ucfirst($invoice->sale_type) }}</td>
						<td class="num">{{ number_format((float) $invoice->grand_total, 2) }}</td>
						<td class="num">{{ number_format((float) $invoice->amount_paid, 2) }}</td>
						<td class="num">{{ number_format((float) $invoice->amount_due, 2) }}</td>
						<td>{{ ucfirst(str_replace('_', ' ', $invoice->payment_status)) }}</td>
					</tr>
				@endforeach
			</tbody>
			<tfoot>
				<tr style="background:#f0fdf4;font-weight:700;font-size:9px;">
					<td colspan="5" style="text-align:right;padding:4px 6px;">{{ $branchName }} Subtotal ({{ $branchRows->count() }} invoice{{ $branchRows->count() === 1 ? '' : 's' }}):</td>
					<td class="num">{{ number_format((float) $branchTotal, 2) }}</td>
					<td class="num" style="color:#047857;">{{ number_format((float) $branchPaid, 2) }}</td>
					<td class="num" style="color:#92400e;">{{ number_format((float) $branchDue, 2) }}</td>
					<td></td>
				</tr>
			</tfoot>
		</table>
		<div style="margin-bottom:16px;"></div>
	@endforeach
@endsection
