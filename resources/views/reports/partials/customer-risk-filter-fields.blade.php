@props(['filterCustomers'])
@php
    $customerOptions = $filterCustomers->map(fn ($c) => ['value' => $c->id, 'label' => $c->name . ' (' . $c->customer_code . ($c->phone ? ' · ' . $c->phone : '') . ')'])->values()->all();
@endphp
<div class="grid gap-4">
    <div>
        <label class="mb-1 block text-xs font-medium text-slate-600">Customer</label>
        <x-searchable-select name="customer_id" :options="$customerOptions" selected="" placeholder="Search by name, code, or phone…" />
    </div>
    <p class="text-xs text-slate-500">Opens the customer's full profile and risk analysis. Use Print or Excel for a downloadable copy; PDF is available on the profile page.</p>
</div>
