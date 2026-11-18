@php
    $bulkExportQuery = collect(request()->except('page'))->all();
    $bulkFilteredExportUrl = route('products.bulk-update.export', $bulkExportQuery);
    $bulkAllExportUrl = route('products.bulk-update.export', array_merge($bulkExportQuery, ['all' => 1]));
@endphp

@can('bulkUpdate', \App\Models\Product::class)
    <details id="product-bulk-update-panel" class="mb-4 rounded-xl border border-slate-200 bg-white shadow-sm ring-1 ring-slate-200/60">
        <summary class="cursor-pointer list-none px-4 py-3 text-sm font-semibold text-slate-900 marker:content-none [&::-webkit-details-marker]:hidden">
            <span class="flex flex-wrap items-center justify-between gap-2">
                <span>Bulk price update (Excel)</span>
                <span class="text-xs font-normal text-slate-500">Export → edit prices → import</span>
            </span>
        </summary>
        <div class="space-y-4 border-t border-slate-100 px-4 py-4">
            <div class="grid gap-4 lg:grid-cols-2">
                <div class="rounded-lg border border-slate-200 bg-slate-50/60 p-4">
                    <h3 class="text-sm font-semibold text-slate-800">1. Export to Excel</h3>
                    <p class="mt-1 text-xs text-slate-600">Download products with current purchase and sale prices. Only edit the <strong>purchase_price</strong> and <strong>sale_price</strong> columns; keep <strong>product_id</strong> unchanged.</p>
                    <div class="mt-3 flex flex-wrap gap-2">
                        <a href="{{ $bulkFilteredExportUrl }}" class="inline-flex rounded-lg border border-slate-300 bg-white px-3 py-1.5 text-xs font-semibold text-slate-800 shadow-sm hover:bg-slate-50">Export current filters</a>
                        <a href="{{ $bulkAllExportUrl }}" class="inline-flex rounded-lg border border-indigo-200 bg-indigo-50 px-3 py-1.5 text-xs font-semibold text-indigo-900 hover:bg-indigo-100">Export all (this list tab)</a>
                    </div>
                </div>
                <div class="rounded-lg border border-slate-200 bg-slate-50/60 p-4">
                    <h3 class="text-sm font-semibold text-slate-800">2. Import updated file</h3>
                    <p class="mt-1 text-xs text-slate-600">Upload the same spreadsheet (.xlsx, .xls, or .csv). Rows are matched by <strong>product_id</strong> (or <strong>sku</strong> if id is missing).</p>
                    <form action="{{ route('products.bulk-update.import') }}" method="post" enctype="multipart/form-data" class="mt-3 flex flex-col gap-2 sm:flex-row sm:items-end">
                        @csrf
                        @foreach (request()->except('page') as $key => $value)
                            @if (is_scalar($value) && $value !== '')
                                <input type="hidden" name="{{ $key }}" value="{{ $value }}">
                            @endif
                        @endforeach
                        <div class="min-w-0 flex-1">
                            <input type="file" name="file" accept=".xlsx,.xls,.csv" required class="block w-full text-sm text-slate-700 file:mr-3 file:rounded-lg file:border-0 file:bg-slate-900 file:px-3 file:py-2 file:text-xs file:font-semibold file:text-white hover:file:bg-slate-800">
                        </div>
                        <button type="submit" class="shrink-0 rounded-lg bg-emerald-700 px-4 py-2 text-sm font-medium text-white hover:bg-emerald-800">Import prices</button>
                    </form>
                </div>
            </div>

            <div class="rounded-lg border border-slate-200 p-4">
                <h3 class="text-sm font-semibold text-slate-800">3. Update selected products on this page</h3>
                <p class="mt-1 text-xs text-slate-600">Select rows with the checkboxes in the table, then set new prices below (leave a field empty to keep the current value).</p>
                <form id="product-bulk-apply-form" action="{{ route('products.bulk-update.apply') }}" method="post" class="mt-3 flex flex-col gap-3 sm:flex-row sm:flex-wrap sm:items-end" onsubmit="return window.submitProductBulkApply(this)">
                    @csrf
                    @foreach (request()->except('page') as $key => $value)
                        @if (is_scalar($value) && $value !== '')
                            <input type="hidden" name="{{ $key }}" value="{{ $value }}">
                        @endif
                    @endforeach
                    <div id="product-bulk-apply-ids"></div>
                    <div>
                        <label class="text-xs font-medium text-slate-600">Purchase price</label>
                        <input type="number" name="default_purchase_price" step="0.01" min="0" placeholder="Leave blank to skip" class="mt-1 w-full min-w-[8rem] rounded-lg border border-slate-300 px-3 py-2 text-sm sm:w-36">
                    </div>
                    <div>
                        <label class="text-xs font-medium text-slate-600">Sale price</label>
                        <input type="number" name="default_sale_price" step="0.01" min="0" placeholder="Leave blank to skip" class="mt-1 w-full min-w-[8rem] rounded-lg border border-slate-300 px-3 py-2 text-sm sm:w-36">
                    </div>
                    <button type="submit" class="rounded-lg bg-slate-900 px-4 py-2 text-sm font-medium text-white hover:bg-slate-800">Apply to selected</button>
                </form>
            </div>
        </div>
    </details>

    @if (is_array(session('import_errors')) && count(session('import_errors')) > 0)
        <div class="mb-4 rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-sm text-amber-900">
            <p class="font-semibold">Import warnings</p>
            <ul class="mt-1 max-h-40 list-disc overflow-y-auto pl-5 text-xs">
                @foreach (session('import_errors') as $importError)
                    <li>{{ $importError }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <script>
        window.submitProductBulkApply = function (form) {
            const checked = document.querySelectorAll('input[data-product-label]:checked');
            if (!checked.length) {
                if (typeof window.appAlert === 'function') {
                    window.appAlert('Select at least one product in the table.', { icon: 'warning', title: 'No products selected' });
                } else {
                    alert('Select at least one product in the table.');
                }
                return false;
            }

            const container = document.getElementById('product-bulk-apply-ids');
            container.innerHTML = '';
            checked.forEach((checkbox) => {
                const input = document.createElement('input');
                input.type = 'hidden';
                input.name = 'product_ids[]';
                input.value = checkbox.value;
                container.appendChild(input);
            });

            return true;
        };
    </script>
@endcan
