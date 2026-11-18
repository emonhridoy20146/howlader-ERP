@props(['item'])

<a
	href="{{ $item['url'] }}"
	data-nav-item
	x-show="matches({{ Js::from($item['search']) }})"
	@if ($item['active']) data-sidebar-active aria-current="page" @endif
	@class([
		'group/nav flex min-h-[2.5rem] items-center gap-2.5 rounded-lg px-2 py-1.5 text-sm font-medium outline-none transition-colors focus-visible:ring-2 focus-visible:ring-sky-400',
		'bg-slate-700/95 text-white shadow-sm ring-1 ring-white/10' => $item['active'],
		'text-slate-300 hover:bg-slate-700/55 hover:text-white' => ! $item['active'],
	])
>
	<span @class([
		'flex h-7 w-7 shrink-0 items-center justify-center rounded-md transition-colors',
		'bg-sky-500/90 text-white' => $item['active'],
		'bg-slate-700/50 text-slate-400 group-hover/nav:text-slate-100' => ! $item['active'],
	])>
		<x-sidebar.icon :name="$item['icon']" class="h-4 w-4" />
	</span>
	<span class="min-w-0 flex-1 truncate">{{ $item['label'] }}</span>
</a>
