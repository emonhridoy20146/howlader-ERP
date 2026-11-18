@extends('reports.exports.print-layout')

@php
	$s = $report['summary'];
	$rec = $report['recommendation'];
	$trend = $report['trend'];
	$money = fn ($v) => number_format((float) $v, 2);
	$months = $report['charts']['months'];
	$maxCount = max(1, collect($months)->max(fn ($m) => $m['on_time'] + $m['late']));
	$maxAmt = max(1, collect($months)->max(fn ($m) => max($m['due'], $m['paid'])));
	$days = $report['charts']['installment_series']['days_late'];
	$maxDays = max(1, ...($days ?: [1]));
@endphp

@push('print-extra-styles')
	<style>
		.rp-grid { width: 100%; border-collapse: collapse; margin-bottom: 10px; }
		.rp-grid td { border: 1px solid #e5e7eb; padding: 4px 6px; vertical-align: top; width: 25%; }
		.rp-grid .k { color: #6b7280; font-size: 8px; display: block; }
		.rp-h { font-size: 11px; font-weight: bold; margin: 14px 0 5px; border-bottom: 1px solid #9ca3af; padding-bottom: 2px; }
		.bar-row td { padding: 1px 4px; font-size: 8px; }
		.bar { height: 7px; display: inline-block; }
		.late { color: #b91c1c; font-weight: bold; }
		tr { page-break-inside: avoid; }
	</style>
@endpush

@section('report-table')
	<p class="rp-h" style="margin-top:0">{{ $customer->name }} — {{ $customer->customer_code }} · {{ $customer->branch?->name }}</p>
	<table class="rp-grid">
		<tr>
			<td><span class="k">Status</span>{{ ucfirst($report['status']) }}</td>
			<td><span class="k">Risk grade / score</span>{{ $report['grade'] }} @if ($report['score']['total'] !== null) · {{ $report['score']['total'] }}/100 @endif</td>
			<td><span class="k">Risk level</span>{{ $report['risk_level'] }}</td>
			<td><span class="k">Late pattern</span>{{ ucfirst($trend['classification']) }}{{ $trend['improving'] ? ' (improving)' : '' }}</td>
		</tr>
		<tr>
			<td><span class="k">Phones</span>{{ implode(', ', $customer->normalizedPhoneNumbers()) ?: '—' }}</td>
			<td><span class="k">Email</span>{{ $customer->email ?: '—' }}</td>
			<td><span class="k">ID</span>{{ trim($customer->id_type.' '.$customer->id_number) ?: '—' }}</td>
			<td><span class="k">Credit limit</span>{{ $money($customer->credit_limit) }}</td>
		</tr>
		<tr><td colspan="4"><span class="k">Address</span>{{ trim($customer->address.' '.$customer->detailed_address) ?: '—' }}</td></tr>
	</table>

	<p class="rp-h">Guarantors</p>
	<table class="data">
		<thead><tr><th>Name</th><th>Type</th><th>Phone</th><th>NID</th><th>Address</th><th>Grade</th></tr></thead>
		<tbody>
			@forelse ($report['guarantors'] as $g)
				<tr><td>{{ $g['name'] }}</td><td>{{ $g['relation'] }}</td><td>{{ implode(', ', $g['phones']) }}</td><td>{{ $g['nid_number'] }}</td><td>{{ $g['address'] }}</td><td>{{ $g['grade'] ?? '—' }}</td></tr>
			@empty
				<tr><td colspan="6">No guarantors on record.</td></tr>
			@endforelse
		</tbody>
	</table>

	<p class="rp-h">Key figures</p>
	<table class="rp-grid">
		<tr>
			<td><span class="k">Purchased</span>{{ $money($s['total_purchased']) }}</td>
			<td><span class="k">Paid</span>{{ $money($s['total_paid']) }}</td>
			<td><span class="k">Installment outstanding</span>{{ $money($s['outstanding']) }}</td>
			<td><span class="k">Overdue</span>{{ $money($s['overdue_amount']) }} ({{ $s['overdue_count'] }})</td>
		</tr>
		<tr>
			<td><span class="k">On-time rate</span>{{ $s['on_time_rate'] === null ? 'N/A' : number_format($s['on_time_rate'] * 100, 1).'%' }}</td>
			<td><span class="k">Avg / max days late</span>{{ $s['avg_days_late'] }} / {{ $s['max_days_late'] }}</td>
			<td><span class="k">Lates 3m / 6m / 12m</span>{{ $s['late_3m'] }} / {{ $s['late_6m'] }} / {{ $s['late_12m'] }}</td>
			<td><span class="k">Streak longest / current</span>{{ $s['longest_streak'] }} / {{ $s['current_streak'] }}</td>
		</tr>
	</table>

	<p class="rp-h">Risk analysis</p>
	<p>{{ $trend['reason'] }}</p>
	@if ($report['score']['total'] !== null)
		<table class="data" style="margin-top:6px">
			<thead><tr><th>Factor</th><th>Value</th><th class="num">Weight</th><th class="num">Points</th></tr></thead>
			<tbody>
				@foreach ($report['score']['factors'] as $f)
					<tr><td>{{ $f['label'] }}</td><td>{{ $f['value'] }}</td><td class="num">{{ $f['weight'] }}</td><td class="num">{{ $f['points'] }}</td></tr>
				@endforeach
				<tr><td colspan="3"><strong>Total</strong></td><td class="num"><strong>{{ $report['score']['total'] }}</strong></td></tr>
			</tbody>
		</table>
		<p style="font-size:8px;color:#6b7280">{{ $report['score']['formula'] }}</p>
	@endif
	<p style="margin-top:6px"><strong>Suggested future installment:</strong> {{ $rec['text'] }}
		@if ($rec['max_amount'] !== null) Max amount {{ $money($rec['max_amount']) }} · max tenure {{ $rec['max_tenure_months'] }} months · min down payment {{ $rec['min_down_payment_pct'] }}%. @endif</p>

	<p class="rp-h">On-time vs late per month</p>
	<table style="width:100%;border-collapse:collapse">
		@foreach ($months as $m)
			<tr class="bar-row">
				<td style="width:60px">{{ $m['label'] }}</td>
				<td>
					<span class="bar" style="background:#10b981;width:{{ round($m['on_time'] / $maxCount * 300) }}px"></span><span class="bar" style="background:#ef4444;width:{{ round($m['late'] / $maxCount * 300) }}px"></span>
					{{ $m['on_time'] }} on time / {{ $m['late'] }} late
				</td>
			</tr>
		@endforeach
	</table>

	<p class="rp-h">Due vs paid per month</p>
	<table style="width:100%;border-collapse:collapse">
		@foreach ($months as $m)
			<tr class="bar-row">
				<td style="width:60px">{{ $m['label'] }}</td>
				<td>
					<span class="bar" style="background:#94a3b8;width:{{ round($m['due'] / $maxAmt * 260) }}px"></span> {{ $money($m['due']) }}<br>
					<span class="bar" style="background:#3b82f6;width:{{ round($m['paid'] / $maxAmt * 260) }}px"></span> {{ $money($m['paid']) }}
				</td>
			</tr>
		@endforeach
	</table>

	<p class="rp-h">Days late per installment</p>
	<svg width="560" height="90" viewBox="0 0 560 90" xmlns="http://www.w3.org/2000/svg">
		<line x1="0" y1="80" x2="560" y2="80" stroke="#9ca3af" />
		@php $n = count($days); $step = $n > 1 ? 540 / ($n - 1) : 0; $pts = []; @endphp
		@foreach ($days as $i => $d)
			@php $x = 10 + $i * $step; $y = 80 - ($d / $maxDays) * 70; $pts[] = round($x, 1).','.round($y, 1); @endphp
			<circle cx="{{ round($x, 1) }}" cy="{{ round($y, 1) }}" r="2" fill="#f59e0b" />
		@endforeach
		@if ($n > 1)<polyline points="{{ implode(' ', $pts) }}" fill="none" stroke="#f59e0b" stroke-width="1" />@endif
		<text x="2" y="10" font-size="8" fill="#6b7280">max {{ $maxDays }} d</text>
	</svg>

	<p class="rp-h">Invoices</p>
	<table class="data">
		<thead><tr><th>Invoice</th><th>Date</th><th>Type</th><th>Status</th><th class="num">Total</th><th class="num">Paid</th><th class="num">Due</th></tr></thead>
		<tbody>
			@foreach ($report['invoices'] as $inv)
				<tr><td>{{ $inv->invoice_number }}</td><td>{{ $inv->invoice_date?->format('Y-m-d') }}</td><td>{{ ucfirst($inv->sale_type) }}</td><td>{{ ucfirst(str_replace('_', ' ', $inv->status)) }}</td><td class="num">{{ $money($inv->grand_total) }}</td><td class="num">{{ $money($inv->amount_paid) }}</td><td class="num">{{ $money($inv->amount_due) }}</td></tr>
			@endforeach
		</tbody>
	</table>

	@foreach ($report['plans'] as $plan)
		<p class="rp-h">Plan {{ $plan['invoice_number'] }} — {{ ucfirst($plan['status']) }}{{ $plan['written_off'] ? ' (written off)' : '' }}</p>
		<table class="data">
			<thead><tr><th>#</th><th>Due date</th><th class="num">Due</th><th class="num">Late fee</th><th class="num">Paid</th><th>Paid date</th><th class="num">Days late</th><th>Band</th><th>Status</th></tr></thead>
			<tbody>
				@foreach ($plan['installments'] as $row)
					<tr>
						<td>{{ $row['installment_no'] }}</td><td>{{ $row['due_date']?->format('Y-m-d') }}</td>
						<td class="num">{{ $money($row['due_amount']) }}</td><td class="num">{{ $money($row['late_fee']) }}</td><td class="num">{{ $money($row['paid_amount']) }}</td>
						<td>{{ $row['paid_date']?->format('Y-m-d') ?? '—' }}</td>
						<td class="num {{ $row['is_late'] ? 'late' : '' }}">{{ $row['days_late'] }}</td>
						<td class="{{ $row['is_late'] ? 'late' : '' }}">{{ $row['band'] }}</td>
						<td>{{ ucfirst(str_replace('_', ' ', $row['status'])) }}</td>
					</tr>
				@endforeach
			</tbody>
		</table>
	@endforeach
@endsection
