<?php

namespace Tests\Feature;

use App\Exports\ProductBulkUpdateExport;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use App\Services\ProductBulkUpdateService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Maatwebsite\Excel\Facades\Excel;
use Tests\TestCase;

class ProductBulkPriceUpdateTest extends TestCase
{
    use RefreshDatabase;

    public function test_export_requires_authentication(): void
    {
        $this->get(route('products.bulk-update.export'))
            ->assertRedirect();
    }

    public function test_export_downloads_spreadsheet_for_admin(): void
    {
        [$product] = $this->seedProduct();

        $user = User::factory()->create(['role' => 'admin', 'is_active' => true]);

        $this->actingAs($user)
            ->get(route('products.bulk-update.export'))
            ->assertOk()
            ->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    }

    public function test_import_updates_prices_from_excel(): void
    {
        [$product] = $this->seedProduct();
        $product->update([
            'default_purchase_price' => 10,
            'default_sale_price' => 20,
        ]);

        $user = User::factory()->create(['role' => 'admin', 'is_active' => true]);

        $export = new ProductBulkUpdateExport(Product::query()->with(['brand', 'category'])->whereKey($product->id)->get());
        $binary = Excel::raw($export, \Maatwebsite\Excel\Excel::XLSX);

        $updated = Product::query()->findOrFail($product->id);
        $updated->default_purchase_price = 55.5;
        $updated->default_sale_price = 88.25;
        $binary = Excel::raw(new ProductBulkUpdateExport(collect([$updated])), \Maatwebsite\Excel\Excel::XLSX);

        $file = UploadedFile::fake()->createWithContent('products.xlsx', $binary);

        $this->actingAs($user)
            ->post(route('products.bulk-update.import'), ['file' => $file])
            ->assertRedirect(route('products.index', ['list' => 'active']));

        $product->refresh();
        $this->assertEqualsWithDelta(55.5, (float) $product->default_purchase_price, 0.001);
        $this->assertEqualsWithDelta(88.25, (float) $product->default_sale_price, 0.001);
    }

    public function test_apply_bulk_update_sets_prices_on_selected_products(): void
    {
        [$productA, $productB] = $this->seedTwoProducts();

        $user = User::factory()->create(['role' => 'admin', 'is_active' => true]);

        $this->actingAs($user)
            ->post(route('products.bulk-update.apply'), [
                'product_ids' => [$productA->id, $productB->id],
                'default_purchase_price' => 12.5,
                'default_sale_price' => 30,
            ])
            ->assertRedirect(route('products.index', ['list' => 'active']));

        $productA->refresh();
        $productB->refresh();

        $this->assertEqualsWithDelta(12.5, (float) $productA->default_purchase_price, 0.001);
        $this->assertEqualsWithDelta(30.0, (float) $productA->default_sale_price, 0.001);
        $this->assertEqualsWithDelta(12.5, (float) $productB->default_purchase_price, 0.001);
        $this->assertEqualsWithDelta(30.0, (float) $productB->default_sale_price, 0.001);
    }

    public function test_bulk_update_service_processes_rows_by_sku(): void
    {
        [$product] = $this->seedProduct();

        $service = app(ProductBulkUpdateService::class);
        $result = $service->processImportRows(collect([
            ['sku' => $product->sku, 'purchase_price' => 7, 'sale_price' => 14],
        ]));

        $this->assertSame(1, $result['updated']);
        $product->refresh();
        $this->assertEqualsWithDelta(7.0, (float) $product->default_purchase_price, 0.001);
        $this->assertEqualsWithDelta(14.0, (float) $product->default_sale_price, 0.001);
    }

    /**
     * @return array{0: Product}
     */
    private function seedProduct(): array
    {
        $brand = Brand::create(['name' => 'Bulk Brand', 'country_of_origin' => 'BD']);
        $category = Category::create(['name' => 'Bulk Cat', 'parent_id' => null, 'sort_order' => 0]);

        $product = Product::create([
            'sku' => 'BULK-001',
            'barcode' => '900000000001',
            'brand_id' => $brand->id,
            'category_id' => $category->id,
            'name' => 'Bulk test product',
            'default_purchase_price' => 1,
            'default_sale_price' => 2,
            'warranty_months' => 0,
            'reorder_level' => 0,
            'unit_of_measure' => 'pieces',
            'is_serialized' => true,
            'is_active' => true,
        ]);

        return [$product];
    }

    /**
     * @return array{0: Product, 1: Product}
     */
    private function seedTwoProducts(): array
    {
        $brand = Brand::create(['name' => 'Bulk Brand 2', 'country_of_origin' => 'BD']);
        $category = Category::create(['name' => 'Bulk Cat 2', 'parent_id' => null, 'sort_order' => 0]);

        $productA = Product::create([
            'sku' => 'BULK-A',
            'barcode' => '900000000002',
            'brand_id' => $brand->id,
            'category_id' => $category->id,
            'name' => 'Product A',
            'default_purchase_price' => 1,
            'default_sale_price' => 2,
            'warranty_months' => 0,
            'reorder_level' => 0,
            'unit_of_measure' => 'pieces',
            'is_serialized' => true,
            'is_active' => true,
        ]);

        $productB = Product::create([
            'sku' => 'BULK-B',
            'barcode' => '900000000003',
            'brand_id' => $brand->id,
            'category_id' => $category->id,
            'name' => 'Product B',
            'default_purchase_price' => 3,
            'default_sale_price' => 4,
            'warranty_months' => 0,
            'reorder_level' => 0,
            'unit_of_measure' => 'pieces',
            'is_serialized' => true,
            'is_active' => true,
        ]);

        return [$productA, $productB];
    }
}
