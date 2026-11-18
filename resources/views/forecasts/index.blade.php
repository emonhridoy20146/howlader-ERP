@extends('layouts.app')
@section('title', 'Sales forecast')
@section('page-title', 'Sales forecast')
@section('content')
<div class="space-y-5">
	<div class="rounded-2xl border border-indigo-100 bg-gradient-to-br from-indigo-50/90 via-white to-white p-5 shadow-sm ring-1 ring-indigo-900/[0.04] sm:p-6">
		<p class="text-base font-semibold text-slate-900">Plan ahead with a gentle sales guess</p>
		<p class="mt-2 max-w-3xl text-sm leading-relaxed text-slate-600">
			Here you’ll see <strong>how many units we think each branch might sell</strong> in the month you pick. Items are grouped under <strong>their category</strong> so you can skim the list the way you’d walk the shop floor. The math is based on <strong>what you actually sold</strong> on invoices in the months just before—think of it as a hint, not a promise.
		</p>
		<ol class="mt-4 space-y-2.5 text-sm text-slate-700">
			<li class="flex gap-3">
				<span class="flex h-7 w-7 shrink-0 items-center justify-center rounded-full bg-indigo-600 text-xs font-bold text-white">1</span>
				<span><strong class="text-slate-900">Pick the month</strong> you’re curious about (we default to next month).</span>
			</li>
			<li class="flex gap-3">
				<span class="flex h-7 w-7 shrink-0 items-center justify-center rounded-full bg-indigo-600 text-xs font-bold text-white">2</span>
				<span><strong class="text-slate-900">Optionally narrow things down</strong> by branch, or search by product name, SKU, or category name.</span>
			</li>
			<li class="flex gap-3">
				<span class="flex h-7 w-7 shrink-0 items-center justify-center rounded-full bg-indigo-600 text-xs font-bold text-white">3</span>
				<span><strong class="text-slate-900">Refresh when you need to</strong> so the numbers catch up with new sales (if your role allows it).</span>
			</li>
		</ol>
		<details class="mt-4 rounded-xl border border-slate-200 bg-white/80 px-4 py-3 text-sm text-slate-600">
			<summary class="cursor-pointer font-medium text-indigo-800 outline-none hover:text-indigo-950">How do we get each number?</summary>
			<p class="mt-3 leading-relaxed">
				For <strong>{{ $forecastMonth->format('F Y') }}</strong>, we look at what you sold from
				<strong>{{ $salesHistoryStart->format('M j, Y') }}</strong> through <strong>{{ $salesHistoryEnd->format('M j, Y') }}</strong>
				—that’s the three full calendar months right before the month you’re viewing. We average how much moved per month, per product, per branch, and show that as <strong>Estimated units / month</strong>.
			</p>
		</details>
	</div>

	<x-filter-drawer>
		<form method="get" action="{{ route('forecasts.index') }}" class="flex flex-col gap-4">
			<p class="text-xs font-medium text-slate-500">Filters</p>
			<div class="grid gap-4 sm:grid-cols-2">
				<div>
					<label for="fc-month" class="block text-sm font-medium text-slate-700">Month to plan for</label>
					<p class="mt-0.5 text-xs text-slate-500">Which month’s estimates you want to see.</p>
					<input
						id="fc-month"
						type="month"
						name="month"
						value="{{ $filters['month'] }}"
						class="mt-2 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm shadow-sm focus:border-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-500/30"
					>
				</div>
				<div>
					<label for="fc-branch" class="block text-sm font-medium text-slate-700">Branch</label>
					<p class="mt-0.5 text-xs text-slate-500">Leave on “All” to see every branch you can access.</p>
					<select
						id="fc-branch"
						name="branch_id"
						class="mt-2 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm shadow-sm focus:border-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-500/30"
					>
						<option value="">All branches</option>
						@foreach ($filterBranches as $b)
							<option value="{{ $b->id }}" @selected((string) $filters['branch_id'] === (string) $b->id)>{{ $b->name }}</option>
						@endforeach
					</select>
				</div>
			</div>
			<div>
				<label for="fc-q" class="block text-sm font-medium text-slate-700">Search products or categories</label>
				<p class="mt-0.5 text-xs text-slate-500">Matches product name, SKU, or category name.</p>
				<input
					id="fc-q"
					type="search"
					name="q"
					value="{{ $filters['q'] }}"
					placeholder="e.g. cables, snacks, SKU…"
					class="mt-2 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm shadow-sm focus:border-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-500/30"
				>
			</div>
			<div class="flex flex-wrap items-center gap-2 border-t border-slate-100 pt-4">
				<button type="submit" class="rounded-lg bg-indigo-600 px-4 py-2.5 text-sm font-semibold text-white shadow-sm hover:bg-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2">Show results</button>
				<a href="{{ route('forecasts.index') }}" class="rounded-lg border border-slate-300 bg-white px-4 py-2.5 text-sm font-medium text-slate-800 hover:bg-slate-50">Clear filters</a>
			</div>
		</form>
	</x-filter-drawer>

	@can('create', \App\Models\DemandForecast::class)
		<form method="post" action="{{ route('forecasts.refresh') }}" class="flex flex-col gap-3 rounded-2xl border border-emerald-200/80 bg-emerald-50/40 p-5 shadow-sm sm:flex-row sm:items-center sm:justify-between">
			@csrf
			<input type="hidden" name="month" value="{{ $filters['month'] }}">
			@if ($filters['branch_id'] !== '')
				<input type="hidden" name="branch_id" value="{{ $filters['branch_id'] }}">
			@endif
			@if ($filters['q'] !== '')
				<input type="hidden" name="q" value="{{ $filters['q'] }}">
			@endif
			<div>
				<p class="text-sm font-semibold text-slate-900">Catch the numbers up with real sales</p>
				<p class="mt-1 max-w-xl text-sm text-slate-600">We’ll recompute estimates for <strong>{{ $forecastMonth->format('F Y') }}</strong> from your latest invoices. Handy after a busy week of selling, or if this page looks a little empty.</p>
			</div>
			<button type="submit" class="shrink-0 rounded-xl bg-emerald-600 px-5 py-2.5 text-sm font-semibold text-white shadow-sm hover:bg-emerald-500 focus:outline-none focus:ring-2 focus:ring-emerald-500 focus:ring-offset-2">Update forecast</button>
		</form>
	@endcan

	@if (session('status'))
		<p class="rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-medium text-emerald-900" role="status">{{ session('status') }}</p>
	@endif

	<x-index-table-shell
		:title="'What we’re guessing for '.$forecastMonth->format('F Y')"
		meta="Grouped by category; within each category, higher estimates come first. Amounts are units per month."
	>
		<table class="min-w-full text-sm whitespace-nowrap">
			<thead class="border-b border-slate-200 bg-slate-50 text-left text-xs font-semibold uppercase tracking-wider text-slate-700">
				<tr>
					<th class="px-4 py-3">Product</th>
					<th class="px-4 py-3">Branch</th>
					<th class="px-4 py-3 text-right">Est. units / month</th>
					<th class="px-4 py-3 text-left">Last updated</th>
				</tr>
			</thead>
			<tbody>
				@forelse ($forecastGroups as $group)
					<tr class="border-b border-slate-200 bg-slate-100/90">
						<td colspan="4" class="px-4 py-2.5">
							<p class="text-xs font-semibold uppercase tracking-wide text-slate-500">Category</p>
							<p class="mt-0.5 text-sm font-semibold text-slate-900">{{ $group->category?->name ?? 'Other (no category)' }}</p>
						</td>
					</tr>
					@foreach ($group->items as $row)
						<tr class="border-b border-slate-100 transition-colors hover:bg-slate-50/70 last:border-0">
							<td class="px-4 py-3 pl-6">
								<p class="font-medium text-slate-900">{{ $row->product?->name ?? '—' }}</p>
								@if ($row->product?->sku)
									<p class="mt-0.5 text-xs text-slate-500">SKU {{ $row->product->sku }}</p>
								@endif
							</td>
							<td class="px-4 py-3">
								<p class="font-medium text-slate-800">{{ $row->location?->name ?? '—' }}</p>
							</td>
							<td class="px-4 py-3 text-right tabular-nums text-base font-semibold text-slate-900">{{ number_format((float) $row->predicted_qty, 1) }}</td>
							<td class="px-4 py-3 text-slate-600">{{ $row->generated_at?->timezone(config('app.timezone'))->diffForHumans() ?? '—' }}</td>
						</tr>
					@endforeach
				@empty
					<tr>
						<td colspan="4" class="px-4 py-12">
							<div class="mx-auto max-w-md text-center">
								<p class="text-sm font-semibold text-slate-800">Nothing to show for this view yet</p>
								<p class="mt-2 text-sm leading-relaxed text-slate-600">
									Maybe the forecast hasn’t been built for <strong>{{ $forecastMonth->format('F Y') }}</strong>, or there wasn’t enough recent sales history for the branch or search you picked.
								</p>
								@can('create', \App\Models\DemandForecast::class)
									<p class="mt-3 text-sm text-slate-600">You can try <strong>Update forecast</strong> above, or loosen your filters.</p>
								@else
									<p class="mt-3 text-sm text-slate-600">Someone with access can refresh the forecast, or you can try another month or branch.</p>
								@endcan
							</div>
						</td>
					</tr>
				@endforelse
			</tbody>
		</table>
	</x-index-table-shell>

	<div>{{ $forecasts->links() }}</div>
</div>
@endsection
