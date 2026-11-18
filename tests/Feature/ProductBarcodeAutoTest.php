<?php

namespace Tests\Feature;

use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductBarcodeAutoTest extends TestCase
{
    use RefreshDatabase;

    public function test_store_assigns_unique_barcode_when_field_empty(): void
    {
        $brand = Brand::create(['name' => 'Test Brand', 'country_of_origin' => 'BD']);
        $category = Category::create(['name' => 'Test Cat', 'parent_id' => null, 'sort_order' => 0]);

        $user = User::factory()->create([
            'role' => 'admin',
            'is_active' => true,
        ]);

        $payload = [
            'sku' => 'SKU-AUTO-1',
            'barcode' => '',
            'brand_id' => $brand->id,
            'category_id' => $category->id,
            'name' => 'Auto barcode product',
            'model_number' => '',
            'description' => '',
            'warranty_months' => 12,
            'default_purchase_price' => 10,
            'default_sale_price' => 20,
            'reorder_level' => 0,
            'unit_of_measure' => 'unit',
            'is_active' => '1',
        ];

        $this->actingAs($user)
            ->post(route('products.store'), $payload)
            ->assertRedirect(route('products.index'));

        $product = Product::query()->where('sku', 'SKU-AUTO-1')->first();
        $this->assertNotNull($product);
        $this->assertIsString($product->barcode);
        $this->assertMatchesRegularExpression('/^9\d{7}$/', $product->barcode);
    }

    public function test_next_barcode_endpoint_returns_json(): void
    {
        $user = User::factory()->create(['role' => 'admin', 'is_active' => true]);

        $response = $this->actingAs($user)
            ->getJson(route('products.barcode.next'));

        $response->assertOk()->assertJsonStructure(['barcode']);
        $this->assertMatchesRegularExpression('/^9\d{7}$/', (string) $response->json('barcode'));
    }

    public function test_store_rejects_duplicate_model_number(): void
    {
        $brand = Brand::create(['name' => 'Brand M', 'country_of_origin' => 'BD']);
        $category = Category::create(['name' => 'Cat M', 'parent_id' => null, 'sort_order' => 0]);

        $user = User::factory()->create([
            'role' => 'admin',
            'is_active' => true,
        ]);

        Product::create([
            'sku' => 'SKU-MODEL-1',
            'barcode' => '90000001',
            'brand_id' => $brand->id,
            'category_id' => $category->id,
            'name' => 'First',
            'model_number' => 'MODEL-X',
            'warranty_months' => 12,
            'default_purchase_price' => 1,
            'default_sale_price' => 2,
            'reorder_level' => 0,
            'unit_of_measure' => 'unit',
            'is_serialized' => true,
            'is_active' => true,
        ]);

        $response = $this->actingAs($user)->post(route('products.store'), [
            'sku' => 'SKU-MODEL-2',
            'barcode' => '90000002',
            'brand_id' => $brand->id,
            'category_id' => $category->id,
            'name' => 'Second',
            'model_number' => 'MODEL-X',
            'description' => '',
            'warranty_months' => 12,
            'default_purchase_price' => 1,
            'default_sale_price' => 2,
            'reorder_level' => 0,
            'unit_of_measure' => 'unit',
            'is_active' => '1',
        ]);

        $response->assertSessionHasErrors('model_number');
    }
}
