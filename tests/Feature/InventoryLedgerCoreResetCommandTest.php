<?php

namespace Tests\Feature;

use App\Models\Brand;
use App\Models\Category;
use App\Models\Customer;
use App\Models\GoodsReceipt;
use App\Models\Location;
use App\Models\Product;
use App\Models\SalesInvoice;
use App\Models\StockLedger;
use App\Models\Supplier;
use App\Models\User;
use App\Services\InventoryBalanceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InventoryLedgerCoreResetCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_prunes_adjustments_orphans_and_keeps_linked_core_rows(): void
    {
        $brand = Brand::query()->create(['name' => 'L brand', 'country_of_origin' => null]);
        $category = Category::query()->create(['name' => 'L cat', 'parent_id' => null, 'sort_order' => 0]);
        $location = Location::query()->create([
            'code' => 'L-1',
            'name' => 'Ledger branch',
            'type' => 'branch',
            'address' => null,
            'phone' => null,
            'manager_name' => null,
            'is_active' => true,
            'dizicashier_uuid' => null,
        ]);
        $product = Product::query()->create([
            'sku' => 'LED-CORE-T',
            'barcode' => null,
            'brand_id' => $brand->id,
            'category_id' => $category->id,
            'name' => 'Ledger core widget',
            'is_serialized' => false,
            'default_purchase_price' => 40,
            'default_sale_price' => 55,
            'warranty_months' => 0,
            'reorder_level' => 0,
            'unit_of_measure' => 'pc',
            'is_active' => true,
        ]);

        $user = User::factory()->create();
        $supplier = Supplier::query()->create([
            'name' => 'L supplier',
            'contact_person' => null,
            'phone' => null,
            'email' => null,
            'address' => null,
            'tax_number' => null,
            'outstanding_balance' => 0,
            'is_active' => true,
        ]);
        $customer = Customer::query()->create([
            'customer_code' => 'LCORE-001',
            'name' => 'Ledger core customer',
            'phone' => '01000000000',
            'email' => null,
            'address' => null,
            'id_type' => null,
            'id_number' => null,
            'credit_limit' => null,
            'outstanding_balance' => 0,
            'is_active' => true,
        ]);

        $grn = GoodsReceipt::query()->create([
            'grn_number' => 'LED-CORE-GRN',
            'purchase_order_id' => null,
            'supplier_id' => $supplier->id,
            'location_id' => $location->id,
            'status' => 'posted',
            'received_at' => now()->toDateString(),
            'delivery_note_ref' => null,
            'created_by' => $user->id,
            'posted_by' => $user->id,
            'posted_at' => now(),
        ]);

        $invoice = SalesInvoice::query()->create([
            'invoice_number' => 'LED-CORE-SI',
            'invoice_date' => now()->toDateString(),
            'branch_id' => $location->id,
            'customer_id' => $customer->id,
            'sale_type' => 'cash',
            'status' => 'posted',
            'subtotal' => 100,
            'discount_total' => 0,
            'tax_total' => 0,
            'grand_total' => 100,
            'amount_paid' => 0,
            'amount_due' => 100,
            'notes' => null,
            'pdf_path' => null,
            'created_by' => $user->id,
            'posted_by' => $user->id,
            'posted_at' => now(),
        ]);

        StockLedger::query()->create([
            'event_time' => now(),
            'product_id' => $product->id,
            'serial_item_id' => null,
            'location_id' => $location->id,
            'movement_type' => 'purchase_receive',
            'quantity_delta' => 10,
            'unit_cost' => 40,
            'reference_type' => 'GoodsReceipt',
            'reference_id' => $grn->id,
            'actor_user_id' => $user->id,
        ]);
        StockLedger::query()->create([
            'event_time' => now(),
            'product_id' => $product->id,
            'serial_item_id' => null,
            'location_id' => $location->id,
            'movement_type' => 'adjust_in',
            'quantity_delta' => 5,
            'unit_cost' => 40,
            'reference_type' => 'Seed',
            'reference_id' => 7,
            'actor_user_id' => $user->id,
        ]);
        StockLedger::query()->create([
            'event_time' => now(),
            'product_id' => $product->id,
            'serial_item_id' => null,
            'location_id' => $location->id,
            'movement_type' => 'sale_issue',
            'quantity_delta' => -2,
            'unit_cost' => null,
            'reference_type' => 'SalesInvoice',
            'reference_id' => $invoice->id,
            'actor_user_id' => $user->id,
        ]);
        StockLedger::query()->create([
            'event_time' => now(),
            'product_id' => $product->id,
            'serial_item_id' => null,
            'location_id' => $location->id,
            'movement_type' => 'purchase_receive',
            'quantity_delta' => 99,
            'unit_cost' => 40,
            'reference_type' => 'GoodsReceipt',
            'reference_id' => 999991,
            'actor_user_id' => $user->id,
        ]);

        app(InventoryBalanceService::class)->recalculate($product->id, $location->id);

        $this->artisan('inventory:reset-ledger-core', ['--force' => true])
            ->assertSuccessful();

        $this->assertSame(2, (int) StockLedger::query()->count());
        $this->assertFalse(StockLedger::query()->where('movement_type', 'adjust_in')->exists());
        $this->assertFalse(
            StockLedger::query()->where('reference_type', 'GoodsReceipt')->where('reference_id', 999991)->exists(),
        );

        app(InventoryBalanceService::class)->recalculate($product->id, $location->id);
        $net = (int) StockLedger::query()
            ->where('product_id', $product->id)
            ->where('location_id', $location->id)
            ->sum('quantity_delta');

        $this->assertSame(8, $net);

        $this->assertDatabaseHas('inventory_balances', [
            'product_id' => $product->id,
            'location_id' => $location->id,
            'on_hand_qty' => 8,
        ]);
    }
}
