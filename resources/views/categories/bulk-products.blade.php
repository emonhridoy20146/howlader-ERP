@extends('layouts.app')

@section('title', 'Bulk product categories')
@section('page-title', 'Bulk product categories')

@section('content')
@php
    use App\Support\CategoryTree;
    $perPage = (string) request('per_page', 20);
    $filterQuery = array_filter([
        'q' => $filters['q'],
        'filter_category_id' => $filters['filter_category_id'],
        'per_page' => $perPage,
    ], fn ($v) => $v !== '' && $v !== null);
@endphp

<div class="space-y-4">
    <div class="flex flex-wrap items-center justify-between gap-2">
        <p class="text-sm text-slate-600">Search products, filter by category tree. Brand and category save automatically when you change a row.</p>
        <a href="{{ route('categories.index') }}" class="rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50">← Categories</a>
    </div>

    <div
        id="bulk-products-toast"
        class="pointer-events-none fixed bottom-4 right-4 z-[70] hidden max-w-sm translate-y-0 rounded-xl border px-4 py-3 text-sm shadow-lg transition-all duration-200 sm:bottom-6 sm:right-6"
        role="status"
        aria-live="polite"
    >
        <div class="flex items-start gap-3">
            <span id="bulk-products-toast-icon" class="mt-0.5 shrink-0"></span>
            <p id="bulk-products-toast-message" class="min-w-0 font-medium leading-snug"></p>
        </div>
    </div>

    <div class="rounded-2xl border border-slate-200 bg-white shadow-sm ring-1 ring-slate-200">
        <div class="border-b border-slate-200 px-4 py-3">
            <h2 class="text-sm font-semibold text-slate-900">Category tree</h2>
            <p class="mt-0.5 text-xs text-slate-500">Vertical parent tree — click a category to filter products (includes all subcategories). Counts include products in child categories.</p>
        </div>
        <nav class="flex max-h-72 flex-col gap-0.5 overflow-y-auto overflow-x-hidden p-2 text-sm sm:max-h-80" aria-label="Category tree filter">
            <a href="{{ route('categories.bulk-products', collect($filterQuery)->except('filter_category_id')->all()) }}"
                class="flex w-full min-w-0 items-center justify-between gap-2 rounded-lg px-3 py-1.5 {{ $filters['filter_category_id'] === '' ? 'bg-slate-900 font-medium text-white' : 'text-slate-700 hover:bg-slate-50' }}">
                <span class="truncate">All categories</span>
            </a>
            @foreach ($treeNodes as $node)
                @php
                    $isActive = (string) $node['id'] === $filters['filter_category_id'];
                    $treeLinkQuery = array_merge($filterQuery, ['filter_category_id' => $node['id']]);
                    $indentPx = 12 + ($node['depth'] * 16);
                @endphp
                <a href="{{ route('categories.bulk-products', $treeLinkQuery) }}"
                    class="grid w-full min-w-0 grid-cols-[minmax(0,1fr)_auto] items-center gap-2 rounded-lg py-1.5 pr-3 text-slate-800 hover:bg-slate-50 {{ $isActive ? 'bg-emerald-50 font-medium text-emerald-900 ring-1 ring-emerald-200' : '' }}"
                    style="padding-left: {{ $indentPx }}px">
                    <span class="flex min-w-0 items-center gap-1.5 truncate">
                        @if ($node['depth'] > 0)
                            <span class="shrink-0 text-xs text-slate-400" aria-hidden="true">↳</span>
                        @endif
                        <span class="truncate">{{ $node['name'] }}</span>
                    </span>
                    <span class="shrink-0 text-xs tabular-nums text-slate-500" title="Products in this category and subcategories">{{ $node['product_count'] }}</span>
                </a>
            @endforeach
        </nav>
    </div>

    <div class="space-y-4">
        <form method="get" action="{{ route('categories.bulk-products') }}" class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm ring-1 ring-slate-200">
            <div>
                <label class="mb-1 block text-xs font-medium text-slate-600">Search products</label>
                <input type="text" name="q" value="{{ $filters['q'] }}" placeholder="Name, model, barcode" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
            </div>
            @if ($filters['filter_category_id'] !== '')
                <input type="hidden" name="filter_category_id" value="{{ $filters['filter_category_id'] }}">
            @endif
            <input type="hidden" name="per_page" value="{{ $perPage }}">
            <div class="mt-3 flex flex-wrap gap-2">
                <button type="submit" class="rounded-lg bg-slate-800 px-4 py-2 text-sm font-semibold text-white hover:bg-slate-700">Apply filters</button>
                <a href="{{ route('categories.bulk-products') }}" class="rounded-lg border border-slate-300 bg-white px-4 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50">Reset</a>
            </div>
        </form>

        <div id="bulk-category-form">
            <div class="mb-3 space-y-3 rounded-xl border border-slate-200 bg-slate-50/80 p-3">
                <div class="flex flex-col gap-3 lg:flex-row lg:flex-wrap lg:items-end">
                    <div class="min-w-[14rem] flex-1">
                        <label class="mb-1 block text-xs font-medium text-slate-600">Target category (tree)</label>
                        <x-searchable-select
                            name="target_category_id"
                            :options="$categorySelectOptions"
                            :selected="old('target_category_id', $filters['target_category_id'])"
                            placeholder="Search category to assign…"
                            :clearable="false"
                            :tree="true"
                        />
                    </div>
                    <button type="button" id="bulk-apply-category" class="rounded-lg bg-amber-600 px-4 py-2 text-sm font-semibold text-white hover:bg-amber-700">
                        Apply category to selected
                    </button>
                </div>
                <div class="flex flex-col gap-3 border-t border-slate-200/80 pt-3 lg:flex-row lg:flex-wrap lg:items-end">
                    <div class="min-w-[14rem] flex-1">
                        <label class="mb-1 block text-xs font-medium text-slate-600">Target brand</label>
                        <x-searchable-select
                            name="target_brand_id"
                            :options="$brandSelectOptions"
                            :selected="old('target_brand_id', '')"
                            placeholder="Search brand to assign…"
                            :clearable="false"
                        />
                    </div>
                    <button type="button" id="bulk-apply-brand" class="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-700">
                        Apply brand to selected
                    </button>
                </div>
                <p class="border-t border-slate-200/80 pt-3 text-xs text-slate-500">{{ $products->total() }} product(s) match · {{ $products->count() }} on this page ({{ $products->perPage() }} per page)</p>
            </div>

            <div class="overflow-x-auto rounded-2xl border border-slate-200 bg-white shadow-sm ring-1 ring-slate-200">
                <table class="min-w-full text-sm whitespace-nowrap">
                    <thead class="border-b border-slate-200 bg-slate-50 text-left text-xs font-semibold uppercase tracking-wider text-slate-700">
                        <tr>
                            <th class="px-3 py-3">
                                <input type="checkbox" id="select-all-products" class="rounded border-slate-300" title="Select all on page">
                            </th>
                            <th class="px-3 py-3">Product</th>
                            <th class="px-3 py-3">Current brand</th>
                            <th class="px-3 py-3">Current category</th>
                            <th class="px-3 py-3 min-w-[12rem]">New brand</th>
                            <th class="px-3 py-3 min-w-[14rem]">New category</th>
                            <th class="px-3 py-3 w-20">Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($products as $product)
                            @php
                                $currentCategory = $product->category;
                                $currentLabel = $currentCategory
                                    ? CategoryTree::breadcrumb($currentCategory, $categoriesById)
                                    : '—';
                            @endphp
                            <tr class="border-b border-slate-100 last:border-0 hover:bg-slate-50/70" data-product-row="{{ $product->id }}">
                                <td class="px-3 py-2">
                                    <input type="checkbox" name="product_ids[]" value="{{ $product->id }}" class="product-row-check rounded border-slate-300" data-product-label="{{ $product->name }}">
                                </td>
                                <td class="px-3 py-2 max-w-xs truncate font-medium text-slate-900 bulk-product-name" title="{{ $product->name }}">{{ $product->name }}</td>
                                <td class="px-3 py-2 bulk-current-brand">{{ $product->brand?->name ?? '—' }}</td>
                                <td class="px-3 py-2 max-w-[10rem] whitespace-normal text-xs text-slate-600 bulk-current-category">{{ $currentLabel }}</td>
                                <td class="px-3 py-2">
                                    <x-searchable-select
                                        name="brand_assignments[{{ $product->id }}]"
                                        :options="$brandSelectOptions"
                                        :selected="(string) old('brand_assignments.'.$product->id, $product->brand_id)"
                                        placeholder="Brand…"
                                        :clearable="false"
                                        :dispatch-change="true"
                                    />
                                </td>
                                <td class="px-3 py-2">
                                    <x-searchable-select
                                        name="assignments[{{ $product->id }}]"
                                        :options="$categorySelectOptions"
                                        :selected="(string) old('assignments.'.$product->id, $product->category_id)"
                                        placeholder="Category…"
                                        :clearable="false"
                                        :tree="true"
                                        :dispatch-change="true"
                                    />
                                </td>
                                <td class="px-3 py-2 text-xs text-slate-400 bulk-row-status" data-product-id="{{ $product->id }}"></td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="7" class="px-4 py-12 text-center text-sm text-slate-500">No products match your filters.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        <div class="mt-4">{{ $products->links() }}</div>
    </div>
</div>

<script>
(function () {
    const patchUrl = @js(route('categories.bulk-products.patch'));
    const bulkUrl = @js(route('categories.bulk-products.update'));
    const csrf = document.documentElement.dataset.csrfToken || '';
    const formRoot = document.getElementById('bulk-category-form');
    const toast = document.getElementById('bulk-products-toast');
    const toastIcon = document.getElementById('bulk-products-toast-icon');
    const toastMessage = document.getElementById('bulk-products-toast-message');
    const inFlight = new Map();
    /** @type {Map<string, { categoryId: string, brandId: string }>} */
    const pendingRowPayloads = new Map();
    let pendingSaves = 0;

    const spinnerSvg = '<svg class="h-5 w-5 animate-spin text-sky-600" fill="none" viewBox="0 0 24 24" aria-hidden="true"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path></svg>';
    const successSvg = '<svg class="h-5 w-5 text-emerald-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75 11.25 15 15 9.75M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z"/></svg>';
    const errorSvg = '<svg class="h-5 w-5 text-rose-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m9-.75a9 9 0 1 1-18 0 9 9 0 0 1 18 0Zm-9 3.75h.008v.008H12v-.008Z"/></svg>';
    const rowSpinnerHtml = '<span class="inline-flex items-center gap-1 text-amber-600"><svg class="h-3.5 w-3.5 animate-spin" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path></svg>Saving</span>';

    function showToastPanel(kind, message) {
        if (!toast || !toastIcon || !toastMessage) return;
        toastMessage.textContent = message;
        toast.classList.remove(
            'hidden',
            'border-slate-200', 'bg-white', 'text-slate-800',
            'border-emerald-200', 'bg-emerald-50', 'text-emerald-900',
            'border-rose-200', 'bg-rose-50', 'text-rose-900',
            'border-sky-200', 'bg-sky-50', 'text-sky-900',
        );
        if (kind === 'saving') {
            toastIcon.innerHTML = spinnerSvg;
            toast.classList.add('border-sky-200', 'bg-sky-50', 'text-sky-900');
        } else if (kind === 'success') {
            toastIcon.innerHTML = successSvg;
            toast.classList.add('border-emerald-200', 'bg-emerald-50', 'text-emerald-900');
        } else {
            toastIcon.innerHTML = errorSvg;
            toast.classList.add('border-rose-200', 'bg-rose-50', 'text-rose-900');
        }
        toast.classList.remove('hidden');
    }

    function hideToastSoon(delay) {
        clearTimeout(hideToastSoon._t);
        hideToastSoon._t = setTimeout(() => toast?.classList.add('hidden'), delay);
    }

    function showToast(message, ok) {
        showToastPanel(ok ? 'success' : 'error', message);
        hideToastSoon(4500);
    }

    function beginSaveProgress(label) {
        pendingSaves += 1;
        showToastPanel('saving', pendingSaves > 1 ? label + ' (' + pendingSaves + ' in progress)' : label);
    }

    function endSaveProgress(success, message) {
        pendingSaves = Math.max(0, pendingSaves - 1);
        if (pendingSaves > 0) {
            showToastPanel('saving', 'Saving… (' + pendingSaves + ' in progress)');
            return;
        }
        if (success) {
            showToast(message, true);
        }
    }

    function rowStatusEl(productId) {
        return formRoot?.querySelector('.bulk-row-status[data-product-id="' + productId + '"]');
    }

    function setRowStatus(productId, html, tone) {
        const el = rowStatusEl(productId);
        if (!el) return;
        if (html === 'spinner') {
            el.innerHTML = rowSpinnerHtml;
        } else if (html === 'success') {
            el.innerHTML = '<span class="text-emerald-600">Saved</span>';
        } else if (html === 'error') {
            el.innerHTML = '<span class="text-rose-600">Error</span>';
        } else {
            el.textContent = html || '';
        }
        el.classList.remove('text-slate-400', 'text-amber-600', 'text-emerald-600', 'text-rose-600');
        if (tone) {
            el.classList.add(tone);
        }
    }

    function productLabel(productId) {
        const row = formRoot?.querySelector('[data-product-row="' + productId + '"]');
        return row?.querySelector('.bulk-product-name')?.textContent?.trim() || 'Product';
    }

    function updateRowDisplay(productId, data) {
        const row = formRoot?.querySelector('[data-product-row="' + productId + '"]');
        if (!row) return;
        if (data.brand_name != null) {
            row.querySelector('.bulk-current-brand').textContent = data.brand_name;
        }
        if (data.category_label != null) {
            row.querySelector('.bulk-current-category').textContent = data.category_label;
        }
    }

    function readRowIds(productId, change) {
        let categoryId = formRoot.querySelector('input[name="assignments[' + productId + ']"]')?.value || '';
        let brandId = formRoot.querySelector('input[name="brand_assignments[' + productId + ']"]')?.value || '';
        const changeName = change?.name || '';
        if (changeName === 'assignments[' + productId + ']') {
            categoryId = String(change.value ?? '');
        } else if (changeName === 'brand_assignments[' + productId + ']') {
            brandId = String(change.value ?? '');
        }
        return { categoryId, brandId };
    }

    function queueProductRowSave(productId, change) {
        const next = readRowIds(productId, change);
        if (!next.categoryId || !next.brandId) {
            return;
        }
        pendingRowPayloads.set(String(productId), next);
        flushProductRowSave(productId);
    }

    async function flushProductRowSave(productId) {
        const key = String(productId);
        if (inFlight.get(key)) {
            return;
        }
        const payload = pendingRowPayloads.get(key);
        if (!payload) {
            return;
        }
        pendingRowPayloads.delete(key);

        const categoryId = Number(payload.categoryId);
        const brandId = Number(payload.brandId);
        if (!categoryId || !brandId) {
            return;
        }

        inFlight.set(key, true);
        setRowStatus(key, 'spinner');
        beginSaveProgress('Saving ' + productLabel(key) + '…');

        try {
            const res = await fetch(patchUrl, {
                method: 'PATCH',
                headers: {
                    'Accept': 'application/json',
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': csrf,
                    'X-Requested-With': 'XMLHttpRequest',
                },
                body: JSON.stringify({
                    product_id: Number(key),
                    category_id: categoryId,
                    brand_id: brandId,
                }),
            });
            const data = await res.json().catch(() => ({}));
            if (!res.ok) {
                throw new Error(data.message || 'Could not save.');
            }
            if (Number(data.category_id) !== categoryId || Number(data.brand_id) !== brandId) {
                throw new Error('Server did not confirm the new category/brand. Please reload and try again.');
            }
            updateRowDisplay(key, data);
            setRowStatus(key, 'success');
            endSaveProgress(true, productLabel(key) + ' saved successfully.');
            setTimeout(() => setRowStatus(key, ''), 2500);
        } catch (err) {
            setRowStatus(key, 'error');
            pendingSaves = Math.max(0, pendingSaves - 1);
            showToast(err.message || 'Save failed.', false);
        } finally {
            inFlight.delete(key);
            if (pendingRowPayloads.has(key)) {
                flushProductRowSave(key);
            }
        }
    }

    function parseAssignmentName(name) {
        const m = name.match(/^assignments\[(\d+)\]$/) || name.match(/^brand_assignments\[(\d+)\]$/);
        return m ? m[1] : null;
    }

    document.addEventListener('searchable-select-changed', (event) => {
        const productId = parseAssignmentName(event.detail?.name || '');
        if (!productId) {
            return;
        }
        queueProductRowSave(productId, event.detail);
    });

    async function bulkApply(action) {
        const targetName = action === 'bulk' ? 'target_category_id' : 'target_brand_id';
        const target = formRoot.querySelector('input[name="' + targetName + '"]')?.value;
        if (!target) {
            showToast(action === 'bulk' ? 'Choose a target category first.' : 'Choose a target brand first.', false);
            return;
        }
        const checked = [...formRoot.querySelectorAll('.product-row-check:checked')];
        if (!checked.length) {
            showToast('Select at least one product.', false);
            return;
        }

        const body = new FormData();
        body.append('_token', csrf);
        body.append('action', action);
        checked.forEach((el) => body.append('product_ids[]', el.value));
        body.append(targetName, target);

        checked.forEach((el) => setRowStatus(el.value, 'spinner'));
        beginSaveProgress('Saving ' + checked.length + ' selected product(s)…');

        try {
            const res = await fetch(bulkUrl, {
                method: 'POST',
                headers: {
                    'Accept': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                },
                body,
            });
            const data = await res.json().catch(() => ({}));
            if (!res.ok) {
                throw new Error(data.message || 'Bulk update failed.');
            }
            (data.product_ids || checked.map((el) => Number(el.value))).forEach((id) => {
                const pid = String(id);
                if (action === 'bulk' && data.category_label) {
                    updateRowDisplay(pid, { category_label: data.category_label });
                    window.dispatchEvent(new CustomEvent('searchable-select-set', {
                        detail: { name: 'assignments[' + pid + ']', value: target },
                    }));
                }
                if (action === 'bulk_brand' && data.brand_name) {
                    updateRowDisplay(pid, { brand_name: data.brand_name });
                    window.dispatchEvent(new CustomEvent('searchable-select-set', {
                        detail: { name: 'brand_assignments[' + pid + ']', value: target },
                    }));
                }
                setRowStatus(pid, 'success');
                setTimeout(() => setRowStatus(pid, ''), 2500);
            });
            pendingSaves = 0;
            showToast(data.message || 'Selected products saved successfully.', true);
        } catch (err) {
            pendingSaves = 0;
            checked.forEach((el) => setRowStatus(el.value, 'error'));
            showToast(err.message || 'Bulk update failed.', false);
        }
    }

    document.getElementById('select-all-products')?.addEventListener('change', function () {
        document.querySelectorAll('.product-row-check').forEach((el) => {
            el.checked = this.checked;
        });
    });

    document.getElementById('bulk-apply-category')?.addEventListener('click', () => bulkApply('bulk'));
    document.getElementById('bulk-apply-brand')?.addEventListener('click', () => bulkApply('bulk_brand'));
})();
</script>
@endsection
