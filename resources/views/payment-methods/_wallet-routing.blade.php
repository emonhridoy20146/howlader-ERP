{{-- Expects $method (PaymentMethod) with walletMaps.location, walletMaps.wallet --}}
@php
    $maps = $method->walletMaps->sortBy(fn ($m) => $m->location?->name ?? '');
@endphp
<aside class="rounded-2xl border border-indigo-100 bg-gradient-to-b from-indigo-50/80 to-white p-5 shadow-sm ring-1 ring-indigo-900/5">
    <h2 class="text-sm font-semibold text-indigo-950">General ledger</h2>
    <p class="mt-2 text-xs leading-relaxed text-indigo-900/80">When customers pay with this method, the system can post <strong>Dr wallet · Cr receivables</strong> if you assign a wallet for each branch.</p>

    @if($maps->isEmpty())
        <div class="mt-4 rounded-xl border border-amber-200 bg-amber-50 px-3 py-3 text-xs text-amber-950">
            No wallet linked yet. Customer payments will still record on the invoice, but <strong>cash/bank GL lines</strong> are skipped until you add a map.
        </div>
    @else
        <ul class="mt-4 space-y-2">
            @foreach($maps as $map)
                <li class="rounded-lg border border-slate-200 bg-white px-3 py-2 text-xs shadow-sm">
                    <div class="flex items-start justify-between gap-2">
                        <div>
                            <p class="text-[0.65rem] font-medium text-slate-700">{{ $map->location?->name }}</p>
                            <p class="font-medium text-slate-900">{{ $map->wallet?->name }}</p>
                            <p class="font-mono text-[0.65rem] text-slate-600">{{ $map->wallet?->code }}</p>
                        </div>
                        @can('update', $map)
                            <a href="{{ route('payment-method-wallet-maps.index', ['drawer' => 'edit', 'edit' => $map->id]) }}" class="shrink-0 text-xs font-medium text-indigo-700 underline decoration-indigo-200 hover:text-indigo-900">Edit</a>
                        @endcan
                    </div>
                </li>
            @endforeach
        </ul>
    @endif

    @can('create', \App\Models\PaymentMethodWalletMap::class)
        <div class="mt-4 space-y-2">
            @if($method->location_id)
                <a href="{{ route('payment-method-wallet-maps.index', array_filter(['drawer' => 'create', 'location_id' => $method->location_id, 'payment_method_id' => $method->id])) }}" class="flex w-full items-center justify-center rounded-lg bg-indigo-700 px-3 py-2.5 text-center text-sm font-medium text-white shadow-sm hover:bg-indigo-800">Link wallet for this branch</a>
            @else
                <a href="{{ route('payment-method-wallet-maps.index', array_filter(['drawer' => 'create', 'payment_method_id' => $method->id])) }}" class="flex w-full items-center justify-center rounded-lg bg-indigo-700 px-3 py-2.5 text-center text-sm font-medium text-white shadow-sm hover:bg-indigo-800">Add wallet by branch</a>
            @endif
            <a href="{{ route('payment-method-wallet-maps.index', ['payment_method_id' => $method->id]) }}" class="block text-center text-xs font-medium text-indigo-800 underline decoration-indigo-200 hover:text-indigo-950">View all mappings for this method</a>
        </div>
    @endcan
</aside>
