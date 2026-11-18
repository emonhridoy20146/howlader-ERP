@extends('layouts.app')
@section('title', 'Edit Purchase Order')
@section('page-title', 'Edit Purchase Order')
@section('content')
@php
    $poEditDefaultLines = $purchaseOrder->lines->map(fn ($line) => [
        'id' => $line->id,
        'product_id' => $line->product_id,
        'qty_ordered' => $line->qty_ordered,
        'unit_cost' => $line->unit_cost,
        'qty_received' => (int) $line->qty_received,
    ])->values()->all();
    $editFrozenAdditionalCosts = round((float) $purchaseOrder->additional_cost_amount, 2);
    $hasReceivedQty = (int) $purchaseOrder->lines->sum('qty_received') > 0;
@endphp
<form action="{{ route('purchase-orders.update', $purchaseOrder) }}" method="post" class="space-y-4 rounded-2xl bg-white p-5 shadow-sm ring-1 ring-slate-200">
    @csrf
    @method('PUT')

    @if ($errors->any())
        <div class="rounded-lg border border-rose-200 bg-rose-50 px-3 py-2 text-sm text-rose-700">
            <ul class="list-disc pl-5">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    @php
        $editSettledAmount = round((float) $purchaseOrder->amountPaid(), 2);
    @endphp

    @if ($editSettledAmount > 0.009)
        <div class="rounded-lg border border-sky-200 bg-sky-50 px-3 py-2 text-sm text-sky-900">
            <strong>{{ number_format($editSettledAmount, 2) }}</strong> is already settled on this PO (payments + additional costs).
            You can change or replace lines that have <strong>no receipts yet</strong>. If the new grand total is lower than that amount, the difference is kept as <strong>supplier credit</strong> on this order (balance due stays 0).
        </div>
    @endif

    @if ($hasReceivedQty)
        <div class="rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-sm text-amber-900">
            This PO already has received quantities on one or more lines. You cannot remove those lines or set ordered quantity below what was received, and you cannot change the product on a line that has receipts.
        </div>
    @endif

    @php
        $supplierOptions = $suppliers->map(fn ($supplier) => ['value' => (string) $supplier->id, 'label' => $supplier->name])->values()->all();
        $warehouseOptions = $locations->map(fn ($location) => ['value' => (string) $location->id, 'label' => $location->name])->values()->all();
    @endphp

    <div class="grid gap-4 md:grid-cols-2">
        <div>
            <label class="text-sm font-medium">Supplier</label>
            <div class="mt-1">
                <x-searchable-select
                    name="supplier_id"
                    :options="$supplierOptions"
                    :selected="old('supplier_id', (string) $purchaseOrder->supplier_id)"
                    placeholder="Select supplier"
                    :clearable="false"
                />
            </div>
        </div>

        <div>
            <label class="text-sm font-medium">Warehouse</label>
            <div class="mt-1">
                <x-searchable-select
                    name="destination_location_id"
                    :options="$warehouseOptions"
                    :selected="old('destination_location_id', (string) $purchaseOrder->destination_location_id)"
                    placeholder="Select warehouse"
                    :clearable="false"
                />
            </div>
        </div>

        <div>
            <label class="text-sm font-medium">Ordered Date</label>
            <input type="date" name="ordered_at" value="{{ old('ordered_at', optional($purchaseOrder->ordered_at)->toDateString()) }}" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2" required>
        </div>

        <div>
            <label class="text-sm font-medium">Expected Date</label>
            <input type="date" name="expected_at" value="{{ old('expected_at', optional($purchaseOrder->expected_at)->toDateString()) }}" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2">
        </div>
    </div>

    <div>
        <label class="text-sm font-medium">Notes</label>
        <textarea name="notes" rows="3" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2">{{ old('notes', $purchaseOrder->notes) }}</textarea>
    </div>

    <div class="grid gap-4 md:grid-cols-2">
        <div>
            <label class="text-sm font-medium">Order discount (flat amount)</label>
            <input type="number" step="0.01" min="0" name="discount_amount" id="po-discount" value="{{ old('discount_amount', $purchaseOrder->discount_amount) }}" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2">
            @error('discount_amount')<p class="mt-1 text-xs text-rose-600">{{ $message }}</p>@enderror
        </div>
        <div>
            <label class="text-sm font-medium">Order tax (flat amount)</label>
            <input type="number" step="0.01" min="0" name="tax_amount" id="po-tax" value="{{ old('tax_amount', $purchaseOrder->tax_amount) }}" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2">
            @error('tax_amount')<p class="mt-1 text-xs text-rose-600">{{ $message }}</p>@enderror
        </div>
    </div>

    <div class="rounded-xl border border-slate-200">
        <div class="flex items-center justify-between border-b border-slate-200 px-3 py-2">
            <h2 class="text-sm font-semibold">Product Rows</h2>
            <button type="button" id="add-line" class="rounded-lg border border-slate-300 px-3 py-1.5 text-sm hover:bg-slate-50">Add Row</button>
        </div>
        <div id="po-lines" class="space-y-3 p-3"></div>
    </div>

    <div class="rounded-xl border border-slate-200 bg-slate-50/60 p-4">
        <h3 class="text-sm font-semibold text-slate-700">Purchase Summary</h3>
        <div class="mt-3 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
            <div>
                <p class="text-xs uppercase tracking-wide text-slate-500">Total Rows</p>
                <p class="text-lg font-semibold text-slate-900" data-summary-rows>0</p>
            </div>
            <div>
                <p class="text-xs uppercase tracking-wide text-slate-500">Total Quantity</p>
                <p class="text-lg font-semibold text-slate-900" data-summary-qty>0</p>
            </div>
            <div>
                <p class="text-xs uppercase tracking-wide text-slate-500">Lines subtotal</p>
                <p class="text-lg font-semibold text-slate-900" data-summary-subtotal>Tk 0.00</p>
            </div>
            <div>
                <p class="text-xs uppercase tracking-wide text-slate-500">Grand total</p>
                <p class="text-lg font-semibold text-emerald-900" data-summary-grand>Tk 0.00</p>
            </div>
            <div>
                <p class="text-xs uppercase tracking-wide text-slate-500">Discount</p>
                <p class="text-lg font-semibold text-slate-800" data-summary-discount>Tk 0.00</p>
            </div>
            <div>
                <p class="text-xs uppercase tracking-wide text-slate-500">Tax</p>
                <p class="text-lg font-semibold text-slate-800" data-summary-tax>Tk 0.00</p>
            </div>
            <div>
                <p class="text-xs uppercase tracking-wide text-slate-500">Additional costs</p>
                <p class="text-lg font-semibold text-slate-800" data-summary-extra-costs>Tk 0.00</p>
            </div>
        </div>
        <p class="mt-3 text-xs text-slate-500">Additional costs are added from the purchase order details page (not here).</p>
    </div>

    <div class="flex flex-wrap gap-2">
        <button class="rounded-lg bg-slate-900 px-4 py-2 text-white">Update PO</button>
        <a href="{{ $purchaseOrder->status === 'draft' ? route('purchase-orders.index') : route('purchase-orders.show', $purchaseOrder) }}" class="rounded-lg border border-slate-300 px-4 py-2 text-sm text-slate-700 hover:bg-slate-50">Cancel</a>
    </div>
</form>

<template id="po-line-template">
    <div class="grid gap-3 rounded-lg border border-slate-200 p-3 md:grid-cols-12" data-line-row>
        <div class="md:col-span-5">
            <input type="hidden" value="" data-key="id" data-line-id-input>
            <label class="text-xs font-medium uppercase tracking-wide text-slate-500">Product</label>
            <div class="relative mt-1" data-product-picker>
                <input type="text" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm" data-product-search placeholder="Search SKU or name" autocomplete="off" role="combobox" aria-expanded="false">
                <div class="absolute z-20 mt-1 hidden max-h-56 w-full overflow-y-auto rounded-lg border border-slate-200 bg-white shadow-lg" data-product-results></div>
                <select class="sr-only" data-key="product_id" data-product-select required tabindex="-1">
                    <option value="">Select product</option>
                    @foreach ($products as $product)
                        <option value="{{ $product->id }}" data-default-cost="{{ $product->default_purchase_price }}" data-search="{{ e(Str::lower($product->sku . ' ' . $product->name)) }}">{{ $product->sku }} - {{ $product->name }}</option>
                    @endforeach
                </select>
            </div>
            <div class="mt-1 hidden text-xs text-amber-800" data-received-hint></div>
        </div>
        <div class="md:col-span-2">
            <label class="text-xs font-medium uppercase tracking-wide text-slate-500">Qty</label>
            <input type="number" min="1" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2" data-key="qty_ordered" required>
        </div>
        <div class="md:col-span-2">
            <label class="text-xs font-medium uppercase tracking-wide text-slate-500">Unit Cost (optional)</label>
            <input type="number" step="0.01" min="0" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2" data-key="unit_cost" placeholder="Auto from product">
        </div>
        <div class="md:col-span-2">
            <label class="text-xs font-medium uppercase tracking-wide text-slate-500">Line Total</label>
            <div class="mt-1 rounded-lg border border-slate-200 bg-slate-50 px-3 py-2 text-sm font-semibold text-slate-800" data-line-total>Tk 0.00</div>
        </div>
        <div class="md:col-span-1 md:flex md:items-end">
            <button type="button" class="w-full rounded-lg border border-rose-300 px-3 py-2 text-sm text-rose-700 hover:bg-rose-50" data-remove-line>Remove</button>
        </div>
    </div>
</template>

<script>
(() => {
    const linesContainer = document.getElementById('po-lines');
    const template = document.getElementById('po-line-template');
    const addLineButton = document.getElementById('add-line');
    const summaryRows = document.querySelector('[data-summary-rows]');
    const summaryQty = document.querySelector('[data-summary-qty]');
    const summarySubtotal = document.querySelector('[data-summary-subtotal]');
    const summaryDiscount = document.querySelector('[data-summary-discount]');
    const summaryTax = document.querySelector('[data-summary-tax]');
    const summaryExtraCosts = document.querySelector('[data-summary-extra-costs]');
    const summaryGrand = document.querySelector('[data-summary-grand]');
    const discountInput = document.getElementById('po-discount');
    const taxInput = document.getElementById('po-tax');
    const frozenAdditionalCosts = @json($editFrozenAdditionalCosts);

    function formatNumber(value) {
        return Number.isInteger(value) ? String(value) : value.toFixed(2);
    }

    function formatCurrency(value) {
        return `Tk ${value.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
    }

    function refreshSummary() {
        const rows = [...linesContainer.querySelectorAll('[data-line-row]')];
        let totalQty = 0;
        let subtotal = 0;
        rows.forEach((row) => {
            const qty = Number.parseFloat(row.querySelector('[data-key="qty_ordered"]')?.value || 0) || 0;
            const unitCostInput = row.querySelector('[data-key="unit_cost"]');
            const selectedOption = row.querySelector('[data-key="product_id"]')?.selectedOptions?.[0];
            const manualCost = Number.parseFloat(unitCostInput?.value || '');
            const defaultCost = Number.parseFloat(selectedOption?.dataset.defaultCost || '');
            const unitCost = Number.isFinite(manualCost) ? manualCost : (Number.isFinite(defaultCost) ? defaultCost : 0);
            const lineTotal = qty * unitCost;
            const lineTotalEl = row.querySelector('[data-line-total]');
            if (lineTotalEl) {
                lineTotalEl.textContent = formatCurrency(Math.round(Math.max(0, lineTotal) * 100) / 100);
            }

            totalQty += qty;
            subtotal += lineTotal;
        });

        const discount = Math.max(0, Number.parseFloat(discountInput?.value || '0') || 0);
        const tax = Math.max(0, Number.parseFloat(taxInput?.value || '0') || 0);
        const subRounded = Math.round(Math.max(0, subtotal) * 100) / 100;
        const extraRounded = Math.round(Math.max(0, Number(frozenAdditionalCosts) || 0) * 100) / 100;
        const discApplied = Math.min(discount, subRounded);
        const grand = Math.round(Math.max(0, subRounded - discApplied + tax + extraRounded) * 100) / 100;

        summaryRows.textContent = String(rows.length);
        summaryQty.textContent = formatNumber(totalQty);
        summarySubtotal.textContent = formatCurrency(subRounded);
        summaryDiscount.textContent = formatCurrency(discApplied);
        summaryTax.textContent = formatCurrency(tax);
        summaryExtraCosts.textContent = formatCurrency(extraRounded);
        summaryGrand.textContent = formatCurrency(grand);
    }

    function refreshNames() {
        [...linesContainer.querySelectorAll('[data-line-row]')].forEach((row, index) => {
            row.querySelectorAll('[data-key]').forEach((input) => {
                input.name = `lines[${index}][${input.dataset.key}]`;
            });
        });
    }

    function wireProductSearch(row) {
        const searchInput = row.querySelector('[data-product-search]');
        const productSelect = row.querySelector('[data-product-select]');
        const results = row.querySelector('[data-product-results]');
        const picker = row.querySelector('[data-product-picker]');
        if (!searchInput || !productSelect || !results || !picker) {
            return;
        }

        const products = [...productSelect.options]
            .filter((option) => option.value !== '')
            .map((option) => ({
                value: option.value,
                label: option.text,
                search: option.dataset.search || option.text.toLowerCase(),
            }));
        let highlightedIndex = -1;

        function closeResults() {
            results.classList.add('hidden');
            searchInput.setAttribute('aria-expanded', 'false');
            highlightedIndex = -1;
        }

        function renderResults(keyword) {
            const normalizedKeyword = keyword.trim().toLowerCase();
            const filtered = products
                .filter((product) => normalizedKeyword === '' || product.search.includes(normalizedKeyword))
                .slice(0, 40);

            if (!filtered.length) {
                results.innerHTML = '<div class="px-3 py-2 text-sm text-slate-500">No products found</div>';
                results.classList.remove('hidden');
                searchInput.setAttribute('aria-expanded', 'true');
                return [];
            }

            results.innerHTML = filtered
                .map(
                    (product, index) => `<button type="button" class="block w-full px-3 py-2 text-left text-sm hover:bg-slate-100 ${index === 0 ? 'bg-slate-50' : ''}" data-product-value="${product.value}">${product.label}</button>`
                )
                .join('');
            results.classList.remove('hidden');
            searchInput.setAttribute('aria-expanded', 'true');
            highlightedIndex = 0;
            return filtered;
        }

        function syncInputWithSelection() {
            const selectedOption = productSelect.options[productSelect.selectedIndex];
            searchInput.value = selectedOption && selectedOption.value ? selectedOption.text : '';
        }

        function selectProductByValue(value) {
            if (!value) {
                productSelect.value = '';
                searchInput.value = '';
                closeResults();
                return;
            }
            productSelect.value = value;
            syncInputWithSelection();
            productSelect.dispatchEvent(new Event('change'));
            closeResults();
        }

        searchInput.addEventListener('focus', () => renderResults(searchInput.value));
        searchInput.addEventListener('input', () => {
            productSelect.value = '';
            renderResults(searchInput.value);
        });

        searchInput.addEventListener('keydown', (event) => {
            const items = [...results.querySelectorAll('[data-product-value]')];
            if (!items.length) {
                return;
            }
            if (event.key === 'ArrowDown') {
                event.preventDefault();
                highlightedIndex = Math.min(highlightedIndex + 1, items.length - 1);
            } else if (event.key === 'ArrowUp') {
                event.preventDefault();
                highlightedIndex = Math.max(highlightedIndex - 1, 0);
            } else if (event.key === 'Enter') {
                event.preventDefault();
                const selectedItem = items[highlightedIndex] || items[0];
                if (selectedItem) {
                    selectProductByValue(selectedItem.dataset.productValue);
                }
                return;
            } else if (event.key === 'Escape') {
                closeResults();
                return;
            } else {
                return;
            }

            items.forEach((item, index) => {
                item.classList.toggle('bg-slate-50', index === highlightedIndex);
            });
        });

        results.addEventListener('click', (event) => {
            const target = event.target.closest('[data-product-value]');
            if (!target) {
                return;
            }
            selectProductByValue(target.dataset.productValue);
        });

        document.addEventListener('click', (event) => {
            if (picker.contains(event.target)) {
                return;
            }
            closeResults();
        });

        syncInputWithSelection();
    }

    function addLine(prefill = null) {
        const fragment = template.content.cloneNode(true);
        const row = fragment.querySelector('[data-line-row]');

        row.querySelector('[data-remove-line]').addEventListener('click', () => {
            row.remove();
            refreshNames();
            refreshSummary();
        });

        const productSelect = row.querySelector('[data-key="product_id"]');
        const unitCostInput = row.querySelector('[data-key="unit_cost"]');

        productSelect.addEventListener('change', () => {
            if (unitCostInput.value) {
                return;
            }
            const selected = productSelect.options[productSelect.selectedIndex];
            if (selected && selected.dataset.defaultCost) {
                unitCostInput.value = selected.dataset.defaultCost;
            }
        });

        const idInput = row.querySelector('[data-line-id-input]');
        if (prefill) {
            if (prefill.id) {
                idInput.value = prefill.id;
            }
            if (prefill.product_id) {
                productSelect.value = prefill.product_id;
            }
            if (prefill.qty_ordered) {
                row.querySelector('[data-key="qty_ordered"]').value = prefill.qty_ordered;
            }
            if (prefill.unit_cost) {
                unitCostInput.value = prefill.unit_cost;
            }
            const hint = row.querySelector('[data-received-hint]');
            if (hint && Number(prefill.qty_received) > 0) {
                hint.textContent = `This line already has ${prefill.qty_received} units received — ordered qty must stay at least that high; line cannot be removed.`;
                hint.classList.remove('hidden');
            }
        }

        linesContainer.appendChild(fragment);
        wireProductSearch(row);
        refreshNames();
        refreshSummary();
    }

    addLineButton.addEventListener('click', () => addLine());
    discountInput?.addEventListener('input', refreshSummary);
    taxInput?.addEventListener('input', refreshSummary);
    linesContainer.addEventListener('input', (event) => {
        if (!event.target.closest('[data-key]')) {
            return;
        }
        refreshSummary();
    });
    linesContainer.addEventListener('change', (event) => {
        if (!event.target.closest('[data-key]')) {
            return;
        }
        refreshSummary();
    });
    const oldLines = @json(old('lines', $poEditDefaultLines));

    if (Array.isArray(oldLines) && oldLines.length) {
        oldLines.forEach((line) => addLine(line));
    } else {
        addLine();
    }
    refreshSummary();
})();
</script>
@endsection
