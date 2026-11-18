@extends('layouts.app')

@section('title', 'Products')
@section('page-title', 'Products')

@section('content')
    @php
        $drawerMode = request('drawer');
        $isEdit = $drawerMode === 'edit' && !is_null($editingProduct);
        $isDrawerOpen = in_array($drawerMode, ['create', 'edit'], true);
        $product = $isEdit ? $editingProduct : new \App\Models\Product();
        $isInactiveList = ($filters['list'] ?? 'active') === 'inactive';
        $tabQueryBase = collect(request()->except('page', 'list'))->all();
        $drawerCloseQuery = array_merge(
            collect(request()->except('page', 'list', 'drawer', 'edit'))->all(),
            ['list' => $filters['list'] ?? 'active']
        );
        $printQuery = array_filter([
            'q' => $filters['q'] ?? '',
            'brand_id' => $filters['brand_id'] ?? '',
            'category_id' => $filters['category_id'] ?? '',
            'location_id' => $filters['location_id'] ?? '',
            'list' => $filters['list'] ?? 'active',
            'stock' => $filters['stock'] ?? '',
            'sort' => $filters['sort'] ?? '',
            'direction' => $filters['direction'] ?? '',
        ], fn ($value) => $value !== '' && $value !== null);
    @endphp

    <div
        data-products-list="{{ $filters['list'] ?? 'active' }}"
        @product-open-serials.window="openSerials($event.detail)"
        x-data="{
            drawerOpen: @js($isDrawerOpen),
            serialsPanelOpen: false,
            activeProductId: null,
            serialFilters: { search: '', status: '', location: '', sort: 'serial_asc' },
            serialsByProduct: @js($serialLookup ?? []),
            productLookup: @js($productLookup ?? []),
            openSerials(productId) {
                this.activeProductId = String(productId);
                this.serialFilters = { search: '', status: '', location: '', sort: 'serial_asc' };
                this.serialsPanelOpen = true;
            },
            closeSerials() {
                this.serialsPanelOpen = false;
                this.activeProductId = null;
            },
            closeDrawer() {
                this.drawerOpen = false;
                window.location.href = @js(route('products.index', $drawerCloseQuery));
            },
            get activeProduct() {
                if (!this.activeProductId) {
                    return null;
                }

                return this.productLookup[this.activeProductId] ?? null;
            },
            get activeSerials() {
                if (!this.activeProductId) {
                    return [];
                }

                return this.serialsByProduct[this.activeProductId] ?? [];
            },
            get filteredSerials() {
                const keyword = this.serialFilters.search.trim().toLowerCase();

                const filtered = this.activeSerials.filter((row) => {
                    const ext = (row.manufacturer_serial ?? '').toLowerCase();
                    const sn = (row.serial_number ?? '').toLowerCase();
                    const locCode = (row.location_code ?? '').toLowerCase();
                    const matchesSearch = keyword === ''
                        || sn.includes(keyword)
                        || ext.includes(keyword)
                        || ((row.location_name ?? '').toLowerCase().includes(keyword))
                        || locCode.includes(keyword);
                    const matchesStatus = this.serialFilters.status === '' || row.status === this.serialFilters.status;
                    const matchesLocation = this.serialFilters.location === '' || String(row.location_id ?? '') === this.serialFilters.location;

                    return matchesSearch && matchesStatus && matchesLocation;
                });
                const sortKey = this.serialFilters.sort || 'serial_asc';
                const cmpSerial = (a, b) => String(a.serial_number ?? '').localeCompare(String(b.serial_number ?? ''), undefined, { numeric: true });
                const sorted = [...filtered];
                if (sortKey === 'serial_desc') {
                    sorted.sort((a, b) => -cmpSerial(a, b));
                } else if (sortKey === 'serial_asc') {
                    sorted.sort(cmpSerial);
                } else if (sortKey === 'status') {
                    sorted.sort((a, b) => String(a.status ?? '').localeCompare(String(b.status ?? '')) || cmpSerial(a, b));
                } else if (sortKey === 'location') {
                    sorted.sort((a, b) => String(a.location_name ?? '').localeCompare(String(b.location_name ?? '')) || cmpSerial(a, b));
                } else {
                    sorted.sort(cmpSerial);
                }
                return sorted;
            },
            get statusOptions() {
                return [...new Set(this.activeSerials.map((row) => row.status).filter(Boolean))].sort();
            },
            get locationOptions() {
                const options = {};

                this.activeSerials.forEach((row) => {
                    if (row.location_id && !options[row.location_id]) {
                        options[row.location_id] = {
                            id: String(row.location_id),
                            label: row.location_name ? String(row.location_name) : `Location ${row.location_id}`,
                        };
                    }
                });

                return Object.values(options).sort((a, b) => a.label.localeCompare(b.label));
            },
            formatStatus(status) {
                return status.replaceAll('_', ' ').replace(/\b\w/g, (char) => char.toUpperCase());
            },
        }"
    >
        <div class="mb-4 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
            <div class="inline-flex flex-wrap gap-1 rounded-lg border border-slate-200 bg-slate-50 p-1">
                <a href="{{ route('products.index', array_merge($tabQueryBase, ['list' => 'active'])) }}" class="rounded-md px-3 py-1.5 text-xs font-semibold {{ ! $isInactiveList ? 'bg-white text-slate-900 shadow-sm ring-1 ring-slate-200' : 'text-slate-600 hover:text-slate-900' }}">Active products</a>
                <a href="{{ route('products.index', array_merge($tabQueryBase, ['list' => 'inactive'])) }}" class="rounded-md px-3 py-1.5 text-xs font-semibold {{ $isInactiveList ? 'bg-white text-amber-900 shadow-sm ring-1 ring-amber-200' : 'text-slate-600 hover:text-slate-900' }}">Deactivated</a>
            </div>
            <div class="flex flex-wrap gap-2">
                <a href="{{ route('products.print', $printQuery) }}" target="_blank" rel="noopener noreferrer" class="inline-flex justify-center rounded-lg border border-slate-300 bg-white px-4 py-2 text-sm font-medium text-slate-800 shadow-sm hover:bg-slate-50">Print list</a>
                @if (! $isInactiveList)
                    <a href="{{ route('products.index', array_merge($tabQueryBase, ['list' => 'active', 'drawer' => 'create'])) }}" class="inline-flex justify-center rounded-lg bg-slate-900 px-4 py-2 text-sm font-medium text-white hover:bg-slate-800">New Product</a>
                @endif
            </div>
        </div>

        @if (session('status'))
            <div class="mb-4 rounded-lg bg-emerald-50 p-3 text-sm text-emerald-700">{{ session('status') }}</div>
        @endif

        @include('products._bulk-update')

        <x-filter-drawer>
<form method="get" action="{{ route('products.index') }}" class="flex flex-col gap-4">
            <div class="flex flex-col gap-4">
                <input type="text" name="q" value="{{ $filters['q'] ?? '' }}" placeholder="Search name, model, barcode" class="rounded-lg border border-slate-300 px-3 py-2 text-sm  ">
                <select name="brand_id" class="rounded-lg border border-slate-300 px-3 py-2 text-sm">
                    <option value="">All brands</option>
                    @foreach ($brands as $brandOption)
                        <option value="{{ $brandOption->id }}" @selected(($filters['brand_id'] ?? '') === (string) $brandOption->id)>{{ $brandOption->name }}</option>
                    @endforeach
                </select>
                <select name="category_id" class="rounded-lg border border-slate-300 px-3 py-2 text-sm">
                    <option value="">All categories</option>
                    @foreach ($categories as $categoryOption)
                        <option value="{{ $categoryOption->id }}" @selected(($filters['category_id'] ?? '') === (string) $categoryOption->id)>{{ $categoryOption->name }}</option>
                    @endforeach
                </select>
                <select name="location_id" class="rounded-lg border border-slate-300 px-3 py-2 text-sm" title="Filter by branch">
                    <option value="">All branches</option>
                    @foreach ($locations as $locationOption)
                        <option value="{{ $locationOption->id }}" @selected(($filters['location_id'] ?? '') === (string) $locationOption->id)>{{ $locationOption->name }} ({{ $locationOption->code }})</option>
                    @endforeach
                </select>
                <input type="hidden" name="list" value="{{ $filters['list'] ?? 'active' }}">
                <select name="sort" class="rounded-lg border border-slate-300 px-3 py-2 text-sm" title="Sort products">
                    <option value="created_at" @selected(($filters['sort'] ?? '') === 'created_at')>Sort: Created</option>
                    <option value="updated_at" @selected(($filters['sort'] ?? '') === 'updated_at')>Sort: Last updated</option>
                    <option value="name" @selected(($filters['sort'] ?? '') === 'name')>Sort: Name</option>
                    <option value="sale_price" @selected(($filters['sort'] ?? '') === 'sale_price')>Sort: Sale price</option>
                    <option value="brand" @selected(($filters['sort'] ?? '') === 'brand')>Sort: Brand</option>
                    <option value="category" @selected(($filters['sort'] ?? '') === 'category')>Sort: Category</option>
                    <option value="inventory" @selected(($filters['sort'] ?? '') === 'inventory')>Sort: Available qty</option>
                </select>
                <select name="direction" class="rounded-lg border border-slate-300 px-3 py-2 text-sm" title="Sort direction">
                    <option value="asc" @selected(($filters['direction'] ?? '') === 'asc')>Ascending</option>
                    <option value="desc" @selected(($filters['direction'] ?? '') === 'desc')>Descending</option>
                </select>
            </div>
            @php
                $stockFilter = (string) ($filters['stock'] ?? '');
                $queryBase = array_merge($tabQueryBase, ['list' => $filters['list'] ?? 'active']);
            @endphp
            <div class="flex flex-col gap-2 md:flex-row md:items-center md:justify-between">
                <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">Stock on hand</p>
                <div class="inline-flex flex-wrap gap-1 rounded-lg border border-slate-200 bg-slate-50 p-1">
                    <a href="{{ route('products.index', $queryBase) }}" class="rounded-md px-3 py-1.5 text-xs font-semibold {{ $stockFilter === '' ? 'bg-white text-slate-900 shadow-sm ring-1 ring-slate-200' : 'text-slate-600 hover:text-slate-900' }}">All</a>
                    <a href="{{ route('products.index', array_merge($queryBase, ['stock' => 'in_stock'])) }}" class="rounded-md px-3 py-1.5 text-xs font-semibold {{ $stockFilter === 'in_stock' ? 'bg-white text-emerald-800 shadow-sm ring-1 ring-emerald-200' : 'text-slate-600 hover:text-slate-900' }}">In stock</a>
                    <a href="{{ route('products.index', array_merge($queryBase, ['stock' => 'out_of_stock'])) }}" class="rounded-md px-3 py-1.5 text-xs font-semibold {{ $stockFilter === 'out_of_stock' ? 'bg-white text-amber-900 shadow-sm ring-1 ring-amber-200' : 'text-slate-600 hover:text-slate-900' }}">Out of stock</a>
                </div>
            </div>
            <x-index-filter-actions :reset-url="route('products.index', ['list' => $filters['list'] ?? 'active'])" />
        </form>
</x-filter-drawer>

        <div class="mb-4 grid gap-3 sm:grid-cols-4">
            <x-status-card label="Total Products">
			<span class="text-slate-900">{{ $kpis['total'] }}</span>
		</x-status-card>
            <x-status-card label="{{ $isInactiveList ? 'Deactivated' : 'Active' }}">
			<span class="text-slate-900">{{ $isInactiveList ? $kpis['inactive'] : $kpis['active'] }}</span>
		</x-status-card>
            <x-status-card label="Serialized">
			<span class="text-slate-900">{{ $kpis['serialized'] }}</span>
		</x-status-card>
            <x-status-card label="Filtered Result">
			<span class="text-slate-900">{{ $kpis['filtered'] }}</span>
		</x-status-card>
        </div>

        <form method="get" action="{{ route('products.labels') }}" target="_blank" class="block">
            @if (($filters['location_id'] ?? '') !== '')
                <input type="hidden" name="location_id" value="{{ $filters['location_id'] }}">
            @endif
            <x-index-table-shell
                :title="$isInactiveList ? 'Deactivated products' : 'Active products'"
                :meta="trim($products->total().' in this list'.(($filterLocation ?? null) ? ' · '.$filterLocation->name : ''))"
            >
                <x-slot:toolbar>
                        <a href="{{ route('products.labels', array_filter(['format' => 'qr', 'location_id' => $filters['location_id'] ?? null])) }}" target="_blank" class="rounded-lg border border-emerald-200 bg-emerald-50 px-3 py-1.5 text-xs font-semibold text-emerald-900 shadow-sm hover:bg-emerald-100">
                            Open QR labels
                        </a>
                        <a href="{{ route('products.labels', array_filter(['format' => 'barcode', 'location_id' => $filters['location_id'] ?? null])) }}" target="_blank" class="rounded-lg border border-emerald-200 bg-emerald-50 px-3 py-1.5 text-xs font-semibold text-emerald-900 shadow-sm hover:bg-emerald-100">
                            Open barcode labels
                        </a>
                        <button type="submit" name="format" value="qr" class="rounded-lg border border-slate-300 bg-white px-3 py-1.5 text-xs font-semibold text-slate-800 shadow-sm hover:bg-slate-50">
                            Print QR (selected)@if ($filterLocation ?? null)<span class="hidden sm:inline"> · {{ $filterLocation->code }}</span>@endif
                        </button>
                        <button type="submit" name="format" value="barcode" class="rounded-lg border border-slate-300 bg-white px-3 py-1.5 text-xs font-semibold text-slate-800 shadow-sm hover:bg-slate-50">
                            Print barcode (selected)@if ($filterLocation ?? null)<span class="hidden sm:inline"> · {{ $filterLocation->code }}</span>@endif
                        </button>
                        @can('bulkUpdate', \App\Models\Product::class)
                            <button type="button" class="rounded-lg border border-indigo-200 bg-indigo-50 px-3 py-1.5 text-xs font-semibold text-indigo-900 shadow-sm hover:bg-indigo-100" onclick="const panel = document.getElementById('product-bulk-update-panel'); if (panel) { panel.setAttribute('open', 'open'); panel.scrollIntoView({ behavior: 'smooth' }); }">Bulk price update</button>
                        @endcan
                </x-slot:toolbar>
            <table class="index-data-table min-w-[56rem] sm:min-w-full text-sm whitespace-nowrap">
                <thead class="bg-slate-50/80 text-left text-[11px] font-semibold uppercase tracking-wide text-slate-600">
                    <tr>
                        <th class="w-10 px-2 py-3 sm:px-3">
                            <input type="checkbox" class="h-4 w-4 rounded border-slate-300 text-slate-900" title="Select all on this page" @change="$el.closest('table').querySelectorAll('input[data-product-label]').forEach((cb) => { cb.checked = $event.target.checked; })">
                        </th>
                        <th class="px-4 py-3">Product</th>
                        <th class="px-4 py-3">Brand / Category</th>
                        <th class="px-4 py-3 text-right">Sale price</th>
                        <th class="px-4 py-3 align-top">
                            <span class="block">Inventory</span>
                            @if ($filterLocation ?? null)
                                <span class="mt-0.5 block text-[10px] font-normal normal-case tracking-normal text-slate-500">{{ $filterLocation->code }}</span>
                            @endif
                        </th>
                        <th class="px-4 py-3 text-center">Active</th>
                        <th class="px-4 py-3 text-right sm:px-6">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @forelse ($products as $rowProduct)
                        @php
                            $inventory = $rowProduct->inventory_summary ?? ['on_hand_qty' => 0, 'reserved_qty' => 0, 'available_qty' => 0];
                            $salePrice = (float) $rowProduct->default_sale_price;
                        @endphp
                        <tr class="transition hover:bg-slate-50/70">
                            <td class="px-2 py-4 sm:px-3">
                                <input type="checkbox" name="product_ids[]" value="{{ $rowProduct->id }}" data-product-label class="h-4 w-4 rounded border-slate-300 text-slate-900">
                            </td>
                            <td class="px-4 py-4">
                                <p class="font-semibold text-slate-900">{{ $rowProduct->name }}</p>
                                @if ($rowProduct->model_number)
                                    <p class="text-xs text-slate-500">Model: {{ $rowProduct->model_number }}</p>
                                @endif
                            </td>
                            <td class="px-4 py-4">
                                <div class="flex flex-wrap gap-1.5">
                                    @if ($rowProduct->brand)
                                        <span class="inline-flex rounded-full bg-blue-50 px-2 py-1 text-xs font-medium text-blue-700">{{ $rowProduct->brand->name }}</span>
                                    @endif
                                    @if ($rowProduct->category)
                                        <span class="inline-flex rounded-full bg-indigo-50 px-2 py-1 text-xs font-medium text-indigo-700">{{ $rowProduct->category->name }}</span>
                                    @endif
                                    @if (! $rowProduct->brand && ! $rowProduct->category)
                                        <span class="text-xs text-slate-400">—</span>
                                    @endif
                                </div>
                            </td>
                            <td class="px-4 py-4 text-right tabular-nums font-semibold text-slate-900">{{ number_format($salePrice, 2) }}</td>
                            <td class="px-4 py-3">
                                <div class="flex flex-wrap gap-1.5">
                                    <span class="inline-flex rounded-full bg-emerald-50 px-2 py-1 text-xs font-semibold text-emerald-700">Available {{ $inventory['available_qty'] }}</span>
                                    <span class="inline-flex rounded-full bg-slate-100 px-2 py-1 text-xs font-medium text-slate-700">On Hand {{ $inventory['on_hand_qty'] }}</span>
                                    <span class="inline-flex rounded-full bg-amber-50 px-2 py-1 text-xs font-medium text-amber-700">Reserved {{ $inventory['reserved_qty'] }}</span>
                                </div>
                            </td>
                            <td class="px-4 py-4 text-center">
                                @can('update', $rowProduct)
                                    <div
                                        class="inline-flex flex-col items-center gap-1"
                                        data-product-active-toggle
                                        data-url="{{ route('products.toggle-active', $rowProduct) }}"
                                        data-active="{{ $rowProduct->is_active ? '1' : '0' }}"
                                    >
                                        <label class="relative inline-flex cursor-pointer items-center" title="{{ $rowProduct->is_active ? 'Deactivate product' : 'Activate product' }}">
                                            <input type="checkbox" class="peer sr-only" @checked($rowProduct->is_active)>
                                            <span class="block h-6 w-11 rounded-full bg-slate-300 transition peer-checked:bg-emerald-500 peer-disabled:opacity-50"></span>
                                            <span class="absolute left-1 top-1 h-4 w-4 rounded-full bg-white shadow transition peer-checked:translate-x-5"></span>
                                        </label>
                                        <span data-active-label class="text-[10px] font-medium {{ $rowProduct->is_active ? 'text-emerald-700' : 'text-amber-700' }}">{{ $rowProduct->is_active ? 'On' : 'Off' }}</span>
                                    </div>
                                @else
                                    <span class="text-xs font-medium {{ $rowProduct->is_active ? 'text-emerald-700' : 'text-amber-700' }}">{{ $rowProduct->is_active ? 'Active' : 'Off' }}</span>
                                @endcan
                            </td>
                            <td class="px-4 py-4 text-right sm:px-6">
                                <x-index-row-actions-menu>
                                    <a href="{{ route('products.show', $rowProduct) }}">View</a>
                                    <a href="{{ route('products.index', array_merge($tabQueryBase, ['list' => $filters['list'] ?? 'active', 'drawer' => 'edit', 'edit' => $rowProduct->id])) }}">Edit</a>
                                    @if ($rowProduct->is_serialized)
                                        <button type="button" @click="$dispatch('product-open-serials', {{ $rowProduct->id }})">Serials</button>
                                    @endif
                                </x-index-row-actions-menu>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="7" class="px-4 py-8 text-center text-slate-500">{{ $isInactiveList ? 'No deactivated products.' : 'No active products match the filters.' }}</td></tr>
                    @endforelse
                </tbody>
            </table>
            </x-index-table-shell>
        </form>
	<x-pagination-limit-selector />

        <div class="mt-4">{{ $products->links() }}</div>

        <div x-cloak x-show="drawerOpen" class="fixed inset-0 z-40">
            <button type="button" class="absolute inset-0 bg-slate-900/40" @click="closeDrawer()" aria-label="Close"></button>
            <div class="absolute inset-y-0 right-0 w-full max-w-2xl overflow-y-auto bg-white shadow-2xl">
                <div class="sticky top-0 z-10 flex items-center justify-between border-b border-slate-200 bg-white px-6 py-4">
                    <h2 class="text-lg font-semibold text-slate-900">{{ $isEdit ? 'Edit Product' : 'New Product' }}</h2>
                    <button type="button" class="text-sm text-slate-600 hover:text-slate-900" @click="closeDrawer()">Close</button>
                </div>
                <div class="p-6">
                    <form action="{{ $isEdit ? route('products.update', $product) : route('products.store') }}" method="post" enctype="multipart/form-data" class="space-y-4">
                        @if ($isEdit)
                            @method('PUT')
                        @endif
                        @include('products._form')
                    </form>
                </div>
            </div>
        </div>

        <div x-cloak x-show="serialsPanelOpen" class="fixed inset-0 z-50">
            <button type="button" class="absolute inset-0 bg-slate-900/40" @click="closeSerials()" aria-label="Close serials panel"></button>
            <div class="absolute inset-y-0 right-0 w-full max-w-3xl overflow-y-auto bg-white shadow-2xl">
                <div class="sticky top-0 border-b border-slate-200 bg-white px-6 py-4">
                    <div class="flex items-center justify-between gap-3">
                        <div>
                            <h3 class="text-lg font-semibold text-slate-900">Serial Inventory</h3>
                            <p class="text-sm text-slate-500" x-text="activeProduct ? activeProduct.name : ''"></p>
                        </div>
                        <button type="button" class="text-sm text-slate-600" @click="closeSerials()">Close</button>
                    </div>
                    <div class="mt-3 grid grid-cols-1 gap-3 md:grid-cols-2 lg:grid-cols-4">
                        <label class="text-xs text-slate-600">
                            Search
                            <input type="text" x-model="serialFilters.search" placeholder="Serial, external serial, or location" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm text-slate-900">
                        </label>
                        <label class="text-xs text-slate-600">
                            Status
                            <select x-model="serialFilters.status" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm text-slate-900">
                                <option value="">All statuses</option>
                                <template x-for="status in statusOptions" :key="status">
                                    <option :value="status" x-text="formatStatus(status)"></option>
                                </template>
                            </select>
                        </label>
                        <label class="text-xs text-slate-600">
                            Location
                            <select x-model="serialFilters.location" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm text-slate-900">
                                <option value="">All locations</option>
                                <template x-for="location in locationOptions" :key="location.id">
                                    <option :value="location.id" x-text="location.label"></option>
                                </template>
                            </select>
                        </label>
                        <label class="text-xs text-slate-600">
                            Sort
                            <select x-model="serialFilters.sort" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm text-slate-900">
                                <option value="serial_asc">Serial A → Z</option>
                                <option value="serial_desc">Serial Z → A</option>
                                <option value="status">Status</option>
                                <option value="location">Location code</option>
                            </select>
                        </label>
                    </div>
                    <p class="mt-3 text-xs text-slate-500" x-text="`${filteredSerials.length} serial(s) found`"></p>
                </div>

                <div class="p-6">
                    <div class="overflow-hidden rounded-xl border border-slate-200">
                        <table class="min-w-full text-sm whitespace-nowrap">
                            <thead class="bg-slate-50 border-b border-slate-200 text-left text-xs font-semibold uppercase tracking-wider text-slate-700">
                                <tr>
                                    <th class="px-4 py-3">Serial</th>
                                    <th class="px-4 py-3">External</th>
                                    <th class="px-4 py-3">Status</th>
                                    <th class="px-4 py-3">Location</th>
                                </tr>
                            </thead>
                            <tbody>
                                <template x-if="filteredSerials.length === 0">
                                    <tr>
                                        <td colspan="4" class="px-4 py-12 text-center text-sm font-medium text-slate-500 bg-slate-50/50">No serials match the current filters.</td>
                                    </tr>
                                </template>
                                <template x-for="row in filteredSerials" :key="row.id">
                                    <tr class="border-b border-slate-100 transition-colors hover:bg-slate-50/70 last:border-0">
                                        <td class="px-4 py-3 font-mono" x-text="row.serial_number"></td>
                                        <td class="px-4 py-3 font-mono text-xs text-slate-600" x-text="row.manufacturer_serial || '—'"></td>
                                        <td class="px-4 py-3">
                                            <span class="rounded-full bg-slate-100 px-2 py-1 text-xs text-slate-700" x-text="formatStatus(row.status)"></span>
                                        </td>
                                        <td class="px-4 py-3" x-text="row.location_name || '—'"></td>
                                    </tr>
                                </template>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>

    @php
        $productToggleFilters = collect(request()->except('page'))
            ->filter(fn ($value) => is_scalar($value) && $value !== '')
            ->all();
    @endphp
    <script>
        (function () {
            const csrfToken = @js(csrf_token());
            const toggleFilters = @js($productToggleFilters);
            const currentList = document.querySelector('[data-products-list]')?.dataset.productsList || 'active';

            function notify(message, icon) {
                if (typeof window.appAlert === 'function') {
                    window.appAlert(message, { icon: icon || 'success', title: icon === 'error' ? 'Could not update' : 'Product updated' });
                    return;
                }
                alert(message);
            }

            function applyToggleUi(wrap, isActive) {
                const checkbox = wrap.querySelector('input[type="checkbox"]');
                const label = wrap.querySelector('[data-active-label]');
                wrap.dataset.active = isActive ? '1' : '0';
                if (checkbox) {
                    checkbox.checked = isActive;
                }
                if (label) {
                    label.textContent = isActive ? 'On' : 'Off';
                    label.classList.toggle('text-emerald-700', isActive);
                    label.classList.toggle('text-amber-700', !isActive);
                }
                const switchLabel = wrap.querySelector('label[title]');
                if (switchLabel) {
                    switchLabel.title = isActive ? 'Deactivate product' : 'Activate product';
                }
            }

            document.addEventListener('change', async (event) => {
                const checkbox = event.target;
                if (!checkbox.matches('[data-product-active-toggle] input[type="checkbox"]')) {
                    return;
                }

                const wrap = checkbox.closest('[data-product-active-toggle]');
                if (!wrap || wrap.dataset.loading === '1') {
                    return;
                }

                const url = wrap.dataset.url;
                const wasActive = wrap.dataset.active === '1';
                const nextActive = checkbox.checked;

                if (wasActive === nextActive) {
                    return;
                }

                wrap.dataset.loading = '1';
                checkbox.disabled = true;

                const body = new FormData();
                body.append('_token', csrfToken);
                Object.entries(toggleFilters).forEach(([key, value]) => {
                    body.append(key, String(value));
                });

                try {
                    const response = await fetch(url, {
                        method: 'POST',
                        headers: {
                            Accept: 'application/json',
                            'X-Requested-With': 'XMLHttpRequest',
                        },
                        body,
                    });

                    const payload = await response.json().catch(() => ({}));

                    if (!response.ok) {
                        throw new Error(payload.message || 'Could not update product status.');
                    }

                    applyToggleUi(wrap, Boolean(payload.is_active));
                    notify(payload.message || 'Product status updated.');

                    const row = wrap.closest('tr');
                    if (row && payload.list && payload.list !== currentList) {
                        row.classList.add('opacity-0', 'transition', 'duration-300');
                        window.setTimeout(() => row.remove(), 280);
                    }
                } catch (error) {
                    applyToggleUi(wrap, wasActive);
                    notify(error.message || 'Could not update product status.', 'error');
                } finally {
                    delete wrap.dataset.loading;
                    checkbox.disabled = false;
                }
            });
        })();
    </script>
@endsection
