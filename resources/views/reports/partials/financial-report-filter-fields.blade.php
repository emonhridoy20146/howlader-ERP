@props(['filters', 'filterBranches'])
@php
    $branchOptions = $filterBranches->map(fn($b) => ['value' => $b->id, 'label' => $b->name])->values()->all();
@endphp
<div class="grid gap-4 sm:grid-cols-2">
    <div class="sm:col-span-2">
        <label class="mb-1 block text-xs font-medium text-slate-600">Branch</label>
        <x-searchable-select name="branch_id" :options="$branchOptions" :selected="$filters['branch_id'] ?? ''" placeholder="All branches" />
        <p class="mt-1 text-xs text-slate-500">Choose one branch or leave blank for a company-wide total across branches you can access.</p>
    </div>
    <div class="sm:col-span-2">
        <label class="mb-1 block text-xs font-medium text-slate-600">Date range</label>
        <div class="grid grid-cols-2 gap-2">
            <input type="date" name="date_from" value="{{ $filters['date_from'] ?? '' }}" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm" title="From date">
            <input type="date" name="date_to" value="{{ $filters['date_to'] ?? '' }}" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm" title="To date">
        </div>
        <p class="mt-1 text-xs text-slate-500">Leave both empty on first open to default to this month through today.</p>
    </div>
</div>
