@props(['filters', 'filterLocations'])
@php
    $locationOptions = $filterLocations->map(fn($l) => ['value' => $l->id, 'label' => $l->name])->values()->all();
    $stockStateOptions = [
        ['value' => 'in_stock', 'label' => 'In stock'],
        ['value' => 'out_of_stock', 'label' => 'Out of stock'],
        ['value' => 'low_stock', 'label' => 'Low stock (reorder level)'],
    ];
    $serializedOptions = [
        ['value' => '1', 'label' => 'Serialized only'],
        ['value' => '0', 'label' => 'Non-serialized only'],
    ];
@endphp
<div class="grid gap-4 sm:grid-cols-2">
    <div class="sm:col-span-2">
        <label class="mb-1 block text-xs font-medium text-slate-600">Search</label>
	    <input type="text" name="q" value="{{ $filters['q'] ?? '' }}" placeholder="Search SKU, product, or location" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
    </div>
    <div class="sm:col-span-2">
        <label class="mb-1 block text-xs font-medium text-slate-600">Location</label>
        <x-searchable-select name="location_id" :options="$locationOptions" :selected="$filters['location_id'] ?? ''" placeholder="All locations" />
    </div>
    <div>
        <label class="mb-1 block text-xs font-medium text-slate-600">Stock State</label>
        <x-searchable-select name="stock_state" :options="$stockStateOptions" :selected="$filters['stock_state'] ?? ''" placeholder="All stock states" />
    </div>
    <div>
        <label class="mb-1 block text-xs font-medium text-slate-600">Product Type</label>
        <x-searchable-select name="serialized" :options="$serializedOptions" :selected="$filters['serialized'] ?? ''" placeholder="All product types" />
    </div>
    @include('reports.partials.stock-as-of-date-field', ['filters' => $filters])
</div>
