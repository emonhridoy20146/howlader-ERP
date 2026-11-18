<?php

namespace Tests\Unit;

use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderLine;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class PurchaseOrderLandedCostTest extends TestCase
{
    #[Test]
    public function it_allocates_grand_total_by_line_total_share(): void
    {
        $po = new PurchaseOrder([
            'subtotal_amount' => 100,
            'total_amount' => 110,
        ]);

        $l1 = new PurchaseOrderLine([
            'line_total' => 40,
            'qty_ordered' => 2,
            'qty_received' => 1,
        ]);
        $l1->id = 1;

        $l2 = new PurchaseOrderLine([
            'line_total' => 60,
            'qty_ordered' => 3,
            'qty_received' => 0,
        ]);
        $l2->id = 2;

        $po->setRelation('lines', collect([$l1, $l2]));

        $alloc = $po->landedLineAllocations();

        $this->assertCount(2, $alloc);
        $this->assertEqualsWithDelta(44.0, $alloc[1]['landed_line_total'], 0.001);
        $this->assertEqualsWithDelta(66.0, $alloc[2]['landed_line_total'], 0.001);
        $this->assertEqualsWithDelta(22.0, $alloc[1]['landed_unit_cost'], 0.0001);
        $this->assertEqualsWithDelta(22.0, $alloc[2]['landed_unit_cost'], 0.0001);
        $this->assertEqualsWithDelta(22.0, $alloc[1]['landed_received_total'], 0.001);

        $sumLanded = round(array_sum(array_column($alloc, 'landed_line_total')), 2);
        $this->assertSame(110.0, $sumLanded);
    }

    #[Test]
    public function it_preserves_total_cents_across_rounding(): void
    {
        $po = new PurchaseOrder([
            'subtotal_amount' => 3,
            'total_amount' => 10.01,
        ]);

        $lines = collect();
        foreach ([1, 2, 3] as $id) {
            $l = new PurchaseOrderLine([
                'line_total' => 1,
                'qty_ordered' => 1,
                'qty_received' => 0,
            ]);
            $l->id = $id;
            $lines->push($l);
        }
        $po->setRelation('lines', $lines);

        $alloc = $po->landedLineAllocations();
        $sumCents = (int) round(array_sum(array_column($alloc, 'landed_line_total')) * 100);
        $this->assertSame(1001, $sumCents);
    }

    #[Test]
    public function it_splits_evenly_when_no_line_value_denominator(): void
    {
        $po = new PurchaseOrder([
            'subtotal_amount' => 0,
            'total_amount' => 30,
        ]);

        $lines = collect();
        foreach ([10, 20, 30] as $id) {
            $l = new PurchaseOrderLine([
                'line_total' => 0,
                'qty_ordered' => 1,
                'qty_received' => 0,
            ]);
            $l->id = $id;
            $lines->push($l);
        }
        $po->setRelation('lines', $lines);

        $alloc = $po->landedLineAllocations();
        $this->assertSame(10.0, $alloc[10]['landed_line_total']);
        $this->assertSame(10.0, $alloc[20]['landed_line_total']);
        $this->assertSame(10.0, $alloc[30]['landed_line_total']);
    }
}
