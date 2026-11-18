@props(['filters', 'filterBranches'])
@php
    $branchOptions = $filterBranches->map(fn($b) => ['value' => $b->id, 'label' => $b->name])->values()->all();
    $paymentStatusOptions = [
        ['value' => 'unpaid', 'label' => 'Unpaid'],
        ['value' => 'partial', 'label' => 'Partial'],
        ['value' => 'paid', 'label' => 'Paid'],
    ];
    $saleTypeOptions = [
        ['value' => 'default', 'label' => 'Default'],
        ['value' => 'credit', 'label' => 'Credit'],
        ['value' => 'installment', 'label' => 'Installment'],
    ];
@endphp
<div class="grid gap-4 sm:grid-cols-2">
    <div class="sm:col-span-2">
        <label class="mb-1 block text-xs font-medium text-slate-600">Search</label>
	    <input type="text" name="q" value="{{ $filters['q'] ?? '' }}" placeholder="Invoice #, customer, phone" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
    </div>
    <div class="sm:col-span-2">
        <label class="mb-1 block text-xs font-medium text-slate-600">Branch</label>
        <x-searchable-select name="branch_id" :options="$branchOptions" :selected="$filters['branch_id'] ?? ''" placeholder="All branches (within access)" />
    </div>
    <div>
        <label class="mb-1 block text-xs font-medium text-slate-600">Payment Status</label>
        <x-searchable-select name="payment_status" :options="$paymentStatusOptions" :selected="$filters['payment_status'] ?? ''" placeholder="All payment status" />
    </div>
    <div>
        <label class="mb-1 block text-xs font-medium text-slate-600">Sale Type</label>
        <x-searchable-select name="sale_type" :options="$saleTypeOptions" :selected="$filters['sale_type'] ?? ''" placeholder="All sale types" />
    </div>
    <div class="sm:col-span-2">
        <label class="mb-1 block text-xs font-medium text-slate-600">Date Range</label>
        <div class="grid grid-cols-2 gap-2">
            <input type="date" name="date_from" value="{{ $filters['date_from'] ?? '' }}" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm" title="From date">
            <input type="date" name="date_to" value="{{ $filters['date_to'] ?? '' }}" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm" title="To date">
        </div>
    </div>
</div>
