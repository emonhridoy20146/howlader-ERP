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
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GoodsReceiptPendingQtyValidationTest extends TestCase
{
    use RefreshDatabase;

    public function test_store_rejects_qty_above_pending_purchase_order_amount(): void
    {
        [$user, $purchaseOrder, $product] = $this->seedPurchaseOrderWithPendingQty(5);

        $response = $this->actingAs($user)->post(route('goods-receipts.store'), [
            'purchase_order_id' => $purchaseOrder->id,
            'supplier_id' => $purchaseOrder->supplier_id,
            'location_id' => $purchaseOrder->destination_location_id,
            'received_at' => now()->toDateString(),
            'lines' => [
                [
                    'product_id' => $product->id,
                    'qty_received' => 6,
                    'unit_cost' => 100,
                    'serial_mode' => 'auto',
                ],
            ],
        ]);

        $response->assertSessionHasErrors('lines');
        $this->assertDatabaseCount('goods_receipts', 0);
    }

    public function test_store_accepts_qty_within_pending_purchase_order_amount(): void
    {
        [$user, $purchaseOrder, $product] = $this->seedPurchaseOrderWithPendingQty(5);

        $response = $this->actingAs($user)->post(route('goods-receipts.store'), [
            'purchase_order_id' => $purchaseOrder->id,
            'supplier_id' => $purchaseOrder->supplier_id,
            'location_id' => $purchaseOrder->destination_location_id,
            'received_at' => now()->toDateString(),
            'lines' => [
                [
                    'product_id' => $product->id,
                    'qty_received' => 5,
                    'unit_cost' => 100,
                    'serial_mode' => 'auto',
                ],
            ],
        ]);

        $response->assertRedirect(route('goods-receipts.index'));
        $this->assertDatabaseCount('goods_receipts', 1);
    }

    /**
     * @return array{0: User, 1: PurchaseOrder, 2: Product}
     */
    private function seedPurchaseOrderWithPendingQty(int $pendingQty): array
    {
        $user = User::factory()->create(['role' => 'admin', 'is_active' => true]);
        $supplier = Supplier::query()->create([
            'name' => 'GRN pending supplier',
            'contact_person' => null,
            'phone' => null,
            'email' => null,
            'address' => null,
            'tax_number' => null,
            'outstanding_balance' => 0,
            'is_active' => true,
        ]);
        $location = Location::query()->create([
            'code' => 'GRN-PQ',
            'name' => 'GRN pending branch',
            'type' => 'branch',
            'address' => null,
            'phone' => null,
            'manager_name' => null,
            'is_active' => true,
            'dizicashier_uuid' => null,
        ]);
        $brand = Brand::query()->create(['name' => 'GRN brand', 'country_of_origin' => null]);
        $category = Category::query()->create(['name' => 'GRN cat', 'parent_id' => null, 'sort_order' => 0]);
        $product = Product::query()->create([
            'sku' => 'GRN-PQ-1',
            'barcode' => '9550888800001',
            'brand_id' => $brand->id,
            'category_id' => $category->id,
            'name' => 'Pending qty widget',
            'is_serialized' => false,
            'default_purchase_price' => 100,
            'default_sale_price' => 150,
            'warranty_months' => 0,
            'reorder_level' => 0,
            'unit_of_measure' => 'pc',
            'is_active' => true,
        ]);

        $purchaseOrder = PurchaseOrder::query()->create([
            'po_number' => 'PO-GRN-PQ-1',
            'supplier_id' => $supplier->id,
            'destination_location_id' => $location->id,
            'status' => 'approved',
            'ordered_at' => now()->toDateString(),
            'expected_at' => null,
            'notes' => null,
            'subtotal_amount' => 500,
            'discount_amount' => 0,
            'tax_amount' => 0,
            'additional_cost_amount' => 0,
            'additional_costs' => [],
            'total_amount' => 500,
            'created_by' => $user->id,
            'approved_by' => $user->id,
            'approved_at' => now(),
        ]);

        PurchaseOrderLine::query()->create([
            'purchase_order_id' => $purchaseOrder->id,
            'product_id' => $product->id,
            'qty_ordered' => 10,
            'qty_received' => 10 - $pendingQty,
            'unit_cost' => 100,
            'tax_amount' => 0,
            'line_total' => 1000,
        ]);

        return [$user, $purchaseOrder, $product];
    }
}
