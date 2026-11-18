<?php

namespace App\Policies;

class GoodsReceiptPolicy extends BaseModelPolicy
{
    public function post(\App\Models\User $user): bool
    {
        return $this->allows($user, 'post');
    }

}
