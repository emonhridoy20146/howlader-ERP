<?php

namespace Tests\Feature;

use App\Models\Brand;
use App\Models\Category;
use App\Models\Location;
use App\Models\ManualStockAdjustment;
use App\Models\Product;
use App\Models\StockLedger;
use App\Models\User;
use App\Services\ReportDataService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardStockQuantityTest extends TestCase
{
    use RefreshDatabase;

    public function test_dashboard_product_quantity_matches_stock_summary_on_hand_kpi(): void
    {
        [$user, $branch, $activeProduct, $inactiveProduct] = $this->seedStock();

        $reportService = app(ReportDataService::class);
        $expectedOnHand = $reportService->stockSummaryOnHandTotal();
        $inventoryKpis = $reportService->stockSummaryInventoryKpis();

        $dashboard = $this->actingAs($user)->get(route('dashboard'));
        $dashboard->assertOk();
        $dashboard->assertSee(number_format($expectedOnHand), false);
        $dashboard->assertSee(number_format($inventoryKpis['available']), false);
        $dashboard->assertSee('In stock', false);
        $dashboard->assertSee('On hold', false);

        $report = $this->actingAs($user)->get(route('reports.stock-summary'));
        $report->assertOk();
        $report->assertSee(number_format($expectedOnHand), false);
    }

    public function test_stock_by_location_on_hand_matches_stock_summary_with_same_scope(): void
    {
        [$user] = $this->seedStock();

        $reportService = app(ReportDataService::class);
        $expectedOnHand = $reportService->stockSummaryOnHandTotal();
        $stockByLocation = $reportService->stockByLocationPaginated(request());
        $stockSummary = $reportService->stockSummaryPaginated(request());

        $this->assertSame($expectedOnHand, $stockByLocation['kpis']['on_hand']);
        $this->assertSame($expectedOnHand, $stockSummary['kpis']['on_hand']);

        $page = $this->actingAs($user)->get(route('reports.stock-by-location'));
        $page->assertOk();
        $page->assertSee(number_format($expectedOnHand), false);
    }

    public function test_stock_summary_on_hand_excludes_inactive_products(): void
    {
        [$user] = $this->seedStock();

        $kpis = app(ReportDataService::class)->stockSummaryPaginated(request())['kpis'];

        $this->assertSame(7, $kpis['on_hand']);
        $this->assertSame(7, $kpis['available']);
        $this->assertSame(0, $kpis['reserved']);
    }

    /**
     * @return array{0: User, 1: Location, 2: Product, 3: Product}
     */
    private function seedStock(): array
    {
        $branch = Location::create([
            'code' => 'BR-STK',
            'name' => 'Stock Branch',
            'type' => 'branch',
            'is_active' => true,
        ]);

        $user = User::factory()->create([
            'role' => 'admin',
            'is_active' => true,
            'branch_id' => $branch->id,
        ]);

        $brand = Brand::create(['name' => 'Brand', 'country_of_origin' => 'BD']);
        $category = Category::create(['name' => 'Category', 'parent_id' => null, 'sort_order' => 0]);

        $activeProduct = Product::create([
            'sku' => 'SKU-ACT',
            'brand_id' => $brand->id,
            'category_id' => $category->id,
            'name' => 'Active product',
            'warranty_months' => 12,
            'default_purchase_price' => 100,
            'default_sale_price' => 150,
            'reorder_level' => 0,
            'unit_of_measure' => 'unit',
            'is_serialized' => false,
            'is_active' => true,
        ]);

        $inactiveProduct = Product::create([
            'sku' => 'SKU-INA',
            'brand_id' => $brand->id,
            'category_id' => $category->id,
            'name' => 'Inactive product',
            'warranty_months' => 12,
            'default_purchase_price' => 50,
            'default_sale_price' => 75,
            'reorder_level' => 0,
            'unit_of_measure' => 'unit',
            'is_serialized' => false,
            'is_active' => false,
        ]);

        foreach ([[$activeProduct, 7], [$inactiveProduct, 3]] as [$product, $qty]) {
            $adjustment = ManualStockAdjustment::create([
                'product_id' => $product->id,
                'location_id' => $branch->id,
                'qty_before' => 0,
                'qty_after' => $qty,
                'qty_delta' => $qty,
                'reason' => 'Test seed',
                'actor_user_id' => $user->id,
            ]);

            StockLedger::create([
                'event_time' => now(),
                'product_id' => $product->id,
                'serial_item_id' => null,
                'location_id' => $branch->id,
                'movement_type' => 'adjust_in',
                'quantity_delta' => $qty,
                'unit_cost' => $product->default_purchase_price,
                'reference_type' => ManualStockAdjustment::class,
                'reference_id' => $adjustment->id,
                'actor_user_id' => $user->id,
            ]);
        }

        return [$user, $branch, $activeProduct, $inactiveProduct];
    }
}
