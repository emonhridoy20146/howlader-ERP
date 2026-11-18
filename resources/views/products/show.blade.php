@extends('layouts.app')

@section('title', 'Product Details')
@section('page-title', 'Product Details')

@section('content')
    @if (session('status'))
        <div class="mb-4 rounded-lg bg-emerald-50 p-3 text-sm text-emerald-800">{{ session('status') }}</div>
    @endif
    @if ($errors->any())
        <div class="mb-4 rounded-lg border border-rose-200 bg-rose-50 p-3 text-sm text-rose-800">
            <ul class="list-inside list-disc space-y-0.5">
                @foreach ($errors->all() as $err)
                    <li>{{ $err }}</li>
                @endforeach
            </ul>
        </div>
    @endif
    @if (! empty($globalSearchViewOnly))
        <div class="mb-4 rounded-lg border border-sky-200 bg-sky-50 px-4 py-3 text-sm text-sky-900">
            View-only product lookup from global search. You can see stock by location only — serials, purchase orders, goods receipts, transfers/invoices, and inventory timeline are hidden.
        </div>
    @endif
    <div x-data="productDetailsTabs(@js($productSerialsForAlpine), @js($productSerialMeta))" class="space-y-6">
        <section class="rounded-2xl bg-white p-5 shadow-sm ring-1 ring-slate-200">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <h2 class="text-2xl font-semibold text-slate-900">{{ $product->name }}</h2>
                    <p class="mt-2 text-sm text-slate-600">
                        {{ $product->brand?->name }} / {{ $product->category?->name }}
                        @if($product->model_number)
                            · Model: {{ $product->model_number }}
                        @endif
                    </p>
                </div>
                <div class="text-right text-sm text-slate-600">
                    @if (! empty($canViewPurchasePrice))
                        <p>Purchase price: <span class="font-semibold text-slate-900">{{ number_format((float) $product->default_purchase_price, 2) }}</span></p>
                    @endif
                    <p>Sale price: <span class="font-semibold text-slate-900">{{ number_format((float) $product->default_sale_price, 2) }}</span></p>
                    <p>Discount limit: <span class="font-semibold text-slate-900">{{ $discountLimitLabel ?? '—' }}</span></p>
                    <p>Status: <span class="font-semibold text-slate-900">{{ $product->is_active ? 'Active' : 'Inactive' }}</span></p>
                </div>
            </div>
            @if ($product->description)
                <p class="mt-4 text-sm text-slate-700">{{ $product->description }}</p>
            @endif
        </section>

        @if (! empty($globalSearchViewOnly))
            <section class="grid gap-4 sm:grid-cols-3">
                <div class="rounded-2xl bg-white p-4 shadow-sm ring-1 ring-slate-200">
                    <p class="text-xs uppercase tracking-[0.16em] text-slate-500">On hand</p>
                    <p class="mt-2 text-2xl font-semibold text-slate-900">{{ number_format($summary['total_on_hand_qty']) }}</p>
                </div>
                <div class="rounded-2xl bg-white p-4 shadow-sm ring-1 ring-slate-200">
                    <p class="text-xs uppercase tracking-[0.16em] text-slate-500">Available</p>
                    <p class="mt-2 text-2xl font-semibold text-slate-900">{{ number_format($summary['total_available_qty']) }}</p>
                </div>
                <div class="rounded-2xl bg-white p-4 shadow-sm ring-1 ring-slate-200">
                    <p class="text-xs uppercase tracking-[0.16em] text-slate-500">Reserved</p>
                    <p class="mt-2 text-2xl font-semibold text-slate-900">{{ number_format($summary['total_reserved_qty']) }}</p>
                </div>
            </section>
        @else
            <section class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                <div class="rounded-2xl bg-white p-4 shadow-sm ring-1 ring-slate-200">
                    <p class="text-xs uppercase tracking-[0.16em] text-slate-500">Inventory Value</p>
                    <p class="mt-2 text-2xl font-semibold text-slate-900">{{ number_format($summary['total_inventory_value'], 2) }}</p>
                    <p class="mt-1 text-xs text-slate-500">On hand {{ $summary['total_on_hand_qty'] }} · Available {{ $summary['total_available_qty'] }}</p>
                </div>
                <div class="rounded-2xl bg-white p-4 shadow-sm ring-1 ring-slate-200">
                    <p class="text-xs uppercase tracking-[0.16em] text-slate-500">Sales All Time</p>
                    <p class="mt-2 text-2xl font-semibold text-slate-900">{{ number_format($summary['sales_all_time'], 2) }}</p>
                    <p class="mt-1 text-xs text-slate-500">Posted and paid sales lines</p>
                </div>
                <div class="rounded-2xl bg-white p-4 shadow-sm ring-1 ring-slate-200">
                    <p class="text-xs uppercase tracking-[0.16em] text-slate-500">Sales This Month</p>
                    <p class="mt-2 text-2xl font-semibold text-slate-900">{{ number_format($summary['sales_this_month'], 2) }}</p>
                    <p class="mt-1 text-xs text-slate-500">{{ now()->format('F Y') }}</p>
                </div>
                <div class="rounded-2xl bg-white p-4 shadow-sm ring-1 ring-slate-200">
                    <p class="text-xs uppercase tracking-[0.16em] text-slate-500">Sales This Year</p>
                    <p class="mt-2 text-2xl font-semibold text-slate-900">{{ number_format($summary['sales_this_year'], 2) }}</p>
                    <p class="mt-1 text-xs text-slate-500">{{ now()->year }}</p>
                </div>
            </section>
        @endif

        <section class="rounded-2xl bg-white shadow-sm ring-1 ring-slate-200">
            <div class="border-b border-slate-200 px-4 pt-3">
                <nav class="flex flex-wrap gap-2">
                    <button type="button" @click="setTab('inventory')" :class="tab === 'inventory' ? 'bg-slate-900 text-white' : 'bg-slate-100 text-slate-700'" class="rounded-lg px-3 py-2 text-sm font-medium">Inventory ({{ $inventoryBalances->count() }})</button>
                    @unless (! empty($globalSearchViewOnly))
                        <button type="button" @click="setTab('serials')" :class="tab === 'serials' ? 'bg-slate-900 text-white' : 'bg-slate-100 text-slate-700'" class="rounded-lg px-3 py-2 text-sm font-medium">Serials (<span x-text="serialRows.length"></span>)</button>
                        <button type="button" @click="setTab('po')" :class="tab === 'po' ? 'bg-slate-900 text-white' : 'bg-slate-100 text-slate-700'" class="rounded-lg px-3 py-2 text-sm font-medium">Purchase Orders ({{ $purchaseOrders->count() }})</button>
                        <button type="button" @click="setTab('grn')" :class="tab === 'grn' ? 'bg-slate-900 text-white' : 'bg-slate-100 text-slate-700'" class="rounded-lg px-3 py-2 text-sm font-medium">Goods Receipts ({{ $goodsReceipts->count() }})</button>
                        <button type="button" @click="setTab('location')" :class="tab === 'location' ? 'bg-slate-900 text-white' : 'bg-slate-100 text-slate-700'" class="rounded-lg px-3 py-2 text-sm font-medium">Transfers &amp; invoices ({{ $locationActivity->count() }} locations)</button>
                        <button type="button" @click="setTab('timeline')" :class="tab === 'timeline' ? 'bg-slate-900 text-white' : 'bg-slate-100 text-slate-700'" class="rounded-lg px-3 py-2 text-sm font-medium">Inventory timeline ({{ $inventoryTimeline->count() }} events)</button>
                    @endunless
                </nav>
            </div>

            <div class="p-4" x-show="tab === 'inventory'" x-cloak>
                <div class="mb-3 grid gap-3 md:grid-cols-3">
                    <input type="text" x-model="filters.inventory.search" placeholder="Search location" class="rounded-lg border border-slate-300 px-3 py-2 text-sm">
                    <select x-model="filters.inventory.type" class="rounded-lg border border-slate-300 px-3 py-2 text-sm">
                        <option value="">All location types</option>
                        <option value="warehouse">Warehouse</option>
                        <option value="branch">Branch</option>
                    </select>
                    <button type="button" @click="filters.inventory = { search: '', type: '' }" class="rounded-lg border border-slate-300 px-3 py-2 text-sm">Clear Filters</button>
                </div>
                <div class="overflow-x-auto">
                    <table class="min-w-full text-sm whitespace-nowrap">
                        <thead class="bg-slate-50 border-b border-slate-200 text-left text-xs font-semibold uppercase tracking-wider text-slate-700">
                            <tr>
                                <th class="px-3 py-2">Location</th>
                                <th class="px-3 py-2">Type</th>
                                <th class="px-3 py-2">On Hand</th>
                                <th class="px-3 py-2">Reserved</th>
                                <th class="px-3 py-2">Available</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($inventoryBalances as $balance)
                                <tr class="border-t border-slate-200"
                                    x-show="matchesInventory({
                                        text: @js(strtolower($balance->location_code . ' ' . $balance->location_name)),
                                        type: @js($balance->location_type)
                                    })">
                                    <td class="px-3 py-2">{{ $balance->location_name }}</td>
                                    <td class="px-3 py-2">{{ ucfirst($balance->location_type) }}</td>
                                    <td class="px-3 py-2">{{ $balance->on_hand_qty }}</td>
                                    <td class="px-3 py-2">{{ $balance->reserved_qty }}</td>
                                    <td class="px-3 py-2 font-semibold">{{ $balance->available_qty }}</td>
                                </tr>
                            @empty
                                <tr><td colspan="5" class="px-3 py-4 text-center text-slate-500">No inventory balances found.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>

            @unless (! empty($globalSearchViewOnly))
            <div class="p-4" x-show="tab === 'serials'" x-cloak>
                <div class="mb-3 grid gap-3 md:grid-cols-2 lg:grid-cols-5">
                    <input type="text" x-model="filters.serials.search" placeholder="Search serial, mfg, location, invoice, customer" class="rounded-lg border border-slate-300 px-3 py-2 text-sm lg:col-span-2">
                    <select x-model="filters.serials.status" class="rounded-lg border border-slate-300 px-3 py-2 text-sm">
                        <option value="">All statuses</option>
                        <option value="draft">Draft</option>
                        <option value="in_stock">In stock</option>
                        <option value="reserved">Reserved</option>
                        <option value="sold">Sold</option>
                        <option value="returned">Returned</option>
                        <option value="damaged">Damaged</option>
                        <option value="in_transfer">In transfer</option>
                        <option value="under_service">Under service</option>
                    </select>
                    <input type="text" x-model="filters.serials.location" placeholder="Location code" class="rounded-lg border border-slate-300 px-3 py-2 text-sm">
                    <select x-model="filters.serials.sort" class="rounded-lg border border-slate-300 px-3 py-2 text-sm">
                        <option value="serial_asc">Serial A → Z</option>
                        <option value="serial_desc">Serial Z → A</option>
                        <option value="status">Status</option>
                        <option value="location">Location code</option>
                        <option value="invoice_date_desc">Sold / invoice date (newest)</option>
                        <option value="invoice_date_asc">Sold / invoice date (oldest)</option>
                        <option value="purchase_cost_desc">Purchase cost (high)</option>
                        <option value="purchase_cost_asc">Purchase cost (low)</option>
                    </select>
                </div>
                <div class="mb-3 flex flex-wrap items-center gap-2" x-show="meta.canRemoveSerial" x-cloak>
                    <button type="button" @click="toggleSelectAllVisible()" class="rounded-lg border border-slate-300 px-3 py-2 text-sm hover:bg-slate-50">Toggle all visible removable</button>
                    <form method="post" :action="meta.bulkDestroySerialsUrl" class="inline-flex items-center gap-2" @submit="confirmBulkRemove($event)">
                        @csrf
                        <template x-for="id in selectedSerialIds" :key="'bulk-serial-' + id">
                            <input type="hidden" name="serial_ids[]" :value="id">
                        </template>
                        <button type="submit" :disabled="selectedSerialIds.length === 0" class="rounded-lg border border-rose-200 bg-white px-3 py-2 text-sm font-semibold text-rose-700 hover:bg-rose-50 disabled:cursor-not-allowed disabled:opacity-50">
                            Remove selected (<span x-text="selectedSerialIds.length"></span>)
                        </button>
                    </form>
                </div>
                <div class="mb-3 flex flex-wrap items-center gap-2" x-show="meta.canSetSerialPrice && meta.canRemoveSerial" x-cloak>
                    <form method="post" :action="meta.bulkSerialPriceUrl" class="inline-flex flex-wrap items-center gap-2">
                        @csrf
                        <template x-for="id in selectedSerialIds" :key="'bulk-price-' + id">
                            <input type="hidden" name="serial_ids[]" :value="id">
                        </template>
                        <label class="text-xs font-medium text-slate-600" for="bulk_serial_price">Sell price for selected</label>
                        <input id="bulk_serial_price" type="number" name="sale_price" step="0.01" min="0" :placeholder="meta.defaultSalePrice.toFixed(2)" class="w-32 rounded-lg border border-slate-300 px-3 py-2 text-sm">
                        <button type="submit" :disabled="selectedSerialIds.length === 0" class="rounded-lg border border-indigo-200 bg-white px-3 py-2 text-sm font-semibold text-indigo-700 hover:bg-indigo-50 disabled:cursor-not-allowed disabled:opacity-50">
                            Set price (<span x-text="selectedSerialIds.length"></span>)
                        </button>
                        <span class="text-xs text-slate-500">Leave empty to use the product sale price.</span>
                    </form>
                </div>
                <div class="mb-3 flex flex-wrap items-center gap-2">
                    <p class="text-xs text-slate-500"><span x-text="displayedSerials.length"></span> of <span x-text="serialRows.length"></span> shown</p>
                    <button type="button" @click="filters.serials = { search: '', status: '', location: '', sort: 'serial_asc' }" class="rounded-lg border border-slate-300 px-3 py-2 text-sm">Clear filters</button>
                </div>
                <div class="overflow-x-auto">
                    <table class="min-w-full text-sm whitespace-nowrap">
                        <thead class="bg-slate-50 border-b border-slate-200 text-left text-xs font-semibold uppercase tracking-wider text-slate-700">
                            <tr>
                                <th class="w-10 px-2 py-2" x-show="meta.canRemoveSerial" x-cloak>
                                    <span class="sr-only">Select</span>
                                    <input type="checkbox" class="rounded border-slate-300" title="Select visible removable" @click.prevent="toggleSelectAllVisible()" :checked="allVisibleRemovableSelected">
                                </th>
                                <th class="px-3 py-2">Serial</th>
                                <th class="px-3 py-2">Mfg serial</th>
                                <th class="px-3 py-2">Status</th>
                                <th class="px-3 py-2">Location</th>
                                <th class="px-3 py-2">Purchase cost</th>
                                <th class="px-3 py-2">Sell price</th>
                                <th class="px-3 py-2">Warranty</th>
                                <th class="px-3 py-2">Invoice</th>
                                <th class="px-3 py-2">Customer</th>
                                <th class="px-3 py-2">Invoice date</th>
                                <th class="px-3 py-2 w-28 text-right">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <template x-if="serialRows.length === 0">
                                <tr><td class="px-3 py-4 text-center text-slate-500" :colspan="meta.canRemoveSerial ? 12 : 11">No serials recorded for this product.</td></tr>
                            </template>
                            <template x-if="serialRows.length > 0 && displayedSerials.length === 0">
                                <tr><td class="px-3 py-4 text-center text-slate-500" :colspan="meta.canRemoveSerial ? 12 : 11">No serials match the current filters.</td></tr>
                            </template>
                            <template x-for="row in displayedSerials" :key="row.id">
                                <tr class="border-b border-slate-100 transition-colors hover:bg-slate-50/70 last:border-0">
                                    <td class="w-10 px-2 py-2 align-middle" x-show="meta.canRemoveSerial" x-cloak>
                                        <input type="checkbox" class="rounded border-slate-300" :disabled="!row.can_remove_serial" :checked="selectedSerialIds.includes(row.id)" @click.prevent="toggleSerialId(row.id)">
                                    </td>
                                    <td class="px-3 py-2 font-mono" x-text="row.serial_number"></td>
                                    <td class="px-3 py-2 font-mono text-xs text-slate-600" x-text="row.manufacturer_serial || '—'"></td>
                                    <td class="px-3 py-2" x-text="formatSerialStatus(row.status)"></td>
                                    <td class="px-3 py-2">
                                        <span x-text="row.location_name || '—'"></span>
                                    </td>
                                    <td class="px-3 py-2" x-text="row.purchase_cost != null ? Number(row.purchase_cost).toFixed(2) : '—'"></td>
                                    <td class="px-3 py-2">
                                        <template x-if="meta.canSetSerialPrice && row.can_set_price">
                                            <form :action="meta.serialPriceBaseUrl + '/' + row.id + '/sale-price'" method="post" class="inline-flex items-center gap-1">
                                                <input type="hidden" name="_token" value="{{ csrf_token() }}">
                                                <input type="hidden" name="_method" value="PATCH">
                                                <input type="number" name="sale_price" step="0.01" min="0" :value="row.sale_price ?? ''" :placeholder="meta.defaultSalePrice.toFixed(2)" class="w-28 rounded-md border border-slate-300 px-2 py-1 text-sm tabular-nums" :title="row.sale_price == null ? 'Using the product sale price' : 'Custom price for this serial'">
                                                <button type="submit" class="rounded-md border border-indigo-200 bg-white px-2 py-1 text-xs font-semibold text-indigo-700 hover:bg-indigo-50">Save</button>
                                            </form>
                                        </template>
                                        <template x-if="!(meta.canSetSerialPrice && row.can_set_price)">
                                            <span class="tabular-nums" :class="row.sale_price == null ? 'text-slate-400' : ''" x-text="row.sale_price != null ? Number(row.sale_price).toFixed(2) : '—'"></span>
                                        </template>
                                    </td>
                                    <td class="px-3 py-2 text-xs text-slate-600">
                                        <span x-text="(row.warranty_start_date || row.warranty_end_date) ? ((row.warranty_start_date || '—') + ' → ' + (row.warranty_end_date || '—')) : '—'"></span>
                                    </td>
                                    <td class="px-3 py-2 font-mono text-xs">
                                        <a x-show="row.sales_invoice_id && row.invoice_url" :href="row.invoice_url" class="text-blue-600 hover:underline" x-text="row.invoice_number"></a>
                                        <span x-show="!row.sales_invoice_id" class="text-slate-500">—</span>
                                    </td>
                                    <td class="px-3 py-2" x-text="row.customer_name || '—'"></td>
                                    <td class="px-3 py-2" x-text="row.invoice_date || '—'"></td>
                                    <td class="px-3 py-2 text-right">
                                        <template x-if="meta.canRemoveSerial && row.can_remove_serial">
                                            <form :action="meta.destroySerialBaseUrl + '/' + row.id" method="post" class="inline" @submit="confirmRemoveSerial($event, row)">
                                                <input type="hidden" name="_token" value="{{ csrf_token() }}">
                                                <input type="hidden" name="_method" value="DELETE">
                                                <button type="submit" class="rounded-md border border-rose-200 bg-white px-2 py-1 text-xs font-semibold text-rose-700 hover:bg-rose-50">Remove</button>
                                            </form>
                                        </template>
                                        <template x-if="!(meta.canRemoveSerial && row.can_remove_serial)">
                                            <span class="text-xs text-slate-400">—</span>
                                        </template>
                                    </td>
                                </tr>
                            </template>
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="p-4" x-show="tab === 'po'" x-cloak>
                <div class="mb-3 grid gap-3 md:grid-cols-4">
                    <input type="text" x-model="filters.po.search" placeholder="Search PO, supplier, location" class="rounded-lg border border-slate-300 px-3 py-2 text-sm">
                    <select x-model="filters.po.status" class="rounded-lg border border-slate-300 px-3 py-2 text-sm">
                        <option value="">All statuses</option>
                        <option value="draft">Draft</option>
                        <option value="approved">Approved</option>
                        <option value="partially_received">Partially received</option>
                        <option value="received">Received</option>
                        <option value="cancelled">Cancelled</option>
                    </select>
                    <input type="date" x-model="filters.po.fromDate" class="rounded-lg border border-slate-300 px-3 py-2 text-sm">
                    <button type="button" @click="filters.po = { search: '', status: '', fromDate: '' }" class="rounded-lg border border-slate-300 px-3 py-2 text-sm">Clear Filters</button>
                </div>
                <div class="overflow-x-auto">
                    <table class="min-w-full text-sm whitespace-nowrap">
                        <thead class="bg-slate-50 border-b border-slate-200 text-left text-xs font-semibold uppercase tracking-wider text-slate-700">
                            <tr>
                                <th class="px-3 py-2">PO Number</th>
                                <th class="px-3 py-2">Supplier</th>
                                <th class="px-3 py-2">Destination</th>
                                <th class="px-3 py-2">Ordered</th>
                                <th class="px-3 py-2">Qty Ordered</th>
                                <th class="px-3 py-2">Qty Received</th>
                                <th class="px-3 py-2">Unit Cost</th>
                                <th class="px-3 py-2">Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($purchaseOrders as $po)
                                <tr class="border-t border-slate-200"
                                    x-show="matchesPo({
                                        text: @js(strtolower($po->po_number . ' ' . $po->supplier_name . ' ' . $po->destination_code . ' ' . $po->destination_name)),
                                        status: @js($po->purchase_order_status),
                                        orderedAt: @js((string) $po->ordered_at)
                                    })">
                                    <td class="px-3 py-2 font-mono">
                                        <a href="{{ route('purchase-orders.show', $po->purchase_order_id) }}" class="text-blue-600 hover:underline">{{ $po->po_number }}</a>
                                    </td>
                                    <td class="px-3 py-2">{{ $po->supplier_name }}</td>
                                    <td class="px-3 py-2">{{ $po->destination_name }}</td>
                                    <td class="px-3 py-2">{{ $po->ordered_at }}</td>
                                    <td class="px-3 py-2">{{ $po->qty_ordered }}</td>
                                    <td class="px-3 py-2">{{ $po->qty_received }}</td>
                                    <td class="px-3 py-2">{{ number_format($po->unit_cost, 2) }}</td>
                                    <td class="px-3 py-2">{{ ucfirst(str_replace('_', ' ', $po->purchase_order_status)) }}</td>
                                </tr>
                            @empty
                                <tr><td colspan="8" class="px-3 py-4 text-center text-slate-500">No related purchase orders found.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="p-4" x-show="tab === 'grn'" x-cloak>
                <div class="mb-3 grid gap-3 md:grid-cols-4">
                    <input type="text" x-model="filters.grn.search" placeholder="Search GRN, PO, supplier, location" class="rounded-lg border border-slate-300 px-3 py-2 text-sm">
                    <select x-model="filters.grn.status" class="rounded-lg border border-slate-300 px-3 py-2 text-sm">
                        <option value="">All statuses</option>
                        <option value="draft">Draft</option>
                        <option value="posted">Posted</option>
                        <option value="cancelled">Cancelled</option>
                    </select>
                    <input type="date" x-model="filters.grn.fromDate" class="rounded-lg border border-slate-300 px-3 py-2 text-sm">
                    <button type="button" @click="filters.grn = { search: '', status: '', fromDate: '' }" class="rounded-lg border border-slate-300 px-3 py-2 text-sm">Clear Filters</button>
                </div>
                <div class="overflow-x-auto">
                    <table class="min-w-full text-sm whitespace-nowrap">
                        <thead class="bg-slate-50 border-b border-slate-200 text-left text-xs font-semibold uppercase tracking-wider text-slate-700">
                            <tr>
                                <th class="px-3 py-2">GRN Number</th>
                                <th class="px-3 py-2">PO Number</th>
                                <th class="px-3 py-2">Supplier</th>
                                <th class="px-3 py-2">Location</th>
                                <th class="px-3 py-2">Received Date</th>
                                <th class="px-3 py-2">Qty Received</th>
                                <th class="px-3 py-2">Unit Cost</th>
                                <th class="px-3 py-2">Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($goodsReceipts as $grn)
                                <tr class="border-t border-slate-200"
                                    x-show="matchesGrn({
                                        text: @js(strtolower($grn->grn_number . ' ' . ($grn->purchase_order_number ?? '') . ' ' . $grn->supplier_name . ' ' . $grn->location_code . ' ' . $grn->location_name)),
                                        status: @js($grn->goods_receipt_status),
                                        receivedAt: @js((string) $grn->received_at)
                                    })">
                                    <td class="px-3 py-2 font-mono">
                                        <a href="{{ route('goods-receipts.show', $grn->goods_receipt_id) }}" class="text-blue-600 hover:underline">{{ $grn->grn_number }}</a>
                                    </td>
                                    <td class="px-3 py-2">{{ $grn->purchase_order_number ?? '-' }}</td>
                                    <td class="px-3 py-2">{{ $grn->supplier_name }}</td>
                                    <td class="px-3 py-2">{{ $grn->location_name }}</td>
                                    <td class="px-3 py-2">{{ $grn->received_at }}</td>
                                    <td class="px-3 py-2">{{ $grn->qty_received }}</td>
                                    <td class="px-3 py-2">{{ number_format($grn->unit_cost, 2) }}</td>
                                    <td class="px-3 py-2">{{ ucfirst($grn->goods_receipt_status) }}</td>
                                </tr>
                            @empty
                                <tr><td colspan="8" class="px-3 py-4 text-center text-slate-500">No related goods receipts found.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="p-4" x-show="tab === 'location'" x-cloak>
                <div class="mb-3 flex flex-wrap gap-3">
                    <input type="text" x-model="filters.activity.search" placeholder="Filter by location code or name" class="min-w-[200px] flex-1 rounded-lg border border-slate-300 px-3 py-2 text-sm">
                    <button type="button" @click="filters.activity = { search: '' }" class="rounded-lg border border-slate-300 px-3 py-2 text-sm">Clear</button>
                </div>
                @if ($locationActivity->isEmpty())
                    <p class="rounded-lg bg-slate-50 px-4 py-6 text-center text-sm text-slate-500">No stock transfers or posted sales for this product yet.</p>
                @else
                    <div class="space-y-5">
                        @foreach ($locationActivity as $block)
                            <div class="rounded-xl border border-slate-200 bg-slate-50/50 shadow-sm"
                                x-show="matchesActivity({ text: @js(\Illuminate\Support\Str::lower($block['location_code'].' '.$block['location_name'])) })">
                                <div class="border-b border-slate-200 bg-white px-4 py-3">
                                    <p class="text-xs uppercase tracking-[0.16em] text-slate-500">{{ ucfirst($block['location_type']) }}</p>
                                    <h3 class="mt-1 text-lg font-semibold text-slate-900">{{ $block['location_name'] }}</h3>
                                </div>
                                <div class="space-y-6 p-4">
                                    <div>
                                        <h4 class="mb-2 text-sm font-semibold text-slate-800">Stock transfer log</h4>
                                        <p class="mb-2 text-xs text-slate-500">Each transfer is listed at both source and destination. Direction is relative to this location.</p>
                                        <div class="overflow-x-auto rounded-lg border border-slate-200 bg-white">
                                            <table class="min-w-full text-sm whitespace-nowrap">
                                                <thead class="bg-slate-50 border-b border-slate-200 text-left text-xs font-semibold uppercase tracking-wider text-slate-700">
                                                    <tr>
                                                        <th class="px-3 py-2">Transfer</th>
                                                        <th class="px-3 py-2">Direction</th>
                                                        <th class="px-3 py-2">Other location</th>
                                                        <th class="px-3 py-2">Requested</th>
                                                        <th class="px-3 py-2">Req / Issued / Rcvd</th>
                                                        <th class="px-3 py-2">Status</th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    @forelse ($block['transfers'] as $t)
                                                        <tr class="border-b border-slate-100 transition-colors hover:bg-slate-50/70 last:border-0">
                                                            <td class="px-3 py-2 font-mono text-xs">
                                                                <a href="{{ route('transfers.index', ['q' => $t['transfer_number']]) }}" class="text-blue-600 hover:underline">{{ $t['transfer_number'] }}</a>
                                                            </td>
                                                            <td class="px-3 py-2">
                                                                @if ($t['direction'] === 'outbound')
                                                                    <span class="font-medium text-amber-800">Outbound</span>
                                                                @else
                                                                    <span class="font-medium text-emerald-800">Inbound</span>
                                                                @endif
                                                            </td>
                                                            <td class="px-3 py-2">{{ $t['counterparty_name'] }}</td>
                                                            <td class="px-3 py-2 text-slate-600">{{ $t['requested_at'] ? \Illuminate\Support\Carbon::parse($t['requested_at'])->format('Y-m-d H:i') : '—' }}</td>
                                                            <td class="px-3 py-2 font-mono text-xs">{{ $t['qty_requested'] }} / {{ $t['qty_issued'] }} / {{ $t['qty_received'] }}</td>
                                                            <td class="px-3 py-2">{{ ucfirst($t['status']) }}</td>
                                                        </tr>
                                                    @empty
                                                        <tr><td colspan="6" class="px-3 py-3 text-center text-slate-500">No transfers involving this location.</td></tr>
                                                    @endforelse
                                                </tbody>
                                                @if (count($block['transfers']) > 0)
                                                    <tfoot>
                                                        <tr class="border-t-2 border-slate-300 bg-slate-100 font-semibold text-slate-900">
                                                            <td colspan="4" class="px-3 py-2 text-right">Totals</td>
                                                            <td class="px-3 py-2 font-mono text-xs">{{ collect($block['transfers'])->sum('qty_requested') }} / {{ collect($block['transfers'])->sum('qty_issued') }} / {{ collect($block['transfers'])->sum('qty_received') }}</td>
                                                            <td class="px-3 py-2"></td>
                                                        </tr>
                                                    </tfoot>
                                                @endif
                                            </table>
                                        </div>
                                    </div>
                                    <div>
                                        <h4 class="mb-2 text-sm font-semibold text-slate-800">Sales invoices (branch)</h4>
                                        <div class="overflow-x-auto rounded-lg border border-slate-200 bg-white">
                                            <table class="min-w-full text-sm whitespace-nowrap">
                                                <thead class="bg-slate-50 border-b border-slate-200 text-left text-xs font-semibold uppercase tracking-wider text-slate-700">
                                                    <tr>
                                                        <th class="px-3 py-2">Invoice</th>
                                                        <th class="px-3 py-2">Date</th>
                                                        <th class="px-3 py-2">Created</th>
                                                        <th class="px-3 py-2">Customer</th>
                                                        <th class="px-3 py-2">Qty</th>
                                                        <th class="px-3 py-2">Line total</th>
                                                        <th class="px-3 py-2">Status</th>
                                                        <th class="px-3 py-2">Payment</th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    @forelse ($block['invoices'] as $inv)
                                                        <tr class="border-b border-slate-100 transition-colors hover:bg-slate-50/70 last:border-0">
                                                            <td class="px-3 py-2 font-mono text-xs">
                                                                <a href="{{ route('sales.invoices.show', $inv['sales_invoice_id']) }}" class="text-blue-600 hover:underline">{{ $inv['invoice_number'] }}</a>
                                                            </td>
                                                            <td class="px-3 py-2 text-slate-600">{{ $inv['invoice_date'] ? \Illuminate\Support\Carbon::parse($inv['invoice_date'])->format('Y-m-d') : '—' }}</td>
                                                            <td class="px-3 py-2 text-slate-600">
                                                                @if (! empty($inv['created_at']))
                                                                    {{ \Illuminate\Support\Carbon::parse($inv['created_at'])->format('Y-m-d') }}
                                                                    <div class="text-xs text-slate-500">{{ \Illuminate\Support\Carbon::parse($inv['created_at'])->format('H:i') }}</div>
                                                                @else
                                                                    —
                                                                @endif
                                                            </td>
                                                            <td class="px-3 py-2">{{ $inv['customer_name'] }}</td>
                                                            <td class="px-3 py-2">{{ $inv['qty'] }}</td>
                                                            <td class="px-3 py-2">{{ number_format($inv['line_total'], 2) }}</td>
                                                            <td class="px-3 py-2">{{ ucfirst($inv['status']) }}</td>
                                                            <td class="px-3 py-2">{{ $inv['payment_status'] ? ucfirst(str_replace('_', ' ', $inv['payment_status'])) : '—' }}</td>
                                                        </tr>
                                                    @empty
                                                        <tr><td colspan="8" class="px-3 py-3 text-center text-slate-500">No invoice lines at this branch.</td></tr>
                                                    @endforelse
                                                </tbody>
                                                @if (count($block['invoices']) > 0)
                                                    <tfoot>
                                                        <tr class="border-t-2 border-slate-300 bg-slate-100 font-semibold text-slate-900">
                                                            <td colspan="4" class="px-3 py-2 text-right">Totals</td>
                                                            <td class="px-3 py-2">{{ collect($block['invoices'])->sum('qty') }}</td>
                                                            <td class="px-3 py-2">{{ number_format(collect($block['invoices'])->sum('line_total'), 2) }}</td>
                                                            <td colspan="2" class="px-3 py-2"></td>
                                                        </tr>
                                                    </tfoot>
                                                @endif
                                            </table>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        @endforeach
                    </div>
                @endif
            </div>

            <div class="p-4" x-show="tab === 'timeline'" x-cloak>
                @if ($inventoryTimeline->isEmpty())
                    <p class="rounded-lg bg-slate-50 px-4 py-6 text-center text-sm text-slate-500">No stock ledger movements recorded for this product yet.</p>
                @else
                    <p class="mb-4 max-w-3xl text-xs leading-relaxed text-slate-600">
                        Ledger events in chronological order (oldest at the top). <strong class="font-semibold text-slate-700">Here</strong> is on-hand quantity at that location immediately after each posting.
                        <strong class="font-semibold text-slate-700">Σ all</strong> is total on-hand summed across locations from the ledger (matches inventory when history is complete). Serial reservations do not emit ledger movements.
                    </p>
                    <div class="mb-3 flex flex-wrap gap-3">
                        <input type="text" x-model="filters.timeline.search" placeholder="Search time, movement, location, invoice, transfer, GRN…" class="min-w-[220px] flex-1 rounded-lg border border-slate-300 px-3 py-2 text-sm">
                        <button type="button" @click="filters.timeline = { search: '' }" class="rounded-lg border border-slate-300 px-3 py-2 text-sm">Clear</button>
                    </div>
                    <div class="overflow-x-auto rounded-lg border border-slate-100">
                        <table class="min-w-[900px] text-sm whitespace-nowrap md:min-w-full">
                            <thead class="bg-slate-50 border-b border-slate-200 text-left text-xs font-semibold uppercase tracking-wider text-slate-700">
                                <tr>
                                    <th class="border-l-[3px] border-slate-300 bg-gradient-to-r from-slate-100/80 px-3 py-2 md:px-4">When</th>
                                    <th class="px-3 py-2">Event</th>
                                    <th class="px-3 py-2">Δ</th>
                                    <th class="px-3 py-2">Location</th>
                                    <th class="px-3 py-2 text-right">Here</th>
                                    <th class="px-3 py-2 text-right">Σ all</th>
                                    <th class="px-3 py-2">Reference</th>
                                    <th class="px-3 py-2">Notes</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($inventoryTimeline as $ev)
                                    <tr
                                        class="border-b border-slate-100 hover:bg-slate-50/60"
                                        x-show="!filters.timeline.search.trim() || @js($ev['search_blob']).includes(filters.timeline.search.toLowerCase().trim())"
                                    >
                                        <td class="border-l-[3px] border-slate-200 bg-slate-50/60 px-3 py-2 align-top text-xs tabular-nums text-slate-600 md:px-4">
                                            {{ $ev['event_time_human'] }}
                                        </td>
                                        <td class="px-3 py-2 align-top">
                                            <span class="font-medium text-slate-900">{{ $ev['movement_label'] }}</span>
                                        </td>
                                        <td class="px-3 py-2 align-top tabular-nums">
                                            @if ($ev['quantity_delta'] > 0)
                                                <span class="font-semibold text-emerald-700">{{ $ev['delta_label'] }}</span>
                                            @elseif ($ev['quantity_delta'] < 0)
                                                <span class="font-semibold text-rose-700">{{ $ev['delta_label'] }}</span>
                                            @else
                                                <span class="font-medium text-slate-600">{{ $ev['delta_label'] }}</span>
                                            @endif
                                        </td>
                                        <td class="px-3 py-2 align-top text-slate-800">
                                            <span class="block font-medium">{{ $ev['location_name'] }}</span>
                                            <span class="text-[11px] text-slate-500">{{ $ev['location_code'] }}</span>
                                        </td>
                                        <td class="px-3 py-2 align-top text-right font-semibold tabular-nums text-slate-900">{{ $ev['qty_on_hand_after_this_location'] }}</td>
                                        <td class="px-3 py-2 align-top text-right font-medium tabular-nums text-slate-700">{{ $ev['total_on_hand_all_locations'] }}</td>
                                        <td class="px-3 py-2 align-top text-xs">
                                            @if (($ev['reference_url'] ?? null))
                                                <a href="{{ $ev['reference_url'] }}" class="text-blue-600 hover:underline">{{ $ev['reference_label'] }}</a>
                                            @else
                                                <span class="text-slate-700">{{ $ev['reference_label'] }}</span>
                                            @endif
                                        </td>
                                        <td class="max-w-[12rem] px-3 py-2 align-top text-xs leading-snug text-slate-600">
                                            @php
                                                $noteBits = [];
                                                if (! empty($ev['serial_number'])) {
                                                    $noteBits[] = 'Serial: '.$ev['serial_number'];
                                                }
                                                if (! empty($ev['unit_cost_formatted'])) {
                                                    $noteBits[] = 'Unit cost '.$ev['unit_cost_formatted'];
                                                }
                                            @endphp
                                            {{ count($noteBits) ? implode(' · ', $noteBits) : '—' }}
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </div>
            @endunless
        </section>
    </div>

    <script>
        function productDetailsTabs(serialRows, meta) {
            const rows = Array.isArray(serialRows) ? serialRows : [];
            const m = meta && typeof meta === 'object' ? meta : {};
            const ALLOWED_TABS = m.globalSearchViewOnly
                ? ['inventory']
                : ['inventory', 'serials', 'po', 'grn', 'location', 'timeline'];
            const rawTab = String(m.initialTab || '');
            const initialTab = ALLOWED_TABS.includes(rawTab) ? rawTab : 'inventory';

            return {
                tab: initialTab,
                serialRows: rows,
                selectedSerialIds: [],
                meta: {
                    productId: Number(m.productId) || 0,
                    canRemoveSerial: Boolean(m.canRemoveSerial),
                    destroySerialBaseUrl: String(m.destroySerialBaseUrl || ''),
                    bulkDestroySerialsUrl: String(m.bulkDestroySerialsUrl || ''),
                    canSetSerialPrice: Boolean(m.canSetSerialPrice),
                    serialPriceBaseUrl: String(m.serialPriceBaseUrl || ''),
                    bulkSerialPriceUrl: String(m.bulkSerialPriceUrl || ''),
                    defaultSalePrice: Number(m.defaultSalePrice) || 0,
                },
                filters: {
                    inventory: { search: '', type: '' },
                    serials: { search: '', status: '', location: '', sort: 'serial_asc' },
                    po: { search: '', status: '', fromDate: '' },
                    grn: { search: '', status: '', fromDate: '' },
                    activity: { search: '' },
                    timeline: { search: '' },
                },
                setTab(name) {
                    if (!ALLOWED_TABS.includes(name)) {
                        return;
                    }
                    this.tab = name;
                    const u = new URL(window.location.href);
                    u.searchParams.set('tab', name);
                    window.history.replaceState({}, '', u.toString());
                },
                toggleSerialId(id) {
                    const n = Number(id);
                    const i = this.selectedSerialIds.indexOf(n);
                    if (i >= 0) {
                        this.selectedSerialIds.splice(i, 1);
                    } else {
                        this.selectedSerialIds.push(n);
                    }
                },
                toggleSelectAllVisible() {
                    const removable = this.displayedSerials.filter((r) => r.can_remove_serial).map((r) => Number(r.id));
                    const allOn = removable.length > 0 && removable.every((id) => this.selectedSerialIds.includes(id));
                    if (allOn) {
                        this.selectedSerialIds = this.selectedSerialIds.filter((id) => !removable.includes(id));
                    } else {
                        this.selectedSerialIds = [...new Set([...this.selectedSerialIds, ...removable])];
                    }
                },
                get allVisibleRemovableSelected() {
                    const removable = this.displayedSerials.filter((r) => r.can_remove_serial).map((r) => Number(r.id));
                    return removable.length > 0 && removable.every((id) => this.selectedSerialIds.includes(id));
                },
                confirmRemoveSerial(e, row) {
                    const damaged = String(row.status || '') === 'damaged';
                    const msg = damaged
                        ? 'Clear this damaged unit from branch inventory? A damage-out ledger entry will be posted and the serial will no longer be at a location.'
                        : 'Remove this serial from stock? It will be written off as damaged (same as stock adjustment).';
                    if (!window.confirm(msg)) {
                        e.preventDefault();
                    }
                },
                confirmBulkRemove(e) {
                    const n = this.selectedSerialIds.length;
                    if (n === 0) {
                        e.preventDefault();
                        window.alert('Select at least one serial.');
                        return;
                    }
                    const msg =
                        'Remove ' +
                        n +
                        ' serial(s)? In-stock units are written off as damaged; damaged units still at a branch get a ledger damage-out and are cleared from the location.';
                    if (!window.confirm(msg)) {
                        e.preventDefault();
                    }
                },
                formatSerialStatus(status) {
                    return String(status || '').replaceAll('_', ' ').replace(/\b\w/g, (c) => c.toUpperCase());
                },
                get displayedSerials() {
                    const search = this.filters.serials.search.toLowerCase().trim();
                    const status = this.filters.serials.status;
                    const location = this.filters.serials.location.toLowerCase().trim();
                    const filtered = this.serialRows.filter((row) => {
                        const blob = [
                            row.serial_number,
                            row.manufacturer_serial,
                            row.location_code,
                            row.location_name,
                            row.status,
                            row.invoice_number,
                            row.customer_name,
                            row.invoice_date,
                        ].join(' ').toLowerCase();
                        if (search && !blob.includes(search)) {
                            return false;
                        }
                        if (status && row.status !== status) {
                            return false;
                        }
                        if (location) {
                            const locHay = `${String(row.location_name || '')} ${String(row.location_code || '')}`.toLowerCase();
                            if (!locHay.includes(location)) {
                                return false;
                            }
                        }
                        return true;
                    });
                    const sortKey = this.filters.serials.sort;
                    const cmpSerial = (a, b) => String(a.serial_number).localeCompare(String(b.serial_number), undefined, { numeric: true });
                    const sorted = [...filtered];
                    if (sortKey === 'serial_desc') {
                        sorted.sort((a, b) => -cmpSerial(a, b));
                    } else if (sortKey === 'serial_asc') {
                        sorted.sort(cmpSerial);
                    } else if (sortKey === 'status') {
                        sorted.sort((a, b) => String(a.status).localeCompare(String(b.status)) || cmpSerial(a, b));
                    } else if (sortKey === 'location') {
                        sorted.sort((a, b) => String(a.location_name || '').localeCompare(String(b.location_name || '')) || cmpSerial(a, b));
                    } else if (sortKey === 'invoice_date_desc') {
                        sorted.sort((a, b) => String(b.invoice_date || '').localeCompare(String(a.invoice_date || '')) || cmpSerial(a, b));
                    } else if (sortKey === 'invoice_date_asc') {
                        sorted.sort((a, b) => String(a.invoice_date || '').localeCompare(String(b.invoice_date || '')) || cmpSerial(a, b));
                    } else if (sortKey === 'purchase_cost_desc') {
                        sorted.sort((a, b) => (Number(b.purchase_cost) || 0) - (Number(a.purchase_cost) || 0) || cmpSerial(a, b));
                    } else if (sortKey === 'purchase_cost_asc') {
                        sorted.sort((a, b) => (Number(a.purchase_cost) || 0) - (Number(b.purchase_cost) || 0) || cmpSerial(a, b));
                    } else {
                        sorted.sort(cmpSerial);
                    }
                    return sorted;
                },
                matchesInventory(row) {
                    const search = this.filters.inventory.search.toLowerCase().trim();
                    const type = this.filters.inventory.type;

                    if (search && !row.text.includes(search)) {
                        return false;
                    }

                    if (type && row.type !== type) {
                        return false;
                    }

                    return true;
                },
                matchesPo(row) {
                    const search = this.filters.po.search.toLowerCase().trim();
                    const status = this.filters.po.status;
                    const fromDate = this.filters.po.fromDate;

                    if (search && !row.text.includes(search)) {
                        return false;
                    }

                    if (status && row.status !== status) {
                        return false;
                    }

                    if (fromDate && row.orderedAt < fromDate) {
                        return false;
                    }

                    return true;
                },
                matchesGrn(row) {
                    const search = this.filters.grn.search.toLowerCase().trim();
                    const status = this.filters.grn.status;
                    const fromDate = this.filters.grn.fromDate;

                    if (search && !row.text.includes(search)) {
                        return false;
                    }

                    if (status && row.status !== status) {
                        return false;
                    }

                    if (fromDate && row.receivedAt < fromDate) {
                        return false;
                    }

                    return true;
                },
                matchesActivity(row) {
                    const search = this.filters.activity.search.toLowerCase().trim();
                    if (!search) {
                        return true;
                    }
                    return row.text.includes(search);
                },
            };
        }
    </script>
@endsection
