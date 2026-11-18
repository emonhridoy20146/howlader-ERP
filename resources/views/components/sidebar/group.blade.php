@props(['group'])

@php
	$key = Js::from($group['key']);
	// Collapsed markup is emitted pre-hidden so the menu does not flash open
	// during the tick before Alpine applies the stored state.
	$startsOpen = $group['active'];
@endphp

@if ($group['flat'])
	<div class="space-y-0.5">
		@foreach ($group['sections'] as $section)
			<x-sidebar.subsection :section="$section" />
		@endforeach
	</div>
@else
	<div class="mt-1" x-show="matchesAny({{ Js::from($group['search']) }})">
		<button
			type="button"
			@click="toggle({{ $key }})"
			:aria-expanded="isOpen({{ $key }})"
			@class([
				'flex w-full items-center gap-2 rounded-lg px-2 py-2 text-left text-[0.65rem] font-semibold uppercase tracking-[0.16em] outline-none transition hover:bg-slate-700/40 focus-visible:ring-2 focus-visible:ring-sky-400',
				'text-white' => $group['active'],
				'text-slate-400 hover:text-slate-100' => ! $group['active'],
			])
		>
			<svg class="h-3.5 w-3.5 shrink-0 text-slate-500 transition-transform duration-200" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor" aria-hidden="true"
				:class="isOpen({{ $key }}) ? 'rotate-90' : ''">
				<path stroke-linecap="round" stroke-linejoin="round" d="m8.25 4.5 7.5 7.5-7.5 7.5" />
			</svg>
			<span class="min-w-0 flex-1 truncate">{{ $group['title'] }}</span>
			@if ($group['active'])
				<span class="h-1.5 w-1.5 shrink-0 rounded-full bg-sky-400" aria-hidden="true"></span>
			@endif
			<span class="shrink-0 rounded-full bg-slate-700/70 px-1.5 py-0.5 text-[0.6rem] font-semibold tracking-normal text-slate-400" x-show="! filtering">{{ $group['count'] }}</span>
		</button>
		<div
			class="mt-0.5 space-y-0.5 pl-1"
			x-show="isOpen({{ $key }})"
			x-transition.opacity.duration.150ms
			@style(['display: none' => ! $startsOpen])
		>
			@foreach ($group['sections'] as $section)
				<x-sidebar.subsection :section="$section" />
			@endforeach
		</div>
	</div>
@endif
