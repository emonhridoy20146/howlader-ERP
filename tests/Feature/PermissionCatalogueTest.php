<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Services\PermissionSyncService;
use App\Support\PermissionRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PermissionCatalogueTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_policy_ability_has_a_permission_in_the_catalogue(): void
    {
        $missing = app(PermissionSyncService::class)->abilitiesMissingFromCatalog();

        $this->assertSame(
            [],
            $missing,
            'These policy abilities resolve to slugs that are not declared in config/permissions.php, '
            .'so no role can ever be granted them: '.implode(', ', $missing)
        );
    }

    public function test_sync_writes_the_catalogue_and_prunes_undeclared_rows(): void
    {
        Permission::query()->create(['slug' => 'ghost.permission', 'name' => 'Ghost']);

        app(PermissionSyncService::class)->sync();

        $this->assertDatabaseMissing('permissions', ['slug' => 'ghost.permission']);
        $this->assertSame(
            count(PermissionRegistry::slugs()),
            Permission::query()->count()
        );
    }

    public function test_retired_slugs_are_never_reintroduced(): void
    {
        app(PermissionSyncService::class)->sync();

        foreach (PermissionRegistry::retired() as $slug) {
            $this->assertDatabaseMissing('permissions', ['slug' => $slug]);
        }
    }

    public function test_every_alias_target_exists_in_the_catalogue(): void
    {
        foreach (PermissionRegistry::aliases() as $old => $replacements) {
            foreach ($replacements as $slug) {
                $this->assertTrue(
                    PermissionRegistry::has($slug),
                    "Alias {$old} points at {$slug}, which is not in the catalogue."
                );
            }
        }
    }

    public function test_every_alias_key_is_a_retired_slug(): void
    {
        $retired = PermissionRegistry::retired();

        foreach (array_keys(PermissionRegistry::aliases()) as $old) {
            $this->assertContains(
                $old,
                $retired,
                "Alias {$old} is still a live permission, so the sync will never expand it. "
                .'Grants for a slug that survives the rebuild belong in the upgrade migration instead.'
            );
        }
    }

    public function test_report_subjects_map_to_declared_permissions(): void
    {
        foreach (PermissionRegistry::reportPermissions() as $slug) {
            $this->assertTrue(PermissionRegistry::has($slug), "Report permission {$slug} is not in the catalogue.");
        }
    }
}
