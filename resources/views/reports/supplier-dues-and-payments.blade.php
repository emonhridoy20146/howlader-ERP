@extends('layouts.app')
@section('title', 'Supplier dues & payments')
@section('page-title', 'Supplier dues & payments')
@section('content')
<div class="space-y-4">
	<p class="flex justify-end">
		<a href="{{ route('reports.index', ['report' => 'supplier-dues-and-payments']) }}" class="text-sm font-medium text-emerald-600 hover:text-emerald-700">← Back to reports</a>
	</p>
	<p class="text-sm text-slate-600">
		Per supplier: remaining balance on <span class="font-medium">non-draft</span>, <span class="font-medium">non-cancelled</span> purchase orders versus <span class="font-medium">payments recorded</span> on those orders.
		Optional warehouse filter matches PO destination.
	</p>

	<form method="get" action="{{ route('reports.supplier-dues-and-payments') }}" class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm ring-1 ring-slate-200">
		@include('reports.partials.supplier-dues-and-payments-filter-fields', ['filters' => $filters, 'filterWarehouseLocations' => $filterWarehouseLocations])
		<div class="mt-4 flex flex-wrap items-center gap-2 border-t border-slate-100 pt-3">
			<span class="text-xs font-medium text-slate-500">Export</span>
			@php
				$exportQuery = array_filter(['q' => $filters['q'], 'location_id' => $filters['location_id']], static fn ($v) => $v !== null && $v !== '');
			@endphp
			<a target="_blank" rel="noopener noreferrer" href="{{ route('reports.print', array_merge(['slug' => 'supplier-dues-and-payments'], $exportQuery)) }}" class="rounded-lg border border-slate-300 bg-white px-3 py-1.5 text-xs font-medium text-slate-800 hover:bg-slate-50">Print</a>
			<a href="{{ route('reports.export', array_merge(['slug' => 'supplier-dues-and-payments', 'format' => 'xlsx'], $exportQuery)) }}" class="rounded-lg border border-slate-300 bg-white px-3 py-1.5 text-xs font-medium text-slate-800 hover:bg-slate-50">Excel</a>
		</div>
		<div class="mt-4 flex flex-wrap gap-2">
			<button type="submit" class="rounded-lg bg-slate-800 px-4 py-2 text-sm font-semibold text-white hover:bg-slate-700">Apply</button>
			<a href="{{ route('reports.supplier-dues-and-payments') }}" class="rounded-lg border border-slate-300 bg-white px-4 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50">Reset</a>
		</div>
	</form>

	<div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
		<x-status-card label="Suppliers (filtered)">
			{{ number_format($kpis['suppliers']) }}
		</x-status-card>
		<x-status-card label="PO lines">
			{{ number_format($kpis['po_count']) }}
		</x-status-card>
		<x-status-card label="Total due (filtered)">
			<span class="text-rose-700">{{ number_format($kpis['total_due'], 2) }}</span>
		</x-status-card>
		<x-status-card label="Payments recorded (filtered)">
			<span class="text-emerald-700">{{ number_format($kpis['total_paid'], 2) }}</span>
		</x-status-card>
	</div>

	<div class="overflow-x-auto overflow-y-hidden rounded-2xl bg-white shadow-sm ring-1 ring-slate-200">
		<table class="min-w-full text-sm whitespace-nowrap">
			<thead class="border-b border-slate-200 bg-slate-50 text-left text-xs font-semibold uppercase tracking-wider text-slate-700">
				<tr>
					<th class="px-4 py-3">Supplier</th>
					<th class="px-4 py-3">Phone</th>
					<th class="px-4 py-3 text-right">POs</th>
					<th class="px-4 py-3 text-right">Amount due</th>
					<th class="px-4 py-3 text-right">Payments recorded</th>
				</tr>
			</thead>
			<tbody>
				@forelse($rows as $row)
					<tr class="border-b border-slate-100 transition-colors hover:bg-slate-50/70 last:border-0">
						<td class="px-4 py-3 font-medium text-slate-800">{{ $row->name }}</td>
						<td class="px-4 py-3 text-slate-600">{{ $row->phone ?: '—' }}</td>
						<td class="px-4 py-3 text-right tabular-nums">{{ number_format((int) $row->po_count) }}</td>
						<td class="px-4 py-3 text-right tabular-nums font-semibold text-rose-700">{{ number_format((float) $row->total_due, 2) }}</td>
						<td class="px-4 py-3 text-right tabular-nums text-emerald-800">{{ number_format((float) $row->total_paid, 2) }}</td>
					</tr>
				@empty
					<tr>
						<td colspan="5" class="bg-slate-50/50 px-4 py-12 text-center text-sm font-medium text-slate-500">No purchase order balances match the filters.</td>
					</tr>
				@endforelse
			</tbody>
			@if($rows->count() > 0)
			<tfoot class="border-t-2 border-slate-200 bg-slate-50/80 font-semibold text-slate-800">
				<tr>
					<td class="px-4 py-3" colspan="3">Totals (all matching suppliers)</td>
					<td class="px-4 py-3 text-right tabular-nums text-rose-700">{{ number_format($kpis['total_due'], 2) }}</td>
					<td class="px-4 py-3 text-right tabular-nums text-emerald-800">{{ number_format($kpis['total_paid'], 2) }}</td>
				</tr>
			</tfoot>
			@endif
		</table>
	</div>

	<div>{{ $rows->links() }}</div>
</div>
@endsection
