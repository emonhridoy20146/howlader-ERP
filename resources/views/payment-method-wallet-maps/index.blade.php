@extends('layouts.app')
@section('title', 'Payment → wallet')
@section('page-title', 'Payment method to wallet')
@php
    $listQuery = request()->only(['location_id', 'payment_method_id']);
    $drawerMode = request('drawer');
    $isEdit = $drawerMode === 'edit' && $editingMap;
    $isDrawerOpen = in_array((string) $drawerMode, ['create', 'edit'], true) || $errors->any();
    if ($drawerMode === 'edit' && ! $editingMap) {
        $isDrawerOpen = $errors->any();
    }
    $locStr = (string) (old('location_id', $mapForForm->location_id ?? '') ?? '');
    $pmStr = (string) (old('payment_method_id', $mapForForm->payment_method_id ?? '') ?? '');
    $wStr = (string) (old('wallet_id', $mapForForm->wallet_id ?? '') ?? '');
    $wmAlpineConfig = [
        'optionsUrl' => route('payment-method-wallet-maps.options'),
        'locationId' => $locStr,
        'paymentMethodId' => $pmStr,
        'walletId' => $wStr,
        'methods' => $mapMethodsOptions,
        'wallets' => $mapWalletsOptions,
    ];
@endphp
@section('content')
<div x-data="{ drawerOpen: @js($isDrawerOpen) }">
<div class="mb-6 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
    <p class="max-w-xl text-sm text-slate-600">Each row ties one <strong>branch</strong> + <strong>payment method</strong> to the <strong>wallet</strong> that receives cash in the ledger when customers pay.</p>
    @can('create', \App\Models\PaymentMethodWalletMap::class)
    <a href="{{ route('payment-method-wallet-maps.index', array_merge($listQuery, ['drawer' => 'create'])) }}" class="shrink-0 rounded-lg bg-slate-900 px-4 py-2 text-sm font-medium text-white shadow-sm hover:bg-slate-800">New mapping</a>
    @endcan
</div>

@if (session('status'))
    <div class="mb-4 rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800">{{ session('status') }}</div>
@endif

<x-filter-drawer>
<form method="get" action="{{ route('payment-method-wallet-maps.index') }}" class="flex flex-col gap-4">
    <p class="mb-3 text-xs font-semibold uppercase tracking-wide text-slate-500">Filters</p>
    <div class="flex flex-wrap items-end gap-4">
        <div>
            <label class="block text-sm font-medium text-slate-700">Branch</label>
            <select name="location_id" class="mt-1 min-w-[12rem] rounded-xl border border-slate-200 bg-white px-3 py-2.5 text-sm focus:border-slate-400 focus:outline-none focus:ring-2 focus:ring-slate-200">
                <option value="">All branches</option>
                @foreach($locations as $location)
                    <option value="{{ $location->id }}" @selected((string) ($filters['location_id'] ?? '') === (string) $location->id)>{{ $location->name }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="block text-sm font-medium text-slate-700">Payment method</label>
            <select name="payment_method_id" class="mt-1 min-w-[14rem] rounded-xl border border-slate-200 bg-white px-3 py-2.5 text-sm focus:border-slate-400 focus:outline-none focus:ring-2 focus:ring-slate-200">
                <option value="">All methods</option>
                @foreach($paymentMethodsForFilter as $pm)
                    <option value="{{ $pm->id }}" @selected((string) ($filters['payment_method_id'] ?? '') === (string) $pm->id)>{{ $pm->code }} — {{ $pm->name }}</option>
                @endforeach
            </select>
        </div>
        <button type="submit" class="rounded-lg bg-slate-800 px-4 py-2.5 text-sm font-medium text-white hover:bg-slate-700">Apply</button>
        <a href="{{ route('payment-method-wallet-maps.index') }}" class="pb-2.5 text-sm font-medium text-slate-600 underline decoration-slate-300 hover:text-slate-900">Reset</a>
    </div>
</form>
</x-filter-drawer>

<div class="grid gap-6 sm:grid-cols-2 lg:grid-cols-3">
    @forelse($maps as $map)
        <div class="group relative flex flex-col rounded-2xl border border-slate-200 bg-white p-5 shadow-sm transition-all hover:-translate-y-1 hover:border-slate-300 hover:shadow-md">
            
            <!-- Header: Branch & Actions -->
            <div class="mb-6 flex items-start justify-between">
                <div class="flex flex-col">
                    <span class="text-[10px] font-bold uppercase tracking-widest text-slate-400">Branch</span>
                    <div class="mt-1 flex items-center gap-1.5 text-sm font-semibold text-slate-800">
                        <svg class="h-4 w-4 text-slate-400" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M13.5 21v-7.5a.75.75 0 0 1 .75-.75h3a.75.75 0 0 1 .75.75V21m-4.5 0H2.36m11.14 0H18m0 0h3.64m-1.39 0V9.349M3.75 21V9.349m0 0a3.001 3.001 0 0 0 3.75-.615A2.993 2.993 0 0 0 9.75 9.75c.896 0 1.7-.393 2.25-1.016a2.993 2.993 0 0 0 2.25 1.016c.896 0 1.7-.393 2.25-1.015a3.001 3.001 0 0 0 3.75.614m-16.5 0a3.004 3.004 0 0 1-.621-4.72l1.189-1.19A1.5 1.5 0 0 1 5.378 3h13.243a1.5 1.5 0 0 1 1.06.44l1.19 1.189a3 3 0 0 1-.621 4.72M6.75 18h3.75a.75.75 0 0 0 .75-.75V13.5a.75.75 0 0 0-.75-.75H6.75a.75.75 0 0 0-.75.75v3.75c0 .414.336.75.75.75Z" />
                        </svg>
                        <span class="truncate max-w-[150px]" title="{{ $map->location?->name }}">{{ $map->location?->name }}</span>
                    </div>
                </div>
                
                <div class="flex gap-1.5 opacity-0 transition-opacity group-hover:opacity-100">
                    @can('update', $map)
                        <a href="{{ route('payment-method-wallet-maps.index', array_merge($listQuery, ['drawer' => 'edit', 'edit' => $map->id])) }}" class="flex h-7 w-7 items-center justify-center rounded bg-slate-100 text-slate-600 transition hover:bg-slate-200 hover:text-slate-900" title="Edit">
                            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m16.862 4.487 1.687-1.688a1.875 1.875 0 1 1 2.652 2.652L10.582 16.07a4.5 4.5 0 0 1-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 0 1 1.13-1.897l8.932-8.931Zm0 0L19.5 7.125M18 14v4.75A2.25 2.25 0 0 1 15.75 21H5.25A2.25 2.25 0 0 1 3 18.75V8.25A2.25 2.25 0 0 1 5.25 6H10" /></svg>
                        </a>
                    @endcan
                    @can('delete', $map)
                        <form action="{{ route('payment-method-wallet-maps.destroy', $map) }}" method="post" class="inline" data-confirm="Remove this mapping?">
                            @csrf
                            @method('DELETE')
                            <button type="submit" class="flex h-7 w-7 items-center justify-center rounded bg-rose-50 text-rose-600 transition hover:bg-rose-100 hover:text-rose-700" title="Remove">
                                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M14.74 9l-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 01-2.244 2.077H8.084a2.25 2.25 0 01-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 00-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 013.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 00-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 00-7.5 0" /></svg>
                            </button>
                        </form>
                    @endcan
                </div>
            </div>
            
            <!-- Connection Diagram -->
            <div class="relative flex items-center justify-between rounded-xl bg-slate-50 p-4 ring-1 ring-slate-100">
                <!-- Source (Payment Method) -->
                <div class="flex flex-1 flex-col items-center text-center z-10">
                    <div class="flex h-12 w-12 items-center justify-center rounded-xl bg-indigo-100 text-indigo-600 shadow-sm ring-1 ring-indigo-200">
                        <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M2.25 8.25h19.5M2.25 9h19.5m-16.5 5.25h6m-6 2.25h3m-3.75 3h15a2.25 2.25 0 0 0 2.25-2.25V6.75A2.25 2.25 0 0 0 19.5 4.5h-15a2.25 2.25 0 0 0-2.25 2.25v10.5A2.25 2.25 0 0 0 4.5 19.5Z" />
                        </svg>
                    </div>
                    <p class="mt-2 text-xs font-bold text-slate-800 line-clamp-1" title="{{ $map->paymentMethod?->name }}">{{ $map->paymentMethod?->name }}</p>
                    <p class="text-[9px] uppercase tracking-widest text-slate-500">Method</p>
                </div>
                
                <!-- Arrow line -->
                <div class="absolute left-0 right-0 top-[40px] flex items-center justify-center pointer-events-none">
                    <div class="h-[2px] w-1/2 bg-gradient-to-r from-indigo-200 via-slate-300 to-emerald-200"></div>
                </div>
                
                <!-- Arrow icon -->
                <div class="relative z-10 flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-white text-slate-400 shadow-sm ring-1 ring-slate-200">
                    <svg class="h-3 w-3" fill="none" viewBox="0 0 24 24" stroke-width="3" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M8.25 4.5l7.5 7.5-7.5 7.5" />
                    </svg>
                </div>
                
                <!-- Destination (Wallet) -->
                <div class="flex flex-1 flex-col items-center text-center z-10">
                    <div class="flex h-12 w-12 items-center justify-center rounded-xl bg-emerald-100 text-emerald-600 shadow-sm ring-1 ring-emerald-200">
                        <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M21 12a2.25 2.25 0 0 0-2.25-2.25H15a3 3 0 1 1-6 0H5.25A2.25 2.25 0 0 0 3 12m18 0v6a2.25 2.25 0 0 1-2.25 2.25H5.25A2.25 2.25 0 0 1 3 18v-6m18 0V9M3 12V9m18 0a2.25 2.25 0 0 0-2.25-2.25H5.25A2.25 2.25 0 0 0 3 9m18 0V6a2.25 2.25 0 0 0-2.25-2.25H5.25A2.25 2.25 0 0 0 3 6v3" />
                        </svg>
                    </div>
                    <p class="mt-2 text-xs font-bold text-slate-800 line-clamp-1" title="{{ $map->wallet?->name }}">{{ $map->wallet?->name }}</p>
                    <p class="text-[9px] uppercase tracking-widest text-slate-500">{{ $map->wallet?->code }}</p>
                </div>
            </div>
            
            <!-- Description footer -->
            <div class="mt-auto pt-4 text-center">
                <p class="text-xs text-slate-500">Deposits into <strong class="font-medium text-slate-700">{{ $map->wallet?->name }}</strong></p>
            </div>
        </div>
    @empty
        <div class="col-span-full rounded-2xl border border-dashed border-slate-300 bg-slate-50 py-16 text-center">
            <svg class="mx-auto h-12 w-12 text-slate-400" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" d="M7.5 21 3 16.5m0 0L7.5 12M3 16.5h13.5m0-13.5L21 7.5m0 0L16.5 12M21 7.5H7.5" />
            </svg>
            <h3 class="mt-4 text-sm font-semibold text-slate-900">No mappings found</h3>
            <p class="mt-1 text-sm text-slate-500">Create a mapping to connect payment methods to branch wallets.</p>
            @can('create', \App\Models\PaymentMethodWalletMap::class)
                <a href="{{ route('payment-method-wallet-maps.index', array_merge($listQuery, ['drawer' => 'create'])) }}" class="mt-4 inline-flex items-center rounded-lg bg-slate-900 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-slate-800">New mapping</a>
            @endcan
        </div>
    @endforelse
</div>
	<x-pagination-limit-selector />

<div class="mt-6">{{ $maps->links() }}</div>

<div x-cloak x-show="drawerOpen" class="fixed inset-0 z-40">
    <a href="{{ route('payment-method-wallet-maps.index', $listQuery) }}" class="absolute inset-0 bg-slate-900/40" aria-label="Close"></a>
    <div class="absolute inset-y-0 right-0 w-full max-w-md overflow-y-auto bg-white shadow-2xl">
        <div class="sticky top-0 flex items-center justify-between border-b border-slate-200 bg-white px-5 py-4 sm:px-6">
            <h2 class="text-lg font-semibold text-slate-900">{{ $isEdit ? 'Edit mapping' : 'New mapping' }}</h2>
            <a href="{{ route('payment-method-wallet-maps.index', $listQuery) }}" class="text-sm text-slate-600 hover:text-slate-900">Close</a>
        </div>
        <div class="p-5 sm:p-6">
            <script>
                function walletMapDrawerState(cfg) {
                    return {
                        optionsUrl: cfg.optionsUrl,
                        locationId: String(cfg.locationId ?? ''),
                        paymentMethodId: String(cfg.paymentMethodId ?? ''),
                        walletId: String(cfg.walletId ?? ''),
                        methods: Array.isArray(cfg.methods) ? cfg.methods : [],
                        wallets: Array.isArray(cfg.wallets) ? cfg.wallets : [],
                        loading: false,
                        init() {
                            if (this.locationId) {
                                this.loadOptions();
                            }
                        },
                        async loadOptions() {
                            if (!this.locationId) {
                                this.methods = [];
                                this.wallets = [];
                                this.paymentMethodId = '';
                                this.walletId = '';
                                return;
                            }
                            this.loading = true;
                            const prevPm = String(this.paymentMethodId ?? '');
                            const prevW = String(this.walletId ?? '');
                            try {
                                const params = new URLSearchParams({ location_id: String(this.locationId) });
                                if (prevPm !== '') {
                                    params.set('include_payment_method_id', prevPm);
                                }
                                if (prevW !== '') {
                                    params.set('include_wallet_id', prevW);
                                }
                                const res = await fetch(this.optionsUrl + '?' + params.toString(), {
                                    headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                                });
                                if (!res.ok) {
                                    this.methods = [];
                                    this.wallets = [];
                                    return;
                                }
                                const data = await res.json();
                                this.methods = data.payment_methods || [];
                                this.wallets = data.wallets || [];
                                await this.$nextTick();
                                const pmOk = prevPm !== '' && this.methods.some((m) => String(m.id) === prevPm);
                                this.paymentMethodId = pmOk ? prevPm : '';
                                const wOk = prevW !== '' && this.wallets.some((w) => String(w.id) === prevW);
                                this.walletId = wOk ? prevW : '';
                            } catch (e) {
                                this.methods = [];
                                this.wallets = [];
                            } finally {
                                this.loading = false;
                            }
                        },
                    };
                }
            </script>
            <form
                action="{{ $isEdit ? route('payment-method-wallet-maps.update', $editingMap) : route('payment-method-wallet-maps.store') }}"
                method="post"
                class="space-y-4"
                x-data="walletMapDrawerState(@js($wmAlpineConfig))"
            >
                @csrf
                @if ($isEdit)
                    @method('PUT')
                @endif
                <div>
                    <label for="wm-location" class="text-sm font-medium text-slate-800">Branch</label>
                    <select id="wm-location" name="location_id" x-model="locationId" @change="loadOptions()" required class="mt-1 w-full rounded-xl border border-slate-200 bg-white px-3 py-2.5 text-sm focus:border-slate-400 focus:outline-none focus:ring-2 focus:ring-slate-200" :disabled="loading">
                        <option value="">Select branch…</option>
                        @foreach($locations as $location)
                            <option value="{{ $location->id }}">{{ $location->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label for="wm-method" class="text-sm font-medium text-slate-800">Payment method</label>
                    <select id="wm-method" name="payment_method_id" x-model="paymentMethodId" required class="mt-1 w-full rounded-xl border border-slate-200 bg-white px-3 py-2.5 text-sm focus:border-slate-400 focus:outline-none focus:ring-2 focus:ring-slate-200" :disabled="loading || !locationId">
                        <option value="" x-text="!locationId ? 'Select a branch first' : 'Choose payment method…'"></option>
                        <template x-for="m in methods" :key="m.id">
                            <option :value="m.id" x-text="m.label"></option>
                        </template>
                    </select>
                </div>
                <div>
                    <label for="wm-wallet" class="text-sm font-medium text-slate-800">Wallet</label>
                    <select id="wm-wallet" name="wallet_id" x-model="walletId" required class="mt-1 w-full rounded-xl border border-slate-200 bg-white px-3 py-2.5 text-sm focus:border-slate-400 focus:outline-none focus:ring-2 focus:ring-slate-200" :disabled="loading || !locationId">
                        <option value="" x-text="!locationId ? 'Select a branch first' : 'Choose wallet…'"></option>
                        <template x-for="w in wallets" :key="w.id">
                            <option :value="w.id" x-text="w.label"></option>
                        </template>
                    </select>
                </div>
                <p x-show="loading && locationId" class="text-xs text-slate-500">Loading methods and wallets…</p>
                <div class="flex flex-wrap gap-2 border-t border-slate-200 pt-4">
                    <button type="submit" class="rounded-lg bg-slate-900 px-5 py-2.5 text-sm font-medium text-white hover:bg-slate-800">{{ $isEdit ? 'Update' : 'Save mapping' }}</button>
                    <a href="{{ route('payment-method-wallet-maps.index', $listQuery) }}" class="rounded-lg border border-slate-300 bg-white px-5 py-2.5 text-sm font-medium text-slate-700 hover:bg-slate-50">Cancel</a>
                </div>
            </form>
        </div>
    </div>
</div>
</div>
@endsection
