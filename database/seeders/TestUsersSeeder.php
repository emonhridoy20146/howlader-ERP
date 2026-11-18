<?php

namespace Database\Seeders;

use App\Models\Location;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class TestUsersSeeder extends Seeder
{
    /**
     * Demo logins for local / QA testing. Password defaults to "password" unless
     * SEED_TEST_USER_PASSWORD is set in the environment.
     */
    public function run(): void
    {
        $password = Hash::make((string) env('SEED_TEST_USER_PASSWORD', 'password'));

        $byCode = Location::query()
            ->where('is_active', true)
            ->whereIn('code', ['WH-01', 'BR-01', 'BR-CGP-01'])
            ->get()
            ->keyBy('code');

        $warehouseId = $byCode->get('WH-01')?->id;
        $dhakaBranchId = $byCode->get('BR-01')?->id;
        $ctgBranchId = $byCode->get('BR-CGP-01')?->id;

        $allIds = Location::query()->where('is_active', true)->orderBy('id')->pluck('id')->all();
        $branchIds = array_values(array_filter([$dhakaBranchId, $ctgBranchId]));
        $warehouseOnly = array_values(array_filter([$warehouseId]));

        $definitions = [
            [
                'email' => 'admin@inventory.test',
                'name' => 'System Admin',
                'full_name' => 'System Administrator',
                'role_slug' => 'admin',
                'legacy_role' => 'admin',
                'location_ids' => $allIds,
                'branch_id_from' => 'first_of',
            ],
            [
                'email' => 'manager@inventory.test',
                'name' => 'Branch Manager',
                'full_name' => 'Branch Manager (Dhaka + Chattogram)',
                'role_slug' => 'branch-manager',
                'legacy_role' => 'branch_manager',
                'location_ids' => $branchIds !== [] ? $branchIds : $allIds,
                'branch_id_from' => 'first_assigned',
            ],
            [
                'email' => 'accountant@inventory.test',
                'name' => 'Accountant',
                'full_name' => 'Head Office Accountant',
                'role_slug' => 'accountant',
                'legacy_role' => 'accountant',
                'location_ids' => $allIds,
                'branch_id_from' => 'first_of',
            ],
            [
                'email' => 'sales@inventory.test',
                'name' => 'Sales',
                'full_name' => 'Sales Executive',
                'role_slug' => 'sales',
                'legacy_role' => 'sales',
                'location_ids' => $branchIds !== [] ? $branchIds : $allIds,
                'branch_id_from' => 'first_assigned',
            ],
            [
                'email' => 'warehouse@inventory.test',
                'name' => 'Warehouse',
                'full_name' => 'Warehouse Operator',
                'role_slug' => 'warehouse',
                'legacy_role' => 'warehouse',
                'location_ids' => $warehouseOnly !== [] ? $warehouseOnly : $allIds,
                'branch_id_from' => 'first_assigned',
            ],
        ];

        foreach ($definitions as $row) {
            $locationIds = $row['location_ids'];
            if ($locationIds === []) {
                $this->command?->warn("Skipping {$row['email']}: no active locations.");

                continue;
            }

            $role = Role::query()->where('slug', $row['role_slug'])->first();
            if (! $role) {
                $this->command?->warn("Skipping {$row['email']}: role {$row['role_slug']} missing. Run RolesAndPermissionsSeeder first.");

                continue;
            }

            $branchId = match ($row['branch_id_from']) {
                'first_of' => $allIds[0] ?? null,
                'first_assigned' => $locationIds[0] ?? null,
                default => $locationIds[0] ?? null,
            };

            $user = User::query()->updateOrCreate(
                ['email' => $row['email']],
                [
                    'name' => $row['name'],
                    'full_name' => $row['full_name'],
                    'password' => $password,
                    'role' => $row['legacy_role'],
                    'branch_id' => $branchId,
                    'is_active' => true,
                ]
            );

            $user->roles()->sync([$role->id]);
            $user->accessibleLocations()->sync($locationIds);
        }
    }
}
