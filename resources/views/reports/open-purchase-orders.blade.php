@extends('layouts.app')
@section('title', 'Open purchase orders')
@section('page-title', 'Open purchase orders')
@section('content')
<div class="space-y-4">
	<p class="text-sm text-slate-600">Purchase orders in draft, approved, or partially received status (same meaning as “open” on the dashboard).</p>

	<x-filter-drawer>
<form method="get" action="{{ route('reports.open-purchase-orders') }}" class="flex flex-col gap-4">
		@include('reports.partials.open-purchase-orders-filter-fields', ['filters' => $filters, 'filterSuppliers' => $filterSuppliers, 'filterLocations' => $filterLocations])
		<div class="flex flex-wrap items-center gap-2 border-t border-slate-100 pt-3">
			<span class="text-xs font-medium text-slate-500">Export</span>
			<a target="_blank" rel="noopener noreferrer" href="{{ route('reports.print', array_merge(['slug' => 'open-purchase-orders'], request()->only(['q', 'status', 'supplier_id', 'location_id', 'date_from', 'date_to']))) }}" class="rounded-lg border border-slate-300 bg-white px-3 py-1.5 text-xs font-medium text-slate-800 hover:bg-slate-50">Print</a>
			<a href="{{ route('reports.export', array_merge(['slug' => 'open-purchase-orders', 'format' => 'xlsx'], request()->only(['q', 'status', 'supplier_id', 'location_id', 'date_from', 'date_to']))) }}" class="rounded-lg border border-slate-300 bg-white px-3 py-1.5 text-xs font-medium text-slate-800 hover:bg-slate-50">Excel</a>
		</div>
		<x-index-filter-actions :reset-url="route('reports.open-purchase-orders')" />
	</form>
</x-filter-drawer>

	<div class="grid gap-3 sm:grid-cols-2">
		<x-status-card label="Matching POs">
			{{ number_format($kpis['filtered']) }}
		</x-status-card>
		<x-status-card label="Grand total (filtered)">
			{{ number_format($kpis['filtered_amount'], 2) }}
		</x-status-card>
	</div>

	<div class="overflow-x-auto overflow-y-hidden rounded-2xl bg-white shadow-sm ring-1 ring-slate-200">
		<table class="min-w-full text-sm whitespace-nowrap">
				<thead class="bg-slate-50 border-b border-slate-200 text-left text-xs font-semibold uppercase tracking-wider text-slate-700">
					<tr>
						<th class="px-4 py-3">PO</th>
						<th class="px-4 py-3">Supplier</th>
						<th class="px-4 py-3">Destination</th>
						<th class="px-4 py-3">Status</th>
						<th class="px-4 py-3">Ordered</th>
						<th class="px-4 py-3 text-right">Total</th>
						<th class="px-4 py-3 text-right">Settled</th>
						<th class="px-4 py-3 text-right">Due</th>
						<th class="px-4 py-3 text-right">Actions</th>
					</tr>
				</thead>
				<tbody>
					@forelse($rows as $po)
						@php
							$supplierPaid = (float) ($po->payments_sum_amount ?? 0);
							$extraCosts = (float) ($po->additional_cost_amount ?? 0);
							$paidTowardPo = round($supplierPaid + $extraCosts, 2);
							$dueTowardPo = max(0, round((float) $po->total_amount - $paidTowardPo, 2));
						@endphp
						<tr class="border-b border-slate-100 transition-colors hover:bg-slate-50/70 last:border-0">
							<td class="px-4 py-3 font-mono text-xs font-medium">{{ $po->po_number }}</td>
							<td class="px-4 py-3">{{ $po->supplier?->name }}</td>
							<td class="px-4 py-3">{{ $po->destinationLocation?->name }}</td>
							<td class="px-4 py-3">{{ ucfirst(str_replace('_', ' ', $po->status)) }}</td>
							<td class="px-4 py-3 whitespace-nowrap">{{ $po->ordered_at?->toDateString() }}</td>
							<td class="px-4 py-3 text-right tabular-nums">{{ number_format((float) $po->total_amount, 2) }}</td>
							<td class="px-4 py-3 text-right tabular-nums">{{ number_format($paidTowardPo, 2) }}</td>
							<td class="px-4 py-3 text-right tabular-nums font-medium text-amber-800">{{ number_format($dueTowardPo, 2) }}</td>
							<td class="px-4 py-3 text-right">
								@can('view', $po)
									<a href="{{ route('purchase-orders.show', $po) }}" class="rounded border border-slate-300 px-2 py-1 text-xs">View</a>
								@endcan
							</td>
						</tr>
					@empty
						<tr><td colspan="9" class="px-4 py-12 text-center text-sm font-medium text-slate-500 bg-slate-50/50">No open purchase orders match the current filters.</td></tr>
					@endforelse
				</tbody>
			</table>
		</div>
	</div>

	<div>{{ $rows->links() }}</div>
</div>
@endsection
