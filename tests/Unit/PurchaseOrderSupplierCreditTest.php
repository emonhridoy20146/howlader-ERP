<?php

namespace Tests\Unit;

use App\Models\Location;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderPayment;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PurchaseOrderSupplierCreditTest extends TestCase
{
    use RefreshDatabase;

    public function test_supplier_credit_when_paid_exceeds_grand_total(): void
    {
        [$po, $user] = $this->makePurchaseOrder(300);

        PurchaseOrderPayment::query()->create([
            'purchase_order_id' => $po->id,
            'wallet_id' => null,
            'amount' => 500,
            'settlement_discount' => 0,
            'paid_at' => now()->toDateString(),
            'reference' => null,
            'notes' => null,
            'created_by' => $user->id,
        ]);

        $po->refresh();

        $this->assertSame(0.0, $po->amountDue());
        $this->assertSame(200.0, $po->supplierCreditBalance());
    }

    public function test_amount_due_zero_when_settlement_discount_fully_covers_remaining(): void
    {
        [$po, $user] = $this->makePurchaseOrder(1000);

        PurchaseOrderPayment::query()->create([
            'purchase_order_id' => $po->id,
            'wallet_id' => null,
            'amount' => 900,
            'settlement_discount' => 100,
            'paid_at' => now()->toDateString(),
            'reference' => null,
            'notes' => null,
            'created_by' => $user->id,
        ]);

        $po->refresh();

        $this->assertSame(0.0, $po->amountDue());
        $this->assertSame(0.0, $po->supplierCreditBalance());
    }

    public function test_negative_refund_reduces_paid_and_clears_supplier_credit(): void
    {
        [$po, $user] = $this->makePurchaseOrder(300);

        PurchaseOrderPayment::query()->create([
            'purchase_order_id' => $po->id,
            'wallet_id' => null,
            'amount' => 500,
            'settlement_discount' => 0,
            'paid_at' => now()->toDateString(),
            'reference' => null,
            'notes' => null,
            'created_by' => $user->id,
        ]);

        $refund = PurchaseOrderPayment::query()->create([
            'purchase_order_id' => $po->id,
            'wallet_id' => null,
            'amount' => -200,
            'settlement_discount' => 0,
            'paid_at' => now()->toDateString(),
            'reference' => 'RF-1',
            'notes' => 'Excess refund',
            'created_by' => $user->id,
        ]);

        $po->refresh();
        $po->unsetRelation('payments');

        $this->assertTrue($refund->isRefund());
        $this->assertSame(300.0, $po->supplierPaymentsTotal());
        $this->assertSame(0.0, $po->amountDue());
        $this->assertSame(0.0, $po->supplierCreditBalance());
    }

    /**
     * @return array{0: PurchaseOrder, 1: User}
     */
    private function makePurchaseOrder(float $totalAmount): array
    {
        $user = User::factory()->create(['role' => 'admin', 'is_active' => true]);
        $supplier = Supplier::query()->create([
            'name' => 'Credit test supplier',
            'contact_person' => null,
            'phone' => null,
            'email' => null,
            'address' => null,
            'tax_number' => null,
            'outstanding_balance' => 0,
            'is_active' => true,
        ]);
        $location = Location::query()->create([
            'code' => 'PO-CR',
            'name' => 'Credit test branch',
            'type' => 'branch',
            'address' => null,
            'phone' => null,
            'manager_name' => null,
            'is_active' => true,
            'dizicashier_uuid' => null,
        ]);

        return [
            PurchaseOrder::query()->create([
            'po_number' => 'PO-CREDIT-'.uniqid(),
            'supplier_id' => $supplier->id,
            'destination_location_id' => $location->id,
            'status' => 'approved',
            'ordered_at' => now()->toDateString(),
            'expected_at' => null,
            'notes' => null,
            'subtotal_amount' => $totalAmount,
            'discount_amount' => 0,
            'tax_amount' => 0,
            'additional_cost_amount' => 0,
            'additional_costs' => [],
            'total_amount' => $totalAmount,
            'created_by' => $user->id,
            'approved_by' => $user->id,
            'approved_at' => now(),
            ]),
            $user,
        ];
    }
}
