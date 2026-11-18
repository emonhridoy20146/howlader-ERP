<?php

namespace Tests\Feature;

use App\Models\Brand;
use App\Models\Category;
use App\Models\Location;
use App\Models\Product;
use App\Models\SerialItem;
use App\Models\StockLedger;
use App\Models\User;
use App\Services\ReportDataService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

class StockByLocationUnserialedStockTest extends TestCase
{
    use RefreshDatabase;

    private Location $branch;

    private User $admin;

    private Brand $brand;

    private Category $category;

    protected function setUp(): void
    {
        parent::setUp();
        $this->branch = Location::create(['code' => 'BR-SL', 'name' => 'Stock Branch', 'type' => 'branch', 'is_active' => true]);
        $this->admin = User::factory()->create(['role' => 'admin', 'is_active' => true, 'branch_id' => $this->branch->id]);
        $this->actingAs($this->admin);
        $this->brand = Brand::create(['name' => 'Brand A']);
        $this->category = Category::create(['name' => 'Category A']);
    }

    private function product(string $sku, bool $serialized): Product
    {
        return Product::create([
            'sku' => $sku, 'brand_id' => $this->brand->id, 'category_id' => $this->category->id, 'name' => 'Product '.$sku,
            'warranty_months' => 12, 'default_purchase_price' => 100, 'default_sale_price' => 150, 'reorder_level' => 0,
            'unit_of_measure' => 'unit', 'is_serialized' => $serialized, 'is_active' => true,
        ]);
    }

    private function ledger(Product $product, int $qty): void
    {
        StockLedger::create([
            'event_time' => now(), 'product_id' => $product->id, 'serial_item_id' => null, 'location_id' => $this->branch->id,
            'movement_type' => 'return_receive', 'quantity_delta' => $qty, 'unit_cost' => null,
            'reference_type' => 'Test', 'reference_id' => 1, 'actor_user_id' => $this->admin->id,
        ]);
    }

    private function serial(Product $product, string $number, string $status): void
    {
        SerialItem::create([
            'product_id' => $product->id, 'serial_number' => $number, 'serial_source' => 'internal',
            'current_location_id' => $this->branch->id, 'status' => $status,
        ]);
    }

    /** @return array<string, float> sku => available qty shown on the report */
    private function reportQuantities(string $stockState = 'in_stock'): array
    {
        $rows = app(ReportDataService::class)->stockByLocationForExport(
            new Request(['location_id' => (string) $this->branch->id, 'stock_state' => $stockState])
        )['rows'];

        return $rows->mapWithKeys(fn ($r) => [$r->product_sku => (float) $r->available_qty])->all();
    }

    public function test_serialized_product_with_stock_but_no_serial_records_is_shown(): void
    {
        $oldStock = $this->product('OLD-NOSERIAL', true);
        $this->ledger($oldStock, 3);

        $this->assertSame(3.0, $this->reportQuantities()['OLD-NOSERIAL'] ?? null);
    }

    public function test_non_serialized_product_with_quantity_is_shown(): void
    {
        $plain = $this->product('PLAIN', false);
        $this->ledger($plain, 5);

        $this->assertSame(5.0, $this->reportQuantities()['PLAIN'] ?? null);
    }

    public function test_serialized_product_with_serials_still_counts_serials_only(): void
    {
        $tracked = $this->product('TRACKED', true);
        $this->ledger($tracked, 3);
        $this->serial($tracked, 'SN-1', 'in_stock');
        $this->serial($tracked, 'SN-2', 'sold');

        $this->assertSame(1.0, $this->reportQuantities()['TRACKED'] ?? null);
    }

    public function test_product_whose_only_serial_is_sold_stays_hidden_even_if_ledger_is_stale(): void
    {
        $stale = $this->product('STALE', true);
        $this->ledger($stale, 1);
        $this->serial($stale, 'SN-SOLD', 'sold');

        $this->assertArrayNotHasKey('STALE', $this->reportQuantities());
    }

    public function test_product_names_link_to_the_product_details_page_on_screen_and_print(): void
    {
        $plain = $this->product('LINKED', false);
        $this->ledger($plain, 2);
        $url = route('products.show', $plain);
        $query = ['location_id' => (string) $this->branch->id, 'stock_state' => 'in_stock'];

        $this->actingAs($this->admin)->get(route('reports.stock-by-location', $query))
            ->assertOk()
            ->assertSee('href="'.$url.'"', false);

        $this->actingAs($this->admin)->get(route('reports.print', ['slug' => 'stock-by-location'] + $query))
            ->assertOk()
            ->assertSee('href="'.$url.'"', false);
    }
}
