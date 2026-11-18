<?php

use App\Models\Permission;
use App\Models\Role;
use App\Services\PermissionSyncService;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /** Roles that can already open the receivables/collection reports get the new yearly sheet too. */
    public function up(): void
    {
        app(PermissionSyncService::class)->syncFromPolicies();

        $new = Permission::query()->where('slug', 'reports.yearly_collection.view')->first();
        $existing = Permission::query()->where('slug', 'reports.receivables.view')->first();
        if (! $new || ! $existing) {
            return;
        }

        Role::query()->whereHas('permissions', fn ($q) => $q->whereKey($existing->id))
            ->each(fn (Role $role) => $role->permissions()->syncWithoutDetaching([$new->id]));
    }

    public function down(): void
    {
        // Permission rows are managed by the catalogue sync; nothing to undo.
    }
};
