@extends('layouts.app')
@section('title', 'Sales Summary')
@section('page-title', 'Sales Summary')
@section('content')
<div class="space-y-4">
	<p class="text-sm text-slate-600">Posted sales only, scoped to your branch context unless you pick a branch below.</p>

	<x-filter-drawer>
<form method="get" action="{{ route('reports.sales-summary') }}" class="flex flex-col gap-4">
		@include('reports.partials.sales-filter-fields', ['filters' => $filters, 'filterBranches' => $filterBranches])
		<div class="flex flex-wrap items-center gap-2 border-t border-slate-100 pt-3">
			<span class="text-xs font-medium text-slate-500">Export</span>
			<a target="_blank" rel="noopener noreferrer" href="{{ route('reports.print', array_merge(['slug' => 'sales-summary'], request()->only(['q', 'date_from', 'date_to', 'branch_id', 'payment_status', 'sale_type']))) }}" class="rounded-lg border border-slate-300 bg-white px-3 py-1.5 text-xs font-medium text-slate-800 hover:bg-slate-50">Print</a>
			<a href="{{ route('reports.export', array_merge(['slug' => 'sales-summary', 'format' => 'xlsx'], request()->only(['q', 'date_from', 'date_to', 'branch_id', 'payment_status', 'sale_type']))) }}" class="rounded-lg border border-slate-300 bg-white px-3 py-1.5 text-xs font-medium text-slate-800 hover:bg-slate-50">Excel</a>
		</div>
		<x-index-filter-actions :reset-url="route('reports.sales-summary')" />
	</form>
</x-filter-drawer>

	<p class="rounded-xl border border-slate-200 bg-slate-50 px-4 py-2.5 text-xs leading-relaxed text-slate-600">
		<span class="font-semibold text-slate-700">Report summary:</span>
		{{ $filterSummary }}
	</p>

	<div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-5">
		<x-status-card label="Posted invoices">
			{{ number_format($kpis['invoices']) }}
		</x-status-card>
		<x-status-card label="Revenue (grand total)">
			{{ number_format($kpis['grand_total'], 2) }}
		</x-status-card>
		<x-status-card label="Collected">
			<span class="text-emerald-700">{{ number_format($kpis['amount_paid'], 2) }}</span>
		</x-status-card>
		<x-status-card label="Outstanding">
			<span class="text-amber-700">{{ number_format($kpis['amount_due'], 2) }}</span>
		</x-status-card>
		<x-status-card label="Customers">
			{{ number_format($kpis['customers']) }}
		</x-status-card>
	</div>

	<div class="overflow-x-auto overflow-y-hidden rounded-2xl bg-white shadow-sm ring-1 ring-slate-200">
		<table class="min-w-full text-sm whitespace-nowrap">
				<thead class="bg-slate-50 border-b border-slate-200 text-left text-xs font-semibold uppercase tracking-wider text-slate-700">
					<tr>
						<th class="px-4 py-3">Invoice</th>
						<th class="px-4 py-3">Date</th>
						<th class="px-4 py-3">Branch</th>
						<th class="px-4 py-3">Customer</th>
						<th class="px-4 py-3">Type</th>
						<th class="px-4 py-3 text-right">Total</th>
						<th class="px-4 py-3 text-right">Paid</th>
						<th class="px-4 py-3 text-right">Due</th>
						<th class="px-4 py-3">Payment</th>
						<th class="px-4 py-3 text-right">Actions</th>
					</tr>
				</thead>
				<tbody>
					@forelse($rows as $invoice)
						<tr class="border-b border-slate-100 transition-colors hover:bg-slate-50/70 last:border-0">
							<td class="px-4 py-3 font-mono text-xs font-medium text-slate-800">{{ $invoice->invoice_number }}</td>
							<td class="px-4 py-3 whitespace-nowrap">{{ $invoice->invoice_date?->toDateString() }}</td>
							<td class="px-4 py-3">
								@if($invoice->branch)
									<p class="font-medium text-slate-800">{{ $invoice->branch->name }}</p>
								@else
									<span class="text-slate-400">—</span>
								@endif
							</td>
							<td class="px-4 py-3">
								@if($invoice->customer)
									<p class="font-medium text-slate-800">{{ $invoice->customer->name }}</p>
									<p class="text-xs text-slate-500">{{ $invoice->customer->phone }}</p>
								@else
									<span class="text-slate-400">—</span>
								@endif
							</td>
							<td class="px-4 py-3">{{ ucfirst($invoice->sale_type) }}</td>
							<td class="px-4 py-3 text-right tabular-nums">{{ number_format($invoice->grand_total, 2) }}</td>
							<td class="px-4 py-3 text-right tabular-nums text-emerald-700">{{ number_format($invoice->amount_paid, 2) }}</td>
							<td class="px-4 py-3 text-right tabular-nums">{{ number_format($invoice->amount_due, 2) }}</td>
							<td class="px-4 py-3">{{ ucfirst(str_replace('_', ' ', $invoice->payment_status)) }}</td>
							<td class="px-4 py-3 text-right">
								<a href="{{ route('sales.invoices.show', $invoice) }}" class="rounded border border-slate-300 px-2 py-1 text-xs">View</a>
							</td>
						</tr>
					@empty
						<tr>
							<td colspan="10" class="px-4 py-12 text-center text-sm font-medium text-slate-500 bg-slate-50/50">No posted sales match the current filters.</td>
						</tr>
					@endforelse
				</tbody>
			</table>
	</div>

	<div>{{ $rows->links() }}</div>
</div>
@endsection
