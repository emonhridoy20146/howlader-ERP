@props(['customer', 'phones' => []])

@php
    $phoneList = $phones !== [] ? $phones : $customer->normalizedPhoneNumbers();
    $primaryPhone = $phoneList[0] ?? $customer->phone;
@endphp

<div class="flex flex-wrap items-center justify-between gap-3">
    <a href="{{ route('customer-tracking.index') }}" class="inline-flex items-center gap-1.5 text-sm font-medium text-slate-600 hover:text-slate-900">
        <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M10.5 19.5 3 12m0 0 7.5-7.5M3 12h18" /></svg>
        Customer tracking
    </a>
    @can('updateTracking', $customer)
        <a href="{{ route('customer-tracking.edit', $customer) }}" class="inline-flex items-center gap-1.5 rounded-lg bg-emerald-600 px-3 py-1.5 text-sm font-medium text-white shadow-sm hover:bg-emerald-700">
            <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="m16.862 4.487 1.687-1.688a1.875 1.875 0 1 1 2.652 2.652L10.582 16.07a4.5 4.5 0 0 1-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 0 1 1.13-1.897l8.932-8.931Z" /></svg>
            Edit
        </a>
    @endcan
</div>

<section class="overflow-hidden rounded-2xl bg-white shadow-sm ring-1 ring-slate-200">
    <div class="border-b border-slate-100 bg-gradient-to-br from-slate-50 via-white to-emerald-50/40 px-4 py-4 sm:px-6">
        <div class="flex gap-4">
            @if ($customer->photoUrl())
                <img src="{{ $customer->photoUrl() }}" alt="{{ $customer->name }}" class="h-16 w-16 shrink-0 rounded-2xl object-cover ring-2 ring-white shadow-sm sm:h-20 sm:w-20">
            @else
                <div class="flex h-16 w-16 shrink-0 items-center justify-center rounded-2xl bg-white text-slate-400 ring-2 ring-white shadow-sm sm:h-20 sm:w-20">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-8 w-8" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 6a3.75 3.75 0 1 1-7.5 0 3.75 3.75 0 0 1 7.5 0ZM4.501 20.118a7.5 7.5 0 0 1 14.998 0A17.933 17.933 0 0 1 12 21.75c-2.676 0-5.216-.584-7.499-1.632Z" />
                    </svg>
                </div>
            @endif

            <div class="min-w-0 flex-1">
                <div class="flex flex-wrap items-start justify-between gap-2">
                    <div class="min-w-0">
                        <p class="text-[11px] font-semibold uppercase tracking-[0.18em] text-emerald-800/70">Customer location</p>
                        <h2 class="mt-0.5 truncate text-xl font-semibold text-slate-900 sm:text-2xl">{{ $customer->name }}</h2>
                    </div>
                    @if ($customer->hasTrackingLocation())
                        <span class="inline-flex shrink-0 items-center gap-1 rounded-full bg-emerald-100 px-2.5 py-1 text-[11px] font-semibold uppercase tracking-wide text-emerald-800 ring-1 ring-emerald-200">
                            <span class="h-1.5 w-1.5 rounded-full bg-emerald-500"></span>
                            Pin saved
                        </span>
                    @else
                        <span class="inline-flex shrink-0 rounded-full bg-amber-100 px-2.5 py-1 text-[11px] font-semibold uppercase tracking-wide text-amber-900 ring-1 ring-amber-200">No pin</span>
                    @endif
                </div>

                <dl class="mt-3 grid gap-2 text-sm sm:grid-cols-2">
                    <div class="min-w-0">
                        <dt class="text-[11px] font-medium uppercase tracking-wide text-slate-500">Code</dt>
                        <dd class="truncate font-mono font-medium text-slate-900">{{ $customer->customer_code }}</dd>
                    </div>
                    @if ($customer->branch)
                        <div class="min-w-0">
                            <dt class="text-[11px] font-medium uppercase tracking-wide text-slate-500">Branch</dt>
                            <dd class="truncate text-slate-800">{{ $customer->branch->name }}</dd>
                        </div>
                    @endif
                    @if ($primaryPhone)
                        <div class="min-w-0">
                            <dt class="text-[11px] font-medium uppercase tracking-wide text-slate-500">Phone</dt>
                            <dd>
                                <a href="tel:{{ preg_replace('/\s+/', '', $primaryPhone) }}" class="font-medium text-emerald-700 hover:text-emerald-900">{{ $primaryPhone }}</a>
                            </dd>
                        </div>
                    @endif
                    @if ($customer->coordinatesLabel())
                        <div class="min-w-0">
                            <dt class="text-[11px] font-medium uppercase tracking-wide text-slate-500">GPS</dt>
                            <dd class="truncate font-mono text-xs text-slate-800 sm:text-sm">{{ $customer->coordinatesLabel() }}</dd>
                        </div>
                    @endif
                </dl>

                <p class="mt-2 text-xs text-slate-500">Updated {{ optional($customer->updated_at)->format('M j, Y g:i A') ?: '—' }}</p>
            </div>
        </div>
    </div>

    <div class="space-y-3 px-4 py-4 sm:px-6">
        <div class="grid grid-cols-2 gap-2 sm:max-w-lg">
            @if ($primaryPhone)
                <a href="tel:{{ preg_replace('/\s+/', '', $primaryPhone) }}" class="inline-flex items-center justify-center gap-2 rounded-xl bg-slate-900 px-3 py-3 text-sm font-semibold text-white shadow-sm hover:bg-slate-800">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 6.75c0 8.284 6.716 15 15 15h2.25a2.25 2.25 0 0 0 2.25-2.25v-1.372c0-.516-.351-.966-.852-1.091l-4.423-1.106c-.44-.11-.902.055-1.173.417l-.97 1.293c-.282.376-.769.542-1.21.38a12.035 12.035 0 0 1-7.143-7.143c-.162-.441.004-.928.38-1.21l1.293-.97c.363-.271.527-.734.417-1.173L6.963 3.102a1.125 1.125 0 0 0-1.091-.852H4.5A2.25 2.25 0 0 0 2.25 4.5v2.25Z" /></svg>
                    Call
                </a>
            @else
                <div class="flex items-center justify-center rounded-xl border border-dashed border-slate-300 px-3 py-3 text-center text-xs text-slate-500">No phone</div>
            @endif
            @if ($customer->googleMapsDirectionsUrl())
                <a href="{{ $customer->googleMapsDirectionsUrl() }}" target="_blank" rel="noopener noreferrer" class="inline-flex items-center justify-center gap-2 rounded-xl bg-emerald-600 px-3 py-3 text-sm font-semibold text-white shadow-sm hover:bg-emerald-700">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M9 6.75V15m6-6v8.25m.503 3.498 4.875-2.437c.381-.19.622-.58.622-1.006V4.82c0-.836-.88-1.38-1.628-1.006l-3.869 1.934c-.317.159-.69.159-1.006 0L9.503 3.252a1.125 1.125 0 0 0-1.006 0L3.622 5.689C3.24 5.88 3 6.27 3 6.695V19.18c0 .836.88 1.38 1.628 1.006l3.869-1.934c.317-.159.69-.159 1.006 0l4.994 2.497c.317.158.69.158 1.006 0Z" /></svg>
                    Directions
                </a>
            @else
                <div class="flex items-center justify-center rounded-xl border border-dashed border-amber-200 bg-amber-50 px-3 py-3 text-center text-xs font-medium text-amber-900">Save a pin to navigate</div>
            @endif
        </div>

        <div class="flex gap-2 overflow-x-auto pb-1 [-ms-overflow-style:none] [scrollbar-width:none] [&::-webkit-scrollbar]:hidden">
            @if ($customer->whatsAppUrl())
                <a href="{{ $customer->whatsAppUrl() }}" target="_blank" rel="noopener noreferrer" class="inline-flex shrink-0 items-center gap-1.5 rounded-full border border-emerald-200 bg-emerald-50 px-3 py-1.5 text-xs font-medium text-emerald-900 hover:bg-emerald-100">WhatsApp</a>
            @endif
            @if ($customer->googleMapsUrl())
                <a href="{{ $customer->googleMapsUrl() }}" target="_blank" rel="noopener noreferrer" class="inline-flex shrink-0 items-center gap-1.5 rounded-full border border-slate-200 bg-white px-3 py-1.5 text-xs font-medium text-slate-700 hover:bg-slate-50">Google Maps</a>
            @endif
            @if ($customer->openStreetMapUrl())
                <a href="{{ $customer->openStreetMapUrl() }}" target="_blank" rel="noopener noreferrer" class="inline-flex shrink-0 items-center gap-1.5 rounded-full border border-slate-200 bg-white px-3 py-1.5 text-xs font-medium text-slate-700 hover:bg-slate-50">OpenStreetMap</a>
            @endif
            <button type="button" @click="copyText('share', @js($customer->trackingShareText()))" class="inline-flex shrink-0 items-center gap-1.5 rounded-full border border-slate-200 bg-white px-3 py-1.5 text-xs font-medium text-slate-700 hover:bg-slate-50">
                <span x-text="copied === 'share' ? 'Copied' : 'Copy details'"></span>
            </button>
            @can('view', $customer)
                <a href="{{ route('customers.show', $customer) }}" class="inline-flex shrink-0 items-center gap-1.5 rounded-full border border-slate-200 bg-white px-3 py-1.5 text-xs font-medium text-slate-700 hover:bg-slate-50">Profile</a>
            @endcan
        </div>
    </div>
</section>
