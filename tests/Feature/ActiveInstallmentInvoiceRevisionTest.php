<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\InstallmentPlan;
use App\Models\InstallmentSchedule;
use App\Models\Location;
use App\Models\SalesInvoice;
use App\Models\User;
use App\Services\ActiveInstallmentInvoiceRevisionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ActiveInstallmentInvoiceRevisionTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_open_revise_form_for_active_installment_invoice(): void
    {
        [$invoice, $admin] = $this->seedActiveInstallmentInvoice();

        $this->actingAs($admin)
            ->get(route('sales.invoices.edit', $invoice))
            ->assertOk()
            ->assertSee('Admin installment revision');
    }

    public function test_non_admin_cannot_open_revise_form_for_active_installment_invoice(): void
    {
        [$invoice] = $this->seedActiveInstallmentInvoice();

        $staff = User::factory()->create([
            'role' => 'sales',
            'is_active' => true,
            'branch_id' => $invoice->branch_id,
        ]);

        $this->actingAs($staff)
            ->get(route('sales.invoices.edit', $invoice))
            ->assertForbidden();
    }

    public function test_regenerating_plan_rebuilds_schedules_from_invoice_settings(): void
    {
        [$invoice] = $this->seedActiveInstallmentInvoice();

        $invoice->update([
            'installment_count' => 3,
            'installment_first_due_date' => now()->addMonth()->toDateString(),
            'installment_frequency' => 'monthly',
            'installment_down_payment' => 1000,
            'grand_total' => 10000,
        ]);

        app(ActiveInstallmentInvoiceRevisionService::class)->regeneratePlanFromInvoiceConfig(
            $invoice->fresh(),
            (int) $invoice->created_by,
        );

        $invoice->refresh();
        $plan = $invoice->installmentPlan;
        $schedules = $plan->schedules()->orderBy('installment_no')->get();

        $this->assertCount(3, $schedules);
        $this->assertSame(9000.0, (float) $plan->financed_amount);
        $this->assertSame(3000.0, (float) $schedules[0]->due_amount);
        $this->assertSame(3000.0, (float) $schedules[1]->due_amount);
        $this->assertSame(3000.0, (float) $schedules[2]->due_amount);
        $this->assertSame(9000.0, (float) $invoice->amount_due);
    }

    /**
     * @return array{0: SalesInvoice, 1: User}
     */
    private function seedActiveInstallmentInvoice(): array
    {
        $branch = Location::create([
            'code' => 'BR-REV',
            'name' => 'Revise Branch',
            'type' => 'branch',
            'is_active' => true,
        ]);

        $admin = User::factory()->create([
            'role' => 'admin',
            'is_active' => true,
            'branch_id' => $branch->id,
        ]);

        $customer = Customer::create([
            'customer_code' => 'C-REV',
            'name' => 'Revise Customer',
            'phone' => '01700007777',
            'is_active' => true,
        ]);

        $invoice = SalesInvoice::create([
            'invoice_number' => 'S-REV-1',
            'branch_id' => $branch->id,
            'customer_id' => $customer->id,
            'invoice_date' => now()->subMonth()->toDateString(),
            'sale_type' => 'installment',
            'status' => 'active',
            'payment_status' => 'partial',
            'subtotal' => 6000,
            'discount_total' => 0,
            'tax_total' => 0,
            'grand_total' => 6000,
            'amount_paid' => 1000,
            'amount_due' => 5000,
            'installment_down_payment' => 1000,
            'installment_count' => 2,
            'installment_first_due_date' => now()->subWeeks(2)->toDateString(),
            'installment_frequency' => 'monthly',
            'created_by' => $admin->id,
        ]);

        $plan = InstallmentPlan::create([
            'sales_invoice_id' => $invoice->id,
            'customer_id' => $customer->id,
            'total_amount' => 6000,
            'down_payment' => 1000,
            'financed_amount' => 5000,
            'remaining_amount' => 5000,
            'penalty_enabled' => false,
            'penalty_rate' => 0,
            'status' => 'active',
            'created_by' => $admin->id,
        ]);

        InstallmentSchedule::create([
            'installment_plan_id' => $plan->id,
            'installment_no' => 1,
            'due_date' => now()->subWeeks(2)->toDateString(),
            'due_amount' => 2500,
            'paid_amount' => 1000,
            'status' => 'partially_paid',
        ]);

        InstallmentSchedule::create([
            'installment_plan_id' => $plan->id,
            'installment_no' => 2,
            'due_date' => now()->addWeeks(2)->toDateString(),
            'due_amount' => 2500,
            'paid_amount' => 0,
            'status' => 'pending',
        ]);

        return [$invoice, $admin];
    }
}
