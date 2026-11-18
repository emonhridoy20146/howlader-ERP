@extends('reports.exports.print-layout')

@section('report-table')
	<div style="margin-bottom:10px;padding:6px 8px;border:1px solid #94a3b8;background:#f8fafc;font-size:9px;">
		<strong>{{ number_format($kpis['listed']) }}</strong> categories ·
		<strong>{{ number_format($kpis['products']) }}</strong> products in listed categories
	</div>
	<table class="data">
		<thead>
			<tr>
				<th class="num">#</th>
				<th>Name</th>
				<th>Parent</th>
				<th class="num">Sort</th>
				<th class="num">Products</th>
			</tr>
		</thead>
		<tbody>
			@foreach ($categories as $index => $category)
				<tr>
					<td class="num">{{ $index + 1 }}</td>
					<td>{{ $category->name }}</td>
					<td>{{ $category->parent?->name ?: '—' }}</td>
					<td class="num">{{ $category->sort_order }}</td>
					<td class="num">{{ number_format((int) $category->products_count) }}</td>
				</tr>
			@endforeach
		</tbody>
	</table>
@endsection
