<?php

namespace App\Services;

use App\Models\Location;
use App\Models\Product;
use App\Models\SerialItem;
use App\Models\User;
use App\Support\LedgerBackedInventory;
use App\Support\SerialLabelCode;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class StockCheckService
{
    /**
     * @return array<string, mixed>
     */
    public function branchChecklist(User $user, int $branchId): array
    {
        $accessibleIds = $user->accessibleLocationIds();
        if (! in_array($branchId, $accessibleIds, true)) {
            return ['error' => 'You do not have access to this branch.'];
        }

        $branch = Location::query()->whereKey($branchId)->first(['id', 'code', 'name']);
        if ($branch === null) {
            return ['error' => 'Branch not found.'];
        }

        $serials = SerialItem::query()
            ->with(['product:id,sku,name,model_number,barcode,is_serialized'])
            ->where('current_location_id', $branchId)
            ->whereIn('status', ['in_stock', 'reserved'])
            ->orderBy('product_id')
            ->orderBy('serial_number')
            ->get()
            ->map(fn (SerialItem $serial): array => $this->checklistSerialRow($serial))
            ->values()
            ->all();

        $bulkProducts = DB::query()
            ->fromSub(LedgerBackedInventory::balancesSubquery(), 'ib')
            ->join('products as p', 'p.id', '=', 'ib.product_id')
            ->where('ib.location_id', $branchId)
            ->where('p.is_serialized', false)
            ->where('p.is_active', true)
            ->where('ib.available_qty', '>', 0)
            ->orderBy('p.sku')
            ->get([
                'p.id as product_id',
                'p.sku',
                'p.name',
                'p.model_number',
                'p.barcode',
                'ib.on_hand_qty',
                'ib.reserved_qty',
                'ib.available_qty',
            ])
            ->map(fn ($row): array => [
                'kind' => 'product',
                'product_id' => (int) $row->product_id,
                'sku' => (string) $row->sku,
                'name' => (string) $row->name,
                'model_number' => $row->model_number,
                'barcode' => $row->barcode,
                'expected_qty' => (int) $row->available_qty,
                'on_hand_qty' => (int) $row->on_hand_qty,
                'reserved_qty' => (int) $row->reserved_qty,
                'checked_qty' => 0,
            ])
            ->values()
            ->all();

        $expectedUnits = count($serials) + (int) collect($bulkProducts)->sum('expected_qty');

        return [
            'branch' => [
                'id' => $branch->id,
                'code' => $branch->code,
                'name' => $branch->name,
            ],
            'serials' => $serials,
            'products' => $bulkProducts,
            'summary' => [
                'serial_count' => count($serials),
                'product_lines' => count($bulkProducts),
                'expected_units' => $expectedUnits,
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function sessionScan(
        string $rawCode,
        User $user,
        int $branchId,
        array $checkedSerialIds,
        array $productCheckedQty,
    ): array {
        $code = trim($rawCode);
        if ($code === '') {
            return ['error' => 'Scan or enter a code.'];
        }

        $accessibleIds = $user->accessibleLocationIds();
        if (! in_array($branchId, $accessibleIds, true)) {
            return ['error' => 'You do not have access to this branch.'];
        }

        $checkedSerialSet = collect($checkedSerialIds)->map(fn ($id) => (int) $id)->filter(fn ($id) => $id > 0)->flip();
        $productQtyMap = collect($productCheckedQty)
            ->mapWithKeys(fn ($row) => [(int) ($row['product_id'] ?? 0) => (int) ($row['checked_qty'] ?? 0)]);

        $serials = $this->findSerialsByCode($code);
        if ($serials->count() > 1) {
            return ['error' => 'Multiple units matched this code.'];
        }

        if ($serials->count() === 1) {
            $serial = $serials->first();
            $serial->loadMissing(['product', 'location']);

            if ($checkedSerialSet->has((int) $serial->id)) {
                return [
                    'result' => 'duplicate',
                    'message' => 'Already checked: '.($serial->label_code ? SerialLabelCode::format((int) $serial->label_code) : $serial->serial_number),
                    'serial_id' => (int) $serial->id,
                ];
            }

            if ((int) $serial->current_location_id === $branchId
                && in_array($serial->status, ['in_stock', 'reserved'], true)) {
                return [
                    'result' => 'serial',
                    'message' => 'Checked: '.($serial->product?->name ?? 'Unit'),
                    'serial_id' => (int) $serial->id,
                    'row' => $this->checklistSerialRow($serial),
                ];
            }

            return [
                'result' => 'extra',
                'message' => 'Extra unit — not expected in stock at this branch.',
                'extra' => $this->extraSerialPayload($serial, $branchId),
            ];
        }

        $product = Product::query()
            ->where('is_active', true)
            ->where('is_serialized', false)
            ->where('barcode', $code)
            ->first();

        if ($product instanceof Product) {
            $expected = (int) DB::query()
                ->fromSub(LedgerBackedInventory::balancesSubquery(), 'ib')
                ->where('ib.product_id', $product->id)
                ->where('ib.location_id', $branchId)
                ->value('ib.available_qty');

            if ($expected <= 0) {
                return [
                    'result' => 'extra',
                    'message' => 'Product barcode matched but no available stock at this branch.',
                    'extra' => [
                        'kind' => 'product',
                        'product_id' => $product->id,
                        'sku' => $product->sku,
                        'name' => $product->name,
                        'barcode' => $product->barcode,
                    ],
                ];
            }

            $current = (int) ($productQtyMap->get((int) $product->id) ?? 0);
            if ($current >= $expected) {
                return [
                    'result' => 'duplicate',
                    'message' => 'All '.$expected.' units already checked for '.$product->sku,
                    'product_id' => (int) $product->id,
                ];
            }

            return [
                'result' => 'product',
                'message' => 'Checked '.$product->sku.' ('.($current + 1).' / '.$expected.')',
                'product_id' => (int) $product->id,
                'checked_qty' => $current + 1,
                'expected_qty' => $expected,
            ];
        }

        return ['error' => 'No product or serial matched this scan.'];
    }

    /**
     * @param  list<int>  $checkedSerialIds
     * @param  list<array{product_id: int, checked_qty: int}>  $productCheckedQty
     * @return array<string, mixed>
     */
    public function buildReport(
        User $user,
        int $branchId,
        array $checkedSerialIds,
        array $productCheckedQty,
        array $extraItems,
    ): array {
        $checklist = $this->branchChecklist($user, $branchId);
        if (isset($checklist['error'])) {
            return $checklist;
        }

        $checkedSerialSet = collect($checkedSerialIds)->map(fn ($id) => (int) $id)->unique()->filter(fn ($id) => $id > 0);
        $productQtyMap = collect($productCheckedQty)
            ->mapWithKeys(fn ($row) => [(int) ($row['product_id'] ?? 0) => max(0, (int) ($row['checked_qty'] ?? 0))]);

        $matchedSerials = [];
        $missingSerials = [];
        foreach ($checklist['serials'] as $row) {
            if ($checkedSerialSet->contains((int) $row['serial_id'])) {
                $matchedSerials[] = $row;
            } else {
                $missingSerials[] = $row;
            }
        }

        $matchedProducts = [];
        $missingProducts = [];
        foreach ($checklist['products'] as $row) {
            $expected = (int) $row['expected_qty'];
            $checked = min($expected, (int) ($productQtyMap->get((int) $row['product_id']) ?? 0));
            $line = array_merge($row, [
                'checked_qty' => $checked,
                'missing_qty' => max(0, $expected - $checked),
                'is_complete' => $checked >= $expected,
            ]);

            if ($checked > 0) {
                $matchedProducts[] = $line;
            }
            if ($checked < $expected) {
                $missingProducts[] = $line;
            }
        }

        $expectedUnits = (int) $checklist['summary']['expected_units'];
        $checkedSerialCount = count($matchedSerials);
        $checkedProductUnits = (int) collect($matchedProducts)->sum('checked_qty');
        $checkedUnits = $checkedSerialCount + $checkedProductUnits;
        $missingUnits = max(0, $expectedUnits - $checkedUnits);

        return [
            'generated_at' => now()->toIso8601String(),
            'branch' => $checklist['branch'],
            'summary' => [
                'expected_units' => $expectedUnits,
                'checked_units' => $checkedUnits,
                'missing_units' => $missingUnits,
                'extra_count' => count($extraItems),
                'serial_expected' => count($checklist['serials']),
                'serial_checked' => $checkedSerialCount,
                'serial_missing' => count($missingSerials),
                'product_lines' => count($checklist['products']),
            ],
            'matched_serials' => $matchedSerials,
            'missing_serials' => $missingSerials,
            'matched_products' => $matchedProducts,
            'missing_products' => $missingProducts,
            'extras' => $extraItems,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function lookup(string $rawCode, User $user, ?int $branchId = null): array
    {
        $code = trim($rawCode);
        if ($code === '') {
            return ['error' => 'Scan or enter a label code, serial, or product barcode.'];
        }

        $accessibleIds = $user->accessibleLocationIds();
        if ($accessibleIds === []) {
            return ['error' => 'You do not have access to any branch.'];
        }

        if ($branchId !== null && ! in_array($branchId, $accessibleIds, true)) {
            return ['error' => 'You do not have access to the selected branch.'];
        }

        $serials = $this->findSerialsByCode($code);
        if ($serials->count() > 1) {
            return ['error' => 'Multiple units matched this code. Open Serial registry and search manually.'];
        }

        if ($serials->count() === 1) {
            return $this->formatSerialResult($serials->first(), $accessibleIds);
        }

        $product = Product::query()
            ->where('is_active', true)
            ->where('barcode', $code)
            ->first();

        if ($product instanceof Product) {
            return $this->formatProductResult($product, $accessibleIds, $branchId);
        }

        return ['error' => 'No product or serial matched this scan.'];
    }

    /**
     * @return Collection<int, SerialItem>
     */
    private function findSerialsByCode(string $code): Collection
    {
        $labelCode = SerialLabelCode::parseScanCode($code);

        return SerialItem::query()
            ->with(['product:id,sku,name,model_number,barcode,is_serialized,is_active', 'location:id,code,name'])
            ->where(function ($query) use ($code, $labelCode): void {
                $query->where('serial_number', $code)
                    ->orWhere('manufacturer_serial', $code);

                if ($labelCode !== null) {
                    $query->orWhere('label_code', $labelCode);
                }
            })
            ->orderBy('id')
            ->limit(5)
            ->get();
    }

    /**
     * @return array<string, mixed>
     */
    private function checklistSerialRow(SerialItem $serial): array
    {
        $serial->loadMissing('product');

        return [
            'kind' => 'serial',
            'serial_id' => (int) $serial->id,
            'label_code' => $serial->label_code !== null
                ? SerialLabelCode::format((int) $serial->label_code)
                : null,
            'serial_number' => (string) $serial->serial_number,
            'manufacturer_serial' => $serial->manufacturer_serial,
            'status' => (string) $serial->status,
            'status_label' => $this->serialStatusLabel((string) $serial->status),
            'product_id' => (int) $serial->product_id,
            'sku' => (string) ($serial->product?->sku ?? ''),
            'name' => (string) ($serial->product?->name ?? ''),
            'model_number' => $serial->product?->model_number,
            'checked' => false,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function extraSerialPayload(SerialItem $serial, int $branchId): array
    {
        $serial->loadMissing(['product', 'location']);

        return [
            'kind' => 'serial',
            'serial_id' => (int) $serial->id,
            'label_code' => $serial->label_code !== null
                ? SerialLabelCode::format((int) $serial->label_code)
                : null,
            'serial_number' => (string) $serial->serial_number,
            'sku' => (string) ($serial->product?->sku ?? ''),
            'name' => (string) ($serial->product?->name ?? ''),
            'status' => (string) $serial->status,
            'status_label' => $this->serialStatusLabel((string) $serial->status),
            'location' => $serial->location ? [
                'id' => $serial->location->id,
                'code' => $serial->location->code,
                'name' => $serial->location->name,
            ] : null,
            'expected_at_branch_id' => $branchId,
        ];
    }

    /**
     * @param  list<int>  $accessibleIds
     * @return array<string, mixed>
     */
    private function formatSerialResult(SerialItem $serial, array $accessibleIds): array
    {
        $serial->loadMissing(['product', 'location']);
        $product = $serial->product;
        if (! $product instanceof Product) {
            return ['error' => 'Serial is not linked to a product.'];
        }

        $locationId = (int) ($serial->current_location_id ?? 0);
        $balances = $this->balancesForProduct((int) $product->id, $accessibleIds);
        $atLocation = $locationId > 0
            ? $balances->firstWhere('location_id', $locationId)
            : null;

        return [
            'match_type' => 'serial',
            'scanned_code' => $this->displayScanCode($serial),
            'product' => $this->productPayload($product),
            'serial' => [
                'id' => $serial->id,
                'serial_number' => $serial->serial_number,
                'label_code' => $serial->label_code !== null
                    ? SerialLabelCode::format((int) $serial->label_code)
                    : null,
                'manufacturer_serial' => $serial->manufacturer_serial,
                'status' => $serial->status,
                'status_label' => $this->serialStatusLabel((string) $serial->status),
                'show_url' => route('serial-items.show', $serial),
            ],
            'location' => $serial->location ? [
                'id' => $serial->location->id,
                'code' => $serial->location->code,
                'name' => $serial->location->name,
            ] : null,
            'balance_at_unit_branch' => $atLocation ? [
                'on_hand_qty' => (int) $atLocation->on_hand_qty,
                'reserved_qty' => (int) $atLocation->reserved_qty,
                'available_qty' => (int) $atLocation->available_qty,
            ] : null,
            'balances' => $balances->values()->all(),
        ];
    }

    /**
     * @param  list<int>  $accessibleIds
     * @return array<string, mixed>
     */
    private function formatProductResult(Product $product, array $accessibleIds, ?int $branchId): array
    {
        $balances = $this->balancesForProduct((int) $product->id, $accessibleIds);

        if ($branchId !== null) {
            $balances = $balances->filter(fn ($row) => (int) $row->location_id === $branchId)->values();
        }

        $selected = $branchId !== null ? $balances->first() : null;

        return [
            'match_type' => 'product',
            'scanned_code' => (string) ($product->barcode ?? ''),
            'product' => $this->productPayload($product),
            'balance_at_branch' => $selected ? [
                'location_id' => (int) $selected->location_id,
                'location_name' => (string) $selected->location_name,
                'location_code' => (string) $selected->location_code,
                'on_hand_qty' => (int) $selected->on_hand_qty,
                'reserved_qty' => (int) $selected->reserved_qty,
                'available_qty' => (int) $selected->available_qty,
            ] : [
                'location_id' => $branchId,
                'on_hand_qty' => 0,
                'reserved_qty' => 0,
                'available_qty' => 0,
            ],
            'balances' => $balances->values()->all(),
        ];
    }

    /**
     * @param  list<int>  $accessibleIds
     * @return Collection<int, object>
     */
    private function balancesForProduct(int $productId, array $accessibleIds): Collection
    {
        return DB::query()
            ->fromSub(LedgerBackedInventory::balancesSubquery(), 'ib')
            ->join('locations as l', 'l.id', '=', 'ib.location_id')
            ->where('ib.product_id', $productId)
            ->whereIn('ib.location_id', $accessibleIds)
            ->orderBy('l.name')
            ->get([
                'ib.product_id',
                'ib.location_id',
                'ib.on_hand_qty',
                'ib.reserved_qty',
                'ib.available_qty',
                'l.code as location_code',
                'l.name as location_name',
            ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function productPayload(Product $product): array
    {
        return [
            'id' => $product->id,
            'sku' => $product->sku,
            'name' => $product->name,
            'model_number' => $product->model_number,
            'barcode' => $product->barcode,
            'is_serialized' => (bool) $product->is_serialized,
            'show_url' => route('products.show', $product),
        ];
    }

    private function displayScanCode(SerialItem $serial): string
    {
        if ($serial->label_code !== null && (int) $serial->label_code > 0) {
            return SerialLabelCode::format((int) $serial->label_code);
        }

        return (string) $serial->serial_number;
    }

    private function serialStatusLabel(string $status): string
    {
        return match ($status) {
            'in_stock' => 'In stock',
            'reserved' => 'Reserved',
            'sold' => 'Sold',
            'damaged' => 'Damaged',
            'in_transit' => 'In transit',
            default => ucfirst(str_replace('_', ' ', $status)),
        };
    }
}
