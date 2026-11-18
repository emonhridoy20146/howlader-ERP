@extends('layouts.app')

@section('title', 'Goods Receipt Details')
@section('page-title', 'Goods Receipt Details')

@section('content')
    <div class="space-y-5">
        <section class="rounded-2xl bg-white p-5 shadow-sm ring-1 ring-slate-200">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <p class="text-xs uppercase tracking-[0.2em] text-slate-500">Goods Receipt</p>
                    <h2 class="mt-1 font-mono text-2xl font-semibold text-slate-900">{{ $goodsReceipt->grn_number }}</h2>
                    <p class="mt-2 text-sm text-slate-600">
                        Supplier: <span class="font-medium text-slate-800">{{ $goodsReceipt->supplier?->name }}</span>
                        · Location: <span class="font-medium text-slate-800">{{ $goodsReceipt->location?->name }}</span>
                    </p>
                    @if($goodsReceipt->purchaseOrder)
                        <p class="mt-1 text-sm text-slate-600">
                            PO:
                            <a href="{{ route('purchase-orders.show', $goodsReceipt->purchaseOrder) }}" class="font-medium text-blue-600 hover:underline">{{ $goodsReceipt->purchaseOrder->po_number }}</a>
                        </p>
                    @endif
                </div>
                <div class="text-right text-sm text-slate-600">
                    <p>Status: <span class="font-semibold text-slate-900">{{ ucfirst($goodsReceipt->status) }}</span></p>
                    <p>Received: <span class="font-semibold text-slate-900">{{ $goodsReceipt->received_at }}</span></p>
                    <p>Delivery Note: <span class="font-semibold text-slate-900">{{ $goodsReceipt->delivery_note_ref ?? '-' }}</span></p>
                    <p class="mt-3 flex flex-wrap items-center justify-end gap-2">
                        @can('delete', $goodsReceipt)
                            <form action="{{ route('goods-receipts.destroy', $goodsReceipt) }}" method="post" class="inline" data-confirm="Delete this goods receipt? Posted stock/journal effects will be rolled back first.">
                                @csrf
                                @method('DELETE')
                                <button type="submit" class="inline-flex rounded-lg border border-rose-200 bg-rose-50 px-3 py-1.5 text-xs font-medium text-rose-700 hover:bg-rose-100">Delete</button>
                            </form>
                        @endcan
                        <a href="{{ route('goods-receipts.receipt', $goodsReceipt) }}" target="_blank" rel="noopener noreferrer" class="inline-flex rounded-lg border border-emerald-200 bg-emerald-50 px-3 py-1.5 text-xs font-medium text-emerald-700 hover:bg-emerald-100">Print receipt</a>
                        @if ($totalSerials > 0)
                            <a href="{{ route('goods-receipts.labels', $goodsReceipt) }}" target="_blank" rel="noopener noreferrer" class="inline-flex rounded-lg border border-slate-300 bg-white px-3 py-1.5 text-xs font-medium text-slate-800 shadow-sm hover:bg-slate-50">Product labels</a>
                        @endif
                    </p>
                </div>
            </div>
        </section>

        <section class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
            <div class="rounded-2xl bg-white p-4 shadow-sm ring-1 ring-slate-200">
                <p class="text-xs uppercase tracking-[0.16em] text-slate-500">Lines</p>
                <p class="mt-2 text-2xl font-semibold text-slate-900">{{ $totalLines }}</p>
            </div>
            <div class="rounded-2xl bg-white p-4 shadow-sm ring-1 ring-slate-200">
                <p class="text-xs uppercase tracking-[0.16em] text-slate-500">Qty Received</p>
                <p class="mt-2 text-2xl font-semibold text-slate-900">{{ $totalQtyReceived }}</p>
            </div>
            <div class="rounded-2xl bg-white p-4 shadow-sm ring-1 ring-slate-200">
                <p class="text-xs uppercase tracking-[0.16em] text-slate-500">Serials Captured</p>
                <p class="mt-2 text-2xl font-semibold text-slate-900">{{ $totalSerials }}</p>
            </div>
            <div class="rounded-2xl bg-white p-4 shadow-sm ring-1 ring-slate-200">
                <p class="text-xs uppercase tracking-[0.16em] text-slate-500">Total Value</p>
                <p class="mt-2 text-2xl font-semibold text-slate-900">{{ number_format($totalValue, 2) }}</p>
            </div>
        </section>

        <section class="rounded-2xl bg-white p-5 shadow-sm ring-1 ring-slate-200">
            <h3 class="text-base font-semibold text-slate-900">Goods Receipt Lines</h3>
            <div class="mt-3 overflow-x-auto">
                <table class="min-w-full text-sm whitespace-nowrap">
                    <thead class="bg-slate-50 border-b border-slate-200 text-left text-xs font-semibold uppercase tracking-wider text-slate-700">
                        <tr>
                            <th class="px-3 py-2">Product</th>
                            <th class="px-3 py-2">Qty Received</th>
                            <th class="px-3 py-2">Unit Cost</th>
                            <th class="px-3 py-2">Line Value</th>
                            <th class="px-3 py-2">Serials</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($goodsReceipt->lines as $line)
                            <tr class="border-t border-slate-200 align-top">
                                <td class="px-3 py-2">{{ $line->product?->sku }} - {{ $line->product?->name }}</td>
                                <td class="px-3 py-2">{{ $line->qty_received }}</td>
                                <td class="px-3 py-2">{{ number_format($line->unit_cost, 2) }}</td>
                                <td class="px-3 py-2">{{ number_format($line->qty_received * $line->unit_cost, 2) }}</td>
                                <td class="px-3 py-2">
                                    @if (! $line->product?->is_serialized)
                                        <span class="text-slate-500">Not required (non-serialized)</span>
                                    @elseif ($line->serials->isEmpty())
                                        <span class="text-amber-700">Missing serials</span>
                                    @else
                                        <div class="max-h-36 overflow-auto rounded border border-slate-200 p-2">
                                            <ul class="space-y-1 font-mono text-xs text-slate-700">
                                                @foreach ($line->serials as $serial)
                                                    <li>
                                                        {{ $serial->serial_number }}
                                                        <span class="font-sans text-slate-500">[MFG: {{ $serial->manufacturer_serial ?: 'N/A' }}] ({{ $serial->status }})</span>
                                                    </li>
                                                @endforeach
                                            </ul>
                                        </div>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="5" class="px-3 py-4 text-center text-slate-500">No lines found.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </section>
    </div>
@endsection
