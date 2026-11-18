@php
	$months = (int) ($months ?? 0);
	$compact = (bool) ($compact ?? false);
@endphp
@if ($months >= 2)
	<span @class([
		'inline-flex items-center rounded-md bg-red-50 font-medium text-red-700 ring-1 ring-inset ring-red-600/10',
		'px-2 py-1 text-xs' => ! $compact,
		'px-1.5 py-0.5 text-[10px]' => $compact,
	])>{{ $months }} mo</span>
@elseif ($months > 0)
	<span @class([
		'inline-flex items-center rounded-md bg-yellow-50 font-medium text-yellow-800 ring-1 ring-inset ring-yellow-600/20',
		'px-2 py-1 text-xs' => ! $compact,
		'px-1.5 py-0.5 text-[10px]' => $compact,
	])>{{ $months }} mo</span>
@else
	<span class="text-slate-400 tabular-nums">0</span>
@endif
