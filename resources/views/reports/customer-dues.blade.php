@extends('layouts.app')
@section('title', 'Customer dues')
@section('page-title', 'Customer dues')
@section('content')
<div class="space-y-4">
	<p class="text-sm text-slate-600">
		Customers with a positive outstanding balance.
		@if ($kpis['branch_scoped'] ?? false)
			Amounts reflect open invoice balances in the selected branch{{ ($filters['branch_id'] ?? '') === '' ? ' (from the header location picker)' : '' }}.
		@else
			If you do not have <span class="font-medium">View all locations</span>, only customers with open invoice balances in your accessible branches are listed.
		@endif
	</p>

	<x-filter-drawer>
<form method="get" action="{{ route('reports.customer-dues') }}" class="flex flex-col gap-4">
		@include('reports.partials.customer-dues-filter-fields', ['filters' => $filters, 'filterBranches' => $filterBranches])
		<div class="flex flex-wrap items-center gap-2 border-t border-slate-100 pt-3">
			<span class="text-xs font-medium text-slate-500">Export</span>
			<a target="_blank" rel="noopener noreferrer" href="{{ route('reports.print', array_merge(['slug' => 'customer-dues'], request()->only(['q', 'branch_id']))) }}" class="rounded-lg border border-slate-300 bg-white px-3 py-1.5 text-xs font-medium text-slate-800 hover:bg-slate-50">Print</a>
			<a href="{{ route('reports.export', array_merge(['slug' => 'customer-dues', 'format' => 'xlsx'], request()->only(['q', 'branch_id']))) }}" class="rounded-lg border border-slate-300 bg-white px-3 py-1.5 text-xs font-medium text-slate-800 hover:bg-slate-50">Excel</a>
		</div>
		<x-index-filter-actions :reset-url="route('reports.customer-dues')" />
	</form>
</x-filter-drawer>

	<div class="grid gap-3 sm:grid-cols-2">
		<x-status-card label="Customers">
			{{ number_format($kpis['customers']) }}
		</x-status-card>
		<x-status-card label="Total due (filtered)">
			<span class="text-rose-700">{{ number_format($kpis['total_outstanding'], 2) }}</span>
		</x-status-card>
	</div>

	@if(is_array($recalcSummary ?? null) && isset($recalcSummary['ran_at']))
		@php
			$recalcAt = \Illuminate\Support\Carbon::parse($recalcSummary['ran_at'])->timezone(config('app.timezone'))->format('Y-m-d H:i');
		@endphp
		<div class="rounded-xl border border-slate-200 bg-slate-50/80 px-4 py-3 text-sm text-slate-700">
			<p class="font-medium text-slate-800">Last balance recalculation</p>
			<p class="mt-1 text-xs text-slate-600">From <code class="rounded bg-white px-1 py-0.5 text-xs font-mono text-slate-800">customers:recalculate-outstanding</code> — portfolio-wide totals (not branch-scoped).</p>
			<dl class="mt-2 grid gap-1 text-xs sm:grid-cols-2 sm:gap-x-6">
				<div><dt class="text-slate-500">Run at</dt><dd class="font-medium text-slate-900">{{ $recalcAt }}</dd></div>
				<div><dt class="text-slate-500">Customers adjusted</dt><dd class="font-medium text-slate-900">{{ number_format((int) ($recalcSummary['customers_adjusted'] ?? 0)) }}</dd></div>
				<div><dt class="text-slate-500">Active customers with dues</dt><dd class="font-medium text-slate-900">{{ number_format((int) ($recalcSummary['customers_with_dues'] ?? 0)) }}</dd></div>
				<div><dt class="text-slate-500">Open invoices (posted/active)</dt><dd class="font-medium text-slate-900">{{ number_format((int) ($recalcSummary['open_invoices'] ?? 0)) }}</dd></div>
				<div class="sm:col-span-2"><dt class="text-slate-500">Total outstanding (all customers)</dt><dd class="font-semibold text-rose-700 tabular-nums">{{ number_format((float) ($recalcSummary['total_outstanding_all_customers'] ?? 0), 2) }}</dd></div>
			</dl>
		</div>
	@endif

	<div class="overflow-x-auto overflow-y-hidden rounded-2xl bg-white shadow-sm ring-1 ring-slate-200">
		<table class="min-w-full text-sm whitespace-nowrap">
				<thead class="bg-slate-50 border-b border-slate-200 text-left text-xs font-semibold uppercase tracking-wider text-slate-700">
					<tr>
						<th class="px-4 py-3">Code</th>
						<th class="px-4 py-3">Customer</th>
						<th class="px-4 py-3">Phone</th>
						<th class="px-4 py-3 text-right">Credit limit</th>
						<th class="px-4 py-3 text-right">Total due</th>
					</tr>
				</thead>
				<tbody>
					@forelse($rows as $customer)
						<tr class="border-b border-slate-100 transition-colors hover:bg-slate-50/70 last:border-0">
							<td class="px-4 py-3 font-mono text-xs">{{ $customer->customer_code }}</td>
							<td class="px-4 py-3 font-medium text-slate-800">{{ $customer->name }}</td>
							<td class="px-4 py-3 text-slate-600">{{ $customer->phone ?: '—' }}</td>
							<td class="px-4 py-3 text-right tabular-nums">{{ number_format((float) $customer->credit_limit, 2) }}</td>
							<td class="px-4 py-3 text-right tabular-nums font-semibold text-rose-700">{{ number_format((float) $customer->outstanding_balance, 2) }}</td>
						</tr>
					@empty
						<tr><td colspan="5" class="px-4 py-12 text-center text-sm font-medium text-slate-500 bg-slate-50/50">No customers with dues match the current filters.</td></tr>
					@endforelse
				</tbody>
				@if($rows->count() > 0)
					<tfoot class="border-t-2 border-slate-200 bg-slate-50/90">
						<tr>
							<td colspan="4" class="px-4 py-3 text-right text-xs font-semibold uppercase tracking-wider text-slate-600">Total due (all matching)</td>
							<td class="px-4 py-3 text-right text-sm font-bold tabular-nums text-rose-700">{{ number_format($kpis['total_outstanding'], 2) }}</td>
						</tr>
					</tfoot>
				@endif
			</table>
		</div>

	<div>{{ $rows->links() }}</div>
</div>
@endsection
