@extends('layouts.app')
@section('title', 'Create Purchase Order')
@section('page-title', 'Create Purchase Order')
@section('content')
<form action="{{ route('purchase-orders.store') }}" method="post" class="space-y-4 rounded-2xl bg-white p-5 shadow-sm ring-1 ring-slate-200">
	@csrf

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
		$supplierOptions = $suppliers->map(fn ($supplier) => ['value' => (string) $supplier->id, 'label' => $supplier->name])->values()->all();
		$warehouseOptions = $locations->map(fn ($location) => ['value' => (string) $location->id, 'label' => $location->name])->values()->all();
		$brandOptions = $brands->map(fn ($brand) => ['value' => (string) $brand->id, 'label' => $brand->name])->values()->all();
		$categoryOptions = $categories->map(fn ($category) => ['value' => (string) $category->id, 'label' => $category->name])->values()->all();
	@endphp

	<div class="grid gap-4 md:grid-cols-2">
		<div>
			<label class="text-sm font-medium">Supplier</label>
			<div class="mt-1">
				<x-searchable-select
					name="supplier_id"
					:options="$supplierOptions"
					:selected="old('supplier_id', '')"
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
					:selected="old('destination_location_id', '')"
					placeholder="Select warehouse"
					:clearable="false"
				/>
			</div>
		</div>

		<div>
			<label class="text-sm font-medium">Ordered Date</label>
			<input type="date" name="ordered_at" value="{{ old('ordered_at', now()->toDateString()) }}" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2" required>
		</div>

		<div>
			<label class="text-sm font-medium">Expected Date</label>
			<input type="date" name="expected_at" value="{{ old('expected_at') }}" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2">
		</div>
	</div>

	<div class="rounded-xl border border-slate-200">
		<div class="flex items-center justify-between border-b border-slate-200 px-3 py-2">
			<h2 class="text-sm font-semibold">Additional Costs</h2>
			<button type="button" id="add-extra-cost" class="rounded-lg border border-slate-300 px-3 py-1.5 text-sm hover:bg-slate-50">Add Cost</button>
		</div>
		<div id="po-extra-costs" class="space-y-3 p-3"></div>
	</div>

	<div>
		<label class="text-sm font-medium">Notes</label>
		<textarea name="notes" rows="3" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2">{{ old('notes') }}</textarea>
	</div>

	<div class="grid gap-4 md:grid-cols-2">
		<div>
			<label class="text-sm font-medium">Order discount (flat amount)</label>
			<input type="number" step="0.01" min="0" name="discount_amount" id="po-discount" value="{{ old('discount_amount', '0') }}" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2">
			@error('discount_amount')<p class="mt-1 text-xs text-rose-600">{{ $message }}</p>@enderror
		</div>
		<div>
			<label class="text-sm font-medium">Order tax (flat amount)</label>
			<input type="number" step="0.01" min="0" name="tax_amount" id="po-tax" value="{{ old('tax_amount', '0') }}" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2">
			@error('tax_amount')<p class="mt-1 text-xs text-rose-600">{{ $message }}</p>@enderror
		</div>
	</div>

	<div class="rounded-xl border border-slate-200">
		<div class="flex flex-wrap items-center justify-between gap-2 border-b border-slate-200 px-3 py-2">
			<h2 class="text-sm font-semibold">Product Rows</h2>
			<div class="flex flex-wrap gap-2">
				@can('create', \App\Models\Product::class)
					<button type="button" id="open-quick-product" class="rounded-lg border border-indigo-200 bg-indigo-50 px-3 py-1.5 text-sm font-medium text-indigo-800 hover:bg-indigo-100">New product</button>
				@endcan
				<button type="button" id="add-line" class="rounded-lg border border-slate-300 px-3 py-1.5 text-sm hover:bg-slate-50">Add Row</button>
			</div>
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
	</div>

	<div>
		<button class="rounded-lg bg-slate-900 px-4 py-2 text-white">Create PO</button>
	</div>
</form>

<template id="po-line-template">
	<div class="grid gap-3 rounded-lg border border-slate-200 p-3 md:grid-cols-12" data-line-row>
		<div class="md:col-span-5">
			<label class="text-xs font-medium uppercase tracking-wide text-slate-500">Product</label>
			<div class="relative mt-1" data-product-picker>
				<input type="text" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm" data-product-search placeholder="Search SKU or name" autocomplete="off" role="combobox" aria-expanded="false">
				<div class="absolute z-20 mt-1 hidden max-h-56 w-full overflow-y-auto rounded-lg border border-slate-200 bg-white shadow-lg" data-product-results></div>
				<select class="sr-only" data-key="product_id" data-product-select required tabindex="-1">
					<option value="">Select product</option>
				</select>
			</div>
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

@can('create', \App\Models\Product::class)
<div id="quick-product-modal" class="fixed inset-0 z-50 hidden" aria-hidden="true">
	<div class="absolute inset-0 bg-slate-900/40" data-close-quick-product></div>
	<div class="relative mx-auto mt-8 flex max-h-[calc(100vh-4rem)] w-full max-w-lg flex-col overflow-visible rounded-2xl bg-white shadow-xl ring-1 ring-slate-200" role="dialog" aria-modal="true" aria-labelledby="quick-product-title">
		<div class="flex items-center justify-between border-b border-slate-200 px-5 py-4">
			<h2 id="quick-product-title" class="text-base font-semibold text-slate-900">New product</h2>
			<button type="button" class="text-sm text-slate-500 hover:text-slate-800" data-close-quick-product>Close</button>
		</div>
		<form id="quick-product-form" class="overflow-y-auto px-5 py-4">
			<div id="quick-product-errors" class="mb-3 hidden rounded-lg border border-rose-200 bg-rose-50 px-3 py-2 text-sm text-rose-700"></div>
			<div class="grid gap-3 sm:grid-cols-2">
				<div class="sm:col-span-2">
					<label class="text-xs font-medium text-slate-600">Name</label>
					<input type="text" name="name" required class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
				</div>
				<div>
					<label class="text-xs font-medium text-slate-600">SKU</label>
					<input type="text" name="sku" required maxlength="60" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
				</div>
				<div>
					<label class="text-xs font-medium text-slate-600">Brand</label>
					<div class="mt-1">
						<x-searchable-select
							name="brand_id"
							:options="$brandOptions"
							selected=""
							placeholder="Select brand"
							:clearable="false"
						/>
					</div>
				</div>
				<div>
					<label class="text-xs font-medium text-slate-600">Category</label>
					<div class="mt-1">
						<x-searchable-select
							name="category_id"
							:options="$categoryOptions"
							selected=""
							placeholder="Select category"
							:clearable="false"
						/>
					</div>
				</div>
				<div>
					<label class="text-xs font-medium text-slate-600">Purchase price</label>
					<input type="number" name="default_purchase_price" step="0.01" min="0" required class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
				</div>
				<div>
					<label class="text-xs font-medium text-slate-600">Sale price</label>
					<input type="number" name="default_sale_price" step="0.01" min="0" required class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
				</div>
			</div>
			<p class="mt-3 text-xs text-slate-500">Creates an active serialized product and adds a new PO line with it selected.</p>
			<div class="mt-4 flex justify-end gap-2 border-t border-slate-100 pt-4">
				<button type="button" class="rounded-lg border border-slate-300 px-4 py-2 text-sm text-slate-700 hover:bg-slate-50" data-close-quick-product>Cancel</button>
				<button type="submit" id="quick-product-submit" class="rounded-lg bg-slate-900 px-4 py-2 text-sm font-medium text-white hover:bg-slate-800">Create &amp; add row</button>
			</div>
		</form>
	</div>
</div>
@endcan

<template id="po-extra-cost-template">
	<div class="grid gap-3 rounded-lg border border-slate-200 p-3 md:grid-cols-12" data-extra-cost-row>
		<div class="md:col-span-3">
			<label class="text-xs font-medium uppercase tracking-wide text-slate-500">Cost Head</label>
			<input type="text" maxlength="120" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2" data-extra-key="label" placeholder="Labour, Transport, etc.">
		</div>
		<div class="md:col-span-2">
			<label class="text-xs font-medium uppercase tracking-wide text-slate-500">Payment Date</label>
			<input type="date" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2" data-extra-key="paid_at">
		</div>
		<div class="md:col-span-2">
			<label class="text-xs font-medium uppercase tracking-wide text-slate-500">Amount</label>
			<input type="number" step="0.01" min="0" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2" data-extra-key="amount" value="0">
		</div>
		<div class="md:col-span-4">
			<label class="text-xs font-medium uppercase tracking-wide text-slate-500">Wallet</label>
			<select class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm" data-extra-key="wallet_id" data-cost-wallet-select>
				<option value="">Select wallet…</option>
				@foreach ($costWallets as $w)
					<option value="{{ $w->id }}">{{ $w->code }} — {{ $w->name }}</option>
				@endforeach
			</select>
		</div>
		<div class="md:col-span-1 md:flex md:items-end">
			<button type="button" class="w-full rounded-lg border border-rose-300 px-3 py-2 text-sm text-rose-700 hover:bg-rose-50" data-remove-extra-cost>Remove</button>
		</div>
	</div>
</template>

<script>
(() => {
	const poProductsCatalog = @json($productsCatalog);
	const quickProductUrl = @json(route('products.quick-store'));
	const csrfToken = @json(csrf_token());
	const linesContainer = document.getElementById('po-lines');
	const template = document.getElementById('po-line-template');
	const addLineButton = document.getElementById('add-line');
	const extraCostsContainer = document.getElementById('po-extra-costs');
	const extraCostTemplate = document.getElementById('po-extra-cost-template');
	const addExtraCostButton = document.getElementById('add-extra-cost');
	const summaryRows = document.querySelector('[data-summary-rows]');
	const summaryQty = document.querySelector('[data-summary-qty]');
	const summarySubtotal = document.querySelector('[data-summary-subtotal]');
	const summaryDiscount = document.querySelector('[data-summary-discount]');
	const summaryTax = document.querySelector('[data-summary-tax]');
	const summaryExtraCosts = document.querySelector('[data-summary-extra-costs]');
	const summaryGrand = document.querySelector('[data-summary-grand]');
	const discountInput = document.getElementById('po-discount');
	const taxInput = document.getElementById('po-tax');

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
		let extraCostTotal = 0;

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
		[...extraCostsContainer.querySelectorAll('[data-extra-cost-row]')].forEach((row) => {
			const amount = Math.max(0, Number.parseFloat(row.querySelector('[data-extra-key="amount"]')?.value || '0') || 0);
			extraCostTotal += amount;
		});

		const discount = Math.max(0, Number.parseFloat(discountInput?.value || '0') || 0);
		const tax = Math.max(0, Number.parseFloat(taxInput?.value || '0') || 0);
		const subRounded = Math.round(Math.max(0, subtotal) * 100) / 100;
		const extraRounded = Math.round(Math.max(0, extraCostTotal) * 100) / 100;
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

	function refreshExtraCostNames() {
		[...extraCostsContainer.querySelectorAll('[data-extra-cost-row]')].forEach((row, index) => {
			row.querySelectorAll('[data-extra-key]').forEach((input) => {
				input.name = `additional_costs[${index}][${input.dataset.extraKey}]`;
			});
		});
	}

	function rebuildProductSelect(select, keepValue = true) {
		const current = keepValue ? select.value : '';
		select.innerHTML = '<option value="">Select product</option>';
		poProductsCatalog.forEach((product) => {
			const option = document.createElement('option');
			option.value = String(product.id);
			option.textContent = product.label;
			option.dataset.defaultCost = String(product.default_purchase_price);
			option.dataset.search = product.search;
			select.appendChild(option);
		});
		if (current) {
			select.value = current;
		}
	}

	function registerProduct(product) {
		if (poProductsCatalog.some((entry) => Number(entry.id) === Number(product.id))) {
			return;
		}
		poProductsCatalog.push(product);
		poProductsCatalog.sort((a, b) => a.label.localeCompare(b.label));
		document.querySelectorAll('[data-product-select]').forEach((select) => rebuildProductSelect(select));
	}

	function setRowProduct(row, productId, unitCost = null) {
		const productSelect = row.querySelector('[data-product-select]');
		const searchInput = row.querySelector('[data-product-search]');
		const unitCostInput = row.querySelector('[data-key="unit_cost"]');
		if (!productSelect || !searchInput) {
			return;
		}

		const product = poProductsCatalog.find((entry) => Number(entry.id) === Number(productId));
		if (!product) {
			return;
		}

		productSelect.value = String(product.id);
		searchInput.value = product.label;
		if (unitCost !== null && unitCost !== '') {
			unitCostInput.value = unitCost;
		} else if (unitCostInput && !unitCostInput.value) {
			unitCostInput.value = String(product.default_purchase_price);
		}
		productSelect.dispatchEvent(new Event('change', { bubbles: true }));
	}

	function wireProductSearch(row) {
		const searchInput = row.querySelector('[data-product-search]');
		const productSelect = row.querySelector('[data-product-select]');
		const results = row.querySelector('[data-product-results]');
		const picker = row.querySelector('[data-product-picker]');
		if (!searchInput || !productSelect || !results || !picker) {
			return;
		}

		rebuildProductSelect(productSelect);

		const products = poProductsCatalog.map((product) => ({
			value: String(product.id),
			label: product.label,
			search: product.search,
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

		if (prefill?.qty_ordered) {
			row.querySelector('[data-key="qty_ordered"]').value = prefill.qty_ordered;
		}

		linesContainer.appendChild(fragment);
		const attachedRow = linesContainer.lastElementChild;
		wireProductSearch(attachedRow);

		if (prefill?.product_id) {
			setRowProduct(attachedRow, prefill.product_id, prefill.unit_cost ?? null);
		}

		refreshNames();
		refreshSummary();
	}

	function addExtraCost(prefill = null) {
		const fragment = extraCostTemplate.content.cloneNode(true);
		const row = fragment.querySelector('[data-extra-cost-row]');
		row.querySelector('[data-remove-extra-cost]').addEventListener('click', () => {
			row.remove();
			refreshExtraCostNames();
			refreshSummary();
		});
		if (prefill) {
			if (prefill.label) {
				row.querySelector('[data-extra-key="label"]').value = prefill.label;
			}
			if (prefill.paid_at) {
				row.querySelector('[data-extra-key="paid_at"]').value = prefill.paid_at;
			}
			if (prefill.amount !== undefined && prefill.amount !== null && prefill.amount !== '') {
				row.querySelector('[data-extra-key="amount"]').value = prefill.amount;
			}
			if (prefill.wallet_id) {
				row.querySelector('[data-cost-wallet-select]').value = String(prefill.wallet_id);
			}
		} else {
			row.querySelector('[data-extra-key="paid_at"]').value = new Date().toISOString().slice(0, 10);
		}
		extraCostsContainer.appendChild(fragment);
		refreshExtraCostNames();
		refreshSummary();
	}

	addLineButton.addEventListener('click', () => addLine());
	addExtraCostButton?.addEventListener('click', () => addExtraCost());
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
	extraCostsContainer.addEventListener('input', (event) => {
		if (!event.target.closest('[data-extra-key]')) {
			return;
		}
		refreshSummary();
	});
	extraCostsContainer.addEventListener('change', (event) => {
		if (!event.target.closest('[data-extra-key]')) {
			return;
		}
		refreshSummary();
	});

	const oldLines = @json(old('lines', []));
	const oldExtraCosts = @json(old('additional_costs', []));
	if (Array.isArray(oldLines) && oldLines.length) {
		oldLines.forEach((line) => addLine(line));
	} else {
		addLine();
	}
	if (Array.isArray(oldExtraCosts) && oldExtraCosts.length) {
		oldExtraCosts.forEach((cost) => addExtraCost(cost));
	}
	refreshSummary();

	const quickModal = document.getElementById('quick-product-modal');
	const quickForm = document.getElementById('quick-product-form');
	const openQuickBtn = document.getElementById('open-quick-product');

	function setQuickModalOpen(open) {
		if (!quickModal) {
			return;
		}
		quickModal.classList.toggle('hidden', !open);
		quickModal.setAttribute('aria-hidden', open ? 'false' : 'true');
		document.body.classList.toggle('overflow-hidden', open);
	}

	function resetQuickProductSearchableSelects() {
		if (!quickForm || typeof Alpine === 'undefined') {
			return;
		}
		quickForm.querySelectorAll('[x-data]').forEach((element) => {
			const state = Alpine.$data(element);
			if (!state || !Object.prototype.hasOwnProperty.call(state, 'selectedId')) {
				return;
			}
			state.selectedId = '';
			if (Object.prototype.hasOwnProperty.call(state, 'search')) {
				state.search = '';
			}
			if (Object.prototype.hasOwnProperty.call(state, 'open')) {
				state.open = false;
			}
		});
	}

	function resetQuickProductForm() {
		quickForm?.reset();
		resetQuickProductSearchableSelects();
	}

	function showQuickErrors(messages) {
		const box = document.getElementById('quick-product-errors');
		if (!box) {
			return;
		}
		if (!messages.length) {
			box.classList.add('hidden');
			box.innerHTML = '';
			return;
		}
		box.classList.remove('hidden');
		box.innerHTML = '<ul class="list-disc pl-5">' + messages.map((msg) => `<li>${msg}</li>`).join('') + '</ul>';
	}

	openQuickBtn?.addEventListener('click', () => {
		showQuickErrors([]);
		resetQuickProductForm();
		setQuickModalOpen(true);
		quickForm?.querySelector('input[name="name"]')?.focus();
	});

	quickModal?.querySelectorAll('[data-close-quick-product]').forEach((el) => {
		el.addEventListener('click', () => setQuickModalOpen(false));
	});

	quickForm?.addEventListener('submit', async (event) => {
		event.preventDefault();
		const submitBtn = document.getElementById('quick-product-submit');
		submitBtn.disabled = true;
		showQuickErrors([]);

		const formData = new FormData(quickForm);
		try {
			const response = await fetch(quickProductUrl, {
				method: 'POST',
				headers: {
					Accept: 'application/json',
					'X-Requested-With': 'XMLHttpRequest',
					'X-CSRF-TOKEN': csrfToken,
				},
				body: formData,
			});
			const payload = await response.json().catch(() => ({}));
			if (!response.ok) {
				const messages = payload.errors
					? Object.values(payload.errors).flat()
					: [payload.message || 'Could not create product.'];
				showQuickErrors(messages);
				return;
			}

			registerProduct(payload.product);
			addLine({
				product_id: String(payload.product.id),
				qty_ordered: '1',
				unit_cost: String(payload.product.default_purchase_price),
			});
			resetQuickProductForm();
			setQuickModalOpen(false);
		} catch (error) {
			showQuickErrors(['Network error while creating product.']);
		} finally {
			submitBtn.disabled = false;
		}
	});
})();
</script>
@endsection
