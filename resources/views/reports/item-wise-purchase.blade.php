@extends('layouts.app')
@section('title', 'Item-wise Purchase Report')
@section('page-title', 'Item-wise Purchase Report')
@section('content')
<div class="space-y-4">
    <div class="flex items-center justify-between">
        <h2 class="text-lg font-semibold text-slate-800">Item-wise Purchase Report</h2>
        <a href="{{ route('reports.index', ['report' => 'item-wise-purchase']) }}" class="text-sm font-medium text-emerald-600 hover:text-emerald-700">← Back to reports</a>
    </div>

    <x-filter-drawer>
<form method="get" action="{{ route('reports.item-wise-purchase') }}" class="flex flex-col gap-4">
        @include('reports.partials.item-wise-purchase-filter-fields', ['filters' => $filters, 'filterLocations' => $filterLocations])
        <x-index-filter-actions :reset-url="route('reports.item-wise-purchase')" />
    </form>
</x-filter-drawer>

    <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
        <div class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
            <div class="text-sm font-medium text-slate-500">Items Ordered</div>
            <div class="mt-1 text-2xl font-semibold text-slate-800">{{ Number::format($kpis['total_items']) }}</div>
        </div>
        <div class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
            <div class="text-sm font-medium text-slate-500">Total Qty Ordered</div>
            <div class="mt-1 text-2xl font-semibold text-slate-800">{{ Number::format($kpis['total_qty_ordered']) }}</div>
        </div>
        <div class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
            <div class="text-sm font-medium text-slate-500">Total Qty Received</div>
            <div class="mt-1 text-2xl font-semibold text-emerald-600">{{ Number::format($kpis['total_qty_received']) }}</div>
        </div>
        <div class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
            <div class="text-sm font-medium text-slate-500">Total Purchase Value</div>
            <div class="mt-1 text-2xl font-semibold text-slate-800">{{ Number::currency($kpis['total_amount'], 'BDT') }}</div>
        </div>
    </div>

    <div class="rounded-2xl border border-slate-200 bg-white shadow-sm overflow-hidden">
        <div class="overflow-x-auto">
            <table class="w-full text-left text-sm text-slate-600">
                <thead class="bg-slate-50 text-xs uppercase text-slate-500">
                    <tr>
                        <th class="px-4 py-3 font-medium">SKU</th>
                        <th class="px-4 py-3 font-medium">Product Name</th>
                        <th class="px-4 py-3 font-medium text-right">Total Qty Ordered</th>
                        <th class="px-4 py-3 font-medium text-right">Total Qty Received</th>
                        <th class="px-4 py-3 font-medium text-right">Total Amount</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-200">
                    @forelse($rows as $row)
                        <tr class="hover:bg-slate-50">
                            <td class="whitespace-nowrap px-4 py-3">{{ $row->sku }}</td>
                            <td class="px-4 py-3 font-medium text-slate-800">{{ $row->name }}</td>
                            <td class="whitespace-nowrap px-4 py-3 text-right">{{ Number::format($row->total_qty_ordered) }}</td>
                            <td class="whitespace-nowrap px-4 py-3 text-right">{{ Number::format($row->total_qty_received) }}</td>
                            <td class="whitespace-nowrap px-4 py-3 text-right">{{ Number::currency($row->total_amount, 'BDT') }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="px-4 py-8 text-center text-slate-500">No items found matching the current filters.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @if ($rows->hasPages())
            <div class="border-t border-slate-200 px-4 py-3">
                {{ $rows->links() }}
            </div>
        @endif
    </div>
</div>
@endsection
