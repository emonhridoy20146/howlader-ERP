<?php

namespace Tests\Feature;

use App\Models\Brand;
use App\Models\Category;
use App\Models\Customer;
use App\Models\Location;
use App\Models\Product;
use App\Models\SaleLineSerial;
use App\Models\SalesInvoice;
use App\Models\SalesInvoiceLine;
use App\Models\SerialItem;
use App\Models\StockLedger;
use App\Models\User;
use App\Services\InventoryBalanceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class ReconcileSerialSalesLedgerCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_inserts_missing_sale_issue_and_updates_balance(): void
    {
        $user = User::factory()->create(['role' => 'admin']);
        $brand = Brand::query()->create(['name' => 'Rsl brand', 'country_of_origin' => null]);
        $category = Category::query()->create(['name' => 'Rsl cat', 'parent_id' => null, 'sort_order' => 0]);
        $location = Location::query()->create([
            'code' => 'RSL-1',
            'name' => 'Serial sale branch',
            'type' => 'branch',
            'address' => null,
            'phone' => null,
            'manager_name' => null,
            'is_active' => true,
            'dizicashier_uuid' => null,
        ]);
        $product = Product::query()->create([
            'sku' => 'RSL-SER-P1',
            'barcode' => '9555000000001',
            'brand_id' => $brand->id,
            'category_id' => $category->id,
            'name' => 'Serialized unit',
            'is_serialized' => true,
            'default_purchase_price' => 100,
            'default_sale_price' => 120,
        ]);

        $serial = SerialItem::query()->create([
            'product_id' => $product->id,
            'serial_number' => 'RSL-SN-001',
            'current_location_id' => $location->id,
            'status' => 'sold',
            'goods_receipt_line_id' => null,
            'purchase_cost' => 100,
        ]);

        StockLedger::query()->create([
            'event_time' => now(),
            'product_id' => $product->id,
            'serial_item_id' => $serial->id,
            'location_id' => $location->id,
            'movement_type' => 'purchase_receive',
            'quantity_delta' => 1,
            'unit_cost' => 100,
            'reference_type' => 'TestGrn',
            'reference_id' => 1,
            'actor_user_id' => $user->id,
        ]);

        app(InventoryBalanceService::class)->recalculate($product->id, $location->id);

        $customer = Customer::query()->create([
            'customer_code' => 'C-RSL',
            'name' => 'RSL Customer',
            'phone' => '01701000001',
            'is_active' => true,
        ]);

        $invoice = SalesInvoice::query()->create([
            'invoice_number' => 'INV-RSL-1',
            'invoice_date' => now()->toDateString(),
            'branch_id' => $location->id,
            'customer_id' => $customer->id,
            'sale_type' => 'cash',
            'status' => 'posted',
            'payment_status' => 'paid',
            'subtotal' => 120,
            'discount_total' => 0,
            'tax_total' => 0,
            'grand_total' => 120,
            'amount_paid' => 120,
            'amount_due' => 0,
            'created_by' => $user->id,
            'posted_by' => $user->id,
            'posted_at' => now(),
        ]);

        $line = SalesInvoiceLine::query()->create([
            'sales_invoice_id' => $invoice->id,
            'product_id' => $product->id,
            'qty' => 1,
            'unit_price' => 120,
            'discount_amount' => 0,
            'tax_amount' => 0,
            'line_total' => 120,
            'warranty_months_snapshot' => 12,
        ]);

        $serial->update(['sale_invoice_line_id' => $line->id]);

        SaleLineSerial::query()->create([
            'sales_invoice_line_id' => $line->id,
            'serial_item_id' => $serial->id,
        ]);

        $exit = Artisan::call('inventory:reconcile-serial-sales-ledger');
        $this->assertSame(0, $exit);

        $this->assertSame(0, (int) StockLedger::query()
            ->where('product_id', $product->id)
            ->where('location_id', $location->id)
            ->sum('quantity_delta'));

        $this->assertDatabaseHas('inventory_balances', [
            'product_id' => $product->id,
            'location_id' => $location->id,
            'on_hand_qty' => 0,
        ]);
    }

    public function test_dry_run_does_not_insert(): void
    {
        $user = User::factory()->create(['role' => 'admin']);
        $brand = Brand::query()->create(['name' => 'Rsl2 brand', 'country_of_origin' => null]);
        $category = Category::query()->create(['name' => 'Rsl2 cat', 'parent_id' => null, 'sort_order' => 0]);
        $location = Location::query()->create([
            'code' => 'RSL-2',
            'name' => 'Serial sale branch 2',
            'type' => 'branch',
            'address' => null,
            'phone' => null,
            'manager_name' => null,
            'is_active' => true,
            'dizicashier_uuid' => null,
        ]);
        $product = Product::query()->create([
            'sku' => 'RSL-SER-P2',
            'barcode' => '9555000000002',
            'brand_id' => $brand->id,
            'category_id' => $category->id,
            'name' => 'Serialized unit 2',
            'is_serialized' => true,
            'default_purchase_price' => 50,
            'default_sale_price' => 60,
        ]);

        $serial = SerialItem::query()->create([
            'product_id' => $product->id,
            'serial_number' => 'RSL-SN-002',
            'current_location_id' => $location->id,
            'status' => 'sold',
            'goods_receipt_line_id' => null,
            'purchase_cost' => 50,
        ]);

        StockLedger::query()->create([
            'event_time' => now(),
            'product_id' => $product->id,
            'serial_item_id' => $serial->id,
            'location_id' => $location->id,
            'movement_type' => 'purchase_receive',
            'quantity_delta' => 1,
            'unit_cost' => 50,
            'reference_type' => 'TestGrn',
            'reference_id' => 2,
            'actor_user_id' => $user->id,
        ]);

        $customer = Customer::query()->create([
            'customer_code' => 'C-RSL2',
            'name' => 'RSL2 Customer',
            'phone' => '01701000002',
            'is_active' => true,
        ]);

        $invoice = SalesInvoice::query()->create([
            'invoice_number' => 'INV-RSL-2',
            'invoice_date' => now()->toDateString(),
            'branch_id' => $location->id,
            'customer_id' => $customer->id,
            'sale_type' => 'cash',
            'status' => 'posted',
            'payment_status' => 'paid',
            'subtotal' => 60,
            'discount_total' => 0,
            'tax_total' => 0,
            'grand_total' => 60,
            'amount_paid' => 60,
            'amount_due' => 0,
            'created_by' => $user->id,
            'posted_by' => $user->id,
            'posted_at' => now(),
        ]);

        $line = SalesInvoiceLine::query()->create([
            'sales_invoice_id' => $invoice->id,
            'product_id' => $product->id,
            'qty' => 1,
            'unit_price' => 60,
            'discount_amount' => 0,
            'tax_amount' => 0,
            'line_total' => 60,
            'warranty_months_snapshot' => 12,
        ]);

        $serial->update(['sale_invoice_line_id' => $line->id]);

        SaleLineSerial::query()->create([
            'sales_invoice_line_id' => $line->id,
            'serial_item_id' => $serial->id,
        ]);

        Artisan::call('inventory:reconcile-serial-sales-ledger', ['--dry-run' => true]);

        $this->assertFalse(
            StockLedger::query()
                ->where('serial_item_id', $serial->id)
                ->where('movement_type', 'sale_issue')
                ->exists()
        );
    }
}
