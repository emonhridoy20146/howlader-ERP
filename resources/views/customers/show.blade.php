@extends('layouts.app')

@section('title', 'Customer Details')
@section('page-title', 'Customer Details')

@section('content')
<div
	class="space-y-5"
	x-data="{
		photoLightbox: { src: null, alt: '' },
		openCustomerPhoto(src, alt) {
			this.photoLightbox = { src, alt: alt || '' };
			document.body.classList.add('overflow-hidden');
		},
		closeCustomerPhoto() {
			this.photoLightbox = { src: null, alt: '' };
			document.body.classList.remove('overflow-hidden');
		},
	}"
>
    @php $phones = $customer->normalizedPhoneNumbers(); @endphp

    <section class="rounded-2xl bg-white p-5 shadow-sm ring-1 ring-slate-200">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <div class="flex min-w-0 flex-1 flex-wrap items-start gap-4">
                @if ($customer->photoUrl())
                    <button
                        type="button"
                        class="inline-flex shrink-0 rounded-2xl focus:outline-none focus-visible:ring-2 focus-visible:ring-emerald-500 focus-visible:ring-offset-2"
                        @click="openCustomerPhoto(@js($customer->photoUrl()), @js($customer->name))"
                        title="View photo"
                    >
                        <img src="{{ $customer->photoUrl() }}" alt="{{ $customer->name }}" class="h-24 w-24 cursor-pointer rounded-2xl bg-slate-100 object-cover ring-1 ring-slate-200 transition hover:ring-2 hover:ring-emerald-400/70">
                    </button>
                @endif
                <div class="min-w-0">
                <p class="text-xs uppercase tracking-[0.2em] text-slate-500">Customer</p>
                <h2 class="mt-1 text-2xl font-semibold text-slate-900">{{ $customer->name }}</h2>
                <p class="mt-1 text-sm text-slate-600">Code: <span class="font-mono text-slate-900">{{ $customer->customer_code }}</span></p>
                <p class="mt-2 text-sm text-slate-600">
                    Business branch:
                    @if ($customer->branch)
                        <span class="font-medium text-slate-800">{{ $customer->branch->name }}</span>
                        <span class="font-mono text-slate-500">· {{ $customer->branch->code }}</span>
                    @else
                        <span class="font-medium text-slate-500">—</span>
                    @endif
                </p>
                <p class="mt-2 text-sm text-slate-600">
                    Phone: <span class="font-medium text-slate-800">{{ count($phones) ? implode(', ', $phones) : '—' }}</span>
                    · Email: <span class="font-medium text-slate-800">{{ $customer->email ?: '—' }}</span>
                </p>
            </div>
            </div>
            <div class="text-right text-sm text-slate-600">
                <p>Status: <span class="font-semibold {{ $customer->is_active ? 'text-emerald-700' : 'text-slate-500' }}">{{ $customer->is_active ? 'Active' : 'Inactive' }}</span></p>
                <p>Outstanding: <span class="font-semibold text-slate-900">{{ number_format((float) $customer->outstanding_balance, 2) }}</span></p>
            </div>
        </div>
        <div class="mt-4 flex flex-wrap gap-2">
            <a href="{{ route('customers.index') }}" class="rounded-lg border border-slate-300 px-3 py-1.5 text-sm text-slate-700 hover:bg-slate-100">Back</a>
            <a href="{{ route('customers.index', ['drawer' => 'edit', 'edit' => $customer->id]) }}" class="rounded-lg border border-blue-200 bg-blue-50 px-3 py-1.5 text-sm font-medium text-blue-700 hover:bg-blue-100">Edit Customer</a>
            @can('view-customer-risk')
                <a href="{{ route('customers.risk', $customer) }}" class="rounded-lg border border-indigo-200 bg-indigo-50 px-3 py-1.5 text-sm font-medium text-indigo-700 hover:bg-indigo-100">Profile &amp; Risk</a>
            @endcan
        </div>
        <dl class="mt-4 grid gap-3 rounded-xl bg-slate-50 p-4 text-sm sm:grid-cols-2 lg:grid-cols-4">
            <div>
                <dt class="text-slate-500">ID type</dt>
                <dd class="font-medium text-slate-900">{{ $customer->id_type ?: '—' }}</dd>
            </div>
            <div>
                <dt class="text-slate-500">ID number</dt>
                <dd class="font-medium text-slate-900">{{ $customer->id_number ?: '—' }}</dd>
            </div>
            <div>
                <dt class="text-slate-500">Created</dt>
                <dd class="font-medium text-slate-900">{{ optional($customer->created_at)->format('Y-m-d H:i') ?: '—' }}</dd>
            </div>
            <div>
                <dt class="text-slate-500">Updated</dt>
                <dd class="font-medium text-slate-900">{{ optional($customer->updated_at)->format('Y-m-d H:i') ?: '—' }}</dd>
            </div>
        </dl>
        @if ($customer->address)
            <p class="mt-4 rounded-lg bg-slate-50 p-3 text-sm text-slate-700"><span class="font-medium text-slate-800">Address · </span>{{ $customer->address }}</p>
        @endif

        @can('viewTracking', $customer)
            <div class="mt-4 rounded-xl border border-emerald-200 bg-emerald-50/70 p-4">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <p class="text-xs font-semibold uppercase tracking-[0.16em] text-emerald-900">Location tracking</p>
                        @if ($customer->hasTrackingLocation())
                            <p class="mt-2 text-sm text-emerald-950">
                                Map pin saved
                                <span class="font-mono text-xs">({{ number_format((float) $customer->latitude, 6) }}, {{ number_format((float) $customer->longitude, 6) }})</span>
                            </p>
                            @if ($customer->detailed_address)
                                <p class="mt-1 text-sm text-emerald-900">{{ \Illuminate\Support\Str::limit($customer->detailed_address, 120) }}</p>
                            @endif
                        @else
                            <p class="mt-2 text-sm text-emerald-900">No house location saved yet.</p>
                        @endif
                    </div>
                    <div class="flex flex-wrap gap-2">
                        <a href="{{ route('customer-tracking.show', $customer) }}" class="rounded-lg border border-emerald-300 bg-white px-3 py-1.5 text-sm font-medium text-emerald-900 hover:bg-emerald-100">View tracking</a>
                        @can('updateTracking', $customer)
                            <a href="{{ route('customer-tracking.edit', $customer) }}" class="rounded-lg border border-emerald-400 bg-emerald-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-emerald-700">Edit tracking</a>
                        @endcan
                        @if ($customer->googleMapsUrl())
                            <a href="{{ $customer->googleMapsUrl() }}" target="_blank" rel="noopener noreferrer" class="rounded-lg border border-emerald-300 bg-white px-3 py-1.5 text-sm text-emerald-900 hover:bg-emerald-100">Google Maps</a>
                        @endif
                    </div>
                </div>
                @if ($customer->houseImageUrl() || $customer->locationImageUrl())
                    <div class="mt-3 flex flex-wrap gap-3">
                        @if ($customer->houseImageUrl())
                            <img src="{{ $customer->houseImageUrl() }}" alt="House photo" class="h-20 w-28 rounded-lg object-cover ring-1 ring-emerald-200">
                        @endif
                        @if ($customer->locationImageUrl())
                            <img src="{{ $customer->locationImageUrl() }}" alt="Location photo" class="h-20 w-28 rounded-lg object-cover ring-1 ring-emerald-200">
                        @endif
                    </div>
                @endif
            </div>
        @endcan

        @if ($customer->nid_path || ($customer->attachments && count($customer->attachments) > 0))
            <div class="mt-4 border-t border-slate-100 pt-4 text-sm">
                <span class="font-semibold text-slate-800 block uppercase tracking-wider text-xs">Verification Documents</span>
                <div class="mt-2 flex flex-wrap gap-3">
                    @if ($customer->nid_path)
                        <div class="rounded-lg border border-slate-200 bg-slate-50 px-3 py-2 flex items-center gap-1.5 text-xs">
                            <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4 text-indigo-600" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
                            <a href="{{ $customer->nidUrl() }}" target="_blank" class="font-medium text-indigo-700 hover:text-indigo-900 underline truncate max-w-[250px]" title="{{ $customer->nid_path }}">
                                View NID ({{ basename($customer->nid_path) }})
                            </a>
                        </div>
                    @endif
                    @if ($customer->attachments && count($customer->attachments) > 0)
                        @foreach ($customer->attachments as $idx => $path)
                            <div class="rounded-lg border border-slate-200 bg-slate-50 px-3 py-2 flex items-center gap-1.5 text-xs">
                                <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4 text-indigo-600" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13.828 10.172a4 4 0 00-5.656 0l-4 4a4 4 0 105.656 5.656l1.102-1.101m-.758-4.899a4 4 0 005.656 0l4-4a4 4 0 00-5.656-5.656l-1.1 1.1"/></svg>
                                <a href="{{ Storage::disk('public')->url($path) }}" target="_blank" class="font-medium text-indigo-700 hover:text-indigo-900 underline truncate max-w-[250px]" title="{{ $path }}">
                                    Attachment #{{ $idx + 1 }} ({{ basename($path) }})
                                </a>
                            </div>
                        @endforeach
                    @endif
                </div>
            </div>
        @endif
    </section>

    @if (! empty($customerActivityScopeNote))
        <div class="rounded-xl border border-sky-200 bg-sky-50/80 px-4 py-3 text-sm text-sky-950" role="status">
            {{ $customerActivityScopeNote }}
        </div>
    @endif

    @if ($customer->defaultGuarantor)
        <section class="rounded-2xl bg-white p-5 shadow-sm ring-1 ring-slate-200">
            <h3 class="text-sm font-semibold uppercase tracking-[0.16em] text-slate-700">Default guarantor</h3>
            <div class="mt-3 flex flex-wrap items-start justify-between gap-3">
                <div>
                    <a href="{{ route('customers.show', $customer->defaultGuarantor) }}" class="font-semibold text-indigo-700 hover:text-indigo-900 underline">
                        {{ $customer->defaultGuarantor->name }}
                    </a>
                    <p class="mt-1 text-sm text-slate-600">
                        Code <span class="font-mono">{{ $customer->defaultGuarantor->customer_code }}</span>
                        · Phone {{ $customer->defaultGuarantor->phone }}
                    </p>
                </div>
                <a href="{{ route('customers.index', ['drawer' => 'edit', 'edit' => $customer->id]) }}" class="rounded-lg border border-slate-300 px-3 py-1.5 text-sm text-slate-700 hover:bg-slate-100">Change guarantor</a>
            </div>
        </section>
    @endif

    @if (($attachableInstallmentInvoices ?? collect())->isNotEmpty() && $customer->defaultGuarantor)
        <section class="rounded-2xl bg-white p-5 shadow-sm ring-1 ring-slate-200">
            <h3 class="text-sm font-semibold uppercase tracking-[0.16em] text-slate-700">Attach guarantor to invoice</h3>
            <p class="mt-1 text-sm text-slate-600">Installment invoices for this customer that do not have a guarantor yet.</p>
            <ul class="mt-4 divide-y divide-slate-100 rounded-xl border border-slate-200">
                @foreach ($attachableInstallmentInvoices as $attachableInvoice)
                    <li class="flex flex-wrap items-center justify-between gap-3 px-4 py-3 text-sm">
                        <div>
                            <a href="{{ route('sales.invoices.show', $attachableInvoice) }}" class="font-medium text-slate-900 hover:underline">
                                {{ $attachableInvoice->invoice_number ?: 'Draft #'.$attachableInvoice->id }}
                            </a>
                            <p class="text-xs text-slate-500">{{ $attachableInvoice->invoice_date?->format('M j, Y') }} · {{ ucfirst($attachableInvoice->status) }} · {{ number_format((float) $attachableInvoice->grand_total, 2) }}</p>
                        </div>
                        <form method="post" action="{{ route('customers.attach-guarantor-to-invoice', $customer) }}">
                            @csrf
                            <input type="hidden" name="invoice_id" value="{{ $attachableInvoice->id }}">
                            <button type="submit" class="rounded-lg bg-indigo-600 px-3 py-1.5 text-xs font-medium text-white hover:bg-indigo-700">Attach guarantor</button>
                        </form>
                    </li>
                @endforeach
            </ul>
        </section>
    @endif

    <section class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
        <div class="rounded-2xl bg-white p-4 shadow-sm ring-1 ring-slate-200">
            <p class="text-xs uppercase tracking-[0.16em] text-slate-500">Sales Invoices</p>
            <p class="mt-2 text-2xl font-semibold text-slate-900">{{ $totals['sales_invoices'] }}</p>
        </div>
        <div class="rounded-2xl bg-white p-4 shadow-sm ring-1 ring-slate-200">
            <p class="text-xs uppercase tracking-[0.16em] text-slate-500">Sales Total</p>
            <p class="mt-2 text-2xl font-semibold text-slate-900">{{ number_format($totals['sales_grand_total'], 2) }}</p>
        </div>
        <div class="rounded-2xl bg-amber-50/70 p-4 shadow-sm ring-1 ring-amber-200">
            <p class="text-xs uppercase tracking-[0.16em] text-amber-900">Sales Due</p>
            <p class="mt-2 text-2xl font-semibold text-amber-950">{{ number_format($totals['sales_due_total'], 2) }}</p>
        </div>
        <div class="rounded-2xl bg-white p-4 shadow-sm ring-1 ring-slate-200">
            <p class="text-xs uppercase tracking-[0.16em] text-slate-500">Open Service Jobs</p>
            <p class="mt-2 text-2xl font-semibold text-slate-900">{{ $totals['service_jobs_open'] }} / {{ $totals['service_jobs'] }}</p>
        </div>
    </section>

    <section class="rounded-2xl bg-white p-5 shadow-sm ring-1 ring-slate-200">
        <h3 class="text-base font-semibold text-slate-900">Sales Invoices</h3>
        <div class="mt-3 overflow-x-auto">
            <table class="min-w-full whitespace-nowrap text-sm">
                <thead class="border-b border-slate-200 bg-slate-50 text-left text-xs font-semibold uppercase tracking-wider text-slate-700">
                    <tr>
                        <th class="px-3 py-2">Invoice</th>
                        <th class="px-3 py-2">Date</th>
                        <th class="px-3 py-2">Branch</th>
                        <th class="px-3 py-2">Status</th>
                        <th class="px-3 py-2 text-right">Grand total</th>
                        <th class="px-3 py-2 text-right">Due</th>
                        <th class="px-3 py-2 text-right">Action</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($salesInvoices as $invoice)
                        <tr class="border-b border-slate-100 transition-colors hover:bg-slate-50/70 last:border-0">
                            <td class="px-3 py-2 font-mono">{{ $invoice->invoice_number }}</td>
                            <td class="px-3 py-2">{{ optional($invoice->invoice_date)->format('Y-m-d') }}</td>
                            <td class="px-3 py-2">{{ $invoice->branch?->name }}</td>
                            <td class="px-3 py-2">
                                @php
                                    $invoiceStatusBadge = match($invoice->status) {
                                        'draft'     => 'bg-slate-100 text-slate-600',
                                        'posted'    => 'bg-blue-100 text-blue-700',
                                        'active'    => 'bg-emerald-100 text-emerald-700',
                                        'cancelled' => 'bg-rose-100 text-rose-700',
                                        'returned'  => 'bg-amber-100 text-amber-700',
                                        default     => 'bg-slate-100 text-slate-600',
                                    };
                                @endphp
                                <span class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium {{ $invoiceStatusBadge }}">
                                    {{ ucfirst(str_replace('_', ' ', (string) $invoice->status)) }}
                                </span>
                            </td>
                            <td class="px-3 py-2 text-right">{{ number_format((float) $invoice->grand_total, 2) }}</td>
                            <td class="px-3 py-2 text-right font-medium {{ (float) $invoice->amount_due > 0 ? 'text-amber-800' : 'text-slate-500' }}">{{ number_format((float) $invoice->amount_due, 2) }}</td>
                            <td class="px-3 py-2 text-right">
                                <a href="{{ route('sales.invoices.show', $invoice) }}" class="text-blue-600 hover:underline">View Invoice</a>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="7" class="px-3 py-4 text-center text-slate-500">No invoices found for this customer.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="mt-4">{{ $salesInvoices->links() }}</div>
    </section>

    <section class="rounded-2xl bg-white p-5 shadow-sm ring-1 ring-slate-200">
        <h3 class="text-base font-semibold text-slate-900">Returns</h3>
        <p class="mt-1 text-xs text-slate-500">Total return refund due: {{ number_format($totals['refund_due_total'], 2) }}</p>
        <div class="mt-3 overflow-x-auto">
            <table class="min-w-full whitespace-nowrap text-sm">
                <thead class="border-b border-slate-200 bg-slate-50 text-left text-xs font-semibold uppercase tracking-wider text-slate-700">
                    <tr>
                        <th class="px-3 py-2">Return</th>
                        <th class="px-3 py-2">Date</th>
                        <th class="px-3 py-2">Invoice</th>
                        <th class="px-3 py-2">Branch</th>
                        <th class="px-3 py-2">Status</th>
                        <th class="px-3 py-2 text-right">Refund due</th>
                        <th class="px-3 py-2 text-right">Action</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($returnOrders as $returnOrder)
                        <tr class="border-b border-slate-100 transition-colors hover:bg-slate-50/70 last:border-0">
                            <td class="px-3 py-2 font-mono">{{ $returnOrder->return_number }}</td>
                            <td class="px-3 py-2">{{ optional($returnOrder->return_date)->format('Y-m-d') }}</td>
                            <td class="px-3 py-2">{{ $returnOrder->salesInvoice?->invoice_number ?: '—' }}</td>
                            <td class="px-3 py-2">{{ $returnOrder->branch?->name }}</td>
                            <td class="px-3 py-2">{{ ucfirst((string) $returnOrder->status) }}</td>
                            <td class="px-3 py-2 text-right">{{ number_format((float) $returnOrder->refund_due_amount, 2) }}</td>
                            <td class="px-3 py-2 text-right">
                                <a href="{{ route('returns.show', $returnOrder) }}" class="text-blue-600 hover:underline">View Return</a>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="7" class="px-3 py-4 text-center text-slate-500">No returns found for this customer.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="mt-4">{{ $returnOrders->links() }}</div>
    </section>

    <section class="rounded-2xl bg-white p-5 shadow-sm ring-1 ring-slate-200">
        <h3 class="text-base font-semibold text-slate-900">Service Jobs</h3>
        <div class="mt-3 overflow-x-auto">
            <table class="min-w-full whitespace-nowrap text-sm">
                <thead class="border-b border-slate-200 bg-slate-50 text-left text-xs font-semibold uppercase tracking-wider text-slate-700">
                    <tr>
                        <th class="px-3 py-2">Job</th>
                        <th class="px-3 py-2">Intake</th>
                        <th class="px-3 py-2">Branch</th>
                        <th class="px-3 py-2">Status</th>
                        <th class="px-3 py-2 text-right">Repair cost</th>
                        <th class="px-3 py-2 text-right">Action</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($serviceJobs as $job)
                        <tr class="border-b border-slate-100 transition-colors hover:bg-slate-50/70 last:border-0">
                            <td class="px-3 py-2 font-mono">{{ $job->job_number }}</td>
                            <td class="px-3 py-2">{{ optional($job->intake_date)->format('Y-m-d') }}</td>
                            <td class="px-3 py-2">{{ $job->branch?->name }}</td>
                            <td class="px-3 py-2">{{ ucfirst((string) $job->status) }}</td>
                            <td class="px-3 py-2 text-right">{{ number_format((float) $job->repair_cost, 2) }}</td>
                            <td class="px-3 py-2 text-right">
                                <a href="{{ route('service-jobs.show', $job) }}" class="text-blue-600 hover:underline">View Job</a>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="px-3 py-4 text-center text-slate-500">No service jobs found for this customer.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="mt-4">{{ $serviceJobs->links() }}</div>
    </section>
    @include('customers.partials.photo-lightbox')
</div>
@endsection
