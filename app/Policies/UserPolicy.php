<?php

namespace App\Policies;

use App\Models\User;
use App\Support\UserImpersonation;

class UserPolicy extends BaseModelPolicy
{
    public function impersonate(User $actor, User $target): bool
    {
        return UserImpersonation::canImpersonate($actor, $target);
    }
}
