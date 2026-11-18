<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Location;
use App\Models\SalesInvoice;
use App\Models\User;
use App\Services\ReportDataService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

class MonthlyCollectionGuarantorFallbackTest extends TestCase
{
    use RefreshDatabase;

    public function test_collection_report_uses_customer_default_guarantor_when_invoice_has_none(): void
    {
        $branch = Location::create([
            'code' => 'BR-GUA',
            'name' => 'Guarantor Branch',
            'type' => 'branch',
            'is_active' => true,
        ]);

        $user = User::factory()->create([
            'role' => 'admin',
            'is_active' => true,
            'branch_id' => $branch->id,
        ]);

        $defaultGuarantor = Customer::create([
            'customer_code' => 'G-DEF-01',
            'name' => 'Default Guarantor Person',
            'phone' => '01710000001',
            'address' => 'Guarantor Road',
            'is_active' => true,
        ]);

        $customer = Customer::create([
            'customer_code' => 'C-GUA-01',
            'name' => 'Buyer Customer',
            'phone' => '01710000002',
            'default_guarantor_id' => $defaultGuarantor->id,
            'is_active' => true,
        ]);

        $invoice = SalesInvoice::create([
            'invoice_number' => 'S-NO-GUA',
            'branch_id' => $branch->id,
            'customer_id' => $customer->id,
            'invoice_date' => now()->startOfMonth()->addDay()->toDateString(),
            'sale_type' => 'default',
            'status' => 'posted',
            'payment_status' => 'unpaid',
            'subtotal' => 1500,
            'discount_total' => 0,
            'tax_total' => 0,
            'grand_total' => 1500,
            'amount_paid' => 0,
            'amount_due' => 1500,
            'created_by' => $user->id,
        ]);

        $invoice->load(['customer.defaultGuarantor']);

        $this->assertStringContainsString('Default Guarantor Person', $invoice->collectionGuarantorDisplayText());
        $this->assertStringContainsString('Guarantor Road', $invoice->collectionGuarantorDisplayText());

        $this->actingAs($user);
        $data = app(ReportDataService::class)->monthlyCollectionPaginated(Request::create('/', 'GET'));
        $listed = $data['thisMonthCredits']->first();

        $this->assertNotNull($listed);
        $this->assertSame($invoice->id, $listed->id);
        $this->assertStringContainsString('Default Guarantor Person', $listed->collectionGuarantorDisplayText());
    }

    public function test_invoice_with_buyer_as_guarantor_falls_back_to_default(): void
    {
        $branch = Location::create([
            'code' => 'BR-GUA3',
            'name' => 'Guarantor Branch 3',
            'type' => 'branch',
            'is_active' => true,
        ]);

        $defaultGuarantor = Customer::create([
            'customer_code' => 'G-DEF-03',
            'name' => 'Real Default Guarantor',
            'phone' => '01710000006',
            'is_active' => true,
        ]);

        $customer = Customer::create([
            'customer_code' => 'C-GUA-03',
            'name' => 'Buyer Customer',
            'phone' => '01710000007',
            'default_guarantor_id' => $defaultGuarantor->id,
            'is_active' => true,
        ]);

        $invoice = SalesInvoice::create([
            'invoice_number' => 'S-BUYER-GUA',
            'branch_id' => $branch->id,
            'customer_id' => $customer->id,
            'guarantor_id' => $customer->id,
            'guarantor_name' => $customer->name,
            'guarantor_phone_numbers' => ['01710000007'],
            'invoice_date' => now()->startOfMonth()->addDay()->toDateString(),
            'sale_type' => 'installment',
            'status' => 'posted',
            'payment_status' => 'partial',
            'subtotal' => 3000,
            'discount_total' => 0,
            'tax_total' => 0,
            'grand_total' => 3000,
            'amount_paid' => 500,
            'amount_due' => 2500,
            'created_by' => User::factory()->create(['role' => 'admin', 'is_active' => true, 'branch_id' => $branch->id])->id,
        ]);

        $display = $invoice->fresh(['customer.defaultGuarantor', 'guarantor'])->collectionGuarantorDisplayText();

        $this->assertStringContainsString('Real Default Guarantor', $display);
        $this->assertStringNotContainsString('Name: Buyer Customer', $display);
    }

    public function test_invoice_guarantor_takes_priority_over_customer_default(): void
    {
        $branch = Location::create([
            'code' => 'BR-GUA2',
            'name' => 'Guarantor Branch 2',
            'type' => 'branch',
            'is_active' => true,
        ]);

        $defaultGuarantor = Customer::create([
            'customer_code' => 'G-DEF-02',
            'name' => 'Should Not Appear',
            'phone' => '01710000003',
            'is_active' => true,
        ]);

        $invoiceGuarantor = Customer::create([
            'customer_code' => 'G-INV-02',
            'name' => 'Invoice Guarantor Person',
            'phone' => '01710000004',
            'is_active' => true,
        ]);

        $customer = Customer::create([
            'customer_code' => 'C-GUA-02',
            'name' => 'Buyer Two',
            'phone' => '01710000005',
            'default_guarantor_id' => $defaultGuarantor->id,
            'is_active' => true,
        ]);

        $invoice = SalesInvoice::create([
            'invoice_number' => 'S-WITH-GUA',
            'branch_id' => $branch->id,
            'customer_id' => $customer->id,
            'guarantor_id' => $invoiceGuarantor->id,
            'guarantor_name' => $invoiceGuarantor->name,
            'guarantor_phone_numbers' => ['01710000004'],
            'invoice_date' => now()->toDateString(),
            'sale_type' => 'installment',
            'status' => 'posted',
            'payment_status' => 'partial',
            'subtotal' => 5000,
            'discount_total' => 0,
            'tax_total' => 0,
            'grand_total' => 5000,
            'amount_paid' => 1000,
            'amount_due' => 4000,
            'created_by' => User::factory()->create(['role' => 'admin', 'is_active' => true, 'branch_id' => $branch->id])->id,
        ]);

        $invoice->load(['guarantor', 'customer.defaultGuarantor']);

        $display = $invoice->collectionGuarantorDisplayText();

        $this->assertStringContainsString('Invoice Guarantor Person', $display);
        $this->assertStringNotContainsString('Should Not Appear', $display);
    }
}
