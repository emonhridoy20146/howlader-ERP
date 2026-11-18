@props(['filters', 'filterBranches', 'collectors'])
@php
    $collectorOptions = collect($collectors)->map(fn ($u) => ['value' => (string) $u['id'], 'label' => $u['name']])->prepend(['value' => 'unknown', 'label' => 'Unknown (no collector recorded)'])->values()->all();
    $field = 'w-full rounded-lg border border-slate-300 px-3 py-2 text-sm';
    $maxYears = (int) config('reports.yearly_collection_max_years', 3);
@endphp
<div class="grid gap-4 sm:grid-cols-2">
    <div>
        <label class="mb-1 block text-xs font-medium text-slate-600">From year</label>
        <input type="number" name="year_from" min="2000" max="{{ now()->year + 1 }}" value="{{ $filters['year_from'] }}" class="{{ $field }}" required>
    </div>
    <div>
        <label class="mb-1 block text-xs font-medium text-slate-600">To year</label>
        <input type="number" name="year_to" min="2000" max="{{ now()->year + 1 }}" value="{{ $filters['year_to'] }}" class="{{ $field }}" required>
    </div>
    <p class="-mt-2 text-xs text-slate-500 sm:col-span-2">At most {{ $maxYears }} years (12 month columns each).</p>
    <div class="sm:col-span-2">
        <span class="mb-1 block text-xs font-medium text-slate-600">Branches <span class="font-normal text-slate-400">(none ticked = all branches in your access; the report is grouped by branch)</span></span>
        <div class="max-h-40 space-y-1 overflow-y-auto rounded-lg border border-slate-300 p-2 text-sm">
            @foreach ($filterBranches as $b)
                <label class="flex items-center gap-2"><input type="checkbox" name="branch_ids[]" value="{{ $b->id }}" @checked(in_array($b->id, $filters['branch_ids'] ?? [], true))> {{ $b->name }}</label>
            @endforeach
        </div>
    </div>
    <div class="sm:col-span-2">
        <label class="mb-1 block text-xs font-medium text-slate-600">Search customer</label>
        <input type="text" name="q" value="{{ $filters['q'] ?? '' }}" placeholder="Name, code, or phone" class="{{ $field }}">
    </div>
    <div>
        <label class="mb-1 block text-xs font-medium text-slate-600">Show</label>
        <select name="status" class="{{ $field }}">
            <option value="all" @selected(($filters['status'] ?? 'all') === 'all')>All unpaid</option>
            <option value="has_missed" @selected(($filters['status'] ?? '') === 'has_missed')>Has missed months in range</option>
            <option value="overdue" @selected(($filters['status'] ?? '') === 'overdue')>Overdue only</option>
        </select>
    </div>
    <div>
        <label class="mb-1 block text-xs font-medium text-slate-600">Plan status</label>
        <select name="plan_status" class="{{ $field }}">
            <option value="">Any</option>
            @foreach (['active', 'completed', 'defaulted'] as $st)
                <option value="{{ $st }}" @selected(($filters['plan_status'] ?? '') === $st)>{{ ucfirst($st) }}</option>
            @endforeach
        </select>
    </div>
    <div class="sm:col-span-2">
        <label class="mb-1 block text-xs font-medium text-slate-600">Collected by</label>
        <x-searchable-select name="collector_id" :options="$collectorOptions" :selected="$filters['collector_id'] ?? ''" placeholder="All collectors" />
    </div>
    <div>
        <label class="mb-1 block text-xs font-medium text-slate-600">Sort by</label>
        <select name="sort" class="{{ $field }}">
            <option value="name" @selected(($filters['sort'] ?? 'name') === 'name')>Customer name</option>
            <option value="code" @selected(($filters['sort'] ?? '') === 'code')>Customer code</option>
            <option value="due" @selected(($filters['sort'] ?? '') === 'due')>Total due (high first)</option>
        </select>
    </div>
    <div class="flex flex-col justify-end gap-1 text-sm">
        <input type="hidden" name="include_paid" value="0">
        <label class="inline-flex items-center gap-2"><input type="checkbox" name="include_paid" value="1" @checked($filters['include_paid'] ?? false)> Include fully paid</label>
        <input type="hidden" name="include_down_payment" value="0">
        <label class="inline-flex items-center gap-2"><input type="checkbox" name="include_down_payment" value="1" @checked($filters['include_down_payment'] ?? true)> Include down payment</label>
    </div>
</div>
