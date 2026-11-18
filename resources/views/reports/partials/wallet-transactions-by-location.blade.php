@php
	$rows = collect($rows ?? []);
	$summaryByBranch = collect($summaryByBranch ?? []);
	$openingBalancesByBranch = collect($openingBalancesByBranch ?? []);
	$compact = (bool) ($compact ?? false);

	$groupedRows = $rows->groupBy(
		fn ($row): string => $row->journalEntry?->branch_id !== null ? (string) $row->journalEntry->branch_id : ''
	);

	$branchSummaries = $summaryByBranch->keyBy(
		fn (array $row): string => $row['branch_id'] !== null ? (string) $row['branch_id'] : ''
	);

	if ($branchSummaries->isEmpty() && $groupedRows->isNotEmpty()) {
		$branchSummaries = $groupedRows->map(function ($locationRows, string $branchKey): array {
			$opening = (float) $openingBalancesByBranch->get($branchKey, 0);
			$debit = round($locationRows->sum(fn ($row) => (float) $row->debit), 2);
			$credit = round($locationRows->sum(fn ($row) => (float) $row->credit), 2);

			return [
				'branch_id' => $branchKey !== '' ? (int) $branchKey : null,
				'branch_name' => $locationRows->first()?->journalEntry?->branch?->name ?? 'Unassigned',
				'branch_code' => $locationRows->first()?->journalEntry?->branch?->code ?? '',
				'line_count' => $locationRows->count(),
				'debit' => $debit,
				'credit' => $credit,
				'net' => round($debit - $credit, 2),
				'opening_balance' => $opening,
				'closing_balance' => round($opening + $debit - $credit, 2),
			];
		})->sortBy('branch_name');
	}
@endphp

@if ($branchSummaries->isEmpty() && $groupedRows->isEmpty())
	@if ($compact)
		<p style="text-align: center; color: #64748b;">No transactions found for the selected period.</p>
	@else
		<div class="rounded-2xl border border-dashed border-slate-300 bg-slate-50 px-4 py-12 text-center text-sm font-medium text-slate-500">
			No transactions found for the selected period.
		</div>
	@endif
@else
	<div class="{{ $compact ? '' : 'space-y-6' }}">
		@foreach ($branchSummaries as $locationSummary)
			@php
				$branchKey = $locationSummary['branch_id'] !== null ? (string) $locationSummary['branch_id'] : '';
				$locationRows = $groupedRows->get($branchKey, collect());
			@endphp
			@include('reports.partials.wallet-transactions-location-section', [
				'locationSummary' => $locationSummary,
				'locationRows' => $locationRows,
				'openingBalance' => (float) ($locationSummary['opening_balance'] ?? $openingBalancesByBranch->get($branchKey, 0)),
				'compact' => $compact,
			])
		@endforeach
	</div>
@endif
