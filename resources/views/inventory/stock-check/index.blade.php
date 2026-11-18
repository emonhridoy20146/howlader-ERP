@extends('layouts.app')
@section('title', 'Stock check')
@section('page-title', 'Stock check')
@section('content')
<div
	class="space-y-4"
	x-data="stockCheckSession(@js([
		'checklistUrl' => route('inventory.stock-check.checklist'),
		'scanUrl' => route('inventory.stock-check.scan'),
		'finishUrl' => route('inventory.stock-check.finish'),
		'csrfToken' => csrf_token(),
		'defaultBranchId' => $defaultBranchId,
	]))"
	@destroy="destroy()"
>
	@if (session('error'))
		<div class="rounded-lg bg-rose-50 px-4 py-3 text-sm text-rose-700 ring-1 ring-rose-200">{{ session('error') }}</div>
	@endif

	<p class="text-sm text-slate-600">
		Select a branch to load expected stock, then scan each label or barcode. Checked items turn green.
		Press <kbd class="rounded border border-slate-300 bg-slate-50 px-1">F2</kbd> to focus the scanner.
	</p>

	<div class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm ring-1 ring-slate-200">
		<div class="grid gap-4 md:grid-cols-2 lg:grid-cols-3">
			<div>
				<label class="text-sm font-medium">Branch</label>
				<select
					x-model="branchId"
					@change="onBranchChange()"
					class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm"
				>
					<option value="">Select branch…</option>
					@foreach ($locations as $location)
						<option value="{{ $location->id }}">{{ $location->name }}@if ($location->code) ({{ $location->code }})@endif</option>
					@endforeach
				</select>
			</div>
			<div>
				<label for="stock-check-scan-input" class="text-sm font-medium">Scan or type code</label>
				<input
					id="stock-check-scan-input"
					type="text"
					x-ref="scanInput"
					x-model="scanInput"
					inputmode="none"
					autocomplete="off"
					data-scan-gateway
					:disabled="!sessionActive || loading"
					class="mt-1 w-full rounded-lg border border-emerald-300 bg-white px-3 py-2.5 text-sm font-mono shadow-sm outline-none ring-emerald-500/15 focus:border-emerald-600 focus:ring-2 focus:ring-emerald-500/30 disabled:cursor-not-allowed disabled:bg-slate-50 disabled:text-slate-400"
					placeholder="Scan QR / label code…"
					@keydown.enter.prevent="submitScan()"
				>
			</div>
			<div class="flex flex-col justify-end gap-2 sm:flex-row md:col-span-2 lg:col-span-1">
				<button
					type="button"
					@click="resetSession()"
					:disabled="!sessionActive"
					class="rounded-lg border border-slate-300 bg-white px-4 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50 disabled:cursor-not-allowed disabled:opacity-50"
				>Reset</button>
				<button
					type="button"
					@click="submitFinish()"
					:disabled="!sessionActive || finishing"
					class="rounded-lg bg-slate-900 px-4 py-2 text-sm font-semibold text-white hover:bg-slate-800 disabled:cursor-not-allowed disabled:opacity-50"
				>End check &amp; report</button>
			</div>
		</div>

		<div
			x-show="notice"
			x-cloak
			class="mt-3 rounded-lg px-3 py-2 text-sm"
			:class="noticeClass"
			x-text="notice"
		></div>
	</div>

	<template x-if="sessionActive">
		<div class="space-y-4">
			<div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
				<div class="rounded-xl border border-emerald-200 bg-emerald-50 p-4 ring-1 ring-emerald-100">
					<p class="text-xs font-semibold uppercase tracking-wide text-emerald-700">Checked</p>
					<p class="mt-1 text-2xl font-bold tabular-nums text-emerald-900" x-text="checkedUnits"></p>
				</div>
				<div class="rounded-xl border border-amber-200 bg-amber-50 p-4 ring-1 ring-amber-100">
					<p class="text-xs font-semibold uppercase tracking-wide text-amber-700">Remaining</p>
					<p class="mt-1 text-2xl font-bold tabular-nums text-amber-900" x-text="remainingUnits"></p>
				</div>
				<div class="rounded-xl border border-slate-200 bg-white p-4 ring-1 ring-slate-200">
					<p class="text-xs font-semibold uppercase tracking-wide text-slate-500">Expected</p>
					<p class="mt-1 text-2xl font-bold tabular-nums text-slate-900" x-text="expectedUnits"></p>
				</div>
				<div class="rounded-xl border border-rose-200 bg-rose-50 p-4 ring-1 ring-rose-100">
					<p class="text-xs font-semibold uppercase tracking-wide text-rose-700">Extras scanned</p>
					<p class="mt-1 text-2xl font-bold tabular-nums text-rose-900" x-text="extras.length"></p>
				</div>
			</div>

			<div class="overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm ring-1 ring-slate-200">
				<div class="flex flex-wrap items-center justify-between gap-3 border-b border-slate-100 px-4 py-3">
					<div>
						<h2 class="text-sm font-semibold text-slate-900" x-text="branchLabel"></h2>
						<p class="text-xs text-slate-500">
							<span x-text="serials.length"></span> serials ·
							<span x-text="products.length"></span> bulk products
						</p>
					</div>
					<div class="flex rounded-lg border border-slate-200 p-0.5 text-xs">
						<button type="button" @click="listFilter = 'all'" class="rounded-md px-3 py-1.5 font-medium" :class="listFilter === 'all' ? 'bg-slate-900 text-white' : 'text-slate-600 hover:bg-slate-50'">All</button>
						<button type="button" @click="listFilter = 'remaining'" class="rounded-md px-3 py-1.5 font-medium" :class="listFilter === 'remaining' ? 'bg-slate-900 text-white' : 'text-slate-600 hover:bg-slate-50'">Remaining</button>
						<button type="button" @click="listFilter = 'checked'" class="rounded-md px-3 py-1.5 font-medium" :class="listFilter === 'checked' ? 'bg-slate-900 text-white' : 'text-slate-600 hover:bg-slate-50'">Checked</button>
					</div>
				</div>

				<div class="max-h-[32rem] overflow-y-auto">
					<template x-if="visibleSerials.length === 0 && visibleProducts.length === 0">
						<p class="px-4 py-8 text-center text-sm text-slate-500">No items match this filter.</p>
					</template>

					<template x-for="row in visibleSerials" :key="'s-' + row.serial_id">
						<div
							class="flex items-start gap-3 border-b border-slate-100 px-4 py-3 text-sm transition-colors"
							:class="row.checked ? 'bg-emerald-50' : 'bg-white'"
						>
							<div
								class="mt-0.5 flex h-6 w-6 shrink-0 items-center justify-center rounded-full border text-xs font-bold"
								:class="row.checked ? 'border-emerald-500 bg-emerald-500 text-white' : 'border-slate-300 text-slate-400'"
								x-text="row.checked ? '✓' : ''"
							></div>
							<div class="min-w-0 flex-1">
								<p class="font-medium text-slate-900" x-text="row.name"></p>
								<p class="font-mono text-xs text-slate-600">
									<span x-text="row.sku"></span>
									<template x-if="row.label_code">
										<span> · Label <span x-text="row.label_code"></span></span>
									</template>
									<span> · Serial <span x-text="row.serial_number"></span></span>
								</p>
							</div>
							<span class="shrink-0 rounded-full px-2 py-0.5 text-xs font-medium ring-1" :class="row.checked ? 'bg-emerald-100 text-emerald-800 ring-emerald-200' : 'bg-slate-100 text-slate-600 ring-slate-200'" x-text="row.status_label"></span>
						</div>
					</template>

					<template x-for="row in visibleProducts" :key="'p-' + row.product_id">
						<div
							class="flex items-start gap-3 border-b border-slate-100 px-4 py-3 text-sm transition-colors"
							:class="row.checked_qty >= row.expected_qty ? 'bg-emerald-50' : (row.checked_qty > 0 ? 'bg-amber-50/60' : 'bg-white')"
						>
							<div
								class="mt-0.5 flex h-6 w-6 shrink-0 items-center justify-center rounded-full border text-xs font-bold"
								:class="row.checked_qty >= row.expected_qty ? 'border-emerald-500 bg-emerald-500 text-white' : 'border-slate-300 text-slate-400'"
								x-text="row.checked_qty >= row.expected_qty ? '✓' : ''"
							></div>
							<div class="min-w-0 flex-1">
								<p class="font-medium text-slate-900" x-text="row.name"></p>
								<p class="font-mono text-xs text-slate-600">
									<span x-text="row.sku"></span>
									<template x-if="row.barcode">
										<span> · Barcode <span x-text="row.barcode"></span></span>
									</template>
								</p>
							</div>
							<span class="shrink-0 rounded-full bg-slate-100 px-2 py-0.5 text-xs font-medium tabular-nums text-slate-700 ring-1 ring-slate-200">
								<span x-text="row.checked_qty"></span> / <span x-text="row.expected_qty"></span>
							</span>
						</div>
					</template>
				</div>
			</div>

			<template x-if="extras.length > 0">
				<div class="rounded-xl border border-rose-200 bg-rose-50/50 p-4 ring-1 ring-rose-100">
					<h3 class="text-sm font-semibold text-rose-900">Extra scans (not expected at this branch)</h3>
					<ul class="mt-2 space-y-1 text-sm text-rose-800">
						<template x-for="(item, index) in extras" :key="'e-' + index">
							<li class="font-mono text-xs">
								<template x-if="item.kind === 'serial'">
									<span x-text="(item.label_code || item.serial_number) + ' · ' + item.sku + ' · ' + (item.location ? item.location.name : item.status_label)"></span>
								</template>
								<template x-if="item.kind === 'product'">
									<span x-text="item.barcode + ' · ' + item.sku + ' · ' + item.name"></span>
								</template>
							</li>
						</template>
					</ul>
				</div>
			</template>
		</div>
	</template>

	<template x-if="!sessionActive && branchId && !loadingChecklist">
		<div class="rounded-xl border border-dashed border-slate-300 bg-slate-50 px-4 py-10 text-center text-sm text-slate-500">
			Select a branch to start a stock check session.
		</div>
	</template>

	<form x-ref="finishForm" method="POST" action="{{ route('inventory.stock-check.finish') }}" class="hidden">
		@csrf
		<input type="hidden" name="branch_id" :value="branchId">
		<input type="hidden" name="extras_json" :value="JSON.stringify(extras)">
		<template x-for="id in checkedSerialIds" :key="'hid-s-' + id">
			<input type="hidden" name="checked_serial_ids[]" :value="id">
		</template>
		<template x-for="row in products" :key="'hid-p-' + row.product_id">
			<input type="hidden" :name="'product_checked_qty[' + row.product_id + '][product_id]'" :value="row.product_id">
			<input type="hidden" :name="'product_checked_qty[' + row.product_id + '][checked_qty]'" :value="row.checked_qty">
		</template>
	</form>
</div>

<script>
function stockCheckSession(boot = {}) {
	return {
		checklistUrl: boot.checklistUrl,
		scanUrl: boot.scanUrl,
		finishUrl: boot.finishUrl,
		csrfToken: boot.csrfToken,
		branchId: boot.defaultBranchId ? String(boot.defaultBranchId) : '',
		branchLabel: '',
		scanInput: '',
		serials: [],
		products: [],
		checkedSerialIds: [],
		extras: [],
		expectedUnits: 0,
		checklistLoaded: false,
		notice: '',
		noticeType: 'success',
		loading: false,
		loadingChecklist: false,
		finishing: false,
		barcodeScanHandler: null,
		focusHotkey: null,

		get sessionActive() {
			return this.branchId !== '' && this.checklistLoaded;
		},

		get checkedUnits() {
			const serialCount = this.checkedSerialIds.length;
			const productCount = this.products.reduce((sum, row) => sum + Math.min(row.expected_qty, row.checked_qty), 0);
			return serialCount + productCount;
		},

		get remainingUnits() {
			return Math.max(0, this.expectedUnits - this.checkedUnits);
		},

		get noticeClass() {
			if (this.noticeType === 'error') return 'bg-rose-50 text-rose-700 ring-1 ring-rose-200';
			if (this.noticeType === 'warning') return 'bg-amber-50 text-amber-800 ring-1 ring-amber-200';
			if (this.noticeType === 'duplicate') return 'bg-slate-100 text-slate-700 ring-1 ring-slate-200';
			return 'bg-emerald-50 text-emerald-800 ring-1 ring-emerald-200';
		},

		get visibleSerials() {
			return this.serials.filter((row) => {
				if (this.listFilter === 'checked') return row.checked;
				if (this.listFilter === 'remaining') return !row.checked;
				return true;
			});
		},

		get visibleProducts() {
			return this.products.filter((row) => {
				const complete = row.checked_qty >= row.expected_qty;
				if (this.listFilter === 'checked') return complete;
				if (this.listFilter === 'remaining') return !complete;
				return true;
			});
		},

		init() {
			this.barcodeScanHandler = (event) => {
				const code = String(event?.detail?.code ?? '').trim();
				if (code === '') return;
				this.scanInput = code;
				this.submitScan();
			};
			window.addEventListener('barcode-scanned', this.barcodeScanHandler);

			this.focusHotkey = (event) => {
				if (event.key !== 'F2') return;
				event.preventDefault();
				this.$refs.scanInput?.focus({ preventScroll: true });
			};
			window.addEventListener('keydown', this.focusHotkey);

			if (this.branchId) {
				this.onBranchChange();
			} else {
				this.$nextTick(() => this.$refs.scanInput?.focus({ preventScroll: true }));
			}
		},

		destroy() {
			if (this.barcodeScanHandler) {
				window.removeEventListener('barcode-scanned', this.barcodeScanHandler);
			}
			if (this.focusHotkey) {
				window.removeEventListener('keydown', this.focusHotkey);
			}
		},

		resetSession() {
			this.checkedSerialIds = [];
			this.extras = [];
			this.serials = this.serials.map((row) => ({ ...row, checked: false }));
			this.products = this.products.map((row) => ({ ...row, checked_qty: 0 }));
			this.notice = 'Session reset.';
			this.noticeType = 'warning';
		},

		async onBranchChange() {
			if (!this.branchId) {
				this.serials = [];
				this.products = [];
				this.checkedSerialIds = [];
				this.extras = [];
				this.expectedUnits = 0;
				this.branchLabel = '';
				this.checklistLoaded = false;
				return;
			}

			this.loadingChecklist = true;
			this.notice = '';
			this.checkedSerialIds = [];
			this.extras = [];

			const params = new URLSearchParams({ branch_id: this.branchId });

			try {
				const response = await fetch(`${this.checklistUrl}?${params.toString()}`, {
					headers: {
						Accept: 'application/json',
						'X-Requested-With': 'XMLHttpRequest',
					},
				});
				const payload = await response.json();

				if (!response.ok) {
					this.noticeType = 'error';
					this.notice = payload.error ?? 'Could not load checklist.';
					this.serials = [];
					this.products = [];
					this.checklistLoaded = false;
					return;
				}

				const data = payload.data ?? {};
				this.branchLabel = data.branch
					? `${data.branch.name}${data.branch.code ? ' (' + data.branch.code + ')' : ''}`
					: '';
				this.serials = (data.serials ?? []).map((row) => ({ ...row, checked: false }));
				this.products = (data.products ?? []).map((row) => ({ ...row, checked_qty: 0 }));
				this.expectedUnits = data.summary?.expected_units ?? 0;
				this.checklistLoaded = true;
				this.noticeType = 'success';
				this.notice = this.expectedUnits > 0
					? `Loaded ${this.expectedUnits} expected units. Start scanning.`
					: 'No stock expected at this branch. You can still scan for extras and finish.';
				this.$nextTick(() => this.$refs.scanInput?.focus({ preventScroll: true }));
			} catch (error) {
				this.noticeType = 'error';
				this.notice = 'Could not load checklist. Try again.';
			} finally {
				this.loadingChecklist = false;
			}
		},

		productCheckedPayload() {
			return this.products.map((row) => ({
				product_id: row.product_id,
				checked_qty: row.checked_qty,
			}));
		},

		async submitScan() {
			const code = String(this.scanInput ?? '').trim();
			if (code === '' || this.loading || !this.branchId) return;

			this.loading = true;
			this.notice = '';

			try {
				const response = await fetch(this.scanUrl, {
					method: 'POST',
					headers: {
						Accept: 'application/json',
						'Content-Type': 'application/json',
						'X-CSRF-TOKEN': this.csrfToken,
						'X-Requested-With': 'XMLHttpRequest',
					},
					body: JSON.stringify({
						branch_id: Number(this.branchId),
						code,
						checked_serial_ids: this.checkedSerialIds,
						product_checked_qty: this.productCheckedPayload(),
					}),
				});
				const payload = await response.json();

				if (!response.ok) {
					this.noticeType = 'error';
					this.notice = payload.error ?? 'Scan failed.';
					return;
				}

				if (payload.result === 'serial') {
					this.checkedSerialIds.push(payload.serial_id);
					this.serials = this.serials.map((row) =>
						row.serial_id === payload.serial_id ? { ...row, checked: true } : row
					);
					this.noticeType = 'success';
					this.notice = payload.message;
				} else if (payload.result === 'product') {
					this.products = this.products.map((row) =>
						row.product_id === payload.product_id
							? { ...row, checked_qty: payload.checked_qty }
							: row
					);
					this.noticeType = 'success';
					this.notice = payload.message;
				} else if (payload.result === 'duplicate') {
					this.noticeType = 'duplicate';
					this.notice = payload.message;
				} else if (payload.result === 'extra') {
					if (payload.extra) {
						this.extras.push(payload.extra);
					}
					this.noticeType = 'warning';
					this.notice = payload.message;
				}
			} catch (error) {
				this.noticeType = 'error';
				this.notice = 'Could not process scan. Try again.';
			} finally {
				this.loading = false;
				this.scanInput = '';
				this.$nextTick(() => this.$refs.scanInput?.focus({ preventScroll: true }));
			}
		},

		submitFinish() {
			if (!this.sessionActive || this.finishing) return;
			this.finishing = true;
			this.$refs.finishForm?.submit();
		},
	};
}
</script>
@endsection
