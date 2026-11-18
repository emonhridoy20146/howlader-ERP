@extends('layouts.app')
@section('title', 'Invoice outstanding by customer')
@section('page-title', 'Invoice outstanding by customer')
@section('content')
<div class="space-y-4">
	<p class="flex justify-end">
		<a href="{{ route('reports.index', ['report' => 'invoice-outstanding-by-customer']) }}" class="text-sm font-medium text-emerald-600 hover:text-emerald-700">← Back to reports</a>
	</p>
	<p class="text-sm text-slate-600">
		Open <span class="font-medium">posted / active</span> sales invoices with an unpaid balance: one row per <span class="font-medium">invoice</span>, ordered by <span class="font-medium">invoice date</span> within each branch. Shows item names, grand total, and the invoice&#8217;s <span class="font-medium">balance due</span>. Branch KPIs summarise customers × branch and total due; leave branch blank for every location you can access.
	</p>

	<form method="get" action="{{ route('reports.invoice-outstanding-by-customer') }}" class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm ring-1 ring-slate-200">
		@include('reports.partials.invoice-outstanding-by-customer-filter-fields', ['filters' => $filters, 'filterBranches' => $filterBranches])
		<div class="mt-4 flex flex-wrap items-center gap-2 border-t border-slate-100 pt-3">
			<span class="text-xs font-medium text-slate-500">Export</span>
			@php
				$exportQuery = array_filter(['q' => $filters['q'], 'branch_id' => $filters['branch_id']], static fn ($v) => $v !== null && $v !== '');
			@endphp
			<a target="_blank" rel="noopener noreferrer" href="{{ route('reports.print', array_merge(['slug' => 'invoice-outstanding-by-customer'], $exportQuery)) }}" class="rounded-lg border border-slate-300 bg-white px-3 py-1.5 text-xs font-medium text-slate-800 hover:bg-slate-50">Print</a>
			<a href="{{ route('reports.export', array_merge(['slug' => 'invoice-outstanding-by-customer', 'format' => 'xlsx'], $exportQuery)) }}" class="rounded-lg border border-slate-300 bg-white px-3 py-1.5 text-xs font-medium text-slate-800 hover:bg-slate-50">Excel</a>
		</div>
		<div class="mt-4 flex flex-wrap gap-2">
			<button type="submit" class="rounded-lg bg-slate-800 px-4 py-2 text-sm font-semibold text-white hover:bg-slate-700">Apply</button>
			<a href="{{ route('reports.invoice-outstanding-by-customer') }}" class="rounded-lg border border-slate-300 bg-white px-4 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50">Reset</a>
		</div>
	</form>

	<div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
		<x-status-card label="Invoices">
			{{ number_format($kpis['invoice_rows'] ?? 0) }}
		</x-status-card>
		<x-status-card label="Branches (in scope)">
			{{ number_format($branchTotals->count()) }}
		</x-status-card>
		<x-status-card label="Distinct customers">
			{{ number_format($kpis['distinct_customers']) }}
		</x-status-card>
		<x-status-card label="Total amount due (filtered)">
			<span class="text-rose-700">{{ number_format($kpis['total_due'], 2) }}</span>
		</x-status-card>
	</div>

	@if ($branchTotals->isNotEmpty())
		<div class="overflow-x-auto overflow-y-hidden rounded-2xl bg-white shadow-sm ring-1 ring-slate-200">
			<h3 class="border-b border-slate-200 bg-slate-50 px-4 py-3 text-sm font-semibold text-slate-800">Totals by branch</h3>
			<table class="min-w-full text-sm whitespace-nowrap">
				<thead class="border-b border-slate-200 bg-slate-50 text-left text-xs font-semibold uppercase tracking-wider text-slate-700">
					<tr>
						<th class="px-4 py-3">Branch</th>
						<th class="px-4 py-3 text-right">Cust×br rows</th>
						<th class="px-4 py-3 text-right">Open inv.</th>
						<th class="px-4 py-3 text-right">Amount due</th>
					</tr>
				</thead>
				<tbody>
					@foreach ($branchTotals as $b)
						<tr class="border-b border-slate-100 transition-colors hover:bg-slate-50/70 last:border-0">
							<td class="px-4 py-3">
								<p class="font-medium text-slate-800">{{ $b->branch_name }}</p>
								<p class="text-xs text-slate-500 font-mono">{{ $b->branch_code }}</p>
							</td>
							<td class="px-4 py-3 text-right tabular-nums">{{ number_format((int) $b->customer_branch_rows) }}</td>
							<td class="px-4 py-3 text-right tabular-nums">{{ number_format((int) $b->open_invoice_count) }}</td>
							<td class="px-4 py-3 text-right tabular-nums font-semibold text-rose-700">{{ number_format((float) $b->total_due, 2) }}</td>
						</tr>
					@endforeach
				</tbody>
				<tfoot class="border-t-2 border-slate-200 bg-slate-50/80 font-semibold text-slate-900">
					<tr>
						<td class="px-4 py-3">All (filtered)</td>
						<td class="px-4 py-3 text-right tabular-nums">{{ number_format((int) $branchTotals->sum('customer_branch_rows')) }}</td>
						<td class="px-4 py-3 text-right tabular-nums">{{ number_format((int) $branchTotals->sum('open_invoice_count')) }}</td>
						<td class="px-4 py-3 text-right tabular-nums text-rose-700">{{ number_format((float) $kpis['total_due'], 2) }}</td>
					</tr>
				</tfoot>
			</table>
		</div>
	@endif

	<div class="overflow-x-auto overflow-y-hidden rounded-2xl bg-white shadow-sm ring-1 ring-slate-200">
		<h3 class="border-b border-slate-200 bg-slate-50 px-4 py-3 text-sm font-semibold text-slate-800">Open invoices (by location, then invoice date)</h3>
		<table class="min-w-full text-sm">
			<thead class="border-b border-slate-200 bg-slate-50 text-left text-xs font-semibold uppercase tracking-wider text-slate-700">
				<tr>
					<th class="px-4 py-3 whitespace-nowrap">Invoice</th>
					<th class="px-4 py-3 whitespace-nowrap">Invoice date</th>
					<th class="px-4 py-3">Customer</th>
					<th class="px-4 py-3 whitespace-nowrap">Phone</th>
					<th class="px-4 py-3 min-w-[12rem]">Items</th>
					<th class="px-4 py-3 text-right whitespace-nowrap">Grand total</th>
					<th class="px-4 py-3 text-right whitespace-nowrap">Balance due</th>
				</tr>
			</thead>
			<tbody>
				@php $prevBranchId = null; @endphp
				@if ($rows->count() === 0)
					<tr>
						<td colspan="7" class="bg-slate-50/50 px-4 py-12 text-center text-sm font-medium text-slate-500">No open invoices match the filters.</td>
					</tr>
				@else
					@foreach ($rows as $row)
						@if ($prevBranchId !== $row->branch_id)
							<tr class="border-t border-slate-200 bg-slate-100/90">
								<td colspan="7" class="px-4 py-2.5">
									<p class="text-[10px] font-semibold uppercase tracking-wider text-slate-500">Location</p>
									<p class="font-medium text-slate-900">{{ $row->branch_name }} <span class="font-mono text-xs font-normal text-slate-500">· {{ $row->branch_code }}</span></p>
								</td>
							</tr>
							@php $prevBranchId = $row->branch_id; @endphp
						@endif
						<tr class="border-b border-slate-100 transition-colors hover:bg-slate-50/70 last:border-0">
							<td class="px-4 py-3 font-mono text-xs text-slate-800 whitespace-nowrap">{{ $row->invoice_number }}</td>
							<td class="px-4 py-3 text-slate-700 whitespace-nowrap">{{ \Illuminate\Support\Carbon::parse($row->invoice_date)->format('Y-m-d') }}</td>
							<td class="px-4 py-3">
								<p class="font-mono text-xs text-slate-500">{{ $row->customer_code }}</p>
								<p class="font-medium text-slate-800">{{ $row->customer_name }}</p>
							</td>
							<td class="px-4 py-3 text-slate-600 whitespace-nowrap">{{ $row->customer_phone ?: '—' }}</td>
							<td class="px-4 py-3 text-slate-800">{{ $row->item_names ?: '—' }}</td>
							<td class="px-4 py-3 text-right tabular-nums whitespace-nowrap">{{ number_format((float) $row->grand_total, 2) }}</td>
							<td class="px-4 py-3 text-right tabular-nums font-semibold text-rose-700 whitespace-nowrap">{{ number_format((float) $row->invoice_amount_due, 2) }}</td>
						</tr>
					@endforeach
				@endif
			</tbody>
		</table>
	</div>

	<div>{{ $rows->links() }}</div>
</div>
@endsection
