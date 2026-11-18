@php
	$cls = match ($status) {
		'sent', 'completed' => 'bg-emerald-50 text-emerald-800 ring-emerald-200',
		'failed' => 'bg-rose-50 text-rose-800 ring-rose-200',
		'processing' => 'bg-sky-50 text-sky-800 ring-sky-200',
		default => 'bg-amber-50 text-amber-800 ring-amber-200',
	};
@endphp
<span class="inline-flex rounded-full px-2 py-0.5 text-xs font-semibold capitalize ring-1 {{ $cls }}">{{ $status }}</span>
