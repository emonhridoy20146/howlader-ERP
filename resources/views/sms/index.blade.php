@extends('layouts.app')
@section('title', 'SMS')
@section('page-title', 'SMS')
@section('content')

@include('sms.partials.header')

@if (! $configured || ! $enabled || ! $senderIds['masking'] && ! $senderIds['non_masking'])
	<div class="mb-4 rounded-lg border border-amber-200 bg-amber-50 p-3 text-sm text-amber-800">
		@if (! $enabled)
			<p><strong>SMS sending is switched off</strong> (<code>SMS_ENABLED=false</code>). Messages will be logged as failed and not delivered.</p>
		@endif
		@if (! $configured)
			<p><strong>BulkSMSBD is not configured.</strong> Add <code>BULKSMSBD_API_KEY</code> to <code>.env</code>.</p>
		@endif
		@if (! $senderIds['masking'] && ! $senderIds['non_masking'])
			<p>Add <code>BULKSMSBD_SENDER_ID_NON_MASKING</code> and/or <code>BULKSMSBD_SENDER_ID_MASKING</code> to <code>.env</code>.</p>
		@endif
	</div>
@endif

{{-- ===================== Stats ===================== --}}
<div class="mb-5 grid gap-3 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-6" x-data="smsBalance(@js($balance))">
	<x-status-card label="Balance (BDT)">
		<span class="tabular-nums" x-text="balance.ok ? Number(balance.balance).toLocaleString(undefined, {maximumFractionDigits: 2}) : '—'"></span>
		<button type="button" @click="refresh()" class="ml-2 align-middle text-xs font-medium text-sky-700 hover:underline" :disabled="loading" x-text="loading ? '…' : 'Refresh'"></button>
		<span class="block text-xs font-normal text-rose-600" x-show="! balance.ok" x-text="balance.message"></span>
	</x-status-card>
	<x-status-card label="SMS remaining (est.)">
		<span class="block text-base font-semibold">
			Non-masking: <span class="tabular-nums">{{ $estimate['non_masking'] !== null ? number_format($estimate['non_masking']) : '—' }}</span>
		</span>
		<span class="block text-base font-semibold">
			Masking: <span class="tabular-nums">{{ $estimate['masking'] !== null ? number_format($estimate['masking']) : '—' }}</span>
		</span>
		@if ($estimate['masking'] === null && $estimate['non_masking'] === null)
			<span class="block text-xs font-normal text-slate-500">Set BULKSMSBD_RATE_* in .env to estimate</span>
		@endif
	</x-status-card>
	<x-status-card label="Sent today">
		<span class="tabular-nums">{{ number_format($stats['today']) }}</span>
		<span class="block text-xs font-normal text-slate-500">{{ number_format($stats['today_parts']) }} SMS parts</span>
	</x-status-card>
	<x-status-card label="Sent this month">
		<span class="tabular-nums">{{ number_format($stats['month']) }}</span>
		<span class="block text-xs font-normal text-slate-500">{{ number_format($stats['month_parts']) }} SMS parts</span>
	</x-status-card>
	<x-status-card label="Total sent">
		<span class="tabular-nums">{{ number_format($stats['total']) }}</span>
		<span class="block text-xs font-normal text-slate-500">{{ number_format($stats['total_parts']) }} SMS parts</span>
	</x-status-card>
	<x-status-card label="Failed / pending">
		<a href="{{ route('sms.history', ['status' => 'failed']) }}" class="tabular-nums text-rose-700 hover:underline">{{ number_format($stats['failed']) }}</a>
		<span class="text-slate-400">/</span>
		<span class="tabular-nums text-amber-700">{{ number_format($stats['pending']) }}</span>
	</x-status-card>
</div>

{{-- ===================== Composer ===================== --}}
<div x-data="smsComposer(window.__smsConfig)" x-cloak>
	<div class="mb-4 inline-flex rounded-xl bg-slate-100 p-1 text-sm font-medium">
		<button type="button" @click="mode = 'bulk'" :class="mode === 'bulk' ? 'bg-white shadow text-slate-900' : 'text-slate-600'" class="rounded-lg px-4 py-1.5">Bulk — select customers</button>
		<button type="button" @click="mode = 'single'" :class="mode === 'single' ? 'bg-white shadow text-slate-900' : 'text-slate-600'" class="rounded-lg px-4 py-1.5">Single / custom numbers</button>
	</div>

	<div class="grid gap-5 xl:grid-cols-[minmax(0,1fr)_26rem]">
		{{-- ---------- Left column ---------- --}}
		<div class="min-w-0 space-y-5">

			{{-- BULK: scenario + filters + customer list --}}
			<template x-if="mode === 'bulk'">
				<div class="space-y-5">
					<div class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
						<h2 class="mb-3 text-sm font-semibold text-slate-900">1. Who should receive it?</h2>
						<div class="grid gap-2 sm:grid-cols-2 lg:grid-cols-4">
							<template x-for="(def, key) in audiences" :key="key">
								<button type="button" @click="chooseAudience(key)"
									:class="audience === key ? 'border-sky-500 bg-sky-50 ring-1 ring-sky-500' : 'border-slate-200 hover:border-slate-300 hover:bg-slate-50'"
									class="rounded-xl border px-3 py-2.5 text-left transition">
									<span class="block text-sm font-semibold text-slate-900" x-text="def.label"></span>
									<span class="block text-xs text-slate-500" x-text="def.label_bn"></span>
								</button>
							</template>
						</div>
						<p class="mt-3 text-xs text-slate-500" x-text="audiences[audience]?.hint"></p>

						<div class="mt-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
							<template x-if="audiences[audience]?.dates">
								<label class="text-xs font-medium text-slate-600">
									<span x-text="(audiences[audience].date_label || 'Date') + ' from'"></span>
									<input type="date" x-model="filters.from" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
								</label>
							</template>
							<template x-if="audiences[audience]?.dates">
								<label class="text-xs font-medium text-slate-600">
									<span x-text="(audiences[audience].date_label || 'Date') + ' to'"></span>
									<input type="date" x-model="filters.to" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
								</label>
							</template>
							<label class="text-xs font-medium text-slate-600">Branch
								<select x-model="filters.branch_id" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
									<option value="">All branches</option>
									<template x-for="b in branches" :key="b.id">
										<option :value="b.id" x-text="b.name"></option>
									</template>
								</select>
							</label>
							<template x-if="hasExtra('sale_type')">
								<label class="text-xs font-medium text-slate-600">Sale type
									<select x-model="filters.sale_type" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
										<option value="">Any</option>
										<option value="cash">Cash</option>
										<option value="credit">Credit</option>
										<option value="installment">Installment</option>
									</select>
								</label>
							</template>
							<template x-if="hasExtra('min_overdue_days')">
								<label class="text-xs font-medium text-slate-600">Overdue at least (days)
									<input type="number" min="1" x-model="filters.min_overdue_days" placeholder="1" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
								</label>
							</template>
							<template x-if="hasExtra('min_due')">
								<label class="text-xs font-medium text-slate-600">Minimum due (BDT)
									<input type="number" min="0" step="any" x-model="filters.min_due" placeholder="0" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
								</label>
							</template>
							<label class="text-xs font-medium text-slate-600">Customer name / phone / code
								<input type="text" x-model="filters.q" @keydown.enter.prevent="load()" placeholder="optional" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
							</label>
						</div>

						<div class="mt-4 flex flex-wrap items-center gap-2">
							<template x-if="audiences[audience]?.dates">
								<div class="flex flex-wrap gap-1 text-xs">
									<button type="button" @click="quickRange(0, 0)" class="rounded-md border border-slate-200 px-2 py-1 hover:bg-slate-50">Today</button>
									<button type="button" @click="quickRange(1, 1)" class="rounded-md border border-slate-200 px-2 py-1 hover:bg-slate-50">Tomorrow</button>
									<button type="button" @click="quickRange(0, 3)" class="rounded-md border border-slate-200 px-2 py-1 hover:bg-slate-50">Next 3 days</button>
									<button type="button" @click="quickRange(0, 7)" class="rounded-md border border-slate-200 px-2 py-1 hover:bg-slate-50">Next 7 days</button>
									<button type="button" @click="quickRange(-7, 0)" class="rounded-md border border-slate-200 px-2 py-1 hover:bg-slate-50">Last 7 days</button>
									<button type="button" @click="quickRange(-30, 0)" class="rounded-md border border-slate-200 px-2 py-1 hover:bg-slate-50">Last 30 days</button>
									<button type="button" @click="filters.from = ''; filters.to = ''" class="rounded-md border border-slate-200 px-2 py-1 hover:bg-slate-50">Any date</button>
								</div>
							</template>
							<button type="button" @click="load()" :disabled="loading" class="ml-auto rounded-lg bg-slate-900 px-4 py-2 text-sm font-semibold text-white hover:bg-slate-800 disabled:opacity-50">
								<span x-text="loading ? 'Loading…' : 'Load customers'"></span>
							</button>
						</div>
					</div>

					<div class="rounded-2xl border border-slate-200 bg-white shadow-sm" x-show="loaded">
						<div class="flex flex-wrap items-center gap-3 border-b border-slate-100 px-4 py-3">
							<h2 class="text-sm font-semibold text-slate-900">2. Choose customers</h2>
							<span class="text-xs text-slate-500">
								<span x-text="rows.length"></span> found ·
								<strong class="text-slate-900" x-text="selectedCount"></strong> selected
								<template x-if="skipped > 0"><span> · <span x-text="skipped"></span> skipped (no valid mobile)</span></template>
							</span>
							<div class="ml-auto flex flex-wrap items-center gap-2">
								<input type="text" x-model="tableSearch" placeholder="Filter list…" class="rounded-lg border border-slate-300 px-2.5 py-1.5 text-xs">
								<button type="button" @click="selectAll(true)" class="rounded-md border border-slate-200 px-2 py-1 text-xs hover:bg-slate-50">Select all</button>
								<button type="button" @click="selectAll(false)" class="rounded-md border border-slate-200 px-2 py-1 text-xs hover:bg-slate-50">Select none</button>
							</div>
						</div>
						<div class="overflow-x-auto">
							<table class="min-w-full whitespace-nowrap text-sm">
								<thead class="bg-slate-50 text-left text-xs font-semibold uppercase tracking-wider text-slate-600">
									<tr>
										<th class="w-10 px-4 py-2"><input type="checkbox" :checked="pageAllSelected" @change="togglePage($event.target.checked)" class="rounded border-slate-300"></th>
										<th class="px-4 py-2">Customer</th>
										<th class="px-4 py-2">Mobile</th>
										<th class="px-4 py-2">Branch</th>
										<th class="px-4 py-2">Details</th>
										<th class="px-4 py-2">Preview</th>
									</tr>
								</thead>
								<tbody>
									<template x-for="row in pageRows" :key="row.customer_id">
										<tr class="border-t border-slate-100 hover:bg-slate-50/70" :class="selected[row.customer_id] ? '' : 'opacity-60'">
											<td class="px-4 py-2"><input type="checkbox" x-model="selected[row.customer_id]" class="rounded border-slate-300"></td>
											<td class="px-4 py-2">
												<div class="font-medium text-slate-900" x-text="row.name"></div>
												<div class="text-xs text-slate-500" x-text="row.code"></div>
											</td>
											<td class="px-4 py-2 font-mono text-xs" x-text="row.phone"></td>
											<td class="px-4 py-2 text-xs text-slate-600" x-text="row.branch"></td>
											<td class="px-4 py-2 text-xs text-slate-600" x-text="row.detail"></td>
											<td class="px-4 py-2">
												<button type="button" @click="previewRow = row" class="text-xs font-medium text-sky-700 hover:underline">View</button>
											</td>
										</tr>
									</template>
									<tr x-show="filteredRows.length === 0">
										<td colspan="6" class="px-4 py-8 text-center text-sm text-slate-500">No customers match.</td>
									</tr>
								</tbody>
							</table>
						</div>
						<div class="flex items-center justify-between border-t border-slate-100 px-4 py-2 text-xs text-slate-600" x-show="pages > 1">
							<button type="button" @click="page = Math.max(1, page - 1)" :disabled="page === 1" class="rounded-md border px-2 py-1 disabled:opacity-40">Prev</button>
							<span>Page <span x-text="page"></span> of <span x-text="pages"></span></span>
							<button type="button" @click="page = Math.min(pages, page + 1)" :disabled="page === pages" class="rounded-md border px-2 py-1 disabled:opacity-40">Next</button>
						</div>
					</div>
				</div>
			</template>

			{{-- SINGLE: customer search + numbers --}}
			<template x-if="mode === 'single'">
				<div class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
					<h2 class="mb-3 text-sm font-semibold text-slate-900">Recipient</h2>
					<label class="block text-xs font-medium text-slate-600">Find a customer (optional)
						<input type="text" x-model="customerQuery" @input.debounce.300ms="searchCustomers()" placeholder="Name, phone or code" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
					</label>
					<div class="mt-2 max-h-56 overflow-y-auto rounded-lg border border-slate-100" x-show="customerResults.length">
						<template x-for="c in customerResults" :key="c.id">
							<button type="button" @click="pickCustomer(c)" class="flex w-full items-center justify-between border-b border-slate-100 px-3 py-2 text-left text-sm last:border-0 hover:bg-slate-50">
								<span><span class="font-medium" x-text="c.name"></span> <span class="text-xs text-slate-500" x-text="c.code"></span></span>
								<span class="font-mono text-xs text-slate-500" x-text="c.phones.join(', ') || 'no valid mobile'"></span>
							</button>
						</template>
					</div>
					<template x-if="singleCustomer">
						<div class="mt-3 flex items-center justify-between rounded-lg bg-sky-50 px-3 py-2 text-sm">
							<span>Customer: <strong x-text="singleCustomer.name"></strong> · total due <span x-text="singleCustomer.total_due"></span></span>
							<button type="button" @click="singleCustomer = null" class="text-xs text-rose-700 hover:underline">Clear</button>
						</div>
					</template>
					<label class="mt-4 block text-xs font-medium text-slate-600">Mobile number(s) — separate with comma or new line
						<textarea x-model="singlePhones" rows="3" placeholder="01XXXXXXXXX, 01YYYYYYYYY" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 font-mono text-sm"></textarea>
					</label>
					<p class="mt-1 text-xs text-slate-500">
						<span x-text="parsedPhones.valid.length"></span> valid number(s)
						<template x-if="parsedPhones.invalid.length"><span class="text-rose-600"> · invalid: <span x-text="parsedPhones.invalid.join(', ')"></span></span></template>
					</p>
					<p class="mt-2 text-xs text-slate-500">With a customer selected you can use {name}, {customer_code}, {total_due} and {company}; without one only {company} is filled.</p>
				</div>
			</template>

			{{-- Recent batches --}}
			<x-index-table-shell title="Recent sends" :meta="'Last '.$recentBatches->count()">
				<table class="min-w-full whitespace-nowrap text-sm">
					<thead class="bg-slate-50 text-left text-xs font-semibold uppercase tracking-wider text-slate-600">
						<tr>
							<th class="px-4 py-2">When</th>
							<th class="px-4 py-2">Type</th>
							<th class="px-4 py-2">Audience</th>
							<th class="px-4 py-2 text-right">Sent / Failed / Total</th>
							<th class="px-4 py-2">Status</th>
							<th class="px-4 py-2">By</th>
						</tr>
					</thead>
					<tbody>
						@forelse ($recentBatches as $b)
							<tr class="border-t border-slate-100 hover:bg-slate-50/70">
								<td class="px-4 py-2"><a href="{{ route('sms.batches.show', $b) }}" class="font-medium text-blue-700 hover:underline">{{ $b->created_at->format('d M Y, h:i A') }}</a></td>
								<td class="px-4 py-2 capitalize">{{ $b->kind }} · {{ str_replace('_', '-', $b->sender_type) }}</td>
								<td class="px-4 py-2">{{ $audiences[$b->audience]['label'] ?? ucfirst(str_replace('_', ' ', (string) $b->audience)) }}</td>
								<td class="px-4 py-2 text-right tabular-nums"><span class="text-emerald-700">{{ $b->sent_count }}</span> / <span class="text-rose-700">{{ $b->failed_count }}</span> / {{ $b->total_recipients }}</td>
								<td class="px-4 py-2">@include('sms.partials.status', ['status' => $b->status])</td>
								<td class="px-4 py-2 text-slate-600">{{ $b->sender?->name ?? '—' }}</td>
							</tr>
						@empty
							<tr><td colspan="6" class="px-4 py-6 text-center text-slate-500">No SMS sent yet.</td></tr>
						@endforelse
					</tbody>
				</table>
			</x-index-table-shell>
		</div>

		{{-- ---------- Right column: message ---------- --}}
		<div class="min-w-0">
			<div class="space-y-4 rounded-2xl border border-slate-200 bg-white p-4 shadow-sm xl:sticky xl:top-4">
				<h2 class="text-sm font-semibold text-slate-900"><span x-show="mode === 'bulk'">3. </span>Message</h2>

				<label class="block text-xs font-medium text-slate-600">Template
					<select x-model="templateId" @change="applyTemplate()" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
						<option value="">— choose a template —</option>
						<template x-for="t in sortedTemplates" :key="t.id">
							<option :value="t.id" x-text="(t.category === audience ? '★ ' : '') + t.name"></option>
						</template>
					</select>
				</label>

				<div>
					<textarea x-ref="message" x-model="message" rows="7" maxlength="1000" placeholder="বাংলা বা English এ লিখুন…" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm leading-relaxed"></textarea>
					<div class="mt-1 flex flex-wrap items-center justify-between gap-2 text-xs">
						<span :class="info.unicode ? 'text-indigo-700' : 'text-slate-500'" x-text="info.unicode ? 'Bangla / Unicode' : 'English (GSM)'"></span>
						<span class="tabular-nums text-slate-600"><span x-text="info.characters"></span> chars · <strong x-text="info.parts"></strong> SMS part(s) · <span x-text="info.perPart"></span>/part</span>
					</div>
				</div>

				<div>
					<p class="mb-1 text-xs font-medium text-slate-600">Insert placeholder</p>
					<div class="flex flex-wrap gap-1">
						<template x-for="(label, key) in placeholders" :key="key">
							<button type="button" @click="insert(key)" :title="label" class="rounded-md bg-slate-100 px-1.5 py-0.5 font-mono text-[0.7rem] text-slate-700 hover:bg-slate-200" x-text="'{' + key + '}'"></button>
						</template>
					</div>
				</div>

				<label class="flex items-center gap-2 text-xs font-medium text-slate-700">
					<input type="checkbox" x-model="banglaDigits" class="rounded border-slate-300">
					Show numbers in Bangla digits (১২৩)
				</label>

				<div>
					<p class="mb-1 text-xs font-medium text-slate-600">Sender</p>
					<div class="grid grid-cols-2 gap-2">
						<label class="cursor-pointer rounded-lg border px-3 py-2 text-xs" :class="senderType === 'non_masking' ? 'border-sky-500 bg-sky-50' : 'border-slate-200'">
							<input type="radio" value="non_masking" x-model="senderType" class="mr-1"> Non-masking
							<span class="block font-mono text-[0.7rem] text-slate-500" x-text="senderIds.non_masking || 'not configured'"></span>
						</label>
						<label class="cursor-pointer rounded-lg border px-3 py-2 text-xs" :class="senderType === 'masking' ? 'border-sky-500 bg-sky-50' : 'border-slate-200'">
							<input type="radio" value="masking" x-model="senderType" class="mr-1"> Masking (brand)
							<span class="block font-mono text-[0.7rem] text-slate-500" x-text="senderIds.masking || 'not configured'"></span>
						</label>
					</div>
				</div>

				<div class="rounded-lg bg-slate-50 p-3">
					<p class="mb-1 text-xs font-semibold uppercase tracking-wider text-slate-500">
						Preview <span class="normal-case tracking-normal" x-show="mode === 'bulk' && currentPreviewRow" x-text="'— ' + (currentPreviewRow?.name || '')"></span>
					</p>
					<p class="whitespace-pre-wrap break-words text-sm text-slate-800" x-text="previewText || '…'"></p>
					<p class="mt-2 text-xs text-amber-700" x-show="unknownPlaceholders.length" x-text="'Not filled: ' + unknownPlaceholders.join(', ')"></p>
				</div>

				<div class="rounded-lg border border-slate-200 p-3 text-xs text-slate-600" x-show="mode === 'bulk'">
					<div class="flex justify-between"><span>Recipients</span><strong class="tabular-nums" x-text="selectedCount"></strong></div>
					<div class="flex justify-between"><span>Estimated SMS parts</span><strong class="tabular-nums" x-text="estimatedParts"></strong></div>
					<p class="mt-1 text-[0.7rem] text-slate-500">Limit per send: {{ number_format($maxRecipients) }} recipients.</p>
				</div>

				<button type="button" @click="confirmSend()" :disabled="! canSend" class="w-full rounded-lg bg-emerald-600 px-4 py-2.5 text-sm font-semibold text-white hover:bg-emerald-700 disabled:cursor-not-allowed disabled:opacity-50">
					<span x-show="mode === 'bulk'" x-text="'Send to ' + selectedCount + ' customer(s)'"></span>
					<span x-show="mode === 'single'" x-text="'Send to ' + parsedPhones.valid.length + ' number(s)'"></span>
				</button>
			</div>
		</div>
	</div>

	{{-- Hidden forms (submitted only after confirmation) --}}
	<form x-ref="bulkForm" method="post" action="{{ route('sms.send-bulk') }}" class="hidden">
		@csrf
		<input type="hidden" name="request_token" :value="token">
		<input type="hidden" name="audience" :value="loadedAudience">
		<template x-for="(value, key) in loadedFilters" :key="key">
			<input type="hidden" :name="key" :value="value ?? ''">
		</template>
		<input type="hidden" name="customer_ids" :value="selectedIds.join(',')">
		<input type="hidden" name="message" :value="message">
		<input type="hidden" name="sender_type" :value="senderType">
		<input type="hidden" name="bangla_digits" :value="banglaDigits ? 1 : 0">
	</form>
	<form x-ref="singleForm" method="post" action="{{ route('sms.send-single') }}" class="hidden">
		@csrf
		<input type="hidden" name="request_token" :value="token">
		<input type="hidden" name="phones" :value="parsedPhones.valid.join(',')">
		<input type="hidden" name="customer_id" :value="singleCustomer ? singleCustomer.id : ''">
		<input type="hidden" name="message" :value="message">
		<input type="hidden" name="sender_type" :value="senderType">
		<input type="hidden" name="bangla_digits" :value="banglaDigits ? 1 : 0">
	</form>

	{{-- Row preview modal --}}
	<div x-show="previewRow" x-transition.opacity class="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/40 p-4" @click.self="previewRow = null" @keydown.escape.window="previewRow = null">
		<div class="w-full max-w-md rounded-2xl bg-white p-5 shadow-xl">
			<h3 class="text-sm font-semibold text-slate-900" x-text="previewRow?.name + ' · ' + previewRow?.phone"></h3>
			<p class="mt-3 whitespace-pre-wrap break-words rounded-lg bg-slate-50 p-3 text-sm" x-text="previewRow ? renderFor(previewRow) : ''"></p>
			<p class="mt-2 text-xs text-slate-500" x-text="previewRow ? smsInfo(renderFor(previewRow)).parts + ' SMS part(s)' : ''"></p>
			<div class="mt-4 text-right"><button type="button" @click="previewRow = null" class="rounded-lg bg-slate-900 px-3 py-1.5 text-sm text-white">Close</button></div>
		</div>
	</div>
</div>

@php
	$smsConfig = [
		'audiences' => $audiences,
		'placeholders' => $placeholders,
		'templates' => $templates,
		'branches' => $branches,
		'senderIds' => $senderIds,
		'defaultSenderType' => $defaultSenderType,
		'company' => $company,
		'maxRecipients' => $maxRecipients,
		'urls' => [
			'audience' => route('sms.audience'),
			'customers' => route('sms.customers.search'),
		],
	];
@endphp
<script>
	window.__smsConfig = @json($smsConfig);
</script>
@include('sms.partials.script')
@endsection
