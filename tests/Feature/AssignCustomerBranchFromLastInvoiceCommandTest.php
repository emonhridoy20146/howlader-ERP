<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Location;
use App\Models\SalesInvoice;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AssignCustomerBranchFromLastInvoiceCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_command_assigns_customer_branch_from_most_recent_invoice(): void
    {
        [$branchA, $branchB, $user, $customer] = $this->fixture();

        SalesInvoice::create([
            'invoice_number' => 'INV-OLD',
            'invoice_date' => now()->subMonths(2)->toDateString(),
            'branch_id' => $branchA->id,
            'customer_id' => $customer->id,
            'sale_type' => 'cash',
            'status' => 'posted',
            'subtotal' => 100,
            'payment_status' => 'paid',
            'discount_total' => 0,
            'tax_total' => 0,
            'grand_total' => 100,
            'amount_paid' => 100,
            'amount_due' => 0,
            'created_by' => $user->id,
        ]);

        SalesInvoice::create([
            'invoice_number' => 'INV-NEW',
            'invoice_date' => now()->subDay()->toDateString(),
            'branch_id' => $branchB->id,
            'customer_id' => $customer->id,
            'sale_type' => 'cash',
            'status' => 'posted',
            'subtotal' => 200,
            'payment_status' => 'paid',
            'discount_total' => 0,
            'tax_total' => 0,
            'grand_total' => 200,
            'amount_paid' => 200,
            'amount_due' => 0,
            'created_by' => $user->id,
        ]);

        $this->artisan('customers:assign-branch-from-last-invoice')->assertSuccessful();

        $customer->refresh();
        $this->assertSame($branchB->id, $customer->branch_id);
    }

    public function test_dry_run_and_only_unassigned_options(): void
    {
        [$branchA, $branchB, $user, $customer] = $this->fixture();
        $customer->update(['branch_id' => $branchA->id]);

        SalesInvoice::create([
            'invoice_number' => 'INV-LATEST',
            'invoice_date' => now()->toDateString(),
            'branch_id' => $branchB->id,
            'customer_id' => $customer->id,
            'sale_type' => 'cash',
            'status' => 'posted',
            'subtotal' => 100,
            'payment_status' => 'paid',
            'discount_total' => 0,
            'tax_total' => 0,
            'grand_total' => 100,
            'amount_paid' => 100,
            'amount_due' => 0,
            'created_by' => $user->id,
        ]);

        $this->artisan('customers:assign-branch-from-last-invoice', ['--dry-run' => true])->assertSuccessful();
        $this->assertSame($branchA->id, $customer->fresh()->branch_id);

        $customer->update(['branch_id' => null]);

        $this->artisan('customers:assign-branch-from-last-invoice', ['--only-unassigned' => true])->assertSuccessful();
        $this->assertSame($branchB->id, $customer->fresh()->branch_id);

        $this->artisan('customers:assign-branch-from-last-invoice', ['--only-unassigned' => true])->assertSuccessful();
        $this->assertSame($branchB->id, $customer->fresh()->branch_id);
    }

    public function test_uses_invoice_date_tiebreaker_before_id(): void
    {
        [$branchA, $branchB, $user, $customer] = $this->fixture();
        $sameDate = now()->subDay()->toDateString();

        SalesInvoice::create([
            'invoice_number' => 'INV-EARLY-ID',
            'invoice_date' => $sameDate,
            'branch_id' => $branchA->id,
            'customer_id' => $customer->id,
            'sale_type' => 'cash',
            'status' => 'posted',
            'subtotal' => 100,
            'payment_status' => 'paid',
            'discount_total' => 0,
            'tax_total' => 0,
            'grand_total' => 100,
            'amount_paid' => 100,
            'amount_due' => 0,
            'created_by' => $user->id,
        ]);

        SalesInvoice::create([
            'invoice_number' => 'INV-LATER-ID',
            'invoice_date' => $sameDate,
            'branch_id' => $branchB->id,
            'customer_id' => $customer->id,
            'sale_type' => 'cash',
            'status' => 'posted',
            'subtotal' => 150,
            'payment_status' => 'paid',
            'discount_total' => 0,
            'tax_total' => 0,
            'grand_total' => 150,
            'amount_paid' => 150,
            'amount_due' => 0,
            'created_by' => $user->id,
        ]);

        $this->artisan('customers:assign-branch-from-last-invoice')->assertSuccessful();

        $this->assertSame($branchB->id, $customer->fresh()->branch_id);
    }

    /**
     * @return array{0: Location, 1: Location, 2: User, 3: Customer}
     */
    private function fixture(): array
    {
        $branchA = Location::create([
            'code' => 'BR-A',
            'name' => 'Branch Alpha',
            'type' => 'branch',
            'is_active' => true,
        ]);
        $branchB = Location::create([
            'code' => 'BR-B',
            'name' => 'Branch Beta',
            'type' => 'branch',
            'is_active' => true,
        ]);
        $user = User::factory()->create([
            'role' => 'admin',
            'is_active' => true,
            'branch_id' => $branchA->id,
        ]);
        $customer = Customer::create([
            'customer_code' => 'C-BR-1',
            'name' => 'Branch assign customer',
            'phone' => '01701000088',
            'is_active' => true,
            'outstanding_balance' => 0,
        ]);

        return [$branchA, $branchB, $user, $customer];
    }
}
