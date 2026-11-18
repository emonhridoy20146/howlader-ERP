@props(['filters', 'filterBranches'])
@php
    $branchOptions = $filterBranches->map(fn ($b) => ['value' => $b->id, 'label' => $b->name])->values()->all();
    $section = $filters['section'] ?? 'all';
@endphp
<div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
    <div class="sm:col-span-2">
        <label class="mb-1 block text-xs font-medium text-slate-600">Search</label>
        <input type="text" name="q" value="{{ $filters['q'] ?? '' }}" placeholder="Invoice #, customer, phone" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
    </div>
    <div>
        <label class="mb-1 block text-xs font-medium text-slate-600">Show</label>
        <select name="section" class="w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm">
            <option value="all" @selected($section === 'all')>All — credit, overdue &amp; matured</option>
            <option value="credit" @selected($section === 'credit')>Past-due credit invoices</option>
            <option value="overdue" @selected($section === 'overdue')>Overdue installment schedules</option>
            <option value="matured" @selected($section === 'matured')>Matured installment plans</option>
        </select>
    </div>
    <div>
        <label class="mb-1 block text-xs font-medium text-slate-600">Branch</label>
        <x-searchable-select name="branch_id" :options="$branchOptions" :selected="$filters['branch_id'] ?? ''" placeholder="All branches (within access)" />
    </div>
</div>
