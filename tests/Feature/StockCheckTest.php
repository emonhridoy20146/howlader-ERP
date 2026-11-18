<?php

namespace Tests\Feature;

use App\Models\Brand;
use App\Models\Category;
use App\Models\Location;
use App\Models\Product;
use App\Models\SerialItem;
use App\Models\StockLedger;
use App\Models\User;
use App\Support\SerialLabelCode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StockCheckTest extends TestCase
{
    use RefreshDatabase;

    public function test_stock_check_lookup_finds_serial_by_label_code(): void
    {
        [$user, $branch, $serial] = $this->seedInStockSerial();

        $response = $this->actingAs($user)->getJson(route('inventory.stock-check.lookup', [
            'code' => SerialLabelCode::format((int) $serial->label_code),
            'branch_id' => $branch->id,
        ]));

        $response->assertOk();
        $response->assertJsonPath('data.match_type', 'serial');
        $response->assertJsonPath('data.serial.id', $serial->id);
        $response->assertJsonPath('data.product.sku', $serial->product->sku);
        $response->assertJsonPath('data.location.id', $branch->id);
    }

    public function test_stock_check_lookup_finds_product_by_barcode(): void
    {
        [$user, $branch, $product] = $this->seedNonSerializedProduct(7);

        $response = $this->actingAs($user)->getJson(route('inventory.stock-check.lookup', [
            'code' => $product->barcode,
            'branch_id' => $branch->id,
        ]));

        $response->assertOk();
        $response->assertJsonPath('data.match_type', 'product');
        $response->assertJsonPath('data.product.id', $product->id);
        $response->assertJsonPath('data.balance_at_branch.on_hand_qty', 7);
    }

    public function test_stock_check_screen_loads_for_authorized_user(): void
    {
        [$user] = array_pad($this->seedInStockSerial(), 1, null);

        $this->actingAs($user)
            ->get(route('inventory.stock-check.index'))
            ->assertOk()
            ->assertSee('Stock check', false)
            ->assertSee('End check', false);
    }

    public function test_stock_check_checklist_lists_branch_serials_and_bulk_products(): void
    {
        [$user, $branch, $serial] = $this->seedInStockSerial();
        [, , $product] = $this->seedNonSerializedProductAtBranch($branch, $user, 4);

        $response = $this->actingAs($user)->getJson(route('inventory.stock-check.checklist', [
            'branch_id' => $branch->id,
        ]));

        $response->assertOk();
        $response->assertJsonPath('data.branch.id', $branch->id);
        $response->assertJsonPath('data.summary.serial_count', 1);
        $response->assertJsonPath('data.summary.product_lines', 1);
        $response->assertJsonPath('data.summary.expected_units', 5);
        $response->assertJsonPath('data.serials.0.serial_id', $serial->id);
        $response->assertJsonPath('data.products.0.product_id', $product->id);
        $response->assertJsonPath('data.products.0.expected_qty', 4);
    }

    public function test_stock_check_session_scan_marks_serial_and_builds_report(): void
    {
        [$user, $branch, $serial] = $this->seedInStockSerial();

        $scan = $this->actingAs($user)->postJson(route('inventory.stock-check.scan'), [
            'branch_id' => $branch->id,
            'code' => SerialLabelCode::format((int) $serial->label_code),
            'checked_serial_ids' => [],
            'product_checked_qty' => [],
        ]);

        $scan->assertOk();
        $scan->assertJsonPath('result', 'serial');
        $scan->assertJsonPath('serial_id', $serial->id);

        $finish = $this->actingAs($user)->post(route('inventory.stock-check.finish'), [
            'branch_id' => $branch->id,
            'checked_serial_ids' => [$serial->id],
            'product_checked_qty' => [],
            'extras_json' => '[]',
        ]);

        $finish->assertRedirect(route('inventory.stock-check.report'));

        $report = $this->actingAs($user)->get(route('inventory.stock-check.report'));
        $report->assertOk();
        $report->assertSee('Physical stock check', false);
        $report->assertSee($serial->serial_number, false);
        $report->assertSee('Checked serials', false);
        $report->assertSee('report-padded-sheet', false);
        $report->assertSee('report-print-page-marker', false);
    }

    public function test_stock_check_session_scan_detects_duplicate_serial(): void
    {
        [$user, $branch, $serial] = $this->seedInStockSerial();

        $response = $this->actingAs($user)->postJson(route('inventory.stock-check.scan'), [
            'branch_id' => $branch->id,
            'code' => $serial->serial_number,
            'checked_serial_ids' => [$serial->id],
            'product_checked_qty' => [],
        ]);

        $response->assertOk();
        $response->assertJsonPath('result', 'duplicate');
    }

    /**
     * @return array{0: User, 1: Location, 2: Product}
     */
    private function seedNonSerializedProductAtBranch(Location $branch, User $user, int $branchQty): array
    {
        $brand = Brand::create(['name' => 'Brand bulk', 'country_of_origin' => 'BD']);
        $category = Category::create(['name' => 'Category bulk', 'parent_id' => null, 'sort_order' => 0]);
        $product = Product::create([
            'sku' => 'SKU-BULK-'.$branch->id,
            'barcode' => '9550999900'.$branch->id,
            'brand_id' => $brand->id,
            'category_id' => $category->id,
            'name' => 'Bulk product at branch',
            'warranty_months' => 0,
            'default_purchase_price' => 10,
            'default_sale_price' => 20,
            'reorder_level' => 0,
            'unit_of_measure' => 'unit',
            'is_serialized' => false,
            'is_active' => true,
        ]);

        StockLedger::create([
            'event_time' => now(),
            'product_id' => $product->id,
            'location_id' => $branch->id,
            'movement_type' => 'purchase_receive',
            'quantity_delta' => $branchQty,
            'unit_cost' => 10,
            'reference_type' => 'Test',
            'reference_id' => 1,
            'actor_user_id' => $user->id,
        ]);

        return [$user, $branch, $product];
    }

    /**
     * @return array{0: User, 1: Location, 2: SerialItem}
     */
    private function seedInStockSerial(): array
    {
        $branch = Location::create([
            'code' => 'BR-SCK',
            'name' => 'Stock check branch',
            'type' => 'branch',
            'is_active' => true,
        ]);

        $user = User::factory()->create([
            'role' => 'admin',
            'is_active' => true,
            'branch_id' => $branch->id,
        ]);

        $brand = Brand::create(['name' => 'Brand', 'country_of_origin' => 'BD']);
        $category = Category::create(['name' => 'Category', 'parent_id' => null, 'sort_order' => 0]);
        $product = Product::create([
            'sku' => 'SKU-SCK',
            'brand_id' => $brand->id,
            'category_id' => $category->id,
            'name' => 'Stock check product',
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
            'serial_number' => 'AST-20260710-000001',
            'serial_source' => 'internal',
            'current_location_id' => $branch->id,
            'status' => 'in_stock',
            'purchase_cost' => 100,
        ]);

        StockLedger::create([
            'event_time' => now(),
            'product_id' => $product->id,
            'serial_item_id' => $serial->id,
            'location_id' => $branch->id,
            'movement_type' => 'purchase_receive',
            'quantity_delta' => 1,
            'unit_cost' => 100,
            'reference_type' => 'Test',
            'reference_id' => 1,
            'actor_user_id' => $user->id,
        ]);

        return [$user, $branch, $serial];
    }

    /**
     * @return array{0: User, 1: Location, 2: Product}
     */
    private function seedNonSerializedProduct(int $branchQty): array
    {
        $branch = Location::create([
            'code' => 'BR-SCK2',
            'name' => 'Stock check branch 2',
            'type' => 'branch',
            'is_active' => true,
        ]);

        $user = User::factory()->create([
            'role' => 'admin',
            'is_active' => true,
            'branch_id' => $branch->id,
        ]);

        $brand = Brand::create(['name' => 'Brand 2', 'country_of_origin' => 'BD']);
        $category = Category::create(['name' => 'Category 2', 'parent_id' => null, 'sort_order' => 0]);
        $product = Product::create([
            'sku' => 'SKU-BULK',
            'barcode' => '9550999900123',
            'brand_id' => $brand->id,
            'category_id' => $category->id,
            'name' => 'Bulk product',
            'warranty_months' => 0,
            'default_purchase_price' => 10,
            'default_sale_price' => 20,
            'reorder_level' => 0,
            'unit_of_measure' => 'unit',
            'is_serialized' => false,
            'is_active' => true,
        ]);

        StockLedger::create([
            'event_time' => now(),
            'product_id' => $product->id,
            'location_id' => $branch->id,
            'movement_type' => 'purchase_receive',
            'quantity_delta' => $branchQty,
            'unit_cost' => 10,
            'reference_type' => 'Test',
            'reference_id' => 1,
            'actor_user_id' => $user->id,
        ]);

        return [$user, $branch, $product];
    }
}
