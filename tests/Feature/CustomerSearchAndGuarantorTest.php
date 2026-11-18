<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Location;
use App\Models\SalesInvoice;
use App\Models\User;
use App\Services\GlobalSearchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CustomerSearchAndGuarantorTest extends TestCase
{
    use RefreshDatabase;

    public function test_customers_index_filters_by_customer_code_field(): void
    {
        $user = User::factory()->create(['role' => 'admin', 'is_active' => true]);

        Customer::create([
            'customer_code' => 'CUST-CODE-1',
            'name' => 'Alpha Buyer',
            'phone' => '01700000001',
            'is_active' => true,
        ]);

        Customer::create([
            'customer_code' => 'OTHER-999',
            'name' => 'ZZZ Unique Beta Name XYZ',
            'phone' => '01700000002',
            'is_active' => true,
        ]);

        $response = $this->actingAs($user)->get(route('customers.index', ['code' => 'CUST-CODE']));

        $response->assertOk();
        $response->assertSee('Alpha Buyer');
        $response->assertSee('1 in this list');
    }

    public function test_global_search_finds_customer_by_exact_code(): void
    {
        $user = User::factory()->create(['role' => 'admin', 'is_active' => true]);

        $customer = Customer::create([
            'customer_code' => 'CUST2607160001',
            'name' => 'Searchable Customer',
            'phone' => '01700000003',
            'is_active' => true,
        ]);

        $results = app(GlobalSearchService::class)->search('CUST2607160001', null, [], $user);

        $this->assertTrue($results['customers']->contains('id', $customer->id));
    }

    public function test_customer_default_guarantor_attaches_to_installment_invoice(): void
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

        $guarantor = Customer::create([
            'customer_code' => 'G-TEST-1',
            'name' => 'Guarantor Person',
            'phone' => '01700000004',
            'is_active' => true,
        ]);

        $customer = Customer::create([
            'customer_code' => 'C-TEST-1',
            'name' => 'Installment Buyer',
            'phone' => '01700000005',
            'default_guarantor_id' => $guarantor->id,
            'is_active' => true,
        ]);

        $invoice = SalesInvoice::create([
            'invoice_date' => now()->toDateString(),
            'branch_id' => $branch->id,
            'customer_id' => $customer->id,
            'sale_type' => 'installment',
            'status' => 'draft',
            'subtotal' => 10000,
            'discount_total' => 0,
            'tax_total' => 0,
            'grand_total' => 10000,
            'amount_paid' => 0,
            'amount_due' => 10000,
            'created_by' => $user->id,
        ]);

        $this->actingAs($user)->post(route('customers.attach-guarantor-to-invoice', $customer), [
            'invoice_id' => $invoice->id,
        ])->assertRedirect(route('sales.invoices.show', $invoice));

        $invoice->refresh();
        $this->assertSame($guarantor->id, $invoice->guarantor_id);
        $this->assertSame('Guarantor Person', $invoice->guarantor_name);
    }

    public function test_customer_store_can_create_inline_guarantor(): void
    {
        $user = User::factory()->create(['role' => 'admin', 'is_active' => true]);

        $response = $this->actingAs($user)->post(route('customers.store'), [
            'customer_code' => 'C-NEW-1',
            'name' => 'Main Customer',
            'phone' => '01700000006',
            'guarantor_mode' => 'new',
            'new_guarantor_name' => 'Inline Guarantor',
            'new_guarantor_phone' => '01700000007',
            'is_active' => 1,
        ]);

        $response->assertRedirect(route('customers.index'));

        $customer = Customer::query()->where('customer_code', 'C-NEW-1')->firstOrFail();
        $this->assertNotNull($customer->default_guarantor_id);
        $this->assertSame('Inline Guarantor', $customer->defaultGuarantor?->name);
    }
}
