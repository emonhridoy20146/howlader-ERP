@php
	$normalizeRows = static function ($rows): \Illuminate\Support\Collection {
		if ($rows instanceof \Illuminate\Contracts\Pagination\Paginator) {
			return collect($rows->items());
		}

		return collect($rows ?? []);
	};

	$installmentBranchId = static fn ($schedule): int => (int) ($schedule->installmentPlan?->salesInvoice?->branch_id ?? 0);
	$creditByBranch = $normalizeRows($creditRows ?? [])->groupBy(static fn ($invoice) => (int) ($invoice->branch_id ?? 0));
	$installmentByBranch = $normalizeRows($installmentRows ?? [])->groupBy($installmentBranchId);
	$maturedByBranch = $normalizeRows($maturedRows ?? [])->groupBy(static fn ($invoice) => (int) ($invoice->branch_id ?? 0));

	$branchIds = $creditByBranch->keys()
		->merge($installmentByBranch->keys())
		->merge($maturedByBranch->keys())
		->map(fn ($id) => (int) $id)
		->unique()
		->sort()
		->values();

	$branchNameFor = static function (int $branchId) use ($creditByBranch, $installmentByBranch, $maturedByBranch): string {
		$credit = $creditByBranch->get($branchId)?->first();
		if ($credit?->branch?->name) {
			return $credit->branch->name;
		}
		$schedule = $installmentByBranch->get($branchId)?->first();
		if ($schedule?->installmentPlan?->salesInvoice?->branch?->name) {
			return $schedule->installmentPlan->salesInvoice->branch->name;
		}
		$matured = $maturedByBranch->get($branchId)?->first();

		return $matured?->branch?->name ?? ($branchId === 0 ? 'Unassigned' : 'Branch #'.$branchId);
	};

	$printMode = (bool) ($printMode ?? false);
	$tableClass = $printMode ? 'data' : 'min-w-full text-sm whitespace-nowrap';
	$theadClass = $printMode ? '' : 'border-b border-slate-200 bg-slate-50 text-left text-xs font-semibold uppercase tracking-wider text-slate-700';
	$thClass = $printMode ? '' : 'px-4 py-3';
	$tdClass = $printMode ? '' : 'px-4 py-3';
	$rowClass = $printMode ? '' : 'border-b border-slate-100 transition-colors hover:bg-slate-50/70 last:border-0';
	$tableWrap = $printMode ? '' : 'overflow-x-auto overflow-y-hidden rounded-xl ring-1 ring-slate-200';
@endphp

<div class="{{ $printMode ? '' : 'space-y-4' }}">
@forelse ($branchIds as $branchId)
	@php
		$branchCredits = $creditByBranch->get($branchId, collect());
		$branchInstallments = $installmentByBranch->get($branchId, collect());
		$branchMatured = $maturedByBranch->get($branchId, collect());
		$branchName = $branchNameFor($branchId);
		$branchCreditDue = (float) $branchCredits->sum('amount_due');
		$branchInstRemaining = $branchInstallments->sum(fn ($row) => $row->remainingDue());
		$branchMaturedDue = (float) $branchMatured->sum('amount_due');
	@endphp
	<section class="{{ $printMode ? 'print-branch-section' : 'overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm' }}">
		@if ($printMode)
			<div class="print-branch-head">
				<strong style="font-size:10px;">{{ $branchName }}</strong>
				<span style="font-size:9px;color:#475569;">
					· Credit {{ number_format($branchCreditDue, 0) }}
					· Inst. {{ number_format($branchInstRemaining, 0) }}
					· Matured {{ number_format($branchMaturedDue, 0) }}
					· Total {{ number_format($branchCreditDue + $branchInstRemaining + $branchMaturedDue, 0) }}
				</span>
			</div>
		@else
			<div class="border-b border-slate-200 bg-gradient-to-r from-slate-800 to-slate-700 px-4 py-3 text-white">
				<div class="flex flex-wrap items-start justify-between gap-2">
					<div>
						<h3 class="text-base font-semibold">{{ $branchName }}</h3>
						<p class="mt-0.5 text-xs text-slate-300">
							{{ $branchCredits->count() }} credit · {{ $branchInstallments->count() }} installment · {{ $branchMatured->count() }} matured
						</p>
					</div>
					<div class="text-right text-xs text-slate-200 tabular-nums">
						<p>Total due <span class="font-semibold text-white">{{ number_format($branchCreditDue + $branchInstRemaining + $branchMaturedDue, 2) }}</span></p>
					</div>
				</div>
			</div>
		@endif

		<div class="{{ $printMode ? '' : 'space-y-8 p-4 sm:p-5' }}">
			@if ($branchCredits->isNotEmpty())
				<div>
					<h4 class="{{ $printMode ? 'print-subsection-title' : 'mb-3 text-sm font-semibold text-slate-800' }}">Past-due credit invoices</h4>
					<div class="{{ $tableWrap }}">
						<table class="{{ $tableClass }}">
							<thead class="{{ $theadClass }}">
								<tr>
									@if ($printMode)
										<th class="num">#</th>
									@endif
									<th class="{{ $thClass }}">Invoice</th>
									<th class="{{ $thClass }}">Customer</th>
									<th class="{{ $thClass }}">Invoice date</th>
									<th class="{{ $thClass }}">Credit due</th>
									<th class="{{ $printMode ? 'num' : $thClass.' text-right' }}">Late (mo)</th>
									<th class="{{ $printMode ? 'num' : $thClass.' text-right' }}">Due</th>
									@if (! $printMode)
										<th class="{{ $thClass }} text-right">Actions</th>
									@endif
								</tr>
							</thead>
							<tbody>
								@foreach ($branchCredits as $invoice)
									@php
										$due = $invoice->credit_due_date;
										$monthsLate = $invoice->creditMonthsLate();
										$c = $invoice->customer;
									@endphp
									<tr class="{{ $rowClass }}">
										@if ($printMode)
											<td class="num">{{ $loop->iteration }}</td>
										@endif
										<td class="{{ $tdClass }}">{{ $invoice->invoice_number }}</td>
										<td class="{{ $tdClass }} max-w-xs whitespace-normal">
											@if ($printMode)
												{{ $c?->name ?? '—' }}
											@else
												<p class="font-medium text-slate-800">{{ $c?->name ?? '—' }}</p>
												@if ($c?->phone)
													<p class="text-xs text-slate-500">{{ $c->phone }}</p>
												@endif
											@endif
										</td>
										<td class="{{ $tdClass }} whitespace-nowrap">{{ $invoice->invoice_date?->toDateString() }}</td>
										<td class="{{ $tdClass }} whitespace-nowrap font-medium text-amber-900">{{ $due?->toDateString() }}</td>
										<td class="{{ $printMode ? 'num' : $tdClass.' text-right' }} whitespace-nowrap">
											@if ($printMode)
												{{ $monthsLate }}
											@else
												@include('reports.partials.months-late-badge', ['months' => $monthsLate])
											@endif
										</td>
										<td class="{{ $printMode ? 'num' : $tdClass.' text-right tabular-nums font-semibold text-rose-700' }}">{{ number_format((float) $invoice->amount_due, 2) }}</td>
										@if (! $printMode)
											<td class="{{ $tdClass }} text-right whitespace-nowrap">
												@can('view', $invoice)
													<a href="{{ route('sales.invoices.show', $invoice) }}" class="rounded-lg border border-slate-300 px-2 py-1 text-xs font-medium hover:bg-slate-50">Invoice</a>
												@endcan
											</td>
										@endif
									</tr>
								@endforeach
							</tbody>
						</table>
					</div>
				</div>
			@endif

			@if ($branchInstallments->isNotEmpty())
				<div>
					<h4 class="{{ $printMode ? 'print-subsection-title' : 'mb-3 text-sm font-semibold text-slate-800' }}">Overdue installment schedules</h4>
					<div class="{{ $tableWrap }}">
						<table class="{{ $tableClass }}">
							<thead class="{{ $theadClass }}">
								<tr>
									@if ($printMode)
										<th class="num">#</th>
									@endif
									<th class="{{ $thClass }}">Invoice</th>
									<th class="{{ $thClass }}">Customer</th>
									<th class="{{ $printMode ? 'num' : $thClass.' text-right' }}">No.</th>
									<th class="{{ $thClass }}">Due date</th>
									<th class="{{ $printMode ? 'num' : $thClass.' text-right' }}">Late (mo)</th>
									<th class="{{ $printMode ? 'num' : $thClass.' text-right' }}">Due</th>
									<th class="{{ $printMode ? 'num' : $thClass.' text-right' }}">Late fee</th>
									<th class="{{ $printMode ? 'num' : $thClass.' text-right' }}">Paid</th>
									<th class="{{ $printMode ? 'num' : $thClass.' text-right' }}">Remaining</th>
									@if (! $printMode)
										<th class="{{ $thClass }} text-right">Actions</th>
									@endif
								</tr>
							</thead>
							<tbody>
								@foreach ($branchInstallments as $schedule)
									@php
										$plan = $schedule->installmentPlan;
										$sinv = $plan?->salesInvoice;
										$cust = $sinv?->customer;
										$sdue = $schedule->due_date;
										$monthsLate = $schedule->monthsLate();
									@endphp
									<tr class="{{ $rowClass }}">
										@if ($printMode)
											<td class="num">{{ $loop->iteration }}</td>
										@endif
										<td class="{{ $tdClass }}">{{ $sinv?->invoice_number ?? '—' }}</td>
										<td class="{{ $tdClass }} max-w-xs whitespace-normal">{{ $cust?->name ?? '—' }}</td>
										<td class="{{ $printMode ? 'num' : $tdClass.' text-right tabular-nums' }}">{{ $schedule->installment_no }}</td>
										<td class="{{ $tdClass }} whitespace-nowrap">{{ $sdue?->toDateString() }}</td>
										<td class="{{ $printMode ? 'num' : $tdClass.' text-right' }} whitespace-nowrap">
											@if ($printMode)
												{{ $monthsLate }}
											@else
												@include('reports.partials.months-late-badge', ['months' => $monthsLate])
											@endif
										</td>
										<td class="{{ $printMode ? 'num' : $tdClass.' text-right tabular-nums' }}">{{ number_format((float) $schedule->due_amount, 2) }}</td>
										<td class="{{ $printMode ? 'num' : $tdClass.' text-right tabular-nums' }}">{{ number_format((float) $schedule->late_fee_amount, 2) }}</td>
										<td class="{{ $printMode ? 'num' : $tdClass.' text-right tabular-nums text-emerald-700' }}">{{ number_format((float) $schedule->paid_amount, 2) }}</td>
										<td class="{{ $printMode ? 'num' : $tdClass.' text-right tabular-nums font-semibold text-rose-700' }}">{{ number_format($schedule->remainingDue(), 2) }}</td>
										@if (! $printMode)
											<td class="{{ $tdClass }} text-right whitespace-nowrap">
												@if ($sinv)
													@can('view', $sinv)
														<a href="{{ route('sales.invoices.show', $sinv) }}" class="rounded-lg border border-slate-300 px-2 py-1 text-xs font-medium hover:bg-slate-50">Invoice</a>
													@endcan
												@endif
												@if ($plan)
													@can('view', $plan)
														<a href="{{ route('installments.show', $plan) }}" class="ml-1 rounded-lg border border-slate-300 px-2 py-1 text-xs font-medium hover:bg-slate-50">Plan</a>
													@endcan
												@endif
											</td>
										@endif
									</tr>
								@endforeach
							</tbody>
						</table>
					</div>
				</div>
			@endif

			@if ($branchMatured->isNotEmpty())
				<div>
					@if (! $printMode)
						<h4 class="mb-1 text-sm font-semibold text-slate-800">Matured installment plans</h4>
						<p class="mb-3 text-xs text-slate-500">
							Final installment date has passed — still overdue even if a recent payment was made.
						</p>
					@endif
					<div class="{{ $tableWrap }}">
						<table class="{{ $tableClass }}">
							<thead class="{{ $theadClass }}">
								<tr>
									@if ($printMode)
										<th class="num">#</th>
									@endif
									<th class="{{ $thClass }}">Invoice</th>
									<th class="{{ $thClass }}">Customer</th>
									<th class="{{ $thClass }}">Invoice date</th>
									<th class="{{ $thClass }}">Last installment due</th>
									<th class="{{ $printMode ? 'num' : $thClass.' text-right' }}">Late (mo)</th>
									<th class="{{ $printMode ? 'num' : $thClass.' text-right' }}">Total</th>
									<th class="{{ $printMode ? 'num' : $thClass.' text-right' }}">Paid</th>
									<th class="{{ $printMode ? 'num' : $thClass.' text-right' }}">Due</th>
									@if (! $printMode)
										<th class="{{ $thClass }} text-right">Actions</th>
									@endif
								</tr>
							</thead>
							<tbody>
								@foreach ($branchMatured as $invoice)
									@php
										$c = $invoice->customer;
										$monthsLate = $invoice->installmentMaturityMonthsLate();
									@endphp
									<tr class="{{ $rowClass }}">
										@if ($printMode)
											<td class="num">{{ $loop->iteration }}</td>
										@endif
										<td class="{{ $tdClass }}">{{ $invoice->invoice_number }}</td>
										<td class="{{ $tdClass }} max-w-xs whitespace-normal">{{ $c?->name ?? '—' }}</td>
										<td class="{{ $tdClass }} whitespace-nowrap">{{ $invoice->invoice_date?->toDateString() }}</td>
										<td class="{{ $tdClass }} whitespace-nowrap font-medium text-amber-900">{{ $invoice->finalInstallmentDueDate()?->toDateString() }}</td>
										<td class="{{ $printMode ? 'num' : $tdClass.' text-right' }} whitespace-nowrap">
											@if ($printMode)
												{{ $monthsLate }}
											@else
												@include('reports.partials.months-late-badge', ['months' => $monthsLate])
											@endif
										</td>
										<td class="{{ $printMode ? 'num' : $tdClass.' text-right tabular-nums' }}">{{ number_format((float) $invoice->grand_total, 2) }}</td>
										<td class="{{ $printMode ? 'num' : $tdClass.' text-right tabular-nums text-emerald-700' }}">{{ number_format((float) $invoice->amount_paid, 2) }}</td>
										<td class="{{ $printMode ? 'num' : $tdClass.' text-right tabular-nums font-semibold text-rose-700' }}">{{ number_format((float) $invoice->amount_due, 2) }}</td>
										@if (! $printMode)
											<td class="{{ $tdClass }} text-right">
												@can('view', $invoice)
													<a href="{{ route('sales.invoices.show', $invoice) }}" class="rounded-lg border border-slate-300 px-2 py-1 text-xs font-medium hover:bg-slate-50">Invoice</a>
												@endcan
											</td>
										@endif
									</tr>
								@endforeach
							</tbody>
						</table>
					</div>
				</div>
			@endif
		</div>
	</section>
@empty
	<div class="{{ $printMode ? '' : 'rounded-2xl border border-dashed border-slate-300 bg-slate-50 px-4 py-16 text-center' }}">
		<p class="{{ $printMode ? '' : 'text-sm font-medium text-slate-600' }}" style="{{ $printMode ? 'font-size:9px;color:#64748b;' : '' }}">
			No overdue receivables match your filters.
		</p>
	</div>
@endforelse
</div>
