<?php

namespace Tests\Feature;

use App\Models\Brand;
use App\Models\Category;
use App\Models\Location;
use App\Models\Product;
use App\Models\SerialItem;
use App\Models\User;
use App\Services\PermissionSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SerialItemRegistryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(PermissionSyncService::class)->syncFromPolicies();
    }

    public function test_admin_can_search_and_update_serial_status(): void
    {
        $branch = Location::create([
            'code' => 'BR-SR',
            'name' => 'Serial Branch',
            'type' => 'branch',
            'is_active' => true,
        ]);

        $user = User::factory()->create([
            'role' => 'admin',
            'is_active' => true,
            'branch_id' => $branch->id,
        ]);

        $product = $this->makeSerializedProduct();
        $serial = SerialItem::create([
            'product_id' => $product->id,
            'serial_number' => 'AST-TEST-0001',
            'manufacturer_serial' => 'MFG-ABC',
            'current_location_id' => $branch->id,
            'status' => 'in_stock',
            'purchase_cost' => 500,
        ]);

        $this->actingAs($user)
            ->get(route('serial-items.index', ['q' => 'AST-TEST']))
            ->assertOk()
            ->assertSee('AST-TEST-0001', false);

        $this->actingAs($user)
            ->put(route('serial-items.update', $serial), ['status' => 'under_service'])
            ->assertRedirect(route('serial-items.show', $serial));

        $this->assertSame('under_service', $serial->fresh()->status);

        $this->actingAs($user)
            ->get(route('serial-items.show', $serial))
            ->assertOk()
            ->assertSee('MFG-ABC', false);
    }

    private function makeSerializedProduct(): Product
    {
        $brand = Brand::create(['name' => 'Serial Brand', 'is_active' => true]);
        $category = Category::create(['name' => 'Serial Cat', 'is_active' => true]);

        return Product::create([
            'sku' => 'SKU-SR-1',
            'barcode' => 'BC-SR-1',
            'brand_id' => $brand->id,
            'category_id' => $category->id,
            'name' => 'Serialized TV',
            'warranty_months' => 12,
            'default_purchase_price' => 500,
            'default_sale_price' => 700,
            'is_serialized' => true,
            'is_active' => true,
        ]);
    }
}
