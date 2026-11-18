<?php

namespace Tests\Feature;

use App\Models\Brand;
use App\Models\Category;
use App\Models\Customer;
use App\Models\Location;
use App\Models\Product;
use App\Models\ReturnLine;
use App\Models\ReturnOrder;
use App\Models\SaleLineSerial;
use App\Models\SalesInvoice;
use App\Models\SalesInvoiceLine;
use App\Models\SerialItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SyncReturnedInvoiceStatusCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_command_marks_fully_returned_posted_invoice_as_returned(): void
    {
        [$invoice] = $this->seedFullyReturnedPostedInvoice();

        $this->artisan('invoices:sync-returned-status')
            ->assertSuccessful();

        $invoice->refresh();
        $this->assertSame('returned', $invoice->status);
    }

    public function test_dry_run_does_not_update_invoice_status(): void
    {
        [$invoice] = $this->seedFullyReturnedPostedInvoice();

        $this->artisan('invoices:sync-returned-status', ['--dry-run' => true])
            ->assertSuccessful()
            ->expectsOutputToContain('Would update 1 invoice(s).');

        $invoice->refresh();
        $this->assertSame('posted', $invoice->status);
    }

    public function test_command_can_target_single_invoice_by_number(): void
    {
        [$invoice] = $this->seedFullyReturnedPostedInvoice();

        $this->artisan('invoices:sync-returned-status', ['--invoice' => 'INV-SYNC-RET'])
            ->assertSuccessful();

        $invoice->refresh();
        $this->assertSame('returned', $invoice->status);
    }

    public function test_command_skips_invoices_with_partial_returns(): void
    {
        $location = Location::create([
            'code' => 'LOC-SYNC-PART',
            'name' => 'Partial return branch',
            'type' => 'branch',
            'is_active' => true,
        ]);

        $user = User::factory()->create([
            'role' => 'admin',
            'is_active' => true,
            'branch_id' => $location->id,
        ]);

        $customer = Customer::create([
            'customer_code' => 'C-SYNC-PART',
            'name' => 'Partial Customer',
            'phone' => '01700000444',
            'is_active' => true,
        ]);

        $brand = Brand::create(['name' => 'Partial Brand']);
        $category = Category::create(['name' => 'Partial Category']);
        $product = Product::create([
            'sku' => 'SKU-PART',
            'brand_id' => $brand->id,
            'category_id' => $category->id,
            'name' => 'Partial Product',
            'warranty_months' => 12,
            'default_purchase_price' => 5000,
            'default_sale_price' => 10000,
            'reorder_level' => 0,
            'unit_of_measure' => 'unit',
            'is_serialized' => false,
            'is_active' => true,
        ]);

        $invoice = SalesInvoice::create([
            'invoice_number' => 'INV-SYNC-PART',
            'invoice_date' => now()->toDateString(),
            'branch_id' => $location->id,
            'customer_id' => $customer->id,
            'sale_type' => 'default',
            'status' => 'posted',
            'payment_status' => 'paid',
            'subtotal' => 20000,
            'discount_total' => 0,
            'tax_total' => 0,
            'grand_total' => 20000,
            'amount_paid' => 20000,
            'amount_due' => 0,
            'created_by' => $user->id,
        ]);

        $line = SalesInvoiceLine::create([
            'sales_invoice_id' => $invoice->id,
            'product_id' => $product->id,
            'qty' => 2,
            'unit_price' => 10000,
            'discount_amount' => 0,
            'tax_amount' => 0,
            'line_total' => 20000,
            'warranty_months_snapshot' => 12,
        ]);

        $returnOrder = ReturnOrder::create([
            'return_number' => 'RET-SYNC-PART',
            'sales_invoice_id' => $invoice->id,
            'customer_id' => $customer->id,
            'branch_id' => $location->id,
            'status' => 'refunded',
            'return_date' => now()->toDateString(),
            'reason' => 'Partial return',
            'created_by' => $user->id,
        ]);

        ReturnLine::create([
            'return_order_id' => $returnOrder->id,
            'sales_invoice_line_id' => $line->id,
            'serial_item_id' => null,
            'qty' => 1,
            'resolution' => 'restock',
        ]);

        $this->artisan('invoices:sync-returned-status')
            ->assertSuccessful()
            ->expectsOutputToContain('No posted or active invoices need a returned status update.');

        $invoice->refresh();
        $this->assertSame('posted', $invoice->status);
    }

    /**
     * @return array{0: SalesInvoice}
     */
    private function seedFullyReturnedPostedInvoice(): array
    {
        $location = Location::create([
            'code' => 'LOC-SYNC-RET',
            'name' => 'Sync return branch',
            'type' => 'branch',
            'is_active' => true,
        ]);

        $user = User::factory()->create([
            'role' => 'admin',
            'is_active' => true,
            'branch_id' => $location->id,
        ]);

        $customer = Customer::create([
            'customer_code' => 'C-SYNC-RET',
            'name' => 'Sync Return Customer',
            'phone' => '01700000555',
            'is_active' => true,
        ]);

        $brand = Brand::create(['name' => 'Sync Brand']);
        $category = Category::create(['name' => 'Sync Category']);
        $product = Product::create([
            'sku' => 'SKU-SYNC',
            'brand_id' => $brand->id,
            'category_id' => $category->id,
            'name' => 'Sync Product',
            'warranty_months' => 12,
            'default_purchase_price' => 8000,
            'default_sale_price' => 12000,
            'reorder_level' => 0,
            'unit_of_measure' => 'unit',
            'is_serialized' => true,
            'is_active' => true,
        ]);

        $invoice = SalesInvoice::create([
            'invoice_number' => 'INV-SYNC-RET',
            'invoice_date' => now()->toDateString(),
            'branch_id' => $location->id,
            'customer_id' => $customer->id,
            'sale_type' => 'default',
            'status' => 'posted',
            'payment_status' => 'paid',
            'subtotal' => 12000,
            'discount_total' => 0,
            'tax_total' => 0,
            'grand_total' => 12000,
            'amount_paid' => 12000,
            'amount_due' => 0,
            'created_by' => $user->id,
        ]);

        $line = SalesInvoiceLine::create([
            'sales_invoice_id' => $invoice->id,
            'product_id' => $product->id,
            'qty' => 1,
            'unit_price' => 12000,
            'discount_amount' => 0,
            'tax_amount' => 0,
            'line_total' => 12000,
            'warranty_months_snapshot' => 12,
        ]);

        $serial = SerialItem::create([
            'product_id' => $product->id,
            'serial_number' => 'SN-SYNC-001',
            'serial_source' => 'internal',
            'current_location_id' => $location->id,
            'status' => 'in_stock',
            'sale_invoice_line_id' => null,
            'purchase_cost' => 8000,
        ]);

        SaleLineSerial::create([
            'sales_invoice_line_id' => $line->id,
            'serial_item_id' => $serial->id,
            'released_at' => now(),
        ]);

        $returnOrder = ReturnOrder::create([
            'return_number' => 'RET-SYNC-001',
            'sales_invoice_id' => $invoice->id,
            'customer_id' => $customer->id,
            'branch_id' => $location->id,
            'status' => 'refunded',
            'return_date' => now()->toDateString(),
            'reason' => 'Legacy return',
            'created_by' => $user->id,
        ]);

        ReturnLine::create([
            'return_order_id' => $returnOrder->id,
            'sales_invoice_line_id' => $line->id,
            'serial_item_id' => $serial->id,
            'qty' => null,
            'resolution' => 'restock',
        ]);

        return [$invoice];
    }
}
