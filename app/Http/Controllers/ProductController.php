<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\AppliesInventoryLocationContext;
use App\Http\Controllers\Concerns\SyncsPublicPhotoUpload;
use App\Exports\ProductBulkUpdateExport;
use App\Imports\ProductBulkUpdateImport;
use App\Models\Brand;
use App\Models\Category;
use App\Models\GoodsReceipt;
use App\Models\GoodsReceiptLine;
use App\Models\ManualStockAdjustment;
use App\Models\Product;
use App\Models\PurchaseOrderLine;
use App\Models\ReturnOrder;
use App\Models\SalesInvoice;
use App\Models\SerialItem;
use App\Models\StockLedger;
use App\Models\StockTransfer;
use App\Models\User;
use App\Services\InventoryBalanceService;
use App\Services\ManualStockAdjustmentService;
use App\Services\ProductBulkUpdateService;
use App\Services\SalesLineDiscountLimitService;
use App\Support\CompanyPad;
use App\Support\LedgerBackedInventory;
use App\Support\ProductBarcode;
use App\Support\SerialLabelBarcode;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class ProductController extends Controller
{
    use AppliesInventoryLocationContext;
    use SyncsPublicPhotoUpload;

    public function index(Request $request): View
    {
        [$filters, $locationId, $productsQuery, $accessibleLocationIds] = $this->productListQuery($request);

        $editId = $request->integer('edit');
        $editingProduct = $editId ? Product::find($editId) : null;
        $brands = Brand::orderBy('name')->get();
        $categories = Category::orderBy('name')->get();
        $locations = DB::table('locations')
            ->where('is_active', true)
            ->when($accessibleLocationIds !== [], fn ($query) => $query->whereIn('id', $accessibleLocationIds))
            ->orderBy('name')
            ->get(['id', 'code', 'name']);

        $products = $productsQuery->paginate($this->resolvePerPage($request, 15))->withQueryString();
        $this->attachInventorySummaries($products->getCollection(), $locationId);

        $productIds = $products->getCollection()->pluck('id');
        $serialLookup = [];

        if ($productIds->isNotEmpty()) {
            $serialLookup = SerialItem::query()
                ->whereIn('serial_items.product_id', $productIds)
                ->when($locationId !== null, fn ($query) => $query->where('serial_items.current_location_id', $locationId))
                ->leftJoin('locations', 'locations.id', '=', 'serial_items.current_location_id')
                ->orderBy('serial_items.product_id')
                ->orderBy('serial_items.serial_number')
                ->get([
                    'serial_items.product_id',
                    'serial_items.id',
                    'serial_items.serial_number',
                    'serial_items.manufacturer_serial',
                    'serial_items.status',
                    'serial_items.current_location_id',
                    'locations.name as location_name',
                    'locations.code as location_code',
                ])
                ->groupBy('product_id')
                ->map(function ($rows) {
                    return $rows->map(function ($row) {
                        return [
                            'id' => $row->id,
                            'serial_number' => $row->serial_number,
                            'manufacturer_serial' => $row->manufacturer_serial,
                            'status' => $row->status,
                            'location_id' => $row->current_location_id,
                            'location_name' => $row->location_name,
                            'location_code' => $row->location_code,
                        ];
                    })->values()->all();
                })
                ->all();
        }

        $productLookup = $products->getCollection()
            ->mapWithKeys(function (Product $product) {
                return [
                    $product->id => [
                        'id' => $product->id,
                        'name' => $product->name,
                        'sku' => $product->sku,
                        'inventory_summary' => $product->inventory_summary,
                    ],
                ];
            })
            ->all();

        $kpis = [
            'total' => Product::count(),
            'active' => Product::where('is_active', true)->count(),
            'inactive' => Product::where('is_active', false)->count(),
            'serialized' => Product::where('is_serialized', true)->count(),
            'filtered' => $products->total(),
        ];

        $filterLocation = $locationId !== null
            ? $locations->firstWhere('id', $locationId)
            : null;

        return view('products.index', compact(
            'products',
            'editingProduct',
            'brands',
            'categories',
            'locations',
            'filterLocation',
            'serialLookup',
            'productLookup',
            'filters',
            'kpis',
        ));
    }

    public function print(Request $request): View
    {
        $this->authorize('viewAny', Product::class);

        [$filters, $locationId, $productsQuery] = $this->productListQuery($request);
        $products = $productsQuery->get();
        $this->attachInventorySummaries($products, $locationId);

        $subtitleParts = ['Generated '.Carbon::now()->format('Y-m-d H:i')];
        $subtitleParts[] = $filters['list'] === 'inactive' ? 'Deactivated' : 'Active';
        if ($filters['q'] !== '') {
            $subtitleParts[] = 'Search: '.$filters['q'];
        }
        if ($filters['brand_id'] !== '') {
            $subtitleParts[] = 'Brand: '.(Brand::find((int) $filters['brand_id'])?->name ?? $filters['brand_id']);
        }
        if ($filters['category_id'] !== '') {
            $subtitleParts[] = 'Category: '.(Category::find((int) $filters['category_id'])?->name ?? $filters['category_id']);
        }
        if ($locationId !== null) {
            $locationName = DB::table('locations')->where('id', $locationId)->value('name');
            $subtitleParts[] = 'Branch: '.($locationName ?: $locationId);
        }
        if ($filters['stock'] === 'in_stock') {
            $subtitleParts[] = 'In stock';
        } elseif ($filters['stock'] === 'out_of_stock') {
            $subtitleParts[] = 'Out of stock';
        }

        $printQuery = array_filter($filters, fn ($value) => $value !== '' && $value !== null);

        return view('products.print', [
            'companyPad' => CompanyPad::settings(),
            'reportTitle' => $filters['list'] === 'inactive' ? 'Deactivated products' : 'Product list',
            'subtitle' => implode(' · ', $subtitleParts),
            'products' => $products,
            'printBackUrl' => route('products.index', $printQuery),
            'printBackLabel' => '← Products',
            'showReportsHubLink' => false,
            'kpis' => [
                'listed' => $products->count(),
                'on_hand' => (int) $products->sum(fn (Product $product) => (int) ($product->inventory_summary['on_hand_qty'] ?? 0)),
                'available' => (int) $products->sum(fn (Product $product) => (int) ($product->inventory_summary['available_qty'] ?? 0)),
            ],
        ]);
    }

    public function nextBarcode(): JsonResponse
    {
        $this->authorize('viewAny', Product::class);

        return response()->json(['barcode' => ProductBarcode::generateUnique()]);
    }

    public function printLabels(Request $request): View|RedirectResponse
    {
        $this->authorize('viewAny', Product::class);

        $ids = array_values(array_unique(array_filter(array_map('intval', (array) $request->input('product_ids', [])))));
        $productFilterId = $request->integer('product_id') ?: null;
        if ($productFilterId !== null) {
            $ids = [$productFilterId];
        }

        $accessibleLocationIds = $this->accessibleLocationIds();
        $locationId = $request->integer('location_id') ?: null;
        if ($locationId !== null && ! in_array($locationId, $accessibleLocationIds, true)) {
            abort(403);
        }

        $productSearch = trim((string) $request->input('product_q', ''));

        $serialsQuery = SerialItem::query()
            ->where('status', 'in_stock')
            ->when($ids !== [], fn ($query) => $query->whereIn('product_id', $ids))
            ->when($locationId !== null, fn ($query) => $query->where('current_location_id', $locationId))
            ->when($productSearch !== '' && $ids === [], function ($query) use ($productSearch): void {
                $term = '%'.addcslashes($productSearch, '%_\\').'%';
                $query->whereHas('product', function ($productQuery) use ($term): void {
                    $productQuery->where('name', 'like', $term)
                        ->orWhere('sku', 'like', $term)
                        ->orWhere('barcode', 'like', $term)
                        ->orWhere('model_number', 'like', $term);
                });
            })
            ->with('product')
            ->orderBy('product_id')
            ->orderBy('serial_number');

        $serials = $serialsQuery->get();

        $labelFormat = SerialLabelBarcode::normalizeFormat($request->input('format'));
        $codeSvgs = SerialLabelBarcode::codeSvgsForSerials($serials, $labelFormat);

        $queryBase = array_filter([
            'location_id' => $locationId,
            'product_id' => $productFilterId,
            'product_q' => $productSearch !== '' ? $productSearch : null,
        ], fn ($v) => $v !== null && $v !== '');
        if ($ids !== [] && $productFilterId === null) {
            $queryBase['product_ids'] = $ids;
        }

        $labelsFormatUrls = [
            'qr' => route('products.labels', array_merge($queryBase, ['format' => SerialLabelBarcode::FORMAT_QR])),
            'barcode' => route('products.labels', array_merge($queryBase, ['format' => SerialLabelBarcode::FORMAT_BARCODE])),
        ];

        $labelFilterLocations = DB::table('locations')
            ->where('is_active', true)
            ->when($accessibleLocationIds !== [], fn ($q) => $q->whereIn('id', $accessibleLocationIds))
            ->orderBy('name')
            ->get(['id', 'code', 'name']);

        $labelFilterProducts = Product::query()
            ->where('is_active', true)
            ->whereExists(function ($sub) use ($locationId, $accessibleLocationIds): void {
                $sub->selectRaw('1')
                    ->from('serial_items')
                    ->whereColumn('serial_items.product_id', 'products.id')
                    ->where('serial_items.status', 'in_stock')
                    ->when($locationId !== null, fn ($q) => $q->where('serial_items.current_location_id', $locationId))
                    ->when(
                        $locationId === null && $accessibleLocationIds !== [],
                        fn ($q) => $q->whereIn('serial_items.current_location_id', $accessibleLocationIds)
                    );
            })
            ->orderBy('name')
            ->get(['id', 'sku', 'name']);

        $locationName = null;
        if ($locationId !== null) {
            $locationName = $labelFilterLocations->firstWhere('id', $locationId)?->name
                ?? DB::table('locations')->where('id', $locationId)->value('name');
        }

        $toolbarNote = $serials->count().' label(s)';
        if ($locationName) {
            $toolbarNote .= ' · '.$locationName;
        } else {
            $toolbarNote .= ' · all branches';
        }
        if ($productFilterId !== null) {
            $picked = $labelFilterProducts->firstWhere('id', $productFilterId);
            $toolbarNote .= ' · '.($picked?->sku ?? ('#'.$productFilterId));
        } elseif ($ids !== []) {
            $toolbarNote .= ' · '.count($ids).' product(s)';
        }

        return view('products.labels-print', [
            'serials' => $serials,
            'codeSvgs' => $codeSvgs,
            'labelFormat' => $labelFormat,
            'labelsFormatUrls' => $labelsFormatUrls,
            'labelsBackUrl' => route('products.index', array_filter([
                'location_id' => $locationId,
            ])),
            'labelToolbarNote' => $toolbarNote,
            'labelEmptyMessage' => $locationId !== null
                ? 'No in-stock serial units match these filters at this branch.'
                : 'No in-stock serial units match these filters.',
            'labelFilterLocations' => $labelFilterLocations,
            'labelFilterProducts' => $labelFilterProducts,
            'labelLocationId' => $locationId,
            'labelProductId' => $productFilterId,
            'labelProductIds' => $productFilterId !== null ? [] : $ids,
            'labelProductSearch' => $productSearch,
        ]);
    }

    public function create(): RedirectResponse
    {
        return redirect()->route('products.index', ['drawer' => 'create']);
    }

    public function store(Request $request): RedirectResponse
    {
        $modelNumber = trim((string) $request->input('model_number', ''));
        $request->merge([
            'barcode' => trim((string) $request->input('barcode', '')),
            'model_number' => $modelNumber === '' ? null : $modelNumber,
        ]);

        $data = $request->validate(array_merge([
            'sku' => ['required', 'string', 'max:60', 'unique:products,sku'],
            'barcode' => ['nullable', 'string', 'max:100', 'unique:products,barcode'],
            'brand_id' => ['required', 'exists:brands,id'],
            'category_id' => ['required', 'exists:categories,id'],
            'name' => ['required', 'string', 'max:200'],
            'model_number' => ['nullable', 'string', 'max:100', Rule::unique('products', 'model_number')],
            'description' => ['nullable', 'string'],
            'warranty_months' => ['required', 'integer', 'min:0', 'max:120'],
            'default_purchase_price' => ['required', 'numeric', 'min:0'],
            'default_sale_price' => ['required', 'numeric', 'min:0'],
            'maximum_deductible' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'reorder_level' => ['required', 'integer', 'min:0'],
            'unit_of_measure' => ['required', 'string', 'max:20'],
            'is_active' => ['nullable', 'boolean'],
        ], $this->photoUploadRules()));

        $data['is_serialized'] = true;
        $data['is_active'] = $request->boolean('is_active', true);

        $md = $request->input('maximum_deductible');
        $data['maximum_deductible'] = ($md === null || $md === '') ? null : round((float) $md, 2);

        if (($data['barcode'] ?? '') === '') {
            $data['barcode'] = ProductBarcode::generateUnique();
        }

        unset($data['photo'], $data['remove_photo']);

        $product = Product::create($data);

        $photoState = $this->resolvedPhotoPathState($request, 'photos/products/'.$product->id, null);
        $product->forceFill($photoState)->save();

        return redirect()->route('products.index')->with('status', 'Product created successfully.');
    }

    public function quickStore(Request $request): JsonResponse
    {
        $this->authorize('create', Product::class);

        $modelNumber = trim((string) $request->input('model_number', ''));
        $request->merge([
            'barcode' => trim((string) $request->input('barcode', '')),
            'model_number' => $modelNumber === '' ? null : $modelNumber,
        ]);

        $data = $request->validate([
            'sku' => ['required', 'string', 'max:60', 'unique:products,sku'],
            'barcode' => ['nullable', 'string', 'max:100', 'unique:products,barcode'],
            'brand_id' => ['required', 'exists:brands,id'],
            'category_id' => ['required', 'exists:categories,id'],
            'name' => ['required', 'string', 'max:200'],
            'model_number' => ['nullable', 'string', 'max:100', Rule::unique('products', 'model_number')],
            'default_purchase_price' => ['required', 'numeric', 'min:0'],
            'default_sale_price' => ['required', 'numeric', 'min:0'],
            'warranty_months' => ['nullable', 'integer', 'min:0', 'max:120'],
            'unit_of_measure' => ['nullable', 'string', 'max:20'],
        ]);

        if (($data['barcode'] ?? '') === '') {
            $data['barcode'] = ProductBarcode::generateUnique();
        }

        $product = Product::create([
            'sku' => $data['sku'],
            'barcode' => $data['barcode'],
            'brand_id' => $data['brand_id'],
            'category_id' => $data['category_id'],
            'name' => $data['name'],
            'model_number' => $data['model_number'],
            'description' => null,
            'warranty_months' => (int) ($data['warranty_months'] ?? 0),
            'default_purchase_price' => $data['default_purchase_price'],
            'default_sale_price' => $data['default_sale_price'],
            'maximum_deductible' => null,
            'reorder_level' => 0,
            'unit_of_measure' => $data['unit_of_measure'] ?? 'pieces',
            'is_serialized' => true,
            'is_active' => true,
        ]);

        $product->load(['brand:id,name', 'category:id,name']);

        return response()->json([
            'product' => [
                'id' => $product->id,
                'sku' => $product->sku,
                'name' => $product->name,
                'label' => $product->sku.' - '.$product->name,
                'search' => Str::lower($product->sku.' '.$product->name),
                'default_purchase_price' => (float) $product->default_purchase_price,
                'default_sale_price' => (float) $product->default_sale_price,
            ],
        ], 201);
    }

    public function edit(Product $product): RedirectResponse
    {
        return redirect()->route('products.index', ['drawer' => 'edit', 'edit' => $product->id]);
    }

    public function show(Product $product): View
    {
        $user = auth()->user();
        $canCatalogView = $user && (
            $user->can('viewAny', Product::class)
            || $user->can('view', $product)
        );
        $canGlobalSearchView = $user && $user->can('globalSearchView', Product::class);

        if (! $canCatalogView && ! $canGlobalSearchView) {
            abort(403);
        }

        // Search-only viewers see stock at every location; they cannot open the products catalog.
        $globalSearchViewOnly = (bool) ($canGlobalSearchView && ! $canCatalogView);

        $product->load(['brand', 'category']);

        $inventoryBalances = DB::query()
            ->fromSub(LedgerBackedInventory::balancesSubquery(), 'inventory_balances')
            ->where('inventory_balances.product_id', $product->id)
            ->join('locations', 'locations.id', '=', 'inventory_balances.location_id')
            ->orderBy('locations.name')
            ->get([
                'inventory_balances.product_id',
                'inventory_balances.location_id',
                'inventory_balances.on_hand_qty',
                'inventory_balances.reserved_qty',
                'inventory_balances.available_qty',
                'locations.code as location_code',
                'locations.name as location_name',
                'locations.type as location_type',
            ]);

        $productSerialsForAlpine = [];
        $purchaseOrders = collect();
        $goodsReceipts = collect();
        $locationActivity = collect();
        $inventoryTimeline = collect();

        if (! $globalSearchViewOnly) {
            $serialRows = SerialItem::query()
                ->where('serial_items.product_id', $product->id)
                ->leftJoin('locations', 'locations.id', '=', 'serial_items.current_location_id')
                ->leftJoin('sales_invoice_lines as sil', 'sil.id', '=', 'serial_items.sale_invoice_line_id')
                ->leftJoin('sales_invoices as si', 'si.id', '=', 'sil.sales_invoice_id')
                ->leftJoin('customers as c', 'c.id', '=', 'si.customer_id')
                ->orderBy('serial_items.serial_number')
                ->get([
                    'serial_items.id',
                    'serial_items.serial_number',
                    'serial_items.manufacturer_serial',
                    'serial_items.status',
                    'serial_items.current_location_id',
                    'serial_items.purchase_cost',
                    'serial_items.sale_price',
                    'serial_items.warranty_start_date',
                    'serial_items.warranty_end_date',
                    'locations.code as location_code',
                    'locations.name as location_name',
                    'si.id as sales_invoice_id',
                    'si.invoice_number',
                    'si.invoice_date',
                    'c.name as customer_name',
                ]);

            $productSerialsForAlpine = $serialRows->map(function ($row) use ($product): array {
                $invoiceId = $row->sales_invoice_id !== null ? (int) $row->sales_invoice_id : null;

                return [
                    'id' => (int) $row->id,
                    'serial_number' => (string) $row->serial_number,
                    'manufacturer_serial' => (string) ($row->manufacturer_serial ?? ''),
                    'status' => (string) $row->status,
                    'location_code' => (string) ($row->location_code ?? ''),
                    'location_name' => (string) ($row->location_name ?? ''),
                    'purchase_cost' => $row->purchase_cost !== null ? (float) $row->purchase_cost : null,
                    'sale_price' => $row->sale_price !== null ? (float) $row->sale_price : null,
                    'can_set_price' => in_array((string) $row->status, ['in_stock', 'reserved'], true),
                    'warranty_start_date' => $row->warranty_start_date ? (string) $row->warranty_start_date : '',
                    'warranty_end_date' => $row->warranty_end_date ? (string) $row->warranty_end_date : '',
                    'invoice_number' => (string) ($row->invoice_number ?? ''),
                    'invoice_date' => $row->invoice_date ? (string) $row->invoice_date : '',
                    'customer_name' => (string) ($row->customer_name ?? ''),
                    'sales_invoice_id' => $invoiceId,
                    'invoice_url' => $invoiceId ? route('sales.invoices.show', $invoiceId) : '',
                    'can_remove_serial' => $product->is_serialized
                        && $row->current_location_id !== null
                        && in_array((string) $row->status, ['in_stock', 'damaged'], true),
                ];
            })->values()->all();

            $purchaseOrders = PurchaseOrderLine::query()
                ->where('purchase_order_lines.product_id', $product->id)
                ->join('purchase_orders', 'purchase_orders.id', '=', 'purchase_order_lines.purchase_order_id')
                ->join('suppliers', 'suppliers.id', '=', 'purchase_orders.supplier_id')
                ->join('locations', 'locations.id', '=', 'purchase_orders.destination_location_id')
                ->orderByDesc('purchase_orders.ordered_at')
                ->get([
                    'purchase_order_lines.id as line_id',
                    'purchase_order_lines.qty_ordered',
                    'purchase_order_lines.qty_received',
                    'purchase_order_lines.unit_cost',
                    'purchase_order_lines.line_total',
                    'purchase_orders.id as purchase_order_id',
                    'purchase_orders.po_number',
                    'purchase_orders.status as purchase_order_status',
                    'purchase_orders.ordered_at',
                    'purchase_orders.expected_at',
                    'suppliers.name as supplier_name',
                    'locations.code as destination_code',
                    'locations.name as destination_name',
                ]);

            $goodsReceipts = GoodsReceiptLine::query()
                ->where('goods_receipt_lines.product_id', $product->id)
                ->join('goods_receipts', 'goods_receipts.id', '=', 'goods_receipt_lines.goods_receipt_id')
                ->join('suppliers', 'suppliers.id', '=', 'goods_receipts.supplier_id')
                ->join('locations', 'locations.id', '=', 'goods_receipts.location_id')
                ->leftJoin('purchase_orders', 'purchase_orders.id', '=', 'goods_receipts.purchase_order_id')
                ->orderByDesc('goods_receipts.received_at')
                ->get([
                    'goods_receipt_lines.id as line_id',
                    'goods_receipt_lines.qty_received',
                    'goods_receipt_lines.unit_cost',
                    'goods_receipts.id as goods_receipt_id',
                    'goods_receipts.grn_number',
                    'goods_receipts.status as goods_receipt_status',
                    'goods_receipts.received_at',
                    'goods_receipts.delivery_note_ref',
                    'purchase_orders.po_number as purchase_order_number',
                    'suppliers.name as supplier_name',
                    'locations.code as location_code',
                    'locations.name as location_name',
                ]);

            $locationActivity = $this->buildProductLocationActivity($product);
            $inventoryTimeline = $this->buildProductInventoryLedgerTimeline($product);
        }

        $totalOnHandQty = (int) $inventoryBalances->sum('on_hand_qty');
        $totalReservedQty = (int) $inventoryBalances->sum('reserved_qty');
        $totalAvailableQty = (int) $inventoryBalances->sum('available_qty');
        $totalInventoryValue = $globalSearchViewOnly
            ? 0.0
            : (float) SerialItem::query()
                ->where('product_id', $product->id)
                ->whereIn('status', ['in_stock', 'reserved'])
                ->sum('purchase_cost');

        $salesAllTime = 0.0;
        $salesThisMonth = 0.0;
        $salesThisYear = 0.0;
        if (! $globalSearchViewOnly) {
            $salesBaseQuery = DB::table('sales_invoice_lines')
                ->join('sales_invoices', 'sales_invoices.id', '=', 'sales_invoice_lines.sales_invoice_id')
                ->where('sales_invoice_lines.product_id', $product->id)
                ->whereIn('sales_invoices.status', ['posted', 'active']);

            $salesAllTime = (float) (clone $salesBaseQuery)->sum('sales_invoice_lines.line_total');
            $salesThisMonth = (float) (clone $salesBaseQuery)
                ->whereBetween('sales_invoices.invoice_date', [now()->startOfMonth()->toDateString(), now()->endOfMonth()->toDateString()])
                ->sum('sales_invoice_lines.line_total');
            $salesThisYear = (float) (clone $salesBaseQuery)
                ->whereYear('sales_invoices.invoice_date', now()->year)
                ->sum('sales_invoice_lines.line_total');
        }

        $summary = [
            'total_on_hand_qty' => $totalOnHandQty,
            'total_reserved_qty' => $totalReservedQty,
            'total_available_qty' => $totalAvailableQty,
            'total_inventory_value' => $totalInventoryValue,
            'sales_all_time' => $salesAllTime,
            'sales_this_month' => $salesThisMonth,
            'sales_this_year' => $salesThisYear,
        ];

        $allowedTabs = $globalSearchViewOnly
            ? ['inventory']
            : ['inventory', 'serials', 'po', 'grn', 'location', 'timeline'];
        $tabQuery = (string) request()->query('tab', '');
        $initialTab = in_array($tabQuery, $allowedTabs, true) ? $tabQuery : 'inventory';

        $productSerialMeta = [
            'productId' => $product->id,
            'canRemoveSerial' => ! $globalSearchViewOnly
                && auth()->check()
                && auth()->user()->can('manageStockLevels', Product::class),
            'destroySerialBaseUrl' => url('/products/'.$product->id.'/serials'),
            'bulkDestroySerialsUrl' => route('products.serials.bulk-destroy', $product),
            'canSetSerialPrice' => ! $globalSearchViewOnly
                && auth()->check()
                && auth()->user()->can('update', $product),
            'serialPriceBaseUrl' => url('/products/'.$product->id.'/serials'),
            'bulkSerialPriceUrl' => route('products.serials.bulk-sale-price', $product),
            'defaultSalePrice' => (float) $product->default_sale_price,
            'initialTab' => $initialTab,
            'globalSearchViewOnly' => $globalSearchViewOnly,
        ];

        $discountLimitService = app(SalesLineDiscountLimitService::class);
        $maxDiscountPercent = $discountLimitService->effectiveMaxDiscountPercent($product);
        $discountLimitLabel = $maxDiscountPercent === null
            ? 'Unlimited'
            : number_format($maxDiscountPercent, 2).'%';

        $canViewPurchasePrice = $user instanceof User
            && ! $globalSearchViewOnly
            && $user->can('viewPurchasePrice', Product::class);
        return view('products.show', compact(
            'product',
            'inventoryBalances',
            'productSerialsForAlpine',
            'productSerialMeta',
            'purchaseOrders',
            'goodsReceipts',
            'summary',
            'locationActivity',
            'inventoryTimeline',
            'globalSearchViewOnly',
            'canViewPurchasePrice',
            'discountLimitLabel',
            'maxDiscountPercent',
        ));
    }

    /**
     * Chronological stock ledger for this product: each row is a moment the on-hand quantity
     * at a location changed, with the running on-hand at that location and total across all locations.
     *
     * @return Collection<int, array<string, mixed>>
     */
    private function buildProductInventoryLedgerTimeline(Product $product): Collection
    {
        $rows = DB::table('stock_ledger')
            ->where('stock_ledger.product_id', $product->id)
            ->join('locations', 'locations.id', '=', 'stock_ledger.location_id')
            ->orderBy('stock_ledger.event_time')
            ->orderBy('stock_ledger.id')
            ->get([
                'stock_ledger.id',
                'stock_ledger.event_time',
                'stock_ledger.movement_type',
                'stock_ledger.quantity_delta',
                'stock_ledger.location_id',
                'stock_ledger.serial_item_id',
                'stock_ledger.reference_type',
                'stock_ledger.reference_id',
                'stock_ledger.unit_cost',
                'locations.code as location_code',
                'locations.name as location_name',
            ]);

        if ($rows->isEmpty()) {
            return collect();
        }

        $serialIds = $rows->pluck('serial_item_id')->filter(fn ($id) => $id !== null)->map(fn ($id) => (int) $id)->unique()->values();
        /** @var Collection<int|string, mixed> */
        $serialNumbers = SerialItem::query()
            ->whereIn('id', $serialIds)
            ->pluck('serial_number', 'id');

        $transferIds = $rows->where('reference_type', StockTransfer::LEDGER_REFERENCE_TYPE)->pluck('reference_id')->map(fn ($id) => (int) $id)->unique()->values();
        $invoiceIds = $rows->where('reference_type', 'SalesInvoice')->pluck('reference_id')->map(fn ($id) => (int) $id)->unique()->values();
        $grnIds = $rows->where('reference_type', 'GoodsReceipt')->pluck('reference_id')->map(fn ($id) => (int) $id)->unique()->values();
        $returnIds = $rows->where('reference_type', 'ReturnOrder')->pluck('reference_id')->map(fn ($id) => (int) $id)->unique()->values();

        $transferNumbers = $transferIds->isNotEmpty()
            ? StockTransfer::query()->whereIn('id', $transferIds)->pluck('transfer_number', 'id')
            : collect();
        $invoiceRefs = $invoiceIds->isNotEmpty()
            ? SalesInvoice::query()->whereIn('id', $invoiceIds)->get(['id', 'invoice_number'])->keyBy('id')
            : collect();
        $grnNumbers = $grnIds->isNotEmpty()
            ? GoodsReceipt::query()->whereIn('id', $grnIds)->pluck('grn_number', 'id')
            : collect();
        $returnNumbers = $returnIds->isNotEmpty()
            ? ReturnOrder::query()->whereIn('id', $returnIds)->pluck('return_number', 'id')
            : collect();

        $balancesByLocation = [];
        $out = [];

        foreach ($rows as $row) {
            $locId = (int) $row->location_id;
            $delta = (int) $row->quantity_delta;

            $before = $balancesByLocation[$locId] ?? 0;
            $balancesByLocation[$locId] = $before + $delta;
            $afterThisLocation = $balancesByLocation[$locId];
            $totalSystemOnHand = (int) array_sum($balancesByLocation);

            $refType = (string) $row->reference_type;
            $refId = (int) $row->reference_id;
            [$refLabel, $refUrl] = $this->resolveStockLedgerReferenceLink($refType, $refId, $transferNumbers, $invoiceRefs, $grnNumbers, $returnNumbers);

            $movementType = (string) $row->movement_type;
            $movementLabel = Str::of($movementType)->replace('_', ' ')->title()->toString();

            $serialId = $row->serial_item_id !== null ? (int) $row->serial_item_id : null;
            $serialNumber = $serialId !== null ? (string) ($serialNumbers->get($serialId) ?? '') : '';

            $eventAt = Carbon::parse($row->event_time)->timezone(config('app.timezone'));
            $costStr = $row->unit_cost !== null ? number_format((float) $row->unit_cost, 2) : '';

            $deltaLabel = ($delta >= 0 ? '+' : '').(string) $delta;

            $searchBlob = Str::lower(implode(' ', array_filter([
                $eventAt->format('Y-m-d H:i:s'),
                $movementLabel,
                $movementType,
                $row->location_code,
                $row->location_name,
                $deltaLabel,
                $refLabel,
                $serialNumber,
                $refType === ManualStockAdjustment::class ? 'adjustment '.$refId : '',
            ], fn ($s) => (string) $s !== '')));

            $out[] = [
                'ledger_id' => (int) $row->id,
                'event_time_iso' => $eventAt->toIso8601String(),
                'event_time_human' => $eventAt->format('Y-m-d H:i:s'),
                'movement_type' => $movementType,
                'movement_label' => $movementLabel,
                'location_id' => $locId,
                'location_code' => (string) $row->location_code,
                'location_name' => (string) $row->location_name,
                'quantity_delta' => $delta,
                'delta_label' => $deltaLabel,
                'qty_on_hand_after_this_location' => $afterThisLocation,
                'total_on_hand_all_locations' => $totalSystemOnHand,
                'unit_cost' => $row->unit_cost !== null ? (float) $row->unit_cost : null,
                'unit_cost_formatted' => $costStr,
                'serial_item_id' => $serialId,
                'serial_number' => $serialNumber,
                'reference_type' => $refType,
                'reference_id' => $refId,
                'reference_label' => $refLabel,
                'reference_url' => $refUrl,
                'search_blob' => $searchBlob,
            ];
        }

        return collect($out);
    }

    /**
     * @param  Collection<int|string, mixed>  $transferNumbers
     * @param  Collection<int|string, SalesInvoice>  $invoiceRefs
     * @param  Collection<int|string, mixed>  $grnNumbers
     * @param  Collection<int|string, mixed>  $returnNumbers
     * @return array{0: string, 1: string|null}
     */
    private function resolveStockLedgerReferenceLink(
        string $referenceType,
        int $referenceId,
        Collection $transferNumbers,
        Collection $invoiceRefs,
        Collection $grnNumbers,
        Collection $returnNumbers,
    ): array {
        switch ($referenceType) {
            case StockTransfer::LEDGER_REFERENCE_TYPE:
                $num = (string) ($transferNumbers->get($referenceId) ?? '');
                $label = $num !== '' ? 'Transfer '.$num : 'Transfer #'.$referenceId;
                $url = route('transfers.index', ['q' => $num !== '' ? $num : $referenceId]);

                return [$label, $url];
            case 'SalesInvoice':
                $inv = $invoiceRefs->get($referenceId);
                $invNo = $inv ? (string) $inv->invoice_number : '';

                return [
                    $invNo !== '' ? 'Invoice '.$invNo : 'Invoice #'.$referenceId,
                    route('sales.invoices.show', $referenceId),
                ];
            case 'GoodsReceipt':
                $grn = (string) ($grnNumbers->get($referenceId) ?? '');

                return [
                    $grn !== '' ? 'GRN '.$grn : 'Goods receipt #'.$referenceId,
                    route('goods-receipts.show', $referenceId),
                ];
            case 'ReturnOrder':
                $ret = (string) ($returnNumbers->get($referenceId) ?? '');

                return [
                    $ret !== '' ? 'Return '.$ret : 'Return #'.$referenceId,
                    route('returns.show', $referenceId),
                ];
            case ManualStockAdjustment::class:

                return ['Stock adjustment #'.$referenceId, null];
            case 'DizicashierStockSync':

                return ['Legacy import (Dizicashier)', null];
            default:

                return [Str::limit($referenceType, 40).' #'.$referenceId, null];
        }
    }

    private function buildProductLocationActivity(Product $product): Collection
    {
        $transferLines = DB::table('stock_transfer_lines')
            ->join('stock_transfers', 'stock_transfers.id', '=', 'stock_transfer_lines.stock_transfer_id')
            ->join('locations as src', 'src.id', '=', 'stock_transfers.source_location_id')
            ->join('locations as dst', 'dst.id', '=', 'stock_transfers.destination_location_id')
            ->where('stock_transfer_lines.product_id', $product->id)
            ->orderByDesc('stock_transfers.requested_at')
            ->get([
                'stock_transfers.id as stock_transfer_id',
                'stock_transfers.transfer_number',
                'stock_transfers.status',
                'stock_transfers.requested_at',
                'stock_transfers.issued_at',
                'stock_transfers.received_at',
                'stock_transfer_lines.qty_requested',
                'stock_transfer_lines.qty_issued',
                'stock_transfer_lines.qty_received',
                'src.id as source_location_id',
                'src.code as source_code',
                'src.name as source_name',
                'src.type as source_type',
                'dst.id as destination_location_id',
                'dst.code as dest_code',
                'dst.name as dest_name',
                'dst.type as dest_type',
            ]);

        $invoiceLines = DB::table('sales_invoice_lines')
            ->join('sales_invoices', 'sales_invoices.id', '=', 'sales_invoice_lines.sales_invoice_id')
            ->join('locations as br', 'br.id', '=', 'sales_invoices.branch_id')
            ->join('customers', 'customers.id', '=', 'sales_invoices.customer_id')
            ->where('sales_invoice_lines.product_id', $product->id)
            ->whereIn('sales_invoices.status', ['posted', 'active'])
            ->orderByDesc('sales_invoices.invoice_date')
            ->orderByDesc('sales_invoices.id')
            ->get([
                'sales_invoices.id as sales_invoice_id',
                'sales_invoices.invoice_number',
                'sales_invoices.invoice_date',
                'sales_invoices.created_at',
                'sales_invoices.status',
                'sales_invoices.payment_status',
                'sales_invoice_lines.qty',
                'sales_invoice_lines.line_total',
                'br.id as branch_id',
                'br.code as branch_code',
                'br.name as branch_name',
                'br.type as branch_type',
                'customers.name as customer_name',
            ]);

        $byLocationId = [];

        $ensureBucket = static function (int $id, string $code, string $name, string $type) use (&$byLocationId): void {
            if (! isset($byLocationId[$id])) {
                $byLocationId[$id] = [
                    'location_id' => $id,
                    'location_code' => $code,
                    'location_name' => $name,
                    'location_type' => $type,
                    'transfers' => [],
                    'invoices' => [],
                ];
            }
        };

        foreach ($transferLines as $t) {
            $sourceId = (int) $t->source_location_id;
            $destId = (int) $t->destination_location_id;

            $ensureBucket($sourceId, $t->source_code, $t->source_name, $t->source_type);
            $byLocationId[$sourceId]['transfers'][] = [
                'stock_transfer_id' => (int) $t->stock_transfer_id,
                'transfer_number' => $t->transfer_number,
                'direction' => 'outbound',
                'counterparty_code' => $t->dest_code,
                'counterparty_name' => $t->dest_name,
                'status' => $t->status,
                'qty_requested' => (int) $t->qty_requested,
                'qty_issued' => (int) $t->qty_issued,
                'qty_received' => (int) $t->qty_received,
                'requested_at' => $t->requested_at,
                'issued_at' => $t->issued_at,
                'received_at' => $t->received_at,
            ];

            $ensureBucket($destId, $t->dest_code, $t->dest_name, $t->dest_type);
            $byLocationId[$destId]['transfers'][] = [
                'stock_transfer_id' => (int) $t->stock_transfer_id,
                'transfer_number' => $t->transfer_number,
                'direction' => 'inbound',
                'counterparty_code' => $t->source_code,
                'counterparty_name' => $t->source_name,
                'status' => $t->status,
                'qty_requested' => (int) $t->qty_requested,
                'qty_issued' => (int) $t->qty_issued,
                'qty_received' => (int) $t->qty_received,
                'requested_at' => $t->requested_at,
                'issued_at' => $t->issued_at,
                'received_at' => $t->received_at,
            ];
        }

        foreach ($invoiceLines as $inv) {
            $branchId = (int) $inv->branch_id;
            $ensureBucket($branchId, $inv->branch_code, $inv->branch_name, $inv->branch_type);
            $byLocationId[$branchId]['invoices'][] = [
                'sales_invoice_id' => (int) $inv->sales_invoice_id,
                'invoice_number' => $inv->invoice_number,
                'invoice_date' => $inv->invoice_date,
                'created_at' => $inv->created_at,
                'customer_name' => $inv->customer_name,
                'status' => $inv->status,
                'payment_status' => $inv->payment_status,
                'qty' => (int) $inv->qty,
                'line_total' => (float) $inv->line_total,
            ];
        }

        foreach ($byLocationId as &$block) {
            usort($block['transfers'], static function (array $a, array $b): int {
                return strcmp((string) ($b['requested_at'] ?? ''), (string) ($a['requested_at'] ?? ''));
            });
            usort($block['invoices'], static function (array $a, array $b): int {
                return strcmp((string) ($b['invoice_date'] ?? ''), (string) ($a['invoice_date'] ?? ''));
            });
        }
        unset($block);

        return collect($byLocationId)->sortBy('location_code')->values();
    }

    public function destroySerial(
        Request $request,
        Product $product,
        SerialItem $serial,
        ManualStockAdjustmentService $manualStockAdjustmentService,
        InventoryBalanceService $inventoryBalanceService,
    ): RedirectResponse {
        $this->authorize('manageStockLevels', Product::class);

        $user = $request->user();
        if (! $user instanceof User) {
            abort(403);
        }

        if (! $product->is_serialized || (int) $serial->product_id !== (int) $product->id) {
            abort(404);
        }

        if ($serial->current_location_id === null) {
            return redirect()->to($this->productShowSerialsUrl($product))
                ->withErrors(['serial' => 'This serial is not at a branch; it cannot be removed from this screen.']);
        }

        if (! in_array((string) $serial->status, ['in_stock', 'damaged'], true)) {
            return redirect()->to($this->productShowSerialsUrl($product))
                ->withErrors(['serial' => 'Only in-stock or damaged-at-branch serials can be removed here.']);
        }

        $locationId = (int) $serial->current_location_id;
        $accessibleIds = $user->accessibleLocationIds();
        if ($accessibleIds === [] || ! in_array($locationId, $accessibleIds, true)) {
            abort(403);
        }

        if ($serial->status === 'damaged') {
            try {
                DB::transaction(function () use ($user, $product, $serial, $inventoryBalanceService): void {
                    $this->writeOffDamagedSerialStillAtBranch($user, $product, $serial, $inventoryBalanceService);
                });
            } catch (\Throwable $e) {
                return redirect()->to($this->productShowSerialsUrl($product))
                    ->withErrors(['serial' => $e->getMessage()]);
            }

            return redirect()->to($this->productShowSerialsUrl($product))
                ->with('status', 'Damaged serial '.$serial->serial_number.' was cleared from branch inventory (ledger damage out).');
        }

        try {
            $manualStockAdjustmentService->applyAdjustment(
                $user,
                $product,
                $locationId,
                null,
                'Removed from product detail: '.$serial->serial_number,
                [$serial->id],
                -1,
            );
        } catch (ValidationException $e) {
            return redirect()->to($this->productShowSerialsUrl($product))
                ->withErrors($e->errors());
        }

        return redirect()->to($this->productShowSerialsUrl($product))
            ->with('status', 'Serial '.$serial->serial_number.' was removed from stock (written off as damaged).');
    }

    public function bulkDestroySerials(
        Request $request,
        Product $product,
        ManualStockAdjustmentService $manualStockAdjustmentService,
        InventoryBalanceService $inventoryBalanceService,
    ): RedirectResponse {
        $this->authorize('manageStockLevels', Product::class);

        $user = $request->user();
        if (! $user instanceof User) {
            abort(403);
        }

        if (! $product->is_serialized) {
            abort(404);
        }

        $data = $request->validate([
            'serial_ids' => ['required', 'array', 'min:1', 'max:100'],
            'serial_ids.*' => ['integer', 'exists:serial_items,id'],
        ]);

        $ids = array_values(array_unique(array_map('intval', $data['serial_ids'])));

        $serials = SerialItem::query()
            ->where('product_id', $product->id)
            ->whereIn('id', $ids)
            ->get()
            ->keyBy('id');

        if ($serials->count() !== count($ids)) {
            return redirect()->to($this->productShowSerialsUrl($product))
                ->withErrors(['serial' => 'One or more serials do not belong to this product.']);
        }

        $accessibleIds = $user->accessibleLocationIds();
        if ($accessibleIds === []) {
            abort(403);
        }

        foreach ($serials as $serial) {
            if ($serial->current_location_id === null) {
                return redirect()->to($this->productShowSerialsUrl($product))
                    ->withErrors(['serial' => 'Serial '.$serial->serial_number.' is not at a branch.']);
            }
            if (! in_array((int) $serial->current_location_id, $accessibleIds, true)) {
                return redirect()->to($this->productShowSerialsUrl($product))
                    ->withErrors(['serial' => 'Serial '.$serial->serial_number.' is not at a location you can manage.']);
            }
            if (! in_array((string) $serial->status, ['in_stock', 'damaged'], true)) {
                return redirect()->to($this->productShowSerialsUrl($product))
                    ->withErrors(['serial' => 'Serial '.$serial->serial_number.' cannot be removed (status: '.$serial->status.').']);
            }
        }

        $inStockByLocation = [];
        $damagedSerials = [];
        foreach ($serials as $serial) {
            $locId = (int) $serial->current_location_id;
            if ($serial->status === 'in_stock') {
                $inStockByLocation[$locId] ??= [];
                $inStockByLocation[$locId][] = (int) $serial->id;
            } else {
                $damagedSerials[] = $serial;
            }
        }

        try {
            DB::transaction(function () use ($user, $product, $inStockByLocation, $damagedSerials, $manualStockAdjustmentService, $inventoryBalanceService): void {
                foreach ($inStockByLocation as $locationId => $serialIds) {
                    $manualStockAdjustmentService->applyAdjustment(
                        $user,
                        $product,
                        $locationId,
                        null,
                        'Bulk removed from product detail ('.count($serialIds).' serial(s))',
                        $serialIds,
                        -count($serialIds),
                    );
                }
                foreach ($damagedSerials as $serial) {
                    $this->writeOffDamagedSerialStillAtBranch($user, $product, $serial, $inventoryBalanceService);
                }
            });
        } catch (ValidationException $e) {
            return redirect()->to($this->productShowSerialsUrl($product))
                ->withErrors($e->errors());
        } catch (\Throwable $e) {
            return redirect()->to($this->productShowSerialsUrl($product))
                ->withErrors(['serial' => $e->getMessage()]);
        }

        $n = count($ids);

        return redirect()->to($this->productShowSerialsUrl($product))
            ->with('status', $n === 1 ? '1 serial was removed.' : $n.' serials were removed.');
    }

    public function updateSerialSalePrice(Request $request, Product $product, SerialItem $serial): RedirectResponse
    {
        $this->authorize('update', $product);

        if ((int) $serial->product_id !== (int) $product->id) {
            abort(404);
        }
        $this->assertMayManageSerialLocation($request, $serial);

        $data = $request->validate([
            'sale_price' => ['nullable', 'numeric', 'min:0', 'max:99999999.99'],
        ]);

        $price = ($data['sale_price'] ?? null) === null || $data['sale_price'] === '' ? null : round((float) $data['sale_price'], 2);
        $serial->update(['sale_price' => $price]);

        return redirect()->to($this->productShowSerialsUrl($product))
            ->with('status', $price === null
                ? 'Serial '.$serial->serial_number.' now uses the product sale price.'
                : 'Sell price for serial '.$serial->serial_number.' set to '.number_format($price, 2).'.');
    }

    public function bulkUpdateSerialSalePrice(Request $request, Product $product): RedirectResponse
    {
        $this->authorize('update', $product);

        $data = $request->validate([
            'serial_ids' => ['required', 'array', 'min:1'],
            'serial_ids.*' => ['integer'],
            'sale_price' => ['nullable', 'numeric', 'min:0', 'max:99999999.99'],
        ]);

        $price = ($data['sale_price'] ?? null) === null || $data['sale_price'] === '' ? null : round((float) $data['sale_price'], 2);

        $serials = SerialItem::query()
            ->where('product_id', $product->id)
            ->whereIn('id', $data['serial_ids'])
            ->whereIn('status', ['in_stock', 'reserved'])
            ->get();

        $updated = 0;
        foreach ($serials as $serial) {
            $this->assertMayManageSerialLocation($request, $serial);
            $serial->update(['sale_price' => $price]);
            $updated++;
        }

        return redirect()->to($this->productShowSerialsUrl($product))
            ->with('status', $updated === 0
                ? 'No in-stock serials were selected, so no prices changed.'
                : ($price === null
                    ? "{$updated} serial(s) now use the product sale price."
                    : "Sell price set to ".number_format($price, 2)." for {$updated} serial(s)."));
    }

    /** A serial at a branch can only be re-priced by users who may access that branch. */
    private function assertMayManageSerialLocation(Request $request, SerialItem $serial): void
    {
        if ($serial->current_location_id === null) {
            return;
        }

        $user = $request->user();
        $accessible = $user instanceof User ? $user->accessibleLocationIds() : [];
        if ($accessible !== [] && ! in_array((int) $serial->current_location_id, $accessible, true)) {
            abort(403);
        }
    }

    private function productShowSerialsUrl(Product $product): string
    {
        return route('products.show', $product).'?tab=serials';
    }

    private function writeOffDamagedSerialStillAtBranch(
        User $user,
        Product $product,
        SerialItem $serial,
        InventoryBalanceService $inventoryBalanceService,
    ): void {
        $locationId = (int) $serial->current_location_id;

        $before = (int) StockLedger::query()
            ->where('product_id', $product->id)
            ->where('location_id', $locationId)
            ->sum('quantity_delta');

        $after = max(0, $before - 1);
        $qtyDelta = $after - $before;

        $adjustment = ManualStockAdjustment::create([
            'product_id' => $product->id,
            'location_id' => $locationId,
            'qty_before' => $before,
            'qty_after' => $after,
            'qty_delta' => $qtyDelta,
            'reason' => $qtyDelta === 0
                ? 'Cleared damaged serial from branch (product detail, on-hand already zero): '.$serial->serial_number
                : 'Cleared damaged serial from branch (product detail): '.$serial->serial_number,
            'actor_user_id' => $user->id,
        ]);

        if ($qtyDelta !== 0) {
            StockLedger::create([
                'event_time' => now(),
                'product_id' => $product->id,
                'serial_item_id' => $serial->id,
                'location_id' => $locationId,
                'movement_type' => 'damage_out',
                'quantity_delta' => $qtyDelta,
                'unit_cost' => $serial->purchase_cost,
                'reference_type' => ManualStockAdjustment::class,
                'reference_id' => $adjustment->id,
                'actor_user_id' => $user->id,
            ]);
        }

        $serial->update(['current_location_id' => null]);
        $inventoryBalanceService->recalculate($product->id, $locationId);
    }

    public function update(Request $request, Product $product): RedirectResponse
    {
        $modelNumber = trim((string) $request->input('model_number', ''));
        $request->merge([
            'barcode' => trim((string) $request->input('barcode', '')),
            'model_number' => $modelNumber === '' ? null : $modelNumber,
        ]);

        $data = $request->validate(array_merge([
            'sku' => ['required', 'string', 'max:60', 'unique:products,sku,'.$product->id],
            'barcode' => ['nullable', 'string', 'max:100', 'unique:products,barcode,'.$product->id],
            'brand_id' => ['required', 'exists:brands,id'],
            'category_id' => ['required', 'exists:categories,id'],
            'name' => ['required', 'string', 'max:200'],
            'model_number' => ['nullable', 'string', 'max:100', Rule::unique('products', 'model_number')->ignore($product->id)],
            'description' => ['nullable', 'string'],
            'warranty_months' => ['required', 'integer', 'min:0', 'max:120'],
            'default_purchase_price' => ['required', 'numeric', 'min:0'],
            'default_sale_price' => ['required', 'numeric', 'min:0'],
            'maximum_deductible' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'reorder_level' => ['required', 'integer', 'min:0'],
            'unit_of_measure' => ['required', 'string', 'max:20'],
            'is_active' => ['nullable', 'boolean'],
        ], $this->photoUploadRules()));

        $data['is_serialized'] = true;
        $data['is_active'] = $request->boolean('is_active', true);

        $md = $request->input('maximum_deductible');
        $data['maximum_deductible'] = ($md === null || $md === '') ? null : round((float) $md, 2);

        if (($data['barcode'] ?? '') === '') {
            $data['barcode'] = ProductBarcode::generateUnique();
        }

        unset($data['photo'], $data['remove_photo']);

        $data = array_merge(
            $data,
            $this->resolvedPhotoPathState($request, 'photos/products/'.$product->id, $product->photo_path),
        );

        $product->update($data);

        return redirect()->route('products.index')->with('status', 'Product updated successfully.');
    }

    public function destroy(Product $product): RedirectResponse
    {
        $product->delete();

        return redirect()->route('products.index')->with('status', 'Product deleted.');
    }

    public function exportBulkUpdate(Request $request, ProductBulkUpdateService $bulkUpdateService): BinaryFileResponse
    {
        $this->authorize('bulkUpdate', Product::class);

        $filters = $bulkUpdateService->filtersFromRequest($request);
        $allProducts = $request->boolean('all');
        $products = $bulkUpdateService->productsForExport($filters, $allProducts);

        $filename = 'products-bulk-update-'.now()->format('Y-m-d-His').'.xlsx';

        return Excel::download(
            new ProductBulkUpdateExport($products),
            $filename,
        );
    }

    public function importBulkUpdate(Request $request, ProductBulkUpdateService $bulkUpdateService): RedirectResponse
    {
        $this->authorize('bulkUpdate', Product::class);

        $request->validate([
            'file' => ['required', 'file', 'mimes:xlsx,xls,csv', 'max:10240'],
        ]);

        $import = new ProductBulkUpdateImport($bulkUpdateService);
        Excel::import($import, $request->file('file'));

        $result = $import->result;
        $redirectFilters = $this->productIndexRedirectFilters($request, $bulkUpdateService);

        $message = "Import complete: {$result['updated']} product(s) updated.";
        if ($result['skipped'] > 0) {
            $message .= " {$result['skipped']} row(s) skipped (no price change).";
        }

        return redirect()
            ->route('products.index', $redirectFilters)
            ->with('status', $message)
            ->with('import_errors', $result['errors']);
    }

    public function applyBulkUpdate(Request $request, ProductBulkUpdateService $bulkUpdateService): RedirectResponse
    {
        $this->authorize('bulkUpdate', Product::class);

        $data = $request->validate([
            'product_ids' => ['required', 'array', 'min:1'],
            'product_ids.*' => ['integer', 'exists:products,id'],
            'default_purchase_price' => ['nullable', 'numeric', 'min:0'],
            'default_sale_price' => ['nullable', 'numeric', 'min:0'],
        ]);

        $purchase = array_key_exists('default_purchase_price', $data) && $data['default_purchase_price'] !== null
            ? (float) $data['default_purchase_price']
            : null;
        $sale = array_key_exists('default_sale_price', $data) && $data['default_sale_price'] !== null
            ? (float) $data['default_sale_price']
            : null;

        if ($purchase === null && $sale === null) {
            throw ValidationException::withMessages([
                'default_purchase_price' => 'Enter at least one price to apply.',
            ]);
        }

        $result = $bulkUpdateService->applyPricesToProducts(
            array_map('intval', $data['product_ids']),
            $purchase,
            $sale,
        );

        return redirect()
            ->route('products.index', $this->productIndexRedirectFilters($request, $bulkUpdateService))
            ->with('status', "Updated prices on {$result['updated']} selected product(s).");
    }

    /**
     * @return array<string, mixed>
     */
    private function productIndexRedirectFilters(Request $request, ProductBulkUpdateService $bulkUpdateService): array
    {
        $filters = array_merge(
            $bulkUpdateService->filtersFromRequest($request),
            $request->only(['sort', 'direction', 'per_page']),
        );

        return array_filter(
            $filters,
            fn ($value) => $value !== null && $value !== '',
        );
    }

    public function toggleActive(Request $request, Product $product): RedirectResponse|JsonResponse
    {
        $this->authorize('update', $product);

        $product->update(['is_active' => ! $product->is_active]);

        $list = $product->is_active ? 'active' : 'inactive';
        $message = $product->is_active
            ? 'Product activated.'
            : 'Product deactivated. It will appear under Deactivated products.';

        if ($request->wantsJson()) {
            return response()->json([
                'is_active' => $product->is_active,
                'list' => $list,
                'message' => $message,
            ]);
        }

        return redirect()
            ->route('products.index', array_merge(
                $request->only(['q', 'brand_id', 'category_id', 'location_id', 'stock', 'sort', 'direction', 'per_page']),
                ['list' => $list],
            ))
            ->with('status', $message);
    }

    /**
     * @return array{0: array<string, string>, 1: int|null, 2: EloquentBuilder<Product>, 3: list<int>}
     */
    private function productListQuery(Request $request): array
    {
        $list = (string) $request->input('list', 'active');
        if (! in_array($list, ['active', 'inactive'], true)) {
            $list = 'active';
        }

        $accessibleLocationIds = $this->accessibleLocationIds();
        $locationId = $request->integer('location_id') ?: null;
        if ($locationId !== null && ! in_array($locationId, $accessibleLocationIds, true)) {
            abort(403);
        }

        $filters = [
            'q' => trim((string) $request->input('q', '')),
            'brand_id' => (string) $request->input('brand_id', ''),
            'category_id' => (string) $request->input('category_id', ''),
            'location_id' => $locationId !== null ? (string) $locationId : '',
            'list' => $list,
            'stock' => (string) $request->input('stock', ''),
            'sort' => (string) $request->input('sort', 'created_at'),
            'direction' => strtolower((string) $request->input('direction', 'desc')) === 'asc' ? 'asc' : 'desc',
        ];

        $sortable = ['created_at', 'updated_at', 'name', 'sku', 'sale_price', 'brand', 'category', 'inventory'];
        if (! in_array($filters['sort'], $sortable, true)) {
            $filters['sort'] = 'created_at';
        }

        $products = Product::query()
            ->with(['brand', 'category'])
            ->when($locationId !== null, function ($query) use ($locationId): void {
                $balances = LedgerBackedInventory::balancesSubquery();
                $query->whereExists(function ($sub) use ($balances, $locationId): void {
                    $sub->fromSub($balances, 'ib')
                        ->selectRaw('1')
                        ->whereColumn('ib.product_id', 'products.id')
                        ->where('ib.location_id', $locationId);
                });
            })
            ->when($filters['q'] !== '', function ($query) use ($filters): void {
                $keyword = $filters['q'];
                $query->where(function ($subQuery) use ($keyword): void {
                    $subQuery->where('sku', 'like', "%{$keyword}%")
                        ->orWhere('name', 'like', "%{$keyword}%")
                        ->orWhere('model_number', 'like', "%{$keyword}%")
                        ->orWhere('barcode', 'like', "%{$keyword}%");
                });
            })
            ->when($filters['brand_id'] !== '', fn ($query) => $query->where('brand_id', (int) $filters['brand_id']))
            ->when($filters['category_id'] !== '', fn ($query) => $query->where('category_id', (int) $filters['category_id']))
            ->where('is_active', $list === 'inactive' ? false : true)
            ->when($filters['stock'] === 'in_stock', function ($query) use ($locationId): void {
                $summary = $this->ledgerInventorySummaryByProductQuery($locationId);
                $query->whereExists(function ($sub) use ($summary): void {
                    $sub->fromSub($summary, 'agg')
                        ->selectRaw('1')
                        ->whereColumn('agg.product_id', 'products.id')
                        ->where('agg.available_qty', '>', 0);
                });
            })
            ->when($filters['stock'] === 'out_of_stock', function ($query) use ($locationId): void {
                $summary = $this->ledgerInventorySummaryByProductQuery($locationId);
                $query->whereNotExists(function ($sub) use ($summary): void {
                    $sub->fromSub($summary, 'agg')
                        ->selectRaw('1')
                        ->whereColumn('agg.product_id', 'products.id')
                        ->where('agg.available_qty', '>', 0);
                });
            });

        $dir = $filters['direction'];
        match ($filters['sort']) {
            'sku' => $products->orderBy('products.sku', $dir),
            'name' => $products->orderBy('products.name', $dir),
            'sale_price' => $products->orderBy('products.default_sale_price', $dir),
            'created_at' => $products->orderBy('products.created_at', $dir),
            'updated_at' => $products->orderBy('products.updated_at', $dir),
            'brand' => $products
                ->leftJoin('brands', 'brands.id', '=', 'products.brand_id')
                ->orderBy('brands.name', $dir)
                ->select('products.*'),
            'category' => $products
                ->leftJoin('categories', 'categories.id', '=', 'products.category_id')
                ->orderBy('categories.name', $dir)
                ->select('products.*'),
            'inventory' => $products->orderBy(
                DB::query()
                    ->fromSub($this->ledgerInventorySummaryByProductQuery($locationId), 'pa')
                    ->select('available_qty')
                    ->whereColumn('pa.product_id', 'products.id')
                    ->limit(1),
                $dir
            ),
            default => $products->orderBy('products.created_at', 'desc'),
        };

        return [$filters, $locationId, $products, $accessibleLocationIds];
    }

    /**
     * @param  Collection<int, Product>  $products
     */
    private function attachInventorySummaries(Collection $products, ?int $locationId): void
    {
        if ($products->isEmpty()) {
            return;
        }

        $inventoryByProduct = $this->ledgerInventorySummaryByProductQuery($locationId)
            ->whereIn('product_id', $products->pluck('id'))
            ->get()
            ->keyBy('product_id');

        $products->each(function (Product $product) use ($inventoryByProduct): void {
            $inventory = $inventoryByProduct->get($product->id);

            $product->inventory_summary = [
                'on_hand_qty' => (int) ($inventory->on_hand_qty ?? 0),
                'reserved_qty' => (int) ($inventory->reserved_qty ?? 0),
                'available_qty' => (int) ($inventory->available_qty ?? 0),
            ];
        });
    }

    /**
     * One row per product: sums across all locations from stock_ledger (+ reserved serial counts),
     * same definition as inventory_balances when recalculated; works even when balance rows are missing.
     * When $locationId is set, quantities are scoped to that branch only.
     */
    private function ledgerInventorySummaryByProductQuery(?int $locationId = null): Builder
    {
        $query = DB::query()->fromSub(LedgerBackedInventory::balancesSubquery(), 'ib');

        if ($locationId !== null) {
            return $query
                ->where('ib.location_id', $locationId)
                ->selectRaw('ib.product_id, ib.on_hand_qty, ib.reserved_qty, ib.available_qty');
        }

        return $query
            ->selectRaw('ib.product_id, SUM(ib.on_hand_qty) as on_hand_qty, SUM(ib.reserved_qty) as reserved_qty, SUM(ib.available_qty) as available_qty')
            ->groupBy('ib.product_id');
    }
}
