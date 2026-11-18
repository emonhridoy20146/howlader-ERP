<?php

namespace App\Services;

use App\Models\Product;
use App\Support\LedgerBackedInventory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class ProductBulkUpdateService
{
    /** @var list<string> */
    private array $importErrors = [];

    private int $importUpdated = 0;

    private int $importSkipped = 0;

    /**
     * @return array{q: string, brand_id: string, category_id: string, location_id: string, list: string, stock: string}
     */
    public function filtersFromRequest(Request $request): array
    {
        $list = (string) $request->input('list', 'active');
        if (! in_array($list, ['active', 'inactive'], true)) {
            $list = 'active';
        }

        return [
            'q' => trim((string) $request->input('q', '')),
            'brand_id' => (string) $request->input('brand_id', ''),
            'category_id' => (string) $request->input('category_id', ''),
            'location_id' => (string) $request->input('location_id', ''),
            'list' => $list,
            'stock' => (string) $request->input('stock', ''),
        ];
    }

    /**
     * @param  array{q?: string, brand_id?: string, category_id?: string, location_id?: string, list?: string, stock?: string}  $filters
     */
    public function queryFromFilters(array $filters, bool $allProducts = false): Builder
    {
        $list = $filters['list'] ?? 'active';
        if (! in_array($list, ['active', 'inactive'], true)) {
            $list = 'active';
        }

        $query = Product::query()
            ->with(['brand:id,name', 'category:id,name'])
            ->when(! $allProducts && ($filters['q'] ?? '') !== '', function (Builder $query) use ($filters): void {
                $keyword = $filters['q'];
                $query->where(function (Builder $subQuery) use ($keyword): void {
                    $subQuery->where('sku', 'like', "%{$keyword}%")
                        ->orWhere('name', 'like', "%{$keyword}%")
                        ->orWhere('model_number', 'like', "%{$keyword}%")
                        ->orWhere('barcode', 'like', "%{$keyword}%");
                });
            })
            ->when(! $allProducts && ($filters['brand_id'] ?? '') !== '', fn (Builder $q) => $q->where('brand_id', (int) $filters['brand_id']))
            ->when(! $allProducts && ($filters['category_id'] ?? '') !== '', fn (Builder $q) => $q->where('category_id', (int) $filters['category_id']))
            ->where('is_active', $list === 'inactive' ? false : true);

        if ($allProducts) {
            return $query->orderBy('sku');
        }

        $locationId = ($filters['location_id'] ?? '') !== '' ? (int) $filters['location_id'] : null;

        $query->when($locationId !== null, function (Builder $q) use ($locationId): void {
            $balances = LedgerBackedInventory::balancesSubquery();
            $q->whereExists(function ($sub) use ($balances, $locationId): void {
                $sub->fromSub($balances, 'ib')
                    ->selectRaw('1')
                    ->whereColumn('ib.product_id', 'products.id')
                    ->where('ib.location_id', $locationId);
            });
        });

        $stock = $filters['stock'] ?? '';
        $summary = $this->ledgerInventorySummarySubquery($locationId);

        return $query
            ->when($stock === 'in_stock', function (Builder $q) use ($summary): void {
                $q->whereExists(function ($sub) use ($summary): void {
                    $sub->fromSub($summary, 'agg')
                        ->selectRaw('1')
                        ->whereColumn('agg.product_id', 'products.id')
                        ->where('agg.available_qty', '>', 0);
                });
            })
            ->when($stock === 'out_of_stock', function (Builder $q) use ($summary): void {
                $q->whereNotExists(function ($sub) use ($summary): void {
                    $sub->fromSub($summary, 'agg')
                        ->selectRaw('1')
                        ->whereColumn('agg.product_id', 'products.id')
                        ->where('agg.available_qty', '>', 0);
                });
            })
            ->orderBy('sku');
    }

    /**
     * @return Collection<int, Product>
     */
    public function productsForExport(array $filters, bool $allProducts = false): Collection
    {
        return $this->queryFromFilters($filters, $allProducts)->get();
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return array{updated: int, skipped: int, errors: list<string>}
     */
    public function processImportRows(Collection $rows): array
    {
        $this->importErrors = [];
        $this->importUpdated = 0;
        $this->importSkipped = 0;

        $rowNumber = 1;
        foreach ($rows as $row) {
            $rowNumber++;
            if ($row instanceof Collection) {
                $row = $row->toArray();
            }
            if (! is_array($row)) {
                continue;
            }

            $normalized = $this->normalizeImportRow($row);
            if ($this->isImportRowEmpty($normalized)) {
                continue;
            }

            $productId = $this->resolveProductId($normalized);
            if ($productId === null) {
                $this->importErrors[] = "Row {$rowNumber}: product not found (product_id or sku required).";

                continue;
            }

            $product = Product::query()->find($productId);
            if ($product === null) {
                $this->importErrors[] = "Row {$rowNumber}: product #{$productId} does not exist.";

                continue;
            }

            $purchase = $this->priceFromRow($normalized, 'purchase_price', 'default_purchase_price', 'purchase');
            $sale = $this->priceFromRow($normalized, 'sale_price', 'default_sale_price', 'sale');

            if ($purchase === null && $sale === null) {
                $this->importSkipped++;

                continue;
            }

            $changes = [];
            if ($purchase !== null) {
                $changes['default_purchase_price'] = $purchase;
            }
            if ($sale !== null) {
                $changes['default_sale_price'] = $sale;
            }

            $product->update($changes);
            $this->importUpdated++;
        }

        return [
            'updated' => $this->importUpdated,
            'skipped' => $this->importSkipped,
            'errors' => $this->importErrors,
        ];
    }

    /**
     * @param  list<int>  $productIds
     * @return array{updated: int}
     */
    public function applyPricesToProducts(array $productIds, ?float $purchasePrice, ?float $salePrice): array
    {
        $changes = [];
        if ($purchasePrice !== null) {
            $changes['default_purchase_price'] = round($purchasePrice, 2);
        }
        if ($salePrice !== null) {
            $changes['default_sale_price'] = round($salePrice, 2);
        }

        if ($changes === []) {
            return ['updated' => 0];
        }

        $updated = Product::query()
            ->whereIn('id', $productIds)
            ->update($changes);

        return ['updated' => (int) $updated];
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function normalizeImportRow(array $row): array
    {
        $normalized = [];
        foreach ($row as $key => $value) {
            if (! is_string($key)) {
                continue;
            }
            $slug = strtolower(trim(preg_replace('/[^a-z0-9]+/i', '_', $key) ?? '', '_'));
            $normalized[$slug] = is_string($value) ? trim($value) : $value;
        }

        return $normalized;
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function isImportRowEmpty(array $row): bool
    {
        foreach ($row as $value) {
            if ($value !== null && $value !== '') {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function resolveProductId(array $row): ?int
    {
        $rawId = $row['product_id'] ?? $row['id'] ?? null;
        if ($rawId !== null && $rawId !== '') {
            $id = (int) $rawId;

            return $id > 0 ? $id : null;
        }

        $sku = (string) ($row['sku'] ?? '');
        if ($sku === '') {
            return null;
        }

        $product = Product::query()->where('sku', $sku)->first();

        return $product?->id;
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function priceFromRow(array $row, string ...$keys): ?float
    {
        foreach ($keys as $key) {
            if (! array_key_exists($key, $row)) {
                continue;
            }
            $value = $row[$key];
            if ($value === null || $value === '') {
                continue;
            }

            if (! is_numeric($value)) {
                return null;
            }

            return round(max(0, (float) $value), 2);
        }

        return null;
    }

    private function ledgerInventorySummarySubquery(?int $locationId = null): \Illuminate\Database\Query\Builder
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
