<?php

namespace App\Services;

use App\Models\InventoryBalance;
use App\Models\Product;
use App\Models\SerialItem;
use App\Models\StockLedger;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Aligns serialized {@see SerialItem} rows at each branch with ledger-based on-hand:
 * creates missing rows when units are outstanding, or removes surplus in_stock-only
 * rows when the ledger shows fewer units (e.g. after orphan cleanup or correcting GRN rollback).
 *
 * Candidates are serialized product × location pairs from ledger/balances plus any branch that
 * still holds serial inventory (needed to detect surplus when only serial rows linger).
 *
 * Won't delete reserved units or rows tied to an active/non-cancelled stock transfer assignment.
 */
final class SerialOnHandBackfillService
{
    public function __construct(
        private readonly InventoryBalanceService $inventoryBalanceService,
        private readonly DocumentNumberGenerator $documentNumbers,
    ) {}

    /**
     * @return array{created: int, removed: int, skipped_pairs: int, trim_blocked_reserved: int}
     */
    public function run(bool $dryRun, ?int $onlyProductId, ?int $onlyLocationId, bool $recalculateFirst): array
    {
        $created = 0;
        $removed = 0;
        $skippedPairs = 0;
        $trimBlockedReserved = 0;

        foreach ($this->eachReconciliationPair(true, $onlyProductId, $onlyLocationId) as $pair) {
            $productId = $pair['product_id'];
            $locationId = $pair['location_id'];

            if ($recalculateFirst) {
                $this->inventoryBalanceService->recalculate($productId, $locationId);
            }

            $onHand = $this->effectiveOnHandQty($productId, $locationId);

            $inStockQty = (int) SerialItem::query()
                ->where('product_id', $productId)
                ->where('current_location_id', $locationId)
                ->where('status', 'in_stock')
                ->count();

            $reservedQty = (int) SerialItem::query()
                ->where('product_id', $productId)
                ->where('current_location_id', $locationId)
                ->where('status', 'reserved')
                ->count();

            $accounted = $inStockQty + $reservedQty;

            $product = Product::query()->find($productId);
            if (! $product || ! $product->is_serialized) {
                $skippedPairs++;

                continue;
            }

            $deficit = $onHand - $accounted;
            $surplus = $accounted - $onHand;

            $pairTouched = false;

            if ($deficit > 0) {
                $pairTouched = true;
                if ($dryRun) {
                    $created += $deficit;
                } else {
                    DB::transaction(function () use ($product, $locationId, $deficit, &$created): void {
                        for ($i = 0; $i < $deficit; $i++) {
                            [$serialNumber, $mfg] = $this->allocateSerialNumbers($product, $locationId, $i + 1);
                            SerialItem::query()->create([
                                'product_id' => $product->id,
                                'serial_number' => $serialNumber,
                                'manufacturer_serial' => $mfg,
                                'serial_source' => 'internal',
                                'current_location_id' => $locationId,
                                'status' => 'in_stock',
                                'goods_receipt_line_id' => null,
                                'sale_invoice_line_id' => null,
                                'purchase_cost' => (float) ($product->default_purchase_price ?? 0),
                            ]);
                            $created++;
                        }
                    });
                }

                $this->inventoryBalanceService->recalculate($productId, $locationId);
            } elseif ($surplus > 0) {
                $removableQty = min($surplus, $inStockQty);
                if ($removableQty < $surplus) {
                    $trimBlockedReserved += $surplus - $removableQty;
                }
                if ($removableQty <= 0) {
                    // Reserved rows hold surplus → cannot auto-delete.
                    $skippedPairs++;
                } else {
                    $pairTouched = true;
                    if ($dryRun) {
                        $removed += $removableQty;
                    } else {
                        $idsToDelete = $this->idsOfRemovableInStockSurplus($productId, $locationId, $removableQty)->all();

                        DB::transaction(function () use ($idsToDelete, &$removed): void {
                            $n = SerialItem::query()->whereKey($idsToDelete)->delete();
                            $removed += (int) $n;
                        });
                    }

                    $this->inventoryBalanceService->recalculate($productId, $locationId);
                }
            }

            if ($pairTouched) {
                continue;
            }

            $skippedPairs++;
        }

        return [
            'created' => $created,
            'removed' => $removed,
            'skipped_pairs' => $skippedPairs,
            'trim_blocked_reserved' => $trimBlockedReserved,
        ];
    }

    /**
     * Sum of deficits for non-serialized products still flagged that way (used by migrate dry-run).
     *
     * @return int approximate serial rows migrate would later need after flipping is_serialized
     */
    public function estimateDeficitNonSerializedProducts(bool $recalculateFirst): int
    {
        $total = 0;

        foreach ($this->eachReconciliationPair(false, null, null) as $pair) {
            $productId = $pair['product_id'];
            $locationId = $pair['location_id'];

            if ($recalculateFirst) {
                $this->inventoryBalanceService->recalculate($productId, $locationId);
            }

            $onHand = $this->effectiveOnHandQty($productId, $locationId);

            if ($onHand <= 0) {
                continue;
            }

            $accounted = (int) SerialItem::query()
                ->where('product_id', $productId)
                ->where('current_location_id', $locationId)
                ->whereIn('status', ['in_stock', 'reserved'])
                ->count();

            $deficit = $onHand - $accounted;
            if ($deficit > 0) {
                $total += $deficit;
            }
        }

        return $total;
    }

    /**
     * Stored on-hand agrees with ledger for positive quantities; fallback to balances when ledger
     * has no movements (fixtures / seeded balances only).
     */
    private function effectiveOnHandQty(int $productId, int $locationId): int
    {
        $fromLedger = $this->ledgerNetQty($productId, $locationId);
        if ($fromLedger > 0) {
            return $fromLedger;
        }

        return (int) (InventoryBalance::query()
            ->where('product_id', $productId)
            ->where('location_id', $locationId)
            ->value('on_hand_qty') ?? 0);
    }

    private function ledgerNetQty(int $productId, int $locationId): int
    {
        $raw = (int) StockLedger::query()
            ->where('product_id', $productId)
            ->where('location_id', $locationId)
            ->sum('quantity_delta');

        return max(0, $raw);
    }

    /**
     * Serialized product × location that should be aligned (balances, ledger, or existing serials at branch).
     *
     * @return \Generator<int, array{product_id: int, location_id: int}>
     */
    private function eachReconciliationPair(bool $filterSerialized, ?int $onlyProductId, ?int $onlyLocationId): \Generator
    {
        yield from $this->eachDistinctPairMerged(
            $this->candidatePairsFromBalancesAndLedger($filterSerialized, $onlyProductId, $onlyLocationId),
            $this->candidatePairsFromSerialPresence($filterSerialized, $onlyProductId, $onlyLocationId),
        );
    }

    /**
     * @param  iterable<int, array{product_id: int, location_id: int}>  $sources
     * @return \Generator<int, array{product_id: int, location_id: int}>
     */
    private function eachDistinctPairMerged(iterable ...$sources): \Generator
    {
        $seen = [];
        foreach ($sources as $source) {
            foreach ($source as $pair) {
                $pid = $pair['product_id'];
                $lid = $pair['location_id'];
                $key = $pid.'|'.$lid;
                if (isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;
                yield ['product_id' => $pid, 'location_id' => $lid];
            }
        }
    }

    /**
     * @return iterable<int, array{product_id: int, location_id: int}>
     */
    private function candidatePairsFromSerialPresence(bool $filterSerialized, ?int $onlyProductId, ?int $onlyLocationId): iterable
    {
        $rows = SerialItem::query()
            ->from('serial_items')
            ->join('products', 'products.id', '=', 'serial_items.product_id')
            ->where('products.is_serialized', $filterSerialized)
            ->when($onlyProductId !== null, fn ($q) => $q->where('serial_items.product_id', $onlyProductId))
            ->when($onlyLocationId !== null, fn ($q) => $q->where('serial_items.current_location_id', $onlyLocationId))
            ->whereNotNull('serial_items.current_location_id')
            ->whereIn('serial_items.status', ['in_stock', 'reserved'])
            ->distinct()
            ->select(['serial_items.product_id', 'serial_items.current_location_id as location_id']);

        foreach ($rows->cursor() as $row) {
            yield ['product_id' => (int) $row->product_id, 'location_id' => (int) $row->location_id];
        }
    }

    /**
     * @return iterable<int, array{product_id: int, location_id: int}>
     */
    private function candidatePairsFromBalancesAndLedger(bool $filterSerialized, ?int $onlyProductId, ?int $onlyLocationId): iterable
    {
        $seen = [];

        $markAndYield = function (int $productId, int $locationId) use (&$seen, $onlyProductId, $onlyLocationId): ?array {
            if ($onlyProductId !== null && $productId !== $onlyProductId) {
                return null;
            }
            if ($onlyLocationId !== null && $locationId !== $onlyLocationId) {
                return null;
            }
            $key = $productId.'|'.$locationId;
            if (isset($seen[$key])) {
                return null;
            }
            $seen[$key] = true;

            return ['product_id' => $productId, 'location_id' => $locationId];
        };

        $balanceQuery = InventoryBalance::query()
            ->join('products', 'products.id', '=', 'inventory_balances.product_id')
            ->where('products.is_serialized', $filterSerialized)
            ->where('inventory_balances.on_hand_qty', '>', 0)
            ->select([
                'inventory_balances.product_id',
                'inventory_balances.location_id',
            ]);

        foreach ($balanceQuery->cursor() as $row) {
            $yielded = $markAndYield((int) $row->product_id, (int) $row->location_id);
            if ($yielded !== null) {
                yield $yielded;
            }
        }

        $ledgerQuery = DB::table('stock_ledger')
            ->join('products', 'products.id', '=', 'stock_ledger.product_id')
            ->where('products.is_serialized', $filterSerialized)
            ->when($onlyProductId !== null, fn ($q) => $q->where('stock_ledger.product_id', $onlyProductId))
            ->when($onlyLocationId !== null, fn ($q) => $q->where('stock_ledger.location_id', $onlyLocationId))
            ->selectRaw('stock_ledger.product_id, stock_ledger.location_id, SUM(stock_ledger.quantity_delta) as qty_sum')
            ->groupBy('stock_ledger.product_id', 'stock_ledger.location_id')
            ->havingRaw('SUM(stock_ledger.quantity_delta) > 0');

        foreach ($ledgerQuery->cursor() as $row) {
            $yielded = $markAndYield((int) $row->product_id, (int) $row->location_id);
            if ($yielded !== null) {
                yield $yielded;
            }
        }
    }

    /**
     * @return Collection<int, int>
     */
    private function idsOfRemovableInStockSurplus(int $productId, int $locationId, int $limit): Collection
    {
        if ($limit <= 0) {
            return collect();
        }

        return SerialItem::query()
            ->where('product_id', $productId)
            ->where('current_location_id', $locationId)
            ->where('status', 'in_stock')
            ->whereNull('sale_invoice_line_id')
            ->whereNotExists(function ($query): void {
                $query->selectRaw('1')
                    ->from('stock_transfer_serials')
                    ->whereColumn('stock_transfer_serials.serial_item_id', 'serial_items.id')
                    ->whereNotIn('stock_transfer_serials.status', ['cancelled']);
            })
            ->orderByDesc('id')
            ->limit($limit)
            ->pluck('id');
    }

    /**
     * @return array{0: string, 1: string|null} serial_number, manufacturer_serial
     */
    private function allocateSerialNumbers(Product $product, int $locationId, int $unitIndex): array
    {
        $barcode = trim((string) ($product->barcode ?? ''));
        $mfg = $barcode !== ''
            ? Str::limit($barcode.'#'.$unitIndex, 100, '')
            : null;

        for ($attempt = 0; $attempt < 80; $attempt++) {
            $serialNumber = $this->buildCandidateSerial($product, $locationId, $unitIndex + $attempt);
            $serialNumber = Str::limit($serialNumber, 100, '');
            if (! SerialItem::query()->where('serial_number', $serialNumber)->exists()) {
                return [$serialNumber, $mfg];
            }
        }

        throw new \RuntimeException('Unable to allocate unique serial for product '.$product->id.' at location '.$locationId);
    }

    private function buildCandidateSerial(Product $product, int $locationId, int $unitIndex): string
    {
        $barcode = trim((string) ($product->barcode ?? ''));
        if ($barcode !== '') {
            $slug = Str::upper(preg_replace('/[^A-Za-z0-9]+/', '-', $barcode) ?? '');
            $slug = trim((string) $slug, '-');
            $slug = $slug !== '' ? Str::limit($slug, 32, '') : 'BC';

            return $slug.'-L'.$locationId.'-U'.str_pad((string) $unitIndex, 4, '0', STR_PAD_LEFT);
        }

        $sku = trim((string) $product->sku);
        if ($sku !== '') {
            $slug = Str::upper(preg_replace('/[^A-Za-z0-9]+/', '-', $sku) ?? '');
            $slug = trim((string) $slug, '-');
            $slug = $slug !== '' ? Str::limit($slug, 32, '') : 'SKU';

            return $slug.'-L'.$locationId.'-U'.str_pad((string) $unitIndex, 4, '0', STR_PAD_LEFT);
        }

        return $this->documentNumbers->next(
            DocumentNumberGenerator::TYPE_SERIAL_BACKFILL,
            'AST',
            now(),
            'Ymd',
            6,
        );
    }
}
