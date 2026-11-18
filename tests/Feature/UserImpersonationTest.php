<?php

namespace Tests\Feature;

use App\Models\Location;
use App\Models\Role;
use App\Models\User;
use App\Support\UserImpersonation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UserImpersonationTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_login_as_active_non_admin_user(): void
    {
        [$admin, $target] = $this->users();

        $response = $this->actingAs($admin)->post(route('users.impersonate', $target));

        $response->assertRedirect(route('dashboard'));
        $this->assertAuthenticatedAs($target);
        $this->assertSame((int) $admin->id, UserImpersonation::impersonatorId());
    }

    public function test_admin_cannot_impersonate_another_admin(): void
    {
        [$admin, $target] = $this->users();
        $otherAdmin = User::factory()->create([
            'role' => 'admin',
            'is_active' => true,
        ]);

        $this->actingAs($admin)->post(route('users.impersonate', $otherAdmin))->assertForbidden();
        $this->assertAuthenticatedAs($admin);
        $this->assertNull(UserImpersonation::impersonatorId());
    }

    public function test_impersonated_admin_can_log_back_in(): void
    {
        [$admin, $target] = $this->users();

        $this->actingAs($admin)->post(route('users.impersonate', $target));
        $this->assertAuthenticatedAs($target);

        $response = $this->post(route('impersonation.leave'));

        $response->assertRedirect(route('users.index'));
        $this->assertAuthenticatedAs($admin);
        $this->assertNull(UserImpersonation::impersonatorId());
    }

    public function test_non_admin_cannot_start_impersonation(): void
    {
        [, $target] = $this->users();
        $staff = User::factory()->create([
            'role' => 'sales',
            'is_active' => true,
        ]);

        $this->actingAs($staff)->post(route('users.impersonate', $target))->assertForbidden();
    }

    /**
     * @return array{0: User, 1: User}
     */
    private function users(): array
    {
        $location = Location::create([
            'code' => 'IMP-1',
            'name' => 'Impersonation branch',
            'type' => 'branch',
            'is_active' => true,
        ]);

        $admin = User::factory()->create([
            'role' => 'admin',
            'is_active' => true,
            'branch_id' => $location->id,
        ]);
        $admin->accessibleLocations()->sync([$location->id]);

        Role::query()->firstOrCreate(['slug' => 'sales'], ['name' => 'Sales']);

        $target = User::factory()->create([
            'role' => 'sales',
            'is_active' => true,
            'branch_id' => $location->id,
        ]);
        $target->accessibleLocations()->sync([$location->id]);

        return [$admin, $target];
    }
}
