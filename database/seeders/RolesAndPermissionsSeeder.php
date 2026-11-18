<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
use App\Services\PermissionSyncService;
use Illuminate\Database\Seeder;

class RolesAndPermissionsSeeder extends Seeder
{
    /**
     * Write the permission catalogue, then guarantee the admin role exists.
     * Create the first admin user with `php artisan admin:create`.
     */
    public function run(): void
    {
        app(PermissionSyncService::class)->sync();

        $admin = Role::query()->firstOrCreate(
            ['slug' => 'admin'],
            ['name' => 'Administrator']
        );

        $admin->permissions()->sync(Permission::query()->pluck('id')->all());
    }
}
