@props(['filters', 'filterLocations'])
@php
    $locationOptions = $filterLocations->map(fn ($l) => ['value' => $l->id, 'label' => $l->name])->values()->all();
    $movementOptions = [
        ['value' => 'transfer_issue', 'label' => 'Issue (out from source)'],
        ['value' => 'transfer_receive', 'label' => 'Receive (in at destination)'],
    ];
@endphp
<div class="grid gap-4 sm:grid-cols-2">
    <div class="sm:col-span-2">
        <label class="mb-1 block text-xs font-medium text-slate-600">Search</label>
        <input type="text" name="q" value="{{ $filters['q'] ?? '' }}" placeholder="Transfer no., product, serial, branch…" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
    </div>
    <div class="sm:col-span-2">
        <label class="mb-1 block text-xs font-medium text-slate-600">Event date range</label>
        <div class="grid grid-cols-2 gap-2">
            <input type="date" name="date_from" value="{{ $filters['date_from'] ?? '' }}" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm" title="From date">
            <input type="date" name="date_to" value="{{ $filters['date_to'] ?? '' }}" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm" title="To date">
        </div>
        <p class="mt-1 text-xs text-slate-500">Defaults to this month through today when both are empty.</p>
    </div>
    <div>
        <label class="mb-1 block text-xs font-medium text-slate-600">Source branch</label>
        <x-searchable-select name="source_location_id" :options="$locationOptions" :selected="$filters['source_location_id'] ?? ''" placeholder="All source branches" />
    </div>
    <div>
        <label class="mb-1 block text-xs font-medium text-slate-600">Destination branch</label>
        <x-searchable-select name="destination_location_id" :options="$locationOptions" :selected="$filters['destination_location_id'] ?? ''" placeholder="All destination branches" />
    </div>
    <div>
        <label class="mb-1 block text-xs font-medium text-slate-600">Ledger branch</label>
        <x-searchable-select name="location_id" :options="$locationOptions" :selected="$filters['location_id'] ?? ''" placeholder="All ledger branches" />
        <p class="mt-1 text-xs text-slate-500">Branch where stock was debited or credited.</p>
    </div>
    <div>
        <label class="mb-1 block text-xs font-medium text-slate-600">Movement</label>
        <x-searchable-select name="movement_type" :options="$movementOptions" :selected="$filters['movement_type'] ?? ''" placeholder="Issue and receive" />
    </div>
</div>
