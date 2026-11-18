@extends('layouts.app')
@section('title', 'Payment register')
@section('page-title', 'Payment register')

@section('content')
@php
	$saleTypeLabels = ['default' => 'Cash', 'credit' => 'Credit', 'installment' => 'Installment'];
	$selected = $selectedInvoice;
	$amountPaidSnap = $selected ? round((float) $selected->amount_paid, 2) : 0;
	$settlementDiscountSnap = $selected ? round((float) $selected->displaySettlementDiscountApplied(), 2) : 0;
	$cashCollectedSnap = $selected ? round((float) $selected->displayCashCollected(), 2) : 0;
	$amountDueSnap = $selected ? round((float) $selected->amount_due, 2) : 0;
	$grandTotalSnap = $selected ? round((float) $selected->grand_total, 2) : 0;
	$allowsFlexiblePaymentCollection = auth()->user()?->can('collect-flexible-payment') ?? false;
	$canApplySettlementDiscount = auth()->user()?->can('apply-settlement-discount') ?? false;
	$targetableSchedules = ($selected && $selected->sale_type === 'installment' && $selected->installmentPlan)
		? $selected->installmentPlan->schedulesSelectableForTargetedPayment($allowsFlexiblePaymentCollection)
		: collect();
	$nextInstallment = ($selected && $selected->sale_type === 'installment' && $selected->installmentPlan)
		? $selected->installmentPlan->schedules->first(fn ($s) => ! $s->isFullyPaid())
		: null;
	$defaultPaymentMethodId = $selected ? $paymentMethods->first()?->id : null;
	$paymentMethodOptions = $paymentMethods->map(fn ($m) => [
		'id' => $m->id,
		'name' => $m->name,
		'type' => ucfirst(str_replace('_', ' ', $m->type)),
	])->values()->all();
	$amountDueValue = $selected ? round((float) $selected->amount_due, 2) : 0;
	$defaultInstallmentDueValue = $nextInstallment
		? round((float) $nextInstallment->remainingDue(), 2)
		: $amountDueValue;
	$defaultPayAmountValue = ($selected && $selected->sale_type === 'installment')
		? $defaultInstallmentDueValue
		: $amountDueValue;
	$requiresFullPayment = $selected && $selected->requiresFullPaymentOnCollection();
	$payAmountInitial = (old('amount') !== null && old('amount') !== '')
		? round((float) old('amount'), 2)
		: $defaultPayAmountValue;
	$payDiscountInitial = (old('settlement_discount') !== null && old('settlement_discount') !== '')
		? round((float) old('settlement_discount'), 2)
		: 0.0;
	$scheduleRemainingById = ($selected && $selected->installmentPlan)
		? $selected->installmentPlan->schedules->mapWithKeys(
			fn ($s) => [(string) $s->id => round((float) $s->remainingDue(), 2)]
		)->all()
		: [];
	$paymentDateOld = old('payment_date');
	$paymentDateInitial = ($paymentDateOld !== null && $paymentDateOld !== '')
		? $paymentDateOld
		: now()->format('Y-m-d');
	$closeSheetHref = route('payment-register.index', request()->except(['invoice', 'page']));
	$customerOptions = $customers->map(function ($c) {
		$phones = $c->normalizedPhoneNumbers();
		$phoneLabel = $phones !== [] ? ' · '.implode(', ', $phones) : '';

		return [
			'value' => $c->id,
			'label' => $c->name.' ('.$c->customer_code.$phoneLabel.')',
		];
	})->values()->all();
	$referrerEmployeeOptions = ($referrerEmployees ?? collect())->map(fn ($employee) => [
		'value' => $employee->id,
		'label' => $employee->displayLabel(),
	])->values()->all();
	$selectedReferrerEmployeeId = old('referrer_employee_id', '');
@endphp

<div
	class="space-y-6"
	@if ($selected)
		x-data='{
			paySheetOpen: true,
			payAmount: @json($payAmountInitial),
			payDiscount: @json($payDiscountInitial),
			maxDue: @json($amountDueValue),
			defaultPayAmount: @json($defaultPayAmountValue),
			requiresFullPayment: @json($requiresFullPayment),
			allowsFlexibleCollection: @json($allowsFlexiblePaymentCollection),
			scheduleRemaining: @json($scheduleRemainingById),
			installmentTarget: @json(old("installment_target", "waterfall")),
			scheduleId: @json(old("installment_schedule_id") ? (string) old("installment_schedule_id") : ""),
			discountSheetOpen: false,
			discountDraft: 0,
			closeSheetHref: @json($closeSheetHref),
			confirmOpen: false,
			confirmAck: false,
			paymentDate: @json($paymentDateInitial),
			paymentMethodId: @json(old('payment_method_id') !== null ? (int) old('payment_method_id') : $defaultPaymentMethodId),
			paymentMethods: @json($paymentMethodOptions),
			referrerEmployeeOptions: @json($referrerEmployeeOptions),
			selectedReferrerLabel: "",
			selectedPaymentMethodLabel() {
				const id = Number(this.paymentMethodId);
				const match = this.paymentMethods.find((m) => Number(m.id) === id);
				return match ? `${match.name} (${match.type})` : "—";
			},
			closePaySheet() { window.location.href = this.closeSheetHref; },
			syncFullPaymentAmount() {
				if (!this.requiresFullPayment) return;
				const due = Number(this.maxDue) || 0;
				const disc = Number(this.payDiscount) || 0;
				this.payAmount = Math.round(Math.max(0, due - disc) * 100) / 100;
			},
			openConfirmModal() {
				const f = this.$refs.paymentRegisterForm;
				if (!f.checkValidity()) { f.reportValidity(); return; }
				const referrerId = f.querySelector("input[name=referrer_employee_id]")?.value?.trim();
				if (!referrerId) {
					if (window.Swal) {
						window.Swal.fire({ icon: "warning", title: "Referrer employee required", text: "Select a referrer employee before reviewing this payment." });
					} else {
						alert("Select a referrer employee before reviewing this payment.");
					}
					return;
				}
				const referrerMatch = this.referrerEmployeeOptions.find((o) => String(o.value) === referrerId);
				this.selectedReferrerLabel = referrerMatch ? referrerMatch.label : "—";
				if (!this.allowsFlexibleCollection && this.requiresFullPayment && this.totalApplied() < Number(this.maxDue) - 0.001) {
					this.syncFullPaymentAmount();
				}
				if (!this.allowsFlexibleCollection && this.requiresFullPayment && this.totalApplied() < Number(this.maxDue) - 0.001) {
					f.reportValidity();
					return;
				}
				this.confirmAck = false;
				this.confirmOpen = true;
			},
			closeConfirmModal() { this.confirmOpen = false; },
			totalApplied() {
				const c = Number(this.payAmount) || 0;
				const d = Number(this.payDiscount) || 0;
				return Math.round((c + d) * 100) / 100;
			},
			dueAfterPayment() {
				const due = Number(this.maxDue) || 0;
				return Math.round(Math.max(0, due - this.totalApplied()) * 100) / 100;
			},
			paymentStatusAfter() {
				return this.dueAfterPayment() <= 0.001 ? "Paid in full" : "Still due";
			},
			maxDiscountNow() {
				if (this.allowsFlexibleCollection) {
					return 999999999;
				}
				const cash = Number(this.payAmount) || 0;
				const cap = Number(this.maxDue) - cash;
				return Math.round(Math.max(0, cap) * 100) / 100;
			},
			openDiscountSheet() {
				this.discountDraft = Number(this.payDiscount) || 0;
				this.discountSheetOpen = true;
			},
			closeDiscountSheet() {
				this.discountSheetOpen = false;
			},
			applyDiscountSheet() {
				let v = Math.round(Math.max(0, Number(this.discountDraft) || 0) * 100) / 100;
				const maxD = this.maxDiscountNow();
				if (v > maxD + 0.0001) v = maxD;
				this.payDiscount = v;
				this.syncFullPaymentAmount();
				this.discountSheetOpen = false;
			},
			clearDiscountSheet() {
				this.payDiscount = 0;
				this.discountDraft = 0;
				this.syncFullPaymentAmount();
				this.discountSheetOpen = false;
			},
			onTargetChange(preSchedule) {
				const pre = preSchedule ? String(preSchedule) : "";
				this.scheduleId = pre;
				if (this.installmentTarget === "schedule" && pre && this.scheduleRemaining[pre] != null) {
					const cap = this.allowsFlexibleCollection
						? Number(this.maxDue)
						: Math.min(Number(this.maxDue), Number(this.scheduleRemaining[pre]));
					this.payAmount = Math.round(cap * 100) / 100;
				} else {
					this.payAmount = this.defaultPayAmount;
				}
				const md = this.maxDiscountNow();
				if ((Number(this.payDiscount) || 0) > md + 0.0001) this.payDiscount = md;
			},
			onEscapePaymentRegister() {
				if (this.confirmOpen) this.closeConfirmModal();
				else if (this.discountSheetOpen) this.closeDiscountSheet();
				else this.closePaySheet();
			}
		}'
		@keydown.escape.window="onEscapePaymentRegister()"
	@endif
>
	@if (session('status') && ! session('flash_swal_success'))
		<div class="rounded-lg bg-emerald-50 p-3 text-sm text-emerald-700">{{ session('status') }}</div>
	@endif

	@if (session('flash_swal_success'))
		<script>
			document.addEventListener('DOMContentLoaded', () => {
				const text = @json(session('flash_swal_success'));
				if (window.Swal && text) {
					window.Swal.fire({
						icon: 'success',
						title: @json(__('Payment recorded')),
						text: text,
						confirmButtonText: @json(__('OK')),
					});
				}
			});
		</script>
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

	<form method="get" action="{{ route('payment-register.index') }}" class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
		<input type="hidden" name="invoice" value="{{ request('invoice') }}">
		<h2 class="text-sm font-semibold text-slate-800">Find invoices</h2>
		<p class="mt-1 text-xs text-slate-500">Search by invoice number, customer phone, or customer code. Optionally narrow by customer (invoice holder), sale type, or the staff member who created the invoice.</p>
		<div class="mt-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-5">
			<div>
				<label for="invoice_q" class="text-xs font-medium text-slate-600">Invoice # contains</label>
				<input
					id="invoice_q"
					type="search"
					name="invoice_q"
					value="{{ $filters['invoice_q'] }}"
					class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm"
					placeholder="e.g. INV-"
				>
			</div>
			<div>
				<label for="customer_contact_q" class="text-xs font-medium text-slate-600">Customer phone / code contains</label>
				<input
					id="customer_contact_q"
					type="search"
					name="customer_contact_q"
					value="{{ $filters['customer_contact_q'] }}"
					class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm"
					placeholder="e.g. 017xxxx or C-101"
				>
			</div>
			<div>
				<label for="customer_id" class="text-xs font-medium text-slate-600">Customer</label>
				<div class="mt-1">
					<x-searchable-select name="customer_id" :options="$customerOptions" :selected="$filters['customer_id'] ?? ''" placeholder="Any customer" />
				</div>
			</div>
			<div>
				<label for="sale_type" class="text-xs font-medium text-slate-600">Sale type</label>
				<select id="sale_type" name="sale_type" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
					<option value="">Any</option>
					@foreach ($saleTypeLabels as $value => $label)
						<option value="{{ $value }}" @selected($filters['sale_type'] === $value)>{{ $label }}</option>
					@endforeach
				</select>
			</div>
			<div>
				<label for="created_by" class="text-xs font-medium text-slate-600">Recorded by (creator)</label>
				<select id="created_by" name="created_by" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
					<option value="">Anyone</option>
					@foreach ($creators as $u)
						<option value="{{ $u->id }}" @selected((string) $filters['created_by'] === (string) $u->id)>{{ $u->displayName() }}</option>
					@endforeach
				</select>
			</div>
		</div>
		<div class="mt-3 flex flex-wrap gap-2">
			<button type="submit" class="rounded-lg bg-slate-900 px-4 py-2 text-sm font-medium text-white hover:bg-slate-800">Search</button>
			<a href="{{ route('payment-register.index', array_filter(['invoice' => request('invoice')])) }}" class="rounded-lg border border-slate-300 bg-white px-4 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50">Reset filters</a>
		</div>
	</form>

	<div class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm sm:p-5">
		<h2 class="text-sm font-semibold text-slate-800">Open balances</h2>
		<p class="mt-1 text-xs text-slate-500">Posted invoices with amount due — cash, credit, or installment. Choose <strong>Collect</strong> to record a payment in the slide-over panel.</p>
		<div class="mt-4 overflow-x-auto">
			<table class="min-w-full divide-y divide-slate-200 text-sm">
				<thead>
					<tr class="text-left text-xs font-medium uppercase tracking-wide text-slate-500">
						<th class="pb-2 pr-4">Invoice</th>
						<th class="pb-2 pr-4">Customer</th>
						<th class="pb-2 pr-4">Branch</th>
						<th class="pb-2 pr-4">Type</th>
						<th class="pb-2 pr-4">Referrer</th>
						<th class="pb-2 pr-4">Due</th>
						<th class="pb-2"></th>
					</tr>
				</thead>
				<tbody class="divide-y divide-slate-100">
					@forelse ($invoices as $inv)
						<tr @class(['bg-indigo-50/80' => $selected && $selected->id === $inv->id])>
							<td class="py-3 pr-4 font-mono text-xs font-semibold">
								<a
									href="{{ route('sales.invoices.show', $inv) }}"
									target="_blank"
									rel="noopener noreferrer"
									title="Open invoice in a new tab"
									class="text-indigo-600 hover:text-indigo-800"
								>{{ $inv->invoice_number }}</a>
							</td>
							<td class="py-3 pr-4 text-slate-700">
								<div>{{ $inv->customer?->name ?? '—' }}</div>
								@php
									$customerPhones = $inv->customer?->normalizedPhoneNumbers() ?? [];
								@endphp
								@if ($customerPhones !== [])
									<div class="mt-0.5 text-xs text-slate-500">{{ implode(', ', $customerPhones) }}</div>
								@endif
							</td>
							<td class="py-3 pr-4 text-slate-600">{{ $inv->branch?->name ?? '—' }}</td>
							<td class="py-3 pr-4">
								@php
									$typeBadge = match($inv->sale_type) {
										'default'     => 'bg-slate-100 text-slate-600',
										'credit'      => 'bg-blue-100 text-blue-700',
										'installment' => 'bg-violet-100 text-violet-700',
										default       => 'bg-slate-100 text-slate-600',
									};
								@endphp
								<span class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium {{ $typeBadge }}">
									{{ $saleTypeLabels[$inv->sale_type] ?? ucfirst($inv->sale_type) }}
								</span>
							</td>
							<td class="py-3 pr-4 text-slate-600">
								@if ($inv->referrer)
									{{ $inv->referrer->displayLabel() }}
								@else
									<span class="text-slate-400">—</span>
								@endif
							</td>
							<td class="py-3 pr-4 font-semibold text-rose-700">{{ number_format($inv->amount_due, 2) }}</td>
							<td class="py-3">
								<a
									href="{{ route('payment-register.index', array_merge(request()->except('page'), ['invoice' => $inv->id])) }}"
									class="rounded-lg bg-slate-900 px-3 py-1.5 text-xs font-medium text-white hover:bg-slate-800"
								>Collect</a>
							</td>
						</tr>
					@empty
						<tr>
							<td colspan="7" class="py-8 text-center text-sm text-slate-500">No matching invoices with a balance.</td>
						</tr>
					@endforelse
				</tbody>
			</table>
		</div>
		<div class="mt-4">{{ $invoices->links() }}</div>
	</div>

	@if ($selected)
		<div
			x-show="paySheetOpen"
			x-cloak
			class="fixed inset-0 z-[100] flex justify-end"
			role="dialog"
			aria-modal="true"
			aria-labelledby="payment-register-sheet-title"
		>
			<div class="absolute inset-0 bg-slate-900/40" @click="closePaySheet()" aria-hidden="true"></div>
			<div
				class="relative flex h-full w-full max-w-lg flex-col bg-white shadow-2xl ring-1 ring-slate-900/10"
				@click.stop
			>
				<div class="flex items-start justify-between gap-3 border-b border-slate-200 px-4 py-4 sm:px-5">
					<div class="min-w-0">
						<div class="flex flex-wrap items-center gap-2">
							<button
								type="button"
								x-show="confirmOpen"
								x-cloak
								@click="closeConfirmModal()"
								class="inline-flex items-center gap-1 rounded-lg px-1 py-0.5 text-xs font-medium text-slate-600 hover:bg-slate-100 hover:text-slate-900"
							>
								<svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M10.5 19.5 3 12m0 0 7.5-7.5M3 12h18" /></svg>
								Back
							</button>
							<span class="inline-flex rounded-full px-2 py-0.5 text-[11px] font-semibold uppercase tracking-wide ring-1" :class="confirmOpen ? 'bg-emerald-50 text-emerald-800 ring-emerald-200' : 'bg-slate-100 text-slate-600 ring-slate-200'" x-text="confirmOpen ? 'Step 2 of 2' : 'Step 1 of 2'">Step 1 of 2</span>
						</div>
						<h2 id="payment-register-sheet-title" class="mt-1 text-base font-semibold text-slate-900" x-text="confirmOpen ? 'Confirm payment' : 'Record payment'">Record payment</h2>
						<p class="mt-1 text-xs text-slate-500">
							<a
								href="{{ route('sales.invoices.show', $selected) }}"
								target="_blank"
								rel="noopener noreferrer"
								title="Open invoice in a new tab"
								class="font-mono font-semibold text-indigo-600 hover:text-indigo-800"
							>{{ $selected->invoice_number }}</a>
							· {{ $selected->customer?->name }}
							@if ($selected->referrer)
								· Ref: {{ $selected->referrer->displayLabel() }}
							@endif
							<span x-show="!confirmOpen">· Due {{ number_format($selected->amount_due, 2) }}</span>
							<span x-show="confirmOpen" x-cloak>· After payment: <span class="font-semibold tabular-nums text-emerald-700" x-text="dueAfterPayment().toFixed(2)">0.00</span> due</span>
						</p>
					</div>
					<a
						x-show="!confirmOpen"
						href="{{ $closeSheetHref }}"
						class="shrink-0 rounded-lg p-2 text-slate-500 hover:bg-slate-100 hover:text-slate-800"
						aria-label="Close"
					>
						<svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12" /></svg>
					</a>
				</div>

				<div x-show="!confirmOpen" class="min-h-0 flex-1 overflow-y-auto px-4 py-4 sm:px-5">
					@if ($selected->sale_type === 'installment' && $selected->installmentPlan)
						<div class="mb-4 rounded-lg border border-slate-200 bg-slate-50 p-3 text-xs text-slate-700">
							<p class="font-semibold text-slate-800">Installment allocation</p>
							<p class="mt-1 leading-relaxed"><strong>Next due</strong> applies cash + discount across installments in order. <strong>Specific installment</strong>{{ $allowsFlexiblePaymentCollection ? ' lets admins choose any installment and enter any amount' : ' caps settlement to that installment row when earlier installments are fully settled' }}.</p>
						</div>
					@endif

					@if ($requiresFullPayment)
						<div class="mb-4 rounded-lg border border-blue-200 bg-blue-50 p-3 text-xs text-blue-900">
							<p class="font-semibold">Cash sale — full payment required</p>
							<p class="mt-1 leading-relaxed">This invoice is a cash sale. You must collect the full outstanding balance in one payment (cash plus any settlement discount must equal the amount due).</p>
						</div>
					@endif

					<form action="{{ route('payment-register.payments.store', $selected) }}" method="post" class="space-y-4" id="payment-register-payment-form" x-ref="paymentRegisterForm" x-init="syncFullPaymentAmount()">
						@csrf

						@if ($selected->sale_type === 'installment' && $selected->installmentPlan)
							<fieldset class="space-y-2">
								<legend class="text-xs font-medium text-slate-600">How should this payment apply?</legend>
								<label class="flex cursor-pointer items-start gap-2 rounded-lg border border-slate-200 p-3 has-[:checked]:border-slate-800 has-[:checked]:bg-slate-50">
									<input type="radio" name="installment_target" value="waterfall" class="mt-0.5" x-model="installmentTarget" @change="onTargetChange('')">
									<span>
										<span class="text-sm font-medium text-slate-900">Next due (automatic)</span>
										<span class="mt-0.5 block text-xs text-slate-500">Recommended — clears earliest unpaid installments first.</span>
									</span>
								</label>
								<label class="flex cursor-pointer items-start gap-2 rounded-lg border border-slate-200 p-3 has-[:checked]:border-slate-800 has-[:checked]:bg-slate-50">
									<input type="radio" name="installment_target" value="schedule" class="mt-0.5" x-model="installmentTarget" @change="onTargetChange(scheduleId)" @if ($targetableSchedules->isEmpty()) disabled @endif>
									<span>
										<span class="text-sm font-medium text-slate-900">Specific installment</span>
										<span class="mt-0.5 block text-xs text-slate-500">{{ $allowsFlexiblePaymentCollection ? 'Choose any installment row, including ones already marked paid.' : 'Only when earlier installments are fully settled.' }}</span>
									</span>
								</label>
							</fieldset>

							<div x-show="installmentTarget === 'schedule'" class="space-y-1.5">
								<label for="installment_schedule_id" class="text-xs font-medium text-slate-600">Installment</label>
								<select
									id="installment_schedule_id"
									name="installment_schedule_id"
									class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm"
									x-model="scheduleId"
									@change="onTargetChange(scheduleId)"
									:required="installmentTarget === 'schedule'"
								>
									<option value="">Select…</option>
									@foreach ($targetableSchedules as $ts)
										<option value="{{ $ts->id }}">#{{ $ts->installment_no }} — due {{ $ts->due_date?->toDateString() }} — remaining {{ number_format($ts->remainingDue(), 2) }}{{ $ts->isFullyPaid() ? ' (paid)' : '' }}</option>
									@endforeach
								</select>
							</div>
						@else
							<input type="hidden" name="installment_target" value="waterfall">
						@endif

						<div class="rounded-xl border-2 border-slate-200 bg-gradient-to-b from-slate-50 to-white p-4 shadow-sm">
							<label for="reg-pay-amount" class="block text-sm font-semibold uppercase tracking-wide text-slate-600">Collected amount</label>
							<p class="mt-1 text-xs text-slate-500" x-show="!requiresFullPayment">Cash or tender collected now (large field for quick entry).</p>
							<p class="mt-1 text-xs text-blue-800" x-show="requiresFullPayment" x-cloak>Fixed to full balance minus any settlement discount.</p>
							<input
								id="reg-pay-amount"
								type="number"
								name="amount"
								x-model="payAmount"
								@change="if (requiresFullPayment) { syncFullPaymentAmount(); } else if ((Number(payDiscount)||0) > maxDiscountNow()+0.0001) { payDiscount = maxDiscountNow(); }"
								@input="if (requiresFullPayment) syncFullPaymentAmount()"
								min="0"
								step="0.01"
								inputmode="decimal"
								autocomplete="off"
								:readonly="requiresFullPayment"
								:class="requiresFullPayment ? 'mt-3 w-full min-h-[4rem] rounded-xl border-2 border-slate-200 bg-slate-100 px-4 py-4 text-3xl font-semibold tabular-nums tracking-tight text-slate-700 shadow-inner cursor-not-allowed' : 'mt-3 w-full min-h-[4rem] rounded-xl border-2 border-slate-300 bg-white px-4 py-4 text-3xl font-semibold tabular-nums tracking-tight text-slate-900 shadow-inner placeholder:text-slate-300 focus:border-indigo-500 focus:outline-none focus:ring-4 focus:ring-indigo-500/20'"
								placeholder="{{ number_format($defaultPayAmountValue, 2, '.', '') }}"
								required
							>
						</div>

						@if ($canApplySettlementDiscount)
							<input type="hidden" name="settlement_discount" x-bind:value="payDiscount">
							<div class="rounded-xl border border-indigo-100 bg-indigo-50/50 p-1">
								<button
									type="button"
									class="flex w-full flex-col items-stretch gap-1 rounded-lg px-4 py-4 text-left transition hover:bg-indigo-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-400"
									@click="openDiscountSheet()"
								>
									<span class="text-sm font-semibold uppercase tracking-wide text-indigo-900">Settlement discount</span>
									<span class="text-3xl font-bold tabular-nums text-indigo-950" x-text="Number(payDiscount).toFixed(2)">0.00</span>
									<span class="text-xs font-medium text-indigo-700">Tap to set — reduces balance without extra cash</span>
								</button>
							</div>
						@endif

						<div class="rounded-xl border border-slate-200 bg-slate-900 px-4 py-4 text-center text-white shadow-md">
							<p class="text-xs font-semibold uppercase tracking-[0.12em] text-white/70">Total applying to invoice</p>
							<p class="mt-2 text-4xl font-bold tabular-nums tracking-tight" x-text="totalApplied().toFixed(2)">0.00</p>
							<p class="mt-2 text-sm text-white/80">of {{ number_format($amountDueValue, 2) }} due</p>
						</div>

						<div class="grid grid-cols-2 gap-3">
							<div class="rounded-xl border border-slate-200 bg-white p-3">
								<p class="text-xs font-semibold uppercase tracking-wide text-slate-500">Due now</p>
								<p class="mt-1 text-xl font-bold tabular-nums text-rose-700">{{ number_format($amountDueSnap, 2) }}</p>
							</div>
							<div class="rounded-xl border border-emerald-200 bg-emerald-50 p-3">
								<p class="text-xs font-semibold uppercase tracking-wide text-emerald-800">Due after payment</p>
								<p class="mt-1 text-xl font-bold tabular-nums text-emerald-900" x-text="dueAfterPayment().toFixed(2)">0.00</p>
							</div>
						</div>

						<div>
							<label for="reg-pay-method" class="text-xs font-medium text-slate-600">Payment method</label>
							@if ($paymentMethods->isEmpty())
								<p class="mt-1 rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-xs text-amber-900">
									No payment methods mapped to a wallet for {{ $selected->branch?->name ?? 'this branch' }}. Configure mappings under Payment → wallet routing.
								</p>
							@else
								<select id="reg-pay-method" name="payment_method_id" x-model="paymentMethodId" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm" required>
									@foreach ($paymentMethods as $method)
										<option
											value="{{ $method->id }}"
											@selected(old('payment_method_id') !== null ? (int) old('payment_method_id') === $method->id : $defaultPaymentMethodId === $method->id)
										>{{ $method->name }} ({{ ucfirst(str_replace('_', ' ', $method->type)) }})</option>
									@endforeach
								</select>
							@endif
						</div>

						<div>
							<label for="reg-pay-ref" class="text-xs font-medium text-slate-600">Reference</label>
							<input id="reg-pay-ref" type="text" name="reference" value="{{ old('reference') }}" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm" placeholder="Optional transaction reference">
						</div>

						<div>
							<label for="reg-pay-referrer" class="text-xs font-medium text-slate-600">Referrer employee <span class="text-rose-500">*</span></label>
							<x-searchable-select
								name="referrer_employee_id"
								:options="$referrerEmployeeOptions"
								:selected="$selectedReferrerEmployeeId"
								placeholder="Search employee by name or code…"
								:clearable="false"
							/>
							<p class="mt-1 text-xs text-slate-500">HR employee who referred or collected this payment. Select the employee for this specific transaction.</p>
						</div>

					@php
						$canBackdatePayment = auth()->check() && auth()->user()?->can('backdate-sales-invoice');
						$paymentDateMin = $canBackdatePayment ? null : now()->subDays(2)->format('Y-m-d');
					@endphp
					<div>
						<label for="reg-pay-date" class="text-xs font-medium text-slate-600">Payment date</label>
						<input
							id="reg-pay-date"
							type="date"
							name="payment_date"
							x-model="paymentDate"
							@if($paymentDateMin) min="{{ $paymentDateMin }}" @endif
							max="{{ now()->format('Y-m-d') }}"
							required
							class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm tabular-nums"
						>
					</div>

						<label class="flex items-center gap-2 text-xs text-slate-600">
							<input type="checkbox" name="print_after" value="1" @checked(old('print_after'))>
							Print invoice after payment
						</label>

						@if ($paymentMethods->isNotEmpty())
							<button type="button" @click="openConfirmModal()" class="w-full rounded-xl bg-slate-900 px-4 py-4 text-base font-semibold text-white shadow-sm hover:bg-slate-800">Review payment</button>
						@endif

						<a
							href="{{ route('sales.invoices.show', $selected) }}"
							target="_blank"
							rel="noopener noreferrer"
							title="Opens in a new tab"
							class="block text-center text-xs font-medium text-indigo-600 hover:text-indigo-800"
						>Open invoice details</a>
					</form>
				</div>

				<div
					x-show="confirmOpen"
					x-cloak
					class="flex min-h-0 flex-1 flex-col bg-white"
					role="region"
					aria-labelledby="payment-confirm-dialog-title"
				>
					<div class="min-h-0 flex-1 space-y-4 overflow-y-auto px-4 py-4 sm:px-5">
						<div class="overflow-hidden rounded-2xl border border-slate-200 bg-gradient-to-br from-slate-50 via-white to-emerald-50/40 shadow-sm">
							<div class="grid grid-cols-[1fr_auto_1fr] items-end gap-2 px-4 pt-4 sm:gap-3">
								<div>
									<p class="text-[11px] font-semibold uppercase tracking-wide text-slate-500">Due now</p>
									<p class="mt-1 text-2xl font-bold tabular-nums text-rose-700 sm:text-3xl">{{ number_format($amountDueSnap, 2) }}</p>
								</div>
								<div class="pb-1 text-center text-slate-400">
									<svg xmlns="http://www.w3.org/2000/svg" class="mx-auto h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M17.25 8.25 21 12m0 0-3.75 3.75M21 12H3" /></svg>
								</div>
								<div class="text-right">
									<p class="text-[11px] font-semibold uppercase tracking-wide text-emerald-700">Due after</p>
									<p class="mt-1 text-2xl font-bold tabular-nums text-emerald-800 sm:text-3xl" x-text="dueAfterPayment().toFixed(2)">0.00</p>
								</div>
							</div>

							<div class="mt-3 border-t border-slate-200/80 px-4 py-3">
								<div class="flex items-center justify-between gap-3 text-sm">
									<span class="text-slate-500">Applying now</span>
									<span class="font-semibold tabular-nums text-slate-900">− <span x-text="totalApplied().toFixed(2)">0.00</span></span>
								</div>
								<div class="mt-2 flex items-center justify-between gap-3">
									<span class="text-sm text-slate-500">Status after payment</span>
									<span
										class="inline-flex rounded-full px-2.5 py-1 text-xs font-semibold"
										:class="dueAfterPayment() <= 0.001 ? 'bg-emerald-100 text-emerald-800 ring-1 ring-emerald-200' : 'bg-amber-100 text-amber-900 ring-1 ring-amber-200'"
										x-text="paymentStatusAfter()"
									>Still due</span>
								</div>
							</div>
						</div>

						<div class="grid gap-3 sm:grid-cols-2">
							<div class="rounded-xl border border-slate-200 bg-white p-4">
								<p class="text-[11px] font-semibold uppercase tracking-wide text-slate-500">Collected cash</p>
								<p class="mt-2 text-2xl font-bold tabular-nums text-slate-900" x-text="(Number(payAmount)||0).toFixed(2)">{{ number_format($payAmountInitial, 2) }}</p>
							</div>
							<div class="rounded-xl border border-indigo-100 bg-indigo-50 p-4">
								<p class="text-[11px] font-semibold uppercase tracking-wide text-indigo-800">Settlement discount</p>
								<p class="mt-2 text-2xl font-bold tabular-nums text-indigo-950" x-text="(Number(payDiscount)||0).toFixed(2)">{{ number_format($payDiscountInitial, 2) }}</p>
							</div>
						</div>

						<dl class="divide-y divide-slate-100 overflow-hidden rounded-xl border border-slate-200 bg-white text-sm">
							<div class="flex justify-between gap-3 px-4 py-3">
								<dt class="font-medium text-slate-700">Total applying</dt>
								<dd class="font-bold tabular-nums text-slate-900" x-text="totalApplied().toFixed(2)">0.00</dd>
							</div>
							<div class="flex justify-between gap-3 px-4 py-3">
								<dt class="text-slate-500">Payment method</dt>
								<dd class="text-right font-semibold text-slate-900" x-text="selectedPaymentMethodLabel()">—</dd>
							</div>
							<div class="flex justify-between gap-3 px-4 py-3">
								<dt class="text-slate-500">Referrer employee</dt>
								<dd class="text-right font-semibold text-slate-900" x-text="selectedReferrerLabel">—</dd>
							</div>
							<div class="flex justify-between gap-3 px-4 py-3">
								<dt class="text-slate-500">Payment date</dt>
								<dd class="font-semibold tabular-nums text-slate-900" x-text="paymentDate"></dd>
							</div>
							<div class="flex justify-between gap-3 px-4 py-3">
								<dt class="text-slate-500">Already paid on invoice</dt>
								<dd class="font-semibold tabular-nums text-emerald-800">{{ number_format($cashCollectedSnap, 2) }}</dd>
							</div>
							@if ($settlementDiscountSnap > 0.009)
								<div class="flex justify-between gap-3 px-4 py-3">
									<dt class="text-slate-500">Settlement discount applied</dt>
									<dd class="font-semibold tabular-nums text-indigo-900">{{ number_format($settlementDiscountSnap, 2) }}</dd>
								</div>
							@endif
							<div class="flex justify-between gap-3 px-4 py-3">
								<dt class="text-slate-500">Invoice grand total</dt>
								<dd class="font-semibold tabular-nums text-slate-900">{{ number_format($grandTotalSnap, 2) }}</dd>
							</div>
						</dl>

						<div>
							<p class="text-[11px] font-semibold uppercase tracking-wide text-slate-500">Transaction history</p>
							<div class="mt-2 max-h-56 overflow-y-auto rounded-xl border border-slate-200 text-xs">
								@if ($selected->debtWriteoffs->isNotEmpty() || $selected->payments->isNotEmpty())
									<table class="min-w-full text-left">
										<thead class="sticky top-0 bg-slate-50 text-[10px] uppercase tracking-wide text-slate-500">
											<tr>
												<th class="px-2 py-2 font-medium">Date</th>
												<th class="px-2 py-2 font-medium">Method</th>
												<th class="px-2 py-2 font-medium">Reference</th>
												<th class="px-2 py-2 font-medium">Ref employee</th>
												<th class="px-2 py-2 font-medium text-right">Amount</th>
											</tr>
										</thead>
										<tbody>
											@foreach ($selected->debtWriteoffs as $wo)
												<tr class="border-b border-slate-100 bg-amber-50/60">
													<td class="px-2 py-2 text-slate-600">{{ $wo->created_at?->toDateString() }}</td>
													<td class="px-2 py-2 font-medium text-amber-900">Write-off</td>
													<td class="px-2 py-2 text-slate-500">—</td>
													<td class="px-2 py-2 text-slate-500">—</td>
													<td class="px-2 py-2 text-right tabular-nums font-medium">{{ number_format((float) $wo->amount, 2) }}</td>
												</tr>
											@endforeach
											@foreach ($selected->payments as $payment)
												@php
													$pivotCash = round((float) $payment->pivot->allocated_amount, 2);
													$pivotDisc = round((float) ($payment->pivot->settlement_discount ?? 0), 2);
													$paymentReference = trim((string) ($payment->reference_no ?? ''));
													$paymentReferrer = $payment->referrerEmployee?->displayLabel();
												@endphp
												@if ($pivotCash > 0.009)
													<tr class="border-b border-slate-100 last:border-0">
														<td class="px-2 py-2 text-slate-600">{{ $payment->payment_date?->toDateString() }}</td>
														<td class="px-2 py-2">{{ $payment->paymentMethodRef?->name ?? ucfirst(str_replace('_', ' ', $payment->payment_method)) }}</td>
														<td class="px-2 py-2 text-slate-600">{{ $paymentReference !== '' ? $paymentReference : '—' }}</td>
														<td class="px-2 py-2 text-slate-600">{{ $paymentReferrer ?: '—' }}</td>
														<td class="px-2 py-2 text-right tabular-nums font-medium text-emerald-700">{{ number_format($pivotCash, 2) }}</td>
													</tr>
												@endif
												@if ($pivotDisc > 0.009)
													<tr class="border-b border-slate-100 bg-indigo-50/40 last:border-0">
														<td class="px-2 py-2 text-slate-600">{{ $payment->payment_date?->toDateString() }}</td>
														<td class="px-2 py-2 font-medium text-indigo-900">Settlement discount</td>
														<td class="px-2 py-2 text-slate-500">—</td>
														<td class="px-2 py-2 text-slate-500">—</td>
														<td class="px-2 py-2 text-right tabular-nums font-medium text-indigo-900">{{ number_format($pivotDisc, 2) }}</td>
													</tr>
												@endif
											@endforeach
										</tbody>
									</table>
								@else
									<p class="px-3 py-6 text-center text-slate-500">No prior payments recorded for this invoice.</p>
								@endif
							</div>
						</div>
						<label class="flex cursor-pointer items-start gap-2 rounded-xl border border-slate-200 bg-white p-3 text-sm text-slate-700">
							<input type="checkbox" x-model="confirmAck" class="mt-0.5 h-4 w-4 rounded border-slate-300 text-indigo-600 focus:ring-indigo-500">
							<span id="payment-confirm-dialog-title">Yes — I reviewed the amounts above and confirm this registration is correct.</span>
						</label>
					</div>

					<div class="border-t border-slate-200 bg-white px-4 py-3 sm:px-5">
						<div class="mb-2 rounded-xl bg-slate-50 px-3 py-2 text-center text-xs text-slate-600 sm:text-sm">
							<span x-show="dueAfterPayment() > 0.001" x-cloak>
								<span class="font-semibold tabular-nums text-amber-900" x-text="dueAfterPayment().toFixed(2)"></span>
								will remain due after this payment.
							</span>
							<span x-show="dueAfterPayment() <= 0.001" x-cloak class="font-semibold text-emerald-700">Invoice will be paid in full after this payment.</span>
						</div>
						<div class="flex flex-col gap-2 sm:flex-row">
							<button type="button" @click="closeConfirmModal()" class="rounded-xl border border-slate-300 bg-white px-4 py-3 text-sm font-medium text-slate-800 hover:bg-slate-50 sm:flex-1">Back to edit</button>
							<button
								type="submit"
								form="payment-register-payment-form"
								:disabled="!confirmAck"
								:class="confirmAck ? 'rounded-xl bg-emerald-600 px-4 py-3 text-sm font-semibold text-white shadow-sm hover:bg-emerald-700 sm:flex-1' : 'cursor-not-allowed rounded-xl bg-slate-300 px-4 py-3 text-sm font-semibold text-slate-500 sm:flex-1'"
							>
								Register payment
							</button>
						</div>
					</div>
				</div>

				@if ($canApplySettlementDiscount)
					<div
						x-show="discountSheetOpen"
						x-cloak
						class="absolute inset-0 z-[30] flex flex-col bg-white"
						role="dialog"
						aria-modal="true"
						aria-labelledby="discount-sheet-title"
					>
						<div class="flex items-start justify-between gap-3 border-b border-slate-200 px-5 py-4">
							<div>
								<h2 id="discount-sheet-title" class="text-lg font-semibold text-slate-900">Settlement discount</h2>
								<p class="mt-1 text-sm text-slate-500">Discount applied with this payment. Does not increase cash-in-hand.</p>
							</div>
							<button type="button" class="rounded-lg p-2 text-slate-500 hover:bg-slate-100 hover:text-slate-800" @click="closeDiscountSheet()" aria-label="Close">
								<svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12" /></svg>
							</button>
						</div>
						<div class="flex min-h-0 flex-1 flex-col gap-4 overflow-y-auto px-5 py-5">
							<div class="rounded-xl border-2 border-indigo-200 bg-indigo-50/60 p-4">
								<label for="discount-draft-input" class="block text-sm font-semibold uppercase tracking-wide text-indigo-950">Discount amount</label>
								<p class="mt-1 text-xs text-indigo-800/90">Max with current collected amount: <span class="font-semibold tabular-nums" x-text="maxDiscountNow().toFixed(2)">0.00</span></p>
								<input
									id="discount-draft-input"
									type="number"
									x-model="discountDraft"
									min="0"
									:max="maxDiscountNow()"
									step="0.01"
									inputmode="decimal"
									autocomplete="off"
									class="mt-3 w-full min-h-[4.5rem] rounded-xl border-2 border-indigo-300 bg-white px-4 py-4 text-4xl font-bold tabular-nums tracking-tight text-indigo-950 shadow-inner focus:border-indigo-500 focus:outline-none focus:ring-4 focus:ring-indigo-500/25"
								>
							</div>
							<p class="text-sm text-slate-600">
								Collected <span class="font-semibold tabular-nums" x-text="(Number(payAmount)||0).toFixed(2)">0.00</span>
								+ discount <span class="font-semibold tabular-nums" x-text="(Number(discountDraft)||0).toFixed(2)">0.00</span>
								= <span class="font-semibold tabular-nums" x-text="(Math.round(((Number(payAmount)||0)+(Number(discountDraft)||0))*100)/100).toFixed(2)">0.00</span>
								<span class="text-slate-400"> / {{ number_format($amountDueValue, 2) }} due</span>
							</p>
							<div class="mt-auto flex flex-col gap-2 border-t border-slate-100 pt-4">
								<button type="button" class="w-full rounded-xl bg-indigo-600 px-4 py-4 text-lg font-semibold text-white shadow-sm hover:bg-indigo-700" @click="applyDiscountSheet()">
									Apply discount
								</button>
								<button type="button" class="w-full rounded-xl border border-slate-300 bg-white px-4 py-3 text-base font-medium text-slate-700 hover:bg-slate-50" @click="clearDiscountSheet()">
									Clear discount
								</button>
								<button type="button" class="w-full rounded-xl py-3 text-base font-medium text-slate-600 hover:bg-slate-50" @click="closeDiscountSheet()">
									Cancel
								</button>
							</div>
						</div>
					</div>
				@endif
			</div>
		</div>
	@endif
</div>
@endsection
