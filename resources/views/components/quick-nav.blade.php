@php
	$items = [
		[
			'href' => route('dashboard'),
			'label' => 'Home',
			'match' => ['dashboard'],
			'icon' => '<path stroke-linecap="round" stroke-linejoin="round" d="m2.25 12 8.954-8.955c.44-.439 1.152-.439 1.591 0L21.75 12M4.5 9.75v10.125c0 .621.504 1.125 1.125 1.125H9.75v-4.875c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125V21h4.125c.621 0 1.125-.504 1.125-1.125V9.75M8.25 21h8.25" />',
		],
		[
			'href' => route('sales.invoices.create'),
			'label' => 'New sale',
			'match' => ['sales.invoices.create'],
			'can' => auth()->user()?->can('create', \App\Models\SalesInvoice::class),
			'primary' => true,
			'icon' => '<path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" />',
		],
		[
			'href' => route('sales.invoices.index'),
			'label' => 'Invoices',
			'match' => ['sales.invoices.index', 'sales.invoices.show', 'sales.invoices.edit'],
			'can' => auth()->user()?->can('viewAny', \App\Models\SalesInvoice::class),
			'icon' => '<path stroke-linecap="round" stroke-linejoin="round" d="M19.5 14.25v-2.625a3.375 3.375 0 0 0-3.375-3.375h-1.5A1.125 1.125 0 0 1 13.5 7.125v-1.5a3.375 3.375 0 0 0-3.375-3.375H8.25m0 12.75h7.5m-7.5 3H12M10.5 2.25H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 0 0-9-9Z" />',
		],
		[
			'href' => route('products.index'),
			'label' => 'Products',
			'match' => ['products.*'],
			'can' => auth()->user()?->can('viewAny', \App\Models\Product::class),
			'icon' => '<path stroke-linecap="round" stroke-linejoin="round" d="m21 7.5-9-5.25L3 7.5m18 0-9 5.25m9-5.25v9l-9 5.25M3 7.5l9 5.25M3 7.5v9l9 5.25m0-9v9" />',
		],
		[
			'href' => route('goods-receipts.create'),
			'label' => 'Receive',
			'match' => ['goods-receipts.*'],
			'can' => auth()->user()?->can('viewAny', \App\Models\GoodsReceipt::class),
			'icon' => '<path stroke-linecap="round" stroke-linejoin="round" d="m20.25 7.5-.625 10.632a2.25 2.25 0 0 1-2.247 2.118H6.622a2.25 2.25 0 0 1-2.247-2.118L3.75 7.5M10 11.25h4M3.375 7.5h17.25c.621 0 1.125-.504 1.125-1.125v-1.5c0-.621-.504-1.125-1.125-1.125H3.375c-.621 0-1.125.504-1.125 1.125v1.5c0 .621.504 1.125 1.125 1.125Z" />',
		],
		[
			'href' => route('customers.index'),
			'label' => 'Customers',
			'match' => ['customers.*'],
			'can' => auth()->user()?->can('viewAny', \App\Models\Customer::class),
			'icon' => '<path stroke-linecap="round" stroke-linejoin="round" d="M15 19.128a9.38 9.38 0 0 0 2.625.372 9.337 9.337 0 0 0 4.121-.952 4.125 4.125 0 0 0-7.533-2.493M15 19.128v-.003c0-1.113-.285-2.16-.786-3.07M15 19.128v.106A12.318 12.318 0 0 1 8.624 21c-2.331 0-4.512-.645-6.374-1.766l-.001-.109a6.375 6.375 0 0 1 11.964-3.07M12 6.375a3.375 3.375 0 1 1-6.75 0 3.375 3.375 0 0 1 6.75 0Zm8.25 2.25a2.625 2.625 0 1 1-5.25 0 2.625 2.625 0 0 1 5.25 0Z" />',
		],
	];
@endphp

<nav
	class="pointer-events-none fixed inset-x-0 bottom-0 z-30 lg:left-64"
	aria-label="Quick actions"
>
	<div
		class="mx-auto flex w-full justify-center px-3 pb-[max(0.65rem,env(safe-area-inset-bottom))] pt-2 sm:px-6"
	>
		<div
			class="pointer-events-auto flex w-full max-w-7xl items-end justify-between gap-0.5 rounded-2xl border border-slate-200/80 bg-white/95 px-1 py-1.5 shadow-[0_-4px_24px_-4px_rgba(15,23,42,0.12)] ring-1 ring-slate-900/5 backdrop-blur-md sm:gap-1 sm:px-2 lg:max-w-lg lg:px-2"
		>
			@foreach ($items as $item)
				@if (!($item['can'] ?? true))
					@continue
				@endif
				@php
					$active = request()->routeIs(...$item['match']);
					$primary = ! empty($item['primary']);
				@endphp
				<a
					href="{{ $item['href'] }}"
					class="group flex min-w-0 flex-1 flex-col items-center justify-end gap-0.5 rounded-xl px-1 py-1 text-center outline-none transition focus-visible:ring-2 focus-visible:ring-slate-400 focus-visible:ring-offset-2 focus-visible:ring-offset-white sm:px-1.5 sm:py-1.5
						{{ $primary
							? ($active
								? 'text-emerald-900'
								: 'text-slate-800')
							: ($active
								? 'text-slate-900'
								: 'text-slate-500 hover:text-slate-800') }}"
					aria-current="{{ $active ? 'page' : 'false' }}"
				>
					<span
						class="flex h-9 w-9 items-center justify-center rounded-xl transition sm:h-10 sm:w-10
							{{ $primary
								? ($active
									? 'bg-emerald-600 shadow-md shadow-emerald-900/20 ring-1 ring-emerald-700/30'
									: 'bg-slate-800 text-white shadow-md shadow-slate-900/25 ring-1 ring-slate-900/20 group-hover:bg-slate-700')
								: ($active
									? 'bg-slate-100 text-slate-900 ring-1 ring-slate-200'
									: 'bg-transparent group-hover:bg-slate-50') }}"
					>
						<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="h-5 w-5" aria-hidden="true">
							{!! $item['icon'] !!}
						</svg>
					</span>
					<span class="max-w-full truncate text-[0.65rem] font-semibold leading-tight tracking-tight sm:text-xs">
						{{ $item['label'] }}
					</span>
				</a>
			@endforeach
		</div>
	</div>
</nav>
