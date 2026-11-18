@if ($items->isNotEmpty())
    <section>
        <h2 class="text-xs font-semibold uppercase tracking-[0.2em] text-slate-500">{{ $title }}</h2>
        <ul class="mt-2 divide-y divide-slate-200 overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm">
            @foreach ($items as $item)
                <li>
                    @if ($type === 'page')
                        <a href="{{ $item['url'] }}" class="flex flex-col gap-0.5 px-4 py-3 text-sm hover:bg-slate-50 sm:flex-row sm:items-center sm:justify-between">
                            <span class="font-medium text-slate-900">{{ $item['label'] }}</span>
                            <span class="text-slate-500">{{ $item['subtitle'] }}</span>
                        </a>
                    @elseif ($type === 'product')
                        <a href="{{ route('products.show', $item) }}" class="flex flex-col gap-0.5 px-4 py-3 text-sm hover:bg-slate-50 sm:flex-row sm:items-center sm:justify-between">
                            <span class="font-medium text-slate-900">{{ $item->name }}</span>
                            <span class="text-slate-500">{{ $item->sku }}@if (! $item->is_active) · deactivated @endif</span>
                        </a>
                    @elseif ($type === 'customer')
                        <a href="{{ route('customers.show', $item) }}" class="flex flex-col gap-0.5 px-4 py-3 text-sm hover:bg-slate-50 sm:flex-row sm:items-center sm:justify-between">
                            <span class="font-medium text-slate-900">{{ $item->name }}</span>
                            <span class="text-slate-500">{{ $item->customer_code ?? '—' }}@if ($item->phone) · {{ $item->phone }} @endif</span>
                        </a>
                        @can('view-customer-risk')
                            <a href="{{ route('customers.risk', $item) }}" class="flex items-center gap-2 px-4 pb-3 text-xs font-medium text-indigo-700 hover:underline">
                                Risk profile: {{ $item->name }} <x-risk-grade-badge :snapshot="$item->riskSnapshot" />
                            </a>
                        @endcan
                    @elseif ($type === 'supplier')
                        <a href="{{ route('suppliers.edit', $item) }}" class="block px-4 py-3 text-sm font-medium text-slate-900 hover:bg-slate-50">{{ $item->name }}</a>
                    @elseif ($type === 'brand')
                        <a href="{{ route('brands.edit', $item) }}" class="block px-4 py-3 text-sm font-medium text-slate-900 hover:bg-slate-50">{{ $item->name }}</a>
                    @elseif ($type === 'category')
                        <a href="{{ route('categories.edit', $item) }}" class="block px-4 py-3 text-sm font-medium text-slate-900 hover:bg-slate-50">{{ $item->name }}</a>
                    @elseif ($type === 'location')
                        <a href="{{ route('locations.show', $item) }}" class="flex flex-col gap-0.5 px-4 py-3 text-sm hover:bg-slate-50 sm:flex-row sm:items-center sm:justify-between">
                            <span class="font-medium text-slate-900">{{ $item->name }}</span>
                            <span class="text-slate-500">{{ $item->code ?? '' }}</span>
                        </a>
                    @elseif ($type === 'invoice')
                        <a href="{{ route('sales.invoices.show', $item) }}" class="flex flex-col gap-0.5 px-4 py-3 text-sm hover:bg-slate-50 sm:flex-row sm:items-center sm:justify-between">
                            <span class="font-medium text-slate-900">{{ $item->invoice_number }}</span>
                            <span class="text-slate-500">{{ $item->customer?->name ?? '—' }} · {{ $item->invoice_date?->format('M j, Y') }}</span>
                        </a>
                    @elseif ($type === 'installment')
                        <a href="{{ route('installments.show', $item) }}" class="flex flex-col gap-0.5 px-4 py-3 text-sm hover:bg-slate-50 sm:flex-row sm:items-center sm:justify-between">
                            <span class="font-medium text-slate-900">{{ $item->salesInvoice?->invoice_number ?? 'Plan #'.$item->id }}</span>
                            <span class="text-slate-500">{{ $item->salesInvoice?->customer?->name ?? '—' }} · {{ str_replace('_', ' ', $item->status) }}</span>
                        </a>
                    @elseif ($type === 'po')
                        <a href="{{ route('purchase-orders.show', $item) }}" class="flex flex-col gap-0.5 px-4 py-3 text-sm hover:bg-slate-50 sm:flex-row sm:items-center sm:justify-between">
                            <span class="font-medium text-slate-900">{{ $item->po_number }}</span>
                            <span class="text-slate-500">{{ $item->supplier?->name ?? '—' }} · {{ str_replace('_', ' ', $item->status) }}</span>
                        </a>
                    @elseif ($type === 'grn')
                        <a href="{{ route('goods-receipts.show', $item) }}" class="flex flex-col gap-0.5 px-4 py-3 text-sm hover:bg-slate-50 sm:flex-row sm:items-center sm:justify-between">
                            <span class="font-medium text-slate-900">{{ $item->grn_number }}</span>
                            <span class="text-slate-500">{{ $item->supplier?->name ?? '—' }} · {{ str_replace('_', ' ', $item->status) }}</span>
                        </a>
                    @elseif ($type === 'transfer')
                        <a href="{{ route('transfers.show', $item) }}" class="flex flex-col gap-0.5 px-4 py-3 text-sm hover:bg-slate-50 sm:flex-row sm:items-center sm:justify-between">
                            <span class="font-medium text-slate-900">{{ $item->transfer_number }}</span>
                            <span class="text-slate-500">{{ str_replace('_', ' ', $item->status) }}</span>
                        </a>
                    @elseif ($type === 'serial')
                        @php
                            $serialSearchUser = auth()->user();
                            $serialOpensProduct = $serialSearchUser
                                && $serialSearchUser->can('globalSearchView', \App\Models\Product::class)
                                && ! $serialSearchUser->can('viewAny', \App\Models\SerialItem::class)
                                && $item->product;
                            $serialHref = $serialOpensProduct
                                ? route('products.show', $item->product)
                                : route('serial-items.show', $item);
                            $serialLocation = $item->relationLoaded('location') ? $item->location : null;
                            $serialLocationLabel = $serialLocation
                                ? trim(($serialLocation->code ? $serialLocation->code.' · ' : '').($serialLocation->name ?? ''))
                                : '';
                        @endphp
                        <a href="{{ $serialHref }}" class="block px-4 py-3 text-sm hover:bg-slate-50">
                            <p class="font-medium text-slate-900">{{ $item->serial_number }}</p>
                            <p class="text-slate-500">{{ $item->product?->name ?? '—' }} · {{ str_replace('_', ' ', $item->status) }}@if ($serialLocationLabel !== '') · {{ $serialLocationLabel }}@endif</p>
                        </a>
                    @elseif ($type === 'return')
                        <a href="{{ route('returns.show', $item) }}" class="flex flex-col gap-0.5 px-4 py-3 text-sm hover:bg-slate-50 sm:flex-row sm:items-center sm:justify-between">
                            <span class="font-medium text-slate-900">{{ $item->return_number }}</span>
                            <span class="text-slate-500">{{ $item->customer?->name ?? '—' }}</span>
                        </a>
                    @elseif ($type === 'service_job')
                        <a href="{{ route('service-jobs.show', $item) }}" class="flex flex-col gap-0.5 px-4 py-3 text-sm hover:bg-slate-50 sm:flex-row sm:items-center sm:justify-between">
                            <span class="font-medium text-slate-900">{{ $item->job_number }}</span>
                            <span class="text-slate-500">{{ $item->serialItem?->serial_number ?? '—' }} · {{ str_replace('_', ' ', $item->status) }}</span>
                        </a>
                    @elseif ($type === 'journal')
                        <a href="{{ route('journal-entries.show', $item) }}" class="flex flex-col gap-0.5 px-4 py-3 text-sm hover:bg-slate-50 sm:flex-row sm:items-center sm:justify-between">
                            <span class="font-medium text-slate-900">{{ $item->document_no ?: 'Journal #'.$item->id }}</span>
                            <span class="text-slate-500">{{ str_replace('_', ' ', $item->status) }}</span>
                        </a>
                    @endif
                </li>
            @endforeach
        </ul>
    </section>
@endif
