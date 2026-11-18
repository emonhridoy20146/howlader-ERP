@extends('layouts.app')

@section('title', 'Categories')
@section('page-title', 'Categories')

@section('content')
    @php
        $drawerMode = request('drawer');
        $isEdit = $drawerMode === 'edit' && !is_null($editingCategory);
        $isDrawerOpen = in_array($drawerMode, ['create', 'edit'], true) || $errors->any();
        $category = $isEdit ? $editingCategory : new \App\Models\Category();
        $printQuery = array_filter([
            'q' => $filters['q'] ?? '',
            'parent_id' => $filters['parent_id'] ?? '',
        ], fn ($value) => $value !== '' && $value !== null);
        $parentFilterOptions = $parents->map(fn ($parent) => [
            'value' => (string) $parent->id,
            'label' => $parent->name,
        ])->values()->all();
        $fromCategoryOptions = $allCategories->map(fn ($cat) => [
            'value' => (string) $cat->id,
            'label' => $cat->name.' ('.number_format($cat->products_count).' products)',
        ])->values()->all();
        $toCategoryOptions = $allCategories->map(fn ($cat) => [
            'value' => (string) $cat->id,
            'label' => $cat->name,
        ])->values()->all();
    @endphp

    <div x-data="{ drawerOpen: @js($isDrawerOpen) }">
        <div class="mb-4 flex flex-col gap-2 sm:flex-row sm:flex-wrap sm:items-center sm:justify-end">
            <a href="{{ route('categories.bulk-products') }}" class="inline-flex justify-center rounded-lg border border-indigo-200 bg-indigo-50 px-4 py-2.5 text-sm font-medium text-indigo-900 shadow-sm hover:bg-indigo-100 sm:py-2">Bulk product categories</a>
            <a href="{{ route('categories.print', $printQuery) }}" target="_blank" rel="noopener noreferrer" class="inline-flex justify-center rounded-lg border border-slate-300 bg-white px-4 py-2.5 text-sm font-medium text-slate-800 shadow-sm hover:bg-slate-50 sm:py-2">Print list</a>
            <a href="{{ route('categories.index', ['drawer' => 'create']) }}" class="inline-flex justify-center rounded-lg bg-slate-900 px-4 py-2.5 text-sm font-medium text-white shadow-sm hover:bg-slate-800 sm:py-2">New Category</a>
        </div>

        @if (session('status'))
            <div class="mb-4 rounded-lg bg-emerald-50 p-3 text-sm text-emerald-700">{{ session('status') }}</div>
        @endif

        <x-filter-drawer>
<form method="get" action="{{ route('categories.index') }}" class="flex flex-col gap-4">
            <div class="flex flex-col gap-4">
                <input type="text" name="q" value="{{ $filters['q'] ?? '' }}" placeholder="Search category or parent" class="rounded-lg border border-slate-300 px-3 py-2 text-sm ">
                <label class="text-xs font-medium text-slate-600">Parent category</label>
                <x-searchable-select
                    name="parent_id"
                    :options="$parentFilterOptions"
                    :selected="$filters['parent_id'] ?? ''"
                    placeholder="All parents"
                />
            </div>
            <x-index-filter-actions :reset-url="route('categories.index')" />
        </form>
</x-filter-drawer>

        <div class="mb-4 rounded-2xl border border-slate-200 bg-white p-4 shadow-sm ring-1 ring-slate-200">
                <h2 class="text-sm font-semibold text-slate-900">Move products to another category</h2>
                <p class="mt-1 text-xs text-slate-500">Reassigns every product in the source category to the destination category.</p>
                <form action="{{ route('categories.move-products') }}" method="post" class="mt-3 grid gap-3 sm:grid-cols-2 lg:grid-cols-4 lg:items-end" data-confirm="Move all products from the source category to the destination category?">
                    @csrf
                    <div>
                        <label class="mb-1 block text-xs font-medium text-slate-600">From category</label>
                        <x-searchable-select
                            name="from_category_id"
                            :options="$fromCategoryOptions"
                            :selected="old('from_category_id', '')"
                            placeholder="Search source category…"
                            :clearable="false"
                        />
                        @error('from_category_id')<p class="mt-1 text-xs text-rose-600">{{ $message }}</p>@enderror
                    </div>
                    <div>
                        <label class="mb-1 block text-xs font-medium text-slate-600">To category</label>
                        <x-searchable-select
                            name="to_category_id"
                            :options="$toCategoryOptions"
                            :selected="old('to_category_id', '')"
                            placeholder="Search destination category…"
                            :clearable="false"
                        />
                        @error('to_category_id')<p class="mt-1 text-xs text-rose-600">{{ $message }}</p>@enderror
                    </div>
                    <div class="sm:col-span-2 lg:col-span-1">
                        <button type="submit" class="w-full rounded-lg bg-amber-600 px-4 py-2 text-sm font-semibold text-white hover:bg-amber-700">Move products</button>
                    </div>
                </form>
        </div>

        <div class="mb-4 grid gap-3 sm:grid-cols-4">
            <x-status-card label="Total">
			<span class="text-slate-900">{{ $kpis['total'] }}</span>
		</x-status-card>
            <x-status-card label="Root">
			<span class="text-slate-900">{{ $kpis['root'] }}</span>
		</x-status-card>
            <x-status-card label="Child">
			<span class="text-slate-900">{{ $kpis['child'] }}</span>
		</x-status-card>
            <x-status-card label="Filtered">
			<span class="text-slate-900">{{ $kpis['filtered'] }}</span>
		</x-status-card>
        </div>

        <x-index-table-shell title="Categories" :meta="$categories->total().' in this list'">
            <table class="index-data-table min-w-[36rem] sm:min-w-full whitespace-nowrap">
                <thead class="bg-slate-50 border-b border-slate-200 text-left text-xs font-semibold uppercase tracking-wider text-slate-700">
                    <tr>
                        <th class="px-4 py-3">Name</th>
                        <th class="px-4 py-3">Parent</th>
                        <th class="px-4 py-3">Order</th>
                        <th class="px-4 py-3 text-center">Assigned Products</th>
                        <th class="px-4 py-3 text-right">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($categories as $rowCategory)
                        <tr class="border-b border-slate-100 transition-colors hover:bg-slate-50/70 last:border-0">
                            <td class="px-4 py-3 font-medium">{{ $rowCategory->name }}</td>
                            <td class="px-4 py-3">{{ $rowCategory->parent?->name ?: '-' }}</td>
                            <td class="px-4 py-3">{{ $rowCategory->sort_order }}</td>
                            <td class="px-4 py-3 text-center tabular-nums">
                                @if ($rowCategory->products_count > 0)
                                    <div class="relative inline-block" x-data="{ open: false }" @mouseenter="open = true" @mouseleave="open = false">
                                        <span class="inline-flex items-center rounded-full bg-emerald-50 px-2.5 py-0.5 text-xs font-medium text-emerald-700 cursor-default underline decoration-dotted">{{ number_format($rowCategory->products_count) }} products</span>
                                        <div
                                            x-show="open"
                                            x-cloak
                                            style="position:fixed;z-index:9999;"
                                            x-init="$watch('open', v => { if(v){ let r=$el.previousElementSibling.getBoundingClientRect(); $el.style.top=(r.bottom+2)+'px'; $el.style.left=(r.left+(r.width/2))+'px'; $el.style.transform='translateX(-50%)'; } })"
                                            class="w-56 rounded-lg border border-slate-200 bg-white p-2 shadow-lg text-left"
                                        >
                                            <p class="mb-1 text-xs font-semibold text-slate-500 uppercase tracking-wide">Assigned Products</p>
                                            <ul class="max-h-48 overflow-y-auto space-y-0.5">
                                                @foreach ($rowCategory->products as $assignedProduct)
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
                                    <a href="{{ route('categories.index', ['drawer' => 'edit', 'edit' => $rowCategory->id]) }}">Edit</a>
                                    <form action="{{ route('categories.destroy', $rowCategory) }}" method="post" data-confirm="Delete this category?">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" class="index-actions-menu-item--danger">Delete</button>
                                    </form>
                                </x-index-row-actions-menu>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="px-4 py-12 text-center text-sm font-medium text-slate-500 bg-slate-50/50">No categories found.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </x-index-table-shell>
	<x-pagination-limit-selector />

        <div class="mt-4">{{ $categories->links() }}</div>

        <div x-cloak x-show="drawerOpen" class="fixed inset-0 z-40">
            <a href="{{ route('categories.index') }}" class="absolute inset-0 bg-slate-900/40" aria-label="Close"></a>
            <div class="absolute inset-y-0 right-0 w-full max-w-xl overflow-y-auto bg-white shadow-2xl">
                <div class="sticky top-0 flex items-center justify-between border-b border-slate-200 bg-white px-6 py-4">
                    <h2 class="text-lg font-semibold text-slate-900">{{ $isEdit ? 'Edit Category' : 'New Category' }}</h2>
                    <a href="{{ route('categories.index') }}" class="text-sm text-slate-600">Close</a>
                </div>
                <div class="p-6">
                    <form action="{{ $isEdit ? route('categories.update', $category) : route('categories.store') }}" method="post" class="space-y-4">
                        @if ($isEdit)
                            @method('PUT')
                        @endif
                        @include('categories._form')
                    </form>
                </div>
            </div>
        </div>
    </div>
@endsection
