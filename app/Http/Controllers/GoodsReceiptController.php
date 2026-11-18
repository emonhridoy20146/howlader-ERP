<?php

namespace App\Http\Controllers;

use App\Exceptions\InvalidStateException;
use App\Http\Controllers\Concerns\AppliesInventoryLocationContext;
use App\Models\AppSetting;
use App\Models\GoodsReceipt;
use App\Models\Location;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\SerialItem;
use App\Models\Supplier;
use App\Services\DocumentNumberGenerator;
use App\Services\GoodsReceiptPostingService;
use App\Support\SerialLabelBarcode;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class GoodsReceiptController extends Controller
{
    use AppliesInventoryLocationContext;

    public function __construct(
        private readonly DocumentNumberGenerator $documentNumbers,
    ) {}

    public function index(Request $request): View
    {
        $sort = (string) $request->input('sort', 'received_at');
        $dir = strtolower((string) $request->input('dir', 'desc')) === 'asc' ? 'asc' : 'desc';
        $sortColumn = in_array($sort, ['received_at', 'posted_at', 'grn_number', 'status', 'created_at'], true)
            ? $sort
            : 'received_at';

        $poLink = (string) $request->input('po_link', '');
        $poLink = in_array($poLink, ['with', 'without'], true) ? $poLink : '';

        $filters = [
            'q' => trim((string) $request->input('q', '')),
            'status' => (string) $request->input('status', ''),
            'supplier_id' => (string) $request->input('supplier_id', ''),
            'location_id' => (string) $request->input('location_id', ''),
            'date_from' => (string) $request->input('date_from', ''),
            'date_to' => (string) $request->input('date_to', ''),
            'posted_from' => (string) $request->input('posted_from', ''),
            'posted_to' => (string) $request->input('posted_to', ''),
            'purchase_order_id' => (string) $request->input('purchase_order_id', ''),
            'po_link' => $poLink,
            'sort' => $sortColumn,
            'dir' => $dir,
        ];

        $baseQuery = GoodsReceipt::query()
            ->with(['supplier', 'location', 'purchaseOrder'])
            ->withCount(['serialItems as serials_count'])
            ->when($filters['q'] !== '', function ($query) use ($filters): void {
                $keyword = $filters['q'];
                $query->where(function ($subQuery) use ($keyword): void {
                    $subQuery->where('grn_number', 'like', "%{$keyword}%")
                        ->orWhere('delivery_note_ref', 'like', "%{$keyword}%")
                        ->orWhereHas('supplier', function ($supplierQuery) use ($keyword): void {
                            $supplierQuery->where('name', 'like', "%{$keyword}%");
                        })
                        ->orWhereHas('purchaseOrder', function ($poQuery) use ($keyword): void {
                            $poQuery->where('po_number', 'like', "%{$keyword}%");
                        })
                        ->orWhereHas('lines.product', function ($productQuery) use ($keyword): void {
                            $productQuery->where('name', 'like', "%{$keyword}%")
                                ->orWhere('sku', 'like', "%{$keyword}%")
                                ->orWhere('model_number', 'like', "%{$keyword}%")
                                ->orWhere('barcode', 'like', "%{$keyword}%");
                        })
                        ->orWhereHas('lines.serials', function ($serialQuery) use ($keyword): void {
                            $serialQuery->where('serial_number', 'like', "%{$keyword}%")
                                ->orWhere('manufacturer_serial', 'like', "%{$keyword}%");
                        });
                });
            })
            ->when($filters['status'] !== '', fn ($query) => $query->where('status', $filters['status']))
            ->when($filters['supplier_id'] !== '', fn ($query) => $query->where('supplier_id', (int) $filters['supplier_id']))
            ->when($filters['location_id'] !== '', fn ($query) => $query->where('location_id', (int) $filters['location_id']))
            ->when($filters['location_id'] === '' && $this->contextLocationId() !== null, fn ($query) => $query->where('location_id', $this->contextLocationId()))
            ->when($filters['date_from'] !== '', fn ($query) => $query->whereDate('received_at', '>=', $filters['date_from']))
            ->when($filters['date_to'] !== '', fn ($query) => $query->whereDate('received_at', '<=', $filters['date_to']))
            ->when($filters['posted_from'] !== '', fn ($query) => $query->whereDate('posted_at', '>=', $filters['posted_from']))
            ->when($filters['posted_to'] !== '', fn ($query) => $query->whereDate('posted_at', '<=', $filters['posted_to']))
            ->when($filters['purchase_order_id'] !== '', fn ($query) => $query->where('purchase_order_id', (int) $filters['purchase_order_id']))
            ->when($filters['purchase_order_id'] === '' && $filters['po_link'] === 'with', fn ($query) => $query->whereNotNull('purchase_order_id'))
            ->when($filters['purchase_order_id'] === '' && $filters['po_link'] === 'without', fn ($query) => $query->whereNull('purchase_order_id'));

        $goodsReceipts = (clone $baseQuery)
            ->orderBy($sortColumn, $dir)
            ->orderBy('id', $dir)
            ->paginate($this->resolvePerPage($request, 15))
            ->withQueryString();

        $kpiLocationScope = GoodsReceipt::query()
            ->when($filters['location_id'] !== '', fn ($query) => $query->where('location_id', (int) $filters['location_id']))
            ->when($filters['location_id'] === '' && $this->contextLocationId() !== null, fn ($query) => $query->where('location_id', $this->contextLocationId()));

        $kpis = [
            'total' => (clone $kpiLocationScope)->count(),
            'draft' => (clone $kpiLocationScope)->where('status', 'draft')->count(),
            'posted' => (clone $kpiLocationScope)->where('status', 'posted')->count(),
            'today' => (clone $kpiLocationScope)->whereDate('received_at', today())->count(),
            'filtered' => $goodsReceipts->total(),
        ];

        $filterSuppliers = Supplier::orderBy('name')->get(['id', 'name']);
        $filterLocations = Location::orderBy('name')->get(['id', 'name', 'code']);
        $filterPurchaseOrders = PurchaseOrder::query()
            ->orderByDesc('ordered_at')
            ->orderByDesc('id')
            ->limit(300)
            ->get(['id', 'po_number']);

        return view('goods-receipts.index', compact('goodsReceipts', 'filters', 'kpis', 'filterSuppliers', 'filterLocations', 'filterPurchaseOrders'));
    }

    public function create(): View
    {
        $purchaseOrders = $this->openPurchaseOrdersQuery()->get();
        $purchaseOrderPendingLines = $this->buildPurchaseOrderPendingLinesLookup($purchaseOrders);
        $productsCatalog = $this->productsCatalog();

        return view('goods-receipts.create', [
            'purchaseOrders' => $purchaseOrders,
            'purchaseOrderPendingLines' => $purchaseOrderPendingLines,
            'productsCatalog' => $productsCatalog,
            'suppliers' => Supplier::where('is_active', true)->orderBy('name')->get(),
            'locations' => Location::where('is_active', true)->orderBy('name')->get(),
        ]);
    }

    public function show(GoodsReceipt $goodsReceipt): View
    {
        $goodsReceipt->load([
            'supplier',
            'location',
            'purchaseOrder',
            'lines.product',
            'lines.serials',
        ]);

        $totalLines = (int) $goodsReceipt->lines->count();
        $totalQtyReceived = (int) $goodsReceipt->lines->sum('qty_received');
        $totalSerials = (int) $goodsReceipt->lines->sum(fn ($line) => $line->serials->count());
        $totalValue = (float) $goodsReceipt->lines->sum(fn ($line) => $line->qty_received * $line->unit_cost);

        return view('goods-receipts.show', compact(
            'goodsReceipt',
            'totalLines',
            'totalQtyReceived',
            'totalSerials',
            'totalValue'
        ));
    }

    public function receipt(GoodsReceipt $goodsReceipt): View
    {
        $goodsReceipt->load([
            'supplier',
            'location',
            'purchaseOrder',
            'lines.product',
            'lines.serials',
        ]);

        $totalQty = (int) $goodsReceipt->lines->sum('qty_received');
        $totalValue = (float) $goodsReceipt->lines->sum(fn ($line) => (int) $line->qty_received * (float) $line->unit_cost);

        return view('goods-receipts.receipt', [
            'goodsReceipt' => $goodsReceipt,
            'totalQty' => $totalQty,
            'totalValue' => $totalValue,
            'printedAt' => now(),
            'companyPad' => $this->companyPadSettings(),
        ]);
    }

    public function printLabels(GoodsReceipt $goodsReceipt): View
    {
        $this->authorize('view', $goodsReceipt);

        $serials = SerialItem::query()
            ->whereIn(
                'goods_receipt_line_id',
                $goodsReceipt->lines()->select('id'),
            )
            ->with('product')
            ->orderBy('product_id')
            ->orderBy('serial_number')
            ->get();

        $labelFormat = SerialLabelBarcode::normalizeFormat(request()->input('format'));
        $codeSvgs = SerialLabelBarcode::codeSvgsForSerials($serials, $labelFormat);
        $labelsFormatUrls = [
            'qr' => route('goods-receipts.labels', [$goodsReceipt, 'format' => SerialLabelBarcode::FORMAT_QR]),
            'barcode' => route('goods-receipts.labels', [$goodsReceipt, 'format' => SerialLabelBarcode::FORMAT_BARCODE]),
        ];

        return view('products.labels-print', [
            'serials' => $serials,
            'codeSvgs' => $codeSvgs,
            'labelFormat' => $labelFormat,
            'labelsFormatUrls' => $labelsFormatUrls,
            'labelToolbarNote' => $serials->count().' label(s) · '.$goodsReceipt->grn_number,
            'labelEmptyMessage' => 'No serial units are recorded on this goods receipt.',
            'labelsBackUrl' => route('goods-receipts.show', $goodsReceipt),
        ]);
    }

    public function edit(GoodsReceipt $goodsReceipt): View|RedirectResponse
    {
        if ($goodsReceipt->status !== 'draft') {
            return redirect()->route('goods-receipts.index')->with('status', 'Only draft goods receipts can be edited.');
        }

        $goodsReceipt->load(['lines.serials']);

        $purchaseOrders = $this->openPurchaseOrdersQuery()->get();
        if ($goodsReceipt->purchase_order_id && ! $purchaseOrders->contains('id', $goodsReceipt->purchase_order_id)) {
            $linkedPurchaseOrder = PurchaseOrder::query()
                ->with(['supplier', 'destinationLocation', 'lines.product'])
                ->withSum('lines as ordered_qty', 'qty_ordered')
                ->withSum('lines as received_qty', 'qty_received')
                ->find($goodsReceipt->purchase_order_id);

            if ($linkedPurchaseOrder) {
                $purchaseOrders->prepend($linkedPurchaseOrder);
            }
        }

        $purchaseOrders = $purchaseOrders->unique('id')->values();
        $purchaseOrderPendingLines = $this->buildPurchaseOrderPendingLinesLookup($purchaseOrders);
        $productsCatalog = $this->productsCatalog();

        return view('goods-receipts.edit', [
            'goodsReceipt' => $goodsReceipt,
            'purchaseOrders' => $purchaseOrders,
            'purchaseOrderPendingLines' => $purchaseOrderPendingLines,
            'productsCatalog' => $productsCatalog,
            'suppliers' => Supplier::where('is_active', true)->orderBy('name')->get(),
            'locations' => Location::where('is_active', true)->orderBy('name')->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $normalizedLines = collect($request->input('lines', []))
            ->map(function (array $line): array {
                $serialsFromInputs = collect($line['serial_numbers'] ?? [])
                    ->map(static fn ($serial): string => trim((string) $serial))
                    ->filter(static fn (string $serial): bool => $serial !== '')
                    ->values();

                $serials = collect(preg_split('/\r\n|\r|\n/', (string) ($line['serial_numbers_text'] ?? '')))
                    ->map(static fn (string $serial): string => trim($serial))
                    ->filter(static fn (string $serial): bool => $serial !== '')
                    ->values();

                $normalizedSerials = $serialsFromInputs->isNotEmpty()
                    ? $serialsFromInputs->all()
                    : $serials->all();

                return [
                    'product_id' => $line['product_id'] ?? null,
                    'qty_received' => $line['qty_received'] ?? null,
                    'unit_cost' => $line['unit_cost'] ?? null,
                    'serial_mode' => in_array((string) ($line['serial_mode'] ?? 'manual'), ['manual', 'auto'], true) ? (string) $line['serial_mode'] : 'manual',
                    'serial_numbers' => $normalizedSerials,
                ];
            })
            ->values()
            ->all();

        $request->merge([
            'lines' => $normalizedLines,
        ]);

        $data = $request->validate([
            'purchase_order_id' => ['nullable', 'exists:purchase_orders,id'],
            'supplier_id' => ['required', 'exists:suppliers,id'],
            'location_id' => ['required', 'exists:locations,id'],
            'received_at' => ['required', 'date'],
            'delivery_note_ref' => ['nullable', 'string', 'max:80'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.product_id' => ['required', 'exists:products,id'],
            'lines.*.qty_received' => ['required', 'integer', 'min:1'],
            'lines.*.unit_cost' => ['nullable', 'numeric', 'min:0'],
            'lines.*.serial_mode' => ['nullable', 'in:manual,auto'],
            'lines.*.serial_numbers' => ['nullable', 'array'],
            'lines.*.serial_numbers.*' => ['nullable', 'string', 'max:100', 'distinct'],
        ]);

        if (! empty($data['purchase_order_id'])) {
            $purchaseOrder = PurchaseOrder::findOrFail($data['purchase_order_id']);

            if (! $this->purchaseOrderHasPendingItems($purchaseOrder)) {
                throw ValidationException::withMessages([
                    'purchase_order_id' => 'Selected purchase order is fully received and cannot be used.',
                ]);
            }

            if ((int) $purchaseOrder->supplier_id !== (int) $data['supplier_id']) {
                throw ValidationException::withMessages([
                    'supplier_id' => 'Selected supplier must match the purchase order supplier.',
                ]);
            }

            if ((int) $purchaseOrder->destination_location_id !== (int) $data['location_id']) {
                throw ValidationException::withMessages([
                    'location_id' => 'Receiving location must match the purchase order destination location.',
                ]);
            }

            $allowedProductIds = $purchaseOrder->lines()->pluck('product_id')->unique()->all();
            foreach ($data['lines'] as $index => $line) {
                if (! in_array((int) $line['product_id'], array_map('intval', $allowedProductIds), true)) {
                    throw ValidationException::withMessages([
                        "lines.$index.product_id" => 'Each product must exist on the selected purchase order.',
                    ]);
                }
            }

            $this->validateReceivedQtyAgainstPurchaseOrder($purchaseOrder, $data['lines']);
        }

        $products = Product::whereIn('id', collect($data['lines'])->pluck('product_id'))
            ->where('is_active', true)
            ->get()
            ->keyBy('id');

        $allSerials = collect($data['lines'])
            ->flatMap(function (array $line) use ($products): array {
                $product = $products->get((int) ($line['product_id'] ?? 0));
                if (! $product || ! $product->is_serialized) {
                    return [];
                }
                if (($line['serial_mode'] ?? 'manual') === 'auto') {
                    return [];
                }

                return $line['serial_numbers'] ?? [];
            })
            ->map(static fn (string $serial): string => strtolower(trim($serial)))
            ->filter(static fn (string $serial): bool => $serial !== '');

        if ($allSerials->count() !== $allSerials->unique()->count()) {
            throw ValidationException::withMessages([
                'lines' => 'Duplicate serial numbers found across line rows.',
            ]);
        }

        foreach ($data['lines'] as $index => $line) {
            $product = $products->get((int) $line['product_id']);
            if (! $product) {
                throw ValidationException::withMessages([
                    "lines.$index.product_id" => 'Selected product is not active.',
                ]);
            }

            $serials = collect($line['serial_numbers'] ?? [])
                ->map(static fn ($serial): string => trim((string) $serial))
                ->filter(static fn (string $serial): bool => $serial !== '')
                ->values()
                ->all();

            if ((bool) $product->is_serialized) {
                $mode = (string) ($line['serial_mode'] ?? 'manual');
                if ($mode === 'manual' && count($serials) !== (int) $line['qty_received']) {
                    throw ValidationException::withMessages([
                        "lines.$index.serial_numbers" => 'Manufacturer serial count must match quantity in manual mode.',
                    ]);
                }
            }
        }

        DB::transaction(function () use ($data, $products): void {
            $grn = GoodsReceipt::create([
                'grn_number' => $this->documentNumbers->next(
                    DocumentNumberGenerator::TYPE_GOODS_RECEIPT,
                    'GRN',
                    Carbon::parse((string) $data['received_at']),
                    'Ymd',
                    5,
                ),
                'purchase_order_id' => $data['purchase_order_id'] ?? null,
                'supplier_id' => $data['supplier_id'],
                'location_id' => $data['location_id'],
                'received_at' => $data['received_at'],
                'delivery_note_ref' => $data['delivery_note_ref'] ?? null,
                'created_by' => auth()->id(),
            ]);

            foreach ($data['lines'] as $lineData) {
                $product = $products->get((int) $lineData['product_id']);

                if (! $product) {
                    throw ValidationException::withMessages([
                        'lines' => 'Selected product is not active.',
                    ]);
                }

                $unitCost = (float) ($lineData['unit_cost'] ?? $product->default_purchase_price);

                $line = $grn->lines()->create([
                    'product_id' => $lineData['product_id'],
                    'qty_received' => $lineData['qty_received'],
                    'unit_cost' => $unitCost,
                ]);

                if ((bool) $product->is_serialized) {
                    $manualSerials = collect($lineData['serial_numbers'] ?? [])
                        ->map(static fn ($serial): string => trim((string) $serial))
                        ->filter(static fn (string $serial): bool => $serial !== '')
                        ->values()
                        ->all();
                    $useManual = (string) ($lineData['serial_mode'] ?? 'manual') === 'manual';

                    for ($i = 0; $i < (int) $lineData['qty_received']; $i++) {
                        $manufacturerSerial = $useManual ? ($manualSerials[$i] ?? null) : null;
                        SerialItem::create([
                            'product_id' => $lineData['product_id'],
                            'serial_number' => $this->nextInternalSerialCode(),
                            'manufacturer_serial' => $manufacturerSerial,
                            'serial_source' => $manufacturerSerial ? 'manufacturer' : 'internal',
                            'status' => 'draft',
                            'goods_receipt_line_id' => $line->id,
                        ]);
                    }
                }
            }
        });

        return redirect()->route('goods-receipts.index')->with('status', 'Goods receipt created as draft.');
    }

    public function update(Request $request, GoodsReceipt $goodsReceipt): RedirectResponse
    {
        if ($goodsReceipt->status !== 'draft') {
            return redirect()->route('goods-receipts.index')->with('status', 'Only draft goods receipts can be edited.');
        }

        $normalizedLines = collect($request->input('lines', []))
            ->map(function (array $line): array {
                $serialsFromInputs = collect($line['serial_numbers'] ?? [])
                    ->map(static fn ($serial): string => trim((string) $serial))
                    ->filter(static fn (string $serial): bool => $serial !== '')
                    ->values();

                $serials = collect(preg_split('/\r\n|\r|\n/', (string) ($line['serial_numbers_text'] ?? '')))
                    ->map(static fn (string $serial): string => trim($serial))
                    ->filter(static fn (string $serial): bool => $serial !== '')
                    ->values();

                $normalizedSerials = $serialsFromInputs->isNotEmpty()
                    ? $serialsFromInputs->all()
                    : $serials->all();

                return [
                    'product_id' => $line['product_id'] ?? null,
                    'qty_received' => $line['qty_received'] ?? null,
                    'unit_cost' => $line['unit_cost'] ?? null,
                    'serial_mode' => in_array((string) ($line['serial_mode'] ?? 'manual'), ['manual', 'auto'], true) ? (string) $line['serial_mode'] : 'manual',
                    'serial_numbers' => $normalizedSerials,
                ];
            })
            ->values()
            ->all();

        $request->merge([
            'lines' => $normalizedLines,
        ]);

        $data = $request->validate([
            'purchase_order_id' => ['nullable', 'exists:purchase_orders,id'],
            'supplier_id' => ['required', 'exists:suppliers,id'],
            'location_id' => ['required', 'exists:locations,id'],
            'received_at' => ['required', 'date'],
            'delivery_note_ref' => ['nullable', 'string', 'max:80'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.product_id' => ['required', 'exists:products,id'],
            'lines.*.qty_received' => ['required', 'integer', 'min:1'],
            'lines.*.unit_cost' => ['nullable', 'numeric', 'min:0'],
            'lines.*.serial_mode' => ['nullable', 'in:manual,auto'],
            'lines.*.serial_numbers' => ['nullable', 'array'],
            'lines.*.serial_numbers.*' => ['nullable', 'string', 'max:100', 'distinct'],
        ]);

        if (! empty($data['purchase_order_id'])) {
            $purchaseOrder = PurchaseOrder::findOrFail($data['purchase_order_id']);

            if (! $this->purchaseOrderHasPendingItems($purchaseOrder)) {
                throw ValidationException::withMessages([
                    'purchase_order_id' => 'Selected purchase order is fully received and cannot be used.',
                ]);
            }

            if ((int) $purchaseOrder->supplier_id !== (int) $data['supplier_id']) {
                throw ValidationException::withMessages([
                    'supplier_id' => 'Selected supplier must match the purchase order supplier.',
                ]);
            }

            if ((int) $purchaseOrder->destination_location_id !== (int) $data['location_id']) {
                throw ValidationException::withMessages([
                    'location_id' => 'Receiving location must match the purchase order destination location.',
                ]);
            }

            $allowedProductIds = $purchaseOrder->lines()->pluck('product_id')->unique()->all();
            foreach ($data['lines'] as $index => $line) {
                if (! in_array((int) $line['product_id'], array_map('intval', $allowedProductIds), true)) {
                    throw ValidationException::withMessages([
                        "lines.$index.product_id" => 'Each product must exist on the selected purchase order.',
                    ]);
                }
            }

            $this->validateReceivedQtyAgainstPurchaseOrder($purchaseOrder, $data['lines']);
        }

        $products = Product::whereIn('id', collect($data['lines'])->pluck('product_id'))
            ->where('is_active', true)
            ->get()
            ->keyBy('id');

        $allSerials = collect($data['lines'])
            ->flatMap(function (array $line) use ($products): array {
                $product = $products->get((int) ($line['product_id'] ?? 0));
                if (! $product || ! $product->is_serialized) {
                    return [];
                }
                if (($line['serial_mode'] ?? 'manual') === 'auto') {
                    return [];
                }

                return $line['serial_numbers'] ?? [];
            })
            ->map(static fn (string $serial): string => strtolower(trim($serial)))
            ->filter(static fn (string $serial): bool => $serial !== '');

        if ($allSerials->count() !== $allSerials->unique()->count()) {
            throw ValidationException::withMessages([
                'lines' => 'Duplicate serial numbers found across line rows.',
            ]);
        }

        foreach ($data['lines'] as $index => $line) {
            $product = $products->get((int) $line['product_id']);
            if (! $product) {
                throw ValidationException::withMessages([
                    "lines.$index.product_id" => 'Selected product is not active.',
                ]);
            }

            $serials = collect($line['serial_numbers'] ?? [])
                ->map(static fn ($serial): string => trim((string) $serial))
                ->filter(static fn (string $serial): bool => $serial !== '')
                ->values()
                ->all();

            if ((bool) $product->is_serialized) {
                $mode = (string) ($line['serial_mode'] ?? 'manual');
                if ($mode === 'manual' && count($serials) !== (int) $line['qty_received']) {
                    throw ValidationException::withMessages([
                        "lines.$index.serial_numbers" => 'Manufacturer serial count must match quantity in manual mode.',
                    ]);
                }
            }
        }

        DB::transaction(function () use ($data, $goodsReceipt, $products): void {
            $goodsReceipt->update([
                'purchase_order_id' => $data['purchase_order_id'] ?? null,
                'supplier_id' => $data['supplier_id'],
                'location_id' => $data['location_id'],
                'received_at' => $data['received_at'],
                'delivery_note_ref' => $data['delivery_note_ref'] ?? null,
            ]);

            $lineIds = $goodsReceipt->lines()->pluck('id');
            SerialItem::whereIn('goods_receipt_line_id', $lineIds)->delete();
            $goodsReceipt->lines()->delete();

            foreach ($data['lines'] as $lineData) {
                $product = $products->get((int) $lineData['product_id']);

                if (! $product) {
                    throw ValidationException::withMessages([
                        'lines' => 'Selected product is not active.',
                    ]);
                }

                $unitCost = (float) ($lineData['unit_cost'] ?? $product->default_purchase_price);

                $line = $goodsReceipt->lines()->create([
                    'product_id' => $lineData['product_id'],
                    'qty_received' => $lineData['qty_received'],
                    'unit_cost' => $unitCost,
                ]);

                if ((bool) $product->is_serialized) {
                    $manualSerials = collect($lineData['serial_numbers'] ?? [])
                        ->map(static fn ($serial): string => trim((string) $serial))
                        ->filter(static fn (string $serial): bool => $serial !== '')
                        ->values()
                        ->all();
                    $useManual = (string) ($lineData['serial_mode'] ?? 'manual') === 'manual';

                    for ($i = 0; $i < (int) $lineData['qty_received']; $i++) {
                        $manufacturerSerial = $useManual ? ($manualSerials[$i] ?? null) : null;
                        SerialItem::create([
                            'product_id' => $lineData['product_id'],
                            'serial_number' => $this->nextInternalSerialCode(),
                            'manufacturer_serial' => $manufacturerSerial,
                            'serial_source' => $manufacturerSerial ? 'manufacturer' : 'internal',
                            'status' => 'draft',
                            'goods_receipt_line_id' => $line->id,
                        ]);
                    }
                }
            }
        });

        return redirect()->route('goods-receipts.index')->with('status', 'Goods receipt updated.');
    }

    public function destroy(GoodsReceipt $goodsReceipt): RedirectResponse
    {
        $this->authorize('delete', $goodsReceipt);

        try {
            app(GoodsReceiptPostingService::class)->rollbackAndDelete(
                $goodsReceipt,
                auth()->id() ? (int) auth()->id() : null,
            );
        } catch (Throwable $e) {
            return redirect()
                ->route('goods-receipts.index')
                ->with('status', $e->getMessage() !== '' ? $e->getMessage() : 'Unable to delete goods receipt.');
        }

        return redirect()->route('goods-receipts.index')->with('status', 'Goods receipt deleted.');
    }

    public function post(GoodsReceipt $grn, GoodsReceiptPostingService $service): RedirectResponse
    {
        try {
            $service->post($grn, auth()->id());
        } catch (InvalidStateException) {
            return back()->with('grn_post_error_popup', [
                'title' => 'Unable to post this Goods Receipt',
                'message' => 'This Goods Receipt no longer matches the pending quantity of its linked Purchase Order.',
                'suggestion' => 'Please review the lines and, if this draft is no longer needed, delete this Goods Receipt and create a new one from the latest Purchase Order pending quantities.',
                'delete_url' => route('goods-receipts.destroy', $grn),
            ]);
        }

        return back()->with('status', 'Goods receipt posted successfully.');
    }

    private function openPurchaseOrdersQuery(): Builder
    {
        return PurchaseOrder::query()
            ->whereIn('status', ['approved', 'partially_received'])
            ->whereHas('lines', function (Builder $query): void {
                $query->whereColumn('qty_received', '<', 'qty_ordered');
            })
            ->with(['supplier', 'destinationLocation', 'lines.product'])
            ->withSum('lines as ordered_qty', 'qty_ordered')
            ->withSum('lines as received_qty', 'qty_received')
            ->orderByDesc('id');
    }

    private function purchaseOrderHasPendingItems(PurchaseOrder $purchaseOrder): bool
    {
        return $purchaseOrder->lines()
            ->whereColumn('qty_received', '<', 'qty_ordered')
            ->exists();
    }

    /**
     * @param  list<array{product_id: mixed, qty_received: mixed}>  $lines
     */
    private function validateReceivedQtyAgainstPurchaseOrder(PurchaseOrder $purchaseOrder, array $lines): void
    {
        $purchaseOrder->loadMissing('lines.product');

        $pendingByProduct = $purchaseOrder->lines
            ->groupBy('product_id')
            ->map(fn (Collection $rows): int => (int) $rows->sum(
                fn ($line): int => max(0, (int) $line->qty_ordered - (int) $line->qty_received)
            ));

        $incomingByProduct = collect($lines)
            ->groupBy('product_id')
            ->map(fn (Collection $rows): int => (int) $rows->sum('qty_received'));

        foreach ($incomingByProduct as $productId => $incomingQty) {
            $pendingQty = (int) ($pendingByProduct->get((int) $productId) ?? 0);
            if ($incomingQty > $pendingQty) {
                $product = $purchaseOrder->lines->firstWhere('product_id', (int) $productId)?->product;
                $label = $product
                    ? trim((string) $product->sku.' - '.(string) $product->name, ' -')
                    : 'Product #'.$productId;

                throw ValidationException::withMessages([
                    'lines' => "Received quantity for {$label} exceeds pending PO quantity ({$pendingQty} available).",
                ]);
            }
        }
    }

    private function buildPurchaseOrderPendingLinesLookup(Collection $purchaseOrders): array
    {
        return $purchaseOrders
            ->mapWithKeys(function (PurchaseOrder $purchaseOrder) {
                $lines = $purchaseOrder->lines
                    ->filter(function ($line): bool {
                        return (int) $line->qty_received < (int) $line->qty_ordered;
                    })
                    ->map(function ($line) {
                        $pendingQty = max((int) $line->qty_ordered - (int) $line->qty_received, 0);
                        $sku = (string) ($line->product?->sku ?? '');
                        $name = (string) ($line->product?->name ?? '');

                        return [
                            'product_id' => $line->product_id,
                            'pending_qty' => $pendingQty,
                            'unit_cost' => (float) $line->unit_cost,
                            'product_label' => ($sku !== '' || $name !== '') ? $sku.' - '.$name : '',
                            'search' => Str::lower(trim($sku.' '.$name)),
                            'is_serialized' => (bool) ($line->product?->is_serialized),
                        ];
                    })
                    ->filter(fn (array $line): bool => $line['pending_qty'] > 0)
                    ->values()
                    ->all();

                return [$purchaseOrder->id => $lines];
            })
            ->all();
    }

    /**
     * @return list<array{id: int, label: string, search: string, default_cost: float}>
     */
    private function productsCatalog(): array
    {
        return Product::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'sku', 'name', 'default_purchase_price', 'is_serialized'])
            ->map(static function (Product $product): array {
                $sku = (string) $product->sku;
                $name = (string) $product->name;

                return [
                    'id' => (int) $product->id,
                    'label' => $sku.' - '.$name,
                    'search' => Str::lower($sku.' '.$name),
                    'default_cost' => (float) ($product->default_purchase_price ?? 0),
                    'is_serialized' => (bool) $product->is_serialized,
                ];
            })
            ->values()
            ->all();
    }

    private function nextInternalSerialCode(): string
    {
        for ($attempt = 0; $attempt < 20; $attempt++) {
            $candidate = $this->documentNumbers->next(
                DocumentNumberGenerator::TYPE_SERIAL_BACKFILL,
                'AST',
                now(),
                'Ymd',
                6,
            );
            if (! SerialItem::query()->where('serial_number', $candidate)->exists()) {
                return $candidate;
            }
        }

        throw new \RuntimeException('Unable to generate unique internal serial code. Please retry.');
    }

    /**
     * @return array<string, string>
     */
    private function companyPadSettings(): array
    {
        $keys = [
            'company_pad_name',
            'company_pad_logo_path',
            'company_pad_tagline',
            'company_pad_address',
            'company_pad_phone',
            'company_pad_email',
            'company_pad_website',
            'company_pad_header_note',
            'company_pad_footer_note',
            'company_pad_footer_left_label',
            'company_pad_footer_right_label',
        ];

        $stored = AppSetting::getMany($keys);

        $defaults = [
            'company_pad_name' => (string) config('app.name'),
            'company_pad_logo_path' => '',
            'company_pad_tagline' => 'Sales & Inventory Management',
            'company_pad_address' => '',
            'company_pad_phone' => '',
            'company_pad_email' => '',
            'company_pad_website' => '',
            'company_pad_header_note' => '',
            'company_pad_footer_note' => 'Thank you for your business.',
            'company_pad_footer_left_label' => 'Supplier Signature',
            'company_pad_footer_right_label' => 'Authorized Signature',
        ];

        foreach ($defaults as $key => $value) {
            $stored[$key] = ($stored[$key] !== null && $stored[$key] !== '') ? (string) $stored[$key] : $value;
        }

        /** @var array<string, string> $stored */
        return $stored;
    }
}
