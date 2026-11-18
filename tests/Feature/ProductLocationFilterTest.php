<?php

namespace Tests\Feature;

use App\Models\Brand;
use App\Models\Category;
use App\Models\Location;
use App\Models\Product;
use App\Models\SerialItem;
use App\Models\ManualStockAdjustment;
use App\Models\StockLedger;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductLocationFilterTest extends TestCase
{
    use RefreshDatabase;

    public function test_products_index_filters_by_branch_inventory(): void
    {
        [$user, $branchA, $branchB, $productA, $productB] = $this->seedProductsAtBranches();

        $response = $this->actingAs($user)->get(route('products.index', [
            'location_id' => $branchA->id,
        ]));

        $response->assertOk();
        $response->assertSee($productA->name);
        $response->assertDontSee($productB->name);
    }

    public function test_print_labels_opens_without_product_selection(): void
    {
        [$user, $branchA, $branchB, $productA, $productB] = $this->seedProductsAtBranches();

        $response = $this->actingAs($user)->get(route('products.labels', [
            'format' => 'barcode',
            'location_id' => $branchA->id,
        ]));

        $response->assertOk();
        $response->assertSee($productA->name, false);
        $response->assertDontSee($productB->name, false);
        $response->assertSee('label-branch-filter', false);
        $response->assertSee('label-product-filter', false);
    }

    public function test_print_labels_can_filter_by_product_on_print_page(): void
    {
        [$user, $branchA, $branchB, $productA, $productB] = $this->seedProductsAtBranches();

        // Put product B stock at branch A as well so both appear in the product filter list.
        $serialB = SerialItem::query()->where('product_id', $productB->id)->firstOrFail();
        $serialB->update(['current_location_id' => $branchA->id]);
        StockLedger::create([
            'event_time' => now(),
            'product_id' => $productB->id,
            'serial_item_id' => $serialB->id,
            'location_id' => $branchA->id,
            'movement_type' => 'purchase_receive',
            'quantity_delta' => 1,
            'unit_cost' => 100,
            'reference_type' => 'Test',
            'reference_id' => 99,
            'actor_user_id' => $user->id,
        ]);

        $response = $this->actingAs($user)->get(route('products.labels', [
            'format' => 'barcode',
            'location_id' => $branchA->id,
            'product_id' => $productA->id,
        ]));

        $response->assertOk();
        $response->assertSee($productA->name, false);
        $response->assertSee(' · SKU-A', false);
        $this->assertSame(1, substr_count($response->getContent(), 'class="thermal-label"'));
        $this->assertStringNotContainsString('AST-B-000001', $response->getContent());
    }

    public function test_print_labels_scopes_serials_to_selected_branch(): void
    {
        [$user, $branchA, $branchB, $productA, $productB] = $this->seedProductsAtBranches();

        $response = $this->actingAs($user)->get(route('products.labels', [
            'product_ids' => [$productA->id, $productB->id],
            'location_id' => $branchA->id,
            'format' => 'barcode',
        ]));

        $response->assertOk();
        $response->assertSee($productA->name, false);
        $response->assertDontSee($productB->name, false);
        $response->assertSee('label-branch-filter', false);
        $response->assertSee($branchA->name, false);
    }

    public function test_admin_can_print_filtered_product_list(): void
    {
        [$user, $branchA, $branchB, $productA, $productB] = $this->seedProductsAtBranches();

        $index = $this->actingAs($user)->get(route('products.index', [
            'location_id' => $branchA->id,
            'category_id' => $productA->category_id,
        ]));
        $index->assertOk();
        $index->assertSee('Print list', false);
        $index->assertSee('products/print?', false);
        $index->assertSee('category_id='.$productA->category_id, false);
        $index->assertSee('location_id='.$branchA->id, false);

        $response = $this->actingAs($user)->get(route('products.print', [
            'location_id' => $branchA->id,
            'category_id' => $productA->category_id,
        ]));

        $response->assertOk();
        $response->assertSee('Product list', false);
        $response->assertSee($productA->name, false);
        $response->assertDontSee($productB->name, false);
        $response->assertSee('Branch A', false);
    }

    public function test_print_labels_branch_filter_shows_all_branches_option(): void
    {
        [$user, $branchA, $branchB, $productA] = $this->seedProductsAtBranches();

        $response = $this->actingAs($user)->get(route('products.labels', [
            'product_ids' => [$productA->id],
            'format' => 'barcode',
        ]));

        $response->assertOk();
        $response->assertSee('All branches', false);
        $response->assertSee($branchA->name, false);
        $response->assertSee($branchB->name, false);
    }

    /**
     * @return array{0: User, 1: Location, 2: Location, 3: Product, 4: Product}
     */
    private function seedProductsAtBranches(): array
    {
        $branchA = Location::create([
            'code' => 'BR-A',
            'name' => 'Branch A',
            'type' => 'branch',
            'is_active' => true,
        ]);

        $branchB = Location::create([
            'code' => 'BR-B',
            'name' => 'Branch B',
            'type' => 'branch',
            'is_active' => true,
        ]);

        $user = User::factory()->create([
            'role' => 'admin',
            'is_active' => true,
            'branch_id' => $branchA->id,
        ]);

        $brand = Brand::create(['name' => 'Brand', 'country_of_origin' => 'BD']);
        $category = Category::create(['name' => 'Category', 'parent_id' => null, 'sort_order' => 0]);

        $productA = Product::create([
            'sku' => 'SKU-A',
            'brand_id' => $brand->id,
            'category_id' => $category->id,
            'name' => 'Product at branch A',
            'warranty_months' => 12,
            'default_purchase_price' => 100,
            'default_sale_price' => 150,
            'reorder_level' => 0,
            'unit_of_measure' => 'unit',
            'is_serialized' => true,
            'is_active' => true,
        ]);

        $productB = Product::create([
            'sku' => 'SKU-B',
            'brand_id' => $brand->id,
            'category_id' => $category->id,
            'name' => 'Product at branch B',
            'warranty_months' => 12,
            'default_purchase_price' => 200,
            'default_sale_price' => 250,
            'reorder_level' => 0,
            'unit_of_measure' => 'unit',
            'is_serialized' => true,
            'is_active' => true,
        ]);

        $serialA = SerialItem::create([
            'product_id' => $productA->id,
            'serial_number' => 'AST-A-000001',
            'serial_source' => 'internal',
            'current_location_id' => $branchA->id,
            'status' => 'in_stock',
            'purchase_cost' => 100,
        ]);

        $serialB = SerialItem::create([
            'product_id' => $productB->id,
            'serial_number' => 'AST-B-000001',
            'serial_source' => 'internal',
            'current_location_id' => $branchB->id,
            'status' => 'in_stock',
            'purchase_cost' => 200,
        ]);

        foreach ([[$productA, $branchA, $serialA], [$productB, $branchB, $serialB]] as [$product, $branch, $serial]) {
            $adjustment = ManualStockAdjustment::create([
                'product_id' => $product->id,
                'location_id' => $branch->id,
                'qty_before' => 0,
                'qty_after' => 1,
                'qty_delta' => 1,
                'reason' => 'Test seed',
                'actor_user_id' => $user->id,
            ]);

            StockLedger::create([
                'event_time' => now(),
                'product_id' => $product->id,
                'serial_item_id' => $serial->id,
                'location_id' => $branch->id,
                'movement_type' => 'adjust_in',
                'quantity_delta' => 1,
                'unit_cost' => $product->default_purchase_price,
                'reference_type' => ManualStockAdjustment::class,
                'reference_id' => $adjustment->id,
                'actor_user_id' => $user->id,
            ]);
        }

        return [$user, $branchA, $branchB, $productA, $productB];
    }
}
