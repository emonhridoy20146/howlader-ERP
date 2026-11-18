@extends('layouts.app')
@section('title', 'Daily sales by branch')
@section('page-title', 'Daily sales by branch')
@section('content')
@php
    $productTotals = $productTotals ?? ['qty_sold' => 0, 'line_total' => 0.0];
@endphp
<div class="space-y-4">
	<p class="flex justify-end">
		<a href="{{ route('reports.index', ['report' => 'sales-by-day-branch']) }}" class="text-sm font-medium text-emerald-600 hover:text-emerald-700">← Back to reports</a>
	</p>
	<p class="text-sm text-slate-600">
		Product line totals by <span class="font-medium">invoice date</span> and <span class="font-medium">branch</span> for <span class="font-medium">posted</span> and <span class="font-medium">active</span> invoices. Summary cards use <span class="font-medium">invoice-level</span> amounts (grand total, paid, due).
	</p>

	<form method="get" action="{{ route('reports.sales-by-day-branch') }}" class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm ring-1 ring-slate-200">
		@include('reports.partials.sales-by-day-branch-filter-fields', ['filters' => $filters, 'filterBranches' => $filterBranches])
		<div class="mt-4 flex flex-wrap items-center gap-2 border-t border-slate-100 pt-3">
			<span class="text-xs font-medium text-slate-500">Export</span>
			@php
				$exportQuery = array_filter([
					'date_from' => $filters['date_from'],
					'date_to' => $filters['date_to'],
					'branch_id' => $filters['branch_id'],
				], static fn ($v) => $v !== null && $v !== '');
			@endphp
			<a target="_blank" rel="noopener noreferrer" href="{{ route('reports.print', array_merge(['slug' => 'sales-by-day-branch'], $exportQuery)) }}" class="rounded-lg border border-slate-300 bg-white px-3 py-1.5 text-xs font-medium text-slate-800 hover:bg-slate-50">Print</a>
			<a href="{{ route('reports.export', array_merge(['slug' => 'sales-by-day-branch', 'format' => 'xlsx'], $exportQuery)) }}" class="rounded-lg border border-slate-300 bg-white px-3 py-1.5 text-xs font-medium text-slate-800 hover:bg-slate-50">Excel</a>
		</div>
		<div class="mt-4 flex flex-wrap gap-2">
			<button type="submit" class="rounded-lg bg-slate-800 px-4 py-2 text-sm font-semibold text-white hover:bg-slate-700">Apply</button>
			<a href="{{ route('reports.sales-by-day-branch') }}" class="rounded-lg border border-slate-300 bg-white px-4 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50">Reset</a>
		</div>
	</form>

	<div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-5">
		<x-status-card label="Product lines">
			{{ number_format($kpis['day_rows']) }}
		</x-status-card>
		<x-status-card label="Invoices">
			{{ number_format($kpis['invoice_count']) }}
		</x-status-card>
		<x-status-card label="Grand total">
			{{ number_format($kpis['grand_total'], 2) }}
		</x-status-card>
		<x-status-card label="Amount paid">
			{{ number_format($kpis['amount_paid'], 2) }}
		</x-status-card>
		<x-status-card label="Amount due">
			<span class="text-amber-800">{{ number_format($kpis['amount_due'], 2) }}</span>
		</x-status-card>
	</div>

	<div class="overflow-x-auto overflow-y-hidden rounded-2xl bg-white shadow-sm ring-1 ring-slate-200">
		<table class="min-w-full text-sm whitespace-nowrap">
			<thead class="border-b border-slate-200 bg-slate-50 text-left text-xs font-semibold uppercase tracking-wider text-slate-700">
				<tr>
					<th class="px-4 py-3">Date</th>
					<th class="px-4 py-3">Product</th>
					<th class="px-4 py-3 text-right">Qty</th>
					<th class="px-4 py-3 text-right">Line total</th>
					<th class="px-4 py-3 text-right">Invoices</th>
				</tr>
			</thead>
			<tbody>
				@php $lastBranchId = null; @endphp
				@forelse($rows as $row)
					@if($lastBranchId !== (int) $row->branch_id)
						@php $lastBranchId = (int) $row->branch_id; @endphp
						<tr class="bg-slate-100">
							<td colspan="5" class="px-4 py-2.5 text-sm font-semibold text-slate-900">
								{{ $row->branch_name }}
								@if($row->branch_code)
									<span class="font-normal text-slate-500">({{ $row->branch_code }})</span>
								@endif
							</td>
						</tr>
					@endif
					<tr class="border-b border-slate-100 transition-colors hover:bg-slate-50/70 last:border-0">
						<td class="px-4 py-3 tabular-nums text-slate-800">{{ $row->invoice_date }}</td>
						<td class="px-4 py-3 text-slate-800">{{ $row->product_name }}</td>
						<td class="px-4 py-3 text-right tabular-nums">{{ number_format((int) $row->qty_sold) }}</td>
						<td class="px-4 py-3 text-right tabular-nums">{{ number_format((float) $row->line_total, 2) }}</td>
						<td class="px-4 py-3 text-right tabular-nums">{{ number_format((int) $row->invoice_count) }}</td>
					</tr>
				@empty
					<tr>
						<td colspan="5" class="bg-slate-50/50 px-4 py-12 text-center text-sm font-medium text-slate-500">No invoices match the filters.</td>
					</tr>
				@endforelse
			</tbody>
			@if($rows->count() > 0)
			<tfoot class="border-t-2 border-slate-200 bg-slate-50/80 font-semibold text-slate-800">
				<tr>
					<td class="px-4 py-3" colspan="2">Totals (product lines)</td>
					<td class="px-4 py-3 text-right tabular-nums">{{ number_format($productTotals['qty_sold']) }}</td>
					<td class="px-4 py-3 text-right tabular-nums">{{ number_format($productTotals['line_total'], 2) }}</td>
					<td class="px-4 py-3 text-right tabular-nums text-slate-500">—</td>
				</tr>
				<tr class="border-t border-slate-200 text-sm font-normal">
					<td class="px-4 py-3" colspan="3">Invoices (unique) · Grand total · Paid · Due</td>
					<td class="px-4 py-3 text-right tabular-nums" colspan="2">
						<span class="tabular-nums">{{ number_format($kpis['invoice_count']) }}</span>
						<span class="mx-1 text-slate-300">·</span>
						<span class="tabular-nums">{{ number_format($kpis['grand_total'], 2) }}</span>
						<span class="mx-1 text-slate-300">·</span>
						<span class="tabular-nums text-emerald-800">{{ number_format($kpis['amount_paid'], 2) }}</span>
						<span class="mx-1 text-slate-300">·</span>
						<span class="tabular-nums text-amber-800">{{ number_format($kpis['amount_due'], 2) }}</span>
					</td>
				</tr>
			</tfoot>
			@endif
		</table>
	</div>

	<div>{{ $rows->links() }}</div>
</div>
@endsection
