@csrf
@php
    $brandOptions = $brands->map(fn ($b) => ['id' => (int) $b->id, 'name' => (string) $b->name])->values()->all();
    $categoryOptions = $categories->map(fn ($c) => ['id' => (int) $c->id, 'name' => (string) $c->name])->values()->all();
    $initialBrandId = (int) old('brand_id', $product->brand_id ?? ($brands->first()->id ?? 0));
    $initialCategoryId = (int) old('category_id', $product->category_id ?? ($categories->first()->id ?? 0));
@endphp
<div class="grid gap-4 md:grid-cols-2">
    <div>
        <label class="text-sm font-medium">SKU</label>
        <input name="sku" value="{{ old('sku', $product->sku ?? '') }}" class="mt-1 w-full rounded-lg border px-3 py-2 @error('sku') border-rose-500 @else border-slate-300 @enderror" required maxlength="60">
        @error('sku')<p class="mt-1 text-xs text-rose-600">{{ $message }}</p>@enderror
    </div>
    <div>
        <label class="text-sm font-medium">Barcode</label>
        <div class="mt-1 flex gap-2">
            <input name="barcode" value="{{ old('barcode', $product->barcode ?? '') }}" placeholder="Leave blank to auto-assign on save" class="min-w-0 flex-1 rounded-lg border border-slate-300 px-3 py-2 font-mono text-sm" maxlength="100" autocomplete="off">
            <button
                type="button"
                class="shrink-0 rounded-lg border border-slate-300 bg-white px-3 py-2 text-xs font-semibold text-slate-800 hover:bg-slate-50"
                onclick="(function (btn) { fetch(@js(route('products.barcode.next')), { headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' } }).then(function (r) { if (!r.ok) { throw new Error(); } return r.json(); }).then(function (d) { var wrap = btn.parentElement; var i = wrap ? wrap.querySelector('input[name=barcode]') : null; if (i && d.barcode) { i.value = d.barcode; } }).catch(function () { if (window.appAlert) { window.appAlert('Could not generate a barcode.', { icon: 'error', title: 'Barcode generation failed' }); return; } alert('Could not generate a barcode.'); }); })(this)"
            >Generate</button>
        </div>
        <p class="mt-1 text-xs text-slate-500">Internal 12-digit codes are created automatically if the field is empty. Use Generate to preview one before saving.</p>
    </div>
    <div>
        <label class="text-sm font-medium">Name</label>
        <input name="name" value="{{ old('name', $product->name ?? '') }}" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2" required>
    </div>
    <div>
        <label class="text-sm font-medium">Model number</label>
        <input name="model_number" value="{{ old('model_number', $product->model_number ?? '') }}" maxlength="100" autocomplete="off" class="mt-1 w-full rounded-lg border px-3 py-2 @error('model_number') border-rose-500 @else border-slate-300 @enderror" aria-describedby="model-number-hint">
        <p id="model-number-hint" class="mt-1 text-xs text-slate-500">Optional. If set, must be unique across all products (same as SKU).</p>
        @error('model_number')<p class="mt-1 text-xs text-rose-600">{{ $message }}</p>@enderror
    </div>
    <div
        class="relative"
        x-data="productOptionPicker({ options: @js($brandOptions), initialId: {{ $initialBrandId }} })"
    >
        <label for="product-form-brand-search" class="text-sm font-medium text-slate-700">Brand</label>
        <input type="hidden" name="brand_id" :value="selectedId != null ? selectedId : ''">
        <input
            id="product-form-brand-search"
            type="text"
            x-model="query"
            autocomplete="off"
            role="combobox"
            :aria-expanded="open ? 'true' : 'false'"
            aria-autocomplete="list"
            aria-controls="product-form-brand-list"
            placeholder="Search brands…"
            class="mt-1 w-full rounded-lg border px-3 py-2 text-sm shadow-sm focus:border-slate-500 focus:outline-none focus:ring-2 focus:ring-slate-200 @error('brand_id') border-rose-500 @else border-slate-300 @enderror"
            @focus="onFocus()"
            @input="onSearchInput()"
            @blur="onBlur()"
            @keydown.escape.prevent="open = false"
        >
        <div
            id="product-form-brand-list"
            x-show="open"
            x-cloak
            x-transition.opacity.duration.100ms
            @click.outside="open = false"
            class="absolute z-[60] mt-1 max-h-48 w-full overflow-y-auto rounded-lg border border-slate-200 bg-white py-1 shadow-lg ring-1 ring-slate-200/60"
            role="listbox"
        >
            <template x-for="opt in filtered" :key="opt.id">
                <button
                    type="button"
                    role="option"
                    class="flex w-full px-3 py-2 text-left text-sm text-slate-800 hover:bg-slate-50"
                    :class="Number(opt.id) === Number(selectedId) ? 'bg-slate-100 font-medium' : ''"
                    @mousedown.prevent="pick(opt)"
                    x-text="opt.name"
                ></button>
            </template>
            <p x-show="filtered.length === 0" class="px-3 py-2 text-xs text-slate-500">No brands match.</p>
        </div>
        @error('brand_id')<p class="mt-1 text-xs text-rose-600">{{ $message }}</p>@enderror
    </div>
    <div
        class="relative"
        x-data="productOptionPicker({ options: @js($categoryOptions), initialId: {{ $initialCategoryId }} })"
    >
        <label for="product-form-category-search" class="text-sm font-medium text-slate-700">Category</label>
        <input type="hidden" name="category_id" :value="selectedId != null ? selectedId : ''">
        <input
            id="product-form-category-search"
            type="text"
            x-model="query"
            autocomplete="off"
            role="combobox"
            :aria-expanded="open ? 'true' : 'false'"
            aria-autocomplete="list"
            aria-controls="product-form-category-list"
            placeholder="Search categories…"
            class="mt-1 w-full rounded-lg border px-3 py-2 text-sm shadow-sm focus:border-slate-500 focus:outline-none focus:ring-2 focus:ring-slate-200 @error('category_id') border-rose-500 @else border-slate-300 @enderror"
            @focus="onFocus()"
            @input="onSearchInput()"
            @blur="onBlur()"
            @keydown.escape.prevent="open = false"
        >
        <div
            id="product-form-category-list"
            x-show="open"
            x-cloak
            x-transition.opacity.duration.100ms
            @click.outside="open = false"
            class="absolute z-[60] mt-1 max-h-48 w-full overflow-y-auto rounded-lg border border-slate-200 bg-white py-1 shadow-lg ring-1 ring-slate-200/60"
            role="listbox"
        >
            <template x-for="opt in filtered" :key="opt.id">
                <button
                    type="button"
                    role="option"
                    class="flex w-full px-3 py-2 text-left text-sm text-slate-800 hover:bg-slate-50"
                    :class="Number(opt.id) === Number(selectedId) ? 'bg-slate-100 font-medium' : ''"
                    @mousedown.prevent="pick(opt)"
                    x-text="opt.name"
                ></button>
            </template>
            <p x-show="filtered.length === 0" class="px-3 py-2 text-xs text-slate-500">No categories match.</p>
        </div>
        @error('category_id')<p class="mt-1 text-xs text-rose-600">{{ $message }}</p>@enderror
    </div>
    <div
        class="md:col-span-2 rounded-xl border border-slate-200 bg-slate-50/80 p-4"
        x-data="{
            purchase: {{ (float) old('default_purchase_price', $product->default_purchase_price ?? 0) }},
            sale: {{ (float) old('default_sale_price', $product->default_sale_price ?? 0) }},
            get profit() { return Math.round((this.sale - this.purchase) * 100) / 100; },
            get markupPercent() {
                if (!this.purchase || this.purchase <= 0) {
                    return null;
                }
                return Math.round((this.profit / this.purchase) * 1000) / 10;
            },
            get marginPercent() {
                if (!this.sale || this.sale <= 0) {
                    return null;
                }
                return Math.round((this.profit / this.sale) * 1000) / 10;
            },
            formatPercent(value) {
                return value.toLocaleString(undefined, { minimumFractionDigits: 1, maximumFractionDigits: 1 });
            },
        }"
    >
        <div class="grid gap-4 sm:grid-cols-2">
            <div>
                <label class="text-sm font-medium">Purchase Price</label>
                <input type="number" step="0.01" name="default_purchase_price" x-model.number="purchase" class="mt-1 w-full rounded-lg border border-slate-300 bg-white px-3 py-2" required>
            </div>
            <div>
                <label class="text-sm font-medium">Sale Price</label>
                <input type="number" step="0.01" name="default_sale_price" x-model.number="sale" class="mt-1 w-full rounded-lg border border-slate-300 bg-white px-3 py-2" required>
            </div>
        </div>
        <dl class="mt-3 grid gap-2 text-sm text-slate-600 sm:grid-cols-3">
            <div>
                <dt class="text-xs font-medium uppercase tracking-wide text-slate-500">Profit</dt>
                <dd class="mt-0.5 font-semibold tabular-nums" :class="profit >= 0 ? 'text-emerald-700' : 'text-rose-700'" x-text="profit.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })"></dd>
            </div>
            <div>
                <dt class="text-xs font-medium uppercase tracking-wide text-slate-500">Markup (on cost)</dt>
                <dd class="mt-0.5 font-semibold tabular-nums" :class="profit >= 0 ? 'text-emerald-700' : 'text-rose-700'">
                    <template x-if="markupPercent !== null">
                        <span x-text="`${formatPercent(markupPercent)}%`"></span>
                    </template>
                    <span x-show="markupPercent === null" class="text-slate-400">—</span>
                </dd>
            </div>
            <div>
                <dt class="text-xs font-medium uppercase tracking-wide text-slate-500">Margin (on sale)</dt>
                <dd class="mt-0.5 font-semibold tabular-nums" :class="profit >= 0 ? 'text-emerald-700' : 'text-rose-700'">
                    <template x-if="marginPercent !== null">
                        <span x-text="`${formatPercent(marginPercent)}%`"></span>
                    </template>
                    <span x-show="marginPercent === null" class="text-slate-400">—</span>
                </dd>
            </div>
        </dl>
    </div>
    <div>
        <label class="text-sm font-medium">Max line discount (%)</label>
        <input type="number" step="0.01" min="0" max="100" name="maximum_deductible" value="{{ old('maximum_deductible', isset($product) && $product->maximum_deductible !== null ? $product->maximum_deductible : '') }}" placeholder="Optional — inherit company default" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 @error('maximum_deductible') border-rose-500 @enderror">
        <p class="mt-1 text-xs text-slate-500">Caps invoice line discounts for this SKU. Leave blank unless this product needs a tighter limit than the global default.</p>
        @error('maximum_deductible')<p class="mt-1 text-xs text-rose-600">{{ $message }}</p>@enderror
    </div>
    <div>
        <label class="text-sm font-medium">Warranty Months</label>
        <input type="number" name="warranty_months" value="{{ old('warranty_months', $product->warranty_months ?? 12) }}" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2" required>
    </div>
    <div>
        <label class="text-sm font-medium">Reorder Level</label>
        <input type="number" name="reorder_level" value="{{ old('reorder_level', $product->reorder_level ?? 0) }}" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2" required>
    </div>
    <div>
        <label class="text-sm font-medium">Unit of Measure</label>
        <input name="unit_of_measure" value="{{ old('unit_of_measure', $product->unit_of_measure ?? 'unit') }}" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2" required>
    </div>
    <x-model-photo-upload :existing-url="$product->photoUrl() ?? null" label="Product photo" />
    <div class="flex flex-wrap items-center gap-3 pt-6">
        <div class="rounded-xl border border-indigo-100 bg-indigo-50 px-3 py-2 text-sm text-indigo-800">
            Asset tracking is enabled by default for all products.
        </div>
        <div class="rounded-xl border border-slate-200 bg-slate-50 px-3 py-2">
            <input type="hidden" name="is_active" value="0">
            <label for="is_active" class="flex cursor-pointer items-center gap-3">
                <span class="text-sm font-medium text-slate-700">Active</span>
                <div class="relative">
                    <input id="is_active" type="checkbox" name="is_active" value="1" class="peer sr-only" @checked(old('is_active', $product->is_active ?? true))>
                    <span class="block h-6 w-11 rounded-full bg-slate-300 transition peer-checked:bg-emerald-500"></span>
                    <span class="absolute left-1 top-1 h-4 w-4 rounded-full bg-white transition peer-checked:translate-x-5"></span>
                </div>
            </label>
        </div>
    </div>
</div>
<div class="mt-4">
    <label class="text-sm font-medium">Description</label>
    <textarea name="description" rows="4" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2">{{ old('description', $product->description ?? '') }}</textarea>
</div>
<div class="mt-4">
    <button class="rounded-lg bg-slate-900 px-4 py-2 text-white">Save</button>
</div>
