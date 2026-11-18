<?php

namespace Tests\Feature;

use App\Models\Brand;
use App\Models\Category;
use App\Models\Location;
use App\Models\Permission;
use App\Models\Product;
use App\Models\Role;
use App\Models\SerialItem;
use App\Models\User;
use App\Support\SerialLabelCode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TransferSerialScanTest extends TestCase
{
    use RefreshDatabase;

    public function test_resolve_serial_scan_returns_available_unit_at_source_branch(): void
    {
        [$user, $source, $serial] = $this->seedAvailableSerial();

        $response = $this->actingAs($user)->getJson(route('transfers.resolve-serial-scan', [
            'source_location_id' => $source->id,
            'code' => $serial->serial_number,
        ]));

        $response->assertOk();
        $response->assertJsonPath('data.id', $serial->id);
        $response->assertJsonPath('data.serial_number', $serial->serial_number);
    }

    public function test_resolve_serial_scan_matches_label_code(): void
    {
        [$user, $source, $serial] = $this->seedAvailableSerial();
        $serial->update(['label_code' => 12345]);

        $response = $this->actingAs($user)->getJson(route('transfers.resolve-serial-scan', [
            'source_location_id' => $source->id,
            'code' => SerialLabelCode::format(12345),
        ]));

        $response->assertOk();
        $response->assertJsonPath('data.id', $serial->id);
    }

    public function test_resolve_serial_scan_rejects_unit_at_other_branch(): void
    {
        [$user, $source, $serial] = $this->seedAvailableSerial();
        $otherBranch = Location::create([
            'code' => 'OTH',
            'name' => 'Other Branch',
            'type' => 'branch',
            'is_active' => true,
        ]);

        $response = $this->actingAs($user)->getJson(route('transfers.resolve-serial-scan', [
            'source_location_id' => $otherBranch->id,
            'code' => $serial->serial_number,
        ]));

        $response->assertOk();
        $response->assertJsonPath('data', null);
        $response->assertJsonPath('error', 'No available in-stock serial matched this scan at the source warehouse.');
    }

    /**
     * @return array{0: User, 1: Location, 2: SerialItem}
     */
    private function seedAvailableSerial(): array
    {
        $source = Location::create([
            'code' => 'SRC-SCAN',
            'name' => 'Source Branch',
            'type' => 'branch',
            'is_active' => true,
        ]);

        $user = $this->userWithTransferCreate($source);

        $brand = Brand::create(['name' => 'Brand', 'country_of_origin' => 'BD']);
        $category = Category::create(['name' => 'Category', 'parent_id' => null, 'sort_order' => 0]);
        $product = Product::create([
            'sku' => 'SKU-TRF',
            'brand_id' => $brand->id,
            'category_id' => $category->id,
            'name' => 'Transfer product',
            'warranty_months' => 12,
            'default_purchase_price' => 100,
            'default_sale_price' => 150,
            'reorder_level' => 0,
            'unit_of_measure' => 'unit',
            'is_serialized' => true,
            'is_active' => true,
        ]);

        $serial = SerialItem::create([
            'product_id' => $product->id,
            'serial_number' => 'AST-TRF-000001',
            'serial_source' => 'internal',
            'current_location_id' => $source->id,
            'status' => 'in_stock',
            'purchase_cost' => 100,
        ]);

        return [$user, $source, $serial];
    }

    private function userWithTransferCreate(Location $location): User
    {
        $role = Role::query()->create([
            'slug' => 'tr-create-'.random_int(1000, 9999),
            'name' => 'Transfer creator',
        ]);

        $permissionIds = Permission::query()
            ->whereIn('slug', [
                'stock-transfer.view-any',
                'stock-transfer.view',
                'stock-transfer.create',
            ])
            ->pluck('id')
            ->all();

        $role->permissions()->sync($permissionIds);

        $user = User::factory()->create([
            'role' => 'sales',
            'is_active' => true,
            'branch_id' => $location->id,
        ]);
        $user->roles()->sync([$role->id]);
        $user->accessibleLocations()->sync([$location->id]);

        return $user;
    }
}
