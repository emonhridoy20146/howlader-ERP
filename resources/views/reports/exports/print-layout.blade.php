<!DOCTYPE html>
<html lang="en">
<head>
	<meta charset="UTF-8">
	<meta name="viewport" content="width=device-width, initial-scale=1.0">
	<title>{{ $reportTitle }}</title>
	<style>
		* { box-sizing: border-box; }
		body {
			font-family: 'Noto Sans Bengali', 'DejaVu Sans', ui-sans-serif, system-ui, sans-serif;
			font-size: 10px;
			color: #1f2937;
			margin: 0;
			padding: 16px;
			padding-top: 0;
			background: #e5e7eb;
		}
		.report-print-chrome {
			max-width: 960px;
			margin: 0 auto 14px;
			display: flex;
			flex-wrap: wrap;
			align-items: center;
			justify-content: flex-end;
			gap: 8px;
		}
		.report-print-chrome a,
		.report-print-chrome button {
			border: 1px solid #cbd5e1;
			background: #fff;
			color: #334155;
			border-radius: 8px;
			font-size: 12px;
			padding: 8px 12px;
			text-decoration: none;
			cursor: pointer;
			font-weight: 600;
		}
		.report-print-chrome button:hover,
		.report-print-chrome a:hover {
			background: #f8fafc;
		}
		.report-padded-sheet {
			max-width: 960px;
			margin: 0 auto;
			padding: 22px 26px 18px;
			border: 1px solid #d1d5db;
			border-radius: 4px;
			background: #fff;
			box-shadow: 0 1px 3px rgb(15 23 42 / 0.08);
		}
		.report-padded-sheet .report-body {
			margin-top: 14px;
		}
		.report-pad-header {
			margin: 0;
			padding: 0 0 18px 0;
			border-bottom: 2px solid #9ca3af;
		}
		.report-pad-header-table {
			width: 100%;
			border-collapse: collapse;
		}
		.report-pad-header-table td {
			vertical-align: top;
			padding: 0;
		}
		.report-pad-left { width: 64%; padding-right: 14px; }
		.report-pad-right { width: 36%; text-align: right; }
		.report-heading-small {
			font-size: 10px;
			letter-spacing: 0.08em;
			text-transform: uppercase;
			color: #4b5563;
			margin: 0 0 6px;
			font-weight: 600;
		}
		.report-brand-name {
			font-size: 22px;
			line-height: 1.05;
			font-weight: 700;
			color: #111827;
			margin: 0 0 6px;
		}
		.report-tagline {
			font-size: 9px;
			color: #6b7280;
			margin: 0 0 8px;
			line-height: 1.35;
		}
		.report-company-block {
			margin-top: 4px;
			font-size: 9px;
			line-height: 1.5;
			color: #374151;
		}
		.report-logo {
			max-height: 52px;
			max-width: 150px;
			width: auto;
			height: auto;
			object-fit: contain;
			display: block;
			margin: 0 0 12px auto;
		}
		.report-meta-side {
			font-size: 8px;
			line-height: 1.55;
			color: #374151;
			text-align: right;
		}
		.report-meta-side .report-meta-k { color: #4b5563; }
		.report-meta-side strong {
			font-size: 9px;
			color: #111827;
		}
		table.data {
			width: 100%;
			border-collapse: collapse;
			margin-top: 8px;
		}
		table.data th {
			background: #e2e8f0;
			text-align: left;
			padding: 6px 4px;
			border: 1px solid #94a3b8;
			font-size: 8px;
		}
		table.data td {
			padding: 5px 4px;
			border: 1px solid #cbd5e1;
			vertical-align: top;
		}
		table.data td.num {
			text-align: right;
			font-variant-numeric: tabular-nums;
		}
		table.data th.num {
			text-align: right;
			font-variant-numeric: tabular-nums;
		}
		@media print {
			body {
				background: #fff !important;
				padding: 8mm !important;
			}
			.report-print-chrome {
				display: none !important;
			}
			.report-padded-sheet {
				max-width: none;
				box-shadow: none;
				border: none;
				padding: 0;
				margin: 0;
			}
			@page {
				size: A4;
				margin: 12mm 12mm 18mm 12mm;
				@bottom-center {
					content: "Page " counter(page) " of " counter(pages);
					font-family: 'Noto Sans Bengali', 'DejaVu Sans', ui-sans-serif, system-ui, sans-serif;
					font-size: 9px;
					color: #64748b;
				}
			}
		}
	</style>
	@stack('print-extra-styles')
</head>
<body>
	@if ($showPrintChrome ?? true)
		<div class="report-print-chrome">
			<a href="{{ $printBackUrl ?? url()->previous() }}">{{ $printBackLabel ?? '← Back' }}</a>
			@if ($showReportsHubLink ?? true)
				<a href="{{ route('reports.index') }}">Reports hub</a>
			@endif
			<button type="button" onclick="window.print()">Print</button>
		</div>
	@endif

	<div class="report-padded-sheet">
		@include('reports.exports.partials.print-header', ['companyPad' => $companyPad, 'reportTitle' => $reportTitle, 'subtitle' => $subtitle])
		<div class="report-body">
			@yield('report-table')
		</div>
	</div>
	@include('reports.exports.partials.print-page-numbers')
</body>
</html>
