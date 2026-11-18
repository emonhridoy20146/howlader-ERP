<?php

namespace Tests\Feature;

use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CategoryManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_print_category_list_with_filters(): void
    {
        $user = User::factory()->create(['role' => 'admin', 'is_active' => true]);

        $parent = Category::create(['name' => 'Electronics', 'sort_order' => 1]);
        Category::create(['name' => 'Phones', 'parent_id' => $parent->id, 'sort_order' => 2]);

        $response = $this->actingAs($user)->get(route('categories.print', ['q' => 'Phone']));
        $response->assertOk();
        $response->assertSee('Category list', false);
        $response->assertSee('Phones', false);
    }

    public function test_admin_can_move_all_products_to_another_category(): void
    {
        $user = User::factory()->create(['role' => 'admin', 'is_active' => true]);

        $from = Category::create(['name' => 'Old Cat', 'sort_order' => 1]);
        $to = Category::create(['name' => 'New Cat', 'sort_order' => 2]);

        $brand = Brand::create(['name' => 'Test Brand', 'country_of_origin' => 'BD']);
        $this->createProduct($brand, $from, 'SKU-A', 'Item A');
        $this->createProduct($brand, $from, 'SKU-B', 'Item B');
        $this->createProduct($brand, $to, 'SKU-C', 'Item C');

        $this->actingAs($user)
            ->post(route('categories.move-products'), [
                'from_category_id' => $from->id,
                'to_category_id' => $to->id,
            ])
            ->assertRedirect(route('categories.index'))
            ->assertSessionHas('status');

        $this->assertSame(0, Product::where('category_id', $from->id)->count());
        $this->assertSame(3, Product::where('category_id', $to->id)->count());
    }

    public function test_move_products_rejects_same_source_and_destination(): void
    {
        $user = User::factory()->create(['role' => 'admin', 'is_active' => true]);
        $cat = Category::create(['name' => 'Only', 'sort_order' => 1]);

        $this->actingAs($user)
            ->from(route('categories.index'))
            ->post(route('categories.move-products'), [
                'from_category_id' => $cat->id,
                'to_category_id' => $cat->id,
            ])
            ->assertSessionHasErrors('to_category_id');
    }

    public function test_bulk_products_page_and_row_category_assign(): void
    {
        $user = User::factory()->create(['role' => 'admin', 'is_active' => true]);

        $from = Category::create(['name' => 'Tree From', 'sort_order' => 1]);
        $to = Category::create(['name' => 'Tree To', 'parent_id' => null, 'sort_order' => 2]);
        Category::create(['name' => 'Tree Child', 'parent_id' => $from->id, 'sort_order' => 1]);

        $tv = Category::create(['name' => 'TV', 'sort_order' => 3]);
        Category::create(['name' => 'LG TV', 'parent_id' => $tv->id, 'sort_order' => 1]);

        $brand = Brand::create(['name' => 'Bulk Brand', 'country_of_origin' => 'BD']);
        $product = $this->createProduct($brand, $from, 'SKU-BULK-CAT', 'Bulk Cat Product');

        $page = $this->actingAs($user)->get(route('categories.bulk-products', [
            'filter_category_id' => $from->id,
            'q' => 'Bulk Cat',
        ]));
        $page->assertOk();
        $page->assertSee('Tree From', false);
        $page->assertSee('LG TV', false);
        $page->assertSee('Category tree', false);
        $page->assertSee('Bulk Cat Product', false);

        $this->actingAs($user)
            ->post(route('categories.bulk-products.update'), [
                'action' => 'rows',
                'assignments' => [
                    (string) $product->id => (string) $to->id,
                ],
                'brand_assignments' => [
                    (string) $product->id => (string) $brand->id,
                ],
            ])
            ->assertRedirect();

        $this->assertSame($to->id, $product->fresh()->category_id);

        $newBrand = Brand::create(['name' => 'Bulk Brand Target', 'country_of_origin' => 'BD']);

        $this->actingAs($user)
            ->post(route('categories.bulk-products.update'), [
                'action' => 'bulk_brand',
                'product_ids' => [$product->id],
                'target_brand_id' => $newBrand->id,
            ])
            ->assertRedirect();

        $this->assertSame($newBrand->id, $product->fresh()->brand_id);

        $anotherBrand = Brand::create(['name' => 'Row Brand Target', 'country_of_origin' => 'BD']);

        $this->actingAs($user)
            ->post(route('categories.bulk-products.update'), [
                'action' => 'rows',
                'assignments' => [
                    (string) $product->id => (string) $to->id,
                ],
                'brand_assignments' => [
                    (string) $product->id => (string) $anotherBrand->id,
                ],
            ])
            ->assertRedirect();

        $fresh = $product->fresh();
        $this->assertSame($to->id, $fresh->category_id);
        $this->assertSame($anotherBrand->id, $fresh->brand_id);
    }

    public function test_bulk_products_patch_updates_via_json(): void
    {
        $user = User::factory()->create(['role' => 'admin', 'is_active' => true]);

        $from = Category::create(['name' => 'Patch From', 'sort_order' => 1]);
        $to = Category::create(['name' => 'Patch To', 'sort_order' => 2]);
        $brand = Brand::create(['name' => 'Patch Brand', 'country_of_origin' => 'BD']);
        $newBrand = Brand::create(['name' => 'Patch Brand New', 'country_of_origin' => 'BD']);
        $product = $this->createProduct($brand, $from, 'SKU-PATCH', 'Patch Product');

        $this->actingAs($user)
            ->patchJson(route('categories.bulk-products.patch'), [
                'product_id' => $product->id,
                'category_id' => $to->id,
                'brand_id' => $newBrand->id,
            ])
            ->assertOk()
            ->assertJsonPath('brand_name', 'Patch Brand New')
            ->assertJsonPath('category_label', 'Patch To')
            ->assertJsonPath('category_id', $to->id)
            ->assertJsonPath('brand_id', $newBrand->id);

        $fresh = $product->fresh();
        $this->assertSame($to->id, $fresh->category_id);
        $this->assertSame($newBrand->id, $fresh->brand_id);
    }

    private function createProduct(Brand $brand, Category $category, string $sku, string $name): Product
    {
        return Product::create([
            'sku' => $sku,
            'barcode' => '9550'.$sku,
            'brand_id' => $brand->id,
            'category_id' => $category->id,
            'name' => $name,
            'warranty_months' => 0,
            'default_purchase_price' => 10,
            'default_sale_price' => 20,
            'reorder_level' => 0,
            'unit_of_measure' => 'unit',
            'is_serialized' => false,
            'is_active' => true,
        ]);
    }
}
