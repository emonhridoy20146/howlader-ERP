@extends('layouts.app')

@section('title', 'Wallet · '.$wallet->name)
@section('page-title', 'Wallet')

@section('content')
@php
	$bgClass = match ($wallet->kind) {
		'cash' => 'bg-gradient-to-br from-emerald-500 to-teal-700',
		'bank' => 'bg-gradient-to-br from-slate-700 to-slate-900',
		'card_terminal' => 'bg-gradient-to-br from-neutral-700 to-stone-900',
		'mobile_banking' => 'bg-gradient-to-br from-purple-500 to-fuchsia-700',
		'digital' => 'bg-gradient-to-br from-sky-500 to-blue-700',
		default => 'bg-gradient-to-br from-slate-500 to-slate-700',
	};
	$icon = match ($wallet->kind) {
		'cash' => '<path stroke-linecap="round" stroke-linejoin="round" d="M2.25 18.75a60.07 60.07 0 0 1 15.797 2.101c.727.198 1.453-.342 1.453-1.096V5.988c0-.754-.726-1.294-1.453-1.096V5.988a60.224 60.224 0 0 1-15.797 2.101c-.727.198-1.453-.342-1.453-1.096v11.752c0 .754.726 1.294 1.453 1.096ZM12 15.75a3.75 3.75 0 1 1 0-7.5 3.75 3.75 0 0 1 0 7.5Z" />',
		'bank' => '<path stroke-linecap="round" stroke-linejoin="round" d="M12 21v-8.25M15.75 21v-8.25M8.25 21v-8.25M3 9l9-6 9 6m-1.5 12V10.332A48.36 48.36 0 0 0 12 9.75c-2.551 0-5.056.2-7.5.582V21M3 21h18M12 6.75h.008v.008H12V6.75Z" />',
		'card_terminal' => '<path stroke-linecap="round" stroke-linejoin="round" d="M2.25 8.25h19.5M2.25 9h19.5m-16.5 5.25h6m-6 2.25h3m-3.75 3h15a2.25 2.25 0 0 0 2.25-2.25V6.75A2.25 2.25 0 0 0 19.5 4.5h-15a2.25 2.25 0 0 0-2.25 2.25v10.5A2.25 2.25 0 0 0 4.5 19.5Z" />',
		'mobile_banking' => '<path stroke-linecap="round" stroke-linejoin="round" d="M10.5 1.5H8.25A2.25 2.25 0 0 0 6 3.75v16.5a2.25 2.25 0 0 0 2.25 2.25h7.5A2.25 2.25 0 0 0 18 20.25V3.75a2.25 2.25 0 0 0-2.25-2.25H13.5m-3 0V3h3V1.5m-3 0h3m-3 18.75h3" />',
		'digital' => '<path stroke-linecap="round" stroke-linejoin="round" d="M12 21a9.004 9.004 0 0 0 8.716-6.747M12 21a9.004 9.004 0 0 1-8.716-6.747M12 21c2.485 0 4.5-4.03 4.5-9S14.485 3 12 3m0 18c-2.485 0-4.5-4.03-4.5-9S9.515 3 12 3m0 0a8.997 8.997 0 0 1 7.843 4.582M12 3a8.997 8.997 0 0 0-7.843 4.582m15.686 0A11.953 11.953 0 0 1 12 10.5c-2.998 0-5.74-1.1-7.843-2.918m15.686 0A8.959 8.959 0 0 1 21 12c0 .778-.099 1.533-.284 2.253m0 0A17.919 17.919 0 0 1 12 16.5c-3.162 0-6.133-.815-8.716-2.247m0 0A9.015 9.015 0 0 1 3 12c0-1.605.42-3.113 1.157-4.418" />',
		default => '<path stroke-linecap="round" stroke-linejoin="round" d="M21 12a2.25 2.25 0 0 0-2.25-2.25H15a3 3 0 1 1-6 0H5.25A2.25 2.25 0 0 0 3 12m18 0v6a2.25 2.25 0 0 1-2.25 2.25H5.25A2.25 2.25 0 0 1 3 18v-6m18 0V9M3 12V9m18 0a2.25 2.25 0 0 0-2.25-2.25H5.25A2.25 2.25 0 0 0 3 9m18 0V6a2.25 2.25 0 0 0-2.25-2.25H5.25A2.25 2.25 0 0 0 3 6v3" />',
	};
	$tot = $transactionTotals ?? ['line_count' => 0, 'total_debit' => 0.0, 'total_credit' => 0.0];
	$periodNet = round((float) $tot['total_debit'] - (float) $tot['total_credit'], 2);
	$fmtMoney = static fn (?float $n, bool $dashZero = true): string => ($n !== null && abs($n) >= 0.005) ? number_format($n, 2) : ($dashZero ? '—' : number_format(0.0, 2));
@endphp

<style>
	@media print {
		aside#app-sidebar,
		nav[aria-label="Quick actions"],
		.wallet-show-no-print,
		body > div.lg\:hidden.fixed {
			display: none !important;
		}
		body {
			background: #fff !important;
		}
		main {
			margin-left: 0 !important;
			padding-top: 0 !important;
		}
		.wallet-transaction-report {
			box-shadow: none !important;
		}
	}
</style>

<div class="space-y-6">
	@if (session('status'))
		<div class="rounded-lg bg-emerald-50 p-3 text-sm text-emerald-700">{{ session('status') }}</div>
	@endif

	{{-- Header --}}
	<section class="overflow-hidden rounded-2xl bg-white shadow-sm ring-1 ring-slate-200">
		<div class="relative {{ $bgClass }} px-5 py-6 text-white sm:px-6 sm:py-8">
			<div class="absolute -right-6 -top-6 opacity-10">
				<svg class="h-36 w-36" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1">{!! $icon !!}</svg>
			</div>
			<div class="relative z-10 flex flex-col gap-6 lg:flex-row lg:items-start lg:justify-between">
				<div class="min-w-0 flex-1">
					<div class="flex flex-wrap items-center gap-2">
						<svg class="h-6 w-6 opacity-90" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">{!! $icon !!}</svg>
						<span class="rounded-md bg-white/20 px-2 py-0.5 text-[10px] font-bold uppercase tracking-widest backdrop-blur-sm">{{ $kindLabel }}</span>
						@if (! $wallet->is_active)
							<span class="rounded-md bg-rose-500/90 px-2 py-0.5 text-[10px] font-bold uppercase tracking-widest">Inactive</span>
						@endif
					</div>
					<h2 class="mt-3 text-2xl font-bold tracking-tight sm:text-3xl">{{ $wallet->name }}</h2>
					<p class="mt-1 font-mono text-sm text-white/85">{{ $wallet->code }}</p>
					@if ($wallet->bank_name || $wallet->account_number)
						<p class="mt-3 text-sm text-white/90">
							@if ($wallet->bank_name)
								<span class="font-medium">{{ $wallet->bank_name }}</span>
							@endif
							@if ($wallet->account_number)
								<span class="font-mono">{{ $wallet->bank_name ? ' · ' : '' }}{{ $wallet->account_number }}</span>
							@endif
						</p>
					@endif
				</div>
				<div class="shrink-0 rounded-xl bg-white/15 px-5 py-4 text-right backdrop-blur-md ring-1 ring-white/20">
					<p class="text-[10px] font-semibold uppercase tracking-[0.2em] text-white/80">Ledger balance</p>
					<p class="mt-1 font-mono text-3xl font-bold tabular-nums tracking-tight">{{ number_format($balance, 2) }}</p>
					<p class="mt-2 text-xs text-white/75">Debit − credit on this wallet’s ledger account</p>
				</div>
			</div>
		</div>

		<div class="wallet-show-no-print border-t border-slate-100 p-4 sm:p-5">
			<div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
				<div class="rounded-xl border border-slate-100 bg-slate-50/80 p-3">
					<p class="text-xs font-medium uppercase tracking-wide text-slate-500">Ledger account</p>
					@if ($wallet->ledgerAccount)
						<p class="mt-1 font-mono text-sm font-semibold text-slate-900">{{ $wallet->ledgerAccount->code }}</p>
						<p class="text-sm text-slate-600">{{ $wallet->ledgerAccount->name }}</p>
					@else
						<p class="mt-1 text-slate-500">—</p>
					@endif
				</div>
				<div class="rounded-xl border border-slate-100 bg-slate-50/80 p-3">
					<p class="text-xs font-medium uppercase tracking-wide text-slate-500">Branches</p>
					@if ($wallet->locations->isEmpty())
						<p class="mt-1"><span class="rounded-md bg-slate-200/80 px-2 py-0.5 text-xs font-medium text-slate-800">All branches</span></p>
					@else
						<ul class="mt-1 max-h-24 list-inside list-disc overflow-y-auto text-sm text-slate-700">
							@foreach ($wallet->locations->sortBy('code') as $loc)
								<li>{{ $loc->name }}</li>
							@endforeach
						</ul>
					@endif
				</div>
				@if ($wallet->notes)
					<div class="rounded-xl border border-slate-100 bg-slate-50/80 p-3 sm:col-span-2">
						<p class="text-xs font-medium uppercase tracking-wide text-slate-500">Notes</p>
						<p class="mt-1 whitespace-pre-wrap text-sm text-slate-700">{{ $wallet->notes }}</p>
					</div>
				@endif
			</div>

			<div class="mt-4 flex flex-wrap gap-2">
				<a href="{{ route('wallets.index') }}" class="rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm text-slate-700 shadow-sm hover:bg-slate-50">Back to list</a>
				@can('update', $wallet)
					<a href="{{ route('wallets.edit', $wallet) }}" class="rounded-lg border border-blue-200 bg-blue-50 px-3 py-2 text-sm font-medium text-blue-700 hover:bg-blue-100">Edit</a>
				@endcan
				@can('viewAny', \App\Models\JournalEntry::class)
					<a href="{{ route('journal-entries.index', ['wallet_id' => $wallet->id]) }}" class="rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm text-slate-700 shadow-sm hover:bg-slate-50">Journal (filtered)</a>
				@endcan
				@can('create', \App\Models\JournalEntry::class)
					<a href="{{ route('journal-entries.create', ['wallet_id' => $wallet->id]) }}" class="rounded-lg bg-slate-900 px-3 py-2 text-sm font-medium text-white shadow-sm hover:bg-slate-800">Post journal</a>
				@endcan
				<button type="button" onclick="window.print()" class="rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm text-slate-700 shadow-sm hover:bg-slate-50">Print page</button>
			</div>
		</div>
	</section>

	{{-- Transaction report --}}
	@if ($transactionLines !== null)
		<section class="wallet-transaction-report rounded-2xl bg-white p-4 shadow-sm ring-1 ring-slate-200 sm:p-5">
			<div class="flex flex-col gap-2 sm:flex-row sm:flex-wrap sm:items-end sm:justify-between">
				<div>
					<h3 class="text-lg font-semibold text-slate-900">Transaction report</h3>
					<p class="text-xs text-slate-500">Journal lines posted to this wallet (same branch rules as the journal list). Use filters to focus a period or source.</p>
				</div>
			</div>

			<div class="wallet-show-no-print mt-3">
				<x-filter-drawer>
				<form method="get" action="{{ route('wallets.show', $wallet) }}" class="flex flex-col gap-4">
					<div>
						<label class="text-sm font-medium text-slate-700">Branch</label>
						<select name="location_id" class="mt-1 block w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
							<option value="">All branches (or context)</option>
							@foreach ($locations as $location)
								<option value="{{ $location->id }}" @selected((string) ($filters['location_id'] ?? '') === (string) $location->id)>{{ $location->code }} — {{ $location->name }}</option>
							@endforeach
						</select>
					</div>
					<div>
						<label class="text-sm font-medium text-slate-700">Source</label>
						<select name="source" class="mt-1 block w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
							<option value="">Any source</option>
							@foreach ($sources as $value => $label)
								<option value="{{ $value }}" @selected(($filters['source'] ?? '') === $value)>{{ $label }}</option>
							@endforeach
						</select>
					</div>
					<div>
						<label class="text-sm font-medium text-slate-700">From</label>
						<input type="date" name="date_from" value="{{ $filters['date_from'] ?? '' }}" class="mt-1 block w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
						@error('date_from')
							<p class="mt-1 text-xs text-red-600">{{ $message }}</p>
						@enderror
					</div>
					<div>
						<label class="text-sm font-medium text-slate-700">To</label>
						<input type="date" name="date_to" value="{{ $filters['date_to'] ?? '' }}" class="mt-1 block w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
						@error('date_to')
							<p class="mt-1 text-xs text-red-600">{{ $message }}</p>
						@enderror
					</div>
					<x-index-filter-actions :reset-url="route('wallets.show', $wallet)" />
				</form>
				</x-filter-drawer>
			</div>

			@php
				$openingBalance = $openingBalance ?? null;
				$closingBalance = $closingBalance ?? null;
			@endphp

			<div class="mt-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-4 xl:grid-cols-6">
				@if ($openingBalance !== null)
					<div class="rounded-xl border border-slate-200 bg-slate-50 p-3">
						<p class="text-xs font-medium uppercase tracking-wide text-slate-500">Opening balance</p>
						<p class="mt-1 font-mono text-xl font-semibold tabular-nums text-slate-900">{{ number_format($openingBalance, 2) }}</p>
						<p class="mt-1 text-[11px] leading-snug text-slate-500">Before {{ $filters['date_from'] }} · all sources · same branch scope</p>
					</div>
				@endif
				<div class="rounded-xl border border-slate-200 bg-slate-50 p-3">
					<p class="text-xs font-medium uppercase tracking-wide text-slate-500">Period debits</p>
					<p class="mt-1 font-mono text-xl font-semibold tabular-nums text-slate-900">{{ number_format($tot['total_debit'], 2) }}</p>
				</div>
				<div class="rounded-xl border border-slate-200 bg-slate-50 p-3">
					<p class="text-xs font-medium uppercase tracking-wide text-slate-500">Period credits</p>
					<p class="mt-1 font-mono text-xl font-semibold tabular-nums text-slate-900">{{ number_format($tot['total_credit'], 2) }}</p>
				</div>
				<div class="rounded-xl border border-slate-200 bg-slate-50 p-3">
					<p class="text-xs font-medium uppercase tracking-wide text-slate-500">Period net (Dr − Cr)</p>
					<p class="mt-1 font-mono text-xl font-semibold tabular-nums {{ $periodNet >= 0 ? 'text-emerald-800' : 'text-rose-800' }}">{{ number_format($periodNet, 2) }}</p>
				</div>
				@if ($closingBalance !== null)
					<div class="rounded-xl border border-slate-200 bg-indigo-50/60 p-3 sm:col-span-2 lg:col-span-1 xl:col-span-2">
						<p class="text-xs font-medium uppercase tracking-wide text-slate-500">Closing balance <span class="font-normal normal-case text-slate-400">(opening + filtered period)</span></p>
						<p class="mt-1 font-mono text-xl font-semibold tabular-nums text-slate-900">{{ number_format($closingBalance, 2) }}</p>
						@if (($filters['source'] ?? '') !== '')
							<p class="mt-1 text-[11px] text-amber-800">A source filter is on: closing uses only matching period lines; it may differ from full ledger balance.</p>
						@endif
					</div>
				@endif
				<div class="rounded-xl border border-slate-200 bg-white p-3 sm:col-span-2 lg:col-span-1">
					<p class="text-xs font-medium uppercase tracking-wide text-slate-500">Lines (filtered)</p>
					<p class="mt-1 text-2xl font-semibold tabular-nums text-slate-900">{{ number_format($tot['line_count'], 0) }}</p>
				</div>
			</div>

			<div class="mt-4 overflow-x-auto rounded-xl border border-slate-200">
				<table class="min-w-[56rem] w-full border-collapse text-sm">
					<thead>
						<tr class="border-b border-slate-200 bg-slate-100 text-left text-xs font-semibold uppercase tracking-wider text-slate-700">
							<th class="px-3 py-3">Date</th>
							<th class="px-3 py-3">Document</th>
							<th class="px-3 py-3">Branch</th>
							<th class="px-3 py-3">Source</th>
							<th class="min-w-[12rem] px-3 py-3">Description</th>
							<th class="min-w-[8rem] px-3 py-3">Memo</th>
							<th class="px-3 py-3 text-right">Debit</th>
							<th class="px-3 py-3 text-right">Credit</th>
							<th class="px-3 py-3 text-right">Net</th>
							<th class="wallet-show-no-print px-3 py-3 text-right">Journal</th>
						</tr>
					</thead>
					<tbody class="text-slate-900">
						@forelse ($transactionLines as $line)
							@php
								$entry = $line->journalEntry;
								$dr = (float) $line->debit;
								$cr = (float) $line->credit;
								$net = round($dr - $cr, 2);
							@endphp
							<tr class="border-b border-slate-100 transition-colors hover:bg-slate-50/80 last:border-0">
								<td class="whitespace-nowrap px-3 py-2.5 align-top text-slate-700">{{ $entry?->entry_date?->format('Y-m-d') ?? '—' }}</td>
								<td class="px-3 py-2.5 align-top font-mono text-xs">{{ $entry?->document_no ?? '—' }}</td>
								<td class="px-3 py-2.5 align-top text-slate-700">{{ $entry?->branch?->name ?? '—' }}</td>
								<td class="max-w-[10rem] truncate px-3 py-2.5 align-top text-slate-600" title="{{ $entry ? ($sources[$entry->source] ?? $entry->source) : '' }}">{{ $entry ? ($sources[$entry->source] ?? $entry->source) : '—' }}</td>
								<td class="px-3 py-2.5 align-top text-slate-700">{{ $entry?->description ?? '—' }}</td>
								<td class="px-3 py-2.5 align-top text-slate-600">{{ $line->line_memo !== null && $line->line_memo !== '' ? $line->line_memo : '—' }}</td>
								<td class="px-3 py-2.5 text-right align-top font-mono tabular-nums">{{ $fmtMoney($dr > 0 ? $dr : null) }}</td>
								<td class="px-3 py-2.5 text-right align-top font-mono tabular-nums">{{ $fmtMoney($cr > 0 ? $cr : null) }}</td>
								<td class="px-3 py-2.5 text-right align-top font-mono tabular-nums {{ $net >= 0 ? 'text-emerald-800' : 'text-rose-800' }}">{{ number_format($net, 2) }}</td>
								<td class="wallet-show-no-print px-3 py-2.5 text-right align-top">
									@if ($entry)
										@can('view', $entry)
											<a href="{{ route('journal-entries.show', $entry) }}" class="text-xs font-medium text-indigo-700 underline decoration-indigo-200 hover:text-indigo-900">View</a>
										@else
											<span class="text-xs text-slate-400">—</span>
										@endcan
									@else
										<span class="text-xs text-slate-400">—</span>
									@endif
								</td>
							</tr>
						@empty
							<tr>
								<td colspan="10" class="px-3 py-10 text-center text-slate-500">No transactions match the current filters.</td>
							</tr>
						@endforelse
					</tbody>
				</table>
			</div>

			<div class="wallet-show-no-print mt-4">
				{{ $transactionLines->links() }}
			</div>
		</section>
	@else
		<section class="rounded-2xl border border-amber-200 bg-amber-50/80 p-4 text-sm text-amber-950">
			This wallet has no linked ledger account; transaction history is unavailable.
		</section>
	@endif

	{{-- Payment method links --}}
	<section class="rounded-2xl bg-white p-4 shadow-sm ring-1 ring-slate-200 sm:p-5">
		<div class="flex flex-wrap items-end justify-between gap-2">
			<div>
				<h3 class="text-base font-semibold text-slate-900">Payment method links</h3>
				<p class="mt-1 text-xs text-slate-500">When customers pay with these methods at the listed branch, postings can use this wallet.</p>
			</div>
			@can('viewAny', \App\Models\PaymentMethodWalletMap::class)
				<a href="{{ route('payment-method-wallet-maps.index') }}" class="text-sm font-medium text-indigo-700 underline decoration-indigo-200 hover:text-indigo-900">Manage mappings</a>
			@endcan
		</div>
		<div class="mt-3 overflow-x-auto rounded-xl border border-slate-200">
			<table class="min-w-full text-sm whitespace-nowrap">
				<thead class="border-b border-slate-200 bg-slate-50 text-left text-xs font-semibold uppercase tracking-wider text-slate-700">
					<tr>
						<th class="px-3 py-2">Branch</th>
						<th class="px-3 py-2">Payment method</th>
						<th class="px-3 py-2 text-right">Action</th>
					</tr>
				</thead>
				<tbody>
					@forelse ($paymentMaps as $map)
						<tr class="border-b border-slate-100 transition-colors hover:bg-slate-50/70 last:border-0">
							<td class="px-3 py-2">
								@if ($map->location)
									<span class="font-mono text-xs">{{ $map->location->code }}</span>
									<span class="text-slate-600"> — {{ $map->location->name }}</span>
								@else
									<span class="text-slate-500">—</span>
								@endif
							</td>
							<td class="px-3 py-2 font-medium text-slate-900">{{ $map->paymentMethod?->name ?? '—' }}</td>
							<td class="px-3 py-2 text-right">
								@can('update', $map)
									<a href="{{ route('payment-method-wallet-maps.index', ['drawer' => 'edit', 'edit' => $map->id]) }}" class="text-xs text-blue-600 hover:underline">Edit map</a>
								@else
									<span class="text-slate-400">—</span>
								@endcan
							</td>
						</tr>
					@empty
						<tr>
							<td colspan="3" class="px-3 py-8 text-center text-slate-500">No payment methods linked to this wallet.</td>
						</tr>
					@endforelse
				</tbody>
			</table>
		</div>
	</section>
</div>
@endsection
