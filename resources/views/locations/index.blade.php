@extends('layouts.app')
@section('title', 'Branches')
@section('page-title', 'Branches')
@section('content')
@if (!empty($restrictToAccessibleLocations))
	<p class="mb-4 text-sm text-slate-600">Only branches linked to your account are listed.</p>
@endif
@php
	$drawerMode = request('drawer');
	$isEdit = $drawerMode === 'edit' && !is_null($editingLocation);
	$isDrawerOpen = in_array($drawerMode, ['create', 'edit'], true) || $errors->any();
	$location = $isEdit ? $editingLocation : new \App\Models\Location();
@endphp

<div x-data="{ drawerOpen: @js($isDrawerOpen), viewMode: localStorage.getItem('locations_view_mode') || 'table' }" x-init="$watch('viewMode', val => localStorage.setItem('locations_view_mode', val))">
	<div class="mb-4 flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
		<div class="flex items-center gap-1 bg-slate-200/60 p-0.5 rounded-lg border border-slate-200 shadow-sm self-start">
			<button 
				type="button" 
				@click="viewMode = 'table'"
				:class="viewMode === 'table' ? 'bg-white text-slate-950 shadow-sm border-slate-200' : 'text-slate-500 hover:text-slate-800 border-transparent'"
				class="flex items-center gap-1.5 rounded-md px-2.5 py-1 text-xs font-semibold transition border"
			>
				<svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
					<path stroke-linecap="round" stroke-linejoin="round" d="M3.75 5.25h16.5m-16.5 4.5h16.5m-16.5 4.5h16.5m-16.5 4.5h16.5" />
				</svg>
				Table View
			</button>
			<button 
				type="button" 
				@click="viewMode = 'grid'"
				:class="viewMode === 'grid' ? 'bg-white text-slate-950 shadow-sm border-slate-200' : 'text-slate-500 hover:text-slate-800 border-transparent'"
				class="flex items-center gap-1.5 rounded-md px-2.5 py-1 text-xs font-semibold transition border"
			>
				<svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
					<path stroke-linecap="round" stroke-linejoin="round" d="M3.75 6A2.25 2.25 0 0 1 6 3.75h2.25A2.25 2.25 0 0 1 10.5 6v2.25a2.25 2.25 0 0 1-2.25 2.25H6a2.25 2.25 0 0 1-2.25-2.25V6ZM3.75 15.75A2.25 2.25 0 0 1 6 13.5h2.25a2.25 2.25 0 0 1 2.25 2.25V18a2.25 2.25 0 0 1-2.25 2.25H6A2.25 2.25 0 0 1 3.75 18v-2.25ZM13.5 6a2.25 2.25 0 0 1 2.25-2.25H18A2.25 2.25 0 0 1 20.25 6v2.25A2.25 2.25 0 0 1 18 10.5h-2.25a2.25 2.25 0 0 1-2.25-2.25V6ZM13.5 15.75a2.25 2.25 0 0 1 2.25-2.25H18a2.25 2.25 0 0 1 2.25 2.25V18A2.25 2.25 0 0 1 18 20.25h-2.25A2.25 2.25 0 0 1 13.5 18v-2.25Z" />
				</svg>
				Grid View
			</button>
		</div>
		<a href="{{ route('locations.index', ['drawer' => 'create']) }}" class="rounded-lg bg-slate-900 px-4 py-2 text-sm text-white hover:bg-slate-800 transition">New Branch</a>
	</div>

	@if (session('status'))
		<div class="mb-4 rounded-lg bg-emerald-50 p-3 text-sm text-emerald-700">{{ session('status') }}</div>
	@endif

	<x-filter-drawer>
<form method="get" action="{{ route('locations.index') }}" class="flex flex-col gap-4">
		<div class="flex flex-col gap-4">
			<input type="text" name="q" value="{{ $filters['q'] ?? '' }}" placeholder="Search code, name, phone, manager" class="rounded-lg border border-slate-300 px-3 py-2 text-sm ">
			<select name="type" class="rounded-lg border border-slate-300 px-3 py-2 text-sm">
				<option value="">All types</option>
				<option value="warehouse" @selected(($filters['type'] ?? '') === 'warehouse')>Warehouse</option>
				<option value="branch" @selected(($filters['type'] ?? '') === 'branch')>Branch</option>
			</select>
			<select name="is_active" class="rounded-lg border border-slate-300 px-3 py-2 text-sm">
				<option value="">All status</option>
				<option value="1" @selected(($filters['is_active'] ?? '') === '1')>Active</option>
				<option value="0" @selected(($filters['is_active'] ?? '') === '0')>Inactive</option>
			</select>
		</div>
		<x-index-filter-actions :reset-url="route('locations.index')" />
	</form>
</x-filter-drawer>

	<div class="mb-4 grid gap-3 sm:grid-cols-5">
		<x-status-card label="Total">
			{{ $kpis['total'] }}
		</x-status-card>
		<x-status-card label="Branches">
			{{ $kpis['branches'] }}
		</x-status-card>
		<x-status-card label="Warehouses">
			{{ $kpis['warehouses'] }}
		</x-status-card>
		<x-status-card label="Active">
			{{ $kpis['active'] }}
		</x-status-card>
		<x-status-card label="Filtered">
			{{ $kpis['filtered'] }}
		</x-status-card>
	</div>

	<!-- Table View -->
	<div x-show="viewMode === 'table'" class="space-y-4">
		<x-index-table-shell title="Branches" :meta="$locations->total().' in this list'">
			<table class="index-data-table min-w-[64rem] sm:min-w-full whitespace-nowrap">
				<thead class="bg-slate-50 border-b border-slate-200 text-left text-xs font-semibold uppercase tracking-wider text-slate-700">
					<tr>
						<th class="px-4 py-3">Branch / Warehouse</th>
						<th class="px-4 py-3">Status & Performance</th>
						<th class="px-4 py-3">Targets Progress</th>
						<th class="px-4 py-3 text-right">Sales (30d)</th>
						<th class="px-4 py-3 text-right">Outstanding</th>
						<th class="px-4 py-3 text-right">On hand</th>
						<th class="px-4 py-3 text-right">Transfers (30d)</th>
						<th class="px-4 py-3">Supported Payments</th>
						<th class="px-4 py-3 text-right">Actions</th>
					</tr>
				</thead>
				<tbody>
					@forelse ($locations as $rowLocation)
						<tr class="border-b border-slate-100 transition-colors hover:bg-slate-50/70 last:border-0">
							<td class="px-4 py-3">
								<div class="flex items-center gap-3">
									<span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg {{ $rowLocation->type === 'warehouse' ? 'bg-amber-100 text-amber-800' : 'bg-rose-100 text-rose-800' }}">
										@if($rowLocation->type === 'warehouse')
											<svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
												<path stroke-linecap="round" stroke-linejoin="round" d="M8.25 21v-4.875c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125V21m0 0h4.5V3.75M5.25 9h3.75m-3.75 3h3.75m-3.75 3h3.75m3.75-6h3.75m-3.75 3h3.75m-3.75 3h3.75M9 21v-4.875c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125V21" />
											</svg>
										@else
											<svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
												<path stroke-linecap="round" stroke-linejoin="round" d="M13.5 21v-7.5a.75.75 0 0 1 .75-.75h3a.75.75 0 0 1 .75.75V21m-4.5 0H2.36m11.14 0H18m0 0h3.64m-1.39 0V9.349M3.75 21V9.349m0 0a3.001 3.001 0 0 0 3.75-.615A2.993 2.993 0 0 0 9.75 9.75c.896 0 1.7-.393 2.25-1.016a2.993 2.993 0 0 0 2.25 1.016c.896 0 1.7-.393 2.25-1.015a3.001 3.001 0 0 0 3.75.614m-16.5 0a3.004 3.004 0 0 1-.621-4.72l1.189-1.19A1.5 1.5 0 0 1 5.378 3h13.243a1.5 1.5 0 0 1 1.06.44l1.19 1.189a3 3 0 0 1-.621 4.72M6.75 18h3.75a.75.75 0 0 0 .75-.75V13.5a.75.75 0 0 0-.75-.75H6.75a.75.75 0 0 0-.75.75v3.75c0 .414.336.75.75.75Z" />
											</svg>
										@endif
									</span>
									<div>
										<div class="font-semibold text-slate-900 leading-none">{{ $rowLocation->name }}</div>
										<div class="mt-1.5 text-[11px] font-mono text-slate-400 leading-none">{{ $rowLocation->code }}</div>
									</div>
								</div>
							</td>
							<td class="px-4 py-3">
								<div class="flex items-center gap-2">
									@if(!$rowLocation->is_active)
										<span class="inline-flex rounded bg-rose-100 px-2 py-0.5 text-[10px] font-bold uppercase tracking-widest text-rose-700">Inactive</span>
									@else
										<span class="inline-flex rounded bg-emerald-100 px-2 py-0.5 text-[10px] font-bold uppercase tracking-widest text-emerald-700">Active</span>
									@endif
									
									@php
										$m = $locationCardMetrics[(int) $rowLocation->id] ?? null;
										$perf = $m['performance'] ?? null;
									@endphp
									@if ($perf)
										@if (!$rowLocation->is_active)
											<span class="text-[10px] font-semibold text-slate-400" title="Inactive locations are not ranked for activity">
												Paused
											</span>
										@else
											@php
												$perfRing = match ($perf['tone']) {
													'emerald' => 'ring-emerald-200',
													'amber' => 'ring-amber-200',
													default => 'ring-slate-200',
												};
												$perfBg = match ($perf['tone']) {
													'emerald' => 'bg-emerald-50 text-emerald-800',
													'amber' => 'bg-amber-50 text-amber-800',
													default => 'bg-slate-100 text-slate-700',
												};
												$perfDot = match ($perf['tone']) {
													'emerald' => 'bg-emerald-500',
													'amber' => 'bg-amber-500',
													default => 'bg-slate-400',
												};
											@endphp
											<span class="inline-flex items-center gap-1 rounded-full px-1.5 py-0.5 text-[10px] font-bold uppercase tracking-wider ring-1 {{ $perfRing }} {{ $perfBg }}" title="Based on last 30 days sales, transfers, and stock depth compared to other locations on this screen">
												<span class="h-1.5 w-1.5 shrink-0 rounded-full {{ $perfDot }}"></span>
												{{ $perf['label'] }}
											</span>
										@endif
									@endif
								</div>
							</td>
							<td class="px-4 py-3">
								@php
									$tp = $branchTargetProgress[(int) $rowLocation->id] ?? null;
								@endphp
								@if (in_array($rowLocation->type, ['branch', 'warehouse'], true) && $tp && ($tp['has_target'] ?? false))
									<div class="w-36 space-y-1">
										@if (($tp['sales']['target'] ?? 0) > 0)
											<div>
												<div class="flex justify-between text-[9px] font-medium text-slate-500">
													<span>Sales</span>
													<span class="tabular-nums font-semibold">{{ $tp['sales']['pct'] !== null ? number_format($tp['sales']['pct'], 0).'%' : '—' }}</span>
												</div>
												<div class="mt-0.5 h-1 overflow-hidden rounded-full bg-slate-100">
													<div class="h-full rounded-full bg-indigo-500" style="width: {{ min(100, $tp['sales']['pct'] ?? 0) }}%"></div>
												</div>
											</div>
										@endif
										@if (($tp['collection']['target'] ?? 0) > 0)
											<div>
												<div class="flex justify-between text-[9px] font-medium text-slate-500">
													<span>Collection</span>
													<span class="tabular-nums font-semibold">{{ $tp['collection']['pct'] !== null ? number_format($tp['collection']['pct'], 0).'%' : '—' }}</span>
												</div>
												<div class="mt-0.5 h-1 overflow-hidden rounded-full bg-slate-100">
													<div class="h-full rounded-full bg-emerald-500" style="width: {{ min(100, $tp['collection']['pct'] ?? 0) }}%"></div>
												</div>
											</div>
										@endif
									</div>
								@else
									<span class="text-slate-400 text-xs">—</span>
								@endif
							</td>
							<td class="px-4 py-3 text-right tabular-nums">
								@if($m)
									<div class="font-bold text-slate-900">{{ number_format($m['sales_30d'], 2) }}</div>
									<div class="text-[10px] text-slate-500">{{ number_format($m['sales_invoices_30d']) }} inv.</div>
								@else
									<span class="text-slate-400 text-xs">—</span>
								@endif
							</td>
							<td class="px-4 py-3 text-right tabular-nums">
								@if($m)
									<div class="font-bold text-slate-900">{{ number_format($m['outstanding'], 2) }}</div>
									<div class="text-[10px] text-slate-500">Amount due</div>
								@else
									<span class="text-slate-400 text-xs">—</span>
								@endif
							</td>
							<td class="px-4 py-3 text-right tabular-nums">
								@if($m)
									<div class="font-bold text-slate-900">{{ number_format($m['on_hand']) }}</div>
									<div class="text-[10px] text-slate-500">{{ number_format($m['sku_lines']) }} SKUs</div>
								@else
									<span class="text-slate-400 text-xs">—</span>
								@endif
							</td>
							<td class="px-4 py-3 text-right tabular-nums">
								@if($m)
									<div class="font-bold text-slate-900">{{ number_format($m['transfers_30d']) }}</div>
									<div class="text-[10px] text-slate-500">In/out touches</div>
								@else
									<span class="text-slate-400 text-xs">—</span>
								@endif
							</td>
							<td class="px-4 py-3">
								@php $methods = $rowLocation->resolvedPaymentMethods(); @endphp
								@if(count($methods))
									<div class="flex flex-wrap gap-1 max-w-[200px]">
										@foreach($methods as $method)
											<span class="inline-flex rounded bg-slate-100 px-1.5 py-0.5 text-[9px] font-medium text-slate-600 ring-1 ring-slate-200">
												{{ $method }}
											</span>
										@endforeach
									</div>
								@else
									<span class="text-slate-400 text-xs">None configured</span>
								@endif
							</td>
							<td class="px-4 py-3 text-right">
								<x-index-row-actions-menu>
									<a href="{{ route('locations.show', $rowLocation) }}">View</a>
									<a href="{{ route('locations.index', ['drawer' => 'edit', 'edit' => $rowLocation->id]) }}">Edit</a>
								</x-index-row-actions-menu>
							</td>
						</tr>
					@empty
						<tr>
							<td colspan="9" class="px-4 py-12 text-center text-sm font-medium text-slate-500 bg-slate-50/50">
								No locations found.
							</td>
						</tr>
					@endforelse
				</tbody>
			</table>
		</x-index-table-shell>
	</div>

	<!-- Grid View -->
	<div x-show="viewMode === 'grid'">
		<div class="grid gap-6 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4">
			@forelse ($locations as $rowLocation)
				<div class="group flex flex-col overflow-hidden rounded-2xl bg-white shadow-sm ring-1 ring-slate-200 transition-all hover:-translate-y-1 hover:shadow-md">
					
					<!-- Store/Warehouse Header Graphic -->
					@if($rowLocation->type === 'warehouse')
						<div class="relative h-24 bg-amber-500 overflow-hidden">
							<!-- Decorative Warehouse Elements -->
							<div class="absolute inset-0 bg-[url('data:image/svg+xml;base64,PHN2ZyB4bWxucz0iaHR0cDovL3d3dy53My5vcmcvMjAwMC9zdmciIHdpZHRoPSI4IiBoZWlnaHQ9IjgiPgo8cmVjdCB3aWR0aD0iOCIgaGVpZ2h0PSI4IiBmaWxsPSIjZmZmIiBmaWxsLW9wYWNpdHk9IjAuMSIvPgo8cGF0aCBkPSJNMCAwTDggOFpNOCAwTDAgOFoiIHN0cm9rZT0iIzAwMCIgc3Ryb2tlLW9wYWNpdHk9IjAuMDUiIHN0cm9rZS13aWR0aD0iMSIvPjwvc3ZnPg==')] opacity-30"></div>
							<div class="absolute -right-4 -top-8 h-32 w-32 rotate-12 bg-amber-400/50 blur-xl"></div>
							<div class="absolute -left-4 -bottom-8 h-24 w-24 -rotate-12 bg-amber-600/50 blur-xl"></div>
							
							<!-- Badge/Icon -->
							<div class="absolute bottom-3 left-4 flex items-center gap-2 rounded bg-white/20 px-3 py-1.5 backdrop-blur-md shadow-sm">
								<svg class="h-4 w-4 text-white" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
									<path stroke-linecap="round" stroke-linejoin="round" d="M8.25 21v-4.875c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125V21m0 0h4.5V3.75M5.25 9h3.75m-3.75 3h3.75m-3.75 3h3.75m3.75-6h3.75m-3.75 3h3.75m-3.75 3h3.75M9 21v-4.875c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125V21" />
								</svg>
								<span class="text-xs font-bold uppercase tracking-widest text-white">Warehouse</span>
							</div>
						</div>
					@else
						<div class="relative h-24 bg-rose-500 overflow-hidden">
							<!-- Store Awning -->
							<div class="absolute inset-x-0 top-0 flex w-full">
								@for($i=0; $i<8; $i++)
									<div class="h-6 flex-1 rounded-b-full shadow-sm {{ $i % 2 === 0 ? 'bg-rose-600' : 'bg-rose-400' }}"></div>
								@endfor
							</div>
							<!-- Store Window Reflection -->
							<div class="absolute -right-10 top-0 h-40 w-16 rotate-45 bg-white/10 blur-sm"></div>
							<div class="absolute -right-4 top-0 h-40 w-4 rotate-45 bg-white/10 blur-sm"></div>
							
							<!-- Badge/Icon -->
							<div class="absolute bottom-3 left-4 flex items-center gap-2 rounded bg-white/20 px-3 py-1.5 backdrop-blur-md shadow-sm">
								<svg class="h-4 w-4 text-white" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
									<path stroke-linecap="round" stroke-linejoin="round" d="M13.5 21v-7.5a.75.75 0 0 1 .75-.75h3a.75.75 0 0 1 .75.75V21m-4.5 0H2.36m11.14 0H18m0 0h3.64m-1.39 0V9.349M3.75 21V9.349m0 0a3.001 3.001 0 0 0 3.75-.615A2.993 2.993 0 0 0 9.75 9.75c.896 0 1.7-.393 2.25-1.016a2.993 2.993 0 0 0 2.25 1.016c.896 0 1.7-.393 2.25-1.015a3.001 3.001 0 0 0 3.75.614m-16.5 0a3.004 3.004 0 0 1-.621-4.72l1.189-1.19A1.5 1.5 0 0 1 5.378 3h13.243a1.5 1.5 0 0 1 1.06.44l1.19 1.189a3 3 0 0 1-.621 4.72M6.75 18h3.75a.75.75 0 0 0 .75-.75V13.5a.75.75 0 0 0-.75-.75H6.75a.75.75 0 0 0-.75.75v3.75c0 .414.336.75.75.75Z" />
								</svg>
								<span class="text-xs font-bold uppercase tracking-widest text-white">Branch</span>
							</div>
						</div>
					@endif
					
					<div class="flex flex-1 flex-col p-5">
						<div class="mb-4">
							<div class="flex items-start justify-between">
								<h3 class="line-clamp-1 text-lg font-bold text-slate-900 pr-2" title="{{ $rowLocation->name }}">{{ $rowLocation->name }}</h3>
								<!-- <span class="font-mono text-xs font-bold tracking-wider text-slate-400 mt-1">{{ $rowLocation->code }}</span> -->
							</div>
							@if(!$rowLocation->is_active)
								<span class="mt-2 inline-flex rounded bg-rose-100 px-2 py-0.5 text-[10px] font-bold uppercase tracking-widest text-rose-700">Inactive</span>
							@else
								<span class="mt-2 inline-flex rounded bg-emerald-100 px-2 py-0.5 text-[10px] font-bold uppercase tracking-widest text-emerald-700">Active</span>
							@endif
							@php
								$m = $locationCardMetrics[(int) $rowLocation->id] ?? null;
								$perf = $m['performance'] ?? null;
							@endphp
							@if ($perf)
								<div class="mt-3 flex items-center gap-2 flex-wrap">
									<span class="text-[10px] font-semibold uppercase tracking-wider text-slate-500">Vs this page</span>
									@if (!$rowLocation->is_active)
										<span class="inline-flex items-center gap-1 rounded-full bg-slate-100 px-2 py-0.5 text-[10px] font-bold uppercase tracking-wider text-slate-600 ring-1 ring-slate-200" title="Inactive locations are not ranked for activity">
											<span class="h-1.5 w-1.5 shrink-0 rounded-full bg-slate-400"></span>
											Paused
										</span>
									@else
										@php
											$perfRing = match ($perf['tone']) {
												'emerald' => 'ring-emerald-200',
												'amber' => 'ring-amber-200',
												default => 'ring-slate-200',
											};
											$perfBg = match ($perf['tone']) {
												'emerald' => 'bg-emerald-50 text-emerald-800',
												'amber' => 'bg-amber-50 text-amber-800',
												default => 'bg-slate-100 text-slate-700',
											};
											$perfDot = match ($perf['tone']) {
												'emerald' => 'bg-emerald-500',
												'amber' => 'bg-amber-500',
												default => 'bg-slate-400',
											};
										@endphp
										<span class="inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-[10px] font-bold uppercase tracking-wider ring-1 {{ $perfRing }} {{ $perfBg }}" title="Based on last 30 days sales, transfers, and stock depth compared to other locations on this screen">
											<span class="h-1.5 w-1.5 shrink-0 rounded-full {{ $perfDot }}"></span>
											{{ $perf['label'] }}
										</span>
									@endif
								</div>
							@endif
						</div>

						@php
							$tp = $branchTargetProgress[(int) $rowLocation->id] ?? null;
						@endphp
						@if (in_array($rowLocation->type, ['branch', 'warehouse'], true) && $tp && ($tp['has_target'] ?? false))
							<div class="mt-4 space-y-2 rounded-xl bg-indigo-50/60 p-3 ring-1 ring-indigo-100">
								<p class="text-[10px] font-semibold uppercase tracking-wider text-indigo-700">{{ $tp['year'] }} targets</p>
								@if (($tp['sales']['target'] ?? 0) > 0)
									<div>
										<div class="flex justify-between text-[10px] font-medium text-slate-600">
											<span>Sales</span>
											<span class="tabular-nums">{{ $tp['sales']['pct'] !== null ? number_format($tp['sales']['pct'], 0).'%' : '—' }}</span>
										</div>
										<div class="mt-1 h-1.5 overflow-hidden rounded-full bg-white">
											<div class="h-full rounded-full bg-indigo-500" style="width: {{ min(100, $tp['sales']['pct'] ?? 0) }}%"></div>
										</div>
									</div>
								@endif
								@if (($tp['collection']['target'] ?? 0) > 0)
									<div>
										<div class="flex justify-between text-[10px] font-medium text-slate-600">
											<span>Collection</span>
											<span class="tabular-nums">{{ $tp['collection']['pct'] !== null ? number_format($tp['collection']['pct'], 0).'%' : '—' }}</span>
										</div>
										<div class="mt-1 h-1.5 overflow-hidden rounded-full bg-white">
											<div class="h-full rounded-full bg-emerald-500" style="width: {{ min(100, $tp['collection']['pct'] ?? 0) }}%"></div>
										</div>
									</div>
								@endif
								<a href="{{ route('locations.show', ['location' => $rowLocation, 'tab' => 'targets']) }}" class="inline-block text-[10px] font-semibold text-indigo-700 hover:text-indigo-900">View targets →</a>
							</div>
						@endif

						@if ($m)
							<div class="mt-4 grid grid-cols-2 gap-2 rounded-xl bg-slate-50/80 p-3 ring-1 ring-slate-100">
								<div>
									<p class="text-[10px] font-semibold uppercase tracking-wider text-slate-500">Sales (30d)</p>
									<p class="mt-0.5 text-sm font-bold tabular-nums text-slate-900">{{ number_format($m['sales_30d'], 2) }}</p>
									<p class="text-[10px] text-slate-500">{{ number_format($m['sales_invoices_30d']) }} inv.</p>
								</div>
								<div>
									<p class="text-[10px] font-semibold uppercase tracking-wider text-slate-500">Outstanding</p>
									<p class="mt-0.5 text-sm font-bold tabular-nums text-slate-900">{{ number_format($m['outstanding'], 2) }}</p>
									<p class="text-[10px] text-slate-500">Amount due</p>
								</div>
								<div>
									<p class="text-[10px] font-semibold uppercase tracking-wider text-slate-500">On hand</p>
									<p class="mt-0.5 text-sm font-bold tabular-nums text-slate-900">{{ number_format($m['on_hand']) }}</p>
									<p class="text-[10px] text-slate-500">{{ number_format($m['sku_lines']) }} SKUs</p>
								</div>
								<div>
									<p class="text-[10px] font-semibold uppercase tracking-wider text-slate-500">Transfers (30d)</p>
									<p class="mt-0.5 text-sm font-bold tabular-nums text-slate-900">{{ number_format($m['transfers_30d']) }}</p>
									<p class="text-[10px] text-slate-500">In / out touches</p>
								</div>
							</div>
						@endif
						
						<div class="mt-auto pt-4 border-t border-slate-100">
							<p class="text-[10px] uppercase tracking-widest text-slate-500">Supported Payments</p>
							<p class="mt-1 text-xs font-medium text-slate-700 line-clamp-2" title="{{ implode(', ', $rowLocation->resolvedPaymentMethods()) }}">{{ implode(', ', $rowLocation->resolvedPaymentMethods()) ?: 'None configured' }}</p>
						</div>
						
						<div class="mt-6 flex items-center justify-between gap-3 border-t border-slate-100 pt-4">
							<a href="{{ route('locations.show', $rowLocation) }}" class="flex flex-1 items-center justify-center gap-1.5 rounded-lg bg-slate-100 py-2.5 text-xs font-semibold text-slate-700 transition hover:bg-slate-200">
								<svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M2.036 12.322a1.012 1.012 0 0 1 0-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.963-7.178Z" /><path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z" /></svg>
								View
							</a>
							<a href="{{ route('locations.index', ['drawer' => 'edit', 'edit' => $rowLocation->id]) }}" class="flex flex-1 items-center justify-center gap-1.5 rounded-lg bg-emerald-50 py-2.5 text-xs font-semibold text-emerald-700 transition hover:bg-emerald-100">
								<svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m16.862 4.487 1.687-1.688a1.875 1.875 0 1 1 2.652 2.652L10.582 16.07a4.5 4.5 0 0 1-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 0 1 1.13-1.897l8.932-8.931Zm0 0L19.5 7.125M18 14v4.75A2.25 2.25 0 0 1 15.75 21H5.25A2.25 2.25 0 0 1 3 18.75V8.25A2.25 2.25 0 0 1 5.25 6H10" /></svg>
								Edit
							</a>
						</div>
					</div>
				</div>
			@empty
				<div class="col-span-full rounded-2xl border border-dashed border-slate-300 bg-slate-50 py-16 text-center">
					<svg class="mx-auto h-12 w-12 text-slate-400" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
						<path stroke-linecap="round" stroke-linejoin="round" d="M13.5 21v-7.5a.75.75 0 0 1 .75-.75h3a.75.75 0 0 1 .75.75V21m-4.5 0H2.36m11.14 0H18m0 0h3.64m-1.39 0V9.349M3.75 21V9.349m0 0a3.001 3.001 0 0 0 3.75-.615A2.993 2.993 0 0 0 9.75 9.75c.896 0 1.7-.393 2.25-1.016a2.993 2.993 0 0 0 2.25 1.016c.896 0 1.7-.393 2.25-1.015a3.001 3.001 0 0 0 3.75.614m-16.5 0a3.004 3.004 0 0 1-.621-4.72l1.189-1.19A1.5 1.5 0 0 1 5.378 3h13.243a1.5 1.5 0 0 1 1.06.44l1.19 1.189a3 3 0 0 1-.621 4.72M6.75 18h3.75a.75.75 0 0 0 .75-.75V13.5a.75.75 0 0 0-.75-.75H6.75a.75.75 0 0 0-.75.75v3.75c0 .414.336.75.75.75Z" />
					</svg>
					<h3 class="mt-4 text-sm font-semibold text-slate-900">No locations found</h3>
					<p class="mt-1 text-sm text-slate-500">Create a branch or warehouse to get started.</p>
					<a href="{{ route('locations.index', ['drawer' => 'create']) }}" class="mt-4 inline-flex items-center rounded-lg bg-slate-900 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-slate-800">New Branch</a>
				</div>
			@endforelse
		</div>
	</div>

	<x-pagination-limit-selector />

	<div class="mt-4">{{ $locations->links() }}</div>

	<div x-cloak x-show="drawerOpen" class="fixed inset-0 z-40">
		<a href="{{ route('locations.index') }}" class="absolute inset-0 bg-slate-900/40" aria-label="Close"></a>
		<div class="absolute inset-y-0 right-0 w-full max-w-xl overflow-y-auto bg-white shadow-2xl">
			<div class="sticky top-0 flex items-center justify-between border-b border-slate-200 bg-white px-6 py-4">
				<h2 class="text-lg font-semibold text-slate-900">{{ $isEdit ? 'Edit Location' : 'New Location' }}</h2>
				<a href="{{ route('locations.index') }}" class="text-sm text-slate-600">Close</a>
			</div>
			<div class="p-6">
				<form action="{{ $isEdit ? route('locations.update', $location) : route('locations.store') }}" method="post" class="space-y-4">
					@if ($isEdit)
						@method('PUT')
					@endif
                    @include('locations._form', ['managerUsers' => $managerUsers ?? null])
				</form>
			</div>
		</div>
	</div>
</div>
@endsection
