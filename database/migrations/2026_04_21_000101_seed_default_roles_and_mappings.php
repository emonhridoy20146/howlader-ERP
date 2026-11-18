<?php

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\PermissionSyncService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        app(PermissionSyncService::class)->syncFromPolicies();

        $adminRole = Role::query()->firstOrCreate(
            ['slug' => 'admin'],
            ['name' => 'Administrator']
        );
        $staffRole = Role::query()->firstOrCreate(
            ['slug' => 'staff'],
            ['name' => 'Staff']
        );

        $adminPermissionIds = Permission::query()->pluck('id')->all();
        $adminRole->permissions()->sync($adminPermissionIds);

        User::query()->orderBy('id')->chunkById(100, function ($users) use ($adminRole, $staffRole): void {
            foreach ($users as $user) {
                $legacyRole = strtolower((string) $user->role);
                $targetRole = $legacyRole === 'admin' ? $adminRole : $staffRole;
                $user->roles()->syncWithoutDetaching([$targetRole->id]);

                if ($user->branch_id) {
                    DB::table('location_user')->updateOrInsert([
                        'location_id' => (int) $user->branch_id,
                        'user_id' => (int) $user->id,
                    ]);
                }
            }
        });
    }

    public function down(): void
    {
        DB::table('location_user')->truncate();
        DB::table('permission_role')->truncate();
        DB::table('role_user')->truncate();
        DB::table('permissions')->truncate();
        DB::table('roles')->truncate();
    }
};
