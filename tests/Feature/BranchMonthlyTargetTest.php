<?php

namespace Tests\Feature;

use App\Models\BranchMonthlyTarget;
use App\Models\Customer;
use App\Models\Location;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Models\SalesInvoice;
use App\Models\User;
use App\Services\BranchMonthlyTargetProgressService;
use App\Services\PermissionSyncService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BranchMonthlyTargetTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(PermissionSyncService::class)->syncFromPolicies();
    }

    public function test_progress_calculates_monthly_sales_and_collections(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-15'));

        $branch = Location::create([
            'code' => 'BR-MTGT',
            'name' => 'Monthly Target Branch',
            'type' => 'branch',
            'is_active' => true,
        ]);

        BranchMonthlyTarget::create([
            'location_id' => $branch->id,
            'year' => 2026,
            'month' => 6,
            'sales_target' => 10000,
            'collection_target' => 8000,
        ]);

        $user = User::factory()->create([
            'role' => 'admin',
            'is_active' => true,
        ]);

        $customer = Customer::create([
            'customer_code' => 'C-MTGT',
            'name' => 'Monthly Target Customer',
            'phone' => '01700000002',
            'is_active' => true,
        ]);

        SalesInvoice::create([
            'invoice_number' => 'INV-MTGT-1',
            'branch_id' => $branch->id,
            'customer_id' => $customer->id,
            'invoice_date' => '2026-06-10',
            'sale_type' => 'cash',
            'status' => 'posted',
            'payment_status' => 'partial',
            'subtotal' => 4000,
            'discount_total' => 0,
            'tax_total' => 0,
            'grand_total' => 4000,
            'amount_paid' => 1500,
            'amount_due' => 2500,
            'created_by' => $user->id,
        ]);

        $payment = Payment::create([
            'payment_number' => 'PAY-MTGT-1',
            'customer_id' => $customer->id,
            'payment_date' => '2026-06-12',
            'payment_method' => 'cash',
            'amount' => 1500,
            'direction' => 'inbound',
            'recorded_by' => $user->id,
        ]);

        $invoice = SalesInvoice::first();
        PaymentAllocation::create([
            'payment_id' => $payment->id,
            'sales_invoice_id' => $invoice->id,
            'allocated_amount' => 1500,
            'settlement_discount' => 0,
        ]);

        $progress = app(BranchMonthlyTargetProgressService::class)->forLocation($branch, 2026);
        $june = $progress['months'][6];

        $this->assertSame(4000.0, $june['sales']['actual']);
        $this->assertSame(40.0, $june['sales']['pct']);
        $this->assertSame(1500.0, $june['collection']['actual']);
        $this->assertSame(18.75, $june['collection']['pct']);
        $this->assertTrue($june['is_current']);
    }

    public function test_admin_can_sync_all_twelve_monthly_targets(): void
    {
        $branch = Location::create([
            'code' => 'BR-MADM',
            'name' => 'Monthly Admin Branch',
            'type' => 'branch',
            'is_active' => true,
        ]);

        $user = User::factory()->create([
            'role' => 'admin',
            'is_active' => true,
        ]);

        $months = [];
        for ($m = 1; $m <= 12; $m++) {
            $months[$m] = [
                'sales_target' => 1000 * $m,
                'collection_target' => 800 * $m,
            ];
        }

        $response = $this->actingAs($user)->post(route('locations.monthly-targets.sync', $branch), [
            'year' => 2026,
            'months' => $months,
        ]);

        $response->assertRedirect();
        $this->assertSame(12, BranchMonthlyTarget::query()->where('location_id', $branch->id)->where('year', 2026)->count());
        $this->assertDatabaseHas('branch_monthly_targets', [
            'location_id' => $branch->id,
            'year' => 2026,
            'month' => 3,
            'sales_target' => 3000,
            'collection_target' => 2400,
        ]);
    }

    public function test_sync_removes_month_when_both_targets_are_zero(): void
    {
        $branch = Location::create([
            'code' => 'BR-MCLR',
            'name' => 'Monthly Clear Branch',
            'type' => 'branch',
            'is_active' => true,
        ]);

        BranchMonthlyTarget::create([
            'location_id' => $branch->id,
            'year' => 2026,
            'month' => 5,
            'sales_target' => 500,
            'collection_target' => 400,
        ]);

        $user = User::factory()->create([
            'role' => 'admin',
            'is_active' => true,
        ]);

        $months = [];
        for ($m = 1; $m <= 12; $m++) {
            $months[$m] = ['sales_target' => '', 'collection_target' => ''];
        }

        $this->actingAs($user)->post(route('locations.monthly-targets.sync', $branch), [
            'year' => 2026,
            'months' => $months,
        ])->assertRedirect();

        $this->assertDatabaseMissing('branch_monthly_targets', [
            'location_id' => $branch->id,
            'year' => 2026,
            'month' => 5,
        ]);
    }
}
