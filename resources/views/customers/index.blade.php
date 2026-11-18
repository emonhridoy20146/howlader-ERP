@extends('layouts.app')
@section('title', 'Customers')
@section('page-title', 'Customers')
@section('content')
@php
	$drawerMode = request('drawer');
	$isEdit = $drawerMode === 'edit' && !is_null($editingCustomer);
	$isDrawerOpen = in_array($drawerMode, ['create', 'edit'], true) || $errors->any();
	$customer = $isEdit ? $editingCustomer : new \App\Models\Customer();
@endphp

<div x-data="{
    drawerOpen: @js($isDrawerOpen),
    photoLightbox: { src: null, alt: '' },
    openCustomerPhoto(src, alt) {
        this.photoLightbox = { src, alt: alt || '' };
        document.body.classList.add('overflow-hidden');
    },
    closeCustomerPhoto() {
        this.photoLightbox = { src: null, alt: '' };
        document.body.classList.remove('overflow-hidden');
    },
}">
	<div class="mb-4 flex flex-col gap-2 sm:flex-row sm:flex-wrap sm:items-center sm:justify-end">
		<a href="{{ route('customers.index', ['drawer' => 'create']) }}" class="inline-flex justify-center rounded-lg bg-slate-900 px-4 py-2.5 text-sm font-medium text-white shadow-sm hover:bg-slate-800 sm:py-2">New Customer</a>
	</div>

	@if (session('status'))
		<div class="mb-4 rounded-lg bg-emerald-50 p-3 text-sm text-emerald-700">{{ session('status') }}</div>
	@endif

	<x-filter-drawer>
<form method="get" action="{{ route('customers.index') }}" class="flex flex-col gap-4">
		<div class="flex flex-col gap-4">
			<input type="text" name="code" value="{{ $filters['code'] ?? '' }}" placeholder="Customer code" class="rounded-lg border border-slate-300 px-3 py-2 text-sm font-mono">
			<input type="text" name="q" value="{{ $filters['q'] ?? '' }}" placeholder="Search name, phone, email" class="rounded-lg border border-slate-300 px-3 py-2 text-sm ">
			@php
				$branchFilterOptions = $branchesForForm->map(fn ($b) => ['value' => $b->id, 'label' => $b->name])->values()->all();
			@endphp
			<div>
				<label class="mb-1 block text-xs font-medium text-slate-600">Business branch</label>
				<x-searchable-select name="branch_id" :options="$branchFilterOptions" :selected="$filters['branch_id'] ?? ''" placeholder="All branches" />
			</div>
			@can('view-customer-risk')
				<select name="grade" class="rounded-lg border border-slate-300 px-3 py-2 text-sm">
					<option value="">All risk grades</option>
					@foreach (['A', 'B', 'C', 'D', 'E', 'N/A'] as $g)
						<option value="{{ $g }}" @selected(($filters['grade'] ?? '') === $g)>Grade {{ $g }}</option>
					@endforeach
				</select>
			@endcan
			<select name="is_active" class="rounded-lg border border-slate-300 px-3 py-2 text-sm">
				<option value="">All status</option>
				<option value="1" @selected(($filters['is_active'] ?? '') === '1')>Active</option>
				<option value="0" @selected(($filters['is_active'] ?? '') === '0')>Inactive</option>
			</select>
		</div>
		<x-index-filter-actions :reset-url="route('customers.index')" />
	</form>
</x-filter-drawer>

	<div class="mb-4 grid gap-3 sm:grid-cols-5">
		<x-status-card label="Total">
			{{ $kpis['total'] }}
		</x-status-card>
		<x-status-card label="Active">
			{{ $kpis['active'] }}
		</x-status-card>
		<x-status-card label="Inactive">
			{{ $kpis['inactive'] }}
		</x-status-card>
		<x-status-card label="Outstanding">
			{{ number_format($kpis['outstanding_total'], 2) }}
		</x-status-card>
		<x-status-card label="Filtered">
			{{ $kpis['filtered'] }}
		</x-status-card>
	</div>

	<x-index-table-shell title="Customers" :meta="$customers->total().' in this list'">
		<table class="index-data-table min-w-[40rem] sm:min-w-full whitespace-nowrap">
			<thead class="bg-slate-50 border-b border-slate-200 text-left text-xs font-semibold uppercase tracking-wider text-slate-700">
				<tr>
					<th class="px-4 py-3 w-14" aria-label="Photo"></th>
					<th class="px-4 py-3">Code</th>
					<th class="px-4 py-3">Name</th>
					<th class="px-4 py-3">Branch</th>
					<th class="px-4 py-3">Address</th>
					<th class="px-4 py-3">Phone</th>
					<th class="px-4 py-3">Outstanding</th>
					@can('view-customer-risk')<th class="px-4 py-3">Risk</th>@endcan
					<th class="px-4 py-3 text-right">Actions</th>
				</tr>
			</thead>
			<tbody>
				@forelse ($customers as $rowCustomer)
					<tr class="border-b border-slate-100 transition-colors hover:bg-slate-50/70 last:border-0">
						<td class="px-4 py-3 align-middle">
							@if ($rowCustomer->photoUrl())
								<button
									type="button"
									class="inline-flex rounded-lg focus:outline-none focus-visible:ring-2 focus-visible:ring-emerald-500 focus-visible:ring-offset-2"
									@click="openCustomerPhoto(@js($rowCustomer->photoUrl()), @js($rowCustomer->name))"
									title="View photo"
								>
									<img src="{{ $rowCustomer->photoUrl() }}" alt="{{ $rowCustomer->name }}" class="h-10 w-10 cursor-pointer rounded-lg bg-slate-100 object-cover ring-1 ring-slate-200 transition hover:ring-2 hover:ring-emerald-400/70">
								</button>
							@else
								@php
									$nm = trim((string) $rowCustomer->name);
									$initial = $nm !== '' ? mb_strtoupper(mb_substr($nm, 0, 1)) : '?';
								@endphp
								<span class="inline-flex h-10 w-10 items-center justify-center rounded-lg bg-slate-100 text-sm font-semibold text-slate-500 ring-1 ring-slate-200" title="No photo">{{ $initial }}</span>
							@endif
						</td>
						<td class="px-4 py-3 font-mono">{{ $rowCustomer->customer_code }}</td>
						<td class="px-4 py-3 font-medium"><a href="{{ route('customers.show', $rowCustomer) }}" class="text-indigo-700 hover:underline">{{ $rowCustomer->name }}</a></td>
						<td class="px-4 py-3">
							@if ($rowCustomer->branch_id && $rowCustomer->branch)
								<p class="font-medium text-slate-800">{{ $rowCustomer->branch->name }}</p>
								<p class="text-xs text-slate-500 font-mono">{{ $rowCustomer->branch->code }}</p>
							@else
								<span class="text-slate-400">—</span>
							@endif
						</td>
						<td class="px-4 py-3 max-w-[14rem] whitespace-normal text-sm text-slate-600">
							<span class="line-clamp-2" title="{{ $rowCustomer->address }}">{{ $rowCustomer->address ? $rowCustomer->address : '—' }}</span>
						</td>
						<td class="px-4 py-3">{{ implode(', ', $rowCustomer->normalizedPhoneNumbers()) }}</td>
						<td class="px-4 py-3">
						@if ((float) $rowCustomer->outstanding_balance > 0.009)
							<span class="font-medium text-rose-600">{{ number_format($rowCustomer->outstanding_balance, 2) }}</span>
						@elseif ((float) $rowCustomer->outstanding_balance < -0.009)
							<span class="font-medium text-emerald-700">{{ number_format($rowCustomer->outstanding_balance, 2) }}</span>
						@else
							<span class="text-slate-400">—</span>
						@endif
					</td>
						@can('view-customer-risk')
							<td class="px-4 py-3"><x-risk-grade-badge :snapshot="$rowCustomer->riskSnapshot" /></td>
						@endcan
						<td class="px-4 py-3 text-right">
							@can('view-customer-risk')
								<a href="{{ route('customers.risk', $rowCustomer) }}" class="mr-1 inline-flex rounded-lg border border-indigo-200 bg-indigo-50 px-2.5 py-1 text-xs font-medium text-indigo-700 hover:bg-indigo-100">Risk Profile</a>
							@endcan
							<x-index-row-actions-menu>
								<a href="{{ route('customers.show', $rowCustomer) }}">View</a>
								@can('view-customer-risk')
									<a href="{{ route('customers.risk', $rowCustomer) }}">Risk Profile</a>
								@endcan
								<a href="{{ route('customers.index', ['drawer' => 'edit', 'edit' => $rowCustomer->id]) }}">Edit</a>
							</x-index-row-actions-menu>
						</td>
					</tr>
				@empty
					<tr><td colspan="9" class="px-4 py-12 text-center text-sm font-medium text-slate-500 bg-slate-50/50">No customers found.</td></tr>
				@endforelse
			</tbody>
		</table>
	</x-index-table-shell>
	<x-pagination-limit-selector />

	<div class="mt-4">{{ $customers->links() }}</div>

	<div x-cloak x-show="drawerOpen" class="fixed inset-0 z-40">
		<a href="{{ route('customers.index') }}" class="absolute inset-0 bg-slate-900/40" aria-label="Close"></a>
		<div class="absolute inset-y-0 right-0 w-full max-w-xl overflow-y-auto bg-white shadow-2xl">
			<div class="sticky top-0 flex items-center justify-between border-b border-slate-200 bg-white px-6 py-4">
				<h2 class="text-lg font-semibold text-slate-900">{{ $isEdit ? 'Edit Customer' : 'New Customer' }}</h2>
				<a href="{{ route('customers.index') }}" class="text-sm text-slate-600">Close</a>
			</div>
			<div class="p-6">
				<form action="{{ $isEdit ? route('customers.update', $customer) : route('customers.store') }}" method="post" class="space-y-4" enctype="multipart/form-data">
					@if ($isEdit)
						@method('PUT')
					@endif
					@include('customers._form')
				</form>
			</div>
		</div>
	</div>
	@include('customers.partials.photo-lightbox')
</div>
@endsection
