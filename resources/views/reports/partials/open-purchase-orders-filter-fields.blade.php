@props(['filters', 'filterSuppliers', 'filterLocations'])
@php
    $supplierOptions = $filterSuppliers->map(fn($s) => ['value' => $s->id, 'label' => $s->name])->values()->all();
    $locationOptions = $filterLocations->map(fn($l) => ['value' => $l->id, 'label' => $l->name])->values()->all();
    $statusOptions = [
        ['value' => 'draft', 'label' => 'Draft'],
        ['value' => 'approved', 'label' => 'Approved'],
        ['value' => 'partially_received', 'label' => 'Partially received'],
    ];
@endphp
<div class="grid gap-4 sm:grid-cols-2">
    <div class="sm:col-span-2">
        <label class="mb-1 block text-xs font-medium text-slate-600">Search</label>
	    <input type="text" name="q" value="{{ $filters['q'] ?? '' }}" placeholder="PO number or supplier" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
    </div>
    <div>
        <label class="mb-1 block text-xs font-medium text-slate-600">Status</label>
        <x-searchable-select name="status" :options="$statusOptions" :selected="$filters['status'] ?? ''" placeholder="All open statuses" />
    </div>
    <div>
        <label class="mb-1 block text-xs font-medium text-slate-600">Supplier</label>
        <x-searchable-select name="supplier_id" :options="$supplierOptions" :selected="$filters['supplier_id'] ?? ''" placeholder="All suppliers" />
    </div>
    <div class="sm:col-span-2">
        <label class="mb-1 block text-xs font-medium text-slate-600">Destination Location</label>
        <x-searchable-select name="location_id" :options="$locationOptions" :selected="$filters['location_id'] ?? ''" placeholder="All locations" />
    </div>
    <div class="sm:col-span-2">
        <label class="mb-1 block text-xs font-medium text-slate-600">Order Date</label>
        <div class="grid grid-cols-2 gap-2">
            <input type="date" name="date_from" value="{{ $filters['date_from'] ?? '' }}" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm" title="Ordered from">
            <input type="date" name="date_to" value="{{ $filters['date_to'] ?? '' }}" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm" title="Ordered to">
        </div>
    </div>
</div>
