@extends('layouts.app')
@section('title', 'Stock by Location')
@section('page-title', 'Stock by Location')
@section('content')
<div class="space-y-4">
	@php
		$groupedRows = collect($rows->items())->groupBy(fn ($row) => (string) $row->location_id);
	@endphp
	<x-filter-drawer>
<form method="get" action="{{ route('reports.stock-by-location') }}" class="flex flex-col gap-4">
		@include('reports.partials.stock-filter-fields', ['filters' => $filters, 'filterLocations' => $filterLocations])
		<div class="flex flex-wrap items-center gap-2 border-t border-slate-100 pt-3">
			<span class="text-xs font-medium text-slate-500">Export</span>
			<a target="_blank" rel="noopener noreferrer" href="{{ route('reports.print', array_merge(['slug' => 'stock-by-location'], request()->only(['q', 'location_id', 'stock_state', 'serialized', 'as_of_date', 'as_of_time']))) }}" class="rounded-lg border border-slate-300 bg-white px-3 py-1.5 text-xs font-medium text-slate-800 hover:bg-slate-50">Print</a>
			<a href="{{ route('reports.export', array_merge(['slug' => 'stock-by-location', 'format' => 'xlsx'], request()->only(['q', 'location_id', 'stock_state', 'serialized', 'as_of_date', 'as_of_time']))) }}" class="rounded-lg border border-slate-300 bg-white px-3 py-1.5 text-xs font-medium text-slate-800 hover:bg-slate-50">Excel</a>
		</div>
		<x-index-filter-actions :reset-url="route('reports.stock-by-location')" />
	</form>
</x-filter-drawer>

	@include('reports.partials.stock-as-of-notice', ['filters' => $filters])

	<div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
		<x-status-card label="Records">
			{{ $kpis['records'] }}
		</x-status-card>
		<x-status-card label="Locations">
			{{ $kpis['locations'] }}
		</x-status-card>
		<x-status-card label="Products">
			{{ $kpis['products'] }}
		</x-status-card>
		<x-status-card label="On Hand">
			{{ number_format($kpis['on_hand']) }}
		</x-status-card>
		<x-status-card label="Reserved">
			{{ number_format($kpis['reserved']) }}
		</x-status-card>
		<x-status-card label="Available">
			{{ number_format($kpis['available']) }}
		</x-status-card>
	</div>

	@forelse ($groupedRows as $locationId => $locationRows)
		@php
			$first = $locationRows->first();
			$locationOnHand = (int) $locationRows->sum(fn ($row) => (int) $row->on_hand_qty);
			$locationReserved = (int) $locationRows->sum(fn ($row) => (int) $row->reserved_qty);
			$locationAvailable = (int) $locationRows->sum(fn ($row) => (int) $row->available_qty);
		@endphp
		<div class="overflow-x-auto overflow-y-hidden rounded-2xl bg-white shadow-sm ring-1 ring-slate-200">
			<div class="flex flex-wrap items-center justify-between gap-3 border-b border-slate-200 bg-slate-50 px-4 py-3">
				<div>
					<p class="text-sm font-semibold text-slate-800">{{ $first->location_name }}</p>
					<p class="text-xs text-slate-500">Location ID: {{ $locationId }} · {{ $locationRows->count() }} products on this page</p>
				</div>
				<div class="flex flex-wrap items-center gap-3 text-xs">
					<span class="rounded-full bg-slate-100 px-2.5 py-1 text-slate-700">On Hand: {{ number_format($locationOnHand) }}</span>
					<span class="rounded-full bg-slate-100 px-2.5 py-1 text-slate-700">Reserved: {{ number_format($locationReserved) }}</span>
					<span class="rounded-full px-2.5 py-1 {{ $locationAvailable <= 0 ? 'bg-rose-100 text-rose-700' : 'bg-emerald-100 text-emerald-700' }}">Available: {{ number_format($locationAvailable) }}</span>
				</div>
			</div>
			<div class="overflow-x-auto">
				<table class="min-w-full text-sm whitespace-nowrap">
					<thead class="bg-white text-left text-slate-600">
						<tr>
							<th class="px-4 py-3">Product</th>
							<th class="px-4 py-3 text-right">On Hand</th>
							<th class="px-4 py-3 text-right">Reserved</th>
							<th class="px-4 py-3 text-right">Available</th>
							<th class="px-4 py-3">Type</th>
							<th class="px-4 py-3 text-right">Reorder Level</th>
							<th class="px-4 py-3">Updated</th>
						</tr>
					</thead>
					<tbody>
						@foreach($locationRows as $row)
							<tr class="border-b border-slate-100 transition-colors hover:bg-slate-50/70 last:border-0">
								<td class="px-4 py-3">
									<p class="font-medium text-slate-800"><a href="{{ route('products.show', $row->product_id) }}" class="hover:text-indigo-700 hover:underline">{{ $row->product_sku }} - {{ $row->product_name }}</a></p>
									<p class="text-xs text-slate-500">Product ID: {{ $row->product_id }}</p>
								</td>
								<td class="px-4 py-3 text-right">{{ number_format($row->on_hand_qty) }}</td>
								<td class="px-4 py-3 text-right">{{ number_format($row->reserved_qty) }}</td>
								<td class="px-4 py-3 text-right font-semibold {{ (int) $row->available_qty <= 0 ? 'text-rose-700' : 'text-emerald-700' }}">{{ number_format($row->available_qty) }}</td>
								<td class="px-4 py-3">{{ (int) $row->is_serialized === 1 ? 'Serialized' : 'Non-serialized' }}</td>
								<td class="px-4 py-3 text-right">{{ number_format($row->reorder_level ?? 0) }}</td>
								<td class="px-4 py-3 text-xs text-slate-600">{{ $row->updated_at ? \Illuminate\Support\Carbon::parse($row->updated_at)->format('Y-m-d H:i') : '—' }}</td>
							</tr>
						@endforeach
					</tbody>
				</table>
			</div>
		</div>
	@empty
		<div class="overflow-x-auto overflow-y-hidden rounded-2xl bg-white shadow-sm ring-1 ring-slate-200">
			<div class="px-4 py-6 text-center text-slate-500">No stock balances found for current filters.</div>
		</div>
	@endforelse

	<x-pagination-limit-selector />
	<div>{{ $rows->links() }}</div>
</div>
@endsection
