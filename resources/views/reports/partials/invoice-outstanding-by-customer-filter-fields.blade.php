@props(['filters', 'filterBranches'])
@php
    $branchOptions = $filterBranches->map(fn ($b) => ['value' => $b->id, 'label' => $b->name])->values()->all();
@endphp
<div class="grid gap-4 sm:grid-cols-2">
    <div class="sm:col-span-2">
        <label class="mb-1 block text-xs font-medium text-slate-600">Search</label>
	    <input type="text" name="q" value="{{ $filters['q'] ?? '' }}" placeholder="Customer name, code, or phone" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
    </div>
    <div class="sm:col-span-2">
        <label class="mb-1 block text-xs font-medium text-slate-600">Branch</label>
        <x-searchable-select name="branch_id" :options="$branchOptions" :selected="$filters['branch_id'] ?? ''" placeholder="All locations (within access)" />
    </div>
</div>
