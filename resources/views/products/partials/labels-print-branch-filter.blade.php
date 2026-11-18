@php
    $labelFilterLocations = $labelFilterLocations ?? collect();
    $labelFilterProducts = $labelFilterProducts ?? collect();
    $labelLocationId = $labelLocationId ?? null;
    $labelProductId = $labelProductId ?? null;
    $labelProductIds = $labelProductIds ?? [];
    $labelProductSearch = $labelProductSearch ?? '';
    $labelFormat = $labelFormat ?? \App\Support\SerialLabelBarcode::FORMAT_QR;
@endphp
<form method="get" action="{{ route('products.labels') }}" class="inline-flex flex-wrap items-center gap-1.5">
    <input type="hidden" name="format" value="{{ $labelFormat }}">
    @foreach ($labelProductIds as $productId)
        <input type="hidden" name="product_ids[]" value="{{ $productId }}">
    @endforeach

    <label for="label-branch-filter" class="toolbar-hint whitespace-nowrap">Branch</label>
    <select
        id="label-branch-filter"
        name="location_id"
        class="rounded border border-slate-300 bg-white px-2 py-1 text-xs text-slate-800"
        onchange="this.form.submit()"
        title="Filter labels by branch"
    >
        <option value="">All branches</option>
        @foreach ($labelFilterLocations as $loc)
            <option value="{{ $loc->id }}" @selected((string) $labelLocationId === (string) $loc->id)>
                {{ $loc->name }} ({{ $loc->code }})
            </option>
        @endforeach
    </select>

    <label for="label-product-filter" class="toolbar-hint whitespace-nowrap">Product</label>
    <select
        id="label-product-filter"
        name="product_id"
        class="rounded border border-slate-300 bg-white px-2 py-1 text-xs text-slate-800"
        onchange="this.form.submit()"
        title="Filter labels by product"
        style="min-width: 12rem; max-width: 18rem;"
    >
        <option value="">All products</option>
        @foreach ($labelFilterProducts as $product)
            <option value="{{ $product->id }}" @selected((string) $labelProductId === (string) $product->id)>
                {{ $product->sku }} — {{ \Illuminate\Support\Str::limit($product->name, 40) }}
            </option>
        @endforeach
    </select>

    <label for="label-product-search" class="toolbar-hint whitespace-nowrap">Search</label>
    <input
        id="label-product-search"
        type="search"
        name="product_q"
        value="{{ $labelProductSearch }}"
        placeholder="Name / SKU / barcode"
        class="rounded border border-slate-300 bg-white px-2 py-1 text-xs text-slate-800"
        style="min-width: 9rem; max-width: 12rem;"
    >
    <button type="submit" class="btn secondary">Apply</button>
</form>
