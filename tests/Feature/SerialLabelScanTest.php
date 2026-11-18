<?php

namespace Tests\Feature;

use App\Models\Brand;
use App\Models\Category;
use App\Models\Location;
use App\Models\Product;
use App\Models\SerialItem;
use App\Models\User;
use App\Support\SerialLabelCode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SerialLabelScanTest extends TestCase
{
    use RefreshDatabase;

    public function test_resolve_serial_scan_matches_five_digit_label_code(): void
    {
        [$user, $branch, $serial] = $this->seedInStockSerial();

        $scan = SerialLabelCode::format((int) $serial->label_code);

        $response = $this->actingAs($user)->getJson(route('sales.invoices.resolve-serial-scan', [
            'branch_id' => $branch->id,
            'code' => $scan,
        ]));

        $response->assertOk();
        $response->assertJsonPath('data.serial.id', $serial->id);
        $response->assertJsonPath('data.product_id', $serial->product_id);
    }

    public function test_invoice_serial_search_finds_unit_by_label_code(): void
    {
        [$user, $branch, $serial] = $this->seedInStockSerial();

        $response = $this->actingAs($user)->getJson(route('sales.invoices.serial-suggestions', [
            'branch_id' => $branch->id,
            'product_id' => $serial->product_id,
            'query' => SerialLabelCode::format((int) $serial->label_code),
        ]));

        $response->assertOk();
        $ids = collect($response->json('data'))->pluck('id')->all();
        $this->assertContains($serial->id, $ids);
    }

    /**
     * @return array{0: User, 1: Location, 2: SerialItem}
     */
    private function seedInStockSerial(): array
    {
        $branch = Location::create([
            'code' => 'BR-SCAN',
            'name' => 'Main branch',
            'type' => 'branch',
            'is_active' => true,
        ]);

        $user = User::factory()->create([
            'role' => 'admin',
            'is_active' => true,
            'branch_id' => $branch->id,
        ]);

        $brand = Brand::create(['name' => 'B', 'country_of_origin' => 'BD']);
        $category = Category::create(['name' => 'C', 'parent_id' => null, 'sort_order' => 0]);
        $product = Product::create([
            'sku' => 'SKU-SCAN',
            'brand_id' => $brand->id,
            'category_id' => $category->id,
            'name' => 'Scan product',
            'warranty_months' => 12,
            'default_purchase_price' => 1,
            'default_sale_price' => 2,
            'reorder_level' => 0,
            'unit_of_measure' => 'unit',
            'is_serialized' => true,
            'is_active' => true,
        ]);

        $serial = SerialItem::create([
            'product_id' => $product->id,
            'serial_number' => 'AST-20260527-000099',
            'serial_source' => 'internal',
            'current_location_id' => $branch->id,
            'status' => 'in_stock',
            'purchase_cost' => 1,
        ]);

        return [$user, $branch, $serial];
    }
}
