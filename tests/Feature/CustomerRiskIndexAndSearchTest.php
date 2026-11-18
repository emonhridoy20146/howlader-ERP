<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\CustomerRiskSnapshot;
use App\Models\Location;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\PermissionSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class CustomerRiskIndexAndSearchTest extends TestCase
{
    use RefreshDatabase;

    private Location $branch;

    protected function setUp(): void
    {
        parent::setUp();
        app(PermissionSyncService::class)->syncFromPolicies();
        $this->branch = Location::create(['code' => 'BR-IDX', 'name' => 'Index Branch', 'type' => 'branch', 'is_active' => true]);
    }

    private function customer(string $name, ?Location $branch = null, ?string $grade = null): Customer
    {
        $customer = Customer::create([
            'customer_code' => 'C-'.strtoupper(substr(md5($name.uniqid()), 0, 8)), 'name' => $name,
            'phone' => '017'.random_int(10000000, 99999999), 'is_active' => true, 'branch_id' => ($branch ?? $this->branch)->id,
        ]);
        if ($grade !== null) {
            CustomerRiskSnapshot::create([
                'customer_id' => $customer->id, 'branch_id' => $customer->branch_id, 'score' => 42.5, 'grade' => $grade,
                'risk_level' => 'High', 'classification' => 'trend', 'status' => 'overdue', 'computed_at' => now(),
            ]);
        }

        return $customer;
    }

    /** @param list<string> $slugs */
    private function user(array $slugs, Location $branch): User
    {
        $user = User::factory()->create(['is_active' => true, 'branch_id' => $branch->id]);
        $user->accessibleLocations()->sync([$branch->id]);
        $role = Role::query()->create(['name' => 'R '.uniqid(), 'slug' => 'r-'.uniqid()]);
        $role->permissions()->sync(Permission::query()->whereIn('slug', $slugs)->pluck('id')->all());
        $user->roles()->sync([$role->id]);

        return $user;
    }

    private function permitted(): User
    {
        return $this->user(['customer.view-any', 'customer.view', 'reports.view', 'reports.customer_risk.view'], $this->branch);
    }

    private function unpermitted(): User
    {
        return $this->user(['customer.view-any', 'customer.view'], $this->branch);
    }

    public function test_index_shows_risk_button_and_grade_badge_only_for_permitted_users(): void
    {
        $c = $this->customer('Badge Bob', null, 'D');
        $url = route('customers.risk', $c);

        $this->actingAs($this->permitted())->get(route('customers.index'))
            ->assertOk()->assertSee($url, false)->assertSee('Risk Profile', false)->assertSee('Score 42.5', false);

        $this->actingAs($this->unpermitted())->get(route('customers.index'))
            ->assertOk()->assertDontSee($url, false)->assertDontSee('Risk Profile', false);
    }

    public function test_index_without_snapshot_shows_dash_and_grade_filter_works(): void
    {
        $nina = $this->customer('No Snapshot Nina');
        $eve = $this->customer('Grade Eve', null, 'E');
        $user = $this->permitted();
        $ninaUrl = route('customers.risk', $nina);
        $eveUrl = route('customers.risk', $eve);

        // The drawer lists every customer by name, so assert on the row action links instead.
        $this->actingAs($user)->get(route('customers.index'))->assertOk()->assertSee($eveUrl, false)->assertSee($ninaUrl, false);
        $this->actingAs($user)->get(route('customers.index', ['grade' => 'E']))->assertOk()->assertSee($eveUrl, false)->assertDontSee($ninaUrl, false);
    }

    public function test_index_query_count_does_not_grow_with_customers(): void
    {
        $user = $this->permitted();
        foreach (range(1, 5) as $i) {
            $this->customer("Few $i", null, 'C');
        }
        $this->actingAs($user)->get(route('customers.index'))->assertOk(); // warm caches

        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->actingAs($user)->get(route('customers.index'))->assertOk();
        $few = count(DB::getQueryLog());
        DB::disableQueryLog();

        foreach (range(1, 20) as $i) {
            $this->customer("Many $i", null, 'B');
        }

        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->actingAs($user)->get(route('customers.index'))->assertOk();
        $many = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertSame($few, $many, 'customers index must not issue per-row queries');
    }

    public function test_global_search_full_page_and_live_include_risk_link_for_permitted_user_only(): void
    {
        $c = $this->customer('Zorbax Alpha', null, 'D');
        $url = route('customers.risk', $c);

        $permitted = $this->permitted();
        $this->actingAs($permitted)->get(route('search', ['q' => 'Zorbax']))->assertOk()->assertSee($url, false)->assertSee('Risk profile: Zorbax Alpha', false);
        $live = $this->actingAs($permitted)->getJson(route('search.live', ['q' => 'Zorbax']))->assertOk();
        $this->assertContains($url, $this->liveActionUrls($live->json('groups')));

        $this->actingAs($this->unpermitted())->get(route('search', ['q' => 'Zorbax']))->assertOk()->assertSee('Zorbax Alpha')->assertDontSee($url, false);
        $liveNo = $this->actingAs($this->unpermitted())->getJson(route('search.live', ['q' => 'Zorbax']))->assertOk();
        $this->assertNotContains($url, $this->liveActionUrls($liveNo->json('groups')));
    }

    public function test_global_search_never_shows_customers_from_other_branches(): void
    {
        $other = Location::create(['code' => 'BR-OTH', 'name' => 'Other', 'type' => 'branch', 'is_active' => true]);
        $outside = $this->customer('Outsider Quxley', $other, 'E');
        $user = $this->permitted();

        $this->actingAs($user)->get(route('search', ['q' => 'Quxley']))
            ->assertOk()->assertDontSee('Outsider Quxley')->assertDontSee(route('customers.risk', $outside), false);
        $live = $this->actingAs($user)->getJson(route('search.live', ['q' => 'Quxley']))->assertOk();
        $this->assertSame([], $live->json('groups'));
    }

    public function test_risk_prefix_puts_customer_risk_results_first(): void
    {
        $this->customer('Karim Prefixed', null, 'C');
        $live = $this->actingAs($this->permitted())->getJson(route('search.live', ['q' => 'risk Karim']))->assertOk();

        $this->assertSame('Customers', $live->json('groups.0.label'));
    }

    /** @return list<string> */
    private function liveActionUrls(array $groups): array
    {
        $urls = [];
        foreach ($groups as $group) {
            foreach ($group['items'] as $item) {
                foreach ($item['actions'] ?? [] as $action) {
                    $urls[] = $action['url'];
                }
            }
        }

        return $urls;
    }
}
