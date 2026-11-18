@extends('layouts.app')
@section('title', 'Yearly Collection Sheet')
@section('page-title', 'Yearly Collection Sheet')
@section('content')
@php
	$ycs = \App\Services\Reports\YearlyCollectionSheetService::class;
	// Only years with activity in this table (empty years are hidden).
	['months' => $months, 'years' => $years] = $ycs::visibleYears($data['months'], $ycs::invoiceMonthMaps($data['customers']));
	$creditCols = $ycs::visibleYears($data['months'], $ycs::invoiceMonthMaps(array_merge([], ...array_map(fn ($cg) => $cg['customers'], array_values($data['credit']['groups'])))));
	$kpis = $data['kpis'];
	$money = fn ($v) => number_format((float) $v, 0);
	$dash = fn ($v) => (float) $v > 0 ? number_format((float) $v, 0) : '–';
	$queryKeys = ['year_from', 'year_to', 'branch_ids', 'branch_id', 'customer_id', 'q', 'status', 'plan_status', 'collector_id', 'sort', 'include_paid', 'include_down_payment'];
	$exportQuery = array_merge(
		array_filter(request()->only($queryKeys), fn ($v) => $v !== null && $v !== ''),
		['year_from' => $filters['year_from'], 'year_to' => $filters['year_to'], 'include_paid' => (int) $filters['include_paid'], 'include_down_payment' => (int) $filters['include_down_payment']]
	);
	$canRisk = auth()->user()?->can('view-customer-risk') ?? false;
	// Sticky left offsets (px) for SL, customer, invoice.
	$stick = ['sl' => 0, 'name' => 44, 'inv' => 204];
	$cellClass = fn (?string $state) => match ($state) {
		'paid' => 'bg-emerald-50 text-emerald-800',
		'partial' => 'bg-amber-50 text-amber-800',
		'missed' => 'bg-rose-50 text-rose-700 font-semibold',
		'upcoming' => 'text-slate-400 italic',
		default => '',
	};
@endphp
<style>
	.ycs-wrap { max-height: 72vh; overflow: auto; }
	.ycs { border-collapse: separate; border-spacing: 0; font-size: 11px; }
	.ycs th, .ycs td { border-right: 1px solid #e2e8f0; border-bottom: 1px solid #e2e8f0; padding: 3px 5px; background: #fff; white-space: nowrap; }
	.ycs thead th { position: sticky; background: #f1f5f9; z-index: 20; text-align: center; font-weight: 600; }
	.ycs thead tr:first-child th { top: 0; height: 26px; }
	.ycs thead tr:nth-child(2) th { top: 26px; }
	.ycs .sk { position: sticky; z-index: 10; }
	.ycs thead .sk { z-index: 30; }
	.ycs .m { min-width: 56px; text-align: right; font-variant-numeric: tabular-nums; }
	.ycs .yr { border-left: 2px solid #64748b; }
	.ycs .cust td { background: #e2e8f0; font-weight: 600; }
	.ycs .branch td { background: #334155; color: #fff; font-weight: 700; }
	.ycs .btot td { background: #cbd5e1; font-weight: 700; border-top: 2px solid #64748b; }
	.ycs .tot td { background: #f8fafc; font-weight: 700; }
	.ycs .grand td { background: #e2e8f0; font-weight: 700; border-top: 2px solid #475569; }
	.ycs .two { display: block; line-height: 1.25; }
	.ycs .num { text-align: right; font-variant-numeric: tabular-nums; }
</style>
<div class="space-y-4">
	<p class="text-sm text-slate-600">Unpaid installment invoices grouped by customer, with a paid / missed grid for each month of {{ $filters['year_from'] }}–{{ $filters['year_to'] }}.</p>

	@if ($errors->any())
		<div class="rounded-lg bg-rose-50 p-3 text-sm text-rose-700">{{ $errors->first() }}</div>
	@endif

	<x-filter-drawer>
<form method="get" action="{{ route('reports.yearly-collection-sheet') }}" class="flex flex-col gap-4">
		@include('reports.partials.yearly-collection-sheet-filter-fields', ['filters' => $filters, 'filterBranches' => $filterBranches, 'collectors' => $collectors])
		<x-index-filter-actions :reset-url="route('reports.yearly-collection-sheet')" />
	</form>
</x-filter-drawer>

	<div class="flex flex-wrap items-center gap-2">
		<a target="_blank" rel="noopener noreferrer" href="{{ route('reports.print', array_merge(['slug' => 'yearly-collection-sheet'], $exportQuery)) }}" class="rounded-lg border border-slate-300 bg-slate-800 px-3 py-1.5 text-xs font-medium text-white hover:bg-slate-700">Print</a>
		<a href="{{ route('reports.export', array_merge(['slug' => 'yearly-collection-sheet', 'format' => 'pdf'], $exportQuery)) }}" class="rounded-lg border border-rose-200 bg-rose-50 px-3 py-1.5 text-xs font-medium text-rose-700 hover:bg-rose-100">Download PDF</a>
		<a href="{{ route('reports.export', array_merge(['slug' => 'yearly-collection-sheet', 'format' => 'xlsx'], $exportQuery)) }}" class="rounded-lg border border-emerald-200 bg-emerald-50 px-3 py-1.5 text-xs font-medium text-emerald-700 hover:bg-emerald-100">Download Excel</a>
	</div>

	<div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-5">
		<x-status-card label="Customers">{{ number_format($kpis['customers']) }}</x-status-card>
		<x-status-card label="Invoices">{{ number_format($kpis['invoices']) }}</x-status-card>
		<x-status-card label="Total paid (range)"><span class="text-emerald-700">{{ $money($kpis['total_paid']) }}</span></x-status-card>
		<x-status-card label="Total due (unpaid, due ≤ today)"><span class="text-rose-700">{{ $money($kpis['total_due']) }}</span></x-status-card>
		<x-status-card label="Unpaid months (invoice × month)"><span class="text-rose-700">{{ number_format($kpis['missed_months']) }}</span></x-status-card>
	</div>

	<div class="flex flex-wrap items-center gap-3 text-xs text-slate-600">
		<span class="rounded bg-emerald-50 px-2 py-0.5 text-emerald-800">✓ Paid</span>
		<span class="rounded bg-amber-50 px-2 py-0.5 text-amber-800">Partial: paid / −shortfall</span>
		<span class="rounded bg-rose-50 px-2 py-0.5 font-semibold text-rose-700">✗ Missed (due, unpaid)</span>
		<span class="px-2 py-0.5 italic text-slate-400">Upcoming due</span>
	</div>

	<h2 class="text-base font-semibold text-slate-800">Installment customers</h2>
	<div class="ycs-wrap rounded-2xl bg-white shadow-sm ring-1 ring-slate-200">
		<table class="ycs min-w-full">
			<thead>
				<tr>
					<th class="sk" style="left:{{ $stick['sl'] }}px;min-width:44px" rowspan="2">SL</th>
					<th class="sk" style="left:{{ $stick['name'] }}px;min-width:160px" rowspan="2">Customer</th>
					<th class="sk" style="left:{{ $stick['inv'] }}px;min-width:110px" rowspan="2">Invoice</th>
					<th rowspan="2">Invoice date</th>
					<th rowspan="2">Inst. amount</th>
					<th rowspan="2">Inst. paid/total</th>
					<th rowspan="2">Last due</th>
					<th rowspan="2">Total payable</th>
					<th rowspan="2">Total paid</th>
					<th rowspan="2">Total due</th>
					<th rowspan="2">Outstanding</th>
					<th rowspan="2">Due inst.</th>
					<th rowspan="2">No. of installments</th>
					<th rowspan="2">Invoice expires</th>
					@foreach ($years as $year => $count)
						<th colspan="{{ $count }}" class="yr">{{ $year }}</th>
					@endforeach
				</tr>
				<tr>
					@foreach ($months as $m)
						<th class="m {{ $m['month'] === 1 ? 'yr' : '' }}" title="{{ $m['label'] }} {{ $m['year'] }}">{{ $m['month'] }}</th>
					@endforeach
				</tr>
			</thead>
			@php $sl = ($data['paginator']->firstItem() ?? 1) - 1; $colspan = 14 + count($months); @endphp
			@forelse ($data['groups'] as $group)
				<tbody>
					<tr class="branch"><td colspan="{{ $colspan }}">Branch: {{ $group['name'] }} — {{ count($group['customers']) }} {{ \Illuminate\Support\Str::plural('customer', count($group['customers'])) }} · {{ $group['invoices'] }} {{ \Illuminate\Support\Str::plural('invoice', $group['invoices']) }}</td></tr>
				</tbody>
			@foreach ($group['customers'] as $customer)
				<tbody>
					<tr class="cust">
						<td colspan="{{ $colspan }}">
							<a class="text-indigo-700 hover:underline" href="{{ route('customers.show', $customer['id']) }}">{{ $customer['code'] }} — {{ $customer['name'] }}</a>
							@if ($customer['phone']) · {{ $customer['phone'] }} @endif
							@if ($customer['branch']) · {{ $customer['branch'] }} @endif
							@if ($canRisk) · <a class="text-xs text-indigo-600 underline" href="{{ route('customers.risk', $customer['id']) }}">Risk profile</a> @endif
						</td>
					</tr>
					@php $sl++; @endphp
					@foreach ($customer['invoices'] as $row)
						<tr>
							<td class="sk num" style="left:{{ $stick['sl'] }}px">{{ $loop->first ? $sl : '' }}</td>
							<td class="sk" style="left:{{ $stick['name'] }}px">{{ $loop->first ? $customer['name'] : '' }}</td>
							<td class="sk" style="left:{{ $stick['inv'] }}px"><a class="text-indigo-700 hover:underline" href="{{ route('sales.invoices.show', $row['invoice_id']) }}">{{ $row['number'] }}</a></td>
							<td>{{ $row['date'] }}</td>
							<td class="num">{{ $money($row['installment_amount']) }}</td>
							<td class="num">{{ $row['inst_paid'] }}/{{ $row['inst_total'] }}@if ($row['inst_partial']) <span class="text-amber-700">({{ $row['inst_partial'] }} partial)</span>@endif</td>
							<td>{{ $row['last_due'] }}</td>
							<td class="num">{{ $money($row['payable']) }}</td>
							<td class="num text-emerald-700">{{ $money($row['paid']) }}</td>
							<td class="num text-rose-700">{{ $money($row['due']) }}</td>
							<td class="num">{{ $money($row['outstanding']) }}</td>
							<td class="num">{{ $row['due_inst'] }}</td>
							<td class="num">{{ $row['inst_total'] }}</td>
							<td>{{ $row['expires'] }}</td>
							@foreach ($months as $m)
								@php $cell = $row['months'][$m['key']]; @endphp
								<td class="m {{ $m['month'] === 1 ? 'yr' : '' }} {{ $cellClass($cell['state']) }}" @if ($cell['state']) title="{{ ucfirst($cell['state']) }} — {{ $m['label'] }} {{ $m['year'] }}" @endif>
									@switch($cell['state'])
										@case('paid') ✓ {{ $money($cell['amount']) }} @break
										@case('partial') {{ $money($cell['amount']) }} / −{{ $money($cell['shortfall']) }} @break
										@case('missed') ✗ {{ $money($cell['amount']) }} @break
										@case('upcoming') {{ $money($cell['amount']) }} @break
									@endswitch
								</td>
							@endforeach
						</tr>
					@endforeach
					<tr class="tot">
						<td colspan="7" class="num">Customer total</td>
						<td class="num">{{ $money($customer['payable']) }}</td>
						<td class="num text-emerald-700">{{ $money($customer['paid']) }}</td>
						<td class="num text-rose-700">{{ $money($customer['due']) }}</td>
						<td class="num">{{ $money($customer['outstanding']) }}</td>
						<td class="num">{{ $customer['due_inst'] }}</td>
						<td></td>
						<td></td>
						@foreach ($months as $m)
							<td class="m {{ $m['month'] === 1 ? 'yr' : '' }}">
								@if ($customer['months'][$m['key']]['paid'] > 0 || $customer['months'][$m['key']]['due'] > 0)
									<span class="two text-emerald-700">{{ $dash($customer['months'][$m['key']]['paid']) }}</span>
									<span class="two text-rose-700">{{ $dash($customer['months'][$m['key']]['due']) }}</span>
								@endif
							</td>
						@endforeach
					</tr>
				</tbody>
			@endforeach
				<tbody>
					<tr class="btot">
						<td colspan="7" class="num">Branch total — {{ $group['name'] }}</td>
						<td class="num">{{ $money($group['payable']) }}</td>
						<td class="num text-emerald-700">{{ $money($group['paid']) }}</td>
						<td class="num text-rose-700">{{ $money($group['due']) }}</td>
						<td class="num">{{ $money($group['outstanding']) }}</td>
						<td class="num">{{ $group['due_inst'] }}</td>
						<td></td>
						<td></td>
						@foreach ($months as $m)
							<td class="m {{ $m['month'] === 1 ? 'yr' : '' }}">
								@if ($group['months'][$m['key']]['paid'] > 0 || $group['months'][$m['key']]['due'] > 0)
									<span class="two text-emerald-700">{{ $dash($group['months'][$m['key']]['paid']) }}</span>
									<span class="two text-rose-700">{{ $dash($group['months'][$m['key']]['due']) }}</span>
								@endif
							</td>
						@endforeach
					</tr>
				</tbody>
			@empty
				<tbody><tr><td colspan="{{ $colspan }}" class="px-4 py-12 text-center text-sm font-medium text-slate-500">No unpaid installment invoices match these filters.</td></tr></tbody>
			@endforelse
			@if (count($data['customers']))
				@php $g = $data['grand']; @endphp
				<tfoot>
					<tr class="grand">
						<td colspan="7" class="num">Page total ({{ $g['customers'] }} customers · {{ $g['invoices'] }} invoices) — paid / due</td>
						<td class="num">{{ $money($g['payable']) }}</td>
						<td class="num text-emerald-700">{{ $money($g['paid']) }}</td>
						<td class="num text-rose-700">{{ $money($g['due']) }}</td>
						<td class="num">{{ $money($g['outstanding']) }}</td>
						<td class="num">{{ $g['due_inst'] }}</td>
						<td></td>
						<td></td>
						@foreach ($months as $m)
							<td class="m {{ $m['month'] === 1 ? 'yr' : '' }}">
								@if ($g['months'][$m['key']]['paid'] > 0 || $g['months'][$m['key']]['due'] > 0)
									<span class="two text-emerald-700">{{ $dash($g['months'][$m['key']]['paid']) }}</span>
									<span class="two text-rose-700">{{ $dash($g['months'][$m['key']]['due']) }}</span>
								@endif
							</td>
						@endforeach
					</tr>
				</tfoot>
			@endif
		</table>
	</div>

	<div>{{ $data['paginator']->links() }}</div>

	@include('reports.partials.yearly-collection-credit', ['credit' => $data['credit']] + $creditCols)
</div>
@endsection
