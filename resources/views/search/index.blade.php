@extends('layouts.app')

@section('title', 'Search')
@section('page-title', 'Search')

@section('content')
    <div class="mx-auto max-w-4xl space-y-6">
        <form method="get" action="{{ route('search') }}" class="flex flex-wrap gap-2">
            <input type="search" name="q" value="{{ $q }}" placeholder="Search by customer code, name, invoice, serial…" autocomplete="off"
                class="min-w-0 flex-1 rounded-xl border border-slate-300 px-4 py-2 text-sm shadow-sm focus:border-slate-500 focus:outline-none focus:ring-2 focus:ring-slate-200">
            <button type="submit" class="rounded-xl bg-slate-800 px-4 py-2 text-sm font-medium text-white hover:bg-slate-700">Search</button>
        </form>

        @if (strlen($q) < app(\App\Services\GlobalSearchService::class)->minimumQueryLength($q))
            <p class="text-sm text-slate-600">Enter at least two characters to search records, or a customer code (for example <span class="font-mono">CUST2607160001</span>). Try screens like “serial”, “grn”, “installment”, or “report”.</p>
        @else
            @php
                $hasAny = collect([
                    $pages,
                    $products,
                    $customers,
                    $suppliers,
                    $brands,
                    $categories,
                    $locations,
                    $invoices,
                    $installmentPlans,
                    $purchaseOrders,
                    $goodsReceipts,
                    $stockTransfers,
                    $serials,
                    $returnOrders,
                    $serviceJobs,
                    $journalEntries,
                ])->contains(fn ($set) => is_array($set) ? $set !== [] : $set->isNotEmpty());
            @endphp
            <div class="space-y-8">
                @if ($riskFirst ?? false)
                @include('search._section', ['title' => 'Customers', 'empty' => 'No matching customers.', 'items' => $customers, 'type' => 'customer'])
                @endif
                @if ($pages !== [])
                    @include('search._section', ['title' => 'Screens', 'empty' => '', 'items' => collect($pages), 'type' => 'page'])
                @endif
                @include('search._section', ['title' => 'Products', 'empty' => 'No matching products.', 'items' => $products, 'type' => 'product'])
                @unless ($riskFirst ?? false)
                @include('search._section', ['title' => 'Customers', 'empty' => 'No matching customers.', 'items' => $customers, 'type' => 'customer'])
                @endunless
                @include('search._section', ['title' => 'Suppliers', 'empty' => 'No matching suppliers.', 'items' => $suppliers, 'type' => 'supplier'])
                @include('search._section', ['title' => 'Brands', 'empty' => 'No matching brands.', 'items' => $brands, 'type' => 'brand'])
                @include('search._section', ['title' => 'Categories', 'empty' => 'No matching categories.', 'items' => $categories, 'type' => 'category'])
                @include('search._section', ['title' => 'Branches', 'empty' => 'No matching branches.', 'items' => $locations, 'type' => 'location'])
                @include('search._section', ['title' => 'Sales invoices', 'empty' => 'No matching invoices.', 'items' => $invoices, 'type' => 'invoice'])
                @include('search._section', ['title' => 'Installment plans', 'empty' => 'No matching installment plans.', 'items' => $installmentPlans, 'type' => 'installment'])
                @include('search._section', ['title' => 'Purchase orders', 'empty' => 'No matching purchase orders.', 'items' => $purchaseOrders, 'type' => 'po'])
                @include('search._section', ['title' => 'Goods receipts', 'empty' => 'No matching goods receipts.', 'items' => $goodsReceipts, 'type' => 'grn'])
                @include('search._section', ['title' => 'Stock transfers', 'empty' => 'No matching transfers.', 'items' => $stockTransfers, 'type' => 'transfer'])
                @include('search._section', ['title' => 'Serial numbers', 'empty' => 'No matching serials.', 'items' => $serials, 'type' => 'serial'])
                @include('search._section', ['title' => 'Returns', 'empty' => 'No matching returns.', 'items' => $returnOrders, 'type' => 'return'])
                @include('search._section', ['title' => 'Service jobs', 'empty' => 'No matching service jobs.', 'items' => $serviceJobs, 'type' => 'service_job'])
                @include('search._section', ['title' => 'Journal entries', 'empty' => 'No matching journal entries.', 'items' => $journalEntries, 'type' => 'journal'])

                @unless ($hasAny)
                    <p class="rounded-xl border border-slate-200 bg-white p-6 text-center text-sm text-slate-600">No results for “{{ $q }}”.@if ($branchId) Results are limited to the selected location.@endif</p>
                @endunless
            </div>
        @endif
    </div>
@endsection
