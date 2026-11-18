<?php

namespace App\Policies;

use App\Models\User;

class RolePolicy extends BaseModelPolicy
{
    /**
     * Renaming a role and changing what it unlocks are separate privileges.
     */
    public function assignPermissions(User $user): bool
    {
        return $user->hasPermission('role.assign-permissions');
    }
}
