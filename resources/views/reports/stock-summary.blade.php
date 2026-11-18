@extends('layouts.app')
@section('title', 'Stock summary')
@section('page-title', 'Stock summary')
@section('content')
@php
	$exportQuery = request()->only(['q', 'location_id', 'stock_state', 'serialized', 'product_id', 'category_id', 'brand_id', 'as_of_date', 'as_of_time']);
	$scopeAny = ($filters['product_id'] ?? '') !== '' || ($filters['category_id'] ?? '') !== '' || ($filters['brand_id'] ?? '') !== '';
	$pageRows = $rows instanceof \Illuminate\Pagination\AbstractPaginator ? $rows->getCollection() : collect($rows ?? []);
	$groupedByBranch = $pageRows->groupBy(fn ($row) => (string) ($row->location_id ?? 0));
@endphp
<div class="space-y-4">
	<x-filter-drawer>
<form method="get" action="{{ route('reports.stock-summary') }}" class="flex flex-col gap-4">
		@include('reports.partials.stock-summary-filter-fields', [
			'filters' => $filters,
			'filterLocations' => $filterLocations,
			'filterProducts' => $filterProducts,
			'filterCategories' => $filterCategories,
			'filterBrands' => $filterBrands,
		])
		<x-index-filter-actions :reset-url="route('reports.stock-summary')" />
	</form>
</x-filter-drawer>

	<div class="flex flex-wrap items-center justify-end gap-2">
		<a target="_blank" rel="noopener noreferrer" href="{{ route('reports.print', array_merge(['slug' => 'stock-summary'], $exportQuery)) }}" class="inline-flex justify-center rounded-lg border border-slate-300 bg-white px-4 py-2 text-sm font-medium text-slate-800 shadow-sm hover:bg-slate-50">Print</a>
		<a href="{{ route('reports.export', array_merge(['slug' => 'stock-summary', 'format' => 'xlsx'], $exportQuery)) }}" class="inline-flex justify-center rounded-lg border border-slate-300 bg-white px-4 py-2 text-sm font-medium text-slate-800 shadow-sm hover:bg-slate-50">Excel</a>
	</div>

	@if ($scopeAny)
		<div class="rounded-xl border border-sky-200 bg-sky-50 px-4 py-3 text-sm text-sky-900">
			Scoped to the selected product, category, and/or brand (combined with location, stock state, and search).
		</div>
	@endif

	@include('reports.partials.stock-as-of-notice', ['filters' => $filters])

	<div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4 xl:grid-cols-8">
		<x-status-card label="Branches">{{ number_format($kpis['branches']) }}</x-status-card>
		<x-status-card label="Categories">{{ number_format($kpis['categories']) }}</x-status-card>
		<x-status-card label="Products">{{ number_format($kpis['products']) }}</x-status-card>
		<x-status-card label="On hand">{{ number_format($kpis['on_hand']) }}</x-status-card>
		<x-status-card label="Reserved">{{ number_format($kpis['reserved']) }}</x-status-card>
		<x-status-card label="Available">{{ number_format($kpis['available']) }}</x-status-card>
		<x-status-card label="Total stock value">{{ number_format($kpis['total_stock_value'], 2) }}</x-status-card>
		<x-status-card label="Value at cost">{{ number_format($kpis['value_at_cost'], 2) }}</x-status-card>
	</div>

	<p class="text-xs text-slate-500">Products are listed by branch. A category filter includes every product in that category and its subcategories. Total stock value = on hand × default sale price. Value at cost = on hand × default purchase price.</p>

	@if ($pageRows->isEmpty())
		<div class="overflow-x-auto overflow-y-hidden rounded-2xl bg-white shadow-sm ring-1 ring-slate-200">
			<div class="px-4 py-10 text-center text-sm text-slate-500">No stock found for current filters.</div>
		</div>
	@else
		<div class="space-y-4">
			@foreach ($groupedByBranch as $branchRows)
				@include('reports.partials.stock-summary-branch-section', ['branchRows' => $branchRows])
			@endforeach
		</div>
	@endif

	<x-pagination-limit-selector />
	<div>{{ $rows->links() }}</div>
</div>
@endsection
