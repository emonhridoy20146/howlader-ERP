<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Location;
use App\Models\SalesInvoice;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardSalesMetricsTest extends TestCase
{
    use RefreshDatabase;

    public function test_dashboard_month_sales_includes_installment_invoices(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-05-20'));

        $branch = Location::create([
            'code' => 'BR-DSH',
            'name' => 'Dashboard Branch',
            'type' => 'branch',
            'is_active' => true,
        ]);

        $user = User::factory()->create([
            'role' => 'admin',
            'is_active' => true,
        ]);

        $customer = Customer::create([
            'customer_code' => 'C-DSH',
            'name' => 'Dashboard Customer',
            'phone' => '01700000099',
            'is_active' => true,
        ]);

        SalesInvoice::create([
            'invoice_number' => 'INV-CASH',
            'branch_id' => $branch->id,
            'customer_id' => $customer->id,
            'invoice_date' => '2026-05-10',
            'sale_type' => 'cash',
            'status' => 'posted',
            'payment_status' => 'paid',
            'subtotal' => 1000,
            'discount_total' => 0,
            'tax_total' => 0,
            'grand_total' => 1000,
            'amount_paid' => 1000,
            'amount_due' => 0,
            'created_by' => $user->id,
        ]);

        SalesInvoice::create([
            'invoice_number' => 'INV-INST',
            'branch_id' => $branch->id,
            'customer_id' => $customer->id,
            'invoice_date' => '2026-05-12',
            'sale_type' => 'installment',
            'status' => 'active',
            'payment_status' => 'partial',
            'subtotal' => 5000,
            'discount_total' => 0,
            'tax_total' => 0,
            'grand_total' => 5000,
            'amount_paid' => 500,
            'amount_due' => 4500,
            'created_by' => $user->id,
        ]);

        $response = $this->actingAs($user)->get(route('dashboard'));

        $response->assertOk();
        $response->assertSee('6,000.00', false);
    }
}
