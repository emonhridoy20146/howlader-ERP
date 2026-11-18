<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>@yield('title', $vendor['name'].' — Shop')</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-screen bg-slate-50 text-slate-900 antialiased">
    @php
        $whatsappDigits = $vendor['phone'] ? preg_replace('/\D+/', '', $vendor['phone']) : null;
    @endphp
    <div
        x-data="{
            contactOpen: false,
            contactProduct: null,
            vendor: @js($vendor),
            whatsappDigits: @js($whatsappDigits),
            openContact(product) { this.contactProduct = product; this.contactOpen = true; },
            closeContact() { this.contactOpen = false; },
            get message() {
                return this.contactProduct
                    ? 'Hi, I am interested in \'' + this.contactProduct.name + '\' (SKU: ' + this.contactProduct.sku + '). Is it available?'
                    : '';
            },
            get whatsappHref() {
                return this.whatsappDigits ? 'https://wa.me/' + this.whatsappDigits + '?text=' + encodeURIComponent(this.message) : null;
            },
            get mailHref() {
                return this.vendor.email ? 'mailto:' + this.vendor.email + '?subject=' + encodeURIComponent('Product inquiry: ' + (this.contactProduct ? this.contactProduct.name : '')) + '&body=' + encodeURIComponent(this.message) : null;
            },
            get telHref() {
                return this.vendor.phone ? 'tel:' + this.whatsappDigits : null;
            },
        }"
    >
        {{-- Top accent bar --}}
        <div class="h-1.5 bg-gradient-to-r from-amber-400 via-rose-500 to-indigo-600"></div>

        {{-- Header --}}
        <header class="border-b border-slate-200 bg-white">
            <div class="mx-auto flex max-w-7xl flex-wrap items-center justify-between gap-4 px-4 py-4 sm:px-6 lg:px-8">
                <a href="{{ route('storefront.index') }}" class="flex items-center gap-3">
                    @if ($vendor['logo_path'])
                        <img src="{{ asset('storage/'.$vendor['logo_path']) }}" alt="{{ $vendor['name'] }}" class="h-10 w-auto object-contain">
                    @else
                        <span class="flex h-10 w-10 items-center justify-center rounded-xl bg-gradient-to-br from-indigo-600 to-amber-500 text-sm font-bold text-white">
                            {{ Str::substr($vendor['name'], 0, 1) }}
                        </span>
                    @endif
                    <div>
                        <p class="text-lg font-semibold tracking-tight text-slate-900">{{ $vendor['name'] }}</p>
                        <p class="text-xs text-slate-500">Product catalog</p>
                    </div>
                </a>
                <div class="flex flex-wrap items-center gap-3 text-sm">
                    @if ($vendor['phone'])
                        <a href="tel:{{ $whatsappDigits }}" class="inline-flex items-center gap-1.5 rounded-lg border border-slate-200 px-3 py-1.5 font-medium text-slate-700 hover:bg-slate-50">
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" class="h-4 w-4"><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 6.75c0 8.284 6.716 15 15 15h1.5a2.25 2.25 0 002.25-2.25v-1.372c0-.516-.351-.966-.852-1.091l-4.423-1.106a1.125 1.125 0 00-1.173.417l-.97 1.293a11.25 11.25 0 01-6.223-6.224l1.293-.97a1.125 1.125 0 00.417-1.173L6.963 3.852a1.125 1.125 0 00-1.091-.852H4.5A2.25 2.25 0 002.25 5.25v1.5z" /></svg>
                            {{ $vendor['phone'] }}
                        </a>
                    @endif
                    @if ($vendor['email'])
                        <a href="mailto:{{ $vendor['email'] }}" class="hidden items-center gap-1.5 rounded-lg border border-slate-200 px-3 py-1.5 font-medium text-slate-700 hover:bg-slate-50 sm:inline-flex">
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" class="h-4 w-4"><path stroke-linecap="round" stroke-linejoin="round" d="M21.75 6.75v10.5a2.25 2.25 0 01-2.25 2.25h-15a2.25 2.25 0 01-2.25-2.25V6.75m19.5 0A2.25 2.25 0 0019.5 4.5h-15a2.25 2.25 0 00-2.25 2.25m19.5 0v.243a2.25 2.25 0 01-1.07 1.916l-7.5 4.615a2.25 2.25 0 01-2.36 0L3.32 8.91a2.25 2.25 0 01-1.07-1.916V6.75" /></svg>
                            {{ $vendor['email'] }}
                        </a>
                    @endif
                </div>
            </div>
        </header>

        @yield('content')

        <footer class="border-t border-slate-200 bg-slate-900 py-8 text-center text-xs text-slate-400">
            <p class="text-sm font-semibold text-white">{{ $vendor['name'] }}</p>
            <p class="mt-2">
                {{ $vendor['address'] }}
                @if ($vendor['address'] && ($vendor['phone'] || $vendor['email']))
                    &middot;
                @endif
                @if ($vendor['phone']){{ $vendor['phone'] }}@endif
                @if ($vendor['phone'] && $vendor['email']) &middot; @endif
                @if ($vendor['email']){{ $vendor['email'] }}@endif
            </p>
        </footer>

        {{-- Contact vendor modal --}}
        <div x-cloak x-show="contactOpen" class="fixed inset-0 z-50" x-transition.opacity>
            <button type="button" class="absolute inset-0 bg-slate-900/50" @click="closeContact()" aria-label="Close"></button>
            <div class="absolute inset-x-0 bottom-0 mx-auto w-full max-w-md rounded-t-2xl bg-white p-6 shadow-2xl sm:inset-y-0 sm:my-auto sm:h-fit sm:rounded-2xl">
                <div class="flex items-start justify-between">
                    <div>
                        <p class="text-xs font-medium uppercase tracking-wide text-slate-500">Contact vendor about</p>
                        <h3 class="mt-0.5 text-base font-semibold text-slate-900" x-text="contactProduct ? contactProduct.name : ''"></h3>
                        <p class="text-xs text-slate-500" x-text="contactProduct ? 'SKU: ' + contactProduct.sku : ''"></p>
                    </div>
                    <button type="button" class="text-slate-400 hover:text-slate-600" @click="closeContact()" aria-label="Close">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" class="h-5 w-5"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" /></svg>
                    </button>
                </div>
                <div class="mt-5 space-y-2">
                    <template x-if="whatsappHref">
                        <a :href="whatsappHref" target="_blank" rel="noopener noreferrer" class="flex items-center gap-3 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-semibold text-emerald-800 hover:bg-emerald-100">
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" class="h-5 w-5"><path d="M12.04 2c-5.52 0-10 4.48-10 10 0 1.77.46 3.45 1.28 4.9L2 22l5.25-1.38A9.96 9.96 0 0012.04 22c5.52 0 10-4.48 10-10s-4.48-10-10-10zm0 18.2c-1.6 0-3.13-.43-4.46-1.24l-.32-.19-3.12.82.83-3.04-.21-.32A8.18 8.18 0 013.84 12c0-4.53 3.68-8.2 8.2-8.2s8.2 3.67 8.2 8.2-3.67 8.2-8.2 8.2z"/></svg>
                            WhatsApp the vendor
                        </a>
                    </template>
                    <template x-if="telHref">
                        <a :href="telHref" class="flex items-center gap-3 rounded-xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm font-semibold text-slate-800 hover:bg-slate-100">
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" class="h-5 w-5"><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 6.75c0 8.284 6.716 15 15 15h1.5a2.25 2.25 0 002.25-2.25v-1.372c0-.516-.351-.966-.852-1.091l-4.423-1.106a1.125 1.125 0 00-1.173.417l-.97 1.293a11.25 11.25 0 01-6.223-6.224l1.293-.97a1.125 1.125 0 00.417-1.173L6.963 3.852a1.125 1.125 0 00-1.091-.852H4.5A2.25 2.25 0 002.25 5.25v1.5z" /></svg>
                            Call <span x-text="vendor.phone"></span>
                        </a>
                    </template>
                    <template x-if="mailHref">
                        <a :href="mailHref" class="flex items-center gap-3 rounded-xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm font-semibold text-slate-800 hover:bg-slate-100">
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" class="h-5 w-5"><path stroke-linecap="round" stroke-linejoin="round" d="M21.75 6.75v10.5a2.25 2.25 0 01-2.25 2.25h-15a2.25 2.25 0 01-2.25-2.25V6.75m19.5 0A2.25 2.25 0 0019.5 4.5h-15a2.25 2.25 0 00-2.25 2.25m19.5 0v.243a2.25 2.25 0 01-1.07 1.916l-7.5 4.615a2.25 2.25 0 01-2.36 0L3.32 8.91a2.25 2.25 0 01-1.07-1.916V6.75" /></svg>
                            Email <span x-text="vendor.email"></span>
                        </a>
                    </template>
                    <p x-show="!whatsappHref && !telHref && !mailHref" class="text-sm text-slate-500">
                        No vendor contact details are configured yet. Add a phone or email in Company Pad settings.
                    </p>
                </div>
            </div>
        </div>
    </div>
</body>
</html>
