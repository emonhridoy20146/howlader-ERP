@props(['snapshot' => null, 'grade' => null])
@php
	$snap = $snapshot;
	$g = $snap?->grade ?? $grade;
@endphp
@if ($g)
	<span title="{{ $snap?->tooltip() }}" {{ $attributes->class(['inline-flex min-w-[1.75rem] justify-center rounded-full px-2 py-0.5 text-xs font-semibold', \App\Models\CustomerRiskSnapshot::gradeClasses($g)]) }}>{{ $g }}</span>
@else
	<span {{ $attributes->class(['text-slate-400']) }}>—</span>
@endif
