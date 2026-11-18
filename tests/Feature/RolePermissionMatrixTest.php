<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\PermissionSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class RolePermissionMatrixTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(PermissionSyncService::class)->sync();
    }

    public function test_editor_saves_exactly_the_ticked_permissions(): void
    {
        $editor = $this->userWith([
            'role.view-any', 'role.view', 'role.update', 'role.assign-permissions',
        ]);

        $target = Role::query()->create(['slug' => 'floor-staff', 'name' => 'Floor staff']);
        $target->permissions()->sync($this->ids(['product.view-any', 'debt.write-off']));

        $this->actingAs($editor)
            ->put(route('roles.update', $target), [
                'name' => 'Floor staff',
                'slug' => 'floor-staff',
                'permission_ids' => $this->ids(['product.view-any', 'product.view']),
            ])
            ->assertRedirect(route('roles.index'));

        $this->assertSame(
            ['product.view', 'product.view-any'],
            $target->fresh()->permissions->pluck('slug')->sort()->values()->all(),
            'The matrix posts the whole selection, so anything left unticked must be dropped.'
        );
    }

    public function test_renaming_a_role_does_not_require_permission_to_regrant_it(): void
    {
        $editor = $this->userWith(['role.view-any', 'role.view', 'role.update']);

        $target = Role::query()->create(['slug' => 'floor-staff', 'name' => 'Floor staff']);
        $target->permissions()->sync($this->ids(['product.view-any']));

        $this->actingAs($editor)
            ->put(route('roles.update', $target), [
                'name' => 'Shop floor',
                'slug' => 'floor-staff',
                'permission_ids' => $this->ids(['debt.write-off']),
            ])
            ->assertRedirect(route('roles.index'));

        $target->refresh();
        $this->assertSame('Shop floor', $target->name);
        $this->assertSame(
            ['product.view-any'],
            $target->permissions->pluck('slug')->all(),
            'Without role.assign-permissions the posted grants must be ignored.'
        );
    }

    public function test_editor_lists_every_catalogue_permission_with_its_slug(): void
    {
        $editor = $this->userWith(['role.view-any', 'role.view', 'role.update', 'role.assign-permissions']);
        $target = Role::query()->create(['slug' => 'floor-staff', 'name' => 'Floor staff']);

        $response = $this->actingAs($editor)->get(route('roles.edit', $target));

        $response->assertOk();
        $response->assertSee('debt.write-off');
        $response->assertSee('Sensitive');
    }

    public function test_retired_slug_on_a_role_is_expanded_into_its_replacements(): void
    {
        $role = Role::query()->create(['slug' => 'legacy-manager', 'name' => 'Legacy manager']);

        // Simulate a row left behind by the old catalogue.
        $legacy = Permission::query()->create(['slug' => 'users.manage', 'name' => 'Manage users']);
        DB::table('permission_role')->insert([
            'role_id' => $role->id,
            'permission_id' => $legacy->id,
        ]);

        app(PermissionSyncService::class)->sync();

        $slugs = $role->fresh()->permissions->pluck('slug')->all();
        $this->assertContains('user.view-any', $slugs);
        $this->assertContains('user.update', $slugs);
        $this->assertNotContains('users.manage', $slugs);
    }

    public function test_report_access_is_scoped_to_the_granted_category(): void
    {
        $user = $this->userWith(['reports.view', 'reports.stock.view']);

        $this->assertTrue($user->can('view-report', 'stock-summary'));
        $this->assertFalse($user->can('view-report', 'sales-summary'));
        $this->assertFalse($user->can('export-reports'));
    }

    public function test_exporting_is_a_separate_grant_from_viewing(): void
    {
        $viewer = $this->userWith(['reports.view', 'reports.sales.view']);
        $exporter = $this->userWith(['reports.view', 'reports.sales.view', 'reports.export'], 'exporter');

        $this->assertTrue($viewer->can('view-report', 'sales-summary'));
        $this->assertFalse($viewer->can('export-reports'));
        $this->assertTrue($exporter->can('export-reports'));
    }

    /**
     * @param  list<string>  $slugs
     */
    private function userWith(array $slugs, string $roleSlug = 'matrix-test'): User
    {
        $role = Role::query()->create([
            'slug' => $roleSlug.'-'.substr(md5(implode(',', $slugs)), 0, 6),
            'name' => 'Matrix test role',
        ]);
        $role->permissions()->sync($this->ids($slugs));

        $user = User::factory()->create(['role' => 'sales', 'is_active' => true]);
        $user->roles()->sync([$role->id]);

        return $user;
    }

    /**
     * @param  list<string>  $slugs
     * @return list<int>
     */
    private function ids(array $slugs): array
    {
        return Permission::query()->whereIn('slug', $slugs)->pluck('id')->all();
    }
}
