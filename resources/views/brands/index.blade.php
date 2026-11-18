@extends('layouts.app')

@section('title', 'Brands')
@section('page-title', 'Brands')

@section('content')
    @php
        $drawerMode = request('drawer');
        $isEdit = $drawerMode === 'edit' && !is_null($editingBrand);
        $isDrawerOpen = in_array($drawerMode, ['create', 'edit'], true) || $errors->any();
        $brand = $isEdit ? $editingBrand : new \App\Models\Brand();
    @endphp

    <div x-data="{ drawerOpen: @js($isDrawerOpen) }">
        <div class="mb-4 flex flex-col gap-2 sm:flex-row sm:flex-wrap sm:items-center sm:justify-end">
            <a href="{{ route('brands.index', ['drawer' => 'create']) }}" class="inline-flex justify-center rounded-lg bg-slate-900 px-4 py-2.5 text-sm font-medium text-white shadow-sm hover:bg-slate-800 sm:py-2">New Brand</a>
        </div>

        @if (session('status'))
            <div class="mb-4 rounded-lg bg-emerald-50 p-3 text-sm text-emerald-700">{{ session('status') }}</div>
        @endif

        <x-filter-drawer>
<form method="get" action="{{ route('brands.index') }}" class="flex flex-col gap-4">
            <input type="text" name="q" value="{{ $filters['q'] ?? '' }}" placeholder="Search brand or country" class="rounded-lg border border-slate-300 px-3 py-2 text-sm">
            <x-index-filter-actions :reset-url="route('brands.index')" submit-label="Search" />
        </form>
</x-filter-drawer>

        <div class="mb-4 grid gap-3 sm:grid-cols-3">
            <x-status-card label="Total Brands">
			<span class="text-slate-900">{{ $kpis['total'] }}</span>
		</x-status-card>
            <x-status-card label="With Country">
			<span class="text-slate-900">{{ $kpis['with_country'] }}</span>
		</x-status-card>
            <x-status-card label="Filtered Result">
			<span class="text-slate-900">{{ $kpis['filtered'] }}</span>
		</x-status-card>
        </div>

        <x-index-table-shell title="Brands" :meta="$brands->total().' in this list'">
            <table class="index-data-table min-w-[32rem] sm:min-w-full whitespace-nowrap">
                <thead class="bg-slate-50 border-b border-slate-200 text-left text-xs font-semibold uppercase tracking-wider text-slate-700">
                    <tr>
                        <th class="px-4 py-3">Name</th>
                        <th class="px-4 py-3">Country</th>
                        <th class="px-4 py-3 text-center">Products</th>
                        <th class="px-4 py-3 text-right">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($brands as $rowBrand)
                        <tr class="border-b border-slate-100 transition-colors hover:bg-slate-50/70 last:border-0">
                            <td class="px-4 py-3 font-medium">{{ $rowBrand->name }}</td>
                            <td class="px-4 py-3">{{ $rowBrand->country_of_origin ?: '-' }}</td>
                            <td class="px-4 py-3 text-center">
                                @if ($rowBrand->products_count > 0)
                                    <div class="relative inline-block" x-data="{ open: false }" @mouseenter="open = true" @mouseleave="open = false">
                                        <span class="inline-flex items-center rounded-full bg-emerald-50 px-2.5 py-0.5 text-xs font-medium text-emerald-700 cursor-default underline decoration-dotted">{{ number_format($rowBrand->products_count) }} products</span>
                                        <div
                                            x-show="open"
                                            x-cloak
                                            style="position:fixed;z-index:9999;"
                                            x-init="$watch('open', v => { if(v){ let r=$el.previousElementSibling.getBoundingClientRect(); $el.style.top=(r.bottom+2)+'px'; $el.style.left=(r.left+(r.width/2))+'px'; $el.style.transform='translateX(-50%)'; } })"
                                            class="w-56 rounded-lg border border-slate-200 bg-white p-2 shadow-lg text-left"
                                        >
                                            <p class="mb-1 text-xs font-semibold text-slate-500 uppercase tracking-wide">Assigned Products</p>
                                            <ul class="max-h-48 overflow-y-auto space-y-0.5">
                                                @foreach ($rowBrand->products as $assignedProduct)
                                                    <li class="text-xs text-slate-700 truncate">{{ $assignedProduct->name }}</li>
                                                @endforeach
                                            </ul>
                                        </div>
                                    </div>
                                @else
                                    <span class="inline-flex items-center rounded-full bg-slate-100 px-2.5 py-0.5 text-xs font-medium text-slate-400">No products</span>
                                @endif
                            </td>
                            <td class="px-4 py-3 text-right">
                                <x-index-row-actions-menu>
                                    <a href="{{ route('brands.index', ['drawer' => 'edit', 'edit' => $rowBrand->id]) }}">Edit</a>
                                    <form action="{{ route('brands.destroy', $rowBrand) }}" method="post" data-confirm="Delete this brand?">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" class="index-actions-menu-item--danger">Delete</button>
                                    </form>
                                </x-index-row-actions-menu>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="4" class="px-4 py-12 text-center text-sm font-medium text-slate-500 bg-slate-50/50">No brands found.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </x-index-table-shell>
	<x-pagination-limit-selector />

        <div class="mt-4">{{ $brands->links() }}</div>

        <div x-cloak x-show="drawerOpen" class="fixed inset-0 z-40">
            <a href="{{ route('brands.index') }}" class="absolute inset-0 bg-slate-900/40" aria-label="Close"></a>
            <div class="absolute inset-y-0 right-0 w-full max-w-xl overflow-y-auto bg-white shadow-2xl">
                <div class="sticky top-0 flex items-center justify-between border-b border-slate-200 bg-white px-6 py-4">
                    <h2 class="text-lg font-semibold text-slate-900">{{ $isEdit ? 'Edit Brand' : 'New Brand' }}</h2>
                    <a href="{{ route('brands.index') }}" class="text-sm text-slate-600">Close</a>
                </div>
                <div class="p-6">
                    <form action="{{ $isEdit ? route('brands.update', $brand) : route('brands.store') }}" method="post" class="space-y-4">
                        @if ($isEdit)
                            @method('PUT')
                        @endif
                        @include('brands._form')
                    </form>
                </div>
            </div>
        </div>
    </div>
@endsection
