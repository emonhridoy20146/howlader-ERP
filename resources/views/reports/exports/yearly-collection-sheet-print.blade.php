@extends('reports.exports.print-layout')

@php
	$months = $data['months'];
	$years = $data['years'];
	$g = $data['grand'];
	$money = fn ($v) => number_format((float) $v, 0);
	$dash = fn ($v) => (float) $v > 0 ? number_format((float) $v, 0) : '–';
	$pdfMode = $pdfMode ?? false;
	$paper = $paper ?? 'legal';
	$colspan = 14 + count($months);
	$legend = [['✓ 500', 'Paid in the month', 'paid'], ['500 / −300', 'Partial: paid / shortfall', 'partial'], ['✗ 500', 'Missed: due and unpaid', 'missed'], ['500', 'Upcoming due', 'upcoming'], ['◆ LAST', 'Final installment month, not fully cleared', 'last-open'], ['◆ DUE', 'Credit due-date month, not fully cleared', 'last-open']];
@endphp

@push('print-extra-styles')
	<style>
		@page { size: {{ strtoupper($paper) }} landscape; margin: 8mm; }
		.report-padded-sheet { max-width: none !important; }
		@if ($pdfMode)
		body { background: #fff !important; padding: 0 !important; }
		.report-padded-sheet { border: 0 !important; box-shadow: none !important; padding: 0 !important; }
		@endif
		table.ycs { width: 100%; border-collapse: collapse; font-size: 7px; font-family: 'DejaVu Sans', sans-serif; }
		table.ycs th, table.ycs td { border: 0.5px solid #6b7280; padding: 1.5px 2px; }
		table.ycs thead { display: table-header-group; }
		table.ycs th { background: #e5e7eb; text-align: center; }
		table.ycs tr { page-break-inside: avoid; }
		table.ycs tbody.grand-block { page-break-inside: avoid; break-inside: avoid; }
		table.ycs tbody.cust { page-break-inside: avoid; break-inside: avoid; }
		table.ycs td.n { text-align: right; }
		table.ycs td.m { text-align: right; min-width: 22px; }
		table.ycs .yr { border-left: 1.5px solid #111827; }
		table.ycs tr.ch td { background: #f3f4f6; font-weight: bold; }
		table.ycs tr.bh td { background: #374151; color: #fff; font-weight: bold; font-size: 8px; }
		table.ycs tbody.branch-head { page-break-after: avoid; break-after: avoid; }
		table.ycs tr.btot td { font-weight: bold; background: #d1d5db; border-top: 1.5px solid #111827; }
		table.ycs tr.tot td { font-weight: bold; background: #f9fafb; }
		table.ycs tr.grand td { font-weight: bold; background: #e5e7eb; border-top: 1.5px solid #111827; }
		.st-paid { background: #dcfce7; } .st-partial { background: #fef3c7; } .st-missed { background: #fee2e2; font-weight: bold; } .st-upcoming { color: #6b7280; font-style: italic; }
		.last-open { outline: 1.5px solid #b91c1c; outline-offset: -1.5px; } .last-open b { color: #b91c1c; font-size: 6px; }
		table.ycs .arr { color: #b91c1c; }
		.legend span { display: inline-block; margin-right: 10px; padding: 1px 4px; border: 0.5px solid #9ca3af; }
		.section-title { font-size: 9px; font-weight: bold; margin: 0 0 4px; padding: 2px 5px; border-left: 3px solid #374151; background: #f3f4f6; text-transform: uppercase; letter-spacing: 0.5px; }
		.branch-title { font-size: 10px; font-weight: bold; margin: 0 0 5px; padding: 3px 5px; background: #374151; color: #fff; }
		table.summary { border-collapse: collapse; margin: 8px 0 0; font-size: 8px; font-family: 'DejaVu Sans', sans-serif; }
		table.summary caption { text-align: left; font-weight: bold; padding-bottom: 2px; }
		table.summary th, table.summary td { border: 0.5px solid #6b7280; padding: 2px 6px; }
		table.summary th { background: #e5e7eb; }
		table.summary td.n { text-align: right; }
		table.summary tr.grand td { font-weight: bold; background: #e5e7eb; border-top: 1.5px solid #111827; }
		.sign { margin-top: 28px; width: 100%; }
		.sign td { width: 25%; text-align: center; border-top: 1px solid #111827; padding-top: 3px; font-size: 8px; }
		.sign td.gap { border: 0; width: 4%; }
	</style>
@endpush

@section('report-table')
	<p class="legend" style="margin:0 0 6px;font-size:7px;">
		@foreach ($legend as [$sample, $label, $cls])
			<span class="st-{{ $cls }}">{{ $sample }}</span>{{ $label }}&nbsp;&nbsp;
		@endforeach
	</p>
	@php
		$sl = 0; $plural = fn ($word, $n) => \Illuminate\Support\Str::plural($word, $n);
		$credit = $data['credit'] ?? ['groups' => [], 'grand' => null];
		$instBranchIds = array_column($data['groups'], 'id');
		$creditOnly = array_values(array_filter($credit['groups'], fn ($cg) => ! in_array($cg['id'], $instBranchIds, true)));
		$pageNo = 0;
		$allMonths = $months;
		$vis = fn (array $maps) => \App\Services\Reports\YearlyCollectionSheetService::visibleYears($allMonths, $maps);
		$creditVis = fn (array $cg) => $vis(\App\Services\Reports\YearlyCollectionSheetService::invoiceMonthMaps($cg['customers']));
	@endphp

	@forelse ($data['groups'] as $group)
		@php
			$distinct = count(array_unique(array_column($group['customers'], 'id')));
			['months' => $months, 'years' => $years] = $vis(\App\Services\Reports\YearlyCollectionSheetService::invoiceMonthMaps($group['customers']));
			$colspan = 14 + count($months);
		@endphp
		<div class="branch-page" @if ($pageNo++ > 0) style="page-break-before: always; break-before: page;" @endif>
			<p class="branch-title">Branch: {{ $group['name'] }} — {{ $distinct }} {{ $plural('customer', $distinct) }} · {{ $group['invoices'] }} {{ $plural('invoice', $group['invoices']) }}</p>
			<p class="section-title">Installment customers</p>
			<table class="ycs">
			<thead>
				<tr>
					<th rowspan="2">SL</th><th rowspan="2">Customer</th><th rowspan="2">Invoice</th><th rowspan="2">Inv. date</th>
					<th rowspan="2">Inst. amt</th><th rowspan="2">Paid/Total</th><th rowspan="2">Last due</th>
					<th rowspan="2">Total payable</th><th rowspan="2">Total paid</th><th rowspan="2">Total due</th><th rowspan="2">Outstanding</th><th rowspan="2">Due inst.</th>
					<th rowspan="2">No. of inst.</th><th rowspan="2">Invoice expires</th>
					@foreach ($years as $year => $count)<th colspan="{{ $count }}" class="yr">{{ $year }}</th>@endforeach
				</tr>
				<tr>
					@foreach ($months as $m)<th class="{{ $m['month'] === 1 ? 'yr' : '' }}">{{ $m['month'] }}</th>@endforeach
				</tr>
			</thead>
				@foreach ($group['customers'] as $customer)
					@php $sl++; @endphp
				<tbody class="cust">
					<tr class="ch"><td colspan="{{ $colspan }}">{{ $customer['code'] }} — {{ $customer['name'] }}@if ($customer['phone']) · {{ $customer['phone'] }}@endif @if ($customer['branch']) · {{ $customer['branch'] }}@endif</td></tr>
					@foreach ($customer['invoices'] as $row)
						<tr>
							<td class="n">{{ $loop->first ? $sl : '' }}</td>
							<td>{{ $loop->first ? $customer['name'] : '' }}</td>
							<td>{{ $row['number'] }}</td>
							<td>{{ $row['date'] }}</td>
							<td class="n">{{ $money($row['installment_amount']) }}</td>
							<td class="n">{{ $row['inst_paid'] }}/{{ $row['inst_total'] }}@if ($row['inst_partial']) ({{ $row['inst_partial'] }} partial)@endif</td>
							<td>{{ $row['last_due'] }}</td>
							<td class="n">{{ $money($row['payable']) }}</td><td class="n">{{ $money($row['paid']) }}</td><td class="n">{{ $money($row['due']) }}</td>
							<td class="n">{{ $money($row['outstanding']) }}</td><td class="n">{{ $row['due_inst'] }}</td>
							<td class="n">{{ $row['inst_total'] }}</td><td>{{ $row['expires'] }}</td>
							@foreach ($months as $m)
								@php $c = $row['months'][$m['key']]; @endphp
								<td class="m {{ $m['month'] === 1 ? 'yr' : '' }} {{ $c['state'] ? 'st-'.$c['state'] : '' }} {{ ($c['last_open'] ?? 0) ? 'last-open' : '' }}">
									@switch($c['state'])
										@case('paid') ✓ {{ $money($c['amount']) }} @break
										@case('partial') {{ $money($c['amount']) }} / −{{ $money($c['shortfall']) }} @break
										@case('missed') ✗ {{ $money($c['amount']) }} @break
										@case('upcoming') {{ $money($c['amount']) }} @break
									@endswitch
										@if ($c['last_open'] ?? 0)<br><b>◆ LAST</b>@endif
								</td>
							@endforeach
						</tr>
					@endforeach
					<tr class="tot">
						<td colspan="7" class="n">Customer total (paid / total unpaid till month)</td>
						<td class="n">{{ $money($customer['payable']) }}</td><td class="n">{{ $money($customer['paid']) }}</td><td class="n">{{ $money($customer['due']) }}</td>
						<td class="n">{{ $money($customer['outstanding']) }}</td><td class="n">{{ $customer['due_inst'] }}</td>
						<td></td><td></td>
						@foreach ($months as $m)
							@php $cm = $customer['months'][$m['key']]; @endphp
							<td class="m {{ $m['month'] === 1 ? 'yr' : '' }} {{ ($cm['last_open'] ?? 0) ? 'last-open' : '' }}">@if ($cm['paid'] > 0 || ($cm['arrears'] ?? 0) > 0){{ $dash($cm['paid']) }}<br><span class="arr">{{ $dash($cm['arrears'] ?? 0) }}</span>@endif @if ($cm['last_open'] ?? 0)<br><b>◆ LAST</b>@endif</td>
						@endforeach
					</tr>
				</tbody>
				@endforeach
				<tbody class="cust">
					<tr class="btot">
						<td colspan="7" class="n">Branch total — {{ $group['name'] }} (paid / total unpaid till month)</td>
						<td class="n">{{ $money($group['payable']) }}</td><td class="n">{{ $money($group['paid']) }}</td><td class="n">{{ $money($group['due']) }}</td>
						<td class="n">{{ $money($group['outstanding']) }}</td><td class="n">{{ $group['due_inst'] }}</td>
						<td></td><td></td>
						@foreach ($months as $m)
							@php $cm = $group['months'][$m['key']]; @endphp
							<td class="m {{ $m['month'] === 1 ? 'yr' : '' }} {{ ($cm['last_open'] ?? 0) ? 'last-open' : '' }}">@if ($cm['paid'] > 0 || ($cm['arrears'] ?? 0) > 0){{ $dash($cm['paid']) }}<br><span class="arr">{{ $dash($cm['arrears'] ?? 0) }}</span>@endif @if ($cm['last_open'] ?? 0)<br><b>◆ LAST</b>@endif</td>
						@endforeach
					</tr>
				</tbody>
			</table>

			<table class="summary">
				<caption>Branch summary — {{ $group['name'] }}</caption>
				<thead><tr><th>Customers</th><th>Invoices</th><th>Total payable</th><th>Total paid</th><th>Total due</th><th>Outstanding</th><th>Due inst.</th><th>Unpaid months</th></tr></thead>
				<tbody><tr>
					<td class="n">{{ $distinct }}</td><td class="n">{{ $group['invoices'] }}</td><td class="n">{{ $money($group['payable']) }}</td><td class="n">{{ $money($group['paid']) }}</td>
					<td class="n">{{ $money($group['due']) }}</td><td class="n">{{ $money($group['outstanding']) }}</td><td class="n">{{ $group['due_inst'] }}</td><td class="n">{{ $group['missed_months'] }}</td>
				</tr></tbody>
			</table>
			@if (isset($credit['groups'][$group['id']]))
				@include('reports.exports.partials.yearly-collection-credit-print', ['cg' => $credit['groups'][$group['id']]] + $creditVis($credit['groups'][$group['id']]))
			@endif
		</div>
	@empty
		@if ($creditOnly === [])
			<p>No unpaid installment or credit invoices match these filters.</p>
		@endif
	@endforelse

	@foreach ($creditOnly as $cg)
		<div class="branch-page" @if ($pageNo++ > 0) style="page-break-before: always; break-before: page;" @endif>
			<p class="branch-title">Branch: {{ $cg['name'] }} — {{ count($cg['customers']) }} {{ $plural('credit customer', count($cg['customers'])) }} · {{ $cg['invoices'] }} {{ $plural('invoice', $cg['invoices']) }}</p>
			@include('reports.exports.partials.yearly-collection-credit-print', ['cg' => $cg] + $creditVis($cg))
		</div>
	@endforeach

	@if (count($data['groups']) || count($credit['groups']))
		@php ['months' => $months, 'years' => $years] = $vis(\App\Services\Reports\YearlyCollectionSheetService::invoiceMonthMaps($data['customers'])); @endphp
		<div class="branch-page" style="page-break-before: always; break-before: page;">
			<p class="branch-title">Summary of all branches</p>
			@if (count($credit['groups']))
				<table class="summary" style="margin-bottom:8px;">
					<caption>Credit customers — all branches</caption>
					<thead><tr><th>#</th><th>Branch</th><th>Customers</th><th>Invoices</th><th>Total payable</th><th>Total paid</th><th>Total due</th></tr></thead>
					<tbody>
						@foreach (array_values($credit['groups']) as $cg)
							<tr>
								<td class="n">{{ $loop->iteration }}</td><td>{{ $cg['name'] }}</td><td class="n">{{ count($cg['customers']) }}</td><td class="n">{{ $cg['invoices'] }}</td>
								<td class="n">{{ $money($cg['payable']) }}</td><td class="n">{{ $money($cg['paid']) }}</td><td class="n">{{ $money($cg['due']) }}</td>
							</tr>
						@endforeach
						<tr class="grand">
							<td></td><td>Credit grand total</td><td class="n">{{ $credit['grand']['customers'] }}</td><td class="n">{{ $credit['grand']['invoices'] }}</td>
							<td class="n">{{ $money($credit['grand']['payable']) }}</td><td class="n">{{ $money($credit['grand']['paid']) }}</td><td class="n">{{ $money($credit['grand']['due']) }}</td>
						</tr>
					</tbody>
				</table>
			@endif
			@if (count($data['groups']))
			<table class="summary">
				<caption>Installment customers — all branches</caption>
				<thead><tr><th>#</th><th>Branch</th><th>Customers</th><th>Invoices</th><th>Total payable</th><th>Total paid</th><th>Total due</th><th>Outstanding</th><th>Due inst.</th><th>Unpaid months</th></tr></thead>
				<tbody>
					@foreach ($data['groups'] as $group)
						<tr>
							<td class="n">{{ $loop->iteration }}</td><td>{{ $group['name'] }}</td>
							<td class="n">{{ count(array_unique(array_column($group['customers'], 'id'))) }}</td><td class="n">{{ $group['invoices'] }}</td>
							<td class="n">{{ $money($group['payable']) }}</td><td class="n">{{ $money($group['paid']) }}</td><td class="n">{{ $money($group['due']) }}</td>
							<td class="n">{{ $money($group['outstanding']) }}</td><td class="n">{{ $group['due_inst'] }}</td><td class="n">{{ $group['missed_months'] }}</td>
						</tr>
					@endforeach
					<tr class="grand">
						<td></td><td>Grand total</td>
						<td class="n">{{ $g['customers'] }}</td><td class="n">{{ $g['invoices'] }}</td>
						<td class="n">{{ $money($g['payable']) }}</td><td class="n">{{ $money($g['paid']) }}</td><td class="n">{{ $money($g['due']) }}</td>
						<td class="n">{{ $money($g['outstanding']) }}</td><td class="n">{{ $g['due_inst'] }}</td><td class="n">{{ $g['missed_months'] }}</td>
					</tr>
				</tbody>
			</table>

			<table class="ycs" style="margin-top:10px;">
			<thead>
				<tr>
					<th rowspan="2">SL</th><th rowspan="2">Customer</th><th rowspan="2">Invoice</th><th rowspan="2">Inv. date</th>
					<th rowspan="2">Inst. amt</th><th rowspan="2">Paid/Total</th><th rowspan="2">Last due</th>
					<th rowspan="2">Total payable</th><th rowspan="2">Total paid</th><th rowspan="2">Total due</th><th rowspan="2">Outstanding</th><th rowspan="2">Due inst.</th>
					<th rowspan="2">No. of inst.</th><th rowspan="2">Invoice expires</th>
					@foreach ($years as $year => $count)<th colspan="{{ $count }}" class="yr">{{ $year }}</th>@endforeach
				</tr>
				<tr>
					@foreach ($months as $m)<th class="{{ $m['month'] === 1 ? 'yr' : '' }}">{{ $m['month'] }}</th>@endforeach
				</tr>
			</thead>
				<tbody class="grand-block">
					<tr class="grand">
						<td colspan="7" class="n">Grand total — {{ $g['customers'] }} {{ $plural('customer', $g['customers']) }} · {{ $g['invoices'] }} {{ $plural('invoice', $g['invoices']) }} (paid / total unpaid till month)</td>
						<td class="n">{{ $money($g['payable']) }}</td><td class="n">{{ $money($g['paid']) }}</td><td class="n">{{ $money($g['due']) }}</td>
						<td class="n">{{ $money($g['outstanding']) }}</td><td class="n">{{ $g['due_inst'] }}</td>
						<td></td><td></td>
						@foreach ($months as $m)
							<td class="m {{ $m['month'] === 1 ? 'yr' : '' }}">@if ($g['months'][$m['key']]['paid'] > 0 || $g['months'][$m['key']]['arrears'] > 0){{ $dash($g['months'][$m['key']]['paid']) }}<br><span class="arr">{{ $dash($g['months'][$m['key']]['arrears']) }}</span>@endif</td>
						@endforeach
					</tr>
				</tbody>
			</table>

			@endif

			<table class="sign"><tr>
				<td>Prepared by</td><td class="gap"></td><td>Organizer</td><td class="gap"></td><td>Accountant</td><td class="gap"></td><td>Manager</td>
			</tr></table>
		</div>
	@endif

	@if ($pdfMode)
		<script type="text/php">
			if (isset($pdf)) {
				$font = $fontMetrics->getFont('DejaVu Sans', 'normal');
				$text = 'Page {PAGE_NUM} of {PAGE_COUNT}';
				$pdf->page_text(24, $pdf->get_height() - 20, $text, $font, 8, [0.3, 0.3, 0.3]);
			}
		</script>
	@endif
@endsection
