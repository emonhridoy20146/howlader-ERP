@extends('layouts.app')
@section('title', 'Sales Invoices')
@section('page-title', 'Sales Invoices')
@section('content')
<div class="mb-4 flex flex-col gap-2 sm:flex-row sm:flex-wrap sm:items-center sm:justify-end">
	@can('viewTrashed', \App\Models\SalesInvoice::class)
		<a href="{{ route('sales.invoices.trashed') }}" class="inline-flex justify-center rounded-lg border border-slate-300 bg-white px-4 py-2.5 text-sm font-medium text-slate-700 shadow-sm hover:bg-slate-50 sm:py-2">Deleted invoices</a>
	@endcan
	<a href="{{ route('sales.invoices.create') }}" class="inline-flex justify-center rounded-lg bg-slate-900 px-4 py-2.5 text-sm font-medium text-white shadow-sm hover:bg-slate-800 sm:py-2">New Invoice</a>
</div>

@if (session('status'))
	<div class="mb-4 rounded-lg bg-emerald-50 p-3 text-sm text-emerald-700">{{ session('status') }}</div>
@endif
@if ($errors->any())
	<div class="mb-4 rounded-lg border border-rose-200 bg-rose-50 p-3 text-sm text-rose-700">
		<ul class="list-disc pl-5">
			@foreach ($errors->all() as $error)
				<li>{{ $error }}</li>
			@endforeach
		</ul>
	</div>
@endif

<x-filter-drawer>
<form method="get" action="{{ route('sales.invoices.index') }}" class="flex flex-col gap-4">
	<div class="flex flex-col gap-4">
		<input type="text" name="q" value="{{ $filters['q'] ?? '' }}" placeholder="Search invoice / customer / phone" class="rounded-lg border border-slate-300 px-3 py-2 text-sm ">
		<select name="status" class="rounded-lg border border-slate-300 px-3 py-2 text-sm ">
			<option value="">All status</option>
			@foreach(['draft','active','posted','cancelled','returned'] as $status)
				<option value="{{ $status }}" @selected(($filters['status'] ?? '') === $status)>{{ ucfirst(str_replace('_', ' ', $status)) }}</option>
			@endforeach
		</select>
		<select name="payment_status" class="rounded-lg border border-slate-300 px-3 py-2 text-sm ">
			<option value="">All payment status</option>
			@foreach(['unpaid','partial','paid'] as $paymentStatus)
				<option value="{{ $paymentStatus }}" @selected(($filters['payment_status'] ?? '') === $paymentStatus)>{{ ucfirst(str_replace('_', ' ', $paymentStatus)) }}</option>
			@endforeach
		</select>
		<select name="sale_type" class="rounded-lg border border-slate-300 px-3 py-2 text-sm ">
			<option value="">All types</option>
			@foreach(['default','credit','installment'] as $saleType)
				<option value="{{ $saleType }}" @selected(($filters['sale_type'] ?? '') === $saleType)>{{ ucfirst($saleType) }}</option>
			@endforeach
		</select>
		<input type="date" name="date_from" value="{{ $filters['date_from'] ?? '' }}" class="rounded-lg border border-slate-300 px-3 py-2 text-sm ">
		<input type="date" name="date_to" value="{{ $filters['date_to'] ?? '' }}" class="rounded-lg border border-slate-300 px-3 py-2 text-sm ">
	</div>
	<x-index-filter-actions :reset-url="route('sales.invoices.index')" />
</form>
</x-filter-drawer>

<div class="mb-4 grid gap-3 sm:grid-cols-6">
	<x-status-card label="Total">
			{{ $kpis['total'] }}
		</x-status-card>
	<x-status-card label="Posted/Active">
			{{ $kpis['posted'] }}
		</x-status-card>
	<x-status-card label="Paid">
			{{ $kpis['paid'] }}
		</x-status-card>
	<x-status-card label="Filtered">
			{{ $kpis['filtered'] }}
		</x-status-card>
	<x-status-card label="Filtered Total">
			{{ number_format($kpis['filtered_total_amount'], 2) }}
		</x-status-card>
	<x-status-card label="Filtered Due">
			{{ number_format($kpis['filtered_due_amount'], 2) }}
		</x-status-card>
</div>

<x-index-table-shell title="Invoices" :meta="$invoices->total().' in this list'">
	<table class="index-data-table min-w-[56rem] sm:min-w-full whitespace-nowrap">
		<thead class="bg-slate-50 border-b border-slate-200 text-left text-xs font-semibold uppercase tracking-wider text-slate-700">
			<tr>
				<th class="px-2 py-3 w-10">SL</th>
				<th class="px-4 py-3">Invoice</th>
				<th class="px-4 py-3">Date</th>
				<th class="px-4 py-3">Created</th>
				<th class="px-4 py-3">Customer</th>
				<th class="px-4 py-3">Type</th>
				<th class="px-4 py-3">Unit Price</th>
				<th class="px-4 py-3">Line Total</th>
				<th class="px-4 py-3">Discount %</th>
				<th class="px-4 py-3">S. Charge</th>
				<th class="px-4 py-3">Total</th>
				<th class="px-4 py-3">Paid</th>
				<th class="px-4 py-3">Due</th>
				<th class="px-4 py-3">Status</th>
				<th class="px-4 py-3">Payment</th>
				<th class="px-4 py-3 text-right">Actions</th>
			</tr>
		</thead>
		<tbody>
			@forelse($invoices as $invoice)
				<tr class="border-b border-slate-100 transition-colors hover:bg-slate-50/70 last:border-0">
					<td class="px-2 py-3 w-10 text-xs text-slate-500">{{ $invoices->firstItem() + $loop->index }}</td>
					<td class="px-2 py-3 font-mono text-xs"><a href="{{ route('sales.invoices.show', $invoice) }}" class="hover:underline text-sm" style="color: {{ $invoice->invoice_number ? '#1C399D' : '#EA580C' }}">{{ $invoice->invoice_number ?? 'Pending payment' }}</a></td>
					<td class="px-2 py-3">{{ $invoice->invoice_date?->toDateString() }}</td>
					<td class="px-4 py-3">
						{{ $invoice->created_at?->toDateString() }}
						@if ($invoice->created_at)
							<div class="text-xs text-slate-500">{{ $invoice->created_at->format('g:i A') }}</div>
						@endif
					</td>
					<td class="px-2 py-3">
						@if ($invoice->customer)
							<a href="{{ route('customers.show', $invoice->customer) }}" class="font-medium hover:underline" style="color:#1C399D">{{ $invoice->customer->name }}</a>
						@else
							<span class="text-slate-400">—</span>
						@endif
						<div class="text-xs text-slate-500">{{ $invoice->customer?->phone }}</div>
					</td>
					<td class="px-2 py-3">
						@php
							$typeBadge = match($invoice->sale_type) {
								'default'     => 'bg-slate-100 text-slate-600',
								'credit'      => 'bg-blue-100 text-blue-700',
								'installment' => 'bg-violet-100 text-violet-700',
								default       => 'bg-slate-100 text-slate-600',
							};
						@endphp
						<span class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium {{ $typeBadge }}">
							{{ ucfirst($invoice->sale_type) }}
						</span>
					</td>
					<td class="px-2 py-3">
						@php
							$minUnitPrice = $invoice->lines_min_unit_price;
							$maxUnitPrice = $invoice->lines_max_unit_price;
						@endphp
						@if ($minUnitPrice === null && $maxUnitPrice === null)
							<span class="text-slate-400">N/A</span>
						@elseif ((float) $minUnitPrice === (float) $maxUnitPrice)
							{{ number_format((float) $minUnitPrice, 2) }}
						@else
							{{ number_format((float) $minUnitPrice, 2) }} - {{ number_format((float) $maxUnitPrice, 2) }}
						@endif
					</td>
					<td class="px-4 py-3">
						@php
							$lineDiscountTotal = (float) ($invoice->lines_sum_discount_amount ?? 0);
							$lineServiceChargeTotal = (float) ($invoice->lines_sum_service_charge ?? 0);
							$lineNetSubtotal = (float) ($invoice->lines_sum_line_total ?? 0);
							$lineGrossSubtotal = $lineNetSubtotal - $lineServiceChargeTotal + $lineDiscountTotal;
						@endphp
						{{ number_format($lineGrossSubtotal, 2) }}
					</td>
					<td class="px-2 py-3">
						@php
							$invoiceDiscountTotal = (float) ($invoice->discount_total ?? 0);
							$totalDiscount = $lineDiscountTotal + $invoiceDiscountTotal;
							$discountPercent = $lineGrossSubtotal > 0
								? round(($totalDiscount / $lineGrossSubtotal) * 100, 2)
								: null;
						@endphp
						@if ($discountPercent === null || $totalDiscount <= 0)
							<span class="text-slate-400">—</span>
						@else
							{{ rtrim(rtrim(number_format($discountPercent, 2), '0'), '.') }}%
							<div class="text-xs text-slate-500">{{ number_format($totalDiscount, 2) }}</div>
						@endif
					</td>
					<td class="px-2 py-3">
						@if ($lineServiceChargeTotal <= 0.009)
							<span class="text-slate-400">—</span>
						@else
							{{ number_format($lineServiceChargeTotal, 2) }}
						@endif
					</td>
					<td class="px-3 py-3 font-semibold text-slate-900">{{ number_format($invoice->grand_total, 2) }}</td>
					<td class="px-3 py-3">
						@if ($invoice->amount_paid <= 0.009)
							<span class="text-slate-400">—</span>
						@else
							<span class="text-emerald-700 font-medium">{{ number_format($invoice->amount_paid, 2) }}</span>
						@endif
					</td>
					<td class="px-2 py-3">
						@if ($invoice->amount_due <= 0.009)
							<span class="text-slate-400">—</span>
						@else
							<span class="text-orange-600 font-normal">{{ number_format($invoice->amount_due, 2) }}</span>
						@endif
					</td>
					<td class="px-2 py-3">{{ ucfirst(str_replace('_', ' ', $invoice->status)) }}</td>
					<td class="px-4 py-3">
						@php
							$paymentBadge = match($invoice->payment_status) {
								'paid'    => 'bg-emerald-100 text-emerald-700',
								'partial' => 'bg-orange-100 text-orange-700',
								'unpaid'  => 'bg-rose-100 text-rose-700',
								default   => 'bg-slate-100 text-slate-600',
							};
						@endphp
						<span class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium {{ $paymentBadge }}">
							{{ ucfirst(str_replace('_', ' ', $invoice->payment_status)) }}
						</span>
					</td>
					<td class="px-4 py-3 text-right">
						<x-index-row-actions-menu>
							<a href="{{ route('sales.invoices.show', $invoice) }}">View</a>
							@can('create', \App\Models\ReturnOrder::class)
								@if (in_array($invoice->status, ['posted', 'active'], true))
									<a href="{{ route('returns.index', ['sales_invoice_id' => $invoice->id]) }}">Start return</a>
								@endif
							@endcan
							@if($invoice->status === 'draft')
								<a href="{{ route('sales.invoices.edit', $invoice) }}">Edit</a>
							@endif
							@can('changeSaleType', $invoice)
								<a href="{{ route('sales.invoices.show', ['invoice' => $invoice, 'change_sale_type' => 1]) }}">Change payment type</a>
							@endcan
							@can('update-payment-method')
								@if (in_array($invoice->status, ['posted', 'active'], true) && $invoice->payments->isNotEmpty())
									@foreach ($invoice->payments as $payment)
										@php
											$branchMethods = $paymentMethodsByBranch[(int) $invoice->branch_id] ?? collect();
										@endphp
										@if (! $payment->skipsWalletGl() && $branchMethods->isNotEmpty())
											<form
												method="post"
												action="{{ route('sales.invoices.payments.update-method', [$invoice, $payment]) }}"
												class="border-t border-slate-100 px-3 py-2"
												@click.stop
											>
												@csrf
												@method('PATCH')
												<p class="mb-1 text-[10px] font-semibold uppercase tracking-wide text-slate-500">
													Change method{{ $invoice->payments->count() > 1 ? ' · '.$payment->payment_number : '' }}
												</p>
												<select name="payment_method_id" class="mb-1.5 w-full rounded-md border border-slate-300 px-2 py-1 text-xs" required>
													@foreach ($branchMethods as $method)
														<option value="{{ $method->id }}" @selected((int) $payment->payment_method_id === (int) $method->id)>{{ $method->name }}</option>
													@endforeach
												</select>
												<button type="submit" class="index-actions-menu-item w-full text-left text-xs font-semibold text-indigo-700 hover:text-indigo-900">Update payment method</button>
											</form>
										@endif
									@endforeach
								@endif
							@endcan
							@can('delete', $invoice)
								<form action="{{ route('sales.invoices.destroy', $invoice) }}" method="post" data-confirm="Move this invoice to the trash? It can be restored later.">
									@csrf
									@method('DELETE')
									<button type="submit" class="index-actions-menu-item--danger">Delete</button>
								</form>
							@endcan
						</x-index-row-actions-menu>
					</td>
				</tr>
			@empty
				<tr>
					<td colspan="16" class="px-4 py-12 text-center text-sm font-medium text-slate-500 bg-slate-50/50">No invoices found.</td>
				</tr>
			@endforelse
		</tbody>
	</table>
</x-index-table-shell>
	<x-pagination-limit-selector />

<div class="mt-4">{{ $invoices->links() }}</div>
@endsection
