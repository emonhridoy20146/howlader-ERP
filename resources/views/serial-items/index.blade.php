@extends('layouts.app')
@section('title', 'Serial registry')
@section('page-title', 'Serial registry')
@section('content')
@php
    $statusLabels = collect(\App\Models\SerialItem::statusOptions())->mapWithKeys(fn ($s) => [$s => ucfirst(str_replace('_', ' ', $s))]);
@endphp

<div class="space-y-4">
    @if (session('status'))
        <div class="rounded-lg bg-emerald-50 p-3 text-sm text-emerald-700">{{ session('status') }}</div>
    @endif

    <x-filter-drawer>
        <form method="get" action="{{ route('serial-items.index') }}" class="flex flex-col gap-4">
            <input type="text" name="q" value="{{ $filters['q'] }}" placeholder="Serial, mfg serial, product, SKU, ID" class="rounded-lg border border-slate-300 px-3 py-2 text-sm">
            <div>
                <label class="mb-1 block text-xs font-medium text-slate-600">Status</label>
                <select name="status" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
                    <option value="">All statuses</option>
                    @foreach ($statusLabels as $value => $label)
                        <option value="{{ $value }}" @selected($filters['status'] === $value)>{{ $label }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="mb-1 block text-xs font-medium text-slate-600">Product</label>
                @php
                    $productOptions = $filterProducts->map(fn ($p) => ['value' => $p->id, 'label' => $p->sku.' — '.$p->name])->values()->all();
                @endphp
                <x-searchable-select name="product_id" :options="$productOptions" :selected="$filters['product_id']" placeholder="All products" />
            </div>
            <div>
                <label class="mb-1 block text-xs font-medium text-slate-600">Current location</label>
                @php
                    $locationOptions = $filterLocations->map(fn ($l) => ['value' => $l->id, 'label' => $l->name])->values()->all();
                @endphp
                <x-searchable-select name="location_id" :options="$locationOptions" :selected="$filters['location_id']" placeholder="All locations" />
            </div>
            <x-index-filter-actions :reset-url="route('serial-items.index')" />
        </form>
    </x-filter-drawer>

    <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
        <x-status-card label="Total units">{{ number_format($kpis['total']) }}</x-status-card>
        <x-status-card label="In stock">{{ number_format($kpis['in_stock']) }}</x-status-card>
        <x-status-card label="Sold">{{ number_format($kpis['sold']) }}</x-status-card>
        <x-status-card label="This page">{{ number_format($kpis['filtered']) }}</x-status-card>
    </div>

    <x-index-table-shell title="Serialized units" :meta="$serials->total().' in this list'">
        <table class="index-data-table min-w-[48rem] sm:min-w-full whitespace-nowrap">
            <thead class="border-b border-slate-200 bg-slate-50 text-left text-xs font-semibold uppercase tracking-wider text-slate-700">
                <tr>
                    <th class="px-4 py-3">Serial</th>
                    <th class="px-4 py-3">Product</th>
                    <th class="px-4 py-3">Status</th>
                    <th class="px-4 py-3">Location</th>
                    <th class="px-4 py-3 text-right">Cost</th>
                    <th class="px-4 py-3"></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                @forelse ($serials as $serial)
                    <tr class="hover:bg-slate-50/70">
                        <td class="px-4 py-3">
                            <a href="{{ route('serial-items.show', $serial) }}" class="font-mono text-sm font-medium text-indigo-600 hover:text-indigo-800">{{ $serial->serial_number }}</a>
                            @if ($serial->manufacturer_serial)
                                <p class="text-xs text-slate-500">MFG: {{ $serial->manufacturer_serial }}</p>
                            @endif
                        </td>
                        <td class="px-4 py-3">
                            @if ($serial->product)
                                <a href="{{ route('products.show', $serial->product) }}" class="text-sm font-medium text-slate-900 hover:text-indigo-700">{{ $serial->product->name }}</a>
                                <p class="text-xs text-slate-500">{{ $serial->product->sku }}</p>
                            @else
                                <span class="text-slate-400">—</span>
                            @endif
                        </td>
                        <td class="px-4 py-3">
                            <span class="inline-flex rounded-full bg-slate-100 px-2 py-0.5 text-xs font-medium capitalize text-slate-700">{{ str_replace('_', ' ', $serial->status) }}</span>
                        </td>
                        <td class="px-4 py-3 text-sm text-slate-700">
                            @if ($serial->location)
                                {{ $serial->location->name }}
                                <span class="text-xs text-slate-500">({{ $serial->location->code }})</span>
                            @else
                                <span class="text-slate-400">—</span>
                            @endif
                        </td>
                        <td class="px-4 py-3 text-right tabular-nums text-sm text-slate-700">
                            {{ $serial->purchase_cost !== null ? number_format((float) $serial->purchase_cost, 2) : '—' }}
                        </td>
                        <td class="px-4 py-3 text-right">
                            <a href="{{ route('serial-items.show', $serial) }}" class="text-sm font-medium text-indigo-600 hover:text-indigo-800">View</a>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6" class="bg-slate-50/50 px-4 py-12 text-center text-sm font-medium text-slate-500">No serial units match the filters.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </x-index-table-shell>

    <div>{{ $serials->links() }}</div>
</div>
@endsection
