<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Models\SerialItem;
use App\Models\StockLedger;
use App\Services\InventoryBalanceService;
use App\Services\ManualStockAdjustmentService;
use App\Support\LedgerBackedInventory;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class InventoryStockController extends Controller
{
    public function index(Request $request): View
    {
        $user = $request->user();
        $accessibleIds = $user->accessibleLocationIds();
        if ($accessibleIds === []) {
            abort(403);
        }

        $serializedInput = $request->input('serialized');
        $serializedFilter = in_array($serializedInput, ['0', '1'], true)
            ? $serializedInput === '1'
            : null;

        $filters = [
            'q' => trim((string) $request->input('q', '')),
            'location_id' => $request->integer('location_id') ?: null,
            'serialized' => $serializedFilter,
        ];

        $perPage = $this->resolvePerPage($request);

        $locations = DB::table('locations')
            ->where('is_active', true)
            ->whereIn('id', $accessibleIds)
            ->orderBy('name')
            ->get(['id', 'code', 'name']);

        $rows = DB::query()
            ->fromSub(LedgerBackedInventory::balancesSubquery(), 'ib')
            ->join('products as p', 'p.id', '=', 'ib.product_id')
            ->join('locations as l', 'l.id', '=', 'ib.location_id')
            ->whereIn('ib.location_id', $accessibleIds)
            ->when($filters['location_id'], fn ($q) => $q->where('ib.location_id', $filters['location_id']))
            ->when($filters['serialized'] !== null, function ($q) use ($filters): void {
                $q->where('p.is_serialized', $filters['serialized'] ? 1 : 0);
            })
            ->when($filters['q'] !== '', function ($q) use ($filters): void {
                $like = '%'.$filters['q'].'%';
                $q->where(function ($sub) use ($like): void {
                    $sub->where('p.sku', 'like', $like)
                        ->orWhere('p.name', 'like', $like);
                });
            })
            ->orderBy('l.name')
            ->orderBy('p.sku')
            ->paginate($perPage, [
                'ib.product_id',
                'ib.location_id',
                'ib.on_hand_qty',
                'ib.reserved_qty',
                'ib.available_qty',
                'ib.updated_at as balance_updated_at',
                'p.sku',
                'p.name as product_name',
                'p.is_serialized',
                'l.code as location_code',
                'l.name as location_name',
            ])
            ->withQueryString();

        return view('inventory-stock.index', compact('rows', 'filters', 'locations'));
    }

    public function adjust(Request $request): View
    {
        $user = $request->user();
        $accessibleIds = $user->accessibleLocationIds();
        if ($accessibleIds === []) {
            abort(403);
        }

        $validated = $request->validate([
            'product_id' => ['required', 'integer', 'exists:products,id'],
            'location_id' => ['required', 'integer', 'exists:locations,id'],
        ]);

        $locationId = (int) $validated['location_id'];
        if (! in_array($locationId, $accessibleIds, true)) {
            abort(403);
        }

        $product = Product::query()->findOrFail((int) $validated['product_id']);

        app(InventoryBalanceService::class)->recalculate($product->id, $locationId);

        $balanceRow = DB::table('inventory_balances')
            ->where('product_id', $product->id)
            ->where('location_id', $locationId)
            ->first();

        $removableSerials = collect();
        if ($product->is_serialized) {
            $removableSerials = SerialItem::query()
                ->where('product_id', $product->id)
                ->where('current_location_id', $locationId)
                ->where('status', 'in_stock')
                ->orderBy('serial_number')
                ->get(['id', 'serial_number', 'manufacturer_serial']);
        }

        $locationName = DB::table('locations')->where('id', $locationId)->value('name');

        return view('inventory-stock.adjust', [
            'product' => $product,
            'locationId' => $locationId,
            'locationName' => (string) $locationName,
            'balance' => [
                'on_hand_qty' => (int) ($balanceRow->on_hand_qty ?? 0),
                'reserved_qty' => (int) ($balanceRow->reserved_qty ?? 0),
                'available_qty' => (int) ($balanceRow->available_qty ?? 0),
            ],
            'removableSerials' => $removableSerials,
        ]);
    }

    public function store(Request $request, ManualStockAdjustmentService $manualStockAdjustmentService): RedirectResponse
    {
        $user = $request->user();
        $accessibleIds = $user->accessibleLocationIds();
        if ($accessibleIds === []) {
            abort(403);
        }

        $data = $request->validate([
            'product_id' => ['required', 'integer', 'exists:products,id'],
            'location_id' => ['required', 'integer', 'exists:locations,id'],
            'adjust_mode' => ['nullable', Rule::in(['absolute', 'relative'])],
            // Only validate qty_delta in relative mode; otherwise a submitted 0 fails `not_in:0` and blocks setting target_on_hand_qty to 0 in absolute mode.
            'qty_delta' => ['exclude_unless:adjust_mode,relative', 'required', 'integer', Rule::notIn([0])],
            'target_on_hand_qty' => ['required_unless:adjust_mode,relative', 'integer', 'min:0'],
            'reason' => ['nullable', 'string', 'max:2000'],
            'serial_ids' => ['sometimes', 'array'],
            'serial_ids.*' => ['integer', 'exists:serial_items,id'],
            'serialized_add_mode' => ['nullable', Rule::in(['auto', 'custom'])],
            'custom_serial_lines' => ['nullable', 'string', 'max:10000'],
        ]);

        $locationId = (int) $data['location_id'];
        if (! in_array($locationId, $accessibleIds, true)) {
            abort(403);
        }

        $product = Product::query()->findOrFail((int) $data['product_id']);

        $serialIds = isset($data['serial_ids'])
            ? array_values(array_map(static fn ($id): int => (int) $id, $data['serial_ids']))
            : [];

        $adjustMode = isset($data['adjust_mode']) && $data['adjust_mode'] !== ''
            ? (string) $data['adjust_mode']
            : 'absolute';
        $relativeDelta = $adjustMode === 'relative' ? (int) $data['qty_delta'] : null;
        $absoluteTarget = $adjustMode === 'relative' ? null : (int) $data['target_on_hand_qty'];

        $customSerialLinesRaw = isset($data['custom_serial_lines']) ? trim((string) $data['custom_serial_lines']) : '';
        $split = $customSerialLinesRaw === '' ? []
            : preg_split("/\r\n|\r|\n/", $customSerialLinesRaw, -1, PREG_SPLIT_NO_EMPTY);
        $customSerialNumbers = $customSerialLinesRaw === ''
            ? []
            : array_values(array_filter(
                array_map(static fn ($line): string => trim((string) $line), is_array($split) ? $split : []),
                static fn (string $line): bool => $line !== ''
            ));

        $serializedAddMode = (isset($data['serialized_add_mode']) && $data['serialized_add_mode'] === 'custom')
            ? 'custom'
            : 'auto';

        $manualStockAdjustmentService->applyAdjustment(
            $user,
            $product,
            $locationId,
            $absoluteTarget,
            $data['reason'] ?? null,
            $serialIds,
            $relativeDelta,
            $serializedAddMode,
            $customSerialNumbers,
        );

        return redirect()
            ->route('inventory.stock-levels.adjust', [
                'product_id' => $product->id,
                'location_id' => $locationId,
            ])
            ->with('status', 'Stock updated for '.$product->sku.' at this branch.');
    }

    public function sync(Request $request, InventoryBalanceService $inventoryBalanceService): RedirectResponse
    {
        $user = $request->user();
        $accessibleIds = $user->accessibleLocationIds();
        if ($accessibleIds === []) {
            abort(403);
        }

        $pairs = StockLedger::query()
            ->whereIn('location_id', $accessibleIds)
            ->select(['product_id', 'location_id'])
            ->groupBy('product_id', 'location_id')
            ->get();

        $count = 0;
        foreach ($pairs as $pair) {
            $inventoryBalanceService->recalculate((int) $pair->product_id, (int) $pair->location_id);
            $count++;
        }

        return redirect()
            ->route('inventory.stock-levels.index')
            ->with('status', $count.' product/branch balance rows were recalculated from the stock ledger.');
    }
}
