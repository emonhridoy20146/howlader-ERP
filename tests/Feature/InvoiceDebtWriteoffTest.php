<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\InstallmentPlan;
use App\Models\InstallmentSchedule;
use App\Models\InvoiceDebtWriteoff;
use App\Models\Location;
use App\Models\Permission;
use App\Models\Role;
use App\Models\SalesInvoice;
use App\Models\User;
use App\Services\PermissionSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InvoiceDebtWriteoffTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_write_off_open_receivable(): void
    {
        $location = Location::create([
            'code' => 'LOC-WO',
            'name' => 'Write-off branch',
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
            'customer_code' => 'C-WO',
            'name' => 'Debtor Co',
            'phone' => '01701000000',
            'is_active' => true,
            'outstanding_balance' => 5000,
        ]);

        $invoice = SalesInvoice::create([
            'invoice_number' => 'INV-WO-1',
            'invoice_date' => now()->toDateString(),
            'branch_id' => $location->id,
            'customer_id' => $customer->id,
            'sale_type' => 'credit',
            'status' => 'posted',
            'subtotal' => 5000,
            'payment_status' => 'unpaid',
            'discount_total' => 0,
            'tax_total' => 0,
            'grand_total' => 5000,
            'amount_paid' => 0,
            'amount_due' => 5000,
            'created_by' => $user->id,
        ]);

        $response = $this->actingAs($user)->post(route('sales.invoices.write-off', $invoice), [
            'amount' => 5000,
            'reason' => 'Uncollectible — board approval 2026-04',
        ]);

        $response->assertRedirect(route('sales.invoices.show', $invoice));

        $invoice->refresh();
        $customer->refresh();

        $this->assertSame('paid', $invoice->payment_status);
        $this->assertEquals(0.0, (float) $invoice->amount_due);
        $this->assertEquals(5000.0, (float) $invoice->amount_paid);
        $this->assertEquals(0.0, (float) $customer->outstanding_balance);

        $this->assertDatabaseHas('accounts_receivable_ledger', [
            'customer_id' => $customer->id,
            'event_type' => 'writeoff',
            'amount_delta' => -5000,
        ]);

        $this->assertDatabaseHas('journal_entries', [
            'source' => 'debt_writeoff',
            'branch_id' => $location->id,
        ]);
    }

    public function test_invoice_print_shows_the_written_off_amount(): void
    {
        $location = Location::create([
            'code' => 'LOC-WOP',
            'name' => 'Print branch',
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
            'customer_code' => 'C-WOP',
            'name' => 'Debtor Co',
            'phone' => '01701000004',
            'is_active' => true,
            'outstanding_balance' => 5000,
        ]);

        $invoice = SalesInvoice::create([
            'invoice_number' => 'INV-WOP-1',
            'invoice_date' => now()->toDateString(),
            'branch_id' => $location->id,
            'customer_id' => $customer->id,
            'sale_type' => 'credit',
            'status' => 'posted',
            'subtotal' => 5000,
            'payment_status' => 'unpaid',
            'discount_total' => 0,
            'tax_total' => 0,
            'grand_total' => 5000,
            'amount_paid' => 0,
            'amount_due' => 5000,
            'created_by' => $user->id,
        ]);

        // No write-off yet: the "Written Off" line should not render at all.
        $this->actingAs($user)
            ->get(route('sales.invoices.print', $invoice))
            ->assertOk()
            ->assertDontSee('Written Off', false);

        $this->actingAs($user)->post(route('sales.invoices.write-off', $invoice), [
            'amount' => 2000,
            'reason' => 'Partial write-off',
        ])->assertRedirect(route('sales.invoices.show', $invoice));

        $this->actingAs($user)
            ->get(route('sales.invoices.print', $invoice))
            ->assertOk()
            ->assertSee('Written Off', false)
            ->assertSee(number_format(2000, 2), false);
    }

    public function test_user_without_permission_cannot_write_off(): void
    {
        app(PermissionSyncService::class)->syncFromPolicies();

        $location = Location::create([
            'code' => 'LOC-WO2',
            'name' => 'Branch 2',
            'type' => 'branch',
            'address' => 'Test',
            'is_active' => true,
        ]);

        $viewAnyId = Permission::query()->where('slug', 'sales-invoice.view-any')->value('id');
        $viewId = Permission::query()->where('slug', 'sales-invoice.view')->value('id');
        $this->assertNotNull($viewAnyId);
        $this->assertNotNull($viewId);

        $role = Role::query()->create([
            'name' => 'Invoices only',
            'slug' => 'invoices-only-test',
        ]);
        $role->permissions()->sync([(int) $viewAnyId, (int) $viewId]);

        $user = User::factory()->create([
            'role' => 'sales',
            'is_active' => true,
            'branch_id' => $location->id,
        ]);
        $user->roles()->sync([$role->id]);

        $customer = Customer::create([
            'customer_code' => 'C-WO2',
            'name' => 'Customer',
            'phone' => '01702000000',
            'is_active' => true,
            'outstanding_balance' => 100,
        ]);

        $invoice = SalesInvoice::create([
            'invoice_number' => 'INV-WO-2',
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

        $this->actingAs($user)->post(route('sales.invoices.write-off', $invoice), [
            'amount' => 100,
        ])->assertForbidden();
    }

    public function test_admin_can_remove_a_write_off_and_it_reverses_everything(): void
    {
        $location = Location::create([
            'code' => 'LOC-WOR',
            'name' => 'Write-off removal branch',
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
            'customer_code' => 'C-WOR',
            'name' => 'Debtor Co',
            'phone' => '01701000001',
            'is_active' => true,
            'outstanding_balance' => 5000,
        ]);

        $invoice = SalesInvoice::create([
            'invoice_number' => 'INV-WOR-1',
            'invoice_date' => now()->toDateString(),
            'branch_id' => $location->id,
            'customer_id' => $customer->id,
            'sale_type' => 'credit',
            'status' => 'posted',
            'subtotal' => 5000,
            'payment_status' => 'unpaid',
            'discount_total' => 0,
            'tax_total' => 0,
            'grand_total' => 5000,
            'amount_paid' => 0,
            'amount_due' => 5000,
            'created_by' => $user->id,
        ]);

        $this->actingAs($user)->post(route('sales.invoices.write-off', $invoice), [
            'amount' => 5000,
            'reason' => 'Uncollectible',
        ])->assertRedirect(route('sales.invoices.show', $invoice));

        $writeoff = InvoiceDebtWriteoff::query()->where('sales_invoice_id', $invoice->id)->firstOrFail();
        $journalEntryId = \App\Models\JournalEntry::query()
            ->where('reference_type', InvoiceDebtWriteoff::class)
            ->where('reference_id', $writeoff->id)
            ->value('id');
        $this->assertNotNull($journalEntryId);

        $response = $this->actingAs($user)->delete(route('sales.invoices.write-off.destroy', [$invoice, $writeoff]));
        $response->assertRedirect(route('sales.invoices.show', $invoice));
        $response->assertSessionHas('status', 'Write-off removed.');

        $invoice->refresh();
        $customer->refresh();

        $this->assertSame('unpaid', $invoice->payment_status);
        $this->assertEquals(5000.0, (float) $invoice->amount_due);
        $this->assertEquals(0.0, (float) $invoice->amount_paid);
        $this->assertEquals(5000.0, (float) $customer->outstanding_balance);

        $this->assertDatabaseMissing('invoice_debt_writeoffs', ['id' => $writeoff->id]);
        $this->assertDatabaseMissing('accounts_receivable_ledger', [
            'reference_type' => InvoiceDebtWriteoff::class,
            'reference_id' => $writeoff->id,
        ]);
        $this->assertDatabaseMissing('journal_entries', ['id' => $journalEntryId]);
        $this->assertDatabaseMissing('journal_lines', ['journal_entry_id' => $journalEntryId]);
    }

    public function test_removing_an_installment_write_off_precisely_undoes_its_schedule_allocations(): void
    {
        $location = Location::create([
            'code' => 'LOC-WOI',
            'name' => 'Installment write-off branch',
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
            'customer_code' => 'C-WOI',
            'name' => 'Installment Debtor',
            'phone' => '01701000002',
            'is_active' => true,
            'outstanding_balance' => 1000,
        ]);

        $invoice = SalesInvoice::create([
            'invoice_number' => 'INV-WOI-1',
            'invoice_date' => now()->toDateString(),
            'branch_id' => $location->id,
            'customer_id' => $customer->id,
            'sale_type' => 'installment',
            'status' => 'active',
            'subtotal' => 1000,
            'payment_status' => 'unpaid',
            'discount_total' => 0,
            'tax_total' => 0,
            'grand_total' => 1000,
            'amount_paid' => 0,
            'amount_due' => 1000,
            'installment_count' => 2,
            'installment_first_due_date' => now()->toDateString(),
            'installment_frequency' => 'monthly',
            'created_by' => $user->id,
        ]);

        $plan = InstallmentPlan::create([
            'sales_invoice_id' => $invoice->id,
            'customer_id' => $customer->id,
            'total_amount' => 1000,
            'down_payment' => 0,
            'financed_amount' => 1000,
            'remaining_amount' => 1000,
            'penalty_enabled' => false,
            'penalty_rate' => 0,
            'status' => 'active',
            'created_by' => $user->id,
        ]);

        $schedule1 = InstallmentSchedule::create([
            'installment_plan_id' => $plan->id,
            'installment_no' => 1,
            'due_date' => now()->toDateString(),
            'due_amount' => 500,
            'paid_amount' => 0,
            'status' => 'pending',
        ]);

        $schedule2 = InstallmentSchedule::create([
            'installment_plan_id' => $plan->id,
            'installment_no' => 2,
            'due_date' => now()->addMonth()->toDateString(),
            'due_amount' => 500,
            'paid_amount' => 0,
            'status' => 'pending',
        ]);

        // Write off the whole 1000: waterfall fills schedule 1 (500) then schedule 2 (500).
        $this->actingAs($user)->post(route('sales.invoices.write-off', $invoice), [
            'amount' => 1000,
        ])->assertRedirect(route('sales.invoices.show', $invoice));

        $this->assertSame(500.0, (float) $schedule1->fresh()->paid_amount);
        $this->assertSame(500.0, (float) $schedule2->fresh()->paid_amount);
        $this->assertSame('completed', $plan->fresh()->status);

        $writeoff = InvoiceDebtWriteoff::query()->where('sales_invoice_id', $invoice->id)->firstOrFail();
        $this->assertDatabaseCount('installment_writeoff_allocations', 2);

        $this->actingAs($user)
            ->delete(route('sales.invoices.write-off.destroy', [$invoice, $writeoff]))
            ->assertRedirect(route('sales.invoices.show', $invoice));

        $this->assertSame(0.0, (float) $schedule1->fresh()->paid_amount);
        $this->assertSame(0.0, (float) $schedule2->fresh()->paid_amount);
        // schedule1's due date is today, so once unpaid again it reads as overdue.
        $this->assertSame('overdue', $schedule1->fresh()->status);
        $this->assertSame('pending', $schedule2->fresh()->status);
        $this->assertSame('active', $plan->fresh()->status);
        $this->assertEquals(1000.0, (float) $plan->fresh()->remaining_amount);

        $invoice->refresh();
        $this->assertEquals(1000.0, (float) $invoice->amount_due);
        $this->assertEquals(0.0, (float) $invoice->amount_paid);
        $this->assertSame('unpaid', $invoice->payment_status);

        $this->assertDatabaseCount('installment_writeoff_allocations', 0);
        $this->assertDatabaseMissing('invoice_debt_writeoffs', ['id' => $writeoff->id]);
    }

    public function test_user_without_remove_permission_cannot_remove_a_write_off(): void
    {
        app(PermissionSyncService::class)->syncFromPolicies();

        $location = Location::create([
            'code' => 'LOC-WOR2',
            'name' => 'Branch',
            'type' => 'branch',
            'address' => 'Test',
            'is_active' => true,
        ]);

        $admin = User::factory()->create([
            'role' => 'admin',
            'is_active' => true,
            'branch_id' => $location->id,
        ]);

        $customer = Customer::create([
            'customer_code' => 'C-WOR2',
            'name' => 'Customer',
            'phone' => '01701000003',
            'is_active' => true,
            'outstanding_balance' => 100,
        ]);

        $invoice = SalesInvoice::create([
            'invoice_number' => 'INV-WOR-2',
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
            'created_by' => $admin->id,
        ]);

        $this->actingAs($admin)->post(route('sales.invoices.write-off', $invoice), [
            'amount' => 100,
        ])->assertRedirect(route('sales.invoices.show', $invoice));

        $writeoff = InvoiceDebtWriteoff::query()->where('sales_invoice_id', $invoice->id)->firstOrFail();

        // This role can write off, but was never granted the separate removal permission.
        $writeOffId = Permission::query()->where('slug', 'debt.write-off')->value('id');
        $viewAnyId = Permission::query()->where('slug', 'sales-invoice.view-any')->value('id');
        $viewId = Permission::query()->where('slug', 'sales-invoice.view')->value('id');
        $this->assertNotNull($writeOffId);

        $role = Role::query()->create([
            'name' => 'Write-off only',
            'slug' => 'write-off-only-test',
        ]);
        $role->permissions()->sync(array_filter([(int) $writeOffId, (int) $viewAnyId, (int) $viewId]));

        $staff = User::factory()->create([
            'role' => 'sales',
            'is_active' => true,
            'branch_id' => $location->id,
        ]);
        $staff->roles()->sync([$role->id]);

        $this->actingAs($staff)
            ->delete(route('sales.invoices.write-off.destroy', [$invoice, $writeoff]))
            ->assertForbidden();

        $this->assertDatabaseHas('invoice_debt_writeoffs', ['id' => $writeoff->id]);
    }
}
