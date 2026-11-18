<?php

namespace Tests\Feature;

use App\Models\Brand;
use App\Models\Category;
use App\Models\Location;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderLine;
use App\Models\Supplier;
use App\Models\User;
use App\Services\PermissionSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PurchaseOrderProductEditLinkTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(PermissionSyncService::class)->syncFromPolicies();
    }

    public function test_purchase_order_lines_link_to_the_product_edit_screen(): void
    {
        $branch = Location::create(['code' => 'BR-PO', 'name' => 'PO Branch', 'type' => 'branch', 'is_active' => true]);
        $admin = User::factory()->create(['role' => 'admin', 'is_active' => true, 'branch_id' => $branch->id]);
        $supplier = Supplier::create(['name' => 'Supplier A']);
        $brand = Brand::create(['name' => 'Brand A']);
        $category = Category::create(['name' => 'Category A']);
        $product = Product::create([
            'sku' => 'SKU-PO', 'brand_id' => $brand->id, 'category_id' => $category->id, 'name' => 'PO Product',
            'warranty_months' => 12, 'default_purchase_price' => 100, 'default_sale_price' => 150, 'reorder_level' => 0,
            'unit_of_measure' => 'unit', 'is_serialized' => false, 'is_active' => true,
        ]);
        $order = PurchaseOrder::create([
            'po_number' => 'PO-LINK-1', 'supplier_id' => $supplier->id, 'destination_location_id' => $branch->id, 'status' => 'draft',
            'ordered_at' => now()->toDateString(), 'subtotal_amount' => 100, 'discount_amount' => 0, 'tax_amount' => 0, 'total_amount' => 100,
            'created_by' => $admin->id,
        ]);
        PurchaseOrderLine::create([
            'purchase_order_id' => $order->id, 'product_id' => $product->id, 'qty_ordered' => 1, 'qty_received' => 0,
            'unit_cost' => 100, 'tax_amount' => 0, 'line_total' => 100,
        ]);

        $response = $this->actingAs($admin)->get(route('purchase-orders.show', $order));

        $response->assertOk();
        $response->assertSee(route('products.edit', $product), false);
        $response->assertSee('Edit product / price');

        // The link lands on the product list with the edit drawer open for this product.
        $this->actingAs($admin)->get(route('products.edit', $product))
            ->assertRedirect(route('products.index', ['drawer' => 'edit', 'edit' => $product->id]));
    }
}
