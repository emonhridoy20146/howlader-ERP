<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Location;
use App\Models\SalesInvoice;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class RecalculateCustomerOutstandingCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_command_sets_outstanding_from_invoice_amounts_due(): void
    {
        Cache::forget('reports.customer_dues.last_recalc');

        $location = Location::create([
            'code' => 'LOC-REC',
            'name' => 'Recalc branch',
            'type' => 'branch',
            'address' => 'Test',
            'is_active' => true,
        ]);

        $user = User::factory()->create([
            'role' => 'admin',
            'is_active' => true,
            'branch_id' => $location->id,
        ]);

        $customer = Customer::create([
            'customer_code' => 'C-REC',
            'name' => 'Recalc Customer',
            'phone' => '01701000001',
            'is_active' => true,
            'outstanding_balance' => 999.99,
        ]);

        SalesInvoice::create([
            'invoice_number' => 'INV-REC-1',
            'invoice_date' => now()->toDateString(),
            'branch_id' => $location->id,
            'customer_id' => $customer->id,
            'sale_type' => 'credit',
            'status' => 'posted',
            'subtotal' => 100,
            'payment_status' => 'unpaid',
            'discount_total' => 0,
            'tax_total' => 0,
            'grand_total' => 100,
            'amount_paid' => 0,
            'amount_due' => 100,
            'created_by' => $user->id,
        ]);

        $this->artisan('customers:recalculate-outstanding')->assertSuccessful();

        $customer->refresh();
        $this->assertEquals(100.0, (float) $customer->outstanding_balance);

        $summary = Cache::get('reports.customer_dues.last_recalc');
        $this->assertIsArray($summary);
        $this->assertArrayHasKey('ran_at', $summary);
        $this->assertEquals(1, (int) $summary['customers_adjusted']);
        $this->assertEquals(1, (int) $summary['customers_with_dues']);
    }

    public function test_dry_run_leaves_balances_and_cache_unchanged(): void
    {
        Cache::forget('reports.customer_dues.last_recalc');

        $location = Location::create([
            'code' => 'LOC-DRY',
            'name' => 'Dry branch',
            'type' => 'branch',
            'address' => 'Test',
            'is_active' => true,
        ]);

        $user = User::factory()->create([
            'role' => 'admin',
            'is_active' => true,
            'branch_id' => $location->id,
        ]);

        $customer = Customer::create([
            'customer_code' => 'C-DRY',
            'name' => 'Dry Customer',
            'phone' => '01701000002',
            'is_active' => true,
            'outstanding_balance' => 500,
        ]);

        SalesInvoice::create([
            'invoice_number' => 'INV-DRY-1',
            'invoice_date' => now()->toDateString(),
            'branch_id' => $location->id,
            'customer_id' => $customer->id,
            'sale_type' => 'credit',
            'status' => 'posted',
            'subtotal' => 100,
            'payment_status' => 'unpaid',
            'discount_total' => 0,
            'tax_total' => 0,
            'grand_total' => 100,
            'amount_paid' => 0,
            'amount_due' => 100,
            'created_by' => $user->id,
        ]);

        $this->artisan('customers:recalculate-outstanding', ['--dry-run' => true])->assertSuccessful();

        $customer->refresh();
        $this->assertEquals(500.0, (float) $customer->outstanding_balance);
        $this->assertNull(Cache::get('reports.customer_dues.last_recalc'));
    }
}
