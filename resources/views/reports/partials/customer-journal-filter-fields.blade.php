@props(['filters', 'filterCustomers'])
@php
    $customerOptions = $filterCustomers->map(fn ($c) => ['value' => $c->id, 'label' => $c->name . ' (' . $c->customer_code . ($c->phone ? ' · ' . $c->phone : '') . ')'])->values()->all();
@endphp
<div class="grid gap-4 sm:grid-cols-2">
    <div class="sm:col-span-2">
        <label class="mb-1 block text-xs font-medium text-slate-600">Customer</label>
        <x-searchable-select name="customer_id" :options="$customerOptions" :selected="$filters['customer_id'] ?? ''" placeholder="Search by name, code, or phone…" />
    </div>
    <div>
        <label class="mb-1 block text-xs font-medium text-slate-600">From date</label>
        <input type="date" name="date_from" value="{{ $filters['date_from'] ?? '' }}" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
    </div>
    <div>
        <label class="mb-1 block text-xs font-medium text-slate-600">To date</label>
        <input type="date" name="date_to" value="{{ $filters['date_to'] ?? '' }}" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
    </div>
</div>
