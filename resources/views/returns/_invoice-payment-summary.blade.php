@props(['invoice', 'refundLineValueBase' => null, 'refundSettlementBase' => null, 'refundableCashRemaining' => null, 'compact' => false])

@php
	$grandTotal = (float) $invoice->grand_total;
	$amountPaid = (float) $invoice->amount_paid;
	$amountDue = (float) $invoice->amount_due;
	$refundableCashRemaining = $refundableCashRemaining ?? $refundSettlementBase;
@endphp

<div {{ $attributes->merge(['class' => 'rounded-xl border border-slate-200 bg-gradient-to-br from-slate-50 via-white to-emerald-50/30 p-4 shadow-sm']) }}>
	<div class="flex flex-wrap items-start justify-between gap-3">
		<div>
			<p class="text-[10px] font-semibold uppercase tracking-wider text-slate-500">Linked invoice</p>
			<a href="{{ route('sales.invoices.show', $invoice) }}" class="mt-0.5 inline-block font-mono text-sm font-semibold text-indigo-700 hover:text-indigo-900">
				{{ $invoice->invoice_number }}
			</a>
			@if ($invoice->invoice_date)
				<p class="mt-0.5 text-xs text-slate-500">{{ $invoice->invoice_date->toDateString() }}</p>
			@endif
		</div>
		@if (! $compact && $refundLineValueBase !== null && $refundSettlementBase !== null)
			<div class="text-right text-xs text-slate-600">
				<p>Returned line value <span class="font-semibold tabular-nums text-slate-800">{{ number_format((float) $refundLineValueBase, 2) }}</span></p>
				<p class="mt-0.5">Refundable (paid share) <span class="font-semibold tabular-nums text-emerald-700">{{ number_format((float) $refundSettlementBase, 2) }}</span></p>
			</div>
		@endif
	</div>

	<dl class="mt-4 grid grid-cols-3 gap-2 sm:gap-3">
		<div class="rounded-lg bg-white/90 px-3 py-2.5 text-center ring-1 ring-slate-200/80 sm:text-left">
			<dt class="text-[10px] font-semibold uppercase tracking-wider text-slate-500">Invoice total</dt>
			<dd class="mt-1 text-sm font-semibold tabular-nums text-slate-900">{{ number_format($grandTotal, 2) }}</dd>
		</div>
		<div class="rounded-lg bg-white/90 px-3 py-2.5 text-center ring-1 ring-emerald-200/80 sm:text-left">
			<dt class="text-[10px] font-semibold uppercase tracking-wider text-emerald-700">Paid</dt>
			<dd class="mt-1 text-sm font-semibold tabular-nums text-emerald-700">{{ number_format($amountPaid, 2) }}</dd>
		</div>
		<div class="rounded-lg bg-white/90 px-3 py-2.5 text-center ring-1 ring-slate-200/80 sm:text-left">
			<dt class="text-[10px] font-semibold uppercase tracking-wider {{ $amountDue > 0 ? 'text-rose-600' : 'text-slate-500' }}">Due</dt>
			<dd class="mt-1 text-sm font-semibold tabular-nums {{ $amountDue > 0 ? 'text-rose-700' : 'text-slate-800' }}">{{ number_format($amountDue, 2) }}</dd>
		</div>
	</dl>

	@if ($compact && $refundSettlementBase !== null)
		<p class="mt-3 border-t border-slate-200/80 pt-3 text-xs text-slate-600">
			Full settlement at 100% refunds up to
			<span class="font-semibold tabular-nums text-emerald-700">{{ number_format((float) $refundSettlementBase, 2) }}</span>
			(the customer's paid share for returned items).
			@if ($refundableCashRemaining !== null && (float) $refundableCashRemaining + 0.001 < (float) $refundSettlementBase)
				<span class="mt-1 block text-amber-700">Only <span class="font-semibold tabular-nums">{{ number_format((float) $refundableCashRemaining, 2) }}</span> of paid amount remains available on this invoice.</span>
			@endif
		</p>
	@endif
</div>
