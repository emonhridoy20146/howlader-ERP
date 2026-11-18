@extends('layouts.app')

@section('title', 'Customer Tracking')
@section('page-title', 'Customer Tracking')

@section('content')
<div class="space-y-4">
    <p class="text-sm text-slate-600">Search a customer to view or set their house location, detailed address, directions, and site photos.</p>

    <form method="get" action="{{ route('customer-tracking.index') }}" class="flex flex-wrap items-end gap-3 rounded-2xl bg-white p-4 shadow-sm ring-1 ring-slate-200">
        <div class="min-w-[16rem] flex-1">
            <label class="mb-1 block text-xs font-medium text-slate-600">Search customer</label>
            <input
                type="search"
                name="q"
                value="{{ $filters['q'] ?? '' }}"
                placeholder="Code, name, phone, email, or address"
                class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm"
                autofocus
            >
        </div>
        <button type="submit" class="rounded-lg bg-emerald-600 px-4 py-2 text-sm font-medium text-white hover:bg-emerald-700">Search</button>
        @if (($filters['q'] ?? '') !== '')
            <a href="{{ route('customer-tracking.index') }}" class="rounded-lg border border-slate-300 px-4 py-2 text-sm text-slate-700 hover:bg-slate-50">Clear</a>
        @endif
    </form>

    <div class="overflow-x-auto rounded-2xl bg-white shadow-sm ring-1 ring-slate-200">
        <table class="min-w-full text-sm">
            <thead class="border-b border-slate-200 bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500">
                <tr>
                    <th class="px-4 py-3">Customer</th>
                    <th class="px-4 py-3">Contact</th>
                    <th class="px-4 py-3">Branch</th>
                    <th class="px-4 py-3">Location</th>
                    <th class="px-4 py-3"></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                @forelse ($customers as $customer)
                    <tr class="hover:bg-slate-50/80">
                        <td class="px-4 py-3">
                            <p class="font-medium text-slate-900">{{ $customer->name }}</p>
                            <p class="font-mono text-xs text-slate-500">{{ $customer->customer_code }}</p>
                        </td>
                        <td class="px-4 py-3 text-slate-700">
                            <p>{{ $customer->phone ?: '—' }}</p>
                            <p class="text-xs text-slate-500">{{ $customer->email ?: '' }}</p>
                        </td>
                        <td class="px-4 py-3 text-slate-700">{{ $customer->branch?->name ?? '—' }}</td>
                        <td class="px-4 py-3">
                            @if ($customer->hasTrackingLocation())
                                <span class="inline-flex rounded-full bg-emerald-50 px-2 py-0.5 text-xs font-medium text-emerald-800 ring-1 ring-emerald-200">Set</span>
                            @else
                                <span class="inline-flex rounded-full bg-slate-100 px-2 py-0.5 text-xs font-medium text-slate-600">Not set</span>
                            @endif
                        </td>
                        <td class="px-4 py-3 text-right">
                            <div class="flex flex-wrap justify-end gap-2">
                                <a href="{{ route('customer-tracking.show', $customer) }}" class="rounded-lg border border-slate-300 px-3 py-1.5 text-xs font-medium text-slate-800 hover:bg-slate-50">View</a>
                                @can('updateTracking', $customer)
                                    <a href="{{ route('customer-tracking.edit', $customer) }}" class="rounded-lg border border-emerald-300 bg-emerald-50 px-3 py-1.5 text-xs font-medium text-emerald-900 hover:bg-emerald-100">Edit</a>
                                @endcan
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="5" class="px-4 py-10 text-center text-slate-500">
                            @if (($filters['q'] ?? '') !== '')
                                No customers matched your search.
                            @else
                                Search by customer code, name, phone, or address to get started.
                            @endif
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    {{ $customers->links() }}
</div>
@endsection
