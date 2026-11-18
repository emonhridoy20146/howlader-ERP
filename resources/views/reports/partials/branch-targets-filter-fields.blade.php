@props(['filters', 'filterBranches'])
@php
    $branchOptions = $filterBranches->map(fn ($b) => ['value' => $b->id, 'label' => $b->name])->values()->all();
@endphp
<div class="grid gap-4 sm:grid-cols-2">
    <div>
        <label class="mb-1 block text-xs font-medium text-slate-600">Year</label>
        <input type="number" name="year" min="2000" max="2100" value="{{ $filters['year'] ?? now()->year }}" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm" required>
    </div>
    <div>
        <label class="mb-1 block text-xs font-medium text-slate-600">Month (for monthly targets)</label>
        <select name="month" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm" required>
            @for ($m = 1; $m <= 12; $m++)
                <option value="{{ $m }}" @selected((int) ($filters['month'] ?? now()->month) === $m)>{{ \Carbon\Carbon::createFromDate(2000, $m, 1)->format('F') }}</option>
            @endfor
        </select>
    </div>
    <div class="sm:col-span-2">
        <label class="mb-1 block text-xs font-medium text-slate-600">Branch</label>
        <x-searchable-select name="branch_id" :options="$branchOptions" :selected="$filters['branch_id'] ?? ''" placeholder="All branches (within access)" />
    </div>
    <div class="sm:col-span-2">
        <label class="mb-1 block text-xs font-medium text-slate-600">Show</label>
        <select name="view" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
            <option value="both" @selected(($filters['view'] ?? 'both') === 'both')>Monthly + annual</option>
            <option value="monthly" @selected(($filters['view'] ?? '') === 'monthly')>Monthly only</option>
            <option value="annual" @selected(($filters['view'] ?? '') === 'annual')>Annual only</option>
        </select>
    </div>
</div>
