@php
    $canApplyLineDiscount = (bool) ($canApplyLineDiscount ?? (auth()->check() && auth()->user()?->can('apply-discount')));
    $canViewLineProfit = (bool) ($canViewLineProfit ?? (auth()->check() && auth()->user()?->can('view-line-profit')));
    $canBackdateInvoice = (bool) (auth()->check() && auth()->user()?->can('backdate-sales-invoice'));
    $invoiceDateMin = $canBackdateInvoice ? null : now()->subDays(2)->toDateString();
    $invoiceDateMax = now()->toDateString();
    $draftFormMode = $draftFormMode ?? 'create';
    $editingInvoice = $editingInvoice ?? null;
    $postedEditMode = ! empty($postedEditMode ?? false);
    $installmentAdminEditMode = ! empty($installmentAdminEditMode ?? false);
    $initialDraftLines = $initialDraftLines ?? [];
    $editInv = $draftFormMode === 'edit' ? $editingInvoice : null;
    $guarantorPhonesDefault = '';
    if ($editInv && is_array($editInv->guarantor_phone_numbers)) {
        $guarantorPhonesDefault = implode("\n", $editInv->guarantor_phone_numbers);
    }
    $referrerEmployees = $referrerEmployees ?? $staffUsers ?? \App\Models\Employee::query()->where('is_active', true)->orderBy('employee_code')->orderBy('first_name')->get(['id', 'employee_code', 'first_name', 'last_name']);
    $referrerOptions = $referrerEmployees->map(fn ($employee) => [
        'value' => $employee->id,
        'label' => $employee->displayLabel(),
    ])->values()->all();
    $selectedReferrerId = old('referrer_id', $editInv?->referrer_id ?? '');
@endphp
<form action="{{ $draftFormMode === 'edit' ? route('sales.invoices.update', $editingInvoice) : route('sales.invoices.store') }}" method="post" enctype="multipart/form-data" class="space-y-0" id="invoice-form">
@csrf
@if ($draftFormMode === 'edit')
@method('PUT')
@endif

<div class="mx-auto w-full max-w-6xl space-y-3 px-4 pb-6 pt-1 sm:px-6">
@if ($draftFormMode === 'edit' && $editingInvoice)
<div class="flex items-center justify-between">
	<a href="{{ route('sales.invoices.show', $editingInvoice) }}" class="inline-flex items-center gap-1.5 rounded-md border border-slate-300 bg-white px-3 py-1.5 text-xs font-medium text-slate-700 shadow-sm hover:bg-slate-50">
		<svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M10.5 19.5 3 12m0 0 7.5-7.5M3 12h18" /></svg>
		Back to invoice Details
	</a>
</div>
@endif
@if ($errors->any())
<div class="mb-3 rounded-lg border border-rose-200 bg-rose-50 px-2 py-1.5 text-xs text-rose-700">
	<ul class="list-disc pl-5">
		@foreach ($errors->all() as $error)
			<li>{{ $error }}</li>
		@endforeach
	</ul>
</div>
@endif

@php
	$defaultInvoiceBranchId = old('branch_id');
	if ($defaultInvoiceBranchId === null && $draftFormMode === 'edit' && $editingInvoice) {
		$defaultInvoiceBranchId = $editingInvoice->branch_id;
	}
	if ($defaultInvoiceBranchId === null && $draftFormMode === 'create') {
		$ctxBranchId = app(\App\Support\InventoryLocationContext::class)->currentId();
		if ($ctxBranchId !== null && $locations->contains('id', $ctxBranchId)) {
			$defaultInvoiceBranchId = $ctxBranchId;
		}
	}
	if ($defaultInvoiceBranchId === null && $locations->count() === 1) {
		$defaultInvoiceBranchId = $locations->first()->id;
	}
@endphp
<section class="rounded-xl border border-slate-200 bg-white p-3 shadow-sm sm:p-4">
	<h2 class="mb-2 text-sm font-semibold text-slate-800">1. Basic info</h2>
	@if ($postedEditMode && $editingInvoice)
		<input type="hidden" name="customer_id" value="{{ $editingInvoice->customer_id }}">
		<input type="hidden" name="branch_id" value="{{ $editingInvoice->branch_id }}">
		<input type="hidden" name="sale_type" value="{{ $editingInvoice->sale_type }}">
		<div class="grid gap-2 sm:grid-cols-2 lg:grid-cols-3">
		<div>
			<label class="text-xs font-semibold text-slate-700">Invoice date</label>
			<input type="date" name="invoice_date" value="{{ old('invoice_date', $editingInvoice->invoice_date?->toDateString()) }}" class="mt-0.5 w-full rounded-md border border-slate-300 px-2 py-1.5 text-sm" required @if($invoiceDateMin) min="{{ $invoiceDateMin }}" @endif max="{{ $invoiceDateMax }}">
		</div>
		<div class="rounded-md border border-slate-100 bg-slate-50/90 px-2 py-2">
			<p class="text-[10px] font-semibold uppercase tracking-wide text-slate-500">Stock location</p>
			<p class="mt-1 text-sm font-medium text-slate-900">{{ $editingInvoice->branch?->name ?? '—' }}</p>
		</div>
			<div class="rounded-md border border-slate-100 bg-slate-50/90 px-2 py-2 sm:col-span-2 lg:col-span-1">
				<p class="text-[10px] font-semibold uppercase tracking-wide text-slate-500">Customer</p>
				<p class="mt-1 text-sm font-medium text-slate-900">{{ $editingInvoice->customer?->name ?? '—' }}</p>
				<p class="mt-0.5 text-[11px] text-slate-600">Customer, branch, and sale type are fixed for posted invoices.</p>
			</div>
			<div class="sm:col-span-2 lg:col-span-1">
				<label class="text-xs font-semibold text-slate-700">Referrer (employee)</label>
				<x-searchable-select name="referrer_id" :options="$referrerOptions" :selected="$selectedReferrerId" placeholder="Search by name or code…" />
				<p class="mt-1 text-[11px] text-slate-500">HR employee who referred this sale.</p>
			</div>
		</div>
	@else
	<div class="grid gap-2 sm:grid-cols-2 lg:grid-cols-3">
		<div>
			<label class="text-xs font-semibold text-slate-700">Invoice date</label>
			<input type="date" name="invoice_date" value="{{ old('invoice_date', $draftFormMode === 'edit' && $editingInvoice ? $editingInvoice->invoice_date?->toDateString() : now()->toDateString()) }}" class="mt-0.5 w-full rounded-md border border-slate-300 px-2 py-1.5 text-sm" required @if($invoiceDateMin) min="{{ $invoiceDateMin }}" @endif max="{{ $invoiceDateMax }}">
		</div>
		<div>
			<label class="text-xs font-semibold text-slate-700">Stock location <span class="text-rose-500">*</span></label>
			<select name="branch_id" class="mt-0.5 w-full rounded-md border border-slate-300 px-2 py-1.5 text-sm" required>
				<option value="">Select branch or warehouse</option>
				@foreach($locations as $location)
					<option value="{{ $location->id }}" @selected((string) $defaultInvoiceBranchId === (string) $location->id)>{{ $location->name }}</option>
				@endforeach
			</select>
		</div>
		<div class="sm:col-span-2 lg:col-span-1">
			<label class="text-xs font-semibold text-slate-700">Customer <span class="text-rose-500">*</span></label>
			<div class="relative mt-1" id="customer-combobox-wrap">
				<div class="flex gap-1.5">
					<div class="relative flex-1">
						<input type="text" id="customer-search" autocomplete="new-password" placeholder="Search name, code, or phone…" class="w-full rounded-md border border-slate-300 px-2 py-1.5 pr-7 text-sm" aria-label="Search customer" role="combobox" aria-expanded="false" aria-autocomplete="list">
						<button type="button" id="clear-customer" class="absolute inset-y-0 cursor-pointer right-1.5 hidden items-center text-rose-500 hover:text-rose-700" aria-label="Clear customer" tabindex="-1">
							<svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12" /></svg>
						</button>
					</div>
					<button type="button" id="open-customer-drawer" class="shrink-0 rounded-md border border-slate-300 px-2 py-1.5 text-xs font-medium hover:bg-slate-50">New</button>
				</div>
				<div id="customer-dropdown" class="absolute left-0 right-0 z-50 mt-0.5 hidden max-h-56 overflow-y-auto overscroll-contain rounded-md border border-slate-200 bg-white py-0.5 shadow-lg text-sm"></div>
				<input type="hidden" name="customer_id" id="customer-id-hidden" value="{{ old('customer_id', $draftFormMode === 'edit' && $editingInvoice ? $editingInvoice->customer_id : '') }}">
			</div>
		</div>
		<div class="sm:col-span-2 lg:col-span-1">
			<label class="text-xs font-semibold text-slate-700">Referrer (employee) <span class="text-rose-500">*</span></label>
			<x-searchable-select name="referrer_id" :options="$referrerOptions" :selected="$selectedReferrerId" placeholder="Search by name or code…" :dispatchChange="true" />
			<p class="mt-1 text-[11px] text-slate-500">HR employee who referred this sale.</p>
		</div>
	</div>
	@endif
</section>

<section class="rounded-xl border border-slate-200 bg-white p-3 shadow-sm sm:p-4" aria-labelledby="product-lines-heading">
	<div class="mb-3 flex flex-col gap-2 sm:flex-row sm:items-start sm:justify-between">
		<div>
			<h2 id="product-lines-heading" class="text-sm font-semibold text-slate-800">2. Lines</h2>
			<p class="mt-1 max-w-2xl text-xs leading-relaxed text-slate-600">Each block is one invoice line. For <strong>serialized</strong> products, use the scanner or pick serials per unit. Non-serialized products only need quantity and price.</p>
		</div>
		<button type="button" id="add-line" class="shrink-0 rounded-lg border border-indigo-200 bg-indigo-50 px-3 py-2 text-xs font-semibold text-indigo-900 hover:bg-indigo-100 sm:self-start">+ Add line</button>
	</div>
	<div class="mb-3 rounded-xl border border-slate-200 bg-slate-50/80 px-3 py-2 shadow-sm ring-1 ring-slate-900/[0.04] sm:flex sm:flex-row sm:items-center sm:gap-3">
		<div class="min-w-0 flex-1">
			<label for="invoice-barcode-scan-input" class="block text-[11px] font-semibold uppercase tracking-wide text-slate-600">Serial scanner</label>
			<p class="mt-0.5 text-[11px] text-slate-500">Scans your unit’s <strong>serial</strong> (not SKU) at this branch. Wedge readers send <kbd class="rounded border border-slate-300 bg-white px-0.5">Enter</kbd> after the code. <kbd class="rounded border border-slate-300 bg-white px-0.5">F2</kbd> focuses this field.</p>
		</div>
		<div class="relative mt-2 min-w-[12rem] max-w-xl flex-1 sm:mt-0">
			<input type="text" id="invoice-barcode-scan-input" inputmode="none" autocomplete="off" tabindex="0"
				class="w-full rounded-lg border border-emerald-200 bg-white px-3 py-2 text-sm shadow-sm outline-none ring-emerald-500/15 transition focus:border-emerald-600 focus:ring-2 focus:ring-emerald-500/30"
				placeholder="Scan serial, then Enter…"
				title="Press F2 to focus. Resolves to an in-stock unit at the selected location by asset or manufacturer serial."
				data-scan-gateway>
			<span class="pointer-events-none absolute right-2.5 top-1/2 hidden -translate-y-1/2 text-[10px] font-semibold uppercase text-emerald-600/70 sm:inline">Ready</span>
		</div>
	</div>
	<div id="lines-body" class="space-y-4"></div>
</section>

<section class="rounded-xl border border-slate-200 bg-white p-3 shadow-sm sm:p-4">
	<h2 class="mb-2 text-sm font-semibold text-slate-800">3. Payment</h2>
	@if ($postedEditMode && $editingInvoice)
		<div class="rounded-md border border-slate-200 bg-slate-50/80 p-3 text-sm text-slate-800">
			<p><span class="font-semibold">Sale type:</span> {{ ucfirst((string) $editingInvoice->sale_type) }}</p>
			@if ($editingInvoice->sale_type === 'credit')
				@php
					$existingCreditDue = $editingInvoice->credit_due_date;
					$postedEditMaxDate = $existingCreditDue ?? now()->addDays(30);
				@endphp
				<div class="mt-3">
					<label class="text-xs font-semibold text-slate-700">Credit due date</label>
					<input type="date" name="credit_due_date" value="{{ old('credit_due_date', $existingCreditDue?->toDateString()) }}" class="mt-0.5 w-full max-w-[12rem] rounded-md border border-slate-300 px-2 py-1.5 text-sm" required max="{{ $postedEditMaxDate->toDateString() }}">
					<p class="mt-1 text-xs text-amber-700">Max: {{ $postedEditMaxDate->format('d M Y') }} {{ $existingCreditDue ? '(original due date)' : '(30 days from today)' }}</p>
				</div>
			@elseif ($installmentAdminEditMode)
				<div class="mt-3 rounded-md border border-amber-200 bg-amber-50/90 p-3 text-xs text-amber-950">
					<p class="font-semibold">Admin installment revision</p>
					<p class="mt-1">Update the plan below, then save. Schedules are rebuilt and existing payments are re-applied in order. Customer, branch, and sale type stay fixed.</p>
				</div>
				<div class="mt-3 grid gap-3 sm:grid-cols-2">
					<div class="sm:col-span-2">
						<label for="plan-grand-total-input" class="text-xs font-semibold text-slate-700">Plan invoice amount</label>
						<input
							type="number"
							name="plan_grand_total"
							id="plan-grand-total-input"
							step="0.01"
							min="0"
							value="{{ old('plan_grand_total', $editingInvoice->grand_total) }}"
							class="mt-0.5 w-full max-w-xs rounded-md border border-slate-300 px-2 py-1.5 text-sm tabular-nums"
							required
						>
						<p class="mt-1 text-[11px] text-slate-600">Total used for the installment plan (can differ from line totals). Must be at least amount already paid.</p>
					</div>
					<div>
						<label class="text-xs font-semibold text-slate-700">Advance / down payment</label>
						<input type="number" step="0.01" min="0" name="installment_down_payment" id="drawer-installment-down-payment" value="{{ old('installment_down_payment', $editingInvoice->installment_down_payment ?? 0) }}" class="mt-0.5 w-full rounded-md border border-slate-300 px-2 py-1.5 text-sm">
					</div>
					<div>
						<label class="text-xs font-semibold text-slate-700">Installment count</label>
						<input type="number" min="1" name="installment_count" id="installment-count" value="{{ old('installment_count', $editingInvoice->installment_count) }}" class="mt-0.5 w-full rounded-md border border-slate-300 px-2 py-1.5 text-sm" required>
					</div>
					<div>
						<label class="text-xs font-semibold text-slate-700">First due date</label>
						<input type="date" name="installment_first_due_date" id="installment-first-due-date" value="{{ old('installment_first_due_date', $editingInvoice->installment_first_due_date?->toDateString()) }}" class="mt-0.5 w-full rounded-md border border-slate-300 px-2 py-1.5 text-sm" required>
					</div>
					<div>
						<label class="text-xs font-semibold text-slate-700">Frequency</label>
						<select name="installment_frequency" id="installment-frequency" class="mt-0.5 w-full rounded-md border border-slate-300 px-2 py-1.5 text-sm">
							<option value="monthly" @selected(old('installment_frequency', $editingInvoice->installment_frequency ?? 'monthly') === 'monthly')>Monthly</option>
							<option value="weekly" @selected(old('installment_frequency', $editingInvoice->installment_frequency ?? 'monthly') === 'weekly')>Weekly</option>
							<option value="daily" @selected(old('installment_frequency', $editingInvoice->installment_frequency ?? 'monthly') === 'daily')>Daily</option>
							<option value="custom" @selected(old('installment_frequency', $editingInvoice->installment_frequency ?? 'monthly') === 'custom')>Custom</option>
						</select>
					</div>
					<div id="installment-custom-days-wrap">
						<label class="text-xs font-semibold text-slate-700">Custom interval (days)</label>
						<input type="number" min="1" name="installment_custom_days" id="installment-custom-days" value="{{ old('installment_custom_days', $editingInvoice->installment_custom_days) }}" class="mt-0.5 w-full rounded-md border border-slate-300 px-2 py-1.5 text-sm">
					</div>
					<div>
						<label class="inline-flex items-center gap-2 text-xs font-semibold text-slate-700">
							<input type="checkbox" name="installment_penalty_enabled" value="1" id="drawer-installment-penalty-enabled" @checked(old('installment_penalty_enabled', $editingInvoice->installment_penalty_enabled))>
							Penalty interest enabled
						</label>
						<input type="number" step="0.0001" min="0" name="installment_penalty_rate" id="drawer-installment-penalty-rate" value="{{ old('installment_penalty_rate', $editingInvoice->installment_penalty_rate ?? '0.02') }}" class="mt-1 w-full max-w-[8rem] rounded-md border border-slate-300 px-2 py-1.5 text-sm">
					</div>
				</div>
				@if ($editingInvoice->installmentPlan?->schedules?->isNotEmpty())
					<div class="mt-4 overflow-x-auto rounded-md border border-slate-200">
						<p class="bg-slate-100 px-2 py-1 text-[10px] font-semibold uppercase tracking-wide text-slate-600">Current schedules (replaced on save)</p>
						<table class="min-w-full text-xs">
							<thead class="bg-slate-50 text-left text-slate-600">
								<tr>
									<th class="px-2 py-1">#</th>
									<th class="px-2 py-1">Due</th>
									<th class="px-2 py-1 text-right">Due amt</th>
									<th class="px-2 py-1 text-right">Paid</th>
								</tr>
							</thead>
							<tbody>
								@foreach ($editingInvoice->installmentPlan->schedules->sortBy('installment_no') as $schedule)
									<tr class="border-t border-slate-100">
										<td class="px-2 py-1">{{ $schedule->installment_no }}</td>
										<td class="px-2 py-1">{{ $schedule->due_date?->toDateString() }}</td>
										<td class="px-2 py-1 text-right tabular-nums">{{ number_format((float) $schedule->due_amount, 2) }}</td>
										<td class="px-2 py-1 text-right tabular-nums">{{ number_format((float) $schedule->paid_amount, 2) }}</td>
									</tr>
								@endforeach
							</tbody>
						</table>
					</div>
				@endif
			@endif
		</div>
	@else
	<div class="rounded-md border border-slate-200 bg-slate-50/80 p-2 sm:p-3">
		<fieldset>
			<legend class="sr-only">Type</legend>
			<div class="grid grid-cols-1 gap-3 sm:grid-cols-3">
				<label class="group relative flex cursor-pointer rounded-xl border border-slate-200 bg-white p-3 shadow-sm focus-within:ring-2 focus-within:ring-slate-500 hover:bg-slate-50 has-[:checked]:border-slate-900 has-[:checked]:ring-1 has-[:checked]:ring-slate-900">
					<input type="radio" name="sale_type" value="default" class="sr-only" @checked(old('sale_type', $draftFormMode === 'edit' && $editingInvoice ? $editingInvoice->sale_type : 'default') === 'default')>
					<div class="flex items-center gap-3 w-full opacity-70 group-has-[:checked]:opacity-100">
						<div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-slate-100 text-slate-500 group-has-[:checked]:bg-slate-900 group-has-[:checked]:text-white">
							<svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 18.75a60.07 60.07 0 0 1 15.797 2.101c.727.198 1.453-.342 1.453-1.096V18.75M3.75 4.5v.75A.75.75 0 0 1 3 6h-.75m0 0v-.375c0-.621.504-1.125 1.125-1.125H20.25M2.25 6v9m18-10.5v.75c0 .414.336.75.75.75h.75m-1.5-1.5h.375c.621 0 1.125.504 1.125 1.125v9.75c0 .621-.504 1.125-1.125 1.125h-.375m1.5-1.5H21a.75.75 0 0 0-.75.75v.75m0 0H3.75m0 0h-.375a1.125 1.125 0 0 1-1.125-1.125V15m1.5 1.5v-.75A.75.75 0 0 0 3 15h-.75M15 10.5a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z" /></svg>
						</div>
						<div>
							<p class="text-sm font-semibold text-slate-900">Default</p>
							<p class="text-[11px] text-slate-500">Pay immediately</p>
						</div>
					</div>
				</label>
				
				<label class="group relative flex cursor-pointer rounded-xl border border-slate-200 bg-white p-3 shadow-sm focus-within:ring-2 focus-within:ring-slate-500 hover:bg-slate-50 has-[:checked]:border-slate-900 has-[:checked]:ring-1 has-[:checked]:ring-slate-900">
					<input type="radio" name="sale_type" value="credit" class="sr-only" @checked(old('sale_type', $draftFormMode === 'edit' && $editingInvoice ? $editingInvoice->sale_type : '') === 'credit')>
					<div class="flex items-center gap-3 w-full opacity-70 group-has-[:checked]:opacity-100">
						<div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-slate-100 text-slate-500 group-has-[:checked]:bg-slate-900 group-has-[:checked]:text-white">
							<svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M6.75 3v2.25M17.25 3v2.25M3 18.75V7.5a2.25 2.25 0 0 1 2.25-2.25h13.5A2.25 2.25 0 0 1 21 7.5v11.25m-18 0A2.25 2.25 0 0 0 5.25 21h13.5A2.25 2.25 0 0 0 21 18.75m-18 0v-7.5A2.25 2.25 0 0 1 5.25 9h13.5A2.25 2.25 0 0 1 21 11.25v7.5" /></svg>
						</div>
						<div>
							<p class="text-sm font-semibold text-slate-900">Credit</p>
							<p class="text-[11px] text-slate-500">Pay later by due date</p>
						</div>
					</div>
				</label>
				
				<label class="group relative flex cursor-pointer rounded-xl border border-slate-200 bg-white p-3 shadow-sm focus-within:ring-2 focus-within:ring-slate-500 hover:bg-slate-50 has-[:checked]:border-slate-900 has-[:checked]:ring-1 has-[:checked]:ring-slate-900">
					<input type="radio" name="sale_type" value="installment" class="sr-only" @checked(old('sale_type', $draftFormMode === 'edit' && $editingInvoice ? $editingInvoice->sale_type : '') === 'installment')>
					<div class="flex items-center gap-3 w-full opacity-70 group-has-[:checked]:opacity-100">
						<div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-slate-100 text-slate-500 group-has-[:checked]:bg-slate-900 group-has-[:checked]:text-white">
							<svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M10.5 6a7.5 7.5 0 1 0 7.5 7.5h-7.5V6Z" /><path stroke-linecap="round" stroke-linejoin="round" d="M13.5 10.5H21A7.5 7.5 0 0 0 13.5 3v7.5Z" /></svg>
						</div>
						<div>
							<p class="text-sm font-semibold text-slate-900">Installment</p>
							<p class="text-[11px] text-slate-500">Pay in multiple parts</p>
						</div>
					</div>
				</label>
			</div>
		</fieldset>
		<div id="payment-type-status" class="mt-2 hidden rounded border border-slate-200 bg-white px-2 py-1.5 text-xs">
			<div class="flex items-center justify-between gap-2">
				<p id="payment-status-label" class="font-medium text-slate-800"></p>
				<button type="button" id="open-payment-drawer-edit" class="rounded border border-slate-300 px-2 py-0.5 text-xs font-medium text-slate-800 hover:bg-slate-50">Open</button>
			</div>
		</div>
		{{-- Installment POST mirrors: drawer lives under display:none when closed; some browsers omit nested fields. Mirrors always submit. --}}
		<div id="installment-post-mirror" class="sr-only" aria-hidden="true">
			<input type="hidden" name="installment_down_payment" id="mirror-installment-down-payment" value="0" disabled>
			<input type="hidden" name="installment_count" id="mirror-installment-count" value="" disabled>
			<input type="hidden" name="installment_first_due_date" id="mirror-installment-first-due-date" value="" disabled>
			<input type="hidden" name="installment_frequency" id="mirror-installment-frequency" value="monthly" disabled>
			<input type="hidden" name="installment_custom_days" id="mirror-installment-custom-days" value="" disabled>
			<input type="hidden" name="installment_penalty_rate" id="mirror-installment-penalty-rate" value="0.02" disabled>
			<input type="hidden" name="installment_penalty_enabled" id="mirror-installment-penalty-enabled" value="0" disabled>
		</div>
	</div>
	@endif
</section>

<section class="rounded-xl border border-slate-200 bg-white p-3 shadow-sm sm:p-4">
	<h3 class="mb-2 text-sm font-semibold text-slate-800">Summary</h3>
	<div class="flex flex-wrap gap-2">
		<div class="w-[76px] shrink-0 rounded border border-slate-100 bg-slate-50 px-2 py-1.5 sm:w-24 lg:w-28">
			<p class="text-[10px] font-medium uppercase text-slate-500">Lines</p>
			<p class="text-sm font-semibold text-slate-900 tabular-nums" id="summary-line-count">0</p>
		</div>
		<div class="w-[76px] shrink-0 rounded border border-slate-100 bg-slate-50 px-2 py-1.5 sm:w-24 lg:w-28">
			<p class="text-[10px] font-medium uppercase text-slate-500">Qty</p>
			<p class="text-sm font-semibold text-slate-900 tabular-nums" id="summary-total-qty">0</p>
		</div>
		<div class="min-w-[96px] flex-1 rounded border border-slate-100 bg-slate-50 px-2 py-1.5">
			<p class="text-[10px] font-medium uppercase text-slate-500">Gross</p>
			<p class="text-sm font-semibold text-slate-900 tabular-nums" id="summary-gross">0.00</p>
			<p class="text-[10px] text-slate-500">before line disc.</p>
		</div>
		<div class="min-w-[96px] flex-1 rounded border border-slate-100 bg-slate-50 px-2 py-1.5">
			<p class="text-[10px] font-medium uppercase text-slate-500">Line discounts</p>
			<p class="text-sm font-semibold text-slate-900 tabular-nums" id="summary-line-discounts">0.00</p>
		</div>
		<div class="min-w-[96px] flex-1 rounded border border-slate-100 bg-slate-50 px-2 py-1.5">
			<p class="text-[10px] font-medium uppercase text-slate-500">Service charges</p>
			<p class="text-sm font-semibold text-slate-900 tabular-nums" id="summary-service-charges">0.00</p>
			<p class="text-[10px] text-slate-500">added to lines</p>
		</div>
		<div class="min-w-[96px] flex-1 rounded border border-slate-100 bg-slate-50 px-2 py-1.5">
			<p class="text-[10px] font-medium uppercase text-slate-500">Total</p>
			<p class="text-sm font-semibold text-slate-900 tabular-nums" id="summary-subtotal">0.00</p>
			<p class="text-[10px] text-slate-500">after disc. + service</p>
		</div>
		<div class="min-w-[96px] flex-1 rounded border border-slate-100 bg-slate-50 px-2 py-1.5">
			<p class="text-[10px] font-medium uppercase text-slate-500">Advance</p>
			<p class="text-sm font-semibold text-slate-900 tabular-nums" id="summary-advance">0.00</p>
		</div>
		@if($draftFormMode === 'edit' && $editingInvoice)
		<div class="relative min-w-[160px] flex-[2] rounded border border-amber-100 bg-amber-50/90 px-2 py-1.5">
			<div class="installment-discount-lock absolute inset-0 z-10 hidden cursor-not-allowed rounded" tabindex="0" role="note" aria-label="Installment sales do not support discounts."><span class="installment-discount-tip pointer-events-none absolute bottom-full left-0 z-50 mb-1 hidden w-60 rounded-md bg-slate-900 px-2.5 py-1.5 text-[11px] font-normal leading-snug text-white shadow-lg">Installment sales do not support discounts. The amount is fixed at 0 and the full price is spread across the installment plan.</span></div>
			<label for="discount-total-input" class="text-[10px] font-medium uppercase text-slate-500">Invoice discount</label>
			<input type="number" name="discount_total" id="discount-total-input" step="0.01" min="0" value="{{ old('discount_total', $editingInvoice->discount_total) ?: '' }}" placeholder="0.00" class="mt-0.5 w-full rounded-md border border-slate-300 bg-white px-2 py-1 text-sm tabular-nums shadow-sm">
		</div>
		<div class="min-w-[96px] flex-1 rounded border border-slate-100 bg-slate-50 px-2 py-1.5">
			<p class="text-[10px] font-medium uppercase text-slate-500">Tax</p>
			<p class="text-sm font-semibold text-slate-900 tabular-nums" id="summary-tax">{{ number_format((float) $editingInvoice->tax_total, 2) }}</p>
		</div>
		<div class="min-w-[96px] flex-1 rounded border border-slate-100 bg-slate-50 px-2 py-1.5">
			<p class="text-[10px] font-medium uppercase text-slate-500">Paid</p>
			<p class="text-sm font-semibold text-slate-900 tabular-nums" id="summary-paid">{{ number_format((float) $editingInvoice->amount_paid, 2) }}</p>
		</div>
		@endif
		<div class="w-full rounded border border-slate-800 bg-slate-900 px-2 py-1.5 text-white">
			<p class="text-[10px] font-medium uppercase text-slate-400">Due</p>
			<p class="text-base font-bold tabular-nums" id="summary-due">0.00</p>
		</div>
	</div>
	<div id="installment-plan-preview-wrap" class="mt-3 hidden">
		<div class="overflow-hidden rounded-lg border border-slate-200 bg-white shadow-sm">
			<div class="border-b border-slate-100 bg-gradient-to-r from-slate-50 to-white px-3 py-2">
				<h4 class="text-xs font-semibold uppercase tracking-wide text-slate-600">Installment schedule</h4>
				<p class="mt-0.5 text-[11px] text-slate-500">Preview from advance and line totals</p>
			</div>
			<div class="max-h-52 overflow-auto">
				<table class="w-full border-collapse text-left text-xs sm:text-sm">
					<thead class="sticky top-0 z-10 border-b border-slate-200 bg-slate-50/95 backdrop-blur-sm">
						<tr class="text-[10px] font-semibold uppercase tracking-wide text-slate-500 sm:text-xs">
							<th class="w-10 px-3 py-2 text-center sm:w-12">#</th>
							<th class="px-3 py-2">Due date</th>
							<th class="px-3 py-2 text-right">Amount</th>
						</tr>
					</thead>
					<tbody id="installment-plan-preview" class="divide-y divide-slate-100 text-slate-800"></tbody>
					<tfoot id="installment-plan-preview-foot" class="hidden border-t border-slate-200 bg-slate-50 font-semibold text-slate-900"></tfoot>
				</table>
			</div>
		</div>
	</div>
</section>

<div class="mt-3 flex flex-col gap-3">
	<div id="invoice-readiness-panel" class="w-full max-w-xl rounded-lg border border-slate-200 bg-slate-50/90 px-3 py-2.5 text-left shadow-sm sm:px-4 sm:py-3">
		<h3 class="text-xs font-semibold uppercase tracking-wide text-slate-600">{{ $draftFormMode === 'edit' ? 'Before you save' : 'Before you create' }}</h3>
		@php
			$readinessOkMessage = ! empty($postedEditMode)
				? 'All required fields are complete — you can save.'
				: ($draftFormMode === 'edit'
					? 'All required fields are complete — you can save the draft.'
					: 'All required fields are complete — you can create the draft.');
		@endphp
		<p id="invoice-readiness-ok" class="mt-1.5 hidden text-sm font-medium text-emerald-800">{{ $readinessOkMessage }}</p>
		<ul id="invoice-readiness-missing" class="mt-1.5 list-disc space-y-1 pl-5 text-sm text-amber-900"></ul>
	</div>
	<div class="flex flex-col items-end gap-2">
		<p id="invoice-client-error" class="hidden w-full max-w-xl rounded-lg border border-rose-200 bg-rose-50 px-3 py-2 text-left text-xs text-rose-800 shadow-sm sm:text-sm" role="alert"></p>
		<div class="flex flex-wrap items-center justify-end gap-2">
			@if ($draftFormMode === 'edit' && $editingInvoice)
				<a href="{{ route('sales.invoices.show', $editingInvoice) }}" class="rounded-md border border-slate-300 bg-white px-4 py-2 text-sm font-medium text-slate-800 shadow-sm hover:bg-slate-50">&larr; Back</a>
			@endif
			<button type="submit" id="create-invoice-submit" formnovalidate disabled class="rounded-md bg-slate-900 px-4 py-2 text-sm font-semibold text-white opacity-50 hover:bg-slate-800 disabled:cursor-not-allowed">
				@if ($postedEditMode ?? false)
					Save changes
				@elseif ($draftFormMode === 'edit')
					Save draft
				@else
					Create
				@endif
			</button>
		</div>
	</div>
</div>
</div>

{{-- Payment terms: inside form so fields submit; backdrop does not close the panel --}}
<div id="payment-terms-drawer" class="fixed inset-0 z-[60] hidden" role="dialog" aria-modal="true" aria-labelledby="payment-drawer-title">
	<div class="absolute inset-0 bg-slate-900/60 backdrop-blur-sm transition-opacity" aria-hidden="true"></div>
	<div class="absolute inset-y-0 right-0 flex w-full max-w-md flex-col border-l border-slate-200 bg-white shadow-2xl transition-transform">
		<div class="flex items-center justify-between border-b border-slate-200 px-4 py-3">
			<h3 id="payment-drawer-title" class="text-base font-semibold text-slate-900">Payment</h3>
			<button type="button" onclick="closePaymentDrawer()" class="rounded-md p-1 text-slate-400 hover:bg-slate-100 hover:text-slate-500 focus:outline-none focus:ring-2 focus:ring-slate-500">
				<span class="sr-only">Close panel</span>
				<svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12" /></svg>
			</button>
		</div>
		<div class="min-h-0 flex-1 overflow-y-auto px-4 py-5 sm:p-6">
			<div id="payment-drawer-error" class="mb-4 hidden rounded-md border border-rose-200 bg-rose-50 p-3 text-sm text-rose-700"></div>
			<fieldset id="payment-drawer-fieldset" class="m-0 min-h-0 border-0 p-0">
			<div id="payment-drawer-credit" class="hidden space-y-4">
				<div>
					<label class="block text-sm font-semibold text-slate-700">Due date <span class="text-rose-500">*</span></label>
					<input type="date" name="credit_due_date" id="drawer-credit-due-date" value="{{ old('credit_due_date', $editInv?->credit_due_date?->toDateString()) }}" class="mt-1 block w-full rounded-md border border-slate-300 px-3 py-2 text-sm placeholder-slate-400 focus:border-slate-500 focus:outline-none focus:ring-1 focus:ring-slate-500">
					<p id="credit-due-date-max-label" class="mt-1.5 text-xs text-amber-700 bg-amber-50 border border-amber-200 rounded px-2 py-1"></p>
				</div>
			</div>
			<div id="payment-drawer-installment" class="hidden space-y-4">
				<div class="grid grid-cols-1 gap-y-4 gap-x-4 sm:grid-cols-2">
					<div>
						<label class="block text-sm font-semibold text-slate-700">Advance <span class="text-rose-500">*</span></label>
						<input type="number" step="0.01" min="0" id="drawer-installment-down-payment" value="{{ old('installment_down_payment', $editInv !== null ? $editInv->installment_down_payment : 0) }}" class="mt-1 block w-full rounded-md border border-slate-300 px-3 py-2 text-sm placeholder-slate-400 focus:border-slate-500 focus:outline-none focus:ring-1 focus:ring-slate-500">
					</div>
					<div>
						<label class="block text-sm font-semibold text-slate-700">Installment count <span class="text-rose-500">*</span></label>
						<input type="number" min="1" id="installment-count" value="{{ old('installment_count', $editInv?->installment_count) }}" class="mt-1 block w-full rounded-md border border-slate-300 px-3 py-2 text-sm placeholder-slate-400 focus:border-slate-500 focus:outline-none focus:ring-1 focus:ring-slate-500">
					</div>
					<div class="sm:col-span-2">
						<label class="block text-sm font-semibold text-slate-700">Frequency</label>
						<select id="installment-frequency" class="mt-1 block w-full rounded-md border border-slate-300 px-3 py-2 text-sm focus:border-slate-500 focus:outline-none focus:ring-1 focus:ring-slate-500">
							<option value="monthly" @selected(old('installment_frequency', $editInv?->installment_frequency ?? 'monthly') === 'monthly')>Monthly</option>
							<option value="weekly" @selected(old('installment_frequency', $editInv?->installment_frequency ?? 'monthly') === 'weekly')>Weekly</option>
							<option value="daily" @selected(old('installment_frequency', $editInv?->installment_frequency ?? 'monthly') === 'daily')>Daily</option>
							<option value="custom" @selected(old('installment_frequency', $editInv?->installment_frequency ?? 'monthly') === 'custom')>Custom</option>
						</select>
					</div>
					<div id="installment-custom-days-wrap" class="sm:col-span-2">
						<label class="block text-sm font-semibold text-slate-700">Days <span class="text-rose-500">*</span></label>
						<input type="number" min="1" id="installment-custom-days" value="{{ old('installment_custom_days', $editInv?->installment_custom_days) }}" class="mt-1 block w-full rounded-md border border-slate-300 px-3 py-2 text-sm placeholder-slate-400 focus:border-slate-500 focus:outline-none focus:ring-1 focus:ring-slate-500" placeholder="10">
					</div>
					<div class="sm:col-span-2">
						<div class="flex items-center justify-between gap-2">
							<label class="block text-sm font-semibold text-slate-700">1st installment due <span class="text-rose-500">*</span></label>
							<span id="installment-first-due-hint" class="text-xs font-semibold text-indigo-600 bg-indigo-50 border border-indigo-200 rounded px-2 py-0.5"></span>
						</div>
						<input type="date" id="installment-first-due-date" value="{{ old('installment_first_due_date', $editInv?->installment_first_due_date?->toDateString()) }}" class="mt-1 block w-full rounded-md border border-slate-300 px-3 py-2 text-sm placeholder-slate-400 focus:border-slate-500 focus:outline-none focus:ring-1 focus:ring-slate-500">
						<p id="installment-first-due-desc" class="mt-1.5 text-xs leading-relaxed text-amber-800 bg-amber-50 border border-amber-200 rounded px-2 py-1.5"></p>
						<label class="mt-2 flex items-center gap-2 cursor-pointer select-none rounded-md border border-rose-200 bg-rose-50 px-2 py-1.5">
							<input type="checkbox" id="installment-date-unlock" class="rounded border-rose-300 text-rose-600 focus:ring-rose-500">
							<span class="text-xs font-medium text-rose-700">⚠ Allow past / custom date (override restriction)</span>
						</label>
					</div>
					<div class="sm:col-span-2 group">
						<label class="relative inline-flex cursor-pointer items-center gap-2">
							<input type="checkbox" value="1" id="drawer-installment-penalty-enabled" @checked(old('installment_penalty_enabled', $editInv?->installment_penalty_enabled)) class="peer sr-only">
							<div class="h-5 w-9 shrink-0 rounded-full bg-slate-200 after:absolute after:left-[2px] after:top-[2px] after:h-4 after:w-4 after:rounded-full after:border after:border-slate-300 after:bg-white after:transition-all after:content-[''] peer-checked:bg-slate-900 peer-checked:after:translate-x-full peer-checked:after:border-white peer-focus:outline-none peer-focus:ring-2 peer-focus:ring-slate-500 peer-focus:ring-offset-1"></div>
							<span class="text-sm font-semibold text-slate-700">Enable penalty interest</span>
						</label>
						
						<div class="hidden group-has-[:checked]:block mt-3">
							<label class="block text-sm font-semibold text-slate-700">Penalty rate</label>
							<div class="relative mt-1 max-w-[10rem]">
								<input type="number" step="0.0001" min="0" id="drawer-installment-penalty-rate" value="{{ old('installment_penalty_rate', $editInv?->installment_penalty_rate !== null ? $editInv->installment_penalty_rate : '0.02') }}" class="block w-full rounded-md border border-slate-300 pl-3 pr-8 py-2 text-sm focus:border-slate-500 focus:outline-none focus:ring-1 focus:ring-slate-500">
								<div class="pointer-events-none absolute inset-y-0 right-0 flex items-center pr-3">
									<span class="text-slate-500 sm:text-sm">%</span>
								</div>
							</div>
						</div>
					</div>
					<div class="sm:col-span-2 mt-2 border-t border-slate-100 pt-5">
						<h4 class="text-xs font-semibold uppercase tracking-wide text-slate-900">Guarantor &amp; Verification <span class="text-rose-500">*</span></h4>
					</div>

					@php
						$initialGuarantorId = old('guarantor_id', $editInv?->guarantor_id ?? $editInv?->customer?->default_guarantor_id);
						$hasKeepOption = $editInv && ($editInv->guarantor_id || $editInv->guarantor_name);
						$initialGuarantorSource = old('guarantor_source', ($editInv && $editInv->guarantor_id) ? 'keep' : (($editInv && $editInv->guarantor_name) ? 'new' : ($initialGuarantorId ? 'existing' : '')));
					@endphp
					<div x-data="{ source: '{{ $initialGuarantorSource }}' }" class="sm:col-span-2 -mt-2 space-y-4">
						<div>
							<div class="grid grid-cols-1 gap-3 {{ $hasKeepOption ? 'sm:grid-cols-3' : 'sm:grid-cols-2' }}">
								@if ($hasKeepOption)
									<label class="flex min-h-[86px] cursor-pointer flex-col items-center justify-center gap-1 rounded-xl border px-4 py-4 text-center transition" :class="source === 'keep' ? 'border-slate-900 bg-slate-50 ring-1 ring-slate-900' : 'border-slate-200 bg-white hover:border-slate-400 hover:bg-slate-50'">
										<input type="radio" name="guarantor_source" value="keep" x-model="source" class="sr-only" @checked($initialGuarantorSource === 'keep')>
										<span class="text-sm font-semibold text-slate-900">Keep Current</span>
										<span class="text-[11px] leading-snug text-slate-500">Retain the guarantor already on this invoice</span>
									</label>
								@endif
								<label class="flex min-h-[86px] cursor-pointer flex-col items-center justify-center gap-1 rounded-xl border px-4 py-4 text-center transition" :class="source === 'existing' ? 'border-slate-900 bg-slate-50 ring-1 ring-slate-900' : 'border-slate-200 bg-white hover:border-slate-400 hover:bg-slate-50'">
									<input type="radio" name="guarantor_source" value="existing" x-model="source" class="sr-only" @checked($initialGuarantorSource === 'existing')>
									<span class="text-sm font-semibold text-slate-900">Existing Customer</span>
									<span class="text-[11px] leading-snug text-slate-500">Pick a customer already in the system</span>
								</label>
								<label class="flex min-h-[86px] cursor-pointer flex-col items-center justify-center gap-1 rounded-xl border px-4 py-4 text-center transition" :class="source === 'new' ? 'border-slate-900 bg-slate-50 ring-1 ring-slate-900' : 'border-slate-200 bg-white hover:border-slate-400 hover:bg-slate-50'">
									<input type="radio" name="guarantor_source" value="new" x-model="source" class="sr-only" @checked($initialGuarantorSource === 'new')>
									<span class="text-sm font-semibold text-slate-900">Add New Guarantor</span>
									<span class="text-[11px] leading-snug text-slate-500">Enter a guarantor not yet registered</span>
								</label>
							</div>
						</div>

						<!-- Option 1: Existing Customer -->
						<div x-show="source === 'existing'" class="space-y-3" x-cloak>
							<div>
								<label class="text-xs font-semibold text-slate-700">Select Customer as Guarantor</label>
								@php
									$customerOptions = $customers->map(fn ($c) => [
										'value' => $c->id,
										'label' => $c->name . ' (' . $c->customer_code . ($c->phone ? ' · ' . $c->phone : '') . ')'
									])->values()->all();
								@endphp
								<div class="mt-1">
									<x-searchable-select name="guarantor_id" :options="$customerOptions" :selected="old('guarantor_id', $initialGuarantorId ?? null)" placeholder="Search by name, code, or phone…" />
								</div>
							</div>
							<div>
								<label for="drawer-guarantor-address-existing" class="text-xs font-semibold text-slate-700">Address <span class="text-rose-500">*</span></label>
								<textarea name="guarantor_address" id="drawer-guarantor-address-existing" rows="2" class="mt-1 w-full rounded-md border border-slate-300 bg-white px-3 py-2 text-sm placeholder-slate-400 focus:border-slate-500 focus:outline-none focus:ring-1 focus:ring-slate-500" placeholder="Guarantor address" required>{{ old('guarantor_address', $editInv?->guarantor_address) }}</textarea>
							</div>
							<div>
								<label for="drawer-guarantor-nid-existing" class="text-xs font-semibold text-slate-700">NID Number <span class="text-rose-500">*</span></label>
								<input type="text" name="guarantor_nid_number" id="drawer-guarantor-nid-existing" class="mt-1 w-full rounded-md border border-slate-300 bg-white px-3 py-2 text-sm placeholder-slate-400 focus:border-slate-500 focus:outline-none focus:ring-1 focus:ring-slate-500" placeholder="National ID number" value="{{ old('guarantor_nid_number', $editInv?->guarantor_nid_number) }}" required>
							</div>
							<div>
								<label for="drawer-guarantor-notes-existing" class="text-xs font-semibold text-slate-700">Guarantor Notes (Optional)</label>
								<textarea name="guarantor_notes" id="drawer-guarantor-notes-existing" rows="2" class="mt-1 w-full rounded-md border border-slate-300 bg-white px-3 py-2 text-sm placeholder-slate-400 focus:border-slate-500 focus:outline-none focus:ring-1 focus:ring-slate-500" placeholder="Any additional notes about the guarantor">{{ old('guarantor_notes', $editInv?->guarantor_notes) }}</textarea>
							</div>
						</div>

						<!-- Option 2: Create New Customer -->
						<div x-show="source === 'new'" class="space-y-3" x-cloak>
							<div>
								<label for="drawer-guarantor-name" class="text-xs font-semibold text-slate-700">Guarantor Name <span class="text-rose-500">*</span></label>
								<input type="text" name="guarantor_name" id="drawer-guarantor-name" class="mt-1 w-full rounded-md border border-slate-300 bg-white px-3 py-2 text-sm placeholder-slate-400 focus:border-slate-500 focus:outline-none focus:ring-1 focus:ring-slate-500" value="{{ old('guarantor_name', $editInv?->guarantor_name) }}">
							</div>
							<div>
								<label for="drawer-guarantor-phone" class="text-xs font-semibold text-slate-700">Phone Number <span class="text-rose-500">*</span></label>
								<input type="text" name="guarantor_phone" id="drawer-guarantor-phone" class="mt-1 w-full rounded-md border border-slate-300 bg-white px-3 py-2 text-sm placeholder-slate-400 focus:border-slate-500 focus:outline-none focus:ring-1 focus:ring-slate-500" value="{{ old('guarantor_phone', isset($editInv->guarantor_phone_numbers[0]) ? $editInv->guarantor_phone_numbers[0] : '') }}">
							</div>
							<div>
								<label for="drawer-guarantor-email" class="text-xs font-semibold text-slate-700">Email Address</label>
								<input type="email" name="guarantor_email" id="drawer-guarantor-email" class="mt-1 w-full rounded-md border border-slate-300 bg-white px-3 py-2 text-sm placeholder-slate-400 focus:border-slate-500 focus:outline-none focus:ring-1 focus:ring-slate-500" value="{{ old('guarantor_email') }}">
							</div>
							<div>
								<label for="drawer-guarantor-address" class="text-xs font-semibold text-slate-700">Address <span class="text-rose-500">*</span></label>
								<textarea name="guarantor_address" id="drawer-guarantor-address" rows="2" class="mt-1 w-full rounded-md border border-slate-300 bg-white px-3 py-2 text-sm placeholder-slate-400 focus:border-slate-500 focus:outline-none focus:ring-1 focus:ring-slate-500" required>{{ old('guarantor_address', $editInv?->guarantor_address) }}</textarea>
							</div>
							<div>
								<label for="drawer-guarantor-nid-new" class="text-xs font-semibold text-slate-700">NID Number <span class="text-rose-500">*</span></label>
								<input type="text" name="guarantor_nid_number" id="drawer-guarantor-nid-new" class="mt-1 w-full rounded-md border border-slate-300 bg-white px-3 py-2 text-sm placeholder-slate-400 focus:border-slate-500 focus:outline-none focus:ring-1 focus:ring-slate-500" placeholder="National ID number" value="{{ old('guarantor_nid_number', $editInv?->guarantor_nid_number) }}" required>
							</div>
							<div>
								<label for="drawer-guarantor-notes-new" class="text-xs font-semibold text-slate-700">Guarantor Notes (Optional)</label>
								<textarea name="guarantor_notes" id="drawer-guarantor-notes-new" rows="2" class="mt-1 w-full rounded-md border border-slate-300 bg-white px-3 py-2 text-sm placeholder-slate-400 focus:border-slate-500 focus:outline-none focus:ring-1 focus:ring-slate-500" placeholder="Any additional notes about the guarantor">{{ old('guarantor_notes', $editInv?->guarantor_notes) }}</textarea>
							</div>
						</div>

						<div class="mt-6 border-t border-slate-200 pt-4 space-y-4">
							<h3 class="text-xs font-semibold text-slate-800 uppercase tracking-wider">Verification Documents</h3>

							<!-- Customer NID Upload -->
							<div class="space-y-1">
								<label class="text-xs font-semibold text-slate-700 block">Customer NID File</label>
								@if ($editInv && $editInv->customer_nid_path)
									<div class="flex items-center justify-between rounded-lg border border-slate-200 bg-slate-50 p-2 text-xs">
										<span class="truncate font-mono text-slate-600" title="{{ $editInv->customer_nid_path }}">{{ basename($editInv->customer_nid_path) }}</span>
										<label class="inline-flex items-center gap-1.5 text-rose-600 cursor-pointer select-none font-medium">
											<input type="checkbox" name="remove_customer_nid" value="1" class="rounded border-slate-300 text-rose-600 focus:ring-rose-500">
											Remove
										</label>
									</div>
								@endif
								<input type="file" name="customer_nid" class="block w-full text-xs text-slate-500 file:mr-4 file:py-2 file:px-4 file:rounded-md file:border-0 file:text-xs file:font-semibold file:bg-slate-50 file:text-slate-700 hover:file:bg-slate-100">
							</div>

							<!-- Guarantor NID Upload -->
							<div class="space-y-1">
								<label class="text-xs font-semibold text-slate-700 block">Guarantor NID File</label>
								@if ($editInv && $editInv->guarantor_nid_path)
									<div class="flex items-center justify-between rounded-lg border border-slate-200 bg-slate-50 p-2 text-xs">
										<span class="truncate font-mono text-slate-600" title="{{ $editInv->guarantor_nid_path }}">{{ basename($editInv->guarantor_nid_path) }}</span>
										<label class="inline-flex items-center gap-1.5 text-rose-600 cursor-pointer select-none font-medium">
											<input type="checkbox" name="remove_guarantor_nid" value="1" class="rounded border-slate-300 text-rose-600 focus:ring-rose-500">
											Remove
										</label>
									</div>
								@endif
								<input type="file" name="guarantor_nid" class="block w-full text-xs text-slate-500 file:mr-4 file:py-2 file:px-4 file:rounded-md file:border-0 file:text-xs file:font-semibold file:bg-slate-50 file:text-slate-700 hover:file:bg-slate-100">
							</div>

							<!-- Other Verification Attachments Upload -->
							<div class="space-y-1">
								<label class="text-xs font-semibold text-slate-700 block">Upload Verification Attachments</label>
								@if ($editInv && $editInv->verification_attachments && count($editInv->verification_attachments) > 0)
									<div class="space-y-1.5">
										@foreach ($editInv->verification_attachments as $idx => $path)
											<div class="flex items-center justify-between rounded-lg border border-slate-200 bg-slate-50 p-2 text-xs">
												<span class="truncate font-mono text-slate-600" title="{{ $path }}">{{ basename($path) }}</span>
												<label class="inline-flex items-center gap-1.5 text-rose-600 cursor-pointer select-none font-medium">
													<input type="checkbox" name="remove_attachments[]" value="{{ $path }}" class="rounded border-slate-300 text-rose-600 focus:ring-rose-500">
													Remove
												</label>
											</div>
										@endforeach
									</div>
								@endif
								<input type="file" name="new_attachments[]" multiple class="block w-full text-xs text-slate-500 file:mr-4 file:py-2 file:px-4 file:rounded-md file:border-0 file:text-xs file:font-semibold file:bg-slate-50 file:text-slate-700 hover:file:bg-slate-100">
							</div>
						</div>
					</div>
				</div>
			</div>
			</fieldset>
		</div>
		<div class="border-t border-slate-200 bg-slate-50 px-4 py-3 sm:flex sm:flex-row-reverse sm:px-6">
			<button type="button" id="payment-drawer-confirm" class="inline-flex w-full justify-center rounded-md bg-slate-900 px-3 py-2 text-sm font-semibold text-white shadow-sm hover:bg-slate-800 sm:ml-3 sm:w-auto">Confirm Terms</button>
			<button type="button" id="payment-drawer-use-default" class="mt-3 inline-flex w-full justify-center rounded-md border border-slate-300 bg-white px-3 py-2 text-sm font-semibold text-slate-900 shadow-sm hover:bg-slate-50 sm:mt-0 sm:w-auto">Use Default</button>
		</div>
	</div>
</div>
</form>

<div id="customer-drawer" class="fixed inset-0 z-[60] hidden" role="dialog" aria-modal="true" aria-labelledby="customer-drawer-title">
	<div id="customer-drawer-backdrop" class="absolute inset-0 bg-slate-900/60 backdrop-blur-sm transition-opacity" aria-hidden="true"></div>
	<div class="absolute inset-y-0 right-0 flex w-full max-w-md flex-col border-l border-slate-200 bg-white shadow-2xl transition-transform">
		<div class="flex items-center justify-between border-b border-slate-200 px-4 py-3">
			<h3 id="customer-drawer-title" class="text-base font-semibold text-slate-900">Create Customer</h3>
			<button type="button" id="close-customer-drawer" class="rounded-md p-1 text-slate-400 hover:bg-slate-100 hover:text-slate-500 focus:outline-none focus:ring-2 focus:ring-slate-500">
				<span class="sr-only">Close panel</span>
				<svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12" /></svg>
			</button>
		</div>
		<form id="quick-customer-form" class="flex min-h-0 flex-1 flex-col">
			<div class="flex-1 overflow-y-auto px-4 py-5 sm:p-6 space-y-4">
				<div>
					<label class="block text-sm font-semibold text-slate-700">Name <span class="text-rose-500">*</span></label>
					<input type="text" name="name" class="mt-1 block w-full rounded-md border border-slate-300 px-3 py-2 text-sm placeholder-slate-400 focus:border-slate-500 focus:outline-none focus:ring-1 focus:ring-slate-500" required>
				</div>
				<div>
					<label class="block text-sm font-semibold text-slate-700">Phone <span class="text-rose-500">*</span></label>
					<input type="text" name="phone" class="mt-1 block w-full rounded-md border border-slate-300 px-3 py-2 text-sm placeholder-slate-400 focus:border-slate-500 focus:outline-none focus:ring-1 focus:ring-slate-500" required>
				</div>
				<div>
					<label class="block text-sm font-semibold text-slate-700">Additional Phones</label>
					<div id="quick-customer-additional-phones" class="mt-1 space-y-2">
						<div class="flex items-center gap-2">
							<input type="text" name="phone_numbers[]" class="block w-full rounded-md border border-slate-300 px-3 py-2 text-sm placeholder-slate-400 focus:border-slate-500 focus:outline-none focus:ring-1 focus:ring-slate-500" placeholder="e.g. 017xxxxxxxx">
							<button type="button" class="flex h-9 w-9 shrink-0 items-center justify-center rounded-md border border-rose-200 bg-rose-50 text-rose-600 hover:bg-rose-100 hover:text-rose-700" data-remove-phone title="Remove phone">
								<svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M20 12H4" /></svg>
							</button>
						</div>
					</div>
					<button type="button" id="quick-customer-add-phone" class="mt-3 inline-flex items-center gap-1.5 rounded-md border border-slate-300 bg-white px-3 py-1.5 text-xs font-medium text-slate-700 shadow-sm hover:bg-slate-50 focus:outline-none focus:ring-2 focus:ring-slate-500 focus:ring-offset-1">
						<svg class="h-4 w-4 text-slate-400" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" /></svg>
						Add Phone
					</button>
				</div>
				<div>
					<label class="block text-sm font-semibold text-slate-700">Email</label>
					<input type="email" name="email" class="mt-1 block w-full rounded-md border border-slate-300 px-3 py-2 text-sm placeholder-slate-400 focus:border-slate-500 focus:outline-none focus:ring-1 focus:ring-slate-500">
				</div>
				<div>
					<label class="block text-sm font-semibold text-slate-700">Address</label>
					<textarea name="address" rows="3" class="mt-1 block w-full rounded-md border border-slate-300 px-3 py-2 text-sm placeholder-slate-400 focus:border-slate-500 focus:outline-none focus:ring-1 focus:ring-slate-500"></textarea>
				</div>
				<div id="quick-customer-error" class="hidden rounded-md border border-rose-200 bg-rose-50 p-3 text-sm text-rose-700"></div>
			</div>
			<div class="border-t border-slate-200 bg-slate-50 px-4 py-3 sm:flex sm:flex-row-reverse sm:px-6">
				<button type="submit" class="inline-flex w-full justify-center rounded-md bg-slate-900 px-3 py-2 text-sm font-semibold text-white shadow-sm hover:bg-slate-800 sm:ml-3 sm:w-auto">Save Customer</button>
			</div>
		</form>
	</div>
</div>

<script>
(() => {
	@php
		$customerPayload = $customers->map(function ($c) {
			return [
				'id' => $c->id,
				'name' => $c->name,
				'customer_code' => $c->customer_code,
				'phone' => $c->phone,
				'phones' => $c->normalizedPhoneNumbers(),
				'default_guarantor_id' => $c->default_guarantor_id,
			];
		})->values();
	@endphp
	const productsByLocation = @json($productsByLocation);
	const resolveSerialScanUrl = @json(route('sales.invoices.resolve-serial-scan'));
	const customers = @json($customerPayload);
	const invoiceLineDiscountUi = @json($canApplyLineDiscount);
	const INSTALLMENT_DISCOUNT_MSG = 'Installment sales do not support discounts. Switch the payment type to Cash/Default or Credit to apply a discount.';
	const invoiceLineProfitUi = @json($canViewLineProfit);
	const globalMaximumDeductible = @json($globalMaximumDeductible ?? null);
	const postedEditMode = @json($postedEditMode ?? false);
	const installmentAdminEditMode = @json($installmentAdminEditMode ?? false);
	const isEditDraft = @json($draftFormMode === 'edit');
	const isCreateMode = @json($draftFormMode === 'create');
	const initialDraftLines = @json($initialDraftLines ?? []);
	const editTaxTotal = @json($draftFormMode === 'edit' && $editingInvoice ? round((float) $editingInvoice->tax_total, 2) : 0.0);
	const editAmountPaid = @json($draftFormMode === 'edit' && $editingInvoice ? round((float) $editingInvoice->amount_paid, 2) : 0.0);
	const editCreditDueDate = @json($editInv?->credit_due_date?->toDateString());

	const linesBody = document.getElementById('lines-body');
	const addLineButton = document.getElementById('add-line');
	const customerSelect = document.getElementById('customer-id-hidden');
	const customerSearchInput = document.getElementById('customer-search');
	const customerDropdown = document.getElementById('customer-dropdown');
	const customerDrawer = document.getElementById('customer-drawer');
	const customerDrawerBackdrop = document.getElementById('customer-drawer-backdrop');
	const openCustomerDrawerButton = document.getElementById('open-customer-drawer');
	const clearCustomerButton = document.getElementById('clear-customer');
	const closeCustomerDrawerButton = document.getElementById('close-customer-drawer');
	const quickCustomerForm = document.getElementById('quick-customer-form');
	const quickCustomerError = document.getElementById('quick-customer-error');
	const quickCustomerPhonesWrap = document.getElementById('quick-customer-additional-phones');
	const quickCustomerAddPhoneBtn = document.getElementById('quick-customer-add-phone');
	const csrfToken = document.querySelector('input[name="_token"]').value;
	const saleTypeSelect = {
		get value() {
			const hiddenSaleType = document.querySelector('input[type="hidden"][name="sale_type"]');
			if (hiddenSaleType) {
				return hiddenSaleType.value;
			}
			const checked = document.querySelector('input[name="sale_type"]:checked');
			return checked ? checked.value : 'default';
		},
		set value(val) {
			const hiddenSaleType = document.querySelector('input[type="hidden"][name="sale_type"]');
			if (hiddenSaleType) {
				hiddenSaleType.value = val;
				return;
			}
			const radio = document.querySelector(`input[name="sale_type"][value="${val}"]`);
			if (radio) radio.checked = true;
		},
		addEventListener(event, handler) {
			const hiddenSaleType = document.querySelector('input[type="hidden"][name="sale_type"]');
			if (hiddenSaleType) {
				return;
			}
			document.querySelectorAll('input[name="sale_type"]').forEach((r) => r.addEventListener(event, handler));
		}
	};
	const paymentTermsDrawer = document.getElementById('payment-terms-drawer');
	const paymentDrawerFieldset = document.getElementById('payment-drawer-fieldset');
	const paymentDrawerCredit = document.getElementById('payment-drawer-credit');
	const paymentDrawerInstallment = document.getElementById('payment-drawer-installment');
	const paymentDrawerError = document.getElementById('payment-drawer-error');
	const paymentDrawerConfirm = document.getElementById('payment-drawer-confirm');
	const paymentDrawerUseDefault = document.getElementById('payment-drawer-use-default');
	const paymentTypeStatus = document.getElementById('payment-type-status');
	const paymentStatusLabel = document.getElementById('payment-status-label');
	const openPaymentDrawerEditBtn = document.getElementById('open-payment-drawer-edit');
	const createInvoiceSubmit = document.getElementById('create-invoice-submit');
	const invoiceReadinessPanel = document.getElementById('invoice-readiness-panel');
	const invoiceReadinessMissing = document.getElementById('invoice-readiness-missing');
	const invoiceReadinessOk = document.getElementById('invoice-readiness-ok');
	const installmentFrequencySelect = document.getElementById('installment-frequency');
	const installmentCustomDaysInput = document.getElementById('installment-custom-days');
	const installmentCustomDaysWrap = document.getElementById('installment-custom-days-wrap');
	const installmentCountInput = document.getElementById('installment-count');
	const installmentFirstDueDateInput = document.getElementById('installment-first-due-date');
	const installmentDownPaymentInput = document.getElementById('drawer-installment-down-payment');
	const planGrandTotalInput = document.getElementById('plan-grand-total-input');
	const drawerCreditDueDate = document.getElementById('drawer-credit-due-date');
	const summaryLineCount = document.getElementById('summary-line-count');
	const summaryTotalQty = document.getElementById('summary-total-qty');
	const summaryGross = document.getElementById('summary-gross');
	const summaryLineDiscounts = document.getElementById('summary-line-discounts');
	const summaryServiceCharges = document.getElementById('summary-service-charges');
	const summarySubtotal = document.getElementById('summary-subtotal');
	const summaryAdvance = document.getElementById('summary-advance');
	const summaryDue = document.getElementById('summary-due');
	const discountTotalInput = document.getElementById('discount-total-input');
	const installmentPlanPreviewWrap = document.getElementById('installment-plan-preview-wrap');
	const installmentPlanPreview = document.getElementById('installment-plan-preview');
	const installmentPlanPreviewFoot = document.getElementById('installment-plan-preview-foot');
	const invoiceClientError = document.getElementById('invoice-client-error');
	let lineIndex = 0;
	let invoiceBarcodeWedgeBuffer = '';
	let invoiceBarcodeWedgeLastTs = 0;
	let invoiceBarcodeWedgeResetId = null;
	const branchSelect = document.querySelector('select[name="branch_id"]') ?? document.querySelector('input[type="hidden"][name="branch_id"]');
	const invoiceBarcodeScanInput = document.getElementById('invoice-barcode-scan-input');
	let paymentTermsConfirmed = postedEditMode || saleTypeSelect.value === 'default';
	let paymentDrawerEscapeBlock = null;

	function syncDrawerBranchInputs() {
		const type = saleTypeSelect.value;
		const creditInputs = paymentDrawerCredit.querySelectorAll('input, select, textarea');
		const installmentInputs = paymentDrawerInstallment.querySelectorAll('input, select, textarea');

		// Never use <fieldset disabled>: browsers omit all nested fields from the POST body.
		if (type === 'default') {
			creditInputs.forEach((el) => {
				el.disabled = true;
			});
			installmentInputs.forEach((el) => {
				el.disabled = true;
			});
			paymentDrawerFieldset.classList.add('opacity-60');
		} else {
			paymentDrawerFieldset.classList.remove('opacity-60');

			if (type === 'credit') {
				creditInputs.forEach((el) => {
					el.disabled = false;
				});
				installmentInputs.forEach((el) => {
					el.disabled = true;
				});
			} else if (type === 'installment') {
				creditInputs.forEach((el) => {
					el.disabled = true;
				});
				installmentInputs.forEach((el) => {
					el.disabled = false;
				});
			}
		}

		syncInstallmentMirror();
		syncGuarantorInputs();
	}

	function syncInstallmentMirror() {
		const md = document.getElementById('mirror-installment-down-payment');
		const mc = document.getElementById('mirror-installment-count');
		const mf = document.getElementById('mirror-installment-first-due-date');
		const mfq = document.getElementById('mirror-installment-frequency');
		const mcust = document.getElementById('mirror-installment-custom-days');
		const mrate = document.getElementById('mirror-installment-penalty-rate');
		const mpen = document.getElementById('mirror-installment-penalty-enabled');
		const all = [md, mc, mf, mfq, mcust, mrate, mpen].filter(Boolean);
		const type = saleTypeSelect.value;

		if (type !== 'installment') {
			all.forEach((el) => {
				el.disabled = true;
			});
			return;
		}

		all.forEach((el) => {
			el.disabled = false;
		});

		if (md) {
			md.value = installmentDownPaymentInput?.value ?? '0';
		}
		if (mc) {
			mc.value = installmentCountInput?.value ?? '';
		}
		if (mf) {
			mf.value = installmentFirstDueDateInput?.value ?? '';
		}
		if (mfq && installmentFrequencySelect) {
			mfq.value = installmentFrequencySelect.value || 'monthly';
		}

		const penRateEl = document.getElementById('drawer-installment-penalty-rate');
		if (mrate) {
			mrate.value = penRateEl?.value ?? '0.02';
		}

		const penCb = document.getElementById('drawer-installment-penalty-enabled');
		if (mpen) {
			mpen.value = penCb?.checked ? '1' : '0';
		}

		const isCustom = installmentFrequencySelect?.value === 'custom';
		if (mcust) {
			if (!isCustom) {
				mcust.disabled = true;
				mcust.value = '';
			} else {
				mcust.disabled = false;
				mcust.value = installmentCustomDaysInput?.value ?? '';
			}
		}
	}

	function syncGuarantorInputs() {
		const saleType = saleTypeSelect.value;
		
		const sourceRadios = document.querySelectorAll('input[name="guarantor_source"]');
		const existingAddress = document.getElementById('drawer-guarantor-address-existing');
		const existingNid = document.getElementById('drawer-guarantor-nid-existing');
		const existingNotes = document.getElementById('drawer-guarantor-notes-existing');
		const newName = document.getElementById('drawer-guarantor-name');
		const newPhone = document.getElementById('drawer-guarantor-phone');
		const newEmail = document.getElementById('drawer-guarantor-email');
		const newAddress = document.getElementById('drawer-guarantor-address');
		const newNid = document.getElementById('drawer-guarantor-nid-new');
		const newNotes = document.getElementById('drawer-guarantor-notes-new');
		
		const guarantorIdInput = document.querySelector('input[name="guarantor_id"]');
		
		const customerNid = document.querySelector('input[name="customer_nid"]');
		const guarantorNid = document.querySelector('input[name="guarantor_nid"]');
		const newAttachments = document.querySelector('input[name="new_attachments[]"]');
		const removeCustomerNid = document.querySelector('input[name="remove_customer_nid"]');
		const removeGuarantorNid = document.querySelector('input[name="remove_guarantor_nid"]');
		const removeAttachments = document.querySelectorAll('input[name="remove_attachments[]"]');

		if (saleType !== 'installment') {
			sourceRadios.forEach(r => r.disabled = true);
			if (guarantorIdInput) guarantorIdInput.disabled = true;
			if (existingAddress) existingAddress.disabled = true;
			if (existingNid) existingNid.disabled = true;
			if (existingNotes) existingNotes.disabled = true;
			if (newName) newName.disabled = true;
			if (newPhone) newPhone.disabled = true;
			if (newEmail) newEmail.disabled = true;
			if (newAddress) newAddress.disabled = true;
			if (newNid) newNid.disabled = true;
			if (newNotes) newNotes.disabled = true;
			if (customerNid) customerNid.disabled = true;
			if (guarantorNid) guarantorNid.disabled = true;
			if (newAttachments) newAttachments.disabled = true;
			if (removeCustomerNid) removeCustomerNid.disabled = true;
			if (removeGuarantorNid) removeGuarantorNid.disabled = true;
			removeAttachments.forEach(cb => cb.disabled = true);
			return;
		}

		sourceRadios.forEach(r => r.disabled = false);

		if (customerNid) customerNid.disabled = false;
		if (guarantorNid) guarantorNid.disabled = false;
		if (newAttachments) newAttachments.disabled = false;
		if (removeCustomerNid) removeCustomerNid.disabled = false;
		if (removeGuarantorNid) removeGuarantorNid.disabled = false;
		removeAttachments.forEach(cb => cb.disabled = false);

		const checkedSource = document.querySelector('input[name="guarantor_source"]:checked')?.value || '';

		if (checkedSource === 'keep') {
			if (guarantorIdInput) guarantorIdInput.disabled = true;
			if (existingAddress) existingAddress.disabled = true;
			if (existingNid) existingNid.disabled = true;
			if (existingNotes) existingNotes.disabled = true;
			if (newName) newName.disabled = true;
			if (newPhone) newPhone.disabled = true;
			if (newEmail) newEmail.disabled = true;
			if (newAddress) newAddress.disabled = true;
			if (newNid) newNid.disabled = true;
			if (newNotes) newNotes.disabled = true;
		} else if (checkedSource === 'existing') {
			if (guarantorIdInput) guarantorIdInput.disabled = false;
			if (existingAddress) existingAddress.disabled = false;
			if (existingNid) existingNid.disabled = false;
			if (existingNotes) existingNotes.disabled = false;
			if (newName) newName.disabled = true;
			if (newPhone) newPhone.disabled = true;
			if (newEmail) newEmail.disabled = true;
			if (newAddress) newAddress.disabled = true;
			if (newNid) newNid.disabled = true;
			if (newNotes) newNotes.disabled = true;
		} else if (checkedSource === 'new') {
			if (guarantorIdInput) guarantorIdInput.disabled = true;
			if (existingAddress) existingAddress.disabled = true;
			if (existingNid) existingNid.disabled = true;
			if (existingNotes) existingNotes.disabled = true;
			if (newName) newName.disabled = false;
			if (newPhone) newPhone.disabled = false;
			if (newEmail) newEmail.disabled = false;
			if (newAddress) newAddress.disabled = false;
			if (newNid) newNid.disabled = false;
			if (newNotes) newNotes.disabled = false;
		} else {
			if (guarantorIdInput) guarantorIdInput.disabled = true;
			if (existingAddress) existingAddress.disabled = true;
			if (existingNid) existingNid.disabled = true;
			if (existingNotes) existingNotes.disabled = true;
			if (newName) newName.disabled = true;
			if (newPhone) newPhone.disabled = true;
			if (newEmail) newEmail.disabled = true;
			if (newAddress) newAddress.disabled = true;
			if (newNid) newNid.disabled = true;
			if (newNotes) newNotes.disabled = true;
		}
	}

	function attachPaymentDrawerEscapeGuard() {
		if (paymentDrawerEscapeBlock) {
			return;
		}
		paymentDrawerEscapeBlock = (event) => {
			if (!paymentTermsDrawer.classList.contains('hidden') && event.key === 'Escape') {
				event.preventDefault();
				event.stopPropagation();
			}
		};
		document.addEventListener('keydown', paymentDrawerEscapeBlock, true);
	}

	function detachPaymentDrawerEscapeGuard() {
		if (!paymentDrawerEscapeBlock) {
			return;
		}
		document.removeEventListener('keydown', paymentDrawerEscapeBlock, true);
		paymentDrawerEscapeBlock = null;
	}

	function openPaymentDrawer() {
		const type = saleTypeSelect.value;
		if (type === 'default') {
			return;
		}
		paymentDrawerError.classList.add('hidden');
		paymentDrawerError.textContent = '';
		const todayLabel = new Date().toLocaleDateString('en-GB', { day: 'numeric', month: 'long', year: 'numeric' });
		document.getElementById('payment-drawer-title').textContent = (type === 'credit' ? 'Credit' : 'Installment') + ' — ' + todayLabel;
		paymentDrawerCredit.classList.toggle('hidden', type !== 'credit');
		paymentDrawerInstallment.classList.toggle('hidden', type !== 'installment');
		paymentTermsDrawer.classList.remove('hidden');
		document.body.classList.add('overflow-hidden');
		syncDrawerBranchInputs();
		if (type === 'installment') syncFirstDueDateMin();
		if (type === 'credit') syncCreditDueDateMin();
		attachPaymentDrawerEscapeGuard();
		paymentDrawerConfirm.focus();
	}

	function closePaymentDrawer() {
		detachPaymentDrawerEscapeGuard();
		paymentTermsDrawer.classList.add('hidden');
		document.body.classList.remove('overflow-hidden');
	}

	function validatePaymentDrawer() {
		const type = saleTypeSelect.value;
		paymentDrawerError.classList.add('hidden');
		paymentDrawerError.textContent = '';

		if (type === 'credit') {
			if (!drawerCreditDueDate.value) {
				paymentDrawerError.textContent = 'Due date required.';
				paymentDrawerError.classList.remove('hidden');
				return false;
			}
			return true;
		}

		if (type === 'installment') {
			const advanceVal = Number(installmentDownPaymentInput?.value ?? '');
			if (Number.isNaN(advanceVal) || advanceVal <= 0) {
				paymentDrawerError.textContent = 'Advance is required and must be greater than 0.';
				paymentDrawerError.classList.remove('hidden');
				installmentDownPaymentInput?.focus();
				return false;
			}
			if (!installmentCountInput.value || Number.parseInt(installmentCountInput.value, 10) < 1) {
				paymentDrawerError.textContent = 'Count required.';
				paymentDrawerError.classList.remove('hidden');
				return false;
			}
			if (!installmentFirstDueDateInput.value) {
				paymentDrawerError.textContent = '1st due required.';
				paymentDrawerError.classList.remove('hidden');
				return false;
			}
			if (installmentFrequencySelect.value === 'custom' && (!installmentCustomDaysInput.value || Number.parseInt(installmentCustomDaysInput.value, 10) < 1)) {
				paymentDrawerError.textContent = 'Days required.';
				paymentDrawerError.classList.remove('hidden');
				return false;
			}
			const guarantorSource = document.querySelector('input[name="guarantor_source"]:checked')?.value;
			if (!guarantorSource) {
				paymentDrawerError.textContent = 'Select a guarantor option before confirming.';
				paymentDrawerError.classList.remove('hidden');
				return false;
			}
			if (guarantorSource === 'existing') {
				const guarantorId = document.querySelector('input[name="guarantor_id"]')?.value?.trim();
				const addr = document.getElementById('drawer-guarantor-address-existing')?.value?.trim();
				const nid = document.getElementById('drawer-guarantor-nid-existing')?.value?.trim();
				if (!guarantorId) {
					paymentDrawerError.textContent = 'Please select a customer as guarantor.';
					paymentDrawerError.classList.remove('hidden');
					return false;
				}
				if (!addr) {
					paymentDrawerError.textContent = 'Guarantor address is required.';
					paymentDrawerError.classList.remove('hidden');
					return false;
				}
				if (!nid) {
					paymentDrawerError.textContent = 'Guarantor NID number is required.';
					paymentDrawerError.classList.remove('hidden');
					return false;
				}
			} else if (guarantorSource === 'new') {
				const name = document.getElementById('drawer-guarantor-name')?.value?.trim();
				const phone = document.getElementById('drawer-guarantor-phone')?.value?.trim();
				const addr = document.getElementById('drawer-guarantor-address')?.value?.trim();
				const nid = document.getElementById('drawer-guarantor-nid-new')?.value?.trim();
				if (!name) {
					paymentDrawerError.textContent = 'Guarantor name is required.';
					paymentDrawerError.classList.remove('hidden');
					return false;
				}
				if (!phone) {
					paymentDrawerError.textContent = 'Guarantor phone is required.';
					paymentDrawerError.classList.remove('hidden');
					return false;
				}
				if (!addr) {
					paymentDrawerError.textContent = 'Guarantor address is required.';
					paymentDrawerError.classList.remove('hidden');
					return false;
				}
				if (!nid) {
					paymentDrawerError.textContent = 'Guarantor NID number is required.';
					paymentDrawerError.classList.remove('hidden');
					return false;
				}
			}
			return true;
		}

		return false;
	}

	function refreshPaymentTypeStatus() {
		if (!paymentTypeStatus || !paymentStatusLabel) {
			return;
		}
		const type = saleTypeSelect.value;
		if (type === 'default') {
			paymentTypeStatus.classList.add('hidden');
			return;
		}
		paymentTypeStatus.classList.remove('hidden');
		if (!paymentTermsConfirmed) {
			paymentStatusLabel.textContent = 'Not confirmed';
			if (openPaymentDrawerEditBtn) {
				openPaymentDrawerEditBtn.classList.remove('hidden');
				openPaymentDrawerEditBtn.textContent = 'Open';
			}
			return;
		}
		if (openPaymentDrawerEditBtn) {
			openPaymentDrawerEditBtn.classList.remove('hidden');
			openPaymentDrawerEditBtn.textContent = 'Edit';
		}
		if (type === 'credit') {
			paymentStatusLabel.textContent = `Credit · due ${drawerCreditDueDate.value}`;
			return;
		}
		const count = installmentCountInput.value;
		const first = installmentFirstDueDateInput.value;
		const freq = installmentFrequencySelect.options[installmentFrequencySelect.selectedIndex]?.text || '';
		paymentStatusLabel.textContent = `Inst. · ${count}× ${first} (${freq})`;
	}

	function refreshSubmitEnabled() {
		const gaps = collectInvoiceReadinessGaps();
		const ready = gaps.length === 0;
		createInvoiceSubmit.disabled = !ready;
		createInvoiceSubmit.classList.toggle('opacity-50', !ready);
		createInvoiceSubmit.classList.toggle('cursor-not-allowed', !ready);
		paintInvoiceReadinessPanel(gaps);
	}

	function discountsBlockedForInstallment() {
		return saleTypeSelect.value === 'installment';
	}

	function clearAndDisableDiscountControls() {
		const blocked = discountsBlockedForInstallment();
		linesBody.querySelectorAll('[data-line-row]').forEach((row) => {
			const typeEl = row.querySelector('.line-discount-type');
			const inputEl = row.querySelector('.line-discount-input');
			if (blocked) {
				if (typeEl) {
					typeEl.value = 'percent';
					typeEl.disabled = true;
					typeEl.title = 'Discount is not available for installment sales';
				}
				if (inputEl) {
					inputEl.value = '';
					inputEl.disabled = true;
					inputEl.title = 'Discount is not available for installment sales';
				}
			} else {
				if (typeEl) {
					typeEl.disabled = !invoiceLineDiscountUi;
					typeEl.title = 'Percent of line or fixed amount';
				}
				if (inputEl) {
					inputEl.disabled = !invoiceLineDiscountUi;
					inputEl.title = '';
				}
			}
		});
		document.querySelectorAll('.installment-discount-lock, .installment-discount-note').forEach((el) => {
			el.classList.toggle('hidden', !blocked);
			if (!blocked) {
				el.querySelector('.installment-discount-tip')?.classList.add('hidden');
			}
		});
		if (discountTotalInput) {
			if (blocked) {
				discountTotalInput.value = '';
				discountTotalInput.disabled = true;
				discountTotalInput.title = 'Discount is not available for installment sales';
			} else {
				discountTotalInput.disabled = false;
				discountTotalInput.title = '';
			}
		}
	}

	document.addEventListener('mouseover', (event) => {
		const lock = event.target.closest?.('.installment-discount-lock');
		if (lock) lock.querySelector('.installment-discount-tip')?.classList.remove('hidden');
	});
	document.addEventListener('mouseout', (event) => {
		const lock = event.target.closest?.('.installment-discount-lock');
		if (lock && !lock.contains(event.relatedTarget)) lock.querySelector('.installment-discount-tip')?.classList.add('hidden');
	});
	document.addEventListener('click', (event) => {
		const lock = event.target.closest?.('.installment-discount-lock');
		document.querySelectorAll('.installment-discount-tip').forEach((tip) => {
			if (!lock || !lock.contains(tip)) tip.classList.add('hidden');
		});
		if (lock) {
			lock.querySelector('.installment-discount-tip')?.classList.remove('hidden');
			lock.focus();
		}
	});
	document.addEventListener('focusout', (event) => {
		event.target.closest?.('.installment-discount-lock')?.querySelector('.installment-discount-tip')?.classList.add('hidden');
	});

	function onSaleTypeChanged(fromUser = true) {
		const type = saleTypeSelect.value;
		syncDrawerBranchInputs();
		if (type === 'default') {
			paymentTermsConfirmed = true;
			closePaymentDrawer();
		} else {
			paymentTermsConfirmed = false;
			if (fromUser) {
				openPaymentDrawer();
			}
		}
		clearAndDisableDiscountControls();
		toggleCustomFrequencyField();
		refreshPaymentTypeStatus();
		refreshSubmitEnabled();
		updateSummary();
	}

	function getProductsForBranch() {
		const branchId = branchSelect.value;
		if (!branchId) {
			return [];
		}
		return productsByLocation[branchId] || productsByLocation[String(branchId)] || [];
	}

	function fillProductSelect(productSelect, preservedValue = null) {
		const prods = getProductsForBranch();
		const previous = preservedValue !== null && preservedValue !== undefined ? String(preservedValue) : String(productSelect.value || '');
		productSelect.textContent = '';
		const emptyOpt = document.createElement('option');
		emptyOpt.value = '';
		emptyOpt.textContent = 'Select product';
		productSelect.appendChild(emptyOpt);

		for (const p of prods) {
			const opt = document.createElement('option');
			opt.value = String(p.id);
			opt.textContent = p.label;
			opt.dataset.price = String(p.price);
			if (invoiceLineProfitUi) {
				opt.dataset.purchasePrice = String(p.purchase_price ?? 0);
			}
			opt.dataset.serialized = p.is_serialized ? '1' : '0';
			opt.dataset.availableQty = String(p.available_qty ?? 0);
			opt.dataset.onHandQty = String(p.on_hand_qty ?? 0);
			opt.dataset.reservedQty = String(p.reserved_qty ?? 0);
			opt.dataset.sku = String(p.sku ?? '');
			opt.dataset.barcode = String(p.barcode ?? '');
			if (p.maximum_deductible != null && p.maximum_deductible !== '') {
				opt.dataset.maxDeduct = String(p.maximum_deductible);
			}
			opt.dataset.search = p.search || `${p.sku} ${p.name}`.toLowerCase();
			productSelect.appendChild(opt);
		}

		const match = Boolean(previous && prods.some((p) => String(p.id) === previous));
		productSelect.value = match ? previous : '';
	}

	function resetInvoiceBarcodeWedgeBuffer() {
		invoiceBarcodeWedgeBuffer = '';
		invoiceBarcodeWedgeLastTs = 0;

		if (invoiceBarcodeWedgeResetId !== null) {
			window.clearTimeout(invoiceBarcodeWedgeResetId);

			invoiceBarcodeWedgeResetId = null;
		}
	}

	function invoiceBarcodeDrawersBlocking() {
		return Boolean(
			(paymentTermsDrawer && !paymentTermsDrawer.classList.contains('hidden'))
			|| (customerDrawer && !customerDrawer.classList.contains('hidden'))
		);
	}

	function invoiceScanNotice(message, variant = 'info') {
		if (typeof window.appAlert === 'function') {
			window.appAlert(message, {
				icon: variant === 'error' ? 'error' : 'info',
				title: variant === 'error' ? 'Barcode' : 'Scan',
			});
		} else {
			window.alert(message);
		}
	}

	function normalizeInvoiceBarcodeToken(raw) {
		return String(raw ?? '').replace(/^\uFEFF/u, '').trim();
	}

	/**
	 * Resolve a wedge‑scanner string to product rows from productsByLocation / current branch.
	 */
	function resolveInvoiceScanProducts(rawCode) {
		const raw = normalizeInvoiceBarcodeToken(rawCode);
		if (!raw) {
			return [];
		}
		const lower = raw.toLowerCase();
		const prods = getProductsForBranch();
		const out = [];

		function pushDistinct(p) {
			if (!p || out.some((h) => String(h.id) === String(p.id))) {
				return;
			}
			out.push(p);
		}

		function step(filter) {
			for (const p of prods) {
				if (filter(p)) {
					pushDistinct(p);
				}
			}

			return out.length > 0;
		}

		out.length = 0;

		step((p) => {
			const bc = String(p.barcode ?? '').trim();

			return bc !== '' && (bc === raw || bc.toLowerCase() === lower);
		});
		if (out.length) {
			return [...out];
		}

		out.length = 0;

		step((p) => String(p.id) === raw);
		if (out.length) {
			return [...out];
		}

		out.length = 0;

		step((p) => {
			const model = String(p.model_number ?? '').trim().toLowerCase();

			return model !== '' && model === lower;
		});

		if (out.length) {
			return [...out];
		}

		const strippedInput = raw.replace(/^0+/u, '') || raw;

		out.length = 0;

		step((p) => {
			const bc = String(p.barcode ?? '').trim();
			const st = bc.replace(/^0+/u, '') || bc;

			return bc !== '' && st !== '' && st === strippedInput;
		});
		if (out.length === 1) {

			return [...out];
		}

		out.length = 0;

		if (lower.length >= 4) {
			for (const p of prods) {
				const hay = String(p.search || '').toLowerCase();
				const tokenHit = hay.split(/\s+/).some((t) => {
					const tokStrip = String(t || '').trim().replace(/^0+/u, '') || '';

					return t === lower || tokStrip === strippedInput;
				});
				const bc = String(p.barcode ?? '').trim().toLowerCase();
				if (tokenHit || (bc && (lower.endsWith(bc) || bc.endsWith(lower)))) {

					pushDistinct(p);
				}
			}
		}


		return out.length === 1 ? [...out] : [];
	}

	function pickInvoiceBarcodeTargetRow() {
		const active = document.activeElement;

		if (active?.matches?.('[data-product-search]')) {
			const row = active.closest('[data-line-row]');
			if (row) {
				return row;
			}
		}

		const rows = [...linesBody.querySelectorAll('[data-line-row]')];
		const emptyRow = rows.find((r) => !(r.querySelector('[data-product-select]')?.value));

		return emptyRow || rows.at(-1) || null;
	}

	function selectProductOnRow(row, product) {
		const productSelect = row.querySelector('[data-product-select]');
		const searchInput = row.querySelector('[data-product-search]');
		if (!productSelect || !searchInput || !product) {
			return false;
		}

		productSelect.value = String(product.id);
		const opt = productSelect.options[productSelect.selectedIndex];

		searchInput.value = opt && opt.value ? opt.text : '';
		productSelect.dispatchEvent(new Event('change'));

		searchInput.closest('[data-product-picker]')
			?.querySelector('[data-product-results]')
			?.classList.add('hidden');

		searchInput.setAttribute('aria-expanded', 'false');

		row.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
		searchInput.blur?.();

		return true;
	}

	function tryBumpQtyForSameScanProduct(row, product) {
		if (product.is_serialized) {
			return false;
		}

		const productSelect = row.querySelector('[data-product-select]');
		if (!productSelect || String(productSelect.value) !== String(product.id)) {

			return false;
		}

		const sel = productSelect.options[productSelect.selectedIndex];
		const avail = sel ? Number.parseInt(sel.dataset.availableQty ?? '0', 10) || 0 : 0;

		const qtyInput = row.querySelector('.line-qty');
		if (!qtyInput) {
			return false;
		}

		let q = parseQtyInput(qtyInput.value);
		if (Number.isNaN(q)) {

			q = 0;
		}

		if (q < 1) {
			q = 1;
		}

		const nextQty = q + 1;

		if (avail > 0 && nextQty > avail) {
			invoiceScanNotice(
				`Only ${avail} units available — cannot set quantity to ${nextQty}.`,
				'error',
			);

			return true;
		}

		qtyInput.value = String(nextQty);
		qtyInput.dispatchEvent(new Event('input', { bubbles: true }));
		qtyInput.dispatchEvent(new Event('change', { bubbles: true }));
		row.scrollIntoView({ behavior: 'smooth', block: 'nearest' });

		return true;
	}

	async function resolveSerialScanFromServer(code) {
		const branchId = branchSelect?.value;
		if (!branchId) {
			return { data: null, error: null };
		}

		const params = new URLSearchParams({
			branch_id: branchId,
			code,
		});

		try {
			const response = await fetch(`${resolveSerialScanUrl}?${params.toString()}`, {
				headers: {
					Accept: 'application/json',
					'X-Requested-With': 'XMLHttpRequest',
				},
			});

			if (!response.ok) {
				return { data: null, error: 'Could not look up serial. Try again.' };
			}

			return response.json();
		} catch {
			return { data: null, error: null };
		}
	}

	function findRowForNextSerial(productId) {
		for (const row of linesBody.querySelectorAll('[data-line-row]')) {
			const sel = row.querySelector('[data-product-select]');
			if (String(sel?.value) !== String(productId)) {
				continue;
			}
			const opt = sel?.options[sel?.selectedIndex];
			if (opt?.dataset?.serialized !== '1') {
				continue;
			}
			const hidden = [...row.querySelectorAll('[data-serial-hidden]')].find((h) => !String(h.value || '').trim());
			if (hidden) {
				return { row, hidden };
			}
		}

		return null;
	}

	function findSerializedRowsForProduct(productId) {
		const rows = [];
		for (const row of linesBody.querySelectorAll('[data-line-row]')) {
			const sel = row.querySelector('[data-product-select]');
			if (String(sel?.value) !== String(productId)) {
				continue;
			}
			const opt = sel?.options[sel?.selectedIndex];
			if (opt?.dataset?.serialized !== '1') {
				continue;
			}
			rows.push(row);
		}

		return rows;
	}

	/**
	 * @return {'ok': true}|{'ok': false, reason: 'none'|'inventory'}
	 */
	function tryIncrementQtyOnLastSerializedLine(productId) {
		const rows = findSerializedRowsForProduct(productId);
		if (!rows.length) {
			return { ok: false, reason: 'none' };
		}
		const row = rows[rows.length - 1];
		const qtyInput = row.querySelector('.line-qty');
		if (!qtyInput) {
			return { ok: false, reason: 'none' };
		}
		let q = parseQtyInput(qtyInput.value);
		if (Number.isNaN(q) || q < 1) {
			q = 1;
		}
		const sel = row.querySelector('[data-product-select]');
		const opt = sel?.options[sel?.selectedIndex];
		const avail = opt ? Number.parseInt(opt.dataset.availableQty ?? '0', 10) || 0 : 0;
		const nextQty = q + 1;
		if (avail > 0 && nextQty > avail) {
			invoiceScanNotice(
				`Only ${avail} units available — cannot increase quantity to ${nextQty}.`,
				'error',
			);

			return { ok: false, reason: 'inventory' };
		}
		qtyInput.value = String(nextQty);
		qtyInput.dispatchEvent(new Event('input', { bubbles: true }));
		qtyInput.dispatchEvent(new Event('change', { bubbles: true }));

		return { ok: true };
	}

	function isSerialAlreadyOnInvoice(hit) {
		const serial = hit?.serial;
		if (!serial) {
			return false;
		}
		const idStr = serial.id != null ? String(serial.id).trim() : '';
		if (idStr !== '') {
			for (const h of document.querySelectorAll('[data-serial-hidden]')) {
				if (String(h.value || '').trim() === idStr) {
					return true;
				}
			}
		}
		const sn = normalizeInvoiceBarcodeToken(serial.serial_number || '');
		const mfg = normalizeInvoiceBarcodeToken(serial.manufacturer_serial || '');
		if (sn === '' && mfg === '') {
			return false;
		}
		for (const inp of document.querySelectorAll('[data-serial-search-input]')) {
			const v = normalizeInvoiceBarcodeToken(inp.value || '');
			if (sn && v === sn) {
				return true;
			}
			if (mfg && v === mfg) {
				return true;
			}
		}

		return false;
	}

	/**
	 * A serial can carry its own sell price. When every serial picked on a line has the same price, the line
	 * uses it; otherwise the line falls back to the product's default sale price.
	 */
	function applySerialPriceToRow(row) {
		const unitPriceInput = row?.querySelector('.line-unit-price');
		if (!unitPriceInput) {
			return;
		}
		const filled = [...row.querySelectorAll('[data-serial-hidden]')].filter((h) => String(h.value || '').trim() !== '');
		if (filled.length === 0) {
			return;
		}
		const prices = filled.map((h) => (h.dataset.salePrice ?? '').trim());
		const allSame = prices.every((p) => p !== '' && p === prices[0]);
		let next = '';
		if (allSame) {
			next = prices[0];
		} else {
			const productSelect = row.querySelector('select[name$="[product_id]"]');
			next = productSelect?.options[productSelect.selectedIndex]?.dataset.price || '';
		}
		if (next !== '' && unitPriceInput.value !== next) {
			unitPriceInput.value = next;
			unitPriceInput.dispatchEvent(new Event('input', { bubbles: true }));
		}
	}

	function fillSerialPicker(row, hidden, serial) {
		hidden.value = String(serial.id);
		hidden.dataset.salePrice = serial.sale_price != null ? String(serial.sale_price) : '';
		applySerialPriceToRow(row);
		const picker = hidden.closest('[data-serial-picker]');
		const inp = picker?.querySelector('[data-serial-search-input]');
		if (inp) {
			inp.value = serial.label || serial.serial_number;
		}
		row.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
	}

	function applySerialScanHit(hit) {
		const product = getProductsForBranch().find((p) => String(p.id) === String(hit.product_id));
		if (!product) {
			invoiceScanNotice('This serial’s product is not stocked at the selected branch.', 'error');

			return;
		}

		if (!product.is_serialized) {
			invoiceScanNotice('This unit is serialized in inventory but the product is not marked serialized. Update the product first.', 'error');

			return;
		}

		if (isSerialAlreadyOnInvoice(hit)) {
			invoiceScanNotice('This serial is already added to the invoice.', 'error');

			return;
		}

		let slot = findRowForNextSerial(hit.product_id);
		if (slot) {
			fillSerialPicker(slot.row, slot.hidden, hit.serial);
			refreshSerialAvailability();
			refreshSubmitEnabled();
			updateSummary();

			return;
		}

		const bump = tryIncrementQtyOnLastSerializedLine(hit.product_id);
		if (bump.reason === 'inventory') {
			return;
		}
		if (bump.ok) {
			slot = findRowForNextSerial(hit.product_id);
			if (slot) {
				fillSerialPicker(slot.row, slot.hidden, hit.serial);
				refreshSerialAvailability();
				refreshSubmitEnabled();
				updateSummary();

				return;
			}
		}

		let row = pickInvoiceBarcodeTargetRow();
		const productSelect = row?.querySelector('[data-product-select]');
		const rowHasProduct = Boolean(productSelect?.value);
		if (!row || rowHasProduct) {
			addLine();
			row = linesBody.querySelector('[data-line-row]:last-child');
		}
		if (!row) {
			invoiceScanNotice('Unable to create a product line.', 'error');

			return;
		}

		selectProductOnRow(row, product);

		const qtyInput = row.querySelector('.line-qty');
		if (qtyInput) {
			const q = parseQtyInput(qtyInput.value);
			if (Number.isNaN(q) || q < 1) {
				qtyInput.value = '1';
			}
		}

		requestAnimationFrame(() => {
			const hidden = [...row.querySelectorAll('[data-serial-hidden]')].find((h) => !String(h.value || '').trim());
			if (hidden && hit.serial) {
				fillSerialPicker(row, hidden, hit.serial);
			}
			refreshSerialAvailability();
			refreshSubmitEnabled();
			updateSummary();
		});
	}

	async function handleInvoiceBarcodeScan(rawCode) {
		const code = normalizeInvoiceBarcodeToken(rawCode);
		if (!code) {
			return;
		}

		if (!branchSelect?.value) {
			invoiceScanNotice('Choose a stock location before scanning.', 'error');

			return;
		}

		if (paymentTermsDrawer && !paymentTermsDrawer.classList.contains('hidden')) {
			invoiceScanNotice('Close the payment terms drawer before scanning.', 'error');

			return;
		}

		if (customerDrawer && !customerDrawer.classList.contains('hidden')) {
			invoiceScanNotice('Close the customer drawer before scanning.', 'error');

			return;
		}

		const serialPayload = await resolveSerialScanFromServer(code);
		if (serialPayload.error) {
			invoiceScanNotice(serialPayload.error, 'error');

			return;
		}
		if (serialPayload.data) {
			applySerialScanHit(serialPayload.data);
			invoiceBarcodeScanInput?.focus({ preventScroll: true });

			return;
		}

		const matches = resolveInvoiceScanProducts(code);

		if (!matches.length) {
			invoiceScanNotice(`No in-stock serial matched “${code}”, and no product matched by barcode for this branch.`, 'error');

			return;
		}

		if (matches.length > 1) {
			invoiceScanNotice(
				`Multiple products match “${code}”. Use the line search to pick the right one.`,
				'error',
			);

			return;
		}

		const product = matches[0];
		let row = pickInvoiceBarcodeTargetRow();

		if (!row) {
			addLine();
			row = linesBody.querySelector('[data-line-row]:last-child');

			if (!row) {
				invoiceScanNotice('Unable to create a product line.', 'error');

				return;
			}
		}

		if (tryBumpQtyForSameScanProduct(row, product)) {
			invoiceBarcodeScanInput?.focus({ preventScroll: true });

			return;
		}

		selectProductOnRow(row, product);
		invoiceBarcodeScanInput?.focus({ preventScroll: true });
	}

	function escapeHtml(text) {
		return String(text)
			.replace(/&/g, '&amp;')
			.replace(/</g, '&lt;')
			.replace(/"/g, '&quot;');
	}

	function inventoryBadgesHtml(availableQty, onHandQty, reservedQty) {
		const av = escapeHtml(String(availableQty ?? 0));
		const oh = escapeHtml(String(onHandQty ?? 0));
		const rs = escapeHtml(String(reservedQty ?? 0));
		return `<div class="flex flex-wrap gap-1.5">
			<span class="inline-flex rounded-full bg-emerald-50 px-2 py-1 text-xs font-semibold text-emerald-700">Available ${av}</span>
			<span class="inline-flex rounded-full bg-slate-100 px-2 py-1 text-xs font-medium text-slate-700">On Hand ${oh}</span>
			<span class="inline-flex rounded-full bg-amber-50 px-2 py-1 text-xs font-medium text-amber-700">Reserved ${rs}</span>
		</div>`;
	}

	function refreshLineProductInventory(row) {
		const invWrap = row.querySelector('[data-product-inventory]');
		const emptyHint = row.querySelector('[data-product-inventory-empty]');
		const scopeLabel = row.querySelector('[data-product-inventory-scope]');
		const productSelect = row.querySelector('[data-product-select]');
		if (!invWrap || !productSelect) {
			return;
		}
		const branchId = branchSelect?.value;
		const selected = productSelect.options[productSelect.selectedIndex];

		if (scopeLabel) {
			scopeLabel.classList.toggle('hidden', !branchId);
		}

		if (!branchId) {
			if (emptyHint) {
				emptyHint.textContent = 'Choose a branch to see stock for this line.';
				emptyHint.classList.remove('hidden');
			}
			invWrap.innerHTML = '';
			invWrap.classList.add('hidden');
			invWrap.classList.remove('flex');
			return;
		}

		if (!selected?.value) {
			invWrap.innerHTML = '';
			invWrap.classList.add('hidden');
			invWrap.classList.remove('flex');
			if (emptyHint) {
				emptyHint.textContent = 'Select a product to see Available, On hand, and Reserved for this branch.';
				emptyHint.classList.remove('hidden');
			}
			return;
		}

		if (emptyHint) {
			emptyHint.classList.add('hidden');
		}
		invWrap.innerHTML = inventoryBadgesHtml(
			selected.dataset.availableQty,
			selected.dataset.onHandQty,
			selected.dataset.reservedQty,
		);
		invWrap.classList.remove('hidden');
		invWrap.classList.add('flex');
	}

	let invoiceSerialPickerDocBound = false;

	function ensureInvoiceSerialPickerDocClose() {
		if (invoiceSerialPickerDocBound) {
			return;
		}
		invoiceSerialPickerDocBound = true;
		document.addEventListener('click', (event) => {
			if (event.target.closest('[data-serial-picker]')) {
				return;
			}
			document.querySelectorAll('[data-serial-results]').forEach((el) => {
				el.classList.add('hidden');
				const inp = el.closest('[data-serial-picker]')?.querySelector('[data-serial-search-input]');
				if (inp) {
					inp.setAttribute('aria-expanded', 'false');
				}
			});
		});
	}

	function collectExcludeSerialIdsExcept(exceptPicker) {
		const ids = [];
		document.querySelectorAll('[data-serial-picker]').forEach((p) => {
			if (p === exceptPicker) {
				return;
			}
			const v = p.querySelector('[data-serial-hidden]')?.value?.trim();
			if (v) {
				ids.push(v);
			}
		});
		return [...new Set(ids)];
	}

	function refreshSerialAvailability() {
		const counts = new Map();
		document.querySelectorAll('[data-serial-hidden]').forEach((h) => {
			const v = h.value?.trim();
			if (!v) {
				return;
			}
			counts.set(v, (counts.get(v) || 0) + 1);
		});
		document.querySelectorAll('[data-serial-picker]').forEach((p) => {
			const h = p.querySelector('[data-serial-hidden]');
			const inp = p.querySelector('[data-serial-search-input]');
			const v = h?.value?.trim() || '';
			const dup = v !== '' && (counts.get(v) || 0) > 1;
			inp?.classList.toggle('border-rose-400', dup);
			inp?.classList.toggle('bg-rose-50', dup);
		});
	}

	function wireSerialPicker(picker, productSelect) {
		ensureInvoiceSerialPickerDocClose();
		const searchInput = picker.querySelector('[data-serial-search-input]');
		const hiddenInput = picker.querySelector('[data-serial-hidden]');
		const results = picker.querySelector('[data-serial-results]');
		if (!searchInput || !hiddenInput || !results) {
			return;
		}

		let debounceId = null;
		let highlightedIndex = -1;
		let lastItems = [];

		function closeResults() {
			results.classList.add('hidden');
			searchInput.setAttribute('aria-expanded', 'false');
			highlightedIndex = -1;
		}

		function selectFromItem(item) {
			hiddenInput.value = String(item.id);
			hiddenInput.dataset.salePrice = item.sale_price != null ? String(item.sale_price) : '';
			searchInput.value = item.label || item.serial_number;
			applySerialPriceToRow(picker.closest('[data-line-row]'));
			closeResults();
			refreshSerialAvailability();
			refreshSubmitEnabled();
		}

		async function fetchAndRender(query) {
			const branchId = document.querySelector('select[name="branch_id"]')?.value;
			const selected = productSelect.options[productSelect.selectedIndex];
			const productId = selected?.value || '';
			if (!branchId || !productId) {
				results.innerHTML = '<div class="px-2 py-1.5 text-xs text-slate-500">Pick branch & product</div>';
				results.classList.remove('hidden');
				searchInput.setAttribute('aria-expanded', 'true');
				highlightedIndex = -1;
				return;
			}

			const exclude = collectExcludeSerialIdsExcept(picker);
			const params = new URLSearchParams({
				branch_id: branchId,
				product_id: productId,
				query: query.trim(),
			});
			if (exclude.length) {
				params.set('exclude_ids', exclude.join(','));
			}

			try {
				const response = await fetch(`{{ route('sales.invoices.serial-suggestions') }}?${params.toString()}`, {
					headers: {
						Accept: 'application/json',
						'X-Requested-With': 'XMLHttpRequest',
					},
				});

				if (!response.ok) {
					return;
				}

				const payload = await response.json();
				lastItems = payload.data || [];
				const ownId = (hiddenInput.value || '').trim();

				if (!lastItems.length) {
					results.innerHTML = '<div class="px-2 py-1.5 text-xs text-slate-500">No serials</div>';
					results.classList.remove('hidden');
					searchInput.setAttribute('aria-expanded', 'true');
					highlightedIndex = -1;
					return;
				}

				results.innerHTML = lastItems
					.map(
						(item, index) =>
							`<button type="button" class="block w-full px-2 py-1.5 text-left text-xs hover:bg-slate-100 ${index === 0 ? 'bg-slate-50' : ''}" data-pick-index="${index}">${escapeHtml(item.label || item.serial_number)}</button>`
					)
					.join('');
				results.classList.remove('hidden');
				searchInput.setAttribute('aria-expanded', 'true');
				highlightedIndex = 0;
				[...results.querySelectorAll('[data-pick-index]')].forEach((btn, idx) => {
					btn.classList.toggle('bg-slate-50', idx === highlightedIndex);
				});

				if (ownId) {
					const match = lastItems.find((it) => String(it.id) === ownId);
					if (match) {
						searchInput.value = match.label || match.serial_number;
					}
				}
			} catch {
				// keep field usable
			}
		}

		function scheduleFetch(query) {
			if (debounceId) {
				clearTimeout(debounceId);
			}
			debounceId = setTimeout(() => {
				fetchAndRender(query);
			}, 200);
		}

		searchInput.addEventListener('focus', () => {
			document.querySelectorAll('[data-serial-results]').forEach((el) => {
				if (!picker.contains(el)) {
					el.classList.add('hidden');
				}
			});
			fetchAndRender(searchInput.value);
		});

		searchInput.addEventListener('input', () => {
			hiddenInput.value = '';
			scheduleFetch(searchInput.value);
			refreshSubmitEnabled();
		});

		searchInput.addEventListener('keydown', (event) => {
			const items = [...results.querySelectorAll('[data-pick-index]')];
			if (event.key === 'Enter') {
				event.preventDefault();
				if (!items.length) {
					return;
				}
				const idx = highlightedIndex >= 0 ? highlightedIndex : 0;
				const item = lastItems[idx];
				if (item) {
					selectFromItem(item);
				}

				return;
			}

			if (!items.length) {
				return;
			}

			if (event.key === 'ArrowDown') {
				event.preventDefault();
				highlightedIndex = Math.min(highlightedIndex + 1, items.length - 1);
			} else if (event.key === 'ArrowUp') {
				event.preventDefault();
				highlightedIndex = Math.max(highlightedIndex - 1, 0);
			} else if (event.key === 'Escape') {
				closeResults();

				return;
			} else {
				return;
			}

			items.forEach((btn, index) => {
				btn.classList.toggle('bg-slate-50', index === highlightedIndex);
			});
		});

		results.addEventListener('mousedown', (event) => {
			event.preventDefault();
		});

		results.addEventListener('click', (event) => {
			const target = event.target.closest('[data-pick-index]');
			if (!target) {
				return;
			}
			const idx = Number.parseInt(target.getAttribute('data-pick-index'), 10);
			const item = lastItems[idx];
			if (item) {
				selectFromItem(item);
			}
		});

		refreshSerialAvailability();
	}

	let invoiceProductSearchOutsideCloseBound = false;
	function ensureInvoiceProductSearchOutsideClose() {
		if (invoiceProductSearchOutsideCloseBound) {
			return;
		}
		invoiceProductSearchOutsideCloseBound = true;
		document.addEventListener('click', (event) => {
			document.querySelectorAll('[data-product-picker]').forEach((picker) => {
				if (picker.contains(event.target)) {
					return;
				}
				const results = picker.querySelector('[data-product-results]');
				const inp = picker.querySelector('[data-product-search]');
				if (results && !results.classList.contains('hidden')) {
					results.classList.add('hidden');
					inp?.setAttribute('aria-expanded', 'false');
				}
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

		ensureInvoiceProductSearchOutsideClose();

		let highlightedIndex = -1;

		function closeResults() {
			results.classList.add('hidden');
			searchInput.setAttribute('aria-expanded', 'false');
			highlightedIndex = -1;
		}

		function productOptionsToList() {
			return [...productSelect.options]
				.filter((option) => option.value !== '')
				.map((option) => ({
					value: option.value,
					label: option.text,
					search: option.dataset.search || option.text.toLowerCase(),
					availableQty: option.dataset.availableQty ?? '0',
					onHandQty: option.dataset.onHandQty ?? '0',
					reservedQty: option.dataset.reservedQty ?? '0',
				}));
		}

		function renderResults(keyword) {
			const products = productOptionsToList();
			const normalizedKeyword = keyword.trim().toLowerCase();
			const filtered = products
				.filter((product) => normalizedKeyword === '' || product.search.includes(normalizedKeyword))
				.slice(0, 40);

			if (!filtered.length) {
				const branchId = document.querySelector('select[name="branch_id"]')?.value;
				const hint = !branchId
					? 'Choose a branch first, then search products.'
					: 'No products match that search for this branch.';
				results.innerHTML = `<div class="px-3 py-2 text-sm text-slate-500">${escapeHtml(hint)}</div>`;
				results.classList.remove('hidden');
				searchInput.setAttribute('aria-expanded', 'true');
				return [];
			}

			results.innerHTML = filtered
				.map(
					(product, index) =>
						`<button type="button" class="block w-full px-3 py-2 text-left hover:bg-slate-100 ${index === 0 ? 'bg-slate-50' : ''}" data-product-value="${escapeHtml(product.value)}">
							<p class="text-sm font-medium text-slate-900">${escapeHtml(product.label)}</p>
							<div class="mt-1.5">${inventoryBadgesHtml(product.availableQty, product.onHandQty, product.reservedQty)}</div>
						</button>`
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
				productSelect.dispatchEvent(new Event('change'));
				return;
			}
			productSelect.value = value;
			syncInputWithSelection();
			productSelect.dispatchEvent(new Event('change'));
			closeResults();
		}

		searchInput.addEventListener('focus', () => renderResults(searchInput.value));
		searchInput.addEventListener('input', () => {
			const hadSelection = Boolean(productSelect.value);
			productSelect.value = '';
			if (hadSelection) {
				productSelect.dispatchEvent(new Event('change'));
			}
			renderResults(searchInput.value);
		});

		searchInput.addEventListener('keydown', (event) => {
			const items = [...results.querySelectorAll('[data-product-value]')];
			if (event.key === 'Enter') {
				event.preventDefault();
				if (!items.length) {
					const code = normalizeInvoiceBarcodeToken(searchInput.value);

					void handleInvoiceBarcodeScan(code);

					return;
				}
				const selectedItem = items[highlightedIndex] || items[0];
				if (selectedItem) {

					selectProductByValue(selectedItem.dataset.productValue);
				}

				return;
			}

			if (!items.length) {
				return;
			}

			if (event.key === 'ArrowDown') {
				event.preventDefault();

				highlightedIndex = Math.min(highlightedIndex + 1, items.length - 1);

			} else if (event.key === 'ArrowUp') {

				event.preventDefault();

				highlightedIndex = Math.max(highlightedIndex - 1, 0);


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

		results.addEventListener('mousedown', (event) => {
			event.preventDefault();
		});

		results.addEventListener('click', (event) => {
			const target = event.target.closest('[data-product-value]');
			if (!target) {
				return;
			}
			selectProductByValue(target.dataset.productValue);
		});

		syncInputWithSelection();
		refreshLineProductInventory(row);
	}

	function rebuildProductPickersForBranch() {
		[...linesBody.querySelectorAll('[data-line-row]')].forEach((row) => {
			const productSelect = row.querySelector('[data-product-select]');
			if (!productSelect) {
				return;
			}
			const prev = productSelect.value;
			fillProductSelect(productSelect, prev);
			const searchInput = row.querySelector('[data-product-search]');
			if (searchInput) {
				if (productSelect.value) {
					const selectedOption = productSelect.options[productSelect.selectedIndex];
					searchInput.value = selectedOption && selectedOption.value ? selectedOption.text : '';
				} else {
					searchInput.value = '';
				}
			}
			productSelect.dispatchEvent(new Event('change'));
			refreshLineProductInventory(row);
		});
		updateSummary();
	}

	function toggleCustomFrequencyField(clearDate = false) {
		if (saleTypeSelect.value !== 'installment') {
			installmentCustomDaysWrap.classList.add('hidden');
			syncInstallmentMirror();
			return;
		}
		const isCustom = installmentFrequencySelect.value === 'custom';
		installmentCustomDaysWrap.classList.toggle('hidden', !isCustom);
		syncInstallmentMirror();
		syncFirstDueDateMin(clearDate);
	}

	function syncCreditDueDateMin() {
		if (!drawerCreditDueDate) return;
		const now = new Date();
		const today = new Date(now.getFullYear(), now.getMonth(), now.getDate());
		const toDateStr = (d) => {
			const y = d.getFullYear();
			const m = String(d.getMonth() + 1).padStart(2, '0');
			const day = String(d.getDate()).padStart(2, '0');
			return `${y}-${m}-${day}`;
		};
		const minStr = toDateStr(today);
		// Edit mode: cap at original existing date so old invoices are never blocked.
		// Create mode: cap at today + 30 days.
		let maxDate;
		if (editCreditDueDate) {
			maxDate = new Date(editCreditDueDate + 'T00:00:00');
		} else {
			maxDate = new Date(today);
			maxDate.setDate(maxDate.getDate() + 30);
		}
		const maxStr = toDateStr(maxDate);
		drawerCreditDueDate.min = minStr;
		drawerCreditDueDate.max = maxStr;
		if (drawerCreditDueDate.value && drawerCreditDueDate.value < minStr) {
			drawerCreditDueDate.value = '';
		}
		if (drawerCreditDueDate.value && drawerCreditDueDate.value > maxStr) {
			drawerCreditDueDate.value = '';
		}
		const label = document.getElementById('credit-due-date-max-label');
		if (label) {
			const fmt = maxDate.toLocaleDateString('en-GB', { day: 'numeric', month: 'long', year: 'numeric' });
			label.textContent = `Max allowed: ${fmt}${editCreditDueDate ? ' (original due date)' : ' (30 days from today)'}`;
		}
	}

	function syncFirstDueDateMin(clearDate = false) {
		if (!installmentFirstDueDateInput) return;
		if (document.getElementById('installment-date-unlock')?.checked) return;
		const freq = installmentFrequencySelect?.value || 'monthly';
		const now = new Date();
		const today = new Date(now.getFullYear(), now.getMonth(), now.getDate());
		let minDate = new Date(today);
		let badgeText = '';
		let descText = '';
		const fmtFull = (d) => d.toLocaleDateString('en-GB', { day: 'numeric', month: 'long', year: 'numeric' });
		const toLocalDateStr = (d) => {
			const y = d.getFullYear();
			const m = String(d.getMonth() + 1).padStart(2, '0');
			const day = String(d.getDate()).padStart(2, '0');
			return `${y}-${m}-${day}`;
		};
		if (freq === 'daily') {
			minDate.setDate(minDate.getDate() + 1);
			badgeText = 'Select from: ' + fmtFull(minDate);
			descText = 'Daily — today & past dates disabled. Pick tomorrow or later.';
		} else if (freq === 'weekly') {
			minDate.setDate(minDate.getDate() + 7);
			badgeText = 'Select from: ' + fmtFull(minDate);
			descText = 'Weekly — next 7 days including today are disabled. Pick 1 week ahead or later.';
		} else if (freq === 'custom') {
			const days = Math.max(Number(installmentCustomDaysInput?.value || 0), 1);
			minDate.setDate(minDate.getDate() + days);
			badgeText = 'Select from: ' + fmtFull(minDate);
			descText = 'Custom (' + days + ' days) — dates before ' + fmtFull(minDate) + ' are disabled.';
		} else {
			const todayDay = today.getDate();
			minDate.setMonth(minDate.getMonth() + 1);
			const nextMonthName = minDate.toLocaleDateString('en-GB', { month: 'long', year: 'numeric' });
			badgeText = 'Select from: ' + fmtFull(minDate);
			descText = 'Monthly — this entire month is disabled. Pick from ' + nextMonthName + ' (day ' + todayDay + ' or later).';
		}
		const minStr = toLocalDateStr(minDate);
		installmentFirstDueDateInput.min = minStr;
		if (clearDate || (!installmentAdminEditMode && installmentFirstDueDateInput.value && installmentFirstDueDateInput.value < minStr)) {
			installmentFirstDueDateInput.value = '';
		}
		const hintEl = document.getElementById('installment-first-due-hint');
		if (hintEl) hintEl.textContent = badgeText;
		const descEl = document.getElementById('installment-first-due-desc');
		if (descEl) descEl.textContent = descText;
	}

	function formatMoney(value) {
		return Number(value || 0).toFixed(2);
	}

	function scrubNumericString(raw) {
		return String(raw ?? '').trim().replace(/,/g, '').replace(/^\++/u, '');
	}

	function parseQtyInput(raw) {
		const s = scrubNumericString(raw);
		if (s === '' || s === '-' || s === '.' || s === '-.') {
			return 0;
		}
		const n = Number.parseFloat(s);
		if (!Number.isFinite(n)) {
			return Number.NaN;
		}
		return Math.max(0, Math.floor(Math.abs(n)));
	}

	function parseMoneyInput(raw) {
		const s = scrubNumericString(raw);
		if (s === '' || s === '-' || s === '.' || s === '-.') {
			return 0;
		}
		const n = Number.parseFloat(s);
		return Number.isFinite(n) ? n : Number.NaN;
	}

	function formatPercent(value) {
		if (!Number.isFinite(value) || value <= 0) {
			return '';
		}
		return `${Number.parseFloat(value.toFixed(2)).toString()}%`;
	}

	function formatSignedPercent(value) {
		if (value === null || !Number.isFinite(value)) {
			return '—';
		}
		const rounded = Math.round(value * 10) / 10;
		const positive = rounded >= 0;
		const body = Math.abs(rounded).toLocaleString(undefined, { minimumFractionDigits: 1, maximumFractionDigits: 1 });
		return `${positive ? '+' : '-'}${body}%`;
	}

	function profitPanelClasses(value) {
		if (value === null || !Number.isFinite(value)) {
			return 'border-slate-100 bg-slate-50';
		}
		return value >= 0 ? 'border-emerald-200 bg-emerald-50' : 'border-rose-200 bg-rose-50';
	}

	function profitTextClasses(value) {
		if (value === null || !Number.isFinite(value)) {
			return 'text-slate-500';
		}
		return value >= 0 ? 'text-emerald-800' : 'text-rose-800';
	}

	function lineSnapshotFromRow(row) {
		const qtyRaw = row.querySelector('.line-qty')?.value;
		const unitRaw = row.querySelector('.line-unit-price')?.value;
		let qty = parseQtyInput(qtyRaw);
		let unitPrice = parseMoneyInput(unitRaw);
		if (Number.isNaN(qty)) {
			qty = 0;
		}
		if (Number.isNaN(unitPrice)) {
			unitPrice = 0;
		}
		unitPrice = Math.max(unitPrice, 0);
		const gross = Math.round(Math.max(qty * unitPrice, 0) * 100) / 100;
		const typeEl = row.querySelector('.line-discount-type');
		const discountInEl = row.querySelector('.line-discount-input');
		let discountAmt = 0;
		if (!discountsBlockedForInstallment() && typeEl && discountInEl && !discountInEl.disabled) {
			const raw = parseMoneyInput(discountInEl.value);
			const d = Number.isNaN(raw) ? 0 : Math.max(raw, 0);
			if (typeEl.value === 'fixed') {
				discountAmt = Math.round(Math.min(d, gross) * 100) / 100;
			} else {
				discountAmt = Math.round(Math.min(gross * (Math.min(d, 100) / 100), gross) * 100) / 100;
			}
		}
		const svcTypeEl = row.querySelector('.line-service-charge-type');
		const svcInEl = row.querySelector('.line-service-charge-input');
		const svcRaw = parseMoneyInput(svcInEl?.value);
		const svcInput = Number.isNaN(svcRaw) ? 0 : Math.max(svcRaw, 0);
		const netAfterDiscount = Math.max(gross - discountAmt, 0);
		let serviceCharge;
		if (svcTypeEl?.value === 'percent') {
			serviceCharge = Math.round(netAfterDiscount * (svcInput / 100) * 100) / 100;
		} else {
			serviceCharge = Math.round(svcInput * 100) / 100;
		}
		const total = Math.round(Math.max(netAfterDiscount + serviceCharge, 0) * 100) / 100;
		let discountPct = 0;
		if (discountAmt > 0 && gross > 0) {
			if (typeEl?.value === 'percent' && discountInEl) {
				const rawPct = parseMoneyInput(discountInEl.value);
				discountPct = Number.isNaN(rawPct) ? 0 : Math.min(Math.max(rawPct, 0), 100);
			} else {
				discountPct = Math.round((discountAmt / gross) * 10000) / 100;
			}
		}
		const productSelect = row.querySelector('[data-product-select]');
		const selected = productSelect?.options?.[productSelect.selectedIndex];
		let purchasePrice = 0;
		let costTotal = 0;
		let lineProfit = null;
		let markupPct = null;
		let marginPct = null;
		if (invoiceLineProfitUi) {
			purchasePrice = Number.parseFloat(selected?.dataset?.purchasePrice ?? '');
			if (Number.isNaN(purchasePrice)) {
				purchasePrice = 0;
			}
			purchasePrice = Math.max(purchasePrice, 0);
			costTotal = Math.round(purchasePrice * Math.max(qty, 0) * 100) / 100;
			lineProfit = Math.round((total - costTotal) * 100) / 100;
			if (costTotal > 0) {
				markupPct = Math.round((lineProfit / costTotal) * 10000) / 100;
			}
			if (total > 0) {
				marginPct = Math.round((lineProfit / total) * 10000) / 100;
			}
		}
		let maxDiscountPct = null;
		let maxDiscountAmt = null;
		const productMaxRaw = selected?.dataset?.maxDeduct;
		if (productMaxRaw != null && productMaxRaw !== '') {
			const n = Number.parseFloat(productMaxRaw);
			if (!Number.isNaN(n)) {
				maxDiscountPct = Math.min(100, Math.max(0, n));
			}
		} else if (globalMaximumDeductible != null && globalMaximumDeductible !== '') {
			const n = Number(globalMaximumDeductible);
			if (!Number.isNaN(n)) {
				maxDiscountPct = Math.min(100, Math.max(0, n));
			}
		}
		if (maxDiscountPct !== null) {
			maxDiscountAmt = Math.round(gross * (maxDiscountPct / 100) * 100) / 100;
		}
		return { qty, unitPrice, gross, discountAmt, discountPct, serviceCharge, total, purchasePrice, costTotal, lineProfit, markupPct, marginPct, maxDiscountPct, maxDiscountAmt };
	}

	function refreshLineHeadings() {
		[...linesBody.querySelectorAll('[data-line-row]')].forEach((row, i) => {
			const heading = row.querySelector('[data-line-heading]');
			if (heading) {
				heading.textContent = String(i + 1);
			}
		});
	}

	function getCurrentLines() {
		return [...linesBody.querySelectorAll('[data-line-row]')].map((row) => lineSnapshotFromRow(row));
	}

	function refreshLineValueSuffixes(row) {
		const discType = row.querySelector('.line-discount-type');
		const discSuffix = row.querySelector('.line-discount-suffix');
		if (discSuffix) {
			const show = discType?.value === 'percent';
			discSuffix.classList.toggle('hidden', !show);
			discSuffix.classList.toggle('flex', show);
			row.querySelector('.line-discount-input')?.classList.toggle('pr-7', show);
		}
		const svcType = row.querySelector('.line-service-charge-type');
		const svcSuffix = row.querySelector('.line-service-charge-suffix');
		if (svcSuffix) {
			const show = svcType?.value === 'percent';
			svcSuffix.classList.toggle('hidden', !show);
			svcSuffix.classList.toggle('flex', show);
			row.querySelector('.line-service-charge-input')?.classList.toggle('pr-7', show);
		}
	}

	function refreshLineComputedCells() {
		linesBody.querySelectorAll('[data-line-row]').forEach((row) => {
			refreshLineValueSuffixes(row);
			const snap = lineSnapshotFromRow(row);
			const discEl = row.querySelector('.line-discount-amt-display');
			const discPctEl = row.querySelector('.line-discount-pct-display');
			const totEl = row.querySelector('.line-line-total-display');
			const profitPanelEl = row.querySelector('.line-profit-panel');
			const profitAmountEl = row.querySelector('.line-profit-amount');
			const profitRatesEl = row.querySelector('.line-profit-rates');
			const profitHintEl = row.querySelector('.line-profit-hint');
			const discountLimitEl = row.querySelector('.line-discount-limit-display');
			const discountLimitHintEl = row.querySelector('.line-discount-limit-hint');
			if (discEl) {
				discEl.textContent = formatMoney(snap.discountAmt);
			}
			if (discPctEl) {
				discPctEl.textContent = snap.discountPct > 0 ? `(${formatPercent(snap.discountPct)})` : '';
			}
			if (totEl) {
				totEl.textContent = formatMoney(snap.total);
			}
			if (invoiceLineProfitUi && profitAmountEl) {
				const toneValue = snap.lineProfit;
				if (profitPanelEl) {
					profitPanelEl.className = `line-profit-panel rounded-lg border px-3 py-2 text-right ${profitPanelClasses(toneValue)}`;
				}
				profitAmountEl.className = `line-profit-amount text-sm font-semibold tabular-nums ${profitTextClasses(toneValue)}`;
				profitAmountEl.textContent = snap.lineProfit === null ? '—' : formatMoney(snap.lineProfit);

				const rateParts = [];
				if (snap.markupPct !== null) {
					rateParts.push(`Markup ${formatSignedPercent(snap.markupPct)}`);
				}
				if (snap.marginPct !== null) {
					rateParts.push(`Margin ${formatSignedPercent(snap.marginPct)}`);
				}
				if (profitRatesEl) {
					profitRatesEl.className = `line-profit-rates mt-0.5 text-[10px] tabular-nums ${profitTextClasses(toneValue)}`;
					profitRatesEl.textContent = rateParts.length ? rateParts.join(' · ') : '—';
				}
				if (profitHintEl) {
					if (!snap.purchasePrice || snap.qty <= 0) {
						profitHintEl.textContent = 'Markup on cost · margin on sale';
					} else {
						profitHintEl.textContent = `Sale+SC ${formatMoney(snap.total)} vs cost ${formatMoney(snap.costTotal)}`;
					}
				}
			}
			if (!invoiceLineProfitUi && discountLimitEl) {
				if (snap.maxDiscountPct === null) {
					discountLimitEl.textContent = 'Unlimited';
					discountLimitEl.className = 'line-discount-limit-display rounded-lg border border-slate-100 bg-slate-50 px-3 py-2 text-right text-sm font-semibold tabular-nums text-slate-700';
					if (discountLimitHintEl) {
						discountLimitHintEl.textContent = 'no max set';
					}
				} else {
					discountLimitEl.textContent = formatPercent(snap.maxDiscountPct);
					discountLimitEl.className = 'line-discount-limit-display rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-right text-sm font-semibold tabular-nums text-amber-900';
					if (discountLimitHintEl) {
						discountLimitHintEl.textContent = snap.gross > 0
							? `up to ${formatMoney(snap.maxDiscountAmt)} on this line`
							: 'max you can discount';
					}
				}
			}
		});
	}

	function addFrequency(startDate, offset) {
		const frequency = installmentFrequencySelect.value || 'monthly';
		const date = new Date(startDate.getTime());
		if (frequency === 'daily') {
			date.setDate(date.getDate() + offset);
			return date;
		}
		if (frequency === 'weekly') {
			date.setDate(date.getDate() + (offset * 7));
			return date;
		}
		if (frequency === 'custom') {
			const step = Math.max(Number(installmentCustomDaysInput.value || 1), 1);
			date.setDate(date.getDate() + (offset * step));
			return date;
		}
		date.setMonth(date.getMonth() + offset);
		return date;
	}

	function clearInvoiceClientError() {
		if (!invoiceClientError) {
			return;
		}
		invoiceClientError.classList.add('hidden');
		invoiceClientError.textContent = '';
	}

	function showInvoiceClientError(message) {
		if (!invoiceClientError) {
			return;
		}
		invoiceClientError.textContent = message;
		invoiceClientError.classList.remove('hidden');
	}

	function validateInvoiceLinesBeforeSubmit() {
		const rows = [...linesBody.querySelectorAll('[data-line-row]')];
		if (!rows.length) {
			return { ok: false, message: 'Add at least one line.', focus: null };
		}

		for (const row of rows) {
			const productSelect = row.querySelector('[data-product-select]');
			const searchInput = row.querySelector('[data-product-search]');
			const lineNo = row.querySelector('[data-line-heading]')?.textContent?.trim() || '';

			if (!productSelect || !productSelect.value) {
				return {
					ok: false,
					message: 'Select a product on every line before creating the invoice.',
					focus: searchInput,
				};
			}

			const opt = productSelect.options[productSelect.selectedIndex];
			const isSerialized = opt?.dataset?.serialized === '1';
			const qty = parseQtyInput(row.querySelector('.line-qty')?.value ?? '');

			if (Number.isNaN(qty)) {
				return {
					ok: false,
					message: `Line ${lineNo}: enter a whole number quantity.`,
					focus: row.querySelector('.line-qty'),
				};
			}

			if (qty < 1) {
				return {
					ok: false,
					message: `Line ${lineNo}: quantity must be at least 1.`,
					focus: row.querySelector('.line-qty'),
				};
			}

			if (isSerialized && qty >= 1) {
				const hiddens = row.querySelectorAll('[data-serial-hidden]');
				let filled = 0;
				hiddens.forEach((h) => {
					if (String(h.value || '').trim() !== '') {
						filled += 1;
					}
				});
				if (filled !== qty) {
					const label = opt?.text || 'this product';
					return {
						ok: false,
						message: `Line ${lineNo}: pick ${qty} serial number(s) for ${label}.`,
						focus: searchInput,
					};
				}
			}

			const unitPriceInput = row.querySelector('.line-unit-price');
			const price = parseMoneyInput(unitPriceInput?.value ?? '');
			if (unitPriceInput && (scrubNumericString(unitPriceInput.value) === '' || Number.isNaN(price) || price < 0)) {
				return { ok: false, message: `Line ${lineNo}: enter a valid unit price.`, focus: unitPriceInput };
			}
		}

		return { ok: true };
	}

	function collectInvoiceReadinessGaps() {
		const gaps = [];
		const invoiceDateInputEl = document.querySelector('input[name="invoice_date"]');

		if (!invoiceDateInputEl?.value?.trim()) {
			gaps.push('Choose an invoice date.');
		}

		if (!branchSelect?.value) {
			gaps.push('Choose a branch.');
		}

		const effectiveCustomerId = customerSelect?.value
			|| document.querySelector('input[type="hidden"][name="customer_id"]')?.value;
		if (!effectiveCustomerId) {
			gaps.push('Choose a customer.');
		}

		if (isCreateMode) {
			const referrerId = document.querySelector('input[name="referrer_id"]')?.value?.trim();
			if (!referrerId) {
				gaps.push('Select a referrer (employee).');
			}
		}

		const type = saleTypeSelect.value;

		if (type === 'credit') {
			if (postedEditMode) {
				const creditDuePosted = document.querySelector('input[name="credit_due_date"]:not(#drawer-credit-due-date)');
				if (!creditDuePosted?.value?.trim()) {
					gaps.push('Credit due date is required.');
				}
			} else if (!paymentTermsConfirmed) {
				gaps.push('Open Payment and confirm credit terms (due date required).');
			} else if (!drawerCreditDueDate?.value) {
				gaps.push('Set the credit due date in Payment.');
			}
		} else if (type === 'installment') {
			if (!installmentAdminEditMode && !paymentTermsConfirmed) {
				gaps.push('Open Payment and confirm the installment plan.');
			} else {
				if (installmentAdminEditMode && planGrandTotalInput) {
					const planTotal = Number(planGrandTotalInput.value);
					if (Number.isNaN(planTotal) || planTotal < 0.01) {
						gaps.push('Enter a valid plan invoice amount.');
					} else if (planTotal + 0.001 < editAmountPaid) {
						gaps.push(`Plan invoice amount cannot be less than amount already paid (${formatMoney(editAmountPaid)}).`);
					}
					const down = Number(installmentDownPaymentInput?.value || 0);
					if (!Number.isNaN(down) && down > planTotal + 0.001) {
						gaps.push('Advance cannot exceed the plan invoice amount.');
					}
				}
				const advanceGapVal = Number(installmentDownPaymentInput?.value ?? '');
				if (Number.isNaN(advanceGapVal) || advanceGapVal <= 0) {
					gaps.push('Advance must be greater than 0 — open Payment to set it.');
				}
				if (!installmentCountInput?.value || Number.parseInt(installmentCountInput.value, 10) < 1) {
					gaps.push('Installment count must be at least 1.');
				}
				if (!installmentFirstDueDateInput?.value) {
					gaps.push('Choose the first installment due date.');
				}
				if (installmentFrequencySelect?.value === 'custom'
					&& (!installmentCustomDaysInput?.value || Number.parseInt(installmentCustomDaysInput.value, 10) < 1)) {
					gaps.push('Set the custom installment interval (days).');
				}
				const guarantorSource = document.querySelector('input[name="guarantor_source"]:checked')?.value;
				if (!guarantorSource) {
					gaps.push('Select a guarantor option (Existing or New).');
				} else if (guarantorSource === 'existing') {
					const guarantorId = document.querySelector('input[name="guarantor_id"]')?.value?.trim();
					const addr = document.getElementById('drawer-guarantor-address-existing')?.value?.trim();
					const nid = document.getElementById('drawer-guarantor-nid-existing')?.value?.trim();
					if (!guarantorId) gaps.push('Select a customer as guarantor.');
					if (!addr) gaps.push('Guarantor address is required.');
					if (!nid) gaps.push('Guarantor NID number is required.');
				} else if (guarantorSource === 'new') {
					const name = document.getElementById('drawer-guarantor-name')?.value?.trim();
					const phone = document.getElementById('drawer-guarantor-phone')?.value?.trim();
					const addr = document.getElementById('drawer-guarantor-address')?.value?.trim();
					const nid = document.getElementById('drawer-guarantor-nid-new')?.value?.trim();
					if (!name) gaps.push('Guarantor name is required.');
					if (!phone) gaps.push('Guarantor phone is required.');
					if (!addr) gaps.push('Guarantor address is required.');
					if (!nid) gaps.push('Guarantor NID number is required.');
				}
			}
		}

		const rows = [...linesBody.querySelectorAll('[data-line-row]')];

		if (!rows.length) {
			gaps.push('Add at least one line with a product.');
			return gaps;
		}

		for (const row of rows) {
			const productSelect = row.querySelector('[data-product-select]');
			const lineNo = row.querySelector('[data-line-heading]')?.textContent?.trim() || '?';

			if (!productSelect?.value) {
				gaps.push(`Line ${lineNo}: choose a product.`);
				continue;
			}

			const opt = productSelect.options[productSelect.selectedIndex];
			const isSerialized = opt?.dataset?.serialized === '1';
			const qty = parseQtyInput(row.querySelector('.line-qty')?.value ?? '');

			if (Number.isNaN(qty)) {
				gaps.push(`Line ${lineNo}: enter a valid whole-number quantity.`);
			} else if (qty < 1) {
				gaps.push(`Line ${lineNo}: quantity must be at least 1.`);
			}

			if (isSerialized && !Number.isNaN(qty) && qty >= 1) {
				const hiddens = row.querySelectorAll('[data-serial-hidden]');
				let filled = 0;
				hiddens.forEach((h) => {
					if (String(h.value || '').trim() !== '') {
						filled += 1;
					}
				});
				if (filled !== qty) {
					const label = opt?.text || 'this product';
					gaps.push(`Line ${lineNo}: pick ${qty} serial number(s) for ${label}.`);
				}
			}

			const unitPriceInput = row.querySelector('.line-unit-price');
			const price = parseMoneyInput(unitPriceInput?.value ?? '');
			if (!unitPriceInput || scrubNumericString(unitPriceInput.value) === '' || Number.isNaN(price) || price < 0) {
				gaps.push(`Line ${lineNo}: enter a valid unit price (0 or more).`);
			}
		}

		return gaps;
	}

	function paintInvoiceReadinessPanel(gaps) {
		if (!invoiceReadinessMissing || !invoiceReadinessOk) {
			return;
		}
		invoiceReadinessMissing.innerHTML = '';

		if (gaps.length === 0) {
			invoiceReadinessOk.classList.remove('hidden');
			invoiceReadinessMissing.classList.add('hidden');
			if (invoiceReadinessPanel) {
				invoiceReadinessPanel.classList.remove('border-amber-200', 'bg-amber-50/50');
				invoiceReadinessPanel.classList.add('border-emerald-200', 'bg-emerald-50/40');
			}
			return;
		}

		invoiceReadinessOk.classList.add('hidden');
		invoiceReadinessMissing.classList.remove('hidden');

		if (invoiceReadinessPanel) {
			invoiceReadinessPanel.classList.add('border-amber-200', 'bg-amber-50/50');
			invoiceReadinessPanel.classList.remove('border-emerald-200', 'bg-emerald-50/40');
		}

		gaps.forEach((text) => {
			const li = document.createElement('li');
			li.textContent = text;
			invoiceReadinessMissing.appendChild(li);
		});
	}

	function renderInstallmentPreview(financedAmount) {
		const saleType = saleTypeSelect.value;
		const count = Math.max(Number(installmentCountInput.value || 0), 0);
		const firstDue = installmentFirstDueDateInput.value;

		if (saleType !== 'installment' || count < 1 || !firstDue || financedAmount <= 0) {
			installmentPlanPreviewWrap.classList.add('hidden');
			installmentPlanPreview.innerHTML = '';
			if (installmentPlanPreviewFoot) {
				installmentPlanPreviewFoot.classList.add('hidden');
				installmentPlanPreviewFoot.innerHTML = '';
			}
			return;
		}

		installmentPlanPreviewWrap.classList.remove('hidden');
		const baseAmount = Math.round((financedAmount / count) * 100) / 100;
		const scheduledTotal = baseAmount * count;
		const delta = Math.round((financedAmount - scheduledTotal) * 100) / 100;
		const startDate = new Date(firstDue + 'T00:00:00');

		const rows = [];
		for (let i = 1; i <= count; i += 1) {
			let amount = baseAmount;
			if (i === count) {
				amount = Math.round((amount + delta) * 100) / 100;
			}

			const dueDate = addFrequency(startDate, i - 1);
			const dueStr = dueDate.toISOString().slice(0, 10);
			rows.push(
				`<tr class="transition-colors hover:bg-slate-50/90"><td class="px-3 py-2 text-center text-xs font-semibold text-slate-500 sm:text-sm">${i}</td><td class="px-3 py-2 font-medium tabular-nums text-slate-800">${dueStr}</td><td class="px-3 py-2 text-right tabular-nums font-medium text-slate-900">${formatMoney(amount)}</td></tr>`
			);
		}

		installmentPlanPreview.innerHTML = rows.join('');

		if (installmentPlanPreviewFoot) {
			installmentPlanPreviewFoot.classList.remove('hidden');
			installmentPlanPreviewFoot.innerHTML = `<tr><td colspan="2" class="px-3 py-2.5 text-xs font-medium text-slate-600">Financed total</td><td class="px-3 py-2.5 text-right text-sm tabular-nums text-slate-900">${formatMoney(financedAmount)}</td></tr>`;
		}
	}

	function updateSummary() {
		refreshLineComputedCells();
		const lines = getCurrentLines();
		const grossTotal = lines.reduce((sum, line) => sum + line.gross, 0);
		const lineDiscTotal = lines.reduce((sum, line) => sum + line.discountAmt, 0);
		const serviceChargeTotal = lines.reduce((sum, line) => sum + line.serviceCharge, 0);
		const subtotal = lines.reduce((sum, line) => sum + line.total, 0);
		const totalQty = lines.reduce((sum, line) => sum + line.qty, 0);
		const lineCount = lines.length;
		const advance = saleTypeSelect.value === 'installment' ? Number(installmentDownPaymentInput?.value || 0) : 0;
		const invDisc = discountsBlockedForInstallment()
			? 0
			: (discountTotalInput ? Math.max(Number(discountTotalInput.value || 0), 0) : 0);
		let due;
		let installmentPreviewBase;
		if (installmentAdminEditMode && planGrandTotalInput) {
			const planTotal = Math.max(Number(planGrandTotalInput.value) || 0, 0);
			due = Math.max(planTotal - editAmountPaid, 0);
			installmentPreviewBase = Math.max(planTotal - advance, 0);
		} else if (isEditDraft) {
			const grand = Math.max(subtotal - invDisc + editTaxTotal, 0);
			due = Math.max(grand - editAmountPaid, 0);
			installmentPreviewBase = Math.max(subtotal - invDisc + editTaxTotal - advance, 0);
		} else {
			due = Math.max(subtotal - advance, 0);
			installmentPreviewBase = due;
		}

		summaryLineCount.textContent = String(lineCount);
		summaryTotalQty.textContent = String(totalQty);
		if (summaryGross) {
			summaryGross.textContent = formatMoney(grossTotal);
		}
		if (summaryLineDiscounts) {
			summaryLineDiscounts.textContent = formatMoney(lineDiscTotal);
		}
		if (summaryServiceCharges) {
			summaryServiceCharges.textContent = formatMoney(serviceChargeTotal);
		}
		summarySubtotal.textContent = formatMoney(subtotal);
		summaryAdvance.textContent = formatMoney(advance);
		summaryDue.textContent = formatMoney(due);

		renderInstallmentPreview(installmentPreviewBase);
		syncInstallmentMirror();
		refreshSubmitEnabled();
	}

	function bindLineEvents(row) {
		const productSelect = row.querySelector('[data-product-select]');
		const qtyInput = row.querySelector('.line-qty');
		const unitPriceInput = row.querySelector('.line-unit-price');
		const serialCell = row.querySelector('.line-serial-cell');
		const serializedTag = row.querySelector('.line-serialized-tag');
		const removeBtn = row.querySelector('.remove-line');
		const currentIndex = row.dataset.index;

		function setSerialSectionVisible(isSerialized) {
			const section = row.querySelector('[data-line-serial-section]');
			if (section) {
				section.classList.toggle('hidden', !isSerialized);
			}
		}

		function renderSerialInputs(preserveExisting = false) {
			const selected = productSelect.options[productSelect.selectedIndex];
			const hasProduct = Boolean(productSelect.value);
			const isSerialized = hasProduct && selected?.dataset.serialized === '1';
			const q = parseQtyInput(qtyInput.value);
			const qty = Number.isNaN(q) ? 0 : q;

			setSerialSectionVisible(isSerialized);

			if (!isSerialized) {
				serialCell.innerHTML = '';

				return;
			}

			if (qty < 1) {
				serialCell.innerHTML = '<span class="text-amber-700">Set qty</span>';

				return;
			}

			let preserved = [];
			if (preserveExisting) {
				preserved = [...serialCell.querySelectorAll('[data-serial-picker]')].map((picker) => {
					const h = picker.querySelector('[data-serial-hidden]');
					const inp = picker.querySelector('[data-serial-search-input]');

					return {
						id: String(h?.value ?? '').trim(),
						label: String(inp?.value ?? '').trim(),
					};
				}).slice(0, qty);
			}

			const inputs = [];
			for (let i = 0; i < qty; i += 1) {
				inputs.push(`
					<div class="relative z-10 mb-1.5 last:mb-0" data-serial-picker data-serial-slot="${currentIndex}-${i}">
						<input type="hidden" name="lines[${currentIndex}][serial_ids][]" value="" data-serial-hidden>
						<div class="relative">
							<input type="text" data-serial-search-input autocomplete="off" placeholder="Serial #${i + 1} — search or scan" class="w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm shadow-sm" role="combobox" aria-expanded="false" aria-autocomplete="list">
							<div data-serial-results class="absolute left-0 right-0 z-40 mt-0.5 hidden max-h-44 overflow-y-auto overscroll-contain rounded border border-slate-200 bg-white py-0.5 text-xs shadow-md"></div>
						</div>
					</div>
				`);
			}

			serialCell.innerHTML = `<div class="max-w-xl space-y-2">${inputs.join('')}</div>`;
			const pickers = [...serialCell.querySelectorAll('[data-serial-picker]')];
			pickers.forEach((picker, i) => {
				const ex = preserved[i];
				if (ex && ex.id) {
					const h = picker.querySelector('[data-serial-hidden]');
					const inp = picker.querySelector('[data-serial-search-input]');
					if (h) {
						h.value = ex.id;
					}
					if (inp && ex.label) {
						inp.value = ex.label;
					}
				}
				wireSerialPicker(picker, productSelect);
			});
			refreshSerialAvailability();
		}

		productSelect.addEventListener('change', () => {
			const selected = productSelect.options[productSelect.selectedIndex];
			const isSerialized = selected?.dataset.serialized === '1';
			const suggestedPrice = selected?.dataset.price || '';

			if (suggestedPrice !== '') {
				unitPriceInput.value = suggestedPrice;
			}

			serializedTag.textContent = isSerialized ? 'Yes' : 'No';
			serializedTag.className = `line-serialized-tag inline-flex rounded-full px-2.5 py-1 text-xs font-semibold ${isSerialized ? 'bg-blue-100 text-blue-900' : 'bg-slate-100 text-slate-700'}`;
			renderSerialInputs(false);
			updateSummary();
			refreshLineProductInventory(row);
		});

		qtyInput.addEventListener('input', () => renderSerialInputs(true));
		renderSerialInputs(false);
		refreshLineProductInventory(row);

		removeBtn.addEventListener('click', () => {
			row.remove();
			if (!linesBody.querySelector('[data-line-row]')) {
				addLine();
			}
			refreshSerialAvailability();
			refreshLineHeadings();
			updateSummary();
		});
	}

	function applyInitialSerialsToRow(row, serials) {
		if (!Array.isArray(serials) || serials.length === 0) {
			return;
		}
		const pickers = [...row.querySelectorAll('[data-serial-picker]')];
		serials.forEach((s, i) => {
			const p = pickers[i];
			if (!p || !s) {
				return;
			}
			const h = p.querySelector('[data-serial-hidden]');
			const inp = p.querySelector('[data-serial-search-input]');
			if (h && s.id != null) {
				h.value = String(s.id);
			}
			if (inp) {
				inp.value = String(s.label || s.serial_number || '');
			}
		});
		refreshSerialAvailability();
	}

	function addLine(initialLine = null) {
		const init = initialLine && typeof initialLine === 'object' ? initialLine : null;
		const initQty = init != null && init.qty != null ? String(init.qty) : '1';
		const initUnit =
			init != null && init.unit_price != null && init.unit_price !== '' ? String(init.unit_price) : '';
		const blankIfZero = (val) => {
			const n = Number.parseFloat(val);
			return (val == null || val === '' || Number.isNaN(n) || n === 0) ? '' : String(val);
		};
		const initDiscVal = discountsBlockedForInstallment()
			? ''
			: (init != null ? blankIfZero(init.discount_input) : '');
		const initDiscFixed = !discountsBlockedForInstallment() && init != null && init.discount_type === 'fixed';
		const initServiceChargeVal =
			init != null && init.service_charge_input != null && init.service_charge_input !== ''
				? blankIfZero(init.service_charge_input)
				: (init != null ? blankIfZero(init.service_charge) : '');
		const initServiceChargePercent = init != null && init.service_charge_type === 'percent';
		const row = document.createElement('div');
		row.className = 'invoice-line-card relative rounded-xl border border-slate-200 bg-white p-4 shadow-sm ring-1 ring-slate-900/[0.04] sm:p-5';
		row.dataset.index = String(lineIndex);
		row.setAttribute('data-line-row', '');
		row.style.zIndex = String(30 + lineIndex);
		row.innerHTML = `
			<div class="mb-4 flex items-center justify-between gap-3">
				<div class="flex items-center gap-2">
					<span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-slate-100 text-sm font-bold text-slate-800" data-line-heading>1</span>
					<span class="text-xs font-medium text-slate-500">Line</span>
				</div>
				<button type="button" class="remove-line rounded-lg border border-rose-200 bg-rose-50 px-3 py-1.5 text-xs font-semibold text-rose-800 hover:bg-rose-100">Remove</button>
			</div>
			<div class="mb-4">
				<label class="mb-1 block text-xs font-semibold uppercase tracking-wide text-slate-600">Product</label>
				<p class="mb-2 text-[11px] text-slate-500">Search by name or barcode.</p>
				<div class="relative z-20" data-product-picker>
					<input type="text" class="w-full min-h-[2.75rem] rounded-lg border border-slate-300 px-3 py-2.5 text-sm shadow-sm focus:border-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-500/25" data-product-search placeholder="Start typing to search…" autocomplete="off" role="combobox" aria-expanded="false">
					<div class="absolute left-0 right-0 z-50 mt-1 hidden max-h-60 overflow-y-auto overscroll-contain rounded-lg border border-slate-200 bg-white py-1 text-sm shadow-lg" data-product-results></div>
					<select class="sr-only" name="lines[${lineIndex}][product_id]" data-product-select tabindex="-1"></select>
				</div>
				<div class="mt-3 rounded-lg border border-slate-100 bg-slate-50/90 p-2.5 sm:p-3">
					<div class="flex flex-wrap items-center justify-between gap-x-3 gap-y-1">
						<p class="align-top text-xs font-semibold text-slate-800">Inventory</p>
						<p class="hidden text-[11px] font-medium text-slate-500" data-product-inventory-scope>Selected branch</p>
					</div>
					<p class="mt-1.5 text-[11px] leading-relaxed text-slate-500" data-product-inventory-empty>Choose a branch and product to view stock.</p>
					<div class="mt-1.5 hidden flex flex-wrap gap-1.5" data-product-inventory></div>
				</div>
			</div>
			<div class="grid grid-cols-2 gap-x-4 gap-y-3 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 2xl:grid-cols-9">
				<div class="min-w-0">
					<label class="mb-1 block text-[11px] font-medium text-slate-600">Quantity</label>
					<input type="text" name="lines[${lineIndex}][qty]" value="${initQty}" inputmode="numeric" autocomplete="off" class="line-qty w-full rounded-lg border border-slate-300 px-3 py-2 text-sm tabular-nums shadow-sm" required>
				</div>
				<div class="min-w-0">
					<label class="mb-1 block text-[11px] font-medium text-slate-600">Unit price</label>
					<input type="text" name="lines[${lineIndex}][unit_price]" value="${initUnit}" inputmode="decimal" autocomplete="off" placeholder="0.00" readonly class="line-unit-price w-full cursor-default rounded-lg border border-slate-200 bg-slate-50 px-3 py-2 text-sm tabular-nums text-slate-800 shadow-sm" required title="Taken from the product sale price">
				</div>
				${invoiceLineDiscountUi ? `
				<div class="relative min-w-0">
					<div class="installment-discount-lock absolute inset-0 z-10 hidden cursor-not-allowed rounded-lg" tabindex="0" role="note" aria-label="${INSTALLMENT_DISCOUNT_MSG}"><span class="installment-discount-tip pointer-events-none absolute bottom-full left-0 z-50 mb-1 hidden w-60 rounded-md bg-slate-900 px-2.5 py-1.5 text-[11px] font-normal leading-snug text-white shadow-lg">${INSTALLMENT_DISCOUNT_MSG}</span></div>
					<label class="mb-1 block text-[11px] font-medium text-slate-600">Discount type</label>
					<select name="lines[${lineIndex}][discount_type]" class="line-discount-type w-full rounded-lg border border-slate-300 px-2 py-2 text-sm shadow-sm" title="Percent of line or fixed amount">
						<option value="percent" ${initDiscFixed ? '' : 'selected'}>Percent off</option>
						<option value="fixed" ${initDiscFixed ? 'selected' : ''}>Fixed amount off</option>
					</select>
				</div>
				<div class="relative min-w-0">
					<div class="installment-discount-lock absolute inset-0 z-10 hidden cursor-not-allowed rounded-lg" tabindex="0" role="note" aria-label="${INSTALLMENT_DISCOUNT_MSG}"><span class="installment-discount-tip pointer-events-none absolute bottom-full left-0 z-50 mb-1 hidden w-60 rounded-md bg-slate-900 px-2.5 py-1.5 text-[11px] font-normal leading-snug text-white shadow-lg">${INSTALLMENT_DISCOUNT_MSG}</span></div>
					<label class="mb-1 block text-[11px] font-medium text-slate-600">Discount value</label>
					<div class="relative">
						<input type="text" name="lines[${lineIndex}][discount_input]" value="${initDiscVal}" inputmode="decimal" autocomplete="off" class="line-discount-input w-full rounded-lg border border-slate-300 px-3 py-2 text-sm tabular-nums shadow-sm">
						<span class="line-discount-suffix pointer-events-none absolute inset-y-0 right-3 hidden items-center text-sm font-medium text-slate-400">%</span>
					</div>
				</div>
				` : ''}
				<div class="min-w-0">
					<label class="mb-1 block text-[11px] font-medium text-slate-600">Service charge type</label>
					<select name="lines[${lineIndex}][service_charge_type]" class="line-service-charge-type w-full rounded-lg border border-slate-300 px-2 py-2 text-sm shadow-sm" title="Percent of line or fixed amount, added to the line total">
						<option value="fixed" ${initServiceChargePercent ? '' : 'selected'}>Fixed amount</option>
						<option value="percent" ${initServiceChargePercent ? 'selected' : ''}>Percent of line</option>
					</select>
				</div>
				<div class="min-w-0">
					<label class="mb-1 block text-[11px] font-medium text-slate-600">Service charge</label>
					<div class="relative">
						<input type="text" name="lines[${lineIndex}][service_charge_input]" value="${initServiceChargeVal}" inputmode="decimal" autocomplete="off" placeholder="0.00" class="line-service-charge-input w-full rounded-lg border border-slate-300 px-3 py-2 text-sm tabular-nums shadow-sm" title="Extra service charge for this product line, added to the line total">
						<span class="line-service-charge-suffix pointer-events-none absolute inset-y-0 right-3 hidden items-center text-sm font-medium text-slate-400">%</span>
					</div>
				</div>
				<div class="min-w-0">
					<label class="mb-1 block text-[11px] font-medium text-slate-600">Line discount</label>
					<p class="line-discount-amt-display rounded-lg border border-slate-100 bg-slate-50 px-3 py-2 text-right text-sm font-medium tabular-nums text-slate-800">0.00</p>
					<p class="line-discount-pct-display mt-0.5 text-right text-[10px] text-slate-500 tabular-nums"></p>
				</div>
				<div class="min-w-0">
					<label class="mb-1 block text-[11px] font-medium text-slate-600">Line total</label>
					<p class="line-line-total-display rounded-lg border border-slate-200 bg-slate-900 px-3 py-2 text-right text-sm font-semibold tabular-nums text-white">0.00</p>
				</div>
				${invoiceLineProfitUi ? `
				<div class="min-w-0">
					<label class="mb-1 block text-[11px] font-medium text-slate-600">Profit</label>
					<div class="line-profit-panel rounded-lg border border-slate-100 bg-slate-50 px-3 py-2 text-right">
						<p class="line-profit-amount text-sm font-semibold tabular-nums text-slate-500">—</p>
						<p class="line-profit-rates mt-0.5 text-[10px] tabular-nums text-slate-500">—</p>
						<p class="line-profit-hint mt-0.5 text-[10px] text-slate-400 tabular-nums">Markup on cost · margin on sale</p>
					</div>
				</div>
				` : `
				<div class="min-w-0">
					<label class="mb-1 block text-[11px] font-medium text-slate-600">Discount limit</label>
					<p class="line-discount-limit-display rounded-lg border border-slate-100 bg-slate-50 px-3 py-2 text-right text-sm font-semibold tabular-nums text-slate-700">—</p>
					<p class="line-discount-limit-hint mt-0.5 text-right text-[10px] text-slate-500 tabular-nums">max you can discount</p>
				</div>
				`}
			</div>
			${invoiceLineDiscountUi ? `<p class="installment-discount-note mt-3 hidden rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-[11px] text-amber-900">${INSTALLMENT_DISCOUNT_MSG}</p>` : ''}
			<div class="mt-4 hidden border-t border-slate-100 pt-4" data-line-serial-section>
				<div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
					<div class="min-w-0 flex-1">
						<label class="mb-1 block text-[11px] font-medium text-slate-600">Serial numbers <span class="text-rose-500" data-serial-required-asterisk>*</span></label>
						<p class="mb-2 text-[11px] text-slate-500 sm:hidden">Pick one serial per unit, or use the scanner at the top.</p>
						<div class="line-serial-cell text-sm"></div>
					</div>
					<div class="shrink-0 sm:pt-6">
						<span class="text-[11px] font-medium text-slate-500">Serialized</span>
						<p class="mt-1"><span class="line-serialized-tag inline-flex rounded-full bg-slate-100 px-2.5 py-1 text-xs font-semibold text-slate-700">No</span></p>
					</div>
				</div>
			</div>
		`;

		linesBody.appendChild(row);
		const productSelect = row.querySelector('[data-product-select]');
		fillProductSelect(productSelect, init?.product_id ?? null);
		wireProductSearch(row);
		bindLineEvents(row);
		if (init?.serials?.length) {
			applyInitialSerialsToRow(row, init.serials);
		}
		lineIndex += 1;
		refreshLineHeadings();
		clearAndDisableDiscountControls();
		updateSummary();
	}

	function hydrateInitialDraftLines() {
		const list = Array.isArray(initialDraftLines) ? initialDraftLines : [];
		if (!list.length) {
			addLine();
			return;
		}
		list.forEach((line) => addLine(line));
	}

	function openCustomerDrawer() {
		customerDrawer.classList.remove('hidden');
	}

	function closeCustomerDrawer() {
		customerDrawer.classList.add('hidden');
		quickCustomerError.classList.add('hidden');
		quickCustomerError.textContent = '';
	}

	function renderQuickCustomerPhoneRows(values = ['']) {
		if (!quickCustomerPhonesWrap) {
			return;
		}
		const rows = Array.isArray(values) && values.length ? values : [''];
		quickCustomerPhonesWrap.innerHTML = rows.map((value) => `
			<div class="flex items-center gap-2">
				<input type="text" name="phone_numbers[]" class="w-full rounded-lg border border-slate-300 px-3 py-2" placeholder="Additional phone" value="${escapeHtml(value)}">
				<button type="button" class="rounded border border-rose-200 bg-rose-50 px-2 py-2 text-xs font-medium text-rose-700 hover:bg-rose-100" data-remove-phone>-</button>
			</div>
		`).join('');
	}

	function customerSearchHaystackFromParts(parts) {
		return parts
			.map((p) => String(p || '').trim().toLowerCase())
			.filter((s) => s !== '')
			.join(' ');
	}

	// ── Customer combobox ──────────────────────────────────────────────────────
	let customerList = customers.map((c) => ({
		id: c.id,
		name: c.name,
		phones: Array.isArray(c.phones) && c.phones.length ? c.phones : (c.phone ? [c.phone] : []),
		search: customerSearchHaystackFromParts([c.name, c.customer_code, ...(Array.isArray(c.phones) && c.phones.length ? c.phones : (c.phone ? [c.phone] : []))]),
		default_guarantor_id: c.default_guarantor_id,
	}));
	let customerHighlightIndex = -1;
	let customerDropdownItems = [];

	function refreshClearCustomerButton() {
		if (!clearCustomerButton) return;
		if (customerSelect?.value) {
			clearCustomerButton.classList.remove('hidden');
			clearCustomerButton.classList.add('inline-flex');
		} else {
			clearCustomerButton.classList.add('hidden');
			clearCustomerButton.classList.remove('inline-flex');
		}
	}

	function setSelectedCustomer(id, label) {
		customerSelect.value = String(id ?? '');
		if (customerSearchInput) {
			customerSearchInput.value = label ?? '';
		}
		closeCustomerDropdown();
		refreshClearCustomerButton();
		refreshSubmitEnabled();
		applyDefaultGuarantorFromCustomer();
	}

	function closeCustomerDropdown() {
		if (customerDropdown) {
			customerDropdown.classList.add('hidden');
		}
		if (customerSearchInput) {
			customerSearchInput.setAttribute('aria-expanded', 'false');
		}
		customerHighlightIndex = -1;
	}

	function renderCustomerDropdown(query) {
		if (!customerDropdown) return;
		const q = query.trim().toLowerCase();
		customerDropdownItems = q === ''
			? customerList.slice(0, 80)
			: customerList.filter((c) => c.search.includes(q)).slice(0, 80);
		if (!customerDropdownItems.length) {
			customerDropdown.innerHTML = '<div class="px-3 py-2 text-xs text-slate-500">No customers found.</div>';
		} else {
			customerDropdown.innerHTML = customerDropdownItems.map((c, i) =>
				`<button type="button" class="w-full px-3 py-2 text-left hover:bg-slate-100 ${i === 0 ? 'bg-slate-50' : ''}" data-cust-index="${i}">`
				+ `<span class="font-medium text-slate-900">${escapeHtml(c.name)}</span>`
				+ (c.phones.length ? `<span class="text-slate-800"> -(</span><span class="text-md font-normal text-slate-800">${escapeHtml(c.phones[0])})</span>` : '')
				+ `</button>`
			).join('');
		}
		customerDropdown.classList.remove('hidden');
		customerSearchInput?.setAttribute('aria-expanded', 'true');
		customerHighlightIndex = customerDropdownItems.length ? 0 : -1;
	}

	function addCustomerOption(customer) {
		const phones = Array.isArray(customer.phones) && customer.phones.length ? customer.phones : (customer.phone ? [customer.phone] : []);
		const entry = {
			id: customer.id,
			name: customer.name,
			phones,
			search: customerSearchHaystackFromParts([customer.name, customer.customer_code, ...phones]),
			default_guarantor_id: customer.default_guarantor_id,
		};
		customerList.unshift(entry);
		const label = phones.length ? `${customer.name} (${phones.join(', ')})` : customer.name;
		setSelectedCustomer(customer.id, label);
	}

	function filterCustomersBySearch(query) {
		// kept for compatibility — now just re-renders dropdown
		renderCustomerDropdown(query);
	}

	clearCustomerButton?.addEventListener('click', () => {
		customerSelect.value = '';
		if (customerSearchInput) customerSearchInput.value = '';
		refreshClearCustomerButton();
		refreshSubmitEnabled();
		customerSearchInput?.focus();
	});
	openCustomerDrawerButton?.addEventListener('click', openCustomerDrawer);
	closeCustomerDrawerButton.addEventListener('click', closeCustomerDrawer);
	customerDrawerBackdrop.addEventListener('click', closeCustomerDrawer);

	// Combobox: open on focus/click
	customerSearchInput?.addEventListener('focus', () => {
		renderCustomerDropdown(customerSearchInput.value);
	});
	customerSearchInput?.addEventListener('click', () => {
		renderCustomerDropdown(customerSearchInput.value);
	});
	customerSearchInput?.addEventListener('input', (event) => {
		// clear selection when user types
		customerSelect.value = '';
		refreshClearCustomerButton();
		renderCustomerDropdown(event.target.value);
		refreshSubmitEnabled();
	});
	customerSearchInput?.addEventListener('keydown', (event) => {
		const items = customerDropdown ? [...customerDropdown.querySelectorAll('[data-cust-index]')] : [];
		if (event.key === 'ArrowDown') {
			event.preventDefault();
			customerHighlightIndex = Math.min(customerHighlightIndex + 1, items.length - 1);
			items.forEach((el, i) => el.classList.toggle('bg-slate-100', i === customerHighlightIndex));
		} else if (event.key === 'ArrowUp') {
			event.preventDefault();
			customerHighlightIndex = Math.max(customerHighlightIndex - 1, 0);
			items.forEach((el, i) => el.classList.toggle('bg-slate-100', i === customerHighlightIndex));
		} else if (event.key === 'Enter') {
			event.preventDefault();
			const idx = customerHighlightIndex >= 0 ? customerHighlightIndex : 0;
			const c = customerDropdownItems[idx];
			if (c) {
				const label = c.phones.length ? `${c.name} (${c.phones.join(', ')})` : c.name;
				setSelectedCustomer(c.id, label);
			}
		} else if (event.key === 'Escape') {
			closeCustomerDropdown();
		}
	});
	customerSearchInput?.addEventListener('blur', () => {
		// slight delay so click on dropdown item fires first
		setTimeout(() => {
			closeCustomerDropdown();
			// if nothing selected, clear the text
			if (!customerSelect.value) {
				customerSearchInput.value = '';
			}
		}, 150);
	});
	customerDropdown?.addEventListener('mousedown', (e) => e.preventDefault());
	customerDropdown?.addEventListener('click', (event) => {
		const btn = event.target.closest('[data-cust-index]');
		if (!btn) return;
		const c = customerDropdownItems[parseInt(btn.dataset.custIndex, 10)];
		if (c) {
			const label = c.phones.length ? `${c.name} (${c.phones.join(', ')})` : c.name;
			setSelectedCustomer(c.id, label);
		}
	});
	// close dropdown when clicking outside
	document.addEventListener('click', (e) => {
		if (!document.getElementById('customer-combobox-wrap')?.contains(e.target)) {
			closeCustomerDropdown();
		}
	});
	saleTypeSelect.addEventListener('change', () => onSaleTypeChanged(true));

	paymentDrawerConfirm.addEventListener('click', () => {
		if (!validatePaymentDrawer()) {
			return;
		}
		paymentTermsConfirmed = true;
		syncInstallmentMirror();
		closePaymentDrawer();
		clearAndDisableDiscountControls();
		refreshPaymentTypeStatus();
		refreshSubmitEnabled();
		updateSummary();
	});

	paymentDrawerUseDefault.addEventListener('click', () => {
		saleTypeSelect.value = 'default';
		paymentTermsConfirmed = true;
		closePaymentDrawer();
		onSaleTypeChanged(false);
	});

	openPaymentDrawerEditBtn?.addEventListener('click', () => {
		paymentTermsConfirmed = false;
		refreshSubmitEnabled();
		refreshPaymentTypeStatus();
		openPaymentDrawer();
	});

	document.getElementById('invoice-form').addEventListener('submit', (event) => {
		clearInvoiceClientError();
		syncInstallmentMirror();
		if (discountsBlockedForInstallment()) {
			clearAndDisableDiscountControls();
		}

		const type = saleTypeSelect.value;
		if (!postedEditMode && type !== 'default' && !paymentTermsConfirmed) {
			event.preventDefault();
			openPaymentDrawer();
			refreshSubmitEnabled();
			return;
		}
		if (!postedEditMode && type !== 'default') {
			syncDrawerBranchInputs();
		}

		const lineCheck = validateInvoiceLinesBeforeSubmit();
		if (!lineCheck.ok) {
			event.preventDefault();
			showInvoiceClientError(lineCheck.message);
			if (lineCheck.focus && typeof lineCheck.focus.focus === 'function') {
				lineCheck.focus.scrollIntoView({ behavior: 'smooth', block: 'center' });
				lineCheck.focus.focus({ preventScroll: true });
			}
		}
	});
	installmentFrequencySelect.addEventListener('change', () => {
		toggleCustomFrequencyField(true);
		updateSummary();
	});
	installmentCustomDaysInput.addEventListener('input', () => {
		syncFirstDueDateMin(true);
		updateSummary();
	});
	installmentCountInput.addEventListener('input', updateSummary);
	installmentFirstDueDateInput.addEventListener('change', updateSummary);
	installmentDownPaymentInput.addEventListener('input', updateSummary);
	document.getElementById('drawer-installment-penalty-enabled')?.addEventListener('change', syncInstallmentMirror);
	document.getElementById('drawer-installment-penalty-rate')?.addEventListener('input', syncInstallmentMirror);
	planGrandTotalInput?.addEventListener('input', updateSummary);
	planGrandTotalInput?.addEventListener('change', updateSummary);
	document.querySelectorAll('input[name="guarantor_source"]').forEach((radio) => {
		radio.addEventListener('change', syncGuarantorInputs);
	});

	quickCustomerForm.addEventListener('submit', async (event) => {
		event.preventDefault();
		quickCustomerError.classList.add('hidden');
		quickCustomerError.textContent = '';

		const formData = new FormData(quickCustomerForm);
		const payload = {
			name: formData.get('name'),
			phone: formData.get('phone'),
			phone_numbers: formData.getAll('phone_numbers[]').map((value) => String(value || '').trim()).filter((value) => value !== ''),
			email: formData.get('email'),
			address: formData.get('address'),
		};

		try {
			const response = await fetch(`{{ route('sales.invoices.customers.quick-create') }}`, {
				method: 'POST',
				headers: {
					'Content-Type': 'application/json',
					'Accept': 'application/json',
					'X-CSRF-TOKEN': csrfToken,
				},
				body: JSON.stringify(payload),
			});

			if (!response.ok) {
				const errorPayload = await response.json();
				const firstError = Object.values(errorPayload.errors || {}).flat()[0] || 'Unable to create customer.';
				throw new Error(firstError);
			}

			const result = await response.json();
			addCustomerOption(result.data);
			quickCustomerForm.reset();
			renderQuickCustomerPhoneRows(['']);
			closeCustomerDrawer();
		} catch (error) {
			quickCustomerError.classList.remove('hidden');
			quickCustomerError.textContent = error.message || 'Unable to create customer.';
		}
	});

	addLineButton.addEventListener('click', addLine);
	quickCustomerAddPhoneBtn?.addEventListener('click', () => {
		const inputs = quickCustomerPhonesWrap ? Array.from(quickCustomerPhonesWrap.querySelectorAll('input[name="phone_numbers[]"]')) : [];
		const values = inputs.map((input) => input.value);
		values.push('');
		renderQuickCustomerPhoneRows(values);
	});
	quickCustomerPhonesWrap?.addEventListener('click', (event) => {
		const removeButton = event.target.closest('[data-remove-phone]');
		if (!removeButton) {
			return;
		}
		const inputs = Array.from(quickCustomerPhonesWrap.querySelectorAll('input[name="phone_numbers[]"]'));
		const values = inputs.map((input) => input.value);
		const row = removeButton.closest('div.flex.items-center.gap-2');
		const rowIndex = Array.from(quickCustomerPhonesWrap.children).indexOf(row);
		if (rowIndex >= 0) {
			values.splice(rowIndex, 1);
		}
		renderQuickCustomerPhoneRows(values.length ? values : ['']);
	});
	if (branchSelect && branchSelect.tagName === 'SELECT') {
		branchSelect.addEventListener('change', rebuildProductPickersForBranch);
	}
	discountTotalInput?.addEventListener('input', () => updateSummary());
	discountTotalInput?.addEventListener('change', () => updateSummary());
	document.querySelector('input[name="invoice_date"]')?.addEventListener('change', refreshSubmitEnabled);
	// hidden input change (programmatic)
	customerSelect?.addEventListener('change', () => {
		refreshSubmitEnabled();
		applyDefaultGuarantorFromCustomer();
	});
	document.querySelector('input[name="referrer_id"]')?.addEventListener('change', refreshSubmitEnabled);
	window.addEventListener('searchable-select-changed', (event) => {
		if (event.detail?.name === 'referrer_id') {
			refreshSubmitEnabled();
		}
	});

	function applyDefaultGuarantorFromCustomer() {
		if (!customerSelect) {
			return;
		}

		if (saleTypeSelect.value !== 'installment') {
			return;
		}

		const customerId = customerSelect.value;
		if (!customerId) {
			return;
		}

		const customer = customerList.find((entry) => String(entry.id) === String(customerId));
		if (!customer?.default_guarantor_id) {
			return;
		}

		const existingRadio = document.querySelector('input[name="guarantor_source"][value="existing"]');
		if (existingRadio) {
			existingRadio.checked = true;
			existingRadio.dispatchEvent(new Event('change', { bubbles: true }));
		}

		const guarantorInput = document.querySelector('input[name="guarantor_id"]');
		if (guarantorInput) {
			guarantorInput.value = customer.default_guarantor_id;
			guarantorInput.dispatchEvent(new Event('change', { bubbles: true }));
		}
	}
	drawerCreditDueDate?.addEventListener('change', refreshSubmitEnabled);
	document.querySelector('input[name="credit_due_date"]:not(#drawer-credit-due-date)')?.addEventListener('change', refreshSubmitEnabled);
	document.getElementById('installment-date-unlock')?.addEventListener('change', function () {
		if (this.checked) {
			installmentFirstDueDateInput.removeAttribute('min');
			const hintEl = document.getElementById('installment-first-due-hint');
			const descEl = document.getElementById('installment-first-due-desc');
			if (hintEl) hintEl.textContent = 'Restriction removed';
			if (descEl) descEl.textContent = 'Date restriction removed — any date allowed.';
		} else {
			syncFirstDueDateMin();
		}
	});
	function lineFieldTriggersTotals(target) {
		return Boolean(target?.matches?.('.line-qty, .line-unit-price, .line-discount-input, .line-discount-type, .line-service-charge-input, .line-service-charge-type'));
	}

	linesBody.addEventListener('input', (event) => {
		const t = event.target;
		if (t.matches('[data-product-search], .line-qty, .line-unit-price, .line-discount-input, .line-service-charge-input')) {
			clearInvoiceClientError();
		}
		if (lineFieldTriggersTotals(t)) {
			updateSummary();
		}
	});
	linesBody.addEventListener('change', (event) => {
		const t = event.target;
		if (t.matches('[data-product-select]')) {
			clearInvoiceClientError();
		}
		if (lineFieldTriggersTotals(t)) {
			updateSummary();
		}
	});

	invoiceBarcodeScanInput?.addEventListener('keydown', (event) => {
		if (event.key !== 'Enter' || event.repeat) {
			return;
		}
		event.preventDefault();
		event.stopPropagation();
		const captured = invoiceBarcodeScanInput.value;
		invoiceBarcodeScanInput.value = '';
		resetInvoiceBarcodeWedgeBuffer();
		void handleInvoiceBarcodeScan(captured);
	});

	document.addEventListener(
		'keydown',
		(event) => {
			if (event.key !== 'F2' || event.repeat) {
				return;
			}
			if (!invoiceBarcodeScanInput || invoiceBarcodeDrawersBlocking()) {
				return;
			}
			event.preventDefault();
			invoiceBarcodeScanInput.focus({ preventScroll: true });
		},
		false,
	);

	document.addEventListener(
		'keydown',
		(event) => {
			if (!branchSelect?.value) {
				return;
			}
			if (invoiceBarcodeDrawersBlocking()) {
				resetInvoiceBarcodeWedgeBuffer();
				return;
			}
			if (event.defaultPrevented) {
				return;
			}
			const active = document.activeElement;
			if (
				active?.matches?.('[data-product-search]')
				|| active?.matches?.('[data-scan-gateway]')
				|| active?.matches?.('[data-serial-search-input]')
			) {
				return;
			}
			const customerQuick = document.getElementById('customer-search');
			if (customerQuick && active === customerQuick) {
				return;
			}
			const tag = active?.tagName?.toUpperCase?.() ?? '';
			const inpType = String(active?.type || '').toLowerCase();
			const guardedTyping =
				tag === 'TEXTAREA'
				|| tag === 'SELECT'
				|| (tag === 'INPUT'
					&& [
						'text',
						'search',
						'tel',
						'email',
						'password',
						'number',
						'url',
						'date',
						'datetime-local',
						'month',
						'week',
						'time',
					].includes(inpType));
			if (guardedTyping) {
				resetInvoiceBarcodeWedgeBuffer();
				return;
			}
			if (event.key === 'Escape') {
				resetInvoiceBarcodeWedgeBuffer();
				return;
			}
			if (event.ctrlKey || event.altKey || event.metaKey || event.repeat) {
				resetInvoiceBarcodeWedgeBuffer();
				return;
			}
			const nowTs =
				typeof performance !== 'undefined' && typeof performance.now === 'function'
					? performance.now()
					: Date.now();
			const maxGapMs = 85;
			if (event.key === 'Enter') {
				const scanned = normalizeInvoiceBarcodeToken(invoiceBarcodeWedgeBuffer);
				resetInvoiceBarcodeWedgeBuffer();
				if (scanned.length < 3) {
					return;
				}
				event.preventDefault();
				event.stopPropagation();
				void handleInvoiceBarcodeScan(scanned);
				return;
			}
			const ch = event.key;
			if (typeof ch !== 'string' || ch.length !== 1 || !/^[\u0020-\u007E]$/u.test(ch)) {
				return;
			}
			event.preventDefault();
			event.stopPropagation();
			const gap = invoiceBarcodeWedgeBuffer.length ? nowTs - invoiceBarcodeWedgeLastTs : 0;
			if (invoiceBarcodeWedgeBuffer.length > 0 && gap > maxGapMs) {
				invoiceBarcodeWedgeBuffer = ch;
			} else {
				invoiceBarcodeWedgeBuffer += ch;
			}
			invoiceBarcodeWedgeLastTs = nowTs;
			if (invoiceBarcodeWedgeResetId !== null) {
				window.clearTimeout(invoiceBarcodeWedgeResetId);
			}
			invoiceBarcodeWedgeResetId = window.setTimeout(() => resetInvoiceBarcodeWedgeBuffer(), 340);
		},
		true,
	);

	window.addEventListener('barcode-scanned', (event) => {
		void handleInvoiceBarcodeScan(String(event?.detail?.code ?? ''));
	});

	hydrateInitialDraftLines();
	renderQuickCustomerPhoneRows(['']);

	// Pre-fill customer search input on edit
	(function initCustomerCombobox() {
		const preselectedId = customerSelect?.value;
		if (!preselectedId) return;
		const c = customerList.find((entry) => String(entry.id) === String(preselectedId));
		if (c && customerSearchInput) {
			customerSearchInput.value = c.phones.length ? `${c.name} (${c.phones.join(', ')})` : c.name;
		}
		refreshClearCustomerButton();
	})();

	(function initPaymentState() {
		if (postedEditMode) {
			if (installmentAdminEditMode) {
				paymentTermsConfirmed = true;
				syncInstallmentMirror();
			}
			clearAndDisableDiscountControls();
			toggleCustomFrequencyField();
			refreshPaymentTypeStatus();
			refreshSubmitEnabled();
			updateSummary();
			return;
		}
		const type = saleTypeSelect.value;
		syncDrawerBranchInputs();
		if (type === 'default') {
			paymentTermsConfirmed = true;
		} else if (validatePaymentDrawer()) {
			paymentTermsConfirmed = true;
			closePaymentDrawer();
		} else {
			paymentTermsConfirmed = false;
			openPaymentDrawer();
		}
		clearAndDisableDiscountControls();
		toggleCustomFrequencyField();
		refreshPaymentTypeStatus();
		refreshSubmitEnabled();
		updateSummary();
	})();
})();
</script>
