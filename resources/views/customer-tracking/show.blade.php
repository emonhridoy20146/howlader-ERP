@extends('layouts.app')

@section('title', $customer->name.' — Tracking')
@section('page-title', 'Customer Tracking')

@section('content')
@php
    $phones = $customer->normalizedPhoneNumbers();
    $hasTrackingContent = $customer->hasTrackingLocation()
        || filled($customer->detailed_address)
        || filled($customer->location_instructions)
        || $customer->houseImageUrl()
        || $customer->locationImageUrl();
@endphp

<div
    class="space-y-5"
    x-data="{
        photoLightbox: { src: null, alt: '' },
        copied: '',
        openPhoto(src, alt) {
            this.photoLightbox = { src, alt: alt || '' };
            document.body.classList.add('overflow-hidden');
        },
        closePhoto() {
            this.photoLightbox = { src: null, alt: '' };
            document.body.classList.remove('overflow-hidden');
        },
        async copyText(key, text) {
            try {
                await navigator.clipboard.writeText(text);
                this.copied = key;
                setTimeout(() => { if (this.copied === key) this.copied = ''; }, 2000);
            } catch (e) {
                window.prompt('Copy this text:', text);
            }
        },
    }"
>
    @if (session('status'))
        <div class="rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-900">{{ session('status') }}</div>
    @endif

    @include('customer-tracking.partials.show-hero', ['customer' => $customer, 'phones' => $phones])

    <section class="overflow-hidden rounded-2xl bg-white shadow-sm ring-1 ring-slate-200">
        @include('customer-tracking.partials.readonly-map', [
            'customer' => $customer,
            'mapLat' => $mapLat,
            'mapLng' => $mapLng,
            'mapZoom' => $mapZoom,
            'mapHeightClass' => 'h-[24rem] md:h-[32rem] lg:h-[36rem]',
        ])
    </section>

    @if (! $hasTrackingContent)
        <section class="rounded-2xl border border-dashed border-slate-300 bg-slate-50 px-6 py-8 text-center">
            <p class="text-sm font-medium text-slate-800">No address, directions, or photos saved yet</p>
            <p class="mt-1 text-sm text-slate-600">Use edit tracking to add a GPS pin, detailed address, finding instructions, and site photos.</p>
            @can('updateTracking', $customer)
                <a href="{{ route('customer-tracking.edit', $customer) }}" class="mt-4 inline-flex rounded-lg bg-emerald-600 px-4 py-2 text-sm font-medium text-white hover:bg-emerald-700">Set up tracking</a>
            @endcan
        </section>
    @else
        <div class="grid gap-5 lg:grid-cols-3">
            <section class="rounded-2xl bg-white p-5 shadow-sm ring-1 ring-slate-200">
                <div class="flex items-start justify-between gap-2">
                    <h3 class="text-xs font-semibold uppercase tracking-wide text-slate-500">GPS coordinates</h3>
                    @if ($customer->coordinatesLabel())
                        <button type="button" @click="copyText('coords', @js($customer->coordinatesLabel()))" class="text-xs font-medium text-emerald-700 hover:text-emerald-900" x-text="copied === 'coords' ? 'Copied' : 'Copy'"></button>
                    @endif
                </div>
                <p class="mt-2 font-mono text-sm text-slate-900">{{ $customer->coordinatesLabel() ?: '—' }}</p>
                @if ($customer->branch)
                    <p class="mt-4 text-xs font-semibold uppercase tracking-wide text-slate-500">Business branch</p>
                    <p class="mt-1 text-sm text-slate-800">{{ $customer->branch->name }}</p>
                    @if ($customer->branch->address)
                        <p class="mt-1 text-sm text-slate-600">{{ $customer->branch->address }}</p>
                    @endif
                @endif
            </section>

            <section class="rounded-2xl bg-white p-5 shadow-sm ring-1 ring-slate-200 lg:col-span-2">
                <div class="flex items-start justify-between gap-2">
                    <h3 class="text-xs font-semibold uppercase tracking-wide text-slate-500">Detailed address</h3>
                    @if (filled($customer->detailed_address) || filled($customer->address))
                        <button type="button" @click="copyText('address', @js(trim((string) ($customer->detailed_address ?: $customer->address))))" class="text-xs font-medium text-emerald-700 hover:text-emerald-900" x-text="copied === 'address' ? 'Copied' : 'Copy'"></button>
                    @endif
                </div>
                @if ($customer->address && $customer->detailed_address && $customer->address !== $customer->detailed_address)
                    <p class="mt-2 text-xs text-slate-500">Registered: {{ $customer->address }}</p>
                @endif
                <p class="mt-2 whitespace-pre-wrap text-sm leading-relaxed text-slate-800">{{ $customer->detailed_address ?: ($customer->address ?: '—') }}</p>
            </section>
        </div>

        @if (filled($customer->location_instructions))
            <section class="overflow-hidden rounded-2xl bg-amber-50 shadow-sm ring-1 ring-amber-200">
                <div class="flex items-center justify-between gap-3 border-b border-amber-200/80 bg-amber-100/50 px-5 py-3">
                    <div class="flex items-center gap-2">
                        <span class="flex h-8 w-8 items-center justify-center rounded-lg bg-amber-200/80 text-amber-900">
                            <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m0-10.036A11.959 11.959 0 0 1 3.598 6 11.99 11.99 0 0 0 3 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016Z" /></svg>
                        </span>
                        <h3 class="text-sm font-semibold text-amber-950">Finding instructions</h3>
                    </div>
                    <button type="button" @click="copyText('instructions', @js($customer->location_instructions))" class="text-xs font-medium text-amber-900 hover:text-amber-950" x-text="copied === 'instructions' ? 'Copied' : 'Copy'"></button>
                </div>
                <p class="whitespace-pre-wrap px-5 py-4 text-sm leading-relaxed text-amber-950">{{ $customer->location_instructions }}</p>
            </section>
        @endif

        <section class="grid gap-5 md:grid-cols-2">
            <div class="group overflow-hidden rounded-2xl bg-white shadow-sm ring-1 ring-slate-200 transition hover:ring-emerald-200">
                <div class="border-b border-slate-100 px-5 py-4">
                    <h3 class="text-sm font-semibold text-slate-900">House image</h3>
                    <p class="mt-1 text-xs text-slate-500">Photo of the customer&apos;s house or building</p>
                </div>
                <div class="p-5">
                    @if ($customer->houseImageUrl())
                        <button type="button" @click="openPhoto(@js($customer->houseImageUrl()), @js('House — '.$customer->name))" class="block w-full focus:outline-none focus-visible:ring-2 focus-visible:ring-emerald-500 focus-visible:ring-offset-2">
                            <img src="{{ $customer->houseImageUrl() }}" alt="House photo for {{ $customer->name }}" class="max-h-80 w-full rounded-xl object-cover ring-1 ring-slate-200 transition hover:ring-2 hover:ring-emerald-400/70">
                        </button>
                    @else
                        <div class="flex h-48 items-center justify-center rounded-xl bg-slate-50 text-sm text-slate-500 ring-1 ring-slate-200">No house image</div>
                    @endif
                </div>
            </div>
            <div class="group overflow-hidden rounded-2xl bg-white shadow-sm ring-1 ring-slate-200 transition hover:ring-emerald-200">
                <div class="border-b border-slate-100 px-5 py-4">
                    <h3 class="text-sm font-semibold text-slate-900">Location image</h3>
                    <p class="mt-1 text-xs text-slate-500">Gate, road, or nearby landmark</p>
                </div>
                <div class="p-5">
                    @if ($customer->locationImageUrl())
                        <button type="button" @click="openPhoto(@js($customer->locationImageUrl()), @js('Location — '.$customer->name))" class="block w-full focus:outline-none focus-visible:ring-2 focus-visible:ring-emerald-500 focus-visible:ring-offset-2">
                            <img src="{{ $customer->locationImageUrl() }}" alt="Location photo for {{ $customer->name }}" class="max-h-80 w-full rounded-xl object-cover ring-1 ring-slate-200 transition hover:ring-2 hover:ring-emerald-400/70">
                        </button>
                    @else
                        <div class="flex h-48 items-center justify-center rounded-xl bg-slate-50 text-sm text-slate-500 ring-1 ring-slate-200">No location image</div>
                    @endif
                </div>
            </div>
        </section>
    @endif

    <template x-teleport="body">
        <div
            x-cloak
            x-show="photoLightbox.src"
            x-transition.opacity
            class="fixed inset-0 z-[1100] flex items-center justify-center bg-slate-950/85 p-4 backdrop-blur-[2px]"
            @keydown.escape.window="closePhoto()"
            @click.self="closePhoto()"
            role="dialog"
            aria-modal="true"
        >
            <button type="button" class="absolute right-4 top-4 rounded-full bg-white/15 p-2.5 text-white hover:bg-white/25 focus:outline-none focus-visible:ring-2 focus-visible:ring-white" @click="closePhoto()" aria-label="Close">
                <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12" /></svg>
            </button>
            <img :src="photoLightbox.src" :alt="photoLightbox.alt" class="max-h-[min(90vh,90dvh)] max-w-full rounded-lg object-contain shadow-2xl ring-1 ring-white/10" @click.stop>
        </div>
    </template>
</div>
@endsection
