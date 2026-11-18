@extends('layouts.app')
@section('title', 'Wallets')
@section('page-title', $balanceOnly ?? false ? 'Wallet balances' : 'Wallets')
@section('content')
@if ($balanceOnly ?? false)
    <p class="mb-4 text-sm text-slate-600">You can view current balances only. Wallet details, transactions, and setup are not available for your role.</p>
@endif
<div class="mb-4 flex flex-wrap items-center justify-end gap-2">
    @can('create', \App\Models\Wallet::class)
    <a href="{{ route('wallets.create') }}" class="rounded-lg bg-slate-900 px-4 py-2 text-sm text-white">New wallet</a>
    @endcan
</div>

@if (session('status'))
    <div class="mb-4 rounded-lg bg-emerald-50 p-3 text-sm text-emerald-700">{{ session('status') }}</div>
@endif

<x-filter-drawer>
<form method="get" action="{{ route('wallets.index') }}" class="flex flex-col gap-4">
    <div class="flex flex-col gap-4">
        <input type="text" name="q" value="{{ $filters['q'] ?? '' }}" placeholder="Search code or name" class="rounded-lg border border-slate-300 px-3 py-2 text-sm ">
        <select name="location_id" class="rounded-lg border border-slate-300 px-3 py-2 text-sm">
            <option value="">All branches</option>
            @foreach($locations as $location)
                <option value="{{ $location->id }}" @selected((string) ($filters['location_id'] ?? '') === (string) $location->id)>{{ $location->name }}</option>
            @endforeach
        </select>
    </div>
    <x-index-filter-actions :reset-url="route('wallets.index')" />
</form>
</x-filter-drawer>

<div class="grid gap-6 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4">
    @forelse($wallets as $wallet)
        @php
            $balance = (float) ($balances[$wallet->ledger_account_id] ?? 0);
            $kindName = \App\Models\Wallet::kinds()[$wallet->kind] ?? $wallet->kind;
            
            // Determine styling based on wallet kind
            $bgClass = match($wallet->kind) {
                'cash' => 'bg-gradient-to-br from-emerald-500 to-teal-700',
                'bank' => 'bg-gradient-to-br from-slate-700 to-slate-900',
                'card_terminal' => 'bg-gradient-to-br from-neutral-700 to-stone-900',
                'mobile_banking' => 'bg-gradient-to-br from-purple-500 to-fuchsia-700',
                'digital' => 'bg-gradient-to-br from-sky-500 to-blue-700',
                default => 'bg-gradient-to-br from-slate-500 to-slate-700',
            };
            
            $icon = match($wallet->kind) {
                'cash' => '<path stroke-linecap="round" stroke-linejoin="round" d="M2.25 18.75a60.07 60.07 0 0 1 15.797 2.101c.727.198 1.453-.342 1.453-1.096V5.988c0-.754-.726-1.294-1.453-1.096V5.988a60.224 60.224 0 0 1-15.797 2.101c-.727.198-1.453-.342-1.453-1.096v11.752c0 .754.726 1.294 1.453 1.096ZM12 15.75a3.75 3.75 0 1 1 0-7.5 3.75 3.75 0 0 1 0 7.5Z" />',
                'bank' => '<path stroke-linecap="round" stroke-linejoin="round" d="M12 21v-8.25M15.75 21v-8.25M8.25 21v-8.25M3 9l9-6 9 6m-1.5 12V10.332A48.36 48.36 0 0 0 12 9.75c-2.551 0-5.056.2-7.5.582V21M3 21h18M12 6.75h.008v.008H12V6.75Z" />',
                'card_terminal' => '<path stroke-linecap="round" stroke-linejoin="round" d="M2.25 8.25h19.5M2.25 9h19.5m-16.5 5.25h6m-6 2.25h3m-3.75 3h15a2.25 2.25 0 0 0 2.25-2.25V6.75A2.25 2.25 0 0 0 19.5 4.5h-15a2.25 2.25 0 0 0-2.25 2.25v10.5A2.25 2.25 0 0 0 4.5 19.5Z" />',
                'mobile_banking' => '<path stroke-linecap="round" stroke-linejoin="round" d="M10.5 1.5H8.25A2.25 2.25 0 0 0 6 3.75v16.5a2.25 2.25 0 0 0 2.25 2.25h7.5A2.25 2.25 0 0 0 18 20.25V3.75a2.25 2.25 0 0 0-2.25-2.25H13.5m-3 0V3h3V1.5m-3 0h3m-3 18.75h3" />',
                'digital' => '<path stroke-linecap="round" stroke-linejoin="round" d="M12 21a9.004 9.004 0 0 0 8.716-6.747M12 21a9.004 9.004 0 0 1-8.716-6.747M12 21c2.485 0 4.5-4.03 4.5-9S14.485 3 12 3m0 18c-2.485 0-4.5-4.03-4.5-9S9.515 3 12 3m0 0a8.997 8.997 0 0 1 7.843 4.582M12 3a8.997 8.997 0 0 0-7.843 4.582m15.686 0A11.953 11.953 0 0 1 12 10.5c-2.998 0-5.74-1.1-7.843-2.918m15.686 0A8.959 8.959 0 0 1 21 12c0 .778-.099 1.533-.284 2.253m0 0A17.919 17.919 0 0 1 12 16.5c-3.162 0-6.133-.815-8.716-2.247m0 0A9.015 9.015 0 0 1 3 12c0-1.605.42-3.113 1.157-4.418" />',
                default => '<path stroke-linecap="round" stroke-linejoin="round" d="M21 12a2.25 2.25 0 0 0-2.25-2.25H15a3 3 0 1 1-6 0H5.25A2.25 2.25 0 0 0 3 12m18 0v6a2.25 2.25 0 0 1-2.25 2.25H5.25A2.25 2.25 0 0 1 3 18v-6m18 0V9M3 12V9m18 0a2.25 2.25 0 0 0-2.25-2.25H5.25A2.25 2.25 0 0 0 3 9m18 0V6a2.25 2.25 0 0 0-2.25-2.25H5.25A2.25 2.25 0 0 0 3 6v3" />',
            };
        @endphp

        <div class="group flex flex-col rounded-2xl bg-white shadow-sm ring-1 ring-slate-200 transition-all hover:-translate-y-1 hover:shadow-md">
            <!-- Wallet Card representation -->
            <div class="relative m-2 overflow-hidden rounded-xl {{ $bgClass }} p-5 text-white shadow-inner">
                <!-- Decorative background pattern -->
                <div class="absolute -right-4 -top-4 opacity-10">
                    <svg class="h-32 w-32" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1">{!! $icon !!}</svg>
                </div>
                
                <div class="relative z-10 flex items-start justify-between">
                    <div>
                        <div class="flex items-center gap-2">
                            <svg class="h-5 w-5 opacity-80" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">{!! $icon !!}</svg>
                            <span class="rounded bg-white/20 px-2 py-0.5 text-[10px] font-bold uppercase tracking-widest backdrop-blur-sm">{{ $kindName }}</span>
                        </div>
                        @if(!$wallet->is_active)
                            <span class="mt-2 inline-block rounded bg-rose-500/80 px-2 py-0.5 text-[10px] font-bold uppercase tracking-widest backdrop-blur-sm">Inactive</span>
                        @endif
                    </div>
                    <span class="font-mono text-sm tracking-wider opacity-80">{{ $wallet->code }}</span>
                </div>
                
                <div class="relative z-10 mt-6">
                    <h3 class="line-clamp-1 text-lg font-bold tracking-wide text-white">{{ $wallet->name }}</h3>
                    @if (! ($balanceOnly ?? false))
                        <p class="mt-1 h-4 text-xs font-medium text-white/70">
                            @if($wallet->bank_name)
                                {{ $wallet->bank_name }}
                                @if($wallet->account_number)
                                    <span class="mx-1">&bull;</span>
                                    <span class="font-mono tracking-widest">{{ substr($wallet->account_number, -4) }}</span>
                                @endif
                            @endif
                        </p>
                    @endif
                </div>
                
                <div class="relative z-10 mt-6 flex items-end justify-between">
                    <div>
                        <p class="text-[10px] uppercase tracking-widest text-white/70">Balance</p>
                        <p class="font-mono text-xl font-bold tracking-tight">{{ number_format($balance, 2) }}</p>
                    </div>
                    @if (! ($balanceOnly ?? false))
                        <div class="text-right">
                            <p class="text-[10px] uppercase tracking-widest text-white/70">Branch Access</p>
                            <p class="text-xs font-medium text-white/90">
                                {{ $wallet->locations->isEmpty() ? 'All Branches' : $wallet->locations->count() . ' Branch' . ($wallet->locations->count() > 1 ? 'es' : '') }}
                            </p>
                        </div>
                    @endif
                </div>
            </div>

            @if (! ($balanceOnly ?? false))
                <!-- Card Actions & Details -->
                <div class="flex flex-1 flex-col justify-between p-4 pt-2">
                    <div class="mb-4 flex items-center justify-between text-xs text-slate-500">
                        <span title="Linked Ledger Account" class="flex items-center gap-1">
                            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M12 6.042A8.967 8.967 0 0 0 6 3.75c-1.052 0-2.062.18-3 .512v14.25A8.987 8.987 0 0 1 6 18c2.305 0 4.408.867 6 2.292m0-14.25a8.966 8.966 0 0 1 6-2.292c1.052 0 2.062.18 3 .512v14.25A8.987 8.987 0 0 0 18 18a8.967 8.967 0 0 0-6 2.292m0-14.25v14.25" />
                            </svg>
                            {{ $wallet->ledgerAccount?->code ?? 'N/A' }}
                        </span>
                        @if($wallet->locations->isNotEmpty())
                            <span class="truncate pl-2 text-right opacity-75" title="{{ $wallet->locations->pluck('name')->join(', ') }}">
                                {{ $wallet->locations->pluck('code')->join(', ') }}
                            </span>
                        @endif
                    </div>
                    
                    <div class="flex items-center justify-between gap-2 border-t border-slate-100 pt-3">
                        @can('view', $wallet)
                            <a href="{{ route('wallets.show', $wallet) }}" class="inline-flex flex-1 items-center justify-center rounded-lg bg-slate-100 px-3 py-2 text-xs font-semibold text-slate-700 transition hover:bg-slate-200">
                                View Details
                            </a>
                        @endcan
                        @can('update', $wallet)
                            <a href="{{ route('wallets.edit', $wallet) }}" class="inline-flex flex-1 items-center justify-center rounded-lg bg-emerald-50 px-3 py-2 text-xs font-semibold text-emerald-700 transition hover:bg-emerald-100">
                                Edit
                            </a>
                        @endcan
                        @can('delete', $wallet)
                            <form action="{{ route('wallets.destroy', $wallet) }}" method="post" data-confirm="Delete this wallet?">
                                @csrf
                                @method('DELETE')
                                <button class="inline-flex items-center justify-center rounded-lg bg-rose-50 p-2 text-rose-600 transition hover:bg-rose-100">
                                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="m14.74 9-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 0 1-2.244 2.077H8.084a2.25 2.25 0 0 1-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 0 0-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 0 1 3.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 0 0-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 0 0-7.5 0" />
                                    </svg>
                                </button>
                            </form>
                        @endcan
                    </div>
                </div>
            @endif
        </div>
    @empty
        <div class="col-span-full rounded-2xl border border-dashed border-slate-300 bg-slate-50 py-16 text-center">
            <svg class="mx-auto h-12 w-12 text-slate-400" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" d="M21 12a2.25 2.25 0 0 0-2.25-2.25H15a3 3 0 1 1-6 0H5.25A2.25 2.25 0 0 0 3 12m18 0v6a2.25 2.25 0 0 1-2.25 2.25H5.25A2.25 2.25 0 0 1 3 18v-6m18 0V9M3 12V9m18 0a2.25 2.25 0 0 0-2.25-2.25H5.25A2.25 2.25 0 0 0 3 9m18 0V6a2.25 2.25 0 0 0-2.25-2.25H5.25A2.25 2.25 0 0 0 3 6v3" />
            </svg>
            <h3 class="mt-4 text-sm font-semibold text-slate-900">No wallets found</h3>
            <p class="mt-1 text-sm text-slate-500">{{ ($balanceOnly ?? false) ? 'No wallets are visible for your branches.' : 'Create a wallet to manage branch finances.' }}</p>
            @can('create', \App\Models\Wallet::class)
                <a href="{{ route('wallets.create') }}" class="mt-4 inline-flex items-center rounded-lg bg-slate-900 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-slate-800">Create Wallet</a>
            @endcan
        </div>
    @endforelse
</div>
	<x-pagination-limit-selector />

<div class="mt-4">{{ $wallets->links() }}</div>
@endsection
