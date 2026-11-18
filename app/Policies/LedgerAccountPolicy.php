<?php

namespace App\Policies;

use App\Models\LedgerAccount;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

class LedgerAccountPolicy extends BaseModelPolicy
{
    public function delete(User $user, Model $model): bool
    {
        if ($model instanceof LedgerAccount && $model->is_system) {
            return false;
        }

        return parent::delete($user, $model);
    }
}
