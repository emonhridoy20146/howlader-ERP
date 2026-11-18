@php
	$saleTypeInitial = old('sale_type', $invoice->sale_type);
@endphp

@if ($canChangeSaleType ?? false)
	<div
		x-show="saleTypeOpen"
		x-cloak
		class="fixed inset-0 z-[100] flex justify-end"
		role="dialog"
		aria-modal="true"
		aria-labelledby="sale-type-sheet-title"
	>
		<div class="absolute inset-0 bg-slate-900/40" @click="saleTypeOpen = false" aria-hidden="true"></div>
		<div
			class="relative flex h-full w-full max-w-md flex-col bg-white shadow-2xl ring-1 ring-slate-900/10"
			@click.stop
			x-data="{
				selectedType: @js($saleTypeInitial === 'installment' ? 'default' : $saleTypeInitial),
			}"
		>
			<div class="flex items-start justify-between gap-3 border-b border-slate-200 px-4 py-4 sm:px-5">
				<div>
					<h2 id="sale-type-sheet-title" class="text-base font-semibold text-slate-900">Change payment type</h2>
					<p class="mt-1 text-xs text-slate-500">
						Invoice {{ $invoice->invoice_number ?? '#'.$invoice->id }} · currently
						<span class="font-medium">{{ ucfirst($invoice->sale_type) }}</span>.
						Switch between cash and credit only. Installment invoices must be created at sale time.
					</p>
				</div>
				<button type="button" class="rounded-lg p-2 text-slate-500 hover:bg-slate-100 hover:text-slate-800" @click="saleTypeOpen = false" aria-label="Close">
					<svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12" /></svg>
				</button>
			</div>
			<div class="min-h-0 flex-1 overflow-y-auto px-4 py-4 sm:px-5">
				@if ($errors->has('sale_type'))
					<div class="mb-4 rounded-lg border border-rose-200 bg-rose-50 px-3 py-2 text-sm text-rose-800">{{ $errors->first('sale_type') }}</div>
				@endif
				<form id="invoice-sale-type-form" method="post" action="{{ route('sales.invoices.sale-type', $invoice) }}" class="space-y-4" data-confirm="Change the payment type for this posted invoice?">
					@csrf
					<input type="hidden" name="_form" value="change_sale_type">

					<fieldset class="space-y-2">
						<legend class="text-xs font-semibold uppercase tracking-wide text-slate-600">Payment type</legend>
						<label class="flex cursor-pointer items-center gap-3 rounded-lg border border-slate-200 p-3 has-[:checked]:border-slate-900 has-[:checked]:ring-1 has-[:checked]:ring-slate-900">
							<input type="radio" name="sale_type" value="default" x-model="selectedType" class="text-slate-900 focus:ring-slate-500" @checked($saleTypeInitial === 'default')>
							<span>
								<span class="block text-sm font-medium text-slate-900">Cash / Default</span>
								<span class="block text-xs text-slate-500">Full payment expected at collection</span>
							</span>
						</label>
						<label class="flex cursor-pointer items-center gap-3 rounded-lg border border-slate-200 p-3 has-[:checked]:border-slate-900 has-[:checked]:ring-1 has-[:checked]:ring-slate-900">
							<input type="radio" name="sale_type" value="credit" x-model="selectedType" class="text-slate-900 focus:ring-slate-500" @checked($saleTypeInitial === 'credit')>
							<span>
								<span class="block text-sm font-medium text-slate-900">Credit</span>
								<span class="block text-xs text-slate-500">Pay later by due date</span>
							</span>
						</label>
					</fieldset>

					@php
						$changeTypeMaxDate = $invoice->credit_due_date ?? now()->addDays(30);
					@endphp
					<div x-show="selectedType === 'credit'" x-cloak class="space-y-2 rounded-lg border border-slate-200 bg-slate-50 p-3">
						<label for="sale-type-credit-due-date" class="text-xs font-medium text-slate-700">Credit due date <span class="text-rose-600">*</span></label>
						<input
							id="sale-type-credit-due-date"
							type="date"
							name="credit_due_date"
							value="{{ old('credit_due_date', $invoice->credit_due_date?->toDateString()) }}"
							min="{{ $invoice->invoice_date?->toDateString() }}"
							max="{{ $changeTypeMaxDate->toDateString() }}"
							class="w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm"
							:required="selectedType === 'credit'"
							:disabled="selectedType !== 'credit'"
						>
						<p class="text-xs text-amber-700">Max: {{ $changeTypeMaxDate->format('d M Y') }} {{ $invoice->credit_due_date ? '(original due date)' : '(30 days from today)' }}</p>
						@error('credit_due_date')
							<p class="text-xs text-rose-700">{{ $message }}</p>
						@enderror
					</div>
				</form>
			</div>
			<div class="shrink-0 border-t border-slate-200 bg-slate-50 px-4 py-3 sm:px-5 pb-[max(0.75rem,env(safe-area-inset-bottom,0px))]">
				<button type="submit" form="invoice-sale-type-form" class="w-full rounded-lg bg-indigo-600 px-4 py-2.5 text-sm font-medium text-white hover:bg-indigo-700">Save payment type</button>
			</div>
		</div>
	</div>
@endif
