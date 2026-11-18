<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\InstallmentPlan;
use App\Models\Location;
use App\Models\SalesInvoice;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PostedInvoiceSaleTypeChangeTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_cannot_convert_posted_invoice_to_installment(): void
    {
        [$invoice, $admin] = $this->seedPostedCreditInvoice();

        $response = $this->actingAs($admin)->post(route('sales.invoices.sale-type', $invoice), [
            'sale_type' => 'installment',
            'installment_down_payment' => 2000,
            'installment_count' => 4,
            'installment_first_due_date' => now()->addMonth()->toDateString(),
            'installment_frequency' => 'monthly',
        ]);

        $response->assertSessionHasErrors('sale_type');

        $invoice->refresh();
        $this->assertSame('credit', $invoice->sale_type);
        $this->assertNull(InstallmentPlan::query()->where('sales_invoice_id', $invoice->id)->first());
    }

    public function test_admin_can_set_credit_due_date_on_posted_default_invoice(): void
    {
        [$invoice, $admin] = $this->seedPostedDefaultInvoice();
        $dueDate = now()->addDays(30)->toDateString();

        $response = $this->actingAs($admin)->post(route('sales.invoices.sale-type', $invoice), [
            'sale_type' => 'credit',
            'credit_due_date' => $dueDate,
        ]);

        $response->assertRedirect(route('sales.invoices.show', $invoice));
        $response->assertSessionHasNoErrors();

        $invoice->refresh();
        $this->assertSame('credit', $invoice->sale_type);
        $this->assertSame('posted', $invoice->status);
        $this->assertSame($dueDate, $invoice->credit_due_date?->toDateString());
    }

    public function test_non_admin_cannot_change_posted_invoice_sale_type(): void
    {
        [$invoice] = $this->seedPostedDefaultInvoice();

        $staff = User::factory()->create([
            'role' => 'sales',
            'is_active' => true,
            'branch_id' => $invoice->branch_id,
        ]);

        $this->actingAs($staff)->post(route('sales.invoices.sale-type', $invoice), [
            'sale_type' => 'credit',
            'credit_due_date' => now()->addDays(14)->toDateString(),
        ])->assertForbidden();
    }

    public function test_active_installment_invoice_cannot_change_payment_type(): void
    {
        [$invoice, $admin] = $this->seedActiveInstallmentInvoice();

        $this->assertFalse($admin->can('changeSaleType', $invoice));

        $this->actingAs($admin)
            ->post(route('sales.invoices.sale-type', $invoice), [
                'sale_type' => 'credit',
                'credit_due_date' => now()->addDays(14)->toDateString(),
            ])
            ->assertForbidden();

        $invoice->refresh();
        $this->assertSame('installment', $invoice->sale_type);
    }

    /**
     * @return array{0: SalesInvoice, 1: User}
     */
    private function seedPostedCreditInvoice(): array
    {
        [$branch, $admin, $customer] = $this->seedBranchAdminCustomer('BR-ST-CR');

        $invoice = SalesInvoice::create([
            'invoice_number' => 'INV-ST-CR-001',
            'invoice_date' => now()->toDateString(),
            'branch_id' => $branch->id,
            'customer_id' => $customer->id,
            'sale_type' => 'credit',
            'credit_due_date' => now()->addDays(15)->toDateString(),
            'status' => 'posted',
            'payment_status' => 'partial',
            'subtotal' => 10000,
            'discount_total' => 0,
            'tax_total' => 0,
            'grand_total' => 10000,
            'amount_paid' => 2000,
            'amount_due' => 8000,
            'created_by' => $admin->id,
        ]);

        return [$invoice, $admin];
    }

    /**
     * @return array{0: SalesInvoice, 1: User}
     */
    private function seedPostedDefaultInvoice(): array
    {
        [$branch, $admin, $customer] = $this->seedBranchAdminCustomer('BR-ST-DF');

        $invoice = SalesInvoice::create([
            'invoice_number' => 'INV-ST-DF-001',
            'invoice_date' => now()->toDateString(),
            'branch_id' => $branch->id,
            'customer_id' => $customer->id,
            'sale_type' => 'default',
            'status' => 'posted',
            'payment_status' => 'unpaid',
            'subtotal' => 5000,
            'discount_total' => 0,
            'tax_total' => 0,
            'grand_total' => 5000,
            'amount_paid' => 0,
            'amount_due' => 5000,
            'created_by' => $admin->id,
        ]);

        return [$invoice, $admin];
    }

    /**
     * @return array{0: SalesInvoice, 1: User}
     */
    private function seedActiveInstallmentInvoice(): array
    {
        [$branch, $admin, $customer] = $this->seedBranchAdminCustomer('BR-ST-IN');

        $invoice = SalesInvoice::create([
            'invoice_number' => 'INV-ST-IN-001',
            'invoice_date' => now()->toDateString(),
            'branch_id' => $branch->id,
            'customer_id' => $customer->id,
            'sale_type' => 'installment',
            'installment_down_payment' => 1000,
            'installment_count' => 2,
            'installment_first_due_date' => now()->addMonth()->toDateString(),
            'installment_frequency' => 'monthly',
            'status' => 'active',
            'payment_status' => 'partial',
            'subtotal' => 6000,
            'discount_total' => 0,
            'tax_total' => 0,
            'grand_total' => 6000,
            'amount_paid' => 1000,
            'amount_due' => 5000,
            'created_by' => $admin->id,
        ]);

        InstallmentPlan::create([
            'sales_invoice_id' => $invoice->id,
            'customer_id' => $customer->id,
            'total_amount' => 6000,
            'down_payment' => 1000,
            'financed_amount' => 5000,
            'remaining_amount' => 5000,
            'penalty_enabled' => false,
            'penalty_rate' => 0.02,
            'created_by' => $admin->id,
        ]);

        return [$invoice, $admin];
    }

    /**
     * @return array{0: Location, 1: User, 2: Customer}
     */
    private function seedBranchAdminCustomer(string $code): array
    {
        $branch = Location::create([
            'code' => $code,
            'name' => $code.' Branch',
            'type' => 'branch',
            'is_active' => true,
        ]);

        $admin = User::factory()->create([
            'role' => 'admin',
            'is_active' => true,
            'branch_id' => $branch->id,
        ]);

        $customer = Customer::create([
            'customer_code' => 'C-'.$code,
            'name' => 'Customer '.$code,
            'phone' => '01700001111',
            'is_active' => true,
        ]);

        return [$branch, $admin, $customer];
    }
}
