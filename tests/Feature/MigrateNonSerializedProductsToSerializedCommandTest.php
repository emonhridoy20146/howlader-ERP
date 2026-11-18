<?php

namespace Tests\Feature;

use App\Models\Brand;
use App\Models\Category;
use App\Models\InventoryBalance;
use App\Models\Location;
use App\Models\Product;
use App\Models\SerialItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class MigrateNonSerializedProductsToSerializedCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_dry_run_does_not_modify_products_or_serials(): void
    {
        $brand = Brand::query()->create(['name' => 'Mig brand', 'country_of_origin' => null]);
        $category = Category::query()->create(['name' => 'Mig cat', 'parent_id' => null, 'sort_order' => 0]);
        $location = Location::query()->create([
            'code' => 'MIG-1',
            'name' => 'Mig loc',
            'type' => 'branch',
            'address' => null,
            'phone' => null,
            'manager_name' => null,
            'is_active' => true,
            'dizicashier_uuid' => null,
        ]);
        $product = Product::query()->create([
            'sku' => 'MIG-NS-DRY',
            'barcode' => null,
            'brand_id' => $brand->id,
            'category_id' => $category->id,
            'name' => 'Non-serial dry',
            'is_serialized' => false,
            'default_purchase_price' => 1,
            'default_sale_price' => 2,
        ]);
        InventoryBalance::query()->updateOrInsert(
            ['product_id' => $product->id, 'location_id' => $location->id],
            ['on_hand_qty' => 2, 'reserved_qty' => 0, 'available_qty' => 2]
        );

        $exit = Artisan::call('products:migrate-to-serialized', ['--dry-run' => true]);
        $this->assertSame(0, $exit, Artisan::output());

        $product->refresh();
        $this->assertFalse($product->is_serialized);
        $this->assertNull($product->barcode);
        $this->assertSame(0, SerialItem::query()->where('product_id', $product->id)->count());
    }

    public function test_migrate_sets_serialized_barcode_and_backfills_serials(): void
    {
        $brand = Brand::query()->create(['name' => 'Mig brand 2', 'country_of_origin' => null]);
        $category = Category::query()->create(['name' => 'Mig cat 2', 'parent_id' => null, 'sort_order' => 0]);
        $location = Location::query()->create([
            'code' => 'MIG-2',
            'name' => 'Mig loc 2',
            'type' => 'branch',
            'address' => null,
            'phone' => null,
            'manager_name' => null,
            'is_active' => true,
            'dizicashier_uuid' => null,
        ]);
        $product = Product::query()->create([
            'sku' => 'MIG-NS-RUN',
            'barcode' => null,
            'brand_id' => $brand->id,
            'category_id' => $category->id,
            'name' => 'Non-serial run',
            'is_serialized' => false,
            'default_purchase_price' => 5,
            'default_sale_price' => 10,
        ]);
        InventoryBalance::query()->updateOrInsert(
            ['product_id' => $product->id, 'location_id' => $location->id],
            ['on_hand_qty' => 3, 'reserved_qty' => 0, 'available_qty' => 3]
        );

        $exit = Artisan::call('products:migrate-to-serialized');
        $this->assertSame(0, $exit, Artisan::output());

        $product->refresh();
        $this->assertTrue($product->is_serialized);
        $this->assertIsString($product->barcode);
        $this->assertMatchesRegularExpression('/^9\d{11}$/', $product->barcode);

        $this->assertSame(
            3,
            SerialItem::query()
                ->where('product_id', $product->id)
                ->where('current_location_id', $location->id)
                ->where('status', 'in_stock')
                ->count()
        );
    }

    public function test_migrate_keeps_existing_barcode(): void
    {
        $brand = Brand::query()->create(['name' => 'Mig brand 3', 'country_of_origin' => null]);
        $category = Category::query()->create(['name' => 'Mig cat 3', 'parent_id' => null, 'sort_order' => 0]);
        $location = Location::query()->create([
            'code' => 'MIG-3',
            'name' => 'Mig loc 3',
            'type' => 'branch',
            'address' => null,
            'phone' => null,
            'manager_name' => null,
            'is_active' => true,
            'dizicashier_uuid' => null,
        ]);
        $product = Product::query()->create([
            'sku' => 'MIG-NS-BC',
            'barcode' => '911111111111',
            'brand_id' => $brand->id,
            'category_id' => $category->id,
            'name' => 'Has barcode',
            'is_serialized' => false,
            'default_purchase_price' => 1,
            'default_sale_price' => 2,
        ]);
        InventoryBalance::query()->updateOrInsert(
            ['product_id' => $product->id, 'location_id' => $location->id],
            ['on_hand_qty' => 1, 'reserved_qty' => 0, 'available_qty' => 1]
        );

        Artisan::call('products:migrate-to-serialized');

        $product->refresh();
        $this->assertSame('911111111111', $product->barcode);
        $this->assertTrue($product->is_serialized);
        $this->assertSame(1, SerialItem::query()->where('product_id', $product->id)->count());
    }
}
