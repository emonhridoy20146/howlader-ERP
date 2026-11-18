@extends('reports.exports.print-layout')

@push('print-extra-styles')
	<style>
		.credit-overdue-print .print-credit-overdue-summary {
			margin-bottom: 8px;
			padding: 7px 9px;
			border: 1px solid #94a3b8;
			background: #f8fafc;
			font-size: 9px;
			line-height: 1.4;
			color: #1f2937;
		}
		.credit-overdue-print .print-credit-overdue-summary .print-summary-title {
			font-size: 10px;
			font-weight: 700;
			text-transform: uppercase;
			letter-spacing: 0.05em;
			color: #374151;
			margin: 0 0 3px;
		}
		.credit-overdue-print .print-summary-note { margin: 0 0 3px; color: #4b5563; }
		.credit-overdue-print table.print-summary-meta { width: 100%; border-collapse: collapse; margin: 0; }
		.credit-overdue-print table.print-summary-meta td {
			padding: 2px 4px 2px 0;
			border: none;
			vertical-align: top;
		}
		.credit-overdue-print table.print-summary-meta td.k { width: 52%; color: #4b5563; }
		.credit-overdue-print table.print-summary-meta td.v { font-weight: 600; color: #111827; }
		.credit-overdue-print table.print-summary-meta td.v.num { text-align: right; font-variant-numeric: tabular-nums; }
		.credit-overdue-print table.print-summary-meta tr.emphasis td.v { color: #9f1239; font-weight: 700; }
		.credit-overdue-print table.print-summary-meta tr.emphasis-amber td.v { color: #92400e; font-weight: 700; }
		.credit-overdue-print .print-branch-section {
			margin: 0 0 6px;
			padding: 0;
			border: none;
			border-top: 1px solid #cbd5e1;
			page-break-inside: auto;
			break-inside: auto;
		}
		.credit-overdue-print .print-branch-head {
			padding: 4px 0;
			page-break-after: avoid;
			break-after: avoid-page;
		}
		.credit-overdue-print .print-subsection-title {
			margin: 4px 0 2px;
			font-size: 9px;
			font-weight: 700;
			color: #334155;
		}
		.credit-overdue-print table.data {
			margin-top: 0;
			margin-bottom: 4px;
			page-break-inside: auto;
			break-inside: auto;
		}
		.credit-overdue-print table.data thead {
			display: table-header-group;
		}
		.credit-overdue-print table.data th,
		.credit-overdue-print table.data td {
			padding: 4px 5px;
			font-size: 9px;
			line-height: 1.35;
			vertical-align: top;
		}
		.credit-overdue-print table.data th {
			font-size: 8px;
		}
		.credit-overdue-print table.data tr {
			page-break-inside: auto;
			break-inside: auto;
		}
		@media print {
			.report-padded-sheet:has(.credit-overdue-print) .report-pad-header {
				padding-bottom: 6px;
				margin-bottom: 4px;
			}
			.report-padded-sheet:has(.credit-overdue-print) .report-brand-name {
				font-size: 18px;
				margin-bottom: 2px;
			}
			.report-padded-sheet:has(.credit-overdue-print) .report-meta-side,
			.report-padded-sheet:has(.credit-overdue-print) .report-company-block {
				font-size: 9px;
			}
			.report-padded-sheet:has(.credit-overdue-print) .report-body {
				margin-top: 6px;
			}
			.report-padded-sheet:has(.credit-overdue-print) .print-credit-overdue-summary {
				page-break-inside: avoid;
				break-inside: avoid-page;
			}
			.credit-overdue-print .print-branch-section:first-of-type {
				border-top: none;
			}
		}
	</style>
@endpush

@section('report-table')
<div class="credit-overdue-print">
	@if(is_array($kpis ?? null))
		@php
			$section = $filters['section'] ?? 'all';
			$showCredit = $section === 'all' || $section === 'credit';
			$showOverdue = $section === 'all' || $section === 'overdue';
			$showMatured = $section === 'all' || $section === 'matured';
		@endphp
		<div class="print-credit-overdue-summary">
			<p class="print-summary-title">Summary</p>
			<p class="print-summary-note">
				All matching rows · grouped by branch · detail capped at {{ number_format((int) ($detailRowLimit ?? 10000)) }} per export section.
			</p>
			<table class="print-summary-meta">
				<tbody>
					@if ($showCredit)
						<tr class="emphasis-amber"><td class="k">Overdue credit invoices</td><td class="v num">{{ number_format((int) ($kpis['invoices'] ?? 0)) }}</td></tr>
						<tr class="emphasis"><td class="k">Credit total due</td><td class="v num">{{ number_format((float) ($kpis['amount_due'] ?? 0), 2) }}</td></tr>
					@endif
					@if ($showOverdue)
						<tr class="emphasis-amber"><td class="k">Overdue installment schedules</td><td class="v num">{{ number_format((int) ($kpis['installment_schedules'] ?? 0)) }}</td></tr>
						<tr class="emphasis"><td class="k">Installment remaining</td><td class="v num">{{ number_format((float) ($kpis['installment_remaining'] ?? 0), 2) }}</td></tr>
					@endif
					@if ($showMatured)
						<tr class="emphasis-amber"><td class="k">Matured installment plans</td><td class="v num">{{ number_format((int) ($kpis['matured_invoices'] ?? 0)) }}</td></tr>
						<tr class="emphasis"><td class="k">Matured plans due</td><td class="v num">{{ number_format((float) ($kpis['matured_amount_due'] ?? 0), 2) }}</td></tr>
					@endif
				</tbody>
			</table>
		</div>
	@endif

	@include('reports.partials.credit-overdue-by-branch', [
		'creditRows' => $creditRows ?? collect(),
		'installmentRows' => $installmentRows ?? collect(),
		'maturedRows' => $maturedRows ?? collect(),
		'printMode' => true,
	])
</div>
@endsection
