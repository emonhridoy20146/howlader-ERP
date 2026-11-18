<?php

namespace App\Policies;

use App\Models\ReturnOrder;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

class ReturnOrderPolicy extends BaseModelPolicy
{
    public function update(User $user, Model $model): bool
    {
        if (! $model instanceof ReturnOrder || $model->status !== 'draft') {
            return false;
        }

        return $this->allows($user, 'update');
    }

    public function approve(User $user, ReturnOrder $returnOrder): bool
    {
        return $this->allows($user, 'approve');
    }

    public function resolve(User $user, ReturnOrder $returnOrder): bool
    {
        return $this->allows($user, 'resolve');
    }

    public function payRefund(User $user, ReturnOrder $returnOrder): bool
    {
        return $this->allows($user, 'payRefund');
    }

    public function rollback(User $user, ReturnOrder $returnOrder): bool
    {
        return $this->allows($user, 'rollback') && $returnOrder->canRollback();
    }
}
