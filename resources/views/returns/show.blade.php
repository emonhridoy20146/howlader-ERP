@extends('layouts.app')
@section('title', 'Return '.$return->return_number)
@section('page-title', 'Return '.$return->return_number)
@php
	$selectedSerialIds = $return->returnLines->pluck('serial_item_id')->filter()->values()->all();
	$qtyByLineId = $return->returnLines->whereNull('serial_item_id')->keyBy('sales_invoice_line_id');

	$refundLineValueBase = round((float) ($refundLineValueBase ?? 0), 2);
	$refundSettlementBase = round((float) ($refundSettlementBase ?? $refundLineValueBase), 2);
	$refundableCashRemaining = round((float) ($refundableCashRemaining ?? $refundSettlementBase), 2);
	$maxRefundPayment = min($return->refundAmountPayable(), $refundableCashRemaining);
	$refundFlatAmountInitial = old('refund_flat_amount', $refundSettlementBase);
@endphp
@section('content')
<div
	class="mx-auto max-w-4xl space-y-6"
	x-data="{
		resolveOpen: {{ ($errors->has('settlement_mode') || $errors->has('pay_now_amount') || $errors->has('wallet_id') || $errors->has('resolution_notes') || $errors->has('refund_flat_amount') || $errors->has('refund_percentage') || $errors->has('refund_amount_mode')) ? 'true' : 'false' }},
		payOpen: {{ ($errors->has('pay_amount') || $errors->has('wallet_id')) ? 'true' : 'false' }},
		baseAmount: {{ $refundSettlementBase }},
		lineValueBase: {{ $refundLineValueBase }},
		cashRemaining: {{ $refundableCashRemaining }},
		settlementMode: '{{ old('settlement_mode', 'full') }}',
		refundAmountMode: '{{ old('refund_amount_mode', 'percent') }}',
		refundPercentage: {{ old('refund_percentage', '100') }},
		refundFlatAmount: {{ $refundFlatAmountInitial }},
		payNowAmount: {{ old('pay_now_amount', null) !== null ? old('pay_now_amount') : 'null' }},
		init() {
			if (this.payNowAmount === null) {
				this.syncPayNow();
				if (this.payNowAmount === null) {
					this.payNowAmount = 0;
				}
			}
			this.$watch('settlementMode', () => this.syncPayNow());
			this.$watch('refundPercentage', () => this.syncPayNow());
			this.$watch('refundFlatAmount', () => {
				const max = parseFloat(this.baseAmount) || 0;
				let flat = parseFloat(this.refundFlatAmount) || 0;
				if (flat > max) {
					this.refundFlatAmount = max;
				}
				this.syncPayNow();
			});
			this.$watch('refundAmountMode', () => this.syncPayNow());
			this.$watch('payNowAmount', () => {
				const max = this.maxPayNow;
				if (parseFloat(this.payNowAmount) > max) {
					this.payNowAmount = max;
				}
			});
		},
		get maxPayNow() {
			return Math.min(this.settlementAmount, parseFloat(this.cashRemaining) || 0);
		},
		get settlementAmount() {
			let base = parseFloat(this.baseAmount) || 0;
			if (this.refundAmountMode === 'flat') {
				let flat = parseFloat(this.refundFlatAmount) || 0;
				if (flat > base) {
					flat = base;
				}
				return Math.round(flat * 100) / 100;
			}
			let pct = parseFloat(this.refundPercentage) || 0;
			return Math.round((base * pct / 100) * 100) / 100;
		},
		syncPayNow() {
			if (this.settlementMode === 'full' || this.settlementMode === 'partial_final') {
				this.payNowAmount = this.maxPayNow;
			} else if (this.settlementMode === 'partial_deferred') {
				if (this.payNowAmount > this.maxPayNow) {
					this.payNowAmount = this.maxPayNow;
				}
			}
		}
	}"
>
	<div class="flex flex-wrap items-center justify-between gap-3">
		<a href="{{ route('returns.index') }}" class="text-sm font-medium text-slate-600 hover:text-slate-900">← Back to returns</a>
		<div class="flex flex-wrap items-center gap-2">
			@can('view', $return)
				<a href="{{ route('returns.receipt', $return) }}" target="_blank" rel="noopener" class="rounded-lg border border-slate-300 px-3 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50">Print receipt</a>
			@endcan
			@if ($return->status === 'draft')
				@can('update', $return)
					<a href="{{ route('returns.index', ['open' => $return->id]) }}" class="rounded-lg border border-slate-300 px-3 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50">Edit header</a>
				@endcan
				@can('resolve', $return)
					@if ($return->returnLines->isEmpty())
						<span
							class="cursor-not-allowed rounded-lg bg-slate-200 px-3 py-2 text-sm font-medium text-slate-500"
							title="Select which invoice lines are being returned first"
						>Resolve return</span>
					@else
						<button type="button" @click="resolveOpen = true; payOpen = false" class="rounded-lg bg-emerald-700 px-3 py-2 text-sm font-medium text-white hover:bg-emerald-800">Resolve return</button>
					@endif
				@endcan
			@endif
			@if ($maxRefundPayment > 0)
				@can('payRefund', $return)
					<button type="button" @click="payOpen = true; resolveOpen = false" class="rounded-lg bg-indigo-700 px-3 py-2 text-sm font-medium text-white hover:bg-indigo-800">Pay pending refund</button>
				@endcan
			@endif
			@can('rollback', $return)
				<form action="{{ route('returns.rollback', $return) }}" method="post" class="inline" data-confirm="Roll back this return? Refund wallet entries will be reversed and returned items will be marked sold again on the invoice.">
					@csrf
					<button type="submit" class="rounded-lg border border-rose-300 bg-rose-50 px-3 py-2 text-sm font-medium text-rose-900 hover:bg-rose-100">Rollback return</button>
				</form>
			@endcan
		</div>
	</div>

	@if (session('status'))
		<div class="rounded-lg bg-emerald-50 p-3 text-sm text-emerald-700">{{ session('status') }}</div>
	@endif

	@if ($errors->any())
		<div class="rounded-lg border border-rose-200 bg-rose-50 p-3 text-sm text-rose-800">
			<p class="font-medium">Please fix the errors below.</p>
			<ul class="mt-2 list-inside list-disc text-xs">
				@foreach ($errors->all() as $err)
					<li>{{ $err }}</li>
				@endforeach
			</ul>
		</div>
	@endif

	@if ($return->status === 'draft' && $return->returnLines->isEmpty())
		<div class="rounded-lg border border-amber-200 bg-amber-50 p-3 text-sm text-amber-900">
			No invoice lines are selected on this return yet. Use <span class="font-medium">Edit header</span> to choose which items are being returned, then resolve it.
		</div>
	@endif

	<div class="rounded-2xl bg-white p-5 shadow-sm ring-1 ring-slate-200">
		<h2 class="text-sm font-semibold text-slate-900">Return details</h2>
		<dl class="mt-3 grid gap-3 text-sm sm:grid-cols-2">
			<div><dt class="text-xs font-medium uppercase tracking-wide text-slate-500">Return number</dt><dd class="mt-0.5 font-mono">{{ $return->return_number }}</dd></div>
			<div><dt class="text-xs font-medium uppercase tracking-wide text-slate-500">Status</dt><dd class="mt-0.5">{{ ucfirst($return->status) }}@if($return->isRolledBack()) <span class="text-rose-700">· Rolled back</span>@endif</dd></div>
			<div><dt class="text-xs font-medium uppercase tracking-wide text-slate-500">Return date</dt><dd class="mt-0.5">{{ $return->return_date?->toDateString() ?? '—' }}</dd></div>
			<div><dt class="text-xs font-medium uppercase tracking-wide text-slate-500">Branch</dt><dd class="mt-0.5">{{ $return->branch?->name ?? '—' }}</dd></div>
			<div><dt class="text-xs font-medium uppercase tracking-wide text-slate-500">Customer</dt><dd class="mt-0.5">{{ $return->customer?->name ?? '—' }}</dd></div>
			<div><dt class="text-xs font-medium uppercase tracking-wide text-slate-500">Invoice</dt><dd class="mt-0.5 font-mono text-xs">@if ($return->salesInvoice)<a href="{{ route('sales.invoices.show', $return->salesInvoice) }}" class="text-indigo-700 hover:text-indigo-900">{{ $return->salesInvoice->invoice_number }}</a>@else—@endif</dd></div>
			@if ($return->salesInvoice)
				<div><dt class="text-xs font-medium uppercase tracking-wide text-slate-500">Invoice total</dt><dd class="mt-0.5 tabular-nums">{{ number_format((float) $return->salesInvoice->grand_total, 2) }}</dd></div>
				<div><dt class="text-xs font-medium uppercase tracking-wide text-slate-500">Invoice paid</dt><dd class="mt-0.5 tabular-nums font-medium text-emerald-700">{{ number_format((float) $return->salesInvoice->amount_paid, 2) }}</dd></div>
				<div><dt class="text-xs font-medium uppercase tracking-wide text-slate-500">Invoice due</dt><dd class="mt-0.5 tabular-nums {{ (float) $return->salesInvoice->amount_due > 0 ? 'text-rose-700' : 'text-slate-700' }}">{{ number_format((float) $return->salesInvoice->amount_due, 2) }}</dd></div>
				@if ($return->status === 'draft')
					<div><dt class="text-xs font-medium uppercase tracking-wide text-slate-500">Refundable (paid share)</dt><dd class="mt-0.5 tabular-nums font-medium text-emerald-700">{{ number_format($refundSettlementBase, 2) }}</dd></div>
				@endif
			@endif
		</dl>
		@if ($return->reason)
			<div class="mt-4 border-t border-slate-100 pt-4">
				<p class="text-xs font-medium uppercase tracking-wide text-slate-500">Reason</p>
				<p class="mt-1 text-sm text-slate-700 whitespace-pre-wrap">{{ $return->reason }}</p>
			</div>
		@endif
	</div>

	@if ($return->salesInvoice)
		<div class="rounded-2xl bg-white p-5 shadow-sm ring-1 ring-slate-200">
			<div class="flex flex-wrap items-start justify-between gap-3">
				<h2 class="text-sm font-semibold text-slate-900">Invoice lines</h2>
				<dl class="flex flex-wrap gap-x-4 gap-y-1 text-xs text-slate-600">
					<div><span class="text-slate-500">Total</span> <span class="font-medium tabular-nums text-slate-800">{{ number_format((float) $return->salesInvoice->grand_total, 2) }}</span></div>
					<div><span class="text-slate-500">Paid</span> <span class="font-medium tabular-nums text-emerald-700">{{ number_format((float) $return->salesInvoice->amount_paid, 2) }}</span></div>
					<div><span class="text-slate-500">Due</span> <span class="font-medium tabular-nums {{ (float) $return->salesInvoice->amount_due > 0 ? 'text-rose-700' : 'text-slate-800' }}">{{ number_format((float) $return->salesInvoice->amount_due, 2) }}</span></div>
				</dl>
			</div>
			<div class="mt-3 overflow-x-auto">
				<table class="min-w-full text-sm whitespace-nowrap">
					<thead class="bg-slate-50 border-b border-slate-200 text-left text-xs font-semibold uppercase tracking-wider text-slate-700">
						<tr>
							<th class="px-3 py-2">Product</th>
							<th class="px-3 py-2 text-right">Qty</th>
							<th class="px-3 py-2 text-right">Line total</th>
						</tr>
					</thead>
					<tbody>
						@foreach ($return->salesInvoice->lines as $line)
							<tr class="border-b border-slate-100 transition-colors hover:bg-slate-50/70 last:border-0">
								<td class="px-3 py-2">
									<span class="font-medium text-slate-800">{{ $line->product?->name ?? 'Item' }}</span>
									@if ($line->product?->model_number)
										<span class="text-slate-500"> · {{ $line->product->model_number }}</span>
									@endif
									@php
										$serials = $line->saleLineSerials
											->map(fn ($sl) => $sl->serialItem?->serial_number ?: $sl->serialItem?->manufacturer_serial)
											->filter()
											->values();
									@endphp
									@if ($serials->isNotEmpty())
										<p class="mt-1 text-xs text-slate-500">Serials: {{ $serials->take(8)->implode(', ') }}{{ $serials->count() > 8 ? ' …' : '' }}</p>
									@endif
								</td>
								<td class="px-3 py-2 text-right tabular-nums">{{ $line->qty }}</td>
								<td class="px-3 py-2 text-right tabular-nums">{{ number_format((float) $line->line_total, 2) }}</td>
							</tr>
						@endforeach
					</tbody>
				</table>
			</div>
		</div>
	@endif

	@if ($return->returnLines->isNotEmpty())
		<div class="rounded-2xl border border-slate-200 bg-slate-50/50 p-5 shadow-sm ring-1 ring-slate-100">
			<h2 class="text-sm font-semibold text-slate-900">Items on this return</h2>
			<ul class="mt-2 space-y-1 text-sm text-slate-700">
				@foreach ($return->returnLines as $rl)
					<li>
						@if ($rl->serialItem)
							<span class="font-mono text-xs">{{ $rl->serialItem->serial_number ?: $rl->serialItem->manufacturer_serial }}</span>
							<span class="text-slate-500">— {{ $rl->salesInvoiceLine?->product?->name ?? 'Line' }}</span>
						@else
							<span class="tabular-nums">Qty {{ $rl->qty }}</span>
							<span class="text-slate-500">— {{ $rl->salesInvoiceLine?->product?->name ?? 'Line' }}</span>
						@endif
					</li>
				@endforeach
			</ul>
		</div>
	@endif

	@if ($return->status === 'draft' && $return->salesInvoice)
		@can('update', $return)
			<div class="rounded-2xl bg-white p-5 shadow-sm ring-1 ring-slate-200">
				<h2 class="text-sm font-semibold text-slate-900">Choose what to return</h2>
				<p class="mt-1 text-xs text-slate-500">Pick specific serials or quantities. Refund and restock apply only to these items.</p>
				<form action="{{ route('returns.items', $return) }}" method="post" class="mt-4 space-y-6">
					@csrf
					@method('PUT')
					@foreach ($return->salesInvoice->lines as $line)
						@php
							$isSerialized = $line->isReturnedBySerial();
						@endphp
						<div class="rounded-lg border border-slate-200 p-3">
							<p class="text-sm font-medium text-slate-800">{{ $line->product?->name ?? 'Item' }} @if ($line->product?->model_number)<span class="text-slate-500">· {{ $line->product->model_number }}</span>@endif</p>
							@if ($isSerialized)
								<p class="mt-2 text-xs text-slate-500">Select serial(s) to return:</p>
								<div class="mt-2 flex flex-wrap gap-3">
									@foreach ($line->saleLineSerials as $sls)
										@php $sid = (int) $sls->serial_item_id; @endphp
										<label class="flex cursor-pointer items-center gap-2 text-sm">
											<input
												type="checkbox"
												name="lines[{{ $line->id }}][serials][]"
												value="{{ $sid }}"
												class="rounded border-slate-300"
												@checked(in_array($sid, $selectedSerialIds, true))
											>
											<span class="font-mono text-xs">{{ $sls->serialItem?->serial_number ?: $sls->serialItem?->manufacturer_serial ?: 'SN '.$sid }}</span>
										</label>
									@endforeach
								</div>
							@else
								<label class="mt-2 block text-xs font-medium text-slate-600" for="line_qty_{{ $line->id }}">Quantity to return (max {{ (int) $line->qty }})</label>
								<input
									id="line_qty_{{ $line->id }}"
									type="number"
									name="lines[{{ $line->id }}][qty]"
									min="0"
									max="{{ (int) $line->qty }}"
									value="{{ old('lines.'.$line->id.'.qty', $qtyByLineId->get($line->id)?->qty ?? 0) }}"
									class="mt-1 w-32 rounded-lg border border-slate-300 px-3 py-2 text-sm"
								>
							@endif
						</div>
					@endforeach
					<div class="flex justify-end">
						<button type="submit" class="rounded-lg bg-slate-900 px-4 py-2 text-sm font-medium text-white hover:bg-slate-800">Save return items</button>
					</div>
				</form>
			</div>
		@endcan
	@endif

	@if ((float) $return->refund_settlement_amount > 0 || $return->refund)
		<div class="rounded-2xl bg-white p-5 shadow-sm ring-1 ring-slate-200">
			<h2 class="text-sm font-semibold text-slate-900">Refund settlement</h2>
			<dl class="mt-3 grid gap-3 text-sm sm:grid-cols-2">
				<div><dt class="text-xs font-medium uppercase tracking-wide text-slate-500">Base (selected lines)</dt><dd class="mt-0.5 tabular-nums">{{ number_format((float) $return->refund_base_amount, 2) }}</dd></div>
				<div><dt class="text-xs font-medium uppercase tracking-wide text-slate-500">Equivalent percentage</dt><dd class="mt-0.5 tabular-nums">{{ number_format((float) $return->refund_percentage, 2) }}%</dd></div>
				<div><dt class="text-xs font-medium uppercase tracking-wide text-slate-500">Settlement amount</dt><dd class="mt-0.5 tabular-nums">{{ number_format((float) $return->refund_settlement_amount, 2) }}</dd></div>
				<div><dt class="text-xs font-medium uppercase tracking-wide text-slate-500">Refund status</dt><dd class="mt-0.5">{{ $return->refund_status ? ucfirst($return->refund_status) : '—' }}</dd></div>
				<div><dt class="text-xs font-medium uppercase tracking-wide text-slate-500">Paid</dt><dd class="mt-0.5 tabular-nums">{{ number_format((float) $return->refund_paid_amount, 2) }}</dd></div>
				<div><dt class="text-xs font-medium uppercase tracking-wide text-slate-500">Due</dt><dd class="mt-0.5 tabular-nums">{{ number_format((float) $return->refund_due_amount, 2) }}</dd></div>
			</dl>
			@if ($return->resolution_notes)
				<p class="mt-3 text-xs text-slate-500"><span class="font-medium text-slate-600">Resolution notes:</span> {{ $return->resolution_notes }}</p>
			@endif
			@if ($return->restocked_at)
				<p class="mt-2 text-xs text-slate-500">Restocked at {{ $return->restocked_at->toDateTimeString() }}</p>
			@endif
			@if ($return->isRolledBack())
				<p class="mt-2 text-xs text-rose-700">Rolled back at {{ $return->rolled_back_at?->toDateTimeString() }}@if($return->rollback_notes) · {{ $return->rollback_notes }}@endif</p>
			@endif

			@if ($return->refund && $return->refund->allocations->isNotEmpty())
				<div class="mt-4 border-t border-slate-100 pt-4">
					<p class="text-xs font-medium uppercase tracking-wide text-slate-500">Refund payments</p>
					<ul class="mt-2 space-y-1 text-sm text-slate-700">
						@foreach ($return->refund->allocations as $alloc)
							<li class="tabular-nums">
								{{ number_format((float) $alloc->allocated_amount, 2) }}
								@if ($alloc->payment)
									— {{ $alloc->payment->payment_number }} ({{ $alloc->payment->payment_date?->toDateString() }})
									@if ($alloc->payment->wallet)
										via {{ $alloc->payment->wallet->name }}
									@endif
								@endif
							</li>
						@endforeach
					</ul>
				</div>
			@endif

		</div>
	@endif

	{{-- Resolve offcanvas --}}
	<div
		x-show="resolveOpen"
		x-cloak
		class="fixed inset-0 z-50 flex justify-end"
		role="dialog"
		aria-modal="true"
		aria-labelledby="resolve-sheet-title"
	>
		<div class="absolute inset-0 bg-slate-900/40" @click="resolveOpen = false" aria-hidden="true"></div>
		<div
			class="relative flex h-full w-full max-w-lg flex-col bg-white shadow-2xl ring-1 ring-slate-900/10"
			@click.stop
		>
			<div class="flex items-start justify-between gap-3 border-b border-slate-200 px-4 py-4 sm:px-5">
				<div>
					<h2 id="resolve-sheet-title" class="text-base font-semibold text-emerald-900">Resolve return</h2>
					<p class="mt-1 text-xs text-slate-500">Restock selected items and record how the customer is refunded.</p>
				</div>
				<button type="button" class="rounded-lg p-2 text-slate-500 hover:bg-slate-100 hover:text-slate-800" @click="resolveOpen = false" aria-label="Close">✕</button>
			</div>
			<div class="min-h-0 flex-1 overflow-y-auto px-4 py-4 sm:px-5">
				@can('resolve', $return)
					@if ($return->status === 'draft')
						@if ($return->salesInvoice)
							<div class="mb-4">
								@include('returns._invoice-payment-summary', [
									'invoice' => $return->salesInvoice,
									'refundLineValueBase' => $refundLineValueBase,
									'refundSettlementBase' => $refundSettlementBase,
									'refundableCashRemaining' => $refundableCashRemaining,
									'compact' => true,
								])
							</div>
						@endif
						<form action="{{ route('returns.resolve', $return) }}" method="post" class="space-y-4">
							@csrf
							<div>
								<label for="resolve_settlement_mode" class="text-xs font-medium text-slate-600">Settlement mode</label>
								<select id="resolve_settlement_mode" name="settlement_mode" x-model="settlementMode" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm" required>
									<option value="full">Full settlement (100% of paid share, pay now)</option>
									<option value="partial_deferred">Partial now, remainder due later</option>
									<option value="partial_final">Partial final (pay agreed amount now, close return)</option>
								</select>
							</div>
							<div>
								<span class="text-xs font-medium text-slate-600">Refund amount basis</span>
								<div class="mt-2 flex flex-wrap gap-4 text-sm">
									<label class="inline-flex items-center gap-2">
										<input type="radio" name="refund_amount_mode" value="percent" x-model="refundAmountMode">
										<span>Percentage of paid share</span>
									</label>
									<label class="inline-flex items-center gap-2">
										<input type="radio" name="refund_amount_mode" value="flat" x-model="refundAmountMode">
										<span>Flat amount</span>
									</label>
								</div>
								@error('refund_amount_mode')
									<p class="mt-1 text-xs text-rose-700">{{ $message }}</p>
								@enderror
							</div>
							<div x-show="refundAmountMode === 'percent'" x-cloak>
								<label for="resolve_refund_percentage" class="text-xs font-medium text-slate-600">
									Refund percentage of paid share
									<span class="text-slate-500 font-normal">(max <span x-text="baseAmount.toFixed(2)"></span> · settlement <span x-text="settlementAmount.toFixed(2)"></span>)</span>
								</label>
								<input
									id="resolve_refund_percentage"
									type="number"
									name="refund_percentage"
									min="0"
									max="100"
									step="0.01"
									x-model.number="refundPercentage"
									:required="refundAmountMode === 'percent'"
									:disabled="refundAmountMode !== 'percent'"
									class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm disabled:bg-slate-100 disabled:text-slate-500"
								>
								@error('refund_percentage')
									<p class="mt-1 text-xs text-rose-700">{{ $message }}</p>
								@enderror
							</div>
							<div x-show="refundAmountMode === 'flat'" x-cloak>
								<label for="resolve_refund_flat_amount" class="text-xs font-medium text-slate-600">
									Refund amount
									<span class="text-slate-500 font-normal">(max paid share <span x-text="baseAmount.toFixed(2)"></span> · settlement <span x-text="settlementAmount.toFixed(2)"></span>)</span>
								</label>
								<input
									id="resolve_refund_flat_amount"
									type="number"
									name="refund_flat_amount"
									min="0"
									:max="baseAmount"
									step="0.01"
									x-model.number="refundFlatAmount"
									:required="refundAmountMode === 'flat'"
									:disabled="refundAmountMode !== 'flat'"
									class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm disabled:bg-slate-100 disabled:text-slate-500"
								>
								@error('refund_flat_amount')
									<p class="mt-1 text-xs text-rose-700">{{ $message }}</p>
								@enderror
							</div>
							<div>
								<label for="resolve_pay_now" class="text-xs font-medium text-slate-600">Pay now amount</label>
								<input
									id="resolve_pay_now"
									type="number"
									name="pay_now_amount"
									min="0"
									:max="maxPayNow"
									step="0.01"
									x-model.number="payNowAmount"
									:readonly="settlementMode === 'full' || settlementMode === 'partial_final'"
									:class="settlementMode === 'full' || settlementMode === 'partial_final' ? 'bg-slate-100 text-slate-500 cursor-not-allowed' : ''"
									class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm"
									required
								>
								<p class="mt-1 text-xs text-slate-500">Maximum pay now: <span class="font-medium tabular-nums" x-text="maxPayNow.toFixed(2)"></span> (cannot exceed invoice paid balance).</p>
								<p class="mt-1 text-xs text-slate-500" x-show="settlementMode === 'partial_deferred'" x-cloak>Use 0 if nothing is paid today; the remainder stays due.</p>
								<p class="mt-1 text-xs text-slate-500" x-show="settlementMode === 'full' || settlementMode === 'partial_final'" x-cloak>Full agreed settlement is paid from the selected wallet when you resolve.</p>
							</div>
							<div>
								<label for="resolve_wallet" class="text-xs font-medium text-slate-600">Wallet (if pay now &gt; 0)</label>
								<select id="resolve_wallet" name="wallet_id" :required="payNowAmount > 0" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
									<option value="">—</option>
									@foreach ($wallets as $wallet)
										<option value="{{ $wallet->id }}" @selected(old('wallet_id') == $wallet->id)>{{ $wallet->name }} ({{ ucfirst($wallet->kind) }})</option>
									@endforeach
								</select>
							</div>
							<div>
								<label for="resolve_payment_date" class="text-xs font-medium text-slate-600">Payment date</label>
								<input id="resolve_payment_date" type="date" name="payment_date" value="{{ old('payment_date', now()->toDateString()) }}" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
							</div>
							<div>
								<label for="resolve_reference" class="text-xs font-medium text-slate-600">Reference</label>
								<input id="resolve_reference" type="text" name="reference_no" value="{{ old('reference_no') }}" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm" placeholder="Optional">
							</div>
							<div>
								<label for="resolve_notes" class="text-xs font-medium text-slate-600">Resolution notes</label>
								<textarea id="resolve_notes" name="resolution_notes" rows="3" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm" placeholder="Required for partial final settlement when a refund amount is set">{{ old('resolution_notes') }}</textarea>
							</div>
							<div class="flex justify-end gap-2 border-t border-slate-100 pt-4">
								<button type="button" class="rounded-lg border border-slate-300 px-4 py-2 text-sm text-slate-700 hover:bg-slate-50" @click="resolveOpen = false">Cancel</button>
								<button type="submit" class="rounded-lg bg-emerald-700 px-4 py-2 text-sm font-medium text-white hover:bg-emerald-800">Confirm resolve</button>
							</div>
						</form>
					@else
						<p class="text-sm text-slate-600">This return is not in draft status.</p>
					@endif
				@else
					<p class="text-sm text-slate-600">You do not have permission to resolve returns.</p>
				@endcan
			</div>
		</div>
	</div>

	{{-- Pay refund offcanvas --}}
	<div
		x-show="payOpen"
		x-cloak
		class="fixed inset-0 z-50 flex justify-end"
		role="dialog"
		aria-modal="true"
		aria-labelledby="pay-sheet-title"
	>
		<div class="absolute inset-0 bg-slate-900/40" @click="payOpen = false" aria-hidden="true"></div>
		<div class="relative flex h-full w-full max-w-md flex-col bg-white shadow-2xl ring-1 ring-slate-900/10" @click.stop>
			<div class="flex items-start justify-between gap-3 border-b border-slate-200 px-4 py-4 sm:px-5">
				<div>
					<h2 id="pay-sheet-title" class="text-base font-semibold text-indigo-900">Pay pending refund</h2>
					<p class="mt-1 text-xs text-slate-500">Due: <span class="font-semibold tabular-nums">{{ number_format($maxRefundPayment, 2) }}</span>@if ($maxRefundPayment < $return->refundAmountPayable()) <span class="text-slate-400">(capped by remaining paid on invoice)</span>@endif</p>
				</div>
				<button type="button" class="rounded-lg p-2 text-slate-500 hover:bg-slate-100" @click="payOpen = false" aria-label="Close">✕</button>
			</div>
			<div class="min-h-0 flex-1 overflow-y-auto px-4 py-4 sm:px-5">
				@can('payRefund', $return)
					@if ($maxRefundPayment > 0)
						<form action="{{ route('returns.pay-refund', $return) }}" method="post" class="space-y-4">
							@csrf
							<div>
								<label for="pay_amount" class="text-xs font-medium text-slate-600">Amount</label>
								<input id="pay_amount" type="number" name="pay_amount" min="0.01" max="{{ $maxRefundPayment }}" step="0.01" value="{{ old('pay_amount', number_format($maxRefundPayment, 2, '.', '')) }}" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm" required>
							</div>
							<div>
								<label for="pay_wallet" class="text-xs font-medium text-slate-600">Wallet</label>
								<select id="pay_wallet" name="wallet_id" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm" required>
									<option value="">Select wallet</option>
									@foreach ($wallets as $wallet)
										<option value="{{ $wallet->id }}" @selected(old('wallet_id') == $wallet->id)>{{ $wallet->name }} ({{ ucfirst($wallet->kind) }})</option>
									@endforeach
								</select>
								<p class="mt-1 text-xs text-slate-500">Payment is recorded immediately using the selected wallet.</p>
							</div>
							<div>
								<label for="pay_date" class="text-xs font-medium text-slate-600">Payment date</label>
								<input id="pay_date" type="date" name="payment_date" value="{{ old('payment_date', now()->toDateString()) }}" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
							</div>
							<div>
								<label for="pay_reference" class="text-xs font-medium text-slate-600">Reference</label>
								<input id="pay_reference" type="text" name="reference_no" value="{{ old('reference_no') }}" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm" placeholder="Optional">
							</div>
							<div class="flex justify-end gap-2 border-t border-slate-100 pt-4">
								<button type="button" class="rounded-lg border border-slate-300 px-4 py-2 text-sm text-slate-700 hover:bg-slate-50" @click="payOpen = false">Cancel</button>
								<button type="submit" class="rounded-lg bg-indigo-700 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-800">Record payment</button>
							</div>
						</form>
					@else
						<p class="text-sm text-slate-600">No refund balance due.</p>
					@endif
				@else
					<p class="text-sm text-slate-600">You do not have permission to record refund payments.</p>
				@endcan
			</div>
		</div>
	</div>
</div>
@endsection
