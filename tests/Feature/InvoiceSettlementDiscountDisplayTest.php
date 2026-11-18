<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Location;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Models\SalesInvoice;
use App\Models\User;
use App\Services\PermissionSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InvoiceSettlementDiscountDisplayTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(PermissionSyncService::class)->syncFromPolicies();
    }

    public function test_invoice_header_uses_allocation_cash_and_discount_not_amount_paid_gap(): void
    {
        $branch = Location::create([
            'code' => 'BR-DISC',
            'name' => 'Discount Branch',
            'type' => 'branch',
            'is_active' => true,
        ]);

        $user = User::factory()->create([
            'role' => 'admin',
            'is_active' => true,
            'branch_id' => $branch->id,
        ]);

        $customer = Customer::create([
            'customer_code' => 'C-DISC',
            'name' => 'Discount Customer',
            'phone' => '01700000999',
            'is_active' => true,
        ]);

        $invoice = SalesInvoice::create([
            'invoice_number' => 'INV-DISC-1',
            'branch_id' => $branch->id,
            'customer_id' => $customer->id,
            'invoice_date' => '2026-06-04',
            'sale_type' => 'installment',
            'status' => 'active',
            'payment_status' => 'paid',
            'subtotal' => 83390,
            'discount_total' => 0,
            'tax_total' => 0,
            'grand_total' => 83390,
            'amount_paid' => 83390,
            'amount_due' => 0,
            'installment_down_payment' => 40000,
            'installment_count' => 3,
            'created_by' => $user->id,
        ]);

        $advancePayment = Payment::create([
            'payment_number' => 'PAY-ADV',
            'customer_id' => $customer->id,
            'payment_date' => '2026-06-04',
            'payment_method' => 'cash',
            'amount' => 40000,
            'direction' => 'inbound',
            'reference_no' => 'Installment advance payment',
            'recorded_by' => $user->id,
        ]);

        PaymentAllocation::create([
            'payment_id' => $advancePayment->id,
            'sales_invoice_id' => $invoice->id,
            'allocated_amount' => 40000,
            'settlement_discount' => 0,
        ]);

        $periodPayment = Payment::create([
            'payment_number' => 'PAY-PER',
            'customer_id' => $customer->id,
            'payment_date' => '2026-07-04',
            'payment_method' => 'cash',
            'amount' => 36000,
            'direction' => 'inbound',
            'recorded_by' => $user->id,
        ]);

        PaymentAllocation::create([
            'payment_id' => $periodPayment->id,
            'sales_invoice_id' => $invoice->id,
            'allocated_amount' => 36000,
            'settlement_discount' => 7390,
        ]);

        $this->assertSame(76000.0, $invoice->fresh()->totalCashCollected());
        $this->assertSame(7390.0, $invoice->fresh()->totalSettlementDiscountApplied());
        $this->assertSame(83390.0, $invoice->fresh()->totalAppliedFromAllocations());

        $wrongDiscountFromAmountPaidGap = round((float) $invoice->amount_paid - $invoice->totalCashCollected(), 2);
        $this->assertSame(7390.0, $wrongDiscountFromAmountPaidGap);

        $response = $this->actingAs($user)->get(route('sales.invoices.show', $invoice));

        $response->assertOk();
        $response->assertSee('76,000.00', false);
        $response->assertSee('7,390.00', false);
        $response->assertDontSee('14,690.00', false);
        $response->assertSee('Settlement discount', false);
    }

    public function test_invoice_totals_exclude_orphan_payment_allocations(): void
    {
        $branch = Location::create([
            'code' => 'BR-ORPHAN',
            'name' => 'Orphan Branch',
            'type' => 'branch',
            'is_active' => true,
        ]);

        $user = User::factory()->create([
            'role' => 'admin',
            'is_active' => true,
            'branch_id' => $branch->id,
        ]);

        $customer = Customer::create([
            'customer_code' => 'C-ORPHAN',
            'name' => 'Orphan Customer',
            'phone' => '01700001001',
            'is_active' => true,
        ]);

        $invoice = SalesInvoice::create([
            'invoice_number' => 'INV-ORPHAN-1',
            'branch_id' => $branch->id,
            'customer_id' => $customer->id,
            'invoice_date' => '2026-06-04',
            'sale_type' => 'installment',
            'status' => 'active',
            'payment_status' => 'paid',
            'subtotal' => 83390,
            'discount_total' => 0,
            'tax_total' => 0,
            'grand_total' => 83390,
            'amount_paid' => 83390,
            'amount_due' => 0,
            'created_by' => $user->id,
        ]);

        $advancePayment = Payment::create([
            'payment_number' => 'PAY-ORPHAN-ADV',
            'customer_id' => $customer->id,
            'payment_date' => '2026-06-04',
            'payment_method' => 'cash',
            'amount' => 40000,
            'direction' => 'inbound',
            'recorded_by' => $user->id,
        ]);

        PaymentAllocation::create([
            'payment_id' => $advancePayment->id,
            'sales_invoice_id' => $invoice->id,
            'allocated_amount' => 40000,
            'settlement_discount' => 0,
        ]);

        $payment = Payment::create([
            'payment_number' => 'PAY-ORPHAN',
            'customer_id' => $customer->id,
            'payment_date' => '2026-07-04',
            'payment_method' => 'cash',
            'amount' => 36000,
            'direction' => 'inbound',
            'recorded_by' => $user->id,
        ]);

        PaymentAllocation::create([
            'payment_id' => $payment->id,
            'sales_invoice_id' => $invoice->id,
            'allocated_amount' => 36000,
            'settlement_discount' => 7390,
        ]);

        $deletedPayment = Payment::create([
            'payment_number' => 'PAY-ORPHAN-DEL',
            'customer_id' => $customer->id,
            'payment_date' => '2026-07-04',
            'payment_method' => 'cash',
            'amount' => 36000,
            'direction' => 'inbound',
            'recorded_by' => $user->id,
        ]);

        PaymentAllocation::create([
            'payment_id' => $deletedPayment->id,
            'sales_invoice_id' => $invoice->id,
            'allocated_amount' => 36000,
            'settlement_discount' => 7300,
        ]);

        $deletedPayment->delete();

        $this->assertSame(76000.0, $invoice->fresh()->totalCashCollected());
        $this->assertSame(7390.0, $invoice->fresh()->totalSettlementDiscountApplied());

        $response = $this->actingAs($user)->get(route('sales.invoices.show', $invoice));

        $response->assertOk();
        $response->assertSee('76,000.00', false);
        $response->assertSee('7,390.00', false);
        $response->assertDontSee('14,690.00', false);
    }

    public function test_invoice_header_includes_imported_header_collections_not_in_allocations(): void
    {
        $branch = Location::create([
            'code' => 'BR-S-IMP',
            'name' => 'Imported Branch',
            'type' => 'branch',
            'is_active' => true,
        ]);

        $user = User::factory()->create([
            'role' => 'admin',
            'is_active' => true,
            'branch_id' => $branch->id,
        ]);

        $customer = Customer::create([
            'customer_code' => 'C-S-IMP',
            'name' => 'Imported Customer',
            'phone' => '01700001000',
            'is_active' => true,
        ]);

        $invoice = SalesInvoice::create([
            'invoice_number' => 'S-25196',
            'branch_id' => $branch->id,
            'customer_id' => $customer->id,
            'invoice_date' => '2026-06-01',
            'sale_type' => 'credit',
            'status' => 'posted',
            'payment_status' => 'partial',
            'subtotal' => 33500,
            'discount_total' => 0,
            'tax_total' => 0,
            'grand_total' => 33500,
            'amount_paid' => 27000,
            'amount_due' => 6500,
            'created_by' => $user->id,
        ]);

        $payment = Payment::create([
            'payment_number' => 'PAY-S-IMP',
            'customer_id' => $customer->id,
            'payment_date' => '2026-07-02',
            'payment_method' => 'cash',
            'amount' => 1500,
            'direction' => 'inbound',
            'recorded_by' => $user->id,
        ]);

        PaymentAllocation::create([
            'payment_id' => $payment->id,
            'sales_invoice_id' => $invoice->id,
            'allocated_amount' => 1500,
            'settlement_discount' => 0,
        ]);

        $this->assertSame(1500.0, $invoice->fresh()->totalCashCollected());
        $this->assertSame(27000.0, $invoice->fresh()->displayCashCollected());

        $response = $this->actingAs($user)->get(route('sales.invoices.show', $invoice));

        $response->assertOk();
        $response->assertSee('27,000.00', false);
        $response->assertSee('6,500.00', false);
    }
}

