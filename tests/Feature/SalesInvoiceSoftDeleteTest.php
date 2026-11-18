<?php

namespace Tests\Feature;

use App\Models\Brand;
use App\Models\Category;
use App\Models\Customer;
use App\Models\Location;
use App\Models\Product;
use App\Models\SaleLineSerial;
use App\Models\SalesInvoice;
use App\Models\SerialItem;
use App\Models\StockLedger;
use App\Models\User;
use App\Services\PermissionSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SalesInvoiceSoftDeleteTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(PermissionSyncService::class)->sync();
    }

    public function test_deleting_an_invoice_soft_deletes_it_and_leaves_stock_untouched(): void
    {
        [$admin, $branch, $customer, $product, $serial] = $this->seedInvoiceFixture();

        $this->actingAs($admin)->post(route('sales.invoices.store'), $this->invoicePayload($branch, $customer, $product, $serial))
            ->assertRedirect(route('sales.invoices.index'));

        $invoice = SalesInvoice::query()->firstOrFail();
        $serialStatusBeforeDelete = $serial->fresh()->status;

        $this->actingAs($admin)
            ->delete(route('sales.invoices.destroy', $invoice))
            ->assertRedirect(route('sales.invoices.index'))
            ->assertSessionHas('status', 'Invoice moved to trash.');

        $this->assertNull(SalesInvoice::query()->find($invoice->id));
        $this->assertNotNull(SalesInvoice::withTrashed()->find($invoice->id));
        $this->assertTrue($invoice->fresh()->trashed());

        // Nothing was reversed: the serial link and status are untouched by a soft delete.
        $this->assertSame($serialStatusBeforeDelete, $serial->fresh()->status);
        $this->assertTrue(SaleLineSerial::query()->where('serial_item_id', $serial->id)->exists());
    }

    public function test_trashed_invoices_are_listed_and_can_be_restored(): void
    {
        [$admin, $branch, $customer, $product, $serial] = $this->seedInvoiceFixture();

        $this->actingAs($admin)->post(route('sales.invoices.store'), $this->invoicePayload($branch, $customer, $product, $serial));
        $invoice = SalesInvoice::query()->firstOrFail();

        $this->actingAs($admin)->delete(route('sales.invoices.destroy', $invoice));

        $this->actingAs($admin)
            ->get(route('sales.invoices.trashed'))
            ->assertOk()
            ->assertSee($customer->name);

        $this->actingAs($admin)
            ->post(route('sales.invoices.restore', $invoice))
            ->assertRedirect(route('sales.invoices.show', $invoice))
            ->assertSessionHas('status', 'Invoice restored.');

        $this->assertFalse($invoice->fresh()->trashed());
        $this->assertNotNull(SalesInvoice::query()->find($invoice->id));
    }

    public function test_force_deleting_a_trashed_invoice_rolls_back_stock_and_removes_it_permanently(): void
    {
        [$admin, $branch, $customer, $product, $serial] = $this->seedInvoiceFixture();

        $this->actingAs($admin)->post(route('sales.invoices.store'), $this->invoicePayload($branch, $customer, $product, $serial));
        $invoice = SalesInvoice::query()->firstOrFail();

        $this->actingAs($admin)->delete(route('sales.invoices.destroy', $invoice));

        $this->actingAs($admin)
            ->delete(route('sales.invoices.force-delete', $invoice))
            ->assertRedirect(route('sales.invoices.trashed'))
            ->assertSessionHas('status', 'Invoice permanently deleted with rollback.');

        $this->assertNull(SalesInvoice::withTrashed()->find($invoice->id));
        $this->assertSame('in_stock', $serial->fresh()->status);
    }

    public function test_force_delete_is_refused_while_invoice_is_still_active(): void
    {
        [$admin, $branch, $customer, $product, $serial] = $this->seedInvoiceFixture();

        $this->actingAs($admin)->post(route('sales.invoices.store'), $this->invoicePayload($branch, $customer, $product, $serial));
        $invoice = SalesInvoice::query()->firstOrFail();

        $this->actingAs($admin)
            ->delete(route('sales.invoices.force-delete', $invoice))
            ->assertRedirect(route('sales.invoices.trashed'))
            ->assertSessionHas('status', 'Move the invoice to trash before permanently deleting it.');

        $this->assertNotNull(SalesInvoice::query()->find($invoice->id));
    }

    public function test_user_without_permissions_cannot_view_restore_or_force_delete_trashed_invoices(): void
    {
        [$admin, $branch, $customer, $product, $serial] = $this->seedInvoiceFixture();

        $this->actingAs($admin)->post(route('sales.invoices.store'), $this->invoicePayload($branch, $customer, $product, $serial));
        $invoice = SalesInvoice::query()->firstOrFail();
        $this->actingAs($admin)->delete(route('sales.invoices.destroy', $invoice));

        $staff = User::factory()->create([
            'role' => 'sales',
            'is_active' => true,
            'branch_id' => $branch->id,
        ]);
        $staff->accessibleLocations()->sync([$branch->id]);

        $this->actingAs($staff)->get(route('sales.invoices.trashed'))->assertForbidden();
        $this->actingAs($staff)->post(route('sales.invoices.restore', $invoice))->assertForbidden();
        $this->actingAs($staff)->delete(route('sales.invoices.force-delete', $invoice))->assertForbidden();
    }

    private function seedInvoiceFixture(): array
    {
        $branch = Location::create([
            'code' => 'INV-SOFTDEL-BR-'.uniqid(),
            'name' => 'Soft Delete Branch',
            'type' => 'branch',
            'is_active' => true,
        ]);

        $user = User::factory()->create([
            'role' => 'admin',
            'is_active' => true,
            'branch_id' => $branch->id,
        ]);

        $customer = Customer::create([
            'customer_code' => 'C-SOFTDEL-'.uniqid(),
            'name' => 'Soft Delete Customer',
            'phone' => '01700009998',
            'is_active' => true,
        ]);

        $brand = Brand::create(['name' => 'Soft Delete Brand '.uniqid()]);
        $category = Category::create(['name' => 'Soft Delete Category '.uniqid()]);

        $product = Product::create([
            'sku' => 'SOFTDEL-SERIAL-'.uniqid(),
            'brand_id' => $brand->id,
            'category_id' => $category->id,
            'name' => 'Soft Delete Serialized Product',
            'warranty_months' => 12,
            'default_purchase_price' => 300,
            'default_sale_price' => 1000,
            'reorder_level' => 0,
            'unit_of_measure' => 'unit',
            'is_serialized' => true,
            'is_active' => true,
        ]);

        $serial = SerialItem::create([
            'product_id' => $product->id,
            'serial_number' => 'SOFTDEL-SN-'.uniqid(),
            'serial_source' => 'internal',
            'current_location_id' => $branch->id,
            'status' => 'in_stock',
            'purchase_cost' => 300,
        ]);

        StockLedger::create([
            'event_time' => now(),
            'product_id' => $product->id,
            'serial_item_id' => $serial->id,
            'location_id' => $branch->id,
            'movement_type' => 'purchase_receive',
            'quantity_delta' => 1,
            'unit_cost' => 300,
            'reference_type' => 'Test',
            'reference_id' => 1,
            'actor_user_id' => $user->id,
        ]);

        return [$user, $branch, $customer, $product, $serial];
    }

    private function invoicePayload(Location $branch, Customer $customer, Product $product, SerialItem $serial): array
    {
        return [
            'invoice_date' => now()->toDateString(),
            'branch_id' => $branch->id,
            'customer_id' => $customer->id,
            'sale_type' => 'credit',
            'credit_due_date' => now()->addDays(10)->toDateString(),
            'lines' => [
                [
                    'product_id' => $product->id,
                    'qty' => 1,
                    'unit_price' => 1000,
                    'serial_ids' => [(string) $serial->id],
                    'service_charge' => 0,
                ],
            ],
        ];
    }
}
