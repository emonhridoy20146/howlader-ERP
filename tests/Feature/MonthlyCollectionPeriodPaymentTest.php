<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Location;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Models\SalesInvoice;
use App\Models\User;
use App\Services\ReportDataService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

class MonthlyCollectionPeriodPaymentTest extends TestCase
{
    use RefreshDatabase;

    public function test_collection_report_shows_payments_in_filtered_period_per_invoice(): void
    {
        $branch = Location::create([
            'code' => 'BR-MCP',
            'name' => 'Collection Period Branch',
            'type' => 'branch',
            'is_active' => true,
        ]);

        $user = User::factory()->create([
            'role' => 'admin',
            'is_active' => true,
            'branch_id' => $branch->id,
        ]);

        $customer = Customer::create([
            'customer_code' => 'C-MCP',
            'name' => 'Collection Period Customer',
            'phone' => '01700004444',
            'is_active' => true,
        ]);

        $invoice = SalesInvoice::create([
            'invoice_number' => 'S-PERIOD-IN',
            'branch_id' => $branch->id,
            'customer_id' => $customer->id,
            'invoice_date' => '2026-03-10',
            'sale_type' => 'default',
            'status' => 'posted',
            'payment_status' => 'partial',
            'subtotal' => 1000,
            'discount_total' => 0,
            'tax_total' => 0,
            'grand_total' => 1000,
            'amount_paid' => 500,
            'amount_due' => 500,
            'created_by' => $user->id,
        ]);

        $priorPayment = Payment::create([
            'payment_number' => 'PAY-MCP-PRIOR',
            'customer_id' => $customer->id,
            'payment_date' => '2026-02-20',
            'payment_method' => 'cash',
            'amount' => 200,
            'direction' => 'inbound',
            'recorded_by' => $user->id,
        ]);

        PaymentAllocation::create([
            'payment_id' => $priorPayment->id,
            'sales_invoice_id' => $invoice->id,
            'allocated_amount' => 200,
            'settlement_discount' => 0,
        ]);

        $inPeriodPayment = Payment::create([
            'payment_number' => 'PAY-MCP-PERIOD',
            'customer_id' => $customer->id,
            'payment_date' => '2026-03-15',
            'payment_method' => 'cash',
            'amount' => 300,
            'direction' => 'inbound',
            'recorded_by' => $user->id,
        ]);

        PaymentAllocation::create([
            'payment_id' => $inPeriodPayment->id,
            'sales_invoice_id' => $invoice->id,
            'allocated_amount' => 300,
            'settlement_discount' => 0,
        ]);

        $this->actingAs($user);

        $request = Request::create('/', 'GET', [
            'date_from' => '2026-03-01',
            'date_to' => '2026-03-31',
        ]);

        $data = app(ReportDataService::class)->monthlyCollectionPaginated($request);

        $this->assertSame(300.0, (float) ($data['periodPaymentsByInvoiceId'][$invoice->id] ?? 0));
    }
}
