<?php

namespace Tests\Feature;

use App\Models\Brand;
use App\Models\Category;
use App\Models\Location;
use App\Models\Product;
use App\Models\SerialItem;
use App\Models\StockLedger;
use App\Models\User;
use App\Services\InventoryBalanceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class InventoryBackfillSerialsLedgerPairsTest extends TestCase
{
    use RefreshDatabase;

    public function test_backfill_creates_serials_when_stock_is_only_on_ledger_without_balance_row(): void
    {
        $user = User::factory()->create();
        $brand = Brand::query()->create(['name' => 'Lf brand', 'country_of_origin' => null]);
        $category = Category::query()->create(['name' => 'Lf cat', 'parent_id' => null, 'sort_order' => 0]);
        $location = Location::query()->create([
            'code' => 'LF-1',
            'name' => 'Ledger-first',
            'type' => 'branch',
            'address' => null,
            'phone' => null,
            'manager_name' => null,
            'is_active' => true,
            'dizicashier_uuid' => null,
        ]);
        $product = Product::query()->create([
            'sku' => 'LF-SER-P1',
            'barcode' => '9555555555555',
            'brand_id' => $brand->id,
            'category_id' => $category->id,
            'name' => 'Ledger only product',
            'is_serialized' => true,
            'default_purchase_price' => 100,
            'default_sale_price' => 120,
        ]);

        StockLedger::query()->create([
            'event_time' => now(),
            'product_id' => $product->id,
            'serial_item_id' => null,
            'location_id' => $location->id,
            'movement_type' => 'adjust_in',
            'quantity_delta' => 3,
            'unit_cost' => null,
            'reference_type' => 'TestAdjustment',
            'reference_id' => 1,
            'actor_user_id' => $user->id,
        ]);

        $this->assertDatabaseMissing('inventory_balances', [
            'product_id' => $product->id,
            'location_id' => $location->id,
        ]);

        $exit = Artisan::call('inventory:backfill-serials');
        $this->assertSame(0, $exit);

        $this->assertSame(
            3,
            SerialItem::query()
                ->where('product_id', $product->id)
                ->where('current_location_id', $location->id)
                ->where('status', 'in_stock')
                ->count()
        );

        Artisan::call('inventory:backfill-serials');
        $this->assertSame(
            3,
            SerialItem::query()
                ->where('product_id', $product->id)
                ->where('current_location_id', $location->id)
                ->where('status', 'in_stock')
                ->count(),
            'Running backfill twice must not duplicate serial rows.'
        );
    }

    public function test_surplus_removes_extra_in_stock_serials_to_match_ledger(): void
    {
        $user = User::factory()->create();
        $brand = Brand::query()->create(['name' => 'Tr brand', 'country_of_origin' => null]);
        $category = Category::query()->create(['name' => 'Tr cat', 'parent_id' => null, 'sort_order' => 0]);
        $location = Location::query()->create([
            'code' => 'TR-1',
            'name' => 'Trim surplus',
            'type' => 'branch',
            'address' => null,
            'phone' => null,
            'manager_name' => null,
            'is_active' => true,
            'dizicashier_uuid' => null,
        ]);
        $product = Product::query()->create([
            'sku' => 'LF-SUR-P1',
            'barcode' => '9555555555999',
            'brand_id' => $brand->id,
            'category_id' => $category->id,
            'name' => 'Surplus trim product',
            'is_serialized' => true,
            'default_purchase_price' => 100,
            'default_sale_price' => 120,
        ]);

        StockLedger::query()->create([
            'event_time' => now(),
            'product_id' => $product->id,
            'serial_item_id' => null,
            'location_id' => $location->id,
            'movement_type' => 'adjust_in',
            'quantity_delta' => 2,
            'unit_cost' => null,
            'reference_type' => 'TestSeed',
            'reference_id' => 1,
            'actor_user_id' => $user->id,
        ]);

        app(InventoryBalanceService::class)->recalculate($product->id, $location->id);

        for ($i = 1; $i <= 5; $i++) {
            SerialItem::query()->create([
                'product_id' => $product->id,
                'serial_number' => 'LF-SUR-EXTRA-'.$i,
                'current_location_id' => $location->id,
                'status' => 'in_stock',
                'goods_receipt_line_id' => null,
                'sale_invoice_line_id' => null,
                'purchase_cost' => 100,
            ]);
        }

        $exit = Artisan::call('inventory:backfill-serials', ['--recalculate' => true]);
        $this->assertSame(0, $exit);

        $this->assertSame(
            2,
            SerialItem::query()
                ->where('product_id', $product->id)
                ->where('current_location_id', $location->id)
                ->where('status', 'in_stock')
                ->count(),
        );
    }
}
