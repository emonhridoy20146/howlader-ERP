<?php

namespace App\Policies;

class PaymentMethodPolicy extends BaseModelPolicy
{
    public function toggleActive(\App\Models\User $user): bool
    {
        return $this->allows($user, 'toggle-active');
    }

}
