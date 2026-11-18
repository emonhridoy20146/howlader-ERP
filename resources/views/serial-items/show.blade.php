@extends('layouts.app')
@section('title', $serial->serial_number)
@section('page-title', 'Serial unit')
@section('content')
@php
    $invoice = $serial->saleInvoiceLine?->salesInvoice;
    $grn = $serial->goodsReceiptLine?->goodsReceipt;
@endphp

<div class="mb-4 flex flex-wrap items-center justify-between gap-3">
    <a href="{{ route('serial-items.index', request()->only(['q', 'status', 'product_id', 'location_id'])) }}" class="text-sm font-medium text-slate-600 hover:text-slate-900">← Serial registry</a>
    @if ($serial->product)
        <a href="{{ route('products.show', ['product' => $serial->product, 'tab' => 'serials']) }}" class="text-sm font-medium text-indigo-600 hover:text-indigo-800">Open on product →</a>
    @endif
</div>

@if (session('status'))
    <div class="mb-4 rounded-lg bg-emerald-50 p-3 text-sm text-emerald-700">{{ session('status') }}</div>
@endif

@if ($errors->any())
    <div class="mb-4 rounded-lg bg-rose-50 p-3 text-sm text-rose-700">
        <ul class="list-inside list-disc">
            @foreach ($errors->all() as $error)
                <li>{{ $error }}</li>
            @endforeach
        </ul>
    </div>
@endif

<div class="grid gap-4 lg:grid-cols-3">
    <div class="space-y-4 lg:col-span-2">
        <div class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm ring-1 ring-slate-200/60">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <p class="text-xs uppercase tracking-[0.15em] text-slate-500">Asset serial</p>
                    <h2 class="mt-1 font-mono text-lg font-semibold text-slate-900">{{ $serial->serial_number }}</h2>
                    @if ($serial->manufacturer_serial)
                        <p class="mt-1 text-sm text-slate-600">Manufacturer: <span class="font-mono">{{ $serial->manufacturer_serial }}</span></p>
                    @endif
                </div>
                <span class="inline-flex rounded-full bg-slate-100 px-2.5 py-1 text-xs font-medium capitalize text-slate-800 ring-1 ring-slate-200">{{ str_replace('_', ' ', $serial->status) }}</span>
            </div>

            <dl class="mt-6 grid gap-4 sm:grid-cols-2">
                <div>
                    <dt class="text-xs font-medium text-slate-500">Product</dt>
                    <dd class="mt-1 text-sm text-slate-900">
                        @if ($serial->product)
                            <a href="{{ route('products.show', $serial->product) }}" class="font-medium text-indigo-600 hover:text-indigo-800">{{ $serial->product->name }}</a>
                            <span class="block text-xs text-slate-500">{{ $serial->product->sku }}</span>
                        @else
                            —
                        @endif
                    </dd>
                </div>
                <div>
                    <dt class="text-xs font-medium text-slate-500">Current location</dt>
                    <dd class="mt-1 text-sm text-slate-900">{{ $serial->location?->name ?? '—' }}</dd>
                </div>
                <div>
                    <dt class="text-xs font-medium text-slate-500">Purchase cost</dt>
                    <dd class="mt-1 text-sm tabular-nums text-slate-900">{{ $serial->purchase_cost !== null ? number_format((float) $serial->purchase_cost, 2) : '—' }}</dd>
                </div>
                <div>
                    <dt class="text-xs font-medium text-slate-500">Warranty</dt>
                    <dd class="mt-1 text-sm text-slate-900">
                        @if ($serial->warranty_start_date || $serial->warranty_end_date)
                            {{ $serial->warranty_start_date?->toDateString() ?? '—' }} → {{ $serial->warranty_end_date?->toDateString() ?? '—' }}
                        @else
                            —
                        @endif
                    </dd>
                </div>
                <div>
                    <dt class="text-xs font-medium text-slate-500">Goods receipt</dt>
                    <dd class="mt-1 text-sm text-slate-900">
                        @if ($grn)
                            <a href="{{ route('goods-receipts.show', $grn) }}" class="font-medium text-indigo-600 hover:text-indigo-800">{{ $grn->grn_number }}</a>
                        @else
                            —
                        @endif
                    </dd>
                </div>
                <div>
                    <dt class="text-xs font-medium text-slate-500">Sales invoice</dt>
                    <dd class="mt-1 text-sm text-slate-900">
                        @if ($invoice)
                            <a href="{{ route('sales.invoices.show', $invoice) }}" class="font-medium text-indigo-600 hover:text-indigo-800">{{ $invoice->invoice_number }}</a>
                            @if ($invoice->customer)
                                <span class="block text-xs text-slate-500">{{ $invoice->customer->name }}</span>
                            @endif
                        @else
                            —
                        @endif
                    </dd>
                </div>
                <div>
                    <dt class="text-xs font-medium text-slate-500">Created</dt>
                    <dd class="mt-1 text-sm text-slate-900">{{ $serial->created_at?->format('Y-m-d H:i') ?? '—' }}</dd>
                </div>
                <div>
                    <dt class="text-xs font-medium text-slate-500">Last updated</dt>
                    <dd class="mt-1 text-sm text-slate-900">{{ $serial->updated_at?->format('Y-m-d H:i') ?? '—' }}</dd>
                </div>
            </dl>
        </div>
    </div>

    <div class="space-y-4">
        @if ($canUpdate)
            <div class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm ring-1 ring-slate-200/60">
                <h3 class="text-sm font-semibold text-slate-900">Change status</h3>
                <p class="mt-1 text-xs text-slate-500">Updates the serial record only. Stock ledger and invoices are not adjusted automatically.</p>
                <form method="post" action="{{ route('serial-items.update', $serial) }}" class="mt-4 space-y-3">
                    @csrf
                    @method('PUT')
                    <select name="status" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm" required>
                        @foreach ($statusOptions as $option)
                            <option value="{{ $option }}" @selected(old('status', $serial->status) === $option)>{{ ucfirst(str_replace('_', ' ', $option)) }}</option>
                        @endforeach
                    </select>
                    <button type="submit" class="w-full rounded-lg bg-slate-900 px-4 py-2 text-sm font-medium text-white hover:bg-slate-800">Save status</button>
                </form>
            </div>
        @endif

        @if ($canRemove)
            <div class="rounded-2xl border border-rose-200 bg-rose-50/50 p-5 ring-1 ring-rose-100">
                <h3 class="text-sm font-semibold text-rose-900">Remove from stock</h3>
                <p class="mt-1 text-xs text-rose-800">Writes off this unit from branch inventory (same as product serial removal).</p>
                <form method="post" action="{{ route('serial-items.destroy', $serial) }}" class="mt-4" onsubmit="return confirm('Remove {{ $serial->serial_number }} from stock? This cannot be undone from this screen.');">
                    @csrf
                    @method('DELETE')
                    <button type="submit" class="w-full rounded-lg border border-rose-300 bg-white px-4 py-2 text-sm font-medium text-rose-700 hover:bg-rose-50">Remove from stock</button>
                </form>
            </div>
        @endif
    </div>
</div>
@endsection
