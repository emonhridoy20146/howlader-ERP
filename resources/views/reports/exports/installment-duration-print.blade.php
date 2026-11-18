@extends('reports.exports.print-layout')

@section('report-table')
	<table class="data">
		<thead>
			<tr>
				<th class="num">#</th>
				<th>Invoice</th>
				<th>Branch</th>
				<th>Customer</th>
				<th>Status</th>
				<th class="num">Schedules</th>
				<th>First due</th>
				<th>Planned end</th>
				<th class="num">Planned (days)</th>
				<th>Actual payoff</th>
				<th class="num">Actual (days)</th>
				<th class="num">Variance (days)</th>
				<th class="num">Remaining</th>
			</tr>
		</thead>
		<tbody>
			@foreach ($rows as $plan)
				@php
					$invoice = $plan->salesInvoice;
					$customer = $invoice?->customer;
					$branch = $invoice?->branch;
					$variance = $plan->durationVarianceDays();
				@endphp
				<tr>
					<td class="num">{{ $loop->iteration }}</td>
					<td>{{ $invoice?->invoice_number }}</td>
					<td>{{ $branch?->name }}</td>
					<td>{{ $customer?->name }} @if($customer?->phone)<br><span style="font-size:8px;">{{ $customer->phone }}</span>@endif</td>
					<td>{{ ucfirst($plan->status) }}</td>
					<td class="num">{{ $plan->schedules->count() }}</td>
					<td>{{ $plan->plannedStartDate()?->toDateString() }}</td>
					<td>{{ $plan->plannedEndDate()?->toDateString() }}</td>
					<td class="num">{{ $plan->plannedDurationDays() }}</td>
					<td>{{ $plan->status === 'completed' ? $plan->actualCompletionDate()?->toDateString() : 'In progress' }}</td>
					<td class="num">{{ $plan->status === 'completed' ? $plan->actualDurationDays() : '' }}</td>
					<td class="num">{{ $variance !== null ? ($variance > 0 ? '+' : '').$variance : '' }}</td>
					<td class="num">{{ number_format((float) $plan->remaining_amount, 2) }}</td>
				</tr>
			@endforeach
		</tbody>
	</table>
	@if(($kpis['plans'] ?? 0) > 0)
		<table class="data" style="margin-top: 10px;">
			<tbody>
				<tr>
					<th style="text-align:left;">Plans</th>
					<td class="num">{{ number_format($kpis['plans']) }}</td>
					<th style="text-align:left;">Active</th>
					<td class="num">{{ number_format($kpis['active']) }}</td>
					<th style="text-align:left;">Completed</th>
					<td class="num">{{ number_format($kpis['completed']) }}</td>
				</tr>
				<tr>
					<th style="text-align:left;">Avg. planned (days)</th>
					<td class="num">{{ number_format($kpis['avg_planned_days'], 1) }}</td>
					<th style="text-align:left;">Avg. actual (days)</th>
					<td class="num">{{ number_format($kpis['avg_actual_days'], 1) }}</td>
					<th style="text-align:left;">Avg. variance (days)</th>
					<td class="num">{{ number_format($kpis['avg_variance_days'], 1) }}</td>
				</tr>
			</tbody>
		</table>
	@endif
@endsection
