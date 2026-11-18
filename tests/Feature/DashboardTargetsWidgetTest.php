<?php

namespace Tests\Feature;

use App\Models\BranchAnnualTarget;
use App\Models\Location;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\PermissionSyncService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardTargetsWidgetTest extends TestCase
{
    use RefreshDatabase;

    public function test_dashboard_hides_targets_widget_without_permission(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-09'));
        app(PermissionSyncService::class)->syncFromPolicies();

        $branch = Location::create([
            'code' => 'BR-T',
            'name' => 'Target Branch',
            'type' => 'branch',
            'is_active' => true,
        ]);

        BranchAnnualTarget::create([
            'location_id' => $branch->id,
            'year' => 2026,
            'sales_target' => 100000,
            'collection_target' => 80000,
        ]);

        $user = User::factory()->create([
            'role' => 'sales',
            'is_active' => true,
            'branch_id' => $branch->id,
        ]);
        $user->accessibleLocations()->sync([$branch->id]);

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertDontSee('Branch sales & collection targets')
            ->assertDontSee('This month · sales', false);
    }

    public function test_dashboard_targets_widget_is_scoped_to_accessible_branches(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-09'));
        app(PermissionSyncService::class)->syncFromPolicies();

        $branchA = Location::create([
            'code' => 'BR-A',
            'name' => 'Alpha Branch',
            'type' => 'branch',
            'is_active' => true,
        ]);
        $branchB = Location::create([
            'code' => 'BR-B',
            'name' => 'Beta Branch',
            'type' => 'branch',
            'is_active' => true,
        ]);

        BranchAnnualTarget::create([
            'location_id' => $branchA->id,
            'year' => 2026,
            'sales_target' => 100000,
            'collection_target' => 80000,
        ]);
        BranchAnnualTarget::create([
            'location_id' => $branchB->id,
            'year' => 2026,
            'sales_target' => 200000,
            'collection_target' => 150000,
        ]);

        \App\Models\BranchMonthlyTarget::create([
            'location_id' => $branchA->id,
            'year' => 2026,
            'month' => 8,
            'sales_target' => 10000,
            'collection_target' => 8000,
        ]);
        \App\Models\BranchMonthlyTarget::create([
            'location_id' => $branchB->id,
            'year' => 2026,
            'month' => 8,
            'sales_target' => 20000,
            'collection_target' => 15000,
        ]);

        $permissionId = Permission::query()->where('slug', 'dashboard.targets.view')->value('id');
        $this->assertNotNull($permissionId);

        $role = Role::query()->create([
            'name' => 'Targets widget',
            'slug' => 'targets-widget-test',
        ]);
        $role->permissions()->sync([(int) $permissionId]);

        $user = User::factory()->create([
            'role' => 'sales',
            'is_active' => true,
            'branch_id' => $branchA->id,
        ]);
        $user->roles()->sync([$role->id]);
        $user->accessibleLocations()->sync([$branchA->id]);

        $response = $this->actingAs($user)->get(route('dashboard'));

        $response->assertOk();
        $response->assertSee('Branch sales & collection targets');
        $response->assertSee('This month · sales', false);
        $response->assertSee('Annual · sales', false);
        $response->assertSee('Alpha Branch', false);
        $response->assertDontSee('Beta Branch', false);
    }
}
