<?php

namespace App\Policies;

class InstallmentPlanPolicy extends BaseModelPolicy
{
    public function reschedule(\App\Models\User $user): bool
    {
        return $this->allows($user, 'reschedule');
    }

}
