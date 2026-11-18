@extends('layouts.app')
@section('title', 'Referrer-wise Sales Report')
@section('page-title', 'Referrer-wise Sales Report')
@section('content')
<div class="space-y-4">
    <div class="flex items-center justify-between">
        <h2 class="text-lg font-semibold text-slate-800">Referrer-wise Sales Report</h2>
        <a href="{{ route('reports.index', ['report' => 'representative-wise-sales']) }}" class="text-sm font-medium text-emerald-600 hover:text-emerald-700">← Back to reports</a>
    </div>

    <x-filter-drawer>
<form method="get" action="{{ route('reports.representative-wise-sales') }}" class="flex flex-col gap-4">
        @include('reports.partials.representative-wise-sales-filter-fields', ['filters' => $filters, 'filterBranches' => $filterBranches, 'filterRepresentatives' => $filterRepresentatives])
        <x-index-filter-actions :reset-url="route('reports.representative-wise-sales')" />
    </form>
</x-filter-drawer>

    <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-5">
        <div class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
            <div class="text-sm font-medium text-slate-500">Referrers</div>
            <div class="mt-1 text-2xl font-semibold text-slate-800">{{ Number::format($kpis['total_representatives']) }}</div>
        </div>
        <div class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
            <div class="text-sm font-medium text-slate-500">Total Invoices</div>
            <div class="mt-1 text-2xl font-semibold text-slate-800">{{ Number::format($kpis['total_invoices']) }}</div>
        </div>
        <div class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
            <div class="text-sm font-medium text-slate-500">Total Revenue</div>
            <div class="mt-1 text-2xl font-semibold text-slate-800">{{ Number::format($kpis['total_revenue'], 2) }}</div>
        </div>
        <div class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
            <div class="text-sm font-medium text-slate-500">Total Collected</div>
            <div class="mt-1 text-2xl font-semibold text-emerald-600">{{ Number::format($kpis['total_collected'], 2) }}</div>
        </div>
        <div class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
            <div class="text-sm font-medium text-slate-500">Total Due</div>
            <div class="mt-1 text-2xl font-semibold text-amber-600">{{ Number::format($kpis['total_due'], 2) }}</div>
        </div>
    </div>

    <div class="rounded-2xl border border-slate-200 bg-white shadow-sm overflow-hidden" x-data="{ openReferrer: {} }">
        <div class="border-b border-slate-100 bg-slate-50/80 px-4 py-2.5 text-xs text-slate-500">
            Expand a referrer to see invoices and payment register collections (oldest first). Invoices tagged <span class="rounded-full bg-amber-100 px-1.5 py-0.5 font-semibold text-amber-800">Payment referral only</span> have no invoice referrer. <span class="rounded-full bg-violet-100 px-1.5 py-0.5 font-semibold text-violet-800">Payment collection</span> means this employee collected on another employee's invoice.
        </div>
        <div class="overflow-x-auto">
            <table class="w-full text-left text-sm text-slate-600">
                <thead class="bg-slate-50 text-xs uppercase text-slate-500">
                    <tr>
                        <th class="w-10 px-4 py-3"></th>
                        <th class="px-4 py-3 font-medium">Referrer</th>
                        <th class="px-4 py-3 font-medium text-right">Invoices Generated</th>
                        <th class="px-4 py-3 font-medium text-right">Total Revenue</th>
                        <th class="px-4 py-3 font-medium text-right">Amount Collected</th>
                        <th class="px-4 py-3 font-medium text-right">Amount Due</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-200">
                    @forelse($rows as $row)
                        @php
                            $refKey = $row->representative_id === null ? 'none' : (string) $row->representative_id;
                            $invoices = ($invoiceDetailsByReferrer ?? collect())->get($refKey, collect());
                        @endphp
                        <tr class="hover:bg-slate-50">
                            <td class="px-4 py-3">
                                @if ($invoices->isNotEmpty())
                                    <button
                                        type="button"
                                        class="rounded-md p-1 text-slate-500 hover:bg-slate-200 hover:text-slate-800"
                                        @click="openReferrer['{{ $refKey }}'] = !openReferrer['{{ $refKey }}']"
                                        :aria-expanded="openReferrer['{{ $refKey }}'] ? 'true' : 'false'"
                                        title="Show invoice details"
                                    >
                                        <svg class="h-4 w-4 transition-transform" :class="openReferrer['{{ $refKey }}'] ? 'rotate-90' : ''" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="m8.25 4.5 7.5 7.5-7.5 7.5" /></svg>
                                    </button>
                                @endif
                            </td>
                            <td class="px-4 py-3 font-medium text-slate-800">
                                <div class="flex flex-wrap items-center gap-2">
                                    <span>{{ $row->representative_id ? \App\Models\Employee::labelFromParts($row->representative_code, $row->representative_first_name, $row->representative_last_name) : 'No referrer' }}</span>
                                    @if ($row->is_payment_referrer_only ?? false)
                                        <span class="rounded-full bg-violet-100 px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-violet-800">Payment collections</span>
                                    @endif
                                </div>
                            </td>
                            <td class="whitespace-nowrap px-4 py-3 text-right">{{ Number::format($row->total_invoices) }}</td>
                            <td class="whitespace-nowrap px-4 py-3 text-right">{{ Number::format($row->total_revenue, 2) }}</td>
                            <td class="whitespace-nowrap px-4 py-3 text-right text-emerald-600">{{ Number::format($row->total_collected, 2) }}</td>
                            <td class="whitespace-nowrap px-4 py-3 text-right text-amber-600">{{ Number::format($row->total_due, 2) }}</td>
                        </tr>
                        @if ($invoices->isNotEmpty())
                            <tr x-show="openReferrer['{{ $refKey }}']" x-cloak>
                                <td colspan="6" class="bg-slate-50 px-4 py-4">
                                    @include('reports.partials.representative-wise-sales-invoice-rows', [
                                        'invoices' => $invoices,
                                        'referrerId' => $row->representative_id,
                                    ])
                                </td>
                            </tr>
                        @endif
                    @empty
                        <tr>
                            <td colspan="6" class="px-4 py-8 text-center text-slate-500">No data found matching the current filters.</td>
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
