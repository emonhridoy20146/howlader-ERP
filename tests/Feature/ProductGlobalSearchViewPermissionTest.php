<?php

namespace Tests\Feature;

use App\Models\Brand;
use App\Models\Category;
use App\Models\Location;
use App\Models\Permission;
use App\Models\Product;
use App\Models\Role;
use App\Models\SerialItem;
use App\Models\User;
use App\Services\GlobalSearchService;
use App\Services\PermissionSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductGlobalSearchViewPermissionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(PermissionSyncService::class)->syncFromPolicies();
    }

    public function test_global_search_view_permission_finds_products_without_catalog_access(): void
    {
        [$user, $product] = $this->seedUserWithOnlyGlobalSearchView();

        $results = app(GlobalSearchService::class)->search($product->sku, null, [], $user);

        $this->assertTrue($results['products']->contains('id', $product->id));
    }

    public function test_global_search_view_can_open_product_show_but_not_index(): void
    {
        [$user, $product] = $this->seedUserWithOnlyGlobalSearchView();

        $this->actingAs($user)
            ->get(route('products.show', $product))
            ->assertOk()
            ->assertSee('View-only product lookup from global search', false)
            ->assertSee($product->name, false)
            ->assertSee('Sale price:', false)
            ->assertSee('Discount limit:', false)
            ->assertDontSee('Purchase price:', false)
            ->assertSee('Inventory (', false)
            ->assertDontSee('Serials (', false)
            ->assertDontSee('Purchase Orders (', false)
            ->assertDontSee('Goods Receipts (', false)
            ->assertDontSee('Transfers &amp; invoices', false)
            ->assertDontSee('Inventory timeline', false);

        $this->actingAs($user)
            ->get(route('products.show', ['product' => $product, 'tab' => 'timeline']))
            ->assertOk()
            ->assertDontSee('Inventory timeline', false);

        $this->actingAs($user)
            ->get(route('products.index'))
            ->assertForbidden();
    }

    public function test_global_search_view_finds_serials_at_other_locations_and_links_to_product(): void
    {
        [$user, $product, $otherBranch, $serial] = $this->seedUserWithSerialAtOtherBranch();

        $results = app(GlobalSearchService::class)->search(
            $serial->serial_number,
            null,
            [(int) $user->branch_id],
            $user,
        );

        $this->assertTrue($results['serials']->contains('id', $serial->id));

        $this->actingAs($user);
        $groups = app(GlobalSearchService::class)->toLiveGroups($results);
        $serialGroup = collect($groups)->firstWhere('label', 'Serial numbers');
        $this->assertNotNull($serialGroup);
        $this->assertSame(
            route('products.show', $product),
            $serialGroup['items'][0]['url'] ?? null,
        );
        $this->assertStringContainsString((string) $otherBranch->code, $serialGroup['items'][0]['subtitle'] ?? '');
    }

    /**
     * @return array{0: User, 1: Product}
     */
    private function seedUserWithOnlyGlobalSearchView(): array
    {
        $branch = Location::create([
            'code' => 'BR-GS',
            'name' => 'Global Search Branch',
            'type' => 'branch',
            'is_active' => true,
        ]);

        $user = User::factory()->create([
            'role' => 'sales',
            'is_active' => true,
            'branch_id' => $branch->id,
        ]);
        $user->accessibleLocations()->sync([$branch->id]);

        $role = Role::query()->create([
            'name' => 'Product search only',
            'slug' => 'product-search-only',
        ]);
        $role->permissions()->sync(
            Permission::query()->whereIn('slug', ['product.global-search-view'])->pluck('id')->all()
        );
        $user->roles()->sync([$role->id]);

        $brand = Brand::create(['name' => 'GS Brand', 'country_of_origin' => 'BD']);
        $category = Category::create(['name' => 'GS Category', 'parent_id' => null, 'sort_order' => 0]);
        $product = Product::create([
            'sku' => 'GS-SKU-100',
            'brand_id' => $brand->id,
            'category_id' => $category->id,
            'name' => 'Global Search Widget',
            'warranty_months' => 12,
            'default_purchase_price' => 100,
            'default_sale_price' => 150,
            'reorder_level' => 0,
            'unit_of_measure' => 'unit',
            'is_serialized' => true,
            'is_active' => true,
        ]);

        return [$user, $product];
    }

    /**
     * @return array{0: User, 1: Product, 2: Location, 3: SerialItem}
     */
    private function seedUserWithSerialAtOtherBranch(): array
    {
        [$user, $product] = $this->seedUserWithOnlyGlobalSearchView();

        $otherBranch = Location::create([
            'code' => 'BR-OTHER',
            'name' => 'Other Branch',
            'type' => 'branch',
            'is_active' => true,
        ]);

        $serial = SerialItem::create([
            'product_id' => $product->id,
            'serial_number' => 'GS-SERIAL-999001',
            'serial_source' => 'internal',
            'current_location_id' => $otherBranch->id,
            'status' => 'in_stock',
            'purchase_cost' => 100,
        ]);

        return [$user, $product, $otherBranch, $serial];
    }
}
