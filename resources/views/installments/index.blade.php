@extends('layouts.app')
@section('title', 'Installments')
@section('page-title', 'Installments')
@section('content')
<div class="mb-4 flex flex-wrap items-center justify-between gap-3">
	<div class="flex flex-wrap items-center gap-2">
		@can('view-report', 'installment-overdue')
			<a href="{{ route('reports.installment-overdue') }}" class="rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-sm font-medium text-amber-900 hover:bg-amber-100">Overdue report</a>
		@endcan
	</div>
</div>

@if (session('status'))
	<div class="mb-4 rounded-lg bg-emerald-50 p-3 text-sm text-emerald-700">{{ session('status') }}</div>
@endif

<x-filter-drawer>
<form method="get" action="{{ route('installments.index') }}" class="flex flex-col gap-4">
	<div class="flex flex-col gap-4">
		<input type="text" name="q" value="{{ $filters['q'] ?? '' }}" placeholder="Plan ID, invoice #, customer name or code" class="rounded-lg border border-slate-300 px-3 py-2 text-sm ">
		<select name="status" class="rounded-lg border border-slate-300 px-3 py-2 text-sm">
			<option value="">All status</option>
			@foreach (['active', 'completed', 'defaulted', 'cancelled'] as $status)
				<option value="{{ $status }}" @selected(($filters['status'] ?? '') === $status)>{{ ucfirst($status) }}</option>
			@endforeach
		</select>
	</div>
	<x-index-filter-actions :reset-url="route('installments.index')" />
</form>
</x-filter-drawer>

<div class="mb-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-5">
	<x-status-card label="Total plans">
			<span class="tabular-nums">{{ $kpis['total'] }}</span>
		</x-status-card>
	<x-status-card label="Active plans">
			<span class="tabular-nums">{{ $kpis['active'] }}</span>
		</x-status-card>
	<x-status-card label="Overdue schedules">
			<span class="tabular-nums">{{ $kpis['overdue_schedules'] }}</span>
		</x-status-card>
	<x-status-card label="Remaining (financed)">
			<span class="tabular-nums">{{ number_format($kpis['remaining_total'], 2) }}</span>
		</x-status-card>
	<x-status-card label="Matching filter">
			<span class="tabular-nums">{{ $kpis['filtered'] }}</span>
		</x-status-card>
</div>

<x-index-table-shell title="Installment plans" :meta="$plans->total().' in this list'">
	<table class="index-data-table min-w-[52rem] sm:min-w-full whitespace-nowrap">
		<thead class="bg-slate-50 border-b border-slate-200 text-left text-xs font-semibold uppercase tracking-wider text-slate-700">
			<tr>
				<th class="px-4 py-3">Plan</th>
				<th class="px-4 py-3">Invoice</th>
				<th class="px-4 py-3">Customer</th>
				<th class="px-4 py-3">Branch</th>
				<th class="px-4 py-3 text-right">Total</th>
				<th class="px-4 py-3 text-right">Balance Due</th>
				<th class="px-4 py-3">Overdue Date</th>
				<th class="px-4 py-3">Plan Status</th>
				<th class="px-4 py-3 text-right">Actions</th>
			</tr>
		</thead>
		<tbody>
			@forelse ($plans as $plan)
				@php
					$invoice = $plan->salesInvoice;
					$customer = $invoice?->customer;
					$branch = $invoice?->branch;
					$statusClass = match ($plan->status) {
						'active' => 'bg-emerald-50 text-emerald-800 ring-emerald-100',
						'completed' => 'bg-slate-100 text-slate-700 ring-slate-200',
						'defaulted' => 'bg-rose-50 text-rose-800 ring-rose-100',
						'cancelled' => 'bg-slate-50 text-slate-600 ring-slate-200',
						default => 'bg-slate-100 text-slate-700 ring-slate-200',
					};
					$overdueSchedules = $plan->status === 'active'
						? $plan->schedules->filter(fn ($s) => $s->remainingDue() >= 0.01 && $s->due_date && $s->due_date->isPast())->sortBy('due_date')
						: collect();
					$hasOverdue = $overdueSchedules->isNotEmpty();
					$overdueCount = $overdueSchedules->count();
					$mostOverdue = $overdueSchedules->first(); // earliest unpaid overdue
					$mostOverdueDays = $mostOverdue ? (int) $mostOverdue->due_date->diffInDays(now()) : 0;
				@endphp
				<tr class="border-b border-slate-100 transition-colors last:border-0 {{ $hasOverdue ? 'bg-rose-50/60 hover:bg-rose-50' : 'hover:bg-slate-50/70' }}">
					<td class="px-4 py-3 font-mono text-xs text-slate-800">#{{ $plan->id }}</td>
					<td class="px-4 py-3">
						@if ($invoice)
							@can('view', $invoice)
								<a href="{{ route('sales.invoices.show', $invoice) }}" class="font-medium text-blue-700 hover:text-blue-900">{{ $invoice->invoice_number }}</a>
							@else
								<span class="text-slate-800">{{ $invoice->invoice_number }}</span>
							@endcan
						@else
							<span class="text-slate-400">—</span>
						@endif
					</td>
					<td class="px-4 py-3">
						@if ($customer)
							<div class="font-medium text-slate-900">{{ $customer->name }}</div>
							<div class="text-xs text-slate-500">{{ $customer->customer_code }}</div>
						@else
							<span class="text-slate-400">—</span>
						@endif
					</td>
					<td class="px-4 py-3 text-slate-700">
						@if ($branch)
							<span class="text-sm text-slate-800">{{ $branch->name }}</span>
						@else
							<span class="text-slate-400">—</span>
						@endif
					</td>
					<td class="px-4 py-3 text-right tabular-nums">{{ number_format((float) $plan->total_amount, 2) }}</td>
					<td class="px-4 py-3 text-right font-medium tabular-nums text-slate-900">{{ number_format((float) $plan->remaining_amount, 2) }}</td>
					<td class="px-4 py-3">
						@if ($hasOverdue)
							<div class="flex flex-col gap-0.5">
								<span class="inline-flex w-fit rounded-full bg-rose-100 px-2 py-0.5 text-xs font-semibold text-rose-700 ring-1 ring-rose-200">⚠ {{ $overdueCount }} Overdue</span>
								<span class="text-xs text-rose-600 tabular-nums">{{ $mostOverdueDays }}days ago ·</span>
							</div>
						@else
							<span class="text-xs text-slate-400">—</span>
						@endif
					</td>
					<td class="px-4 py-3">
						<span class="inline-flex rounded-full px-2 py-0.5 text-xs font-medium ring-1 {{ $statusClass }}">{{ ucfirst($plan->status) }}</span>
					</td>
					<td class="px-4 py-3 text-right">
						<x-index-row-actions-menu>
							<a href="{{ route('installments.show', $plan) }}">View</a>
						</x-index-row-actions-menu>
					</td>
				</tr>
			@empty
				<tr>
					<td colspan="9" class="px-4 py-12 text-center text-sm font-medium text-slate-500 bg-slate-50/50">No installment plans found.</td>
				</tr>
			@endforelse
		</tbody>
	</table>
</x-index-table-shell>
	<x-pagination-limit-selector />
<div class="mt-4">{{ $plans->links() }}</div>
@endsection
