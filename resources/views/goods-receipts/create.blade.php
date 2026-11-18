@extends('layouts.app')
@section('title', 'Create Goods Receipt')
@section('page-title', 'Create Goods Receipt')
@section('content')
<form action="{{ route('goods-receipts.store') }}" method="post" class="space-y-4 rounded-2xl bg-white p-5 shadow-sm ring-1 ring-slate-200">
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

	<div class="grid gap-4 md:grid-cols-2 lg:grid-cols-4">
		<div>
			<label class="text-sm font-medium">PO (optional)</label>
			<input type="text" id="purchase-order-search" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm" placeholder="Search PO number or supplier">
			<select name="purchase_order_id" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2" id="purchase-order-select">
				<option value="">Standalone</option>
				@foreach ($purchaseOrders as $po)
					@php
						$pendingQty = max(((int) ($po->ordered_qty ?? 0)) - ((int) ($po->received_qty ?? 0)), 0);
					@endphp
					<option
						value="{{ $po->id }}"
						data-supplier-id="{{ $po->supplier_id }}"
						data-location-id="{{ $po->destination_location_id }}"
						data-search="{{ strtolower($po->po_number . ' ' . ($po->supplier?->name ?? '')) }}"
						@selected((string) old('purchase_order_id') === (string) $po->id)
					>
						{{ $po->po_number }} - {{ $po->supplier?->name }} (Pending: {{ $pendingQty }})
					</option>
				@endforeach
			</select>
		</div>

		<div>
			<label class="text-sm font-medium">Supplier</label>
			<select name="supplier_id" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2" id="supplier-select" required>
				<option value="">Select supplier</option>
				@foreach ($suppliers as $supplier)
					<option value="{{ $supplier->id }}" @selected((string) old('supplier_id') === (string) $supplier->id)>{{ $supplier->name }}</option>
				@endforeach
			</select>
		</div>

		<div>
			<label class="text-sm font-medium">Receiving Location</label>
			<select name="location_id" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2" id="location-select" required>
				<option value="">Select location</option>
				@foreach ($locations as $location)
					<option value="{{ $location->id }}" @selected((string) old('location_id') === (string) $location->id)>{{ $location->name }}</option>
				@endforeach
			</select>
		</div>

		<div>
			<label class="text-sm font-medium">Received At</label>
			<input type="date" name="received_at" value="{{ old('received_at', now()->toDateString()) }}" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2" required>
		</div>

		<div class="lg:col-span-2">
			<label class="text-sm font-medium">Delivery Note Ref</label>
			<input type="text" name="delivery_note_ref" value="{{ old('delivery_note_ref') }}" maxlength="80" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2" placeholder="Optional">
		</div>
	</div>

	<div class="rounded-xl border border-slate-200">
		<div class="flex items-center justify-between border-b border-slate-200 px-3 py-2">
			<h2 class="text-sm font-semibold">Product Rows</h2>
			<button type="button" id="add-grn-line" class="rounded-lg border border-slate-300 px-3 py-1.5 text-sm hover:bg-slate-50">Add Row</button>
		</div>
		<p class="px-3 pt-2 text-xs text-slate-500">Serial numbers are required only when the selected product is serialized.</p>
		<div id="grn-lines" class="space-y-3 p-3"></div>
		<div class="flex items-center justify-end gap-3 border-t border-slate-200 bg-slate-50 px-3 py-2">
			<span class="text-xs font-medium uppercase tracking-wide text-slate-500">Total quantity</span>
			<span id="grn-total-qty" class="min-w-[3rem] text-right text-sm font-semibold tabular-nums text-slate-900">0</span>
		</div>
	</div>

	<div>
		<button class="rounded-lg bg-slate-900 px-4 py-2 text-white">Create Draft GRN</button>
	</div>
</form>

<template id="grn-line-template">
	<div class="space-y-2 rounded-lg border border-slate-200 p-3" data-line-row>
		<div class="grid gap-3 md:grid-cols-12">
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
				<input type="number" min="1" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2" data-key="qty_received" required>
				<p class="mt-1 text-xs text-slate-500" data-qty-pending-hint></p>
			</div>
			<div class="md:col-span-2">
				<label class="text-xs font-medium uppercase tracking-wide text-slate-500">Serial Mode</label>
				<select class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm" data-key="serial_mode" data-serial-mode>
					<option value="manual">Manufacturer serials</option>
					<option value="auto">Auto generate internal</option>
				</select>
			</div>
			<div class="md:col-span-2">
				<label class="text-xs font-medium uppercase tracking-wide text-slate-500">Unit Cost (optional)</label>
				<input type="number" step="0.01" min="0" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2" data-key="unit_cost" placeholder="Auto from product">
			</div>
			<div class="md:col-span-2 md:flex md:items-end">
				<button type="button" class="w-full rounded-lg border border-rose-300 px-3 py-2 text-sm text-rose-700 hover:bg-rose-50" data-remove-line>Remove</button>
			</div>
		</div>
		<div data-serial-section>
			<label class="text-xs font-medium uppercase tracking-wide text-slate-500">Serial Numbers</label>
            <p class="mt-1 text-xs text-slate-500" data-serial-hint></p>
			<div class="mt-1 space-y-2" data-serial-list></div>
		</div>
	</div>
</template>

<script>
(() => {
	const form = document.querySelector('form[action="{{ route('goods-receipts.store') }}"]');
	const linesContainer = document.getElementById('grn-lines');
	const template = document.getElementById('grn-line-template');
	const addLineButton = document.getElementById('add-grn-line');

	const purchaseOrderSelect = document.getElementById('purchase-order-select');
	const purchaseOrderSearch = document.getElementById('purchase-order-search');
	const purchaseOrderPendingLines = @json($purchaseOrderPendingLines ?? []);
	const allProducts = @json($productsCatalog ?? []);
	const supplierSelect = document.getElementById('supplier-select');
	const locationSelect = document.getElementById('location-select');
	const totalQtyEl = document.getElementById('grn-total-qty');

	function updateTotalQty() {
		const total = [...linesContainer.querySelectorAll('[data-key="qty_received"]')].reduce((sum, input) => {
			const qty = parseInt(input.value, 10);
			return sum + (Number.isFinite(qty) && qty > 0 ? qty : 0);
		}, 0);
		if (totalQtyEl) {
			totalQtyEl.textContent = String(total);
		}
	}

	function pendingQtyMapForPurchaseOrder() {
		const poId = purchaseOrderSelect.value;
		if (!poId) {
			return new Map();
		}

		return new Map(
			mergePendingLinesByProductId(purchaseOrderPendingLines[String(poId)] || []).map((line) => [
				String(line.product_id),
				Number(line.pending_qty) || 0,
			])
		);
	}

	function allocatedQtyByProduct(exceptRow = null) {
		const map = new Map();
		[...linesContainer.querySelectorAll('[data-line-row]')].forEach((row) => {
			if (row === exceptRow) {
				return;
			}
			const productId = row.querySelector('[data-product-select]')?.value;
			const qty = parseInt(row.querySelector('[data-key="qty_received"]')?.value || '0', 10);
			if (!productId || !Number.isFinite(qty) || qty < 1) {
				return;
			}
			map.set(productId, (map.get(productId) || 0) + qty);
		});

		return map;
	}

	function maxQtyForRow(row) {
		if (!purchaseOrderSelect.value) {
			return null;
		}
		const productId = row.querySelector('[data-product-select]')?.value;
		if (!productId) {
			return null;
		}
		const pending = pendingQtyMapForPurchaseOrder().get(String(productId)) || 0;
		const allocatedElsewhere = allocatedQtyByProduct(row).get(String(productId)) || 0;

		return Math.max(0, pending - allocatedElsewhere);
	}

	function applyQtyLimits(row) {
		const qtyInput = row.querySelector('[data-key="qty_received"]');
		const hint = row.querySelector('[data-qty-pending-hint]');
		if (!qtyInput) {
			return;
		}

		const maxQty = maxQtyForRow(row);
		if (maxQty === null) {
			qtyInput.removeAttribute('max');
			if (hint) {
				hint.textContent = '';
			}
			return;
		}

		if (maxQty <= 0) {
			qtyInput.max = '1';
			if (hint) {
				hint.textContent = 'No pending quantity on PO';
			}
			return;
		}

		qtyInput.max = String(maxQty);
		if (hint) {
			hint.textContent = `Pending on PO: ${maxQty}`;
		}

		const current = parseInt(qtyInput.value, 10);
		if (Number.isFinite(current) && current > maxQty) {
			qtyInput.value = String(maxQty);
			renderSerialInputs(row, maxQty);
		}
	}

	function applyQtyLimitsToAllRows() {
		[...linesContainer.querySelectorAll('[data-line-row]')].forEach((row) => applyQtyLimits(row));
	}

	function refreshNames() {
		[...linesContainer.querySelectorAll('[data-line-row]')].forEach((row, index) => {
			row.querySelectorAll('[data-key]').forEach((input) => {
				input.name = `lines[${index}][${input.dataset.key}]`;
			});

			row.querySelectorAll('[data-serial-input]').forEach((input, serialIndex) => {
				input.name = `lines[${index}][serial_numbers][${serialIndex}]`;
			});
		});
	}

	function mergePendingLinesByProductId(lines) {
		const map = new Map();
		lines.forEach((line) => {
			const id = String(line.product_id);
			if (!map.has(id)) {
				map.set(id, { ...line });
				return;
			}
			const existing = map.get(id);
			existing.pending_qty = (Number(existing.pending_qty) || 0) + (Number(line.pending_qty) || 0);
		});
		return [...map.values()];
	}

	function baseProductCatalogEntries() {
		const poId = purchaseOrderSelect.value;
		if (!poId) {
			return allProducts.map((product) => ({
				value: String(product.id),
				label: product.label,
				search: product.search || product.label.toLowerCase(),
				defaultCost: Number(product.default_cost) || 0,
				isSerialized: Boolean(product.is_serialized),
			}));
		}
		const rawLines = purchaseOrderPendingLines[String(poId)] || [];
		const lines = mergePendingLinesByProductId(rawLines);
		return lines.map((line) => ({
			value: String(line.product_id),
			label: line.product_label || `Product #${line.product_id}`,
			search: line.search || (line.product_label || '').toLowerCase(),
			defaultCost: Number(line.unit_cost) || 0,
			isSerialized: Boolean(line.is_serialized),
		}));
	}

	function otherRowsSelectedProductIds(exceptRow) {
		return [...linesContainer.querySelectorAll('[data-line-row]')]
			.filter((row) => row !== exceptRow)
			.map((row) => row.querySelector('[data-product-select]')?.value)
			.filter((value) => value !== '' && value !== undefined);
	}

	function fillProductSelectFromCatalog(row, preserveValue = null) {
		const productSelect = row.querySelector('[data-product-select]');
		if (!productSelect) {
			return;
		}
		const previous = preserveValue !== null ? preserveValue : productSelect.value;
		const taken = new Set(otherRowsSelectedProductIds(row));
		const entries = baseProductCatalogEntries().filter((entry) => !taken.has(entry.value) || entry.value === previous);

		productSelect.innerHTML = '';
		const placeholder = document.createElement('option');
		placeholder.value = '';
		placeholder.textContent = 'Select product';
		productSelect.appendChild(placeholder);

		entries.forEach((entry) => {
			const option = document.createElement('option');
			option.value = entry.value;
			option.textContent = entry.label;
			option.dataset.search = entry.search;
			option.dataset.defaultCost = String(entry.defaultCost);
			option.dataset.isSerialized = entry.isSerialized ? '1' : '0';
			productSelect.appendChild(option);
		});

		if (previous && [...productSelect.options].some((option) => option.value === previous)) {
			productSelect.value = previous;
		} else {
			productSelect.value = '';
		}

		const searchInput = row.querySelector('[data-product-search]');
		const selectedOption = productSelect.options[productSelect.selectedIndex];
		if (searchInput) {
			searchInput.value = selectedOption && selectedOption.value ? selectedOption.text : '';
		}
	}

	function applyProductCatalogToAllRows() {
		[...linesContainer.querySelectorAll('[data-line-row]')].forEach((row) => {
			const productSelect = row.querySelector('[data-product-select]');
			fillProductSelectFromCatalog(row, productSelect ? productSelect.value : '');
		});
	}

	function wireProductPicker(row) {
		const searchInput = row.querySelector('[data-product-search]');
		const productSelect = row.querySelector('[data-product-select]');
		const results = row.querySelector('[data-product-results]');
		const picker = row.querySelector('[data-product-picker]');
		if (!searchInput || !productSelect || !results || !picker) {
			return;
		}

		let highlightedIndex = -1;

		function closeResults() {
			results.classList.add('hidden');
			searchInput.setAttribute('aria-expanded', 'false');
			highlightedIndex = -1;
		}

		function productsFromSelect() {
			return [...productSelect.options]
				.filter((option) => option.value !== '')
				.map((option) => ({
					value: option.value,
					label: option.text,
					search: option.dataset.search || option.text.toLowerCase(),
				}));
		}

		function renderResults(keyword) {
			const normalizedKeyword = keyword.trim().toLowerCase();
			const products = productsFromSelect();
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

	function isSerializedSelected(row) {
		const productSelect = row.querySelector('[data-product-select]');
		const selected = productSelect?.options?.[productSelect.selectedIndex];
		return selected?.dataset?.isSerialized === '1';
	}

	function renderSerialInputs(row, count, values = null) {
		const serialSection = row.querySelector('[data-serial-section]');
		const serialHint = row.querySelector('[data-serial-hint]');
		const serialList = row.querySelector('[data-serial-list]');
		const existingValues = values ?? [...serialList.querySelectorAll('[data-serial-input]')].map((input) => input.value);
		const serialized = isSerializedSelected(row);
		const mode = row.querySelector('[data-serial-mode]')?.value || 'manual';
		const serialCount = Number.isFinite(count) && count > 0 ? count : 0;

		serialSection.classList.toggle('opacity-70', !serialized);
		serialHint.textContent = !serialized
			? 'Serial numbers are not required for this product.'
			: (mode === 'auto' ? `Internal asset IDs will be auto generated (${serialCount}).` : 'Enter one manufacturer serial per quantity.');
		serialList.className = serialized && mode === 'manual' ? 'mt-1 grid grid-cols-1 gap-2 md:grid-cols-4' : 'mt-1 space-y-2';
		serialList.innerHTML = '';
		if (!serialized || mode === 'auto') {
			refreshNames();
			return;
		}
		for (let i = 0; i < serialCount; i += 1) {
			const serialInput = document.createElement('input');
			serialInput.type = 'text';
			serialInput.className = 'w-full rounded-lg border border-slate-300 px-3 py-2 font-mono text-sm';
			serialInput.placeholder = `Serial ${i + 1}`;
			serialInput.required = true;
			serialInput.dataset.serialInput = '1';
			serialInput.value = existingValues[i] || '';
			serialList.appendChild(serialInput);
		}

		refreshNames();
	}

	function addLine(prefill = null) {
		const fragment = template.content.cloneNode(true);
		const row = fragment.querySelector('[data-line-row]');

		row.querySelector('[data-remove-line]').addEventListener('click', () => {
			row.remove();
			refreshNames();
			applyProductCatalogToAllRows();
			updateTotalQty();
			applyQtyLimitsToAllRows();
		});

		const qtyInput = row.querySelector('[data-key="qty_received"]');
		const unitCostInput = row.querySelector('[data-key="unit_cost"]');
		const modeInput = row.querySelector('[data-serial-mode]');

		linesContainer.appendChild(fragment);

		fillProductSelectFromCatalog(row);
		wireProductPicker(row);

		const productSelect = row.querySelector('[data-product-select]');

		productSelect.addEventListener('change', () => {
			if (!unitCostInput.value) {
				const selected = productSelect.options[productSelect.selectedIndex];
				if (selected && selected.dataset.defaultCost !== undefined && selected.dataset.defaultCost !== '') {
					unitCostInput.value = selected.dataset.defaultCost;
				}
			}
			const desiredCount = parseInt(qtyInput.value, 10);
			renderSerialInputs(row, Number.isNaN(desiredCount) ? 0 : desiredCount);
			applyProductCatalogToAllRows();
			applyQtyLimits(row);
			applyQtyLimitsToAllRows();
		});

		qtyInput.addEventListener('input', () => {
			const desiredCount = parseInt(qtyInput.value, 10);
			renderSerialInputs(row, Number.isNaN(desiredCount) ? 0 : desiredCount);
			updateTotalQty();
			applyQtyLimits(row);
			applyQtyLimitsToAllRows();
		});
		modeInput.addEventListener('change', () => {
			const desiredCount = parseInt(qtyInput.value, 10);
			renderSerialInputs(row, Number.isNaN(desiredCount) ? 0 : desiredCount);
		});

		if (prefill) {
			if (prefill.product_id) {
				productSelect.value = String(prefill.product_id);
				const searchInput = row.querySelector('[data-product-search]');
				const selectedOption = productSelect.options[productSelect.selectedIndex];
				if (searchInput && selectedOption && selectedOption.value) {
					searchInput.value = selectedOption.text;
				}
			}
			if (prefill.qty_received) {
				qtyInput.value = prefill.qty_received;
			} else {
				qtyInput.value = 1;
			}
			if (prefill.unit_cost) {
				unitCostInput.value = prefill.unit_cost;
			}
			modeInput.value = prefill.serial_mode || (Array.isArray(prefill.serial_numbers) && prefill.serial_numbers.length ? 'manual' : 'auto');

			let serialValues = [];
			if (Array.isArray(prefill.serial_numbers)) {
				serialValues = prefill.serial_numbers;
			} else if (typeof prefill.serial_numbers_text === 'string') {
				serialValues = prefill.serial_numbers_text.split(/\r\n|\r|\n/).map((value) => value.trim()).filter((value) => value !== '');
			}

			const serialCount = parseInt(qtyInput.value, 10) || serialValues.length || 0;
			renderSerialInputs(row, serialCount, serialValues);
		} else {
			qtyInput.value = 1;
			modeInput.value = 'auto';
			renderSerialInputs(row, 1);
		}

		refreshNames();
		applyProductCatalogToAllRows();
		updateTotalQty();
		applyQtyLimits(row);
		applyQtyLimitsToAllRows();
	}

	addLineButton.addEventListener('click', () => addLine());

	function filterPurchaseOrders() {
		const keyword = purchaseOrderSearch.value.trim().toLowerCase();
		[...purchaseOrderSelect.options].forEach((option, index) => {
			if (index === 0) {
				option.hidden = false;
				return;
			}

			const haystack = option.dataset.search || option.text.toLowerCase();
			option.hidden = keyword !== '' && !haystack.includes(keyword);
		});
	}

	purchaseOrderSearch.addEventListener('input', filterPurchaseOrders);

	function setLinesFromPendingPurchaseOrder(poId) {
		const lines = purchaseOrderPendingLines[String(poId)] || [];
		if (!lines.length) {
			return;
		}

		linesContainer.innerHTML = '';
		lines.forEach((line) => {
			addLine({
				product_id: line.product_id,
				qty_received: line.pending_qty,
				unit_cost: line.unit_cost,
				serial_mode: line.is_serialized ? 'auto' : 'manual',
				serial_numbers: line.is_serialized ? Array.from({ length: line.pending_qty }, () => '') : [],
			});
		});
		updateTotalQty();
		applyQtyLimitsToAllRows();
	}

	purchaseOrderSelect.addEventListener('change', () => {
		const selected = purchaseOrderSelect.options[purchaseOrderSelect.selectedIndex];
		if (!selected || !selected.value) {
			applyProductCatalogToAllRows();
			applyQtyLimitsToAllRows();
			return;
		}

		if (selected.dataset.supplierId) {
			supplierSelect.value = selected.dataset.supplierId;
		}

		if (selected.dataset.locationId) {
			locationSelect.value = selected.dataset.locationId;
		}

		setLinesFromPendingPurchaseOrder(selected.value);
	});

	const oldLines = @json(old('lines', []));
	if (Array.isArray(oldLines) && oldLines.length) {
		oldLines.forEach((line) => addLine(line));
	} else if (purchaseOrderSelect.value && purchaseOrderPendingLines[String(purchaseOrderSelect.value)]?.length) {
		setLinesFromPendingPurchaseOrder(purchaseOrderSelect.value);
	} else {
		addLine();
	}

	filterPurchaseOrders();

	form.addEventListener('submit', (event) => {
		const serials = [];
		let invalid = false;

		if (purchaseOrderSelect.value) {
			const pendingMap = pendingQtyMapForPurchaseOrder();
			const incomingMap = new Map();
			[...linesContainer.querySelectorAll('[data-line-row]')].forEach((row) => {
				const productId = row.querySelector('[data-product-select]')?.value;
				const qty = parseInt(row.querySelector('[data-key="qty_received"]')?.value || '0', 10);
				if (!productId || !Number.isFinite(qty) || qty < 1) {
					return;
				}
				incomingMap.set(productId, (incomingMap.get(productId) || 0) + qty);
			});

			for (const [productId, incomingQty] of incomingMap.entries()) {
				const pendingQty = pendingMap.get(String(productId)) || 0;
				if (incomingQty > pendingQty) {
					invalid = true;
					break;
				}
			}
		}

		[...linesContainer.querySelectorAll('[data-line-row]')].forEach((row) => {
			const serialized = isSerializedSelected(row);
			const mode = row.querySelector('[data-serial-mode]')?.value || 'manual';
			const qty = parseInt(row.querySelector('[data-key="qty_received"]').value || '0', 10);
			const inputs = [...row.querySelectorAll('[data-serial-input]')];

			if (qty < 1) {
				invalid = true;
				return;
			}
			if (!serialized || mode === 'auto') {
				return;
			}
			if (inputs.length !== qty) {
				invalid = true;
				return;
			}

			for (const input of inputs) {
				const value = input.value.trim();
				if (value === '') {
					invalid = true;
					break;
				}

				serials.push(value.toLowerCase());
			}
		});

		if (!invalid && new Set(serials).size !== serials.length) {
			invalid = true;
		}

		if (invalid) {
			event.preventDefault();
			if (window.appAlert) {
				window.appAlert('Please provide valid quantities and serial numbers for serialized products (one per quantity, no duplicates). Quantity cannot exceed pending PO amount.', {
					icon: 'error',
					title: 'Validation failed',
				});
			} else {
				alert('Please provide valid quantities and serial numbers for serialized products (one per quantity, no duplicates). Quantity cannot exceed pending PO amount.');
			}
		}
	});
})();
</script>
@endsection
