@props(['filters', 'filterWarehouseLocations'])
@php
    $locationOptions = $filterWarehouseLocations->map(fn($l) => ['value' => $l->id, 'label' => $l->name])->values()->all();
@endphp
<div class="grid gap-4 sm:grid-cols-2">
    <div class="sm:col-span-2">
        <label class="mb-1 block text-xs font-medium text-slate-600">Search</label>
	    <input type="text" name="q" value="{{ $filters['q'] ?? '' }}" placeholder="Supplier name, phone, contact" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
    </div>
    <div class="sm:col-span-2">
        <label class="mb-1 block text-xs font-medium text-slate-600">Destination (warehouse)</label>
        <x-searchable-select name="location_id" :options="$locationOptions" :selected="$filters['location_id'] ?? ''" placeholder="All destinations (within access)" />
    </div>
</div>
