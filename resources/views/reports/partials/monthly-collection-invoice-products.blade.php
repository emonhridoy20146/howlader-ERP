@php
	/** @var \App\Models\SalesInvoice|null $invoice */
@endphp
@if (! $invoice || ! $invoice->relationLoaded('lines') || $invoice->lines->isEmpty())
	<span class="text-slate-400">—</span>
@else
	<ul class="max-w-xs space-y-0.5 whitespace-normal text-xs text-slate-600">
		@foreach ($invoice->lines as $line)
			@php
				$label = $line->product?->name ?? ('Product #'.(string) $line->product_id);
				$defaultPrice = $line->product ? (float) $line->product->default_sale_price : null;
			@endphp
			<li>
				{{ $label }} × {{ number_format((float) $line->qty, 0) }} @ {{ number_format((float) $line->unit_price, 0) }}
				@if ($defaultPrice !== null)
					<span class="text-slate-500">(def. {{ number_format($defaultPrice, 0) }})</span>
				@endif
			</li>
		@endforeach
	</ul>
@endif
