<?php

namespace App\Policies;

use App\Models\PaymentMethodWalletMap;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

class PaymentMethodWalletMapPolicy extends BaseModelPolicy
{
    public function view(User $user, Model $model): bool
    {
        return $this->inBranch($user, $model) && parent::view($user, $model);
    }

    public function update(User $user, Model $model): bool
    {
        return $this->inBranch($user, $model) && parent::update($user, $model);
    }

    public function delete(User $user, Model $model): bool
    {
        return $this->inBranch($user, $model) && parent::delete($user, $model);
    }

    private function inBranch(User $user, Model $model): bool
    {
        if (! $model instanceof PaymentMethodWalletMap) {
            return false;
        }

        return in_array((int) $model->location_id, $user->accessibleLocationIds(), true);
    }
}
