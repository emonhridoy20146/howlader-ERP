@php
	$navGroups = \App\Support\Navigation::forUser(auth()->user());
	$navItemSearch = array_merge([], ...array_column($navGroups, 'search'));
@endphp

<div
	class="flex min-h-0 flex-1 flex-col"
	x-data="sidebarNav({
		groups: {{ Js::from(array_column($navGroups, 'key')) }},
		items: {{ Js::from($navItemSearch) }},
		activeGroup: {{ Js::from(\App\Support\Navigation::activeGroupKey($navGroups)) }},
	})"
	@keydown.arrow-down.prevent="moveFocus(1)"
	@keydown.arrow-up.prevent="moveFocus(-1)"
	@keydown.window="onShortcut($event) && (sidebarOpen = true)"
>
	<div class="shrink-0 px-3 pb-2 pt-3">
		<label for="sidebar-menu-filter" class="sr-only">Find a menu item</label>
		<div class="relative">
			<span class="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-2.5 text-slate-500">
				<svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" aria-hidden="true">
					<path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-5.197-5.197m0 0A7.5 7.5 0 105.196 5.196a7.5 7.5 0 0010.607 10.607z" />
				</svg>
			</span>
			<input
				id="sidebar-menu-filter"
				x-ref="search"
				type="text"
				x-model="q"
				@keydown.escape.prevent="clearOrBlur()"
				@keydown.enter.prevent="openFirst()"
				placeholder="Find a menu…"
				autocomplete="off"
				spellcheck="false"
				class="w-full rounded-lg border border-slate-700 bg-slate-900/70 py-2 pl-8 pr-14 text-sm text-slate-100 placeholder:text-slate-500 focus:border-sky-500 focus:outline-none focus:ring-1 focus:ring-sky-500"
			>
			<button
				type="button"
				x-cloak
				x-show="filtering"
				@click="clear()"
				class="absolute inset-y-0 right-1.5 my-auto flex h-6 w-6 items-center justify-center rounded-md text-slate-400 transition hover:bg-slate-700 hover:text-white"
			>
				<span class="sr-only">Clear menu filter</span>
				<svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor" aria-hidden="true">
					<path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12" />
				</svg>
			</button>
			<kbd
				x-show="! filtering"
				class="pointer-events-none absolute inset-y-0 right-2 my-auto hidden h-5 items-center rounded border border-slate-700 bg-slate-800 px-1.5 text-[0.6rem] font-medium text-slate-500 lg:flex"
			>alt m</kbd>
		</div>
		<p x-cloak x-show="filtering" class="mt-1.5 px-0.5 text-[0.7rem] text-slate-500">
			<span x-text="resultCount"></span> of {{ count($navItemSearch) }} menu items
		</p>
	</div>

	<nav
		x-ref="list"
		class="sidebar-scroll min-h-0 flex-1 overflow-y-auto overscroll-contain px-3 pb-6 text-sm"
		aria-label="Main navigation"
	>
		@foreach ($navGroups as $group)
			<x-sidebar.group :group="$group" />
		@endforeach

		<p x-cloak x-show="filtering && resultCount === 0" class="mt-4 rounded-lg bg-slate-900/60 px-3 py-4 text-center text-xs text-slate-400">
			Nothing matches “<span class="font-medium text-slate-200" x-text="q"></span>”.
		</p>
	</nav>

	<div x-cloak x-show="! filtering" class="shrink-0 border-t border-slate-700/60 px-3 py-2">
		<button
			type="button"
			@click="toggleAll()"
			class="w-full rounded-lg px-2 py-1.5 text-left text-[0.65rem] font-semibold uppercase tracking-[0.16em] text-slate-500 transition hover:bg-slate-700/40 hover:text-slate-200"
			x-text="allOpen ? 'Collapse all' : 'Expand all'"
		></button>
	</div>
</div>
