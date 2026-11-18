@php
	$logoPath = $companyPad['company_pad_logo_path'] ?? '';
	$logoSrc = ($logoPath !== '' && file_exists(public_path('storage/'.$logoPath)))
		? asset('storage/'.$logoPath)
		: null;
	$reportHeading = $reportTitle ?? 'Report';
	$companyName = $companyPad['company_pad_name'] ?? config('app.name');
@endphp
<header class="report-pad-header">
	<table class="report-pad-header-table" role="presentation">
		<tr>
			<td class="report-pad-left">
				<p class="report-heading-small">{{ $reportHeading }}</p>
				<p class="report-brand-name">{{ $companyName }}</p>
				@if (! empty($companyPad['company_pad_tagline']))
					<p class="report-tagline">{{ $companyPad['company_pad_tagline'] }}</p>
				@endif
				<div class="report-company-block">
					@if (! empty($companyPad['company_pad_address']))
						{{ $companyPad['company_pad_address'] }}<br>
					@endif
					@if (! empty($companyPad['company_pad_phone']))
						<span class="report-meta-k">Tel:</span> {{ $companyPad['company_pad_phone'] }}
					@endif
					@if (! empty($companyPad['company_pad_phone']) && ! empty($companyPad['company_pad_email']))
						<br>
					@endif
					@if (! empty($companyPad['company_pad_email']))
						{{ $companyPad['company_pad_email'] }}
					@endif
					@if (! empty($companyPad['company_pad_website']))
						<br>{{ $companyPad['company_pad_website'] }}
					@endif
				</div>
			</td>
			<td class="report-pad-right">
				@if ($logoSrc)
					<img class="report-logo" src="{{ $logoSrc }}" alt="">
				@endif
				<div class="report-meta-side">
					<strong>Report details</strong><br>
					<span class="report-meta-k">{{ $subtitle }}</span>
				</div>
			</td>
		</tr>
	</table>
</header>
