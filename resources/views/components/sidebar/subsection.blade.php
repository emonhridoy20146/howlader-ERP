@props(['section'])

@if ($section['title'])
	<div class="mt-2 first:mt-0" x-show="matchesAny({{ Js::from($section['search']) }})">
		<p class="px-2 pb-1 text-[0.6rem] font-semibold uppercase tracking-[0.16em] text-slate-500">
			{{ $section['title'] }}
		</p>
		<div class="space-y-0.5">
			@foreach ($section['items'] as $item)
				<x-sidebar.nav-link :item="$item" />
			@endforeach
		</div>
	</div>
@else
	<div class="space-y-0.5">
		@foreach ($section['items'] as $item)
			<x-sidebar.nav-link :item="$item" />
		@endforeach
	</div>
@endif
