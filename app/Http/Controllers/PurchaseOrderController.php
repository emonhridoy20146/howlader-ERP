<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\AppliesInventoryLocationContext;
use App\Models\AppSetting;
use App\Models\Brand;
use App\Models\Category;
use App\Models\GoodsReceipt;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\Location;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderCostPosting;
use App\Models\PurchaseOrderLine;
use App\Models\PurchaseOrderPayment;
use App\Models\Supplier;
use App\Models\Wallet;
use App\Services\DocumentNumberGenerator;
use App\Services\GoodsReceiptPostingService;
use App\Services\JournalService;
use App\Services\PurchaseOrderChangeLogService;
use App\Support\OwnerCapitalWallet;
use App\Exceptions\InvalidStateException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class PurchaseOrderController extends Controller
{
    use AppliesInventoryLocationContext;

    public function __construct(
        private readonly JournalService $journalService,
        private readonly PurchaseOrderChangeLogService $purchaseOrderChangeLogService,
        private readonly GoodsReceiptPostingService $goodsReceiptPostingService,
        private readonly DocumentNumberGenerator $documentNumbers,
    ) {}

    public function index(Request $request): View
    {
        $sort = (string) $request->input('sort', 'ordered_at');
        $dir = strtolower((string) $request->input('dir', 'desc')) === 'asc' ? 'asc' : 'desc';
        $sortColumn = in_array($sort, ['ordered_at', 'expected_at', 'total_amount', 'po_number', 'status', 'created_at'], true)
            ? $sort
            : 'ordered_at';

        $filters = [
            'q' => trim((string) $request->input('q', '')),
            'status' => (string) $request->input('status', ''),
            'supplier_id' => (string) $request->input('supplier_id', ''),
            'location_id' => (string) $request->input('location_id', ''),
            'date_from' => (string) $request->input('date_from', ''),
            'date_to' => (string) $request->input('date_to', ''),
            'expected_from' => (string) $request->input('expected_from', ''),
            'expected_to' => (string) $request->input('expected_to', ''),
            'total_min' => trim((string) $request->input('total_min', '')),
            'total_max' => trim((string) $request->input('total_max', '')),
            'sort' => $sortColumn,
            'dir' => $dir,
        ];

        $baseQuery = PurchaseOrder::query()
            ->with(['supplier', 'destinationLocation'])
            ->withSettledPaymentsSum()
            ->when($filters['q'] !== '', function ($query) use ($filters): void {
                $keyword = $filters['q'];
                $query->where(function ($subQuery) use ($keyword): void {
                    $subQuery->where('po_number', 'like', "%{$keyword}%")
                        ->orWhere('notes', 'like', "%{$keyword}%")
                        ->orWhereHas('supplier', function ($supplierQuery) use ($keyword): void {
                            $supplierQuery->where('name', 'like', "%{$keyword}%");
                        })
                        ->orWhereHas('lines.product', function ($productQuery) use ($keyword): void {
                            $productQuery->where('name', 'like', "%{$keyword}%")
                                ->orWhere('sku', 'like', "%{$keyword}%")
                                ->orWhere('model_number', 'like', "%{$keyword}%")
                                ->orWhere('barcode', 'like', "%{$keyword}%");
                        });
                });
            })
            ->when($filters['status'] !== '', fn ($query) => $query->where('status', $filters['status']))
            ->when($filters['supplier_id'] !== '', fn ($query) => $query->where('supplier_id', (int) $filters['supplier_id']))
            ->when($filters['location_id'] !== '', fn ($query) => $query->where('destination_location_id', (int) $filters['location_id']))
            ->when($filters['location_id'] === '' && $this->contextLocationId() !== null, fn ($query) => $query->where('destination_location_id', $this->contextLocationId()))
            ->when($filters['date_from'] !== '', fn ($query) => $query->whereDate('ordered_at', '>=', $filters['date_from']))
            ->when($filters['date_to'] !== '', fn ($query) => $query->whereDate('ordered_at', '<=', $filters['date_to']))
            ->when($filters['expected_from'] !== '', fn ($query) => $query->whereDate('expected_at', '>=', $filters['expected_from']))
            ->when($filters['expected_to'] !== '', fn ($query) => $query->whereDate('expected_at', '<=', $filters['expected_to']))
            ->when($filters['total_min'] !== '' && is_numeric($filters['total_min']), fn ($query) => $query->where('total_amount', '>=', (float) $filters['total_min']))
            ->when($filters['total_max'] !== '' && is_numeric($filters['total_max']), fn ($query) => $query->where('total_amount', '<=', (float) $filters['total_max']));

        $purchaseOrders = (clone $baseQuery)
            ->orderBy($sortColumn, $dir)
            ->orderBy('id', $dir)
            ->paginate($this->resolvePerPage($request, 15))
            ->withQueryString();

        $kpiLocationScope = PurchaseOrder::query()
            ->when($filters['location_id'] !== '', fn ($query) => $query->where('destination_location_id', (int) $filters['location_id']))
            ->when($filters['location_id'] === '' && $this->contextLocationId() !== null, fn ($query) => $query->where('destination_location_id', $this->contextLocationId()));

        $kpis = [
            'total' => (clone $kpiLocationScope)->count(),
            'draft' => (clone $kpiLocationScope)->where('status', 'draft')->count(),
            'open' => (clone $kpiLocationScope)->whereIn('status', ['draft', 'approved', 'partially_received'])->count(),
            'filtered' => $purchaseOrders->total(),
            'filtered_amount' => (float) (clone $baseQuery)->sum('total_amount'),
        ];

        $filterSuppliers = Supplier::orderBy('name')->get(['id', 'name']);
        $filterLocations = Location::warehouses()->orderBy('name')->get(['id', 'name', 'code']);

        return view('purchase-orders.index', compact('purchaseOrders', 'filters', 'kpis', 'filterSuppliers', 'filterLocations'));
    }

    public function create(): View
    {
        $products = Product::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'sku', 'name', 'default_purchase_price', 'default_sale_price']);

        return view('purchase-orders.create', [
            'suppliers' => Supplier::orderBy('name')->get(),
            'locations' => Location::where('is_active', true)->warehouses()->orderBy('name')->get(),
            'products' => $products,
            'productsCatalog' => $products->map(fn (Product $product) => [
                'id' => $product->id,
                'sku' => $product->sku,
                'name' => $product->name,
                'label' => $product->sku.' - '.$product->name,
                'search' => strtolower($product->sku.' '.$product->name),
                'default_purchase_price' => (float) $product->default_purchase_price,
            ])->values()->all(),
            'brands' => Brand::query()->orderBy('name')->get(['id', 'name']),
            'categories' => Category::query()->orderBy('name')->get(['id', 'name']),
            'costWallets' => $this->costWalletsGlobalQuery()->get(['id', 'code', 'name']),
        ]);
    }

    public function show(PurchaseOrder $purchaseOrder): View
    {
        $purchaseOrder->load([
            'supplier',
            'destinationLocation',
            'lines.product',
            'payments.creator',
            'payments.wallet',
            'changeLogs' => fn ($q) => $q->with('user')->limit(200),
        ]);

        $relatedGoodsReceipts = GoodsReceipt::query()
            ->where('purchase_order_id', $purchaseOrder->id)
            ->with(['location', 'supplier'])
            ->withCount('lines')
            ->orderByDesc('received_at')
            ->orderByDesc('id')
            ->get();

        $relatedInvoices = GoodsReceipt::query()
            ->where('purchase_order_id', $purchaseOrder->id)
            ->whereNotNull('delivery_note_ref')
            ->where('delivery_note_ref', '!=', '')
            ->with(['location'])
            ->orderByDesc('received_at')
            ->orderByDesc('id')
            ->get(['id', 'grn_number', 'delivery_note_ref', 'received_at', 'location_id']);

        $orderedQty = (int) $purchaseOrder->lines->sum('qty_ordered');
        $receivedQty = (int) $purchaseOrder->lines->sum('qty_received');
        $pendingQty = max($orderedQty - $receivedQty, 0);
        $orderedAmount = (float) $purchaseOrder->lines->sum('line_total');
        $receivedAmount = (float) $purchaseOrder->lines->sum(fn ($line) => ((float) $line->qty_received) * ((float) $line->unit_cost));
        $pendingAmount = max($orderedAmount - $receivedAmount, 0);

        $amountPaid = $purchaseOrder->amountPaid();
        $amountDue = $purchaseOrder->amountDue();
        $landedLineAllocations = $purchaseOrder->landedLineAllocations();

        $paymentWallets = Wallet::query()
            ->where('is_active', true)
            ->whereAppliesToLocation((int) $purchaseOrder->destination_location_id)
            ->whereHas('ledgerAccount', fn ($q) => $q->where('is_active', true))
            ->orderBy('code')
            ->get(['id', 'code', 'name']);

        $ownerWallet = OwnerCapitalWallet::activeForLocation((int) $purchaseOrder->destination_location_id);
        $supplierPaymentWallets = $ownerWallet
            ? collect([$ownerWallet])->values()
            : collect();
        $ownerCapitalWalletCode = OwnerCapitalWallet::code();

        return view('purchase-orders.show', compact(
            'purchaseOrder',
            'relatedGoodsReceipts',
            'relatedInvoices',
            'orderedQty',
            'receivedQty',
            'pendingQty',
            'orderedAmount',
            'receivedAmount',
            'pendingAmount',
            'amountPaid',
            'amountDue',
            'paymentWallets',
            'supplierPaymentWallets',
            'ownerCapitalWalletCode',
            'landedLineAllocations',
        ));
    }

    public function receipt(PurchaseOrder $purchaseOrder): View
    {
        $purchaseOrder->load([
            'supplier',
            'destinationLocation',
            'lines.product',
            'payments.wallet',
            'payments.creator',
        ]);

        $relatedGrns = GoodsReceipt::query()
            ->where('purchase_order_id', $purchaseOrder->id)
            ->orderByDesc('received_at')
            ->orderByDesc('id')
            ->get(['id', 'grn_number', 'received_at', 'status']);

        return view('purchase-orders.receipt', [
            'purchaseOrder' => $purchaseOrder,
            'relatedGrns' => $relatedGrns,
            'amountPaid' => $purchaseOrder->amountPaid(),
            'amountDue' => $purchaseOrder->amountDue(),
            'landedLineAllocations' => $purchaseOrder->landedLineAllocations(),
            'printedAt' => now(),
            'companyPad' => $this->companyPadSettings(),
        ]);
    }

    public function edit(PurchaseOrder $purchaseOrder): View|RedirectResponse
    {
        $this->authorize('update', $purchaseOrder);

        if ($purchaseOrder->status === 'cancelled') {
            return redirect()
                ->route('purchase-orders.show', $purchaseOrder)
                ->with('status', 'Cancelled purchase orders cannot be edited.');
        }

        $purchaseOrder->load('lines.product');

        return view('purchase-orders.edit', [
            'purchaseOrder' => $purchaseOrder,
            'suppliers' => Supplier::orderBy('name')->get(),
            'locations' => Location::where('is_active', true)->warehouses()->orderBy('name')->get(),
            'products' => Product::where('is_active', true)->orderBy('name')->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'supplier_id' => ['required', 'exists:suppliers,id'],
            'destination_location_id' => ['required', Rule::exists('locations', 'id')->where('type', 'warehouse')],
            'ordered_at' => ['required', 'date'],
            'expected_at' => ['nullable', 'date'],
            'notes' => ['nullable', 'string'],
            'discount_amount' => ['nullable', 'numeric', 'min:0'],
            'tax_amount' => ['nullable', 'numeric', 'min:0'],
            'additional_costs' => ['nullable', 'array'],
            'additional_costs.*.label' => ['nullable', 'string', 'max:120'],
            'additional_costs.*.amount' => ['nullable', 'numeric', 'min:0'],
            'additional_costs.*.paid_at' => ['nullable', 'date'],
            'additional_costs.*.wallet_id' => ['nullable', 'integer', 'exists:wallets,id'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.product_id' => ['required', 'exists:products,id'],
            'lines.*.qty_ordered' => ['required', 'integer', 'min:1'],
            'lines.*.unit_cost' => ['nullable', 'numeric', 'min:0'],
        ]);

        $branchId = (int) $data['destination_location_id'];
        $this->validateAdditionalCostWalletRows($data['additional_costs'] ?? [], $branchId);

        $poNumber = $this->documentNumbers->next(
            DocumentNumberGenerator::TYPE_PURCHASE_ORDER,
            'PO',
            Carbon::parse((string) $data['ordered_at']),
            'Ym',
            5,
        );

        DB::transaction(function () use ($data, $poNumber, $branchId): void {
            $purchaseOrder = PurchaseOrder::create([
                'po_number' => $poNumber,
                'supplier_id' => $data['supplier_id'],
                'destination_location_id' => $data['destination_location_id'],
                'ordered_at' => $data['ordered_at'],
                'expected_at' => $data['expected_at'] ?? null,
                'notes' => $data['notes'] ?? null,
                'status' => 'draft',
                'subtotal_amount' => 0,
                'discount_amount' => 0,
                'tax_amount' => 0,
                'additional_cost_amount' => 0,
                'additional_costs' => [],
                'total_amount' => 0,
                'created_by' => auth()->id(),
            ]);

            $products = Product::whereIn('id', collect($data['lines'])->pluck('product_id'))
                ->get()
                ->keyBy('id');

            $subtotal = $this->computeLinesSubtotal($data['lines'], $products);

            foreach ($data['lines'] as $line) {
                $product = $products->get((int) $line['product_id']);
                $unitCost = (float) ($line['unit_cost'] ?? ($product?->default_purchase_price ?? 0));
                $qtyOrdered = (int) $line['qty_ordered'];
                $lineTotal = round($qtyOrdered * $unitCost, 2);

                PurchaseOrderLine::create([
                    'purchase_order_id' => $purchaseOrder->id,
                    'product_id' => $line['product_id'],
                    'qty_ordered' => $qtyOrdered,
                    'qty_received' => 0,
                    'unit_cost' => $unitCost,
                    'tax_amount' => 0,
                    'line_total' => $lineTotal,
                ]);
            }

            $additionalCosts = $this->buildAdditionalCostsWithPostings(
                $purchaseOrder,
                $data['additional_costs'] ?? [],
                $branchId,
                auth()->id() ? (int) auth()->id() : null,
            );

            $totals = $this->computeHeaderTotals(
                $subtotal,
                (float) ($data['discount_amount'] ?? 0),
                (float) ($data['tax_amount'] ?? 0),
                $additionalCosts,
            );

            $purchaseOrder->update([
                ...$totals,
                'additional_costs' => $additionalCosts,
            ]);
        });

        return redirect()->route('purchase-orders.index')->with('status', 'Purchase order created.');
    }

    public function update(Request $request, PurchaseOrder $purchaseOrder): RedirectResponse
    {
        $this->authorize('update', $purchaseOrder);

        if ($purchaseOrder->status === 'cancelled') {
            return redirect()
                ->route('purchase-orders.show', $purchaseOrder)
                ->with('status', 'Cancelled purchase orders cannot be edited.');
        }

        $data = $request->validate([
            'supplier_id' => ['required', 'exists:suppliers,id'],
            'destination_location_id' => ['required', Rule::exists('locations', 'id')->where('type', 'warehouse')],
            'ordered_at' => ['required', 'date'],
            'expected_at' => ['nullable', 'date'],
            'notes' => ['nullable', 'string'],
            'discount_amount' => ['nullable', 'numeric', 'min:0'],
            'tax_amount' => ['nullable', 'numeric', 'min:0'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.id' => [
                'nullable',
                'integer',
                Rule::exists('purchase_order_lines', 'id')->where('purchase_order_id', $purchaseOrder->id),
            ],
            'lines.*.product_id' => ['required', 'exists:products,id'],
            'lines.*.qty_ordered' => ['required', 'integer', 'min:1'],
            'lines.*.unit_cost' => ['nullable', 'numeric', 'min:0'],
        ]);

        $existingLines = $purchaseOrder->lines()->get()->keyBy('id');

        $submittedIds = [];
        foreach ($data['lines'] as $index => $line) {
            $lineId = $this->nullableInt($line['id'] ?? null);
            $qtyOrdered = (int) $line['qty_ordered'];
            $productId = (int) $line['product_id'];

            if ($lineId !== null) {
                $submittedIds[] = $lineId;
                $existing = $existingLines->get($lineId);
                if (! $existing) {
                    throw ValidationException::withMessages([
                        "lines.{$index}.id" => 'Invalid line reference.',
                    ]);
                }
                if ($qtyOrdered < (int) $existing->qty_received) {
                    throw ValidationException::withMessages([
                        "lines.{$index}.qty_ordered" => 'Ordered quantity cannot be less than already received ('.$existing->qty_received.').',
                    ]);
                }
                if ((int) $existing->qty_received > 0 && (int) $existing->product_id !== $productId) {
                    throw ValidationException::withMessages([
                        "lines.{$index}.product_id" => 'Cannot change product on a line that already has received quantity.',
                    ]);
                }
            }
        }

        $submittedIds = array_values(array_unique($submittedIds));
        foreach ($existingLines as $row) {
            if (in_array((int) $row->id, $submittedIds, true)) {
                continue;
            }
            if ((int) $row->qty_received > 0) {
                $sku = $row->product?->sku ?? 'line #'.$row->id;

                throw ValidationException::withMessages([
                    'lines' => 'Cannot remove line '.$sku.' because it already has received quantity.',
                ]);
            }
        }

        $products = Product::whereIn('id', collect($data['lines'])->pluck('product_id'))
            ->get()
            ->keyBy('id');

        $existingAdditionalCosts = is_array($purchaseOrder->additional_costs) ? $purchaseOrder->additional_costs : [];
        $previewAdditionalSum = round(collect($existingAdditionalCosts)->sum(fn (array $r): float => max(0, round((float) ($r['amount'] ?? 0), 2))), 2);

        $subtotal = $this->computeLinesSubtotal($data['lines'], $products);
        $totalsPreview = $this->computeHeaderTotals(
            $subtotal,
            (float) ($data['discount_amount'] ?? 0),
            (float) ($data['tax_amount'] ?? 0),
            $previewAdditionalSum > 0
                ? [['label' => '', 'amount' => $previewAdditionalSum, 'paid_at' => now()->toDateString(), 'wallet_id' => 0, 'wallet_code' => '', 'wallet_name' => '']]
                : [],
        );

        $before = $this->purchaseOrderChangeLogService->snapshot(
            $purchaseOrder->fresh(['supplier', 'destinationLocation', 'lines.product']),
        );

        DB::transaction(function () use ($data, $purchaseOrder, $products, $submittedIds): void {
            $purchaseOrder->refresh();

            $purchaseOrder->update([
                'supplier_id' => $data['supplier_id'],
                'destination_location_id' => $data['destination_location_id'],
                'ordered_at' => $data['ordered_at'],
                'expected_at' => $data['expected_at'] ?? null,
                'notes' => $data['notes'] ?? null,
            ]);

            if ($submittedIds === []) {
                $purchaseOrder->lines()->delete();
            } else {
                $purchaseOrder->lines()->whereNotIn('id', $submittedIds)->delete();
            }

            foreach ($data['lines'] as $line) {
                $product = $products->get((int) $line['product_id']);
                $unitCost = (float) ($line['unit_cost'] ?? ($product?->default_purchase_price ?? 0));
                $qtyOrdered = (int) $line['qty_ordered'];
                $lineTotal = round($qtyOrdered * $unitCost, 2);
                $lineId = $this->nullableInt($line['id'] ?? null);

                if ($lineId !== null) {
                    PurchaseOrderLine::query()
                        ->where('purchase_order_id', $purchaseOrder->id)
                        ->whereKey($lineId)
                        ->update([
                            'product_id' => (int) $line['product_id'],
                            'qty_ordered' => $qtyOrdered,
                            'unit_cost' => $unitCost,
                            'tax_amount' => 0,
                            'line_total' => $lineTotal,
                        ]);
                } else {
                    PurchaseOrderLine::create([
                        'purchase_order_id' => $purchaseOrder->id,
                        'product_id' => (int) $line['product_id'],
                        'qty_ordered' => $qtyOrdered,
                        'qty_received' => 0,
                        'unit_cost' => $unitCost,
                        'tax_amount' => 0,
                        'line_total' => $lineTotal,
                    ]);
                }
            }

            $purchaseOrder->refresh();
            $additionalCosts = $this->hydrateAdditionalCostWalletLabels(
                $this->sanitizeAdditionalCosts(is_array($purchaseOrder->additional_costs) ? $purchaseOrder->additional_costs : []),
            );

            $subtotalAfter = round((float) PurchaseOrderLine::query()
                ->where('purchase_order_id', $purchaseOrder->id)
                ->sum('line_total'), 2);
            $totalsAfter = $this->computeHeaderTotals(
                $subtotalAfter,
                (float) ($data['discount_amount'] ?? 0),
                (float) ($data['tax_amount'] ?? 0),
                $additionalCosts,
            );
            $purchaseOrder->update($totalsAfter);
        });

        $purchaseOrder->refresh()->load(['supplier', 'destinationLocation', 'lines.product']);
        $after = $this->purchaseOrderChangeLogService->snapshot($purchaseOrder);
        $this->purchaseOrderChangeLogService->recordUpdateIfChanged(
            $purchaseOrder,
            $before,
            $after,
            auth()->id() ? (int) auth()->id() : null,
        );

        $statusMessage = 'Purchase order updated.';
        $supplierCredit = $purchaseOrder->supplierCreditBalance();
        if ($supplierCredit > 0.009) {
            $statusMessage .= ' Supplier credit (overpaid): '.number_format($supplierCredit, 2)
                .'. Balance due is 0 — use this credit on a future PO or record a refund from the supplier.';
        }

        return redirect()->route('purchase-orders.show', $purchaseOrder)->with('status', $statusMessage);
    }

    public function destroy(PurchaseOrder $purchaseOrder): RedirectResponse
    {
        $this->authorize('delete', $purchaseOrder);

        try {
            DB::transaction(function () use ($purchaseOrder): void {
                $purchaseOrder = PurchaseOrder::query()
                    ->lockForUpdate()
                    ->with(['payments:id,purchase_order_id', 'costPostings:id,purchase_order_id'])
                    ->findOrFail($purchaseOrder->id);

                $linkedGoodsReceipts = GoodsReceipt::query()
                    ->where('purchase_order_id', $purchaseOrder->id)
                    ->lockForUpdate()
                    ->get();

                $actorId = auth()->id() ? (int) auth()->id() : null;
                foreach ($linkedGoodsReceipts as $goodsReceipt) {
                    $this->goodsReceiptPostingService->rollbackAndDelete($goodsReceipt, $actorId);
                }

                $paymentIds = $purchaseOrder->payments->pluck('id')->map(fn ($id) => (int) $id)->all();
                $costPostingIds = $purchaseOrder->costPostings->pluck('id')->map(fn ($id) => (int) $id)->all();

                $this->deleteJournalEntriesByReference(PurchaseOrderPayment::class, $paymentIds);
                $this->deleteJournalEntriesByReference(PurchaseOrderCostPosting::class, $costPostingIds);

                $purchaseOrder->delete();
            });
        } catch (\Throwable $e) {
            return redirect()
                ->route('purchase-orders.index')
                ->with('status', $e->getMessage() !== '' ? $e->getMessage() : 'Unable to delete purchase order.');
        }

        return redirect()->route('purchase-orders.index')->with('status', 'Purchase order deleted.');
    }

    public function approve(PurchaseOrder $purchaseOrder): RedirectResponse
    {
        if ($purchaseOrder->status !== 'draft') {
            return back()->with('status', 'Only draft purchase orders can be approved.');
        }

        $this->authorize('approve', PurchaseOrder::class);

        $purchaseOrder->update([
            'status' => 'approved',
            'approved_by' => auth()->id(),
            'approved_at' => now(),
        ]);

        $this->purchaseOrderChangeLogService->recordApproved(
            $purchaseOrder->fresh(),
            auth()->id() ? (int) auth()->id() : null,
        );

        return back()->with('status', 'Purchase order approved.');
    }

    public function storePayment(Request $request, PurchaseOrder $purchaseOrder): RedirectResponse
    {
        if (in_array($purchaseOrder->status, ['draft', 'cancelled'], true)) {
            return back()->with('status', 'Payments can only be recorded for approved or in-progress purchase orders.');
        }

        $this->authorize('recordPayment', PurchaseOrder::class);

        $branchId = (int) $purchaseOrder->destination_location_id;

        $data = $request->validate([
            'return_to' => ['nullable', 'string', Rule::in(['supplier-payment-register'])],
            'wallet_id' => [
                'required',
                'integer',
                'exists:wallets,id',
                function (string $attribute, mixed $value, \Closure $fail) use ($branchId): void {
                    $ownerWallet = OwnerCapitalWallet::activeForLocation($branchId);
                    if (! $ownerWallet || (int) $ownerWallet->id !== (int) $value) {
                        $fail('Supplier payments only accept the '.OwnerCapitalWallet::code().' wallet.');
                    }
                },
            ],
            'amount' => ['required', 'numeric', 'min:0'],
            'settlement_discount' => ['nullable', 'numeric', 'min:0'],
            'paid_at' => ['required', 'date'],
            'reference' => ['nullable', 'string', 'max:120'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        $amount = round(max(0, (float) $data['amount']), 2);
        $discount = round(max(0, (float) ($data['settlement_discount'] ?? 0)), 2);
        $totalApplied = round($amount + $discount, 2);
        $due = $purchaseOrder->amountDue();

        if ($totalApplied < 0.01) {
            throw ValidationException::withMessages([
                'amount' => 'Enter a payment amount and/or settlement discount.',
            ]);
        }

        if ($amount < 0.01 && $discount > 0.009) {
            throw ValidationException::withMessages([
                'amount' => 'Cash amount is required when recording a settlement discount.',
            ]);
        }

        if ($totalApplied - $due > 0.009) {
            throw ValidationException::withMessages([
                'amount' => 'Cash plus settlement discount cannot exceed the balance due ('.number_format($due, 2).').',
            ]);
        }

        DB::transaction(function () use ($purchaseOrder, $data, $amount, $discount): void {
            $payment = PurchaseOrderPayment::create([
                'purchase_order_id' => $purchaseOrder->id,
                'wallet_id' => (int) $data['wallet_id'],
                'amount' => $amount,
                'settlement_discount' => $discount,
                'paid_at' => $data['paid_at'],
                'reference' => $data['reference'] ?? null,
                'notes' => $data['notes'] ?? null,
                'created_by' => (int) auth()->id(),
            ]);

            $this->journalService->postSupplierPoPayment(
                $purchaseOrder,
                $payment,
                (int) $data['wallet_id'],
                (int) auth()->id(),
            );
        });

        if (($data['return_to'] ?? null) === 'supplier-payment-register') {
            $purchaseOrder->refresh();
            $stayOpen = $purchaseOrder->amountDue() > 0.009
                && ! in_array($purchaseOrder->status, ['draft', 'cancelled'], true);

            return redirect()->route('supplier-payment-register.index', array_filter([
                'purchase_order' => $stayOpen ? $purchaseOrder->id : null,
            ]))->with('status', 'Payment recorded.');
        }

        return back()->with('status', 'Payment recorded.');
    }

    public function storeSupplierRefund(Request $request, PurchaseOrder $purchaseOrder): RedirectResponse
    {
        $this->authorize('recordPayment', PurchaseOrder::class);

        if (in_array($purchaseOrder->status, ['draft', 'cancelled'], true)) {
            return back()->with('status', 'Refunds can only be recorded for approved or in-progress purchase orders.');
        }

        $branchId = (int) $purchaseOrder->destination_location_id;
        $credit = $purchaseOrder->supplierCreditBalance();
        if ($credit <= 0.009) {
            return back()->withErrors(['amount' => 'There is no excess paid amount to refund on this purchase order.']);
        }

        $data = $request->validate([
            'wallet_id' => [
                'required',
                'integer',
                'exists:wallets,id',
                function (string $attribute, mixed $value, \Closure $fail) use ($branchId): void {
                    $ok = Wallet::query()
                        ->whereKey((int) $value)
                        ->where('is_active', true)
                        ->whereAppliesToLocation($branchId)
                        ->whereHas('ledgerAccount', fn ($q) => $q->where('is_active', true))
                        ->exists();
                    if (! $ok) {
                        $fail('The selected wallet is not available for this purchase order branch.');
                    }
                },
            ],
            'amount' => ['required', 'numeric', 'min:0.01'],
            'paid_at' => ['required', 'date'],
            'reference' => ['nullable', 'string', 'max:120'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        $amount = round((float) $data['amount'], 2);
        if ($amount - $credit > 0.009) {
            throw ValidationException::withMessages([
                'amount' => 'Refund cannot exceed the excess paid amount ('.number_format($credit, 2).').',
            ]);
        }

        try {
            DB::transaction(function () use ($purchaseOrder, $data, $amount): void {
                $locked = PurchaseOrder::query()->lockForUpdate()->findOrFail($purchaseOrder->id);
                $available = $locked->supplierCreditBalance();
                if ($amount - $available > 0.009) {
                    throw ValidationException::withMessages([
                        'amount' => 'Refund cannot exceed the excess paid amount ('.number_format($available, 2).').',
                    ]);
                }

                $payment = PurchaseOrderPayment::create([
                    'purchase_order_id' => $locked->id,
                    'wallet_id' => (int) $data['wallet_id'],
                    'amount' => -abs($amount),
                    'settlement_discount' => 0,
                    'paid_at' => $data['paid_at'],
                    'reference' => $data['reference'] ?? null,
                    'notes' => $data['notes'] ?? null,
                    'created_by' => (int) auth()->id(),
                ]);

                $this->journalService->postSupplierPoRefund(
                    $locked,
                    $payment,
                    (int) $data['wallet_id'],
                    (int) auth()->id(),
                );
            });
        } catch (ValidationException $e) {
            throw $e;
        }

        return redirect()
            ->route('purchase-orders.show', $purchaseOrder)
            ->with('status', 'Excess payment refunded to wallet. Paid total was reduced.');
    }

    public function destroyPayment(
        PurchaseOrder $purchaseOrder,
        PurchaseOrderPayment $purchaseOrderPayment,
    ): RedirectResponse {
        Gate::authorize('delete-payment-allocation');
        $this->authorize('update', $purchaseOrder);

        $wasRefund = false;

        try {
            DB::transaction(function () use ($purchaseOrder, $purchaseOrderPayment, &$wasRefund): void {
                $purchaseOrder = PurchaseOrder::query()->lockForUpdate()->findOrFail($purchaseOrder->id);
                $payment = PurchaseOrderPayment::query()->lockForUpdate()->findOrFail($purchaseOrderPayment->id);

                if ((int) $payment->purchase_order_id !== (int) $purchaseOrder->id) {
                    throw new InvalidStateException('Payment does not belong to this purchase order.');
                }

                if ($purchaseOrder->status === 'cancelled') {
                    throw new InvalidStateException('Payments cannot be removed from a cancelled purchase order.');
                }

                $wasRefund = $payment->isRefund();
                $this->deleteJournalEntriesByReference(PurchaseOrderPayment::class, [(int) $payment->id]);
                $payment->delete();
            });
        } catch (InvalidStateException $e) {
            return redirect()
                ->route('purchase-orders.show', $purchaseOrder)
                ->withErrors(['payment' => $e->getMessage()]);
        } catch (\Throwable $e) {
            return redirect()
                ->route('purchase-orders.show', $purchaseOrder)
                ->with('status', $e->getMessage() !== '' ? $e->getMessage() : 'Unable to remove payment.');
        }

        return redirect()
            ->route('purchase-orders.show', $purchaseOrder)
            ->with('status', $wasRefund
                ? 'Refund removed. Wallet balance and AP entries were reversed.'
                : 'Payment removed. Wallet balance and AP entries were restored.');
    }

    public function storeAdditionalCost(Request $request, PurchaseOrder $purchaseOrder): RedirectResponse
    {
        $this->authorize('manageAdditionalCost', PurchaseOrder::class);

        if ($purchaseOrder->status === 'cancelled') {
            return back()->with('status', 'Cannot add costs to a cancelled purchase order.');
        }

        $branchId = (int) $purchaseOrder->destination_location_id;

        $data = $request->validate([
            'label' => ['required', 'string', 'max:120'],
            'amount' => ['required', 'numeric', 'min:0.01'],
            'paid_at' => ['required', 'date'],
            'wallet_id' => [
                'required',
                'integer',
                'exists:wallets,id',
                function (string $attribute, mixed $value, \Closure $fail) use ($branchId): void {
                    $ok = Wallet::query()
                        ->whereKey((int) $value)
                        ->where('is_active', true)
                        ->whereAppliesToLocation($branchId)
                        ->whereHas('ledgerAccount', fn ($q) => $q->where('is_active', true))
                        ->exists();
                    if (! $ok) {
                        $fail('The selected wallet is not available for this purchase order branch.');
                    }
                },
            ],
        ]);

        $wallet = Wallet::query()
            ->whereKey((int) $data['wallet_id'])
            ->where('is_active', true)
            ->whereAppliesToLocation($branchId)
            ->whereHas('ledgerAccount', fn ($q) => $q->where('is_active', true))
            ->firstOrFail();

        $before = $this->purchaseOrderChangeLogService->snapshot(
            $purchaseOrder->fresh(['supplier', 'destinationLocation', 'lines.product']),
        );

        DB::transaction(function () use ($purchaseOrder, $data, $wallet): void {
            $purchaseOrder->refresh();

            $posting = PurchaseOrderCostPosting::create([
                'purchase_order_id' => $purchaseOrder->id,
                'wallet_id' => $wallet->id,
                'amount' => round((float) $data['amount'], 2),
                'paid_at' => $data['paid_at'],
                'label' => trim((string) $data['label']),
                'created_by' => auth()->id() ? (int) auth()->id() : null,
            ]);

            $existingCosts = $purchaseOrder->additional_costs;
            if (! is_array($existingCosts)) {
                $existingCosts = [];
            }

            $existingCosts[] = [
                'label' => trim((string) $data['label']),
                'amount' => round((float) $data['amount'], 2),
                'paid_at' => $data['paid_at'],
                'wallet_id' => $wallet->id,
                'wallet_code' => $wallet->code,
                'wallet_name' => $wallet->name,
                'posting_id' => $posting->id,
            ];

            $additionalCosts = $this->hydrateAdditionalCostWalletLabels($this->sanitizeAdditionalCosts($existingCosts));
            $totals = $this->computeHeaderTotals(
                (float) $purchaseOrder->subtotal_amount,
                (float) $purchaseOrder->discount_amount,
                (float) $purchaseOrder->tax_amount,
                $additionalCosts,
            );

            $purchaseOrder->update([
                'additional_costs' => $additionalCosts,
                ...$totals,
            ]);

            $this->journalService->postPurchaseOrderAdditionalCost(
                $purchaseOrder->fresh(),
                $posting->fresh(),
                auth()->id() ? (int) auth()->id() : null,
            );
        });

        $purchaseOrder->refresh()->load(['supplier', 'destinationLocation', 'lines.product']);
        $after = $this->purchaseOrderChangeLogService->snapshot($purchaseOrder);
        $this->purchaseOrderChangeLogService->recordUpdateIfChanged(
            $purchaseOrder,
            $before,
            $after,
            auth()->id() ? (int) auth()->id() : null,
        );

        return back()->with('status', 'Additional cost added.');
    }

    private function costWalletsGlobalQuery(): Builder
    {
        return Wallet::query()
            ->where('is_active', true)
            ->whereHas('ledgerAccount', fn ($q) => $q->where('is_active', true))
            ->orderBy('code');
    }

    /**
     * @return \Illuminate\Database\Eloquent\Collection<int, Wallet>
     */
    private function costWalletsForBranch(int $branchId): Collection
    {
        return $this->costWalletsGlobalQuery()
            ->whereAppliesToLocation($branchId)
            ->get(['id', 'code', 'name']);
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    private function validateAdditionalCostWalletRows(array $rows, int $branchId): void
    {
        foreach ($rows as $index => $row) {
            if (! is_array($row)) {
                continue;
            }
            $amount = round((float) ($row['amount'] ?? 0), 2);
            if ($amount <= 0.0001) {
                continue;
            }
            $walletId = (int) ($row['wallet_id'] ?? 0);
            if ($walletId <= 0) {
                throw ValidationException::withMessages([
                    "additional_costs.{$index}.wallet_id" => 'Select a wallet for each additional cost with an amount.',
                ]);
            }
            $ok = Wallet::query()
                ->whereKey($walletId)
                ->where('is_active', true)
                ->whereAppliesToLocation($branchId)
                ->whereHas('ledgerAccount', fn ($q) => $q->where('is_active', true))
                ->exists();
            if (! $ok) {
                throw ValidationException::withMessages([
                    "additional_costs.{$index}.wallet_id" => 'The selected wallet is not available for this branch.',
                ]);
            }
        }
    }

    /**
     * @param  array<int|string, mixed>|null  $previous
     * @param  list<array<string, mixed>>  $submitted
     */
    private function assertPostedAdditionalCostsStillPresent(?array $previous, array $submitted): void
    {
        $prevIds = collect($previous ?? [])
            ->pluck('posting_id')
            ->filter(fn ($id) => (int) $id > 0)
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values();

        if ($prevIds->isEmpty()) {
            return;
        }

        $submittedIds = collect($submitted)
            ->pluck('posting_id')
            ->filter(fn ($id) => (int) $id > 0)
            ->map(fn ($id) => (int) $id)
            ->unique();

        $missing = $prevIds->diff($submittedIds);
        if ($missing->isNotEmpty()) {
            throw ValidationException::withMessages([
                'additional_costs' => 'Cannot remove additional costs that are already posted to the ledger.',
            ]);
        }
    }

    /**
     * @param  array<int|string, mixed>|null  $previous
     * @param  list<array<string, mixed>>  $submitted
     */
    private function assertPostedAdditionalCostsImmutable(?array $previous, array $submitted): void
    {
        $prevByPosting = collect($previous ?? [])
            ->filter(fn ($row) => is_array($row) && ! empty($row['posting_id']))
            ->keyBy(fn ($row) => (int) $row['posting_id']);

        foreach ($submitted as $index => $row) {
            if (! is_array($row)) {
                continue;
            }
            $pid = (int) ($row['posting_id'] ?? 0);
            if ($pid <= 0) {
                continue;
            }
            $prev = $prevByPosting->get($pid);
            if (! $prev) {
                continue;
            }
            if (abs((float) ($prev['amount'] ?? 0) - (float) ($row['amount'] ?? 0)) > 0.009) {
                throw ValidationException::withMessages([
                    "additional_costs.{$index}.amount" => 'Posted additional costs cannot change amount.',
                ]);
            }
            if ((int) ($prev['wallet_id'] ?? 0) !== (int) ($row['wallet_id'] ?? 0)) {
                throw ValidationException::withMessages([
                    "additional_costs.{$index}.wallet_id" => 'Posted additional costs cannot change wallet.',
                ]);
            }
        }
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    private function buildAdditionalCostsWithPostings(
        PurchaseOrder $purchaseOrder,
        array $rows,
        int $branchId,
        ?int $userId,
        bool $isUpdate = false,
    ): array {
        $actorId = $userId ?? (auth()->id() ? (int) auth()->id() : 0);
        $existingByPostingId = collect($purchaseOrder->additional_costs ?? [])
            ->filter(fn ($r) => is_array($r) && ! empty($r['posting_id']))
            ->keyBy(fn ($r) => (int) $r['posting_id']);

        $result = [];
        foreach ($rows as $index => $row) {
            if (! is_array($row)) {
                continue;
            }

            $pid = $isUpdate ? (int) ($row['posting_id'] ?? 0) : 0;
            if ($isUpdate && $pid > 0) {
                $posting = PurchaseOrderCostPosting::query()
                    ->where('purchase_order_id', $purchaseOrder->id)
                    ->whereKey($pid)
                    ->first();
                if (! $posting) {
                    throw ValidationException::withMessages([
                        "additional_costs.{$index}.posting_id" => 'Invalid posted cost reference.',
                    ]);
                }
                $prev = $existingByPostingId->get($pid);
                if (! $prev) {
                    throw ValidationException::withMessages([
                        "additional_costs.{$index}.posting_id" => 'Unknown posted cost reference.',
                    ]);
                }
                $label = trim((string) ($row['label'] ?? ''));
                $paidAt = trim((string) ($row['paid_at'] ?? ''));
                $result[] = [
                    'label' => $label !== '' ? $label : (string) ($prev['label'] ?? 'Misc Cost'),
                    'amount' => round((float) ($prev['amount'] ?? 0), 2),
                    'paid_at' => $paidAt !== '' ? $paidAt : (string) ($prev['paid_at'] ?? now()->toDateString()),
                    'wallet_id' => (int) ($prev['wallet_id'] ?? 0),
                    'wallet_code' => (string) ($prev['wallet_code'] ?? ''),
                    'wallet_name' => (string) ($prev['wallet_name'] ?? ''),
                    'posting_id' => $pid,
                ];

                continue;
            }

            $amount = round((float) ($row['amount'] ?? 0), 2);
            $label = trim((string) ($row['label'] ?? ''));
            $paidAt = trim((string) ($row['paid_at'] ?? ''));
            if ($amount <= 0.0001 && $label === '') {
                continue;
            }
            if ($amount <= 0.0001) {
                continue;
            }

            $walletId = (int) ($row['wallet_id'] ?? 0);
            if ($walletId <= 0) {
                throw ValidationException::withMessages([
                    "additional_costs.{$index}.wallet_id" => 'Select a wallet for each additional cost with an amount.',
                ]);
            }

            $wallet = Wallet::query()
                ->whereKey($walletId)
                ->where('is_active', true)
                ->whereAppliesToLocation($branchId)
                ->whereHas('ledgerAccount', fn ($q) => $q->where('is_active', true))
                ->firstOrFail();

            $posting = PurchaseOrderCostPosting::create([
                'purchase_order_id' => $purchaseOrder->id,
                'wallet_id' => $wallet->id,
                'amount' => $amount,
                'paid_at' => $paidAt !== '' ? $paidAt : now()->toDateString(),
                'label' => $label !== '' ? $label : 'Misc Cost',
                'created_by' => $actorId ?: null,
            ]);

            $this->journalService->postPurchaseOrderAdditionalCost(
                $purchaseOrder->fresh(),
                $posting->fresh(),
                $actorId,
            );

            $result[] = [
                'label' => $label !== '' ? $label : 'Misc Cost',
                'amount' => $amount,
                'paid_at' => $paidAt !== '' ? $paidAt : now()->toDateString(),
                'wallet_id' => $wallet->id,
                'wallet_code' => $wallet->code,
                'wallet_name' => $wallet->name,
                'posting_id' => $posting->id,
            ];
        }

        return $this->hydrateAdditionalCostWalletLabels($this->sanitizeAdditionalCosts($result));
    }

    /**
     * @param  list<array<string, mixed>>  $costs
     * @return list<array<string, mixed>>
     */
    private function hydrateAdditionalCostWalletLabels(array $costs): array
    {
        $ids = collect($costs)->pluck('wallet_id')->filter()->map(fn ($id) => (int) $id)->unique()->values()->all();
        if ($ids === []) {
            return $costs;
        }

        $wallets = Wallet::query()->whereIn('id', $ids)->get(['id', 'code', 'name'])->keyBy('id');
        $out = [];
        foreach ($costs as $row) {
            $wid = (int) ($row['wallet_id'] ?? 0);
            if ($wid > 0 && $wallets->has($wid)) {
                $w = $wallets->get($wid);
                $row['wallet_code'] = $w->code;
                $row['wallet_name'] = $w->name;
            }
            $out[] = $row;
        }

        return $out;
    }

    /**
     * @param  list<array{product_id: mixed, qty_ordered: mixed, unit_cost?: mixed|null}>  $lines
     */
    private function computeLinesSubtotal(array $lines, Collection $products): float
    {
        $subtotal = 0.0;
        foreach ($lines as $line) {
            $product = $products->get((int) $line['product_id']);
            $unitCost = (float) ($line['unit_cost'] ?? ($product?->default_purchase_price ?? 0));
            $qtyOrdered = (int) $line['qty_ordered'];
            $subtotal += $qtyOrdered * $unitCost;
        }

        return round($subtotal, 2);
    }

    /**
     * @param  list<array<string, mixed>>  $additionalCosts
     * @return array{subtotal_amount: float, discount_amount: float, tax_amount: float, additional_cost_amount: float, total_amount: float}
     */
    private function computeHeaderTotals(float $subtotal, float $discount, float $tax, array $additionalCosts = []): array
    {
        $subtotal = max(0, round($subtotal, 2));
        $discount = max(0, round($discount, 2));
        $tax = max(0, round($tax, 2));
        $additionalCostTotal = round(collect($additionalCosts)->sum(fn (array $row): float => (float) ($row['amount'] ?? 0)), 2);
        $discount = min($discount, $subtotal);
        $grand = round(max(0, $subtotal - $discount + $tax + $additionalCostTotal), 2);

        return [
            'subtotal_amount' => $subtotal,
            'discount_amount' => $discount,
            'tax_amount' => $tax,
            'additional_cost_amount' => $additionalCostTotal,
            'total_amount' => $grand,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function sanitizeAdditionalCosts(mixed $rows): array
    {
        if (! is_array($rows)) {
            return [];
        }

        $costs = [];
        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }

            $label = trim((string) ($row['label'] ?? ''));
            $amount = max(0, round((float) ($row['amount'] ?? 0), 2));
            $paidAt = trim((string) ($row['paid_at'] ?? ''));
            if ($label === '' && $amount <= 0) {
                continue;
            }

            $out = [
                'label' => $label !== '' ? $label : 'Misc Cost',
                'amount' => $amount,
                'paid_at' => $paidAt !== '' ? $paidAt : now()->toDateString(),
            ];
            $walletId = (int) ($row['wallet_id'] ?? 0);
            if ($walletId > 0) {
                $out['wallet_id'] = $walletId;
            }
            $walletCode = trim((string) ($row['wallet_code'] ?? ''));
            if ($walletCode !== '') {
                $out['wallet_code'] = $walletCode;
            }
            $walletName = trim((string) ($row['wallet_name'] ?? ''));
            if ($walletName !== '') {
                $out['wallet_name'] = $walletName;
            }
            $postingId = (int) ($row['posting_id'] ?? 0);
            if ($postingId > 0) {
                $out['posting_id'] = $postingId;
            }
            $costs[] = $out;
        }

        return $costs;
    }

    private function nullableInt(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        return (int) $value;
    }

    /**
     * @param  list<int>  $referenceIds
     */
    private function deleteJournalEntriesByReference(string $referenceType, array $referenceIds): void
    {
        if ($referenceIds === []) {
            return;
        }

        $entryIds = JournalEntry::query()
            ->where('reference_type', $referenceType)
            ->whereIn('reference_id', $referenceIds)
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();

        if ($entryIds === []) {
            return;
        }

        JournalLine::query()->whereIn('journal_entry_id', $entryIds)->delete();
        JournalEntry::query()->whereIn('id', $entryIds)->delete();
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
