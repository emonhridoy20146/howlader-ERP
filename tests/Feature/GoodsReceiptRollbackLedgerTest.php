<?php

namespace Tests\Feature;

use App\Models\Brand;
use App\Models\Category;
use App\Models\GoodsReceipt;
use App\Models\GoodsReceiptLine;
use App\Models\Location;
use App\Models\Product;
use App\Models\StockLedger;
use App\Models\Supplier;
use App\Models\User;
use App\Services\GoodsReceiptPostingService;
use App\Services\InventoryBalanceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GoodsReceiptRollbackLedgerTest extends TestCase
{
    use RefreshDatabase;

    public function test_rollback_deletes_ledger_rows_using_string_goods_receipt_reference(): void
    {
        $user = User::factory()->create();
        $supplier = Supplier::query()->create([
            'name' => 'GRN rollback supplier',
            'contact_person' => null,
            'phone' => null,
            'email' => null,
            'address' => null,
            'tax_number' => null,
            'outstanding_balance' => 0,
            'is_active' => true,
        ]);

        $brand = Brand::query()->create(['name' => 'Rb brand', 'country_of_origin' => null]);
        $category = Category::query()->create(['name' => 'Rb cat', 'parent_id' => null, 'sort_order' => 0]);
        $location = Location::query()->create([
            'code' => 'RB-1',
            'name' => 'Rollback branch',
            'type' => 'branch',
            'address' => null,
            'phone' => null,
            'manager_name' => null,
            'is_active' => true,
            'dizicashier_uuid' => null,
        ]);

        $product = Product::query()->create([
            'sku' => 'GRN-RB-NS',
            'barcode' => '9550199990001',
            'brand_id' => $brand->id,
            'category_id' => $category->id,
            'name' => 'Non-serial rollback item',
            'is_serialized' => false,
            'default_purchase_price' => 10,
            'default_sale_price' => 15,
            'warranty_months' => 0,
            'reorder_level' => 0,
            'unit_of_measure' => 'pc',
            'is_active' => true,
        ]);

        $grn = GoodsReceipt::query()->create([
            'grn_number' => 'GRN-RB-TEST-1',
            'purchase_order_id' => null,
            'supplier_id' => $supplier->id,
            'location_id' => $location->id,
            'status' => 'draft',
            'received_at' => now()->toDateString(),
            'delivery_note_ref' => null,
            'created_by' => $user->id,
            'posted_by' => null,
            'posted_at' => null,
        ]);

        GoodsReceiptLine::query()->create([
            'goods_receipt_id' => $grn->id,
            'product_id' => $product->id,
            'qty_received' => 3,
            'unit_cost' => 10,
        ]);

        $grn->refresh()->load('lines.product');

        app(GoodsReceiptPostingService::class)->post($grn, (int) $user->id);

        $grn->refresh();
        $this->assertSame('posted', $grn->status);

        $this->assertSame(
            1,
            (int) StockLedger::query()
                ->where('reference_id', $grn->id)
                ->where('reference_type', 'GoodsReceipt')
                ->count(),
        );

        app(GoodsReceiptPostingService::class)->rollbackAndDelete($grn->fresh(['lines']), (int) $user->id);

        $this->assertDatabaseMissing('goods_receipts', ['id' => $grn->id]);
        $this->assertSame(
            0,
            (int) StockLedger::query()
                ->where('reference_id', $grn->id)
                ->where(function ($q): void {
                    $q->where('reference_type', 'GoodsReceipt')
                        ->orWhere('reference_type', GoodsReceipt::class);
                })
                ->count(),
        );

        app(InventoryBalanceService::class)->recalculate($product->id, $location->id);
        $this->assertDatabaseHas('inventory_balances', [
            'product_id' => $product->id,
            'location_id' => $location->id,
            'on_hand_qty' => 0,
        ]);
    }
}
