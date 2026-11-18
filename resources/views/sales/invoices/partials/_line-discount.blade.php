@php
	$lineDiscount = max(0, (float) ($line->discount_amount ?? 0));
	$discountPercentLabel = $line->formattedDiscountPercent();
	$plain = (bool) ($plain ?? false);
	$currency = $currency ?? null;
@endphp
@if ($lineDiscount <= 0.0001)
	@if ($plain)
		—
	@else
		<span class="text-slate-400">—</span>
	@endif
@else
	@if ($plain)
		@if ($currency)
			{{ $currency }}
		@endif
		{{ number_format($lineDiscount, 2) }}
		@if ($discountPercentLabel !== null)
			<br><small style="font-size:11px;color:#64748b">({{ $discountPercentLabel }}%)</small>
		@endif
	@else
		<span class="font-medium tabular-nums">{{ number_format($lineDiscount, 2) }}</span>
		@if ($discountPercentLabel !== null)
			<span class="mt-0.5 block text-xs font-normal text-slate-500">({{ $discountPercentLabel }}%)</span>
		@endif
	@endif
@endif
