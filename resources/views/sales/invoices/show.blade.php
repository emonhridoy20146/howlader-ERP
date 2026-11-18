@extends('layouts.app')
@section('title', 'Invoice Details')
@section('page-title', 'Invoice Details')
@section('content')
@php
	$targetableSchedules = ($invoice->sale_type === 'installment' && $invoice->installmentPlan)
		? $invoice->installmentPlan->schedulesSelectableForTargetedPayment(auth()->user()?->can('collect-flexible-payment') ?? false)
		: collect();
	$nextInstallment = ($invoice->sale_type === 'installment' && $invoice->installmentPlan)
		? $invoice->installmentPlan->schedules->first(fn ($s) => ! $s->isFullyPaid())
		: null;
	$amountDueValue = round((float) $invoice->amount_due, 2);
	$openWriteOffSheet = old('_form') === 'debt_writeoff' && $errors->any();
	$writeOffAmountInitial = ($openWriteOffSheet && (old('amount') !== null && old('amount') !== ''))
		? round((float) old('amount'), 2)
		: $amountDueValue;
	$postSheetOpenOnError = $invoice->status === 'draft' && $invoice->sale_type !== 'installment' && $errors->any();
	$activateSheetOpenOnError = $invoice->status === 'draft' && $invoice->sale_type === 'installment' && $errors->any();
	$guarantorSheetOpenOnError = old('_form') === 'guarantor' && $errors->any();
	$branchSheetOpenOnError = old('_form') === 'branch' && $errors->any();
	$canChangeBranch = isset($branchChangeLocations) && $branchChangeLocations->isNotEmpty();
	$openHistoricalPaymentSheet = old('_form') === 'historical_payment' && $errors->any();
	$canChangeSaleType = auth()->user()?->can('changeSaleType', $invoice) ?? false;
	$saleTypeSheetOpenOnError = old('_form') === 'change_sale_type' && $errors->any();
	$openSaleTypeFromQuery = request()->boolean('change_sale_type');
	$canBackdateSales = auth()->user()?->can('backdate-sales-invoice') ?? false;
	$allowsFlexiblePaymentCollection = auth()->user()?->can('collect-flexible-payment') ?? false;
	$historicalPaymentMaxAmount = round((float) $invoice->maxHistoricalPaymentAmount(), 2);
	$historicalPaymentBackfill = $invoice->allowsAdminHistoricalPaymentBackfill();
	$canAddHistoricalPayment = $canBackdateSales && $invoice->allowsAdminHistoricalPayment();
	$historicalPaymentUnavailableReason = $canBackdateSales && ! $canAddHistoricalPayment
		? $invoice->historicalPaymentUnavailableReason()
		: null;
	$historicalPaymentDateInitial = old('payment_date', $invoice->invoice_date?->toDateString() ?? now()->toDateString());
	$historicalPaymentAmountInitial = old('amount', $historicalPaymentMaxAmount > 0
		? number_format($historicalPaymentMaxAmount, 2, '.', '')
		: '');
	$historicalAdvanceMax = ($invoice->sale_type === 'installment' && $invoice->installmentPlan)
		? app(\App\Services\InstallmentAdvanceService::class)->maxHistoricalAdvanceAmount($invoice)
		: 0.0;
	$historicalAdvanceConfigured = round(max(0, (float) ($invoice->installment_down_payment ?? 0)), 2);
	$settlementDiscountApplied = round((float) $invoice->displaySettlementDiscountApplied(), 2);
	$cashCollected = round((float) $invoice->displayCashCollected(), 2);
	$writtenOffTotal = round((float) $invoice->debtWriteoffs->sum('amount'), 2);
	$refundedTotal = round((float) $invoice->totalRefundedAmount(), 2);
	$extraSummaryCards = ($settlementDiscountApplied > 0.009 ? 1 : 0) + ($writtenOffTotal > 0.009 ? 1 : 0) + ($refundedTotal > 0.009 ? 1 : 0);
	$transactionAllocations = $invoice->recordedInboundPaymentAllocations
		->sortByDesc(fn ($allocation) => ($allocation->payment?->payment_date?->toDateString() ?? '0000-01-01').'-'.str_pad((string) $allocation->id, 10, '0', STR_PAD_LEFT))
		->values();
	$canViewLineProfit = auth()->user()?->can('view-line-profit') ?? false;
@endphp
<div
	class="space-y-6"
	@keydown.escape.window="
		if (postOpen) { closePostSheet(); }
		else if (activateOpen) { closeActivateSheet(); }
		else if (writeOffOpen) { closeWriteOffSheet(); }
		else if (historicalOpen) { closeHistoricalSheet(); }
		else if (guarantorOpen) { guarantorOpen = false; }
		else if (branchOpen) { branchOpen = false; }
		else if (saleTypeOpen) { saleTypeOpen = false; }
	"
	x-data="{
		postOpen: false,
		activateOpen: false,
		writeOffOpen: false,
		historicalOpen: false,
		guarantorOpen: false,
		branchOpen: false,
		saleTypeOpen: false,
		writeOffAmount: @js($writeOffAmountInitial),
		maxDue: @js($amountDueValue),
		openWriteOffSheet() {
			this.writeOffAmount = Math.round(Number(this.maxDue) * 100) / 100;
			this.writeOffOpen = true;
		},
		closeWriteOffSheet() { this.writeOffOpen = false; },
		openHistoricalSheet() { this.historicalOpen = true; },
		closeHistoricalSheet() { this.historicalOpen = false; },
		openPostSheet() { this.postOpen = true; },
		closePostSheet() { this.postOpen = false; },
		openActivateSheet() { this.activateOpen = true; },
		closeActivateSheet() { this.activateOpen = false; }
	}"
	@if ($openWriteOffSheet)
		x-init="writeOffOpen = true"
	@elseif ($postSheetOpenOnError)
		x-init="postOpen = true"
	@elseif ($activateSheetOpenOnError)
		x-init="activateOpen = true"
	@elseif ($guarantorSheetOpenOnError)
		x-init="guarantorOpen = true"
	@elseif ($branchSheetOpenOnError)
		x-init="branchOpen = true"
	@elseif ($openHistoricalPaymentSheet)
		x-init="historicalOpen = true"
	@elseif ($saleTypeSheetOpenOnError || $openSaleTypeFromQuery)
		x-init="saleTypeOpen = true"
	@endif
>
	<div class="flex flex-wrap items-center justify-between gap-2">
		<a href="{{ route('sales.invoices.index') }}" class="rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm hover:bg-slate-50">Back To List</a>
		<div class="inline-flex flex-wrap items-center justify-end gap-2">
			<a
				href="{{ route('sales.invoices.print', $invoice) }}"
				class="rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50"
				target="_blank"
				rel="noopener"
			>Print invoice</a>
			@if ($invoice->isPayable() && $invoice->amount_due > 0)
				@can('view', $invoice)
					<a
						href="{{ route('payment-register.index', ['invoice' => $invoice->id]) }}"
						class="rounded-lg bg-slate-900 px-4 py-2 text-sm font-medium text-white shadow-sm hover:bg-slate-800"
					>Register payment</a>
				@endcan
				@can('write-off-debt')
					<button
						type="button"
						class="rounded-lg border border-amber-300 bg-amber-50 px-4 py-2 text-sm font-medium text-amber-950 shadow-sm hover:bg-amber-100"
						@click="openWriteOffSheet()"
					>Write off</button>
				@endcan
			@endif
			@can('create', \App\Models\ReturnOrder::class)
				@if (in_array($invoice->status, ['posted', 'active'], true))
					<a
						href="{{ route('returns.index', ['sales_invoice_id' => $invoice->id]) }}"
						class="rounded-lg border border-violet-200 bg-violet-50 px-3 py-2 text-sm font-medium text-violet-900 hover:bg-violet-100"
					>Start return</a>
				@endif
			@endcan
			@if ($invoice->status === 'draft')
				@can('update', $invoice)
					<a href="{{ route('sales.invoices.edit', $invoice) }}" class="rounded-lg border border-indigo-200 bg-indigo-50 px-3 py-2 text-sm font-medium text-indigo-700 hover:bg-indigo-100">Edit draft</a>
				@endcan
			@elseif (in_array($invoice->status, ['posted', 'active'], true))
				@if ($invoice->sale_type === 'installment')
					@can('reviseActive', $invoice)
						<a href="{{ route('sales.invoices.edit', $invoice) }}" class="rounded-lg border border-indigo-200 bg-indigo-50 px-3 py-2 text-sm font-medium text-indigo-700 hover:bg-indigo-100">Revise installment</a>
					@endcan
				@else
					@can('update', $invoice)
						<a href="{{ route('sales.invoices.edit', $invoice) }}" class="rounded-lg border border-indigo-200 bg-indigo-50 px-3 py-2 text-sm font-medium text-indigo-700 hover:bg-indigo-100">Edit invoice</a>
					@endcan
				@endif
				@if ($canChangeSaleType)
					<button
						type="button"
						class="rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50"
						@click="saleTypeOpen = true"
					>Change payment type</button>
				@endif
			@endif
			@if ($invoice->sale_type === 'installment')
				<button
					type="button"
					class="rounded-lg border border-indigo-200 bg-indigo-50 px-3 py-2 text-sm font-medium text-indigo-700 hover:bg-indigo-100"
					@click="guarantorOpen = true"
				>Manage Guarantor &amp; Verification</button>
			@endif
			@if ($invoice->status === 'draft')
				@if ($invoice->sale_type === 'installment')
					<button
						type="button"
						class="rounded-lg bg-blue-600 px-4 py-2 text-sm font-medium text-white shadow-sm hover:bg-blue-700"
						@click="openActivateSheet()"
					>Activate installment</button>
				@else
					<button
						type="button"
						class="rounded-lg bg-blue-600 px-4 py-2 text-sm font-medium text-white shadow-sm hover:bg-blue-700"
						@click="openPostSheet()"
					>Post invoice</button>
				@endif
			@endif
			@can('delete', $invoice)
				<form action="{{ route('sales.invoices.destroy', $invoice) }}" method="post" class="inline" data-confirm="Move this invoice to the trash? It can be restored later.">
					@csrf
					@method('DELETE')
					<button type="submit" class="rounded-lg border border-rose-200 bg-rose-50 px-3 py-2 text-sm font-medium text-rose-700 hover:bg-rose-100">Delete Invoice</button>
				</form>
			@endcan
		</div>
	</div>

	@if (session('status'))
		<div class="rounded-lg bg-emerald-50 p-3 text-sm text-emerald-700">{{ session('status') }}</div>
	@endif
	@if (session('error'))
		<div class="rounded-lg bg-red-50 p-3 text-sm text-red-700">{{ session('error') }}</div>
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

	<div class="grid gap-4 sm:grid-cols-2 {{ $extraSummaryCards >= 2 ? 'lg:grid-cols-6' : ($extraSummaryCards === 1 ? 'lg:grid-cols-5' : 'lg:grid-cols-4') }}">
		<div class="rounded-xl border border-slate-200 bg-white p-4">
			<p class="text-xs uppercase tracking-wide text-slate-500">Invoice</p>
			<p class="mt-1 font-mono text-sm font-semibold text-slate-800">{{ $invoice->invoice_number ?? 'Pending payment' }}</p>
		</div>
		<div class="rounded-xl border border-slate-200 bg-white p-4">
			<p class="text-xs uppercase tracking-wide text-slate-500">Grand total</p>
			<p class="mt-1 text-lg font-semibold text-slate-800">{{ number_format($invoice->grand_total, 2) }}</p>
		</div>
		<div class="rounded-xl border border-slate-200 bg-white p-4">
			<p class="text-xs uppercase tracking-wide text-slate-500">Paid</p>
			<p class="mt-1 text-lg font-semibold text-emerald-700">{{ number_format($cashCollected, 2) }}</p>
		</div>
		@if ($settlementDiscountApplied > 0.009)
			<div class="rounded-xl border border-indigo-200 bg-indigo-50/40 p-4">
				<p class="text-xs uppercase tracking-wide text-indigo-800">Settlement discount</p>
				<p class="mt-1 text-lg font-semibold text-indigo-900">{{ number_format($settlementDiscountApplied, 2) }}</p>
			</div>
		@endif
		@if ($refundedTotal > 0.009)
			<div class="rounded-xl border border-sky-200 bg-sky-50/40 p-4">
				<p class="text-xs uppercase tracking-wide text-sky-800">Refunded</p>
				<p class="mt-1 text-lg font-semibold text-sky-900">{{ number_format($refundedTotal, 2) }}</p>
			</div>
		@endif
		@if ($writtenOffTotal > 0.009)
			<div class="rounded-xl border border-amber-200 bg-amber-50/40 p-4">
				<p class="text-xs uppercase tracking-wide text-amber-800">Written off</p>
				<p class="mt-1 text-lg font-semibold text-amber-900">{{ number_format($writtenOffTotal, 2) }}</p>
			</div>
		@endif
		<div class="rounded-xl border border-slate-200 bg-white p-4">
			<p class="text-xs uppercase tracking-wide text-slate-500">Amount due</p>
			<p class="mt-1 text-lg font-semibold text-rose-700">{{ number_format($invoice->amount_due, 2) }}</p>
		</div>
	</div>

	<div class="rounded-xl border border-slate-200 bg-white p-4 sm:p-6">
		<h3 class="text-sm font-semibold text-slate-800">Invoice info</h3>
		<div class="mt-3 grid gap-3 text-sm md:grid-cols-2">
			<div><span class="text-slate-500">Customer:</span> {{ $invoice->customer?->name }} ({{ implode(', ', $invoice->customer?->normalizedPhoneNumbers() ?? []) }})</div>
			<div>
				<span class="text-slate-500">Stock location:</span>
				@if ($invoice->branch)
					<a href="{{ route('locations.show', $invoice->branch) }}" class="font-medium text-indigo-600 hover:text-indigo-800">{{ $invoice->branch->name }}</a>
				@else
					—
				@endif
				@if ($canChangeBranch)
					<button
						type="button"
						class="ml-2 text-xs font-medium text-indigo-700 hover:text-indigo-900 underline"
						@click="branchOpen = true"
					>Change</button>
				@endif
			</div>
			<div><span class="text-slate-500">Date:</span> {{ $invoice->invoice_date?->toDateString() }}</div>
			<div><span class="text-slate-500">Referrer:</span> {{ $invoice->referrer?->displayLabel() ?? '—' }}</div>
			<div><span class="text-slate-500">Type:</span> {{ ucfirst($invoice->sale_type) }}
				@if ($canChangeSaleType)
					<button
						type="button"
						class="ml-2 text-xs font-medium text-indigo-700 hover:text-indigo-900 underline"
						@click="saleTypeOpen = true"
					>Change</button>
				@endif
			</div>
			<div><span class="text-slate-500">Invoice status:</span> {{ ucfirst(str_replace('_', ' ', $invoice->status)) }}</div>
			<div><span class="text-slate-500">Payment status:</span> {{ ucfirst(str_replace('_', ' ', $invoice->payment_status)) }}</div>
			<div><span class="text-slate-500">Subtotal:</span> {{ number_format((float) $invoice->subtotal, 2) }}</div>
			@if ((float) $invoice->tax_total > 0)
				<div><span class="text-slate-500">Tax:</span> {{ number_format((float) $invoice->tax_total, 2) }}</div>
			@endif
			<div><span class="text-slate-500">Invoice discount:</span> {{ number_format((float) $invoice->discount_total, 2) }}</div>
			@if ($invoice->sale_type === 'credit')
				<div><span class="text-slate-500">Credit due:</span> {{ $invoice->credit_due_date?->toDateString() }}
					@if ($canChangeSaleType)
						<button
							type="button"
							class="ml-2 text-xs font-medium text-indigo-700 hover:text-indigo-900 underline"
							@click="saleTypeOpen = true"
						>Update</button>
					@endif
				</div>
			@endif
			@if ($invoice->sale_type === 'installment')
				<div><span class="text-slate-500">Advance (at sale):</span> {{ number_format((float) $invoice->installment_down_payment, 2) }}</div>
				<div><span class="text-slate-500">Installment count:</span> {{ $invoice->installment_count }}</div>
				<div><span class="text-slate-500">Frequency:</span> {{ ucfirst((string) ($invoice->installment_frequency ?? 'monthly')) }}@if ($invoice->installment_frequency === 'custom' && $invoice->installment_custom_days) ({{ $invoice->installment_custom_days }} days) @endif</div>
				<div><span class="text-slate-500">Penalty:</span> {{ $invoice->installment_penalty_enabled ? 'Enabled' : 'Disabled' }} ({{ $invoice->installment_penalty_rate }})</div>
				@if ($invoice->guarantor_id && $invoice->guarantor)
					<div class="md:col-span-2 rounded-lg border border-indigo-100 bg-indigo-50/50 px-3 py-2">
						<p class="font-semibold text-indigo-950 flex items-center gap-1.5">
							<svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4 text-indigo-600" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/></svg>
							Linked Guarantor Customer
						</p>
						<p class="mt-1">
							<a href="{{ route('customers.show', $invoice->guarantor) }}" class="font-semibold text-indigo-700 hover:text-indigo-900 underline">
								{{ $invoice->guarantor->name }} ({{ $invoice->guarantor->customer_code }})
							</a>
						</p>
						<p class="text-xs text-slate-600 mt-1">Phone: {{ $invoice->guarantor->phone }}</p>
						@if ($invoice->guarantor_address)
							<p class="text-xs text-slate-600">Address: {{ $invoice->guarantor_address }}</p>
						@endif
						@if ($invoice->guarantor_nid_number)
							<p class="text-xs text-slate-600">NID: {{ $invoice->guarantor_nid_number }}</p>
						@endif
						@if ($invoice->guarantor_notes)
							<p class="text-xs text-slate-500 mt-1 italic">Notes: {{ $invoice->guarantor_notes }}</p>
						@endif
					</div>
				@elseif ($invoice->guarantor_name || $invoice->guarantor_address || !empty($invoice->guarantor_phone_numbers) || $invoice->guarantor_notes)
					<div class="md:col-span-2 rounded-lg border border-slate-100 bg-slate-50 px-3 py-2">
						<p><span class="text-slate-500 font-medium">Guarantor (Text Only):</span> {{ $invoice->guarantor_name ?: 'N/A' }}</p>
						@if ($invoice->guarantor_address)
							<p><span class="text-slate-500">Address:</span> {{ $invoice->guarantor_address }}</p>
						@endif
						@if (!empty($invoice->guarantor_phone_numbers))
							<p><span class="text-slate-500">Phones:</span> {{ implode(', ', $invoice->guarantor_phone_numbers) }}</p>
						@endif
						@if ($invoice->guarantor_nid_number)
							<p><span class="text-slate-500">NID:</span> {{ $invoice->guarantor_nid_number }}</p>
						@endif
						@if ($invoice->guarantor_notes)
							<p><span class="text-slate-500">Other info:</span> {{ $invoice->guarantor_notes }}</p>
						@endif
					</div>
				@endif

				<div class="md:col-span-2 rounded-lg border border-slate-200 bg-white p-3 space-y-2">
					<p class="font-semibold text-slate-800 text-xs uppercase tracking-wider">Verification Documents</p>
					<div class="grid grid-cols-1 sm:grid-cols-2 gap-3 text-xs">
						<div class="rounded bg-slate-50 p-2 border border-slate-100">
							<span class="text-slate-500 block font-medium">Customer NID:</span>
							@if ($invoice->customer_nid_path)
								<a href="{{ $invoice->customerNidUrl() }}" target="_blank" class="mt-1 inline-flex items-center gap-1 font-medium text-indigo-600 hover:underline">
									<svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
									View/Download NID
								</a>
							@else
								<span class="text-slate-400 mt-1 block">Not Uploaded</span>
							@endif
						</div>
						<div class="rounded bg-slate-50 p-2 border border-slate-100">
							<span class="text-slate-500 block font-medium">Guarantor NID:</span>
							@if ($invoice->guarantor_nid_path)
								<a href="{{ $invoice->guarantorNidUrl() }}" target="_blank" class="mt-1 inline-flex items-center gap-1 font-medium text-indigo-600 hover:underline">
									<svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
									View/Download NID
								</a>
							@else
								<span class="text-slate-400 mt-1 block">Not Uploaded</span>
							@endif
						</div>
					</div>
					@if ($invoice->verification_attachments && count($invoice->verification_attachments) > 0)
						<div class="mt-2 border-t border-slate-100 pt-2">
							<span class="text-slate-500 block text-xs font-medium">Other Verification Attachments:</span>
							<ul class="mt-1 space-y-1.5">
								@foreach ($invoice->verification_attachments as $idx => $path)
									<li>
										<a href="{{ \Illuminate\Support\Facades\Storage::disk('public')->url($path) }}" target="_blank" class="inline-flex items-center gap-1 text-xs text-indigo-600 hover:underline font-mono">
											<svg xmlns="http://www.w3.org/2000/svg" class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13.828 10.172a4 4 0 00-5.656 0l-4 4a4 4 0 105.656 5.656l1.102-1.101m-.758-4.899a4 4 0 005.656 0l4-4a4 4 0 00-5.656-5.656l-1.1 1.1"/></svg>
											Attachment #{{ $idx + 1 }} ({{ basename($path) }})
										</a>
									</li>
								@endforeach
							</ul>
						</div>
					@endif
				</div>
				@if ($invoice->installmentPlan)
					<div class="md:col-span-2">
						<span class="text-slate-500">Plan remaining:</span>
						<span class="font-medium text-slate-800">{{ number_format((float) $invoice->installmentPlan->remaining_amount, 2) }}</span>
						<span class="text-slate-400"> · </span>
						<span class="text-slate-500">Plan status:</span>
						<span class="font-medium text-slate-800">{{ ucfirst($invoice->installmentPlan->status) }}</span>
					</div>
				@endif
				@if ($nextInstallment)
					<div class="md:col-span-2 rounded-lg border border-slate-100 bg-slate-50 px-3 py-2 text-slate-800">
						<span class="text-slate-500">Next due installment:</span>
						#{{ $nextInstallment->installment_no }} on {{ $nextInstallment->due_date?->toDateString() }}
						— <span class="font-semibold">{{ number_format($nextInstallment->remainingDue(), 2) }}</span> remaining
					</div>
				@endif
			@endif
		</div>
	</div>

	<div class="overflow-hidden rounded-xl border border-slate-200 bg-white">
		<div class="border-b border-slate-200 px-4 py-3">
			<h3 class="text-sm font-semibold text-slate-800">Line items</h3>
		</div>
		<table class="min-w-full text-sm whitespace-nowrap">
				<thead class="bg-slate-50 border-b border-slate-200 text-left text-xs font-semibold uppercase tracking-wider text-slate-700">
					<tr>
						<th class="px-4 py-3">Product</th>
						<th class="px-4 py-3">Qty</th>
						<th class="px-4 py-3">Unit price</th>
						<th class="px-4 py-3">Line discount</th>
						<th class="px-4 py-3">Service charge</th>
						<th class="px-4 py-3">Line total</th>
						@if ($canViewLineProfit)
							<th class="px-4 py-3">Profit</th>
						@endif
						<th class="px-4 py-3">Serials</th>
					</tr>
				</thead>
				<tbody>
					@foreach ($invoice->lines as $line)
						<tr class="border-b border-slate-100 transition-colors hover:bg-slate-50/70 last:border-0">
							<td class="px-4 py-3">{{ $line->product?->name }}</td>
							<td class="px-4 py-3">{{ $line->qty }}</td>
							<td class="px-4 py-3">{{ number_format($line->unit_price, 2) }}</td>
							<td class="px-4 py-3 align-top tabular-nums">
								@include('sales.invoices.partials._line-discount', ['line' => $line])
							</td>
							<td class="px-4 py-3 tabular-nums">
								@php $lineServiceCharge = max(0, (float) ($line->service_charge ?? 0)); @endphp
								@if ($lineServiceCharge <= 0.0001)
									<span class="text-slate-400">—</span>
								@else
									{{ number_format($lineServiceCharge, 2) }}
								@endif
							</td>
							<td class="px-4 py-3 font-medium">{{ number_format($line->line_total, 2) }}</td>
							@if ($canViewLineProfit)
								@php
									$purchasePrice = max(0, (float) ($line->product?->default_purchase_price ?? 0));
									$costTotal = round($purchasePrice * max(0, (int) $line->qty), 2);
									$lineTotal = (float) $line->line_total;
									$lineProfit = round($lineTotal - $costTotal, 2);
									$markupPct = $costTotal > 0 ? round(($lineProfit / $costTotal) * 100, 1) : null;
									$marginPct = $lineTotal > 0 ? round(($lineProfit / $lineTotal) * 100, 1) : null;
									$profitTone = $lineProfit >= 0 ? 'text-emerald-700' : 'text-rose-700';
								@endphp
								<td class="px-4 py-3 align-top tabular-nums">
									<p class="font-semibold {{ $profitTone }}">{{ number_format($lineProfit, 2) }}</p>
									@if ($markupPct !== null || $marginPct !== null)
										<p class="mt-0.5 text-[11px] {{ $profitTone }}">
											@if ($markupPct !== null)
												Markup {{ ($markupPct >= 0 ? '+' : '').number_format($markupPct, 1) }}%
											@endif
											@if ($markupPct !== null && $marginPct !== null)
												<span class="text-slate-400"> · </span>
											@endif
											@if ($marginPct !== null)
												Margin {{ ($marginPct >= 0 ? '+' : '').number_format($marginPct, 1) }}%
											@endif
										</p>
									@else
										<p class="mt-0.5 text-[11px] text-slate-400">—</p>
									@endif
									@if ($purchasePrice > 0 && (int) $line->qty > 0)
										<p class="mt-0.5 text-[10px] text-slate-400">Sale+SC {{ number_format($lineTotal, 2) }} vs cost {{ number_format($costTotal, 2) }}</p>
									@endif
								</td>
							@endif
							<td class="px-4 py-3 text-xs">
								@forelse ($line->saleLineSerials as $serial)
									<span class="mb-1 mr-1 inline-block rounded bg-slate-100 px-2 py-1">
										{{ $serial->serialItem?->serial_number }}
										@if ($serial->serialItem?->manufacturer_serial)
											<span class="text-slate-500">/ {{ $serial->serialItem?->manufacturer_serial }}</span>
										@endif
										@if ($serial->released_at)
											<span class="text-amber-800">· returned</span>
										@endif
									</span>
								@empty
									<span class="text-slate-400">N/A</span>
								@endforelse
							</td>
						</tr>
					@endforeach
				</tbody>
			</table>
	</div>

	<div class="overflow-hidden rounded-xl border border-slate-200 bg-white">
		<div class="flex flex-wrap items-center justify-between gap-2 border-b border-slate-200 px-4 py-3">
			<h3 class="text-sm font-semibold text-slate-800">Transactions</h3>
			@if ($canAddHistoricalPayment)
				<button
					type="button"
					class="rounded-lg border border-sky-200 bg-sky-50 px-3 py-1.5 text-xs font-medium text-sky-900 hover:bg-sky-100"
					@click="openHistoricalSheet()"
				>{{ $historicalPaymentBackfill ? 'Add missing transaction' : 'Add historical payment' }}</button>
			@elseif ($historicalPaymentUnavailableReason)
				<span class="text-xs text-slate-500" title="{{ $historicalPaymentUnavailableReason }}">Historical payment unavailable</span>
			@endif
		</div>
		@if ($invoice->debtWriteoffs->isNotEmpty() || $transactionAllocations->isNotEmpty())
			<div class="overflow-x-auto">
				<table class="min-w-full text-sm whitespace-nowrap">
					<thead class="bg-slate-50 border-b border-slate-200 text-left text-xs font-semibold uppercase tracking-wider text-slate-700">
						<tr>
							<th class="px-4 py-3">Date</th>
							<th class="px-4 py-3">Type</th>
							<th class="px-4 py-3">Reference</th>
							<th class="px-4 py-3">Detail</th>
							<th class="px-4 py-3">Referrer</th>
							<th class="px-4 py-3 text-right">Amount</th>
							<th class="px-4 py-3 text-right">Actions</th>
						</tr>
					</thead>
					<tbody>
						@foreach ($invoice->debtWriteoffs as $wo)
							<tr class="border-t border-slate-200 bg-amber-50/40">
								<td class="px-4 py-3">{{ $wo->created_at?->toDateString() }}</td>
								<td class="px-4 py-3 font-medium text-amber-900">Bad debt write-off</td>
								<td class="px-4 py-3 font-mono text-xs">WO-{{ $wo->id }}</td>
								<td class="px-4 py-3 text-slate-600">{{ $wo->reason ?: '—' }} @if($wo->actor)<span class="text-xs text-slate-400">· {{ $wo->actor->name }}</span>@endif</td>
								<td class="px-4 py-3 text-slate-400">—</td>
								<td class="px-4 py-3 text-right font-medium text-amber-900">{{ number_format((float) $wo->amount, 2) }}</td>
								<td class="px-4 py-3 text-right">
									@can('remove-debt-writeoff')
										@if (in_array($invoice->status, ['posted', 'active'], true))
											<form
												action="{{ route('sales.invoices.write-off.destroy', [$invoice, $wo]) }}"
												method="post"
												class="inline"
												data-confirm="Remove this write-off? The invoice balance, customer balance and accounting entries will be restored."
											>
												@csrf
												@method('DELETE')
												<button type="submit" class="rounded-md border border-rose-200 bg-white px-2 py-1 text-xs font-semibold text-rose-700 hover:bg-rose-50">Remove</button>
											</form>
										@endif
									@endcan
								</td>
							</tr>
						@endforeach
						@foreach ($transactionAllocations as $allocation)
							@php
								$payment = $allocation->payment;
								$pivotCash = round((float) $allocation->allocated_amount, 2);
								$pivotDisc = round((float) ($allocation->settlement_discount ?? 0), 2);
								$allocationId = $allocation->id;
							@endphp
							@if ($payment && $pivotCash > 0.009)
								<tr class="border-b border-slate-100 transition-colors hover:bg-slate-50/70 last:border-0">
									<td class="px-4 py-3">{{ $payment->payment_date?->toDateString() }}</td>
									<td class="px-4 py-3">
										@if ($payment->isHistoricalPayment())
											<span class="font-medium text-sky-900">Historical payment</span>
										@else
											Payment
										@endif
									</td>
									<td class="px-4 py-3 font-mono text-xs">{{ $payment->payment_number }}</td>
									<td class="px-4 py-3">
										<span>{{ $payment->paymentMethodRef?->name ?? ucfirst(str_replace('_', ' ', $payment->payment_method)) }} @if($payment->reference_no)<span class="text-slate-500">· {{ $payment->reference_no }}</span>@endif</span>
										@if ($payment->isHistoricalPayment())
											<span class="mt-0.5 block text-xs text-sky-800">
												Backdated admin entry · invoice paid totals unchanged
												@if ($payment->skipsWalletGl())
													· wallet not updated
												@endif
											</span>
										@endif
									</td>
									<td class="px-4 py-3 text-slate-600">{{ $payment->referrerEmployee?->displayLabel() ?? '—' }}</td>
									<td class="px-4 py-3 text-right font-medium text-emerald-700">{{ number_format($pivotCash, 2) }}</td>
									<td class="px-4 py-3 text-right">
										<div class="flex flex-col items-end gap-1.5">
											@can('update-payment-method')
												@if (! $payment->skipsWalletGl() && in_array($invoice->status, ['posted', 'active'], true) && ($paymentMethodsForBranch ?? collect())->isNotEmpty())
													<form method="post" action="{{ route('sales.invoices.payments.update-method', [$invoice, $payment]) }}" class="flex items-center gap-1">
														@csrf
														@method('PATCH')
														<select name="payment_method_id" class="max-w-[10rem] rounded-md border border-slate-300 px-2 py-1 text-xs">
															@foreach ($paymentMethodsForBranch as $method)
																<option value="{{ $method->id }}" @selected((int) $payment->payment_method_id === (int) $method->id)>{{ $method->name }}</option>
															@endforeach
														</select>
														<button type="submit" class="rounded-md border border-indigo-200 bg-indigo-50 px-2 py-1 text-xs font-semibold text-indigo-800 hover:bg-indigo-100">Change</button>
													</form>
												@endif
											@endcan
											@can('delete-payment-allocation')
												@if ($allocationId !== null && in_array($invoice->status, ['posted', 'active'], true))
													<form
														action="{{ route('sales.invoices.payment-allocations.destroy', [$invoice, $allocationId]) }}"
														method="post"
														class="inline"
														data-confirm="Remove this payment? Customer balance and accounting entries for this allocation will be reversed."
													>
														@csrf
														@method('DELETE')
														<button type="submit" class="rounded-md border border-rose-200 bg-white px-2 py-1 text-xs font-semibold text-rose-700 hover:bg-rose-50">Remove</button>
													</form>
												@endif
											@endcan
										</div>
									</td>
								</tr>
							@endif
							@if ($payment && $pivotDisc > 0.009)
								<tr class="border-b border-slate-100 bg-indigo-50/30 transition-colors hover:bg-indigo-50/50 last:border-0">
									<td class="px-4 py-3">{{ $payment->payment_date?->toDateString() }}</td>
									<td class="px-4 py-3 font-medium text-indigo-900">Settlement discount</td>
									<td class="px-4 py-3 font-mono text-xs">{{ $payment->payment_number }}</td>
									<td class="px-4 py-3 text-slate-600">
										@if ($pivotCash > 0.009)
											Applied with payment above · reduces balance without extra cash
										@else
											Settlement discount · reduces balance without cash collection
										@endif
									</td>
									<td class="px-4 py-3 text-slate-600">{{ $payment->referrerEmployee?->displayLabel() ?? '—' }}</td>
									<td class="px-4 py-3 text-right font-medium text-indigo-900">{{ number_format($pivotDisc, 2) }}</td>
									<td class="px-4 py-3 text-right">
										@if ($pivotCash <= 0.009)
											<div class="flex flex-col items-end gap-1.5">
												@can('delete-payment-allocation')
													@if ($allocationId !== null && in_array($invoice->status, ['posted', 'active'], true))
														<form
															action="{{ route('sales.invoices.payment-allocations.destroy', [$invoice, $allocationId]) }}"
															method="post"
															class="inline"
															data-confirm="Remove this settlement discount? Customer balance and accounting entries for this allocation will be reversed."
														>
															@csrf
															@method('DELETE')
															<button type="submit" class="rounded-md border border-rose-200 bg-white px-2 py-1 text-xs font-semibold text-rose-700 hover:bg-rose-50">Remove</button>
														</form>
													@endif
												@endcan
											</div>
										@endif
									</td>
								</tr>
							@endif
						@endforeach
					</tbody>
				</table>
			</div>
		@else
			<div class="px-4 py-4 text-sm text-slate-500">
				No transactions recorded yet.
				@if ($canAddHistoricalPayment && $historicalPaymentBackfill)
					<span class="mt-1 block text-xs text-sky-800">This invoice is marked paid but has no payment rows. Use <span class="font-medium">Add missing transaction</span> above to backfill one without changing invoice paid totals.</span>
				@elseif ($historicalPaymentUnavailableReason)
					<span class="mt-1 block text-xs text-amber-800">{{ $historicalPaymentUnavailableReason }}</span>
				@endif
			</div>
		@endif
	</div>

	@if ($invoice->returnOrders->isNotEmpty() || (in_array($invoice->status, ['posted', 'active'], true) && auth()->user()?->can('create', \App\Models\ReturnOrder::class)))
		<div class="overflow-hidden rounded-xl border border-slate-200 bg-white">
			<div class="flex flex-wrap items-center justify-between gap-2 border-b border-slate-200 px-4 py-3">
				<h3 class="text-sm font-semibold text-slate-800">Returns</h3>
				@can('create', \App\Models\ReturnOrder::class)
					@if (in_array($invoice->status, ['posted', 'active'], true))
						<a
							href="{{ route('returns.index', ['sales_invoice_id' => $invoice->id]) }}"
							class="text-xs font-medium text-violet-700 underline decoration-violet-300 underline-offset-2 hover:text-violet-900"
						>New return from this invoice</a>
					@endif
				@endcan
			</div>
			@if ($invoice->returnOrders->isEmpty())
				<div class="px-4 py-4 text-sm text-slate-500">No return orders linked to this invoice yet.</div>
			@else
				<div class="overflow-x-auto">
					<table class="min-w-full text-sm whitespace-nowrap">
						<thead class="bg-slate-50 border-b border-slate-200 text-left text-xs font-semibold uppercase tracking-wider text-slate-700">
							<tr>
								<th class="px-4 py-3">Return No</th>
								<th class="px-4 py-3">Date</th>
								<th class="px-4 py-3">Branch</th>
								<th class="px-4 py-3">Status</th>
								<th class="px-4 py-3 text-right">Actions</th>
							</tr>
						</thead>
						<tbody>
							@foreach ($invoice->returnOrders as $ret)
								<tr class="border-b border-slate-100 transition-colors hover:bg-slate-50/70 last:border-0">
									<td class="px-4 py-3 font-mono text-xs">{{ $ret->return_number }}</td>
									<td class="px-4 py-3">{{ $ret->return_date?->toDateString() }}</td>
									<td class="px-4 py-3">{{ $ret->branch?->name ?? '—' }}</td>
									<td class="px-4 py-3">{{ ucfirst(str_replace('_', ' ', $ret->status)) }}</td>
									<td class="px-4 py-3 text-right">
										@can('view', $ret)
											<a href="{{ route('returns.show', $ret) }}" class="text-xs font-medium text-indigo-700 hover:text-indigo-900">View</a>
										@endcan
									</td>
								</tr>
							@endforeach
						</tbody>
					</table>
				</div>
			@endif
		</div>
	@endif

	@if ($invoice->sale_type === 'installment' && $invoice->installmentPlan && $invoice->installmentPlan->schedules->count() > 0)
		<div class="overflow-hidden rounded-xl border border-slate-200 bg-white">
			<div class="flex flex-wrap items-center justify-between gap-2 border-b border-slate-200 px-4 py-3">
				<h3 class="text-sm font-semibold text-slate-800">Installment schedule</h3>
				@if ($invoice->isPayable() && $invoice->amount_due > 0)
					@can('view', $invoice)
						<a
							href="{{ route('payment-register.index', ['invoice' => $invoice->id]) }}"
							class="text-xs font-medium text-indigo-700 underline decoration-indigo-300 underline-offset-2 hover:text-indigo-900"
						>Open payment register</a>
					@endcan
				@endif
			</div>
			<div class="overflow-x-auto">
				<table class="min-w-full text-sm whitespace-nowrap">
					<thead class="bg-slate-50 border-b border-slate-200 text-left text-xs font-semibold uppercase tracking-wider text-slate-700">
						<tr>
							<th class="px-4 py-3">No</th>
							<th class="px-4 py-3">Due date</th>
							<th class="px-4 py-3">Due</th>
							<th class="px-4 py-3">Paid</th>
							<th class="px-4 py-3">Remaining</th>
							<th class="px-4 py-3">Status</th>
							<th class="px-4 py-3 text-right">Pay</th>
						</tr>
					</thead>
					<tbody>
						@foreach ($invoice->installmentPlan->schedules as $schedule)
							@php
								$isDueToday = $schedule->remainingDue() >= 0.01 && $schedule->due_date && $schedule->due_date->isToday();
								$isOverdue = $schedule->remainingDue() >= 0.01 && $schedule->due_date && $schedule->due_date->isPast() && ! $isDueToday;
								$daysOverdue = $isOverdue ? (int) $schedule->due_date->diffInDays(now()) : 0;
							@endphp
							<tr class="border-b border-slate-100 transition-colors last:border-0 {{ $isOverdue ? 'bg-rose-50/70 hover:bg-rose-50' : ($isDueToday ? 'bg-amber-50/70 hover:bg-amber-50' : 'hover:bg-slate-50/70') }}">
								<td class="px-4 py-3">{{ $schedule->installment_no }}</td>
								<td class="px-4 py-3">
									<span class="tabular-nums {{ $isOverdue ? 'font-semibold text-rose-700' : ($isDueToday ? 'font-semibold text-amber-700' : '') }}">{{ $schedule->due_date?->toDateString() }}</span>
									@if ($isDueToday)
										<div class="mt-1"><span class="inline-flex w-fit items-center gap-1 rounded-full bg-amber-100 px-2 py-0.5 text-xs font-semibold text-amber-700 ring-1 ring-amber-200">⏰ Due today</span></div>
									@elseif ($isOverdue)
										<div class="mt-1 flex flex-col gap-0.5">
											<span class="inline-flex w-fit items-center gap-1 rounded-full bg-rose-100 px-2 py-0.5 text-xs font-semibold text-rose-700 ring-1 ring-rose-200">⚠ {{ $daysOverdue }} {{ \Illuminate\Support\Str::plural('day', $daysOverdue) }} overdue</span>
											<span class="text-xs text-rose-500">Was due {{ $schedule->due_date->diffForHumans() }}</span>
										</div>
									@elseif ($schedule->remainingDue() >= 0.01 && $schedule->due_date)
										<div class="mt-0.5 text-xs text-slate-400">Due {{ $schedule->due_date->diffForHumans() }}</div>
									@endif
								</td>
								<td class="px-4 py-3">{{ number_format($schedule->due_amount, 2) }}</td>
								<td class="px-4 py-3">{{ number_format($schedule->paid_amount, 2) }}</td>
								<td class="px-4 py-3 font-medium">{{ number_format($schedule->remainingDue(), 2) }}</td>
								<td class="px-4 py-3">{{ ucfirst(str_replace('_', ' ', $schedule->status)) }}</td>
								<td class="px-4 py-3 text-right">
									@if ($invoice->isPayable() && $invoice->amount_due > 0 && $schedule->remainingDue() >= 0.01 && $targetableSchedules->contains(fn ($s) => (int) $s->id === (int) $schedule->id))
										@can('view', $invoice)
											<a
												href="{{ route('payment-register.index', ['invoice' => $invoice->id]) }}"
												class="text-xs font-medium text-indigo-700 hover:text-indigo-900"
											>Pay in register</a>
										@endcan
									@elseif ($schedule->remainingDue() < 0.01)
										<span class="text-xs text-slate-400">—</span>
									@else
										<span class="text-xs text-slate-400">Pay earlier first</span>
									@endif
								</td>
							</tr>
						@endforeach
					</tbody>
				</table>
			</div>
		</div>
	@endif

	@can('write-off-debt')
		@if (($invoice->isPayable() && $invoice->amount_due > 0) || $openWriteOffSheet)
			{{-- Write-off receivable offcanvas --}}
			<div
				x-show="writeOffOpen"
				x-cloak
				class="fixed inset-0 z-[100] flex justify-end"
				role="dialog"
				aria-modal="true"
				aria-labelledby="write-off-sheet-title"
			>
				<div class="absolute inset-0 bg-slate-900/40" @click="closeWriteOffSheet()" aria-hidden="true"></div>
				<div
					class="relative flex h-full w-full max-w-md flex-col bg-amber-50 shadow-2xl ring-1 ring-amber-900/15"
					@click.stop
				>
					<div class="flex items-start justify-between gap-3 border-b border-amber-200/80 bg-amber-50 px-4 py-4 sm:px-5">
						<div>
							<h2 id="write-off-sheet-title" class="text-base font-semibold text-amber-950">Write off receivable</h2>
							<p class="mt-1 text-xs text-amber-900/80">Invoice {{ $invoice->invoice_number ?? '#'.$invoice->id }} · posts bad debt expense, clears AR (and installment schedules when applicable). Not reversible from this screen.</p>
						</div>
						<button type="button" class="rounded-lg p-2 text-amber-800 hover:bg-amber-100 hover:text-amber-950" @click="closeWriteOffSheet()" aria-label="Close">
							<svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12" /></svg>
						</button>
					</div>
					<div class="min-h-0 flex-1 overflow-y-auto px-4 py-4 sm:px-5">
						@if ($errors->has('write_off'))
							<div class="mb-4 rounded-lg border border-rose-200 bg-rose-50 px-3 py-2 text-sm text-rose-800">{{ $errors->first('write_off') }}</div>
						@endif
						<form id="invoice-write-off-form" method="post" action="{{ route('sales.invoices.write-off', $invoice) }}" class="space-y-4" data-confirm="Write off this receivable?">
							@csrf
							<input type="hidden" name="_form" value="debt_writeoff">
							<div>
								<label for="write-off-amount" class="text-xs font-medium text-amber-950">Amount (max {{ number_format($invoice->amount_due, 2) }})</label>
								<input
									id="write-off-amount"
									type="number"
									name="amount"
									x-model="writeOffAmount"
									step="0.01"
									min="0.01"
									:max="maxDue"
									class="mt-1 w-full rounded-lg border border-amber-300/90 bg-white px-3 py-2 text-sm"
									required
								>
								@error('amount')
									<p class="mt-1 text-xs text-rose-700">{{ $message }}</p>
								@enderror
							</div>
							<div>
								<label for="write-off-reason" class="text-xs font-medium text-amber-950">Reason (optional)</label>
								<textarea id="write-off-reason" name="reason" rows="3" maxlength="2000" class="mt-1 w-full rounded-lg border border-amber-300/90 bg-white px-3 py-2 text-sm" placeholder="e.g. Customer insolvency, management approval ref. …">{{ old('reason') }}</textarea>
								@error('reason')
									<p class="mt-1 text-xs text-rose-700">{{ $message }}</p>
								@enderror
							</div>
						</form>
					</div>
					<div class="shrink-0 border-t border-amber-200/80 bg-amber-50/95 px-4 py-3 backdrop-blur-sm sm:px-5 pb-[max(0.75rem,env(safe-area-inset-bottom,0px))]">
						<button type="submit" form="invoice-write-off-form" class="w-full rounded-lg bg-amber-900 px-4 py-2.5 text-sm font-medium text-white hover:bg-amber-950">Confirm write-off</button>
					</div>
				</div>
			</div>
		@endif
	@endcan

	@if ($canAddHistoricalPayment)
		{{-- Historical payment (admin, backdated; wallet GL optional) --}}
		<div
			x-show="historicalOpen"
			x-cloak
			class="fixed inset-0 z-[100] flex justify-end"
			role="dialog"
			aria-modal="true"
			aria-labelledby="historical-payment-sheet-title"
		>
			<div class="absolute inset-0 bg-slate-900/40" @click="closeHistoricalSheet()" aria-hidden="true"></div>
			<div
				class="relative flex h-full w-full max-w-md flex-col bg-sky-50 shadow-2xl ring-1 ring-sky-900/15"
				@click.stop
				x-data="{
					skipWalletGl: @js((bool) old('skip_wallet_gl', false)),
					installmentTarget: @js(old('installment_target', 'waterfall')),
					confirmMessage() {
						const skip = this.skipWalletGl;
						if (skip) {
							return 'Record this historical transaction? Invoice paid totals will not change and wallet balance will not increase.';
						}
						return 'Record this historical transaction? Invoice paid totals will not change; wallet balance will increase unless you opt out below.';
					}
				}"
			>
				<div class="flex items-start justify-between gap-3 border-b border-sky-200/80 bg-sky-50 px-4 py-4 sm:px-5">
					<div>
						<h2 id="historical-payment-sheet-title" class="text-base font-semibold text-sky-950">{{ $historicalPaymentBackfill ? 'Add missing transaction' : 'Add historical payment' }}</h2>
						<p class="mt-1 text-xs text-sky-900/80">
							Record a backdated transaction for reporting. Invoice paid and due totals on the header are never changed. Unless you check “Do not increase wallet balance” below, the payment is posted to the mapped wallet and appears in wallet transaction history (use the payment date when filtering).
						</p>
					</div>
					<button type="button" class="rounded-lg p-2 text-sky-800 hover:bg-sky-100 hover:text-sky-950" @click="closeHistoricalSheet()" aria-label="Close">
						<svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12" /></svg>
					</button>
				</div>
				<div class="min-h-0 flex-1 overflow-y-auto px-4 py-4 sm:px-5">
					@if ($errors->has('historical_payment'))
						<div class="mb-4 rounded-lg border border-rose-200 bg-rose-50 px-3 py-2 text-sm text-rose-800">{{ $errors->first('historical_payment') }}</div>
					@endif
					<form
						id="invoice-historical-payment-form"
						method="post"
						action="{{ route('sales.invoices.historical-payments.store', $invoice) }}"
						class="space-y-4"
						x-bind:data-confirm="confirmMessage()"
					>
						@csrf
						<input type="hidden" name="_form" value="historical_payment">
						<div>
							<label for="historical-payment-date" class="text-xs font-medium text-sky-950">Payment date</label>
							<input
								id="historical-payment-date"
								type="date"
								name="payment_date"
								value="{{ $historicalPaymentDateInitial }}"
								max="{{ now()->toDateString() }}"
								min="{{ $invoice->invoice_date?->toDateString() }}"
								class="mt-1 w-full rounded-lg border border-sky-300/90 bg-white px-3 py-2 text-sm"
								required
							>
							@error('payment_date')
								<p class="mt-1 text-xs text-rose-700">{{ $message }}</p>
							@enderror
						</div>
						<div>
							<label for="historical-payment-amount" class="text-xs font-medium text-sky-950">
								@if ($allowsFlexiblePaymentCollection)
									Amount
								@else
									Amount (max {{ number_format($historicalPaymentMaxAmount, 2) }})
								@endif
							</label>
							<input
								id="historical-payment-amount"
								type="number"
								name="amount"
								value="{{ $historicalPaymentAmountInitial }}"
								step="0.01"
								min="0.01"
								@if (! $allowsFlexiblePaymentCollection)
									max="{{ number_format($historicalPaymentMaxAmount, 2, '.', '') }}"
								@endif
								class="mt-1 w-full rounded-lg border border-sky-300/90 bg-white px-3 py-2 text-sm"
								required
							>
							@error('amount')
								<p class="mt-1 text-xs text-rose-700">{{ $message }}</p>
							@enderror
						</div>
						@can('apply-discount')
							<div>
								<label for="historical-settlement-discount" class="text-xs font-medium text-sky-950">Settlement discount (optional)</label>
								<input
									id="historical-settlement-discount"
									type="number"
									name="settlement_discount"
									value="{{ old('settlement_discount', 0) }}"
									step="0.01"
									min="0"
									class="mt-1 w-full rounded-lg border border-sky-300/90 bg-white px-3 py-2 text-sm"
								>
								@error('settlement_discount')
									<p class="mt-1 text-xs text-rose-700">{{ $message }}</p>
								@enderror
							</div>
						@endcan
						<div>
							<label for="historical-payment-method" class="text-xs font-medium text-sky-950">Payment method</label>
							@if (($paymentMethodsForBranch ?? collect())->isEmpty())
								<p class="mt-1 text-xs text-rose-700">No payment methods are configured for this branch. Add payment methods before recording a historical payment.</p>
							@endif
							<select id="historical-payment-method" name="payment_method_id" class="mt-1 w-full rounded-lg border border-sky-300/90 bg-white px-3 py-2 text-sm" required @disabled(($paymentMethodsForBranch ?? collect())->isEmpty())>
								<option value="">Select method…</option>
								@foreach ($paymentMethodsForBranch as $method)
									<option value="{{ $method->id }}" @selected((int) old('payment_method_id') === (int) $method->id)>{{ $method->name }}</option>
								@endforeach
							</select>
							@error('payment_method_id')
								<p class="mt-1 text-xs text-rose-700">{{ $message }}</p>
							@enderror
						</div>
						<div class="rounded-lg border border-sky-200 bg-white px-3 py-2.5">
							<label class="flex cursor-pointer items-start gap-2.5">
								<input
									type="hidden"
									name="skip_wallet_gl"
									value="0"
								>
								<input
									type="checkbox"
									name="skip_wallet_gl"
									value="1"
									class="mt-0.5 rounded border-sky-300 text-sky-900 focus:ring-sky-600"
									x-model="skipWalletGl"
									@checked(old('skip_wallet_gl'))
								>
								<span class="text-sm text-sky-950">
									<span class="font-medium">Do not increase wallet balance</span>
									<span class="mt-0.5 block text-xs text-sky-900/75">Posts to historical clearing only — the entry will not appear in wallet transaction history or change cash on hand.</span>
								</span>
							</label>
						</div>
						<div>
							<label for="historical-payment-reference" class="text-xs font-medium text-sky-950">Reference (optional)</label>
							<input id="historical-payment-reference" type="text" name="reference" value="{{ old('reference') }}" maxlength="100" class="mt-1 w-full rounded-lg border border-sky-300/90 bg-white px-3 py-2 text-sm" placeholder="Receipt / bank ref.">
							@error('reference')
								<p class="mt-1 text-xs text-rose-700">{{ $message }}</p>
							@enderror
						</div>
						<div>
							<label for="historical-payment-notes" class="text-xs font-medium text-sky-950">Note (optional)</label>
							<textarea id="historical-payment-notes" name="notes" rows="2" maxlength="500" class="mt-1 w-full rounded-lg border border-sky-300/90 bg-white px-3 py-2 text-sm" placeholder="Why this backdated entry is being recorded…">{{ old('notes') }}</textarea>
							@error('notes')
								<p class="mt-1 text-xs text-rose-700">{{ $message }}</p>
							@enderror
						</div>
						@if ($invoice->sale_type === 'installment' && $invoice->installmentPlan)
							<div>
								<span class="text-xs font-medium text-sky-950">Installment allocation</span>
								<div class="mt-2 space-y-2 text-sm">
									@if ($historicalAdvanceMax > 0.009)
										<label class="flex items-start gap-2 rounded-lg border border-sky-200 bg-white px-3 py-2 has-[:checked]:border-sky-700 has-[:checked]:ring-1 has-[:checked]:ring-sky-700">
											<input type="radio" name="installment_target" value="advance" class="mt-0.5" x-model="installmentTarget" @checked(old('installment_target') === 'advance')>
											<span>
												<span class="font-medium text-sky-950">Advance (down payment)</span>
												<span class="mt-0.5 block text-xs text-sky-900/75">
													Records as installment advance — does not apply to schedule rows.
													@if ($historicalAdvanceConfigured > 0.009)
														Configured advance {{ number_format($historicalAdvanceConfigured, 2) }} · up to {{ number_format($historicalAdvanceMax, 2) }} remaining.
													@else
														Up to {{ number_format($historicalAdvanceMax, 2) }}.
													@endif
												</span>
											</span>
										</label>
									@endif
									<label class="flex items-center gap-2">
										<input type="radio" name="installment_target" value="waterfall" x-model="installmentTarget" @checked(old('installment_target', 'waterfall') === 'waterfall')>
										<span>Automatic (earliest unpaid first)</span>
									</label>
									@if ($targetableSchedules->isNotEmpty())
										<label class="flex items-center gap-2">
											<input type="radio" name="installment_target" value="schedule" x-model="installmentTarget" @checked(old('installment_target') === 'schedule')>
											<span>Specific installment{{ $allowsFlexiblePaymentCollection ? ' (any row)' : '' }}</span>
										</label>
										<select name="installment_schedule_id" class="w-full rounded-lg border border-sky-300/90 bg-white px-3 py-2 text-sm" x-show="installmentTarget === 'schedule'" x-cloak>
											<option value="">Choose installment…</option>
											@foreach ($targetableSchedules as $schedule)
												<option value="{{ $schedule->id }}" @selected((int) old('installment_schedule_id') === (int) $schedule->id)>
													#{{ $schedule->installment_no }} · due {{ $schedule->due_date?->toDateString() }} · {{ number_format($schedule->remainingDue(), 2) }} remaining{{ $schedule->isFullyPaid() ? ' (paid)' : '' }}
												</option>
											@endforeach
										</select>
									@endif
								</div>
								@error('installment_schedule_id')
									<p class="mt-1 text-xs text-rose-700">{{ $message }}</p>
								@enderror
							</div>
						@endif
					</form>
				</div>
				<div class="shrink-0 border-t border-sky-200/80 bg-sky-50/95 px-4 py-3 backdrop-blur-sm sm:px-5 pb-[max(0.75rem,env(safe-area-inset-bottom,0px))]">
					<button type="submit" form="invoice-historical-payment-form" class="w-full rounded-lg bg-sky-900 px-4 py-2.5 text-sm font-medium text-white hover:bg-sky-950">Record historical payment</button>
				</div>
			</div>
		</div>
	@endif

	@if ($invoice->status === 'draft' && $invoice->sale_type !== 'installment')
		{{-- Post invoice (draft, non-installment) --}}
		<div
			x-show="postOpen"
			x-cloak
			class="fixed inset-0 z-[100] flex justify-end"
			role="dialog"
			aria-modal="true"
			aria-labelledby="post-invoice-sheet-title"
		>
			<div class="absolute inset-0 bg-slate-900/40" @click="closePostSheet()" aria-hidden="true"></div>
			<div
				class="relative flex h-full w-full max-w-md flex-col bg-white shadow-2xl ring-1 ring-slate-900/10"
				@click.stop
			>
				<div class="flex items-start justify-between gap-3 border-b border-slate-200 px-4 py-4 sm:px-5">
					<div>
						<h2 id="post-invoice-sheet-title" class="text-base font-semibold text-slate-900">Post invoice</h2>
						<p class="mt-1 text-xs text-slate-500">Finalize {{ $invoice->invoice_number ?? 'this draft' }} and update stock. Record payments from <span class="font-medium">Payment register</span> after posting.</p>
					</div>
					<button type="button" class="rounded-lg p-2 text-slate-500 hover:bg-slate-100 hover:text-slate-800" @click="closePostSheet()" aria-label="Close">
						<svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12" /></svg>
					</button>
				</div>
				<div class="min-h-0 flex-1 overflow-y-auto px-4 py-4 sm:px-5">
					<div class="mb-4 rounded-lg border border-slate-200 bg-slate-50 p-3 text-xs text-slate-700">
						<p class="font-medium text-slate-800">Grand total: {{ number_format($invoice->grand_total, 2) }}</p>
						<p class="mt-1 text-slate-600">Posting locks in this total and issues inventory. Use Payment register to collect payment after the invoice is posted.</p>
					</div>
					<form action="{{ route('sales.invoices.post', $invoice) }}" method="post" class="space-y-4">
						@csrf
						<button type="submit" class="w-full rounded-lg bg-blue-600 px-4 py-2.5 text-sm font-medium text-white hover:bg-blue-700">Confirm &amp; post</button>
					</form>
				</div>
			</div>
		</div>
	@endif

	@if ($invoice->status === 'draft' && $invoice->sale_type === 'installment')
		{{-- Activate installment draft --}}
		<div
			x-show="activateOpen"
			x-cloak
			class="fixed inset-0 z-[100] flex justify-end"
			role="dialog"
			aria-modal="true"
			aria-labelledby="activate-installment-sheet-title"
		>
			<div class="absolute inset-0 bg-slate-900/40" @click="closeActivateSheet()" aria-hidden="true"></div>
			<div
				class="relative flex h-full w-full max-w-md flex-col bg-white shadow-2xl ring-1 ring-slate-900/10"
				@click.stop
			>
				<div class="flex items-start justify-between gap-3 border-b border-slate-200 px-4 py-4 sm:px-5">
					<div>
						<h2 id="activate-installment-sheet-title" class="text-base font-semibold text-slate-900">Activate installment</h2>
						<p class="mt-1 text-xs text-slate-500">Post this draft, create the installment plan, and reserve stock. If there is an advance, it is charged using the first payment method that has a wallet map for this branch.</p>
					</div>
					<button type="button" class="rounded-lg p-2 text-slate-500 hover:bg-slate-100 hover:text-slate-800" @click="closeActivateSheet()" aria-label="Close">
						<svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12" /></svg>
					</button>
				</div>
				<div class="min-h-0 flex-1 overflow-y-auto px-4 py-4 sm:px-5">
					<div class="mb-4 rounded-lg border border-blue-100 bg-blue-50/80 p-3 text-xs text-slate-800">
						<p><span class="font-semibold">Total:</span> {{ number_format($invoice->grand_total, 2) }}</p>
						@if ((float) ($invoice->installment_down_payment ?? 0) > 0)
							<p class="mt-1"><span class="font-semibold">Advance at activation:</span> {{ number_format((float) $invoice->installment_down_payment, 2) }}</p>
						@endif
					</div>
					<p class="text-xs text-slate-600">This will post the draft, create the installment schedule, and reserve stock. Collect further payments from <span class="font-medium">Payment register</span> after activation.</p>
					<form id="invoice-activate-installment-form" action="{{ route('sales.invoices.activate-installment', $invoice) }}" method="post" class="mt-4">
						@csrf
					</form>
				</div>
				<div class="shrink-0 border-t border-slate-200 bg-slate-50 px-4 py-3 sm:px-5 pb-[max(0.75rem,env(safe-area-inset-bottom,0px))]">
					<button type="submit" form="invoice-activate-installment-form" class="w-full rounded-lg bg-blue-600 px-4 py-2.5 text-sm font-medium text-white hover:bg-blue-700">Confirm &amp; activate</button>
				</div>
			</div>
		</div>
	@endif

	@if ($invoice->sale_type === 'installment')
		{{-- Guarantor and Verification Sheet --}}
		<div
			x-show="guarantorOpen"
			x-cloak
			class="fixed inset-0 z-[100] flex justify-end"
			role="dialog"
			aria-modal="true"
			aria-labelledby="guarantor-sheet-title"
		>
			<div class="absolute inset-0 bg-slate-900/40" @click="guarantorOpen = false" aria-hidden="true"></div>
			<div
				class="relative flex h-full w-full max-w-md flex-col bg-white shadow-2xl ring-1 ring-slate-900/10"
				@click.stop
			>
				<div class="flex items-start justify-between gap-3 border-b border-slate-200 px-4 py-4 sm:px-5">
					<div>
						<h2 id="guarantor-sheet-title" class="text-base font-semibold text-slate-900">Manage Guarantor &amp; Verification</h2>
						<p class="mt-1 text-xs text-slate-500">Link a guarantor customer and upload NID or verification attachments for this invoice.</p>
					</div>
					<button type="button" class="rounded-lg p-2 text-slate-500 hover:bg-slate-100 hover:text-slate-800" @click="guarantorOpen = false" aria-label="Close">
						<svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12" /></svg>
					</button>
				</div>
				<div class="min-h-0 flex-1 overflow-y-auto px-4 py-4 sm:px-5">
					<form id="guarantor-form" action="{{ route('sales.invoices.guarantor', $invoice) }}" method="post" enctype="multipart/form-data" class="space-y-4">
						@csrf
						<input type="hidden" name="_form" value="guarantor">

						<div x-data="{ source: '{{ old('guarantor_source', $invoice->guarantor_id ? 'existing' : ($invoice->guarantor_name ? 'new' : 'keep')) }}' }">
							<div>
								<label class="text-xs font-semibold text-slate-700 block">Guarantor Option</label>
								<div class="mt-2 grid grid-cols-2 gap-2">
									@if ($invoice->guarantor_id || $invoice->guarantor_name)
										<label class="flex items-center justify-center rounded-lg border p-2 text-xs font-medium cursor-pointer" :class="source === 'keep' ? 'border-indigo-600 bg-indigo-50 text-indigo-900' : 'border-slate-200 bg-white text-slate-700'">
											<input type="radio" name="guarantor_source" value="keep" x-model="source" class="sr-only">
											Keep Current
										</label>
									@endif
									<label class="flex items-center justify-center rounded-lg border p-2 text-xs font-medium cursor-pointer" :class="source === 'existing' ? 'border-indigo-600 bg-indigo-50 text-indigo-900' : 'border-slate-200 bg-white text-slate-700'">
										<input type="radio" name="guarantor_source" value="existing" x-model="source" class="sr-only">
										Select Existing
									</label>
									<label class="flex items-center justify-center rounded-lg border p-2 text-xs font-medium cursor-pointer" :class="source === 'new' ? 'border-indigo-600 bg-indigo-50 text-indigo-900' : 'border-slate-200 bg-white text-slate-700'">
										<input type="radio" name="guarantor_source" value="new" x-model="source" class="sr-only">
										Add New Customer
									</label>
									<label class="flex items-center justify-center rounded-lg border p-2 text-xs font-medium cursor-pointer" :class="source === 'none' ? 'border-rose-600 bg-rose-50 text-rose-900' : 'border-slate-200 bg-white text-slate-700'">
										<input type="radio" name="guarantor_source" value="none" x-model="source" class="sr-only">
										No Guarantor
									</label>
								</div>
							</div>

							<!-- Option 1: Existing Customer -->
							<div x-show="source === 'existing'" class="mt-4 space-y-3" x-cloak>
								<div>
									<label class="text-xs font-medium text-slate-700">Select Customer as Guarantor</label>
									@php
										$customerOptions = $customers->map(fn ($c) => [
											'value' => $c->id,
											'label' => $c->name . ' (' . $c->customer_code . ($c->phone ? ' · ' . $c->phone : '') . ')'
										])->values()->all();
									@endphp
									<div class="mt-1">
										<x-searchable-select name="guarantor_id" :options="$customerOptions" :selected="old('guarantor_id', $invoice->guarantor_id)" placeholder="Search by name, code, or phone…" />
									</div>
								</div>
								<div>
									<label for="guarantor_address_existing" class="text-xs font-medium text-slate-700">Address</label>
									<textarea name="guarantor_address" id="guarantor_address_existing" rows="2" class="mt-1 w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm" placeholder="Guarantor address">{{ old('guarantor_address', $invoice->guarantor_address) }}</textarea>
								</div>
								<div>
									<label for="guarantor_nid_number_existing" class="text-xs font-medium text-slate-700">NID Number</label>
									<input type="text" name="guarantor_nid_number" id="guarantor_nid_number_existing" class="mt-1 w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm" placeholder="National ID number" value="{{ old('guarantor_nid_number', $invoice->guarantor_nid_number) }}">
								</div>
								<div>
									<label for="guarantor_notes_existing" class="text-xs font-medium text-slate-700">Guarantor Notes (Optional)</label>
									<textarea name="guarantor_notes" id="guarantor_notes_existing" rows="2" class="mt-1 w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm" placeholder="Any additional notes about the guarantor">{{ old('guarantor_notes', $invoice->guarantor_notes) }}</textarea>
								</div>
							</div>

							<!-- Option 2: Create New Customer -->
							<div x-show="source === 'new'" class="mt-4 space-y-3" x-cloak>
								<div>
									<label for="guarantor_name" class="text-xs font-medium text-slate-700">Guarantor/Customer Name *</label>
									<input type="text" name="guarantor_name" id="guarantor_name" class="mt-1 w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm" value="{{ old('guarantor_name', $invoice->guarantor_name) }}">
								</div>
								<div>
									<label for="guarantor_phone" class="text-xs font-medium text-slate-700">Phone Number *</label>
									<input type="text" name="guarantor_phone" id="guarantor_phone" class="mt-1 w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm" value="{{ old('guarantor_phone', isset($invoice->guarantor_phone_numbers[0]) ? $invoice->guarantor_phone_numbers[0] : '') }}">
								</div>
								<div>
									<label for="guarantor_email" class="text-xs font-medium text-slate-700">Email Address</label>
									<input type="email" name="guarantor_email" id="guarantor_email" class="mt-1 w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm" value="{{ old('guarantor_email') }}">
								</div>
								<div>
									<label for="guarantor_address" class="text-xs font-medium text-slate-700">Address *</label>
									<textarea name="guarantor_address" id="guarantor_address" rows="2" class="mt-1 w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm" required>{{ old('guarantor_address', $invoice->guarantor_address) }}</textarea>
								</div>
								<div>
									<label for="guarantor_nid_number_new" class="text-xs font-medium text-slate-700">NID Number *</label>
									<input type="text" name="guarantor_nid_number" id="guarantor_nid_number_new" class="mt-1 w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm" placeholder="National ID number" value="{{ old('guarantor_nid_number', $invoice->guarantor_nid_number) }}" required>
								</div>
								<div>
									<label for="guarantor_notes_new" class="text-xs font-medium text-slate-700">Guarantor Notes (Optional)</label>
									<textarea name="guarantor_notes" id="guarantor_notes_new" rows="2" class="mt-1 w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm" placeholder="Any additional notes about the guarantor">{{ old('guarantor_notes', $invoice->guarantor_notes) }}</textarea>
								</div>
							</div>
						</div>

						<div class="mt-6 border-t border-slate-200 pt-4 space-y-4">
							<h3 class="text-xs font-semibold text-slate-800 uppercase tracking-wider">Verification Documents</h3>

							<!-- Customer NID Upload -->
							<div>
								<label class="text-xs font-medium text-slate-700 block">Customer NID File</label>
								@if ($invoice->customer_nid_path)
									<div class="mt-1 flex items-center justify-between rounded-lg border border-slate-200 bg-slate-50 p-2 text-xs">
										<span class="truncate font-mono text-slate-600" title="{{ $invoice->customer_nid_path }}">{{ basename($invoice->customer_nid_path) }}</span>
										<label class="inline-flex items-center gap-1 text-rose-600 cursor-pointer select-none">
											<input type="checkbox" name="remove_customer_nid" value="1" class="rounded border-slate-300 text-rose-600 focus:ring-rose-500">
											Remove
										</label>
									</div>
								@endif
								<input type="file" name="customer_nid" class="mt-1 block w-full text-xs text-slate-500 file:mr-4 file:py-2 file:px-4 file:rounded-md file:border-0 file:text-xs file:font-semibold file:bg-slate-50 file:text-slate-700 hover:file:bg-slate-100">
							</div>

							<!-- Guarantor NID Upload -->
							<div>
								<label class="text-xs font-medium text-slate-700 block">Guarantor NID File</label>
								@if ($invoice->guarantor_nid_path)
									<div class="mt-1 flex items-center justify-between rounded-lg border border-slate-200 bg-slate-50 p-2 text-xs">
										<span class="truncate font-mono text-slate-600" title="{{ $invoice->guarantor_nid_path }}">{{ basename($invoice->guarantor_nid_path) }}</span>
										<label class="inline-flex items-center gap-1 text-rose-600 cursor-pointer select-none">
											<input type="checkbox" name="remove_guarantor_nid" value="1" class="rounded border-slate-300 text-rose-600 focus:ring-rose-500">
											Remove
										</label>
									</div>
								@endif
								<input type="file" name="guarantor_nid" class="mt-1 block w-full text-xs text-slate-500 file:mr-4 file:py-2 file:px-4 file:rounded-md file:border-0 file:text-xs file:font-semibold file:bg-slate-50 file:text-slate-700 hover:file:bg-slate-100">
							</div>

							<!-- Other Verification Attachments Upload -->
							<div>
								<label class="text-xs font-medium text-slate-700 block">Upload Verification Attachments</label>
								@if ($invoice->verification_attachments && count($invoice->verification_attachments) > 0)
									<div class="mt-1 space-y-1.5">
										@foreach ($invoice->verification_attachments as $idx => $path)
											<div class="flex items-center justify-between rounded-lg border border-slate-200 bg-slate-50 p-2 text-xs">
												<span class="truncate font-mono text-slate-600" title="{{ $path }}">{{ basename($path) }}</span>
												<label class="inline-flex items-center gap-1 text-rose-600 cursor-pointer select-none">
													<input type="checkbox" name="remove_attachments[]" value="{{ $path }}" class="rounded border-slate-300 text-rose-600 focus:ring-rose-500">
													Remove
												</label>
											</div>
										@endforeach
									</div>
								@endif
								<input type="file" name="new_attachments[]" multiple class="mt-2 block w-full text-xs text-slate-500 file:mr-4 file:py-2 file:px-4 file:rounded-md file:border-0 file:text-xs file:font-semibold file:bg-slate-50 file:text-slate-700 hover:file:bg-slate-100">
								<p class="text-[10px] text-slate-400 mt-1">You can select multiple files at once.</p>
							</div>
						</div>
					</form>
				</div>
				<div class="shrink-0 border-t border-slate-200 bg-slate-50 px-4 py-3 sm:px-5 pb-[max(0.75rem,env(safe-area-inset-bottom,0px))]">
					<button type="submit" form="guarantor-form" class="w-full rounded-lg bg-blue-600 px-4 py-2.5 text-sm font-medium text-white hover:bg-blue-700">Save Changes</button>
				</div>
			</div>
		</div>
	@endif

	@if ($canChangeBranch)
		<div
			x-show="branchOpen"
			x-cloak
			class="fixed inset-0 z-[100] flex justify-end"
			role="dialog"
			aria-modal="true"
			aria-labelledby="branch-sheet-title"
		>
			<div class="absolute inset-0 bg-slate-900/40" @click="branchOpen = false" aria-hidden="true"></div>
			<div
				class="relative flex h-full w-full max-w-md flex-col bg-white shadow-2xl ring-1 ring-slate-900/10"
				@click.stop
			>
				<div class="flex items-start justify-between gap-3 border-b border-slate-200 px-4 py-4 sm:px-5">
					<div>
						<h2 id="branch-sheet-title" class="text-base font-semibold text-slate-900">Change stock location</h2>
						<p class="mt-1 text-xs text-slate-500">
							Move invoice {{ $invoice->invoice_number ?? '#'.$invoice->id }} from
							<strong>{{ $invoice->branch?->name ?? '—' }}</strong>
							to another branch or warehouse.
							@if ($invoice->status === 'draft')
								Assigned serial numbers will be cleared; re-select them before posting.
							@else
								Stock ledger and invoice GL will follow the new location. Payment entries stay on their original branch.
							@endif
						</p>
					</div>
					<button type="button" class="rounded-lg p-2 text-slate-500 hover:bg-slate-100 hover:text-slate-800" @click="branchOpen = false" aria-label="Close">
						<svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12" /></svg>
					</button>
				</div>
				<div class="min-h-0 flex-1 overflow-y-auto px-4 py-4 sm:px-5">
					<form id="invoice-branch-form" method="post" action="{{ route('sales.invoices.branch', $invoice) }}" class="space-y-4" data-confirm="Change the stock location for this invoice?">
						@csrf
						<input type="hidden" name="_form" value="branch">
						<div>
							<label for="invoice-branch-id" class="text-xs font-medium text-slate-700">New stock location</label>
							<select id="invoice-branch-id" name="branch_id" class="mt-1 w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm" required>
								<option value="">Select branch or warehouse</option>
								@foreach ($branchChangeLocations as $location)
									<option value="{{ $location->id }}" @selected((string) old('branch_id') === (string) $location->id)>{{ $location->name }}</option>
								@endforeach
							</select>
							@error('branch_id')
								<p class="mt-1 text-xs text-rose-700">{{ $message }}</p>
							@enderror
						</div>
					</form>
				</div>
				<div class="shrink-0 border-t border-slate-200 bg-slate-50 px-4 py-3 sm:px-5 pb-[max(0.75rem,env(safe-area-inset-bottom,0px))]">
					<button type="submit" form="invoice-branch-form" class="w-full rounded-lg bg-indigo-600 px-4 py-2.5 text-sm font-medium text-white hover:bg-indigo-700">Save location</button>
				</div>
			</div>
		</div>
	@endif

	@include('sales.invoices.partials._change_sale_type_sheet')
</div>

@endsection
