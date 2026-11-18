@extends('layouts.app')
@section('title', 'Installment duration')
@section('page-title', 'Installment duration')
@section('content')
<div class="space-y-4">
	<p class="text-sm text-slate-600">
		One row per installment plan: the span the schedule is planned over (first due date to last due date) against how long it actually took to pay off (first due date to the date of the final payment). Active plans show elapsed days instead of an actual duration. Results follow your branch context.
	</p>

	<x-filter-drawer>
		<form method="get" action="{{ route('reports.installment-duration') }}" class="flex flex-col gap-4">
			@include('reports.partials.installment-duration-filter-fields', ['filters' => $filters, 'filterBranches' => $filterBranches])
			<div class="flex flex-wrap items-center gap-2 border-t border-slate-100 pt-3">
				<span class="text-xs font-medium text-slate-500">Export (uses filters above)</span>
				@php
					$exportQuery = array_filter([
						'q' => $filters['q'] ?? '',
						'branch_id' => $filters['branch_id'] ?? '',
						'status' => $filters['status'] ?? '',
						'date_from' => $filters['date_from'] ?? '',
						'date_to' => $filters['date_to'] ?? '',
					], static fn ($v) => $v !== null && $v !== '');
				@endphp
				<a target="_blank" rel="noopener noreferrer" href="{{ route('reports.print', array_merge(['slug' => 'installment-duration'], $exportQuery)) }}" class="rounded-lg border border-slate-300 bg-white px-3 py-1.5 text-xs font-medium text-slate-800 hover:bg-slate-50">Print</a>
				<a href="{{ route('reports.export', array_merge(['slug' => 'installment-duration', 'format' => 'xlsx'], $exportQuery)) }}" class="rounded-lg border border-slate-300 bg-white px-3 py-1.5 text-xs font-medium text-slate-800 hover:bg-slate-50">Excel</a>
			</div>
			<x-index-filter-actions :reset-url="route('reports.installment-duration')" />
		</form>
	</x-filter-drawer>

	<div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
		<x-status-card label="Plans (filtered)">
			{{ number_format($kpis['plans']) }}
		</x-status-card>
		<x-status-card label="Active / Completed">
			{{ number_format($kpis['active']) }} / {{ number_format($kpis['completed']) }}
		</x-status-card>
		<x-status-card label="Avg. planned duration">
			{{ number_format($kpis['avg_planned_days'], 1) }} days
		</x-status-card>
		<x-status-card label="Avg. actual duration (completed)">
			{{ number_format($kpis['avg_actual_days'], 1) }} days
		</x-status-card>
		<x-status-card label="Avg. variance (completed)">
			<span class="{{ $kpis['avg_variance_days'] > 0 ? 'text-rose-700' : 'text-emerald-700' }}">
				{{ $kpis['avg_variance_days'] > 0 ? '+' : '' }}{{ number_format($kpis['avg_variance_days'], 1) }} days
			</span>
		</x-status-card>
		<x-status-card label="Completed plans that overran">
			<span class="text-rose-700">{{ number_format($kpis['overran_count']) }}</span>
		</x-status-card>
	</div>

	<div class="overflow-x-auto overflow-y-hidden rounded-2xl bg-white shadow-sm ring-1 ring-slate-200">
		<table class="min-w-full text-sm whitespace-nowrap">
			<thead class="bg-slate-50 border-b border-slate-200 text-left text-xs font-semibold uppercase tracking-wider text-slate-700">
				<tr>
					<th class="px-4 py-3">Invoice</th>
					<th class="px-4 py-3">Branch</th>
					<th class="px-4 py-3">Customer</th>
					<th class="px-4 py-3">Status</th>
					<th class="px-4 py-3 text-right">#</th>
					<th class="px-4 py-3">First due</th>
					<th class="px-4 py-3">Planned end</th>
					<th class="px-4 py-3 text-right">Planned (days)</th>
					<th class="px-4 py-3">Actual payoff</th>
					<th class="px-4 py-3 text-right">Actual (days)</th>
					<th class="px-4 py-3 text-right">Variance (days)</th>
					<th class="px-4 py-3 text-right">Remaining</th>
				</tr>
			</thead>
			<tbody>
				@forelse($rows as $plan)
					@php
						$invoice = $plan->salesInvoice;
						$customer = $invoice?->customer;
						$branch = $invoice?->branch;
						$variance = $plan->durationVarianceDays();
					@endphp
					<tr class="border-b border-slate-100 transition-colors hover:bg-slate-50/70 last:border-0">
						<td class="px-4 py-3">
							@if ($invoice)
								<a href="{{ route('sales.invoices.show', $invoice) }}" class="font-mono text-xs font-medium text-indigo-700 hover:text-indigo-900">{{ $invoice->invoice_number ?? 'Pending' }}</a>
							@else
								<span class="text-slate-400">—</span>
							@endif
						</td>
						<td class="px-4 py-3">{{ $branch?->name ?? '—' }}</td>
						<td class="px-4 py-3 max-w-xs whitespace-normal">
							<p class="font-medium text-slate-800">{{ $customer?->name ?? '—' }}</p>
							<p class="text-xs text-slate-500">{{ $customer?->phone ?: '—' }}</p>
						</td>
						<td class="px-4 py-3">
							@if ($plan->status === 'completed')
								<span class="inline-flex items-center rounded-md bg-emerald-50 px-2 py-1 text-xs font-medium text-emerald-700 ring-1 ring-inset ring-emerald-600/20">Completed</span>
							@elseif ($plan->status === 'active')
								<span class="inline-flex items-center rounded-md bg-sky-50 px-2 py-1 text-xs font-medium text-sky-700 ring-1 ring-inset ring-sky-600/20">Active</span>
							@else
								<span class="inline-flex items-center rounded-md bg-slate-100 px-2 py-1 text-xs font-medium text-slate-700">{{ ucfirst($plan->status) }}</span>
							@endif
						</td>
						<td class="px-4 py-3 text-right tabular-nums">{{ $plan->schedules->count() }}</td>
						<td class="px-4 py-3 whitespace-nowrap">{{ $plan->plannedStartDate()?->toDateString() ?? '—' }}</td>
						<td class="px-4 py-3 whitespace-nowrap">{{ $plan->plannedEndDate()?->toDateString() ?? '—' }}</td>
						<td class="px-4 py-3 text-right tabular-nums">{{ $plan->plannedDurationDays() ?? '—' }}</td>
						<td class="px-4 py-3 whitespace-nowrap">
							@if ($plan->status === 'completed')
								{{ $plan->actualCompletionDate()?->toDateString() ?? '—' }}
							@else
								<span class="text-xs text-slate-500">In progress ({{ $plan->elapsedDaysSinceStart() ?? 0 }}d elapsed)</span>
							@endif
						</td>
						<td class="px-4 py-3 text-right tabular-nums">{{ $plan->status === 'completed' ? ($plan->actualDurationDays() ?? '—') : '—' }}</td>
						<td class="px-4 py-3 text-right tabular-nums font-semibold {{ $variance !== null && $variance > 0 ? 'text-rose-700' : ($variance !== null ? 'text-emerald-700' : '') }}">
							@if ($variance !== null)
								{{ $variance > 0 ? '+' : '' }}{{ $variance }}
							@else
								—
							@endif
						</td>
						<td class="px-4 py-3 text-right tabular-nums">{{ number_format((float) $plan->remaining_amount, 2) }}</td>
					</tr>
				@empty
					<tr>
						<td colspan="12" class="px-4 py-12 text-center text-sm font-medium text-slate-500 bg-slate-50/50">No installment plans match the current filters.</td>
					</tr>
				@endforelse
			</tbody>
		</table>
	</div>

	<div>{{ $rows->links() }}</div>
</div>
@endsection
