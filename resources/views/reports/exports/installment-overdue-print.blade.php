@extends('reports.exports.print-layout')

@push('print-extra-styles')
	<style>
		.print-installment-overdue-summary {
			margin-bottom: 12px;
			padding: 8px 10px;
			border: 1px solid #94a3b8;
			background: #f8fafc;
			font-size: 8px;
			line-height: 1.45;
			color: #1f2937;
		}
		.print-installment-overdue-summary .print-summary-title {
			font-size: 9px;
			font-weight: 700;
			text-transform: uppercase;
			letter-spacing: 0.06em;
			color: #374151;
			margin: 0 0 6px;
		}
		.print-installment-overdue-summary .print-summary-note { margin: 0 0 6px; color: #4b5563; }
		table.print-summary-meta { width: 100%; border-collapse: collapse; margin: 0; }
		table.print-summary-meta td {
			padding: 3px 4px 3px 0;
			border: none;
			vertical-align: top;
		}
		table.print-summary-meta td.k { width: 52%; color: #4b5563; }
		table.print-summary-meta td.v { font-weight: 600; color: #111827; }
		table.print-summary-meta td.v.num { text-align: right; font-variant-numeric: tabular-nums; }
		table.print-summary-meta tr.emphasis td.v { color: #9f1239; font-weight: 700; }
		table.print-summary-meta tr.emphasis-amber td.v { color: #92400e; font-weight: 700; }
		table.print-summary-meta tr.emphasis-emerald td.v { color: #047857; font-weight: 700; }
	</style>
@endpush

@section('report-table')
	@if(is_array($kpis ?? null))
		<div class="print-installment-overdue-summary">
			<p class="print-summary-title">Summary</p>
			<p class="print-summary-note">Totals include all {{ number_format((int) ($totalMatching ?? 0)) }} overdue schedules in your branch access.</p>
			<table class="print-summary-meta">
				<tbody>
					<tr class="emphasis-amber"><td class="k">Overdue installment schedules</td><td class="v num">{{ number_format((int) ($kpis['schedules'] ?? 0)) }}</td></tr>
					<tr class="emphasis"><td class="k">Outstanding (remaining)</td><td class="v num">{{ number_format((float) ($kpis['remaining'] ?? 0), 2) }}</td></tr>
					<tr><td class="k">Due + late fee (all matching)</td><td class="v num">{{ number_format((float) ($kpis['due_and_late_fee'] ?? 0), 2) }}</td></tr>
					<tr class="emphasis-emerald"><td class="k">Paid toward schedules</td><td class="v num">{{ number_format((float) ($kpis['paid'] ?? 0), 2) }}</td></tr>
				</tbody>
			</table>
		</div>
	@endif

	<table class="data">
		<thead>
			<tr>
				<th class="num">SL</th>
				<th>Invoice</th>
				<th>Branch</th>
				<th>Customer</th>
				<th>Plan</th>
				<th class="num">Ins. No.</th>
				<th>Due</th>
				<th class="num">Days Late</th>
				<th class="num">Total</th>
				<th class="num">Paid</th>
				<th class="num">Late fee</th>
				<th class="num">Remaining</th>
			</tr>
		</thead>
		<tbody>
			@foreach ($rows as $i => $row)
				@php
					$plan = $row->installmentPlan;
					$invoice = $plan?->salesInvoice;
					$customer = $invoice?->customer;
					$branch = $invoice?->branch;
					$daysLate = $row->due_date && $row->due_date->isPast()
						? (int) $row->due_date->diffInDays(\Illuminate\Support\Carbon::today())
						: 0;
					$years  = (int) floor($daysLate / 365);
					$months = (int) floor(($daysLate % 365) / 30);
					$days   = $daysLate % 30;
					$lateParts = [];
					if ($years > 0)  $lateParts[] = $years.($years === 1 ? ' year' : ' years');
					if ($months > 0) $lateParts[] = $months.($months === 1 ? ' month' : ' months');
					if ($days > 0 || $daysLate === 0) $lateParts[] = $days.($days === 1 ? ' day' : ' days');
					$lateLine1 = implode(' ', array_slice($lateParts, 0, 2));
					$lateLine2 = count($lateParts) > 2 ? $lateParts[2] : null;
				@endphp
				<tr>
					<td class="num">{{ $i + 1 }}</td>
					<td>{{ $invoice?->invoice_number }}</td>
					<td>{{ $branch?->name }}</td>
					<td>{{ $customer?->name }} @if($customer?->phone)<br><span style="font-size:8px;">{{ $customer->phone }}</span>@endif</td>
					<td>{{ $plan?->id }}</td>
					<td class="num">{{ $row->installment_no }}</td>
					<td>{{ $row->due_date?->toDateString() }}</td>
					<td class="num">{{ $lateLine1 }}@if($lateLine2)<br>{{ $lateLine2 }}@endif</td>
					<td class="num">{{ number_format((float) $invoice?->grand_total, 2) }}</td>
					<td class="num">{{ number_format((float) $invoice?->amount_paid, 2) }}</td>
					<td class="num">{{ number_format((float) $row->late_fee_amount, 2) }}</td>
					<td class="num">{{ number_format((float) $invoice?->amount_due, 2) }}</td>
				</tr>
			@endforeach
		</tbody>
		@if($rows->isNotEmpty())
			<tfoot>
				<tr>
					<td colspan="8"><strong>Subtotal (rows listed)</strong></td>
					<td class="num"><strong>—</strong></td>
					<td class="num"><strong>{{ number_format($rows->sum(fn ($r) => (float) $r->paid_amount), 2) }}</strong></td>
					<td class="num"><strong>{{ number_format($rows->sum(fn ($r) => (float) $r->late_fee_amount), 2) }}</strong></td>
					<td class="num"><strong>{{ number_format($rows->sum(fn ($r) => $r->remainingDue()), 2) }}</strong></td>
				</tr>
			</tfoot>
		@endif
	</table>
@endsection
