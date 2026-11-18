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
use Tests\TestCase;

class InventoryStockLevelsTest extends TestCase
{
    use RefreshDatabase;

    public function test_non_serialized_manual_adjust_updates_ledger_and_balance(): void
    {
        $user = User::factory()->create(['role' => 'admin']);
        $brand = Brand::query()->create(['name' => 'T brand', 'country_of_origin' => null]);
        $category = Category::query()->create(['name' => 'T cat', 'parent_id' => null, 'sort_order' => 0]);
        $location = Location::query()->create([
            'code' => 'T-1',
            'name' => 'Test branch',
            'type' => 'branch',
            'address' => null,
            'phone' => null,
            'manager_name' => null,
            'is_active' => true,
            'dizicashier_uuid' => null,
        ]);
        $product = Product::query()->create([
            'sku' => 'NON-SERIAL-T',
            'barcode' => '9550111122222',
            'brand_id' => $brand->id,
            'category_id' => $category->id,
            'name' => 'Non-serial widget',
            'is_serialized' => false,
            'default_purchase_price' => 50,
            'default_sale_price' => 75,
            'warranty_months' => 0,
            'reorder_level' => 0,
            'unit_of_measure' => 'pc',
            'is_active' => true,
        ]);

        StockLedger::query()->create([
            'event_time' => now(),
            'product_id' => $product->id,
            'serial_item_id' => null,
            'location_id' => $location->id,
            'movement_type' => 'adjust_in',
            'quantity_delta' => 10,
            'unit_cost' => 50,
            'reference_type' => 'Seed',
            'reference_id' => 1,
            'actor_user_id' => $user->id,
        ]);
        app(InventoryBalanceService::class)->recalculate($product->id, $location->id);

        $this->actingAs($user)->post(route('inventory.stock-levels.adjust.store'), [
            'product_id' => $product->id,
            'location_id' => $location->id,
            'target_on_hand_qty' => 7,
            'reason' => 'Cycle count',
        ])->assertRedirect($this->adjustFormUrl($product, $location));

        $this->assertSame(
            7,
            (int) StockLedger::query()
                ->where('product_id', $product->id)
                ->where('location_id', $location->id)
                ->sum('quantity_delta')
        );

        app(InventoryBalanceService::class)->recalculate($product->id, $location->id);
        $this->assertDatabaseHas('inventory_balances', [
            'product_id' => $product->id,
            'location_id' => $location->id,
            'on_hand_qty' => 7,
        ]);
    }

    public function test_absolute_target_zero_is_allowed_when_qty_delta_zero_is_also_submitted(): void
    {
        $user = User::factory()->create(['role' => 'admin']);
        $brand = Brand::query()->create(['name' => 'Z brand', 'country_of_origin' => null]);
        $category = Category::query()->create(['name' => 'Z cat', 'parent_id' => null, 'sort_order' => 0]);
        $location = Location::query()->create([
            'code' => 'Z-1',
            'name' => 'Zero branch',
            'type' => 'branch',
            'address' => null,
            'phone' => null,
            'manager_name' => null,
            'is_active' => true,
            'dizicashier_uuid' => null,
        ]);
        $product = Product::query()->create([
            'sku' => 'ZERO-T',
            'barcode' => null,
            'brand_id' => $brand->id,
            'category_id' => $category->id,
            'name' => 'Zero stock item',
            'is_serialized' => false,
            'default_purchase_price' => 10,
            'default_sale_price' => 15,
            'warranty_months' => 0,
            'reorder_level' => 0,
            'unit_of_measure' => 'pc',
            'is_active' => true,
        ]);

        StockLedger::query()->create([
            'event_time' => now(),
            'product_id' => $product->id,
            'serial_item_id' => null,
            'location_id' => $location->id,
            'movement_type' => 'adjust_in',
            'quantity_delta' => 4,
            'unit_cost' => 10,
            'reference_type' => 'Seed',
            'reference_id' => 1,
            'actor_user_id' => $user->id,
        ]);
        app(InventoryBalanceService::class)->recalculate($product->id, $location->id);

        // The adjust UI can submit qty_delta=0 alongside absolute mode; validation must not reject target 0.
        $this->actingAs($user)->post(route('inventory.stock-levels.adjust.store'), [
            'product_id' => $product->id,
            'location_id' => $location->id,
            'adjust_mode' => 'absolute',
            'target_on_hand_qty' => 0,
            'qty_delta' => 0,
        ])->assertRedirect($this->adjustFormUrl($product, $location));

        $this->assertSame(
            0,
            (int) StockLedger::query()
                ->where('product_id', $product->id)
                ->where('location_id', $location->id)
                ->sum('quantity_delta'),
        );
    }

    public function test_recalculate_persists_negative_on_hand_and_absolute_adjust_can_correct(): void
    {
        $user = User::factory()->create(['role' => 'admin']);
        $brand = Brand::query()->create(['name' => 'Neg brand', 'country_of_origin' => null]);
        $category = Category::query()->create(['name' => 'Neg cat', 'parent_id' => null, 'sort_order' => 0]);
        $location = Location::query()->create([
            'code' => 'NEG-1',
            'name' => 'Neg branch',
            'type' => 'branch',
            'address' => null,
            'phone' => null,
            'manager_name' => null,
            'is_active' => true,
            'dizicashier_uuid' => null,
        ]);
        $product = Product::query()->create([
            'sku' => 'NEG-T',
            'barcode' => null,
            'brand_id' => $brand->id,
            'category_id' => $category->id,
            'name' => 'Negative stock item',
            'is_serialized' => false,
            'default_purchase_price' => 10,
            'default_sale_price' => 15,
            'warranty_months' => 0,
            'reorder_level' => 0,
            'unit_of_measure' => 'pc',
            'is_active' => true,
        ]);

        StockLedger::query()->create([
            'event_time' => now(),
            'product_id' => $product->id,
            'serial_item_id' => null,
            'location_id' => $location->id,
            'movement_type' => 'adjust_out',
            'quantity_delta' => -5,
            'unit_cost' => null,
            'reference_type' => 'Seed',
            'reference_id' => 1,
            'actor_user_id' => $user->id,
        ]);

        app(InventoryBalanceService::class)->recalculate($product->id, $location->id);
        $this->assertDatabaseHas('inventory_balances', [
            'product_id' => $product->id,
            'location_id' => $location->id,
            'on_hand_qty' => -5,
            'available_qty' => 0,
        ]);

        $this->actingAs($user)->post(route('inventory.stock-levels.adjust.store'), [
            'product_id' => $product->id,
            'location_id' => $location->id,
            'adjust_mode' => 'absolute',
            'target_on_hand_qty' => 3,
            'qty_delta' => 0,
        ])->assertRedirect($this->adjustFormUrl($product, $location));

        $this->assertSame(
            3,
            (int) StockLedger::query()
                ->where('product_id', $product->id)
                ->where('location_id', $location->id)
                ->sum('quantity_delta'),
        );

        app(InventoryBalanceService::class)->recalculate($product->id, $location->id);
        $this->assertDatabaseHas('inventory_balances', [
            'product_id' => $product->id,
            'location_id' => $location->id,
            'on_hand_qty' => 3,
            'available_qty' => 3,
        ]);
    }

    public function test_serialized_decrease_requires_serial_ids_and_marks_damaged(): void
    {
        $user = User::factory()->create(['role' => 'admin']);
        $brand = Brand::query()->create(['name' => 'S brand', 'country_of_origin' => null]);
        $category = Category::query()->create(['name' => 'S cat', 'parent_id' => null, 'sort_order' => 0]);
        $location = Location::query()->create([
            'code' => 'S-1',
            'name' => 'Serial branch',
            'type' => 'branch',
            'address' => null,
            'phone' => null,
            'manager_name' => null,
            'is_active' => true,
            'dizicashier_uuid' => null,
        ]);
        $product = Product::query()->create([
            'sku' => 'SERIAL-T',
            'barcode' => '9550111133333',
            'brand_id' => $brand->id,
            'category_id' => $category->id,
            'name' => 'Serial widget',
            'is_serialized' => true,
            'default_purchase_price' => 100,
            'default_sale_price' => 120,
            'warranty_months' => 12,
            'reorder_level' => 0,
            'unit_of_measure' => 'pc',
            'is_active' => true,
        ]);

        StockLedger::query()->create([
            'event_time' => now(),
            'product_id' => $product->id,
            'serial_item_id' => null,
            'location_id' => $location->id,
            'movement_type' => 'adjust_in',
            'quantity_delta' => 2,
            'unit_cost' => null,
            'reference_type' => 'Seed',
            'reference_id' => 1,
            'actor_user_id' => $user->id,
        ]);

        $s1 = SerialItem::query()->create([
            'product_id' => $product->id,
            'serial_number' => 'SN-T-AAA',
            'current_location_id' => $location->id,
            'status' => 'in_stock',
            'goods_receipt_line_id' => null,
            'sale_invoice_line_id' => null,
            'purchase_cost' => 100,
        ]);
        $s2 = SerialItem::query()->create([
            'product_id' => $product->id,
            'serial_number' => 'SN-T-BBB',
            'current_location_id' => $location->id,
            'status' => 'in_stock',
            'goods_receipt_line_id' => null,
            'sale_invoice_line_id' => null,
            'purchase_cost' => 100,
        ]);

        app(InventoryBalanceService::class)->recalculate($product->id, $location->id);

        $this->actingAs($user)->post(route('inventory.stock-levels.adjust.store'), [
            'product_id' => $product->id,
            'location_id' => $location->id,
            'target_on_hand_qty' => 1,
            'serial_ids' => [$s1->id],
        ])->assertRedirect($this->adjustFormUrl($product, $location));

        $s1->refresh();
        $this->assertSame('damaged', $s1->status);
        $this->assertNull($s1->current_location_id);
        $s2->refresh();
        $this->assertSame('in_stock', $s2->status);

        $this->assertSame(
            1,
            (int) StockLedger::query()
                ->where('product_id', $product->id)
                ->where('location_id', $location->id)
                ->sum('quantity_delta')
        );

        $this->assertTrue(
            StockLedger::query()
                ->where('serial_item_id', $s1->id)
                ->where('movement_type', 'damage_out')
                ->exists()
        );
    }

    public function test_serialized_increase_auto_generates_serials(): void
    {
        $user = User::factory()->create(['role' => 'admin']);
        $brand = Brand::query()->create(['name' => 'U brand', 'country_of_origin' => null]);
        $category = Category::query()->create(['name' => 'U cat', 'parent_id' => null, 'sort_order' => 0]);
        $location = Location::query()->create([
            'code' => 'U-1',
            'name' => 'Up branch',
            'type' => 'branch',
            'address' => null,
            'phone' => null,
            'manager_name' => null,
            'is_active' => true,
            'dizicashier_uuid' => null,
        ]);
        $product = Product::query()->create([
            'sku' => 'SERIAL-U',
            'barcode' => '9550111144444',
            'brand_id' => $brand->id,
            'category_id' => $category->id,
            'name' => 'Serial up',
            'is_serialized' => true,
            'default_purchase_price' => 100,
            'default_sale_price' => 120,
            'warranty_months' => 12,
            'reorder_level' => 0,
            'unit_of_measure' => 'pc',
            'is_active' => true,
        ]);

        StockLedger::query()->create([
            'event_time' => now(),
            'product_id' => $product->id,
            'serial_item_id' => null,
            'location_id' => $location->id,
            'movement_type' => 'adjust_in',
            'quantity_delta' => 1,
            'unit_cost' => null,
            'reference_type' => 'Seed',
            'reference_id' => 1,
            'actor_user_id' => $user->id,
        ]);
        SerialItem::query()->create([
            'product_id' => $product->id,
            'serial_number' => 'SN-U-ONLY',
            'current_location_id' => $location->id,
            'status' => 'in_stock',
            'goods_receipt_line_id' => null,
            'sale_invoice_line_id' => null,
            'purchase_cost' => 100,
        ]);

        app(InventoryBalanceService::class)->recalculate($product->id, $location->id);

        $this->actingAs($user)->from(route('inventory.stock-levels.adjust', [
            'product_id' => $product->id,
            'location_id' => $location->id,
        ]))->post(route('inventory.stock-levels.adjust.store'), [
            'product_id' => $product->id,
            'location_id' => $location->id,
            'adjust_mode' => 'relative',
            'qty_delta' => 2,
            'serialized_add_mode' => 'auto',
        ])->assertRedirect($this->adjustFormUrl($product, $location));

        app(InventoryBalanceService::class)->recalculate($product->id, $location->id);
        $this->assertSame(
            3,
            (int) SerialItem::query()
                ->where('product_id', $product->id)
                ->where('current_location_id', $location->id)
                ->where('status', 'in_stock')
                ->count(),
        );

        $this->assertSame(
            3,
            (int) StockLedger::query()
                ->where('product_id', $product->id)
                ->where('location_id', $location->id)
                ->where('movement_type', 'adjust_in')
                ->count(),
        );
    }

    public function test_serialized_increase_custom_serials(): void
    {
        $user = User::factory()->create(['role' => 'admin']);
        $brand = Brand::query()->create(['name' => 'V brand', 'country_of_origin' => null]);
        $category = Category::query()->create(['name' => 'V cat', 'parent_id' => null, 'sort_order' => 0]);
        $location = Location::query()->create([
            'code' => 'V-1',
            'name' => 'Custom branch',
            'type' => 'branch',
            'address' => null,
            'phone' => null,
            'manager_name' => null,
            'is_active' => true,
            'dizicashier_uuid' => null,
        ]);
        $product = Product::query()->create([
            'sku' => 'SERIAL-V',
            'barcode' => '9550111155555',
            'brand_id' => $brand->id,
            'category_id' => $category->id,
            'name' => 'Serial custom',
            'is_serialized' => true,
            'default_purchase_price' => 50,
            'default_sale_price' => 75,
            'warranty_months' => 0,
            'reorder_level' => 0,
            'unit_of_measure' => 'pc',
            'is_active' => true,
        ]);

        StockLedger::query()->create([
            'event_time' => now(),
            'product_id' => $product->id,
            'serial_item_id' => null,
            'location_id' => $location->id,
            'movement_type' => 'adjust_in',
            'quantity_delta' => 1,
            'unit_cost' => 50,
            'reference_type' => 'Seed',
            'reference_id' => 1,
            'actor_user_id' => $user->id,
        ]);

        SerialItem::query()->create([
            'product_id' => $product->id,
            'serial_number' => 'SN-V-EXIST',
            'current_location_id' => $location->id,
            'status' => 'in_stock',
            'goods_receipt_line_id' => null,
            'sale_invoice_line_id' => null,
            'purchase_cost' => 50,
        ]);

        app(InventoryBalanceService::class)->recalculate($product->id, $location->id);

        $this->actingAs($user)->post(route('inventory.stock-levels.adjust.store'), [
            'product_id' => $product->id,
            'location_id' => $location->id,
            'adjust_mode' => 'relative',
            'qty_delta' => 2,
            'serialized_add_mode' => 'custom',
            'custom_serial_lines' => "CUST-SN-AAA\nCUST-SN-BBB\r\n",
        ])->assertRedirect($this->adjustFormUrl($product, $location));

        $this->assertTrue(SerialItem::query()->where('serial_number', 'CUST-SN-AAA')->exists());
        $this->assertTrue(SerialItem::query()->where('serial_number', 'CUST-SN-BBB')->exists());
        app(InventoryBalanceService::class)->recalculate($product->id, $location->id);
        $this->assertDatabaseHas('inventory_balances', [
            'product_id' => $product->id,
            'location_id' => $location->id,
            'on_hand_qty' => 3,
        ]);
    }

    public function test_absolute_increase_serialized_requires_custom_serial_count(): void
    {
        $user = User::factory()->create(['role' => 'admin']);
        $brand = Brand::query()->create(['name' => 'W brand', 'country_of_origin' => null]);
        $category = Category::query()->create(['name' => 'W cat', 'parent_id' => null, 'sort_order' => 0]);
        $location = Location::query()->create([
            'code' => 'W-1',
            'name' => 'Bad custom branch',
            'type' => 'branch',
            'address' => null,
            'phone' => null,
            'manager_name' => null,
            'is_active' => true,
            'dizicashier_uuid' => null,
        ]);
        $product = Product::query()->create([
            'sku' => 'SERIAL-W',
            'barcode' => null,
            'brand_id' => $brand->id,
            'category_id' => $category->id,
            'name' => 'Serial bad custom',
            'is_serialized' => true,
            'default_purchase_price' => 10,
            'default_sale_price' => 15,
            'warranty_months' => 0,
            'reorder_level' => 0,
            'unit_of_measure' => 'pc',
            'is_active' => true,
        ]);

        StockLedger::query()->create([
            'event_time' => now(),
            'product_id' => $product->id,
            'serial_item_id' => null,
            'location_id' => $location->id,
            'movement_type' => 'adjust_in',
            'quantity_delta' => 1,
            'unit_cost' => 10,
            'reference_type' => 'Seed',
            'reference_id' => 1,
            'actor_user_id' => $user->id,
        ]);
        SerialItem::query()->create([
            'product_id' => $product->id,
            'serial_number' => 'SN-W-ONLY',
            'current_location_id' => $location->id,
            'status' => 'in_stock',
            'goods_receipt_line_id' => null,
            'sale_invoice_line_id' => null,
            'purchase_cost' => 10,
        ]);
        app(InventoryBalanceService::class)->recalculate($product->id, $location->id);

        $this->actingAs($user)->post(route('inventory.stock-levels.adjust.store'), [
            'product_id' => $product->id,
            'location_id' => $location->id,
            'adjust_mode' => 'absolute',
            'target_on_hand_qty' => 3,
            'serialized_add_mode' => 'custom',
            'custom_serial_lines' => 'ONLY-ONE-LINENO',
        ])->assertSessionHasErrors('custom_serial_lines');
    }

    private function adjustFormUrl(Product $product, Location $location): string
    {
        return route('inventory.stock-levels.adjust', [
            'product_id' => $product->id,
            'location_id' => $location->id,
        ]);
    }
}
