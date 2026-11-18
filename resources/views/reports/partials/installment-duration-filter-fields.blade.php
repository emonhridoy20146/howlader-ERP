@props(['filters', 'filterBranches'])
@php
    $branchOptions = $filterBranches->map(fn($b) => ['value' => $b->id, 'label' => $b->name])->values()->all();
@endphp
<div class="grid gap-4 sm:grid-cols-2">
    <div class="sm:col-span-2">
        <label class="mb-1 block text-xs font-medium text-slate-600">Search</label>
        <input type="text" name="q" value="{{ $filters['q'] ?? '' }}" placeholder="Invoice #, customer, phone" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
    </div>
    <div>
        <label class="mb-1 block text-xs font-medium text-slate-600">Branch</label>
        <x-searchable-select name="branch_id" :options="$branchOptions" :selected="$filters['branch_id'] ?? ''" placeholder="All branches (within access)" />
    </div>
    <div>
        <label class="mb-1 block text-xs font-medium text-slate-600">Status</label>
        <select name="status" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
            <option value="">All statuses</option>
            <option value="active" @selected(($filters['status'] ?? '') === 'active')>Active</option>
            <option value="completed" @selected(($filters['status'] ?? '') === 'completed')>Completed</option>
        </select>
    </div>
    <div class="sm:col-span-2">
        <label class="mb-1 block text-xs font-medium text-slate-600">Invoice date range</label>
        <div class="grid grid-cols-2 gap-2">
            <input type="date" name="date_from" value="{{ $filters['date_from'] ?? '' }}" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm" title="From date">
            <input type="date" name="date_to" value="{{ $filters['date_to'] ?? '' }}" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm" title="To date">
        </div>
    </div>
</div>
