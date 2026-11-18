<?php

namespace App\Policies;

use App\Models\Customer;
use App\Models\User;

class CustomerPolicy extends BaseModelPolicy
{
    public function viewAnyTracking(User $user): bool
    {
        if ($user->isAdmin()) {
            return true;
        }

        return $user->hasPermission('customer.tracking.view');
    }

    public function viewTracking(User $user, Customer $customer): bool
    {
        return $this->viewAnyTracking($user);
    }

    public function updateTracking(User $user, Customer $customer): bool
    {
        if ($user->isAdmin()) {
            return true;
        }

        return $user->hasPermission('customer.tracking.update');
    }
}
