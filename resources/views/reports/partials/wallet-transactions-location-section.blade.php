@php
	$locationSummary = is_array($locationSummary) ? $locationSummary : [];
	$locationRows = collect($locationRows ?? [])
		->sortBy(fn ($row): string => sprintf(
			'%s-%010d-%010d',
			$row->journalEntry->entry_date->format('Y-m-d'),
			$row->journalEntry->id,
			$row->id,
		))
		->values();
	$openingBalance = (float) ($openingBalance ?? ($locationSummary['opening_balance'] ?? 0));
	$runningBalance = $openingBalance;
	$compact = (bool) ($compact ?? false);
@endphp

@if ($compact)
<div style="margin: 0 0 1.25rem; page-break-inside: avoid;">
	<p style="margin: 0 0 0.5rem; font-weight: bold; font-size: 11px;">
		{{ $locationSummary['branch_name'] ?? 'Unassigned' }}
		@if (! empty($locationSummary['branch_code']))
			<span style="font-weight: normal; color: #64748b;"> · {{ $locationSummary['branch_code'] }}</span>
		@endif
	</p>
	<table class="data" style="margin: 0;">
		<thead>
			<tr>
				<th class="num">#</th>
				<th>Date</th>
				<th>Document No</th>
				<th>Description</th>
				<th>Source</th>
				<th class="num">Debit</th>
				<th class="num">Credit</th>
				<th class="num">Balance</th>
			</tr>
		</thead>
		<tbody>
			<tr>
				<td colspan="7" style="text-align: right; font-weight: bold;">Opening Balance</td>
				<td class="num" style="font-weight: bold;">{{ number_format($runningBalance, 2) }}</td>
			</tr>
			@forelse ($locationRows as $row)
				@php
					$runningBalance += ((float) $row->debit - (float) $row->credit);
				@endphp
				<tr>
					<td class="num">{{ $loop->iteration }}</td>
					<td>{{ $row->journalEntry->entry_date->format('M d, Y') }}</td>
					<td>{{ $row->journalEntry->document_no }}</td>
					<td>{{ $row->line_memo ?: $row->journalEntry->description }}</td>
					<td>{{ \App\Models\JournalEntry::sources()[$row->journalEntry->source] ?? $row->journalEntry->source }}</td>
					<td class="num">{{ $row->debit > 0 ? number_format($row->debit, 2) : '—' }}</td>
					<td class="num">{{ $row->credit > 0 ? number_format($row->credit, 2) : '—' }}</td>
					<td class="num">{{ number_format($runningBalance, 2) }}</td>
				</tr>
			@empty
				<tr>
					<td colspan="8" style="text-align: center; color: #64748b;">No transactions for this location in the selected period.</td>
				</tr>
			@endforelse
		</tbody>
	</table>
</div>
@else
<div class="overflow-hidden rounded-2xl bg-white shadow-sm ring-1 ring-slate-200">
	<div class="border-b border-slate-200 bg-gradient-to-r from-slate-50 to-white px-4 py-4">
		<div class="flex flex-wrap items-start justify-between gap-3">
			<div>
				<p class="text-base font-semibold text-slate-900">{{ $locationSummary['branch_name'] ?? 'Unassigned' }}</p>
				<p class="mt-0.5 text-xs text-slate-500">
					@if (! empty($locationSummary['branch_code']))
						{{ $locationSummary['branch_code'] }} ·
					@endif
					{{ number_format((int) ($locationSummary['line_count'] ?? $locationRows->count())) }} transactions
				</p>
			</div>
			<div class="grid grid-cols-2 gap-x-4 gap-y-1 text-right text-xs sm:grid-cols-4">
				<div>
					<p class="text-slate-500">Opening</p>
					<p class="font-semibold tabular-nums text-slate-900">{{ number_format($openingBalance, 2) }}</p>
				</div>
				<div>
					<p class="text-slate-500">Debit</p>
					<p class="font-semibold tabular-nums text-slate-900">{{ number_format((float) ($locationSummary['debit'] ?? 0), 2) }}</p>
				</div>
				<div>
					<p class="text-slate-500">Credit</p>
					<p class="font-semibold tabular-nums text-slate-900">{{ number_format((float) ($locationSummary['credit'] ?? 0), 2) }}</p>
				</div>
				<div>
					<p class="text-emerald-800">Closing</p>
					<p class="font-semibold tabular-nums text-emerald-900">{{ number_format((float) ($locationSummary['closing_balance'] ?? $openingBalance), 2) }}</p>
				</div>
			</div>
		</div>
	</div>

	<div class="overflow-x-auto">
		<table class="min-w-full divide-y divide-slate-300 text-sm">
			<thead class="bg-slate-50">
				<tr>
					<th scope="col" class="py-3 pl-4 pr-3 text-left text-xs font-semibold uppercase tracking-wide text-slate-600 sm:pl-6">Date</th>
					<th scope="col" class="px-3 py-3 text-left text-xs font-semibold uppercase tracking-wide text-slate-600">Document No</th>
					<th scope="col" class="px-3 py-3 text-left text-xs font-semibold uppercase tracking-wide text-slate-600">Description</th>
					<th scope="col" class="px-3 py-3 text-left text-xs font-semibold uppercase tracking-wide text-slate-600">Source</th>
					<th scope="col" class="px-3 py-3 text-right text-xs font-semibold uppercase tracking-wide text-slate-600">Debit</th>
					<th scope="col" class="px-3 py-3 text-right text-xs font-semibold uppercase tracking-wide text-slate-600">Credit</th>
					<th scope="col" class="px-3 py-3 text-right text-xs font-semibold uppercase tracking-wide text-slate-600 sm:pr-6">Balance</th>
				</tr>
			</thead>
			<tbody class="divide-y divide-slate-200 bg-white">
				<tr class="bg-slate-50/80">
					<td colspan="6" class="py-3 pl-4 pr-3 text-right text-sm font-medium text-slate-900 sm:pl-6">Opening balance</td>
					<td class="whitespace-nowrap px-3 py-3 text-right text-sm font-medium tabular-nums text-slate-900 sm:pr-6">{{ number_format($runningBalance, 2) }}</td>
				</tr>
				@forelse ($locationRows as $row)
					@php
						$runningBalance += ((float) $row->debit - (float) $row->credit);
					@endphp
					<tr class="hover:bg-slate-50/70">
						<td class="whitespace-nowrap py-3 pl-4 pr-3 text-slate-900 sm:pl-6">{{ $row->journalEntry->entry_date->format('M d, Y') }}</td>
						<td class="whitespace-nowrap px-3 py-3 text-slate-600">{{ $row->journalEntry->document_no }}</td>
						<td class="px-3 py-3 text-slate-600">{{ $row->line_memo ?: $row->journalEntry->description }}</td>
						<td class="whitespace-nowrap px-3 py-3 text-slate-600">{{ \App\Models\JournalEntry::sources()[$row->journalEntry->source] ?? $row->journalEntry->source }}</td>
						<td class="whitespace-nowrap px-3 py-3 text-right tabular-nums text-slate-900">{{ (float) $row->debit > 0 ? number_format((float) $row->debit, 2) : '—' }}</td>
						<td class="whitespace-nowrap px-3 py-3 text-right tabular-nums text-slate-900">{{ (float) $row->credit > 0 ? number_format((float) $row->credit, 2) : '—' }}</td>
						<td class="whitespace-nowrap px-3 py-3 text-right tabular-nums font-medium text-slate-900 sm:pr-6">{{ number_format($runningBalance, 2) }}</td>
					</tr>
				@empty
					<tr>
						<td colspan="7" class="py-6 text-center text-sm text-slate-500">No transactions for this location in the selected period.</td>
					</tr>
				@endforelse
			</tbody>
		</table>
	</div>
</div>
@endif
