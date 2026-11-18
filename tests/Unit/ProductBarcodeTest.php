<?php

namespace Tests\Unit;

use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Support\ProductBarcode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductBarcodeTest extends TestCase
{
    use RefreshDatabase;

    public function test_format_produces_configured_length(): void
    {
        $this->assertSame('90000001', ProductBarcode::format(1));
        $this->assertSame('91234567', ProductBarcode::format(1234567));
    }

    public function test_generate_unique_is_sequential(): void
    {
        $brand = Brand::create(['name' => 'B', 'country_of_origin' => 'BD']);
        $category = Category::create(['name' => 'C', 'parent_id' => null, 'sort_order' => 0]);

        Product::create([
            'sku' => 'SKU-1',
            'barcode' => '90000005',
            'brand_id' => $brand->id,
            'category_id' => $category->id,
            'name' => 'One',
            'warranty_months' => 12,
            'default_purchase_price' => 1,
            'default_sale_price' => 2,
            'reorder_level' => 0,
            'unit_of_measure' => 'unit',
            'is_serialized' => true,
            'is_active' => true,
        ]);

        $this->assertSame('90000006', ProductBarcode::generateUnique());
    }
}
