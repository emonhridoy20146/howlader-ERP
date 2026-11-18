<?php

namespace App\Policies;

use App\Models\User;

class PurchaseOrderPolicy extends BaseModelPolicy
{
    public function approve(User $user): bool
    {
        return $this->allows($user, 'approve');
    }

    /**
     * Pay a supplier or book a refund received from one.
     */
    public function recordPayment(User $user): bool
    {
        return $this->allows($user, 'recordPayment');
    }

    /**
     * Freight, duty and other landed costs, which change unit cost on receipt.
     */
    public function manageAdditionalCost(User $user): bool
    {
        return $this->allows($user, 'manageAdditionalCost');
    }
}
