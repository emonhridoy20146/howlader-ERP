<?php

namespace Tests\Feature;

use App\Models\Brand;
use App\Models\Category;
use App\Models\Location;
use App\Models\Product;
use App\Models\SerialItem;
use App\Models\User;
use App\Services\PermissionSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SerialSalePriceTest extends TestCase
{
    use RefreshDatabase;

    private Location $branch;

    private User $admin;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();
        app(PermissionSyncService::class)->syncFromPolicies();
        $this->branch = Location::create(['code' => 'BR-SP', 'name' => 'Price Branch', 'type' => 'branch', 'is_active' => true]);
        $this->admin = User::factory()->create(['role' => 'admin', 'is_active' => true, 'branch_id' => $this->branch->id]);
        $brand = Brand::create(['name' => 'Brand A']);
        $category = Category::create(['name' => 'Category A']);
        $this->product = Product::create([
            'sku' => 'SKU-SP', 'brand_id' => $brand->id, 'category_id' => $category->id, 'name' => 'Priced Phone',
            'warranty_months' => 12, 'default_purchase_price' => 100, 'default_sale_price' => 150, 'reorder_level' => 0,
            'unit_of_measure' => 'unit', 'is_serialized' => true, 'is_active' => true,
        ]);
    }

    private function serial(string $number, string $status = 'in_stock'): SerialItem
    {
        return SerialItem::create([
            'product_id' => $this->product->id, 'serial_number' => $number, 'serial_source' => 'internal',
            'current_location_id' => $this->branch->id, 'status' => $status,
        ]);
    }

    public function test_product_page_shows_the_sell_price_column_and_editor(): void
    {
        $serial = $this->serial('SN-1');
        $serial->update(['sale_price' => 125]);

        $this->actingAs($this->admin)->get(route('products.show', $this->product).'?tab=serials')
            ->assertOk()
            ->assertSee('Sell price')
            ->assertSee('Sell price for selected')
            ->assertSee('bulk-sale-price', false)
            ->assertSee('/sale-price', false);
    }

    public function test_sell_price_can_be_set_changed_and_cleared_for_one_serial(): void
    {
        $serial = $this->serial('SN-1');

        $this->actingAs($this->admin)
            ->patch(route('products.serials.sale-price', [$this->product, $serial]), ['sale_price' => '1299.50'])
            ->assertRedirect(route('products.show', $this->product).'?tab=serials');
        $this->assertSame('1299.50', $serial->fresh()->sale_price);

        $this->actingAs($this->admin)
            ->patch(route('products.serials.sale-price', [$this->product, $serial]), ['sale_price' => ''])
            ->assertSessionHasNoErrors();
        $this->assertNull($serial->fresh()->sale_price);
    }

    public function test_negative_price_is_rejected(): void
    {
        $serial = $this->serial('SN-1');

        $this->actingAs($this->admin)
            ->patch(route('products.serials.sale-price', [$this->product, $serial]), ['sale_price' => '-5'])
            ->assertSessionHasErrors('sale_price');
        $this->assertNull($serial->fresh()->sale_price);
    }

    public function test_bulk_price_applies_only_to_in_stock_serials_of_this_product(): void
    {
        $a = $this->serial('SN-A');
        $b = $this->serial('SN-B');
        $sold = $this->serial('SN-SOLD', 'sold');

        $this->actingAs($this->admin)
            ->post(route('products.serials.bulk-sale-price', $this->product), ['serial_ids' => [$a->id, $b->id, $sold->id], 'sale_price' => '99'])
            ->assertSessionHasNoErrors();

        $this->assertSame('99.00', $a->fresh()->sale_price);
        $this->assertSame('99.00', $b->fresh()->sale_price);
        $this->assertNull($sold->fresh()->sale_price);
    }

    public function test_user_without_edit_permission_cannot_set_prices(): void
    {
        $serial = $this->serial('SN-1');
        $viewer = User::factory()->create(['role' => 'employee', 'is_active' => true, 'branch_id' => $this->branch->id]);

        $this->actingAs($viewer)
            ->patch(route('products.serials.sale-price', [$this->product, $serial]), ['sale_price' => '10'])
            ->assertForbidden();
        $this->assertNull($serial->fresh()->sale_price);
    }

    public function test_invoice_serial_lookup_returns_the_serial_price(): void
    {
        $priced = $this->serial('SN-PRICED');
        $priced->update(['sale_price' => 140]);
        $this->serial('SN-DEFAULT');

        $response = $this->actingAs($this->admin)->getJson(route('sales.invoices.serial-suggestions', [
            'branch_id' => $this->branch->id, 'product_id' => $this->product->id,
        ]));

        $response->assertOk();
        $data = collect($response->json('data'))->keyBy('serial_number');
        $this->assertSame(140.0, (float) $data['SN-PRICED']['sale_price']);
        $this->assertNull($data['SN-DEFAULT']['sale_price']);
    }
}
