@extends('layouts.app')

@section('title', 'Stock by branch')
@section('page-title', 'Stock by branch')

@section('content')
    <p class="mb-4 max-w-3xl text-sm text-slate-600">
        Quantities reflect the <span class="font-medium">stock ledger</span> (and reserved serials), the same rules as <span class="font-medium">inventory_balances</span> after a sync.
        Use <span class="font-medium">Recalculate from ledger</span> to refresh cached <span class="font-medium">inventory_balances</span> rows.
    </p>

    @if (session('status'))
        <div class="mb-4 rounded-lg bg-emerald-50 p-3 text-sm text-emerald-700">{{ session('status') }}</div>
    @endif

    <div class="mb-4 flex flex-col gap-2 sm:flex-row sm:flex-wrap sm:items-center sm:justify-between">
        <form method="post" action="{{ route('inventory.stock-levels.sync') }}" class="inline">
            @csrf
            <button type="submit" class="inline-flex justify-center rounded-lg border border-slate-300 bg-white px-4 py-2 text-sm font-medium text-slate-800 shadow-sm hover:bg-slate-50">
                Recalculate from ledger
            </button>
        </form>
    </div>

    <div class="mb-4 rounded-2xl border border-slate-200 bg-white p-4 shadow-sm ring-1 ring-slate-900/[0.06]">
        <form method="get" action="{{ route('inventory.stock-levels.index') }}" class="flex flex-col gap-4">
            @if (request()->filled('per_page'))
                <input type="hidden" name="per_page" value="{{ request('per_page') }}">
            @endif
            <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                <div class="flex flex-col gap-1 sm:col-span-2 lg:col-span-1">
                    <label for="stock-filter-q" class="text-xs font-medium text-slate-600">Search</label>
                    <input
                        id="stock-filter-q"
                        type="text"
                        name="q"
                        value="{{ $filters['q'] ?? '' }}"
                        placeholder="SKU or product name"
                        class="rounded-lg border border-slate-300 px-3 py-2 text-sm"
                    >
                </div>
                <div class="flex flex-col gap-1">
                    <label for="stock-filter-location" class="text-xs font-medium text-slate-600">Branch</label>
                    <select id="stock-filter-location" name="location_id" class="rounded-lg border border-slate-300 px-3 py-2 text-sm">
                        <option value="">All my branches</option>
                        @foreach ($locations as $loc)
                            <option value="{{ $loc->id }}" @selected((int) ($filters['location_id'] ?? 0) === (int) $loc->id)>{{ $loc->name }} ({{ $loc->code }})</option>
                        @endforeach
                    </select>
                </div>
                <div class="flex flex-col gap-1">
                    <label for="stock-filter-serialized" class="text-xs font-medium text-slate-600">Serialized</label>
                    <select id="stock-filter-serialized" name="serialized" class="rounded-lg border border-slate-300 px-3 py-2 text-sm">
                        <option value="" @selected($filters['serialized'] === null)>All products</option>
                        <option value="1" @selected($filters['serialized'] === true)>Serialized only</option>
                        <option value="0" @selected($filters['serialized'] === false)>Non-serialized only</option>
                    </select>
                </div>
            </div>
            <x-index-filter-actions :reset-url="route('inventory.stock-levels.index')" submit-label="Apply filters" />
        </form>
    </div>

    <x-index-table-shell title="Stock levels" :meta="$rows->total().' rows'">
        <table class="index-data-table min-w-[48rem] sm:min-w-full whitespace-nowrap">
            <thead class="border-b border-slate-200 bg-slate-50 text-left text-xs font-semibold uppercase tracking-wider text-slate-700">
                <tr>
                    <th class="px-4 py-3">Branch</th>
                    <th class="px-4 py-3">SKU</th>
                    <th class="px-4 py-3">Product</th>
                    <th class="px-4 py-3 text-right">On hand</th>
                    <th class="px-4 py-3 text-right">Reserved</th>
                    <th class="px-4 py-3 text-right">Available</th>
                    <th class="px-4 py-3">Serialized</th>
                    <th class="px-4 py-3 text-right">Actions</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($rows as $row)
                    <tr class="border-b border-slate-100 transition-colors hover:bg-slate-50/70 last:border-0">
                        <td class="px-4 py-3">
                            <span class="font-medium text-slate-900">{{ $row->location_name }}</span>
                            <span class="ml-1 text-xs text-slate-500">{{ $row->location_code }}</span>
                        </td>
                        <td class="px-4 py-3 font-mono text-sm">{{ $row->sku }}</td>
                        <td class="px-4 py-3">
                            <a href="{{ route('products.show', $row->product_id) }}" class="font-medium text-indigo-700 hover:underline">{{ $row->product_name }}</a>
                        </td>
                        <td class="px-4 py-3 text-right tabular-nums">{{ number_format((int) $row->on_hand_qty) }}</td>
                        <td class="px-4 py-3 text-right tabular-nums">{{ number_format((int) $row->reserved_qty) }}</td>
                        <td class="px-4 py-3 text-right tabular-nums">{{ number_format((int) $row->available_qty) }}</td>
                        <td class="px-4 py-3 text-sm">{{ $row->is_serialized ? 'Yes' : 'No' }}</td>
                        <td class="px-4 py-3 text-right">
                            <a
                                href="{{ route('inventory.stock-levels.adjust', ['product_id' => $row->product_id, 'location_id' => $row->location_id]) }}"
                                class="rounded-lg bg-slate-900 px-3 py-1.5 text-xs font-semibold text-white hover:bg-slate-800"
                            >Adjust</a>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="8" class="bg-slate-50/50 px-4 py-12 text-center text-sm font-medium text-slate-500">
                            No stock rows for your filters. Try clearing branch or search filters, or post a goods receipt so the ledger has movements.
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </x-index-table-shell>
    <x-pagination-limit-selector />
    <div class="mt-4">{{ $rows->links() }}</div>
@endsection
