@extends('layouts.storefront')

@section('content')
    @php $colorFor = fn ($categoryId) => \App\Support\StorefrontPalette::colorFor($categoryId); @endphp

    {{-- Hero --}}
    <section class="relative overflow-hidden bg-gradient-to-br from-slate-900 via-indigo-950 to-slate-900">
        <div class="pointer-events-none absolute inset-0" aria-hidden="true">
            <div class="absolute -left-24 -top-24 h-72 w-72 rounded-full bg-indigo-500/30 blur-3xl"></div>
            <div class="absolute -right-16 top-10 h-72 w-72 rounded-full bg-amber-400/20 blur-3xl"></div>
            <div class="absolute bottom-0 left-1/3 h-64 w-64 rounded-full bg-rose-500/20 blur-3xl"></div>
            <svg class="absolute right-6 top-6 h-24 w-24 text-white/10 sm:h-36 sm:w-36" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1"><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 3h1.386c.51 0 .955.343 1.087.835l.383 1.437M7.5 14.25a3 3 0 00-3 3h15.75m-12.75-3h11.218c1.121-2.3 1.87-4.694 2.25-7.152.075-.483-.298-.923-.786-.923H5.106M7.5 14.25L5.106 5.106M7.5 14.25L4.5 17.25m13.5-3l1.5-3M6 20.25a.75.75 0 11-1.5 0 .75.75 0 011.5 0zm12.75 0a.75.75 0 11-1.5 0 .75.75 0 011.5 0z" /></svg>
            <svg class="absolute bottom-6 left-8 h-20 w-20 text-white/10" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1"><path stroke-linecap="round" stroke-linejoin="round" d="M20.25 7.5l-.625 10.632a2.25 2.25 0 01-2.247 2.118H6.622a2.25 2.25 0 01-2.247-2.118L3.75 7.5M10 11.25h4M3.375 7.5h17.25c.621 0 1.125-.504 1.125-1.125v-1.5c0-.621-.504-1.125-1.125-1.125H3.375c-.621 0-1.125.504-1.125 1.125v1.5c0 .621.504 1.125 1.125 1.125z" /></svg>
        </div>

        <div class="relative mx-auto max-w-7xl px-4 py-16 sm:px-6 sm:py-20 lg:px-8">
            <div class="mx-auto max-w-2xl text-center">
                <span class="inline-flex items-center gap-1.5 rounded-full bg-white/10 px-3 py-1 text-xs font-semibold text-amber-300 ring-1 ring-white/20">
                    Live stock, straight from our shelves
                </span>
                <h1 class="mt-5 text-3xl font-bold tracking-tight text-white sm:text-5xl">
                    Shop {{ $vendor['name'] }}'s products
                </h1>
                <p class="mt-4 text-base text-slate-300 sm:text-lg">
                    Browse the full catalog, filter by category, and see what's in stock right now. Found something you like? Message us directly to order.
                </p>

                <form method="get" action="{{ route('storefront.index') }}#products" class="mx-auto mt-8 flex max-w-xl items-center gap-2 rounded-2xl bg-white p-2 shadow-xl">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" class="ml-2 h-5 w-5 shrink-0 text-slate-400"><path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-5.197-5.197m0 0A7.5 7.5 0 105.196 5.196a7.5 7.5 0 0010.607 10.607z" /></svg>
                    <input
                        type="text"
                        name="q"
                        value="{{ $filters['q'] }}"
                        placeholder="Search products, SKU, model…"
                        class="min-w-0 flex-1 rounded-xl border-0 px-2 py-2.5 text-sm text-slate-900 focus:outline-none focus:ring-0"
                    >
                    <button class="shrink-0 rounded-xl bg-slate-900 px-5 py-2.5 text-sm font-semibold text-white hover:bg-slate-800">Search</button>
                </form>

                <div class="mx-auto mt-10 grid max-w-lg grid-cols-3 gap-3">
                    <div class="rounded-2xl bg-white/10 px-3 py-4 ring-1 ring-white/10 backdrop-blur">
                        <p class="text-2xl font-bold text-white tabular-nums">{{ number_format($stats['products']) }}</p>
                        <p class="mt-0.5 text-xs font-medium text-slate-300">Products</p>
                    </div>
                    <div class="rounded-2xl bg-white/10 px-3 py-4 ring-1 ring-white/10 backdrop-blur">
                        <p class="text-2xl font-bold text-white tabular-nums">{{ number_format($stats['categories']) }}</p>
                        <p class="mt-0.5 text-xs font-medium text-slate-300">Categories</p>
                    </div>
                    <div class="rounded-2xl bg-white/10 px-3 py-4 ring-1 ring-white/10 backdrop-blur">
                        <p class="text-2xl font-bold text-emerald-300 tabular-nums">{{ number_format($stats['in_stock']) }}</p>
                        <p class="mt-0.5 text-xs font-medium text-slate-300">In stock now</p>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <main class="mx-auto max-w-7xl px-4 py-10 sm:px-6 lg:px-8">
        {{-- Shop by category --}}
        @if ($topCategories->isNotEmpty())
            <section id="categories" class="mb-10">
                <div class="mb-4 flex items-end justify-between">
                    <div>
                        <h2 class="text-lg font-bold text-slate-900">Shop by category</h2>
                        <p class="text-sm text-slate-500">Jump straight to what you're after.</p>
                    </div>
                    @if ($filters['category_id'])
                        <a href="{{ route('storefront.index') }}#products" class="text-sm font-medium text-indigo-600 hover:text-indigo-700">Clear category ×</a>
                    @endif
                </div>
                <div class="-mx-4 flex gap-3 overflow-x-auto px-4 pb-2 sm:mx-0 sm:grid sm:grid-cols-4 sm:overflow-visible sm:px-0 lg:grid-cols-5 xl:grid-cols-10">
                    @foreach ($topCategories as $category)
                        @php $color = $colorFor($category->id); @endphp
                        <a
                            href="{{ route('storefront.index', array_filter(['category_id' => $category->id, 'stock' => $filters['stock']])) }}#products"
                            class="group flex w-28 shrink-0 flex-col items-center gap-2 rounded-2xl border border-slate-200 bg-white p-3 text-center shadow-sm transition hover:-translate-y-0.5 hover:shadow-md sm:w-auto {{ $filters['category_id'] === $category->id ? 'ring-2 ring-indigo-500' : '' }}"
                        >
                            <span class="flex h-12 w-12 items-center justify-center rounded-xl {{ $color['tile'] }} text-lg font-bold text-white shadow-sm">
                                {{ Str::substr($category->name, 0, 1) }}
                            </span>
                            <span class="line-clamp-2 text-xs font-semibold text-slate-800">{{ $category->name }}</span>
                            <span class="rounded-full {{ $color['chip'] }} px-2 py-0.5 text-[10px] font-semibold">{{ $category->products_count }} items</span>
                        </a>
                    @endforeach
                </div>
            </section>
        @endif

        {{-- Filters (auto-submits on change — no button needed) --}}
        <form
            id="products"
            method="get"
            action="{{ route('storefront.index') }}#products"
            class="mb-6 scroll-mt-6 flex flex-wrap items-end gap-4 rounded-2xl border border-slate-200 bg-white p-4 shadow-sm"
            x-data="{
                submit() { this.$el.submit(); },
                debounced: null,
                onType() { clearTimeout(this.debounced); this.debounced = setTimeout(() => this.submit(), 450); },
            }"
        >
            <div class="min-w-[200px] flex-1">
                <label class="text-xs font-medium uppercase tracking-wide text-slate-500">Search</label>
                <input
                    type="text"
                    name="q"
                    value="{{ $filters['q'] }}"
                    placeholder="Search by name, SKU, model…"
                    class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-200"
                    @input="onType()"
                >
            </div>
            <div class="min-w-[180px]">
                <label class="text-xs font-medium uppercase tracking-wide text-slate-500">Category</label>
                <select name="category_id" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-200" onchange="this.form.submit()">
                    <option value="">All categories</option>
                    @foreach ($categories as $category)
                        <option value="{{ $category->id }}" @selected($filters['category_id'] === $category->id)>{{ $category->name }} ({{ $category->products_count }})</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="text-xs font-medium uppercase tracking-wide text-slate-500">Availability</label>
                <div class="mt-1 flex items-center gap-2 rounded-lg border border-slate-300 bg-slate-50 p-1 text-sm">
                    <label class="inline-flex cursor-pointer items-center gap-1.5 rounded-md px-3 py-1.5 {{ $filters['stock'] === 'in_stock' ? 'bg-emerald-600 font-medium text-white shadow-sm' : 'text-slate-600' }}">
                        <input type="radio" name="stock" value="in_stock" class="sr-only" @checked($filters['stock'] === 'in_stock') onchange="this.form.submit()">
                        In stock
                    </label>
                    <label class="inline-flex cursor-pointer items-center gap-1.5 rounded-md px-3 py-1.5 {{ $filters['stock'] === 'all' ? 'bg-white font-medium text-slate-900 shadow-sm' : 'text-slate-600' }}">
                        <input type="radio" name="stock" value="all" class="sr-only" @checked($filters['stock'] === 'all') onchange="this.form.submit()">
                        Show all
                    </label>
                </div>
            </div>
            @if ($filters['q'] !== '' || $filters['category_id'] || $filters['stock'] !== 'in_stock')
                <a href="{{ route('storefront.index') }}#products" class="rounded-lg border border-slate-300 bg-white px-4 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50">Reset</a>
            @endif
            <noscript><button class="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-700">Filter</button></noscript>
        </form>

        @if ($activeCategoryName)
            <p class="mb-4 text-sm text-slate-600">
                Showing <span class="font-semibold text-slate-900">{{ $activeCategoryName }}</span> — {{ $products->total() }} product(s).
            </p>
        @endif

        {{-- Product grid --}}
        @if ($products->isEmpty())
            <div class="rounded-2xl border border-dashed border-slate-300 bg-white p-12 text-center">
                <p class="text-sm text-slate-500">
                    @if ($filters['stock'] === 'in_stock')
                        No in-stock products match your filters. Try "Show all" to include out-of-stock items.
                    @else
                        No products match your filters.
                    @endif
                </p>
            </div>
        @else
            <div class="grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-4 xl:grid-cols-5">
                @foreach ($products as $product)
                    @php
                        $available = (float) ($availabilityByProduct[$product->id] ?? 0);
                        $inStock = $available > 0;
                        $color = $colorFor($product->category?->id);
                    @endphp
                    <div class="group flex flex-col overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm transition hover:-translate-y-0.5 hover:shadow-lg">
                        <a href="{{ route('storefront.show', $product) }}" class="relative block aspect-square w-full overflow-hidden bg-slate-100">
                            @if ($product->photoUrl())
                                <img src="{{ $product->photoUrl() }}" alt="{{ $product->name }}" class="h-full w-full object-cover transition group-hover:scale-105" loading="lazy">
                            @else
                                <div class="flex h-full w-full flex-col items-center justify-center gap-1.5 bg-gradient-to-br from-slate-100 to-slate-200">
                                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1" class="h-10 w-10 text-slate-400">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M2.25 15.75l5.159-5.159a2.25 2.25 0 013.182 0l5.159 5.159m-1.5-1.5l1.409-1.409a2.25 2.25 0 013.182 0l2.909 2.909M3 12V4.5A2.25 2.25 0 015.25 2.25h13.5A2.25 2.25 0 0121 4.5V15a2.25 2.25 0 01-2.25 2.25H5.25A2.25 2.25 0 013 15v-1.5z" />
                                        <circle cx="8.25" cy="8.25" r="1.5" />
                                    </svg>
                                    <span class="text-[10px] font-semibold uppercase tracking-wide text-slate-400">No image</span>
                                </div>
                            @endif
                            <span class="absolute left-2 top-2 rounded-full px-2 py-0.5 text-[11px] font-semibold shadow-sm {{ $inStock ? 'bg-emerald-100 text-emerald-700' : 'bg-rose-100 text-rose-700' }}">
                                {{ $inStock ? 'In stock' : 'Out of stock' }}
                            </span>
                        </a>
                        <div class="flex flex-1 flex-col gap-1 p-3">
                            @if ($product->category)
                                <span class="w-fit rounded-full {{ $color['chip'] }} px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide">{{ $product->category->name }}</span>
                            @endif
                            <h3 class="mt-1 line-clamp-2 text-sm font-semibold text-slate-900">
                                <a href="{{ route('storefront.show', $product) }}" class="hover:text-indigo-700" title="{{ $product->name }}">{{ $product->name }}</a>
                            </h3>
                            <p class="mt-1 text-base font-bold tabular-nums text-slate-900">{{ number_format((float) $product->default_sale_price, 2) }}</p>
                            <button
                                type="button"
                                @click="openContact({ name: @js($product->name), sku: @js($product->sku) })"
                                class="mt-auto inline-flex items-center justify-center gap-1.5 rounded-lg bg-gradient-to-r from-indigo-600 to-indigo-700 px-3 py-2 text-xs font-semibold text-white shadow-sm hover:from-indigo-700 hover:to-indigo-800"
                            >
                                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" class="h-3.5 w-3.5"><path stroke-linecap="round" stroke-linejoin="round" d="M8.625 12a.375.375 0 11-.75 0 .375.375 0 01.75 0zm0 0H8.25m4.125 0a.375.375 0 11-.75 0 .375.375 0 01.75 0zm0 0H12m4.125 0a.375.375 0 11-.75 0 .375.375 0 01.75 0zm0 0h-.375M21 12c0 4.556-4.03 8.25-9 8.25a9.764 9.764 0 01-2.555-.337A5.972 5.972 0 015.41 20.97a5.969 5.969 0 01-.474-.065 4.48 4.48 0 00.978-2.025c.09-.457-.133-.901-.467-1.226C3.93 16.178 3 14.189 3 12c0-4.556 4.03-8.25 9-8.25s9 3.694 9 8.25z" /></svg>
                                Contact Vendor
                            </button>
                        </div>
                    </div>
                @endforeach
            </div>

            <div class="mt-6">{{ $products->links() }}</div>
        @endif
    </main>
@endsection
