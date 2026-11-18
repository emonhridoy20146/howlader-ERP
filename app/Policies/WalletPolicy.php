<?php

namespace App\Policies;

use App\Models\User;
use App\Models\Wallet;
use Illuminate\Database\Eloquent\Model;

class WalletPolicy extends BaseModelPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->allows($user, 'viewAny')
            || $this->allows($user, 'view')
            || $this->viewBalance($user);
    }

    /**
     * List wallets and see current balances only (no detail page, edits, or transaction history).
     */
    public function viewBalance(User $user): bool
    {
        return $this->allows($user, 'viewBalance');
    }

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
        if (! $model instanceof Wallet) {
            return false;
        }

        $accessible = $user->accessibleLocationIds();
        if ($accessible === []) {
            return false;
        }

        $model->loadMissing('locations');

        if ($model->locations->isEmpty()) {
            return true;
        }

        return $model->locations->pluck('id')->intersect($accessible)->isNotEmpty();
    }
}
