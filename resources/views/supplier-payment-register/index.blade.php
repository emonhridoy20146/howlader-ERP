@extends('layouts.app')
@section('title', 'Supplier payment register')
@section('page-title', 'Supplier payment register')

@section('content')
@php
	$selected = $selectedPo ?? null;
	$amountDueSelected = $selected ? round((float) $selected->amountDue(), 2) : 0;
	$closeSheetHref = route('supplier-payment-register.index', request()->except(['purchase_order', 'page']));
	$payDiscountInitial = (old('settlement_discount') !== null && old('settlement_discount') !== '')
		? round((float) old('settlement_discount'), 2)
		: 0;
	$payAmountInitial = old('amount') !== null && old('amount') !== ''
		? round((float) old('amount'), 2)
		: max(0, round($amountDueSelected - $payDiscountInitial, 2));
@endphp

<div
	class="space-y-6"
	@if ($selected)
		x-data='{
			paySheetOpen: true,
			closeSheetHref: @json($closeSheetHref),
			maxDue: @json($amountDueSelected),
			payAmount: @json($payAmountInitial),
			payDiscount: @json($payDiscountInitial),
			closePaySheet() { window.location.href = this.closeSheetHref; },
			totalApplied() {
				const c = Number(this.payAmount) || 0;
				const d = Number(this.payDiscount) || 0;
				return Math.round((c + d) * 100) / 100;
			},
			dueAfterPayment() {
				const due = Number(this.maxDue) || 0;
				return Math.round(Math.max(0, due - this.totalApplied()) * 100) / 100;
			},
			maxDiscountNow() {
				const cash = Number(this.payAmount) || 0;
				return Math.round(Math.max(0, Number(this.maxDue) - cash) * 100) / 100;
			},
			onAmountInput() {
				const md = this.maxDiscountNow();
				if ((Number(this.payDiscount) || 0) > md + 0.0001) this.payDiscount = md;
			},
			onDiscountInput() {
				let d = Math.round(Math.max(0, Number(this.payDiscount) || 0) * 100) / 100;
				const md = this.maxDiscountNow();
				if (d > md + 0.0001) d = md;
				this.payDiscount = d;
			}
		}'
		@keydown.escape.window="closePaySheet()"
	@endif
>
	@if (session('status'))
		<div class="rounded-lg bg-emerald-50 p-3 text-sm text-emerald-700">{{ session('status') }}</div>
	@endif

	@if ($errors->any())
		<div class="rounded-lg border border-rose-200 bg-rose-50 p-3 text-sm text-rose-800">
			<p class="font-medium">Please fix the following:</p>
			<ul class="mt-2 list-inside list-disc space-y-0.5">
				@foreach ($errors->all() as $err)
					<li>{{ $err }}</li>
				@endforeach
			</ul>
		</div>
	@endif

	<form method="get" action="{{ route('supplier-payment-register.index') }}" class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
		<input type="hidden" name="purchase_order" value="{{ request('purchase_order') }}">
		<h2 class="text-sm font-semibold text-slate-800">Find purchase orders</h2>
		<p class="mt-1 text-xs text-slate-500">Search approved or in-progress POs with a supplier balance. Warehouse follows your branch context.</p>
		<div class="mt-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
			<div>
				<label for="po_q" class="text-xs font-medium text-slate-600">PO # contains</label>
				<input
					id="po_q"
					type="search"
					name="po_q"
					value="{{ $filters['po_q'] }}"
					class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm"
					placeholder="e.g. PO-"
				>
			</div>
			<div>
				<label for="supplier_id" class="text-xs font-medium text-slate-600">Supplier</label>
				<select id="supplier_id" name="supplier_id" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
					<option value="">Any supplier</option>
					@foreach ($suppliers as $s)
						<option value="{{ $s->id }}" @selected((string) $filters['supplier_id'] === (string) $s->id)>{{ $s->name }}</option>
					@endforeach
				</select>
			</div>
		</div>
		<div class="mt-3 flex flex-wrap gap-2">
			<button type="submit" class="rounded-lg bg-slate-900 px-4 py-2 text-sm font-medium text-white hover:bg-slate-800">Search</button>
			<a href="{{ route('supplier-payment-register.index', array_filter(['purchase_order' => request('purchase_order')])) }}" class="rounded-lg border border-slate-300 bg-white px-4 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50">Reset filters</a>
		</div>
	</form>

	<div class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm sm:p-5">
		<h2 class="text-sm font-semibold text-slate-800">Open balances</h2>
		<p class="mt-1 text-xs text-slate-500">Purchase orders that still owe the supplier (excluding draft and cancelled). Choose <strong>Pay</strong> to record a wallet payment in the slide-over.</p>
		<div class="mt-4 overflow-x-auto">
			<table class="min-w-full divide-y divide-slate-200 text-sm">
				<thead>
					<tr class="text-left text-xs font-medium uppercase tracking-wide text-slate-500">
						<th class="pb-2 pr-4">PO</th>
						<th class="pb-2 pr-4">Supplier</th>
						<th class="pb-2 pr-4">Warehouse</th>
						<th class="pb-2 pr-4">Status</th>
						<th class="pb-2 pr-4">Due</th>
						<th class="pb-2"></th>
					</tr>
				</thead>
				<tbody class="divide-y divide-slate-100">
					@forelse ($orders as $po)
						@php $due = round((float) $po->amountDue(), 2); @endphp
						<tr @class(['bg-indigo-50/80' => $selected && $selected->id === $po->id])>
							<td class="py-3 pr-4 font-mono text-xs font-semibold">
								<a
									href="{{ route('purchase-orders.show', $po) }}"
									target="_blank"
									rel="noopener noreferrer"
									title="Open PO in a new tab"
									class="text-indigo-600 hover:text-indigo-800"
								>{{ $po->po_number }}</a>
							</td>
							<td class="py-3 pr-4 text-slate-700">{{ $po->supplier?->name ?? '—' }}</td>
							<td class="py-3 pr-4 text-slate-600">{{ $po->destinationLocation?->name ?? '—' }}</td>
							<td class="py-3 pr-4 text-slate-600">{{ ucfirst(str_replace('_', ' ', $po->status)) }}</td>
							<td class="py-3 pr-4 font-semibold text-rose-700">{{ number_format($due, 2) }}</td>
							<td class="py-3">
								<a
									href="{{ route('supplier-payment-register.index', array_merge(request()->except('page'), ['purchase_order' => $po->id])) }}"
									class="rounded-lg bg-slate-900 px-3 py-1.5 text-xs font-medium text-white hover:bg-slate-800"
								>Pay</a>
							</td>
						</tr>
					@empty
						<tr>
							<td colspan="6" class="py-8 text-center text-sm text-slate-500">No matching purchase orders with a balance.</td>
						</tr>
					@endforelse
				</tbody>
			</table>
		</div>
		<div class="mt-4">{{ $orders->links() }}</div>
	</div>

	@if ($selected)
		<div
			x-show="paySheetOpen"
			x-cloak
			class="fixed inset-0 z-[100] flex justify-end"
			role="dialog"
			aria-modal="true"
			aria-labelledby="supplier-payment-register-sheet-title"
		>
			<div class="absolute inset-0 bg-slate-900/40" @click="closePaySheet()" aria-hidden="true"></div>
			<div
				class="relative flex h-full w-full max-w-lg flex-col bg-white shadow-2xl ring-1 ring-slate-900/10"
				@click.stop
			>
				<div class="flex items-start justify-between gap-3 border-b border-slate-200 px-4 py-4 sm:px-5">
					<div class="min-w-0">
						<h2 id="supplier-payment-register-sheet-title" class="text-base font-semibold text-slate-900">Register supplier payment</h2>
						<p class="mt-1 text-xs text-slate-500">
							<a
								href="{{ route('purchase-orders.show', $selected) }}"
								target="_blank"
								rel="noopener noreferrer"
								title="Open PO in a new tab"
								class="font-mono font-semibold text-indigo-600 hover:text-indigo-800"
							>{{ $selected->po_number }}</a>
							· {{ $selected->supplier?->name }}
							· Due {{ number_format($amountDueSelected, 2) }}
						</p>
					</div>
					<a
						href="{{ $closeSheetHref }}"
						class="shrink-0 rounded-lg p-2 text-slate-500 hover:bg-slate-100 hover:text-slate-800"
						aria-label="Close"
					>
						<svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12" /></svg>
					</a>
				</div>

				<div class="min-h-0 flex-1 overflow-y-auto px-4 py-4 sm:px-5">
					@cannot('update', $selected)
						<p class="rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-sm text-amber-900">You do not have permission to record payments on this purchase order.</p>
					@endcannot

					@can('update', $selected)
						@if ($paymentWallets->isEmpty())
							<p class="rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-sm text-amber-900">
								{{ $ownerCapitalWalletCode ?? 'OWNER-0001' }} wallet is required for this register. Make sure it is active, has an active ledger account, and applies to this warehouse.
							</p>
						@endif

						<form action="{{ route('purchase-orders.payments.store', $selected) }}" method="post" class="mt-4 space-y-4">
							@csrf
							<input type="hidden" name="return_to" value="supplier-payment-register">
							<input type="hidden" name="__po_payment" value="1">
							<input type="hidden" name="settlement_discount" x-bind:value="payDiscount">

							<div>
								<label for="reg-po-wallet_id" class="text-xs font-medium text-slate-600">Pay from wallet</label>
								@if ($paymentWallets->count() === 1)
									@php $ownerWallet = $paymentWallets->first(); @endphp
									<input type="hidden" name="wallet_id" value="{{ $ownerWallet->id }}">
									<div id="reg-po-wallet_id" class="mt-1 rounded-lg border border-slate-200 bg-slate-50 px-3 py-2 text-sm text-slate-800">
										<span class="font-semibold">{{ $ownerWallet->code }}</span>
										<span class="text-slate-500">— {{ $ownerWallet->name }}</span>
									</div>
									<p class="mt-1 text-xs text-slate-500">Supplier payments from this register always use {{ $ownerCapitalWalletCode ?? 'OWNER-0001' }}.</p>
								@else
									<select id="reg-po-wallet_id" name="wallet_id" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm" required @disabled($paymentWallets->isEmpty())>
										<option value="">Select wallet…</option>
										@foreach ($paymentWallets as $w)
											<option value="{{ $w->id }}" @selected((string) old('wallet_id') === (string) $w->id)>{{ $w->code }} — {{ $w->name }}</option>
										@endforeach
									</select>
									<p class="mt-1 text-xs text-slate-500">Only {{ $ownerCapitalWalletCode ?? 'OWNER-0001' }} is accepted here.</p>
								@endif
							</div>

							<div class="rounded-xl border-2 border-slate-200 bg-gradient-to-b from-slate-50 to-white p-4 shadow-sm">
								<label for="reg-po-amount" class="block text-sm font-semibold uppercase tracking-wide text-slate-600">Cash amount</label>
								<input
									id="reg-po-amount"
									type="number"
									step="0.01"
									min="0.01"
									name="amount"
									x-model.number="payAmount"
									@input="onAmountInput()"
									x-bind:max="maxDue"
									class="mt-3 w-full min-h-[4rem] rounded-xl border-2 border-slate-300 bg-white px-4 py-4 text-3xl font-semibold tabular-nums tracking-tight text-slate-900 shadow-inner focus:border-indigo-500 focus:outline-none focus:ring-4 focus:ring-indigo-500/20"
									required
									@disabled($amountDueSelected <= 0.009 || $paymentWallets->isEmpty())
								>
								<p class="mt-2 text-xs text-slate-500">Due {{ number_format($amountDueSelected, 2) }} · After payment: <span class="font-semibold tabular-nums" x-text="dueAfterPayment().toFixed(2)">0.00</span></p>
							</div>

							<div class="rounded-xl border border-indigo-200 bg-indigo-50/50 p-4">
								<label for="reg-po-settlement-discount" class="block text-sm font-semibold uppercase tracking-wide text-indigo-900">Settlement discount</label>
								<p class="mt-1 text-xs text-indigo-800/80">Reduces payable balance without extra cash (cash + discount cannot exceed due).</p>
								<input
									id="reg-po-settlement-discount"
									type="number"
									step="0.01"
									min="0"
									x-model.number="payDiscount"
									@input="onDiscountInput()"
									x-bind:max="maxDiscountNow()"
									class="mt-2 w-full rounded-lg border border-indigo-200 bg-white px-3 py-2.5 text-lg font-semibold tabular-nums text-indigo-950 focus:border-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-500/30"
									@disabled($amountDueSelected <= 0.009 || $paymentWallets->isEmpty())
								>
								<p class="mt-2 text-xs text-indigo-900">Applied to PO: <span class="font-semibold tabular-nums" x-text="totalApplied().toFixed(2)">0.00</span></p>
							</div>

							<div>
								<label for="reg-po-paid_at" class="text-xs font-medium text-slate-600">Paid on</label>
								<input id="reg-po-paid_at" type="date" name="paid_at" value="{{ old('paid_at', now()->toDateString()) }}" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm" required @disabled($paymentWallets->isEmpty())>
							</div>

							<div>
								<label for="reg-po-reference" class="text-xs font-medium text-slate-600">Reference (optional)</label>
								<input id="reg-po-reference" type="text" name="reference" value="{{ old('reference') }}" maxlength="120" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm" placeholder="Cheque / transfer ref" @disabled($paymentWallets->isEmpty())>
							</div>

							<div>
								<label for="reg-po-notes" class="text-xs font-medium text-slate-600">Notes</label>
								<input id="reg-po-notes" type="text" name="notes" value="{{ old('notes') }}" maxlength="500" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm" @disabled($paymentWallets->isEmpty())>
							</div>

							@if ($paymentWallets->isNotEmpty() && $amountDueSelected > 0.009)
								<button type="submit" class="w-full rounded-xl bg-slate-900 px-4 py-4 text-base font-semibold text-white shadow-sm hover:bg-slate-800">Save payment</button>
							@endif

							<a
								href="{{ route('purchase-orders.show', $selected) }}"
								target="_blank"
								rel="noopener noreferrer"
								class="block text-center text-xs font-medium text-indigo-600 hover:text-indigo-800"
							>Open PO details</a>
						</form>
					@endcan
				</div>
			</div>
		</div>
	@endif
</div>
@endsection
