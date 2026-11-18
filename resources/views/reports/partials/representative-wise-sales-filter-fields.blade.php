@props(['filters', 'filterBranches', 'filterRepresentatives'])
@php
    $branchOptions = $filterBranches->map(fn($b) => ['value' => $b->id, 'label' => $b->name])->values()->all();
    $repOptions = $filterRepresentatives->map(fn ($employee) => [
        'value' => $employee->id,
        'label' => $employee->displayLabel(),
    ])->values()->all();
@endphp
<div class="grid gap-4 sm:grid-cols-2">
    <div class="sm:col-span-2">
        <label class="mb-1 block text-xs font-medium text-slate-600">Search</label>
	    <input type="text" name="q" value="{{ $filters['q'] ?? '' }}" placeholder="Employee name or code" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
    </div>
    <div>
        <label class="mb-1 block text-xs font-medium text-slate-600">Branch</label>
        <x-searchable-select name="branch_id" :options="$branchOptions" :selected="$filters['branch_id'] ?? ''" placeholder="All branches" />
    </div>
    <div>
        <label class="mb-1 block text-xs font-medium text-slate-600">Employee</label>
        <x-searchable-select name="representative_id" :options="$repOptions" :selected="$filters['representative_id'] ?? ''" placeholder="All employees" />
    </div>
    <div class="sm:col-span-2">
        <label class="mb-1 block text-xs font-medium text-slate-600">Date range</label>
        <p class="mb-2 text-[11px] text-slate-500">Matches invoice date or payment collection date.</p>
        <div class="grid grid-cols-2 gap-2">
            <input type="date" name="date_from" value="{{ $filters['date_from'] ?? '' }}" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm" title="From date">
            <input type="date" name="date_to" value="{{ $filters['date_to'] ?? '' }}" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm" title="To date">
        </div>
    </div>
</div>
