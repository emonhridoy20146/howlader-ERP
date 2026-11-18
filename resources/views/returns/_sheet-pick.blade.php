@props(['idSuffix' => 'form'])
{{-- Shared customer + invoice pickers (Alpine: pick.* on returnOrderSheet root) --}}
<div class="space-y-4">
	<div class="relative space-y-1.5">
		<label for="return-pick-customer-{{ $idSuffix }}" class="text-xs font-medium text-slate-600">Customer <span class="font-normal text-slate-400">(search name, code, phone)</span></label>
		<input
			id="return-pick-customer-{{ $idSuffix }}"
			type="text"
			class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm"
			placeholder="Type to search…"
			x-model="pick.customerSearch"
			@input="scheduleCustomerSearch()"
			@focus="onCustomerPickFocus()"
			autocomplete="off"
		>
		<input type="hidden" name="customer_id" x-model="pick.customer_id" required>
		<ul
			x-show="pick.customerOpen && pick.customerResults.length"
			x-cloak
			class="absolute z-10 mt-1 max-h-48 w-full overflow-auto rounded-lg border border-slate-200 bg-white py-1 text-sm shadow-lg"
			@keydown.escape.window="pick.customerOpen = false"
		>
			<template x-for="c in pick.customerResults" :key="c.id">
				<li>
					<button type="button" class="block w-full px-3 py-2 text-left hover:bg-slate-50" @mousedown.prevent="selectCustomer(c)" x-text="c.name + (c.customer_code ? ' · ' + c.customer_code : '')"></button>
				</li>
			</template>
		</ul>
		@error('customer_id')<p class="mt-1 text-xs text-rose-600">{{ $message }}</p>@enderror
	</div>

	<div class="relative space-y-1.5">
		<label for="return-pick-invoice-{{ $idSuffix }}" class="text-xs font-medium text-slate-600">Sales invoice <span class="font-normal text-slate-400">(search number, product, model, serial)</span></label>
		<input
			id="return-pick-invoice-{{ $idSuffix }}"
			type="text"
			class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm"
			placeholder="Type invoice # or filter lines…"
			x-model="pick.invoiceSearch"
			@input="scheduleInvoiceSearch()"
			@focus="onInvoicePickFocus()"
			autocomplete="off"
		>
		<input type="hidden" name="sales_invoice_id" x-model="pick.sales_invoice_id" required>
		<ul
			x-show="pick.invoiceOpen && pick.invoiceResults.length"
			x-cloak
			class="absolute z-10 mt-1 max-h-64 w-full overflow-auto rounded-lg border border-slate-200 bg-white py-1 text-sm shadow-lg"
		>
			<template x-for="inv in pick.invoiceResults" :key="inv.id">
				<li>
					<button type="button" class="block w-full px-3 py-2 text-left hover:bg-slate-50" @mousedown.prevent="selectInvoice(inv)">
						<span class="block font-medium text-slate-900" x-text="inv.option_label"></span>
						<span class="mt-0.5 block text-xs leading-snug text-slate-500" x-text="inv.option_subtitle"></span>
					</button>
				</li>
			</template>
		</ul>
		@error('sales_invoice_id')<p class="mt-1 text-xs text-rose-600">{{ $message }}</p>@enderror
	</div>

	<div class="space-y-1.5">
		<label class="text-xs font-medium text-slate-600">Branch</label>
		<select name="branch_id" x-model="pick.branch_id" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm" required>
			<option value="" disabled>Choose branch…</option>
			@foreach ($locations as $loc)
				<option value="{{ $loc->id }}">{{ $loc->name }}</option>
			@endforeach
		</select>
		<p class="text-xs text-slate-500">Filled from the invoice when you pick one; you can change it if needed.</p>
		@error('branch_id')<p class="mt-1 text-xs text-rose-600">{{ $message }}</p>@enderror
	</div>

	<div
		x-show="pick.invoiceDetail"
		x-cloak
		class="rounded-lg border border-slate-200 bg-slate-50/80 p-3 text-xs text-slate-700"
	>
		<p class="font-semibold text-slate-800">Invoice preview</p>
		<p class="mt-1 font-mono text-sm text-slate-900" x-text="pick.invoiceDetail ? (pick.invoiceDetail.invoice_number + ' · ' + pick.invoiceDetail.invoice_date + ' · Total ' + pick.invoiceDetail.grand_total + ' · Paid ' + pick.invoiceDetail.amount_paid + (parseFloat(pick.invoiceDetail.amount_due) > 0 ? ' · Due ' + pick.invoiceDetail.amount_due : '')) : ''"></p>
		<div class="mt-2 max-h-40 overflow-y-auto border-t border-slate-200 pt-2" x-show="pick.invoiceDetail && pick.invoiceDetail.lines && pick.invoiceDetail.lines.length">
			<ul class="space-y-1.5">
				<template x-for="(ln, idx) in (pick.invoiceDetail && pick.invoiceDetail.lines) ? pick.invoiceDetail.lines : []" :key="'ln-' + idx">
					<li class="leading-relaxed">
						<span class="font-medium text-slate-800" x-text="(ln.product_name || 'Item') + (ln.model_number ? ' · ' + ln.model_number : '')"></span>
						<span class="text-slate-500" x-text="' ×' + ln.qty + ' · ' + ln.line_total"></span>
						<span class="block text-slate-600" x-show="ln.serials && ln.serials.length" x-text="'Serials: ' + ln.serials.join(', ') + (ln.serials_more ? ' …+' + ln.serials_more : '')"></span>
					</li>
				</template>
			</ul>
		</div>
	</div>
</div>
