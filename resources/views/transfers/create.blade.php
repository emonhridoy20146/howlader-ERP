@extends('layouts.app')
@php
	$isEdit = isset($transfer) && $transfer;
	$formAction = $isEdit ? route('transfers.update', $transfer) : route('transfers.store');
	$formTitle = $isEdit ? 'Edit Transfer' : 'Create Transfer';
	$selectedSourceLocationId = (string) old('source_location_id', $isEdit ? $transfer->source_location_id : '');
	$selectedDestinationLocationId = (string) old('destination_location_id', $isEdit ? $transfer->destination_location_id : '');
	$selectedTransferDate = old('transfer_date', $isEdit && $transfer->requested_at ? $transfer->requested_at->toDateString() : now()->toDateString());
	$selectedReason = old('reason', $isEdit ? $transfer->reason : '');
	$bootSelectedSerialIds = old('serial_ids', $selectedSerialIds ?? []);
@endphp
@section('title', $formTitle)
@section('page-title', $formTitle)
@section('content')
	<form action="{{ $formAction }}" method="post" class="space-y-4 rounded-2xl bg-white p-5 shadow-sm ring-1 ring-slate-200" x-data="transferCreate(@js([
		'sourceLocationId' => $selectedSourceLocationId,
		'destinationLocationId' => $selectedDestinationLocationId,
		'selectedSerialIds' => array_values(array_map(static fn ($id) => (string) $id, is_array($bootSelectedSerialIds) ? $bootSelectedSerialIds : [])),
		'editingTransferId' => $isEdit ? (int) $transfer->id : null,
	]))" @destroy="destroy()">
		@csrf
		@if ($isEdit)
			@method('PUT')
		@endif

		@if ($errors->any())
			<div class="rounded-lg border border-rose-200 bg-rose-50 px-3 py-2 text-sm text-rose-700">
				<ul class="list-disc pl-5">
					@foreach ($errors->all() as $error)
						<li>{{ $error }}</li>
					@endforeach
				</ul>
			</div>
		@endif

		<div class="grid gap-4 md:grid-cols-2">
			<div>
				<label class="text-sm font-medium">Transfer Date</label>
				<input type="date" name="transfer_date" value="{{ $selectedTransferDate }}" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2" required>
			</div>
			<div></div>
			<div>
				<label class="text-sm font-medium">Source Warehouse</label>
				<select name="source_location_id" x-model="sourceLocationId" @change="refreshSerials()" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2" required>
					<option value="">Select source</option>
					@foreach ($locations as $location)
						<option value="{{ $location->id }}" @selected($selectedSourceLocationId === (string) $location->id)>{{ $location->name }}</option>
					@endforeach
				</select>
			</div>
			<div>
				<label class="text-sm font-medium">Destination Warehouse</label>
				<select name="destination_location_id" x-model="destinationLocationId" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2" required>
					<option value="">Select destination</option>
					@foreach ($locations as $location)
						<option value="{{ $location->id }}" @selected($selectedDestinationLocationId === (string) $location->id)>{{ $location->name }}</option>
					@endforeach
				</select>
			</div>
		</div>

		<div>
			<label class="text-sm font-medium">Reason</label>
			<textarea name="reason" rows="2" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2" placeholder="Why this transfer is needed">{{ $selectedReason }}</textarea>
		</div>

		<div class="rounded-xl border border-slate-200">
			<div class="border-b border-slate-200 px-3 py-2">
				<h2 class="text-sm font-semibold">Select Specific Serials</h2>
				<p class="mt-1 text-xs text-slate-500">Only available in-stock serials are shown. Serials already allocated to active transfers are excluded.</p>
			</div>

			<div class="border-b border-slate-200 bg-slate-50/80 px-3 py-3 sm:flex sm:items-center sm:gap-3">
				<div class="min-w-0 flex-1">
					<label for="transfer-barcode-scan-input" class="block text-[11px] font-semibold uppercase tracking-wide text-slate-600">Barcode scanner</label>
					<p class="mt-0.5 text-[11px] text-slate-500">Scan a unit serial or label code at the source warehouse. Each scan adds the unit to the selection. Press <kbd class="rounded border border-slate-300 bg-white px-0.5">F2</kbd> to focus.</p>
				</div>
				<div class="relative mt-2 min-w-[12rem] max-w-xl flex-1 sm:mt-0">
					<input
						type="text"
						id="transfer-barcode-scan-input"
						x-ref="scanInput"
						inputmode="none"
						autocomplete="off"
						data-scan-gateway
						class="w-full rounded-lg border border-emerald-200 bg-white px-3 py-2 text-sm shadow-sm outline-none ring-emerald-500/15 transition focus:border-emerald-600 focus:ring-2 focus:ring-emerald-500/30"
						placeholder="Scan serial, then Enter…"
						title="Resolves to an available in-stock unit at the source warehouse."
						@keydown.enter.prevent="submitScanFromInput()"
					>
				</div>
			</div>

			<div
				x-show="scanNotice"
				x-cloak
				class="border-b px-3 py-2 text-xs"
				:class="scanNoticeType === 'error' ? 'border-rose-200 bg-rose-50 text-rose-700' : (scanNoticeType === 'warning' ? 'border-amber-200 bg-amber-50 text-amber-800' : 'border-emerald-200 bg-emerald-50 text-emerald-700')"
				x-text="scanNotice"
			></div>

			<div class="grid gap-3 p-3 md:grid-cols-3">
				<div>
					<label class="text-xs font-medium uppercase tracking-wide text-slate-500">Search</label>
					<input type="text" x-model="searchQuery" @input.debounce.350ms="refreshSerials()" placeholder="Serial, SKU, or product" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2">
				</div>
				<div>
					<label class="text-xs font-medium uppercase tracking-wide text-slate-500">Product Filter</label>
					<select x-model="productFilter" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2">
						<option value="">All products</option>
						<template x-for="product in productOptions" :key="product.id">
							<option :value="String(product.id)" x-text="product.label"></option>
						</template>
					</select>
				</div>
				<div class="flex items-end">
					<div class="flex flex-wrap gap-2">
						<button type="button" @click="groupedView = !groupedView" class="rounded-lg border border-slate-300 px-3 py-2 text-sm hover:bg-slate-50" x-text="groupedView ? 'Flat View' : 'Grouped View'"></button>
						<button type="button" @click="selectAllVisible()" class="rounded-lg border border-slate-300 px-3 py-2 text-sm hover:bg-slate-50">Select Visible</button>
						<button type="button" @click="clearSelection()" class="rounded-lg border border-slate-300 px-3 py-2 text-sm hover:bg-slate-50">Clear</button>
						<button type="button" @click="refreshSerials()" class="rounded-lg border border-slate-300 px-3 py-2 text-sm hover:bg-slate-50">Refresh</button>
					</div>
				</div>
			</div>

			<div class="max-h-96 overflow-auto border-t border-slate-200">
				<template x-if="loading">
					<div class="px-3 py-6 text-center text-sm text-slate-500">Loading serials...</div>
				</template>
				<template x-if="!loading && filteredSerials.length === 0">
					<div class="px-3 py-6 text-center text-sm text-slate-500">No serials available for this source warehouse.</div>
				</template>

				<template x-if="!loading && filteredSerials.length > 0 && groupedView">
					<div class="space-y-2 p-2">
						<template x-for="group in groupedSerials" :key="`group-${group.key}`">
							<div class="overflow-hidden rounded-lg border border-slate-200">
								<button type="button" class="flex w-full items-center justify-between bg-slate-50 px-3 py-2 text-left" @click="toggleGroup(group.key)">
									<span class="text-sm font-medium text-slate-700" x-text="group.label"></span>
									<span class="text-xs text-slate-500" x-text="`${group.rows.length} serial(s)`"></span>
								</button>
								<div x-show="isGroupOpen(group.key)">
									<div class="grid gap-2 p-2 sm:grid-cols-2 lg:grid-cols-3">
										<template x-for="row in group.rows" :key="`serial-${row.id}`">
											<label class="flex cursor-pointer items-center gap-2 rounded-md border border-slate-200 bg-white px-2 py-1.5 hover:bg-slate-50">
												<input type="checkbox" :value="row.id" x-model="selectedSerialIds" class="rounded border-slate-300">
												<span class="font-mono text-xs text-slate-700" x-text="row.serial_display || row.serial_number"></span>
											</label>
										</template>
									</div>
								</div>
							</div>
						</template>
					</div>
				</template>

				<template x-if="!loading && filteredSerials.length > 0 && !groupedView">
					<table class="min-w-full text-sm whitespace-nowrap">
						<thead class="sticky top-0 bg-slate-50 text-left text-slate-600">
							<tr>
								<th class="px-3 py-2">Pick</th>
								<th class="px-3 py-2">Serial</th>
								<th class="px-3 py-2">Product</th>
							</tr>
						</thead>
						<tbody>
							<template x-for="row in filteredSerials" :key="`flat-${row.id}`">
								<tr class="border-t border-slate-100">
									<td class="px-3 py-2">
										<input type="checkbox" :value="row.id" x-model="selectedSerialIds" class="rounded border-slate-300">
									</td>
									<td class="px-3 py-2 font-mono text-xs" x-text="row.serial_display || row.serial_number"></td>
									<td class="px-3 py-2" x-text="row.product_label"></td>
								</tr>
							</template>
						</tbody>
					</table>
				</template>
			</div>

			<div class="grid gap-2 border-t border-slate-200 bg-slate-50 px-3 py-2 text-xs text-slate-600 md:grid-cols-2">
				<span x-text="`${selectedSerialIds.length} serial(s) selected`"></span>
				<span class="text-right" x-text="`${filteredSerials.length} visible of ${serials.length} loaded`"></span>
			</div>

			<div class="border-t border-slate-200 px-3 py-2">
				<p class="text-xs font-medium uppercase tracking-wide text-slate-500">Selected Serials</p>
				<div class="mt-2 flex max-h-24 flex-wrap gap-1 overflow-auto">
					<template x-if="selectedRows.length === 0">
						<span class="text-xs text-slate-400">No serial selected yet.</span>
					</template>
					<template x-for="row in selectedRows" :key="`selected-${row.id}`">
						<span class="inline-flex items-center rounded-full bg-slate-100 px-2 py-1 font-mono text-[11px] text-slate-700" x-text="row.serial_display || row.serial_number"></span>
					</template>
				</div>
			</div>

			<template x-for="id in selectedSerialIds" :key="`hidden-serial-${id}`">
				<input type="hidden" name="serial_ids[]" :value="id">
			</template>
		</div>

		<div>
			<button class="rounded-lg bg-slate-900 px-4 py-2 text-white">{{ $isEdit ? 'Update Transfer' : 'Create Transfer' }}</button>
		</div>
	</form>

	<script>
	function transferCreate(boot = {}) {
		return {
			sourceLocationId: String(boot.sourceLocationId || ''),
			destinationLocationId: String(boot.destinationLocationId || ''),
			searchQuery: '',
			loading: false,
			serials: [],
			selectedSerialIds: Array.isArray(boot.selectedSerialIds) ? boot.selectedSerialIds.map((id) => String(id)) : [],
			editingTransferId: boot.editingTransferId ? Number(boot.editingTransferId) : null,
			productFilter: '',
			groupedView: true,
			expandedGroups: {},
			selectedSerialLookup: {},
			lastLoadedSourceLocationId: String(boot.sourceLocationId || ''),
			scanNotice: '',
			scanNoticeType: 'success',

			init() {
				if (this.sourceLocationId) {
					this.refreshSerials();
				}

				this.barcodeScanHandler = (event) => {
					const code = String(event?.detail?.code || '').trim();
					if (!code) {
						return;
					}
					void this.handleBarcodeScan(code);
				};
				window.addEventListener('barcode-scanned', this.barcodeScanHandler);

				this.f2FocusHandler = (event) => {
					if (event.key !== 'F2' || event.repeat) {
						return;
					}
					event.preventDefault();
					this.$refs.scanInput?.focus({ preventScroll: true });
				};
				document.addEventListener('keydown', this.f2FocusHandler);
			},

			destroy() {
				if (this.barcodeScanHandler) {
					window.removeEventListener('barcode-scanned', this.barcodeScanHandler);
				}
				if (this.f2FocusHandler) {
					document.removeEventListener('keydown', this.f2FocusHandler);
				}
			},

			submitScanFromInput() {
				const input = this.$refs.scanInput;
				if (!input) {
					return;
				}
				const code = String(input.value || '').trim();
				input.value = '';
				if (!code) {
					return;
				}
				void this.handleBarcodeScan(code);
			},

			setScanNotice(message, type = 'success') {
				this.scanNotice = message;
				this.scanNoticeType = type;
			},

			selectSerialFromScan(row) {
				const id = String(row.id);
				if (this.selectedSerialIds.map(String).includes(id)) {
					this.setScanNotice(`Already selected: ${row.serial_display || row.serial_number}`, 'warning');
					return false;
				}

				this.selectedSerialLookup[id] = row;
				this.selectedSerialIds = [...this.selectedSerialIds.map(String), id];
				this.expandedGroups[String(row.product_id)] = true;

				if (!this.serials.some((serial) => String(serial.id) === id)) {
					this.serials = [...this.serials, row];
				}

				this.setScanNotice(`Added: ${row.serial_display || row.serial_number}`, 'success');
				return true;
			},

			async handleBarcodeScan(rawCode) {
				const code = String(rawCode || '').trim();
				if (!code) {
					return;
				}

				if (!this.sourceLocationId) {
					this.setScanNotice('Choose a source warehouse before scanning.', 'error');
					return;
				}

				const params = new URLSearchParams({
					source_location_id: this.sourceLocationId,
					code,
				});
				if (this.editingTransferId) {
					params.set('transfer_id', String(this.editingTransferId));
				}

				try {
					const response = await fetch(`{{ route('transfers.resolve-serial-scan') }}?${params.toString()}`, {
						headers: {
							'X-Requested-With': 'XMLHttpRequest',
						},
					});
					const payload = await response.json();

					if (payload.error) {
						this.setScanNotice(payload.error, 'error');
						this.$refs.scanInput?.focus({ preventScroll: true });
						return;
					}

					if (!payload.data) {
						this.setScanNotice('No serial matched this scan.', 'error');
						this.$refs.scanInput?.focus({ preventScroll: true });
						return;
					}

					this.selectSerialFromScan(payload.data);
					this.$refs.scanInput?.focus({ preventScroll: true });
				} catch (error) {
					this.setScanNotice('Unable to resolve this scan. Try again.', 'error');
				}
			},

			get productOptions() {
				const map = {};
				this.serials.forEach((row) => {
					if (!map[row.product_id]) {
						map[row.product_id] = {
							id: row.product_id,
							label: row.product_label,
						};
					}
				});

				return Object.values(map).sort((a, b) => a.label.localeCompare(b.label));
			},

			get filteredSerials() {
				if (!this.productFilter) {
					return this.serials;
				}

				return this.serials.filter((row) => String(row.product_id) === this.productFilter);
			},

			get groupedSerials() {
				const groups = {};
				this.filteredSerials.forEach((row) => {
					const key = String(row.product_id);
					if (!groups[key]) {
						groups[key] = {
							key,
							label: row.product_label,
							rows: [],
						};
					}
					groups[key].rows.push(row);
				});

				return Object.values(groups).sort((a, b) => a.label.localeCompare(b.label));
			},

			get selectedRows() {
				return this.selectedSerialIds
					.map((id) => this.selectedSerialLookup[String(id)])
					.filter(Boolean);
			},

			selectAllVisible() {
				const selectedSet = new Set(this.selectedSerialIds.map((id) => String(id)));
				this.filteredSerials.forEach((row) => selectedSet.add(String(row.id)));
				this.selectedSerialIds = [...selectedSet];
			},

			clearSelection() {
				this.selectedSerialIds = [];
				this.selectedSerialLookup = {};
			},

			toggleGroup(groupKey) {
				this.expandedGroups[groupKey] = !this.isGroupOpen(groupKey);
			},

			isGroupOpen(groupKey) {
				if (this.expandedGroups[groupKey] === undefined) {
					return true;
				}

				return this.expandedGroups[groupKey];
			},

			async refreshSerials() {
				if (!this.sourceLocationId) {
					this.serials = [];
					this.selectedSerialIds = [];
					this.selectedSerialLookup = {};
					this.productFilter = '';
					this.lastLoadedSourceLocationId = '';
					return;
				}

				const sourceChanged = Boolean(
					this.lastLoadedSourceLocationId &&
					this.lastLoadedSourceLocationId !== this.sourceLocationId
				);
				if (sourceChanged) {
					this.selectedSerialIds = [];
					this.selectedSerialLookup = {};
				}

				this.loading = true;
				try {
					const params = new URLSearchParams({
						source_location_id: this.sourceLocationId,
						query: this.searchQuery || '',
					});
					if (this.editingTransferId) {
						params.set('transfer_id', String(this.editingTransferId));
					}
					const response = await fetch(`{{ route('transfers.available-serials') }}?${params.toString()}`, {
						headers: {
							'X-Requested-With': 'XMLHttpRequest',
						},
					});
					const payload = await response.json();
					this.serials = payload.data || [];
					this.serials.forEach((row) => {
						this.selectedSerialLookup[String(row.id)] = row;
					});
					if (this.productFilter && !this.productOptions.some((row) => String(row.id) === this.productFilter)) {
						this.productFilter = '';
					}
					this.lastLoadedSourceLocationId = this.sourceLocationId;
				} finally {
					this.loading = false;
				}
			},
		};
	}
	</script>
@endsection
