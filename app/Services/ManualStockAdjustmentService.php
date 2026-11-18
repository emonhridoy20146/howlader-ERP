<?php

namespace App\Services;

use App\Models\ManualStockAdjustment;
use App\Models\Product;
use App\Models\SerialItem;
use App\Models\StockLedger;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ManualStockAdjustmentService
{
    public function __construct(
        private readonly InventoryBalanceService $inventoryBalanceService,
        private readonly DocumentNumberGenerator $documentNumbers,
    ) {}

    /**
     * Apply a manual correction so on-hand qty at the location matches the computed target.
     * Use {@see $relativeDelta} to change stock by N (positive adds, negative removes), or
     * {@see $absoluteTargetOnHand} for an explicit count.
     *
     * Serialized products: decreases require exactly that many removable serial IDs.
     * Increases create new serial rows (auto-generated codes or custom numbers you provide), except when
     * on-hand is negative: the portion that only brings the balance up toward zero is posted as a bulk
     * adjust_in on the ledger without creating serial rows; any further increase still creates serials.
     *
     * @param  list<int>  $serialIdsToRemove
     * @param  list<string>  $customSerialNumbers  One value per unit when adding with custom mode.
     */
    public function applyAdjustment(
        User $actor,
        Product $product,
        int $locationId,
        ?int $absoluteTargetOnHand,
        ?string $reason,
        array $serialIdsToRemove,
        ?int $relativeDelta = null,
        string $serializedAddMode = 'auto',
        array $customSerialNumbers = [],
    ): ManualStockAdjustment {
        if ($relativeDelta !== null && $absoluteTargetOnHand !== null) {
            throw ValidationException::withMessages([
                'adjust_mode' => 'Send either a target on-hand quantity or a relative change, not both.',
            ]);
        }
        if ($relativeDelta === null && $absoluteTargetOnHand === null) {
            throw ValidationException::withMessages([
                'target_on_hand_qty' => 'Provide a target on-hand quantity or a change amount.',
            ]);
        }

        if ($absoluteTargetOnHand !== null && $absoluteTargetOnHand < 0) {
            throw ValidationException::withMessages(['target_on_hand_qty' => 'On-hand quantity cannot be negative.']);
        }

        if (! empty($reason) && mb_strlen(trim($reason)) > 2000) {
            throw ValidationException::withMessages(['reason' => 'Reason is too long.']);
        }

        return DB::transaction(function () use ($actor, $product, $locationId, $absoluteTargetOnHand, $reason, $serialIdsToRemove, $relativeDelta, $serializedAddMode, $customSerialNumbers): ManualStockAdjustment {
            $this->inventoryBalanceService->recalculate($product->id, $locationId);
            $row = DB::table('inventory_balances')
                ->where('product_id', $product->id)
                ->where('location_id', $locationId)
                ->lockForUpdate()
                ->first();

            $before = (int) ($row->on_hand_qty ?? 0);
            $reserved = (int) ($row->reserved_qty ?? 0);

            if ($relativeDelta !== null) {
                $targetOnHandQty = max(0, $before + (int) $relativeDelta);
            } else {
                $targetOnHandQty = (int) $absoluteTargetOnHand;
            }

            if ($targetOnHandQty < $reserved) {
                throw ValidationException::withMessages([
                    'target_on_hand_qty' => 'On-hand cannot be below reserved quantity ('.$reserved.') at this branch.',
                ]);
            }

            $delta = $targetOnHandQty - $before;

            if ($delta === 0) {
                if (
                    $product->is_serialized
                    && $relativeDelta !== null
                    && $relativeDelta < 0
                    && $serialIdsToRemove !== []
                ) {
                    return $this->applySerializedRemoval(
                        $actor,
                        $product,
                        $locationId,
                        $before,
                        $targetOnHandQty,
                        $delta,
                        $reason,
                        $serialIdsToRemove,
                        abs((int) $relativeDelta),
                    );
                }

                throw ValidationException::withMessages(
                    $relativeDelta !== null
                        ? ['qty_delta' => 'That change equals current on-hand.']
                        : ['target_on_hand_qty' => 'Requested quantity equals current on-hand.']
                );
            }

            $product->refresh();

            if ($product->is_serialized && $delta > 0) {
                if ($before < 0) {
                    $fillNegative = min($delta, -$before);
                    $remainder = $delta - $fillNegative;

                    $firstAdjustment = null;
                    if ($fillNegative > 0) {
                        $afterFill = $before + $fillNegative;
                        $firstAdjustment = $this->persistAdjustmentMeta(
                            $actor,
                            $product->id,
                            $locationId,
                            $before,
                            $afterFill,
                            $fillNegative,
                            $reason,
                        );
                        StockLedger::create([
                            'event_time' => now(),
                            'product_id' => $product->id,
                            'serial_item_id' => null,
                            'location_id' => $locationId,
                            'movement_type' => 'adjust_in',
                            'quantity_delta' => $fillNegative,
                            'unit_cost' => (float) ($product->default_purchase_price ?? 0),
                            'reference_type' => ManualStockAdjustment::class,
                            'reference_id' => $firstAdjustment->id,
                            'actor_user_id' => $actor->id,
                        ]);
                        $this->inventoryBalanceService->recalculate($product->id, $locationId);
                    }

                    if ($remainder > 0) {
                        $this->inventoryBalanceService->recalculate($product->id, $locationId);
                        $row2 = DB::table('inventory_balances')
                            ->where('product_id', $product->id)
                            ->where('location_id', $locationId)
                            ->lockForUpdate()
                            ->first();
                        $before2 = (int) ($row2->on_hand_qty ?? 0);
                        $target2 = $before2 + $remainder;
                        $mode = $serializedAddMode === 'custom' ? 'custom' : 'auto';

                        return $this->applySerializedAddition(
                            $actor,
                            $product,
                            $locationId,
                            $before2,
                            $target2,
                            $remainder,
                            $reason,
                            $mode,
                            $customSerialNumbers,
                        );
                    }

                    assert($firstAdjustment instanceof ManualStockAdjustment);

                    return $firstAdjustment->refresh();
                }

                $mode = $serializedAddMode === 'custom' ? 'custom' : 'auto';

                return $this->applySerializedAddition(
                    $actor,
                    $product,
                    $locationId,
                    $before,
                    $targetOnHandQty,
                    $delta,
                    $reason,
                    $mode,
                    $customSerialNumbers,
                );
            }

            if ($delta < 0) {
                if ($product->is_serialized) {
                    $requestedSerialCount = $relativeDelta !== null
                        ? abs((int) $relativeDelta)
                        : abs($delta);

                    return $this->applySerializedRemoval(
                        $actor,
                        $product,
                        $locationId,
                        $before,
                        $targetOnHandQty,
                        $delta,
                        $reason,
                        $serialIdsToRemove,
                        $requestedSerialCount,
                    );
                }

                $adjustment = $this->persistAdjustmentMeta(
                    $actor,
                    $product->id,
                    $locationId,
                    $before,
                    $targetOnHandQty,
                    $delta,
                    $reason,
                );

                StockLedger::create([
                    'event_time' => now(),
                    'product_id' => $product->id,
                    'serial_item_id' => null,
                    'location_id' => $locationId,
                    'movement_type' => 'adjust_out',
                    'quantity_delta' => $delta,
                    'unit_cost' => null,
                    'reference_type' => ManualStockAdjustment::class,
                    'reference_id' => $adjustment->id,
                    'actor_user_id' => $actor->id,
                ]);

                $this->inventoryBalanceService->recalculate($product->id, $locationId);

                return $adjustment->refresh();
            }

            $adjustment = $this->persistAdjustmentMeta(
                $actor,
                $product->id,
                $locationId,
                $before,
                $targetOnHandQty,
                $delta,
                $reason,
            );

            StockLedger::create([
                'event_time' => now(),
                'product_id' => $product->id,
                'serial_item_id' => null,
                'location_id' => $locationId,
                'movement_type' => 'adjust_in',
                'quantity_delta' => $delta,
                'unit_cost' => (float) ($product->default_purchase_price ?? 0),
                'reference_type' => ManualStockAdjustment::class,
                'reference_id' => $adjustment->id,
                'actor_user_id' => $actor->id,
            ]);

            $this->inventoryBalanceService->recalculate($product->id, $locationId);

            return $adjustment->refresh();
        });
    }

    /**
     * @param  list<string>  $customSerialNumbers
     */
    private function applySerializedAddition(
        User $actor,
        Product $product,
        int $locationId,
        int $before,
        int $targetOnHandQty,
        int $delta,
        ?string $reason,
        string $mode,
        array $customSerialNumbers,
    ): ManualStockAdjustment {
        $addCount = $delta;
        $purchaseCost = (float) ($product->default_purchase_price ?? 0);

        if ($mode === 'custom') {
            $trimmed = array_values(array_unique(array_filter(
                array_map(static fn ($s): string => trim((string) $s), $customSerialNumbers),
                static fn (string $s): bool => $s !== ''
            )));
            if (count($trimmed) !== $addCount) {
                throw ValidationException::withMessages([
                    'custom_serial_lines' => 'When adding '.$addCount.' serialized unit(s), enter exactly '.$addCount.' serial number(s) (non-empty lines).',
                ]);
            }
            $existingCount = SerialItem::query()->whereIn('serial_number', $trimmed)->count();
            if ($existingCount > 0) {
                throw ValidationException::withMessages([
                    'custom_serial_lines' => 'One or more serial numbers already exist in the system.',
                ]);
            }
            $serialNumbersToUse = $trimmed;
        } else {
            $serialNumbersToUse = [];
            for ($i = 0; $i < $addCount; $i++) {
                $serialNumbersToUse[] = $this->nextInternalSerialCode();
            }
        }

        $adjustment = $this->persistAdjustmentMeta(
            $actor,
            $product->id,
            $locationId,
            $before,
            $targetOnHandQty,
            $delta,
            $reason,
        );

        foreach ($serialNumbersToUse as $serialNumber) {
            $serial = SerialItem::query()->create([
                'product_id' => $product->id,
                'serial_number' => $serialNumber,
                'manufacturer_serial' => null,
                'serial_source' => 'internal',
                'current_location_id' => $locationId,
                'status' => 'in_stock',
                'goods_receipt_line_id' => null,
                'sale_invoice_line_id' => null,
                'purchase_cost' => $purchaseCost,
            ]);

            StockLedger::query()->create([
                'event_time' => now(),
                'product_id' => $product->id,
                'serial_item_id' => $serial->id,
                'location_id' => $locationId,
                'movement_type' => 'adjust_in',
                'quantity_delta' => 1,
                'unit_cost' => $purchaseCost,
                'reference_type' => ManualStockAdjustment::class,
                'reference_id' => $adjustment->id,
                'actor_user_id' => $actor->id,
            ]);
        }

        $this->inventoryBalanceService->recalculate($product->id, $locationId);

        return $adjustment->refresh();
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

        throw new \RuntimeException('Unable to generate a unique internal serial code. Please retry.');
    }

    /**
     * @param  list<int>  $serialIdsToRemove
     */
    private function applySerializedRemoval(
        User $actor,
        Product $product,
        int $locationId,
        int $before,
        int $targetOnHandQty,
        int $delta,
        ?string $reason,
        array $serialIdsToRemove,
        int $requestedSerialCount,
    ): ManualStockAdjustment {
        $ids = array_values(array_unique(array_map(static fn ($id): int => (int) $id, $serialIdsToRemove)));
        if (count($ids) !== $requestedSerialCount) {
            throw ValidationException::withMessages([
                'serial_ids' => 'Removing '.$requestedSerialCount.' unit(s): select exactly '.$requestedSerialCount.' serial number(s).',
            ]);
        }

        sort($ids, SORT_NUMERIC);

        $ledgerOutCount = $delta < 0 ? abs($delta) : 0;
        $ledgerlessCount = $requestedSerialCount - $ledgerOutCount;
        if ($ledgerlessCount < 0) {
            throw ValidationException::withMessages([
                'serial_ids' => 'Removal count is inconsistent with selected serials.',
            ]);
        }

        $serials = SerialItem::query()
            ->where('product_id', $product->id)
            ->whereIn('id', $ids)
            ->lockForUpdate()
            ->get()
            ->keyBy(static fn (SerialItem $s): int => (int) $s->id);

        if ($serials->count() !== count($ids)) {
            throw ValidationException::withMessages(['serial_ids' => 'One or more serial IDs are invalid.']);
        }

        foreach ($ids as $id) {
            $serial = $serials->get($id);
            if ($serial === null) {
                throw ValidationException::withMessages(['serial_ids' => 'One or more serial IDs are invalid.']);
            }
            if ((int) $serial->current_location_id !== $locationId) {
                throw ValidationException::withMessages([
                    'serial_ids' => 'Serial '.$serial->serial_number.' is not at this branch.',
                ]);
            }
            if ($serial->status !== 'in_stock') {
                throw ValidationException::withMessages([
                    'serial_ids' => 'Serial '.$serial->serial_number.' must be in stock (not reserved) to remove.',
                ]);
            }
        }

        $reasonSuffix = $ledgerlessCount > 0
            ? ' ['.$ledgerlessCount.' unit(s) cleared from serial records only; on-hand was already at this target.]'
            : '';
        $trimmed = $reason !== null ? trim($reason) : '';
        $fullReason = $trimmed === '' ? trim($reasonSuffix) : $trimmed.$reasonSuffix;
        if ($fullReason === '') {
            $fullReason = null;
        }

        $adjustment = $this->persistAdjustmentMeta(
            $actor,
            $product->id,
            $locationId,
            $before,
            $targetOnHandQty,
            $delta,
            $fullReason,
        );

        $forLedgerIds = array_slice($ids, 0, $ledgerOutCount);
        $forLedgerlessIds = array_slice($ids, $ledgerOutCount);

        foreach ($forLedgerIds as $id) {
            $serial = $serials->get($id);
            if ($serial === null) {
                continue;
            }
            StockLedger::create([
                'event_time' => now(),
                'product_id' => $product->id,
                'serial_item_id' => $serial->id,
                'location_id' => $locationId,
                'movement_type' => 'damage_out',
                'quantity_delta' => -1,
                'unit_cost' => $serial->purchase_cost,
                'reference_type' => ManualStockAdjustment::class,
                'reference_id' => $adjustment->id,
                'actor_user_id' => $actor->id,
            ]);
            $serial->update([
                'status' => 'damaged',
                'current_location_id' => null,
            ]);
        }

        foreach ($forLedgerlessIds as $id) {
            $serial = $serials->get($id);
            if ($serial === null) {
                continue;
            }
            $serial->update([
                'status' => 'damaged',
                'current_location_id' => null,
            ]);
        }

        $this->inventoryBalanceService->recalculate($product->id, $locationId);

        return $adjustment->refresh();
    }

    private function persistAdjustmentMeta(
        User $actor,
        int $productId,
        int $locationId,
        int $before,
        int $after,
        int $delta,
        ?string $reason,
    ): ManualStockAdjustment {
        return ManualStockAdjustment::create([
            'product_id' => $productId,
            'location_id' => $locationId,
            'qty_before' => $before,
            'qty_after' => $after,
            'qty_delta' => $delta,
            'reason' => $reason !== null ? trim($reason) : null,
            'actor_user_id' => $actor->id,
        ]);
    }
}
