@extends('layouts.app')
@section('title', 'Branch In-Stock Pricing')
@section('page-title', 'Branch In-Stock Pricing')
@section('content')
<div class="space-y-4">
	@php
		$groupedByLocation = collect($rows->items())->groupBy(fn ($row) => (string) $row->location_id);
	@endphp
	<x-filter-drawer>
		<form method="get" action="{{ route('reports.branch-in-stock-pricing') }}" class="flex flex-col gap-4">
			@include('reports.partials.branch-in-stock-pricing-filter-fields', [
				'filters' => $filters,
				'filterLocations' => $filterLocations,
				'filterCategories' => $filterCategories,
			])
			<div class="flex flex-wrap items-center gap-2 border-t border-slate-100 pt-3">
				<span class="text-xs font-medium text-slate-500">Export</span>
				<a target="_blank" rel="noopener noreferrer" href="{{ route('reports.print', array_merge(['slug' => 'branch-in-stock-pricing'], request()->only(['q', 'location_id', 'category_id', 'as_of_date', 'as_of_time']))) }}" class="rounded-lg border border-slate-300 bg-white px-3 py-1.5 text-xs font-medium text-slate-800 hover:bg-slate-50">Print</a>
				<a href="{{ route('reports.export', array_merge(['slug' => 'branch-in-stock-pricing', 'format' => 'xlsx'], request()->only(['q', 'location_id', 'category_id', 'as_of_date', 'as_of_time']))) }}" class="rounded-lg border border-slate-300 bg-white px-3 py-1.5 text-xs font-medium text-slate-800 hover:bg-slate-50">Excel</a>
			</div>
			<x-index-filter-actions :reset-url="route('reports.branch-in-stock-pricing')" />
		</form>
	</x-filter-drawer>

	@include('reports.partials.stock-as-of-notice', ['filters' => $filters])

	<div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
		<x-status-card label="Records">{{ $kpis['records'] }}</x-status-card>
		<x-status-card label="Branches">{{ $kpis['locations'] }}</x-status-card>
		<x-status-card label="Categories">{{ $kpis['categories'] }}</x-status-card>
		<x-status-card label="Products">{{ $kpis['products'] }}</x-status-card>
		<x-status-card label="On Hand">{{ number_format($kpis['on_hand']) }}</x-status-card>
		<x-status-card label="MRP Value">{{ number_format($kpis['mrp_value'], 2) }}</x-status-card>
	</div>

	<p class="text-xs text-slate-500">Only products with sellable stock at the branch are shown. Serialized products use in-stock serial counts (ledger-only balances without serials are excluded). Damaged units are excluded. MRP uses default sale price. Max discount uses the product limit, or the global setting when the product limit is unset. If neither is set, no discount is allowed (0%). Amount after discount = MRP minus max discount amount.</p>

	@forelse ($groupedByLocation as $locationId => $locationRows)
		@php
			$first = $locationRows->first();
			$groupedByCategory = $locationRows->groupBy(fn ($row) => (string) ($row->category_id ?? '0'));
			$locationOnHand = (int) $locationRows->sum(fn ($row) => (int) $row->on_hand_qty);
		@endphp
		<div class="overflow-x-auto overflow-y-hidden rounded-2xl bg-white shadow-sm ring-1 ring-slate-200">
			<div class="flex flex-wrap items-center justify-between gap-3 border-b border-slate-200 bg-slate-50 px-4 py-3">
				<div>
					<p class="text-sm font-semibold text-slate-800">{{ $first->location_name }}</p>
					<p class="text-xs text-slate-500">{{ $locationRows->count() }} products on this page · On hand: {{ number_format($locationOnHand) }}</p>
				</div>
			</div>
			@foreach ($groupedByCategory as $categoryRows)
				@php $categoryFirst = $categoryRows->first(); @endphp
				<div class="border-b border-slate-100 last:border-0">
					<div class="bg-slate-50/80 px-4 py-2 text-xs font-semibold uppercase tracking-wide text-slate-600">
						{{ $categoryFirst->category_name ?: 'Uncategorized' }}
						<span class="font-normal normal-case text-slate-500">· {{ $categoryRows->count() }} products</span>
					</div>
					<div class="overflow-x-auto">
						<table class="min-w-full text-sm whitespace-nowrap">
							<thead class="bg-white text-left text-slate-600">
								<tr>
									<th class="px-4 py-3">Product</th>
									<th class="px-4 py-3 text-right">On hand</th>
									<th class="px-4 py-3 text-right">Available</th>
									<th class="px-4 py-3 text-right">MRP</th>
									<th class="px-4 py-3 text-right">Max disc. %</th>
									<th class="px-4 py-3 text-right">Max disc. amount</th>
									<th class="px-4 py-3 text-right">After discount</th>
								</tr>
							</thead>
							<tbody>
								@foreach ($categoryRows as $row)
									<tr class="border-b border-slate-100 transition-colors hover:bg-slate-50/70 last:border-0">
										<td class="px-4 py-3">
											<p class="font-medium text-slate-800">{{ $row->product_sku }} - {{ $row->product_name }}</p>
										</td>
										<td class="px-4 py-3 text-right">{{ number_format((int) $row->on_hand_qty) }}</td>
										<td class="px-4 py-3 text-right font-semibold text-emerald-700">{{ number_format((int) $row->available_qty) }}</td>
										<td class="px-4 py-3 text-right">{{ number_format((float) $row->mrp, 2) }}</td>
										<td class="px-4 py-3 text-right">{{ number_format((float) $row->max_discount_percent, 2) }}%</td>
										<td class="px-4 py-3 text-right">{{ number_format((float) $row->max_discount_amount, 2) }}</td>
										<td class="px-4 py-3 text-right font-semibold text-slate-900">{{ number_format((float) $row->amount_after_discount, 2) }}</td>
									</tr>
								@endforeach
							</tbody>
						</table>
					</div>
				</div>
			@endforeach
		</div>
	@empty
		<div class="overflow-x-auto overflow-y-hidden rounded-2xl bg-white shadow-sm ring-1 ring-slate-200">
			<div class="px-4 py-6 text-center text-slate-500">No in-stock products found for current filters.</div>
		</div>
	@endforelse

	<x-pagination-limit-selector />
	<div>{{ $rows->links() }}</div>
</div>
@endsection
