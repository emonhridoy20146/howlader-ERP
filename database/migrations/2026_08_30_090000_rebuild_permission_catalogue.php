<?php

use App\Services\PermissionSyncService;
use App\Support\PermissionRegistry;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Moves the catalogue to config/permissions.php: writes the new slugs, carries
 * every existing role across from the retired ones, and stops treating the
 * legacy `users.role` column as an authorization input.
 */
return new class extends Migration
{
    /**
     * Capabilities that used to be implied by another permission and now need
     * their own row, so existing roles keep working. Left slug => granted slugs.
     */
    private const DERIVED_GRANTS = [
        'product.update' => ['product.bulk-update', 'product.view-purchase-price'],
        'sales.invoice.view-line-profit' => ['product.view-purchase-price'],
        'purchase-order.update' => [
            'purchase-order.approve',
            'purchase-order.record-payment',
            'purchase-order.manage-additional-cost',
        ],
        'stock-transfer.approve' => ['stock-transfer.cancel'],
        'stock-transfer.create' => ['stock-transfer.cancel'],
        // `reports.export` used to be the single key to the whole reports area.
        'reports.export' => [
            'reports.view',
            'reports.stock.view',
            'reports.sales.view',
            'reports.receivables.view',
            'reports.payables.view',
            'reports.finance.view',
        ],
    ];

    public function up(): void
    {
        if (! Schema::hasTable('permissions')) {
            return;
        }

        $this->backfillLegacyAdminRole();

        app(PermissionSyncService::class)->sync();

        $this->applyDerivedGrants();
        $this->grantAdminEverything();

        if (Schema::hasColumn('users', 'role')) {
            Schema::table('users', function (Blueprint $table): void {
                $table->string('role')->nullable()->default(null)->change();
            });
        }
    }

    public function down(): void
    {
        // The catalogue is rebuilt from config on every sync; nothing to reverse.
    }

    /**
     * Users who were only ever admins through the legacy enum must keep access
     * once `isAdmin()` reads roles alone.
     */
    private function backfillLegacyAdminRole(): void
    {
        if (! Schema::hasColumn('users', 'role')) {
            return;
        }

        $roleId = DB::table('roles')->where('slug', 'admin')->value('id');
        if ($roleId === null) {
            $roleId = DB::table('roles')->insertGetId(['name' => 'Administrator', 'slug' => 'admin']);
        }

        $legacyAdminIds = DB::table('users')->where('role', 'admin')->pluck('id');

        foreach ($legacyAdminIds as $userId) {
            $exists = DB::table('role_user')
                ->where('user_id', $userId)
                ->where('role_id', $roleId)
                ->exists();

            if (! $exists) {
                DB::table('role_user')->insert(['user_id' => $userId, 'role_id' => $roleId]);
            }
        }
    }

    private function applyDerivedGrants(): void
    {
        $idsBySlug = DB::table('permissions')->pluck('id', 'slug');

        foreach (self::DERIVED_GRANTS as $sourceSlug => $targetSlugs) {
            $sourceId = $idsBySlug[$sourceSlug] ?? null;
            if ($sourceId === null) {
                continue;
            }

            $roleIds = DB::table('permission_role')->where('permission_id', $sourceId)->pluck('role_id');

            foreach ($roleIds as $roleId) {
                foreach ($targetSlugs as $targetSlug) {
                    $targetId = $idsBySlug[$targetSlug] ?? null;
                    if ($targetId === null) {
                        continue;
                    }

                    $exists = DB::table('permission_role')
                        ->where('role_id', $roleId)
                        ->where('permission_id', $targetId)
                        ->exists();

                    if (! $exists) {
                        DB::table('permission_role')->insert([
                            'role_id' => $roleId,
                            'permission_id' => $targetId,
                        ]);
                    }
                }
            }
        }
    }

    private function grantAdminEverything(): void
    {
        $adminRoleId = DB::table('roles')->where('slug', 'admin')->value('id');
        if ($adminRoleId === null) {
            return;
        }

        $held = DB::table('permission_role')->where('role_id', $adminRoleId)->pluck('permission_id')->all();

        $missing = DB::table('permissions')
            ->whereIn('slug', PermissionRegistry::slugs())
            ->whereNotIn('id', $held === [] ? [0] : $held)
            ->pluck('id');

        foreach ($missing as $permissionId) {
            DB::table('permission_role')->insert([
                'role_id' => $adminRoleId,
                'permission_id' => $permissionId,
            ]);
        }
    }
};
