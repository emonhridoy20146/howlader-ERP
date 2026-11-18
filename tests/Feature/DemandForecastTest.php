<?php

namespace Tests\Feature;

use App\Models\Brand;
use App\Models\Category;
use App\Models\Customer;
use App\Models\DemandForecast;
use App\Models\Location;
use App\Models\Permission;
use App\Models\Product;
use App\Models\Role;
use App\Models\SalesInvoice;
use App\Models\SalesInvoiceLine;
use App\Models\User;
use App\Services\DemandForecastService;
use App\Services\PermissionSyncService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DemandForecastTest extends TestCase
{
    use RefreshDatabase;

    public function test_refresh_computes_three_month_moving_average(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-05-15 12:00:00', config('app.timezone')));

        $location = Location::create([
            'code' => 'FC-BR',
            'name' => 'Forecast branch',
            'type' => 'branch',
            'address' => 'Test',
            'is_active' => true,
        ]);

        $user = User::factory()->create([
            'role' => 'admin',
            'is_active' => true,
            'branch_id' => $location->id,
        ]);

        $customer = Customer::create([
            'customer_code' => 'C-FC',
            'name' => 'FC Customer',
            'phone' => '01701000000',
            'is_active' => true,
        ]);

        $brand = Brand::create(['name' => 'FC Brand', 'country_of_origin' => 'BD']);
        $category = Category::create(['name' => 'FC Cat', 'parent_id' => null, 'sort_order' => 0]);
        $product = Product::create([
            'sku' => 'SKU-FC-1',
            'brand_id' => $brand->id,
            'category_id' => $category->id,
            'name' => 'FC Product',
            'warranty_months' => 12,
            'default_purchase_price' => 10,
            'default_sale_price' => 20,
            'reorder_level' => 0,
            'unit_of_measure' => 'unit',
            'is_serialized' => false,
            'is_active' => true,
        ]);

        foreach ([
            ['date' => '2026-03-10', 'qty' => 10],
            ['date' => '2026-04-10', 'qty' => 20],
            ['date' => '2026-05-10', 'qty' => 30],
        ] as $i => $row) {
            $inv = SalesInvoice::create([
                'invoice_number' => 'INV-FC-'.($i + 1),
                'invoice_date' => $row['date'],
                'branch_id' => $location->id,
                'customer_id' => $customer->id,
                'sale_type' => 'cash',
                'status' => 'posted',
                'subtotal' => 200,
                'payment_status' => 'paid',
                'discount_total' => 0,
                'tax_total' => 0,
                'grand_total' => 200,
                'amount_paid' => 200,
                'amount_due' => 0,
                'created_by' => $user->id,
            ]);
            SalesInvoiceLine::create([
                'sales_invoice_id' => $inv->id,
                'product_id' => $product->id,
                'qty' => $row['qty'],
                'unit_price' => 20,
                'discount_amount' => 0,
                'tax_amount' => 0,
                'line_total' => 20 * $row['qty'],
                'warranty_months_snapshot' => 12,
            ]);
        }

        $target = Carbon::parse('2026-06-01')->startOfMonth();
        $written = app(DemandForecastService::class)->refresh($target, null, null);

        $this->assertSame(1, $written);

        $fc = DemandForecast::query()->where('product_id', $product->id)->where('location_id', $location->id)->first();
        $this->assertNotNull($fc);
        $this->assertEquals(20.0, (float) $fc->predicted_qty);
        $this->assertSame('moving_average_3m', $fc->algorithm);

        Carbon::setTestNow();
    }

    public function test_user_without_demand_forecast_permission_cannot_view_forecasts(): void
    {
        app(PermissionSyncService::class)->syncFromPolicies();

        $location = Location::create([
            'code' => 'FC-NO',
            'name' => 'No FC',
            'type' => 'branch',
            'address' => 'Test',
            'is_active' => true,
        ]);

        $reportsOnly = Permission::query()->where('slug', 'reports.export')->value('id');
        $this->assertNotNull($reportsOnly);

        $role = Role::query()->create([
            'name' => 'Reports only',
            'slug' => 'reports-only-fc-test',
        ]);
        $role->permissions()->sync([(int) $reportsOnly]);

        $user = User::factory()->create([
            'role' => 'sales',
            'is_active' => true,
            'branch_id' => $location->id,
        ]);
        $user->roles()->sync([$role->id]);
        $user->accessibleLocations()->sync([$location->id]);

        $this->actingAs($user)->get(route('forecasts.index'))->assertForbidden();
    }

    public function test_admin_can_open_forecasts_index(): void
    {
        $location = Location::create([
            'code' => 'FC-OK',
            'name' => 'OK branch',
            'type' => 'branch',
            'address' => 'Test',
            'is_active' => true,
        ]);

        $user = User::factory()->create([
            'role' => 'admin',
            'is_active' => true,
            'branch_id' => $location->id,
        ]);

        $this->actingAs($user)->get(route('forecasts.index'))->assertOk();
    }
}
