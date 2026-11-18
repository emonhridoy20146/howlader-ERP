@extends('layouts.app')
@section('title', 'Monthly Collection')
@section('page-title', 'Monthly Collection')
@section('content')
@php
	$summary = $summary ?? [];
	$instDue = (float) ($summary['installment_due'] ?? $thisMonthInstallments->sum('due_amount'));
	$instPaid = (float) ($summary['installment_paid'] ?? $thisMonthInstallments->sum('paid_amount'));
	$instRemaining = (float) ($summary['installment_remaining'] ?? $thisMonthInstallments->sum('remaining'));
	$instInvoices = (int) ($summary['installment_invoices'] ?? $thisMonthInstallments->count());
	$instCustomers = (int) ($summary['installment_customers'] ?? 0);

	$creditGrand = (float) ($summary['credit_grand'] ?? $thisMonthCredits->sum('grand_total'));
	$creditPaid = (float) ($summary['credit_paid'] ?? $thisMonthCredits->sum('amount_paid'));
	$creditDue = (float) ($summary['credit_due'] ?? $thisMonthCredits->sum('amount_due'));
	$creditInvoices = (int) ($summary['credit_invoices'] ?? $thisMonthCredits->count());
	$creditCustomers = (int) ($summary['credit_customers'] ?? 0);

	$overdueDue = (float) ($summary['overdue_installment_due'] ?? $overdueInstallments->sum('due_amount'));
	$overduePaid = (float) ($summary['overdue_installment_paid'] ?? $overdueInstallments->sum('paid_amount'));
	$overdueRemaining = (float) ($summary['overdue_installment_remaining'] ?? $overdueInstallments->sum('remaining'));
	$overdueInstInvoices = (int) ($summary['overdue_installment_invoices'] ?? $overdueInstallments->count());
	$overdueInstCustomers = (int) ($summary['overdue_installment_customers'] ?? 0);

	$overdueCreditGrand = (float) ($summary['overdue_credit_grand'] ?? $overdueCredits->sum('grand_total'));
	$overdueCreditPaid = (float) ($summary['overdue_credit_paid'] ?? $overdueCredits->sum('amount_paid'));
	$overdueCreditDue = (float) ($summary['overdue_credit_due'] ?? $overdueCredits->sum('amount_due'));
	$overdueCreditInvoices = (int) ($summary['overdue_credit_invoices'] ?? $overdueCredits->count());
	$overdueCreditCustomers = (int) ($summary['overdue_credit_customers'] ?? 0);

	$maturedOverdueGrand = (float) ($summary['matured_overdue_grand'] ?? $maturedOverdueInstallments->sum('grand_total'));
	$maturedOverduePaid = (float) ($summary['matured_overdue_paid'] ?? $maturedOverdueInstallments->sum('amount_paid'));
	$maturedOverdueDue = (float) ($summary['matured_overdue_due'] ?? $maturedOverdueInstallments->sum('amount_due'));
	$maturedOverdueInvoices = (int) ($summary['matured_overdue_invoices'] ?? $maturedOverdueInstallments->count());
	$maturedOverdueCustomers = (int) ($summary['matured_overdue_customers'] ?? 0);

	$totalDue = (float) ($summary['total_due'] ?? ($instRemaining + $creditDue + $overdueRemaining + $overdueCreditDue + $maturedOverdueDue));
	$totalPaid = (float) ($summary['total_paid'] ?? ($instPaid + $creditPaid + $overduePaid + $overdueCreditPaid + $maturedOverduePaid));
	$periodPaid = (float) ($summary['period_paid'] ?? 0);
	$totalCustomers = (int) ($summary['total_customers'] ?? 0);
	$totalInvoices = (int) ($summary['total_invoices'] ?? 0);

	$formatSchedDueDate = static function ($date): string {
		if (! $date instanceof \DateTimeInterface) {
			return '—';
		}

		return $date->format('d-m-Y');
	};

	$periodPaymentsByInvoiceId = $periodPaymentsByInvoiceId ?? collect();
	$periodPaymentFor = static function (?int $invoiceId) use ($periodPaymentsByInvoiceId): float {
		if (! $invoiceId) {
			return 0.0;
		}

		return (float) ($periodPaymentsByInvoiceId[$invoiceId] ?? 0);
	};

	$collectorsByInvoiceId = $collectorsByInvoiceId ?? collect();
	$collectorsFor = static fn (?int $invoiceId): string => $invoiceId ? (string) ($collectorsByInvoiceId[$invoiceId] ?? '—') : '—';

	$collectionDateFrom = trim((string) ($filters['date_from'] ?? ''));
	$collectionDateTo = trim((string) ($filters['date_to'] ?? ''));
	if ($collectionDateFrom === '' && $collectionDateTo === '') {
		$collectionDateFrom = now()->startOfMonth()->toDateString();
		$collectionDateTo = now()->endOfMonth()->toDateString();
	} elseif ($collectionDateFrom === '') {
		$collectionDateFrom = $collectionDateTo;
	} elseif ($collectionDateTo === '') {
		$collectionDateTo = $collectionDateFrom;
	}
	if ($collectionDateFrom > $collectionDateTo) {
		[$collectionDateFrom, $collectionDateTo] = [$collectionDateTo, $collectionDateFrom];
	}
@endphp
<div class="space-y-8">
	<p class="text-sm text-slate-600">
		Overview of installments and credit or default (cash) invoices due in the selected date range, plus overdue installments and past-due receivables. Unpaid default sales use the invoice date as the due date and stay on the report each month until paid. Product lines show sold price and default sale price (def.). The "Overdue (matured plans)" table lists installment invoices whose <span class="font-medium">final</span> installment date has already passed — the whole plan is past due, not just one installment. Results follow your branch context. Summary totals cover the full filtered set (not only the current page).
	</p>

	<x-filter-drawer>
		<form method="get" action="{{ route('reports.monthly-collection') }}" class="flex flex-col gap-4">
			@include('reports.partials.monthly-collection-filter-fields', ['filters' => $filters, 'filterBranches' => $filterBranches])
			<x-index-filter-actions :reset-url="route('reports.monthly-collection')" />
		</form>
	</x-filter-drawer>

	<div class="flex flex-wrap items-center gap-2">
		<span class="text-xs font-medium text-slate-500">Export (uses filters above)</span>
		@php
			$exportQuery = array_filter([
				'q' => $filters['q'] ?? '',
				'branch_id' => $filters['branch_id'] ?? '',
				'date_from' => $filters['date_from'] ?? '',
				'date_to' => $filters['date_to'] ?? '',
				'collector_id' => $filters['collector_id'] ?? '',
			], static fn ($v) => $v !== null && $v !== '');
		@endphp
		<a target="_blank" rel="noopener noreferrer" href="{{ route('reports.print', array_merge(['slug' => 'monthly-collection'], $exportQuery)) }}" class="rounded-lg border border-slate-300 bg-white px-3 py-1.5 text-xs font-medium text-slate-800 hover:bg-slate-50">Print</a>
		<a href="{{ route('reports.export', array_merge(['slug' => 'monthly-collection', 'format' => 'xlsx'], $exportQuery)) }}" class="rounded-lg border border-slate-300 bg-white px-3 py-1.5 text-xs font-medium text-slate-800 hover:bg-slate-50">Excel</a>
	</div>

	<section class="rounded-2xl bg-white p-5 shadow-sm ring-1 ring-slate-200">
		<div class="flex flex-wrap items-end justify-between gap-2">
			<div>
				<h2 class="text-base font-semibold text-slate-900">Collection summary</h2>
				<p class="mt-1 text-xs text-slate-500">{{ $collectionDateFrom }} to {{ $collectionDateTo }} · full filtered set</p>
			</div>
			<p class="text-xs text-slate-500">{{ number_format($totalInvoices) }} invoices · {{ number_format($totalCustomers) }} customers</p>
		</div>

		<div class="mt-4 grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
			@foreach ([
				['label' => 'Total installment (due)', 'value' => $instDue, 'tone' => 'text-sky-700', 'meta' => number_format($instInvoices).' invoices · '.number_format($instCustomers).' customers'],
				['label' => 'Total installment paid', 'value' => $instPaid, 'tone' => 'text-emerald-700', 'meta' => 'Remaining '.number_format($instRemaining, 2)],
				['label' => 'Credit & default due', 'value' => $creditDue, 'tone' => 'text-indigo-700', 'meta' => number_format($creditInvoices).' invoices · '.number_format($creditCustomers).' customers'],
				['label' => 'Credit & default paid', 'value' => $creditPaid, 'tone' => 'text-emerald-700', 'meta' => 'Grand '.number_format($creditGrand, 2)],
				['label' => 'Overdue installment remaining', 'value' => $overdueRemaining, 'tone' => 'text-rose-700', 'meta' => number_format($overdueInstInvoices).' invoices · '.number_format($overdueInstCustomers).' customers'],
				['label' => 'Overdue credit due', 'value' => $overdueCreditDue, 'tone' => 'text-rose-700', 'meta' => number_format($overdueCreditInvoices).' invoices · '.number_format($overdueCreditCustomers).' customers'],
				['label' => 'Overdue (matured plans) due', 'value' => $maturedOverdueDue, 'tone' => 'text-rose-900', 'meta' => number_format($maturedOverdueInvoices).' invoices · '.number_format($maturedOverdueCustomers).' customers'],
				['label' => 'Total due (all sections)', 'value' => $totalDue, 'tone' => 'text-rose-800', 'meta' => 'Installment + credit + overdue + matured'],
				['label' => 'Paid in selected period', 'value' => $periodPaid, 'tone' => 'text-emerald-800', 'meta' => 'Cash on listed invoices · lifetime paid '.number_format($totalPaid, 2)],
			] as $card)
				<div class="rounded-xl border border-slate-200 bg-slate-50/70 px-4 py-3">
					<p class="text-[11px] font-semibold uppercase tracking-wide text-slate-500">{{ $card['label'] }}</p>
					<p class="mt-1 text-2xl font-bold tabular-nums {{ $card['tone'] }}">{{ number_format((float) $card['value'], 2) }}</p>
					<p class="mt-1 text-xs text-slate-500">{{ $card['meta'] }}</p>
				</div>
			@endforeach
		</div>
	</section>

	{{-- Section: This Month Installments --}}
	<div>
		<h2 class="text-lg font-semibold text-slate-800 mb-3">Installments due {{ $collectionDateFrom }} to {{ $collectionDateTo }}</h2>
		<div class="overflow-x-auto overflow-y-hidden rounded-2xl bg-white shadow-sm ring-1 ring-slate-200">
			<table class="min-w-full text-sm whitespace-nowrap">
				<thead class="bg-slate-50 border-b border-slate-200 text-left text-xs font-semibold uppercase tracking-wider text-slate-700">
					<tr>
						<th class="px-4 py-3">Invoice</th>
						<th class="px-4 py-3">Branch</th>
						<th class="px-4 py-3">Customer</th>
						<th class="px-4 py-3">Sale date</th>
						<th class="px-4 py-3">Products</th>
						<th class="px-4 py-3 text-right">Invoice total</th>
						<th class="px-4 py-3 text-right">#</th>
						<th class="px-4 py-3">Due date</th>
						<th class="px-4 py-3 text-right">Due</th>
						<th class="px-4 py-3 text-right">Paid</th>
						<th class="px-4 py-3 text-right">This month payment</th>
						<th class="px-4 py-3">Collected by</th>
						<th class="px-4 py-3 text-right">Remaining</th>
					</tr>
				</thead>
				<tbody>
					@forelse($thisMonthInstallments as $row)
						@php
							$invoice = $row->invoice;
							$customer = $invoice?->customer;
							$branch = $invoice?->branch;
							$remaining = (float) $row->remaining;
						@endphp
						<tr class="border-b border-slate-100 transition-colors hover:bg-slate-50/70 last:border-0">
							<td class="px-4 py-3">
								@if ($invoice)
									<p class="font-mono text-xs font-medium text-slate-800">{{ $invoice->invoice_number }}</p>
								@else
									<span class="text-slate-400">—</span>
								@endif
							</td>
							<td class="px-4 py-3">
								@if ($branch)
									<p class="font-medium text-slate-800">{{ $branch->name }}</p>
								@else
									<span class="text-slate-400">—</span>
								@endif
							</td>
							<td class="px-4 py-3 max-w-xs whitespace-normal">
								@if ($customer)
									<p class="font-medium text-slate-800">{{ $customer->name }}</p>
									<p class="text-xs text-slate-500">{{ $customer->phone ?: '—' }}</p>
									@if ($customer->address !== null && trim((string) $customer->address) !== '')
										<p class="text-xs text-slate-500 mt-0.5">{{ $customer->address }}</p>
									@endif
								@else
									<span class="text-slate-400">—</span>
								@endif
							</td>
							<td class="px-4 py-3 whitespace-nowrap">{{ ($row->invoice_date ?? $invoice?->invoice_date)?->toDateString() ?? '—' }}</td>
							<td class="px-4 py-3">@include('reports.partials.monthly-collection-invoice-products', ['invoice' => $invoice])</td>
							<td class="px-4 py-3 text-right tabular-nums">{{ $invoice ? number_format((float) $invoice->grand_total, 2) : '—' }}</td>
							<td class="px-4 py-3 text-right tabular-nums">{{ $row->installment_nos }}</td>
							<td class="px-4 py-3 whitespace-nowrap">{{ $formatSchedDueDate($row->due_date) }}</td>
							<td class="px-4 py-3 text-right tabular-nums">{{ number_format((float) $row->due_amount, 2) }}</td>
							<td class="px-4 py-3 text-right tabular-nums text-emerald-700">{{ number_format((float) $row->paid_amount, 2) }}</td>
							<td class="px-4 py-3 text-right tabular-nums text-sky-700">{{ number_format($periodPaymentFor((int) ($invoice?->id ?? 0)), 2) }}</td>
							<td class="px-4 py-3 text-slate-600">{{ $collectorsFor((int) ($invoice?->id ?? 0)) }}</td>
							<td class="px-4 py-3 text-right tabular-nums font-semibold text-rose-700">{{ number_format($remaining, 2) }}</td>
						</tr>
					@empty
						<tr>
							<td colspan="13" class="px-4 py-12 text-center text-sm font-medium text-slate-500 bg-slate-50/50">No installments due this month.</td>
						</tr>
					@endforelse
				</tbody>
				@if($thisMonthInstallments->isNotEmpty())
				<tfoot class="bg-slate-50 border-t border-slate-200 font-semibold text-slate-800">
					<tr>
						<td colspan="9" class="px-4 py-3 text-right">Total (listed):</td>
						<td class="px-4 py-3 text-right tabular-nums">{{ number_format((float) $instDue, 2) }}</td>
						<td class="px-4 py-3 text-right tabular-nums text-emerald-700">{{ number_format((float) $instPaid, 2) }}</td>
						<td class="px-4 py-3 text-right tabular-nums text-sky-700">{{ number_format($thisMonthInstallments->sum(fn ($row) => $periodPaymentFor((int) ($row->invoice?->id ?? 0))), 2) }}</td>
						<td></td>
						<td class="px-4 py-3 text-right tabular-nums text-rose-700">{{ number_format($instRemaining, 2) }}</td>
					</tr>
				</tfoot>
				@endif
			</table>
			@if($thisMonthInstallments->hasPages())
				<div class="border-t border-slate-200 px-4 py-3 bg-white">
					{{ $thisMonthInstallments->appends(request()->except('this_month_inst_page'))->links() }}
				</div>
			@endif
		</div>
	</div>

	{{-- Section: This Month Credit Dues --}}
	<div>
		<h2 class="text-lg font-semibold text-slate-800 mb-3">Credit &amp; default dues {{ $collectionDateFrom }} to {{ $collectionDateTo }}</h2>
		<div class="overflow-x-auto overflow-y-hidden rounded-2xl bg-white shadow-sm ring-1 ring-slate-200">
			<table class="min-w-full text-sm whitespace-nowrap">
				<thead class="bg-slate-50 border-b border-slate-200 text-left text-xs font-semibold uppercase tracking-wider text-slate-700">
					<tr>
						<th class="px-4 py-3">Invoice</th>
						<th class="px-4 py-3">Branch</th>
						<th class="px-4 py-3">Customer</th>
						<th class="px-4 py-3">Invoice Date</th>
						<th class="px-4 py-3">Products</th>
						<th class="px-4 py-3">Due date</th>
						<th class="px-4 py-3 text-right">Total</th>
						<th class="px-4 py-3 text-right">Paid</th>
						<th class="px-4 py-3 text-right">This month payment</th>
						<th class="px-4 py-3">Collected by</th>
						<th class="px-4 py-3 text-right">Due</th>
					</tr>
				</thead>
				<tbody>
					@forelse($thisMonthCredits as $invoice)
						<tr class="border-b border-slate-100 transition-colors hover:bg-slate-50/70 last:border-0">
							<td class="px-4 py-3">
								<p class="font-mono text-xs font-medium text-slate-800">{{ $invoice->invoice_number }}</p>
							</td>
							<td class="px-4 py-3">
								<p class="font-medium text-slate-800">{{ $invoice->branch?->name }}</p>
							</td>
							<td class="px-4 py-3 max-w-xs whitespace-normal">
								<p class="font-medium text-slate-800">{{ $invoice->customer?->name }}</p>
								<p class="text-xs text-slate-500">{{ $invoice->customer?->phone ?: '—' }}</p>
								@if ($invoice->customer && $invoice->customer->address !== null && trim((string) $invoice->customer->address) !== '')
									<p class="text-xs text-slate-500 mt-0.5">{{ $invoice->customer->address }}</p>
								@endif
							</td>
							<td class="px-4 py-3 whitespace-nowrap">{{ $invoice->invoice_date?->toDateString() }}</td>
							<td class="px-4 py-3">@include('reports.partials.monthly-collection-invoice-products', ['invoice' => $invoice])</td>
							<td class="px-4 py-3 whitespace-nowrap">{{ $invoice->collectionDueDate()?->toDateString() }}</td>
							<td class="px-4 py-3 text-right tabular-nums">{{ number_format((float) $invoice->grand_total, 2) }}</td>
							<td class="px-4 py-3 text-right tabular-nums text-emerald-700">{{ number_format((float) $invoice->amount_paid, 2) }}</td>
							<td class="px-4 py-3 text-right tabular-nums text-sky-700">{{ number_format($periodPaymentFor((int) $invoice->id), 2) }}</td>
							<td class="px-4 py-3 text-slate-600">{{ $collectorsFor((int) $invoice->id) }}</td>
							<td class="px-4 py-3 text-right tabular-nums font-semibold text-rose-700">{{ number_format((float) $invoice->amount_due, 2) }}</td>
						</tr>
					@empty
						<tr>
							<td colspan="11" class="px-4 py-12 text-center text-sm font-medium text-slate-500 bg-slate-50/50">No credit or default invoices due this month.</td>
						</tr>
					@endforelse
				</tbody>
				@if($thisMonthCredits->isNotEmpty())
				<tfoot class="bg-slate-50 border-t border-slate-200 font-semibold text-slate-800">
					<tr>
						<td colspan="7" class="px-4 py-3 text-right">Total (listed):</td>
						<td class="px-4 py-3 text-right tabular-nums">{{ number_format((float) $creditGrand, 2) }}</td>
						<td class="px-4 py-3 text-right tabular-nums text-emerald-700">{{ number_format((float) $creditPaid, 2) }}</td>
						<td class="px-4 py-3 text-right tabular-nums text-sky-700">{{ number_format($thisMonthCredits->sum(fn ($invoice) => $periodPaymentFor((int) $invoice->id)), 2) }}</td>
						<td></td>
						<td class="px-4 py-3 text-right tabular-nums text-rose-700">{{ number_format((float) $creditDue, 2) }}</td>
					</tr>
				</tfoot>
				@endif
			</table>
			@if($thisMonthCredits->hasPages())
				<div class="border-t border-slate-200 px-4 py-3 bg-white">
					{{ $thisMonthCredits->appends(request()->except('this_month_credit_page'))->links() }}
				</div>
			@endif
		</div>
	</div>

	{{-- Section: Overdue Installments --}}
	<div>
		<h2 class="text-lg font-semibold text-slate-800 mb-3">Overdue installments (before {{ $collectionDateFrom }})</h2>
		<div class="overflow-x-auto overflow-y-hidden rounded-2xl bg-white shadow-sm ring-1 ring-slate-200">
			<table class="min-w-full text-sm whitespace-nowrap">
				<thead class="bg-slate-50 border-b border-slate-200 text-left text-xs font-semibold uppercase tracking-wider text-slate-700">
					<tr>
						<th class="px-4 py-3">Invoice</th>
						<th class="px-4 py-3">Branch</th>
						<th class="px-4 py-3">Customer</th>
						<th class="px-4 py-3">Sale date</th>
						<th class="px-4 py-3">Products</th>
						<th class="px-4 py-3 text-right">Invoice total</th>
						<th class="px-4 py-3 text-right">#</th>
						<th class="px-4 py-3">Due date</th>
						<th class="px-4 py-3 text-right">Late (mo)</th>
						<th class="px-4 py-3 text-right">Due</th>
						<th class="px-4 py-3 text-right">Paid</th>
						<th class="px-4 py-3 text-right">This month payment</th>
						<th class="px-4 py-3">Collected by</th>
						<th class="px-4 py-3 text-right">Remaining</th>
					</tr>
				</thead>
				<tbody>
					@forelse($overdueInstallments as $row)
						@php
							$invoice = $row->invoice;
							$customer = $invoice?->customer;
							$branch = $invoice?->branch;
							$remaining = (float) $row->remaining;
							$monthsLate = (int) $row->months_late;
						@endphp
						<tr class="border-b border-slate-100 transition-colors hover:bg-slate-50/70 last:border-0">
							<td class="px-4 py-3">
								@if ($invoice)
									<p class="font-mono text-xs font-medium text-slate-800">{{ $invoice->invoice_number }}</p>
								@else
									<span class="text-slate-400">—</span>
								@endif
							</td>
							<td class="px-4 py-3">
								@if ($branch)
									<p class="font-medium text-slate-800">{{ $branch->name }}</p>
								@else
									<span class="text-slate-400">—</span>
								@endif
							</td>
							<td class="px-4 py-3 max-w-xs whitespace-normal">
								@if ($customer)
									<p class="font-medium text-slate-800">{{ $customer->name }}</p>
									<p class="text-xs text-slate-500">{{ $customer->phone ?: '—' }}</p>
									@if ($customer->address !== null && trim((string) $customer->address) !== '')
										<p class="text-xs text-slate-500 mt-0.5">{{ $customer->address }}</p>
									@endif
								@else
									<span class="text-slate-400">—</span>
								@endif
							</td>
							<td class="px-4 py-3 whitespace-nowrap">{{ ($row->invoice_date ?? $invoice?->invoice_date)?->toDateString() ?? '—' }}</td>
							<td class="px-4 py-3">@include('reports.partials.monthly-collection-invoice-products', ['invoice' => $invoice])</td>
							<td class="px-4 py-3 text-right tabular-nums">{{ $invoice ? number_format((float) $invoice->grand_total, 2) : '—' }}</td>
							<td class="px-4 py-3 text-right tabular-nums">{{ $row->installment_nos }}</td>
							<td class="px-4 py-3 whitespace-nowrap">{{ $formatSchedDueDate($row->due_date) }}</td>
							<td class="px-4 py-3 text-right tabular-nums whitespace-nowrap">
								@if ($monthsLate >= 2)
									<span class="inline-flex items-center rounded-md bg-red-50 px-2 py-1 text-xs font-medium text-red-700 ring-1 ring-inset ring-red-600/10">{{ $monthsLate }} mo</span>
								@elseif ($monthsLate > 0)
									<span class="inline-flex items-center rounded-md bg-yellow-50 px-2 py-1 text-xs font-medium text-yellow-800 ring-1 ring-inset ring-yellow-600/20">{{ $monthsLate }} mo</span>
								@else
									<span class="text-slate-400">0</span>
								@endif
							</td>
							<td class="px-4 py-3 text-right tabular-nums">{{ number_format((float) $row->due_amount, 2) }}</td>
							<td class="px-4 py-3 text-right tabular-nums text-emerald-700">{{ number_format((float) $row->paid_amount, 2) }}</td>
							<td class="px-4 py-3 text-right tabular-nums text-sky-700">{{ number_format($periodPaymentFor((int) ($invoice?->id ?? 0)), 2) }}</td>
							<td class="px-4 py-3 text-slate-600">{{ $collectorsFor((int) ($invoice?->id ?? 0)) }}</td>
							<td class="px-4 py-3 text-right tabular-nums font-semibold text-rose-700">{{ number_format($remaining, 2) }}</td>
						</tr>
					@empty
						<tr>
							<td colspan="14" class="px-4 py-12 text-center text-sm font-medium text-slate-500 bg-slate-50/50">No overdue installments.</td>
						</tr>
					@endforelse
				</tbody>
				@if($overdueInstallments->isNotEmpty())
				<tfoot class="bg-slate-50 border-t border-slate-200 font-semibold text-slate-800">
					<tr>
						<td colspan="10" class="px-4 py-3 text-right">Total (listed):</td>
						<td class="px-4 py-3 text-right tabular-nums">{{ number_format((float) $overdueDue, 2) }}</td>
						<td class="px-4 py-3 text-right tabular-nums text-emerald-700">{{ number_format((float) $overduePaid, 2) }}</td>
						<td class="px-4 py-3 text-right tabular-nums text-sky-700">{{ number_format($overdueInstallments->sum(fn ($row) => $periodPaymentFor((int) ($row->invoice?->id ?? 0))), 2) }}</td>
						<td></td>
						<td class="px-4 py-3 text-right tabular-nums text-rose-700">{{ number_format($overdueRemaining, 2) }}</td>
					</tr>
				</tfoot>
				@endif
			</table>
			@if($overdueInstallments->hasPages())
				<div class="border-t border-slate-200 px-4 py-3 bg-white">
					{{ $overdueInstallments->appends(request()->except('overdue_inst_page'))->links() }}
				</div>
			@endif
		</div>
	</div>

	{{-- Section: Overdue Credit Dues --}}
	<div>
		<h2 class="text-lg font-semibold text-slate-800 mb-3">Overdue credit &amp; default dues (before {{ $collectionDateFrom }})</h2>
		<div class="overflow-x-auto overflow-y-hidden rounded-2xl bg-white shadow-sm ring-1 ring-slate-200">
			<table class="min-w-full text-sm whitespace-nowrap">
				<thead class="bg-slate-50 border-b border-slate-200 text-left text-xs font-semibold uppercase tracking-wider text-slate-700">
					<tr>
						<th class="px-4 py-3">Invoice</th>
						<th class="px-4 py-3">Branch</th>
						<th class="px-4 py-3">Customer</th>
						<th class="px-4 py-3">Invoice Date</th>
						<th class="px-4 py-3">Products</th>
						<th class="px-4 py-3">Credit due</th>
						<th class="px-4 py-3 text-right">Late (mo)</th>
						<th class="px-4 py-3 text-right">Total</th>
						<th class="px-4 py-3 text-right">Paid</th>
						<th class="px-4 py-3 text-right">This month payment</th>
						<th class="px-4 py-3">Collected by</th>
						<th class="px-4 py-3 text-right">Due</th>
					</tr>
				</thead>
				<tbody>
					@forelse($overdueCredits as $invoice)
						@php
							$monthsLate = $invoice->creditMonthsLate();
						@endphp
						<tr class="border-b border-slate-100 transition-colors hover:bg-slate-50/70 last:border-0">
							<td class="px-4 py-3">
								<p class="font-mono text-xs font-medium text-slate-800">{{ $invoice->invoice_number }}</p>
							</td>
							<td class="px-4 py-3">
								<p class="font-medium text-slate-800">{{ $invoice->branch?->name }}</p>
							</td>
							<td class="px-4 py-3 max-w-xs whitespace-normal">
								<p class="font-medium text-slate-800">{{ $invoice->customer?->name }}</p>
								<p class="text-xs text-slate-500">{{ $invoice->customer?->phone ?: '—' }}</p>
								@if ($invoice->customer && $invoice->customer->address !== null && trim((string) $invoice->customer->address) !== '')
									<p class="text-xs text-slate-500 mt-0.5">{{ $invoice->customer->address }}</p>
								@endif
							</td>
							<td class="px-4 py-3 whitespace-nowrap">{{ $invoice->invoice_date?->toDateString() }}</td>
							<td class="px-4 py-3">@include('reports.partials.monthly-collection-invoice-products', ['invoice' => $invoice])</td>
							<td class="px-4 py-3 whitespace-nowrap font-medium text-amber-900">{{ $invoice->collectionDueDate()?->toDateString() }}</td>
							<td class="px-4 py-3 text-right tabular-nums whitespace-nowrap">
								@if ($monthsLate >= 2)
									<span class="inline-flex items-center rounded-md bg-red-50 px-2 py-1 text-xs font-medium text-red-700 ring-1 ring-inset ring-red-600/10">{{ $monthsLate }} mo</span>
								@elseif ($monthsLate > 0)
									<span class="inline-flex items-center rounded-md bg-yellow-50 px-2 py-1 text-xs font-medium text-yellow-800 ring-1 ring-inset ring-yellow-600/20">{{ $monthsLate }} mo</span>
								@else
									<span class="text-slate-400">0</span>
								@endif
							</td>
							<td class="px-4 py-3 text-right tabular-nums">{{ number_format((float) $invoice->grand_total, 2) }}</td>
							<td class="px-4 py-3 text-right tabular-nums text-emerald-700">{{ number_format((float) $invoice->amount_paid, 2) }}</td>
							<td class="px-4 py-3 text-right tabular-nums text-sky-700">{{ number_format($periodPaymentFor((int) $invoice->id), 2) }}</td>
							<td class="px-4 py-3 text-slate-600">{{ $collectorsFor((int) $invoice->id) }}</td>
							<td class="px-4 py-3 text-right tabular-nums font-semibold text-rose-700">{{ number_format((float) $invoice->amount_due, 2) }}</td>
						</tr>
					@empty
						<tr>
							<td colspan="12" class="px-4 py-12 text-center text-sm font-medium text-slate-500 bg-slate-50/50">No overdue credit invoices.</td>
						</tr>
					@endforelse
				</tbody>
				@if($overdueCredits->isNotEmpty())
				<tfoot class="bg-slate-50 border-t border-slate-200 font-semibold text-slate-800">
					<tr>
						<td colspan="8" class="px-4 py-3 text-right">Total (listed):</td>
						<td class="px-4 py-3 text-right tabular-nums">{{ number_format((float) $overdueCreditGrand, 2) }}</td>
						<td class="px-4 py-3 text-right tabular-nums text-emerald-700">{{ number_format((float) $overdueCreditPaid, 2) }}</td>
						<td class="px-4 py-3 text-right tabular-nums text-sky-700">{{ number_format($overdueCredits->sum(fn ($invoice) => $periodPaymentFor((int) $invoice->id)), 2) }}</td>
						<td></td>
						<td class="px-4 py-3 text-right tabular-nums text-rose-700">{{ number_format((float) $overdueCreditDue, 2) }}</td>
					</tr>
				</tfoot>
				@endif
			</table>
			@if($overdueCredits->hasPages())
				<div class="border-t border-slate-200 px-4 py-3 bg-white">
					{{ $overdueCredits->appends(request()->except('overdue_credit_page'))->links() }}
				</div>
			@endif
		</div>
	</div>

	{{-- Section: Overdue (matured installment plans) --}}
	<div>
		<h2 class="text-lg font-semibold text-slate-800 mb-3">Overdue (matured plans) — final installment date passed</h2>
		<p class="mb-3 text-xs text-slate-500">Installment invoices where the <span class="font-medium">last</span> scheduled installment fell due before {{ $collectionDateFrom }} and the plan still has a balance — the whole plan has run past its end date, separate from the "Overdue installments" table above (which lists any individual late installment).</p>
		<div class="overflow-x-auto overflow-y-hidden rounded-2xl bg-white shadow-sm ring-1 ring-slate-200">
			<table class="min-w-full text-sm whitespace-nowrap">
				<thead class="bg-slate-50 border-b border-slate-200 text-left text-xs font-semibold uppercase tracking-wider text-slate-700">
					<tr>
						<th class="px-4 py-3">Invoice</th>
						<th class="px-4 py-3">Branch</th>
						<th class="px-4 py-3">Customer</th>
						<th class="px-4 py-3">Invoice Date</th>
						<th class="px-4 py-3">Products</th>
						<th class="px-4 py-3">Last installment due</th>
						<th class="px-4 py-3 text-right">Late (mo)</th>
						<th class="px-4 py-3 text-right">Total</th>
						<th class="px-4 py-3 text-right">Paid</th>
						<th class="px-4 py-3 text-right">This month payment</th>
						<th class="px-4 py-3">Collected by</th>
						<th class="px-4 py-3 text-right">Due</th>
					</tr>
				</thead>
				<tbody>
					@forelse($maturedOverdueInstallments as $invoice)
						@php
							$monthsLate = $invoice->installmentMaturityMonthsLate();
						@endphp
						<tr class="border-b border-slate-100 transition-colors hover:bg-slate-50/70 last:border-0">
							<td class="px-4 py-3">
								<p class="font-mono text-xs font-medium text-slate-800">{{ $invoice->invoice_number }}</p>
							</td>
							<td class="px-4 py-3">
								<p class="font-medium text-slate-800">{{ $invoice->branch?->name }}</p>
							</td>
							<td class="px-4 py-3 max-w-xs whitespace-normal">
								<p class="font-medium text-slate-800">{{ $invoice->customer?->name }}</p>
								<p class="text-xs text-slate-500">{{ $invoice->customer?->phone ?: '—' }}</p>
								@if ($invoice->customer && $invoice->customer->address !== null && trim((string) $invoice->customer->address) !== '')
									<p class="text-xs text-slate-500 mt-0.5">{{ $invoice->customer->address }}</p>
								@endif
							</td>
							<td class="px-4 py-3 whitespace-nowrap">{{ $invoice->invoice_date?->toDateString() }}</td>
							<td class="px-4 py-3">@include('reports.partials.monthly-collection-invoice-products', ['invoice' => $invoice])</td>
							<td class="px-4 py-3 whitespace-nowrap font-medium text-amber-900">{{ $invoice->finalInstallmentDueDate()?->toDateString() }}</td>
							<td class="px-4 py-3 text-right tabular-nums whitespace-nowrap">
								@if ($monthsLate >= 2)
									<span class="inline-flex items-center rounded-md bg-red-50 px-2 py-1 text-xs font-medium text-red-700 ring-1 ring-inset ring-red-600/10">{{ $monthsLate }} mo</span>
								@elseif ($monthsLate > 0)
									<span class="inline-flex items-center rounded-md bg-yellow-50 px-2 py-1 text-xs font-medium text-yellow-800 ring-1 ring-inset ring-yellow-600/20">{{ $monthsLate }} mo</span>
								@else
									<span class="text-slate-400">0</span>
								@endif
							</td>
							<td class="px-4 py-3 text-right tabular-nums">{{ number_format((float) $invoice->grand_total, 2) }}</td>
							<td class="px-4 py-3 text-right tabular-nums text-emerald-700">{{ number_format((float) $invoice->amount_paid, 2) }}</td>
							<td class="px-4 py-3 text-right tabular-nums text-sky-700">{{ number_format($periodPaymentFor((int) $invoice->id), 2) }}</td>
							<td class="px-4 py-3 text-slate-600">{{ $collectorsFor((int) $invoice->id) }}</td>
							<td class="px-4 py-3 text-right tabular-nums font-semibold text-rose-700">{{ number_format((float) $invoice->amount_due, 2) }}</td>
						</tr>
					@empty
						<tr>
							<td colspan="12" class="px-4 py-12 text-center text-sm font-medium text-slate-500 bg-slate-50/50">No matured installment plans overdue.</td>
						</tr>
					@endforelse
				</tbody>
				@if($maturedOverdueInstallments->isNotEmpty())
				<tfoot class="bg-slate-50 border-t border-slate-200 font-semibold text-slate-800">
					<tr>
						<td colspan="8" class="px-4 py-3 text-right">Total (listed):</td>
						<td class="px-4 py-3 text-right tabular-nums">{{ number_format((float) $maturedOverdueGrand, 2) }}</td>
						<td class="px-4 py-3 text-right tabular-nums text-emerald-700">{{ number_format((float) $maturedOverduePaid, 2) }}</td>
						<td class="px-4 py-3 text-right tabular-nums text-sky-700">{{ number_format($maturedOverdueInstallments->sum(fn ($invoice) => $periodPaymentFor((int) $invoice->id)), 2) }}</td>
						<td></td>
						<td class="px-4 py-3 text-right tabular-nums text-rose-700">{{ number_format((float) $maturedOverdueDue, 2) }}</td>
					</tr>
				</tfoot>
				@endif
			</table>
			@if($maturedOverdueInstallments->hasPages())
				<div class="border-t border-slate-200 px-4 py-3 bg-white">
					{{ $maturedOverdueInstallments->appends(request()->except('matured_overdue_page'))->links() }}
				</div>
			@endif
		</div>
	</div>
	@include('reports.partials.collector-subtotals', ['totals' => $summary['collector_totals'] ?? []])
</div>
@endsection
