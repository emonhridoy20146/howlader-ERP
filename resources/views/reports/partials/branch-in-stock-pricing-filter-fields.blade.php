@props(['filters', 'filterLocations', 'filterCategories'])
@php
    $locationOptions = $filterLocations->map(fn ($l) => ['value' => $l->id, 'label' => $l->name])->values()->all();
    $categoryOptions = $filterCategories->map(fn ($c) => ['value' => $c->id, 'label' => $c->name])->values()->all();
@endphp
<div class="grid gap-4 sm:grid-cols-2">
    <div class="sm:col-span-2">
        <label class="mb-1 block text-xs font-medium text-slate-600">Search</label>
        <input type="text" name="q" value="{{ $filters['q'] ?? '' }}" placeholder="Search SKU, product, category, or branch" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
    </div>
    <div>
        <label class="mb-1 block text-xs font-medium text-slate-600">Branch</label>
        <x-searchable-select name="location_id" :options="$locationOptions" :selected="$filters['location_id'] ?? ''" placeholder="All branches" />
    </div>
    <div>
        <label class="mb-1 block text-xs font-medium text-slate-600">Category</label>
        <x-searchable-select name="category_id" :options="$categoryOptions" :selected="$filters['category_id'] ?? ''" placeholder="All categories" />
    </div>
    @include('reports.partials.stock-as-of-date-field', ['filters' => $filters])
</div>
