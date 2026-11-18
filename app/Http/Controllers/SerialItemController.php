<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\AppliesInventoryLocationContext;
use App\Models\Location;
use App\Models\ManualStockAdjustment;
use App\Models\Product;
use App\Models\SerialItem;
use App\Models\StockLedger;
use App\Models\User;
use App\Services\InventoryBalanceService;
use App\Services\ManualStockAdjustmentService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class SerialItemController extends Controller
{
    use AppliesInventoryLocationContext;

    public function index(Request $request): View
    {
        $this->authorize('viewAny', SerialItem::class);

        $filters = [
            'q' => trim((string) $request->input('q', '')),
            'status' => (string) $request->input('status', ''),
            'product_id' => (string) $request->input('product_id', ''),
            'location_id' => (string) $request->input('location_id', ''),
        ];

        $baseQuery = SerialItem::query()
            ->with(['product:id,sku,name,is_serialized', 'location:id,code,name']);

        $this->applyRegistryLocationScope($baseQuery);

        if ($filters['q'] !== '') {
            $keyword = $filters['q'];
            $like = '%'.addcslashes($keyword, '%_\\').'%';
            $baseQuery->where(function ($sub) use ($like, $keyword): void {
                $sub->where('serial_number', 'like', $like)
                    ->orWhere('manufacturer_serial', 'like', $like)
                    ->orWhereHas('product', function ($productQuery) use ($like): void {
                        $productQuery->where('name', 'like', $like)
                            ->orWhere('sku', 'like', $like)
                            ->orWhere('barcode', 'like', $like);
                    });
                if (ctype_digit($keyword)) {
                    $sub->orWhere('serial_items.id', (int) $keyword);

                    $labelCode = \App\Support\SerialLabelCode::parseScanCode($keyword);
                    if ($labelCode !== null) {
                        $sub->orWhere('label_code', $labelCode);
                    }
                }
            });
        }

        if ($filters['status'] !== '') {
            $baseQuery->where('status', $filters['status']);
        }

        if ($filters['product_id'] !== '') {
            $baseQuery->where('product_id', (int) $filters['product_id']);
        }

        if ($filters['location_id'] !== '') {
            $baseQuery->where('current_location_id', (int) $filters['location_id']);
        }

        $kpiQuery = clone $baseQuery;

        $serials = (clone $baseQuery)
            ->latest('id')
            ->paginate($this->resolvePerPage($request, 25))
            ->withQueryString();

        $kpis = [
            'total' => (clone $kpiQuery)->count(),
            'in_stock' => (clone $kpiQuery)->where('status', 'in_stock')->count(),
            'sold' => (clone $kpiQuery)->where('status', 'sold')->count(),
            'filtered' => $serials->total(),
        ];

        $filterProducts = Product::query()
            ->where('is_serialized', true)
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'sku', 'name']);

        $filterLocations = $this->filterLocationsForUser();

        return view('serial-items.index', compact(
            'serials',
            'filters',
            'kpis',
            'filterProducts',
            'filterLocations',
        ));
    }

    public function show(SerialItem $serialItem): View
    {
        $this->authorize('view', $serialItem);
        $this->ensureUserMayViewSerial($serialItem);

        $serialItem->load([
            'product.brand',
            'product.category',
            'location',
            'goodsReceiptLine.goodsReceipt',
            'saleInvoiceLine.salesInvoice.customer',
            'saleInvoiceLine.salesInvoice.branch',
        ]);

        $canUpdate = auth()->user()?->can('update', $serialItem) ?? false;
        $canRemove = $this->userCanRemoveSerial(auth()->user(), $serialItem);

        return view('serial-items.show', [
            'serial' => $serialItem,
            'statusOptions' => SerialItem::statusOptions(),
            'canUpdate' => $canUpdate,
            'canRemove' => $canRemove,
        ]);
    }

    public function update(Request $request, SerialItem $serialItem): RedirectResponse
    {
        $this->authorize('update', $serialItem);
        $this->ensureUserMayViewSerial($serialItem);

        $data = $request->validate([
            'status' => ['required', Rule::in(SerialItem::statusOptions())],
        ]);

        $newStatus = (string) $data['status'];
        if ($newStatus === $serialItem->status) {
            return redirect()
                ->route('serial-items.show', $serialItem)
                ->with('status', 'No changes were made.');
        }

        $serialItem->update(['status' => $newStatus]);

        return redirect()
            ->route('serial-items.show', $serialItem)
            ->with('status', 'Status updated to '.str_replace('_', ' ', $newStatus).'.');
    }

    public function destroy(
        Request $request,
        SerialItem $serialItem,
        ManualStockAdjustmentService $manualStockAdjustmentService,
        InventoryBalanceService $inventoryBalanceService,
    ): RedirectResponse {
        $this->authorize('delete', $serialItem);
        $this->ensureUserMayViewSerial($serialItem);

        $user = $request->user();
        if (! $user instanceof User) {
            abort(403);
        }

        $product = $serialItem->product;
        if (! $product || ! $product->is_serialized) {
            abort(404);
        }

        if ($serialItem->current_location_id === null) {
            return redirect()
                ->route('serial-items.show', $serialItem)
                ->withErrors(['serial' => 'This serial is not at a branch; it cannot be removed from stock here.']);
        }

        if (! in_array((string) $serialItem->status, ['in_stock', 'damaged'], true)) {
            return redirect()
                ->route('serial-items.show', $serialItem)
                ->withErrors(['serial' => 'Only in-stock or damaged-at-branch serials can be removed.']);
        }

        $locationId = (int) $serialItem->current_location_id;
        $accessibleIds = $user->accessibleLocationIds();
        if ($accessibleIds === [] || ! in_array($locationId, $accessibleIds, true)) {
            abort(403);
        }

        if ($serialItem->status === 'damaged') {
            try {
                DB::transaction(function () use ($user, $product, $serialItem, $inventoryBalanceService): void {
                    $this->writeOffDamagedSerialStillAtBranch($user, $product, $serialItem, $inventoryBalanceService);
                });
            } catch (\Throwable $e) {
                return redirect()
                    ->route('serial-items.show', $serialItem)
                    ->withErrors(['serial' => $e->getMessage()]);
            }

            return redirect()
                ->route('serial-items.index')
                ->with('status', 'Damaged serial '.$serialItem->serial_number.' was cleared from branch inventory.');
        }

        try {
            $manualStockAdjustmentService->applyAdjustment(
                $user,
                $product,
                $locationId,
                null,
                'Removed from serial registry: '.$serialItem->serial_number,
                [$serialItem->id],
                -1,
            );
        } catch (ValidationException $e) {
            return redirect()
                ->route('serial-items.show', $serialItem)
                ->withErrors($e->errors());
        }

        return redirect()
            ->route('serial-items.index')
            ->with('status', 'Serial '.$serialItem->serial_number.' was removed from stock.');
    }

    private function applyRegistryLocationScope(Builder $query): void
    {
        $contextId = $this->contextLocationId();
        if ($contextId !== null) {
            $query->where(function ($sub) use ($contextId): void {
                $sub->where('current_location_id', $contextId)
                    ->orWhereHas('saleInvoiceLine.salesInvoice', fn ($invoiceQuery) => $invoiceQuery->where('branch_id', $contextId));
            });

            return;
        }

        $accessibleIds = $this->accessibleLocationIds();
        if ($accessibleIds !== []) {
            $query->where(function ($sub) use ($accessibleIds): void {
                $sub->whereIn('current_location_id', $accessibleIds)
                    ->orWhereHas('saleInvoiceLine.salesInvoice', fn ($invoiceQuery) => $invoiceQuery->whereIn('branch_id', $accessibleIds));
            });
        }
    }

    private function ensureUserMayViewSerial(SerialItem $serial): void
    {
        $user = auth()->user();
        if (! $user) {
            abort(403);
        }

        if ($user->isAdmin() || $user->hasPermission('location.view-all')) {
            return;
        }

        $accessible = $user->accessibleLocationIds();
        if ($accessible === []) {
            abort(403);
        }

        $locationId = $serial->current_location_id;
        if ($locationId !== null && in_array((int) $locationId, $accessible, true)) {
            return;
        }

        $branchId = $serial->saleInvoiceLine?->salesInvoice?->branch_id;
        if ($branchId !== null && in_array((int) $branchId, $accessible, true)) {
            return;
        }

        abort(403);
    }

    private function userCanRemoveSerial(?User $user, SerialItem $serial): bool
    {
        if (! $user || ! $user->can('manageStockLevels', Product::class)) {
            return false;
        }

        if ($serial->current_location_id === null) {
            return false;
        }

        if (! in_array((string) $serial->status, ['in_stock', 'damaged'], true)) {
            return false;
        }

        if ($user->isAdmin() || $user->hasPermission('location.view-all')) {
            return true;
        }

        return in_array((int) $serial->current_location_id, $user->accessibleLocationIds(), true);
    }

    /** @return Collection<int, Location> */
    private function filterLocationsForUser()
    {
        $query = Location::query()->where('is_active', true)->orderBy('name');
        $user = auth()->user();
        if ($user && ! $user->isAdmin() && ! $user->hasPermission('location.view-all')) {
            $query->whereIn('id', $user->accessibleLocationIds());
        }

        return $query->get(['id', 'code', 'name']);
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
                ? 'Cleared damaged serial from branch (serial registry, on-hand already zero): '.$serial->serial_number
                : 'Cleared damaged serial from branch (serial registry): '.$serial->serial_number,
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
}
