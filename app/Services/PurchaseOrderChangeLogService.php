<?php

namespace App\Services;

use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderChangeLog;
use Illuminate\Support\Collection;

class PurchaseOrderChangeLogService
{
    /**
     * @return array<string, mixed>
     */
    public function snapshot(PurchaseOrder $purchaseOrder): array
    {
        $purchaseOrder->loadMissing(['supplier', 'destinationLocation', 'lines.product']);

        return [
            'supplier_name' => $purchaseOrder->supplier?->name ?? (string) $purchaseOrder->supplier_id,
            'destination_label' => $purchaseOrder->destinationLocation
                ? $purchaseOrder->destinationLocation->name
                : (string) $purchaseOrder->destination_location_id,
            'ordered_at' => optional($purchaseOrder->ordered_at)->toDateString(),
            'expected_at' => optional($purchaseOrder->expected_at)->toDateString(),
            'notes' => (string) ($purchaseOrder->notes ?? ''),
            'subtotal_amount' => round((float) $purchaseOrder->subtotal_amount, 2),
            'discount_amount' => round((float) $purchaseOrder->discount_amount, 2),
            'tax_amount' => round((float) $purchaseOrder->tax_amount, 2),
            'additional_cost_amount' => round((float) $purchaseOrder->additional_cost_amount, 2),
            'additional_costs_digest' => $this->digestAdditionalCosts($purchaseOrder->additional_costs ?? []),
            'total_amount' => round((float) $purchaseOrder->total_amount, 2),
            'lines' => $purchaseOrder->lines->sortBy('id')->values()->map(fn ($line) => [
                'id' => $line->id,
                'product_label' => trim(($line->product?->sku ?? '').' — '.($line->product?->name ?? '#'.$line->product_id)),
                'qty_ordered' => (int) $line->qty_ordered,
                'qty_received' => (int) $line->qty_received,
                'unit_cost' => round((float) $line->unit_cost, 2),
                'line_total' => round((float) $line->line_total, 2),
            ])->all(),
        ];
    }

    /**
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     * @return array{attributes: list<array{label: string, before: string, after: string}>, lines: list<string>}
     */
    public function diff(array $before, array $after): array
    {
        $attributes = [];

        $labels = [
            'supplier_name' => 'Supplier',
            'destination_label' => 'Destination',
            'ordered_at' => 'Ordered date',
            'expected_at' => 'Expected date',
            'notes' => 'Notes',
            'subtotal_amount' => 'Subtotal',
            'discount_amount' => 'Discount',
            'tax_amount' => 'Tax',
            'additional_cost_amount' => 'Additional costs',
            'additional_costs_digest' => 'Additional cost lines',
            'total_amount' => 'Grand total',
        ];

        foreach ($labels as $key => $label) {
            $b = $this->stringifySnapshotValue($before[$key] ?? null);
            $a = $this->stringifySnapshotValue($after[$key] ?? null);
            if ($b !== $a) {
                $attributes[] = ['label' => $label, 'before' => $b, 'after' => $a];
            }
        }

        /** @var Collection<int, array<string, mixed>> $beforeById */
        $beforeById = collect($before['lines'] ?? [])->keyBy('id');
        /** @var Collection<int, array<string, mixed>> $afterById */
        $afterById = collect($after['lines'] ?? [])->keyBy('id');

        $lineMessages = [];

        foreach ($afterById as $id => $ln) {
            if (! $beforeById->has($id)) {
                $lineMessages[] = 'Added line: '.($ln['product_label'] ?? '')
                    .' × '.($ln['qty_ordered'] ?? 0)
                    .' @ Tk '.number_format((float) ($ln['unit_cost'] ?? 0), 2)
                    .' (line total Tk '.number_format((float) ($ln['line_total'] ?? 0), 2).')';
            }
        }

        foreach ($beforeById as $id => $ln) {
            if (! $afterById->has($id)) {
                $lineMessages[] = 'Removed line: '.($ln['product_label'] ?? '')
                    .' (ordered '.($ln['qty_ordered'] ?? 0).', received '.($ln['qty_received'] ?? 0).')';
            }
        }

        foreach ($beforeById as $id => $beforeLine) {
            if (! $afterById->has($id)) {
                continue;
            }
            $a = $afterById->get($id);
            $parts = [];
            if (($beforeLine['product_label'] ?? '') !== ($a['product_label'] ?? '')) {
                $parts[] = 'product: '.($beforeLine['product_label'] ?? '').' → '.($a['product_label'] ?? '');
            }
            if ((int) ($beforeLine['qty_ordered'] ?? 0) !== (int) ($a['qty_ordered'] ?? 0)) {
                $parts[] = 'ordered qty: '.($beforeLine['qty_ordered'] ?? 0).' → '.($a['qty_ordered'] ?? 0);
            }
            if (abs((float) ($beforeLine['unit_cost'] ?? 0) - (float) ($a['unit_cost'] ?? 0)) > 0.001) {
                $parts[] = 'unit cost: Tk '.number_format((float) ($beforeLine['unit_cost'] ?? 0), 2)
                    .' → Tk '.number_format((float) ($a['unit_cost'] ?? 0), 2);
            }
            if (abs((float) ($beforeLine['line_total'] ?? 0) - (float) ($a['line_total'] ?? 0)) > 0.001) {
                $parts[] = 'line total: Tk '.number_format((float) ($beforeLine['line_total'] ?? 0), 2)
                    .' → Tk '.number_format((float) ($a['line_total'] ?? 0), 2);
            }
            if ((int) ($beforeLine['qty_received'] ?? 0) !== (int) ($a['qty_received'] ?? 0)) {
                $parts[] = 'qty received: '.($beforeLine['qty_received'] ?? 0).' → '.($a['qty_received'] ?? 0);
            }
            if ($parts !== []) {
                $lineMessages[] = ($a['product_label'] ?? 'Line #'.$id).': '.implode('; ', $parts);
            }
        }

        return ['attributes' => $attributes, 'lines' => $lineMessages];
    }

    /**
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     */
    public function recordUpdateIfChanged(PurchaseOrder $purchaseOrder, array $before, array $after, ?int $userId): void
    {
        $diff = $this->diff($before, $after);
        if ($diff['attributes'] === [] && $diff['lines'] === []) {
            return;
        }

        PurchaseOrderChangeLog::create([
            'purchase_order_id' => $purchaseOrder->id,
            'user_id' => $userId,
            'event' => 'updated',
            'changes' => $diff,
        ]);
    }

    public function recordApproved(PurchaseOrder $purchaseOrder, ?int $userId): void
    {
        PurchaseOrderChangeLog::create([
            'purchase_order_id' => $purchaseOrder->id,
            'user_id' => $userId,
            'event' => 'approved',
            'changes' => [
                'attributes' => [
                    ['label' => 'Status', 'before' => 'Draft', 'after' => 'Approved'],
                ],
                'lines' => [],
            ],
        ]);
    }

    private function stringifySnapshotValue(mixed $value): string
    {
        if ($value === null) {
            return '';
        }
        if (is_float($value) || is_int($value)) {
            return number_format((float) $value, 2, '.', '');
        }

        return trim((string) $value);
    }

    private function digestAdditionalCosts(mixed $rows): string
    {
        if (! is_array($rows) || $rows === []) {
            return '';
        }

        $normalized = collect($rows)
            ->filter(fn ($row) => is_array($row))
            ->sortBy(fn (array $row): string => (string) (($row['posting_id'] ?? '').'|'.($row['label'] ?? '').'|'.($row['paid_at'] ?? '')))
            ->values()
            ->all();

        return hash('sha256', json_encode($normalized) ?: '');
    }
}
