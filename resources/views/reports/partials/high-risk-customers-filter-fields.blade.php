@props(['filters', 'filterBranches'])
@php
    $branchOptions = $filterBranches->map(fn ($b) => ['value' => $b->id, 'label' => $b->name])->values()->all();
    $selectedGrades = $filters['grade'] ?? [];
    $field = 'w-full rounded-lg border border-slate-300 px-3 py-2 text-sm';
@endphp
<div class="grid gap-4 sm:grid-cols-2">
    <div class="sm:col-span-2">
        <label class="mb-1 block text-xs font-medium text-slate-600">Search customer</label>
        <input type="text" name="q" value="{{ $filters['q'] ?? '' }}" placeholder="Name, code, or phone" class="{{ $field }}">
    </div>
    <div class="sm:col-span-2">
        <label class="mb-1 block text-xs font-medium text-slate-600">Branch</label>
        <x-searchable-select name="branch_id" :options="$branchOptions" :selected="$filters['branch_id'] ?? ''" placeholder="All locations (within access)" />
    </div>
    <div class="sm:col-span-2">
        <span class="mb-1 block text-xs font-medium text-slate-600">Grade <span class="font-normal text-slate-400">(none selected = D/E or trend)</span></span>
        <div class="flex flex-wrap gap-3 text-sm">
            @foreach (['A', 'B', 'C', 'D', 'E', 'N/A'] as $g)
                <label class="inline-flex items-center gap-1"><input type="checkbox" name="grade[]" value="{{ $g }}" @checked(in_array($g, $selectedGrades, true))> {{ $g }}</label>
            @endforeach
        </div>
    </div>
    <div>
        <label class="mb-1 block text-xs font-medium text-slate-600">Late pattern</label>
        <select name="classification" class="{{ $field }}">
            <option value="">Default rule</option>
            <option value="any" @selected(($filters['classification'] ?? '') === 'any')>Any</option>
            <option value="trend" @selected(($filters['classification'] ?? '') === 'trend')>Trend</option>
            <option value="isolated" @selected(($filters['classification'] ?? '') === 'isolated')>Isolated</option>
        </select>
    </div>
    <div>
        <label class="mb-1 block text-xs font-medium text-slate-600">Status</label>
        <select name="status" class="{{ $field }}">
            <option value="">Any</option>
            @foreach (['active', 'overdue', 'defaulted', 'closed'] as $st)
                <option value="{{ $st }}" @selected(($filters['status'] ?? '') === $st)>{{ ucfirst($st) }}</option>
            @endforeach
        </select>
    </div>
    <div>
        <label class="mb-1 block text-xs font-medium text-slate-600">Overdue from</label>
        <input type="number" step="0.01" min="0" name="overdue_min" value="{{ $filters['overdue_min'] ?? '' }}" class="{{ $field }}">
    </div>
    <div>
        <label class="mb-1 block text-xs font-medium text-slate-600">Overdue to</label>
        <input type="number" step="0.01" min="0" name="overdue_max" value="{{ $filters['overdue_max'] ?? '' }}" class="{{ $field }}">
    </div>
    <div>
        <label class="mb-1 block text-xs font-medium text-slate-600">Max days late ≥</label>
        <input type="number" min="0" name="min_days_late" value="{{ $filters['min_days_late'] ?? '' }}" class="{{ $field }}">
    </div>
    <div>
        <label class="mb-1 block text-xs font-medium text-slate-600">Has guarantor</label>
        <select name="has_guarantor" class="{{ $field }}">
            <option value="">Any</option>
            <option value="yes" @selected(($filters['has_guarantor'] ?? '') === 'yes')>Yes</option>
            <option value="no" @selected(($filters['has_guarantor'] ?? '') === 'no')>No</option>
        </select>
    </div>
    <div class="sm:col-span-2">
        <label class="mb-1 block text-xs font-medium text-slate-600">Guarantor name</label>
        <input type="text" name="guarantor" value="{{ $filters['guarantor'] ?? '' }}" class="{{ $field }}">
    </div>
    <div>
        <label class="mb-1 block text-xs font-medium text-slate-600">Sort by</label>
        <select name="sort" class="{{ $field }}">
            @foreach (['overdue_amount' => 'Overdue amount', 'score' => 'Score', 'max_days_late' => 'Max days late', 'late_6m' => 'Lates (6m)', 'name' => 'Name'] as $k => $l)
                <option value="{{ $k }}" @selected(($filters['sort'] ?? 'overdue_amount') === $k)>{{ $l }}</option>
            @endforeach
        </select>
    </div>
    <div>
        <label class="mb-1 block text-xs font-medium text-slate-600">Direction</label>
        <select name="dir" class="{{ $field }}">
            <option value="desc" @selected(($filters['dir'] ?? 'desc') === 'desc')>Descending</option>
            <option value="asc" @selected(($filters['dir'] ?? '') === 'asc')>Ascending</option>
        </select>
    </div>
</div>
