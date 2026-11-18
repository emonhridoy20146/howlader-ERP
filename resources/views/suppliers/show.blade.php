@extends('layouts.app')

@section('title', 'Supplier Details')
@section('page-title', 'Supplier Details')

@section('content')
<div class="space-y-5">
    @php $phones = $supplier->normalizedPhoneNumbers(); @endphp
    <section class="rounded-2xl bg-white p-5 shadow-sm ring-1 ring-slate-200">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div>
                <p class="text-xs uppercase tracking-[0.2em] text-slate-500">Supplier</p>
                <h2 class="mt-1 text-2xl font-semibold text-slate-900">{{ $supplier->name }}</h2>
                <p class="mt-2 text-sm text-slate-600">
                    Contact: <span class="font-medium text-slate-800">{{ $supplier->contact_person ?: '—' }}</span>
                    · Phone: <span class="font-medium text-slate-800">{{ count($phones) ? implode(', ', $phones) : '—' }}</span>
                    · Email: <span class="font-medium text-slate-800">{{ $supplier->email ?: '—' }}</span>
                </p>
            </div>
            <div class="text-right text-sm text-slate-600">
                <p>Status: <span class="font-semibold {{ $supplier->is_active ? 'text-emerald-700' : 'text-slate-500' }}">{{ $supplier->is_active ? 'Active' : 'Inactive' }}</span></p>
                <p>Tax number: <span class="font-semibold text-slate-900">{{ $supplier->tax_number ?: '—' }}</span></p>
            </div>
        </div>
        <div class="mt-4 flex flex-wrap gap-2">
            <a href="{{ route('suppliers.index') }}" class="rounded-lg border border-slate-300 px-3 py-1.5 text-sm text-slate-700 hover:bg-slate-100">Back</a>
            <a href="{{ route('suppliers.index', ['drawer' => 'edit', 'edit' => $supplier->id]) }}" class="rounded-lg border border-blue-200 bg-blue-50 px-3 py-1.5 text-sm font-medium text-blue-700 hover:bg-blue-100">Edit Supplier</a>
        </div>
        @if ($supplier->address)
            <p class="mt-4 rounded-lg bg-slate-50 p-3 text-sm text-slate-700">{{ $supplier->address }}</p>
        @endif
    </section>

    <section class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
        <div class="rounded-2xl bg-white p-4 shadow-sm ring-1 ring-slate-200">
            <p class="text-xs uppercase tracking-[0.16em] text-slate-500">PO Count</p>
            <p class="mt-2 text-2xl font-semibold text-slate-900">{{ $totals['purchase_orders'] }}</p>
        </div>
        <div class="rounded-2xl bg-white p-4 shadow-sm ring-1 ring-slate-200">
            <p class="text-xs uppercase tracking-[0.16em] text-slate-500">Active PO</p>
            <p class="mt-2 text-2xl font-semibold text-slate-900">{{ $totals['purchase_orders_active'] }}</p>
        </div>
        <div class="rounded-2xl bg-white p-4 shadow-sm ring-1 ring-slate-200">
            <p class="text-xs uppercase tracking-[0.16em] text-slate-500">PO Total</p>
            <p class="mt-2 text-2xl font-semibold text-slate-900">{{ number_format($totals['purchase_orders_grand_total'], 2) }}</p>
        </div>
        <div class="rounded-2xl bg-amber-50/70 p-4 shadow-sm ring-1 ring-amber-200">
            <p class="text-xs uppercase tracking-[0.16em] text-amber-900">PO Due</p>
            <p class="mt-2 text-2xl font-semibold text-amber-950">{{ number_format($totals['purchase_orders_due'], 2) }}</p>
        </div>
    </section>

    <section class="rounded-2xl bg-white p-5 shadow-sm ring-1 ring-slate-200">
        <h3 class="text-base font-semibold text-slate-900">Purchase Orders</h3>
        <div class="mt-3 overflow-x-auto">
            <table class="min-w-full text-sm whitespace-nowrap">
                <thead class="bg-slate-50 border-b border-slate-200 text-left text-xs font-semibold uppercase tracking-wider text-slate-700">
                    <tr>
                        <th class="px-3 py-2">PO No</th>
                        <th class="px-3 py-2">Location</th>
                        <th class="px-3 py-2">Status</th>
                        <th class="px-3 py-2">Ordered</th>
                        <th class="px-3 py-2 text-right">Grand total</th>
                        <th class="px-3 py-2 text-right">Paid</th>
                        <th class="px-3 py-2 text-right">Due</th>
                        <th class="px-3 py-2 text-right">Action</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($purchaseOrders as $po)
                        @php
                            $supplierPaid = (float) ($po->payments_sum_amount ?? 0);
                            $extraCosts = (float) ($po->additional_cost_amount ?? 0);
                            $paid = round($supplierPaid + $extraCosts, 2);
                            $due = max(0, round((float) $po->total_amount - $paid, 2));
                        @endphp
                        <tr class="border-b border-slate-100 transition-colors hover:bg-slate-50/70 last:border-0">
                            <td class="px-3 py-2 font-mono">{{ $po->po_number }}</td>
                            <td class="px-3 py-2">{{ $po->destinationLocation?->name }}</td>
                            <td class="px-3 py-2">{{ ucfirst(str_replace('_', ' ', $po->status)) }}</td>
                            <td class="px-3 py-2">{{ optional($po->ordered_at)->format('Y-m-d') }}</td>
                            <td class="px-3 py-2 text-right">{{ number_format((float) $po->total_amount, 2) }}</td>
                            <td class="px-3 py-2 text-right">{{ number_format($paid, 2) }}</td>
                            <td class="px-3 py-2 text-right font-medium {{ $due > 0.009 ? 'text-amber-800' : 'text-slate-500' }}">{{ number_format($due, 2) }}</td>
                            <td class="px-3 py-2 text-right">
                                <a href="{{ route('purchase-orders.show', $po) }}" class="text-blue-600 hover:underline">View PO</a>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="8" class="px-3 py-4 text-center text-slate-500">No purchase orders found for this supplier.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="mt-4">{{ $purchaseOrders->links() }}</div>
    </section>
</div>
@endsection
