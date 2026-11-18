@extends('layouts.app')
@section('title', 'Low stock')
@section('page-title', 'Low stock')
@section('content')
<div class="space-y-4">
	<p class="text-sm text-slate-600">Products at or below reorder level (reorder level must be greater than zero). Scoped to your location context.</p>

	<x-filter-drawer>
<form method="get" action="{{ route('reports.low-stock') }}" class="flex flex-col gap-4">
		@include('reports.partials.low-stock-filter-fields', ['filters' => $filters, 'filterLocations' => $filterLocations])
		<div class="flex flex-wrap items-center gap-2 border-t border-slate-100 pt-3">
			<span class="text-xs font-medium text-slate-500">Export</span>
			<a target="_blank" rel="noopener noreferrer" href="{{ route('reports.print', array_merge(['slug' => 'low-stock'], request()->only(['q', 'location_id', 'as_of_date', 'as_of_time']))) }}" class="rounded-lg border border-slate-300 bg-white px-3 py-1.5 text-xs font-medium text-slate-800 hover:bg-slate-50">Print</a>
			<a href="{{ route('reports.export', array_merge(['slug' => 'low-stock', 'format' => 'xlsx'], request()->only(['q', 'location_id', 'as_of_date', 'as_of_time']))) }}" class="rounded-lg border border-slate-300 bg-white px-3 py-1.5 text-xs font-medium text-slate-800 hover:bg-slate-50">Excel</a>
		</div>
		<x-index-filter-actions :reset-url="route('reports.low-stock')" />
	</form>
</x-filter-drawer>

	@include('reports.partials.stock-as-of-notice', ['filters' => $filters])

	<x-status-card label="Low-stock positions">
			{{ number_format($kpis['positions']) }}
		</x-status-card>

	<div class="overflow-x-auto overflow-y-hidden rounded-2xl bg-white shadow-sm ring-1 ring-slate-200">
		<table class="min-w-full text-sm whitespace-nowrap">
				<thead class="bg-slate-50 border-b border-slate-200 text-left text-xs font-semibold uppercase tracking-wider text-slate-700">
					<tr>
						<th class="px-4 py-3">Product</th>
						<th class="px-4 py-3">Location</th>
						<th class="px-4 py-3 text-right">Reorder</th>
						<th class="px-4 py-3 text-right">On hand</th>
						<th class="px-4 py-3 text-right">Reserved</th>
						<th class="px-4 py-3 text-right">Available</th>
					</tr>
				</thead>
				<tbody>
					@forelse($rows as $row)
						<tr class="border-b border-slate-100 transition-colors hover:bg-slate-50/70 last:border-0">
							<td class="px-4 py-3">
								<p class="font-medium text-slate-800">{{ $row->product_sku }} — {{ $row->product_name }}</p>
							</td>
							<td class="px-4 py-3">
								<p class="font-medium text-slate-800">{{ $row->location_name }}</p>
							</td>
							<td class="px-4 py-3 text-right tabular-nums">{{ number_format((int) $row->reorder_level) }}</td>
							<td class="px-4 py-3 text-right tabular-nums">{{ number_format((int) $row->on_hand_qty) }}</td>
							<td class="px-4 py-3 text-right tabular-nums">{{ number_format((int) $row->reserved_qty) }}</td>
							<td class="px-4 py-3 text-right tabular-nums font-semibold text-amber-800">{{ number_format((int) $row->available_qty) }}</td>
						</tr>
					@empty
						<tr><td colspan="6" class="px-4 py-12 text-center text-sm font-medium text-slate-500 bg-slate-50/50">No low-stock rows for current filters.</td></tr>
					@endforelse
				</tbody>
			</table>
		</div>
	</div>

	<div>{{ $rows->links() }}</div>
</div>
@endsection
