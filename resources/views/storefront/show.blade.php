@extends('layouts.storefront')

@section('title', $product->name.' — '.$vendor['name'])

@section('content')
    @php
        $color = \App\Support\StorefrontPalette::colorFor($product->category?->id);
    @endphp

    <main class="mx-auto max-w-7xl px-4 py-8 sm:px-6 lg:px-8">
        <nav class="mb-6 flex items-center gap-1.5 text-sm text-slate-500">
            <a href="{{ route('storefront.index') }}" class="hover:text-indigo-600">Shop</a>
            <span>/</span>
            @if ($product->category)
                <a href="{{ route('storefront.index', ['category_id' => $product->category->id, 'stock' => 'all']) }}#products" class="hover:text-indigo-600">{{ $product->category->name }}</a>
                <span>/</span>
            @endif
            <span class="truncate text-slate-700">{{ $product->name }}</span>
        </nav>

        <div class="grid gap-8 lg:grid-cols-2">
            {{-- Image --}}
            <div class="relative aspect-square w-full overflow-hidden rounded-2xl border border-slate-200 bg-slate-100">
                @if ($product->photoUrl())
                    <img src="{{ $product->photoUrl() }}" alt="{{ $product->name }}" class="h-full w-full object-cover">
                @else
                    <div class="flex h-full w-full flex-col items-center justify-center gap-2 bg-gradient-to-br from-slate-100 to-slate-200">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1" class="h-16 w-16 text-slate-400">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M2.25 15.75l5.159-5.159a2.25 2.25 0 013.182 0l5.159 5.159m-1.5-1.5l1.409-1.409a2.25 2.25 0 013.182 0l2.909 2.909M3 12V4.5A2.25 2.25 0 015.25 2.25h13.5A2.25 2.25 0 0121 4.5V15a2.25 2.25 0 01-2.25 2.25H5.25A2.25 2.25 0 013 15v-1.5z" />
                            <circle cx="8.25" cy="8.25" r="1.5" />
                        </svg>
                        <span class="text-xs font-semibold uppercase tracking-wide text-slate-400">No image</span>
                    </div>
                @endif
                <span class="absolute left-3 top-3 rounded-full px-3 py-1 text-xs font-semibold shadow-sm {{ $inStock ? 'bg-emerald-100 text-emerald-700' : 'bg-rose-100 text-rose-700' }}">
                    {{ $inStock ? 'In stock' : 'Out of stock' }}
                </span>
            </div>

            {{-- Details --}}
            <div>
                @if ($product->category)
                    <span class="w-fit rounded-full {{ $color['chip'] }} px-2.5 py-1 text-xs font-semibold uppercase tracking-wide">{{ $product->category->name }}</span>
                @endif
                <h1 class="mt-3 text-2xl font-bold tracking-tight text-slate-900 sm:text-3xl">{{ $product->name }}</h1>
                <p class="mt-1 text-sm text-slate-500">SKU: {{ $product->sku }}@if ($product->model_number) &middot; Model: {{ $product->model_number }}@endif</p>

                <p class="mt-5 text-3xl font-bold tabular-nums text-slate-900">{{ number_format((float) $product->default_sale_price, 2) }}</p>
                <p class="mt-1 text-sm {{ $inStock ? 'text-emerald-700' : 'text-rose-700' }}">
                    @if ($inStock)
                        In stock &middot; {{ (int) $available }} {{ $product->unit_of_measure }}{{ $available == 1 ? '' : 's' }} available
                    @else
                        Currently out of stock
                    @endif
                </p>

                <button
                    type="button"
                    @click="openContact({ name: @js($product->name), sku: @js($product->sku) })"
                    class="mt-6 inline-flex w-full items-center justify-center gap-2 rounded-xl bg-gradient-to-r from-indigo-600 to-indigo-700 px-5 py-3 text-sm font-semibold text-white shadow-sm hover:from-indigo-700 hover:to-indigo-800 sm:w-auto"
                >
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" class="h-4 w-4"><path stroke-linecap="round" stroke-linejoin="round" d="M8.625 12a.375.375 0 11-.75 0 .375.375 0 01.75 0zm0 0H8.25m4.125 0a.375.375 0 11-.75 0 .375.375 0 01.75 0zm0 0H12m4.125 0a.375.375 0 11-.75 0 .375.375 0 01.75 0zm0 0h-.375M21 12c0 4.556-4.03 8.25-9 8.25a9.764 9.764 0 01-2.555-.337A5.972 5.972 0 015.41 20.97a5.969 5.969 0 01-.474-.065 4.48 4.48 0 00.978-2.025c.09-.457-.133-.901-.467-1.226C3.93 16.178 3 14.189 3 12c0-4.556 4.03-8.25 9-8.25s9 3.694 9 8.25z" /></svg>
                    Contact Vendor about this item
                </button>

                @if ($product->description)
                    <div class="mt-8">
                        <h2 class="text-sm font-semibold uppercase tracking-wide text-slate-500">Description</h2>
                        <p class="mt-2 whitespace-pre-line text-sm leading-relaxed text-slate-700">{{ $product->description }}</p>
                    </div>
                @endif

                <div class="mt-8 rounded-2xl border border-slate-200 bg-white">
                    <h2 class="border-b border-slate-100 px-4 py-3 text-sm font-semibold uppercase tracking-wide text-slate-500">Specifications</h2>
                    <dl class="divide-y divide-slate-100 text-sm">
                        <div class="flex justify-between px-4 py-2.5">
                            <dt class="text-slate-500">SKU</dt>
                            <dd class="font-medium text-slate-900">{{ $product->sku }}</dd>
                        </div>
                        @if ($product->model_number)
                            <div class="flex justify-between px-4 py-2.5">
                                <dt class="text-slate-500">Model number</dt>
                                <dd class="font-medium text-slate-900">{{ $product->model_number }}</dd>
                            </div>
                        @endif
                        @if ($product->category)
                            <div class="flex justify-between px-4 py-2.5">
                                <dt class="text-slate-500">Category</dt>
                                <dd class="font-medium text-slate-900">{{ $product->category->name }}</dd>
                            </div>
                        @endif
                        <div class="flex justify-between px-4 py-2.5">
                            <dt class="text-slate-500">Unit</dt>
                            <dd class="font-medium text-slate-900">{{ $product->unit_of_measure }}</dd>
                        </div>
                        @if ($product->warranty_months > 0)
                            <div class="flex justify-between px-4 py-2.5">
                                <dt class="text-slate-500">Warranty</dt>
                                <dd class="font-medium text-slate-900">{{ $product->warranty_months }} month{{ $product->warranty_months == 1 ? '' : 's' }}</dd>
                            </div>
                        @endif
                    </dl>
                </div>
            </div>
        </div>

        {{-- Related products --}}
        @if ($related->isNotEmpty())
            <section class="mt-14">
                <h2 class="mb-4 text-lg font-bold text-slate-900">More from {{ $product->category->name }}</h2>
                <div class="grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-4 xl:grid-cols-6">
                    @foreach ($related as $item)
                        @php
                            $itemAvailable = (float) ($availabilityByProduct[$item->id] ?? 0);
                            $itemInStock = $itemAvailable > 0;
                        @endphp
                        <a href="{{ route('storefront.show', $item) }}" class="group flex flex-col overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm transition hover:-translate-y-0.5 hover:shadow-lg">
                            <div class="relative aspect-square w-full overflow-hidden bg-slate-100">
                                @if ($item->photoUrl())
                                    <img src="{{ $item->photoUrl() }}" alt="{{ $item->name }}" class="h-full w-full object-cover transition group-hover:scale-105" loading="lazy">
                                @else
                                    <div class="flex h-full w-full items-center justify-center bg-gradient-to-br from-slate-100 to-slate-200">
                                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1" class="h-8 w-8 text-slate-400"><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 15.75l5.159-5.159a2.25 2.25 0 013.182 0l5.159 5.159m-1.5-1.5l1.409-1.409a2.25 2.25 0 013.182 0l2.909 2.909M3 12V4.5A2.25 2.25 0 015.25 2.25h13.5A2.25 2.25 0 0121 4.5V15a2.25 2.25 0 01-2.25 2.25H5.25A2.25 2.25 0 013 15v-1.5z" /><circle cx="8.25" cy="8.25" r="1.5" /></svg>
                                    </div>
                                @endif
                                <span class="absolute left-2 top-2 rounded-full px-2 py-0.5 text-[10px] font-semibold shadow-sm {{ $itemInStock ? 'bg-emerald-100 text-emerald-700' : 'bg-rose-100 text-rose-700' }}">
                                    {{ $itemInStock ? 'In stock' : 'Out' }}
                                </span>
                            </div>
                            <div class="p-3">
                                <h3 class="line-clamp-2 text-sm font-semibold text-slate-900">{{ $item->name }}</h3>
                                <p class="mt-1 text-sm font-bold tabular-nums text-slate-900">{{ number_format((float) $item->default_sale_price, 2) }}</p>
                            </div>
                        </a>
                    @endforeach
                </div>
            </section>
        @endif
    </main>
@endsection
