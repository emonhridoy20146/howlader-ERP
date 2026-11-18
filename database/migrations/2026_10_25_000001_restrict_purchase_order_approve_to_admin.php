<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Purchase order approval must stay an administrator-only action. The
 * permission catalogue rebuild (2026_08_30_090000) auto-granted
 * `purchase-order.approve` to every role that already held
 * `purchase-order.update`, which let non-admin staff roles approve
 * purchase orders. Admins already bypass permission checks via
 * BaseModelPolicy::before(), so revoking this from every other role is safe.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('permissions') || ! Schema::hasTable('permission_role') || ! Schema::hasTable('roles')) {
            return;
        }

        $permissionId = DB::table('permissions')->where('slug', 'purchase-order.approve')->value('id');
        $adminRoleId = DB::table('roles')->where('slug', 'admin')->value('id');

        if ($permissionId === null) {
            return;
        }

        DB::table('permission_role')
            ->where('permission_id', $permissionId)
            ->when($adminRoleId !== null, fn ($query) => $query->where('role_id', '!=', $adminRoleId))
            ->delete();
    }

    public function down(): void
    {
        // Intentionally irreversible: re-granting this to non-admin roles was the bug.
    }
};
