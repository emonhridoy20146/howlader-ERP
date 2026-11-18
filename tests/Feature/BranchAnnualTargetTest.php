<?php

namespace Tests\Feature;

use App\Models\BranchAnnualTarget;
use App\Models\Customer;
use App\Models\Location;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Models\Permission;
use App\Models\Role;
use App\Models\SalesInvoice;
use App\Models\User;
use App\Services\BranchAnnualTargetProgressService;
use App\Services\PermissionSyncService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BranchAnnualTargetTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(PermissionSyncService::class)->syncFromPolicies();
    }

    public function test_progress_calculates_yearly_sales_and_collections(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-01'));

        $branch = Location::create([
            'code' => 'BR-TGT',
            'name' => 'Target Branch',
            'type' => 'branch',
            'is_active' => true,
        ]);

        BranchAnnualTarget::create([
            'location_id' => $branch->id,
            'year' => 2026,
            'sales_target' => 10000,
            'collection_target' => 8000,
        ]);

        $user = User::factory()->create([
            'role' => 'admin',
            'is_active' => true,
        ]);

        $customer = Customer::create([
            'customer_code' => 'C-TGT',
            'name' => 'Target Customer',
            'phone' => '01700000001',
            'is_active' => true,
        ]);

        SalesInvoice::create([
            'invoice_number' => 'INV-TGT-1',
            'branch_id' => $branch->id,
            'customer_id' => $customer->id,
            'invoice_date' => '2026-03-15',
            'sale_type' => 'cash',
            'status' => 'posted',
            'payment_status' => 'partial',
            'subtotal' => 5000,
            'discount_total' => 0,
            'tax_total' => 0,
            'grand_total' => 5000,
            'amount_paid' => 2000,
            'amount_due' => 3000,
            'created_by' => $user->id,
        ]);

        $payment = Payment::create([
            'payment_number' => 'PAY-TGT-1',
            'customer_id' => $customer->id,
            'payment_date' => '2026-04-10',
            'payment_method' => 'cash',
            'amount' => 2000,
            'direction' => 'inbound',
            'recorded_by' => $user->id,
        ]);

        $invoice = SalesInvoice::first();
        PaymentAllocation::create([
            'payment_id' => $payment->id,
            'sales_invoice_id' => $invoice->id,
            'allocated_amount' => 2000,
            'settlement_discount' => 0,
        ]);

        $progress = app(BranchAnnualTargetProgressService::class)->forLocation($branch, 2026);

        $this->assertSame(5000.0, $progress['sales']['actual']);
        $this->assertSame(50.0, $progress['sales']['pct']);
        $this->assertSame(2000.0, $progress['collection']['actual']);
        $this->assertSame(25.0, $progress['collection']['pct']);
    }

    public function test_authorized_user_can_store_annual_targets(): void
    {
        $branch = Location::create([
            'code' => 'BR-ADM',
            'name' => 'Admin Branch',
            'type' => 'branch',
            'is_active' => true,
        ]);

        $user = User::factory()->create([
            'role' => 'admin',
            'is_active' => true,
        ]);

        $response = $this->actingAs($user)->post(route('locations.annual-targets.store', $branch), [
            'year' => 2026,
            'sales_target' => 120000,
            'collection_target' => 90000,
            'notes' => 'FY target',
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('branch_annual_targets', [
            'location_id' => $branch->id,
            'year' => 2026,
            'sales_target' => 120000,
            'collection_target' => 90000,
        ]);
    }

    public function test_non_admin_without_permission_cannot_store_targets(): void
    {
        $branch = Location::create([
            'code' => 'BR-NO',
            'name' => 'No Perm Branch',
            'type' => 'branch',
            'is_active' => true,
        ]);

        $perm = Permission::query()->where('slug', 'location.view')->first();
        $this->assertNotNull($perm);

        $role = Role::query()->create([
            'slug' => 'locations-only-target-test',
            'name' => 'Locations only',
        ]);
        $role->permissions()->sync([(int) $perm->id]);

        $user = User::factory()->create([
            'role' => 'sales',
            'is_active' => true,
            'branch_id' => $branch->id,
        ]);
        $user->roles()->sync([$role->id]);
        $user->accessibleLocations()->sync([$branch->id]);

        $this->actingAs($user)->post(route('locations.annual-targets.store', $branch), [
            'year' => 2026,
            'sales_target' => 1000,
            'collection_target' => 1000,
        ])->assertForbidden();
    }
}
